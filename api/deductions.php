<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET ?payslip_id=N : list deductions for a payslip ----
if ($method === "GET") {
    $payslipId = (int) ($_GET["payslip_id"] ?? 0);
    if ($payslipId === 0) {
        respond(false, "Missing payslip_id.", null, 422);
    }
    $stmt = $pdo->prepare(
        "SELECT deduction_id, payslip_id, deduction_type, amount
         FROM deductions WHERE payslip_id = ? ORDER BY deduction_id"
    );
    $stmt->execute([$payslipId]);
    respond(true, "", $stmt->fetchAll());
}

// ---- POST: add a deduction ----
if ($method === "POST") {
    $in        = json_input();
    $payslipId = (int)   ($in["payslip_id"]      ?? 0);
    $type      = trim($in["deduction_type"] ?? "");
    $amount    = (float) ($in["amount"]          ?? 0);

    if ($payslipId === 0 || $type === "" || $amount <= 0) {
        respond(false, "Payslip, type, and amount (>0) are required.", null, 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO deductions (payslip_id, deduction_type, amount)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$payslipId, $type, $amount]);
        $newId = (int) $pdo->lastInsertId();

        $totals = recomputePayslipTotals($pdo, $payslipId);

        $pdo->commit();

        respond(true, "Deduction added.", [
            "deduction_id" => $newId,
            "totals"       => $totals,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, "Failed to add deduction: " . $e->getMessage(), null, 500);
    }
}

// ---- DELETE ?id=N : remove a deduction ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) {
        respond(false, "Missing deduction_id.", null, 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT payslip_id FROM deductions WHERE deduction_id = ?");
        $stmt->execute([$id]);
        $payslipId = (int) $stmt->fetchColumn();
        if ($payslipId === 0) {
            $pdo->rollBack();
            respond(false, "Deduction not found.", null, 404);
        }

        $pdo->prepare("DELETE FROM deductions WHERE deduction_id = ?")->execute([$id]);

        $totals = recomputePayslipTotals($pdo, $payslipId);

        $pdo->commit();

        respond(true, "Deduction removed.", ["totals" => $totals]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, "Failed to remove deduction: " . $e->getMessage(), null, 500);
    }
}

respond(false, "Method not allowed.", null, 405);