<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read

$method = $_SERVER["REQUEST_METHOD"];

$fields = ["employee_number", "first_name", "last_name", "birth_date", "civil_status",
           "position_id", "department_id", "supervisor_id", "date_hired", "employment_status",
           "basic_salary",
           "tin_number", "sss_number", "philhealth_number", "pagibig_number",
           // Payment details: used by api/bank_file.php?format=bank. MAKER-writable
           // only (the guard below), and never returned by an employee-portal me_*
           // endpoint - the portal selects its own contact columns explicitly.
           "bank_name", "bank_account_name", "bank_account_number",
           "mobile_number", "email", "address",
           "emergency_contact_name", "emergency_contact_number"];

// ---- GET ----
if ($method === "GET") {
    if (isset($_GET["simple"])) {
        $rows = $pdo->query(
            "SELECT employee_id AS id, CONCAT(last_name, ', ', first_name) AS label
             FROM employees WHERE is_active = 1 ORDER BY last_name"
        )->fetchAll();
        respond(true, "", $rows);
    }

    $showAll = isset($_GET["all"]);
    $sql = "SELECT e.*, p.title AS position_title, d.department_name
            FROM employees e
            LEFT JOIN positions p ON p.position_id = e.position_id
            LEFT JOIN departments d ON d.department_id = e.department_id"
            . ($showAll ? "" : " WHERE e.is_active = 1")
            . " ORDER BY e.employee_id";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: create ----
requireRole(MAKER_ROLES);   // only the Payroll Officer may write master data

if ($method === "POST") {
    $in   = json_input();
    $vals = [];
    foreach ($fields as $f) $vals[$f] = trim((string) ($in[$f] ?? ""));

    if ($vals["employee_number"] === "" || $vals["first_name"] === "" || $vals["last_name"] === "") {
        respond(false, "Employee Number, First Name and Last Name are required.", null, 422);
    }
    if ($vals["basic_salary"] === "" || (float) $vals["basic_salary"] <= 0) {
        respond(false, "Basic Salary is required and must be greater than zero.", null, 422);
    }
    if ($vals["supervisor_id"] !== "") {
        $sup = $pdo->prepare(
            "SELECT 1 FROM users WHERE employee_id = ? AND role = 'Supervisor' AND is_active = 1"
        );
        $sup->execute([(int) $vals["supervisor_id"]]);
        if (!$sup->fetchColumn()) {
            respond(false, "The selected supervisor is not an active Supervisor account.", null, 422);
        }
    }

    try {
        $placeholders = implode(",", array_fill(0, count($fields), "?"));
        $sql = "INSERT INTO employees (" . implode(",", $fields) . ", is_active) VALUES ($placeholders, 1)";
        $stmt = $pdo->prepare($sql);
        // Optional inputs the form leaves blank arrive as "" - store NULL instead,
        // which an INT/DATE column would otherwise reject.
        $stmt->execute(array_map(fn($v) => $v === "" ? null : $v, array_values($vals)));
        respond(true, "Employee added.", ["employee_id" => (int) $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        respond(false, $e->getCode() === '23000'
            ? "Error: employee number may already exist."
            : "Error: the employee could not be saved. Please check the values entered.", null, 409);
    }
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in   = json_input();
    $id   = (int) ($in["employee_id"] ?? 0);
    $vals = [];
    foreach ($fields as $f) $vals[$f] = trim((string) ($in[$f] ?? ""));

    if ($id === 0 || $vals["employee_number"] === "" || $vals["first_name"] === "" || $vals["last_name"] === "") {
        respond(false, "Employee Number, First Name and Last Name are required.", null, 422);
    }
    if ($vals["basic_salary"] === "" || (float) $vals["basic_salary"] <= 0) {
        respond(false, "Basic Salary is required and must be greater than zero.", null, 422);
    }
    if ($vals["supervisor_id"] !== "") {
        if ((int) $vals["supervisor_id"] === $id) {
            respond(false, "An employee cannot be their own supervisor.", null, 422);
        }
        $sup = $pdo->prepare(
            "SELECT 1 FROM users WHERE employee_id = ? AND role = 'Supervisor' AND is_active = 1"
        );
        $sup->execute([(int) $vals["supervisor_id"]]);
        if (!$sup->fetchColumn()) {
            respond(false, "The selected supervisor is not an active Supervisor account.", null, 422);
        }
    }

    $currentStmt = $pdo->prepare("SELECT basic_salary FROM employees WHERE employee_id = ?");
    $currentStmt->execute([$id]);
    $currentSalary = $currentStmt->fetchColumn();
    if ($currentSalary === false) {
        respond(false, "Employee not found.", null, 404);
    }
    if (abs((float) $vals["basic_salary"] - (float) $currentSalary) >= 0.005) {
        respond(false,
            "Basic salary changes are restricted to the Approver. Use the salary change form.",
            null, 403);
    }

    try {
        $sql = "UPDATE employees SET " . implode(" = ?, ", $fields) . " = ? WHERE employee_id = ?";
        $stmt = $pdo->prepare($sql);
        $params = array_map(fn($v) => $v === "" ? null : $v, array_values($vals));
        $stmt->execute([...$params, $id]);
        respond(true, "Employee updated.");
    } catch (PDOException $e) {
        respond(false, $e->getCode() === '23000'
            ? "Error: employee number may already exist."
            : "Error: the employee could not be saved. Please check the values entered.", null, 409);
    }
}

// ---- PATCH: soft delete / restore ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int) ($in["employee_id"] ?? 0);
    $status = (int) ($in["is_active"]   ?? 1);

    if ($id === 0) {
        respond(false, "Missing employee_id.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE employees SET is_active = ? WHERE employee_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status ? "Employee restored." : "Employee deactivated.");
}

respond(false, "Method not allowed.", null, 405);