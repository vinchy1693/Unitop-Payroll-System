<?php
// =====================================================================
// tests/defense_verify.php
//
// The accuracy proof for the defence demo.
//
//   1. Reads the seeded inputs and recomputes EVERY figure independently
//      (plain PHP loops + the published rules; it does NOT include
//      api/_payroll_helpers.php, so the arithmetic is a second opinion).
//   2. Logs in as a temporary Administrator and POSTs the period to the
//      real api/payroll_runs.php endpoint.
//   3. Reads the generated payslip, govt_contributions and deduction lines
//      back from MySQL and asserts every figure to the cent.
//   4. Prints a side-by-side expected-vs-actual table.
//   5. Restores the demo state: deletes that run's payslip + children and
//      the run itself, sets the period back to Open, and keeps all seeded
//      attendance / leave / overtime so the demo can be reproduced live.
//
// Prerequisites: tests/defense_seed.php has been run, Apache is serving
// http://localhost/master_file_module_api/, and the payroll_db credentials
// match config.php (root / no password).
//
// Run:  C:\xampp\php\php.exe tests\defense_verify.php
// Exit code 0 = every figure matched and the demo state was restored.
// =====================================================================

declare(strict_types=1);

const BASE_URL = 'http://localhost/master_file_module_api/api/';
const TEST_PW  = 'TestPass123';

const DB_DSN  = 'mysql:host=localhost;dbname=payroll_db;charset=utf8mb4';
const DB_USER = 'root';
const DB_PASS = '';

const EMPLOYEE_ID     = 3;
const EMPLOYEE_NUMBER = 'EMP-0001';
const PERIOD_START    = '2026-11-16';
const PERIOD_END      = '2026-11-30';
const PERIOD_TYPE     = 'Semi-monthly';
const MONTHLY_SALARY  = 60000.00;
const SEED_DEVICE     = 'Seed';

const LEAVE_START = '2026-11-18';
const LEAVE_END   = '2026-11-19';

const ABSENT_DATES = ['2026-11-24', '2026-11-27'];
const LATE_DAYS    = ['2026-11-20' => 15, '2026-11-26' => 30];

const OT_DATE_1 = '2026-11-20';
const OT_DATE_2 = '2026-11-25';

// Documented shape of the seeded sample. The input check below fails loudly
// if the database has drifted, because the printed walkthrough depends on it.
const DOC_W       = 13;
const DOC_PRESENT = 9;
const DOC_LEAVE   = 2;
const DOC_ABSENT  = 2;
const DOC_LATE_MINUTES = 45;

$PASS = 0;
$FAIL = 0;

function out(string $line = ''): void { echo $line . PHP_EOL; }
function section(string $title): void { out(); out('=== ' . $title . ' ==='); }
function ok(string $name): void { global $PASS; $PASS++; out('  PASS  ' . $name); }
function bad(string $name, string $detail): void { global $FAIL; $FAIL++; out('  FAIL  ' . $name); out('        -> ' . $detail); }

function eqCents(string $name, $actual, $expected): void {
    $a = (int) round((float) $actual * 100);
    $e = (int) round((float) $expected * 100);
    if ($a === $e) {
        ok($name);
    } else {
        bad($name, 'expected ' . money($e / 100) . ', got ' . money($a / 100));
    }
}

function eqInt(string $name, $actual, int $expected): void {
    if ((int) $actual === $expected) {
        ok($name);
    } else {
        bad($name, 'expected ' . $expected . ', got ' . var_export($actual, true));
    }
}

function isTrue(string $name, bool $cond, string $detail = ''): void {
    $cond ? ok($name) : bad($name, $detail ?: 'condition is false');
}

function money(float $v): string { return number_format($v, 2, '.', ','); }
function round2(float $v): float { return round($v, 2); }

function dayList(string $from, string $to): array {
    $days   = [];
    $cursor = new DateTime($from);
    $end    = new DateTime($to);
    while ($cursor <= $end) {
        $days[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
    return $days;
}
function isoDow(string $ymd): int { return (int) (new DateTime($ymd))->format('N'); }
function isWorkDay(string $ymd): bool { return isoDow($ymd) <= 6; }   // Sunday = 7
function workDays(string $from, string $to): array {
    return array_values(array_filter(dayList($from, $to), 'isWorkDay'));
}
function businessDaysMonFri(string $from, string $to): int {
    $n = 0;
    foreach (dayList($from, $to) as $d) {
        if (isoDow($d) <= 5) $n++;
    }
    return $n;
}

function httpReq(string $method, string $path, ?array $body = null, ?string $jar = null): array {
    $ch = curl_init(BASE_URL . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR]  = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $options);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'code' => $code,
        'raw'  => (string) $raw,
        'json' => json_decode((string) $raw, true),
        'error'=> $err,
    ];
}

// Remove every payroll run (and its payslips' children) for one period and
// reopen it. Scoped strictly to the demo period id.
function resetPeriod(PDO $pdo, int $periodId): int {
    $stmt = $pdo->prepare("SELECT run_id FROM payroll_runs WHERE period_id = ?");
    $stmt->execute([$periodId]);
    $runIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($runIds) {
        $in = implode(',', $runIds);
        $slipIds = array_map('intval', $pdo->query("SELECT payslip_id FROM payslips WHERE run_id IN ($in)")
                                              ->fetchAll(PDO::FETCH_COLUMN));
        if ($slipIds) {
            $si = implode(',', $slipIds);
            foreach (['cash_disbursements', 'loan_payments', 'allowances', 'deductions', 'govt_contributions'] as $table) {
                $pdo->exec("DELETE FROM `$table` WHERE payslip_id IN ($si)");
            }
            $pdo->exec("DELETE FROM payslips WHERE payslip_id IN ($si)");
        }
        $pdo->prepare("DELETE FROM payroll_runs WHERE period_id = ?")->execute([$periodId]);
    }
    $pdo->prepare("UPDATE payroll_periods SET status = 'Open' WHERE period_id = ?")->execute([$periodId]);
    return count($runIds);
}

// ---------------------------------------------------------------------
// Connect and resolve the period
// ---------------------------------------------------------------------
$pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stmt = $pdo->prepare(
    "SELECT period_id, status FROM payroll_periods
     WHERE start_date = ? AND end_date = ? AND period_type = ?"
);
$stmt->execute([PERIOD_START, PERIOD_END, PERIOD_TYPE]);
$period = $stmt->fetch();
if (!$period) {
    out('VERIFY ABORTED: the demo period ' . PERIOD_START . '..' . PERIOD_END .
        ' (' . PERIOD_TYPE . ') does not exist. Run tests/defense_seed.php first.');
    exit(1);
}
$periodId = (int) $period['period_id'];

$stmt = $pdo->prepare("SELECT employee_number, first_name, last_name, basic_salary FROM employees WHERE employee_id = ?");
$stmt->execute([EMPLOYEE_ID]);
$employee = $stmt->fetch();
if (!$employee || $employee['employee_number'] !== EMPLOYEE_NUMBER) {
    out('VERIFY ABORTED: employee ' . EMPLOYEE_ID . ' is not ' . EMPLOYEE_NUMBER . '.');
    exit(1);
}

out('=====================================================================');
out(' DEFENCE PAYROLL VERIFICATION');
out(' employee ' . EMPLOYEE_ID . ' (' . EMPLOYEE_NUMBER . ' ' .
    $employee['first_name'] . ' ' . $employee['last_name'] . ')');
out(' period #' . $periodId . '  ' . PERIOD_START . ' .. ' . PERIOD_END . '  (' . PERIOD_TYPE . ')');
out('=====================================================================');

// =====================================================================
// 1. Independent recomputation from the seeded rows
// =====================================================================
section('1. Independent expected figures (own arithmetic, no app helpers)');

$workDays = workDays(PERIOD_START, PERIOD_END);
$W = count($workDays);

$workSet = array_fill_keys($workDays, true);

// --- attendance actually in the table, restricted to working days ---
$stmt = $pdo->prepare(
    "SELECT work_date, time_in, time_out, hours_worked, minutes_late, status
     FROM attendance
     WHERE employee_id = ? AND work_date BETWEEN ? AND ?
     ORDER BY work_date"
);
$stmt->execute([EMPLOYEE_ID, PERIOD_START, PERIOD_END]);
$attendance = [];
foreach ($stmt->fetchAll() as $row) {
    if (!isset($workSet[$row['work_date']])) continue;   // Sunday scans never count
    $attendance[$row['work_date']] = $row;
}
$present    = count($attendance);
$lateMinutes = 0;
foreach ($attendance as $row) {
    $lateMinutes += (int) $row['minutes_late'];
}

// --- approved PAID leave in the period, minus days that have attendance ---
$stmt = $pdo->prepare(
    "SELECT lr.leave_id, lr.start_date, lr.end_date, lr.status,
            lt.name AS type_name, lt.is_paid
     FROM leave_requests lr
     JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
     WHERE lr.employee_id = ? AND lr.status = 'Approved' AND lt.is_paid = 1
       AND NOT (lr.end_date < ? OR lr.start_date > ?)
     ORDER BY lr.start_date"
);
$stmt->execute([EMPLOYEE_ID, PERIOD_START, PERIOD_END]);
$leaveDates = [];
$leaveRequests = $stmt->fetchAll();
foreach ($leaveRequests as $lr) {
    foreach (dayList($lr['start_date'], $lr['end_date']) as $d) {
        if ($d < PERIOD_START || $d > PERIOD_END) continue;
        if (!isWorkDay($d)) continue;
        $leaveDates[$d] = true;
    }
}
foreach (array_keys($attendance) as $d) {
    unset($leaveDates[$d]);   // present wins over leave
}
$leaveDays = count($leaveDates);

$absent = max(0, $W - $present - $leaveDays);

// --- money ---
$semiBasic = round2(MONTHLY_SALARY / 2.0);          // 30000.00
$dailyRaw  = $semiBasic / $W;                        // full precision
$dailyRate = round2($dailyRaw);                      // reported value
$absenceDeduction = round2($absent * $dailyRaw);
$perMinute = $dailyRaw / 480.0;
$lateDeduction = round2($lateMinutes * $perMinute);

$stmt = $pdo->prepare(
    "SELECT ot_date, hours, rate_multiplier, ot_type
     FROM overtime_records
     WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
       AND status IN ('Approved','Completed')
       AND hours IS NOT NULL AND hours > 0
       AND time_in IS NOT NULL AND time_out IS NOT NULL
     ORDER BY ot_date"
);
$stmt->execute([EMPLOYEE_ID, PERIOD_START, PERIOD_END]);
$overtime = $stmt->fetchAll();

$hourlyRate = $dailyRate / 8.0;      // the app prices OT off the rounded daily rate
$otPay = 0.0;
foreach ($overtime as $ot) {
    $otPay += (float) $ot['hours'] * (float) $ot['rate_multiplier'] * $hourlyRate;
}
$otPay = round2($otPay);

$gross = round2($semiBasic + $otPay);

// Statutory: monthly salary -> monthly contribution -> employee half.
$sssBase = max(5000.0, min(35000.0, MONTHLY_SALARY));
$sssEE   = round2($sssBase * 0.05 / 2.0);            // 875.00
$phBase  = max(10000.0, min(100000.0, MONTHLY_SALARY));
$phEE    = round2($phBase * 0.025 / 2.0);            // 750.00
$piBase  = min(10000.0, MONTHLY_SALARY);
$piEE    = round2($piBase * 0.02 / 2.0);             // 100.00
$statutory = round2($sssEE + $phEE + $piEE);         // 1725.00

$taxable = round2($semiBasic - ($absenceDeduction + $lateDeduction) - $statutory);

$annual = $taxable * 24.0;
if ($annual <= 250000)        { $annualTax = 0.0; }
elseif ($annual <= 400000)    { $annualTax = ($annual - 250000) * 0.15; }
elseif ($annual <= 800000)    { $annualTax = 22500 + ($annual - 400000) * 0.20; }
elseif ($annual <= 2000000)   { $annualTax = 102500 + ($annual - 800000) * 0.25; }
elseif ($annual <= 8000000)   { $annualTax = 402500 + ($annual - 2000000) * 0.30; }
else                          { $annualTax = 2202500 + ($annual - 8000000) * 0.35; }
$tax = round2($annualTax / 24.0);

$totalDeductions = round2($statutory + $tax + $absenceDeduction + $lateDeduction);
$netPay = max(0.0, round2($gross - $totalDeductions));

// ---- guard: the DB must still contain the documented sample -------------
$inputProblems = [];
if ($W !== DOC_W) $inputProblems[] = "W is $W, documented " . DOC_W;
if ($present !== DOC_PRESENT) $inputProblems[] = "present is $present, documented " . DOC_PRESENT;
if ($leaveDays !== DOC_LEAVE) $inputProblems[] = "leave is $leaveDays, documented " . DOC_LEAVE;
if ($absent !== DOC_ABSENT) $inputProblems[] = "absent is $absent, documented " . DOC_ABSENT;
if ($lateMinutes !== DOC_LATE_MINUTES) $inputProblems[] = "late minutes are $lateMinutes, documented " . DOC_LATE_MINUTES;
if (count($overtime) !== 2) $inputProblems[] = 'overtime records: ' . count($overtime) . ', documented 2';
if (!isset($attendance['2026-11-20']) || (int) $attendance['2026-11-20']['minutes_late'] !== LATE_DAYS['2026-11-20']) {
    $inputProblems[] = 'the 2026-11-20 late (15 min) attendance row is missing or altered';
}
if (!isset($attendance['2026-11-26']) || (int) $attendance['2026-11-26']['minutes_late'] !== LATE_DAYS['2026-11-26']) {
    $inputProblems[] = 'the 2026-11-26 late (30 min) attendance row is missing or altered';
}
foreach (ABSENT_DATES as $d) {
    if (isset($attendance[$d])) $inputProblems[] = "$d should be absent but has attendance";
}
if (!isset($leaveDates[LEAVE_START]) || !isset($leaveDates[LEAVE_END])) {
    $inputProblems[] = 'the approved paid leave does not cover ' . LEAVE_START . ' and ' . LEAVE_END;
}
if ($employee['basic_salary'] === null || abs((float) $employee['basic_salary'] - MONTHLY_SALARY) > 0.005) {
    $inputProblems[] = 'basic_salary is ' . var_export($employee['basic_salary'], true);
}

if ($inputProblems) {
    out();
    out('VERIFY ABORTED: the seeded inputs do not match the documented demo:');
    foreach ($inputProblems as $problem) out('  - ' . $problem);
    out('Re-run tests/defense_seed.php and try again.');
    exit(1);
}

out('  W (Mon-Sat days counted by hand) : ' . $W);
out('  present (attendance rows)        : ' . $present);
out('  leave (approved paid, no row)    : ' . $leaveDays . '  [' . implode(', ', array_keys($leaveDates)) . ']');
out('  absent = max(0, ' . $W . ' - ' . $present . ' - ' . $leaveDays . ') : ' . $absent);
out('  late minutes                     : ' . $lateMinutes);
out('  overtime rows                    : ' . count($overtime) . ' (' .
    implode(', ', array_map(fn($o) => $o['ot_date'] . ' ' . (float) $o['hours'] . 'h x' . (float) $o['rate_multiplier'], $overtime)) . ')');
out();
out('  Walkthrough (every intermediate value):');
out('    basic_pay        = 60,000.00 / 2 = ' . money($semiBasic));
out('    daily_rate       = ' . money($semiBasic) . ' / ' . $W . ' = ' . sprintf('%.10F', $dailyRaw) .
    '  -> ' . money($dailyRate) . ' (2 dp, reported)');
out('    absence          = ' . $absent . ' x ' . sprintf('%.10F', $dailyRaw) . ' = ' .
    sprintf('%.10F', $absent * $dailyRaw) . ' -> ' . money($absenceDeduction));
out('    late             = ' . $lateMinutes . ' x (' . sprintf('%.10F', $dailyRaw) . ' / 480) = ' .
    sprintf('%.10F', $lateMinutes * $perMinute) . ' -> ' . money($lateDeduction));
out('    OT hourly        = ' . money($dailyRate) . ' / 8 = ' . sprintf('%.10F', $hourlyRate));
out('    OT pay           = ' . money($otPay));
out('    gross_pay        = ' . money($semiBasic) . ' + ' . money($otPay) . ' = ' . money($gross));
out('    statutory EE     = SSS ' . money($sssEE) . ' + PhilHealth ' . money($phEE) .
    ' + Pag-IBIG ' . money($piEE) . ' = ' . money($statutory));
out('    taxable          = ' . money($semiBasic) . ' - (' . money($absenceDeduction) . ' + ' .
    money($lateDeduction) . ') - ' . money($statutory) . ' = ' . money($taxable));
out('    annual taxable   = ' . money($taxable) . ' x 24 = ' . money($annual));
out('    BIR annual tax   = 22,500 + (' . money($annual) . ' - 400,000) x 0.20 = ' .
    sprintf('%.6F', $annualTax));
out('    withholding tax  = ' . sprintf('%.6F', $annualTax) . ' / 24 = ' .
    sprintf('%.10F', $annualTax / 24.0) . ' -> ' . money($tax));
out('    total_deductions = ' . money($statutory) . ' + ' . money($tax) . ' + ' .
    money($absenceDeduction) . ' + ' . money($lateDeduction) . ' = ' . money($totalDeductions));
out('    net_pay          = ' . money($gross) . ' - ' . money($totalDeductions) . ' = ' . money($netPay));

// =====================================================================
// 2. Run payroll through the real endpoint as a temporary Payroll Officer
// =====================================================================
section('2. Run the real payroll endpoint');

$tempUser = 'dsh_defense_verify';
$jar = sys_get_temp_dir() . '/dsh_defense_verify_' . getmypid() . '.txt';
$runId = null;
$payslipId = null;
$staleRuns = 0;

try {
    // Start from a clean, Open period. Any run left over from an interrupted
    // verification is removed here and reported; the seeded inputs are never
    // touched by this.
    $staleRuns = resetPeriod($pdo, $periodId);
    if ($staleRuns > 0) {
        out('  (reset ' . $staleRuns . ' stale payroll run(s) left on the demo period)');
    }

    // Temporary Payroll Officer (the maker), same fixture pattern as tests/_harness.php.
    $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$tempUser]);
    $pdo->prepare(
        "INSERT INTO users (username, employee_id, role, is_active, password_hash)
         VALUES (?, NULL, 'Payroll Officer', 1, ?)"
    )->execute([$tempUser, password_hash(TEST_PW, PASSWORD_DEFAULT)]);

    $login = httpReq('POST', 'auth.php?action=login', ['username' => $tempUser, 'password' => TEST_PW], $jar);
    if ($login['code'] !== 200) {
        throw new RuntimeException(
            'login failed: HTTP ' . $login['code'] . ' ' . jumpMessage($login)
        );
    }
    ok('logged in as a temporary Payroll Officer');

    $run = httpReq('POST', 'payroll_runs.php', ['period_id' => $periodId], $jar);
    out('  response: HTTP ' . $run['code'] . '  ' . jumpMessage($run));
    if ($run['code'] !== 200) {
        throw new RuntimeException('payroll run failed: HTTP ' . $run['code'] . ' ' . jumpMessage($run));
    }
    $runId = (int) ($run['json']['data']['run_id'] ?? 0);
    $generated = (int) ($run['json']['data']['generated'] ?? 0);
    isTrue('payroll run accepted (HTTP 200)', $run['code'] === 200);
    isTrue('a run_id was returned', $runId > 0, 'run_id = ' . $runId);
    isTrue('the run generated at least one payslip', $generated >= 1, 'generated = ' . $generated);

    // =================================================================
    // 3. Read the payslip back and assert every figure
    // =================================================================
    section('3. Stored payslip, contributions and deduction lines');

    $stmt = $pdo->prepare("SELECT * FROM payslips WHERE run_id = ? AND employee_id = ?");
    $stmt->execute([$runId, EMPLOYEE_ID]);
    $payslip = $stmt->fetch();
    if (!$payslip) {
        throw new RuntimeException("no payslip was generated for employee " . EMPLOYEE_ID . " in run $runId.");
    }
    $payslipId = (int) $payslip['payslip_id'];

    $stmt = $pdo->prepare("SELECT * FROM govt_contributions WHERE payslip_id = ?");
    $stmt->execute([$payslipId]);
    $gc = $stmt->fetch();
    if (!$gc) {
        throw new RuntimeException("no govt_contributions row for payslip $payslipId.");
    }

    $stmt = $pdo->prepare("SELECT deduction_type, amount FROM deductions WHERE payslip_id = ? ORDER BY deduction_id");
    $stmt->execute([$payslipId]);
    $deductionLines = $stmt->fetchAll();

    out('  payslip #' . $payslipId . '  basic ' . money((float) $payslip['basic_pay']) .
        '  OT ' . money((float) $payslip['overtime_pay']) .
        '  gross ' . money((float) $payslip['gross_pay']) .
        '  deductions ' . money((float) $payslip['total_deductions']) .
        '  net ' . money((float) $payslip['net_pay']));
    out('  govt: SSS ' . money((float) $gc['sss_ee']) .
        '  PhilHealth ' . money((float) $gc['philhealth_ee']) .
        '  Pag-IBIG ' . money((float) $gc['pagibig_ee']) .
        '  tax ' . money((float) $gc['withholding_tax']));
    foreach ($deductionLines as $line) {
        out('  deduction: ' . $line['deduction_type'] . ' = ' . money((float) $line['amount']));
    }
    out();

    // Split the two attendance deduction lines back out.
    $actualAbsence = 0.0;
    $actualLate    = 0.0;
    $absenceLabel  = null;
    $lateLabel     = null;
    foreach ($deductionLines as $line) {
        $type = (string) $line['deduction_type'];
        if (str_starts_with($type, 'Absences')) {
            $actualAbsence += (float) $line['amount'];
            $absenceLabel = $type;
        } elseif (str_starts_with($type, 'Late')) {
            $actualLate += (float) $line['amount'];
            $lateLabel = $type;
        }
    }
    $actualTotalLines = 0.0;
    foreach ($deductionLines as $line) {
        $actualTotalLines += (float) $line['amount'];
    }

    eqCents('basic_pay = ' . money($semiBasic), $payslip['basic_pay'], $semiBasic);
    eqCents('overtime_pay = ' . money($otPay), $payslip['overtime_pay'], $otPay);
    eqCents('gross_pay = ' . money($gross), $payslip['gross_pay'], $gross);

    eqCents('SSS EE = ' . money($sssEE), $gc['sss_ee'], $sssEE);
    eqCents('PhilHealth EE = ' . money($phEE), $gc['philhealth_ee'], $phEE);
    eqCents('Pag-IBIG EE = ' . money($piEE), $gc['pagibig_ee'], $piEE);
    eqCents('withholding tax = ' . money($tax), $gc['withholding_tax'], $tax);

    isTrue('an Absences deduction line exists', $absenceLabel !== null, 'no "Absences ..." line');
    eqCents('absence_deduction = ' . money($absenceDeduction), $actualAbsence, $absenceDeduction);
    isTrue('absence line names 2 day(s)', $absenceLabel !== null && str_contains($absenceLabel, '2 day(s)'),
        'label: ' . var_export($absenceLabel, true));

    isTrue('a Late/Undertime deduction line exists', $lateLabel !== null, 'no "Late ..." line');
    eqCents('late_deduction = ' . money($lateDeduction), $actualLate, $lateDeduction);
    isTrue('late line names ' . $lateMinutes . ' min', $lateLabel !== null && str_contains($lateLabel, $lateMinutes . ' min'),
        'label: ' . var_export($lateLabel, true));

    eqCents('total_deductions = ' . money($totalDeductions), $payslip['total_deductions'], $totalDeductions);
    eqCents('net_pay = ' . money($netPay), $payslip['net_pay'], $netPay);

    eqCents('gross - total_deductions = net_pay',
        round2((float) $payslip['gross_pay'] - (float) $payslip['total_deductions']), $payslip['net_pay']);
    eqCents('statutory + tax + attendance lines = total_deductions',
        round2($statutory + (float) $gc['withholding_tax'] + $actualTotalLines), $payslip['total_deductions']);
    eqInt('exactly 2 attendance deduction lines (no loans/allowances)', count($deductionLines), 2);

    // =================================================================
    // 4. Side-by-side table
    // =================================================================
    section('4. Expected vs actual (to the cent)');

    $actualDailyRate = $absent > 0 ? round2($actualAbsence / $absent) : null;

    $rows = [
        ['W (working days)',       (string) $W,                 (string) $W,                 'period dates'],
        ['Present days',           (string) $present,            (string) $present,           'attendance rows'],
        ['Leave days',             (string) $leaveDays,          (string) $leaveDays,         'approved paid leave'],
        ['Absent days',            (string) $absent,             (string) $absent,            'max(0, W-present-leave)'],
        ['basic_pay',              money($semiBasic),            money((float) $payslip['basic_pay']), 'payslip'],
        ['daily_rate',             money($dailyRate),            money((float) $actualDailyRate), 'absence line / absent days'],
        ['absence_deduction',      money($absenceDeduction),     money($actualAbsence),       'deductions'],
        ['late_deduction',         money($lateDeduction),        money($actualLate),          'deductions'],
        ['overtime_pay',           money($otPay),                money((float) $payslip['overtime_pay']), 'payslip'],
        ['gross_pay',              money($gross),                money((float) $payslip['gross_pay']), 'payslip'],
        ['SSS EE',                 money($sssEE),                money((float) $gc['sss_ee']), 'govt_contributions'],
        ['PhilHealth EE',          money($phEE),                 money((float) $gc['philhealth_ee']), 'govt_contributions'],
        ['Pag-IBIG EE',            money($piEE),                 money((float) $gc['pagibig_ee']), 'govt_contributions'],
        ['withholding tax',        money($tax),                  money((float) $gc['withholding_tax']), 'govt_contributions'],
        ['total_deductions',       money($totalDeductions),      money((float) $payslip['total_deductions']), 'payslip'],
        ['net_pay',                money($netPay),               money((float) $payslip['net_pay']), 'payslip'],
    ];

    out('+-------------------------+----------------------+----------------------+----------------------------+--------+');
    out('| Metric                  | Expected             | Actual               | Source                     | Match  |');
    out('+-------------------------+----------------------+----------------------+----------------------------+--------+');
    $allMatch = true;
    foreach ($rows as [$label, $expected, $actual, $source]) {
        $match = (int) round((float) str_replace(',', '', $expected) * 100)
              === (int) round((float) str_replace(',', '', $actual) * 100);
        if (!$match) $allMatch = false;
        out('| ' . str_pad($label, 23) . ' | ' . str_pad($expected, 20) . ' | ' . str_pad($actual, 20) . ' | ' .
            str_pad($source, 26) . ' | ' . str_pad($match ? 'OK' : 'MISMATCH', 6) . ' |');
    }
    out('+-------------------------+----------------------+----------------------+----------------------------+--------+');
    isTrue('every row in the side-by-side table matches', $allMatch, 'at least one row differs');

} catch (Throwable $e) {
    bad('verification completed without an exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    // =================================================================
    // 5. Restore the demo state
    // =================================================================
    section('5. Restore the demo state (period Open, inputs kept)');

    try {
        $removedRuns = resetPeriod($pdo, $periodId);
        $pdo->prepare("DELETE FROM users WHERE username = ?")->execute([$tempUser]);
        if (file_exists($jar)) unlink($jar);
        out('  removed ' . $removedRuns . ' payroll run(s) for period #' . $periodId .
            ' (payslip and children deleted)');
    } catch (Throwable $e) {
        bad('cleanup completed without an exception', $e->getMessage());
    }

    // ---- confirmations ----
    $stmt = $pdo->prepare("SELECT status FROM payroll_periods WHERE period_id = ?");
    $stmt->execute([$periodId]);
    $statusNow = $stmt->fetchColumn();
    eqInt('period is Open again', $statusNow === 'Open' ? 1 : 0, 1);
    if ($statusNow !== 'Open') out('        actual status: ' . var_export($statusNow, true));

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM payslips ps
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         WHERE pr.period_id = ?"
    );
    $stmt->execute([$periodId]);
    eqInt('no payslips remain for the period', (int) $stmt->fetchColumn(), 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll_runs WHERE period_id = ?");
    $stmt->execute([$periodId]);
    eqInt('no payroll runs remain for the period', (int) $stmt->fetchColumn(), 0);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND scan_device_id = ? AND work_date BETWEEN ? AND ?"
    );
    $stmt->execute([EMPLOYEE_ID, SEED_DEVICE, PERIOD_START, PERIOD_END]);
    eqInt('seeded attendance rows intact (9)', (int) $stmt->fetchColumn(), 9);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM overtime_records WHERE employee_id = ? AND ot_date BETWEEN ? AND ?"
    );
    $stmt->execute([EMPLOYEE_ID, PERIOD_START, PERIOD_END]);
    eqInt('seeded overtime rows intact (2)', (int) $stmt->fetchColumn(), 2);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM leave_requests
         WHERE employee_id = ? AND status = 'Approved' AND start_date = ? AND end_date = ?"
    );
    $stmt->execute([EMPLOYEE_ID, LEAVE_START, LEAVE_END]);
    eqInt('approved leave request intact (1)', (int) $stmt->fetchColumn(), 1);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$tempUser]);
    eqInt('temporary Payroll Officer removed', (int) $stmt->fetchColumn(), 0);

    $stmt = $pdo->prepare("SELECT basic_salary FROM employees WHERE employee_id = ?");
    $stmt->execute([EMPLOYEE_ID]);
    eqCents('employee basic_salary still 60,000.00', $stmt->fetchColumn(), MONTHLY_SALARY);
}

// =====================================================================
// Summary
// =====================================================================
out();
out(str_repeat('=', 74));
out('RESULT: ' . $PASS . ' passed, ' . $FAIL . ' failed');
out(str_repeat('=', 74));
exit($FAIL === 0 ? 0 : 1);

// Helper kept last so a thrown exception inside try/finally reads the API text.
function jumpMessage(array $response): string {
    $json = $response['json'];
    if (is_array($json) && isset($json['message'])) return (string) $json['message'];
    $raw = trim((string) ($response['raw'] ?? ''));
    if ($raw !== '') return substr($raw, 0, 300);
    return (string) ($response['error'] ?? '(no response)');
}
