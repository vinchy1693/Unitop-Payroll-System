<?php
// =====================================================================
// tests/me_selfservice_test.php
//
// Step 2 self-service checks for the employee portal:
//   * api/me_loans.php   - own loans + payment history, nothing foreign
//   * api/me_profile.php - own record; PATCH limited to five contact
//                          fields; audit_log row on success
//
// Portal files are only read over HTTP. Everything created in payroll_db
// is removed afterwards, and the real employees' contact fields are left
// exactly as they were (NULL).
//
// Run:  C:\xampp\php\php.exe tests\me_selfservice_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

const PORTAL = 'http://localhost/employee_portal/api/';

const CONTACT_FIELDS = ['mobile_number', 'email', 'address',
                        'emergency_contact_name', 'emergency_contact_number'];

function preq(string $method, string $path, ?array $body = null, ?string $jar = null): array {
    $ch = curl_init(PORTAL . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30];
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
    if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => (string) $raw, 'json' => json_decode((string) $raw, true)];
}

function portalLogin(string $username): string {
    $jar = sys_get_temp_dir() . '/dsh_self_' . $username . '_' . getmypid() . '.txt';
    onCleanup(function () use ($jar) { if (file_exists($jar)) unlink($jar); });
    $r = preq('POST', 'auth.php?action=login', ['username' => $username, 'password' => TEST_PW], $jar);
    if ($r['code'] !== 200) {
        throw new RuntimeException("portal login failed for $username: HTTP {$r['code']} {$r['raw']}");
    }
    return $jar;
}

function employeeSnapshot(array $ids): array {
    global $pdo;
    $in = implode(',', array_map('intval', $ids));
    return $pdo->query(
        "SELECT employee_id, employee_number, first_name, last_name, basic_salary,
                tin_number, sss_number, philhealth_number, pagibig_number,
                position_id, department_id, is_active, date_hired,
                mobile_number, email, address, emergency_contact_name, emergency_contact_number
         FROM employees WHERE employee_id IN ($in) ORDER BY employee_id"
    )->fetchAll();
}

function detailsValue(array $details, string $field, string $key) {
    return (array_key_exists($field, $details) && is_array($details[$field])
            && array_key_exists($key, $details[$field]))
        ? $details[$field][$key]
        : '__MISSING__';
}

function addLoan(int $employeeId, string $type, float $principal, float $monthly, string $status): int {
    global $pdo;
    $pdo->prepare(
        "INSERT INTO loans (employee_id, loan_type, principal_amount, monthly_amortization, status)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$employeeId, $type, $principal, $monthly, $status]);
    $id = (int) $pdo->lastInsertId();
    onCleanup(function () use ($pdo, $id) {
        $pdo->exec("DELETE FROM loan_payments WHERE loan_id = $id");
        $pdo->exec("DELETE FROM loans WHERE loan_id = $id");
    });
    return $id;
}

$WATCH = ['employees', 'users', 'loans', 'loan_payments', 'payslips',
          'payroll_runs', 'payroll_periods', 'positions', 'departments', 'audit_log'];
$START = [];
foreach ($WATCH as $t) $START[$t] = dbCount($t);

// Real rows that must never be touched by this test.
$REAL_IDS = [4, 5, 8];
$REAL_BEFORE = employeeSnapshot($REAL_IDS);
$AUDIT_MAX = (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);

// Remove only the audit rows this test generates.
onCleanup(function () use ($AUDIT_MAX) {
    global $pdo;
    $pdo->exec("DELETE FROM audit_log WHERE audit_id > $AUDIT_MAX AND action = 'me_profile.php'");
});

printf("portal: %s\n", PORTAL);
printf("start: employees=%d users=%d loans=%d loan_payments=%d audit_log=%d\n",
    dbCount('employees'), dbCount('users'), dbCount('loans'), dbCount('loan_payments'), dbCount('audit_log'));

try {
    // =================================================================
    // Fixtures: three employees, three loans, one payment.
    // =================================================================
    $empA = mkEmployee('DSH-SELF-A', 'Self', 'Alpha', 50000.00, 4, 4);
    $empB = mkEmployee('DSH-SELF-B', 'Self', 'Beta',  50000.00, 4, 4);
    $empC = mkEmployee('DSH-SELF-C', 'Self', 'Gamma', 50000.00, 4, 4);
    $userA = mkUser('dsh_self_a', 'Employee', $empA);
    mkUser('dsh_self_b', 'Employee', $empB);
    mkUser('dsh_self_c', 'Employee', $empC);

    $loanA1 = addLoan($empA, 'Salary Loan', 12000.00, 1000.00, 'Active');
    $loanA2 = addLoan($empA, 'Emergency Loan', 3000.00, 500.00, 'Active');
    $loanB  = addLoan($empB, 'Salary Loan', 9000.00, 750.00, 'Active');

    // A payslip is required by loan_payments.payslip_id (NOT NULL FK), so seed
    // one through the normal period/run tables.
    $periodId = mkPeriod('2027-04-01', '2027-04-15');
    $GLOBALS['pdo']->prepare("INSERT INTO payroll_runs (period_id, run_date, status) VALUES (?,?,?)")
        ->execute([$periodId, '2027-04-15', 'Finalized']);
    $runId = (int) $GLOBALS['pdo']->lastInsertId();
    $GLOBALS['pdo']->prepare(
        "INSERT INTO payslips (run_id, employee_id, basic_pay, overtime_pay, gross_pay, total_deductions, net_pay)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([$runId, $empA, 25000.00, 0, 25000.00, 1000.00, 24000.00]);
    $payslipId = (int) $GLOBALS['pdo']->lastInsertId();
    $GLOBALS['pdo']->prepare(
        "INSERT INTO loan_payments (loan_id, payslip_id, amount_paid, payment_date) VALUES (?,?,?,?)"
    )->execute([$loanA1, $payslipId, 1000.00, '2027-04-15']);

    $jarA = portalLogin('dsh_self_a');
    $jarB = portalLogin('dsh_self_b');
    $jarC = portalLogin('dsh_self_c');

    // =================================================================
    section('1. me_loans.php returns only the caller\'s loans');
    // =================================================================
    $r = preq('GET', 'me_loans.php', null, $jarA);
    eq('GET me_loans.php -> 200', $r['code'], 200);
    is_true('success is true', ($r['json']['success'] ?? false) === true);
    $loans = $r['json']['data'] ?? null;
    is_true('data is a list', is_array($loans) && array_is_list($loans),
        'got ' . json_encode($loans));

    $ids = array_map('intval', array_column($loans ?? [], 'loan_id'));
    eq('employee A sees exactly their two loans', count($ids), 2);
    is_true('loan A1 present', in_array($loanA1, $ids, true));
    is_true('loan A2 present', in_array($loanA2, $ids, true));
    is_true("employee B's loan is absent", !in_array($loanB, $ids, true),
        'returned loan ids ' . json_encode($ids));

    $byId = [];
    foreach ($loans as $l) $byId[(int) $l['loan_id']] = $l;

    if (isset($byId[$loanA1])) {
        eq('principal is the loan principal', $byId[$loanA1]['principal_amount'], 12000.00);
        eq('amount paid is summed from loan_payments', $byId[$loanA1]['amount_paid'], 1000.00);
        eq('outstanding balance is principal - paid', $byId[$loanA1]['outstanding_balance'], 11000.00);
        eq('monthly amortization is returned', $byId[$loanA1]['monthly_amortization'], 1000.00);
        eq('status is returned', $byId[$loanA1]['status'], 'Active');
        eq('loan type is returned', $byId[$loanA1]['loan_type'], 'Salary Loan');
        is_true('start_date key exists (schema has no such column, so null)',
            array_key_exists('start_date', $byId[$loanA1]) && $byId[$loanA1]['start_date'] === null);
        is_true('end_date key exists (schema has no such column, so null)',
            array_key_exists('end_date', $byId[$loanA1]) && $byId[$loanA1]['end_date'] === null);
        $pays = $byId[$loanA1]['payments'] ?? null;
        is_true('payment history is a list', is_array($pays) && array_is_list($pays));
        eq('the payment is listed', count($pays ?? []), 1);
        if (isset($pays[0])) {
            eq('payment amount', $pays[0]['amount_paid'] ?? null, 1000.00);
            eq('payment date', $pays[0]['payment_date'] ?? null, '2027-04-15');
            eq('payment payslip id', (int) ($pays[0]['payslip_id'] ?? 0), $payslipId);
        }
    }

    if (isset($byId[$loanA2])) {
        eq('a loan with no payments shows 0 paid', $byId[$loanA2]['amount_paid'], 0);
        eq('a loan with no payments shows full principal outstanding',
            $byId[$loanA2]['outstanding_balance'], 3000.00);
        eq('a loan with no payments has an empty payment list',
            count($byId[$loanA2]['payments'] ?? []), 0);
    }

    $r = preq('GET', 'me_loans.php', null, $jarB);
    eq("employee B's GET me_loans -> 200", $r['code'], 200);
    $idsB = array_map('intval', array_column($r['json']['data'] ?? [], 'loan_id'));
    eq("employee B sees only their own loan", $idsB, [$loanB]);

    $r = preq('GET', 'me_loans.php', null, $jarC);
    eq('an employee with no loans -> 200', $r['code'], 200);
    is_true('an employee with no loans gets success=true', ($r['json']['success'] ?? false) === true);
    is_true('an employee with no loans gets an empty list', ($r['json']['data'] ?? null) === [],
        json_encode($r['json']['data'] ?? null));

    // =================================================================
    section('2. me_profile.php GET returns the caller\'s own record');
    // =================================================================
    $r = preq('GET', 'me_profile.php', null, $jarA);
    eq('GET me_profile.php -> 200', $r['code'], 200);
    $p = $r['json']['data'] ?? [];
    eq('employee_id is the session employee', (int) ($p['employee_id'] ?? 0), $empA);
    eq('employee_number matches', $p['employee_number'] ?? null, 'DSH-SELF-A');
    eq('first name matches', $p['first_name'] ?? null, 'Self');
    eq('last name matches', $p['last_name'] ?? null, 'Alpha');
    $expectedJoin = dbRow("SELECT p.title AS position_title, d.department_name
                           FROM employees e
                           LEFT JOIN positions p ON p.position_id = e.position_id
                           LEFT JOIN departments d ON d.department_id = e.department_id
                           WHERE e.employee_id = $empA");
    eq('position_title comes from positions', $p['position_title'] ?? null,
        $expectedJoin['position_title'] ?? null);
    eq('department_name comes from departments', $p['department_name'] ?? null,
        $expectedJoin['department_name'] ?? null);
    eq('date_hired is returned', $p['date_hired'] ?? null, '2026-01-01');
    eq('employment_status is returned', $p['employment_status'] ?? null, 'Active');
    foreach (CONTACT_FIELDS as $f) {
        is_true("contact field '$f' is in the payload", array_key_exists($f, $p));
        is_true("contact field '$f' starts NULL",
            array_key_exists($f, $p) && $p[$f] === null,
            var_export(array_key_exists($f, $p) ? $p[$f] : '__MISSING__', true));
    }
    eq('government TIN is readable', $p['tin_number'] ?? null, 'TIN-DSH-SELF-A');
    eq('government SSS is readable', $p['sss_number'] ?? null, 'SSS-DSH-SELF-A');
    eq('government PhilHealth is readable', $p['philhealth_number'] ?? null, 'PH-DSH-SELF-A');
    eq('government Pag-IBIG is readable', $p['pagibig_number'] ?? null, 'PI-DSH-SELF-A');
    is_true('basic_salary is not exposed by the profile GET',
        !array_key_exists('basic_salary', $p));

    // =================================================================
    section('3. PATCH updates the five contact fields and they persist');
    // =================================================================
    $auditBefore = (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);
    $newContact = [
        'mobile_number'            => '0917 555 0101',
        'email'                    => 'self.alpha@example.com',
        'address'                  => '12 Mabini Street, Cebu City',
        'emergency_contact_name'   => 'Maria Alpha',
        'emergency_contact_number' => '0918 555 0102',
    ];
    $r = preq('PATCH', 'me_profile.php', $newContact, $jarA);
    eq('PATCH contact fields -> 200', $r['code'], 200);
    is_true('PATCH reports success', ($r['json']['success'] ?? false) === true);

    // Snapshot the audit rows written by exactly this request; later PATCHes
    // in this test are checked separately.
    $auditRows = dbAll("SELECT * FROM audit_log WHERE audit_id > $auditBefore ORDER BY audit_id");

    $db = dbRow("SELECT mobile_number, email, address, emergency_contact_name,
                        emergency_contact_number
                 FROM employees WHERE employee_id = $empA");
    eq('mobile_number persisted in the DB', $db['mobile_number'] ?? null, $newContact['mobile_number']);
    eq('email persisted in the DB', $db['email'] ?? null, $newContact['email']);
    eq('address persisted in the DB', $db['address'] ?? null, $newContact['address']);
    eq('emergency_contact_name persisted in the DB',
        $db['emergency_contact_name'] ?? null, $newContact['emergency_contact_name']);
    eq('emergency_contact_number persisted in the DB',
        $db['emergency_contact_number'] ?? null, $newContact['emergency_contact_number']);

    $r = preq('GET', 'me_profile.php', null, $jarA);
    eq('a fresh GET still returns 200', $r['code'], 200);
    $p2 = $r['json']['data'] ?? [];
    foreach ($newContact as $f => $v) {
        eq("a fresh GET returns the saved $f", $p2[$f] ?? null, $v);
    }

    // Blank -> NULL, matching the admin form's treatment of optional fields.
    $r = preq('PATCH', 'me_profile.php', ['address' => '   '], $jarA);
    eq('blank address accepted -> 200', $r['code'], 200);
    $blank = dbRow("SELECT address FROM employees WHERE employee_id = $empA");
    is_true('blank address is stored as NULL',
        array_key_exists('address', $blank) && $blank['address'] === null,
        var_export(array_key_exists('address', $blank) ? $blank['address'] : '__MISSING__', true));
    // Restore it so later assertions match $newContact.
    preq('PATCH', 'me_profile.php', ['address' => $newContact['address']], $jarA);

    // =================================================================
    section('4. Protected columns cannot be changed through the profile');
    // =================================================================
    $before = dbRow("SELECT employee_number, basic_salary, tin_number, sss_number,
                            philhealth_number, pagibig_number, position_id, department_id,
                            is_active, date_hired, mobile_number
                     FROM employees WHERE employee_id = $empA");
    $r = preq('PATCH', 'me_profile.php', [
        'employee_id'              => $empB,          // foreign employee id
        'basic_salary'             => 999999.99,
        'tin_number'               => 'HACKED-TIN',
        'sss_number'               => 'HACKED-SSS',
        'philhealth_number'        => 'HACKED-PH',
        'pagibig_number'           => 'HACKED-PI',
        'position_id'              => 5,
        'department_id'            => 5,
        'employee_number'          => 'HACKED-001',
        'is_active'                => 0,
        'date_hired'               => '2000-01-01',
        'mobile_number'            => '0917 555 0199', // the one legal field
    ], $jarA);
    eq('PATCH mixing protected fields with a legal one -> 200', $r['code'], 200);

    $after = dbRow("SELECT employee_number, basic_salary, tin_number, sss_number,
                           philhealth_number, pagibig_number, position_id, department_id,
                           is_active, date_hired, mobile_number
                    FROM employees WHERE employee_id = $empA");
    eq('basic_salary unchanged', $after['basic_salary'] ?? null, $before['basic_salary'] ?? null);
    eq('tin_number unchanged', $after['tin_number'] ?? null, $before['tin_number'] ?? null);
    eq('sss_number unchanged', $after['sss_number'] ?? null, $before['sss_number'] ?? null);
    eq('philhealth_number unchanged', $after['philhealth_number'] ?? null, $before['philhealth_number'] ?? null);
    eq('pagibig_number unchanged', $after['pagibig_number'] ?? null, $before['pagibig_number'] ?? null);
    eq('position_id unchanged', $after['position_id'] ?? null, $before['position_id'] ?? null);
    eq('department_id unchanged', $after['department_id'] ?? null, $before['department_id'] ?? null);
    eq('employee_number unchanged', $after['employee_number'] ?? null, $before['employee_number'] ?? null);
    eq('is_active unchanged', (int) ($after['is_active'] ?? -1), 1);
    eq('date_hired unchanged', $after['date_hired'] ?? null, $before['date_hired'] ?? null);
    eq('the legal field on the same request did update',
        $after['mobile_number'] ?? null, '0917 555 0199');

    $bRow = dbRow("SELECT COUNT(*) AS c FROM employees WHERE employee_id = $empB
                     AND (mobile_number IS NOT NULL OR email IS NOT NULL OR basic_salary <> 50000.00)");
    eq("employee B's row was not touched by the foreign employee_id", (int) ($bRow['c'] ?? -1), 0);

    $r = preq('PATCH', 'me_profile.php', ['basic_salary' => 1], $jarA);
    eq('a PATCH with only protected fields is refused -> 422', $r['code'], 422);

    // =================================================================
    section('5. Validation');
    // =================================================================
    $r = preq('PATCH', 'me_profile.php', ['email' => 'not-an-email'], $jarA);
    eq('invalid email -> 422', $r['code'], 422);
    is_true('the invalid-email message is about the email',
        str_contains(strtolower((string) ($r['json']['message'] ?? '')), 'email'),
        (string) ($r['json']['message'] ?? ''));
    $emailNow = dbRow("SELECT email FROM employees WHERE employee_id = $empA");
    eq('the invalid email was not stored', $emailNow['email'] ?? null, $newContact['email']);

    $r = preq('PATCH', 'me_profile.php', ['mobile_number' => str_repeat('9', 31)], $jarA);
    eq('a value longer than the column is rejected -> 422', $r['code'], 422);
    $mobileNow = dbRow("SELECT mobile_number FROM employees WHERE employee_id = $empA");
    eq('the over-long value was not stored', $mobileNow['mobile_number'] ?? null, '0917 555 0199');

    // =================================================================
    section('6. Exactly one audit_log row, with the changed fields only');
    // =================================================================
    eq('exactly one audit row was written by the successful PATCH', count($auditRows), 1);

    $ar = $auditRows[0] ?? [];
    eq('audit action', $ar['action'] ?? null, 'me_profile.php');
    eq('audit method', $ar['method'] ?? null, 'PATCH');
    eq('audit http_status', (int) ($ar['http_status'] ?? 0), 200);
    eq('audit success flag', (int) ($ar['success'] ?? 0), 1);
    eq('audit user_id', (int) ($ar['user_id'] ?? 0), $userA);
    eq('audit username', $ar['username'] ?? null, 'dsh_self_a');
    eq('audit role', $ar['role'] ?? null, 'Employee');
    eq('audit target', $ar['target'] ?? null, 'employee_id=' . $empA);
    is_true('audit ip is recorded', ($ar['ip'] ?? '') !== '', var_export($ar['ip'] ?? null, true));

    $details = json_decode((string) ($ar['details'] ?? ''), true);
    is_true('audit details is JSON', is_array($details), (string) ($ar['details'] ?? ''));
    if (is_array($details)) {
        $changedKeys = array_keys($details);
        sort($changedKeys);
        $expectedKeys = CONTACT_FIELDS; // all five were supplied and changed
        sort($expectedKeys);
        eq('audit details holds exactly the five changed fields', $changedKeys, $expectedKeys);
        eq('details old mobile_number was NULL', detailsValue($details, 'mobile_number', 'old'), null);
        eq('details new mobile_number', detailsValue($details, 'mobile_number', 'new'), $newContact['mobile_number']);
        eq('details new email', detailsValue($details, 'email', 'new'), $newContact['email']);
        is_true('audit details never contains the whole row (no basic_salary)',
            !array_key_exists('basic_salary', $details));
        is_true('audit details never contains the protected position_id',
            !array_key_exists('position_id', $details));
    }

    $countBeforeReject = dbCount('audit_log');
    $r = preq('PATCH', 'me_profile.php', ['email' => 'not-an-email'], $jarA);
    $countAfterReject = dbCount('audit_log');
    eq('a rejected PATCH writes no audit row', $countAfterReject, $countBeforeReject);

    // =================================================================
    section('7. PUT is an accepted alias; other verbs -> 405');
    // =================================================================
    $r = preq('PUT', 'me_profile.php', ['emergency_contact_number' => '0918 555 7777'], $jarA);
    eq('PUT me_profile.php -> 200', $r['code'], 200);
    $putRow = dbRow("SELECT emergency_contact_number FROM employees WHERE employee_id = $empA");
    eq('PUT persisted the contact field', $putRow['emergency_contact_number'] ?? null, '0918 555 7777');

    foreach (['me_loans.php', 'me_profile.php'] as $endpoint) {
        $r = preq('DELETE', $endpoint, null, $jarA);
        eq("DELETE $endpoint -> 405", $r['code'], 405);
        is_true("DELETE $endpoint reports failure", ($r['json']['success'] ?? true) === false);
    }
    $r = preq('POST', 'me_loans.php', ['employee_id' => $empB], $jarA);
    eq('POST me_loans.php -> 405 (and it cannot request a foreign employee)', $r['code'], 405);
    $r = preq('POST', 'me_profile.php', ['mobile_number' => '0917 000 0000'], $jarA);
    eq('POST me_profile.php -> 405', $r['code'], 405);

    // The GET-only contract must also hold for a would-be borrower.
    $r = preq('GET', "me_profile.php?employee_id=$empB", null, $jarA);
    eq('a foreign employee_id in the query string is ignored', $r['code'], 200);
    eq('... and the profile returned is still employee A',
        (int) ($r['json']['data']['employee_id'] ?? 0), $empA);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();

    $drift = [];
    foreach ($WATCH as $t) {
        $now = dbCount($t);
        if ($now !== $START[$t]) $drift[] = "$t {$START[$t]}->$now";
    }
    $drift ? bad('database fully restored', implode(', ', $drift)) : ok('database fully restored to its pre-test state');

    $REAL_AFTER = employeeSnapshot($REAL_IDS);
    is_true('real employees 4/5/8 are byte-for-byte unchanged (contact fields still NULL)',
        $REAL_AFTER === $REAL_BEFORE,
        'before=' . json_encode($REAL_BEFORE) . ' after=' . json_encode($REAL_AFTER));
}

summary();
