<?php
// =====================================================================
// api/cash_disbursements.php
//
// Cash actually handed to an employee against a payslip. The maker records
// the release; the checker can read it. A payslip whose run is Approved or
// Released is locked, so no disbursement can be added or removed then (a
// formal adjustment is required).
//
// GET    ?payslip_id=N | ?run_id=N | (no filter: latest 500)
// POST   { payslip_id, amount_released, date_released?, acknowledged_by_signature? }
// DELETE ?id=N
// =====================================================================

require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET ----
if ($method === "GET") {
    $where  = "";
    $params = [];

    $payslipId = (int) ($_GET["payslip_id"] ?? 0);
    $runId     = (int) ($_GET["run_id"] ?? 0);

    if ($payslipId > 0) {
        $where  = " WHERE cd.payslip_id = ?";
        $params = [$payslipId];
    } elseif ($runId > 0) {
        $where  = " WHERE ps.run_id = ?";
        $params = [$runId];
    }

    $sql = "SELECT cd.disbursement_id, cd.payslip_id, cd.released_by,
                   cd.amount_released, cd.date_released, cd.acknowledged_by_signature,
                   ps.run_id,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   e.employee_number,
                   u.username AS released_by_name
            FROM cash_disbursements cd
            JOIN payslips ps ON ps.payslip_id = cd.payslip_id
            JOIN employees e ON e.employee_id = ps.employee_id
            LEFT JOIN users u ON u.user_id = cd.released_by"
            . $where
            . " ORDER BY cd.disbursement_id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    respond(true, "", $stmt->fetchAll());
}

// Everything below mutates: only the maker may write.
requireRole(MAKER_ROLES);

// ---- POST: record a cash release ----
if ($method === "POST") {
    $in        = json_input();
    $payslipId = (int)   ($in["payslip_id"] ?? 0);
    $amount    = (float) ($in["amount_released"] ?? 0);
    $date      = trim((string) ($in["date_released"] ?? ""));
    $ack       = !empty($in["acknowledged_by_signature"]) ? 1 : 0;

    if ($payslipId === 0 || $amount <= 0) {
        respond(false, "Payslip and amount_released (>0) are required.", null, 422);
    }
    if ($date === "") $date = date("Y-m-d");

    // Approved / Released runs are immutable: a formal adjustment is required.
    assertPayslipUnlocked($pdo, $payslipId, "Cash disbursements on this payslip");

    $user = currentUser();
    $stmt = $pdo->prepare(
        "INSERT INTO cash_disbursements
         (payslip_id, released_by, amount_released, date_released, acknowledged_by_signature)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$payslipId, $user["user_id"], $amount, $date, $ack]);

    respond(true, "Cash disbursement recorded.", [
        "disbursement_id" => (int) $pdo->lastInsertId(),
    ]);
}

// ---- DELETE: remove a cash release (unlocked runs only) ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) {
        respond(false, "Missing disbursement_id.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT payslip_id FROM cash_disbursements WHERE disbursement_id = ?");
    $stmt->execute([$id]);
    $payslipId = $stmt->fetchColumn();
    if ($payslipId === false) {
        respond(false, "Cash disbursement not found.", null, 404);
    }

    assertPayslipUnlocked($pdo, (int) $payslipId, "Cash disbursements on this payslip");

    $pdo->prepare("DELETE FROM cash_disbursements WHERE disbursement_id = ?")->execute([$id]);
    respond(true, "Cash disbursement removed.");
}

respond(false, "Method not allowed.", null, 405);
