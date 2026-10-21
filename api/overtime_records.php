<?php
// =====================================================================
// api/overtime_records.php
//
// Routes:
//   GET   TEAM_QUEUE_ROLES  (maker + checker see the whole queue; a Supervisor
//                            sees only their own team's rows - a filter)
//   POST                   SUPERVISOR_ROLE only, for a team member
//   PATCH                  CHECKER_ROLES only (the Approver decides)
//   PUT / DELETE           MAKER_ROLES only (maintenance, never creation)
// =====================================================================
require __DIR__ . "/_bootstrap.php";
require __DIR__ . "/_payroll_helpers.php";

requireRole(TEAM_QUEUE_ROLES);   // maker + checker + supervisor may read

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
    global $pdo;

    $row["ot_id"]          = (int) $row["ot_id"];
    $row["employee_id"]    = (int) $row["employee_id"];
    $row["hours"]          = $row["hours"] !== null ? (float) $row["hours"] : null;

    // Old rows (before migration 013) have no day_type; derive it from the date.
    if (empty($row["day_type"]) && !empty($row["ot_date"])) {
        $row["day_type"] = dayTypeForDate($pdo, (string) $row["ot_date"]);
    }
    $statutory = overtimeMultiplierForDayType((string) ($row["day_type"] ?? "Ordinary Day"));
    $row["statutory_multiplier"] = $statutory;
    $row["rate_multiplier"] = max((float) ($row["rate_multiplier"] ?? 0), $statutory);

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
            $minutes = max(0, $end - $inMinutes);
            // Same meal-break rule the clock-out uses for the final hours.
            if ($minutes > 300) $minutes -= 60;
            $hours = round($minutes / 60, 2);
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
                   u.username AS approved_by_name,
                   rb.username AS requested_by_name
            FROM overtime_records ot
            JOIN employees e ON e.employee_id = ot.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            LEFT JOIN users u ON u.user_id = ot.approved_by
            LEFT JOIN users rb ON rb.user_id = ot.requested_by";
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

    // Team scope. A Supervisor only ever sees overtime filed by their own
    // team; the maker and the checker keep the full queue. A Supervisor who
    // supervises nobody gets an empty list (200), never an error.
    $scope = teamScopeIds($pdo);
    if ($scope === []) {
        respond(true, "", []);
    }
    $scopeSql    = $scope !== null
        ? " AND ot.employee_id IN (" . implode(",", array_fill(0, count($scope), "?")) . ")"
        : "";
    $scopeParams = $scope ?? [];

    // ?id=N - single record
    if (isset($_GET["id"])) {
        $id = (int) $_GET["id"];
        $stmt = $pdo->prepare(overtimeSelect() . " WHERE ot.ot_id = ?" . $scopeSql);
        $stmt->execute(array_merge([$id], $scopeParams));
        $row = $stmt->fetch();
        if (!$row) respond(false, "OT record not found.", null, 404);
        respond(true, "", decorateOvertimeRow($row));
    }

    // ?date=YYYY-MM-DD - all OT records for a date (queue view)
    if (isset($_GET["date"])) {
        $date   = trim($_GET["date"]);
        $params = [$date];
        $sql    = overtimeSelect() . " WHERE ot.ot_date = ?" . statusSql($statuses, $params)
                . $scopeSql
                . " ORDER BY e.last_name, e.first_name, ot.ot_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $scopeParams));
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
                . $scopeSql
                . " ORDER BY ot.ot_date DESC, ot.ot_id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $scopeParams));
        respond(true, "", array_map("decorateOvertimeRow", $stmt->fetchAll()));
    }

    // ?status=... (no other filter) - the whole approval queue
    if ($statuses) {
        $params = [];
        $sql    = overtimeSelect() . " WHERE 1=1" . statusSql($statuses, $params)
                . $scopeSql
                . " ORDER BY ot.ot_date DESC, ot.ot_id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $scopeParams));
        respond(true, "", array_map("decorateOvertimeRow", $stmt->fetchAll()));
    }

    // ?all=1 - every request (queue with "All statuses" and no date)
    if (!empty($_GET["all"])) {
        $sql = overtimeSelect() . " WHERE 1=1" . $scopeSql
             . " ORDER BY ot.ot_date DESC, ot.ot_id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scopeParams);
        respond(true, "", array_map("decorateOvertimeRow", $stmt->fetchAll()));
    }

    respond(false, "Missing query parameter (id, date, employee_id, status, or all).", null, 422);
}

// ---- POST: a Supervisor files a request for a team member ----
// Only a Supervisor may file; the employee portal is read-only. The Approver
// decides it. A Supervisor may also decide a team request, but never one they
// filed themselves.
if ($method === "POST") {
    requireRole(MANAGER_ROLE);
} elseif ($method === "PATCH") {
    requireRole(SUPERVISOR_ROLE);
} else {
    requireRole(MAKER_ROLES);   // PUT / DELETE maintenance only
}

/** HH:MM or HH:MM:SS -> HH:MM:SS, or null when it is not a valid time. */
function normalizeOtTime($value): ?string {
    $value = trim((string) ($value ?? ""));
    if ($value === "") return null;
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $value, $m)) {
        return null;
    }
    return sprintf("%s:%s:%s", $m[1], $m[2], $m[3] ?? "00");
}

if ($method === "POST") {
    $in       = json_input();
    $empId    = (int)  ($in["employee_id"] ?? 0);
    $otDate   = trim($in["ot_date"] ?? "");
    $reason   = trim($in["reason"] ?? "");
    $reqStart = normalizeOtTime($in["requested_start"] ?? null);
    $reqEnd   = normalizeOtTime($in["requested_end"] ?? null);

    if ($empId === 0 || $otDate === "") {
        respond(false, "Employee and OT date are required.", null, 422);
    }

    assertManages($pdo, $empId, "file overtime for this employee");
    $empStmt = $pdo->prepare("SELECT is_active FROM employees WHERE employee_id = ?");
    $empStmt->execute([$empId]);
    $empActive = $empStmt->fetchColumn();
    if ($empActive === false) {
        respond(false, "Employee not found.", null, 404);
    }
    if ((int) $empActive === 0) {
        respond(false, "This employee is inactive; no overtime request can be filed.", null, 422);
    }

    $dateCheck = DateTime::createFromFormat("Y-m-d", $otDate);
    if (!$dateCheck || $dateCheck->format("Y-m-d") !== $otDate) {
        respond(false, "ot_date must be a real date in YYYY-MM-DD form.", null, 422);
    }
    if (strlen($reason) < 10) {
        respond(false, "A reason of at least 10 characters is required for an overtime request.", null, 422);
    }
    if (strlen($reason) > 500) {
        respond(false, "The reason must be at most 500 characters.", null, 422);
    }
    if ($reqStart === null || $reqEnd === null) {
        respond(false, "Requested start and end times are required (HH:MM).", null, 422);
    }
    if (strcmp($reqEnd, $reqStart) <= 0) {
        respond(false, "The requested end must be after the requested start.", null, 422);
    }

    assertAttendanceDateUnlocked($pdo, $otDate, "Overtime for this date");

    // The day type and the rate are derived on the server from the date and the
    // holidays table. A client-supplied multiplier is ignored, so a rest day or
    // holiday can never be stored as an ordinary-day 1.25 request.
    $dayType    = dayTypeForDate($pdo, $otDate);
    $multiplier = overtimeMultiplierForDayType($dayType);

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
    // clock-in/clock-out can fill them. requested_by records the Supervisor
    // who filed it; approved_by is filled by the Approver later.
    $requesterId = (int) (currentUser()["user_id"] ?? 0);
    $stmt = $pdo->prepare(
        "INSERT INTO overtime_records
         (employee_id, ot_date, hours, ot_type, rate_multiplier, reason,
          requested_start, requested_end, day_type, status, time_in, time_out,
          requested_at, requested_by, approved_by, approved_at)
         VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 'Pending', NULL, NULL, NOW(), ?, NULL, NULL)"
    );
    $stmt->execute([
        $empId, $otDate, $dayType, $multiplier, $reason,
        $reqStart, $reqEnd, $dayType, $requesterId,
    ]);

    respond(true, "Overtime request submitted - waiting for approval.", [
        "ot_id"           => (int) $pdo->lastInsertId(),
        "status"          => "Pending",
        "day_type"        => $dayType,
        "rate_multiplier" => $multiplier,
    ]);
}

// ---- PUT: edit a request that has not started yet ----
if ($method === "PUT") {
    $in       = json_input();
    $id       = (int)  ($in["ot_id"] ?? 0);
    $otDate   = trim($in["ot_date"] ?? "");
    $reason   = trim($in["reason"] ?? "");
    $reqStart = normalizeOtTime($in["requested_start"] ?? null);
    $reqEnd   = normalizeOtTime($in["requested_end"] ?? null);

    if ($id === 0 || $otDate === "") {
        respond(false, "OT date is required.", null, 422);
    }
    $dateCheck = DateTime::createFromFormat("Y-m-d", $otDate);
    if (!$dateCheck || $dateCheck->format("Y-m-d") !== $otDate) {
        respond(false, "ot_date must be a real date in YYYY-MM-DD form.", null, 422);
    }
    if (strlen($reason) < 10) {
        respond(false, "A reason of at least 10 characters is required for an overtime request.", null, 422);
    }
    if (strlen($reason) > 500) {
        respond(false, "The reason must be at most 500 characters.", null, 422);
    }
    if ($reqStart === null || $reqEnd === null) {
        respond(false, "Requested start and end times are required (HH:MM).", null, 422);
    }
    if (strcmp($reqEnd, $reqStart) <= 0) {
        respond(false, "The requested end must be after the requested start.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT status, time_in, employee_id, ot_date FROM overtime_records WHERE ot_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, "OT record not found.", null, 404);
    if ($row["status"] === "Completed" || $row["time_in"] !== null) {
        respond(false, "Overtime that has already started cannot be edited.", null, 409);
    }

    assertAttendanceDateUnlocked($pdo, (string) $row["ot_date"], "Overtime for this date");
    assertAttendanceDateUnlocked($pdo, $otDate, "Overtime for this date");

    $stmt = $pdo->prepare(
        "SELECT ot_id FROM overtime_records
         WHERE employee_id = ? AND ot_date = ? AND status IN ('Pending','Approved')
           AND ot_id <> ? LIMIT 1"
    );
    $stmt->execute([(int) $row["employee_id"], $otDate, $id]);
    if ($stmt->fetchColumn()) {
        respond(false, "This employee already has an open overtime request for that date.", null, 409);
    }

    $dayType    = dayTypeForDate($pdo, $otDate);
    $multiplier = overtimeMultiplierForDayType($dayType);

    $stmt = $pdo->prepare(
        "UPDATE overtime_records
         SET ot_date = ?, ot_type = ?, rate_multiplier = ?, reason = ?,
             requested_start = ?, requested_end = ?, day_type = ?
         WHERE ot_id = ?"
    );
    $stmt->execute([$otDate, $dayType, $multiplier, $reason, $reqStart, $reqEnd, $dayType, $id]);

    respond(true, "Overtime request updated.", [
        "day_type"        => $dayType,
        "rate_multiplier" => $multiplier,
    ]);
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

    $stmt = $pdo->prepare("SELECT ot_id, employee_id, ot_date, requested_by, status FROM overtime_records WHERE ot_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, "OT record not found.", null, 404);

    assertNotOwnEmployee((int) $row["employee_id"], "approve or reject this overtime request");
    assertAttendanceDateUnlocked($pdo, (string) $row["ot_date"], "Overtime for this date");

    if (isSupervisor()) {
        assertSupervises($pdo, (int) $row["employee_id"], "approve or reject this overtime request");
        $actor = currentUser();
        if ((int) ($row["requested_by"] ?? 0) === (int) ($actor["user_id"] ?? 0)) {
            respond(
                false,
                "You cannot approve or reject an overtime request you filed yourself. "
                . "Another Supervisor must decide it.",
                null,
                403
            );
        }
    }

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

    $stmt = $pdo->prepare("SELECT ot_date FROM overtime_records WHERE ot_id = ?");
    $stmt->execute([$id]);
    $otDate = $stmt->fetchColumn();
    if ($otDate === false) {
        respond(false, "Overtime record not found.", null, 404);
    }
    assertAttendanceDateUnlocked($pdo, (string) $otDate, "Overtime for this date");

    $pdo->prepare("DELETE FROM overtime_records WHERE ot_id = ?")->execute([$id]);
    respond(true, "Overtime record deleted.");
}

respond(false, "Method not allowed.", null, 405);
