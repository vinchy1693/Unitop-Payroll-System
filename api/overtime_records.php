<?php
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET ----
if ($method === "GET") {
    // ?id=N — single record
    if (isset($_GET["id"])) {
        $id = (int) $_GET["id"];
        $stmt = $pdo->prepare(
            "SELECT ot.*, e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    d.department_name
             FROM overtime_records ot
             JOIN employees e ON e.employee_id = ot.employee_id
             LEFT JOIN departments d ON d.department_id = e.department_id
             WHERE ot.ot_id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) respond(false, "OT record not found.", null, 404);
        respond(true, "", $row);
    }

    // ?date=YYYY-MM-DD — all OT records for a date
    if (isset($_GET["date"])) {
        $date = trim($_GET["date"]);
        $stmt = $pdo->prepare(
            "SELECT ot.*, e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    d.department_name
             FROM overtime_records ot
             JOIN employees e ON e.employee_id = ot.employee_id
             LEFT JOIN departments d ON d.department_id = e.department_id
             WHERE ot.ot_date = ?
             ORDER BY e.last_name, e.first_name"
        );
        $stmt->execute([$date]);
        respond(true, "", $stmt->fetchAll());
    }

    // ?employee_id=N&from=&to= — per-employee range
    if (isset($_GET["employee_id"])) {
        $empId = (int) $_GET["employee_id"];
        $from  = trim($_GET["from"] ?? "1900-01-01");
        $to    = trim($_GET["to"]   ?? "2999-12-31");
        $stmt = $pdo->prepare(
            "SELECT ot.*, e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name
             FROM overtime_records ot
             JOIN employees e ON e.employee_id = ot.employee_id
             WHERE ot.employee_id = ? AND ot.ot_date BETWEEN ? AND ?
             ORDER BY ot.ot_date DESC"
        );
        $stmt->execute([$empId, $from, $to]);
        respond(true, "", $stmt->fetchAll());
    }

    respond(false, "Missing query parameter (id, date, or employee_id).", null, 422);
}

// ---- POST: create ----
if ($method === "POST") {
    $in        = json_input();
    $empId     = (int)   ($in["employee_id"]     ?? 0);
    $otDate    = trim($in["ot_date"]             ?? "");
    $hours     = (float) ($in["hours"]           ?? 0);
    $otType    = trim($in["ot_type"]             ?? "");
    $multiplier = (float) ($in["rate_multiplier"] ?? 0);

    if ($empId === 0 || $otDate === "" || $hours <= 0 || $otType === "" || $multiplier <= 0) {
        respond(false, "All fields are required and hours/multiplier must be > 0.", null, 422);
    }

    // One OT record per employee per day per type
    $stmt = $pdo->prepare(
        "SELECT ot_id FROM overtime_records
         WHERE employee_id = ? AND ot_date = ? AND ot_type = ?"
    );
    $stmt->execute([$empId, $otDate, $otType]);
    if ($stmt->fetchColumn()) {
        respond(false, "This employee already has a '$otType' record for that date.", null, 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO overtime_records (employee_id, ot_date, hours, ot_type, rate_multiplier)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$empId, $otDate, $hours, $otType, $multiplier]);

    respond(true, "Overtime recorded.", ["ot_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in        = json_input();
    $id        = (int)   ($in["ot_id"]            ?? 0);
    $empId     = (int)   ($in["employee_id"]      ?? 0);
    $otDate    = trim($in["ot_date"]              ?? "");
    $hours     = (float) ($in["hours"]            ?? 0);
    $otType    = trim($in["ot_type"]              ?? "");
    $multiplier = (float) ($in["rate_multiplier"] ?? 0);

    if ($id === 0 || $empId === 0 || $otDate === "" || $hours <= 0 || $otType === "" || $multiplier <= 0) {
        respond(false, "All fields are required and hours/multiplier must be > 0.", null, 422);
    }

    $stmt = $pdo->prepare(
        "UPDATE overtime_records
         SET employee_id = ?, ot_date = ?, hours = ?, ot_type = ?, rate_multiplier = ?
         WHERE ot_id = ?"
    );
    $stmt->execute([$empId, $otDate, $hours, $otType, $multiplier, $id]);

    respond(true, "Overtime updated.");
}

// ---- DELETE ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) {
        respond(false, "Missing ot_id.", null, 422);
    }
    $pdo->prepare("DELETE FROM overtime_records WHERE ot_id = ?")->execute([$id]);
    respond(true, "Overtime record deleted.");
}

respond(false, "Method not allowed.", null, 405);