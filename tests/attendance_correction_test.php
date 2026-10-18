<?php
// =====================================================================
// tests/attendance_correction_test.php
//
// Attendance correction requests across the two apps (segregation of duties,
// step 5). The employee portal files, the admin queue decides.
//
//   1. an employee files a correction for their own date -> Pending
//   2. a future date (422) and a too-short reason (422) are refused
//      (plus the 90-day window)
//   3. a second Pending request for the same employee + date -> 409
//   4. a date inside an Approved / Released payroll run -> 409
//   5. the client's employee_id is IGNORED - always the session employee
//   6. the portal GET returns only that employee's own requests
//   7. approving applies the correction and recomputes hours_worked /
//      minutes_late exactly as api/scan_attendance.php does, including the
//      300-minute lunch boundary
//   8. rejecting requires remarks (422) and leaves attendance unchanged
//   9. approving an already-approved request -> 409
//  10. role guards: Employee 403 on the admin endpoint, Approver 403 on the
//      PATCH (maker only), maker 200
//  11. every mutation is in audit_log
//
// Real data is untouched: the suite works on DSH-* fixtures and on work dates
// that are NOT inside any Approved/Released payroll run, and it restores every
// attendance row it touches.
//
// Run:  C:\xampp\php\php.exe tests\attendance_correction_test.php
// =====================================================================

require __DIR__ . '/_harness.php';
require __DIR__ . '/../api/_payroll_helpers.php';

const PORTAL = 'http://localhost/employee_portal/api/';

function preq(string $method, string $path, ?array $body = null, ?string $jar = null): array {
    $ch = curl_init(PORTAL . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30];
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
    if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => (string) $raw, 'json' => json_decode((string) $raw, true)];
}

/**
 * Work dates for the suite: recent (inside the 90-day window) and NOT covered
 * by any Approved / Released payroll run, so the suite never fights real
 * payroll data and never depends on what today's date happens to be.
 */
function freeDates(int $count): array {
    $out = [];
    for ($ago = 1; $ago <= 89 && count($out) < $count; $ago++) {
        $date = date('Y-m-d', strtotime("-$ago days"));
        $locked = dbRow("SELECT pr.run_id
                         FROM payroll_runs pr
                         JOIN payroll_periods pp ON pp.period_id = pr.period_id
                         WHERE '$date' BETWEEN pp.start_date AND pp.end_date
                           AND pr.status IN ('Approved', 'Released')
                         LIMIT 1");
        if (!$locked) $out[] = $date;
    }
    if (count($out) < $count) {
        throw new RuntimeException("could not find $count unlocked work dates in the last 90 days");
    }
    return $out;
}

/** The newest attendance row for one employee + date (null when there is none). */
function attendanceRow(int $employeeId, string $date): ?array {
    $row = dbRow("SELECT * FROM attendance
                  WHERE employee_id = $employeeId AND work_date = '$date'
                  ORDER BY attendance_id DESC LIMIT 1");
    return $row ?: null;
}

function correctionRow(int $id): array {
    $row = dbRow("SELECT * FROM attendance_corrections WHERE correction_id = $id");
    if (!$row) throw new RuntimeException("correction $id not found");
    return $row;
}

/**
 * Independent recomputation of the scan rules (deliberately written without
 * looking at the endpoint's helpers): shift starts 08:00, minutes_late counts
 * whole minutes past it, and a span longer than 300 minutes loses 60 minutes
 * of lunch. api/scan_attendance.php does exactly this with DateTime::diff().
 */
function expectHoursLate(?string $timeIn, ?string $timeOut): array {
    if ($timeIn === null) return ['hours' => null, 'late' => 0];

    $shift = new DateTime('08:00:00');
    $in    = new DateTime($timeIn);

    $late = 0;
    if ($in > $shift) {
        $gap  = $shift->diff($in);
        $late = ($gap->h * 60) + $gap->i;
    }
    if ($timeOut === null) return ['hours' => null, 'late' => $late];

    $out  = new DateTime($timeOut);
    $span = $in->diff($out);
    $minutes = ($span->h * 60) + $span->i;
    if ($minutes > 300) $minutes -= 60;

    return ['hours' => round($minutes / 60, 2), 'late' => $late];
}

$WATCH = ['employees', 'users', 'attendance', 'attendance_corrections',
          'payroll_periods', 'payroll_runs', 'audit_log'];
$START = [];
foreach ($WATCH as $t) $START[$t] = dbCount($t);

$AUDIT_MAX = (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);

$empA = 0;
$empB = 0;
$corrD1 = 0;
$corrD2 = 0;
$corrD4 = 0;
$corrD5 = 0;
$corrD6 = 0;
$lockRunId = 0;

try {
    // =================================================================
    // Fixtures
    // =================================================================
    $makerId = mkUser('dsh_corr_maker', 'Payroll Officer');
    mkUser('dsh_corr_check', 'Approver');
    mkUser('dsh_corr_employee', 'Employee');            // an admin-site Employee session

    $empA = mkEmployee('DSH-CORR-A', 'Correction', 'Alpha', 30000.00);
    $empB = mkEmployee('DSH-CORR-B', 'Correction', 'Beta', 30000.00);
    mkUser('dsh_corr_pa', 'Employee', $empA);           // portal accounts
    mkUser('dsh_corr_pb', 'Employee', $empB);

    // Registered after mkEmployee(), so it runs BEFORE the employee teardown
    // (the registry is executed in reverse order).
    onCleanup(function () use ($empA, $empB) {
        $GLOBALS['pdo']->exec("DELETE FROM attendance_corrections WHERE employee_id IN ($empA, $empB)");
    });

    $jarMaker    = login('dsh_corr_maker');
    $jarCheck    = login('dsh_corr_check');
    $jarEmployee = login('dsh_corr_employee');

    $jarA = sys_get_temp_dir() . '/dsh_corr_a_' . getmypid() . '.txt';
    $jarB = sys_get_temp_dir() . '/dsh_corr_b_' . getmypid() . '.txt';
    onCleanup(function () use ($jarA, $jarB) {
        if (file_exists($jarA)) unlink($jarA);
        if (file_exists($jarB)) unlink($jarB);
    });

    $r = preq('POST', 'auth.php?action=login', ['username' => 'dsh_corr_pa', 'password' => TEST_PW], $jarA);
    eq('employee A logs into the portal -> 200', $r['code'], 200);
    eq('the portal session is employee A', $r['json']['data']['employee_id'] ?? null, $empA);
    $r = preq('POST', 'auth.php?action=login', ['username' => 'dsh_corr_pb', 'password' => TEST_PW], $jarB);
    eq('employee B logs into the portal -> 200', $r['code'], 200);

    $free = freeDates(6);
    [$d1, $d2, $d3, $d4, $d5, $d6] = $free;
    printf("  work dates: d1=%s d2=%s d3=%s d4=%s d5=%s d6=%s\n", $d1, $d2, $d3, $d4, $d5, $d6);

    // A Released run covering d3 only: the date a correction must be refused on.
    $periodLock = mkPeriod($d3, $d3);
    $GLOBALS['pdo']->prepare(
        "INSERT INTO payroll_runs (period_id, run_date, status) VALUES (?, CURDATE(), 'Released')"
    )->execute([$periodLock]);
    $lockRunId = (int) $GLOBALS['pdo']->lastInsertId();

    // =================================================================
    section('0. The date-lock helper resolves the fixture run, not other days');
    // =================================================================
    $lock = attendanceDateLock($GLOBALS['pdo'], $d3);
    is_true('attendanceDateLock finds the Released run for d3', $lock !== null, json_encode($lock));
    eq('the resolved run is the fixture run', (int) ($lock['run_id'] ?? 0), $lockRunId);
    eq('the resolved status is Released', $lock['status'] ?? null, 'Released');
    is_true('a date outside every run is not locked', attendanceDateLock($GLOBALS['pdo'], $d1) === null);

    // A record employee A clocked in to but never clocked out of.
    $GLOBALS['pdo']->prepare(
        "INSERT INTO attendance (employee_id, qr_code_id, work_date, time_in, time_out,
                                 scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, '08:00:00', NULL, 'DSH-SEED', NULL, 0, 'Clocked In')"
    )->execute([$empA, $d1]);
    $attD1 = (int) $GLOBALS['pdo']->lastInsertId();

    $GLOBALS['pdo']->prepare(
        "INSERT INTO attendance (employee_id, qr_code_id, work_date, time_in, time_out,
                                 scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, '08:00:00', NULL, 'DSH-SEED', NULL, 0, 'Clocked In')"
    )->execute([$empB, $d5]);

    $reason = 'I forgot to scan out when my shift ended.';

    // =================================================================
    section('1. An employee files a correction for their own date');
    // =================================================================
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $d1, 'correction_type' => 'clock_out',
        'requested_time_out' => '17:30', 'reason' => $reason,
    ], $jarA);
    eq('file a clock-out correction -> 200', $r['code'], 200);
    $corrD1 = (int) ($r['json']['data']['correction_id'] ?? 0);
    is_true('correction_id returned', $corrD1 > 0, substr($r['raw'], 0, 200));
    eq('response status is Pending', $r['json']['data']['status'] ?? null, 'Pending');

    $row = correctionRow($corrD1);
    eq('stored employee_id is the session employee', (int) $row['employee_id'], $empA);
    eq('stored attendance_id is the existing row', (int) $row['attendance_id'], $attD1);
    eq('stored work_date', $row['work_date'], $d1);
    eq('stored correction_type', $row['correction_type'], 'clock_out');
    eq('stored requested_time_out', $row['requested_time_out'], '17:30:00');
    is_true('requested_time_in stays NULL for a clock-out correction', $row['requested_time_in'] === null);
    eq('stored reason', $row['reason'], $reason);
    eq('stored status is Pending', $row['status'], 'Pending');
    is_true('requested_at is recorded', $row['requested_at'] !== null);
    is_true('reviewed_by starts NULL', $row['reviewed_by'] === null);

    // =================================================================
    section('2. Future date and too-short reason are refused');
    // =================================================================
    $future = date('Y-m-d', strtotime('+1 day'));
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $future, 'correction_type' => 'clock_out',
        'requested_time_out' => '17:00', 'reason' => $reason,
    ], $jarA);
    eq('a future work date -> 422', $r['code'], 422);
    is_true('the 422 explains the date cannot be in the future',
        stripos((string) ($r['json']['message'] ?? ''), 'future') !== false,
        (string) ($r['json']['message'] ?? ''));

    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $d2, 'correction_type' => 'clock_in',
        'requested_time_in' => '08:00', 'reason' => 'Too short',
    ], $jarA);
    eq('a reason shorter than 10 characters -> 422', $r['code'], 422);
    is_true('the 422 names the 10-character minimum',
        stripos((string) ($r['json']['message'] ?? ''), '10 characters') !== false,
        (string) ($r['json']['message'] ?? ''));

    $old = date('Y-m-d', strtotime('-120 days'));
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $old, 'correction_type' => 'clock_out',
        'requested_time_out' => '17:00', 'reason' => $reason,
    ], $jarA);
    eq('a date older than 90 days -> 422', $r['code'], 422);
    is_true('the 422 names the 90-day window',
        stripos((string) ($r['json']['message'] ?? ''), '90 days') !== false,
        (string) ($r['json']['message'] ?? ''));

    eq('the refused filings created no request',
        dbCount('attendance_corrections', "employee_id = $empA AND work_date IN ('$future', '$d2', '$old')"), 0);

    // =================================================================
    section('3. A second Pending request for the same date -> 409');
    // =================================================================
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $d1, 'correction_type' => 'clock_out',
        'requested_time_out' => '18:00', 'reason' => 'Second attempt, same day.',
    ], $jarA);
    eq('a duplicate Pending request -> 409', $r['code'], 409);
    is_true('the 409 says a request is already Pending',
        stripos((string) ($r['json']['message'] ?? ''), 'pending') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('exactly one Pending request exists for that employee + date',
        dbCount('attendance_corrections', "employee_id = $empA AND work_date = '$d1' AND status = 'Pending'"), 1);

    // =================================================================
    section('4. A date inside an Approved/Released run -> 409');
    // =================================================================
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $d3, 'correction_type' => 'both',
        'requested_time_in' => '08:00', 'requested_time_out' => '17:00', 'reason' => $reason,
    ], $jarA);
    eq('a locked payroll date -> 409', $r['code'], 409);
    is_true('the 409 says the payroll is already decided',
        stripos((string) ($r['json']['message'] ?? ''), 'already') !== false,
        (string) ($r['json']['message'] ?? ''));
    is_true('the 409 asks for a formal adjustment',
        stripos((string) ($r['json']['message'] ?? ''), 'formal') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('no request was created for the locked date',
        dbCount('attendance_corrections', "employee_id = $empA AND work_date = '$d3'"), 0);

    // =================================================================
    section('5. The client employee_id is ignored');
    // =================================================================
    $r = preq('POST', 'me_attendance_corrections.php', [
        'employee_id' => $empB,                 // must not be honoured
        'work_date' => $d4, 'correction_type' => 'both',
        'requested_time_in' => '08:12', 'requested_time_out' => '17:00', 'reason' => $reason,
    ], $jarA);
    eq('employee A filing with employee_id = B -> 200', $r['code'], 200);
    $corrD4 = (int) ($r['json']['data']['correction_id'] ?? 0);
    eq('the request is stored for the SESSION employee, not the body',
        (int) correctionRow($corrD4)['employee_id'], $empA);
    is_true('employee B has no request for that date',
        dbCount('attendance_corrections', "employee_id = $empB AND work_date = '$d4'") === 0);

    // and the mirror image: B cannot file on A's behalf either.
    $r = preq('POST', 'me_attendance_corrections.php', [
        'employee_id' => $empA,
        'work_date' => $d5, 'correction_type' => 'clock_out',
        'requested_time_out' => '17:00', 'reason' => $reason,
    ], $jarB);
    eq('employee B filing with employee_id = A -> 200', $r['code'], 200);
    $corrD5 = (int) ($r['json']['data']['correction_id'] ?? 0);
    eq('that request is stored for employee B',
        (int) correctionRow($corrD5)['employee_id'], $empB);

    // =================================================================
    section('6. The portal GET returns only my own requests');
    // =================================================================
    $rA = preq('GET', 'me_attendance_corrections.php', null, $jarA);
    eq('portal GET -> 200', $rA['code'], 200);
    $rowsA = $rA['json']['data']['rows'] ?? [];
    eq('employee A sees exactly their own two requests', count($rowsA), 2);
    $foreign = array_filter($rowsA, fn($x) => (int) ($x['employee_id'] ?? 0) !== $empA);
    eq('no request of another employee leaks into the list', count($foreign), 0);
    $idsA = array_map(fn($x) => (int) $x['correction_id'], $rowsA);
    is_true("employee B's request is not in employee A's list", !in_array($corrD5, $idsA, true));

    $byId = [];
    foreach ($rowsA as $x) $byId[(int) $x['correction_id']] = $x;
    is_true('the filed request is in the list', isset($byId[$corrD1]));
    if (isset($byId[$corrD1])) {
        eq('it shows as Pending', $byId[$corrD1]['status'], 'Pending');
        eq('it carries the current attendance time in', $byId[$corrD1]['current_time_in'], '08:00:00');
        is_true('it carries the (still missing) current time out', $byId[$corrD1]['current_time_out'] === null);
        is_true('has_attendance is true', (bool) $byId[$corrD1]['has_attendance'] === true);
    }

    $rB = preq('GET', 'me_attendance_corrections.php', null, $jarB);
    $rowsB = $rB['json']['data']['rows'] ?? [];
    eq('employee B sees only their own request', count($rowsB), 1);
    eq('employee B has no request of employee A',
        dbCount('attendance_corrections', "employee_id = $empB AND work_date = '$d1'"), 0);

    // =================================================================
    section('7. Approving applies the correction and recomputes the scan rules');
    // =================================================================
    // (a) update the existing row: clock in 08:00, requested clock out 17:30.
    $expect = expectHoursLate('08:00:00', '17:30:00');
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD1, 'action' => 'approve',
    ], $jarMaker);
    eq('maker approves -> 200', $r['code'], 200);
    eq('response reports the applied record', $r['json']['data']['applied']['attendance_created'] ?? null, false);

    $row = correctionRow($corrD1);
    eq('the request is now Approved', $row['status'], 'Approved');
    eq('reviewed_by is the maker', (int) $row['reviewed_by'], $makerId);
    is_true('reviewed_at is recorded', $row['reviewed_at'] !== null);

    $att = attendanceRow($empA, $d1);
    is_true('the attendance row still exists', $att !== null);
    eq('the stored attendance_id points at it', (int) $row['attendance_id'], (int) $att['attendance_id']);
    eq('time_in is unchanged', $att['time_in'], '08:00:00');
    eq('time_out is the requested time', $att['time_out'], '17:30:00');
    eq('hours_worked matches the independent recomputation', $att['hours_worked'], $expect['hours']);
    eq('hours_worked is 8.50 (9.5h span less the 60-minute lunch)', (float) $att['hours_worked'], 8.50);
    eq('minutes_late matches the independent recomputation', (int) $att['minutes_late'], $expect['late']);
    eq('status is Present', $att['status'], 'Present');

    // (b) create the row that did not exist: both times, a late clock-in.
    $expect = expectHoursLate('08:12:00', '17:00:00');
    is_true('no attendance row exists for d4 before approval', attendanceRow($empA, $d4) === null);
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD4, 'action' => 'approve',
    ], $jarMaker);
    eq('approving a correction with no attendance row -> 200', $r['code'], 200);
    eq('the response says a record was created',
        $r['json']['data']['applied']['attendance_created'] ?? null, true);

    $att = attendanceRow($empA, $d4);
    is_true('the attendance row was created', $att !== null);
    if ($att) {
        eq('created time_in', $att['time_in'], '08:12:00');
        eq('created time_out', $att['time_out'], '17:00:00');
        eq('created minutes_late matches the independent recomputation', (int) $att['minutes_late'], $expect['late']);
        eq('created minutes_late is 12', (int) $att['minutes_late'], 12);
        eq('created hours_worked matches the independent recomputation', $att['hours_worked'], $expect['hours']);
        eq('created hours_worked is 7.80', (float) $att['hours_worked'], 7.80);
        eq('created status is Late', $att['status'], 'Late');
        eq('the correction now points at the created row',
            (int) correctionRow($corrD4)['attendance_id'], (int) $att['attendance_id']);
    }

    // (c) the lunch boundary: a 300-minute span is NOT longer than 300 minutes.
    $expect = expectHoursLate('08:00:00', '13:00:00');
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $d6, 'correction_type' => 'both',
        'requested_time_in' => '08:00', 'requested_time_out' => '13:00', 'reason' => $reason,
    ], $jarA);
    eq('a same-day correction that starts before it ends -> 200', $r['code'], 200);
    $corrD6 = (int) ($r['json']['data']['correction_id'] ?? 0);
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD6, 'action' => 'approve',
    ], $jarMaker);
    eq('approve the boundary correction -> 200', $r['code'], 200);
    $att = attendanceRow($empA, $d6);
    eq('a 300-minute span keeps all 300 minutes (no lunch deducted)',
        (float) ($att['hours_worked'] ?? -1), 5.00);
    eq('the boundary case matches the independent recomputation',
        $att['hours_worked'] ?? null, $expect['hours']);

    // =================================================================
    section('8. Rejecting requires remarks and leaves attendance unchanged');
    // =================================================================
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $d2, 'correction_type' => 'clock_in',
        'requested_time_in' => '09:00', 'reason' => 'I think I scanned in late.',
    ], $jarA);
    eq('file a correction to reject -> 200', $r['code'], 200);
    $corrD2 = (int) ($r['json']['data']['correction_id'] ?? 0);

    $before = attendanceRow($empA, $d2);
    is_true('no attendance row exists for the date being rejected', $before === null);

    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD2, 'action' => 'reject',
    ], $jarMaker);
    eq('rejecting without remarks -> 422', $r['code'], 422);
    is_true('the 422 says remarks are required',
        stripos((string) ($r['json']['message'] ?? ''), 'remarks') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('the refused rejection leaves the request Pending',
        correctionRow($corrD2)['status'], 'Pending');
    is_true('the refused rejection leaves attendance unchanged', attendanceRow($empA, $d2) === null);

    $rejectRemarks = 'The scan log shows you clocked in at 08:00. Please file a clock-out correction instead.';
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD2, 'action' => 'reject', 'remarks' => $rejectRemarks,
    ], $jarMaker);
    eq('rejecting with remarks -> 200', $r['code'], 200);
    $row = correctionRow($corrD2);
    eq('the request is Rejected', $row['status'], 'Rejected');
    eq('the remarks are stored', $row['review_remarks'], $rejectRemarks);
    eq('reviewed_by is the maker', (int) $row['reviewed_by'], $makerId);
    is_true('attendance is untouched by the rejection', attendanceRow($empA, $d2) === null);

    // A rejection must also leave an EXISTING row exactly as it was.
    $beforeB = attendanceRow($empB, $d5);
    is_true("employee B's row exists before the rejection", $beforeB !== null);
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD5, 'action' => 'reject', 'remarks' => 'Clock-out already recorded by the kiosk.',
    ], $jarMaker);
    eq('rejecting the clock-out correction -> 200', $r['code'], 200);
    eq("employee B's attendance row is unchanged", attendanceRow($empB, $d5), $beforeB);

    $rB = preq('GET', 'me_attendance_corrections.php', null, $jarB);
    $bRow = $rB['json']['data']['rows'][0] ?? [];
    eq('the employee sees the rejection', $bRow['status'] ?? null, 'Rejected');
    eq('the employee sees the reviewer remarks', $bRow['review_remarks'] ?? null, 'Clock-out already recorded by the kiosk.');

    // =================================================================
    section('9. Approving twice -> 409');
    // =================================================================
    $attBefore = attendanceRow($empA, $d1);
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD1, 'action' => 'approve',
    ], $jarMaker);
    eq('approving an already-approved request -> 409', $r['code'], 409);
    is_true('the 409 names the current status',
        stripos((string) ($r['json']['message'] ?? ''), 'already Approved') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('the attendance row is not touched twice', attendanceRow($empA, $d1), $attBefore);

    // =================================================================
    section('10. Role guards');
    // =================================================================
    $r = req('GET', 'attendance_corrections.php');
    eq('unauthenticated GET -> 401', $r['code'], 401);

    $r = req('GET', 'attendance_corrections.php', null, $jarEmployee);
    eq('Employee GET on the admin queue -> 403', $r['code'], 403);

    $r = req('GET', 'attendance_corrections.php', null, $jarCheck);
    eq('Approver GET on the admin queue -> 200', $r['code'], 200);
    is_true('the Approver sees the queue rows', isset($r['json']['data']['rows']) && isset($r['json']['data']['total']),
        substr($r['raw'], 0, 200));

    $r = req('GET', 'attendance_corrections.php', null, $jarMaker);
    eq('maker GET on the admin queue -> 200', $r['code'], 200);
    $queueIds = array_map(fn($x) => (int) $x['correction_id'], $r['json']['data']['rows'] ?? []);
    is_true('the queue lists the corrections filed in this run',
        in_array($corrD1, $queueIds, true) && in_array($corrD2, $queueIds, true));
    $queueRow = null;
    foreach (($r['json']['data']['rows'] ?? []) as $x) {
        if ((int) $x['correction_id'] === $corrD1) $queueRow = $x;
    }
    is_true('the queue row carries employee name and number',
        isset($queueRow['employee_name'], $queueRow['employee_number']),
        json_encode($queueRow));
    eq('the queue row carries the reviewer name',
        $queueRow['reviewed_by_name'] ?? null, 'dsh_corr_maker');
    eq('the queue row carries the current attendance times',
        $queueRow['current_time_in'] ?? null, '08:00:00');

    // The checker may read but never decide.
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD6, 'action' => 'approve',
    ], $jarCheck);
    eq('Approver PATCH (maker only) -> 403', $r['code'], 403);
    eq('the refused PATCH left the request decided as before',
        correctionRow($corrD6)['status'], 'Approved');

    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD2, 'action' => 'approve',
    ], $jarEmployee);
    eq('Employee PATCH -> 403', $r['code'], 403);

    // Maker 200 on a fresh request for a date that already has a decided one.
    $r = preq('POST', 'me_attendance_corrections.php', [
        'work_date' => $d6, 'correction_type' => 'clock_out',
        'requested_time_out' => '18:00', 'reason' => 'Overtime ran an hour longer than planned.',
    ], $jarA);
    eq('a second request for a date whose first request was decided -> 200', $r['code'], 200);
    $corrD6b = (int) ($r['json']['data']['correction_id'] ?? 0);
    $r = req('PATCH', 'attendance_corrections.php', [
        'correction_id' => $corrD6b, 'action' => 'approve',
    ], $jarMaker);
    eq('maker PATCH -> 200', $r['code'], 200);
    eq('the second approval is recorded', correctionRow($corrD6b)['status'], 'Approved');
    eq('the recomputed time out is applied', attendanceRow($empA, $d6)['time_out'] ?? null, '18:00:00');

    // =================================================================
    section('11. Queue filters and pagination');
    // =================================================================
    $r = req('GET', 'attendance_corrections.php?status=Pending', null, $jarMaker);
    eq('?status=Pending -> 200', $r['code'], 200);
    $pending = $r['json']['data']['rows'] ?? [];
    eq('the Pending filter returns only Pending rows',
        count(array_filter($pending, fn($x) => $x['status'] !== 'Pending')), 0);

    $r = req('GET', "attendance_corrections.php?employee_id=$empB", null, $jarMaker);
    eq('?employee_id= -> 200', $r['code'], 200);
    eq('the employee filter returns only that employee',
        count(array_filter($r['json']['data']['rows'] ?? [], fn($x) => (int) $x['employee_id'] !== $empB)), 0);

    $r = req('GET', "attendance_corrections.php?from=$d1&to=$d1", null, $jarMaker);
    eq('?from/?to -> 200', $r['code'], 200);
    $range = $r['json']['data']['rows'] ?? [];
    eq('the date range returns only that work date', count($range), 1);
    eq('the ranged row is the d1 correction', (int) ($range[0]['correction_id'] ?? 0), $corrD1);

    $r = req('GET', 'attendance_corrections.php?limit=1', null, $jarMaker);
    eq('?limit=1 -> 200', $r['code'], 200);
    eq('limit=1 returns one row', count($r['json']['data']['rows'] ?? []), 1);
    eq('limit is echoed', $r['json']['data']['limit'] ?? null, 1);
    is_true('total counts the whole filtered set', (int) ($r['json']['data']['total'] ?? 0) >= 5,
        'total: ' . ($r['json']['data']['total'] ?? 'missing'));

    $r = req('GET', 'attendance_corrections.php?limit=9999', null, $jarMaker);
    eq('limit above the cap -> 200', $r['code'], 200);
    eq('limit is clamped to 500', $r['json']['data']['limit'] ?? null, 500);

    $r = req('GET', 'attendance_corrections.php?status=Nonsense', null, $jarMaker);
    eq('an unknown status -> 422', $r['code'], 422);
    $r = req('GET', 'attendance_corrections.php?from=not-a-date', null, $jarMaker);
    eq('an invalid from date -> 422', $r['code'], 422);

    // =================================================================
    section('12. Every mutation is in audit_log');
    // =================================================================
    $adminRows = dbAll("SELECT * FROM audit_log
                        WHERE action = 'attendance_corrections.php' AND audit_id > $AUDIT_MAX
                        ORDER BY audit_id");
    is_true('every admin decision produced an audit row', count($adminRows) >= 10,
        'rows: ' . count($adminRows));
    $makerRows = array_values(array_filter($adminRows, fn($x) => ($x['role'] ?? null) === 'Payroll Officer'));
    is_true('every maker decision is attributed to the maker', count($makerRows) >= 8,
        'maker rows: ' . count($makerRows));
    eq('maker decisions all carry the maker username',
        count(array_filter($makerRows, fn($x) => ($x['username'] ?? null) !== 'dsh_corr_maker')), 0);
    is_true('a successful approval is logged with the target correction',
        count(array_filter($adminRows, fn($x) => ($x['target'] ?? '') === "correction_id=$corrD1"
            && (int) $x['http_status'] === 200 && (int) $x['success'] === 1)) >= 1);
    is_true('the failed approve-twice is logged with 409 and success=0',
        count(array_filter($adminRows, fn($x) => (int) $x['http_status'] === 409
            && (int) $x['success'] === 0)) >= 1);
    is_true('the rejected-without-remarks attempt is logged with 422 and success=0',
        count(array_filter($adminRows, fn($x) => (int) $x['http_status'] === 422
            && (int) $x['success'] === 0)) >= 1);
    is_true('the Approver 403 attempt is logged',
        count(array_filter($adminRows, fn($x) => (int) $x['http_status'] === 403)) >= 1);

    $portalRows = dbAll("SELECT * FROM audit_log
                         WHERE action = 'me_attendance_corrections.php' AND audit_id > $AUDIT_MAX
                         ORDER BY audit_id");
    is_true('every portal filing produced an audit row', count($portalRows) >= 6,
        'rows: ' . count($portalRows));
    eq('portal filings are attributed to the employee session',
        count(array_filter($portalRows, fn($x) => ($x['username'] ?? null) !== 'dsh_corr_pa'
            && ($x['username'] ?? null) !== 'dsh_corr_pb')), 0);
    $filedD1 = array_values(array_filter($portalRows,
        fn($x) => ($x['target'] ?? '') === "correction_id=$corrD1"));
    is_true('the filing of the d1 correction is logged', count($filedD1) === 1);
    if ($filedD1) {
        eq('the filing row records the employee role', $filedD1[0]['role'] ?? null, 'Employee');
        is_true('the filing row records the work date and correction type',
            str_contains((string) ($filedD1[0]['details'] ?? ''), $d1)
            && str_contains((string) ($filedD1[0]['details'] ?? ''), 'clock_out'),
            'details: ' . ($filedD1[0]['details'] ?? 'NULL'));
        eq('the filing row is a POST', $filedD1[0]['method'] ?? null, 'POST');
        eq('the filing row is a success', (int) ($filedD1[0]['success'] ?? 0), 1);
    }
    eq('refused filings are not logged as filings',
        count(array_filter($portalRows, fn($x) => (int) $x['http_status'] !== 200)), 0);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    $GLOBALS['pdo']->exec("DELETE FROM audit_log WHERE audit_id > $AUDIT_MAX");

    printf("\n=== cleanup verification ===\n");
    printf("  fixture corrections: %d\n", dbCount('attendance_corrections', "employee_id IN ($empA, $empB)"));
    printf("  fixture attendance:  %d\n", dbCount('attendance', "employee_id IN ($empA, $empB)"));
    printf("  fixture employees:   %d\n", dbCount('employees', "employee_number LIKE 'DSH-CORR-%'"));
    printf("  fixture users:       %d\n", dbCount('users', "username LIKE 'dsh\\_corr\\_%'"));
    printf("  fixture lock run:    %d\n", $lockRunId > 0 ? dbCount('payroll_runs', "run_id = $lockRunId") : 0);

    is_true('all fixtures cleaned up',
        dbCount('attendance_corrections', "employee_id IN ($empA, $empB)") === 0
        && dbCount('attendance', "employee_id IN ($empA, $empB)") === 0
        && dbCount('employees', "employee_number LIKE 'DSH-CORR-%'") === 0
        && dbCount('users', "username LIKE 'dsh\\_corr\\_%'") === 0
        && ($lockRunId === 0 || dbCount('payroll_runs', "run_id = $lockRunId") === 0),
        'a fixture row survived cleanup');

    $drift = [];
    foreach ($WATCH as $t) {
        $now = dbCount($t);
        if ($now !== $START[$t]) $drift[] = "$t {$START[$t]}->$now";
    }
    $drift
        ? bad('real data untouched', implode(', ', $drift))
        : ok('real data untouched (table counts match the pre-test state)');
}

summary();
