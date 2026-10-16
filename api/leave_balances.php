<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: balance grid for a year ----
if ($method === "GET") {
    $year = (int) ($_GET["year"] ?? date("Y"));

    $employees = $pdo->query(
        "SELECT employee_id, employee_number,
                CONCAT(last_name, ', ', first_name) AS employee_name
         FROM employees WHERE is_active = 1
         ORDER BY last_name, first_name"
    )->fetchAll();

    $types = $pdo->query(
        "SELECT leave_type_id, name, days_per_year
         FROM leave_types
         WHERE is_active = 1
         ORDER BY leave_type_id"
    )->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT employee_id, leave_type_id, remaining_days
         FROM leave_balances WHERE year = ?"
    );
    $stmt->execute([$year]);
    $balRows = $stmt->fetchAll();

    $indexed = [];
    foreach ($balRows as $b) {
        $indexed[$b["employee_id"] . ":" . $b["leave_type_id"]] = (float) $b["remaining_days"];
    }

    $grid = [];
    foreach ($employees as $emp) {
        $row = [
            "employee_id"     => (int) $emp["employee_id"],
            "employee_number" => $emp["employee_number"],
            "employee_name"   => $emp["employee_name"],
            "balances"        => [],
        ];
        foreach ($types as $t) {
            $key = $emp["employee_id"] . ":" . $t["leave_type_id"];
            $row["balances"][] = [
                "leave_type_id" => (int) $t["leave_type_id"],
                "name"          => $t["name"],
                "days_per_year" => (float) $t["days_per_year"],
                "remaining"     => $indexed[$key] ?? (float) $t["days_per_year"],
                "from_db"       => array_key_exists($key, $indexed),
            ];
        }
        $grid[] = $row;
    }

    respond(true, "", [
        "year"      => $year,
        "types"     => $types,
        "employees" => $grid,
    ]);
}

// ---- POST: initialize balances for the year ----
if ($method === "POST") {
    $in   = json_input();
    $year = (int) ($in["year"] ?? date("Y"));

    if ($year < 2000) {
        respond(false, "Invalid year.", null, 422);
    }

    $employees = $pdo->query("SELECT employee_id FROM employees WHERE is_active = 1")->fetchAll();
    $types     = $pdo->query("SELECT leave_type_id, days_per_year FROM leave_types WHERE is_active = 1")->fetchAll();

    $inserted = 0;
    $stmt = $pdo->prepare(
        "INSERT INTO leave_balances (employee_id, leave_type_id, year, remaining_days)
         SELECT ?, ?, ?, ?
         FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM leave_balances
             WHERE employee_id = ? AND leave_type_id = ? AND year = ?
         )"
    );

    foreach ($employees as $e) {
        foreach ($types as $t) {
            $stmt->execute([
                $e["employee_id"], $t["leave_type_id"], $year, (float) $t["days_per_year"],
                $e["employee_id"], $t["leave_type_id"], $year,
            ]);
            if ($stmt->rowCount() > 0) $inserted++;
        }
    }

    respond(true, "Initialized $inserted new balance row(s) for $year.");
}

respond(false, "Method not allowed.", null, 405);