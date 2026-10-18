<?php
// =====================================================================
// tests/supervisor_scope_test.php
//
// The Supervisor role (segregation of duties, step 6).
//
//   Supervisor CAN     view their team's attendance, approve or reject their
//                      team's attendance corrections / overtime / leave, and
//                      RECOMMEND (never approve) a team member's loan.
//   Supervisor CANNOT  see salaries, edit payroll, approve their own request,
//                      or reach anything outside their own team.
//
//   1. the GET lists are team-scoped (another team's rows are absent)
//   2. a team member's correction / overtime / leave can be approved
//   3. another team's request -> 403
//   4. the Supervisor's OWN request -> 403 (own-request rule)
//   5. a Supervisor with no employee_id -> 403 on every decision, never an error
//   6. a loan recommendation is recorded and the loan status is UNCHANGED
//   7. a Supervisor cannot approve a loan -> 403
//   8. dashboard / employees / salary rates / payroll runs / payslips -> 403
//   9. the maker's existing powers are unchanged (regression)
//  10. every approval / rejection / recommendation is in audit_log
//
// Real data is untouched: everything runs on DSH-SUP-* fixtures and on work
// dates that are not inside any Approved / Released payroll run. Employees
// 4/5/8 (Vinchy, Juan, Harry) are snapshotted and asserted unchanged.
//
// Run:  C:\xampp\php\php.exe tests\supervisor_scope_test.php
// =====================================================================

require __DIR__ . '/_harness.php';
require __DIR__ . '/../api/_payroll_helpers.php';

// ---------------------------------------------------------------------
// Fixture builders
// ---------------------------------------------------------------------

/** Work dates that no Approved / Released payroll run covers. */
function freeWorkDates(int $count, string $from = '2029-01-01'): array {
    global $pdo;
    $out    = [];
    $cursor = new DateTime($from);
    while (count($out) < $count) {
        $date = $cursor->format('Y-m-d');
        if (attendanceDateLock($pdo, $date) === null) $out[] = $date;
        $cursor->modify('+1 day');
    }
    return $out;
}

function mkCorrection(int $employeeId, string $workDate, string $type = 'both',
                      ?string $timeIn = '08:05', ?string $timeOut = '17:10'): int {
    global $pdo;
    $pdo->prepare(
        "INSERT INTO attendance_corrections
           (employee_id, attendance_id, work_date, correction_type,
            requested_time_in, requested_time_out, reason, status)
         VALUES (?, NULL, ?, ?, ?, ?, 'Test correction filed by the supervisor suite.', 'Pending')"
    )->execute([$employeeId, $workDate, $type, $timeIn, $timeOut]);
    return (int) $pdo->lastInsertId();
}

function mkOvertime(int $employeeId, string $otDate, string $status = 'Pending'): int {
    global $pdo;
    $pdo->prepare(
        "INSERT INTO overtime_records
           (employee_id, ot_date, hours, ot_type, rate_multiplier, status, requested_at)
         VALUES (?, ?, NULL, 'Test OT', 1.25, ?, NOW())"
    )->execute([$employeeId, $otDate, $status]);
    return (int) $pdo->lastInsertId();
}

function mkLeave(int $employeeId, int $leaveTypeId, string $startDate,
                 string $endDate = '', string $status = 'Pending'): int {
    global $pdo;
    if ($endDate === '') $endDate = $startDate;
    $pdo->prepare(
        "INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, status)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$employeeId, $leaveTypeId, $startDate, $endDate, $status]);
    return (int) $pdo->lastInsertId();
}

function mkLoan(int $employeeId, string $status = 'Pending', float $principal = 12000.0): int {
    global $pdo;
    $pdo->prepare(
        "INSERT INTO loans (employee_id, loan_type, principal_amount, monthly_amortization, status)
         VALUES (?, 'Test Loan', ?, 1000, ?)"
    )->execute([$employeeId, $principal, $status]);
    return (int) $pdo->lastInsertId();
}

function mkAttendance(int $employeeId, string $workDate,
                      string $timeIn = '08:00:00', ?string $timeOut = '17:00:00'): int {
    global $pdo;
    $pdo->prepare(
        "INSERT INTO attendance
           (employee_id, qr_code_id, work_date, time_in, time_out,
            scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, ?, ?, 'DSH-SUP', NULL, 0, ?)"
    )->execute([$employeeId, $workDate, $timeIn, $timeOut, $timeOut === null ? 'Clocked In' : 'Present']);
    return (int) $pdo->lastInsertId();
}

/** Ids of a list payload, for "is this row in the list" assertions. */
function idsOf(array $rows, string $key): array {
    return array_map(fn($r) => (int) ($r[$key] ?? 0), $rows);
}

/** True when every row belongs to one of $employeeIds. */
function onlyFromTeam(array $rows, array $employeeIds, string $key = 'employee_id'): bool {
    foreach ($rows as $r) {
        if (!in_array((int) ($r[$key] ?? 0), $employeeIds, true)) return false;
    }
    return true;
}

function rowStatus(string $table, string $idColumn, int $id): ?string {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status FROM `$table` WHERE `$idColumn` = ?");
    $stmt->execute([$id]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

// ---------------------------------------------------------------------
// Pre-test snapshot
// ---------------------------------------------------------------------

const REAL_EMPLOYEES = [4, 5, 8];

$REAL_BEFORE = [];
foreach (REAL_EMPLOYEES as $id) {
    $REAL_BEFORE[$id] = dbRow("SELECT employee_id, employee_number, first_name, last_name,
                                      department_id, position_id, basic_salary, is_active,
                                      supervisor_id
                               FROM employees WHERE employee_id = $id");
}

const WATCH_TABLES = ['employees', 'users', 'attendance', 'attendance_corrections',
                      'overtime_records', 'leave_requests', 'leave_balances', 'loans',
                      'payroll_periods', 'payroll_runs'];
$COUNT_BEFORE = [];
foreach (WATCH_TABLES as $t) $COUNT_BEFORE[$t] = dbCount($t);

$AUDIT_MAX = (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);

// ---------------------------------------------------------------------
// State carried between sections
// ---------------------------------------------------------------------

$empSup   = 0;   // the Supervisor's own employee record
$empSup2  = 0;   // another team's lead
$empT1    = 0;   // team member 1
$empT2    = 0;   // team member 2
$empX     = 0;   // another team's member

$userSup   = 0;
$userNoEmp = 0;
$userMaker = 0;
$userCheck = 0;

$corrT1 = 0; $corrT2 = 0; $corrX = 0; $corrSelf = 0; $corrT1b = 0; $corrMaker = 0;
$otT1   = 0; $otT2   = 0; $otX   = 0; $otSelf   = 0; $otNoEmp   = 0; $otMaker = 0;
$lvT1   = 0; $lvT2   = 0; $lvX   = 0; $lvSelf   = 0; $lvNoEmp   = 0; $lvMaker = 0;
$loanT1 = 0; $loanX  = 0; $loanSelf = 0; $loanNoEmp = 0; $loanMaker = 0; $loanChecker = 0;

$fixtureEmployees = [];

try {
    // =================================================================
    // Fixtures: two teams
    //
    //   empSup  leads  empT1, empT2
    //   empSup2 leads  empX
    //
    // Neither supervisor is a member of their own team, so the team test and
    // the own-request rule are independent checks.
    // =================================================================
    $empSup   = mkEmployee('DSH-SUP-BOSS-A', 'Supervisor', 'Alpha', 40000.00);
    $empSup2  = mkEmployee('DSH-SUP-BOSS-B', 'Supervisor', 'Bravo', 40000.00);
    $empT1    = mkEmployee('DSH-SUP-T1', 'Team', 'One', 30000.00);
    $empT2    = mkEmployee('DSH-SUP-T2', 'Team', 'Two', 30000.00);
    $empX     = mkEmployee('DSH-SUP-X1', 'Other', 'Team', 30000.00);
    $fixtureEmployees = [$empSup, $empSup2, $empT1, $empT2, $empX];

    $GLOBALS['pdo']->exec("UPDATE employees SET supervisor_id = $empSup  WHERE employee_id IN ($empT1, $empT2)");
    $GLOBALS['pdo']->exec("UPDATE employees SET supervisor_id = $empSup2 WHERE employee_id = $empX");

    $userSup   = mkUser('dsh_sup_boss', 'Supervisor', $empSup);
    $userNoEmp = mkUser('dsh_sup_nobody', 'Supervisor');            // no employee link
    $userMaker = mkUser('dsh_sup_maker', 'Payroll Officer');
    $userCheck = mkUser('dsh_sup_check', 'Approver');
    mkUser('dsh_sup_admin', 'Administrator');

    // Registered AFTER mkEmployee(), so these run BEFORE the employee teardown
    // (the cleanup registry executes in reverse order).
    onCleanup(function () use ($fixtureEmployees) {
        $in = implode(',', array_map('intval', $fixtureEmployees));
        $GLOBALS['pdo']->exec("DELETE FROM attendance_corrections WHERE employee_id IN ($in)");
        $GLOBALS['pdo']->exec("UPDATE employees SET supervisor_id = NULL WHERE employee_id IN ($in)");
    });

    $jarSup    = login('dsh_sup_boss');
    $jarNoEmp  = login('dsh_sup_nobody');
    $jarMaker  = login('dsh_sup_maker');
    $jarCheck  = login('dsh_sup_check');
    $jarAdmin  = login('dsh_sup_admin');

    $leaveType = mkLeaveType('DSH Supervisor Leave', 12);

    $dates = freeWorkDates(20);
    [$dC1, $dC2, $dC3, $dC4, $dC5, $dC6]       = array_slice($dates, 0, 6);
    [$dO1, $dO2, $dO3, $dO4, $dO5, $dO6]       = array_slice($dates, 6, 6);
    [$dL1, $dL2, $dL3, $dL4, $dL5, $dL6]       = array_slice($dates, 12, 6);
    [$dV, $dAtt]                               = array_slice($dates, 18, 2);

    printf("  fixture teams: supervisor #%d leads %d,%d; supervisor #%d leads %d\n",
        $empSup, $empT1, $empT2, $empSup2, $empX);

    // ---- corrections -------------------------------------------------
    $corrT1    = mkCorrection($empT1,    $dC1);          // approve (team)
    $corrT2    = mkCorrection($empT2,    $dC2);          // reject  (team)
    $corrX     = mkCorrection($empX,     $dC3);          // another team -> 403
    $corrSelf  = mkCorrection($empSup,   $dC4);          // own request  -> 403
    $corrT1b   = mkCorrection($empT1,    $dC5);          // no-employee-link -> 403
    $corrMaker = mkCorrection($empX,     $dC6);          // maker regression

    // ---- overtime ----------------------------------------------------
    $otT1     = mkOvertime($empT1,  $dO1);
    $otT2     = mkOvertime($empT2,  $dO2);
    $otX      = mkOvertime($empX,   $dO3);
    $otSelf   = mkOvertime($empSup, $dO4);
    $otNoEmp  = mkOvertime($empT1,  $dO5);
    $otMaker  = mkOvertime($empX,   $dO6);

    // ---- leave -------------------------------------------------------
    $lvT1    = mkLeave($empT1,  $leaveType, $dL1);
    $lvT2    = mkLeave($empT2,  $leaveType, $dL2);
    $lvX     = mkLeave($empX,   $leaveType, $dL3);
    $lvSelf  = mkLeave($empSup, $leaveType, $dL4);
    $lvNoEmp = mkLeave($empT1,  $leaveType, $dL5);
    $lvMaker = mkLeave($empT2,  $leaveType, $dL6);

    // ---- loans -------------------------------------------------------
    $loanT1      = mkLoan($empT1,    'Pending');
    $loanX       = mkLoan($empX,     'Pending');
    $loanSelf    = mkLoan($empSup,   'Pending');
    $loanNoEmp   = mkLoan($empT1,    'Pending');
    $loanMaker   = mkLoan($empT2,    'Pending');
    $loanChecker = mkLoan($empX,     'Pending');

    // ---- attendance for the read-scope checks ------------------------
    $attT1 = mkAttendance($empT1, $dV);
    $attX  = mkAttendance($empX,  $dV);
    mkAttendance($empT1, $dAtt, '08:00:00', '17:00:00');

    // =================================================================
    section('0. The schema, the role, and the team definition');
    // =================================================================
    $col = dbRow("SHOW COLUMNS FROM employees LIKE 'supervisor_id'");
    is_true('employees.supervisor_id exists', $col !== null, 'column missing');
    eq('supervisor_id is nullable', $col['Null'] ?? null, 'YES');

    $indexCount = dbRow("SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
                           AND COLUMN_NAME = 'supervisor_id'");
    is_true('employees.supervisor_id is indexed', (int) $indexCount['n'] > 0, 'no index');

    $fk = dbRow("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
                   AND CONSTRAINT_NAME = 'fk_employees_supervisor'");
    is_true('the self-referencing foreign key exists', $fk !== null, 'constraint missing');
    eq('deleting a supervisor sets the team members NULL', $fk['DELETE_RULE'] ?? null, 'SET NULL');

    foreach (['recommended_by', 'recommended_at', 'recommendation_remarks'] as $c) {
        is_true("loans.$c exists", dbRow("SHOW COLUMNS FROM loans LIKE '$c'") !== null, 'column missing');
    }

    // The team is "every employee whose supervisor_id points at that employee",
    // which is exactly the query supervisorTeamIds() runs; section 1 proves the
    // helpers behind it over real HTTP.
    $teamRows = dbAll("SELECT employee_id FROM employees WHERE supervisor_id = $empSup ORDER BY employee_id");
    eq('supervisor #1 leads exactly T1 and T2',
        array_map('intval', array_column($teamRows, 'employee_id')), [$empT1, $empT2]);
    $team2Rows = dbAll("SELECT employee_id FROM employees WHERE supervisor_id = $empSup2 ORDER BY employee_id");
    eq("supervisor #2 leads exactly X",
        array_map('intval', array_column($team2Rows, 'employee_id')), [$empX]);
    eq('a supervisor is not a member of their own team',
        dbCount('employees', "employee_id = $empSup AND supervisor_id = $empSup"), 0);

    // 'Supervisor' is a role the users screen may assign (ALL_ROLES), and a
    // bogus role is still refused - the list was extended, not relaxed.
    $r = req('POST', 'users.php', [
        'username' => 'dsh_sup_created_' . getmypid(),
        'role'     => 'Supervisor',
        'employee_id' => $empT2,
        'password' => TEST_PW,
    ], $jarAdmin);
    eq('an Administrator can create a Supervisor user -> 200', $r['code'], 200);
    $createdId = (int) ($r['json']['data']['user_id'] ?? 0);
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM users WHERE username LIKE 'dsh\\_sup\\_created\\_%'"));
    eq('the created user really has the Supervisor role',
        dbRow("SELECT role FROM users WHERE user_id = $createdId")['role'] ?? null, 'Supervisor');

    $r = req('POST', 'users.php', [
        'username' => 'dsh_sup_created_bogus_' . getmypid(),
        'role'     => 'Line Manager',
        'password' => TEST_PW,
    ], $jarAdmin);
    eq('an unknown role is still refused -> 422', $r['code'], 422);

    $usersHtml = (string) file_get_contents(__DIR__ . '/../users.html');
    is_true('the users screen offers the Supervisor role',
        str_contains($usersHtml, '<option>Supervisor</option>'), 'option missing from users.html');

    // =================================================================
    section('1. GET lists are team-scoped');
    // =================================================================
    $TEAM = [$empT1, $empT2];

    // ---- attendance corrections
    $r = req('GET', 'attendance_corrections.php', null, $jarSup);
    eq('Supervisor GET attendance corrections -> 200', $r['code'], 200);
    $rows = $r['json']['data']['rows'] ?? [];
    is_true('only the Supervisor team rows are listed', onlyFromTeam($rows, $TEAM), json_encode(idsOf($rows, 'correction_id')));
    $ids = idsOf($rows, 'correction_id');
    is_true('a team member correction is listed', in_array($corrT1, $ids, true));
    is_true("another team's correction is NOT listed", !in_array($corrX, $ids, true));
    is_true('the Supervisor own correction is NOT listed', !in_array($corrSelf, $ids, true));
    eq('total counts only the team rows',
        (int) ($r['json']['data']['total'] ?? -1),
        dbCount('attendance_corrections', "employee_id IN ($empT1, $empT2)"));

    $r = req('GET', 'attendance_corrections.php', null, $jarMaker);
    $makerIds = idsOf($r['json']['data']['rows'] ?? [], 'correction_id');
    eq('the maker still sees the whole queue -> 200', $r['code'], 200);
    is_true('the maker queue includes the other team', in_array($corrX, $makerIds, true));
    is_true('the maker queue includes the Supervisor own row', in_array($corrSelf, $makerIds, true));

    $r = req('GET', 'attendance_corrections.php', null, $jarCheck);
    eq('the checker still sees the whole queue -> 200', $r['code'], 200);

    // ---- overtime
    $r = req('GET', 'overtime_records.php?all=1', null, $jarSup);
    eq('Supervisor GET overtime -> 200', $r['code'], 200);
    $rows = $r['json']['data'] ?? [];
    is_true('only the Supervisor team overtime is listed', onlyFromTeam($rows, $TEAM), json_encode(idsOf($rows, 'ot_id')));
    $ids = idsOf($rows, 'ot_id');
    is_true('a team member overtime request is listed', in_array($otT1, $ids, true));
    is_true("another team's overtime is NOT listed", !in_array($otX, $ids, true));
    is_true('the Supervisor own overtime is NOT listed', !in_array($otSelf, $ids, true));

    $r = req('GET', 'overtime_records.php?all=1', null, $jarMaker);
    eq('the maker still sees every overtime row -> 200', $r['code'], 200);
    is_true('the maker overtime list includes the other team',
        in_array($otX, idsOf($r['json']['data'] ?? [], 'ot_id'), true));

    // ---- leave
    $r = req('GET', 'leave_requests.php', null, $jarSup);
    eq('Supervisor GET leave -> 200', $r['code'], 200);
    $rows = $r['json']['data'] ?? [];
    is_true('only the Supervisor team leave is listed', onlyFromTeam($rows, $TEAM), json_encode(idsOf($rows, 'leave_id')));
    $ids = idsOf($rows, 'leave_id');
    is_true('a team member leave request is listed', in_array($lvT1, $ids, true));
    is_true("another team's leave is NOT listed", !in_array($lvX, $ids, true));
    is_true('the Supervisor own leave is NOT listed', !in_array($lvSelf, $ids, true));

    $r = req('GET', 'leave_requests.php', null, $jarMaker);
    eq('the maker still sees every leave request -> 200', $r['code'], 200);
    is_true('the maker leave list includes the other team',
        in_array($lvX, idsOf($r['json']['data'] ?? [], 'leave_id'), true));

    // ---- loans
    $r = req('GET', 'loans.php', null, $jarSup);
    eq('Supervisor GET loans -> 200', $r['code'], 200);
    $rows = $r['json']['data'] ?? [];
    is_true('only the Supervisor team loans are listed', onlyFromTeam($rows, $TEAM), json_encode(idsOf($rows, 'loan_id')));
    $ids = idsOf($rows, 'loan_id');
    is_true('a team member loan is listed', in_array($loanT1, $ids, true));
    is_true("another team's loan is NOT listed", !in_array($loanX, $ids, true));
    is_true('the Supervisor own loan is NOT listed', !in_array($loanSelf, $ids, true));

    $r = req('GET', 'loans.php', null, $jarMaker);
    eq('the maker still sees every loan -> 200', $r['code'], 200);
    is_true('the maker loan list includes the other team',
        in_array($loanX, idsOf($r['json']['data'] ?? [], 'loan_id'), true));

    // ---- attendance
    $r = req('GET', "attendance.php?date=$dV", null, $jarSup);
    eq('Supervisor GET attendance for a date -> 200', $r['code'], 200);
    $rows = $r['json']['data'] ?? [];
    is_true('only the Supervisor team attendance is listed', onlyFromTeam($rows, $TEAM), json_encode(idsOf($rows, 'attendance_id')));
    is_true('a team member attendance row is listed',
        in_array($attT1, idsOf($rows, 'attendance_id'), true));
    is_true("another team's attendance row is NOT listed",
        !in_array($attX, idsOf($rows, 'attendance_id'), true));

    $r = req('GET', "attendance.php?date=$dV", null, $jarMaker);
    eq('the maker still sees the whole day -> 200', $r['code'], 200);
    is_true('the maker day view includes the other team',
        in_array($attX, idsOf($r['json']['data'] ?? [], 'attendance_id'), true));

    $r = req('GET', "attendance.php?summary=1&from=$dV&to=$dV", null, $jarSup);
    eq('Supervisor GET the attendance summary -> 200', $r['code'], 200);
    $summaryIds = idsOf($r['json']['data']['employees'] ?? [], 'employee_id');
    eq('the summary lists only the team members', $summaryIds, $TEAM);

    $r = req('GET', "attendance.php?summary=1&from=$dV&to=$dV", null, $jarMaker);
    is_true('the maker summary still lists every active employee',
        count($r['json']['data']['employees'] ?? []) > count($summaryIds));

    $r = req('GET', "attendance.php?employee_detail=1&employee_id=$empT1&from=$dV&to=$dV", null, $jarSup);
    eq("Supervisor can open a team member's day-by-day detail -> 200", $r['code'], 200);

    $r = req('GET', "attendance.php?employee_detail=1&employee_id=$empX&from=$dV&to=$dV", null, $jarSup);
    eq("Supervisor CANNOT open another team's detail -> 403", $r['code'], 403);
    is_true('the 403 says the employee is not in the team',
        stripos((string) ($r['json']['message'] ?? ''), 'not in your team') !== false,
        (string) ($r['json']['message'] ?? ''));

    $r = req('GET', "attendance.php?id=$attT1", null, $jarSup);
    eq("Supervisor can read a team member's attendance row -> 200", $r['code'], 200);
    $r = req('GET', "attendance.php?id=$attX", null, $jarSup);
    eq("Supervisor CANNOT read another team's attendance row -> 403", $r['code'], 403);

    $r = req('GET', "attendance.php?employee_detail=1&employee_id=$empX&from=$dV&to=$dV", null, $jarMaker);
    eq('the maker is unaffected by the read scope -> 200', $r['code'], 200);

    // =================================================================
    section('2. A team member\'s request can be decided');
    // =================================================================
    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrT1, 'action' => 'approve'], $jarSup);
    eq("Supervisor approves a team member's correction -> 200", $r['code'], 200);
    eq('the correction is Approved', rowStatus('attendance_corrections', 'correction_id', $corrT1), 'Approved');
    $row = dbRow("SELECT reviewed_by FROM attendance_corrections WHERE correction_id = $corrT1");
    eq('reviewed_by is the Supervisor user', (int) $row['reviewed_by'], $userSup);
    is_true('the correction was applied to attendance',
        dbCount('attendance', "employee_id = $empT1 AND work_date = '$dC1' AND time_out = '17:10:00'") === 1);

    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrT2, 'action' => 'reject', 'remarks' => 'Clocked out at 17:00 per the kiosk log.'], $jarSup);
    eq("Supervisor rejects a team member's correction -> 200", $r['code'], 200);
    eq('the correction is Rejected', rowStatus('attendance_corrections', 'correction_id', $corrT2), 'Rejected');

    $r = req('PATCH', 'overtime_records.php',
        ['ot_id' => $otT1, 'action' => 'approve'], $jarSup);
    eq("Supervisor approves a team member's overtime -> 200", $r['code'], 200);
    eq('the overtime is Approved', rowStatus('overtime_records', 'ot_id', $otT1), 'Approved');

    $r = req('PATCH', 'overtime_records.php',
        ['ot_id' => $otT2, 'action' => 'reject'], $jarSup);
    eq("Supervisor rejects a team member's overtime -> 200", $r['code'], 200);
    eq('the overtime is Rejected', rowStatus('overtime_records', 'ot_id', $otT2), 'Rejected');

    $r = req('PATCH', 'leave_requests.php',
        ['leave_id' => $lvT1, 'status' => 'Approved'], $jarSup);
    eq("Supervisor approves a team member's leave -> 200", $r['code'], 200);
    eq('the leave is Approved', rowStatus('leave_requests', 'leave_id', $lvT1), 'Approved');

    $r = req('PATCH', 'leave_requests.php',
        ['leave_id' => $lvT2, 'status' => 'Rejected'], $jarSup);
    eq("Supervisor rejects a team member's leave -> 200", $r['code'], 200);
    eq('the leave is Rejected', rowStatus('leave_requests', 'leave_id', $lvT2), 'Rejected');

    // =================================================================
    section('3. Another team\'s request -> 403');
    // =================================================================
    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrX, 'action' => 'approve'], $jarSup);
    eq("Supervisor cannot approve another team's correction -> 403", $r['code'], 403);
    is_true('the 403 explains the cross-team refusal',
        stripos((string) ($r['json']['message'] ?? ''), 'not in your team') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq("another team's correction is still Pending",
        rowStatus('attendance_corrections', 'correction_id', $corrX), 'Pending');
    eq("no attendance row was created for another team's correction",
        dbCount('attendance', "employee_id = $empX AND work_date = '$dC3'"), 0);

    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrX, 'action' => 'reject', 'remarks' => 'Not mine to decide.'], $jarSup);
    eq("Supervisor cannot reject another team's correction either -> 403", $r['code'], 403);

    $r = req('PATCH', 'overtime_records.php',
        ['ot_id' => $otX, 'action' => 'approve'], $jarSup);
    eq("Supervisor cannot approve another team's overtime -> 403", $r['code'], 403);
    eq("another team's overtime is still Pending", rowStatus('overtime_records', 'ot_id', $otX), 'Pending');

    $r = req('PATCH', 'leave_requests.php',
        ['leave_id' => $lvX, 'status' => 'Approved'], $jarSup);
    eq("Supervisor cannot approve another team's leave -> 403", $r['code'], 403);
    eq("another team's leave is still Pending", rowStatus('leave_requests', 'leave_id', $lvX), 'Pending');

    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanX, 'action' => 'recommend', 'remarks' => 'Good payer.'], $jarSup);
    eq("Supervisor cannot recommend another team's loan -> 403", $r['code'], 403);
    eq("another team's loan has no recommendation",
        dbCount('loans', "loan_id = $loanX AND recommended_by IS NULL"), 1);

    // =================================================================
    section('4. The Supervisor\'s OWN request -> 403 (own-request rule)');
    // =================================================================
    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrSelf, 'action' => 'approve'], $jarSup);
    eq('Supervisor cannot approve their own correction -> 403', $r['code'], 403);
    is_true('the 403 says it is their own request',
        stripos((string) ($r['json']['message'] ?? ''), 'own request') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('their own correction is still Pending',
        rowStatus('attendance_corrections', 'correction_id', $corrSelf), 'Pending');

    $r = req('PATCH', 'overtime_records.php',
        ['ot_id' => $otSelf, 'action' => 'approve'], $jarSup);
    eq('Supervisor cannot approve their own overtime -> 403', $r['code'], 403);
    is_true('the overtime 403 also names the own-request rule',
        stripos((string) ($r['json']['message'] ?? ''), 'own request') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('their own overtime is still Pending', rowStatus('overtime_records', 'ot_id', $otSelf), 'Pending');

    $r = req('PATCH', 'leave_requests.php',
        ['leave_id' => $lvSelf, 'status' => 'Approved'], $jarSup);
    eq('Supervisor cannot approve their own leave -> 403', $r['code'], 403);
    is_true('the leave 403 also names the own-request rule',
        stripos((string) ($r['json']['message'] ?? ''), 'own request') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('their own leave is still Pending', rowStatus('leave_requests', 'leave_id', $lvSelf), 'Pending');

    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanSelf, 'action' => 'recommend'], $jarSup);
    eq('Supervisor cannot recommend their own loan -> 403', $r['code'], 403);
    eq('their own loan has no recommendation',
        dbCount('loans', "loan_id = $loanSelf AND recommended_by IS NULL"), 1);

    // =================================================================
    section('5. A Supervisor with no employee link decides nothing');
    // =================================================================
    $r = req('GET', 'auth.php?action=me', null, $jarNoEmp);
    eq('the unlinked Supervisor session resolves -> 200', $r['code'], 200);
    $me = $r['json']['data'] ?? [];
    is_true('it has no employee_id',
        array_key_exists('employee_id', $me) && $me['employee_id'] === null, json_encode($me));
    eq('its role is Supervisor', $me['role'] ?? null, 'Supervisor');

    foreach ([
        ['attendance_corrections.php', ['correction_id' => $corrT1b, 'action' => 'approve'], 'correction'],
        ['overtime_records.php',       ['ot_id' => $otNoEmp, 'action' => 'approve'],           'overtime'],
        ['leave_requests.php',         ['leave_id' => $lvNoEmp, 'status' => 'Approved'],        'leave'],
        ['loans.php',                  ['loan_id' => $loanNoEmp, 'action' => 'recommend'],      'loan'],
    ] as [$endpoint, $body, $label]) {
        $r = req('PATCH', $endpoint, $body, $jarNoEmp);
        eq("an unlinked Supervisor cannot decide a $label -> 403 (not 500)", $r['code'], 403);
        eq("the $label refusal is a normal JSON failure", $r['json']['success'] ?? null, false);
        is_true("the $label refusal explains the missing employee link",
            stripos((string) ($r['json']['message'] ?? ''), 'not linked to an employee') !== false,
            (string) ($r['json']['message'] ?? ''));
    }

    eq('the unlinked Supervisor changed nothing (correction)',
        rowStatus('attendance_corrections', 'correction_id', $corrT1b), 'Pending');
    eq('the unlinked Supervisor changed nothing (overtime)',
        rowStatus('overtime_records', 'ot_id', $otNoEmp), 'Pending');
    eq('the unlinked Supervisor changed nothing (leave)',
        rowStatus('leave_requests', 'leave_id', $lvNoEmp), 'Pending');
    eq('the unlinked Supervisor changed nothing (loan)',
        dbCount('loans', "loan_id = $loanNoEmp AND recommended_by IS NULL"), 1);

    // Their read lists are empty, not an error.
    $r = req('GET', 'attendance_corrections.php', null, $jarNoEmp);
    eq('an unlinked Supervisor GET corrections -> 200', $r['code'], 200);
    eq('... and sees no rows', count($r['json']['data']['rows'] ?? [0]), 0);
    eq('... with total 0', (int) ($r['json']['data']['total'] ?? -1), 0);
    $r = req('GET', 'overtime_records.php?all=1', null, $jarNoEmp);
    eq('an unlinked Supervisor GET overtime -> 200 with no rows', count($r['json']['data'] ?? [0]), 0);
    $r = req('GET', 'leave_requests.php', null, $jarNoEmp);
    eq('an unlinked Supervisor GET leave -> 200 with no rows', count($r['json']['data'] ?? [0]), 0);
    $r = req('GET', 'loans.php', null, $jarNoEmp);
    eq('an unlinked Supervisor GET loans -> 200 with no rows', count($r['json']['data'] ?? [0]), 0);

    // =================================================================
    section('6. Recommend a team member\'s loan (advice only)');
    // =================================================================
    $remarks = 'Three years of service with no missed amortisation; recommended for approval.';
    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanT1, 'action' => 'recommend', 'remarks' => $remarks], $jarSup);
    eq("Supervisor recommends a team member's loan -> 200", $r['code'], 200);
    eq('the response reports the UNCHANGED status', $r['json']['data']['status'] ?? null, 'Pending');
    eq('the loan status is still Pending',
        rowStatus('loans', 'loan_id', $loanT1), 'Pending');
    is_true('the loan was NOT activated',
        dbCount('loans', "loan_id = $loanT1 AND status = 'Active'") === 0);

    $loanRow = dbRow("SELECT status, recommended_by, recommended_at, recommendation_remarks
                      FROM loans WHERE loan_id = $loanT1");
    eq('recommended_by is the Supervisor user', (int) $loanRow['recommended_by'], $userSup);
    is_true('recommended_at is recorded', $loanRow['recommended_at'] !== null);
    eq('recommendation_remarks is stored', $loanRow['recommendation_remarks'], $remarks);
    eq('the status column was not touched', $loanRow['status'], 'Pending');

    $r = req('GET', 'loans.php', null, $jarSup);
    $recommended = null;
    foreach (($r['json']['data'] ?? []) as $x) {
        if ((int) $x['loan_id'] === $loanT1) $recommended = $x;
    }
    is_true('the list exposes the recommendation', $recommended !== null);
    if ($recommended) {
        eq('has_recommendation is true', (bool) $recommended['has_recommendation'], true);
        eq('the recommender name is resolved', $recommended['recommended_by_name'] ?? null, 'dsh_sup_boss');
        eq('the remarks are exposed', $recommended['recommendation_remarks'] ?? null, $remarks);
        eq('the loan is still Pending in the list', $recommended['status'] ?? null, 'Pending');
    }

    // Recommending again just updates the advice; still no status change.
    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanT1, 'action' => 'recommend', 'remarks' => 'Revised: recommended at half the amount.'], $jarSup);
    eq('a repeat recommendation -> 200', $r['code'], 200);
    eq('the status is still Pending after a repeat',
        rowStatus('loans', 'loan_id', $loanT1), 'Pending');

    // The maker and the checker have no recommendation step.
    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanT1, 'action' => 'recommend'], $jarMaker);
    eq('the maker cannot recommend -> 403', $r['code'], 403);
    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanT1, 'action' => 'recommend'], $jarCheck);
    eq('the checker cannot recommend -> 403', $r['code'], 403);

    // =================================================================
    section('7. A Supervisor can never approve a loan');
    // =================================================================
    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanT1, 'action' => 'approve'], $jarSup);
    eq('Supervisor cannot approve a loan -> 403', $r['code'], 403);
    eq('the loan is still Pending', rowStatus('loans', 'loan_id', $loanT1), 'Pending');

    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanT1, 'status' => 'Active'], $jarSup);
    eq('Supervisor cannot force a loan status -> 403', $r['code'], 403);
    eq('the loan status is unchanged', rowStatus('loans', 'loan_id', $loanT1), 'Pending');

    // The Approver still decides, and the recommendation survives it.
    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanChecker, 'action' => 'approve'], $jarCheck);
    eq('the Approver still approves a loan -> 200', $r['code'], 200);
    eq('that loan is Active', rowStatus('loans', 'loan_id', $loanChecker), 'Active');
    $r = req('PATCH', 'loans.php',
        ['loan_id' => $loanT1, 'action' => 'approve'], $jarCheck);
    eq('the Approver approves the recommended loan -> 200', $r['code'], 200);
    eq('the loan is now Active', rowStatus('loans', 'loan_id', $loanT1), 'Active');
    eq('the recommendation is still recorded',
        (int) dbRow("SELECT recommended_by FROM loans WHERE loan_id = $loanT1")['recommended_by'], $userSup);

    // =================================================================
    section('8. Salary / payroll screens stay closed to a Supervisor');
    // =================================================================
    $closed = [
        'dashboard.php'                       => 'the dashboard',
        'employees.php'                       => 'the employee master file (salaries)',
        'employees.php?simple=1'              => 'the employee lookup',
        'salary_rates.php'                    => 'salary rates',
        'payroll_periods.php'                 => 'payroll periods',
        'payroll_runs.php'                    => 'payroll runs',
        'payslips.php?run_id=1'               => 'payslips',
        'reports.php?report=payroll_register&run_id=1' => 'reports',
        'ledger.php?employee_id=4&from=2027-01-01&to=2027-01-31' => 'the ledger',
        'departments.php'                     => 'departments',
        'positions.php'                       => 'positions',
        'qr_codes.php'                        => 'QR codes',
        'leave_types.php'                     => 'leave types',
        'leave_balances.php'                  => 'leave balances',
        'users.php'                           => 'users',
        'audit_log.php'                       => 'the audit log',
    ];
    foreach ($closed as $path => $label) {
        $r = req('GET', $path, null, $jarSup);
        eq("Supervisor cannot read $label -> 403", $r['code'], 403);
    }

    foreach ([
        ['POST', 'attendance.php', ['employee_id' => $empT1, 'work_date' => $dAtt, 'time_in' => '09:00']],
        ['PUT', 'attendance.php', ['attendance_id' => $attT1, 'employee_id' => $empT1, 'work_date' => $dV, 'time_in' => '09:00']],
        ['DELETE', "attendance.php?id=$attT1", null],
        ['POST', 'employees.php', []],
        ['POST', 'salary_rates.php', []],
        ['POST', 'payroll_periods.php', []],
        ['POST', 'leave_requests.php', []],
        ['POST', 'overtime_records.php', []],
        ['POST', 'loans.php', []],
    ] as [$method, $path, $body]) {
        $r = req($method, $path, $body, $jarSup);
        eq("Supervisor $method $path -> 403", $r['code'], 403);
    }

    eq('the team attendance row survived the refused writes',
        dbCount('attendance', "attendance_id = $attT1"), 1);

    // =================================================================
    section('9. The maker and the checker are unchanged (regression)');
    // =================================================================
    $r = req('GET', 'attendance_corrections.php', null, $jarMaker);
    eq('the maker GET corrections -> 200', $r['code'], 200);
    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrMaker, 'action' => 'approve'], $jarMaker);
    eq("the maker still approves any team's correction -> 200", $r['code'], 200);
    eq('it is Approved', rowStatus('attendance_corrections', 'correction_id', $corrMaker), 'Approved');

    $r = req('PATCH', 'overtime_records.php',
        ['ot_id' => $otMaker, 'action' => 'approve'], $jarMaker);
    eq("the maker still approves any team's overtime -> 200", $r['code'], 200);
    eq('it is Approved', rowStatus('overtime_records', 'ot_id', $otMaker), 'Approved');

    $r = req('PATCH', 'leave_requests.php',
        ['leave_id' => $lvMaker, 'status' => 'Approved'], $jarMaker);
    eq("the maker still approves any team's leave -> 200", $r['code'], 200);
    eq('it is Approved', rowStatus('leave_requests', 'leave_id', $lvMaker), 'Approved');

    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrX, 'action' => 'approve'], $jarMaker);
    eq('the maker still approves a request from the other team -> 200', $r['code'], 200);

    // The checker keeps read-only access everywhere a Supervisor reads.
    foreach (['attendance_corrections.php', 'overtime_records.php?all=1', 'leave_requests.php', 'loans.php'] as $path) {
        $r = req('GET', $path, null, $jarCheck);
        eq("the checker GET $path -> 200", $r['code'], 200);
    }
    $r = req('PATCH', 'attendance_corrections.php',
        ['correction_id' => $corrT2, 'action' => 'approve'], $jarCheck);
    eq('the checker still cannot decide a correction -> 403', $r['code'], 403);
    $r = req('PATCH', 'overtime_records.php',
        ['ot_id' => $otMaker, 'action' => 'approve'], $jarCheck);
    eq('the checker still cannot decide overtime -> 403', $r['code'], 403);

    // Unauthenticated stays 401 (not 403): the role guards did not replace login.
    $r = req('GET', 'attendance_corrections.php');
    eq('unauthenticated GET corrections -> 401', $r['code'], 401);
    $r = req('GET', 'loans.php');
    eq('unauthenticated GET loans -> 401', $r['code'], 401);

    // =================================================================
    section('10. Every decision is in audit_log');
    // =================================================================
    $logs = dbAll("SELECT * FROM audit_log WHERE audit_id > $AUDIT_MAX ORDER BY audit_id");
    is_true('the run produced audit rows', count($logs) > 0, 'no audit rows');

    $supLogs = array_values(array_filter($logs,
        fn($x) => ($x['username'] ?? null) === 'dsh_sup_boss'));
    is_true('the Supervisor decisions are attributed to their account', count($supLogs) > 0);
    eq('every Supervisor audit row carries the Supervisor role',
        count(array_filter($supLogs, fn($x) => ($x['role'] ?? null) !== 'Supervisor')), 0);

    $approvals = [
        ['attendance_corrections.php', 'correction_id', $corrT1,      200, 'a team correction approval'],
        ['attendance_corrections.php', 'correction_id', $corrT2,      200, 'a team correction rejection'],
        ['overtime_records.php',       'ot_id',         $otT1,        200, 'a team overtime approval'],
        ['overtime_records.php',       'ot_id',         $otT2,        200, 'a team overtime rejection'],
        ['leave_requests.php',         'leave_id',      $lvT1,        200, 'a team leave approval'],
        ['leave_requests.php',         'leave_id',      $lvT2,        200, 'a team leave rejection'],
        ['loans.php',                  'loan_id',       $loanT1,      200, 'a loan recommendation'],
        ['attendance_corrections.php', 'correction_id', $corrX,       403, "a cross-team correction attempt"],
        ['attendance_corrections.php', 'correction_id', $corrSelf,    403, 'a own-request correction attempt'],
        ['overtime_records.php',       'ot_id',         $otX,         403, 'a cross-team overtime attempt'],
        ['leave_requests.php',         'leave_id',      $lvSelf,      403, 'a own-request leave attempt'],
        ['loans.php',                  'loan_id',       $loanT1,      403, 'a refused loan approval'],
    ];
    foreach ($approvals as [$action, $column, $id, $status, $label]) {
        $hits = array_values(array_filter($logs, fn($x) =>
            ($x['action'] ?? null) === $action
            && (string) ($x['target'] ?? '') === "$column=$id"
            && (int) $x['http_status'] === $status
            && ($x['username'] ?? null) === 'dsh_sup_boss'));
        is_true("audit_log records $label (HTTP $status)", count($hits) >= 1,
            "no $action row for $column=$id at $status");
    }

    $recommendRows = array_values(array_filter($logs, fn($x) =>
        ($x['action'] ?? null) === 'loans.php'
        && (string) ($x['target'] ?? '') === "loan_id=$loanT1"
        && (int) $x['http_status'] === 200));
    is_true('the recommendation row stores the action and remarks',
        count(array_filter($recommendRows, fn($x) =>
            str_contains((string) ($x['details'] ?? ''), 'recommend')
            && str_contains((string) ($x['details'] ?? ''), 'recommended'))) >= 1,
        'details did not carry the recommendation');
    is_true('the recommendation is logged as a PATCH',
        count(array_filter($recommendRows, fn($x) => ($x['method'] ?? null) === 'PATCH')) >= 1);
    is_true('the recommendation is logged as a success',
        count(array_filter($recommendRows, fn($x) => (int) ($x['success'] ?? 0) === 1)) >= 1);

    // The login POST is audited too, so only the decision rows are counted.
    $noEmpLogs = array_values(array_filter($logs,
        fn($x) => ($x['username'] ?? null) === 'dsh_sup_nobody'
            && ($x['action'] ?? '') !== 'auth.php'));
    is_true('the unlinked Supervisor refusals are logged too', count($noEmpLogs) >= 4,
        'rows: ' . count($noEmpLogs));
    eq('every unlinked-Supervisor decision row is a 403',
        count(array_filter($noEmpLogs, fn($x) => (int) $x['http_status'] !== 403)), 0);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    $GLOBALS['pdo']->exec("DELETE FROM audit_log WHERE audit_id > $AUDIT_MAX");

    printf("\n=== cleanup verification ===\n");
    $in = $fixtureEmployees ? implode(',', array_map('intval', $fixtureEmployees)) : '0';
    printf("  fixture employees:     %d\n", dbCount('employees', "employee_number LIKE 'DSH-SUP-%'"));
    printf("  fixture users:         %d\n", dbCount('users', "username LIKE 'dsh\\_sup\\_%'"));
    printf("  fixture corrections:   %d\n", dbCount('attendance_corrections', "employee_id IN ($in)"));
    printf("  fixture attendance:    %d\n", dbCount('attendance', "employee_id IN ($in)"));
    printf("  fixture overtime:      %d\n", dbCount('overtime_records', "employee_id IN ($in)"));
    printf("  fixture leave:         %d\n", dbCount('leave_requests', "employee_id IN ($in)"));
    printf("  fixture loans:         %d\n", dbCount('loans', "employee_id IN ($in)"));
    printf("  fixture team links:    %d\n", dbCount('employees', "employee_id IN ($in) AND supervisor_id IS NOT NULL"));

    is_true('all supervisor fixtures cleaned up',
        dbCount('employees', "employee_number LIKE 'DSH-SUP-%'") === 0
        && dbCount('users', "username LIKE 'dsh\\_sup\\_%'") === 0
        && dbCount('attendance_corrections', "employee_id IN ($in)") === 0
        && dbCount('attendance', "employee_id IN ($in)") === 0
        && dbCount('overtime_records', "employee_id IN ($in)") === 0
        && dbCount('leave_requests', "employee_id IN ($in)") === 0
        && dbCount('loans', "employee_id IN ($in)") === 0,
        'a fixture row survived cleanup');

    // Real employees 4/5/8 must be exactly as they were, supervisor_id included.
    $drift = [];
    foreach (REAL_EMPLOYEES as $id) {
        $after = dbRow("SELECT employee_id, employee_number, first_name, last_name,
                               department_id, position_id, basic_salary, is_active,
                               supervisor_id
                        FROM employees WHERE employee_id = $id");
        if ($after !== $REAL_BEFORE[$id]) $drift[] = "employee #$id changed";
    }
    eq('employees 4/5/8 are untouched (supervisor_id still NULL)', $drift, []);
    eq('employees 4/5/8 still have no supervisor_id',
        dbCount('employees', "employee_id IN (4, 5, 8) AND supervisor_id IS NOT NULL"), 0);

    $countDrift = [];
    foreach (WATCH_TABLES as $t) {
        $now = dbCount($t);
        if ($now !== $COUNT_BEFORE[$t]) $countDrift[] = "$t {$COUNT_BEFORE[$t]}->$now";
    }
    $countDrift
        ? bad('real data untouched', implode(', ', $countDrift))
        : ok('real data untouched (table counts match the pre-test state)');
}

summary();
