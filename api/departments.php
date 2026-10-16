<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list (or ?simple=1 for dropdown options, ?all=1 for incl. inactive) ----
if ($method === "GET") {
    if (isset($_GET["simple"])) {
        $rows = $pdo->query(
            "SELECT department_id AS id, department_name AS label
             FROM departments WHERE is_active = 1 ORDER BY department_name"
        )->fetchAll();
        respond(true, "", $rows);
    }

    $showAll = isset($_GET["all"]);
    $sql = "SELECT d.*, e.first_name AS manager_first_name, e.last_name AS manager_last_name
            FROM departments d
            LEFT JOIN employees e ON e.employee_id = d.manager_employee_id"
            . ($showAll ? "" : " WHERE d.is_active = 1")
            . " ORDER BY d.department_id";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r["manager_name"] = $r["manager_first_name"]
            ? $r["manager_last_name"] . ", " . $r["manager_first_name"]
            : null;
    }
    respond(true, "", $rows);
}

// ---- POST: create ----
if ($method === "POST") {
    $in      = json_input();
    $name    = trim($in["department_name"] ?? "");
    $manager = ($in["manager_employee_id"] ?? "") !== "" ? (int) $in["manager_employee_id"] : null;

    if ($name === "") {
        respond(false, "Department name is required.", null, 422);
    }

    $stmt = $pdo->prepare("INSERT INTO departments (department_name, manager_employee_id, is_active) VALUES (?, ?, 1)");
    $stmt->execute([$name, $manager]);

    respond(true, "Department added.", ["department_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in      = json_input();
    $id      = (int) ($in["department_id"] ?? 0);
    $name    = trim($in["department_name"] ?? "");
    $manager = ($in["manager_employee_id"] ?? "") !== "" ? (int) $in["manager_employee_id"] : null;

    if ($id === 0 || $name === "") {
        respond(false, "Department name is required.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE departments SET department_name = ?, manager_employee_id = ? WHERE department_id = ?");
    $stmt->execute([$name, $manager, $id]);

    respond(true, "Department updated.");
}

// ---- PATCH: soft delete / restore (toggle is_active) ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int) ($in["department_id"] ?? 0);
    $status = (int) ($in["is_active"] ?? 1);

    if ($id === 0) {
        respond(false, "Missing department_id.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE departments SET is_active = ? WHERE department_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status ? "Department restored." : "Department deactivated.");
}

respond(false, "Method not allowed.", null, 405);
