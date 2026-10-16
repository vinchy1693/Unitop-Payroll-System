<?php
// =====================================================================
// tests/payroll_run_test.php
// Drives the REAL payroll endpoint against a temporary period, to verify
// the fixed pipeline end-to-end. Everything it creates is removed after.
// Run:  C:\xampp\php\php.exe tests\payroll_run_test.php
// =====================================================================

const BASE = 'http://localhost/master_file_module_api/api/';
const TMP_USER = 'dsh_test_payroll';
const TMP_PW   = 'TestPass123';

$PASS = 0; $FAIL = 0; $NOTES = [];
function ok(string $n): void { global $PASS; $PASS++; printf("  PASS  %s\n", $n); }
function bad(string $n, string $d): void { global $FAIL; $FAIL++; printf("  FAIL  %s\n        -> %s\n", $n, $d); }
function eq(string $n, $a, $e, float $tol = 0.011): void {
    if (is_numeric($a) && is_numeric($e)) {
        if (abs((float)$a - (float)$e) <= $tol) { ok($n); return; }
    } elseif ($a === $e) { ok($n); return; }
    bad($n, "expected " . var_export($e, true) . ", got " . var_export($a, true));
}
function is_true(string $n, bool $c, string $d = ''): void { $c ? ok($n) : bad($n, $d ?: 'condition false'); }
function note(string $s): void { global $NOTES; $NOTES[] = $s; }
function section(string $t): void { printf("\n=== %s ===\n", $t); }

function req(string $method, string $path, ?array $body = null, ?string $jar = null): array {
    $ch = curl_init(BASE . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30];
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
    if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => $raw, 'json' => json_decode((string)$raw, true)];
}

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$jar   = sys_get_temp_dir() . '/dsh_pr_' . getmypid() . '.txt';
$count = fn(string $t) => (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
$before = ['payroll_periods' => $count('payroll_periods'), 'payroll_runs' => $count('payroll_runs'),
           'payslips' => $count('payslips'), 'deductions' => $count('deductions'),
           'govt_contributions' => $count('govt_contributions'), 'users' => $count('users')];

$periodId = null; $runId = null;

try {
    $pdo->prepare("INSERT INTO users (username, employee_id, role, is_active, password_hash) VALUES (?, NULL, 'Administrator', 1, ?)")
        ->execute([TMP_USER, password_hash(TMP_PW, PASSWORD_DEFAULT)]);

    $pdo->prepare("INSERT INTO payroll_periods (start_date, end_date, period_type, status) VALUES ('2026-10-16','2026-10-31','Semi-monthly','Open')")
        ->execute();
    $periodId = (int) $pdo->lastInsertId();
    printf("temp period_id = %d\n", $periodId);

    $r = req('POST', 'auth.php?action=login', ['username' => TMP_USER, 'password' => TMP_PW], $jar);
    eq('login -> 200', $r['code'], 200);

    // =================================================================
    section('1. Run payroll through the real endpoint');
    // =================================================================
    $r = req('POST', 'payroll_runs.php', ['period_id' => $periodId], $jar);
    printf("  response: HTTP %d  %s\n", $r['code'], $r['json']['message'] ?? '');
    eq('run -> 200', $r['code'], 200);
    eq('one payslip generated', $r['json']['data']['generated'] ?? null, 1);
    $runId = (int) ($r['json']['data']['run_id'] ?? 0);
    is_true('run_id returned', $runId > 0, 'no run_id');

    // =================================================================
    section('2. Inspect the generated payslip');
    // =================================================================
    $ps = $pdo->query("SELECT * FROM payslips WHERE run_id = $runId")->fetch();
    if (!$ps) { bad('payslip exists for the run', 'none found'); throw new RuntimeException('no payslip'); }
    $pid  = (int) $ps['payslip_id'];
    $gc   = $pdo->query("SELECT * FROM govt_contributions WHERE payslip_id = $pid")->fetch();
    $deds = $pdo->query("SELECT deduction_type, amount FROM deductions WHERE payslip_id = $pid")->fetchAll();

    printf("  basic=%s overtime=%s gross=%s total_deductions=%s net=%s\n",
        $ps['basic_pay'], $ps['overtime_pay'], $ps['gross_pay'], $ps['total_deductions'], $ps['net_pay']);
    printf("  sss_ee=%s philhealth_ee=%s pagibig_ee=%s withholding_tax=%s\n",
        $gc['sss_ee'], $gc['philhealth_ee'], $gc['pagibig_ee'], $gc['withholding_tax']);
    foreach ($deds as $d) printf("  deduction: %s = %s\n", $d['deduction_type'], $d['amount']);

    is_true('net pay is NOT negative', (float)$ps['net_pay'] >= 0, "net_pay = {$ps['net_pay']}");
    eq('net pay = 417.86 under the Mon-Sat week', $ps['net_pay'], 417.86);
    eq('withholding tax = 0 (taxable base is below the exemption)', $gc['withholding_tax'], 0.00);
    eq('SSS EE still full 875.00 (contributions ignore attendance)', $gc['sss_ee'], 875.00);
    eq('gross pay = 30,000.00', $ps['gross_pay'], 30000.00);
    eq('total deductions = 29,582.14 (27,857.14 absence + 1,725.00 statutory)', $ps['total_deductions'], 29582.14);
    eq('gross - total_deductions = net_pay', round((float)$ps['gross_pay'] - (float)$ps['total_deductions'], 2), $ps['net_pay']);

    $abs = null; foreach ($deds as $d) if (str_starts_with($d['deduction_type'], 'Absences')) $abs = $d;
    is_true('an Absences deduction line exists', $abs !== null, 'no Absences row');
    if ($abs) eq('absence deduction = 27,857.14 (13 days x 2,142.86)', $abs['amount'], 27857.14);

    // =================================================================
    section('3. Period lifecycle + re-run guard');
    // =================================================================
    $st = $pdo->query("SELECT status FROM payroll_periods WHERE period_id = $periodId")->fetchColumn();
    eq('period marked Processed', $st, 'Processed');
    $r = req('POST', 'payroll_runs.php', ['period_id' => $periodId], $jar);
    eq('re-running a Processed period -> 409', $r['code'], 409);

    // =================================================================
    section('4. Recompute is idempotent');
    // =================================================================
    require __DIR__ . '/../api/_payroll_helpers.php';
    $rc1 = recomputePayslipTotals($pdo, $pid);
    $rc2 = recomputePayslipTotals($pdo, $pid);
    is_true('recomputing twice yields the same net pay', $rc1['net_pay'] === $rc2['net_pay'], "{$rc1['net_pay']} vs {$rc2['net_pay']}");
    eq('recompute agrees with the stored value', $rc2['net_pay'], $ps['net_pay']);

} catch (Throwable $e) {
    bad('harness completed without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    // ---- cleanup (children first, FK order) ----
    if ($runId !== null) {
        $ids = $pdo->query("SELECT payslip_id FROM payslips WHERE run_id = $runId")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            foreach (['deductions', 'govt_contributions', 'loan_payments', 'allowances', 'cash_disbursements'] as $t) {
                $pdo->exec("DELETE FROM `$t` WHERE payslip_id IN ($in)");
            }
            $pdo->exec("DELETE FROM payslips WHERE run_id = $runId");
        }
        $pdo->exec("DELETE FROM payroll_runs WHERE run_id = $runId");
    }
    if ($periodId !== null) $pdo->exec("DELETE FROM payroll_periods WHERE period_id = $periodId");
    $pdo->exec("DELETE FROM attendance WHERE scan_device_id = 'TEST'");
    $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([TMP_USER]);
    if (file_exists($jar)) unlink($jar);

    printf("\n=== cleanup verification ===\n");
    $clean = true;
    foreach ($before as $t => $n) {
        $now = $count($t);
        printf("  %-20s %d -> %d %s\n", $t, $n, $now, $n === $now ? '(restored)' : '(LEFTOVER!)');
        if ($n !== $now) $clean = false;
    }
    $clean ? ok('all test data removed (row counts restored)') : bad('test data fully cleaned up', 'row counts changed');
}

printf("\n================ SUMMARY ================\n");
printf("PASS: %d   FAIL: %d\n", $PASS, $FAIL);
if ($NOTES) { echo "\nFindings:\n"; foreach ($NOTES as $n) printf("  * %s\n", $n); }
