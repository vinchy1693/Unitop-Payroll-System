<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET ?payslip_id=N : list allowances for a payslip ----
if ($method === "GET") {
    $payslipId = (int) ($_GET["payslip_id"] ?? 0);
    if ($payslipId === 0) {
        respond(false, "Missing payslip_id.", null, 422);
    }
    $stmt = $pdo->prepare(
        "SELECT allowance_id, payslip_id, allowance_type, amount
         FROM allowances WHERE payslip_id = ? ORDER BY allowance_id"
    );
    $stmt->execute([$payslipId]);
    respond(true, "", $stmt->fetchAll());
}

// ---- POST: add an allowance ----
requireRole(MAKER_ROLES);   // only the Payroll Officer may change a payslip

if ($method === "POST") {
    $in        = json_input();
    $payslipId = (int)   ($in["payslip_id"]      ?? 0);
    $type      = trim($in["allowance_type"] ?? "");
    $amount    = (float) ($in["amount"]          ?? 0);

    if ($payslipId === 0 || $type === "" || $amount <= 0) {
        respond(false, "Payslip, type, and amount (>0) are required.", null, 422);
    }

    // Approved / Released runs are immutable: a formal adjustment is required.
    assertPayslipUnlocked($pdo, $payslipId, "Allowances on this payslip");

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO allowances (payslip_id, allowance_type, amount)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$payslipId, $type, $amount]);
        $newId = (int) $pdo->lastInsertId();

        $totals = recomputePayslipTotals($pdo, $payslipId);

        $pdo->commit();

        respond(true, "Allowance added.", [
            "allowance_id" => $newId,
            "totals"       => $totals,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, "Failed to add allowance: " . $e->getMessage(), null, 500);
    }
}

// ---- DELETE ?id=N : remove an allowance ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) {
        respond(false, "Missing allowance_id.", null, 422);
    }

    try {
        $pdo->beginTransaction();

        // Get its payslip_id first
        $stmt = $pdo->prepare("SELECT payslip_id FROM allowances WHERE allowance_id = ?");
        $stmt->execute([$id]);
        $payslipId = (int) $stmt->fetchColumn();
        if ($payslipId === 0) {
            $pdo->rollBack();
            respond(false, "Allowance not found.", null, 404);
        }

        assertPayslipUnlocked($pdo, $payslipId, "Allowances on this payslip");

        $pdo->prepare("DELETE FROM allowances WHERE allowance_id = ?")->execute([$id]);

        $totals = recomputePayslipTotals($pdo, $payslipId);

        $pdo->commit();

        respond(true, "Allowance removed.", ["totals" => $totals]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, "Failed to remove allowance: " . $e->getMessage(), null, 500);
    }
}

respond(false, "Method not allowed.", null, 405);