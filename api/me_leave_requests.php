<?php
require __DIR__ . "/_bootstrap.php";

$user = currentUser();
if (!$user || !$user["employee_id"]) {
    respond(false, "Your account is not linked to an employee record.", null, 403);
}

$method    = $_SERVER["REQUEST_METHOD"];
$empId     = $user["employee_id"];

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

// ---- GET: own leave requests ----
if ($method === "GET") {
    $stmt = $pdo->prepare(
        "SELECT lr.leave_id, lr.leave_type_id, lr.start_date, lr.end_date, lr.status,
                lt.name AS leave_type_name
         FROM leave_requests lr
         JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
         WHERE lr.employee_id = ?
         ORDER BY lr.leave_id DESC"
    );
    $stmt->execute([$empId]);
    respond(true, "", $stmt->fetchAll());
}

// ---- POST: file own leave request ----
if ($method === "POST") {
    $in     = json_input();
    $typeId = (int) ($in["leave_type_id"] ?? 0);
    $start  = trim($in["start_date"] ?? "");
    $end    = trim($in["end_date"]   ?? "");

    if ($typeId === 0 || $start === "" || $end === "") {
        respond(false, "Leave type, start date, and end date are required.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End date must be on or after start date.", null, 422);
    }
    // A single-day request is always allowed (see api/leave_requests.php); only
    // multi-day ranges must contain a working day.
    if ($start !== $end && countBusinessDays($start, $end) === 0) {
        respond(false, "The selected range has no working days.", null, 422);
    }

    // No overlap with own pending/approved requests
    $stmt = $pdo->prepare(
        "SELECT leave_id FROM leave_requests
         WHERE employee_id = ? AND status IN ('Pending','Approved')
           AND NOT (end_date < ? OR start_date > ?)"
    );
    $stmt->execute([$empId, $start, $end]);
    if ($stmt->fetchColumn()) {
        respond(false, "You already have a pending or approved leave overlapping those dates.", null, 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, status)
         VALUES (?, ?, ?, ?, 'Pending')"
    );
    $stmt->execute([$empId, $typeId, $start, $end]);

    respond(true, "Leave request filed. Waiting for HR approval.", ["leave_id" => (int) $pdo->lastInsertId()]);
}

// ---- PATCH: cancel own pending request ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int)  ($in["leave_id"] ?? 0);
    $status = trim($in["status"] ?? "");

    if ($id === 0 || $status !== "Cancelled") {
        respond(false, "Valid leave_id and status 'Cancelled' required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "SELECT status, employee_id FROM leave_requests WHERE leave_id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(false, "Leave request not found.", null, 404);
    if ((int) $row["employee_id"] !== $empId) {
        respond(false, "You can only cancel your own leave requests.", null, 403);
    }
    if ($row["status"] !== "Pending") {
        respond(false, "Only Pending requests can be cancelled.", null, 409);
    }

    $pdo->prepare("UPDATE leave_requests SET status = 'Cancelled' WHERE leave_id = ?")
        ->execute([$id]);

    respond(true, "Leave request cancelled.");
}

respond(false, "Method not allowed.", null, 405);