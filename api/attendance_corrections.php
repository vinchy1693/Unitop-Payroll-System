<?php
// =====================================================================
// api/attendance_corrections.php
//
// Attendance correction requests (segregation of duties, step 5).
//
// An employee files a correction from the portal when a record is wrong or
// missing (forgot to scan out, scanned at the wrong time). This is the ADMIN
// side of that queue:
//
//   GET   list  - filters ?status= ?employee_id= ?from= ?to= , paginated
//   PATCH       - {correction_id, action: "approve"|"reject", remarks?}
//
// Roles:
//   GET   TEAM_QUEUE_ROLES  (maker + checker see the whole queue; a Supervisor
//                            sees only their own team's rows - a filter, not a
//                            403, so the queue screen loads for them)
//   PATCH MAKER_ROLES       (attendance is the maker's domain - the checker
//                            never writes payroll data)
//   PATCH + Supervisor      for a TEAM MEMBER's request only. The own-request
//                           rule and the team test both live in
//                           assertSupervises() (api/_bootstrap.php).
//
// Approving APPLIES the correction to the attendance table inside a
// transaction: the row is created when it does not exist yet, otherwise its
// time_in / time_out are updated per correction_type, and hours_worked /
// minutes_late are recomputed with the SAME rules the kiosk uses (see the
// helpers below - they are a literal copy of the arithmetic in
// api/scan_attendance.php, not a second convention).
//
// A date that falls inside an Approved / Released payroll run is refused with
// 409: the figures behind that payslip were signed off and only a formal
// adjustment may change them (assertAttendanceDateUnlocked()).
// =====================================================================

require __DIR__ . "/_bootstrap.php";
require __DIR__ . "/_payroll_helpers.php";
require __DIR__ . "/_attendance_policy.php";

$method = $_SERVER["REQUEST_METHOD"];

const CORRECTION_STATUSES = ["Pending", "Approved", "Rejected"];
const CORRECTION_TYPES    = ["clock_in", "clock_out", "both"];

// =====================================================================
// Scan-rule recomputation.
//
// api/scan_attendance.php, clock IN (lines 99-105):
//     $workStart   = new DateTime("08:00:00");
//     $actualTime  = new DateTime($nowTime);
//     $minutesLate = 0;
//     if ($actualTime > $workStart) {
//         $diff = $workStart->diff($actualTime);
//         $minutesLate = ($diff->h * 60) + $diff->i;
//     }
//
// api/scan_attendance.php, clock OUT (lines 125-137):
//     $timeIn  = new DateTime($existing["time_in"]);
//     $timeOut = new DateTime($nowTime);
//     $diff    = $timeIn->diff($timeOut);
//     $totalMinutes = ($diff->h * 60) + $diff->i;
//     $netMinutes  = $totalMinutes > 300 ? $totalMinutes - 60 : $totalMinutes;
//     $hoursWorked = round($netMinutes / 60, 2);
//
// So: minutes_late counts whole minutes past 08:00 (0 when at or before
// 08:00), and hours_worked drops one 60-minute lunch only when the in/out
// span exceeds 300 minutes. Seconds are ignored in both, exactly like the
// kiosk. Keep these two functions in step with that file.
// =====================================================================
const SCAN_LUNCH_THRESHOLD    = 300;   // minutes
const SCAN_LUNCH_DEDUCTION    = 60;    // minutes

/** Same grace-period rule as the kiosk and manual attendance. */
function scanMinutesLate(string $timeIn): int {
    return attendanceLateMinutes($timeIn);
}

/** Hours between two times, less the 60-minute lunch when the span > 300 min. */
function scanHoursWorked(string $timeIn, string $timeOut): float {
    $diff         = (new DateTime($timeIn))->diff(new DateTime($timeOut));
    $totalMinutes = ($diff->h * 60) + $diff->i;
    $netMinutes   = $totalMinutes > SCAN_LUNCH_THRESHOLD
        ? $totalMinutes - SCAN_LUNCH_DEDUCTION
        : $totalMinutes;
    return round($netMinutes / 60, 2);
}

/** HH:MM or HH:MM:SS -> HH:MM:SS, or null when it is not a valid time. */
function normalizeTime(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === "") return null;
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $value, $m)) {
        return null;
    }
    return sprintf("%s:%s:%s", $m[1], $m[2], $m[3] ?? "00");
}

// =====================================================================
// Shared row shape
// =====================================================================

/**
 * One correction with everything the queue shows: who filed it, the current
 * attendance times it is up against, the requested times, and who decided it.
 *
 * The attendance join uses c.attendance_id, falling back to the newest row for
 * that employee + date, so a correction filed before the row existed still
 * shows what the employee is changing.
 */
function correctionsSelect(): string {
    return "SELECT c.correction_id, c.employee_id, c.attendance_id, c.work_date,
                   c.correction_type, c.requested_time_in, c.requested_time_out,
                   c.reason, c.status, c.requested_at,
                   c.reviewed_by, c.reviewed_at, c.review_remarks,
                   e.employee_number,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   d.department_name,
                   a.attendance_id AS current_attendance_id,
                   a.time_in       AS current_time_in,
                   a.time_out      AS current_time_out,
                   a.hours_worked  AS current_hours_worked,
                   a.minutes_late  AS current_minutes_late,
                   a.status        AS current_status,
                   ru.username     AS reviewed_by_name
            FROM attendance_corrections c
            JOIN employees e ON e.employee_id = c.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            LEFT JOIN attendance a
                   ON a.attendance_id = COALESCE(c.attendance_id,
                        (SELECT a2.attendance_id
                         FROM attendance a2
                         WHERE a2.employee_id = c.employee_id
                           AND a2.work_date   = c.work_date
                         ORDER BY a2.attendance_id DESC
                         LIMIT 1))
            LEFT JOIN users ru ON ru.user_id = c.reviewed_by";
}

/** Cast the row for JSON and add the two facts the UI reasons about. */
function decorateCorrection(array $row): array {
    $row["correction_id"] = (int) $row["correction_id"];
    $row["employee_id"]   = (int) $row["employee_id"];
    $row["attendance_id"] = $row["attendance_id"] !== null ? (int) $row["attendance_id"] : null;
    $row["reviewed_by"]   = $row["reviewed_by"] !== null ? (int) $row["reviewed_by"] : null;

    $row["has_attendance"] = $row["current_attendance_id"] !== null;
    $row["current_attendance_id"] = $row["current_attendance_id"] !== null
        ? (int) $row["current_attendance_id"]
        : null;
    $row["current_hours_worked"] = $row["current_hours_worked"] !== null
        ? (float) $row["current_hours_worked"]
        : null;
    $row["current_minutes_late"] = $row["current_minutes_late"] !== null
        ? (int) $row["current_minutes_late"]
        : null;

    return $row;
}

/** Re-read one correction in its queue shape. */
function correctionById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(correctionsSelect() . " WHERE c.correction_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? decorateCorrection($row) : null;
}

/** YYYY-MM-DD or null. */
function correctionFilterDate(string $value): ?string {
    $value = trim($value);
    if ($value === "") return null;
    $dt = DateTime::createFromFormat("!Y-m-d", $value);
    if (!$dt || $dt->format("Y-m-d") !== $value) return null;
    return $value;
}

// =====================================================================
// GET: the queue
// =====================================================================
if ($method === "GET") {
    requireRole([MANAGER_ROLE, SUPERVISOR_ROLE]);   // team leads review corrections

    $limit = isset($_GET["limit"]) && $_GET["limit"] !== "" ? (int) $_GET["limit"] : 100;
    if ($limit < 1)   $limit = 100;
    if ($limit > 500) $limit = 500;

    $offset = isset($_GET["offset"]) && $_GET["offset"] !== "" ? (int) $_GET["offset"] : 0;
    if ($offset < 0) $offset = 0;

    $where  = [];
    $params = [];

    // Team scope. A Supervisor only ever sees corrections filed by their own
    // team; the maker and the checker keep the whole queue. A Supervisor who
    // supervises nobody gets an empty queue (200), never an error.
    $scope = teamScopeIds($pdo);
    if ($scope !== null) {
        if (!$scope) {
            respond(true, "", ["rows" => [], "total" => 0, "limit" => $limit, "offset" => $offset]);
        }
        $where[] = "c.employee_id IN (" . implode(",", array_fill(0, count($scope), "?")) . ")";
        $params  = array_merge($params, $scope);
    }

    // ?status=Pending | Pending,Approved | all
    $rawStatus = trim((string) ($_GET["status"] ?? ""));
    if ($rawStatus !== "" && strcasecmp($rawStatus, "all") !== 0) {
        $wanted = [];
        foreach (explode(",", $rawStatus) as $piece) {
            $piece = trim($piece);
            if ($piece === "") continue;
            $match = null;
            foreach (CORRECTION_STATUSES as $allowed) {
                if (strcasecmp($allowed, $piece) === 0) { $match = $allowed; break; }
            }
            if ($match === null) {
                respond(false,
                    "Unknown correction status '$piece'. Use one of: "
                    . implode(", ", CORRECTION_STATUSES) . ".", null, 422);
            }
            $wanted[$match] = true;
        }
        $wanted = array_keys($wanted);
        if ($wanted) {
            $where[] = "c.status IN (" . implode(",", array_fill(0, count($wanted), "?")) . ")";
            $params = array_merge($params, $wanted);
        }
    }

    $employeeId = isset($_GET["employee_id"]) && $_GET["employee_id"] !== ""
        ? (int) $_GET["employee_id"]
        : 0;
    if ($employeeId > 0) {
        $where[]  = "c.employee_id = ?";
        $params[] = $employeeId;
    }

    $from = array_key_exists("from", $_GET) ? correctionFilterDate((string) $_GET["from"]) : null;
    if (array_key_exists("from", $_GET) && trim((string) $_GET["from"]) !== "" && $from === null) {
        respond(false, "from must be a valid date (YYYY-MM-DD).", null, 422);
    }
    if ($from !== null) {
        $where[]  = "c.work_date >= ?";
        $params[] = $from;
    }

    $to = array_key_exists("to", $_GET) ? correctionFilterDate((string) $_GET["to"]) : null;
    if (array_key_exists("to", $_GET) && trim((string) $_GET["to"]) !== "" && $to === null) {
        respond(false, "to must be a valid date (YYYY-MM-DD).", null, 422);
    }
    if ($to !== null) {
        $where[]  = "c.work_date <= ?";
        $params[] = $to;
    }

    $whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_corrections c" . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Pending first (that is the queue), then the newest work date.
    $sql = correctionsSelect() . $whereSql
         . " ORDER BY (c.status = 'Pending') DESC, c.work_date DESC, c.correction_id DESC"
         . " LIMIT " . $limit . " OFFSET " . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respond(true, "", [
        "rows"   => array_map("decorateCorrection", $stmt->fetchAll()),
        "total"  => $total,
        "limit"  => $limit,
        "offset" => $offset,
    ]);
}

// Everything below writes: the single Manager-or-Supervisor approval.
requireRole([MANAGER_ROLE, SUPERVISOR_ROLE]);

if ($method !== "PATCH") {
    respond(false, "Method not allowed.", null, 405);
}

// =====================================================================
// PATCH: approve / reject
// =====================================================================
$in     = json_input();
$id     = (int) ($in["correction_id"] ?? 0);
$action = strtolower(trim((string) ($in["action"] ?? "")));
$remarks = trim((string) ($in["remarks"] ?? ""));

if ($id === 0) {
    respond(false, "correction_id is required.", null, 422);
}
if (!in_array($action, ["approve", "reject"], true)) {
    respond(false, "action must be 'approve' or 'reject'.", null, 422);
}

function remarkLength(string $value): int {
    return function_exists("mb_strlen") ? mb_strlen($value, "UTF-8") : strlen($value);
}

if (remarkLength($remarks) > 500) {
    respond(false, "Remarks must be at most 500 characters.", null, 422);
}
// A rejection without a reason is not reviewable; the employee must be told
// what was wrong so they can file a corrected request.
if ($action === "reject" && $remarks === "") {
    respond(false, "Remarks are required when rejecting an attendance correction.", null, 422);
}

$user = currentUser();

// Team scope and the own-request rule, decided BEFORE the transaction opens so
// a refusal cannot leave one half-written and its audit row cannot be rolled
// back with the correction.
$owner = $pdo->prepare("SELECT employee_id FROM attendance_corrections WHERE correction_id = ?");
$owner->execute([$id]);
$ownerEmployeeId = $owner->fetchColumn();
if ($ownerEmployeeId === false) {
    respond(false, "Attendance correction not found.", null, 404);
}
assertNotOwnEmployee((int) $ownerEmployeeId, "approve or reject this correction");
if (isManager()) {
    assertManages($pdo, (int) $ownerEmployeeId, "approve or reject this attendance correction");
} elseif (isSupervisor()) {
    assertSupervises($pdo, (int) $ownerEmployeeId, "approve or reject this attendance correction");
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT * FROM attendance_corrections WHERE correction_id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $correction = $stmt->fetch();

    if (!$correction) {
        $pdo->rollBack();
        respond(false, "Attendance correction not found.", null, 404);
    }
    // Idempotent: a decided request is never applied a second time.
    if ($correction["status"] !== "Pending") {
        $pdo->rollBack();
        respond(
            false,
            "Only Pending corrections can be decided. This one is already {$correction['status']}.",
            ["correction_id" => $id, "status" => $correction["status"]],
            409
        );
    }

    $workDate   = (string) $correction["work_date"];
    $employeeId = (int) $correction["employee_id"];
    $applied    = null;

    if ($action === "approve") {
        // ---- payroll lock: resolve the run that owns this date ----------
        $lock = attendanceDateLock($pdo, $workDate);
        if ($lock !== null) {
            $pdo->rollBack();
            respond(
                false,
                attendanceDateLockedMessage($lock, "Attendance for $workDate"),
                $lock,
                409
            );
        }

        // ---- resolve the times this correction produces -----------------
        $type = (string) $correction["correction_type"];
        $reqIn  = normalizeTime($correction["requested_time_in"] !== null
            ? (string) $correction["requested_time_in"] : null);
        $reqOut = normalizeTime($correction["requested_time_out"] !== null
            ? (string) $correction["requested_time_out"] : null);

        if (($type === "clock_in" || $type === "both") && $reqIn === null) {
            $pdo->rollBack();
            respond(false, "This correction has no valid requested time in, so it cannot be applied.", null, 409);
        }
        if (($type === "clock_out" || $type === "both") && $reqOut === null) {
            $pdo->rollBack();
            respond(false, "This correction has no valid requested time out, so it cannot be applied.", null, 409);
        }

        $stmt = $pdo->prepare(
            "SELECT attendance_id, time_in, time_out
             FROM attendance
             WHERE employee_id = ? AND work_date = ?
             ORDER BY attendance_id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$employeeId, $workDate]);
        $existing = $stmt->fetch() ?: null;

        $timeIn  = $existing ? $existing["time_in"]  : null;
        $timeOut = $existing ? $existing["time_out"] : null;
        if ($type === "clock_in"  || $type === "both") $timeIn  = $reqIn;
        if ($type === "clock_out" || $type === "both") $timeOut = $reqOut;

        // A clock-out needs a clock-in to measure from: without one there is
        // no hours_worked to compute and nothing to attach the time to.
        if ($timeIn === null) {
            $pdo->rollBack();
            respond(
                false,
                "There is no clock-in time for $workDate, so a clock-out cannot be applied. "
                . "The employee must file a 'both' correction instead.",
                null,
                409
            );
        }
        if ($timeOut !== null && strcmp((string) $timeOut, (string) $timeIn) <= 0) {
            $pdo->rollBack();
            respond(false, "Time out must be after time in.", null, 422);
        }

        $minutesLate = scanMinutesLate((string) $timeIn);
        $hoursWorked = $timeOut !== null
            ? scanHoursWorked((string) $timeIn, (string) $timeOut)
            : null;
        $status = $minutesLate > 0 ? "Late" : ($timeOut === null ? "Clocked In" : "Present");

        if ($existing) {
            $pdo->prepare(
                "UPDATE attendance
                 SET time_in = ?, time_out = ?, hours_worked = ?, minutes_late = ?, status = ?
                 WHERE attendance_id = ?"
            )->execute([
                $timeIn, $timeOut, $hoursWorked, $minutesLate, $status,
                $existing["attendance_id"],
            ]);
            $attendanceId = (int) $existing["attendance_id"];
        } else {
            // Created by a reviewer, not by a scan: the provenance is recorded
            // in scan_device_id so the kiosk rows stay distinguishable.
            $pdo->prepare(
                "INSERT INTO attendance
                 (employee_id, qr_code_id, work_date, time_in, time_out,
                  scan_device_id, hours_worked, minutes_late, status)
                 VALUES (?, NULL, ?, ?, ?, 'Correction', ?, ?, ?)"
            )->execute([
                $employeeId, $workDate, $timeIn, $timeOut,
                $hoursWorked, $minutesLate, $status,
            ]);
            $attendanceId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            "UPDATE attendance_corrections
             SET status = 'Approved', attendance_id = ?, reviewed_by = ?,
                 reviewed_at = NOW(), review_remarks = ?
             WHERE correction_id = ?"
        )->execute([$attendanceId, $user["user_id"], $remarks !== "" ? $remarks : null, $id]);

        $applied = [
            "attendance_id"      => $attendanceId,
            "attendance_created" => !$existing,
            "time_in"            => $timeIn,
            "time_out"           => $timeOut,
            "hours_worked"       => $hoursWorked,
            "minutes_late"       => $minutesLate,
            "status"             => $status,
        ];
        $message = $existing
            ? "Attendance correction approved and applied to the existing record."
            : "Attendance correction approved and a new attendance record was created.";
    } else {
        $pdo->prepare(
            "UPDATE attendance_corrections
             SET status = 'Rejected', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?
             WHERE correction_id = ?"
        )->execute([$user["user_id"], $remarks, $id]);
        $message = "Attendance correction rejected.";
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respondServerError("Attendance correction could not be saved. Please try again or contact the administrator.", $e, "attendance_corrections");
}

respond(true, $message, [
    "correction" => correctionById($pdo, $id),
    "applied"    => $applied,
]);
