<?php
// =====================================================================
// tests/repair_payslips.php
//
// One-off maintenance tool: rebuilds the attendance-based and statutory
// figures of already-stored payslips using the CORRECTED payroll logic
// (absences reduce the taxable base BEFORE withholding tax is computed,
// and attendance deductions are capped so net pay cannot go negative).
//
// Payslip IDs, run IDs and period statuses are left untouched.
// Loan deductions are preserved; only Absences / Late-Undertime lines
// are replaced.
//
// Usage:
//   C:\xampp\php\php.exe tests\repair_payslips.php --dry-run 3 4
//   C:\xampp\php\php.exe tests\repair_payslips.php 3 4
// =====================================================================

require __DIR__ . '/../api/_payroll_helpers.php';

$args = array_slice($argv, 1);
$dry  = in_array('--dry-run', $args, true);
$ids  = array_values(array_filter(array_map('intval', $args), fn($i) => $i > 0));

if (!$ids) {
    fwrite(STDERR, "usage: php repair_payslips.php [--dry-run] <payslip_id> [payslip_id...]\n");
    exit(1);
}

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$fetch = $pdo->prepare(
    "SELECT ps.payslip_id, ps.employee_id, ps.basic_pay, ps.overtime_pay,
            ps.gross_pay, ps.total_deductions, ps.net_pay,
            r.run_id, pp.period_id, pp.start_date, pp.end_date, pp.status AS period_status,
            e.basic_salary, e.first_name, e.last_name
     FROM payslips ps
     JOIN payroll_runs    r  ON r.run_id    = ps.run_id
     JOIN payroll_periods pp ON pp.period_id = r.period_id
     JOIN employees       e  ON e.employee_id = ps.employee_id
     WHERE ps.payslip_id = ?"
);

$updContrib = $pdo->prepare(
    "UPDATE govt_contributions
     SET sss_ee = ?, sss_er = ?, philhealth_ee = ?, philhealth_er = ?,
         pagibig_ee = ?, pagibig_er = ?, withholding_tax = ?
     WHERE payslip_id = ?"
);
$insContrib = $pdo->prepare(
    "INSERT INTO govt_contributions
     (payslip_id, sss_ee, sss_er, philhealth_ee, philhealth_er,
      pagibig_ee, pagibig_er, withholding_tax)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$delAttRows = $pdo->prepare(
    "DELETE FROM deductions
     WHERE payslip_id = ?
       AND (deduction_type LIKE 'Absences%' OR deduction_type LIKE 'Late/Undertime%')"
);
$insDed = $pdo->prepare(
    "INSERT INTO deductions (payslip_id, deduction_type, amount) VALUES (?, ?, ?)"
);

printf("%s\n", $dry ? '*** DRY RUN - nothing will be committed ***' : '*** APPLYING REPAIRS ***');
$pdo->beginTransaction();

try {
    foreach ($ids as $id) {
        $fetch->execute([$id]);
        $ps = $fetch->fetch();
        if (!$ps) { printf("\npayslip %d: NOT FOUND - skipped\n", $id); continue; }

        $empId   = (int)   $ps['employee_id'];
        $monthly = (float) $ps['basic_salary'];
        $basic   = (float) $ps['basic_pay'];
        $otPay   = (float) $ps['overtime_pay'];

        printf("\n--- payslip %d (%s %s, period %d: %s..%s, %s) ---\n",
            $id, $ps['first_name'], $ps['last_name'], $ps['period_id'],
            $ps['start_date'], $ps['end_date'], $ps['period_status']);

        // 1. attendance deductions, corrected weekend handling
        $att    = computeAttendanceDeductions($pdo, $empId, $ps['start_date'], $ps['end_date'], $monthly, $basic);
        $attDed = round($att['absence_deduction'] + $att['late_deduction'], 2);

        // 2. statutory contributions + tax on the reduced taxable base
        $calc      = computePayslipDeductions($monthly, $basic, $attDed);
        $statutory = $calc['total_deductions'];

        // 3. cap attendance deductions at what is left after statutory
        $room             = max(0.0, round($basic + $otPay - $statutory, 2));
        $absenceDeduction = min($att['absence_deduction'], $room);
        $lateDeduction    = min($att['late_deduction'], max(0.0, round($room - $absenceDeduction, 2)));

        printf("  attendance: expected=%d present=%d absent=%d -> absence=%.2f late=%.2f\n",
            $att['expected_days'], $att['present_days'], $att['absent_days'],
            $absenceDeduction, $lateDeduction);
        printf("  statutory : sss_ee=%.2f ph_ee=%.2f pi_ee=%.2f tax=%.2f (was tax %.2f)\n",
            $calc['sss_ee'], $calc['philhealth_ee'], $calc['pagibig_ee'], $calc['withholding_tax'],
            (float) $pdo->query("SELECT withholding_tax FROM govt_contributions WHERE payslip_id = $id")->fetchColumn());

        // 4. write contributions
        $params = [$calc['sss_ee'], $calc['sss_er'], $calc['philhealth_ee'], $calc['philhealth_er'],
                   $calc['pagibig_ee'], $calc['pagibig_er'], $calc['withholding_tax']];
        $exists = (int) $pdo->query("SELECT COUNT(*) FROM govt_contributions WHERE payslip_id = $id")->fetchColumn();
        if ($exists) { $updContrib->execute([...$params, $id]); }
        else         { $insContrib->execute([$id, ...$params]); }

        // 5. replace only the attendance deduction lines (loans preserved)
        $delAttRows->execute([$id]);
        if ($absenceDeduction > 0) {
            $insDed->execute([$id,
                $absenceDeduction < $att['absence_deduction']
                    ? "Absences ({$att['absent_days']} day(s), capped to remaining pay)"
                    : "Absences ({$att['absent_days']} day(s))",
                $absenceDeduction]);
        }
        if ($lateDeduction > 0) {
            $insDed->execute([$id,
                $lateDeduction < $att['late_deduction']
                    ? "Late/Undertime ({$att['late_minutes']} min, capped to remaining pay)"
                    : "Late/Undertime ({$att['late_minutes']} min)",
                $lateDeduction]);
        }

        // 6. rebuild gross / total_deductions / net_pay
        $rc = recomputePayslipTotals($pdo, $id);

        printf("  BEFORE: gross=%.2f deductions=%.2f NET=%.2f\n",
            (float) $ps['gross_pay'], (float) $ps['total_deductions'], (float) $ps['net_pay']);
        printf("  AFTER : gross=%.2f deductions=%.2f NET=%.2f\n",
            $rc['gross_pay'], $rc['total_deductions'], $rc['net_pay']);

        $check = round($rc['gross_pay'] - $rc['total_deductions'], 2);
        printf("  check : gross - deductions = %.2f %s | net >= 0 %s\n",
            $check,
            abs($check - $rc['net_pay']) < 0.011 ? 'OK' : 'MISMATCH',
            $rc['net_pay'] >= 0 ? 'OK' : 'FAIL');
    }

    if ($dry) { $pdo->rollBack(); printf("\n*** DRY RUN - rolled back, nothing changed ***\n"); }
    else      { $pdo->commit();  printf("\n*** repairs committed ***\n"); }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "FAILED, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
