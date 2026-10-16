<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only

$method = $_SERVER["REQUEST_METHOD"];

function countBusinessDays(string $start, string $end): int {
    if ($start > $end) return 0;
    $cursor = new DateTime($start);
    $endEx  = (new DateTime($end))->modify('+1 day');
    $days = 0;
    while ($cursor < $endEx) {
        $dow = (int) $cursor->format('N');
        if ($dow <= 5) $days++;
        $cursor->modify('+1 day');
    }
    return $days;
}

// ---- GET ----
if ($method === "GET") {
    $status = $_GET["status"] ?? "";

    $sql = "SELECT lr.leave_id, lr.employee_id, lr.leave_type_id,
                   lr.start_date, lr.end_date, lr.status,
                   lt.name AS leave_type_name,
                   e.employee_number,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   d.department_name
            FROM leave_requests lr
            JOIN employees e ON e.employee_id = lr.employee_id
            JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
            LEFT JOIN departments d ON d.department_id = e.department_id";

    $params = [];
    if ($status !== "" && in_array($status, ["Pending","Approved","Rejected"], true)) {
        $sql .= " WHERE lr.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY lr.leave_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    respond(true, "", $stmt->fetchAll());
}

// ---- POST: file ----
if ($method === "POST") {
    $in     = json_input();
    $empId  = (int) ($in["employee_id"]   ?? 0);
    $typeId = (int) ($in["leave_type_id"] ?? 0);
    $start  = trim($in["start_date"] ?? "");
    $end    = trim($in["end_date"]   ?? "");

    if ($empId === 0 || $typeId === 0 || $start === "" || $end === "") {
        respond(false, "All fields are required.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End date must be on or after start date.", null, 422);
    }
    if (countBusinessDays($start, $end) === 0) {
        respond(false, "The selected range has no working days (Mon–Fri).", null, 422);
    }

    // No overlapping pending/approved requests
    $stmt = $pdo->prepare(
        "SELECT leave_id FROM leave_requests
         WHERE employee_id = ? AND status IN ('Pending','Approved')
           AND NOT (end_date < ? OR start_date > ?)"
    );
    $stmt->execute([$empId, $start, $end]);
    if ($stmt->fetchColumn()) {
        respond(false, "This employee already has a pending or approved leave overlapping those dates.", null, 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, status)
         VALUES (?, ?, ?, ?, 'Pending')"
    );
    $stmt->execute([$empId, $typeId, $start, $end]);

    respond(true, "Leave request filed.", ["leave_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update (Pending only) ----
if ($method === "PUT") {
    $in     = json_input();
    $id     = (int) ($in["leave_id"]      ?? 0);
    $empId  = (int) ($in["employee_id"]   ?? 0);
    $typeId = (int) ($in["leave_type_id"] ?? 0);
    $start  = trim($in["start_date"] ?? "");
    $end    = trim($in["end_date"]   ?? "");

    if ($id === 0 || $empId === 0 || $typeId === 0 || $start === "" || $end === "") {
        respond(false, "All fields are required.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End date must be on or after start date.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT status FROM leave_requests WHERE leave_id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current === false) respond(false, "Leave request not found.", null, 404);
    if ($current !== "Pending") {
        respond(false, "Only Pending requests can be edited.", null, 409);
    }

    $stmt = $pdo->prepare(
        "UPDATE leave_requests
         SET employee_id = ?, leave_type_id = ?, start_date = ?, end_date = ?
         WHERE leave_id = ?"
    );
    $stmt->execute([$empId, $typeId, $start, $end, $id]);

    respond(true, "Leave request updated.");
}

// ---- PATCH: approve / reject ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int)  ($in["leave_id"] ?? 0);
    $status = trim($in["status"] ?? "");

    if ($id === 0 || !in_array($status, ["Approved","Rejected"], true)) {
        respond(false, "Valid leave_id and status (Approved or Rejected) required.", null, 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT lr.leave_id, lr.employee_id, lr.leave_type_id,
                    lr.start_date, lr.end_date, lr.status,
                    lt.days_per_year
             FROM leave_requests lr
             JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
             WHERE lr.leave_id = ?"
        );
        $stmt->execute([$id]);
        $lr = $stmt->fetch();
        if (!$lr) {
            $pdo->rollBack();
            respond(false, "Leave request not found.", null, 404);
        }
        if ($lr["status"] !== "Pending") {
            $pdo->rollBack();
            respond(false, "Only Pending requests can change status.", null, 409);
        }

        $days  = countBusinessDays($lr["start_date"], $lr["end_date"]);
        $year  = (int) (new DateTime($lr["start_date"]))->format("Y");
        $empId = (int) $lr["employee_id"];
        $typeId = (int) $lr["leave_type_id"];

        // Approved → deduct from balance
        if ($status === "Approved") {
            $stmt = $pdo->prepare(
                "SELECT balance_id, remaining_days FROM leave_balances
                 WHERE employee_id = ? AND leave_type_id = ? AND year = ?"
            );
            $stmt->execute([$empId, $typeId, $year]);
            $bal = $stmt->fetch();

            if (!$bal) {
                $pdo->prepare(
                    "INSERT INTO leave_balances (employee_id, leave_type_id, year, remaining_days)
                     VALUES (?, ?, ?, ?)"
                )->execute([$empId, $typeId, $year, (float) $lr["days_per_year"]]);
                $remaining = (float) $lr["days_per_year"];
            } else {
                $remaining = (float) $bal["remaining_days"];
            }

            if ($days > $remaining) {
                $pdo->rollBack();
                respond(false, "Insufficient leave balance. Requested: $days day(s), Remaining: $remaining day(s).", null, 409);
            }

            $pdo->prepare(
                "UPDATE leave_balances
                 SET remaining_days = remaining_days - ?
                 WHERE employee_id = ? AND leave_type_id = ? AND year = ?"
            )->execute([$days, $empId, $typeId, $year]);
        }

        $pdo->prepare("UPDATE leave_requests SET status = ? WHERE leave_id = ?")
            ->execute([$status, $id]);

        $pdo->commit();
        respond(true, "Leave request $status. ($days working day(s))");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, "Failed: " . $e->getMessage(), null, 500);
    }
}

// ---- DELETE (Pending only) ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) respond(false, "Missing leave_id.", null, 422);

    $stmt = $pdo->prepare("SELECT status FROM leave_requests WHERE leave_id = ?");
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn();
    if ($status === false) respond(false, "Leave request not found.", null, 404);
    if ($status !== "Pending") {
        respond(false, "Only Pending requests can be deleted.", null, 409);
    }

    $pdo->prepare("DELETE FROM leave_requests WHERE leave_id = ?")->execute([$id]);
    respond(true, "Leave request deleted.");
}

respond(false, "Method not allowed.", null, 405);