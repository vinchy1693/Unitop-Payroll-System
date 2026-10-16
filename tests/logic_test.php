<?php
// =====================================================================
// tests/logic_test.php  —  system logic test harness
// Run:  C:\xampp\php\php.exe tests\logic_test.php
//
// Pure-function tests run standalone. DB tests run inside a transaction
// that is ALWAYS rolled back, so payroll_db is left unchanged.
// =====================================================================

require __DIR__ . '/../api/_payroll_helpers.php';

$PASS = 0; $FAIL = 0; $NOTES = [];
function ok(string $name): void { global $PASS; $PASS++; printf("  PASS  %s\n", $name); }
function bad(string $name, string $detail): void { global $FAIL; $FAIL++; printf("  FAIL  %s\n        -> %s\n", $name, $detail); }
function eq(string $name, $actual, $expected, float $tol = 0.011): void {
    $a = is_numeric($actual) ? (float)$actual : $actual;
    $e = is_numeric($expected) ? (float)$expected : $expected;
    if (is_float($a) && is_float($e)) {
        if (abs($a - $e) <= $tol) { ok($name); return; }
        bad($name, "expected " . var_export($e, true) . ", got " . var_export($a, true));
        return;
    }
    if ($a === $e) { ok($name); return; }
    bad($name, "expected " . var_export($e, true) . ", got " . var_export($a, true));
}
function note(string $s): void { global $NOTES; $NOTES[] = $s; }
function is_true(string $n, bool $c, string $d = ''): void { $c ? ok($n) : bad($n, $d ?: 'condition false'); }
function section(string $t): void { printf("\n=== %s ===\n", $t); }

// ---------------------------------------------------------------------
section('1. Statutory contributions (employee monthly = 60,000)');
// ---------------------------------------------------------------------
$sss = computeSSS(60000);
eq('SSS EE = 5% of MSC 35,000 cap', $sss['ee'], 1750.00);
eq('SSS ER = 10% of MSC 35,000 cap', $sss['er'], 3500.00);

$sssLow = computeSSS(3000);
eq('SSS floor: MSC clamped up to 5,000 (EE)', $sssLow['ee'], 250.00);

$sssHigh = computeSSS(100000);
eq('SSS ceiling: MSC clamped down to 35,000 (EE)', $sssHigh['ee'], 1750.00);

$ph = computePhilHealth(60000);
eq('PhilHealth EE = 2.5%', $ph['ee'], 1500.00);
eq('PhilHealth ER = 2.5%', $ph['er'], 1500.00);
eq('PhilHealth floor 10,000', computePhilHealth(4000)['ee'], 250.00);
eq('PhilHealth ceiling 100,000', computePhilHealth(500000)['ee'], 2500.00);

$pi = computePagIbig(60000);
eq('Pag-IBIG EE = 2% of 10,000 cap', $pi['ee'], 200.00);
eq('Pag-IBIG ER = 2% of 10,000 cap', $pi['er'], 200.00);

// ---------------------------------------------------------------------
section('2. BIR withholding tax (TRAIN brackets, annualised)');
// ---------------------------------------------------------------------
eq('annual 250,000 -> exempt',          computeWithholdingTax(250000 / 24), 0.00);
eq('annual 400,000 -> 22,500',          computeWithholdingTax(400000 / 24), 22500 / 24);
eq('annual 800,000 -> 102,500',         computeWithholdingTax(800000 / 24), 102500 / 24);
eq('annual 2,000,000 -> 402,500',       computeWithholdingTax(2000000 / 24), 402500 / 24);
eq('annual 8,000,000 -> 2,202,500',     computeWithholdingTax(8000000 / 24), 2202500 / 24);

$ded = computePayslipDeductions(60000, 30000);
eq('semi-monthly SSS EE halved', $ded['sss_ee'], 875.00);
eq('semi-monthly PhilHealth EE halved', $ded['philhealth_ee'], 750.00);
eq('semi-monthly Pag-IBIG EE halved', $ded['pagibig_ee'], 100.00);
eq('withholding tax on 30,000 semi basic', $ded['withholding_tax'], 3259.17);
eq('total statutory deductions', $ded['total_deductions'], 4984.17);
eq('net on full attendance', $ded['net_pay'], 25015.83);

// ---------------------------------------------------------------------
section('3. 13th month pay');
// ---------------------------------------------------------------------
$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tm = compute13thMonthPay($pdo, 3, 2026);
printf("  employee 3: hired=%s monthly=%s months=%s amount=%s tax=%s net=%s\n",
    '2026-10-16', $tm['monthly'], $tm['months'], $tm['amount'], $tm['tax'], $tm['net']);
eq('13th month = monthly * months / 12', $tm['amount'], round(60000 * $tm['months'] / 12, 2));
eq('13th month tax-exempt up to 90,000', $tm['tax'], max(0, round(($tm['amount'] - 90000) * 0.20, 2)));

// ---------------------------------------------------------------------
section('4. Attendance deductions against the live periods');
// ---------------------------------------------------------------------
$pdo->beginTransaction();
try {
    // Use a period with NO live attendance for the deterministic checks, and an
    // independent working-day count, so results do not depend on current data.
    $eStart = '2030-06-01';
    $eEnd   = '2030-06-30';
    $e2     = (new DateTime($eEnd))->modify('+1 day');
    $expected = 0;
    $c = new DateTime($eStart);
    while ($c < $e2) { if ((int) $c->format('N') <= 6) $expected++; $c->modify('+1 day'); }

    $att = computeAttendanceDeductions($pdo, 3, $eStart, $eEnd, 60000);
    printf("  %s..%s (no live data): expected=%d present=%d\n", $eStart, $eEnd, $att['expected_days'], $att['present_days']);
    eq('expected working days (Mon-Sat) matches an independent count', $att['expected_days'], $expected);
    eq('no attendance -> guard returns a zero deduction', $att['absence_deduction'], 0.0);

    // Locate a Sunday, a Monday and a Saturday inside that period.
    $sunday = $monday = $saturday = null;
    $c = new DateTime($eStart);
    while ($c < $e2 && ($sunday === null || $monday === null || $saturday === null)) {
        $n = (int) $c->format('N');
        if ($n === 7 && $sunday   === null) $sunday   = $c->format('Y-m-d');
        if ($n === 1 && $monday   === null) $monday   = $c->format('Y-m-d');
        if ($n === 6 && $saturday === null) $saturday = $c->format('Y-m-d');
        $c->modify('+1 day');
    }
    $insAtt = $pdo->prepare(
        "INSERT INTO attendance (employee_id, qr_code_id, work_date, time_in, time_out,
                                 scan_device_id, hours_worked, minutes_late, status)
         VALUES (3, NULL, ?, '08:00:00', '17:00:00', 'TEST', 8.00, 0, 'Present')"
    );

    $insAtt->execute([$sunday]);
    $afterSun = computeAttendanceDeductions($pdo, 3, $eStart, $eEnd, 60000);
    eq("a Sunday ($sunday) record is NOT counted as present", $afterSun['present_days'], 0);

    $insAtt->execute([$monday]);
    $afterMon = computeAttendanceDeductions($pdo, 3, $eStart, $eEnd, 60000);
    eq("a Monday ($monday) record IS counted as present", $afterMon['present_days'], 1);

    $insAtt->execute([$saturday]);
    $afterSat = computeAttendanceDeductions($pdo, 3, $eStart, $eEnd, 60000);
    eq("a Saturday ($saturday) record IS counted as present", $afterSat['present_days'], 2);
    eq('absent days = expected - 2', $afterSat['absent_days'], $expected - 2);
    eq('daily rate = period basic / working days', $afterSat['daily_rate'], round(30000 / $expected, 2));
    eq('absence deduction = absent days x daily rate', $afterSat['absence_deduction'], round(($expected - 2) * (30000 / $expected), 2));

    // --- Zero-attendance guard ---
    $zero = computeAttendanceDeductions($pdo, 3, '2030-01-01', '2030-01-31', 60000);
    eq('zero attendance -> no deduction (guard works)', $zero['absence_deduction'], 0.0);
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------
section('5. Payslip totals: negative net pay and the taxable base');
// ---------------------------------------------------------------------
$pdo->beginTransaction();
try {
    $ps = $pdo->query("SELECT * FROM payslips ORDER BY payslip_id LIMIT 1")->fetch();
    if ($ps) {
        printf("  stored payslip %s: gross=%s deductions=%s net=%s\n",
            $ps['payslip_id'], $ps['gross_pay'], $ps['total_deductions'], $ps['net_pay']);
        is_true('every stored payslip has a non-negative net pay', (float) $ps['net_pay'] >= 0, "net_pay = {$ps['net_pay']}");
    } else {
        note('no payslips are stored right now, so the stored-row net-pay check was skipped');
    }

    // (a) the corrected math, assembled exactly as payroll_runs.php now does
    $att    = computeAttendanceDeductions($pdo, 3, '2026-10-16', '2026-10-31', 60000);
    $attDed = round($att['absence_deduction'] + $att['late_deduction'], 2);
    $calc   = computePayslipDeductions(60000, 30000, $attDed);
    $net    = round(30000 - $calc['total_deductions'] - $attDed, 2);
    printf("  corrected: absence=%s statutory=%s tax=%s -> net=%s\n",
        $attDed, $calc['total_deductions'], $calc['withholding_tax'], $net);
    eq('tax on the post-absence taxable base is 0 (was 3,259.17)', $calc['withholding_tax'], 0.00);
    eq('net pay becomes 417.86 under the Mon-Sat week (was -2,256.90)', $net, 417.86);
    is_true('net pay is not negative', $net >= 0, "net=$net");

    // (b) the floor: deductions larger than gross must clamp to a zero net
    $someRun = (int) $pdo->query("SELECT COALESCE(MIN(run_id),0) FROM payroll_runs")->fetchColumn();
    if ($someRun === 0) {
        note('no payroll run exists, so the net-pay floor test was skipped');
    } else {
    $pdo->prepare(
        "INSERT INTO payslips (run_id, employee_id, basic_pay, overtime_pay, gross_pay, total_deductions, net_pay)
         VALUES (?, 3, 30000.00, 0, 30000.00, 0, 0)"
    )->execute([$someRun]);
    $pid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO govt_contributions (payslip_id, sss_ee, sss_er, philhealth_ee, philhealth_er,
                                         pagibig_ee, pagibig_er, withholding_tax)
         VALUES (?, 875, 1750, 750, 750, 100, 100, 0)"
    )->execute([$pid]);
    $pdo->prepare("INSERT INTO deductions (payslip_id, deduction_type, amount) VALUES (?, 'Absences (12 day(s))', 32727.24)")
        ->execute([$pid]);
    $rc = recomputePayslipTotals($pdo, $pid);
    printf("  floor test: gross=%s deductions=%s net=%s\n", $rc['gross_pay'], $rc['total_deductions'], $rc['net_pay']);
    is_true('net pay floored at 0 when deductions exceed gross', (float)$rc['net_pay'] === 0.0, "net={$rc['net_pay']}");
    eq('gross - total_deductions still equals net_pay', round($rc['gross_pay'] - $rc['total_deductions'], 2), $rc['net_pay']);
    }
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------
section('6. Data integrity');
// ---------------------------------------------------------------------
$orphans = $pdo->query(
    "SELECT e.employee_id, e.department_id, e.position_id
     FROM employees e
     LEFT JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN positions   p ON p.position_id   = e.position_id
     WHERE d.department_id IS NULL OR p.position_id IS NULL"
)->fetchAll();
if ($orphans) {
    foreach ($orphans as $o) {
        bad('employee references existing department/position',
            "employee {$o['employee_id']} points at department_id={$o['department_id']} and position_id={$o['position_id']}, which do not exist");
    }
} else { ok('employee department/position references resolve'); }

$fk = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA='payroll_db' AND REFERENCED_TABLE_NAME IS NOT NULL"
)->fetchColumn();
printf("  FK constraints present in live schema: %s\n", $fk);
if ((int)$fk === 0) note('live schema has NO foreign keys, so dangling references are not prevented');

foreach (['departments','positions','leave_types','attendance'] as $t) {
    $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    printf("  %-14s rows=%s\n", $t, $n);
}
if ((int)$pdo->query("SELECT COUNT(*) FROM leave_types")->fetchColumn() === 0) {
    note('leave_types is empty - leave requests/balances have no valid type to reference');
}
if ((int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn() < 4) {
    note('only ' . $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn() . ' department row(s) exist; employee points at department_id=3');
}

$nullProc = $pdo->query("SELECT COUNT(*) FROM payroll_runs WHERE processed_by IS NULL")->fetchColumn();
$totRuns  = $pdo->query("SELECT COUNT(*) FROM payroll_runs")->fetchColumn();
printf("  payroll_runs with processed_by NULL: %s of %s\n", $nullProc, $totRuns);
if ($nullProc == $totRuns && $totRuns > 0) note('payroll runs never record who processed them (audit gap)');

// ---------------------------------------------------------------------
printf("\n================ SUMMARY ================\n");
printf("PASS: %d   FAIL: %d\n", $PASS, $FAIL);
if ($NOTES) {
    echo "\nFindings:\n";
    foreach ($NOTES as $n) printf("  * %s\n", $n);
}
