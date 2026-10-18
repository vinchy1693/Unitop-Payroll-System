<?php
// =====================================================================
// tests/audit_log_test.php
//
// Covers the central audit trail (respond() hook in api/_bootstrap.php),
// the read-only viewer (api/audit_log.php) and the dashboard role guard.
//
// Creates its own admin/employee accounts and one throwaway department,
// then removes every fixture (including the audit rows this test caused).
//
// Run:  C:\xampp\php\php.exe tests\audit_log_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

const AUDIT_ADMIN = 'dsh_audit_admin';
const AUDIT_EMP   = 'dsh_audit_emp';

$auditBaseline = 0;
$createdDeptId = 0;
$adminId       = 0;
$empId         = 0;

function auditMaxId(): int {
    $row = dbRow("SELECT COALESCE(MAX(audit_id), 0) AS max_id FROM audit_log");
    return (int) ($row['max_id'] ?? 0);
}

function auditRowsAfter(int $id): array {
    return dbAll("SELECT * FROM audit_log WHERE audit_id > $id ORDER BY audit_id");
}

function auditOneAfter(int $id): ?array {
    $rows = auditRowsAfter($id);
    return count($rows) === 1 ? $rows[0] : null;
}

try {
    // Captured before any fixture exists, so a crash mid-suite can only ever
    // remove rows this run created (never pre-existing audit history).
    $auditBaseline = auditMaxId();

    // ---- fixtures ----------------------------------------------------
    $adminId = mkUser(AUDIT_ADMIN, 'Administrator');
    $empId   = mkUser(AUDIT_EMP, 'Employee');

    $jarAdmin = login(AUDIT_ADMIN);
    $jarEmp   = login(AUDIT_EMP);

    // =================================================================
    section('1. GET requests are never audited');
    // =================================================================
    $before = auditMaxId();
    $r = req('GET', 'departments.php', null, $jarAdmin);
    eq('GET departments.php -> 200', $r['code'], 200);
    eq('GET created no audit row', count(auditRowsAfter($before)), 0);

    $r = req('GET', 'audit_log.php?limit=1', null, $jarAdmin);
    eq('GET audit_log.php -> 200', $r['code'], 200);
    eq('reading the audit log itself creates no audit row', count(auditRowsAfter($before)), 0);

    // =================================================================
    section('2. Successful mutation is logged once, with the right actor');
    // =================================================================
    $deptName = 'DSH Audit Dept ' . getmypid();
    $before = auditMaxId();
    $r = req('POST', 'departments.php', ['department_name' => $deptName], $jarAdmin);
    eq('POST departments.php -> 200', $r['code'], 200);
    $createdDeptId = (int) ($r['json']['data']['department_id'] ?? 0);
    is_true('department fixture created', $createdDeptId > 0, 'response: ' . substr($r['raw'], 0, 160));
    if ($createdDeptId > 0) {
        onCleanup(function () use ($createdDeptId) {
            $GLOBALS['pdo']->exec("DELETE FROM departments WHERE department_id = $createdDeptId");
        });
    }

    $rows = auditRowsAfter($before);
    eq('exactly one audit row for one request', count($rows), 1);
    $row = $rows[0] ?? [];
    eq('audit user_id matches the session', $row['user_id'] ?? null, $adminId);
    eq('audit username matches the session', $row['username'] ?? null, AUDIT_ADMIN);
    eq('audit role matches the session', $row['role'] ?? null, 'Administrator');
    eq('audit action is the endpoint name', $row['action'] ?? null, 'departments.php');
    eq('audit method is POST', $row['method'] ?? null, 'POST');
    eq('audit http_status is 200', $row['http_status'] ?? null, 200);
    eq('audit success flag is 1', $row['success'] ?? null, 1);
    eq('audit message is the response message', $row['message'] ?? null, 'Department added.');
    eq('target is NULL when the body has no id',
        array_key_exists('target', $row) ? $row['target'] : 'not-set', null);
    is_true('details contains the request body', str_contains((string) ($row['details'] ?? ''), $deptName),
        'details: ' . ($row['details'] ?? 'NULL'));

    // =================================================================
    section('3. Failed mutations are logged with success=0');
    // =================================================================
    $before = auditMaxId();
    $r = req('POST', 'departments.php', ['department_name' => ''], $jarAdmin);
    eq('empty department name -> 422', $r['code'], 422);
    $row = auditOneAfter($before);
    is_true('failed mutation produced one audit row', $row !== null, 'row count: ' . count(auditRowsAfter($before)));
    eq('failed row http_status is 422', $row['http_status'] ?? null, 422);
    eq('failed row success flag is 0', $row['success'] ?? null, 0);
    eq('failed row message is the 422 message', $row['message'] ?? null, 'Department name is required.');

    // =================================================================
    section('4. Login attempts are logged without the password');
    // =================================================================
    // The fixture logins are themselves audit rows: a successful login must
    // already be attributed to the user the new session belongs to.
    $loginRow = dbRow("SELECT * FROM audit_log
                       WHERE action = 'auth.php' AND username = '" . AUDIT_ADMIN . "'
                       ORDER BY audit_id DESC LIMIT 1");
    is_true('successful login is attributed to the logged-in user', $loginRow !== false,
        'no auth.php row for ' . AUDIT_ADMIN);
    eq('successful login row records the Administrator role', $loginRow['role'] ?? null, 'Administrator');
    is_true('successful login row redacts the password',
        str_contains((string) ($loginRow['details'] ?? ''), '"password":"***"'),
        'details: ' . ($loginRow['details'] ?? 'NULL'));

    $secret = 'AuditLeakProbe' . getmypid() . 'X';
    $badJar = sys_get_temp_dir() . '/dsh_audit_bad_' . getmypid() . '.txt';
    $before = auditMaxId();
    $r = req('POST', 'auth.php?action=login', ['username' => AUDIT_ADMIN, 'password' => $secret], $badJar);
    eq('login with a wrong password -> 401', $r['code'], 401);
    if (file_exists($badJar)) unlink($badJar);

    $row = auditOneAfter($before);
    is_true('failed login produced one audit row', $row !== null, 'row count: ' . count(auditRowsAfter($before)));
    eq('login row action is auth.php', $row['action'] ?? null, 'auth.php');
    eq('login row http_status is 401', $row['http_status'] ?? null, 401);
    eq('login row success flag is 0', $row['success'] ?? null, 0);

    $details = (string) ($row['details'] ?? '');
    is_true('password value is redacted in details', str_contains($details, '"password":"***"'),
        'details: ' . $details);
    $flattened = json_encode($row);
    is_true('plaintext password appears nowhere in the row', strpos($flattened, $secret) === false,
        'row: ' . $flattened);

    // =================================================================
    section('5. Actor + target on an update');
    // =================================================================
    $before = auditMaxId();
    $r = req('PATCH', 'departments.php', ['department_id' => $createdDeptId, 'is_active' => 1], $jarAdmin);
    eq('PATCH departments.php -> 200', $r['code'], 200);
    $row = auditOneAfter($before);
    is_true('update produced one audit row', $row !== null, 'row count: ' . count(auditRowsAfter($before)));
    eq('update row username matches the session', $row['username'] ?? null, AUDIT_ADMIN);
    eq('update row role matches the session', $row['role'] ?? null, 'Administrator');
    eq('update target is the affected record', $row['target'] ?? null, 'department_id=' . $createdDeptId);

    // =================================================================
    section('6. audit_log.php returns rows and respects filters');
    // =================================================================
    $before = auditMaxId();
    $r = req('GET', 'audit_log.php?action=departments.php&limit=1', null, $jarAdmin);
    eq('audit_log.php filtered GET -> 200', $r['code'], 200);
    $body   = $r['json']['data'] ?? [];
    $viewer = $body['rows'] ?? [];
    $total  = (int) ($body['total'] ?? 0);
    eq('?limit=1 returns one row', count($viewer), 1);
    eq('viewer reports the requested limit', $body['limit'] ?? null, 1);
    is_true('total counts the filtered set', $total >= 3, 'total: ' . $total);
    is_true('all returned rows match ?action=', isset($viewer[0])
        && ($viewer[0]['action'] ?? null) === 'departments.php', json_encode($viewer[0] ?? null));
    eq('viewer GET creates no audit row', count(auditRowsAfter($before)), 0);

    $r = req('GET', 'audit_log.php?action=departments.php&username=' . AUDIT_ADMIN . '&limit=500', null, $jarAdmin);
    eq('username filter -> 200', $r['code'], 200);
    $allAdminRows = $r['json']['data']['rows'] ?? [];
    $badUser = array_filter($allAdminRows, fn($x) => ($x['username'] ?? null) !== AUDIT_ADMIN);
    eq('username filter returns only that user', count($badUser), 0);
    is_true('username filter finds the department activity', count($allAdminRows) >= 3,
        'rows: ' . count($allAdminRows));

    $r = req('GET', 'audit_log.php?success=0&limit=500', null, $jarAdmin);
    eq('success filter -> 200', $r['code'], 200);
    $failedRows = $r['json']['data']['rows'] ?? [];
    $badSuccess = array_filter($failedRows, fn($x) => (int) ($x['success'] ?? -1) !== 0);
    eq('success=0 returns only failed rows', count($badSuccess), 0);
    is_true('success=0 finds the 422 and 401 rows', count($failedRows) >= 2, 'rows: ' . count($failedRows));

    $r = req('GET', 'audit_log.php?limit=9999', null, $jarAdmin);
    eq('limit above the cap -> 200', $r['code'], 200);
    eq('limit is clamped to 500', $r['json']['data']['limit'] ?? null, 500);

    $r = req('GET', 'audit_log.php?limit=1&offset=1&action=departments.php', null, $jarAdmin);
    eq('offset filter -> 200', $r['code'], 200);
    eq('offset skips the first row', count($r['json']['data']['rows'] ?? []), 1);
    eq('offset is echoed back', $r['json']['data']['offset'] ?? null, 1);

    // Use the database clock: created_at is written by CURRENT_TIMESTAMP, and
    // the DB timezone need not match PHP's.
    $today = (string) (dbRow("SELECT CURDATE() AS d")['d'] ?? date('Y-m-d'));
    $r = req('GET', "audit_log.php?from=$today&to=$today&action=departments.php&limit=500", null, $jarAdmin);
    eq('date range filter -> 200', $r['code'], 200);
    is_true('date range keeps today\'s rows', (int) ($r['json']['data']['total'] ?? 0) >= 3,
        'total: ' . ($r['json']['data']['total'] ?? 'missing'));

    $r = req('GET', 'audit_log.php?from=2099-01-01&limit=500', null, $jarAdmin);
    eq('future-only date range -> 200', $r['code'], 200);
    eq('future-only date range returns no rows', (int) ($r['json']['data']['total'] ?? -1), 0);

    $r = req('GET', 'audit_log.php?from=not-a-date', null, $jarAdmin);
    eq('invalid from date -> 422', $r['code'], 422);

    // =================================================================
    section('7. audit_log.php is Administrator-only');
    // =================================================================
    $r = req('GET', 'audit_log.php', null, $jarEmp);
    eq('Employee reading the audit log -> 403', $r['code'], 403);
    is_true('Employee gets success=false', ($r['json']['success'] ?? null) === false, substr($r['raw'], 0, 160));
    $r = req('GET', 'audit_log.php', null, $jarAdmin);
    eq('Administrator reading the audit log -> 200', $r['code'], 200);

    $before = auditMaxId();
    $r = req('POST', 'audit_log.php', ['anything' => 1], $jarAdmin);
    eq('POST to the read-only viewer -> 405', $r['code'], 405);
    $row = auditOneAfter($before);
    eq('rejected POST to the viewer is itself audited with the failing status',
        $row['http_status'] ?? null, 405);

    // =================================================================
    section('8. Dashboard role guard');
    // =================================================================
    $r = req('GET', 'dashboard.php', null, $jarEmp);
    eq('Employee reading the dashboard -> 403', $r['code'], 403);
    is_true('Employee dashboard body is success=false', ($r['json']['success'] ?? null) === false,
        substr($r['raw'], 0, 160));
    $r = req('GET', 'dashboard.php', null, $jarAdmin);
    eq('Administrator reading the dashboard -> 200', $r['code'], 200);
    is_true('Administrator dashboard returns counts', isset($r['json']['data']['counts']),
        substr($r['raw'], 0, 160));

    // =================================================================
    section('9. Logout is still attributed to the user who logged out');
    // =================================================================
    $logoutJar = login(AUDIT_ADMIN);
    $before = auditMaxId();
    $r = req('POST', 'auth.php?action=logout', null, $logoutJar);
    eq('logout -> 200', $r['code'], 200);
    $row = auditOneAfter($before);
    is_true('logout produced one audit row', $row !== null, 'row count: ' . count(auditRowsAfter($before)));
    eq('logout row username is the user who logged out', $row['username'] ?? null, AUDIT_ADMIN);
    eq('logout row role is the user who logged out', $row['role'] ?? null, 'Administrator');
    eq('logout row action is auth.php', $row['action'] ?? null, 'auth.php');
    eq('logout row success flag is 1', $row['success'] ?? null, 1);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();

    // Remove every audit row this suite created: the fixture logins
    // (username matches) and anything after the baseline (anonymous
    // failed-login rows included).
    $pdo->exec("DELETE FROM audit_log
                WHERE username IN ('" . AUDIT_ADMIN . "', '" . AUDIT_EMP . "')
                   OR audit_id > " . (int) $auditBaseline);

    printf("\n=== cleanup verification ===\n");
    printf("  leftover test users:        %d\n", dbCount('users', "username LIKE 'dsh\\_%'"));
    printf("  leftover test departments:  %d\n", dbCount('departments', "department_name LIKE 'DSH%'"));
    printf("  leftover test audit rows:   %d\n", dbCount('audit_log', "username LIKE 'dsh\\_%'"));
    printf("  audit rows after baseline:  %d\n", dbCount('audit_log', "audit_id > " . (int) $auditBaseline));
    is_true('all test fixtures cleaned up',
        dbCount('users', "username LIKE 'dsh\\_%'") === 0
        && dbCount('departments', "department_name LIKE 'DSH%'") === 0
        && dbCount('audit_log', "username LIKE 'dsh\\_%'") === 0
        && dbCount('audit_log', "audit_id > " . (int) $auditBaseline) === 0,
        'a fixture row survived cleanup');
}

summary();
