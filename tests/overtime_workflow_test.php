<?php
// =====================================================================
// tests/overtime_workflow_test.php
//
// End-to-end test of the overtime approval workflow:
//
//   scan 1  -> clock in            (attendance row)
//   scan 2  -> clock out           (shift ends, time_out set)
//   scan 3  -> overtime REQUEST    (Pending, time_in NULL, no attendance row)
//   scan 4  -> refused while Pending (no duplicate, no clock-in)
//   maker approves
//   scan 5  -> overtime clock-in   (time_in set)
//   scan 6  -> overtime clock-out  (time_out + hours, Completed)
//   payroll -> hours x multiplier x (daily_rate / 8)
//   second block after Completed -> a NEW Pending request
//   reject  -> excluded from payroll and cannot be clocked in
//
// Run:  C:\xampp\php\php.exe tests\overtime_workflow_test.php
// =====================================================================

require __DIR__ . '/_harness.php';
require __DIR__ . '/../api/_payroll_helpers.php';

// Independent working-day counter (Mon-Sat, Sunday is the rest day).
function wfWorkdays(string $from, string $to): int {
    $n = 0;
    $c = new DateTime($from);
    $e = (new DateTime($to))->modify('+1 day');
    while ($c < $e) {
        if ((int) $c->format('N') <= 6) $n++;
        $c->modify('+1 day');
    }
    return $n;
}

function wfScan(string $qr, string $date, string $time, string $jar): array {
    return req('POST', 'scan_attendance.php', [
        'code_value' => $qr,
        'demo_mode'  => true,
        'demo_date'  => $date,
        'demo_time'  => $time,
    ], $jar);
}

function wfOvertime(PDO $pdo, int $empId, string $date): array {
    $stmt = $pdo->prepare(
        "SELECT * FROM overtime_records WHERE employee_id = ? AND ot_date = ? ORDER BY ot_id"
    );
    $stmt->execute([$empId, $date]);
    return $stmt->fetchAll();
}

/**
 * Run payroll, read the fixture employee's payslip, then undo the run and
 * reopen the period so the next assertion can run payroll again.
 * Only this test's own period/run/payslips are touched.
 */
function wfPayrollOnce(PDO $pdo, int $periodId, int $empId, string $jar): array {
    $r = req('POST', 'payroll_runs.php', ['period_id' => $periodId], $jar);
    $runId = (int) ($r['json']['data']['run_id'] ?? 0);
    $ps = false;
    if ($runId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM payslips WHERE run_id = ? AND employee_id = ?");
        $stmt->execute([$runId, $empId]);
        $ps = $stmt->fetch();

        $slips = $pdo->query("SELECT payslip_id FROM payslips WHERE run_id = $runId")->fetchAll(PDO::FETCH_COLUMN);
        if ($slips) {
            $in = implode(',', array_map('intval', $slips));
            foreach (['cash_disbursements', 'loan_payments', 'allowances', 'deductions', 'govt_contributions'] as $t) {
                $pdo->exec("DELETE FROM `$t` WHERE payslip_id IN ($in)");
            }
            $pdo->exec("DELETE FROM payslips WHERE run_id = $runId");
        }
        $pdo->exec("DELETE FROM payroll_runs WHERE run_id = $runId");
    }
    $pdo->prepare("UPDATE payroll_periods SET status = 'Open' WHERE period_id = ?")->execute([$periodId]);

    return ['code' => $r['code'], 'run_id' => $runId, 'payslip' => $ps];
}

// Protected rows: the three real employees / users must come out unchanged.
$protected = dbAll("SELECT employee_id, basic_salary FROM employees WHERE employee_id IN (4,5,8) ORDER BY employee_id");
$protectedUsers = dbAll("SELECT username FROM users WHERE username IN ('admin','vinchy','juan.delacruz') ORDER BY username");
$existingRunPayslips = dbCount('payslips', "run_id = 1");
$loansBefore = dbAll("SELECT loan_id, status FROM loans");

$empId = 0;
$periodId = 0;

try {
    // ---- fixtures -----------------------------------------------------
    // Since the maker/checker split, the Payroll Officer (maker) drives the
    // overtime workflow; the Approver only reads it.
    $adminId = mkUser('dsh_ot_admin', 'Payroll Officer');
    $jar     = login('dsh_ot_admin');

    $salary = 24000.00;   // semi-monthly basic = 12,000
    $empId  = mkEmployee('DSH-OT-EMP', 'Overtime', 'Tester', $salary);

    $qr = 'DSH-OT-QR-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $GLOBALS['pdo']->prepare(
        "INSERT INTO qr_codes (employee_id, code_value, issued_date, status) VALUES (?, ?, CURDATE(), 'Active')"
    )->execute([$empId, $qr]);
    onCleanup(function () use ($qr) {
        $GLOBALS['pdo']->prepare("DELETE FROM qr_codes WHERE code_value = ?")->execute([$qr]);
    });

    $periodStart = '2027-04-01';
    $periodEnd   = '2027-04-15';
    $periodId    = mkPeriod($periodStart, $periodEnd);

    // First working day of the period.
    $otDate = $periodStart;
    while ((int) (new DateTime($otDate))->format('N') === 7) {
        $otDate = (new DateTime($otDate))->modify('+1 day')->format('Y-m-d');
    }

    $workdays = wfWorkdays($periodStart, $periodEnd);
    printf("fixture: employee=%d period=%d %s..%s (%d working days) OT date=%s\n",
        $empId, $periodId, $periodStart, $periodEnd, $workdays, $otDate);

    // =================================================================
    section('1. Clock in');
    // =================================================================
    $r = wfScan($qr, $otDate, '08:00:00', $jar);
    eq('clock in -> 200', $r['code'], 200);
    eq('action = in', $r['json']['data']['action'] ?? null, 'in');
    eq('attendance rows for the day', dbCount('attendance', "employee_id = $empId AND work_date = '$otDate'"), 1);
    $att = dbRow("SELECT * FROM attendance WHERE employee_id = $empId AND work_date = '$otDate'");
    eq('attendance time_in = 08:00:00', $att['time_in'] ?? null, '08:00:00');
    is_true('attendance time_out is still NULL', ($att['time_out'] ?? null) === null);

    // =================================================================
    section('2. Clock out');
    // =================================================================
    $r = wfScan($qr, $otDate, '17:00:00', $jar);
    eq('clock out -> 200', $r['code'], 200);
    eq('action = out', $r['json']['data']['action'] ?? null, 'out');
    $att = dbRow("SELECT * FROM attendance WHERE employee_id = $empId AND work_date = '$otDate'");
    eq('attendance time_out = 17:00:00', $att['time_out'] ?? null, '17:00:00');
    eq('hours_worked = 8.00 (9h minus 1h lunch)', $att['hours_worked'] ?? null, 8.00);
    eq('still exactly one attendance row', dbCount('attendance', "employee_id = $empId AND work_date = '$otDate'"), 1);

    // =================================================================
    section('3. Overtime request is filed (Pending, no time_in)');
    // =================================================================
    $r = wfScan($qr, $otDate, '17:30:00', $jar);
    eq('overtime request -> 200', $r['code'], 200);
    eq('action = ot_request', $r['json']['data']['action'] ?? null, 'ot_request');
    is_true('message mentions waiting for approval',
        stripos((string) ($r['json']['message'] ?? ''), 'waiting for approval') !== false,
        $r['json']['message'] ?? '');
    $ots = wfOvertime($GLOBALS['pdo'], $empId, $otDate);
    eq('one overtime request exists', count($ots), 1);
    $req = $ots[0] ?? [];
    eq('request status = Pending', $req['status'] ?? null, 'Pending');
    is_true('request time_in is NULL', ($req['time_in'] ?? null) === null);
    is_true('request hours is NULL', ($req['hours'] ?? null) === null);
    is_true('requested_at is recorded', ($req['requested_at'] ?? null) !== null);
    eq('no new attendance row was created', dbCount('attendance', "employee_id = $empId AND work_date = '$otDate'"), 1);

    // =================================================================
    section('4. A second scan while Pending is refused');
    // =================================================================
    $r = wfScan($qr, $otDate, '17:31:00', $jar);
    eq('scan while Pending -> 409', $r['code'], 409);
    eq('action = ot_pending', $r['json']['data']['action'] ?? null, 'ot_pending');
    is_true('message says still awaiting approval',
        stripos((string) ($r['json']['message'] ?? ''), 'still awaiting approval') !== false,
        $r['json']['message'] ?? '');
    eq('no duplicate request was created', count(wfOvertime($GLOBALS['pdo'], $empId, $otDate)), 1);
    $req = wfOvertime($GLOBALS['pdo'], $empId, $otDate)[0] ?? [];
    is_true('the pending request was not started', ($req['time_in'] ?? null) === null);
    eq('no extra attendance row was created', dbCount('attendance', "employee_id = $empId AND work_date = '$otDate'"), 1);

    // =================================================================
    section('5. Payroll excludes the Pending request');
    // =================================================================
    $pay = wfPayrollOnce($GLOBALS['pdo'], $periodId, $empId, $jar);
    eq('pending payroll run -> 200', $pay['code'], 200);
    is_true('payslip generated for the fixture employee', $pay['payslip'] !== false, 'no payslip');
    if ($pay['payslip']) {
        eq('Pending overtime contributes 0 to overtime_pay', $pay['payslip']['overtime_pay'] ?? null, 0.00);
    }

    // =================================================================
    section('6. Admin approves the request');
    // =================================================================
    $otId = (int) ($req['ot_id'] ?? 0);
    $r = req('PATCH', 'overtime_records.php', ['ot_id' => $otId, 'action' => 'approve'], $jar);
    eq('PATCH approve -> 200', $r['code'], 200);
    eq('status = Approved', $r['json']['data']['status'] ?? null, 'Approved');
    $req = dbRow("SELECT * FROM overtime_records WHERE ot_id = $otId");
    eq('stored status = Approved', $req['status'] ?? null, 'Approved');
    eq('approved_by = the logged-in admin', (int) ($req['approved_by'] ?? 0), $adminId);
    is_true('approved_at is recorded', ($req['approved_at'] ?? null) !== null);
    is_true('approval did not invent time_in', ($req['time_in'] ?? null) === null);
    eq('approval did not invent hours', $req['hours'] ?? null, null);

    // A non-payroll role must not be able to decide requests.
    mkUser('dsh_ot_emp', 'Employee', $empId);
    $empJar = login('dsh_ot_emp');
    $r = req('PATCH', 'overtime_records.php', ['ot_id' => $otId, 'action' => 'reject'], $empJar);
    eq('Employee role cannot PATCH overtime -> 403', $r['code'], 403);

    // =================================================================
    section('7. Approved overtime clock-in');
    // =================================================================
    $r = wfScan($qr, $otDate, '18:00:00', $jar);
    eq('overtime clock-in -> 200', $r['code'], 200);
    eq('action = ot_in', $r['json']['data']['action'] ?? null, 'ot_in');
    is_true('message says overtime started',
        stripos((string) ($r['json']['message'] ?? ''), 'overtime started') !== false,
        $r['json']['message'] ?? '');
    $req = dbRow("SELECT * FROM overtime_records WHERE ot_id = $otId");
    eq('time_in = 18:00:00', $req['time_in'] ?? null, '18:00:00');
    is_true('time_out is still NULL', ($req['time_out'] ?? null) === null);
    eq('status is still Approved (running)', $req['status'] ?? null, 'Approved');
    eq('no attendance row was created for overtime', dbCount('attendance', "employee_id = $empId AND work_date = '$otDate'"), 1);

    // =================================================================
    section('8. Approved overtime clock-out computes the hours');
    // =================================================================
    $r = wfScan($qr, $otDate, '20:30:00', $jar);
    eq('overtime clock-out -> 200', $r['code'], 200);
    eq('action = ot_out', $r['json']['data']['action'] ?? null, 'ot_out');
    eq('returned hours = 2.50', $r['json']['data']['hours'] ?? null, 2.50);
    is_true('message reports 2.50 hour(s)',
        strpos((string) ($r['json']['message'] ?? ''), '2.50 hour(s)') !== false,
        $r['json']['message'] ?? '');
    $req = dbRow("SELECT * FROM overtime_records WHERE ot_id = $otId");
    eq('time_out = 20:30:00', $req['time_out'] ?? null, '20:30:00');
    eq('hours = 2.50 (time_out - time_in, no lunch)', $req['hours'] ?? null, 2.50);
    eq('status = Completed', $req['status'] ?? null, 'Completed');

    // =================================================================
    section('9. Completed overtime reaches payroll');
    // =================================================================
    $dailyRate   = round(($salary / 2) / $workdays, 2);
    $expectedOt  = round(2.50 * 1.25 * ($dailyRate / 8.0), 2);
    printf("independent expectation: daily=%.2f hourly=%.4f ot=%.2f\n",
        $dailyRate, $dailyRate / 8.0, $expectedOt);

    $pay = wfPayrollOnce($GLOBALS['pdo'], $periodId, $empId, $jar);
    eq('completed payroll run -> 200', $pay['code'], 200);
    is_true('payslip generated', $pay['payslip'] !== false, 'no payslip');
    if ($pay['payslip']) {
        eq('overtime_pay = 2.50 x 1.25 x (daily_rate / 8)', $pay['payslip']['overtime_pay'] ?? null, $expectedOt);
        eq('gross_pay = basic + overtime',
            $pay['payslip']['gross_pay'] ?? null,
            round((float) $pay['payslip']['basic_pay'] + $expectedOt, 2));
    }

    // =================================================================
    section('10. A second block the same day files a NEW request');
    // =================================================================
    $r = wfScan($qr, $otDate, '21:00:00', $jar);
    eq('second overtime request -> 200', $r['code'], 200);
    eq('action = ot_request', $r['json']['data']['action'] ?? null, 'ot_request');
    $ots = wfOvertime($GLOBALS['pdo'], $empId, $otDate);
    eq('two overtime records for the day', count($ots), 2);
    $second = $ots[1] ?? [];
    eq('second request status = Pending', $second['status'] ?? null, 'Pending');
    is_true('second request time_in is NULL', ($second['time_in'] ?? null) === null);
    eq('first block is still Completed', $ots[0]['status'] ?? null, 'Completed');
    eq('first block kept its 2.50 hours', $ots[0]['hours'] ?? null, 2.50);

    // =================================================================
    section('11. Reject excludes the request and cannot start overtime');
    // =================================================================
    $secondId = (int) ($second['ot_id'] ?? 0);
    $r = req('PATCH', 'overtime_records.php', ['ot_id' => $secondId, 'action' => 'reject'], $jar);
    eq('PATCH reject -> 200', $r['code'], 200);
    eq('status = Rejected', $r['json']['data']['status'] ?? null, 'Rejected');
    $rejected = dbRow("SELECT * FROM overtime_records WHERE ot_id = $secondId");
    eq('stored status = Rejected', $rejected['status'] ?? null, 'Rejected');
    eq('rejected_by recorded', (int) ($rejected['approved_by'] ?? 0), $adminId);
    is_true('rejected_at recorded', ($rejected['approved_at'] ?? null) !== null);

    // Scanning a Rejected request never starts it; it files a fresh request.
    $r = wfScan($qr, $otDate, '21:30:00', $jar);
    eq('scan after reject -> 200', $r['code'], 200);
    eq('action = ot_rejected', $r['json']['data']['action'] ?? null, 'ot_rejected');
    is_true('message says the request was not approved',
        stripos((string) ($r['json']['message'] ?? ''), 'not approved') !== false,
        $r['json']['message'] ?? '');
    $rejected = dbRow("SELECT * FROM overtime_records WHERE ot_id = $secondId");
    is_true('rejected request was never clocked in', ($rejected['time_in'] ?? null) === null);
    is_true('rejected request has no hours', ($rejected['hours'] ?? null) === null);

    $ots = wfOvertime($GLOBALS['pdo'], $empId, $otDate);
    eq('a fresh request was filed after the rejection', count($ots), 3);
    eq('the fresh request is Pending', $ots[2]['status'] ?? null, 'Pending');
    is_true('the fresh request has no time_in', ($ots[2]['time_in'] ?? null) === null);

    // Another scan while that fresh request is Pending is refused.
    $r = wfScan($qr, $otDate, '21:31:00', $jar);
    eq('scan while fresh request is Pending -> 409', $r['code'], 409);
    eq('action = ot_pending', $r['json']['data']['action'] ?? null, 'ot_pending');
    eq('still three records (no duplicate)', count(wfOvertime($GLOBALS['pdo'], $empId, $otDate)), 3);

    // Payroll still pays only the Completed block.
    $pay = wfPayrollOnce($GLOBALS['pdo'], $periodId, $empId, $jar);
    eq('post-reject payroll run -> 200', $pay['code'], 200);
    if ($pay['payslip']) {
        eq('Rejected/Pending blocks add nothing to overtime_pay',
            $pay['payslip']['overtime_pay'] ?? null, $expectedOt);
    } else {
        bad('payslip generated after rejection', 'no payslip');
    }

    // API status filter + pending hours payload.
    $r = req('GET', 'overtime_records.php?status=Rejected', null, $jar);
    eq('GET ?status=Rejected -> 200', $r['code'], 200);
    $rejectedRows = array_values(array_filter(
        $r['json']['data'] ?? [],
        fn($row) => (int) ($row['employee_id'] ?? 0) === $empId
    ));
    eq('status filter returns the rejected request', count($rejectedRows), 1);
    eq('rejected row is not payable', $rejectedRows[0]['is_payable'] ?? null, false);

    $r = req('GET', "overtime_records.php?id=$secondId", null, $jar);
    eq('GET single rejected request -> 200', $r['code'], 200);
    eq('single rejected row reports pending_hours = 0', $r['json']['data']['pending_hours'] ?? null, 0.00);
    eq('single rejected row is_payable = false', $r['json']['data']['is_payable'] ?? null, false);

    // =================================================================
    section('12. Live pending hours + status filtering');
    // =================================================================
    // A block that is running right now (approved, clocked in, not out, on
    // today's date) shows its elapsed hours in the queue. It is not payable.
    $today  = date('Y-m-d');
    $liveIn = date('H:i:s', time() - 90 * 60);
    $GLOBALS['pdo']->prepare(
        "INSERT INTO overtime_records
         (employee_id, ot_date, hours, ot_type, rate_multiplier, status, time_in, time_out, requested_at)
         VALUES (?, ?, NULL, 'Ordinary Day', 1.25, 'Approved', ?, NULL, NOW())"
    )->execute([$empId, $today, $liveIn]);
    $liveId = (int) $GLOBALS['pdo']->lastInsertId();

    $r = req('GET', "overtime_records.php?id=$liveId", null, $jar);
    eq('running block -> 200', $r['code'], 200);
    eq('running block reports live pending_hours ~ 1.50', $r['json']['data']['pending_hours'] ?? null, 1.50, 0.05);
    eq('running block is not payable yet', $r['json']['data']['is_payable'] ?? null, false);

    $r = req('GET', 'overtime_records.php?status=Pending', null, $jar);
    eq('GET ?status=Pending -> 200', $r['code'], 200);
    $pendingMine = array_values(array_filter(
        $r['json']['data'] ?? [],
        fn($row) => (int) ($row['employee_id'] ?? 0) === $empId
    ));
    eq('status filter returns the pending request', count($pendingMine), 1);
    eq('pending request status = Pending', $pendingMine[0]['status'] ?? null, 'Pending');

    $r = req('GET', "overtime_records.php?date=$otDate&status=Pending", null, $jar);
    eq('GET ?date=...&status=Pending -> 200', $r['code'], 200);
    eq('date + status filter returns the one pending request', count($r['json']['data'] ?? []), 1);

    $r = req('GET', 'overtime_records.php?all=1', null, $jar);
    eq('GET ?all=1 -> 200', $r['code'], 200);
    $allMine = array_values(array_filter(
        $r['json']['data'] ?? [],
        fn($row) => (int) ($row['employee_id'] ?? 0) === $empId
    ));
    eq('all-status listing returns every fixture block', count($allMine), 4);

    $r = req('GET', 'overtime_records.php?status=Bogus', null, $jar);
    eq('unknown status filter -> 422', $r['code'], 422);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();

    // Restore loan statuses in case the payroll runs touched a real loan.
    foreach ($loansBefore as $loan) {
        $GLOBALS['pdo']->prepare("UPDATE loans SET status = ? WHERE loan_id = ?")
            ->execute([$loan['status'], $loan['loan_id']]);
    }

    // ---- targeted cleanup verification (never sweeps other people's rows) ----
    $checks = [
        'fixture overtime rows'  => dbCount('overtime_records', "employee_id = $empId"),
        'fixture attendance rows'=> dbCount('attendance', "employee_id = $empId"),
        'fixture QR codes'       => dbCount('qr_codes', "employee_id = $empId"),
        'fixture employee row'   => dbCount('employees', "employee_id = $empId"),
        'fixture admin user'     => dbCount('users', "username = 'dsh_ot_admin'"),
        'fixture employee user'  => dbCount('users', "username = 'dsh_ot_emp'"),
        'fixture period'         => dbCount('payroll_periods', "period_id = $periodId"),
        'fixture payroll runs'   => dbCount('payroll_runs', "period_id = $periodId"),
    ];
    $leftover = [];
    foreach ($checks as $label => $n) {
        if ($n !== 0) $leftover[] = "$label=$n";
    }
    if ($leftover) {
        bad('all test fixtures removed', implode(', ', $leftover));
    } else {
        ok('all test fixtures removed');
    }

    // ---- protected rows untouched ----
    $afterProtected = dbAll("SELECT employee_id, basic_salary FROM employees WHERE employee_id IN (4,5,8) ORDER BY employee_id");
    eq('the three real employees still exist with the same salaries', $afterProtected, $protected);

    $afterUsers = dbAll("SELECT username FROM users WHERE username IN ('admin','vinchy','juan.delacruz') ORDER BY username");
    eq('the three real users still exist', $afterUsers, $protectedUsers);

    eq('the pre-existing payroll run 1 still has its payslips', dbCount('payslips', "run_id = 1"), $existingRunPayslips);

    $positions = dbCount('positions');
    $departments = dbCount('departments');
    is_true('positions untouched', $positions >= 2, "positions=$positions");
    is_true('departments untouched', $departments >= 2, "departments=$departments");
}

summary();
