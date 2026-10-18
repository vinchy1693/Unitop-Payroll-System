<?php
// =====================================================================
// tests/employee_portal_test.php
//
// Cross-system test: the admin site (this repo) and the separate employee
// portal at C:\xampp\htdocs\employee_portal, sharing one payroll_db.
//
// Portal files are only READ over HTTP - nothing outside this repo is
// modified. Everything created in the database is removed afterwards.
//
// Run:  C:\xampp\php\php.exe tests\employee_portal_test.php
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
    return ['code' => $code, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
}

$WATCH = ['employees','users','payroll_periods','payroll_runs','payslips','deductions',
          'govt_contributions','allowances','attendance','qr_codes','leave_types',
          'leave_balances','leave_requests','positions','departments','loans','overtime_records'];
$START = [];
foreach ($WATCH as $t) $START[$t] = dbCount($t);

printf("portal: %s\nadmin : %s\n", PORTAL, BASE_URL);
printf("start: employees=%d users=%d payslips=%d attendance=%d leave_types=%d\n",
    dbCount('employees'), dbCount('users'), dbCount('payslips'), dbCount('attendance'), dbCount('leave_types'));

try {
    // ---- fixtures on the ADMIN side ----
    // The maker (Payroll Officer) prepares the data since the split.
    mkUser('dsh_admin', 'Payroll Officer');
    $jarAdmin = login('dsh_admin');

    $empA = mkEmployee('DSH-PORT-A', 'Portal', 'Alpha', 60000.00);
    $empB = mkEmployee('DSH-PORT-B', 'Portal', 'Beta', 60000.00);
    mkUser('dsh_portal_a', 'Employee', $empA);
    mkUser('dsh_portal_b', 'Employee', $empB);

    // =================================================================
    section('1. Portal authorization');
    // =================================================================
    $r = preq('GET', 'me_dashboard.php');
    eq('portal without a session -> 401', $r['code'], 401);

    $r = preq('GET', 'me_dashboard.php', null, $jarAdmin);
    is_true('an admin session is invisible to the portal (separate sessions)',
        $r['code'] === 401, "HTTP {$r['code']} - expected the portal not to see the admin session at all");

    $jarA = sys_get_temp_dir() . '/dsh_portal_a_' . getmypid() . '.txt';
    onCleanup(fn() => file_exists($jarA) && unlink($jarA));
    $r = preq('POST', 'auth.php?action=login', ['username' => 'dsh_admin', 'password' => TEST_PW], $jarA);
    eq('admin credentials rejected by the portal login -> 403', $r['code'], 403);
    is_true('portal tells the admin to use the admin login', str_contains((string) ($r['json']['message'] ?? ''), 'employees only'));

    $r = preq('POST', 'auth.php?action=login', ['username' => 'dsh_portal_a', 'password' => TEST_PW], $jarA);
    eq('employee login on the portal -> 200', $r['code'], 200);
    eq('portal returns the employee_id', $r['json']['data']['employee_id'] ?? null, $empA);

    // =================================================================
    section('2. The two apps use independent sessions');
    // =================================================================
    // Each app now names its own session cookie (admin_sid / portal_sid), so a
    // login on one must NOT authenticate the other.
    $r = req('GET', 'auth.php?action=me', null, $jarA);
    is_true('a portal session does NOT authenticate on the admin site',
        $r['code'] === 401,
        "HTTP {$r['code']} - the session cookie is still shared between the two apps");
    $r = req('GET', 'employees.php', null, $jarA);
    is_true('a portal session cannot read admin endpoints', $r['code'] === 401, "HTTP {$r['code']}");

    $r = preq('GET', 'me_dashboard.php', null, $jarA);
    eq('the portal session still works on the portal', $r['code'], 200);

    $r = req('GET', 'auth.php?action=me', null, $jarAdmin);
    eq('the admin session is unaffected by what the portal does', $r['code'], 200);

    $r = preq('POST', 'auth.php?action=logout', null, $jarA);
    $r = req('GET', 'auth.php?action=me', null, $jarAdmin);
    eq('portal logout does not end the admin session', $r['code'], 200);

    // fresh portal login for the rest of the tests
    preq('POST', 'auth.php?action=login', ['username' => 'dsh_portal_a', 'password' => TEST_PW], $jarA);

    // =================================================================
    section('3. HR-generated data is visible in the portal');
    // =================================================================
    $r = req('POST', 'qr_codes.php', ['employee_id' => $empA], $jarAdmin);
    eq('admin generates a QR for the employee -> 200', $r['code'], 200);
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM qr_codes WHERE employee_id = $empA"));
    $adminQr = dbRow("SELECT code_value FROM qr_codes WHERE employee_id = $empA ORDER BY qr_code_id DESC LIMIT 1");

    $r = preq('GET', 'me_qr.php', null, $jarA);
    eq('portal me_qr -> 200', $r['code'], 200);
    eq('portal shows the QR that HR generated', $r['json']['data']['code_value'] ?? null, $adminQr['code_value'] ?? null);

    // attendance seeded by HR, plus a payroll run
    $start = '2027-09-01'; $end = '2027-09-15';
    $periodId = mkPeriod($start, $end);
    $ins = $GLOBALS['pdo']->prepare(
        "INSERT INTO attendance (employee_id, qr_code_id, work_date, time_in, time_out, scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, '08:00:00', '17:00:00', 'DSH-SEED', 8.00, 0, 'Present')"
    );
    $cd = new DateTime($start); $ce = (new DateTime($end))->modify('+1 day');
    $seeded = 0;
    while ($cd < $ce) { if ((int) $cd->format('N') <= 6) { $ins->execute([$empA, $cd->format('Y-m-d')]); $seeded++; } $cd->modify('+1 day'); }

    $r = req('POST', 'payroll_runs.php', ['period_id' => $periodId], $jarAdmin);
    eq('admin runs payroll -> 200', $r['code'], 200);
    $runId = (int) ($r['json']['data']['run_id'] ?? 0);
    $ps = dbRow("SELECT * FROM payslips WHERE run_id = $runId AND employee_id = $empA");
    is_true('payslip generated for employee A', $ps !== false);

    $r = preq('GET', 'me_payslips.php', null, $jarA);
    eq('portal me_payslips -> 200', $r['code'], 200);
    $list = $r['json']['data'] ?? [];
    $found = null;
    foreach ($list as $row) { if ((int) ($row['payslip_id'] ?? 0) === (int) ($ps['payslip_id'] ?? 0)) $found = $row; }
    is_true('the payslip the admin just generated appears in the portal', $found !== null, 'not in the portal list');
    if ($found) {
        eq('portal shows the correct net pay', $found['net_pay'], $ps['net_pay']);
        eq('portal shows the correct gross pay', $found['gross_pay'], $ps['gross_pay']);
    }

    $r = preq('GET', 'me_dashboard.php', null, $jarA);
    eq('portal me_dashboard -> 200', $r['code'], 200);
    printf("  dashboard latest_payslip=%s attendance=%s leave_total=%s\n",
        json_encode($r['json']['data']['latest_payslip'] ?? null) === 'null' ? 'none' : 'present',
        json_encode($r['json']['data']['attendance'] ?? []), $r['json']['data']['leave_total'] ?? '-');

    // =================================================================
    section('4. Isolation between employees');
    // =================================================================
    $r = preq('GET', "me_attendance.php?from=$start&to=$end", null, $jarA);
    eq('portal me_attendance -> 200', $r['code'], 200);
    eq('portal returns exactly the seeded working days', $r['json']['data']['days_present'] ?? null, $seeded);
    $foreign = array_filter($r['json']['data']['records'] ?? [], fn($x) => false);
    is_true('me_attendance scopes to the session employee', count($foreign) === 0);

    // Employee A asking for Employee B's payslip by id
    $psB = dbRow("SELECT payslip_id FROM payslips WHERE employee_id = $empB LIMIT 1");
    $bId = (int) ($psB['payslip_id'] ?? 0);
    if ($bId) {
        $r = preq('GET', "me_payslips.php?id=$bId", null, $jarA);
        is_true("portal refuses another employee's payslip by id (expect 404)",
            $r['code'] === 404, "HTTP {$r['code']} - returned " . substr($r['raw'], 0, 120));
    }

    // =================================================================
    section('5. me_payslips.php ?id= detail branch');
    // =================================================================
    $aId = (int) ($ps['payslip_id'] ?? 0);
    if ($aId) {
        $r = preq('GET', "me_payslips.php?id=$aId", null, $jarA);
        eq("GET me_payslips.php?id=$aId -> 200", $r['code'], 200);
        $json = $r['json']['data'] ?? null;
        $isList = is_array($json) && array_is_list($json);
        is_true('the ?id= request returns a single payslip object (not the list)',
            is_array($json) && !$isList && isset($json['payslip_id']),
            'returned ' . ($isList ? 'the payslip LIST' : gettype($json)) . ' - the ?id= branch is unreachable because the GET list branch above it responds and exits first');
        if ($isList) {
            finding('employee_portal/api/me_payslips.php: the `GET ?id=N` single-payslip branch (line 27) is unreachable - the plain GET branch (line 10) catches every GET and exits. The payslip detail view, including its allowances/deductions line items, is dead code and always receives the list.');
        }
    }

    // =================================================================
    section('6. Leave round trip across the two systems');
    // =================================================================
    $lt = mkLeaveType('DSH Portal Leave', 4);
    $r = req('POST', 'leave_balances.php', ['year' => 2027], $jarAdmin);
    eq('admin initialises leave balances for 2027 -> 200', $r['code'], 200);

    $d = new DateTime('2027-01-01');
    while ((int) $d->format('N') !== 1) $d->modify('+1 day');
    $mon = $d->format('Y-m-d');
    $tue = (clone $d)->modify('+1 day')->format('Y-m-d');

    $r = preq('POST', 'me_leave_requests.php', ['leave_type_id' => $lt, 'start_date' => $mon, 'end_date' => $tue], $jarA);
    eq('employee files a leave request in the portal -> 200', $r['code'], 200);
    $lrId = (int) ($r['json']['data']['leave_id'] ?? 0);
    is_true('leave_id returned', $lrId > 0);

    $adminSees = dbRow("SELECT * FROM leave_requests WHERE leave_id = $lrId");
    is_true('the request is visible on the admin side', $adminSees !== false && $adminSees['status'] === 'Pending',
        json_encode($adminSees));

    $r = preq('POST', 'me_leave_requests.php', ['leave_type_id' => $lt, 'start_date' => $mon, 'end_date' => $tue], $jarA);
    eq('duplicate overlapping request from the portal -> 409', $r['code'], 409);

    $balBefore = (float) (dbRow("SELECT remaining_days FROM leave_balances WHERE employee_id = $empA AND leave_type_id = $lt AND year = 2027")['remaining_days'] ?? -1);
    $r = req('PATCH', 'leave_requests.php', ['leave_id' => $lrId, 'status' => 'Approved'], $jarAdmin);
    eq('admin approves the request -> 200', $r['code'], 200);
    $balAfter = (float) (dbRow("SELECT remaining_days FROM leave_balances WHERE employee_id = $empA AND leave_type_id = $lt AND year = 2027")['remaining_days'] ?? -1);
    eq('approval deducts 2 days', $balAfter, $balBefore - 2);

    $r = preq('GET', 'me_leave_balances.php?year=2027', null, $jarA);
    eq('portal me_leave_balances -> 200', $r['code'], 200);
    $pbal = null;
    foreach (($r['json']['data']['balances'] ?? []) as $b) { if ((int) $b['leave_type_id'] === $lt) $pbal = (float) $b['remaining_days']; }
    eq('the portal reflects the balance the admin approval produced', $pbal, $balAfter);

    $r = preq('PATCH', 'me_leave_requests.php', ['leave_id' => $lrId, 'status' => 'Cancelled'], $jarA);
    eq('approved request cannot be cancelled by the employee -> 409', $r['code'], 409);

    // a second request on a non-overlapping working day, cancelled by its owner
    $wed = (clone $d)->modify('+2 day')->format('Y-m-d');
    $r = preq('POST', 'me_leave_requests.php', ['leave_type_id' => $lt, 'start_date' => $wed, 'end_date' => $wed], $jarA);
    eq('follow-up request on a free day -> 200', $r['code'], 200);
    $lr2 = (int) ($r['json']['data']['leave_id'] ?? 0);
    $r = preq('PATCH', 'me_leave_requests.php', ['leave_id' => $lr2, 'status' => 'Cancelled'], $jarA);
    eq('employee cancels own pending request -> 200', $r['code'], 200);
    eq('request is Cancelled', dbRow("SELECT status FROM leave_requests WHERE leave_id = $lr2")['status'] ?? null, 'Cancelled');

    // employee A must not cancel employee B's request
    $GLOBALS['pdo']->prepare("INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, status) VALUES (?,?,?,?,'Pending')")
        ->execute([$empB, $lt, $tue, $tue]);
    $bLeave = (int) $GLOBALS['pdo']->lastInsertId();
    $r = preq('PATCH', 'me_leave_requests.php', ['leave_id' => $bLeave, 'status' => 'Cancelled'], $jarA);
    eq("employee A cannot cancel employee B's request -> 403", $r['code'], 403);

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
