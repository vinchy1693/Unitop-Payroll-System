<?php
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list loans with computed balance ----
if ($method === "GET") {
    $sql = "SELECT l.loan_id, l.employee_id, l.loan_type,
                   l.principal_amount, l.monthly_amortization, l.status,
                   e.employee_number,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   d.department_name,
                   COALESCE(
                       (SELECT SUM(amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id),
                       0
                   ) AS total_paid
            FROM loans l
            JOIN employees e ON e.employee_id = l.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            ORDER BY l.status ASC, l.loan_id DESC";

    $rows = $pdo->query($sql)->fetchAll();

    // Attach computed remaining balance
    foreach ($rows as &$r) {
        $r["remaining_balance"] = round((float) $r["principal_amount"] - (float) $r["total_paid"], 2);
    }
    unset($r);

    respond(true, "", $rows);
}

// ---- POST: create loan ----
if ($method === "POST") {
    $in         = json_input();
    $empId      = (int)   ($in["employee_id"]           ?? 0);
    $type       = trim($in["loan_type"]                 ?? "");
    $principal  = (float) ($in["principal_amount"]      ?? 0);
    $amort      = (float) ($in["monthly_amortization"]  ?? 0);

    if ($empId === 0 || $type === "" || $principal <= 0 || $amort <= 0) {
        respond(false, "Employee, Type, Principal, and Monthly Amortization are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO loans (employee_id, loan_type, principal_amount, monthly_amortization, status)
         VALUES (?, ?, ?, ?, 'Active')"
    );
    $stmt->execute([$empId, $type, $principal, $amort]);

    respond(true, "Loan added.", ["loan_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update basic loan info ----
if ($method === "PUT") {
    $in         = json_input();
    $id         = (int)   ($in["loan_id"]              ?? 0);
    $empId      = (int)   ($in["employee_id"]          ?? 0);
    $type       = trim($in["loan_type"]                ?? "");
    $principal  = (float) ($in["principal_amount"]     ?? 0);
    $amort      = (float) ($in["monthly_amortization"] ?? 0);

    if ($id === 0 || $empId === 0 || $type === "" || $principal <= 0 || $amort <= 0) {
        respond(false, "All fields are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "UPDATE loans
         SET employee_id = ?, loan_type = ?, principal_amount = ?, monthly_amortization = ?
         WHERE loan_id = ?"
    );
    $stmt->execute([$empId, $type, $principal, $amort, $id]);

    respond(true, "Loan updated.");
}

// ---- PATCH: change status (Active / Paid / Cancelled) ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int)  ($in["loan_id"] ?? 0);
    $status = trim($in["status"] ?? "");

    if ($id === 0 || !in_array($status, ["Active", "Paid", "Cancelled"], true)) {
        respond(false, "Valid loan_id and status (Active / Paid / Cancelled) required.", null, 422);
    }

    $pdo->prepare("UPDATE loans SET status = ? WHERE loan_id = ?")
        ->execute([$status, $id]);

    respond(true, "Loan marked as $status.");
}

// ---- DELETE: only if no payments exist ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) {
        respond(false, "Missing loan_id.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM loan_payments WHERE loan_id = ?");
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        respond(false, "Cannot delete: payments already recorded. Mark as Cancelled instead.", null, 409);
    }

    $pdo->prepare("DELETE FROM loans WHERE loan_id = ?")->execute([$id]);
    respond(true, "Loan deleted.");
}

respond(false, "Method not allowed.", null, 405);