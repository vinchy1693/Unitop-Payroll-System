<?php
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];

// Standard work start time (08:00 AM). Change if UNITOP uses a different shift.
const WORK_START = "08:00:00";
const LUNCH_THRESHOLD_MINUTES = 300;  // > 5 hours = deduct 1-hour lunch
const LUNCH_DEDUCTION_MINUTES = 60;

// ---- Helper: compute hours_worked and minutes_late ----
function computeAttendanceStats(string $timeIn, string $timeOut): array {
    $in  = new DateTime($timeIn);
    $out = new DateTime($timeOut);

    if ($out <= $in) {
        return ["hours_worked" => 0, "minutes_late" => 0, "error" => "time_out must be after time_in"];
    }

    $diff = $in->diff($out);
    $totalMinutes = ($diff->h * 60) + $diff->i;

    $netMinutes = $totalMinutes > LUNCH_THRESHOLD_MINUTES
        ? $totalMinutes - LUNCH_DEDUCTION_MINUTES
        : $totalMinutes;

    $hoursWorked = round($netMinutes / 60, 2);

    $workStart = new DateTime(WORK_START);
    $lateMinutes = 0;
    if ($in > $workStart) {
        $lateDiff = $workStart->diff($in);
        $lateMinutes = ($lateDiff->h * 60) + $lateDiff->i;
    }

    return [
        "hours_worked" => $hoursWorked,
        "minutes_late" => $lateMinutes,
    ];
}

// ---- GET: list attendance ----
if ($method === "GET") {
    // ?id=N — single record
    if (isset($_GET["id"])) {
        $id = (int) $_GET["id"];
        $stmt = $pdo->prepare(
            "SELECT a.*, e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    d.department_name
             FROM attendance a
             JOIN employees e ON e.employee_id = a.employee_id
             LEFT JOIN departments d ON d.department_id = e.department_id
             WHERE a.attendance_id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) respond(false, "Attendance record not found.", null, 404);
        respond(true, "", $row);
    }

    // ?date=YYYY-MM-DD — all attendance for that date
    if (isset($_GET["date"])) {
        $date = trim($_GET["date"]);
        $stmt = $pdo->prepare(
            "SELECT a.*, e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    d.department_name
             FROM attendance a
             JOIN employees e ON e.employee_id = a.employee_id
             LEFT JOIN departments d ON d.department_id = e.department_id
             WHERE a.work_date = ?
             ORDER BY e.last_name, e.first_name"
        );
        $stmt->execute([$date]);
        respond(true, "", $stmt->fetchAll());
    }

    // ?employee_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD — per-employee range
    if (isset($_GET["employee_id"])) {
        $empId = (int) $_GET["employee_id"];
        $from  = trim($_GET["from"] ?? "1900-01-01");
        $to    = trim($_GET["to"]   ?? "2999-12-31");
        $stmt = $pdo->prepare(
            "SELECT a.*, e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name
             FROM attendance a
             JOIN employees e ON e.employee_id = a.employee_id
             WHERE a.employee_id = ? AND a.work_date BETWEEN ? AND ?
             ORDER BY a.work_date DESC"
        );
        $stmt->execute([$empId, $from, $to]);
        respond(true, "", $stmt->fetchAll());
    }

    respond(false, "Missing query parameter (id, date, or employee_id).", null, 422);
}

// ---- POST: create ----
if ($method === "POST") {
    $in      = json_input();
    $empId   = (int)   ($in["employee_id"] ?? 0);
    $workDay = trim($in["work_date"]     ?? "");
    $timeIn  = trim($in["time_in"]       ?? "");
    $timeOut = trim($in["time_out"]      ?? "");

    if ($empId === 0 || $workDay === "" || $timeIn === "" || $timeOut === "") {
        respond(false, "Employee, Date, Time In, and Time Out are required.", null, 422);
    }

    // Prevent duplicate: one row per employee per day
    $stmt = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id = ? AND work_date = ?");
    $stmt->execute([$empId, $workDay]);
    if ($stmt->fetchColumn()) {
        respond(false, "This employee already has an attendance record for that date.", null, 409);
    }

    $stats = computeAttendanceStats($timeIn, $timeOut);
    if (isset($stats["error"])) {
        respond(false, $stats["error"], null, 422);
    }

    $status = $stats["minutes_late"] > 0 ? "Late" : "Present";

    $stmt = $pdo->prepare(
        "INSERT INTO attendance
         (employee_id, qr_code_id, work_date, time_in, time_out,
          scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, ?, ?, 'Manual', ?, ?, ?)"
    );
    $stmt->execute([
        $empId, $workDay, $timeIn, $timeOut,
        $stats["hours_worked"], $stats["minutes_late"], $status,
    ]);

    respond(true, "Attendance recorded.", ["attendance_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in      = json_input();
    $id      = (int)   ($in["attendance_id"] ?? 0);
    $empId   = (int)   ($in["employee_id"]   ?? 0);
    $workDay = trim($in["work_date"]         ?? "");
    $timeIn  = trim($in["time_in"]           ?? "");
    $timeOut = trim($in["time_out"]          ?? "");

    if ($id === 0 || $empId === 0 || $workDay === "" || $timeIn === "" || $timeOut === "") {
        respond(false, "All fields are required.", null, 422);
    }

    $stats = computeAttendanceStats($timeIn, $timeOut);
    if (isset($stats["error"])) {
        respond(false, $stats["error"], null, 422);
    }

    $status = $stats["minutes_late"] > 0 ? "Late" : "Present";

    $stmt = $pdo->prepare(
        "UPDATE attendance
         SET employee_id = ?, work_date = ?, time_in = ?, time_out = ?,
             hours_worked = ?, minutes_late = ?, status = ?
         WHERE attendance_id = ?"
    );
    $stmt->execute([
        $empId, $workDay, $timeIn, $timeOut,
        $stats["hours_worked"], $stats["minutes_late"], $status, $id,
    ]);

    respond(true, "Attendance updated.");
}

// ---- DELETE ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) {
        respond(false, "Missing attendance_id.", null, 422);
    }
    $stmt = $pdo->prepare("DELETE FROM attendance WHERE attendance_id = ?");
    $stmt->execute([$id]);
    respond(true, "Attendance record deleted.");
}

respond(false, "Method not allowed.", null, 405);