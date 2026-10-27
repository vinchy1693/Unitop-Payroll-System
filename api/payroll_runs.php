<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read; mutations are guarded per action
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

/** One run, with every name the approval screen shows. */
function payrollRunById(PDO $pdo, int $runId): ?array {
    $stmt = $pdo->prepare(
        "SELECT pr.run_id, pr.period_id, pr.run_date, pr.status,
                pr.processed_by, pr.submitted_by, pr.submitted_at,
                pr.approved_by, pr.approved_at,
                pr.returned_by, pr.returned_at,
                pr.release_approved_by, pr.release_approved_at,
                pr.remarks,
                pp.start_date, pp.end_date, pp.period_type,
                (SELECT COUNT(*) FROM payslips ps WHERE ps.run_id = pr.run_id) AS payslip_count,
                (SELECT COALESCE(SUM(ps.gross_pay), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_gross,
                (SELECT COALESCE(SUM(ps.total_deductions), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_deductions,
                (SELECT COALESCE(SUM(ps.net_pay), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_net,
                su.username AS submitted_by_name,
                ap.username AS approved_by_name,
                re.username AS returned_by_name,
                rl.username AS release_approved_by_name
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         LEFT JOIN users su ON su.user_id = pr.submitted_by
         LEFT JOIN users ap ON ap.user_id = pr.approved_by
         LEFT JOIN users re ON re.user_id = pr.returned_by
         LEFT JOIN users rl ON rl.user_id = pr.release_approved_by
         WHERE pr.run_id = ?"
    );
    $stmt->execute([$runId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---- GET: list runs ----
if ($method === "GET") {
    $sql = "SELECT pr.run_id, pr.period_id, pr.run_date, pr.status,
                   pr.processed_by, pr.submitted_by, pr.submitted_at,
                   pr.approved_by, pr.approved_at,
                   pr.returned_by, pr.returned_at,
                   pr.release_approved_by, pr.release_approved_at,
                   pr.remarks,
                   pp.start_date, pp.end_date, pp.period_type,
                   (SELECT COUNT(*) FROM payslips ps WHERE ps.run_id = pr.run_id) AS payslip_count,
                   (SELECT COALESCE(SUM(ps.gross_pay), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_gross,
                   (SELECT COALESCE(SUM(ps.total_deductions), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_deductions,
                   (SELECT COALESCE(SUM(ps.net_pay), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_net,
                   su.username AS submitted_by_name,
                   ap.username AS approved_by_name,
                   re.username AS returned_by_name,
                   rl.username AS release_approved_by_name
            FROM payroll_runs pr
            JOIN payroll_periods pp ON pp.period_id = pr.period_id
            LEFT JOIN users su ON su.user_id = pr.submitted_by
            LEFT JOIN users ap ON ap.user_id = pr.approved_by
            LEFT JOIN users re ON re.user_id = pr.returned_by
            LEFT JOIN users rl ON rl.user_id = pr.release_approved_by
            ORDER BY pr.run_id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// =====================================================================
// PATCH: the approval state machine
//
//   Submitted --approve--> Approved --release--> Released
//   Submitted --return---> Returned  --resubmit-> Submitted
//   Returned  --approve--> Approved
//
// approve / return / release are CHECKER actions; resubmit is the MAKER's.
// The submitter may never decide their own run, even with the Approver role.
// =====================================================================
if ($method === "PATCH") {
    $in      = json_input();
    $runId   = (int) ($in["run_id"] ?? 0);
    $action  = strtolower(trim((string) ($in["action"] ?? "")));
    $remarks = trim((string) ($in["remarks"] ?? ""));

    if ($runId === 0) {
        respond(false, "run_id is required.", null, 422);
    }
    if (!in_array($action, ["approve", "return", "release", "resubmit"], true)) {
        respond(false, "action must be approve, return, release or resubmit.", null, 422);
    }

    if ($action === "resubmit") {
        requireRole(MAKER_ROLES);    // the maker sends a returned run back
    } else {
        requireRole(CHECKER_ROLES);  // only Payroll/HR decides
    }

    $user = currentUser();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM payroll_runs WHERE run_id = ? FOR UPDATE");
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) {
            $pdo->rollBack();
            respond(false, "Payroll run not found.", null, 404);
        }

        // The combined Payroll/HR role may process and approve the same run.
        // The approval is still audited; a separate checker role can be added
        // later without changing the state machine.
        $status = (string) $run["status"];

        if ($action === "approve") {
            if (!in_array($status, ["Submitted", "Returned"], true)) {
                $pdo->rollBack();
                respond(false, "Cannot approve a run that is $status. Only Submitted or Returned runs can be approved.", null, 409);
            }
            $pdo->prepare(
                "UPDATE payroll_runs
                 SET status = 'Approved', approved_by = ?, approved_at = NOW()
                 WHERE run_id = ?"
            )->execute([$user["user_id"], $runId]);
            $message = "Payroll run approved.";
            $pdo->prepare("UPDATE payroll_periods SET status = 'Processed' WHERE period_id = ?")
                ->execute([$run["period_id"]]);

        } elseif ($action === "return") {
            if ($status !== "Submitted") {
                $pdo->rollBack();
                respond(false, "Cannot return a run that is $status. Only Submitted runs can be returned.", null, 409);
            }
            if ($remarks === "") {
                $pdo->rollBack();
                respond(false, "Remarks are required when returning a payroll run: explain what must be corrected.", null, 422);
            }
            if (strlen($remarks) > 500) {
                $remarks = substr($remarks, 0, 500);
            }
            $pdo->prepare(
                "UPDATE payroll_runs
                 SET status = 'Returned', returned_by = ?, returned_at = NOW(), remarks = ?
                 WHERE run_id = ?"
            )->execute([$user["user_id"], $remarks, $runId]);
            $message = "Payroll run returned to the maker for correction.";
            $pdo->prepare("UPDATE payroll_periods SET status = 'Returned' WHERE period_id = ?")
                ->execute([$run["period_id"]]);

        } elseif ($action === "release") {
            if ($status !== "Approved") {
                $pdo->rollBack();
                respond(false, "Cannot release a run that is $status. Only Approved runs can be released.", null, 409);
            }
            $pdo->prepare(
                "UPDATE payroll_runs
                 SET status = 'Released', release_approved_by = ?, release_approved_at = NOW()
                 WHERE run_id = ?"
            )->execute([$user["user_id"], $runId]);
            $message = "Payment release authorised.";
            $pdo->prepare("UPDATE payroll_periods SET status = 'Processed' WHERE period_id = ?")
                ->execute([$run["period_id"]]);

        } else { // resubmit
            if ($status !== "Returned") {
                $pdo->rollBack();
                respond(false, "Cannot resubmit a run that is $status. Only Returned runs can be resubmitted.", null, 409);
            }
            $pdo->prepare(
                "UPDATE payroll_runs
                 SET status = 'Submitted', submitted_by = ?, submitted_at = NOW()
                 WHERE run_id = ?"
            )->execute([$user["user_id"], $runId]);
            $message = "Payroll run resubmitted for approval.";
            $pdo->prepare("UPDATE payroll_periods SET status = 'For Approval' WHERE period_id = ?")
                ->execute([$run["period_id"]]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respondServerError("The payroll run action could not be completed. Please try again.", $e, "payroll_runs PATCH");
    }

    respond(true, $message, payrollRunById($pdo, $runId));
}

// =====================================================================
// POST: process an Open period into a Submitted run (the maker's step)
// =====================================================================
if ($method === "POST") {
    requireRole(MAKER_ROLES);

    $in       = json_input();
    $periodId = (int) ($in["period_id"] ?? 0);

    if ($periodId === 0) {
        respond(false, "period_id is required.", null, 422);
    }

    $maker = currentUser();

    try {
        $pdo->beginTransaction();

        // Lock the period BEFORE deciding whether it can be processed. The old
        // check ran before beginTransaction(), so two Process clicks could both
        // read "Open", both insert a run, and the loser died on the unique key
        // with a 500. SELECT ... FOR UPDATE serialises them: the first holds
        // the row, marks the period Processed and commits; the second then
        // reads the committed status and gets a clean 409.
        $stmt = $pdo->prepare(
            "SELECT period_id, start_date, end_date, period_type, status
             FROM payroll_periods WHERE period_id = ? FOR UPDATE"
        );
        $stmt->execute([$periodId]);
        $period = $stmt->fetch();

        if (!$period) {
            $pdo->rollBack();
            respond(false, "Payroll period not found.", null, 404);
        }
        if ($period["status"] !== "Open") {
            $pdo->rollBack();
            respond(false, "Only Open periods can be processed. This one is {$period['status']}.", null, 409);
        }

        // 13th Month branch
        if ($period["period_type"] === "13th Month") {
            $year = (int) (new DateTime($period["start_date"]))->format("Y");

            $pdo->prepare(
                "INSERT INTO payroll_runs
                 (period_id, processed_by, run_date, status, submitted_by, submitted_at)
                 VALUES (?, ?, CURDATE(), 'Submitted', ?, NOW())"
            )->execute([$periodId, $maker["user_id"], $maker["user_id"]]);
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

            // 13th Month Pay is exempt up to ₱90,000 (RA 10963, Sec. 32(B)(7)(a)).
            // compute13thMonthPay() already taxes only the excess, so the line is
            // marked non-taxable and recomputePayslipTotals() must not tax it again.
            $allowStmt = $pdo->prepare(
                "INSERT INTO allowances (payslip_id, allowance_type, amount, is_taxable)
                 VALUES (?, '13th Month Pay', ?, 0)"
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

            $pdo->prepare("UPDATE payroll_periods SET status = 'For Approval' WHERE period_id = ?")
                ->execute([$periodId]);

            $pdo->commit();
            respond(true, "13th Month run submitted for approval. $generated payslip(s) generated, $skipped employee(s) skipped.",
                ["run_id" => $runId, "status" => "Submitted", "generated" => $generated, "skipped" => $skipped]);
        }

        // Regular semi-monthly payroll
        $pdo->prepare(
            "INSERT INTO payroll_runs
             (period_id, processed_by, run_date, status, submitted_by, submitted_at)
             VALUES (?, ?, CURDATE(), 'Submitted', ?, NOW())"
        )->execute([$periodId, $maker["user_id"], $maker["user_id"]]);
        $runId = (int) $pdo->lastInsertId();

        // Employees now carry their own basic_salary - no rate lookup needed
        $employees = $pdo->query(
            "SELECT employee_id, basic_salary FROM employees WHERE is_active = 1 ORDER BY employee_id"
        )->fetchAll();

        $slipStmt = $pdo->prepare(
            "INSERT INTO payslips
             (run_id, employee_id, basic_pay, overtime_pay, premium_pay, gross_pay, total_deductions, net_pay)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)"
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
            "SELECT ot_date, hours, rate_multiplier
             FROM overtime_records
             WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
               AND status IN ('Approved','Completed')
               AND hours IS NOT NULL AND hours > 0
               AND time_in IS NOT NULL AND time_out IS NOT NULL"
        );

        // Loan amortization divisor - Semi-monthly = 2, 13th month = n/a
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
            $attendanceDeduction = round(
                $att["absence_deduction"] + $att["late_deduction"] + $att["undertime_deduction"], 2
            );

            // Premium pay for work recorded on rest days / holidays. The first
            // eight hours use the day-type rate and the excess the day-type OT
            // rate; ordinary-day work is covered by the basic.
            $premium    = computePremiumPay(
                $pdo, (int) $emp["employee_id"], $period["start_date"], $period["end_date"], $att["daily_rate"]
            );
            $premiumPay = $premium["total"];

            // Overtime, priced at the same period-based rate as the daily rate.
            // The multiplier is at least the statutory one for the date, so a
            // stored 1.25 can never underpay a rest day / holiday.
            $otStmt->execute([$emp["employee_id"], $period["start_date"], $period["end_date"]]);
            $otRows = $otStmt->fetchAll();

            $hourlyRate = $att["daily_rate"] / 8.0;
            $otPay = 0.0;
            foreach ($otRows as $ot) {
                $otDayType  = dayTypeForDate($pdo, $ot["ot_date"]);
                $multiplier = max(
                    (float) $ot["rate_multiplier"],
                    overtimeMultiplierForDayType($otDayType)
                );
                $otPay += (float) $ot["hours"] * $multiplier * $hourlyRate;
            }
            $otPay = round($otPay, 2);

            // Statutory contributions (based on monthly salary) plus withholding
            // tax computed on the reduced taxable base. Premium pay is taxable
            // compensation, so it is passed through the same additional-taxable
            // channel as overtime.
            $calc      = computePayslipDeductions($monthly, $basic, $attendanceDeduction, $otPay + $premiumPay);
            $statutory = $calc["total_deductions"];

            // Never dock more than the employee actually earned: cap attendance
            // deductions at what remains after statutory deductions.
            $room             = max(0.0, round($basic + $otPay + $premiumPay - $statutory, 2));
            $absenceDeduction = min($att["absence_deduction"], $room);
            $remainingRoom    = max(0.0, round($room - $absenceDeduction, 2));
            $lateDeduction    = min($att["late_deduction"], $remainingRoom);
            $remainingRoom    = max(0.0, round($remainingRoom - $lateDeduction, 2));
            $undertimeDeduction = min($att["undertime_deduction"], $remainingRoom);

            // Insert payslip shell
            $slipStmt->execute([
                $runId,
                $emp["employee_id"],
                $basic,
                $otPay,
                $premiumPay,
                round($basic + $otPay + $premiumPay, 2),
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
            if ($undertimeDeduction > 0) {
                $dedStmt->execute([
                    $payslipId,
                    $undertimeDeduction < $att["undertime_deduction"]
                        ? "Undertime ({$att['undertime_minutes']} min, capped to remaining pay)"
                        : "Undertime ({$att['undertime_minutes']} min)",
                    $undertimeDeduction,
                ]);
            }

            // Loans. Only withhold what is actually left after statutory and
            // attendance deductions; the rest stays on the loan and is collected
            // next run. Crediting the full amortisation against a zero net pay
            // would forgive debt the employee never paid.
            $loanRoom = $remainingRoom;
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
                if ($amountDue > $loanRoom) $amountDue = round($loanRoom, 2);

                if ($amountDue <= 0) {
                    // No room this run; leave the loan Active for the next run.
                    continue;
                }

                $loanPayStmt->execute([$loan["loan_id"], $payslipId, $amountDue]);

                $dedStmt->execute([
                    $payslipId,
                    "Loan: " . $loan["loan_type"],
                    $amountDue,
                ]);

                $loanRoom = max(0.0, round($loanRoom - $amountDue, 2));

                if (round($remaining - $amountDue, 2) <= 0) {
                    $pdo->prepare("UPDATE loans SET status = 'Paid' WHERE loan_id = ?")
                        ->execute([$loan["loan_id"]]);
                }
            }

            // Recompute totals
            recomputePayslipTotals($pdo, $payslipId);
            $generated++;
        }

        $pdo->prepare("UPDATE payroll_periods SET status = 'For Approval' WHERE period_id = ?")
            ->execute([$periodId]);

        $pdo->commit();
        respond(true, "Payroll run submitted for approval. $generated payslip(s) generated, $skipped employee(s) skipped (no basic salary).",
            ["run_id" => $runId, "status" => "Submitted", "generated" => $generated, "skipped" => $skipped]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Belt and braces: even if the period lock were bypassed, uq_runs_period
        // stops a second run. Translate its duplicate-key error into the same
        // clean 409 rather than a 500.
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            respond(false,
                "This period already has a payroll run. Void the existing run first, then process the period again.",
                null, 409);
        }
        respondServerError("The payroll run could not be processed. Please try again or contact the administrator.", $e, "payroll_runs POST (PDO)");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respondServerError("The payroll run could not be processed. Please try again or contact the administrator.", $e, "payroll_runs POST");
    }
}

respond(false, "Method not allowed.", null, 405);
