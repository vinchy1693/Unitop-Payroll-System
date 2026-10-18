<?php
// Runs payroll for every Open period of the current month and prints the payslips.
require __DIR__ . '/_harness.php';

$pdo = $GLOBALS['pdo'];

// Use the harness's proven login (temp Payroll Officer, password TEST_PW),
// which is the maker role allowed to process payroll.
mkUser('dsh_run_admin', 'Payroll Officer');
$jar = login('dsh_run_admin');
printf("logged in as a temporary Payroll Officer\n\n");

$periods = dbAll("SELECT period_id, start_date, end_date, status FROM payroll_periods ORDER BY start_date");
foreach ($periods as $p) {
    printf("=== period %d: %s .. %s (%s) ===\n", $p['period_id'], $p['start_date'], $p['end_date'], $p['status']);
    if ($p['status'] !== 'Open') { echo "  skipped (not Open)\n\n"; continue; }

    $r = req('POST', 'payroll_runs.php', ['period_id' => (int) $p['period_id']], $jar);
    printf("  run -> HTTP %d  %s\n", $r['code'], $r['json']['message'] ?? '');
    $runId = (int) ($r['json']['data']['run_id'] ?? 0);

    $rows = dbAll("SELECT ps.payslip_id, e.employee_number, CONCAT(e.last_name, ', ', e.first_name) AS name,
                          ps.basic_pay, ps.overtime_pay, ps.gross_pay, ps.total_deductions, ps.net_pay
                   FROM payslips ps JOIN employees e ON e.employee_id = ps.employee_id
                   WHERE ps.run_id = $runId ORDER BY e.employee_number");
    foreach ($rows as $row) {
        $gc = dbRow("SELECT sss_ee, philhealth_ee, pagibig_ee, withholding_tax FROM govt_contributions WHERE payslip_id = " . (int) $row['payslip_id']);
        $deds = dbAll("SELECT deduction_type, amount FROM deductions WHERE payslip_id = " . (int) $row['payslip_id']);
        printf("  %s %-20s basic %9.2f  OT %8.2f  gross %9.2f  ded %8.2f  NET %9.2f\n",
            $row['employee_number'], $row['name'], $row['basic_pay'], $row['overtime_pay'],
            $row['gross_pay'], $row['total_deductions'], $row['net_pay']);
        printf("      SSS %.2f | PH %.2f | PI %.2f | tax %.2f\n",
            $gc['sss_ee'] ?? 0, $gc['philhealth_ee'] ?? 0, $gc['pagibig_ee'] ?? 0, $gc['withholding_tax'] ?? 0);
        foreach ($deds as $d) printf("      - %s = %.2f\n", $d['deduction_type'], $d['amount']);
    }
    echo "\n";
}
if (file_exists($jar)) unlink($jar);
runCleanup();
