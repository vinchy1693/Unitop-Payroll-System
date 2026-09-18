<?php
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list all salary rates (sorted per employee, newest first) ----
if ($method === "GET") {
    $sql = "SELECT sr.rate_id, sr.employee_id, sr.basic_salary, sr.rate_type, sr.effective_date,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   e.employee_number
            FROM salary_rates sr
            JOIN employees e ON e.employee_id = sr.employee_id
            ORDER BY e.last_name, e.first_name, sr.effective_date DESC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: create ----
if ($method === "POST") {
    $in      = json_input();
    $empId   = (int)   ($in["employee_id"]    ?? 0);
    $salary  = (float) ($in["basic_salary"]   ?? 0);
    $type    = trim($in["rate_type"]          ?? "Monthly");
    $effDate = trim($in["effective_date"]     ?? "");

    if ($empId === 0 || $salary <= 0 || $effDate === "") {
        respond(false, "Employee, Basic Salary, and Effective Date are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO salary_rates (employee_id, basic_salary, rate_type, effective_date)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$empId, $salary, $type, $effDate]);

    respond(true, "Salary rate added.", ["rate_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in      = json_input();
    $id      = (int)   ($in["rate_id"]        ?? 0);
    $empId   = (int)   ($in["employee_id"]    ?? 0);
    $salary  = (float) ($in["basic_salary"]   ?? 0);
    $type    = trim($in["rate_type"]          ?? "Monthly");
    $effDate = trim($in["effective_date"]     ?? "");

    if ($id === 0 || $empId === 0 || $salary <= 0 || $effDate === "") {
        respond(false, "All fields are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "UPDATE salary_rates
         SET employee_id = ?, basic_salary = ?, rate_type = ?, effective_date = ?
         WHERE rate_id = ?"
    );
    $stmt->execute([$empId, $salary, $type, $effDate, $id]);

    respond(true, "Salary rate updated.");
}

// ---- DELETE: hard delete (salary_rates has no is_active) ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);

    if ($id === 0) {
        respond(false, "Missing rate_id.", null, 422);
    }

    $stmt = $pdo->prepare("DELETE FROM salary_rates WHERE rate_id = ?");
    $stmt->execute([$id]);

    respond(true, "Salary rate deleted.");
}

respond(false, "Method not allowed.", null, 405);