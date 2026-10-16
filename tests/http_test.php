<?php
// =====================================================================
// tests/http_test.php  —  end-to-end HTTP test against the running app
// Run:  C:\xampp\php\php.exe tests\http_test.php
//
// Creates two temporary users (dsh_test_admin / dsh_test_emp) and one
// temporary attendance row, then removes ALL of them in a finally block.
// =====================================================================

const BASE = 'http://localhost/master_file_module_api/api/';
const TMP_PW = 'TestPass123';

$PASS = 0; $FAIL = 0; $NOTES = [];
function ok(string $n): void { global $PASS; $PASS++; printf("  PASS  %s\n", $n); }
function bad(string $n, string $d): void { global $FAIL; $FAIL++; printf("  FAIL  %s\n        -> %s\n", $n, $d); }
function is_true(string $n, bool $c, string $d = ''): void { $c ? ok($n) : bad($n, $d ?: 'condition false'); }
function eq(string $n, $a, $e): void { $a == $e ? ok($n) : bad($n, "expected " . var_export($e, true) . ", got " . var_export($a, true)); }
function note(string $s): void { global $NOTES; $NOTES[] = $s; }
function section(string $t): void { printf("\n=== %s ===\n", $t); }

function req(string $method, string $path, ?array $body = null, ?string $jar = null): array {
    $ch = curl_init(BASE . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    if ($jar !== null)  { $opts[CURLOPT_COOKIEJAR] = $jar; $opts[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'raw' => $raw, 'json' => json_decode((string) $raw, true), 'err' => $err];
}

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$jarAdmin = sys_get_temp_dir() . '/dsh_admin_' . getmypid() . '.txt';
$jarEmp   = sys_get_temp_dir() . '/dsh_emp_' . getmypid() . '.txt';
$newAttId = null;

$attBefore   = (int) $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
$usersBefore = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$qrValue     = $pdo->query("SELECT code_value FROM qr_codes WHERE status='Active' LIMIT 1")->fetchColumn();

try {
    // ---- seed temporary users ----
    $ins = $pdo->prepare("INSERT INTO users (username, employee_id, role, is_active, password_hash) VALUES (?, ?, ?, 1, ?)");
    $hash = password_hash(TMP_PW, PASSWORD_DEFAULT);
    $ins->execute(['dsh_test_admin', null, 'Administrator', $hash]);
    $ins->execute(['dsh_test_emp', 3, 'Employee', $hash]);

    // =================================================================
    section('1. Unauthenticated access is blocked');
    // =================================================================
    $r = req('GET', 'employees.php');
    eq('GET employees.php without session -> 401', $r['code'], 401);
    is_true('401 body is JSON with success=false', ($r['json']['success'] ?? null) === false, 'body: ' . substr((string)$r['raw'], 0, 120));

    $r = req('GET', 'auth.php?action=me');
    eq('GET auth.php?action=me without session -> 401', $r['code'], 401);

    // =================================================================
    section('2. Login / session handling');
    // =================================================================
    $r = req('POST', 'auth.php?action=login', ['username' => 'dsh_test_admin']);
    eq('login with missing password -> 422', $r['code'], 422);

    $r = req('POST', 'auth.php?action=login', ['username' => 'dsh_test_admin', 'password' => 'wrong-password']);
    eq('login with wrong password -> 401', $r['code'], 401);

    $r = req('POST', 'auth.php?action=login', ['username' => 'no_such_user', 'password' => TMP_PW]);
    eq('login with unknown user -> 401', $r['code'], 401);

    $r = req('POST', 'auth.php?action=login', ['username' => 'dsh_test_admin', 'password' => TMP_PW], $jarAdmin);
    eq('login with correct password -> 200', $r['code'], 200);
    eq('login returns Administrator role', $r['json']['data']['role'] ?? null, 'Administrator');

    $r = req('GET', 'auth.php?action=me', null, $jarAdmin);
    eq('session persists (action=me) -> 200', $r['code'], 200);
    is_true('currentUser() exposes employee_id key', array_key_exists('employee_id', $r['json']['data'] ?? []), 'missing employee_id');

    $r = req('POST', 'auth.php?action=login', ['username' => 'dsh_test_emp', 'password' => TMP_PW], $jarEmp);
    eq('employee login -> 200', $r['code'], 200);
    eq('employee session carries employee_id=3', $r['json']['data']['employee_id'] ?? null, 3);

    // =================================================================
    section('3. Role guards');
    // =================================================================
    $r = req('GET', 'users.php', null, $jarEmp);
    eq('Employee role on users.php (admin-only) -> 403', $r['code'], 403);
    $r = req('GET', 'users.php', null, $jarAdmin);
    eq('Administrator role on users.php -> 200', $r['code'], 200);
    $leak = strpos((string) $r['raw'], 'password_hash') !== false;
    is_true('users.php never returns password_hash', !$leak, 'password_hash found in response payload');

    // =================================================================
    section('4. Attendance summary + detail (weekend handling)');
    // =================================================================
    $r = req('GET', 'attendance.php?summary=1&from=2026-10-16&to=2026-10-31', null, $jarAdmin);
    eq('summary -> 200', $r['code'], 200);
    $t = $r['json']['data']['totals'] ?? [];
    eq('summary expected_days includes Saturdays (14)', $t['expected_days'] ?? null, 14);
    $empRow = null;
    foreach (($r['json']['data']['employees'] ?? []) as $row) { if ((int) $row['employee_id'] === 3) $empRow = $row; }
    if ($empRow) {
        eq('summary days_present = 1', $empRow['days_present'], 1);
        eq('summary absences = 14 - 1 = 13', $empRow['absences'], 13);
    } else { bad('employee 3 appears in summary', 'not found in employees[]'); }

    $r = req('GET', 'attendance.php?employee_detail=1&employee_id=3&from=2026-10-16&to=2026-10-19', null, $jarAdmin);
    eq('employee_detail -> 200', $r['code'], 200);
    $days = $r['json']['data']['days'] ?? [];
    $byDate = []; foreach ($days as $d) $byDate[$d['date']] = $d;
    eq('16 Oct (Fri) has a record', $byDate['2026-10-16']['has_record'] ?? null, true);
    eq('17 Oct (Sat) is a WORKING day, so marked Absent', $byDate['2026-10-17']['status'] ?? null, 'Absent');
    eq('18 Oct (Sun) marked Weekend', $byDate['2026-10-18']['status'] ?? null, 'Weekend');
    eq('19 Oct (Mon) marked Absent', $byDate['2026-10-19']['status'] ?? null, 'Absent');
    eq('detail expected_days = 3 working days (Fri, Sat, Mon)', $r['json']['data']['totals']['expected_days'] ?? null, 3);

    // --- Ledger must use the same Mon-Sat work week ---
    $r = req('GET', 'ledger.php?employee_id=3&from=2026-10-16&to=2026-10-19', null, $jarAdmin);
    eq('ledger -> 200', $r['code'], 200);
    $led = [];
    foreach (($r['json']['data']['ledger'] ?? []) as $e) { if (isset($e['date'])) $led[$e['date']] = $e['status'] ?? null; }
    eq('ledger: 17 Oct (Sat) is a working day (Absent)', $led['2026-10-17'] ?? null, 'Absent');
    eq('ledger: 18 Oct (Sun) is still Weekend', $led['2026-10-18'] ?? null, 'Weekend');

    // =================================================================
    section('5. Attendance write-path validation (non-destructive)');
    // =================================================================
    $r = req('POST', 'attendance.php', ['employee_id' => 3], $jarAdmin);
    eq('POST attendance missing date/time_in -> 422', $r['code'], 422);

    $r = req('POST', 'attendance.php', ['employee_id' => 3, 'work_date' => '2026-10-16', 'time_in' => '08:00'], $jarAdmin);
    eq('POST duplicate date -> 409', $r['code'], 409);

    $r = req('PATCH', 'attendance.php', ['employee_id' => 3, 'work_date' => '2026-11-20', 'time_out' => '17:00'], $jarAdmin);
    eq('PATCH with no time-in record -> 404', $r['code'], 404);

    // =================================================================
    section('6. QR scanner end-to-end (demo mode)');
    // =================================================================
    $r = req('POST', 'scan_attendance.php', ['code_value' => 'NOT-A-REAL-CODE'], $jarAdmin);
    eq('unknown QR -> 404', $r['code'], 404);

    $r = req('POST', 'scan_attendance.php', ['code_value' => ''], $jarAdmin);
    eq('empty QR -> 422', $r['code'], 422);

    $r = req('POST', 'scan_attendance.php', [
        'code_value' => $qrValue, 'demo_mode' => true, 'demo_date' => '2026-12-25', 'demo_time' => '08:15',
    ], $jarAdmin);
    eq('clock IN at 08:15 -> 200', $r['code'], 200);
    eq('  action = in', $r['json']['data']['action'] ?? null, 'in');
    eq('  minutes_late = 15', $r['json']['data']['late_minutes'] ?? null, 15);
    eq('  reported time = 08:15 AM', $r['json']['data']['time'] ?? null, '08:15 AM');
    eq('  status = Late', $r['json']['data']['status'] ?? null, 'Late');

    $r = req('POST', 'scan_attendance.php', [
        'code_value' => $qrValue, 'demo_mode' => true, 'demo_date' => '2026-12-25', 'demo_time' => '17:00',
    ], $jarAdmin);
    eq('clock OUT at 17:00 -> 200', $r['code'], 200);
    eq('  action = out', $r['json']['data']['action'] ?? null, 'out');
    eq('  hours_worked = 7.75 (8h45m - 60m lunch)', $r['json']['data']['hours_worked'] ?? null, 7.75);

    $r = req('POST', 'scan_attendance.php', [
        'code_value' => $qrValue, 'demo_mode' => true, 'demo_date' => '2026-12-25', 'demo_time' => '18:00',
    ], $jarAdmin);
    eq('third scan same day -> 409 duplicate', $r['code'], 409);

    $r = req('GET', 'attendance.php?date=2026-12-25', null, $jarAdmin);
    $rec = $r['json']['data'][0] ?? null;
    if ($rec) {
        $newAttId = (int) $rec['attendance_id'];
        eq('  stored hours_worked = 7.75', $rec['hours_worked'], '7.75');
        eq('  stored minutes_late = 15', $rec['minutes_late'], 15);
        eq('  stored status = Late', $rec['status'], 'Late');
        eq('  stored device = QR Kiosk', $rec['scan_device_id'], 'QR Kiosk');
    } else { bad('created attendance row is retrievable', 'GET attendance.php?date=2026-12-25 returned nothing'); }

    // =================================================================
    section('7. Employee self-service portal');
    // =================================================================
    $r = req('GET', 'me_attendance.php', null, $jarEmp);
    eq('me_attendance -> 200', $r['code'], 200);
    $ownRows = $r['json']['data'] ?? [];
    $foreign = array_filter($ownRows, fn($x) => isset($x['employee_id']) && (int) $x['employee_id'] !== 3);
    is_true('me_attendance returns only the caller\'s own records', count($foreign) === 0, count($foreign) . ' foreign row(s) leaked');

    foreach (['me_payslips.php', 'me_leave_balances.php', 'me_leave_requests.php'] as $ep) {
        $r = req('GET', $ep, null, $jarEmp);
        printf("  %-24s -> HTTP %d %s\n", $ep, $r['code'], ($r['json']['success'] ?? false) ? '(ok)' : '(success=false: ' . substr((string)($r['json']['message'] ?? ''), 0, 60) . ')');
        is_true("$ep responds 200 for an Employee", $r['code'] === 200, "HTTP {$r['code']}");
        if (($r['json']['success'] ?? false) === false) note("$ep returned success=false for a valid Employee: " . ($r['json']['message'] ?? ''));
    }

    // =================================================================
    section('8. Read-only smoke test of every endpoint');
    // =================================================================
    $gets = [
        'dashboard.php',
        'employees.php',
        'departments.php',
        'positions.php',
        'salary_rates.php',
        'attendance.php?date=2026-10-16',
        'attendance.php?summary=1&from=2026-10-16&to=2026-10-31',
        'attendance.php?employee_detail=1&employee_id=3&from=2026-10-16&to=2026-10-31',
        'payroll_periods.php',
        'payroll_runs.php',
        'payslips.php?run_id=2',
        'allowances.php?payslip_id=3',
        'deductions.php?payslip_id=3',
        'loans.php',
        'overtime_records.php?employee_id=3',
        'leave_types.php',
        'leave_balances.php',
        'leave_requests.php',
        'qr_codes.php',
        'users.php',
        'reports.php?report=payroll_register&run_id=2',
        'reports.php?report=remittance&year=2026&month=10',
        'reports.php?report=employee_history&employee_id=3',
        'reports.php?report=department_summary&from=2026-10-16&to=2026-10-31',
        'reports.php?report=payroll_summary&run_id=2',
        'ledger.php?employee_id=3&from=2026-10-01&to=2026-11-30',
    ];
    // Resolve run/payslip ids from the database (and drop run-scoped endpoints
    // when none exist) so this suite survives a payroll reset or a manual run.
    $runId  = (int) $pdo->query("SELECT COALESCE(MAX(run_id),0) FROM payroll_runs")->fetchColumn();
    $slipId = (int) $pdo->query("SELECT COALESCE(MAX(payslip_id),0) FROM payslips")->fetchColumn();
    $gets = array_values(array_filter($gets, function ($ep) use ($runId, $slipId) {
        if (str_contains($ep, 'run_id=') && $runId === 0) return false;
        if (str_contains($ep, 'payslip_id=') && $slipId === 0) return false;
        return true;
    }));
    $gets = array_map(fn($ep) => str_replace(
        ['run_id=2', 'payslip_id=3'],
        ["run_id=$runId", "payslip_id=$slipId"],
        $ep
    ), $gets);
    printf("  (using run_id=%d, payslip_id=%d)\n", $runId, $slipId);

    foreach ($gets as $ep) {
        $r = req('GET', $ep, null, $jarAdmin);
        $flag = $r['code'] === 200 ? 'ok' : 'HTTP ' . $r['code'];
        printf("  %-52s -> %s\n", $ep, $flag);
        if ($r['code'] !== 200) bad("$ep responds 200 for Administrator", "HTTP {$r['code']} " . substr((string)($r['json']['message'] ?? ''), 0, 90));
        elseif (($r['json']['success'] ?? null) !== true) note("$ep returned HTTP 200 but success=false: " . ($r['json']['message'] ?? ''));
    }

    // =================================================================
    section('9. Logout');
    // =================================================================
    $r = req('POST', 'auth.php?action=logout', null, $jarAdmin);
    eq('logout -> 200', $r['code'], 200);
    $r = req('GET', 'employees.php', null, $jarAdmin);
    eq('after logout, employees.php -> 401', $r['code'], 401);

    // =================================================================
    section('10. change_password validation');
    // =================================================================
    $r = req('POST', 'auth.php?action=change_password',
        ['current_password' => TMP_PW, 'new_password' => 'abc123', 'confirm_password' => 'different'], $jarEmp);
    eq('mismatched confirmation -> 422', $r['code'], 422);

    $r = req('POST', 'auth.php?action=change_password',
        ['current_password' => TMP_PW, 'new_password' => 'abc', 'confirm_password' => 'abc'], $jarEmp);
    eq('new password shorter than 6 -> 422', $r['code'], 422);

    $r = req('POST', 'auth.php?action=change_password',
        ['current_password' => 'wrong', 'new_password' => 'abc123', 'confirm_password' => 'abc123'], $jarEmp);
    eq('wrong current password -> 401', $r['code'], 401);

} catch (Throwable $e) {
    bad('harness completed without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    // ---- cleanup ----
    if ($newAttId !== null) {
        req('DELETE', 'attendance.php?id=' . $newAttId, null, $jarEmp);
    }
    $pdo->exec("DELETE FROM attendance WHERE scan_device_id = 'QR Kiosk' AND work_date = '2026-12-25'");
    $pdo->exec("DELETE FROM users WHERE username IN ('dsh_test_admin','dsh_test_emp')");
    foreach ([$jarAdmin, $jarEmp] as $j) { if (file_exists($j)) unlink($j); }

    $attAfter   = (int) $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
    $usersAfter = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    printf("\n=== cleanup verification ===\n");
    printf("  attendance rows: %d -> %d %s\n", $attBefore, $attAfter, $attBefore === $attAfter ? '(restored)' : '(LEFTOVER!)');
    printf("  users rows:      %d -> %d %s\n", $usersBefore, $usersAfter, $usersBefore === $usersAfter ? '(restored)' : '(LEFTOVER!)');
    if ($attBefore !== $attAfter || $usersBefore !== $usersAfter) {
        bad('test data fully cleaned up', 'row counts changed');
    } else {
        ok('test data fully cleaned up (row counts restored)');
    }
}

printf("\n================ SUMMARY ================\n");
printf("PASS: %d   FAIL: %d\n", $PASS, $FAIL);
if ($NOTES) { echo "\nFindings:\n"; foreach ($NOTES as $n) printf("  * %s\n", $n); }
