<?php
// =====================================================================
// tests/bank_file_test.php
//
// Step 4 of the segregation-of-duties rework: employee bank details and the
// three payment exports (bank file, payroll register, government remittance).
//
//   1. the maker can save bank_name / bank_account_name / bank_account_number
//      through employees.php, the checker cannot (403), and a blank becomes NULL
//   2. format=bank: correct header, one row per payslip, net pay per row, and a
//      trailing total that equals the sum of the rows
//   3. refused (422) when ANY included employee has no bank name / account
//      number: the message names every affected employee and no CSV is returned
//   4. a Returned run answers 409 for every format
//   5. format=register: every payslip, and the totals row adds up
//   6. format=remittance: totals match govt_contributions aggregated independently
//   7. role guards: Employee / Administrator 403, Payroll Officer / Approver 200
//   8. every successful export wrote exactly one audit_log row
//   9. a name or field beginning with =, + or - is escaped so it cannot execute
//      as a spreadsheet formula
//
// Run:  C:\xampp\php\php.exe tests\bank_file_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

// =====================================================================
// Local helpers (kept here so the shared harness stays untouched)
// =====================================================================

/** GET that also returns the response headers, for Content-Type / Disposition. */
function csvReq(string $path, ?string $jar = null): array {
    $ch = curl_init(BASE_URL . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30];
    if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw   = (string) curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $hsize);
    $body       = substr($raw, $hsize);

    $headers = [];
    foreach (explode("\n", str_replace("\r\n", "\n", $headerText)) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) continue;   // status line or blank line
        $headers[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
    }

    return ['code' => $code, 'headers' => $headers, 'body' => (string) $body, 'raw' => (string) $raw];
}

/** Split a CSV body into rows of cells. Only used on the LF-only output. */
function csvParse(string $body): array {
    $rows = [];
    foreach (explode("\n", rtrim($body, "\n")) as $line) {
        if ($line === '') continue;
        $rows[] = str_getcsv($line, ',', '"', '\\');
    }
    return $rows;
}

/** Index of a header column, or fail loudly. */
function colIndex(array $header, string $name): int {
    $idx = array_search($name, $header, true);
    if ($idx === false) throw new RuntimeException("CSV header has no column '$name': " . implode(',', $header));
    return (int) $idx;
}

/** The full employees.php PUT/POST payload for an existing fixture employee. */
function employeePayload(int $employeeId, array $overrides = []): array {
    $row = dbRow("SELECT * FROM employees WHERE employee_id = $employeeId");
    if (!$row) throw new RuntimeException("fixture employee $employeeId is missing");

    $fields = ['employee_number', 'first_name', 'last_name', 'birth_date', 'civil_status',
               'position_id', 'department_id', 'date_hired', 'employment_status',
               'basic_salary',
               'tin_number', 'sss_number', 'philhealth_number', 'pagibig_number',
               'bank_name', 'bank_account_name', 'bank_account_number',
               'mobile_number', 'email', 'address',
               'emergency_contact_name', 'emergency_contact_number'];

    $payload = ['employee_id' => $employeeId];
    foreach ($fields as $f) $payload[$f] = $row[$f];
    foreach ($overrides as $k => $v) $payload[$k] = $v;
    return $payload;
}

function auditMax(): int {
    return (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);
}

function auditSince(int $maxId, string $where = '1'): array {
    return dbAll("SELECT * FROM audit_log
                  WHERE audit_id > $maxId AND action = 'bank_file.php' AND ($where)
                  ORDER BY audit_id");
}

/** Assert exactly one audit row for an export attempt and return it. */
function auditOne(string $label, int $since, int $expectStatus, int $expectSuccess): array {
    $rows = auditSince($since);
    eq("$label: exactly one audit row", count($rows), 1);
    $row = $rows[0] ?? [];
    eq("$label: audit http_status", $row['http_status'] ?? null, $expectStatus);
    eq("$label: audit success flag", $row['success'] ?? null, $expectSuccess);
    eq("$label: audit method is GET", $row['method'] ?? null, 'GET');
    eq("$label: audit action is bank_file.php", $row['action'] ?? null, 'bank_file.php');
    return $row;
}

// =====================================================================
// Fixtures
// =====================================================================

$AUDIT_BASE = auditMax();   // captured before any fixture login writes a row
$runId      = 0;
$realBefore = [];
$successfulExports = 0;     // every 200 export response the suite performs

try {
    $makerId   = mkUser('dsh_bank_maker', 'Payroll Officer');
    $checkerId = mkUser('dsh_bank_check', 'Approver');
    $empUserId = mkUser('dsh_bank_emp',   'Employee');
    $adminId   = mkUser('dsh_bank_admin', 'Administrator');

    $makerJar   = login('dsh_bank_maker');
    $checkerJar = login('dsh_bank_check');
    $empJar     = login('dsh_bank_emp');
    $adminJar   = login('dsh_bank_admin');

    // employees 4 / 5 / 8 are real data: remember their bank fields (all NULL
    // before this migration) and prove at the end that nothing touched them.
    foreach (dbAll("SELECT employee_id, bank_name, bank_account_name, bank_account_number
                    FROM employees WHERE employee_id IN (4, 5, 8)") as $r) {
        $realBefore[(int) $r['employee_id']] = $r;
    }
    is_true('employees 4/5/8 existed before the suite and have no bank details',
        count($realBefore) === 3
        && $realBefore[4]['bank_name'] === null
        && $realBefore[5]['bank_name'] === null
        && $realBefore[8]['bank_name'] === null,
        'got ' . json_encode($realBefore));

    // DSH-BANK-C's last name starts with "=" so the CONCATed employee_name
    // ("=SUM(1+1), Fixture") starts with a formula character.
    $empA = mkEmployee('DSH-BANK-A', 'Alice',   'Banker',     15000.00);
    $empB = mkEmployee('DSH-BANK-B', 'Bob',     'Banker',     12000.00);
    $empC = mkEmployee('DSH-BANK-C', 'Fixture', '=SUM(1+1)',   9000.00);

    // ---- one Submitted run with three payslips --------------------------
    $periodId = mkPeriod('2027-09-01', '2027-09-15');
    $GLOBALS['pdo']->prepare(
        "INSERT INTO payroll_runs (period_id, processed_by, run_date, status, submitted_by, submitted_at)
         VALUES (?, ?, CURDATE(), 'Submitted', ?, NOW())"
    )->execute([$periodId, $makerId, $makerId]);
    $runId = (int) $GLOBALS['pdo']->lastInsertId();
    is_true('fixture run created', $runId > 0);

    // Amounts are chosen so that gross = basic + overtime + allowance and
    // total_deductions = statutory shares + tax + other deductions, exactly as
    // recomputePayslipTotals() would write them.
    $slipFixtures = [
        ['emp' => $empA, 'number' => 'DSH-BANK-A', 'basic' => 15000.00, 'ot' => 250.00,
         'allowance' => 500.00, 'sss_ee' => 750.00, 'sss_er' => 1500.00,
         'ph_ee' => 375.00, 'ph_er' => 375.00, 'pi_ee' => 200.00, 'pi_er' => 200.00,
         'tax' => 1200.50, 'other' => 300.00],
        ['emp' => $empB, 'number' => 'DSH-BANK-B', 'basic' => 12000.00, 'ot' => 0.00,
         'allowance' => 0.00, 'sss_ee' => 600.00, 'sss_er' => 1200.00,
         'ph_ee' => 300.00, 'ph_er' => 300.00, 'pi_ee' => 200.00, 'pi_er' => 200.00,
         'tax' => 900.25, 'other' => 0.00],
        ['emp' => $empC, 'number' => 'DSH-BANK-C', 'basic' => 9000.00, 'ot' => 0.00,
         'allowance' => 0.00, 'sss_ee' => 450.00, 'sss_er' => 900.00,
         'ph_ee' => 225.00, 'ph_er' => 225.00, 'pi_ee' => 200.00, 'pi_er' => 200.00,
         'tax' => 0.00, 'other' => 125.50],
    ];

    $slipStmt = $GLOBALS['pdo']->prepare(
        "INSERT INTO payslips (run_id, employee_id, basic_pay, overtime_pay, gross_pay, total_deductions, net_pay)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $gcStmt = $GLOBALS['pdo']->prepare(
        "INSERT INTO govt_contributions
            (payslip_id, sss_ee, sss_er, philhealth_ee, philhealth_er, pagibig_ee, pagibig_er, withholding_tax)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $allowStmt = $GLOBALS['pdo']->prepare(
        "INSERT INTO allowances (payslip_id, allowance_type, amount) VALUES (?, 'DSH Allowance', ?)"
    );
    $dedStmt = $GLOBALS['pdo']->prepare(
        "INSERT INTO deductions (payslip_id, deduction_type, amount) VALUES (?, 'DSH Deduction', ?)"
    );

    $expected = [];   // employee_number => ['gross' => ..., 'net' => ..., 'payslip_id' => ...]
    foreach ($slipFixtures as $f) {
        $gross    = round($f['basic'] + $f['ot'] + $f['allowance'], 2);
        $totalDed = round($f['sss_ee'] + $f['ph_ee'] + $f['pi_ee'] + $f['tax'] + $f['other'], 2);
        $net      = round($gross - $totalDed, 2);

        $slipStmt->execute([$runId, $f['emp'], $f['basic'], $f['ot'], $gross, $totalDed, $net]);
        $payslipId = (int) $GLOBALS['pdo']->lastInsertId();

        $gcStmt->execute([$payslipId, $f['sss_ee'], $f['sss_er'], $f['ph_ee'], $f['ph_er'],
                          $f['pi_ee'], $f['pi_er'], $f['tax']]);
        if ($f['allowance'] > 0) $allowStmt->execute([$payslipId, $f['allowance']]);
        if ($f['other'] > 0)     $dedStmt->execute([$payslipId, $f['other']]);

        $expected[$f['number']] = ['payslip_id' => $payslipId, 'gross' => $gross, 'net' => $net];
    }

    $payslipCount = (int) dbRow("SELECT COUNT(*) AS c FROM payslips WHERE run_id = $runId")['c'];
    eq('fixture run has three payslips', $payslipCount, 3);

    // =================================================================
    section('1. Bank details: maker writes, checker is refused, blank becomes NULL');
    // =================================================================
    $r = req('PUT', 'employees.php', employeePayload($empA, [
        'bank_name'           => 'Bank of the Philippine Islands',
        'bank_account_name'   => 'Alice Banker',
        'bank_account_number' => '0012-3456-7890',
    ]), $makerJar);
    eq('maker saves the three bank fields -> 200', $r['code'], 200);

    $row = dbRow("SELECT bank_name, bank_account_name, bank_account_number FROM employees WHERE employee_id = $empA");
    eq('bank_name stored', $row['bank_name'] ?? null, 'Bank of the Philippine Islands');
    eq('bank_account_name stored', $row['bank_account_name'] ?? null, 'Alice Banker');
    eq('bank_account_number stored', $row['bank_account_number'] ?? null, '0012-3456-7890');

    $r = req('GET', 'employees.php?all=1', null, $makerJar);
    eq('GET employees.php -> 200', $r['code'], 200);
    $listed = null;
    foreach (($r['json']['data'] ?? []) as $employeeRow) {
        if ((int) ($employeeRow['employee_id'] ?? 0) === $empA) $listed = $employeeRow;
    }
    is_true('the staff list exposes the bank details to the maker/checker',
        $listed !== null && ($listed['bank_name'] ?? null) === 'Bank of the Philippine Islands',
        json_encode($listed));

    $r = req('PUT', 'employees.php', employeePayload($empA, ['bank_account_number' => '9999-9999']), $checkerJar);
    eq('checker saving bank fields -> 403', $r['code'], 403);
    eq('the refused checker write changed nothing',
        dbRow("SELECT bank_account_number FROM employees WHERE employee_id = $empA")['bank_account_number'],
        '0012-3456-7890');

    $r = req('PUT', 'employees.php', employeePayload($empA, [
        'bank_account_name'   => '',
        'bank_account_number' => '   ',
    ]), $makerJar);
    eq('blank bank fields -> 200', $r['code'], 200);
    $row = dbRow("SELECT bank_name, bank_account_name, bank_account_number FROM employees WHERE employee_id = $empA");
    is_true('a blank bank_account_name is stored as NULL', $row['bank_account_name'] === null, json_encode($row));
    is_true('a whitespace-only bank_account_number is stored as NULL', $row['bank_account_number'] === null, json_encode($row));

    // POST accepts the same three fields on create.
    $r = req('POST', 'employees.php', [
        'employee_number' => 'DSH-BANK-POST', 'first_name' => 'Post', 'last_name' => 'Banker',
        'basic_salary' => '10000', 'bank_name' => 'Land Bank',
        'bank_account_name' => 'Post Banker', 'bank_account_number' => '5555-0000',
    ], $makerJar);
    eq('maker creates an employee with bank details -> 200', $r['code'], 200);
    $postEmpId = (int) ($r['json']['data']['employee_id'] ?? 0);
    is_true('POST returns the new employee_id', $postEmpId > 0, substr($r['raw'], 0, 160));
    if ($postEmpId > 0) {
        onCleanup(function () use ($postEmpId) {
            $GLOBALS['pdo']->exec("DELETE FROM employees WHERE employee_id = $postEmpId");
        });
        $row = dbRow("SELECT bank_name, bank_account_name FROM employees WHERE employee_id = $postEmpId");
        eq('POST stored the bank name', $row['bank_name'] ?? null, 'Land Bank');
        eq('POST stored the bank account name', $row['bank_account_name'] ?? null, 'Post Banker');
    }

    // Complete details again: the export tests below need them.
    $r = req('PUT', 'employees.php', employeePayload($empA, [
        'bank_name'           => 'Bank of the Philippine Islands',
        'bank_account_name'   => 'Alice Banker',
        'bank_account_number' => '0012-3456-7890',
    ]), $makerJar);
    eq('the maker can restore complete bank details -> 200', $r['code'], 200);

    // =================================================================
    section('2. format=bank refuses the whole file when an account is missing');
    // =================================================================
    // empA has details; empB and empC have none.
    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=bank", $makerJar);
    eq('one employee with no bank details -> 422', $r['code'], 422);
    $json = json_decode($r['body'], true);
    is_true('the 422 body is JSON, not a CSV file',
        is_array($json) && ($json['success'] ?? null) === false, substr($r['body'], 0, 200));
    is_true('no CSV header is returned',
        strpos($r['body'], 'employee_number,employee_name') === false, substr($r['body'], 0, 200));
    is_true('the 422 Content-Type is JSON',
        str_contains(strtolower((string) ($r['headers']['content-type'] ?? '')), 'application/json'),
        (string) ($r['headers']['content-type'] ?? 'missing'));

    $message = (string) ($json['message'] ?? '');
    is_true('the message names the first affected employee (number + name)',
        str_contains($message, 'DSH-BANK-B') && str_contains($message, 'Banker, Bob'), $message);
    is_true('the message names the second affected employee (number + name)',
        str_contains($message, 'DSH-BANK-C') && str_contains($message, '=SUM(1+1), Fixture'), $message);
    is_true('the message does not name the employee whose details are complete',
        strpos($message, 'DSH-BANK-A') === false, $message);
    is_true('the response lists the affected employees as data',
        count($json['data']['missing'] ?? []) === 2, json_encode($json['data'] ?? null));

    $row = auditOne('missing bank details', $since, 422, 0);
    eq('the refusal audit row is attributed to the maker', $row['username'] ?? null, 'dsh_bank_maker');
    eq('the refusal audit row records the Payroll Officer role', $row['role'] ?? null, 'Payroll Officer');

    // =================================================================
    section('3. The maker saves the remaining payment details');
    // =================================================================
    $r = req('PUT', 'employees.php', employeePayload($empB, [
        'bank_name'           => 'Metropolitan Bank',
        'bank_account_name'   => 'Bob Banker',
        'bank_account_number' => '0022-3333-4444',
    ]), $makerJar);
    eq('empB bank details saved -> 200', $r['code'], 200);

    // empC carries values that each need the formula guard.
    $r = req('PUT', 'employees.php', employeePayload($empC, [
        'bank_name'           => '=BDO Unibank',
        'bank_account_name'   => '+Account Holder',
        'bank_account_number' => '-00-123',
    ]), $makerJar);
    eq('empC bank details saved -> 200', $r['code'], 200);
    eq('empC bank name stored verbatim',
        dbRow("SELECT bank_name FROM employees WHERE employee_id = $empC")['bank_name'], '=BDO Unibank');

    // =================================================================
    section('4. format=bank: header, one row per payslip, correct net pay');
    // =================================================================
    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=bank", $makerJar);
    eq('bank file -> 200', $r['code'], 200);
    $successfulExports++;
    eq('Content-Type is text/csv',
        strtolower(trim(explode(';', (string) ($r['headers']['content-type'] ?? ''))[0])), 'text/csv');

    $disposition = (string) ($r['headers']['content-disposition'] ?? '');
    is_true('Content-Disposition is an attachment', stripos($disposition, 'attachment') !== false, $disposition);
    is_true('the filename carries the period start date', str_contains($disposition, '2027-09-01'), $disposition);
    is_true('the filename carries the period end date', str_contains($disposition, '2027-09-15'), $disposition);
    is_true('the filename carries the format', str_contains($disposition, 'bank-file'), $disposition);
    is_true('the file uses LF line endings only', strpos($r['body'], "\r") === false, 'a carriage return was found');

    $rows = csvParse($r['body']);
    eq('bank header row', implode(',', $rows[0] ?? []),
        'employee_number,employee_name,bank_name,bank_account_name,bank_account_number,net_pay');
    eq('one row per payslip plus a total row', count($rows) - 2, $payslipCount);

    $header   = $rows[0];
    $netIdx   = colIndex($header, 'net_pay');
    $dataRows = array_slice($rows, 1, -1);
    $totalRow = $rows[count($rows) - 1];

    $rowSum = 0.0;
    $seen   = [];
    foreach ($dataRows as $row) {
        $number = (string) $row[0];
        $seen[$number] = true;
        $ps = dbRow("SELECT ps.payslip_id, ps.net_pay
                     FROM payslips ps
                     JOIN employees e ON e.employee_id = ps.employee_id
                     WHERE ps.run_id = $runId AND e.employee_number = " . $GLOBALS['pdo']->quote($number));
        is_true("a payslip exists for $number", $ps !== false, $number);
        eq("net pay for $number matches the payslip", (float) $row[$netIdx], (float) $ps['net_pay']);
        $rowSum += (float) $row[$netIdx];
    }
    foreach ($expected as $number => $info) {
        is_true("payslip $number appears in the bank file", isset($seen[$number]));
    }

    eq('the total row is labelled TOTAL', $totalRow[0] ?? null, 'TOTAL');
    eq('the trailing total equals the sum of the rows', (float) $totalRow[$netIdx], round($rowSum, 2));

    $rowA = null;
    foreach ($dataRows as $row) {
        if ((string) $row[0] === 'DSH-BANK-A') $rowA = $row;
    }
    is_true('the bank row carries the bank name saved through employees.php',
        $rowA !== null && $rowA[colIndex($header, 'bank_name')] === 'Bank of the Philippine Islands',
        json_encode($rowA));

    $row = auditOne('bank export', $since, 200, 1);
    eq('the audit row is attributed to the maker', $row['username'] ?? null, 'dsh_bank_maker');
    eq('the audit row records the Payroll Officer role', $row['role'] ?? null, 'Payroll Officer');
    eq('the audit target is the run', $row['target'] ?? null, 'run_id=' . $runId);
    is_true('the audit details name the format',
        str_contains((string) ($row['details'] ?? ''), '"bank"'), (string) ($row['details'] ?? 'NULL'));

    // =================================================================
    section('5. format=register: every payslip, and the totals row adds up');
    // =================================================================
    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=register", $checkerJar);
    eq('register file -> 200 for the Approver', $r['code'], 200);
    $successfulExports++;
    is_true('the register filename carries the format', str_contains(
        strtolower((string) ($r['headers']['content-disposition'] ?? '')), 'payroll-register'),
        (string) ($r['headers']['content-disposition'] ?? ''));
    is_true('the register uses LF line endings only', strpos($r['body'], "\r") === false);

    $rows = csvParse($r['body']);
    eq('register header row', implode(',', $rows[0] ?? []),
        'employee_number,employee_name,position,department,basic_pay,overtime_pay,gross_pay,'
        . 'sss_ee,philhealth_ee,pagibig_ee,withholding_tax,other_deductions,total_deductions,net_pay');
    eq('the register has one row per payslip plus a totals row', count($rows) - 2, $payslipCount);

    $header   = $rows[0];
    $dataRows = array_slice($rows, 1, -1);
    $totals   = $rows[count($rows) - 1];
    eq('the totals row is labelled TOTAL', $totals[0] ?? null, 'TOTAL');

    $rowSum = 0.0;
    $seen   = [];
    foreach ($dataRows as $row) {
        $number = (string) $row[0];
        $seen[$number] = true;
        $rowSum += (float) $row[colIndex($header, 'net_pay')];
    }
    foreach (array_keys($expected) as $number) {
        is_true("the register includes $number", isset($seen[$number]));
    }
    eq('the register net pay column sums to the payslip nets', $rowSum,
        (float) dbRow("SELECT COALESCE(SUM(net_pay), 0) AS s FROM payslips WHERE run_id = $runId")['s']);

    // Every numeric column: sum of the data rows must equal the totals row.
    foreach (['basic_pay', 'overtime_pay', 'gross_pay', 'sss_ee', 'philhealth_ee', 'pagibig_ee',
              'withholding_tax', 'other_deductions', 'total_deductions', 'net_pay'] as $column) {
        $idx = colIndex($header, $column);
        $sum = 0.0;
        foreach ($dataRows as $row) $sum += (float) $row[$idx];
        eq("the totals row adds up for $column", (float) $totals[$idx], round($sum, 2));
    }

    // The register reads the real columns: gross includes the allowance and
    // total_deductions is the sum of its parts for this fixture.
    $rowA = null;
    foreach ($dataRows as $row) {
        if ((string) $row[0] === 'DSH-BANK-A') $rowA = $row;
    }
    is_true('empA is on the register', $rowA !== null);
    if ($rowA !== null) {
        eq('the register gross pay matches the payslip', (float) $rowA[colIndex($header, 'gross_pay')],
            $expected['DSH-BANK-A']['gross']);
        eq('the register other_deductions matches the deductions table', (float) $rowA[colIndex($header, 'other_deductions')], 300.00);
        eq('the register total_deductions is the sum of its parts',
            (float) $rowA[colIndex($header, 'total_deductions')],
            round((float) $rowA[colIndex($header, 'sss_ee')]
                + (float) $rowA[colIndex($header, 'philhealth_ee')]
                + (float) $rowA[colIndex($header, 'pagibig_ee')]
                + (float) $rowA[colIndex($header, 'withholding_tax')]
                + (float) $rowA[colIndex($header, 'other_deductions')], 2));
        eq('the register position comes from positions', (string) $rowA[colIndex($header, 'position')],
            (string) (dbRow("SELECT p.title AS t FROM employees e
                             LEFT JOIN positions p ON p.position_id = e.position_id
                             WHERE e.employee_id = $empA")['t'] ?? ''));
    }

    $row = auditOne('register export', $since, 200, 1);
    eq('the register audit row is attributed to the checker', $row['username'] ?? null, 'dsh_bank_check');

    // =================================================================
    section('6. format=remittance: totals match govt_contributions');
    // =================================================================
    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=remittance", $makerJar);
    eq('remittance file -> 200', $r['code'], 200);
    $successfulExports++;
    is_true('the remittance filename carries the format', str_contains(
        strtolower((string) ($r['headers']['content-disposition'] ?? '')), 'govt-remittance'),
        (string) ($r['headers']['content-disposition'] ?? ''));

    $rows = csvParse($r['body']);
    eq('remittance header row', implode(',', $rows[0] ?? []),
        'employee_number,employee_name,sss_ee,sss_er,sss_total,'
        . 'philhealth_ee,philhealth_er,philhealth_total,'
        . 'pagibig_ee,pagibig_er,pagibig_total,grand_total');
    eq('the remittance has one row per payslip plus a grand total row', count($rows) - 2, $payslipCount);

    $header   = $rows[0];
    $dataRows = array_slice($rows, 1, -1);
    $grand    = $rows[count($rows) - 1];
    eq('the grand total row is labelled GRAND TOTAL', $grand[0] ?? null, 'GRAND TOTAL');

    // Independent aggregation, straight from the database.
    $agg = dbRow(
        "SELECT COALESCE(SUM(gc.sss_ee), 0)        AS sss_ee,
                COALESCE(SUM(gc.sss_er), 0)        AS sss_er,
                COALESCE(SUM(gc.philhealth_ee), 0) AS philhealth_ee,
                COALESCE(SUM(gc.philhealth_er), 0) AS philhealth_er,
                COALESCE(SUM(gc.pagibig_ee), 0)    AS pagibig_ee,
                COALESCE(SUM(gc.pagibig_er), 0)    AS pagibig_er
         FROM govt_contributions gc
         JOIN payslips ps ON ps.payslip_id = gc.payslip_id
         WHERE ps.run_id = $runId"
    );

    foreach (['sss_ee', 'sss_er', 'philhealth_ee', 'philhealth_er', 'pagibig_ee', 'pagibig_er'] as $column) {
        eq("the remittance total for $column matches SUM($column)",
            (float) $grand[colIndex($header, $column)], (float) $agg[$column]);
    }
    foreach (['sss', 'philhealth', 'pagibig'] as $agency) {
        eq("the remittance {$agency}_total is employee + employer",
            (float) $grand[colIndex($header, "{$agency}_total")],
            round((float) $agg["{$agency}_ee"] + (float) $agg["{$agency}_er"], 2));
    }
    $sixTotal = 0.0;
    foreach (['sss_ee', 'sss_er', 'philhealth_ee', 'philhealth_er', 'pagibig_ee', 'pagibig_er'] as $column) {
        $sixTotal += (float) $agg[$column];
    }
    eq('the grand_total is every statutory amount added together',
        (float) $grand[colIndex($header, 'grand_total')], round($sixTotal, 2));

    // Each employee row is that payslip's own contributions.
    $rowB = null;
    foreach ($dataRows as $row) {
        if ((string) $row[0] === 'DSH-BANK-B') $rowB = $row;
    }
    is_true('empB is on the remittance file', $rowB !== null);
    if ($rowB !== null) {
        $gcB = dbRow("SELECT gc.* FROM govt_contributions gc
                      JOIN payslips ps ON ps.payslip_id = gc.payslip_id
                      WHERE ps.run_id = $runId AND ps.employee_id = $empB");
        eq('the per-employee sss_ee matches govt_contributions',
            (float) $rowB[colIndex($header, 'sss_ee')], (float) $gcB['sss_ee']);
        eq('the per-employee pagibig_er matches govt_contributions',
            (float) $rowB[colIndex($header, 'pagibig_er')], (float) $gcB['pagibig_er']);
    }

    $row = auditOne('remittance export', $since, 200, 1);
    eq('the remittance audit row records the Payroll Officer role', $row['role'] ?? null, 'Payroll Officer');

    // =================================================================
    section('7. Formula injection is defused in every cell');
    // =================================================================
    $r = csvReq("bank_file.php?run_id=$runId&format=bank", $makerJar);
    eq('bank file for the escaping check -> 200', $r['code'], 200);
    $successfulExports++;
    $rows = csvParse($r['body']);

    $bad = [];
    foreach ($rows as $rowNo => $row) {
        foreach ($row as $colNo => $cell) {
            $cell = (string) $cell;
            if ($cell !== '' && strpbrk($cell[0], '=+-@') !== false) {
                $bad[] = "row $rowNo col $colNo: $cell";
            }
        }
    }
    is_true('no cell of the bank file starts with =, +, - or @', count($bad) === 0, json_encode($bad));

    $rowC = null;
    foreach ($rows as $row) {
        if ((string) $row[0] === 'DSH-BANK-C') $rowC = $row;
    }
    is_true('empC is on the bank file', $rowC !== null);
    if ($rowC !== null) {
        $header = $rows[0];
        $name = (string) $rowC[colIndex($header, 'employee_name')];
        is_true('the employee name beginning with = is prefixed with a single quote',
            str_starts_with($name, "'="), $name);
        $bankName = (string) $rowC[colIndex($header, 'bank_name')];
        is_true('a bank name beginning with = is prefixed with a single quote',
            str_starts_with($bankName, "'="), $bankName);
        $accountName = (string) $rowC[colIndex($header, 'bank_account_name')];
        is_true('an account name beginning with + is prefixed with a single quote',
            str_starts_with($accountName, "'+"), $accountName);
        $accountNo = (string) $rowC[colIndex($header, 'bank_account_number')];
        is_true('an account number beginning with - is prefixed with a single quote',
            str_starts_with($accountNo, "'-"), $accountNo);
    }

    // Same guard in the register, where the name is the only text field.
    $r = csvReq("bank_file.php?run_id=$runId&format=register", $makerJar);
    eq('register file for the escaping check -> 200', $r['code'], 200);
    $successfulExports++;
    $rows = csvParse($r['body']);
    $rowC = null;
    foreach (array_slice($rows, 1, -1) as $row) {
        if ((string) $row[0] === 'DSH-BANK-C') $rowC = $row;
    }
    is_true('the register escapes the = name too',
        $rowC !== null && str_starts_with((string) $rowC[1], "'="), json_encode($rowC));

    // =================================================================
    section('8. Role guards: maker and checker only');
    // =================================================================
    $r = csvReq("bank_file.php?run_id=$runId&format=register", $empJar);
    eq('Employee exporting -> 403', $r['code'], 403);
    is_true('the Employee 403 body is success=false',
        (json_decode($r['body'], true)['success'] ?? null) === false, substr($r['body'], 0, 160));

    $r = csvReq("bank_file.php?run_id=$runId&format=register", $adminJar);
    eq('Administrator exporting -> 403', $r['code'], 403);
    is_true('the Administrator 403 body is success=false',
        (json_decode($r['body'], true)['success'] ?? null) === false, substr($r['body'], 0, 160));

    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=register", $makerJar);
    eq('Payroll Officer exporting -> 200', $r['code'], 200);
    $successfulExports++;
    $row = auditOne('maker export', $since, 200, 1);
    eq('the maker export is audited as a success', $row['success'] ?? null, 1);

    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=register", $checkerJar);
    eq('Approver exporting -> 200', $r['code'], 200);
    $successfulExports++;
    auditOne('checker export', $since, 200, 1);

    $r = csvReq("bank_file.php?run_id=$runId&format=register", null);
    eq('anonymous export -> 401', $r['code'], 401);

    // ---- contract guards ----
    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=999999999&format=bank", $makerJar);
    eq('unknown run -> 404', $r['code'], 404);
    auditOne('unknown run', $since, 404, 0);

    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=pdf", $makerJar);
    eq('unknown format -> 422', $r['code'], 422);
    auditOne('unknown format', $since, 422, 0);

    $r = req('POST', 'bank_file.php', ['run_id' => $runId, 'format' => 'bank'], $makerJar);
    eq('POST to the read-only endpoint -> 405', $r['code'], 405);

    // =================================================================
    section('9. A Returned run answers 409 for every format');
    // =================================================================
    $r = req('PATCH', 'payroll_runs.php', [
        'run_id' => $runId, 'action' => 'return',
        'remarks' => 'DSH: returned so the export guard can be tested.',
    ], $checkerJar);
    eq('the checker returns the run -> 200', $r['code'], 200);
    eq('the run is Returned', dbRow("SELECT status FROM payroll_runs WHERE run_id = $runId")['status'], 'Returned');

    foreach (['bank', 'register', 'remittance'] as $format) {
        $since = auditMax();
        $r = csvReq("bank_file.php?run_id=$runId&format=$format", $makerJar);
        eq("$format on a Returned run -> 409", $r['code'], 409);
        $json = json_decode($r['body'], true);
        is_true("the $format 409 is JSON with success=false",
            is_array($json) && ($json['success'] ?? null) === false, substr($r['body'], 0, 160));
        is_true("the $format 409 explains the run is being reworked",
            stripos((string) ($json['message'] ?? ''), 'Returned') !== false, (string) ($json['message'] ?? ''));
        is_true("the $format 409 returns no CSV body",
            strpos($r['body'], 'employee_number') === false, substr($r['body'], 0, 160));
        auditOne("$format 409", $since, 409, 0);
    }

    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runId, 'action' => 'resubmit'], $makerJar);
    eq('the maker resubmits -> 200', $r['code'], 200);
    $since = auditMax();
    $r = csvReq("bank_file.php?run_id=$runId&format=bank", $makerJar);
    eq('after resubmission the bank file exports again -> 200', $r['code'], 200);
    $successfulExports++;
    auditOne('post-resubmission bank export', $since, 200, 1);

    // =================================================================
    section('10. Audit summary: one row per export attempt');
    // =================================================================
    $successRows = dbAll("SELECT * FROM audit_log
                          WHERE audit_id > $AUDIT_BASE AND action = 'bank_file.php'
                            AND method = 'GET' AND success = 1");
    $refusedRows = dbAll("SELECT * FROM audit_log
                          WHERE audit_id > $AUDIT_BASE AND action = 'bank_file.php'
                            AND method = 'GET' AND success = 0");
    $attemptRows = dbAll("SELECT * FROM audit_log
                          WHERE audit_id > $AUDIT_BASE AND action = 'bank_file.php'
                            AND method = 'GET'");

    // Every 200 export this suite performed left exactly one row behind.
    eq('one audit row per successful export', count($successRows), $successfulExports);
    is_true('refused attempts are on the audit trail too', count($refusedRows) >= 6,
        'refused rows: ' . count($refusedRows));
    eq('every attempt wrote exactly one row',
        count($attemptRows), count($successRows) + count($refusedRows));

    $statuses = array_map(fn($r) => (int) $r['http_status'], $refusedRows);
    sort($statuses);
    foreach ([403, 404, 409, 422] as $expectedStatus) {
        is_true("the audit trail records a $expectedStatus refusal", in_array($expectedStatus, $statuses, true),
            'statuses: ' . json_encode($statuses));
    }
    $badTarget = array_filter($successRows, fn($r) => ($r['target'] ?? '') !== 'run_id=' . $runId);
    eq('every successful export audit row targets the run', count($badTarget), 0);
    $withBankAccount = array_filter($attemptRows, fn($r) => str_contains((string) ($r['details'] ?? ''), '0012-3456-7890'));
    eq('the audit trail never stores a bank account number', count($withBankAccount), 0);

    // The rejected POST is logged by respond()'s own hook, not by the endpoint.
    $postRows = dbAll("SELECT * FROM audit_log
                       WHERE audit_id > $AUDIT_BASE AND action = 'bank_file.php' AND method = 'POST'");
    eq('the rejected POST is audited once', count($postRows), 1);
    eq('the rejected POST is audited with its 405 status', (int) ($postRows[0]['http_status'] ?? 0), 405);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();

    // Remove only the audit rows this suite caused (fixture logins included).
    $GLOBALS['pdo']->exec("DELETE FROM audit_log WHERE audit_id > " . (int) $AUDIT_BASE);

    // employees 4/5/8 were never written to; prove it, then re-assert NULL as a
    // belt-and-braces restore of their previous values.
    $realAfter = dbAll("SELECT employee_id, bank_name, bank_account_name, bank_account_number
                        FROM employees WHERE employee_id IN (4, 5, 8)");
    $dirty = array_filter($realAfter, fn($r) => $r['bank_name'] !== null
        || $r['bank_account_name'] !== null || $r['bank_account_number'] !== null);
    $GLOBALS['pdo']->exec("UPDATE employees SET bank_name = NULL, bank_account_name = NULL,
                           bank_account_number = NULL WHERE employee_id IN (4, 5, 8)");

    printf("\n=== cleanup verification ===\n");
    printf("  fixture employees left:  %d\n", dbCount('employees', "employee_number LIKE 'DSH-BANK-%'"));
    printf("  fixture run left:        %d\n", dbCount('payroll_runs', "run_id = " . (int) $runId));
    printf("  fixture users left:      %d\n", dbCount('users', "username LIKE 'dsh\\_bank\\_%'"));
    printf("  audit rows after base:   %d\n", dbCount('audit_log', "audit_id > " . (int) $AUDIT_BASE));

    is_true('employees 4/5/8 were never written to', count($dirty) === 0, json_encode($dirty));
    is_true('employees 4/5/8 have NULL bank fields again',
        dbCount('employees', "employee_id IN (4, 5, 8) AND (
                    bank_name IS NOT NULL OR bank_account_name IS NOT NULL OR bank_account_number IS NOT NULL)") === 0,
        'a bank field on a real employee survived');
    is_true('all fixtures cleaned up',
        dbCount('employees', "employee_number LIKE 'DSH-BANK-%'") === 0
        && dbCount('payroll_runs', "run_id = " . (int) $runId) === 0
        && dbCount('users', "username LIKE 'dsh\\_bank\\_%'") === 0
        && dbCount('audit_log', "audit_id > " . (int) $AUDIT_BASE) === 0,
        'a fixture row survived cleanup');
}

summary();
