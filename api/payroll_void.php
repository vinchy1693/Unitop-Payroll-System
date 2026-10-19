<?php
// =====================================================================
// POST payroll_void.php
//
// Void a payroll run so its period can be processed again.
//
// Why this exists: a period's run could never be undone. The lock correctly
// refused edits to an Approved run with "a formal adjustment is required", but
// no such adjustment existed - so a wrong payroll was permanent, and the only
// way out was deleting rows by hand in the database.
//
// Guarded by CHECKER_ROLES: voiding a payroll is the checker's decision, not the
// maker's. A Released run is refused outright, because payment has already been
// authorised - that needs a reversing adjustment, not a void.
//
// Body: { run_id: int, remarks: string }   remarks is required (min 10 chars).
// =====================================================================
require __DIR__ . "/_bootstrap.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

requireRole(CHECKER_ROLES);   // the Approver authorises a void

$in      = json_input();
$runId   = (int)   ($in["run_id"] ?? 0);
$remarks = trim((string) ($in["remarks"] ?? ""));

if ($runId === 0) {
    respond(false, "run_id is required.", null, 422);
}
if (strlen($remarks) < 10) {
    respond(false, "A reason is required (at least 10 characters) - a void is a formal adjustment and must be justified.", null, 422);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT r.run_id, r.period_id, r.status, p.start_date, p.end_date
         FROM payroll_runs r
         JOIN payroll_periods p ON p.period_id = r.period_id
         WHERE r.run_id = ? FOR UPDATE"
    );
    $stmt->execute([$runId]);
    $run = $stmt->fetch();

    if (!$run) {
        $pdo->rollBack();
        respond(false, "Payroll run not found.", null, 404);
    }
    if ($run["status"] === "Released") {
        $pdo->rollBack();
        respond(false,
            "Run #$runId is Released - payment has already been authorised. "
            . "A released payroll cannot be voided; it needs a reversing adjustment.",
            null, 409);
    }

    $payslipIds = $pdo->prepare("SELECT payslip_id FROM payslips WHERE run_id = ?");
    $payslipIds->execute([$runId]);
    $ids = array_map("intval", $payslipIds->fetchAll(PDO::FETCH_COLUMN));

    if ($ids) {
        $list = implode(",", $ids);

        // Remember which loans this run paid before the payments disappear, so
        // a loan that the run marked Paid can be reopened for collection.
        $loanStmt = $pdo->prepare(
            "SELECT DISTINCT loan_id FROM loan_payments WHERE payslip_id IN ($list)"
        );
        $loanStmt->execute();
        $loanIds = array_map("intval", $loanStmt->fetchAll(PDO::FETCH_COLUMN));

        foreach (["cash_disbursements", "loan_payments", "allowances", "deductions", "govt_contributions"] as $table) {
            $pdo->exec("DELETE FROM $table WHERE payslip_id IN ($list)");
        }
        $pdo->exec("DELETE FROM payslips WHERE payslip_id IN ($list)");

        if ($loanIds) {
            $loanList = implode(",", $loanIds);
            // Re-open only loans that are still short after the delete; a loan
            // with other payments that still covers the principal stays Paid.
            $pdo->exec(
                "UPDATE loans l
                 SET status = 'Active'
                 WHERE l.loan_id IN ($loanList)
                   AND l.status = 'Paid'
                   AND l.principal_amount > COALESCE(
                       (SELECT SUM(lp.amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id), 0
                   )"
            );
        }
    }
    $pdo->prepare("DELETE FROM payroll_runs WHERE run_id = ?")->execute([$runId]);

    // The period becomes processable again; the unique key on period_id means the
    // old run must be gone first, which it now is.
    $pdo->prepare("UPDATE payroll_periods SET status = 'Open' WHERE period_id = ?")
        ->execute([(int) $run["period_id"]]);

    $pdo->commit();

    respond(true,
        "Payroll run #$runId voided. "
        . count($ids) . " payslip(s) removed and period #{$run["period_id"]} "
        . "({$run["start_date"]} to {$run["end_date"]}) reopened. Reason recorded in the audit log.",
        [
            "run_id"     => $runId,
            "period_id"  => (int) $run["period_id"],
            "payslips"   => count($ids),
            "was_status" => $run["status"],
            "remarks"    => $remarks,
        ]
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respondServerError("The run could not be voided. Please try again or contact the administrator.", $e, "payroll_void");
}
