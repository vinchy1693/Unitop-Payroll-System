<?php
// =====================================================================
// tests/_harness.php
// Shared helpers for the full-system suite: assertions, an HTTP client,
// database access, fixture builders, and a cleanup registry that removes
// everything a test created (children first, in reverse order).
// =====================================================================

const BASE_URL = 'http://localhost/master_file_module_api/api/';
const TEST_PW  = 'TestPass123';

$GLOBALS['PASS'] = 0;
$GLOBALS['FAIL'] = 0;
$GLOBALS['NOTES'] = [];
$GLOBALS['CLEANUP'] = [];

function ok(string $n): void { $GLOBALS['PASS']++; printf("  PASS  %s\n", $n); }
function bad(string $n, string $d): void { $GLOBALS['FAIL']++; printf("  FAIL  %s\n        -> %s\n", $n, $d); }
function is_true(string $n, bool $c, string $d = ''): void { $c ? ok($n) : bad($n, $d ?: 'condition false'); }
function eq(string $n, $a, $e, float $tol = 0.011): void {
    if (is_numeric($a) && is_numeric($e)) {
        if (abs((float)$a - (float)$e) <= $tol) { ok($n); return; }
    } elseif ($a === $e) { ok($n); return; }
    bad($n, "expected " . var_export($e, true) . ", got " . var_export($a, true));
}
function note(string $s): void { $GLOBALS['NOTES'][] = $s; }
function finding(string $s): void { $GLOBALS['NOTES'][] = 'FINDING: ' . $s; }
function section(string $t): void { printf("\n=== %s ===\n", $t); }

function req(string $method, string $path, ?array $body = null, ?string $jar = null): array {
    $ch = curl_init(BASE_URL . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30];
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
    if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($ch, $o);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
}

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function dbCount(string $table, string $where = '1'): int {
    global $pdo;
    return (int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn();
}
function dbRow(string $sql) { global $pdo; return $pdo->query($sql)->fetch(); }
function dbAll(string $sql): array { global $pdo; return $pdo->query($sql)->fetchAll(); }

// ---- cleanup registry -------------------------------------------------
function onCleanup(callable $fn): void { $GLOBALS['CLEANUP'][] = $fn; }
function runCleanup(): void {
    foreach (array_reverse($GLOBALS['CLEANUP']) as $fn) {
        try { $fn(); } catch (Throwable $e) { /* deferred to sweepTestData() */ }
    }
    $GLOBALS['CLEANUP'] = [];
}

// Deterministic backstop. Individual teardown closures can run in an order the
// foreign keys reject, so this removes anything still carrying a test marker,
// strictly children-before-parents. Scoped to markers, so real rows are safe.
function sweepTestData(): void {
    global $pdo;
    $dshEmp    = "SELECT employee_id FROM employees WHERE employee_number LIKE 'DSH-%'";
    $dshPeriod = "SELECT period_id FROM payroll_periods WHERE start_date >= '2027-01-01'";
    $dshPayslip= "SELECT ps.payslip_id FROM payslips ps WHERE ps.employee_id IN ($dshEmp)
                    OR ps.run_id IN (SELECT run_id FROM payroll_runs WHERE period_id IN ($dshPeriod))";
    $steps = [
        "DELETE FROM cash_disbursements WHERE payslip_id IN ($dshPayslip)",
        "DELETE FROM loan_payments      WHERE payslip_id IN ($dshPayslip)",
        "DELETE FROM allowances         WHERE payslip_id IN ($dshPayslip)",
        "DELETE FROM deductions         WHERE payslip_id IN ($dshPayslip)",
        "DELETE FROM govt_contributions WHERE payslip_id IN ($dshPayslip)",
        "DELETE FROM payslips           WHERE payslip_id IN ($dshPayslip)",
        "DELETE FROM payroll_runs       WHERE period_id IN ($dshPeriod)",
        "DELETE FROM payroll_periods    WHERE period_id IN ($dshPeriod)",
        "DELETE FROM deductions         WHERE attendance_id IN (SELECT attendance_id FROM attendance WHERE employee_id IN ($dshEmp))",
        "DELETE FROM attendance         WHERE employee_id IN ($dshEmp)",
        "DELETE FROM overtime_records   WHERE employee_id IN ($dshEmp)",
        "DELETE FROM loans              WHERE employee_id IN ($dshEmp)",
        "DELETE FROM leave_requests     WHERE employee_id IN ($dshEmp)",
        "DELETE FROM leave_balances     WHERE employee_id IN ($dshEmp)",
        "DELETE FROM salary_rates       WHERE employee_id IN ($dshEmp)",
        "DELETE FROM qr_codes           WHERE employee_id IN ($dshEmp)",
        "DELETE FROM users              WHERE username LIKE 'dsh\\_%'",
        "DELETE FROM employees          WHERE employee_number LIKE 'DSH-%'",
        "DELETE FROM leave_requests     WHERE leave_type_id IN (SELECT leave_type_id FROM leave_types WHERE name LIKE 'DSH%')",
        "DELETE FROM leave_balances     WHERE leave_type_id IN (SELECT leave_type_id FROM leave_types WHERE name LIKE 'DSH%')",
        "DELETE FROM leave_types        WHERE name LIKE 'DSH%'",
        "DELETE FROM salary_rates       WHERE rate_type LIKE 'DSH%'",
        "DELETE FROM positions          WHERE title LIKE 'DSH%'",
        "DELETE FROM departments        WHERE department_name LIKE 'DSH%'",
    ];
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($steps as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { printf("  (sweep step failed: %s)\n", $e->getMessage()); }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// ---- fixtures --------------------------------------------------------
function mkUser(string $username, string $role, ?int $employeeId = null): int {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO users (username, employee_id, role, is_active, password_hash)
                           VALUES (?, ?, ?, 1, ?)");
    $stmt->execute([$username, $employeeId, $role, password_hash(TEST_PW, PASSWORD_DEFAULT)]);
    $id = (int) $pdo->lastInsertId();
    onCleanup(function () use ($pdo, $username) {
        $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
    });
    return $id;
}

function login(string $username): string {
    $jar = sys_get_temp_dir() . '/dsh_' . $username . '_' . getmypid() . '.txt';
    onCleanup(function () use ($jar) { if (file_exists($jar)) unlink($jar); });
    $r = req('POST', 'auth.php?action=login', ['username' => $username, 'password' => TEST_PW], $jar);
    if ($r['code'] !== 200) throw new RuntimeException("login failed for $username: HTTP {$r['code']}");
    return $jar;
}

function mkEmployee(string $number, string $first, string $last, float $salary, ?int $deptId = null, ?int $posId = null): int {
    global $pdo;
    $sql = "INSERT INTO employees (employee_number, first_name, last_name, birth_date, civil_status,
                position_id, department_id, date_hired, employment_status, basic_salary,
                tin_number, sss_number, philhealth_number, pagibig_number, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)";
    $pdo->prepare($sql)->execute([
        $number, $first, $last, '1990-01-01', 'Single', $posId, $deptId, '2026-01-01', 'Active', $salary,
        'TIN-'.$number, 'SSS-'.$number, 'PH-'.$number, 'PI-'.$number,
    ]);
    $id = (int) $pdo->lastInsertId();
    onCleanup(function () use ($pdo, $id) {
        // Remove anything that could reference this employee, children first.
        $slips = $pdo->query("SELECT payslip_id FROM payslips WHERE employee_id = $id")->fetchAll(PDO::FETCH_COLUMN);
        if ($slips) {
            $in = implode(',', array_map('intval', $slips));
            foreach (['deductions', 'govt_contributions', 'loan_payments', 'allowances', 'cash_disbursements'] as $t) {
                $pdo->exec("DELETE FROM `$t` WHERE payslip_id IN ($in)");
            }
            $pdo->exec("DELETE FROM payslips WHERE employee_id = $id");
        }
        foreach (['attendance', 'overtime_records', 'loans', 'leave_requests', 'leave_balances',
                  'salary_rates', 'qr_codes', 'users'] as $t) {
            $pdo->exec("DELETE FROM `$t` WHERE employee_id = $id");
        }
        $pdo->exec("DELETE FROM employees WHERE employee_id = $id");
    });
    return $id;
}

function mkPeriod(string $start, string $end, string $type = 'Semi-monthly'): int {
    global $pdo;
    $pdo->prepare("INSERT INTO payroll_periods (start_date, end_date, period_type, status) VALUES (?,?,?,'Open')")
        ->execute([$start, $end, $type]);
    $id = (int) $pdo->lastInsertId();
    onCleanup(function () use ($pdo, $id) {
        $runs = $pdo->query("SELECT run_id FROM payroll_runs WHERE period_id = $id")->fetchAll(PDO::FETCH_COLUMN);
        if ($runs) {
            $in = implode(',', array_map('intval', $runs));
            $slips = $pdo->query("SELECT payslip_id FROM payslips WHERE run_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            if ($slips) {
                $si = implode(',', array_map('intval', $slips));
                foreach (['deductions', 'govt_contributions', 'loan_payments', 'allowances', 'cash_disbursements'] as $t) {
                    $pdo->exec("DELETE FROM `$t` WHERE payslip_id IN ($si)");
                }
                $pdo->exec("DELETE FROM payslips WHERE run_id IN ($in)");
            }
            $pdo->exec("DELETE FROM payroll_runs WHERE period_id = $id");
        }
        $pdo->exec("DELETE FROM payroll_periods WHERE period_id = $id");
    });
    return $id;
}

function mkLeaveType(string $name, float $days): int {
    global $pdo;
    $pdo->prepare("INSERT INTO leave_types (name, days_per_year, is_paid, is_active) VALUES (?,?,1,1)")
        ->execute([$name, $days]);
    $id = (int) $pdo->lastInsertId();
    onCleanup(function () use ($pdo, $id) {
        $pdo->exec("DELETE FROM leave_requests WHERE leave_type_id = $id");
        $pdo->exec("DELETE FROM leave_balances WHERE leave_type_id = $id");
        $pdo->exec("DELETE FROM leave_types WHERE leave_type_id = $id");
    });
    return $id;
}

function summary(): void {
    printf("\n%s\n", str_repeat('=', 74));
    printf("PASS: %d   FAIL: %d\n", $GLOBALS['PASS'], $GLOBALS['FAIL']);
    if ($GLOBALS['NOTES']) {
        echo "\nNotes & findings:\n";
        foreach ($GLOBALS['NOTES'] as $n) printf("  * %s\n", $n);
    }
}
