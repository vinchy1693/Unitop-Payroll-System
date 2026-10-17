<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only

$method = $_SERVER["REQUEST_METHOD"];

const OVERTIME_STATUSES = ["Pending", "Approved", "Rejected", "Completed"];

/**
 * Add the values the approval queue and the payslip care about:
 *   pending_hours  - hours already computed, or the live elapsed overtime
 *                    while an approved block is running; 0 before it starts
 *   is_payable     - the single definition payroll uses: approved/completed,
 *                    hours present, and both overtime timestamps present
 *   approved_by_name - who approved/rejected it
 */
function decorateOvertimeRow(array $row): array {
    $row["ot_id"]          = (int) $row["ot_id"];
    $row["employee_id"]    = (int) $row["employee_id"];
    $row["rate_multiplier"] = $row["rate_multiplier"] !== null ? (float) $row["rate_multiplier"] : 0.0;
    $row["hours"]          = $row["hours"] !== null ? (float) $row["hours"] : null;

    $hours = $row["hours"];
    if ($hours === null && $row["time_in"] !== null) {
        $inMinutes = ((int) substr($row["time_in"], 0, 2)) * 60 + (int) substr($row["time_in"], 3, 2);
        if ($row["time_out"] !== null) {
            $outMinutes = ((int) substr($row["time_out"], 0, 2)) * 60 + (int) substr($row["time_out"], 3, 2);
            $end = $outMinutes;
        } elseif (($row["ot_date"] ?? "") === date("Y-m-d")) {
            $end = ((int) date("H")) * 60 + (int) date("i");
        } else {
            $end = null;
        }
        if ($end !== null) {
            if ($end < $inMinutes) $end += 24 * 60;
            $hours = round(max(0, $end - $inMinutes) / 60, 2);
        }
    }
    $row["pending_hours"] = $hours !== null ? $hours : 0.0;

    $row["is_payable"] = in_array($row["status"], ["Approved", "Completed"], true)
        && $row["hours"] !== null && $row["hours"] > 0
        && $row["time_in"] !== null && $row["time_out"] !== null;

    return $row;
}

function overtimeSelect(): string {
    return "SELECT ot.*, e.employee_number,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   d.department_name,
                   u.username AS approved_by_name
            FROM overtime_records ot
            JOIN employees e ON e.employee_id = ot.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            LEFT JOIN users u ON u.user_id = ot.approved_by";
}

/** Parse ?status= into a validated list (single value or comma-separated). */
function statusFilter(): array {
    $raw = trim($_GET["status"] ?? "");
    if ($raw === "" || strcasecmp($raw, "all") === 0) return [];
    $out = [];
    foreach (explode(",", $raw) as $s) {
        $s = trim($s);
        if ($s === "") continue;
        $match = null;
        foreach (OVERTIME_STATUSES as $allowed) {
            if (strcasecmp($allowed, $s) === 0) { $match = $allowed; break; }
        }
        if ($match === null) {
            respond(false, "Unknown overtime status '$s'. Use one of: " . implode(", ", OVERTIME_STATUSES) . ".", null, 422);
        }
        $out[$match] = true;
    }
    return array_keys($out);
}

function statusSql(array $statuses, array &$params): string {
    if (!$statuses) return "";
    $params = array_merge($params, $statuses);
    return " AND ot.status IN (" . implode(",", array_fill(0, count($statuses), "?")) . ")";
}

// ---- GET ----
if ($method === "GET") {
    $statuses = statusFilter();

    // ?id=N - single record
    if (isset($_GET["id"])) {
        $id = (int) $_GET["id"];
        $stmt = $pdo->prepare(overtimeSelect() . " WHERE ot.ot_id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) respond(false, "OT record not found.", null, 404);
        respond(true, "", decorateOvertimeRow($row));
    }

    // ?date=YYYY-MM-DD - all OT records for a date (queue view)
    if (isset($_GET["date"])) {
        $date   = trim($_GET["date"]);
        $params = [$date];
        $sql    = overtimeSelect() . " WHERE ot.ot_date = ?" . statusSql($statuses, $params)
                . " ORDER BY e.last_name, e.first_name, ot.ot_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond(true, "", array_map("decorateOvertimeRow", $stmt->fetchAll()));
    }

    // ?employee_id=N&from=&to= - per-employee range
    if (isset($_GET["employee_id"])) {
        $empId  = (int) $_GET["employee_id"];
        $from   = trim($_GET["from"] ?? "1900-01-01");
        $to     = trim($_GET["to"]   ?? "2999-12-31");
        $params = [$empId, $from, $to];
        $sql    = overtimeSelect() . " WHERE ot.employee_id = ? AND ot.ot_date BETWEEN ? AND ?"
                . statusSql($statuses, $params)
                . " ORDER BY ot.ot_date DESC, ot.ot_id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond(true, "", array_map("decorateOvertimeRow", $stmt->fetchAll()));
    }

    // ?status=... (no other filter) - the whole approval queue
    if ($statuses) {
        $params = [];
        $sql    = overtimeSelect() . " WHERE 1=1" . statusSql($statuses, $params)
                . " ORDER BY ot.ot_date DESC, ot.ot_id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond(true, "", array_map("decorateOvertimeRow", $stmt->fetchAll()));
    }

    // ?all=1 - every request (queue with "All statuses" and no date)
    if (!empty($_GET["all"])) {
        $sql = overtimeSelect() . " ORDER BY ot.ot_date DESC, ot.ot_id DESC LIMIT 500";
        $rows = $pdo->query($sql)->fetchAll();
        respond(true, "", array_map("decorateOvertimeRow", $rows));
    }

    respond(false, "Missing query parameter (id, date, employee_id, status, or all).", null, 422);
}

// ---- POST: file a request (never a completed entry) ----
if ($method === "POST") {
    $in         = json_input();
    $empId      = (int)   ($in["employee_id"]     ?? 0);
    $otDate     = trim($in["ot_date"]             ?? "");
    $otType     = trim($in["ot_type"]             ?? "");
    $multiplier = (float) ($in["rate_multiplier"] ?? 0);

    if ($empId === 0 || $otDate === "" || $otType === "" || $multiplier <= 0) {
        respond(false, "Employee, OT date, OT type and a rate multiplier > 0 are required.", null, 422);
    }

    // One open request per employee per day. A finished block does not block
    // a new request later the same day.
    $stmt = $pdo->prepare(
        "SELECT ot_id FROM overtime_records
         WHERE employee_id = ? AND ot_date = ? AND status IN ('Pending','Approved')
         LIMIT 1"
    );
    $stmt->execute([$empId, $otDate]);
    if ($stmt->fetchColumn()) {
        respond(false, "This employee already has an open overtime request for that date.", null, 409);
    }

    // Hours and time_in/time_out are NOT accepted here: only an overtime
    // clock-in/clock-out can fill them.
    $stmt = $pdo->prepare(
        "INSERT INTO overtime_records
         (employee_id, ot_date, hours, ot_type, rate_multiplier,
          status, time_in, time_out, requested_at, approved_by, approved_at)
         VALUES (?, ?, NULL, ?, ?, 'Pending', NULL, NULL, NOW(), NULL, NULL)"
    );
    $stmt->execute([$empId, $otDate, $otType, $multiplier]);

    respond(true, "Overtime request submitted - waiting for approval.", [
        "ot_id"  => (int) $pdo->lastInsertId(),
        "status" => "Pending",
    ]);
}

// ---- PUT: edit a request that has not started yet ----
if ($method === "PUT") {
    $in         = json_input();
    $id         = (int)   ($in["ot_id"]            ?? 0);
    $otDate     = trim($in["ot_date"]              ?? "");
    $otType     = trim($in["ot_type"]              ?? "");
    $multiplier = (float) ($in["rate_multiplier"] ?? 0);

    if ($id === 0 || $otDate === "" || $otType === "" || $multiplier <= 0) {
        respond(false, "OT date, OT type and a rate multiplier > 0 are required.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT status, time_in FROM overtime_records WHERE ot_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, "OT record not found.", null, 404);
    if ($row["status"] === "Completed" || $row["time_in"] !== null) {
        respond(false, "Overtime that has already started cannot be edited.", null, 409);
    }

    $stmt = $pdo->prepare(
        "UPDATE overtime_records
         SET ot_date = ?, ot_type = ?, rate_multiplier = ?
         WHERE ot_id = ?"
    );
    $stmt->execute([$otDate, $otType, $multiplier, $id]);

    respond(true, "Overtime request updated.");
}

// ---- PATCH: approve / reject a Pending request ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int) ($in["ot_id"] ?? 0);
    $action = strtolower(trim($in["action"] ?? ""));
    $target = trim($in["status"] ?? "");

    if ($action === "approve")     $target = "Approved";
    elseif ($action === "reject")  $target = "Rejected";

    if ($id === 0) respond(false, "ot_id is required.", null, 422);
    if (!in_array($target, ["Approved", "Rejected"], true)) {
        respond(false, "action must be 'approve' or 'reject'.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT ot_id, status FROM overtime_records WHERE ot_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, "OT record not found.", null, 404);
    if ($row["status"] !== "Pending") {
        respond(false, "Only Pending requests can be approved or rejected. This one is {$row['status']}.", null, 409);
    }

    $user = currentUser();
    $pdo->prepare(
        "UPDATE overtime_records
         SET status = ?, approved_by = ?, approved_at = NOW()
         WHERE ot_id = ?"
    )->execute([$target, $user["user_id"], $id]);

    $stmt = $pdo->prepare(overtimeSelect() . " WHERE ot.ot_id = ?");
    $stmt->execute([$id]);
    $updated = decorateOvertimeRow($stmt->fetch());

    respond(true, $target === "Approved" ? "Overtime request approved." : "Overtime request rejected.", $updated);
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
