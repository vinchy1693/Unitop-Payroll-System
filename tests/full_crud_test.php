<?php
// =====================================================================
// tests/full_crud_test.php
// Security + CRUD + attendance write path + leave lifecycle.
// Creates its own fixtures and removes every one of them afterwards.
// Run:  C:\xampp\php\php.exe tests\full_crud_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

printf("start: employees=%d users=%d periods=%d attendance=%d leave_types=%d leave_balances=%d\n",
    dbCount('employees'), dbCount('users'), dbCount('payroll_periods'),
    dbCount('attendance'), dbCount('leave_types'), dbCount('leave_balances'));

// Snapshot every table this suite touches, so "restored" means exactly the
// pre-test state instead of a hardcoded guess that goes stale after a reset.
$CRUD_WATCH = ['employees','users','payroll_periods','attendance','leave_types','leave_balances',
               'leave_requests','positions','departments','qr_codes','salary_rates','loans',
               'overtime_records'];
$CRUD_START = [];
foreach ($CRUD_WATCH as $t) $CRUD_START[$t] = dbCount($t);

try {
    // ---- fixtures ----
    // Master data and attendance are written by the maker (Payroll Officer);
    // the Administrator is technical only since the maker/checker split.
    mkUser('dsh_admin', 'Payroll Officer');
    $jar = login('dsh_admin');

    $empId = mkEmployee('DSH-EMP-A', 'Probe', 'Employee', 20000.00);
    mkUser('dsh_emp_role', 'Employee', $empId);
    $jarEmp = login('dsh_emp_role');

    // =================================================================
    section('1. Development debug endpoints are gone');
    // =================================================================
    $r = req('GET', 'debug_me.php');
    is_true('api/debug_me.php no longer exists', $r['code'] === 404, "HTTP {$r['code']} - the file is still served");
    $r = req('GET', 'test_session.php');
    is_true('api/test_session.php no longer exists', $r['code'] === 404, "HTTP {$r['code']} - the file is still served");

    // =================================================================
    section('2. Authorization: an Employee must not reach admin actions');
    // =================================================================
    $probes = [
        ['POST', 'departments.php',      ['department_name' => 'DSH Unauthorized Dept'], 'create a department'],
        ['POST', 'payroll_periods.php',  ['start_date' => '2027-06-01', 'end_date' => '2027-06-15'], 'create a payroll period'],
        ['POST', 'employees.php',        ['employee_number' => 'DSH-UNAUTH', 'first_name' => 'X', 'last_name' => 'Y', 'basic_salary' => 10000], 'create an employee'],
        ['POST', 'loans.php',            ['employee_id' => $empId, 'loan_type' => 'DSHUNAUTH', 'principal_amount' => 1000, 'monthly_amortization' => 100], 'create a loan'],
        ['POST', 'overtime_records.php', ['employee_id' => $empId, 'ot_date' => '2027-06-01', 'hours' => 2, 'ot_type' => 'DSHUNAUTH', 'rate_multiplier' => 1.25], 'create overtime'],
        ['DELETE', 'attendance.php?id=999999', null, 'delete an attendance record'],
    ];
    foreach ($probes as [$m, $p, $b, $label]) {
        $r = req($m, $p, $b, $jarEmp);
        is_true("Employee cannot $label (expect 403)", $r['code'] === 403, "HTTP {$r['code']} " . ($r['json']['message'] ?? ''));
        if ($r['code'] !== 403) {
            finding("AUTHORIZATION GAP: an Employee session was allowed to $label ($m $p) -> HTTP {$r['code']}. Payroll endpoints should require MAKER_ROLES.");
        }
    }
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM departments WHERE department_name = 'DSH Unauthorized Dept'"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM payroll_periods WHERE start_date = '2027-06-01' AND end_date = '2027-06-15'"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM employees WHERE employee_number = 'DSH-UNAUTH'"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM loans WHERE loan_type = 'DSHUNAUTH'"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM overtime_records WHERE ot_type = 'DSHUNAUTH'"));

    // =================================================================
    section('3. Departments / positions / salary rates');
    // =================================================================
    $r = req('POST', 'departments.php', ['department_name' => 'DSH Dept'], $jar);
    eq('POST department -> 200', $r['code'], 200);
    $deptId = (int) (dbRow("SELECT department_id FROM departments WHERE department_name = 'DSH Dept'")['department_id'] ?? 0);
    is_true('department row created', $deptId > 0, 'not found in DB');
    onCleanup(fn() => $GLOBALS['pdo']->exec("UPDATE employees SET department_id = NULL WHERE department_id = $deptId"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM departments WHERE department_id = $deptId"));

    $r = req('POST', 'departments.php', ['department_name' => ''], $jar);
    eq('POST department without a name -> 422', $r['code'], 422);

    $r = req('PUT', 'departments.php', ['department_id' => $deptId, 'department_name' => 'DSH Dept Renamed'], $jar);
    eq('PUT department -> 200', $r['code'], 200);
    eq('department rename persisted', dbRow("SELECT department_name FROM departments WHERE department_id = $deptId")['department_name'], 'DSH Dept Renamed');

    $r = req('POST', 'positions.php', ['title' => 'DSH Position', 'department_id' => $deptId], $jar);
    eq('POST position -> 200', $r['code'], 200);
    $posId = (int) (dbRow("SELECT position_id FROM positions WHERE title = 'DSH Position'")['position_id'] ?? 0);
    is_true('position row created', $posId > 0, 'not found in DB');
    onCleanup(fn() => $GLOBALS['pdo']->exec("UPDATE employees SET position_id = NULL WHERE position_id = $posId"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM positions WHERE position_id = $posId"));

    $r = req('POST', 'salary_rates.php', ['employee_id' => $empId, 'basic_salary' => 25000, 'rate_type' => 'Monthly', 'effective_date' => '2027-01-01'], $jar);
    eq('POST salary rate -> 200', $r['code'], 200);
    $rateId = (int) (dbRow("SELECT rate_id FROM salary_rates WHERE employee_id = $empId")['rate_id'] ?? 0);
    is_true('salary rate row created', $rateId > 0, 'not found in DB');
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM salary_rates WHERE employee_id = $empId"));

    $r = req('POST', 'salary_rates.php', ['employee_id' => $empId, 'basic_salary' => 0, 'effective_date' => '2027-01-01'], $jar);
    eq('POST salary rate with 0 salary -> 422', $r['code'], 422);

    // =================================================================
    section('4. Leave types + balance initialisation');
    // =================================================================
    $r = req('POST', 'leave_types.php', ['name' => 'DSH Leave', 'days_per_year' => 2], $jar);
    eq('POST leave type -> 200', $r['code'], 200);
    $ltId = (int) (dbRow("SELECT leave_type_id FROM leave_types WHERE name = 'DSH Leave'")['leave_type_id'] ?? 0);
    is_true('leave type row created', $ltId > 0, 'not found in DB');
    eq('leave type is forced to paid', (int) dbRow("SELECT is_paid FROM leave_types WHERE leave_type_id = $ltId")['is_paid'], 1);
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM leave_requests WHERE leave_type_id = $ltId"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM leave_balances WHERE leave_type_id = $ltId"));
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM leave_types WHERE leave_type_id = $ltId"));

    $r = req('POST', 'leave_types.php', ['name' => ''], $jar);
    eq('POST leave type without a name -> 422', $r['code'], 422);

    $r = req('POST', 'leave_balances.php', ['year' => 2027], $jar);
    eq('POST leave balances (initialise year) -> 200', $r['code'], 200);
    $bal = dbRow("SELECT remaining_days FROM leave_balances WHERE employee_id = $empId AND leave_type_id = $ltId AND year = 2027");
    is_true('balance row created for the new employee', $bal !== false, 'no leave_balances row');
    if ($bal) eq('balance seeded from days_per_year', $bal['remaining_days'], 2.00);

    $r = req('POST', 'leave_balances.php', ['year' => 1999], $jar);
    eq('POST leave balances with an invalid year -> 422', $r['code'], 422);

    // =================================================================
    section('5. QR codes');
    // =================================================================
    $r = req('POST', 'qr_codes.php', ['employee_id' => $empId], $jar);
    eq('POST qr code -> 200', $r['code'], 200);
    $qrId = (int) (dbRow("SELECT qr_code_id FROM qr_codes WHERE employee_id = $empId ORDER BY qr_code_id DESC LIMIT 1")['qr_code_id'] ?? 0);
    is_true('qr code row created', $qrId > 0, 'not found in DB');
    $qrVal = $qrId ? dbRow("SELECT code_value, status FROM qr_codes WHERE qr_code_id = $qrId") : null;
    if ($qrVal) {
        is_true('generated code value looks like a UNITOP token', str_starts_with((string) $qrVal['code_value'], 'UNITOP-'), (string) $qrVal['code_value']);
        eq('new qr code is Active', $qrVal['status'], 'Active');
    }
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM qr_codes WHERE employee_id = $empId"));

    $r = req('POST', 'qr_codes.php', ['employee_id' => 999999], $jar);
    eq('POST qr code for a nonexistent employee -> 404', $r['code'], 404);

    // =================================================================
    section('6. Payroll periods: create, overlap guard, edit, status');
    // =================================================================
    $r = req('POST', 'payroll_periods.php', ['start_date' => '2027-07-01', 'end_date' => '2027-07-15', 'period_type' => 'Semi-monthly'], $jar);
    eq('POST payroll period -> 200', $r['code'], 200);
    $perId = (int) (dbRow("SELECT period_id FROM payroll_periods WHERE start_date = '2027-07-01'")['period_id'] ?? 0);
    is_true('period row created and Open', $perId > 0 && dbRow("SELECT status FROM payroll_periods WHERE period_id = $perId")['status'] === 'Open');
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM payroll_periods WHERE period_id = $perId"));

    $r = req('POST', 'payroll_periods.php', ['start_date' => '2027-07-10', 'end_date' => '2027-07-20', 'period_type' => 'Semi-monthly'], $jar);
    eq('overlapping period of the same type -> 409', $r['code'], 409);

    $r = req('POST', 'payroll_periods.php', ['start_date' => '2027-07-20', 'end_date' => '2027-07-01'], $jar);
    eq('period with end before start -> 422', $r['code'], 422);

    $r = req('PUT', 'payroll_periods.php', ['period_id' => $perId, 'start_date' => '2027-07-01', 'end_date' => '2027-07-16', 'period_type' => 'Semi-monthly'], $jar);
    eq('PUT open period -> 200', $r['code'], 200);

    $r = req('PATCH', 'payroll_periods.php', ['period_id' => $perId, 'status' => 'Closed'], $jar);
    eq('PATCH period to Closed -> 200', $r['code'], 200);
    eq('period is Closed', dbRow("SELECT status FROM payroll_periods WHERE period_id = $perId")['status'], 'Closed');
    $r = req('PATCH', 'payroll_periods.php', ['period_id' => $perId, 'status' => 'Bogus'], $jar);
    eq('PATCH with an invalid status -> 422', $r['code'], 422);

    // =================================================================
    section('7. Employees: create, update, soft delete, edge cases');
    // =================================================================
    $r = req('POST', 'employees.php', [
        'employee_number' => 'DSH-EMP-B', 'first_name' => 'Full', 'last_name' => 'Record',
        'birth_date' => '1995-05-05', 'civil_status' => 'Single',
        'position_id' => $posId, 'department_id' => $deptId,
        'date_hired' => '2027-01-01', 'employment_status' => 'Active',
        'basic_salary' => 30000, 'tin_number' => 'T', 'sss_number' => 'S',
        'philhealth_number' => 'P', 'pagibig_number' => 'G',
    ], $jar);
    eq('POST employee with every field -> 200', $r['code'], 200);
    $newEmp = (int) (dbRow("SELECT employee_id FROM employees WHERE employee_number = 'DSH-EMP-B'")['employee_id'] ?? 0);
    is_true('employee row created and active', $newEmp > 0 && (int) dbRow("SELECT is_active FROM employees WHERE employee_id = $newEmp")['is_active'] === 1);
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM employees WHERE employee_number LIKE 'DSH-EMP-%'"));

    $r = req('POST', 'employees.php', ['employee_number' => 'DSH-EMP-B', 'first_name' => 'Dup', 'last_name' => 'Number', 'basic_salary' => 1000], $jar);
    eq('duplicate employee_number -> 409', $r['code'], 409);

    $r = req('POST', 'employees.php', ['employee_number' => 'DSH-EMP-C', 'first_name' => 'No', 'last_name' => 'Salary'], $jar);
    eq('employee without basic_salary -> 422', $r['code'], 422);

    // Optional FK columns left empty: the JS sends "" for unset selects.
    $r = req('POST', 'employees.php', [
        'employee_number' => 'DSH-EMP-D', 'first_name' => 'No', 'last_name' => 'Dept',
        'basic_salary' => 15000, 'department_id' => '', 'position_id' => '',
    ], $jar);
    printf("  employee with empty department_id/position_id -> HTTP %d %s\n", $r['code'], $r['json']['message'] ?? '');
    is_true('employee can be created without a department/position', $r['code'] === 200,
        "HTTP {$r['code']}: " . ($r['json']['message'] ?? ''));
    if ($r['code'] !== 200 && str_contains((string) ($r['json']['message'] ?? ''), 'employee number may already exist')) {
        finding('employees.php POST/PUT report "employee number may already exist" (409) when the real failure is an empty position_id/department_id being inserted into an INT column - the error message hides the true cause.');
    }
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM employees WHERE employee_number LIKE 'DSH-EMP-%'"));

    $r = req('PATCH', 'employees.php', ['employee_id' => $newEmp, 'is_active' => 0], $jar);
    eq('PATCH employee to inactive -> 200', $r['code'], 200);
    eq('employee is inactive', (int) dbRow("SELECT is_active FROM employees WHERE employee_id = $newEmp")['is_active'], 0);

    // =================================================================
    section('8. Attendance write path (POST / PUT / PATCH / DELETE)');
    // =================================================================
    $r = req('POST', 'attendance.php', ['employee_id' => $empId, 'work_date' => '2027-02-01', 'time_in' => '08:30', 'time_out' => '17:00'], $jar);
    eq('POST attendance with time in+out -> 200', $r['code'], 200);
    $att = dbRow("SELECT * FROM attendance WHERE employee_id = $empId AND work_date = '2027-02-01'");
    is_true('attendance row created', $att !== false, 'no row');
    if ($att) {
        eq('minutes_late = 30 for an 08:30 clock-in', $att['minutes_late'], 30);
        eq('hours_worked = 7.50 (8h30m less 1h lunch)', $att['hours_worked'], '7.50');
        eq('status = Late', $att['status'], 'Late');
        eq('scan_device_id = Manual', $att['scan_device_id'], 'Manual');
        $attId = (int) $att['attendance_id'];
        onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM attendance WHERE attendance_id = $attId"));
    } else { $attId = 0; }

    $r = req('POST', 'attendance.php', ['employee_id' => $empId, 'work_date' => '2027-02-01', 'time_in' => '08:00'], $jar);
    eq('duplicate attendance for the same date -> 409', $r['code'], 409);

    $r = req('PATCH', 'attendance.php', ['employee_id' => $empId, 'work_date' => '2027-02-02', 'time_out' => '17:00'], $jar);
    eq('PATCH time-out with no prior clock-in -> 404', $r['code'], 404);

    $r = req('POST', 'attendance.php', ['employee_id' => $empId, 'work_date' => '2027-02-03', 'time_in' => '08:00'], $jar);
    eq('POST attendance with time-in only -> 200', $r['code'], 200);
    $open = dbRow("SELECT * FROM attendance WHERE employee_id = $empId AND work_date = '2027-02-03'");
    if ($open) {
        eq('open record has no time_out', $open['time_out'], null);
        eq('open record status = Clocked In', $open['status'], 'Clocked In');
        $openId = (int) $open['attendance_id'];
        onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM attendance WHERE attendance_id = $openId"));
    }
    $r = req('PATCH', 'attendance.php', ['employee_id' => $empId, 'work_date' => '2027-02-03', 'time_out' => '17:00'], $jar);
    eq('PATCH time-out on an open record -> 200', $r['code'], 200);
    $closed = dbRow("SELECT * FROM attendance WHERE employee_id = $empId AND work_date = '2027-02-03'");
    if ($closed) { eq('closed record now has 8.00 hours', $closed['hours_worked'], '8.00'); eq('closed record status = Present', $closed['status'], 'Present'); }

    if ($attId) {
        $r = req('PUT', 'attendance.php', ['attendance_id' => $attId, 'employee_id' => $empId, 'work_date' => '2027-02-01', 'time_in' => '08:00', 'time_out' => '17:00'], $jar);
        eq('PUT attendance edit -> 200', $r['code'], 200);
        $edited = dbRow("SELECT * FROM attendance WHERE attendance_id = $attId");
        eq('edited record now has 0 late minutes', $edited['minutes_late'], 0);
        eq('edited record hours = 8.00', $edited['hours_worked'], '8.00');

        $r = req('DELETE', "attendance.php?id=$attId", null, $jar);
        eq('DELETE attendance -> 200', $r['code'], 200);
        is_true('attendance row removed', dbRow("SELECT attendance_id FROM attendance WHERE attendance_id = $attId") === false);
    }

    // =================================================================
    section('9. Leave request lifecycle');
    // =================================================================
    $d = new DateTime('2027-01-01');
    while ((int) $d->format('N') !== 1) $d->modify('+1 day');   // first Monday of 2027
    $mon = $d->format('Y-m-d');
    $tue = (clone $d)->modify('+1 day')->format('Y-m-d');
    $sat = (clone $d)->modify('+5 day')->format('Y-m-d');
    $sun = (clone $d)->modify('+6 day')->format('Y-m-d');
    printf("  using weekdays %s / %s and weekend %s / %s\n", $mon, $tue, $sat, $sun);

    $r = req('POST', 'leave_requests.php', ['employee_id' => $empId, 'leave_type_id' => $ltId, 'start_date' => $mon, 'end_date' => $tue], $jar);
    eq('POST leave request over 2 working days -> 200', $r['code'], 200);
    $lr = dbRow("SELECT * FROM leave_requests WHERE employee_id = $empId AND leave_type_id = $ltId ORDER BY leave_id DESC LIMIT 1");
    is_true('leave request row created as Pending', $lr !== false && $lr['status'] === 'Pending', json_encode($lr));
    $lrId = $lr ? (int) $lr['leave_id'] : 0;
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM leave_requests WHERE employee_id = $empId"));

    $r = req('POST', 'leave_requests.php', ['employee_id' => $empId, 'leave_type_id' => $ltId, 'start_date' => $mon, 'end_date' => $tue], $jar);
    eq('overlapping pending request -> 409', $r['code'], 409);

    $r = req('POST', 'leave_requests.php', ['employee_id' => $empId, 'leave_type_id' => $ltId, 'start_date' => $sat, 'end_date' => $sun], $jar);
    printf("  leave spanning only %s(Sat)..%s(Sun) -> HTTP %d %s\n", $sat, $sun, $r['code'], $r['json']['message'] ?? '');
    eq('weekend-only leave range rejected -> 422', $r['code'], 422);

    $before = (float) (dbRow("SELECT remaining_days FROM leave_balances WHERE employee_id = $empId AND leave_type_id = $ltId AND year = 2027")['remaining_days'] ?? -1);
    $r = req('PATCH', 'leave_requests.php', ['leave_id' => $lrId, 'status' => 'Approved'], $jar);
    eq('PATCH leave request to Approved -> 200', $r['code'], 200);
    $after = (float) (dbRow("SELECT remaining_days FROM leave_balances WHERE employee_id = $empId AND leave_type_id = $ltId AND year = 2027")['remaining_days'] ?? -1);
    printf("  balance %s -> %s after approving 2 days\n", $before, $after);
    eq('approval deducts 2 days from the balance', $after, $before - 2);

    $r = req('PATCH', 'leave_requests.php', ['leave_id' => $lrId, 'status' => 'Approved'], $jar);
    eq('approving an already-approved request -> 409', $r['code'], 409);

    $r = req('POST', 'leave_requests.php', ['employee_id' => $empId, 'leave_type_id' => $ltId, 'start_date' => $mon, 'end_date' => $mon], $jar);
    eq('request exceeding the remaining balance -> 409', $r['code'], 409);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    printf("\nend: employees=%d users=%d periods=%d attendance=%d leave_types=%d leave_balances=%d leave_requests=%d positions=%d departments=%d\n",
        dbCount('employees'), dbCount('users'), dbCount('payroll_periods'),
        dbCount('attendance'), dbCount('leave_types'), dbCount('leave_balances'),
        dbCount('leave_requests'), dbCount('positions'), dbCount('departments'));
    $restored = true;
    $drift = [];
    foreach ($CRUD_WATCH as $t) {
        $now = dbCount($t);
        if ($now !== $CRUD_START[$t]) { $drift[] = "$t {$CRUD_START[$t]}->$now"; $restored = false; }
    }
    is_true('database fully restored to its pre-test state', $restored, implode(', ', $drift));
}

summary();
