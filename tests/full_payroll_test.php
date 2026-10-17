<?php
// =====================================================================
// tests/full_payroll_test.php
// The payroll paths that had no data to exercise before: overtime pay,
// late/undertime deductions, loan amortisation + auto-"Paid", the
// 13th Month run, and report/report arithmetic against the database.
// Run:  C:\xampp\php\php.exe tests\full_payroll_test.php
// =====================================================================

require __DIR__ . '/_harness.php';
require __DIR__ . '/../api/_payroll_helpers.php';

// Independent working-day counter, so expectations do not lean on the code
// under test. Work week is Monday-Saturday.
function workdays(string $from, string $to): int {
    $n = 0; $c = new DateTime($from); $e = (new DateTime($to))->modify('+1 day');
    while ($c < $e) { if ((int) $c->format('N') <= 6) $n++; $c->modify('+1 day'); }
    return $n;
}

// Snapshot every table we might touch, so "restored" means restored to how it
// was when the test started - not to a hardcoded assumption.
$WATCH = ['employees','users','payroll_periods','payroll_runs','payslips','deductions',
          'govt_contributions','allowances','loan_payments','loans','overtime_records',
          'attendance','qr_codes','leave_types','leave_balances','leave_requests',
          'positions','departments','salary_rates'];
$START = [];
foreach ($WATCH as $t) $START[$t] = dbCount($t);

printf("start: employees=%d users=%d periods=%d attendance=%d loans=%d overtime=%d payslips=%d\n",
    dbCount('employees'), dbCount('users'), dbCount('payroll_periods'), dbCount('attendance'),
    dbCount('loans'), dbCount('overtime_records'), dbCount('payslips'));

try {
    mkUser('dsh_pay_admin', 'Administrator');
    $jar = login('dsh_pay_admin');

    $salary = 60000.00;
    $empId  = mkEmployee('DSH-EMP-P', 'Pay', 'Load', $salary);

    $start = '2027-03-01';
    $end   = '2027-03-15';
    $periodId = mkPeriod($start, $end);

    // ---- seed full attendance for every working day, first day late ----
    $days = [];
    $c = new DateTime($start); $e = (new DateTime($end))->modify('+1 day');
    while ($c < $e) {
        if ((int) $c->format('N') <= 6) $days[] = $c->format('Y-m-d');
        $c->modify('+1 day');
    }
    $lateDay = $days[0];
    $ins = $GLOBALS['pdo']->prepare(
        "INSERT INTO attendance (employee_id, qr_code_id, work_date, time_in, time_out, scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, ?, ?, 'DSH-SEED', ?, ?, ?)"
    );
    foreach ($days as $i => $d) {
        if ($d === $lateDay) $ins->execute([$empId, $d, '09:00:00', '17:00:00', 7.00, 60, 'Late']);
        else                  $ins->execute([$empId, $d, '08:00:00', '17:00:00', 8.00,  0, 'Present']);
    }
    $W = workdays($start, $end);
    printf("period %s..%s has %d working days; seeded %d attendance rows (1 late)\n", $start, $end, $W, count($days));

    $dailyRate = round(30000.0 / $W, 2);
    $lateDed   = round(60 * ($dailyRate / 480.0), 2);
    $otPay     = round(4 * 1.25 * ($dailyRate / 8.0), 2);
    $loanPay   = 400.00;   // principal 400, monthly amortisation 1000 -> half = 500, capped at 400

    // ---- loan sized to settle in one semi-monthly payment ----
    $r = req('POST', 'loans.php', ['employee_id' => $empId, 'loan_type' => 'DSH Loan',
                                   'principal_amount' => 400, 'monthly_amortization' => 1000], $jar);
    eq('POST loan -> 200', $r['code'], 200);

    // ---- overtime: 4 hours at 1.25x on the first working day ----
    // Overtime is now requested after clock-out, approved by an admin, then
    // started and ended by the employee; the clock-out computes the hours.
    $qr = 'DSH-PAY-QR-' . getmypid();
    $GLOBALS['pdo']->prepare(
        "INSERT INTO qr_codes (employee_id, code_value, issued_date, status) VALUES (?, ?, CURDATE(), 'Active')"
    )->execute([$empId, $qr]);
    $scan = function (string $time) use ($qr, $lateDay, $jar) {
        return req('POST', 'scan_attendance.php', [
            'code_value' => $qr,
            'demo_mode'  => true,
            'demo_date'  => $lateDay,
            'demo_time'  => $time,
        ], $jar);
    };

    $r = $scan('17:30:00');
    eq('overtime request after clock-out -> 200', $r['code'], 200);
    eq('overtime request action = ot_request', $r['json']['data']['action'] ?? null, 'ot_request');
    $otId = (int) ($r['json']['data']['ot_id'] ?? 0);
    is_true('overtime request id returned', $otId > 0, 'no ot_id');

    // A second open request for the same day is refused.
    $r = req('POST', 'overtime_records.php', ['employee_id' => $empId, 'ot_date' => $lateDay,
                                              'ot_type' => 'DSH OT', 'rate_multiplier' => 1.25], $jar);
    eq('duplicate open overtime request -> 409', $r['code'], 409);

    $r = req('PATCH', 'overtime_records.php', ['ot_id' => $otId, 'action' => 'approve'], $jar);
    eq('approve overtime request -> 200', $r['code'], 200);

    $r = $scan('18:00:00');
    eq('overtime clock-in -> 200', $r['code'], 200);
    eq('overtime clock-in action = ot_in', $r['json']['data']['action'] ?? null, 'ot_in');

    $r = $scan('22:00:00');
    eq('overtime clock-out -> 200', $r['code'], 200);
    eq('overtime clock-out action = ot_out', $r['json']['data']['action'] ?? null, 'ot_out');
    eq('overtime clock-out computes 4.00 hours', $r['json']['data']['hours'] ?? null, 4.00);

    // =================================================================
    section('1. Payroll run with overtime, late and a loan');
    // =================================================================
    printf("expectations: daily=%.2f late=%.2f ot=%.2f loan=%.2f\n", $dailyRate, $lateDed, $otPay, $loanPay);

    $r = req('POST', 'payroll_runs.php', ['period_id' => $periodId], $jar);
    eq('run payroll -> 200', $r['code'], 200);
    $runId = (int) ($r['json']['data']['run_id'] ?? 0);
    is_true('run_id returned', $runId > 0);

    $ps = dbRow("SELECT * FROM payslips WHERE run_id = $runId AND employee_id = $empId");
    is_true('payslip generated for the test employee', $ps !== false, 'no payslip');
    if ($ps) {
        $pid = (int) $ps['payslip_id'];
        $gc  = dbRow("SELECT * FROM govt_contributions WHERE payslip_id = $pid");
        $ded = dbAll("SELECT deduction_type, amount FROM deductions WHERE payslip_id = $pid");

        printf("  basic=%s ot=%s gross=%s total_ded=%s net=%s\n", $ps['basic_pay'], $ps['overtime_pay'], $ps['gross_pay'], $ps['total_deductions'], $ps['net_pay']);
        foreach ($ded as $d) printf("  deduction: %s = %s\n", $d['deduction_type'], $d['amount']);

        eq('basic_pay = 30,000', $ps['basic_pay'], 30000.00);
        eq('overtime_pay = 4h x 1.25 x hourly rate', $ps['overtime_pay'], $otPay);
        eq('gross_pay = basic + overtime', $ps['gross_pay'], round(30000 + $otPay, 2));

        $lateRows = array_values(array_filter($ded, fn($d) => str_starts_with($d['deduction_type'], 'Late/Undertime')));
        is_true('a Late/Undertime deduction row exists', count($lateRows) === 1, count($lateRows) . ' row(s)');
        if ($lateRows) eq('late deduction = 60 min x (daily/480)', $lateRows[0]['amount'], $lateDed);

        $loanRows = array_values(array_filter($ded, fn($d) => str_starts_with($d['deduction_type'], 'Loan:')));
        is_true('a Loan deduction row exists', count($loanRows) === 1, count($loanRows) . ' row(s)');
        if ($loanRows) eq('loan deduction capped at the remaining principal', $loanRows[0]['amount'], $loanPay);

        $noAbsence = count(array_filter($ded, fn($d) => str_starts_with($d['deduction_type'], 'Absences')));
        eq('no absence deduction when every working day is present', $noAbsence, 0);

        eq('SSS EE still based on monthly salary', $gc['sss_ee'], 875.00);
        $taxable = max(0.0, round(30000 - $lateDed - 875 - 750 - 100, 2));
        eq('withholding tax on the reduced taxable base', $gc['withholding_tax'], round(computeWithholdingTax($taxable), 2));

        $expectedNet = round(30000 + $otPay - $lateDed - $loanPay
                             - (875 + 750 + 100) - (float) $gc['withholding_tax'], 2);
        eq('net pay = gross - late - loan - statutory - tax', $ps['net_pay'], $expectedNet);
        is_true('net pay is not negative', (float) $ps['net_pay'] >= 0, "net = {$ps['net_pay']}");

        $pay = dbRow("SELECT * FROM loan_payments WHERE payslip_id = $pid");
        is_true('a loan_payments row was recorded', $pay !== false);
        if ($pay) eq('loan payment amount = 400.00', $pay['amount_paid'], 400.00);
        eq('loan fully settled -> status Paid',
            dbRow("SELECT status FROM loans WHERE loan_id = " . (int) ($pay['loan_id'] ?? 0))['status'] ?? null, 'Paid');

        $r = req('DELETE', "loans.php?id=" . (int) ($pay['loan_id'] ?? 0), null, $jar);
        eq('loan with recorded payments cannot be deleted -> 409', $r['code'], 409);
    }

    // =================================================================
    section('2. 13th Month run');
    // =================================================================
    $tmPeriod = mkPeriod('2026-12-01', '2026-12-31', '13th Month');
    $r = req('POST', 'payroll_runs.php', ['period_id' => $tmPeriod], $jar);
    eq('13th month run -> 200', $r['code'], 200);
    $tmRun = (int) ($r['json']['data']['run_id'] ?? 0);

    $tm = dbRow("SELECT * FROM payslips WHERE run_id = $tmRun AND employee_id = $empId");
    is_true('13th month payslip generated', $tm !== false, 'no payslip');
    if ($tm) {
        $tpid = (int) $tm['payslip_id'];
        $allow = dbRow("SELECT * FROM allowances WHERE payslip_id = $tpid");
        printf("  13th month: basic=%s gross=%s deductions=%s net=%s allowance=%s\n",
            $tm['basic_pay'], $tm['gross_pay'], $tm['total_deductions'], $tm['net_pay'], $allow['amount'] ?? '-');
        eq('13th month basic_pay is 0', $tm['basic_pay'], 0.00);
        eq('13th month allowance = 60,000 for a full year at 60,000/month', $allow['amount'] ?? null, 60000.00);
        eq('gross = the allowance', $tm['gross_pay'], 60000.00);
        eq('tax = 0 (below the 90,000 exemption)', $tm['total_deductions'], 0.00);
        eq('net = 60,000', $tm['net_pay'], 60000.00);
        $gc = dbRow("SELECT * FROM govt_contributions WHERE payslip_id = $tpid");
        eq('no SSS withheld on 13th month', $gc['sss_ee'], 0.00);
        eq('13th month withholding tax = 0', $gc['withholding_tax'], 0.00);
    }
    eq('13th month period marked Processed',
        dbRow("SELECT status FROM payroll_periods WHERE period_id = $tmPeriod")['status'], 'Processed');

    // =================================================================
    section('3. Reports arithmetic for the regular run');
    // =================================================================
    $r = req('GET', "reports.php?report=payroll_register&run_id=$runId", null, $jar);
    eq('payroll_register -> 200', $r['code'], 200);
    $reg = $r['json']['data'] ?? [];
    $mine = null;
    foreach (($reg['payslips'] ?? []) as $row) { if (($row['employee_number'] ?? '') === 'DSH-EMP-P') $mine = $row; }
    is_true('the test employee appears in the register', $mine !== null, 'not found');
    if ($mine) eq('register net_pay matches the stored payslip', $mine['net_pay'], $ps['net_pay']);
    $dbNet = (float) dbRow("SELECT COALESCE(SUM(net_pay),0) net FROM payslips WHERE run_id = $runId")['net'];
    eq('register totals.net_pay equals the SUM of the run', $reg['totals']['net_pay'] ?? null, round($dbNet, 2));
    $dbGross = (float) dbRow("SELECT COALESCE(SUM(gross_pay),0) g FROM payslips WHERE run_id = $runId")['g'];
    eq('register totals.gross_pay equals the SUM of the run', $reg['totals']['gross_pay'] ?? null, round($dbGross, 2));

    $r = req('GET', "reports.php?report=payroll_summary&run_id=$runId", null, $jar);
    eq('payroll_summary -> 200', $r['code'], 200);
    $sum = $r['json']['data'] ?? [];
    $slipCount = dbCount('payslips', "run_id = $runId");
    eq('payroll_summary lists one row per payslip', count($sum['employees'] ?? []), $slipCount);
    eq('payroll_summary headcount = payslip count', $sum['totals']['headcount'] ?? null, $slipCount);
    eq('payroll_summary total_net equals the SUM of the run', $sum['totals']['total_net'] ?? null, round($dbNet, 2));
    eq('payroll_summary total_gross equals the SUM of the run', $sum['totals']['total_gross'] ?? null, round($dbGross, 2));

    $r = req('GET', 'reports.php?report=remittance&year=2027&month=3', null, $jar);
    eq('remittance -> 200', $r['code'], 200);
    $rem = $r['json']['data']['totals'] ?? [];
    $dbRem = dbRow(
        "SELECT COUNT(*) payslip_count,
                COALESCE(SUM(g.sss_ee),0) sss_ee, COALESCE(SUM(g.philhealth_ee),0) philhealth_ee,
                COALESCE(SUM(g.pagibig_ee),0) pagibig_ee, COALESCE(SUM(g.withholding_tax),0) withholding_tax
         FROM payslips ps
         JOIN payroll_runs r ON r.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = r.period_id
         LEFT JOIN govt_contributions g ON g.payslip_id = ps.payslip_id
         WHERE pp.period_type <> '13th Month' AND pp.start_date <= '2027-03-31' AND pp.end_date >= '2027-03-01'"
    );
    eq('remittance payslip_count matches the DB', $rem['payslip_count'] ?? null, (int) $dbRem['payslip_count']);
    eq('remittance sss_ee matches the DB', $rem['sss_ee'] ?? null, round((float) $dbRem['sss_ee'], 2));
    eq('remittance withholding_tax matches the DB', $rem['withholding_tax'] ?? null, round((float) $dbRem['withholding_tax'], 2));
    is_true('remittance excludes the 13th Month run', (int) ($rem['payslip_count'] ?? 0) === dbCount('payslips', "run_id = $runId"),
        "count {$rem['payslip_count']} vs regular-run payslips " . dbCount('payslips', "run_id = $runId"));

    // =================================================================
    section('4. Ledger reflects the seeded attendance');
    // =================================================================
    $r = req('GET', "ledger.php?employee_id=$empId&from=$start&to=$end", null, $jar);
    eq('ledger -> 200', $r['code'], 200);
    $led = $r['json']['data'] ?? [];
    $dbHours = (float) dbRow("SELECT COALESCE(SUM(hours_worked),0) h FROM attendance
                              WHERE employee_id = $empId AND work_date BETWEEN '$start' AND '$end'
                                AND DAYOFWEEK(work_date) BETWEEN 2 AND 7")['h'];
    eq('ledger total_hours matches the working-day attendance sum', $led['totals']['total_hours'] ?? null, round($dbHours, 2));
    $weekendEntries = 0;
    foreach (($led['ledger'] ?? []) as $row) { if (($row['status'] ?? '') === 'Weekend') $weekendEntries++; }
    $sundays = 0; $cc = new DateTime($start); $ee = (new DateTime($end))->modify('+1 day');
    while ($cc < $ee) { if ((int) $cc->format('N') === 7) $sundays++; $cc->modify('+1 day'); }
    eq('ledger marks only Sundays as Weekend', $weekendEntries, $sundays);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    $drift = [];
    foreach ($WATCH as $t) { $now = dbCount($t); if ($now !== $START[$t]) $drift[] = "$t {$START[$t]}->$now"; }
    if ($drift) {
        bad('database fully restored to its pre-test state', implode(', ', $drift));
    } else {
        ok('database fully restored to its pre-test state');
        printf("  (snapshot preserved: payslips=%d, periods=%d, attendance=%d)\n",
            dbCount('payslips'), dbCount('payroll_periods'), dbCount('attendance'));
    }
}

summary();
