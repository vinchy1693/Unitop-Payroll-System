<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET ----
if ($method === "GET") {
    $sql = "SELECT pr.run_id, pr.period_id, pr.run_date, pr.status,
                   pp.start_date, pp.end_date, pp.period_type,
                   (SELECT COUNT(*) FROM payslips ps WHERE ps.run_id = pr.run_id) AS payslip_count
            FROM payroll_runs pr
            JOIN payroll_periods pp ON pp.period_id = pr.period_id
            ORDER BY pr.run_id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: run ----
if ($method === "POST") {
    $in       = json_input();
    $periodId = (int) ($in["period_id"] ?? 0);

    if ($periodId === 0) {
        respond(false, "period_id is required.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT period_id, start_date, end_date, period_type, status FROM payroll_periods WHERE period_id = ?");
    $stmt->execute([$periodId]);
    $period = $stmt->fetch();

    if (!$period) respond(false, "Payroll period not found.", null, 404);
    if ($period["status"] !== "Open") {
        respond(false, "Only Open periods can be processed. This one is {$period['status']}.", null, 409);
    }

    try {
        $pdo->beginTransaction();

        // 13th Month branch
        if ($period["period_type"] === "13th Month") {
            $year = (int) (new DateTime($period["start_date"]))->format("Y");

            $pdo->prepare(
                "INSERT INTO payroll_runs (period_id, processed_by, run_date, status)
                 VALUES (?, NULL, CURDATE(), 'Completed')"
            )->execute([$periodId]);
            $runId = (int) $pdo->lastInsertId();

            $employees = $pdo->query(
                "SELECT employee_id FROM employees WHERE is_active = 1 ORDER BY employee_id"
            )->fetchAll();

            $slipStmt = $pdo->prepare(
                "INSERT INTO payslips
                 (run_id, employee_id, basic_pay, overtime_pay, gross_pay, total_deductions, net_pay)
                 VALUES (?, ?, 0, 0, 0, 0, 0)"
            );

            $contribStmt = $pdo->prepare(
                "INSERT INTO govt_contributions
                 (payslip_id, sss_ee, sss_er, philhealth_ee, philhealth_er,
                  pagibig_ee, pagibig_er, withholding_tax)
                 VALUES (?, 0, 0, 0, 0, 0, 0, ?)"
            );

            $allowStmt = $pdo->prepare(
                "INSERT INTO allowances (payslip_id, allowance_type, amount)
                 VALUES (?, '13th Month Pay', ?)"
            );

            $generated = 0;
            $skipped   = 0;

            foreach ($employees as $emp) {
                $tm = compute13thMonthPay($pdo, (int) $emp["employee_id"], $year);
                if ($tm["amount"] <= 0) { $skipped++; continue; }

                $slipStmt->execute([$runId, $emp["employee_id"]]);
                $payslipId = (int) $pdo->lastInsertId();

                $allowStmt->execute([$payslipId, $tm["amount"]]);
                $contribStmt->execute([$payslipId, $tm["tax"]]);

                recomputePayslipTotals($pdo, $payslipId);
                $generated++;
            }

            $pdo->prepare("UPDATE payroll_periods SET status = 'Processed' WHERE period_id = ?")
                ->execute([$periodId]);

            $pdo->commit();
            respond(true, "13th Month run completed for $year. $generated payslip(s) generated, $skipped employee(s) skipped.",
                ["run_id" => $runId, "generated" => $generated, "skipped" => $skipped]);
        }

        // Regular semi-monthly payroll
        $pdo->prepare(
            "INSERT INTO payroll_runs (period_id, processed_by, run_date, status)
             VALUES (?, NULL, CURDATE(), 'Completed')"
        )->execute([$periodId]);
        $runId = (int) $pdo->lastInsertId();

        // Employees now carry their own basic_salary — no rate lookup needed
        $employees = $pdo->query(
            "SELECT employee_id, basic_salary FROM employees WHERE is_active = 1 ORDER BY employee_id"
        )->fetchAll();

        $slipStmt = $pdo->prepare(
            "INSERT INTO payslips
             (run_id, employee_id, basic_pay, overtime_pay, gross_pay, total_deductions, net_pay)
             VALUES (?, ?, ?, ?, ?, 0, ?)"
        );

        $contribStmt = $pdo->prepare(
            "INSERT INTO govt_contributions
             (payslip_id, sss_ee, sss_er, philhealth_ee, philhealth_er,
              pagibig_ee, pagibig_er, withholding_tax)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $dedStmt = $pdo->prepare(
            "INSERT INTO deductions (payslip_id, deduction_type, amount)
             VALUES (?, ?, ?)"
        );

        // Only overtime that was approved (or completed) AND actually clocked
        // in and out is payable. A Pending/Rejected request, or an approved
        // request the employee never started, contributes nothing. `hours` is
        // written by the overtime clock-out, never by hand.
        $otStmt = $pdo->prepare(
            "SELECT hours, rate_multiplier
             FROM overtime_records
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
               AND status IN ('Approved','Completed')
               AND hours IS NOT NULL AND hours > 0
               AND time_in IS NOT NULL AND time_out IS NOT NULL"
        );

        // Loan amortization divisor — Semi-monthly = 2, 13th month = n/a
        $loanDivisor = 2;

        $loanStmt = $pdo->prepare(
            "SELECT l.loan_id, l.loan_type, l.principal_amount, l.monthly_amortization,
                    COALESCE((SELECT SUM(amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id), 0) AS paid
             FROM loans l
             WHERE l.employee_id = ? AND l.status = 'Active'"
        );

        $loanPayStmt = $pdo->prepare(
            "INSERT INTO loan_payments (loan_id, payslip_id, amount_paid, payment_date)
             VALUES (?, ?, ?, CURDATE())"
        );

        $generated = 0;
        $skipped   = 0;

        foreach ($employees as $emp) {
            $monthly = (float) $emp["basic_salary"];
            if ($monthly <= 0) { $skipped++; continue; }

            // Semi-monthly: half the monthly basic
            $basic = round($monthly / 2, 2);

            // Attendance deductions are computed FIRST because absences and
            // undertime reduce taxable compensation. Computing the withholding
            // tax on the full semi-monthly basic and only then subtracting the
            // absence produced a negative net pay. The returned daily_rate is
            // this period's own rate (basic / its working days, Mon-Sat).
            $att = computeAttendanceDeductions(
                $pdo,
                (int) $emp["employee_id"],
                $period["start_date"],
                $period["end_date"],
                $monthly,
                $basic
            );
            $attendanceDeduction = round($att["absence_deduction"] + $att["late_deduction"], 2);

            // Overtime, priced at the same period-based rate as the daily rate
            $otStmt->execute([$emp["employee_id"], $period["start_date"], $period["end_date"]]);
            $otRows = $otStmt->fetchAll();

            $hourlyRate = $att["daily_rate"] / 8.0;
            $otPay = 0.0;
            foreach ($otRows as $ot) {
                $otPay += (float) $ot["hours"] * (float) $ot["rate_multiplier"] * $hourlyRate;
            }
            $otPay = round($otPay, 2);

            // Statutory contributions (based on monthly salary) plus withholding
            // tax computed on the reduced taxable base.
            $calc      = computePayslipDeductions($monthly, $basic, $attendanceDeduction);
            $statutory = $calc["total_deductions"];

            // Never dock more than the employee actually earned: cap attendance
            // deductions at what remains after statutory deductions.
            $room             = max(0.0, round($basic + $otPay - $statutory, 2));
            $absenceDeduction = min($att["absence_deduction"], $room);
            $lateDeduction    = min($att["late_deduction"], max(0.0, round($room - $absenceDeduction, 2)));

            // Insert payslip shell
            $slipStmt->execute([
                $runId,
                $emp["employee_id"],
                $basic,
                $otPay,
                round($basic + $otPay, 2),
                $calc["net_pay"],
            ]);
            $payslipId = (int) $pdo->lastInsertId();

            // Government contributions
            $contribStmt->execute([
                $payslipId,
                $calc["sss_ee"], $calc["sss_er"],
                $calc["philhealth_ee"], $calc["philhealth_er"],
                $calc["pagibig_ee"], $calc["pagibig_er"],
                $calc["withholding_tax"],
            ]);

            // Attendance deductions (capped amounts, labelled when capped)
            if ($absenceDeduction > 0) {
                $dedStmt->execute([
                    $payslipId,
                    $absenceDeduction < $att["absence_deduction"]
                        ? "Absences ({$att['absent_days']} day(s), capped to remaining pay)"
                        : "Absences ({$att['absent_days']} day(s))",
                    $absenceDeduction,
                ]);
            }
            if ($lateDeduction > 0) {
                $dedStmt->execute([
                    $payslipId,
                    $lateDeduction < $att["late_deduction"]
                        ? "Late/Undertime ({$att['late_minutes']} min, capped to remaining pay)"
                        : "Late/Undertime ({$att['late_minutes']} min)",
                    $lateDeduction,
                ]);
            }

            // Loans
            $loanStmt->execute([$emp["employee_id"]]);
            $loans = $loanStmt->fetchAll();

            foreach ($loans as $loan) {
                $remaining = round((float) $loan["principal_amount"] - (float) $loan["paid"], 2);
                if ($remaining <= 0) {
                    $pdo->prepare("UPDATE loans SET status = 'Paid' WHERE loan_id = ?")
                        ->execute([$loan["loan_id"]]);
                    continue;
                }

                $amountDue = round((float) $loan["monthly_amortization"] / $loanDivisor, 2);
                if ($amountDue > $remaining) $amountDue = $remaining;

                $loanPayStmt->execute([$loan["loan_id"], $payslipId, $amountDue]);

                $dedStmt->execute([
                    $payslipId,
                    "Loan: " . $loan["loan_type"],
                    $amountDue,
                ]);

                if (round($remaining - $amountDue, 2) <= 0) {
                    $pdo->prepare("UPDATE loans SET status = 'Paid' WHERE loan_id = ?")
                        ->execute([$loan["loan_id"]]);
                }
            }

            // Recompute totals
            recomputePayslipTotals($pdo, $payslipId);
            $generated++;
        }

        $pdo->prepare("UPDATE payroll_periods SET status = 'Processed' WHERE period_id = ?")
            ->execute([$periodId]);

        $pdo->commit();
        respond(true, "Payroll run completed. $generated payslip(s) generated, $skipped employee(s) skipped (no basic salary).",
            ["run_id" => $runId, "generated" => $generated, "skipped" => $skipped]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, "Payroll run failed: " . $e->getMessage(), null, 500);
    }
}

respond(false, "Method not allowed.", null, 405);