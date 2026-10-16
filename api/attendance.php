<?php
require __DIR__ . "/_bootstrap.php";
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

const WORK_START = "08:00:00";
const LUNCH_THRESHOLD_MINUTES = 300;
const LUNCH_DEDUCTION_MINUTES = 60;

// Any signed-in user may VIEW attendance (the kiosk screen reads it); only
// admin / payroll staff may create, edit or delete records.
if ($method !== "GET") {
    requireRole(PAYROLL_ROLES);
}

function computeAttendanceStats(string $timeIn, string $timeOut): array {
    $in  = new DateTime($timeIn);
    $out = new DateTime($timeOut);
    if ($out <= $in) return ["error" => "time_out must be after time_in"];

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
    return ["hours_worked" => $hoursWorked, "minutes_late" => $lateMinutes];
}

function computeTimeInStats(string $timeIn): array {
    $in = new DateTime($timeIn);
    $workStart = new DateTime(WORK_START);
    $lateMinutes = 0;
    if ($in > $workStart) {
        $lateDiff = $workStart->diff($in);
        $lateMinutes = ($lateDiff->h * 60) + $lateDiff->i;
    }
    return ["minutes_late" => $lateMinutes];
}

// countWorkingDays() lived here as a duplicate of the payroll helper. The work
// week (Mon-Sat) is now defined once in api/_payroll_helpers.php so the
// attendance screen and the payslip engine can never disagree again.

// ---- GET ----
if ($method === "GET") {

    // Employee detail — day-by-day breakdown
    if (isset($_GET["employee_detail"])) {
        $empId = (int) ($_GET["employee_id"] ?? 0);
        $from  = trim($_GET["from"] ?? "");
        $to    = trim($_GET["to"]   ?? "");

        if ($empId === 0 || $from === "" || $to === "") {
            respond(false, "Employee, from, and to are required.", null, 422);
        }
        if ($from > $to) {
            respond(false, "The 'from' date must be on or before the 'to' date.", null, 422);
        }

        $stmt = $pdo->prepare(
            "SELECT e.employee_id, e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    d.department_name,
                    p.title AS position_title
             FROM employees e
             LEFT JOIN departments d ON d.department_id = e.department_id
             LEFT JOIN positions   p ON p.position_id   = e.position_id
             WHERE e.employee_id = ?"
        );
        $stmt->execute([$empId]);
        $employee = $stmt->fetch();
        if (!$employee) respond(false, "Employee not found.", null, 404);

        $stmt = $pdo->prepare(
            "SELECT attendance_id, work_date, time_in, time_out,
                    hours_worked, minutes_late, status
             FROM attendance
             WHERE employee_id = ? AND work_date BETWEEN ? AND ?
             ORDER BY work_date"
        );
        $stmt->execute([$empId, $from, $to]);
        $records = $stmt->fetchAll();

        $byDate = [];
        foreach ($records as $r) {
            $byDate[$r["work_date"]] = $r;
        }

        $cursor = new DateTime($from);
        $endEx  = (new DateTime($to))->modify('+1 day');

        $days = [];
        $totals = [
            "present"      => 0,
            "absent"       => 0,
            "late"         => 0,
            "late_minutes" => 0,
            "hours"        => 0.0,
        ];

        while ($cursor < $endEx) {
            $dateStr   = $cursor->format("Y-m-d");
            $dow       = (int) $cursor->format("N");
            $isWeekend = !isWorkingDay($cursor);
            $dayLabel  = $cursor->format("D");

            if ($isWeekend) {
                $days[] = [
                    "date"         => $dateStr,
                    "day_label"    => $dayLabel,
                    "is_weekend"   => true,
                    "has_record"   => false,
                    "time_in"      => null,
                    "time_out"     => null,
                    "hours_worked" => null,
                    "minutes_late" => null,
                    "status"       => "Weekend",
                ];
            } elseif (isset($byDate[$dateStr])) {
                $r = $byDate[$dateStr];
                $days[] = [
                    "date"         => $dateStr,
                    "day_label"    => $dayLabel,
                    "is_weekend"   => false,
                    "has_record"   => true,
                    "time_in"      => $r["time_in"],
                    "time_out"     => $r["time_out"],
                    "hours_worked" => $r["hours_worked"] !== null ? (float) $r["hours_worked"] : null,
                    "minutes_late" => (int) $r["minutes_late"],
                    "status"       => $r["status"],
                ];
                $totals["present"]++;
                if ((int) $r["minutes_late"] > 0) {
                    $totals["late"]++;
                    $totals["late_minutes"] += (int) $r["minutes_late"];
                }
                $totals["hours"] += (float) ($r["hours_worked"] ?? 0);
            } else {
                $days[] = [
                    "date"         => $dateStr,
                    "day_label"    => $dayLabel,
                    "is_weekend"   => false,
                    "has_record"   => false,
                    "time_in"      => null,
                    "time_out"     => null,
                    "hours_worked" => null,
                    "minutes_late" => null,
                    "status"       => "Absent",
                ];
                $totals["absent"]++;
            }

            $cursor->modify("+1 day");
        }

        $totals["hours"] = round($totals["hours"], 2);
        $totals["expected_days"] = countWorkingDays($from, $to);

        respond(true, "", [
            "employee" => $employee,
            "from"     => $from,
            "to"       => $to,
            "days"     => $days,
            "totals"   => $totals,
        ]);
    }

    // Summary mode — FIXED: excludes weekends
    if (isset($_GET["summary"])) {
        $from = trim($_GET["from"] ?? "");
        $to   = trim($_GET["to"]   ?? "");
        if ($from === "" || $to === "") {
            respond(false, "Both from and to dates are required.", null, 422);
        }
        if ($from > $to) {
            respond(false, "The 'from' date must be on or before the 'to' date.", null, 422);
        }

        $expectedDays = countWorkingDays($from, $to);

        // Only working-day attendance (Mon-Sat) counts; Sunday scans are ignored
        $sql = "SELECT
                    e.employee_id,
                    e.employee_number,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    COALESCE(d.department_name, '—') AS department_name,
                    COUNT(a.attendance_id) AS days_present,
                    SUM(CASE WHEN a.minutes_late > 0 THEN 1 ELSE 0 END) AS days_late,
                    COALESCE(SUM(a.minutes_late), 0) AS total_late_minutes,
                    COALESCE(SUM(a.hours_worked), 0) AS total_hours
                FROM employees e
                LEFT JOIN attendance a
                  ON a.employee_id = e.employee_id
                 AND a.work_date BETWEEN ? AND ?
                 AND " . workDaySql('a.work_date') . "
                LEFT JOIN departments d ON d.department_id = e.department_id
                WHERE e.is_active = 1
                GROUP BY e.employee_id, e.employee_number, e.last_name, e.first_name, d.department_name
                ORDER BY e.last_name, e.first_name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();

        $totals = [
            "expected_days"      => $expectedDays,
            "days_present"       => 0,
            "days_late"          => 0,
            "total_late_minutes" => 0,
            "total_hours"        => 0,
            "absences"           => 0,
        ];

        foreach ($rows as &$r) {
            $r["days_present"]       = (int) $r["days_present"];
            $r["days_late"]          = (int) $r["days_late"];
            $r["total_late_minutes"] = (int) $r["total_late_minutes"];
            $r["total_hours"]        = round((float) $r["total_hours"], 2);
            $r["absences"]           = max(0, $expectedDays - $r["days_present"]);

            $totals["days_present"]       += $r["days_present"];
            $totals["days_late"]          += $r["days_late"];
            $totals["total_late_minutes"] += $r["total_late_minutes"];
            $totals["total_hours"]        += $r["total_hours"];
            $totals["absences"]           += $r["absences"];
        }
        unset($r);

        $totals["total_hours"] = round($totals["total_hours"], 2);

        respond(true, "", [
            "from"          => $from,
            "to"            => $to,
            "expected_days" => $expectedDays,
            "employees"     => $rows,
            "totals"        => $totals,
        ]);
    }

    // Single record
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

    // Daily view
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

    respond(false, "Missing query parameter (summary, employee_detail, id, or date).", null, 422);
}

// ---- POST (Time In only, or full log) ----
if ($method === "POST") {
    $in      = json_input();
    $empId   = (int)   ($in["employee_id"] ?? 0);
    $workDay = trim($in["work_date"]     ?? "");
    $timeIn  = trim($in["time_in"]       ?? "");
    $timeOut = trim($in["time_out"]      ?? "");

    if ($empId === 0 || $workDay === "" || $timeIn === "") {
        respond(false, "Employee, Date, and Time In are required.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id = ? AND work_date = ?");
    $stmt->execute([$empId, $workDay]);
    if ($stmt->fetchColumn()) {
        respond(false, "This employee already has an attendance record for that date.", null, 409);
    }

    if ($timeOut !== "") {
        $stats = computeAttendanceStats($timeIn, $timeOut);
        if (isset($stats["error"])) respond(false, $stats["error"], null, 422);

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

        respond(true, "Attendance recorded (Time In + Time Out).", ["attendance_id" => (int) $pdo->lastInsertId()]);
    }

    $tiStats = computeTimeInStats($timeIn);
    $status = $tiStats["minutes_late"] > 0 ? "Late" : "Clocked In";

    $stmt = $pdo->prepare(
        "INSERT INTO attendance
         (employee_id, qr_code_id, work_date, time_in, time_out,
          scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, ?, NULL, 'Manual', NULL, ?, ?)"
    );
    $stmt->execute([
        $empId, $workDay, $timeIn,
        $tiStats["minutes_late"], $status,
    ]);

    respond(true, "Time In recorded. Don't forget to clock out!", ["attendance_id" => (int) $pdo->lastInsertId()]);
}

// ---- PATCH (Time Out) ----
if ($method === "PATCH") {
    $in      = json_input();
    $empId   = (int)   ($in["employee_id"] ?? 0);
    $workDay = trim($in["work_date"]     ?? "");
    $timeOut = trim($in["time_out"]      ?? "");

    if ($empId === 0 || $workDay === "" || $timeOut === "") {
        respond(false, "Employee, Date, and Time Out are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "SELECT attendance_id, time_in, time_out FROM attendance
         WHERE employee_id = ? AND work_date = ?"
    );
    $stmt->execute([$empId, $workDay]);
    $existing = $stmt->fetch();

    if (!$existing) {
        respond(false, "No Time In record found for this employee on that date. Please clock in first.", null, 404);
    }

    if ($existing["time_out"] !== null) {
        respond(false, "This employee already has a Time Out recorded for that date. Use Edit to change it.", null, 409);
    }

    $timeIn = $existing["time_in"];

    $stats = computeAttendanceStats($timeIn, $timeOut);
    if (isset($stats["error"])) respond(false, $stats["error"], null, 422);

    $status = $stats["minutes_late"] > 0 ? "Late" : "Present";

    $stmt = $pdo->prepare(
        "UPDATE attendance
         SET time_out = ?, hours_worked = ?, minutes_late = ?, status = ?
         WHERE attendance_id = ?"
    );
    $stmt->execute([
        $timeOut, $stats["hours_worked"], $stats["minutes_late"], $status,
        $existing["attendance_id"],
    ]);

    respond(true, "Time Out recorded successfully.", ["attendance_id" => (int) $existing["attendance_id"]]);
}

// ---- PUT (Edit full record) ----
if ($method === "PUT") {
    $in      = json_input();
    $id      = (int)   ($in["attendance_id"] ?? 0);
    $empId   = (int)   ($in["employee_id"]   ?? 0);
    $workDay = trim($in["work_date"]         ?? "");
    $timeIn  = trim($in["time_in"]           ?? "");
    $timeOut = trim($in["time_out"]          ?? "");

    if ($id === 0 || $empId === 0 || $workDay === "" || $timeIn === "") {
        respond(false, "Employee, Date, and Time In are required.", null, 422);
    }

    if ($timeOut !== "") {
        $stats = computeAttendanceStats($timeIn, $timeOut);
        if (isset($stats["error"])) respond(false, $stats["error"], null, 422);

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
    } else {
        $tiStats = computeTimeInStats($timeIn);
        $status = $tiStats["minutes_late"] > 0 ? "Late" : "Clocked In";

        $stmt = $pdo->prepare(
            "UPDATE attendance
             SET employee_id = ?, work_date = ?, time_in = ?, time_out = NULL,
                 hours_worked = NULL, minutes_late = ?, status = ?
             WHERE attendance_id = ?"
        );
        $stmt->execute([
            $empId, $workDay, $timeIn,
            $tiStats["minutes_late"], $status, $id,
        ]);
    }

    respond(true, "Attendance updated.");
}

// ---- DELETE ----
if ($method === "DELETE") {
    $id = (int) ($_GET["id"] ?? 0);
    if ($id === 0) respond(false, "Missing attendance_id.", null, 422);
    $pdo->prepare("DELETE FROM attendance WHERE attendance_id = ?")->execute([$id]);
    respond(true, "Attendance record deleted.");
}

respond(false, "Method not allowed.", null, 405);