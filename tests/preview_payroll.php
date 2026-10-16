<?php
// =====================================================================
// tests/preview_payroll.php
//
// READ-ONLY. Shows what a payroll run WOULD produce for every open period,
// without writing anything, so you can compare against the numbers the app
// gives you when you click "Process".
//
// Mirrors the calculation order in api/payroll_runs.php:
//   attendance deductions -> statutory contributions + tax on the reduced
//   base -> cap attendance at remaining pay -> net.
//
// Run:  C:\xampp\php\php.exe tests\preview_payroll.php
// =====================================================================

require __DIR__ . '/../api/_payroll_helpers.php';

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$periods   = $pdo->query("SELECT * FROM payroll_periods ORDER BY period_id")->fetchAll();
$employees = $pdo->query("SELECT employee_id, employee_number, first_name, last_name, basic_salary
                          FROM employees WHERE is_active = 1 ORDER BY employee_id")->fetchAll();

printf("Work week: Monday-Saturday (from api/_payroll_helpers.php)\n");
printf("Active employees: %d | payroll periods: %d\n", count($employees), count($periods));

foreach ($periods as $p) {
    printf("\n=== period %d: %s .. %s (%s, %s) ===\n",
        $p['period_id'], $p['start_date'], $p['end_date'], $p['period_type'], $p['status']);
    printf("  working days in period: %d\n", countWorkingDays($p['start_date'], $p['end_date']));

    foreach ($employees as $e) {
        $monthly = (float) $e['basic_salary'];
        if ($monthly <= 0) continue;
        $basic = round($monthly / 2, 2);

        $att    = computeAttendanceDeductions($pdo, (int) $e['employee_id'],
                                              $p['start_date'], $p['end_date'], $monthly, $basic);
        $attDed = round($att['absence_deduction'] + $att['late_deduction'], 2);

        $calc      = computePayslipDeductions($monthly, $basic, $attDed);
        $statutory = $calc['total_deductions'];

        $room      = max(0.0, round($basic - $statutory, 2));
        $absDed    = min($att['absence_deduction'], $room);
        $lateDed   = min($att['late_deduction'], max(0.0, round($room - $absDed, 2)));
        $net       = round($basic - $statutory - $absDed - $lateDed, 2);

        printf("  %s %s (id %d)\n", $e['employee_number'], $e['first_name'] . ' ' . $e['last_name'], $e['employee_id']);
        printf("    basic_pay            %10.2f   (monthly %.2f / 2)\n", $basic, $monthly);
        printf("    working/present/absent  %d / %d / %d\n", $att['expected_days'], $att['present_days'], $att['absent_days']);
        printf("    daily_rate           %10.2f\n", $att['daily_rate']);
        printf("    sss_ee/ph_ee/pi_ee   %10.2f %10.2f %10.2f\n", $calc['sss_ee'], $calc['philhealth_ee'], $calc['pagibig_ee']);
        printf("    withholding_tax      %10.2f\n", $calc['withholding_tax']);
        printf("    absence deduction    %10.2f%s\n", $absDed,
            $absDed < $att['absence_deduction'] ? '  (capped)' : '');
        printf("    late deduction       %10.2f\n", $lateDed);
        printf("    TOTAL deductions     %10.2f\n", round($statutory + $absDed + $lateDed, 2));
        printf("    >> NET PAY           %10.2f\n", $net);
    }
}
printf("\nNothing was written. Run payroll from the app when ready.\n");
