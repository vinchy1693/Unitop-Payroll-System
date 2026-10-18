<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker (read) + checker (read); Administrator has no access
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$employeeId = (int) ($_GET["employee_id"] ?? 0);
$from       = trim($_GET["from"] ?? "");
$to         = trim($_GET["to"]   ?? "");

if ($employeeId === 0) {
    respond(false, "Missing employee_id.", null, 422);
}
if ($from === "") $from = "1900-01-01";
if ($to === "")   $to   = "2999-12-31";

// ---- Employee info ----
$stmt = $pdo->prepare(
    "SELECT e.employee_id, e.employee_number,
            CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
            e.date_hired, e.employment_status,
            d.department_name,
            p.title AS position_title
     FROM employees e
     LEFT JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN positions   p ON p.position_id   = e.position_id
     WHERE e.employee_id = ?"
);
$stmt->execute([$employeeId]);
$employee = $stmt->fetch();
if (!$employee) respond(false, "Employee not found.", null, 404);

// ---- Attendance in range ----
$stmt = $pdo->prepare(
    "SELECT attendance_id AS id, work_date, time_in, time_out,
            hours_worked, minutes_late, status
     FROM attendance
     WHERE employee_id = ? AND work_date BETWEEN ? AND ?
     ORDER BY work_date"
);
$stmt->execute([$employeeId, $from, $to]);
$attendance = $stmt->fetchAll();

// Index by date for fast lookup
$attendanceByDate = [];
foreach ($attendance as $a) {
    $attendanceByDate[$a["work_date"]] = $a;
}

// ---- Leave requests (approved) overlapping the range ----
$stmt = $pdo->prepare(
    "SELECT lr.leave_id AS id, lr.start_date, lr.end_date, lr.status,
            lt.leave_type_id, lt.name AS leave_type
     FROM leave_requests lr
     JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
     WHERE lr.employee_id = ?
       AND lr.start_date <= ?
       AND lr.end_date   >= ?
     ORDER BY lr.start_date"
);
$stmt->execute([$employeeId, $to, $from]);
$leaves = $stmt->fetchAll();

// Index leave by date (each day covered gets an entry)
$leaveByDate = [];
foreach ($leaves as $lv) {
    $cursor = new DateTime($lv["start_date"]);
    $endEx  = (new DateTime($lv["end_date"]))->modify("+1 day");
    while ($cursor < $endEx) {
        $dateStr = $cursor->format("Y-m-d");
        // Store the leave entry for this date (approved takes priority)
        if (!isset($leaveByDate[$dateStr]) || $lv["status"] === "Approved") {
            $leaveByDate[$dateStr] = $lv;
        }
        $cursor->modify("+1 day");
    }
}

// ---- Current-year leave balances ----
$year = (int) date("Y");
$stmt = $pdo->prepare(
    "SELECT lt.leave_type_id, lt.name, lt.days_per_year,
            COALESCE(lb.remaining_days, lt.days_per_year) AS remaining_days
     FROM leave_types lt
     LEFT JOIN leave_balances lb
       ON lb.leave_type_id = lt.leave_type_id
      AND lb.employee_id = ?
      AND lb.year = ?
     WHERE lt.is_active = 1
     ORDER BY lt.name"
);
$stmt->execute([$employeeId, $year]);
$balances = $stmt->fetchAll();

// ---- Walk every day in range ----
$ledger = [];
$totals = [
    "present"       => 0,
    "late"          => 0,
    "late_minutes"  => 0,
    "absent"        => 0,
    "leave"         => 0,
    "total_hours"   => 0.0,
    "working_days"  => 0,
];

$cursor = new DateTime($from);
$endEx  = (new DateTime($to))->modify("+1 day");

while ($cursor < $endEx) {
    $dateStr   = $cursor->format("Y-m-d");
    $dayLabel  = $cursor->format("D");
    $dow       = (int) $cursor->format("N");
    $isWeekend = !isWorkingDay($cursor);

    if ($isWeekend) {
        $ledger[] = [
            "date"         => $dateStr,
            "day_label"    => $dayLabel,
            "event"        => "Weekend",
            "detail"       => "",
            "hours"        => null,
            "late"         => null,
            "leave_type"   => null,
            "status"       => "Weekend",
        ];
        $cursor->modify("+1 day");
        continue;
    }

    $totals["working_days"]++;

    // 1. Attendance record exists?
    if (isset($attendanceByDate[$dateStr])) {
        $a = $attendanceByDate[$dateStr];
        $minutesLate = (int) $a["minutes_late"];
        $hours       = $a["hours_worked"] !== null ? (float) $a["hours_worked"] : null;

        $detail = "";
        if ($a["time_in"] && $a["time_out"]) {
            $detail = substr($a["time_in"], 0, 5) . " – " . substr($a["time_out"], 0, 5);
        } elseif ($a["time_in"]) {
            $detail = "In " . substr($a["time_in"], 0, 5) . " (no clock out)";
        }

        if ($minutesLate > 0) {
            $totals["late"]++;
            $totals["late_minutes"] += $minutesLate;
            $event = "Late";
        } else {
            $totals["present"]++;
            $event = "Present";
        }
        if ($hours !== null) {
            $totals["total_hours"] += $hours;
        }

        $ledger[] = [
            "date"       => $dateStr,
            "day_label"  => $dayLabel,
            "event"      => $event,
            "detail"     => $detail,
            "hours"      => $hours,
            "late"       => $minutesLate,
            "leave_type" => null,
            "status"     => $a["status"],
        ];
    }
    // 2. Approved leave covers this day?
    elseif (isset($leaveByDate[$dateStr]) && $leaveByDate[$dateStr]["status"] === "Approved") {
        $lv = $leaveByDate[$dateStr];
        $totals["leave"]++;

        $ledger[] = [
            "date"       => $dateStr,
            "day_label"  => $dayLabel,
            "event"      => "Leave",
            "detail"     => "On approved leave",
            "hours"      => null,
            "late"       => null,
            "leave_type" => $lv["leave_type"],
            "status"     => "Approved",
        ];
    }
    // 3. Pending leave filed for this day
    elseif (isset($leaveByDate[$dateStr])) {
        $lv = $leaveByDate[$dateStr];

        $ledger[] = [
            "date"       => $dateStr,
            "day_label"  => $dayLabel,
            "event"      => "Leave",
            "detail"     => "Leave request " . strtolower($lv["status"]),
            "hours"      => null,
            "late"       => null,
            "leave_type" => $lv["leave_type"],
            "status"     => $lv["status"],
        ];
    }
    // 4. No attendance, no leave → Absent
    else {
        $totals["absent"]++;

        $ledger[] = [
            "date"       => $dateStr,
            "day_label"  => $dayLabel,
            "event"      => "Absent",
            "detail"     => "No attendance record",
            "hours"      => null,
            "late"       => null,
            "leave_type" => null,
            "status"     => "Absent",
        ];
    }

    $cursor->modify("+1 day");
}

$totals["total_hours"] = round($totals["total_hours"], 2);

respond(true, "", [
    "employee" => $employee,
    "from"     => $from,
    "to"       => $to,
    "balances" => $balances,
    "ledger"   => $ledger,
    "totals"   => $totals,
]);