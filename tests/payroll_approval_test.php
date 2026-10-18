<?php
// =====================================================================
// tests/payroll_approval_test.php
//
// Maker / checker workflow for payroll runs:
//   1. the maker processes a period -> a Submitted run with submitted_by
//   2. the submitter cannot approve their own run, even as Approver -> 403
//   3. a different Approver approves -> Approved + approved_by/at
//   4. approving an already-Approved run -> 409
//   5. return requires remarks; a Returned run is editable again
//   6. resubmit (maker) puts it back to Submitted
//   7. release only from Approved
//   8. after approval, payslip / deduction / allowance / loan-payment /
//      cash-disbursement writes -> 409 and the DB is unchanged
//   9. the same writes succeed while the run is Returned
//
// Run:  C:\xampp\php\php.exe tests\payroll_approval_test.php
// =====================================================================

require __DIR__ . '/_harness.php';

// Everything the lock must protect, for one payslip / loan.
function lockSnapshot(int $payslipId, int $loanId): array {
    $ps = dbRow("SELECT basic_pay, overtime_pay, gross_pay, total_deductions, net_pay
                 FROM payslips WHERE payslip_id = $payslipId");
    return [
        'payslip'    => $ps,
        'allowances' => dbCount('allowances', "payslip_id = $payslipId"),
        'deductions' => dbCount('deductions', "payslip_id = $payslipId"),
        'payments'   => dbCount('loan_payments', "loan_id = $loanId"),
        'cash'       => dbCount('cash_disbursements', "payslip_id = $payslipId"),
    ];
}

function payslipFor(int $runId, int $employeeId): array {
    $row = dbRow("SELECT * FROM payslips WHERE run_id = $runId AND employee_id = $employeeId");
    if (!$row) throw new RuntimeException("no payslip for run $runId employee $employeeId");
    return $row;
}

function runRow(int $runId): array {
    $row = dbRow("SELECT * FROM payroll_runs WHERE run_id = $runId");
    if (!$row) throw new RuntimeException("run $runId not found");
    return $row;
}

$empId = 0;
$runA = 0;
$runB = 0;
$loanId = 0;
$selfJar = null;

// Real HTTP mutations write audit rows; remove only the ones this run created.
$AUDIT_MAX = (int) (dbRow("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_log")['m'] ?? 0);

try {
    // ---- fixtures -----------------------------------------------------
    $selfId   = mkUser('dsh_appr_self',  'Payroll Officer');   // becomes an Approver later
    $makerId  = mkUser('dsh_appr_maker', 'Payroll Officer');
    $checkerId = mkUser('dsh_appr_check', 'Approver');

    $selfJar    = login('dsh_appr_self');
    $makerJar   = login('dsh_appr_maker');
    $checkerJar = login('dsh_appr_check');

    $empId = mkEmployee('DSH-APPR-EMP', 'Approval', 'Tester', 30000.00);
    $periodA = mkPeriod('2027-05-01', '2027-05-15');
    $periodB = mkPeriod('2027-06-01', '2027-06-15');

    // =================================================================
    section('1. Processing creates a Submitted run with submitted_by');
    // =================================================================
    $r = req('POST', 'payroll_runs.php', ['period_id' => $periodA], $selfJar);
    eq('maker processes the period -> 200', $r['code'], 200);
    $runA = (int) ($r['json']['data']['run_id'] ?? 0);
    is_true('run_id returned', $runA > 0, substr($r['raw'], 0, 200));
    eq('response status = Submitted', $r['json']['data']['status'] ?? null, 'Submitted');

    $row = runRow($runA);
    eq('stored status = Submitted', $row['status'], 'Submitted');
    eq('submitted_by = the maker', (int) $row['submitted_by'], $selfId);
    is_true('submitted_at is recorded', $row['submitted_at'] !== null);
    eq('processed_by = the maker', (int) $row['processed_by'], $selfId);
    is_true('approved_by starts NULL', $row['approved_by'] === null);

    $slipA = payslipFor($runA, $empId);
    $payslipAId = (int) $slipA['payslip_id'];
    is_true('a payslip exists for the fixture employee', $payslipAId > 0);

    // =================================================================
    section('2. The submitter cannot approve their own run');
    // =================================================================
    // The same person now holds the Approver role, so the role guard passes
    // and only the segregation rule can stop them.
    $GLOBALS['pdo']->prepare("UPDATE users SET role = 'Approver' WHERE user_id = ?")->execute([$selfId]);
    $selfJar = login('dsh_appr_self');

    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runA, 'action' => 'approve'], $selfJar);
    eq('submitter (now Approver) approving own run -> 403', $r['code'], 403);
    is_true('the 403 explains segregation of duties',
        stripos((string) ($r['json']['message'] ?? ''), 'submitted') !== false
        || stripos((string) ($r['json']['message'] ?? ''), 'segregation') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('run is still Submitted', runRow($runA)['status'], 'Submitted');

    // Put the maker role back; this account is done for the rest of the run.
    $GLOBALS['pdo']->prepare("UPDATE users SET role = 'Payroll Officer' WHERE user_id = ?")->execute([$selfId]);

    // =================================================================
    section('3. A different Approver approves');
    // =================================================================
    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runA, 'action' => 'approve'], $checkerJar);
    eq('different Approver approves -> 200', $r['code'], 200);
    eq('response status = Approved', $r['json']['data']['status'] ?? null, 'Approved');

    $row = runRow($runA);
    eq('stored status = Approved', $row['status'], 'Approved');
    eq('approved_by = the checker', (int) $row['approved_by'], $checkerId);
    is_true('approved_at is recorded', $row['approved_at'] !== null);
    eq('submitted_by is unchanged', (int) $row['submitted_by'], $selfId);

    // =================================================================
    section('4. Approving an already-Approved run -> 409');
    // =================================================================
    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runA, 'action' => 'approve'], $checkerJar);
    eq('approve from Approved -> 409', $r['code'], 409);
    eq('run is still Approved', runRow($runA)['status'], 'Approved');

    // =================================================================
    section('5. After approval every payslip write -> 409 and nothing changes');
    // =================================================================
    // Seeded BEFORE approval below in section 6; here we assert with what the
    // run already has (attendance deductions may exist, allowances do not).
    $before = lockSnapshot($payslipAId, 0);

    $r = req('PUT', 'payslips.php', ['payslip_id' => $payslipAId, 'basic_pay' => 999999.99], $makerJar);
    eq('editing a payslip -> 409', $r['code'], 409);
    is_true('payslip 409 says a formal adjustment is required',
        stripos((string) ($r['json']['message'] ?? ''), 'formal adjustment is required') !== false,
        (string) ($r['json']['message'] ?? ''));

    $r = req('POST', 'deductions.php', [
        'payslip_id' => $payslipAId, 'deduction_type' => 'DSH Locked Deduction', 'amount' => 10,
    ], $makerJar);
    eq('adding a deduction -> 409', $r['code'], 409);
    is_true('deduction 409 says a formal adjustment is required',
        stripos((string) ($r['json']['message'] ?? ''), 'formal adjustment is required') !== false);

    $r = req('POST', 'allowances.php', [
        'payslip_id' => $payslipAId, 'allowance_type' => 'DSH Locked Allowance', 'amount' => 10,
    ], $makerJar);
    eq('adding an allowance -> 409', $r['code'], 409);
    is_true('allowance 409 says a formal adjustment is required',
        stripos((string) ($r['json']['message'] ?? ''), 'formal adjustment is required') !== false);

    $r = req('POST', 'cash_disbursements.php', [
        'payslip_id' => $payslipAId, 'amount_released' => 100,
    ], $makerJar);
    eq('recording a cash disbursement -> 409', $r['code'], 409);

    $after = lockSnapshot($payslipAId, 0);
    eq('payslip money fields unchanged', $after['payslip'], $before['payslip']);
    eq('no allowance row was created', $after['allowances'], $before['allowances']);
    eq('no deduction row was created', $after['deductions'], $before['deductions']);
    eq('no cash disbursement row was created', $after['cash'], $before['cash']);

    // =================================================================
    section('6. Returned runs are editable, then resubmitted');
    // =================================================================
    $r = req('POST', 'payroll_runs.php', ['period_id' => $periodB], $makerJar);
    eq('maker processes the second period -> 200', $r['code'], 200);
    $runB = (int) ($r['json']['data']['run_id'] ?? 0);
    is_true('second run_id returned', $runB > 0);
    $slipB = payslipFor($runB, $empId);
    $payslipBId = (int) $slipB['payslip_id'];

    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'return'], $checkerJar);
    eq('return without remarks -> 422', $r['code'], 422);
    is_true('the 422 explains that remarks are required',
        stripos((string) ($r['json']['message'] ?? ''), 'remarks') !== false,
        (string) ($r['json']['message'] ?? ''));
    eq('a refused return leaves the run Submitted', runRow($runB)['status'], 'Submitted');

    // Only the Approver may return.
    $r = req('PATCH', 'payroll_runs.php', [
        'run_id' => $runB, 'action' => 'return', 'remarks' => 'Maker may not return.',
    ], $makerJar);
    eq('maker returning a run -> 403', $r['code'], 403);

    $remarks = 'Payslip basic pay does not match the approved rate. Please correct.';
    $r = req('PATCH', 'payroll_runs.php', [
        'run_id' => $runB, 'action' => 'return', 'remarks' => $remarks,
    ], $checkerJar);
    eq('return with remarks -> 200', $r['code'], 200);
    eq('response status = Returned', $r['json']['data']['status'] ?? null, 'Returned');

    $row = runRow($runB);
    eq('stored status = Returned', $row['status'], 'Returned');
    eq('returned_by = the checker', (int) $row['returned_by'], $checkerId);
    is_true('returned_at is recorded', $row['returned_at'] !== null);
    eq('the remarks are stored', $row['remarks'], $remarks);

    // Editable again: payslip + line items.
    $r = req('PUT', 'payslips.php', ['payslip_id' => $payslipBId, 'basic_pay' => 15000.00], $makerJar);
    eq('editing a payslip on a Returned run -> 200', $r['code'], 200);
    eq('the new basic pay is stored', (float) dbRow("SELECT basic_pay FROM payslips WHERE payslip_id = $payslipBId")['basic_pay'], 15000.00);

    $r = req('POST', 'deductions.php', [
        'payslip_id' => $payslipBId, 'deduction_type' => 'DSH Returned Deduction', 'amount' => 12.50,
    ], $makerJar);
    eq('adding a deduction on a Returned run -> 200', $r['code'], 200);
    $dedB = (int) ($r['json']['data']['deduction_id'] ?? 0);

    $r = req('POST', 'allowances.php', [
        'payslip_id' => $payslipBId, 'allowance_type' => 'DSH Returned Allowance', 'amount' => 20,
    ], $makerJar);
    eq('adding an allowance on a Returned run -> 200', $r['code'], 200);

    $r = req('DELETE', "deductions.php?id=$dedB", null, $makerJar);
    eq('removing a deduction on a Returned run -> 200', $r['code'], 200);

    // Resubmit: maker only, Returned -> Submitted.
    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'resubmit'], $checkerJar);
    eq('Approver resubmitting -> 403', $r['code'], 403);

    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'resubmit'], $makerJar);
    eq('maker resubmits -> 200', $r['code'], 200);
    eq('response status = Submitted', $r['json']['data']['status'] ?? null, 'Submitted');
    $row = runRow($runB);
    eq('stored status = Submitted', $row['status'], 'Submitted');
    eq('submitted_by = the resubmitting maker', (int) $row['submitted_by'], $makerId);
    is_true('submitted_at was refreshed', $row['submitted_at'] !== null);

    // =================================================================
    section('7. Release only from Approved');
    // =================================================================
    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'release'], $checkerJar);
    eq('release from Submitted -> 409', $r['code'], 409);
    eq('run is still Submitted', runRow($runB)['status'], 'Submitted');

    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'approve'], $checkerJar);
    eq('approve the resubmitted run -> 200', $r['code'], 200);
    eq('run is Approved', runRow($runB)['status'], 'Approved');

    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'release'], $checkerJar);
    eq('release from Approved -> 200', $r['code'], 200);
    eq('response status = Released', $r['json']['data']['status'] ?? null, 'Released');

    $row = runRow($runB);
    eq('stored status = Released', $row['status'], 'Released');
    eq('release_approved_by = the checker', (int) $row['release_approved_by'], $checkerId);
    is_true('release_approved_at is recorded', $row['release_approved_at'] !== null);

    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'release'], $checkerJar);
    eq('release again -> 409', $r['code'], 409);
    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'approve'], $checkerJar);
    eq('approve a Released run -> 409', $r['code'], 409);

    // =================================================================
    section('8. Loan payments are locked with the run');
    // =================================================================
    $r = req('POST', 'loans.php', [
        'employee_id' => $empId, 'loan_type' => 'DSH Approval Loan',
        'principal_amount' => 1200, 'monthly_amortization' => 200,
    ], $makerJar);
    eq('maker creates a loan -> 200', $r['code'], 200);
    $loanId = (int) ($r['json']['data']['loan_id'] ?? 0);
    onCleanup(function () use ($loanId) {
        $GLOBALS['pdo']->exec("DELETE FROM loan_payments WHERE loan_id = $loanId");
        $GLOBALS['pdo']->exec("DELETE FROM loans WHERE loan_id = $loanId");
    });

    $r = req('POST', 'loans.php', [
        'action' => 'payment', 'loan_id' => $loanId, 'payslip_id' => $payslipBId, 'amount_paid' => 50,
    ], $makerJar);
    eq('loan payment on the Released run -> 409', $r['code'], 409);
    eq('no loan payment row was created', dbCount('loan_payments', "loan_id = $loanId"), 0);

    // =================================================================
    section('9. The API exposes the workflow fields for the UI');
    // =================================================================
    $r = req('GET', 'payroll_runs.php', null, $checkerJar);
    eq('GET payroll_runs.php -> 200', $r['code'], 200);
    $runs = [];
    foreach (($r['json']['data'] ?? []) as $row) $runs[(int) $row['run_id']] = $row;
    is_true('run A is in the list', isset($runs[$runA]));
    is_true('run B is in the list', isset($runs[$runB]));
    if (isset($runs[$runA])) {
        eq('run A status', $runs[$runA]['status'], 'Approved');
        eq('run A submitter name', $runs[$runA]['submitted_by_name'], 'dsh_appr_self');
        eq('run A approver name', $runs[$runA]['approved_by_name'], 'dsh_appr_check');
        is_true('run A approved_at is exposed', $runs[$runA]['approved_at'] !== null);
    }
    if (isset($runs[$runB])) {
        eq('run B status', $runs[$runB]['status'], 'Released');
        eq('run B release approver name', $runs[$runB]['release_approved_by_name'], 'dsh_appr_check');
        eq('run B keeps the return remarks', $runs[$runB]['remarks'], $remarks);
    }

    // =================================================================
    section('10. Role guards on the workflow actions');
    // =================================================================
    foreach ([['approve', $makerJar, 'maker approve'], ['return', $makerJar, 'maker return'], ['release', $makerJar, 'maker release']] as [$action, $jar, $label]) {
        $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => $action, 'remarks' => 'x'], $jar);
        eq("$label -> 403", $r['code'], 403);
    }

    // Invalid action / missing run id.
    $r = req('PATCH', 'payroll_runs.php', ['run_id' => $runB, 'action' => 'approve_self'], $checkerJar);
    eq('unknown action -> 422', $r['code'], 422);
    $r = req('PATCH', 'payroll_runs.php', ['action' => 'approve'], $checkerJar);
    eq('missing run_id -> 422', $r['code'], 422);

} catch (Throwable $e) {
    bad('harness ran without exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    runCleanup();
    sweepTestData();
    $GLOBALS['pdo']->exec("DELETE FROM audit_log WHERE audit_id > $AUDIT_MAX");

    printf("\n=== cleanup verification ===\n");
    printf("  fixture runs left:    %d\n", dbCount('payroll_runs', "run_id IN ($runA, $runB)"));
    printf("  fixture employees:    %d\n", dbCount('employees', "employee_number LIKE 'DSH-APPR-%'"));
    printf("  fixture users:        %d\n", dbCount('users', "username LIKE 'dsh\\_appr\\_%'"));
    is_true('all approval fixtures cleaned up',
        dbCount('payroll_runs', "run_id IN ($runA, $runB)") === 0
        && dbCount('employees', "employee_number LIKE 'DSH-APPR-%'") === 0
        && dbCount('users', "username LIKE 'dsh\\_appr\\_%'") === 0,
        'a fixture row survived cleanup');
}

summary();
