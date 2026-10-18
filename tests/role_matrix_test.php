<?php
// =====================================================================
// tests/role_matrix_test.php
//
// Drives real HTTP calls for each role and asserts the permission matrix:
//
//   endpoint group                         Employee  Maker  Checker  Admin
//   auth.php / scan_attendance.php           200     200     200     200
//   dashboard.php / reports.php              403     200     200     403
//   employees/salary_rates/positions/
//     departments/qr_codes                   403     200     200     403
//   attendance / overtime_records /
//     attendance_corrections                 403     200     200     403
//   leave_types / leave_balances /
//     leave_requests                         403     200     200     403
//   allowances / deductions / ledger /
//     cash_disbursements                     403     200     200     403
//   loans.php                                403     200     200     403
//   payroll_periods / payroll_runs /
//     payslips                               403     200     200     403
//   users.php / audit_log.php                403     403     403     200
//
// Mutations use a deliberately invalid payload: the maker must reach the
// handler (422 / 409 / 405), everyone else must be stopped by the guard (403).
// A small happy-path section then proves the maker's writes really return 200.
//
// Run:  C:\xampp\php\php.exe tests\role_matrix_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

const ROLE_ORDER = ['Employee', 'Payroll Officer', 'Approver', 'Administrator'];

$jars  = [];
$users = [];

// This suite performs real mutations, so respond() writes an audit row for
// each one. Remove only the rows this run created, like audit_log_test does.
$AUDIT_MAX = (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);

/**
 * Assert one exact status per role, in ROLE_ORDER.
 * $expect = [Employee, Payroll Officer, Approver, Administrator]
 */
function expectRole(array $expect, callable $call, string $label): array {
    global $jars;
    $results = [];
    foreach (ROLE_ORDER as $i => $role) {
        $r = $call($jars[$role]);
        $results[$role] = $r;
        eq("$label [$role] -> {$expect[$i]}", $r['code'], $expect[$i]);
    }
    return $results;
}

$fixturePayslip = 0;
$fixtureRun     = 0;
$fixturePeriod  = 0;
$fixtureLoan    = 0;

try {
    // ---- four role accounts ------------------------------------------
    $users['Employee']        = mkUser('dsh_matrix_emp',   'Employee');
    $users['Payroll Officer'] = mkUser('dsh_matrix_maker', 'Payroll Officer');
    $users['Approver']        = mkUser('dsh_matrix_check', 'Approver');
    $users['Administrator']   = mkUser('dsh_matrix_admin', 'Administrator');

    foreach (ROLE_ORDER as $role) {
        $jars[$role] = login('dsh_matrix_' . [
            'Employee' => 'emp', 'Payroll Officer' => 'maker',
            'Approver' => 'check', 'Administrator' => 'admin',
        ][$role]);
    }

    // Fixtures: one employee with a QR code, one unlocked Submitted run with
    // a payslip, so every read endpoint has a real id to work with.
    $empId = mkEmployee('DSH-MX-EMP', 'Matrix', 'Tester', 24000.00);
    $qr = 'DSH-MX-QR-' . getmypid();
    $GLOBALS['pdo']->prepare(
        "INSERT INTO qr_codes (employee_id, code_value, issued_date, status) VALUES (?, ?, CURDATE(), 'Active')"
    )->execute([$empId, $qr]);

    $fixturePeriod = mkPeriod('2027-08-01', '2027-08-15');
    $GLOBALS['pdo']->prepare(
        "INSERT INTO payroll_runs (period_id, processed_by, run_date, status, submitted_by, submitted_at)
         VALUES (?, ?, CURDATE(), 'Submitted', ?, NOW())"
    )->execute([$fixturePeriod, $users['Payroll Officer'], $users['Payroll Officer']]);
    $fixtureRun = (int) $GLOBALS['pdo']->lastInsertId();
    $GLOBALS['pdo']->prepare(
        "INSERT INTO payslips (run_id, employee_id, basic_pay, overtime_pay, gross_pay, total_deductions, net_pay)
         VALUES (?, ?, 12000, 0, 12000, 0, 12000)"
    )->execute([$fixtureRun, $empId]);
    $fixturePayslip = (int) $GLOBALS['pdo']->lastInsertId();

    $realPayslip = (int) dbRow("SELECT COALESCE(MIN(payslip_id), 0) AS id FROM payslips WHERE run_id = 1")['id'];

    // =================================================================
    section('1. Reads: 200 for the roles the matrix allows, 403 otherwise');
    // =================================================================
    $reads = [
        'dashboard.php'                          => [403, 200, 200, 403],
        'reports.php?report=payroll_register&run_id=1' => [403, 200, 200, 403],
        'employees.php'                          => [403, 200, 200, 403],
        'employees.php?simple=1'                 => [403, 200, 200, 403],
        'salary_rates.php'                       => [403, 200, 200, 403],
        'positions.php'                          => [403, 200, 200, 403],
        'departments.php'                        => [403, 200, 200, 403],
        'qr_codes.php'                           => [403, 200, 200, 403],
        'attendance.php?date=2027-01-04'         => [403, 200, 200, 403],
        'attendance_corrections.php'             => [403, 200, 200, 403],
        'overtime_records.php?all=1'             => [403, 200, 200, 403],
        'leave_types.php'                        => [403, 200, 200, 403],
        'leave_balances.php'                     => [403, 200, 200, 403],
        'leave_requests.php'                     => [403, 200, 200, 403],
        "allowances.php?payslip_id=$realPayslip" => [403, 200, 200, 403],
        "deductions.php?payslip_id=$realPayslip" => [403, 200, 200, 403],
        "cash_disbursements.php?payslip_id=$realPayslip" => [403, 200, 200, 403],
        'ledger.php?employee_id=4&from=2027-01-01&to=2027-01-31' => [403, 200, 200, 403],
        'loans.php'                              => [403, 200, 200, 403],
        'payroll_periods.php'                    => [403, 200, 200, 403],
        'payroll_runs.php'                       => [403, 200, 200, 403],
        'payslips.php?run_id=1'                  => [403, 200, 200, 403],
        'users.php'                              => [403, 403, 403, 200],
        'audit_log.php'                          => [403, 403, 403, 200],
        'auth.php?action=me'                     => [200, 200, 200, 200],
    ];
    foreach ($reads as $path => $expect) {
        expectRole($expect, fn($jar) => req('GET', $path, null, $jar), "GET $path");
    }

    // =================================================================
    section('2. auth.php actions stay open to every signed-in role');
    // =================================================================
    foreach (ROLE_ORDER as $role) {
        $r = req('GET', 'auth.php?action=me', null, $jars[$role]);
        eq("auth me returns the $role session", $r['json']['data']['role'] ?? null, $role);
    }
    // Kiosk exit (auth.php?action=verify) checks the supplied credentials'
    // role: Employee credentials are refused, every staff role is accepted.
    $r = req('POST', 'auth.php?action=verify',
        ['username' => 'dsh_matrix_emp', 'password' => TEST_PW], $jars['Employee']);
    eq('Employee credentials cannot authorise kiosk exit -> 403', $r['code'], 403);
    $r = req('POST', 'auth.php?action=verify',
        ['username' => 'dsh_matrix_maker', 'password' => TEST_PW], $jars['Payroll Officer']);
    eq('Payroll Officer can authorise kiosk exit -> 200', $r['code'], 200);
    $r = req('POST', 'auth.php?action=verify',
        ['username' => 'dsh_matrix_check', 'password' => TEST_PW], $jars['Approver']);
    eq('Approver can authorise kiosk exit -> 200', $r['code'], 200);
    $r = req('POST', 'auth.php?action=verify',
        ['username' => 'dsh_matrix_admin', 'password' => TEST_PW], $jars['Administrator']);
    eq('Administrator can authorise kiosk exit -> 200', $r['code'], 200);

    // =================================================================
    section('3. scan_attendance.php works for every role');
    // =================================================================
    $days = ['2029-02-01', '2029-02-02', '2029-02-03', '2029-02-04'];
    foreach (ROLE_ORDER as $i => $role) {
        $r = req('POST', 'scan_attendance.php', [
            'code_value' => $qr, 'demo_mode' => true,
            'demo_date' => $days[$i], 'demo_time' => '08:00:00',
        ], $jars[$role]);
        eq("$role can clock in through the kiosk endpoint -> 200", $r['code'], 200);
        eq("$role scan action = in", $r['json']['data']['action'] ?? null, 'in');
    }

    // =================================================================
    section('4. Writes: 403 for every non-maker role');
    // =================================================================
    // The payload is invalid on purpose, so a 422 (or 409/405) proves the
    // role guard let the maker through and the handler validated the input.
    $writes = [
        ['POST',   'employees.php',       [],                                                                       [403, 422, 403, 403]],
        ['POST',   'salary_rates.php',    [],                                                                       [403, 422, 403, 403]],
        ['POST',   'positions.php',       [],                                                                       [403, 422, 403, 403]],
        ['POST',   'departments.php',     [],                                                                       [403, 422, 403, 403]],
        ['POST',   'qr_codes.php',        [],                                                                       [403, 422, 403, 403]],
        ['POST',   'attendance.php',      [],                                                                       [403, 422, 403, 403]],
        ['PATCH',  'attendance_corrections.php', [],                                                                [403, 422, 403, 403]],
        ['POST',   'overtime_records.php',[],                                                                       [403, 422, 403, 403]],
        ['POST',   'leave_types.php',     [],                                                                       [403, 422, 403, 403]],
        ['POST',   'leave_balances.php',  ['year' => 1999],                                                         [403, 422, 403, 403]],
        ['POST',   'leave_requests.php',  [],                                                                       [403, 422, 403, 403]],
        ['POST',   'allowances.php',      [],                                                                       [403, 422, 403, 403]],
        ['POST',   'deductions.php',      [],                                                                       [403, 422, 403, 403]],
        ['POST',   'cash_disbursements.php', [],                                                                    [403, 422, 403, 403]],
        ['POST',   'loans.php',           [],                                                                       [403, 422, 403, 403]],
        ['PUT',    'payslips.php',        [],                                                                       [403, 422, 403, 403]],
        ['POST',   'payroll_periods.php', [],                                                                       [403, 422, 403, 403]],
        ['POST',   'payroll_runs.php',    [],                                                                       [403, 422, 403, 403]],
        ['PATCH',  'payroll_runs.php',    ['run_id' => 1, 'action' => 'approve'],                                   [403, 403, 409, 403]],
        ['PATCH',  'payroll_runs.php',    ['run_id' => 1, 'action' => 'resubmit'],                                  [403, 409, 403, 403]],
        ['PATCH',  'loans.php',           ['action' => 'approve', 'loan_id' => 0],                                  [403, 403, 422, 403]],
        ['POST',   'users.php',           [],                                                                       [403, 403, 403, 422]],
        ['POST',   'audit_log.php',       [],                                                                       [403, 403, 403, 405]],
    ];
    foreach ($writes as [$method, $path, $body, $expect]) {
        expectRole($expect, fn($jar) => req($method, $path, $body, $jar), "$method $path");
    }

    // =================================================================
    section('5. Maker happy path: the allowed writes really return 200');
    // =================================================================
    $makerJar   = $jars['Payroll Officer'];
    $checkerJar = $jars['Approver'];

    // Department create + deactivate.
    $r = req('POST', 'departments.php', ['department_name' => 'DSH Matrix Dept'], $makerJar);
    eq('POST department -> 200', $r['code'], 200);
    $deptId = (int) ($r['json']['data']['department_id'] ?? 0);
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM departments WHERE department_id = $deptId"));
    $r = req('PATCH', 'departments.php', ['department_id' => $deptId, 'is_active' => 0], $makerJar);
    eq('PATCH department -> 200', $r['code'], 200);

    // Position create.
    $r = req('POST', 'positions.php', ['title' => 'DSH Matrix Position', 'department_id' => $deptId], $makerJar);
    eq('POST position -> 200', $r['code'], 200);

    // Leave type create.
    $r = req('POST', 'leave_types.php', ['name' => 'DSH Matrix Leave', 'days_per_year' => 3], $makerJar);
    eq('POST leave type -> 200', $r['code'], 200);
    $ltId = (int) ($r['json']['data']['leave_type_id'] ?? 0);
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM leave_types WHERE leave_type_id = $ltId"));

    // Payslip-linked writes on the unlocked Submitted fixture run.
    $r = req('PUT', 'payslips.php', ['payslip_id' => $fixturePayslip, 'basic_pay' => 12500.00], $makerJar);
    eq('PUT payslip on an unlocked run -> 200', $r['code'], 200);

    $r = req('POST', 'allowances.php', [
        'payslip_id' => $fixturePayslip, 'allowance_type' => 'DSH Matrix Allowance', 'amount' => 100,
    ], $makerJar);
    eq('POST allowance on an unlocked run -> 200', $r['code'], 200);
    $allowId = (int) ($r['json']['data']['allowance_id'] ?? 0);

    $r = req('POST', 'deductions.php', [
        'payslip_id' => $fixturePayslip, 'deduction_type' => 'DSH Matrix Deduction', 'amount' => 50,
    ], $makerJar);
    eq('POST deduction on an unlocked run -> 200', $r['code'], 200);

    $r = req('POST', 'cash_disbursements.php', [
        'payslip_id' => $fixturePayslip, 'amount_released' => 500,
    ], $makerJar);
    eq('POST cash disbursement on an unlocked run -> 200', $r['code'], 200);

    $r = req('DELETE', "allowances.php?id=$allowId", null, $makerJar);
    eq('DELETE allowance on an unlocked run -> 200', $r['code'], 200);

    // Loan create + delete (no payments).
    $r = req('POST', 'loans.php', [
        'employee_id' => $empId, 'loan_type' => 'DSH Matrix Loan',
        'principal_amount' => 1000, 'monthly_amortization' => 100,
    ], $makerJar);
    eq('POST loan -> 200', $r['code'], 200);
    $fixtureLoan = (int) ($r['json']['data']['loan_id'] ?? 0);
    $r = req('DELETE', "loans.php?id=$fixtureLoan", null, $makerJar);
    eq('DELETE loan -> 200', $r['code'], 200);

    // Attendance write + delete.
    $r = req('POST', 'attendance.php', [
        'employee_id' => $empId, 'work_date' => '2027-08-02', 'time_in' => '08:00', 'time_out' => '17:00',
    ], $makerJar);
    eq('POST attendance -> 200', $r['code'], 200);
    $attId = (int) ($r['json']['data']['attendance_id'] ?? 0);
    $r = req('DELETE', "attendance.php?id=$attId", null, $makerJar);
    eq('DELETE attendance -> 200', $r['code'], 200);

    // =================================================================
    section('6. Checker can read the run but cannot write payroll data');
    // =================================================================
    $r = req('GET', 'payroll_runs.php', null, $checkerJar);
    eq('Approver reads payroll runs -> 200', $r['code'], 200);
    $r = req('POST', 'allowances.php', [
        'payslip_id' => $fixturePayslip, 'allowance_type' => 'DSH Checker', 'amount' => 1,
    ], $checkerJar);
    eq('Approver cannot add an allowance -> 403', $r['code'], 403);

    // =================================================================
    section('7. Administrator is technical only');
    // =================================================================
    foreach (['dashboard.php', 'reports.php?report=payroll_register&run_id=1', 'employees.php',
              'attendance.php?date=2027-01-04', 'loans.php', 'payroll_periods.php',
              'payroll_runs.php', 'payslips.php?run_id=1'] as $path) {
        $r = req('GET', $path, null, $jars['Administrator']);
        eq("Administrator GET $path -> 403", $r['code'], 403);
    }
    $r = req('POST', 'departments.php', ['department_name' => 'DSH Admin Attempt'], $jars['Administrator']);
    eq('Administrator cannot create master data -> 403', $r['code'], 403);
    $r = req('GET', 'users.php', null, $jars['Administrator']);
    eq('Administrator can manage users -> 200', $r['code'], 200);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    $GLOBALS['pdo']->exec("DELETE FROM audit_log WHERE audit_id > $AUDIT_MAX");

    printf("\n=== cleanup verification ===\n");
    printf("  fixture users:       %d\n", dbCount('users', "username LIKE 'dsh\\_matrix\\_%'"));
    printf("  fixture employees:   %d\n", dbCount('employees', "employee_number LIKE 'DSH-MX-%'"));
    printf("  fixture runs:        %d\n", dbCount('payroll_runs', "run_id = $fixtureRun"));
    is_true('all matrix fixtures cleaned up',
        dbCount('users', "username LIKE 'dsh\\_matrix\\_%'") === 0
        && dbCount('employees', "employee_number LIKE 'DSH-MX-%'") === 0
        && dbCount('payroll_runs', "run_id = $fixtureRun") === 0,
        'a fixture row survived cleanup');
}

summary();
