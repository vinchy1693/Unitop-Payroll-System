<?php
// =====================================================================
// tests/smoke_readonly.php
// Read-only health check of every GET endpoint. Useful after a data reset
// to confirm empty result sets do not break anything.
// Creates one temporary user and removes it afterwards. No other writes.
// Run:  C:\xampp\php\php.exe tests\smoke_readonly.php
// =====================================================================

const BASE = 'http://localhost/master_file_module_api/api/';
const TMP_USER = 'dsh_smoke_user';
const TMP_PW   = 'TestPass123';

$bad = 0; $good = 0; $notes = [];
function req(string $m, string $p, ?array $b = null, ?string $jar = null): array {
    $ch = curl_init(BASE . $p);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 25];
    if ($b !== null) $o[CURLOPT_POSTFIELDS] = json_encode($b);
    if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => $raw, 'json' => json_decode((string)$raw, true)];
}

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$jarA = sys_get_temp_dir() . '/dsh_sa_' . getmypid() . '.txt';
$jarE = sys_get_temp_dir() . '/dsh_se_' . getmypid() . '.txt';
$usersBefore = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$checks = [
    'dashboard.php',
    'employees.php',
    'departments.php',
    'positions.php',
    'salary_rates.php',
    'payroll_periods.php',
    'payroll_runs.php',
    'payslips.php?run_id=1',
    'allowances.php?payslip_id=1',
    'deductions.php?payslip_id=1',
    'loans.php',
    'overtime_records.php?employee_id=3',
    'leave_types.php',
    'leave_balances.php',
    'leave_requests.php',
    'qr_codes.php',
    'users.php',
    'attendance.php?date=2026-10-16',
    'attendance.php?summary=1&from=2026-10-16&to=2026-10-31',
    'attendance.php?employee_detail=1&employee_id=3&from=2026-10-16&to=2026-10-31',
    'ledger.php?employee_id=3&from=2026-10-01&to=2026-11-30',
    'reports.php?report=payroll_register&run_id=1',
    'reports.php?report=payroll_summary&run_id=1',
    'reports.php?report=remittance&year=2026&month=10',
    'reports.php?report=employee_history&employee_id=3',
    'reports.php?report=department_summary&from=2026-10-16&to=2026-10-31',
];

// reports.php?run_id=1 is only a 404 when that run does not actually exist.
$runOneExists = (int) $pdo->query("SELECT COUNT(*) FROM payroll_runs WHERE run_id = 1")->fetchColumn() > 0;
$expect404 = $runOneExists ? [] : [
    'reports.php?report=payroll_register&run_id=1',
    'reports.php?report=payroll_summary&run_id=1',
];

try {
    $pdo->prepare("INSERT INTO users (username, employee_id, role, is_active, password_hash) VALUES (?, NULL, 'Administrator', 1, ?)")
        ->execute([TMP_USER, password_hash(TMP_PW, PASSWORD_DEFAULT)]);

    $r = req('POST', 'auth.php?action=login', ['username' => TMP_USER, 'password' => TMP_PW], $jarA);
    if ($r['code'] !== 200) throw new RuntimeException("admin login failed: HTTP {$r['code']}");

    printf("%-58s %s\n", 'ENDPOINT', 'RESULT');
    printf("%s\n", str_repeat('-', 74));
    foreach ($checks as $ep) {
        $r = req('GET', $ep, null, $jarA);
        $want = in_array($ep, $expect404, true) ? 404 : 200;
        $ok = $r['code'] === $want;
        $ok ? $good++ : $bad++;
        printf("%-58s HTTP %-4d %s\n", $ep, $r['code'],
            $ok ? ($want === 404 ? 'ok (404 as expected)' : 'ok')
                : 'FAIL: ' . substr((string)($r['json']['message'] ?? $r['raw']), 0, 40));
        if (!$ok) $notes[] = "$ep -> HTTP {$r['code']} " . ($r['json']['message'] ?? '');
        if ($r['code'] === 200 && ($r['json']['success'] ?? null) !== true) {
            $notes[] = "$ep returned HTTP 200 but success=false: " . ($r['json']['message'] ?? '');
        }
    }

    // Employee portal with the reset data
    $pdo->prepare("INSERT INTO users (username, employee_id, role, is_active, password_hash) VALUES ('dsh_smoke_emp', 3, 'Employee', 1, ?)")
        ->execute([password_hash(TMP_PW, PASSWORD_DEFAULT)]);
    $r = req('POST', 'auth.php?action=login', ['username' => 'dsh_smoke_emp', 'password' => TMP_PW], $jarE);
    if ($r['code'] === 200) {
        foreach (['me_attendance.php', 'me_payslips.php', 'me_leave_balances.php', 'me_leave_requests.php'] as $ep) {
            $r = req('GET', $ep, null, $jarE);
            $ok = $r['code'] === 200;
            $ok ? $good++ : $bad++;
            printf("%-58s HTTP %-4d %s\n", $ep, $r['code'], $ok ? 'ok' : 'FAIL');
            if (!$ok) $notes[] = "$ep -> HTTP {$r['code']}";
        }
    } else { $notes[] = 'employee login failed'; }

} catch (Throwable $e) {
    $bad++;
    printf("harness error: %s\n", $e->getMessage());
} finally {
    $pdo->exec("DELETE FROM users WHERE username IN ('" . TMP_USER . "','dsh_smoke_emp')");
    foreach ([$jarA, $jarE] as $j) { if (file_exists($j)) unlink($j); }
}

printf("\n%s\n", str_repeat('=', 74));
printf("endpoints OK: %d   problems: %d\n", $good, $bad);
printf("users rows: %d -> %d %s\n", $usersBefore, (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    $usersBefore === (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ? '(restored)' : '(LEFTOVER!)');
if ($notes) { echo "\nNotes:\n"; foreach ($notes as $n) printf("  * %s\n", $n); }
