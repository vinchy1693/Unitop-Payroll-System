<?php
// =====================================================================
// tests/manual_qr_test.php
//
// Manual "type the QR value" fallback:
//   - admin site  : attendance.html -> scan_attendance.php (existing endpoint)
//   - employee portal: attendance.html -> me_scan_attendance.php (new)
// Verifies an employee can only submit their OWN code, and that clock
// in/out still follows the normal rules.
//
// Run:  C:\xampp\php\php.exe tests\manual_qr_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

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
    return ['code' => $code, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
}

$WATCH = ['employees','users','attendance','qr_codes'];
$START = [];
foreach ($WATCH as $t) $START[$t] = dbCount($t);

try {
    mkUser('dsh_admin', 'Administrator');
    $jarAdmin = login('dsh_admin');

    $empA = mkEmployee('DSH-QR-A', 'Manual', 'Alpha', 30000.00);
    $empB = mkEmployee('DSH-QR-B', 'Manual', 'Beta', 30000.00);
    mkUser('dsh_qr_a', 'Employee', $empA);
    mkUser('dsh_qr_b', 'Employee', $empB);

    req('POST', 'qr_codes.php', ['employee_id' => $empA], $jarAdmin);
    req('POST', 'qr_codes.php', ['employee_id' => $empB], $jarAdmin);
    $codeA = dbRow("SELECT code_value FROM qr_codes WHERE employee_id = $empA ORDER BY qr_code_id DESC LIMIT 1")['code_value'];
    $codeB = dbRow("SELECT code_value FROM qr_codes WHERE employee_id = $empB ORDER BY qr_code_id DESC LIMIT 1")['code_value'];
    is_true('QR codes generated for both employees', $codeA && $codeB && $codeA !== $codeB);

    $jarA = sys_get_temp_dir() . '/dsh_qr_a_' . getmypid() . '.txt';
    onCleanup(fn() => file_exists($jarA) && unlink($jarA));
    $r = preq('POST', 'auth.php?action=login', ['username' => 'dsh_qr_a', 'password' => TEST_PW], $jarA);
    eq('portal login as employee A -> 200', $r['code'], 200);

    // =================================================================
    section('1. Guards on the manual endpoint');
    // =================================================================
    $r = preq('GET', 'me_scan_attendance.php', null, $jarA);
    eq('GET is rejected -> 405', $r['code'], 405);
    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => ''], $jarA);
    eq('empty code -> 422', $r['code'], 422);
    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => 'NOT-A-CODE'], $jarA);
    eq('unknown code -> 404', $r['code'], 404);
    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => $codeB], $jarA);
    eq("employee A cannot submit employee B's code -> 404", $r['code'], 404);
    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => $codeA]);
    eq('no portal session -> 401', $r['code'], 401);

    // =================================================================
    section('2. Clock in by typing the QR value');
    // =================================================================
    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => $codeA], $jarA);
    eq('submitting own code -> 200', $r['code'], 200);
    eq('  action = in', $r['json']['data']['action'] ?? null, 'in');

    $att = dbRow("SELECT * FROM attendance WHERE employee_id = $empA AND work_date = CURDATE()");
    is_true('an attendance row was created for today', $att !== false, 'no row');
    if ($att) {
        printf("  recorded: in=%s late=%s status=%s device=%s\n",
            $att['time_in'], $att['minutes_late'], $att['status'], $att['scan_device_id']);
        eq('scan_device_id marks the manual portal path', $att['scan_device_id'], 'Portal (manual)');
        eq('qr_code_id is the employee\'s own code', (int) $att['qr_code_id'],
            (int) dbRow("SELECT qr_code_id FROM qr_codes WHERE code_value = '$codeA'")['qr_code_id']);
        is_true('late minutes matches the 08:00 cut-off',
            (int) $att['minutes_late'] === max(0, (int) ((strtotime($att['time_in']) - strtotime('08:00:00')) / 60)),
            "late={$att['minutes_late']} for in={$att['time_in']}");
        eq('status is Clocked In or Late', in_array($att['status'], ['Clocked In', 'Late'], true), true);
        is_true('no time_out yet', $att['time_out'] === null);
    }

    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => $codeA], $jarA);
    eq('immediate second submit is refused (under a minute) -> 422', $r['code'], 422);

    // =================================================================
    section('3. Clock out by typing the QR value');
    // =================================================================
    // Backdate the clock-in so the worked duration is realistic.
    $GLOBALS['pdo']->exec("UPDATE attendance SET time_in = '08:00:00' WHERE employee_id = $empA AND work_date = CURDATE()");
    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => $codeA], $jarA);
    eq('submitting own code again -> 200', $r['code'], 200);
    eq('  action = out', $r['json']['data']['action'] ?? null, 'out');

    $att2 = dbRow("SELECT * FROM attendance WHERE employee_id = $empA AND work_date = CURDATE()");
    if ($att2) {
        $mins = (int) ((strtotime($att2['time_out']) - strtotime($att2['time_in'])) / 60);
        $expected = round(($mins > 300 ? $mins - 60 : $mins) / 60, 2);
        printf("  recorded: %s -> %s = %s hrs (expected %s)\n", $att2['time_in'], $att2['time_out'], $att2['hours_worked'], $expected);
        eq('hours_worked applies the 60-minute lunch break over 5 hours', $att2['hours_worked'], $expected);
        eq('status after clock-out is Late or Present', in_array($att2['status'], ['Late', 'Present'], true), true);
    }

    $r = preq('POST', 'me_scan_attendance.php', ['code_value' => $codeA], $jarA);
    eq('a third submit the same day -> 409 duplicate', $r['code'], 409);

    // =================================================================
    section('4. Employee B is unaffected, and the admin fallback still works');
    // =================================================================
    eq('employee B has no attendance row',
        dbCount('attendance', "employee_id = $empB AND work_date = CURDATE()"), 0);

    // The admin page's manual box posts to the existing kiosk endpoint.
    $r = req('POST', 'scan_attendance.php', [
        'code_value' => $codeA, 'demo_mode' => true, 'demo_date' => '2031-01-06', 'demo_time' => '08:00',
    ], $jarAdmin);
    eq('admin manual submission (scan_attendance.php) -> 200', $r['code'], 200);
    eq('  action = in', $r['json']['data']['action'] ?? null, 'in');
    $adminRow = dbRow("SELECT * FROM attendance WHERE employee_id = $empA AND work_date = '2031-01-06'");
    is_true('admin manual entry recorded the row', $adminRow !== false);
    if ($adminRow) eq('admin manual entry uses the QR Kiosk device', $adminRow['scan_device_id'], 'QR Kiosk');

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    $drift = [];
    foreach ($WATCH as $t) { $now = dbCount($t); if ($now !== $START[$t]) $drift[] = "$t {$START[$t]}->$now"; }
    $drift ? bad('database fully restored', implode(', ', $drift)) : ok('database fully restored to its pre-test state');
}

summary();
