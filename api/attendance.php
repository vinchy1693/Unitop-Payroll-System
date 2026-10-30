<?php
require __DIR__ . "/_bootstrap.php";
require __DIR__ . "/_payroll_helpers.php";
require __DIR__ . "/_attendance_policy.php";

$method = $_SERVER["REQUEST_METHOD"];

const LUNCH_THRESHOLD_MINUTES = 300;
const LUNCH_DEDUCTION_MINUTES = 60;
const REGULAR_DAILY_GROSS = 500.00;

// Payroll staff may VIEW attendance; only the maker (Payroll Officer) may
// create, edit or delete records. A Supervisor may also view, but ONLY their
// own team's rows - see the scope applied inside the GET branches below.
// Every mutation still hits requireRole(MAKER_ROLES) further down, so a
// Supervisor can never write attendance. The kiosk does not call this endpoint
// - it uses scan_attendance.php and auth.php, which stay open to every role.
requireRole(array_merge(TEAM_QUEUE_ROLES, [FINANCE_ROLE]));

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
    $lateMinutes = attendanceLateMinutes($timeIn);

    return ["hours_worked" => $hoursWorked, "minutes_late" => $lateMinutes];
}

function computeTimeInStats(string $timeIn): array {
    return ["minutes_late" => attendanceLateMinutes($timeIn)];
}

// countWorkingDays() lived here as a duplicate of the payroll helper. The work
// week (Mon-Sat) is now defined once in api/_payroll_helpers.php so the
// attendance screen and the payslip engine can never disagree again.

// ---- GET ----
if ($method === "GET") {
    $showGross = in_array((string) (currentUser()["role"] ?? ""), ["Payroll Officer", FINANCE_ROLE, "Administrator"], true);

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

        // A Supervisor may only open a team member's detail. The maker and the
        // checker are unaffected (assertTeamReadScope is a no-op for them).
        assertTeamReadScope($pdo, $empId, "read this employee's attendance");

        $stmt = $pdo->prepare(
            "SELECT e.employee_id, e.employee_number, e.basic_salary,
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
        $hasAnyRecords = count($records) > 0;

        $cursor = new DateTime($from);
        $endEx  = (new DateTime($to))->modify('+1 day');

        $days = [];
        $totals = [
            "present"            => 0,
            "absent"             => 0,
            "late"               => 0,
            "late_minutes"       => 0,
            "undertime_minutes"  => 0,
            "hours"              => 0.0,
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
                    "undertime_minutes" => null,
                    "status"       => "Weekend",
                ];
            } elseif (isset($byDate[$dateStr])) {
                $r = $byDate[$dateStr];
                $isAbsent = ($r["status"] === "Absent");
                $dayUndertime = $isAbsent ? 0 : attendanceUndertimeMinutes(
                    $r["time_out"] !== null ? (string) $r["time_out"] : null,
                    (int) $r["minutes_late"]
                );
                $days[] = [
                    "date"         => $dateStr,
                    "day_label"    => $dayLabel,
                    "is_weekend"   => false,
                    "has_record"   => true,
                    "time_in"      => $r["time_in"],
                    "time_out"     => $r["time_out"],
                    "hours_worked" => $r["hours_worked"] !== null ? (float) $r["hours_worked"] : null,
                    "minutes_late" => (int) $r["minutes_late"],
                    "undertime_minutes" => $dayUndertime,
                    "status"       => $r["status"],
                ];
                if (!$isAbsent) {
                    $totals["present"]++;
                    if ((int) $r["minutes_late"] > 0) {
                        $totals["late"]++;
                        $totals["late_minutes"] += (int) $r["minutes_late"];
                    }
                    $totals["undertime_minutes"] += $dayUndertime;
                    $totals["hours"] += (float) ($r["hours_worked"] ?? 0);
                } else {
                    $totals["absent"]++;
                }
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
                    "undertime_minutes" => 0,
                    "status"       => $hasAnyRecords ? "Absent" : "No Record",
                ];
                if ($hasAnyRecords) {
                    $totals["absent"]++;
                }
            }

            $cursor->modify("+1 day");
        }

        $totals["hours"] = round($totals["hours"], 2);
        $totals["expected_days"] = countWorkingDays($from, $to);

        if ($showGross) {
            // Flat contractual rate for the payroll attendance view. Gross is
            // earned only on regular days with attendance; absences show zero.
            $dailyRate = REGULAR_DAILY_GROSS;

            $gross = 0.0;
            foreach ($days as &$day) {
                if ($day["is_weekend"]) {
                    $day["daily_gross"] = null;
                } elseif ($day["has_record"] && $day["status"] !== "Absent") {
                    $day["daily_gross"] = $dailyRate;
                    $gross += $dailyRate;
                } else {
                    $day["daily_gross"] = 0.0;
                }
            }
            unset($day);

            $employee["daily_rate"] = $dailyRate;
            $employee["month_working_days"] = null;
            $employee["period_gross"] = round($gross, 2);
            $totals["gross"] = round($gross, 2);
        } else {
            unset($employee["basic_salary"]);
        }

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

        // Team scope: a Supervisor's summary lists only their own team. The
        // maker and the checker keep every active employee. A Supervisor with
        // an empty team matches nobody (`AND 1=0`) instead of erroring.
        $scope         = teamScopeIds($pdo);
        $employeeScope = $scope === null
            ? ""
            : ($scope
                ? " AND e.employee_id IN (" . implode(",", array_fill(0, count($scope), "?")) . ")"
                : " AND 1=0");
        $scopeParams   = $scope ?? [];

        // Only working-day attendance (Mon-Sat) counts; Sunday scans are ignored
        $sql = "SELECT
                    e.employee_id,
                    e.employee_number,
                    e.basic_salary,
                    CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                    COALESCE(d.department_name, '—') AS department_name,
                    COUNT(a.attendance_id) AS attendance_rows,
                    COUNT(CASE
                        WHEN a.time_in IS NOT NULL
                         AND a.time_out IS NOT NULL
                         AND a.status IN ('Present', 'Late')
                        THEN 1
                    END) AS days_present,
                    SUM(CASE
                        WHEN a.time_in IS NOT NULL
                         AND a.time_out IS NOT NULL
                         AND a.status IN ('Present', 'Late')
                         AND a.minutes_late > 0
                        THEN 1 ELSE 0
                    END) AS days_late,
                    COALESCE(SUM(CASE
                        WHEN a.time_in IS NOT NULL
                         AND a.time_out IS NOT NULL
                         AND a.status IN ('Present', 'Late')
                        THEN a.minutes_late ELSE 0
                    END), 0) AS total_late_minutes,
                    COALESCE(SUM(CASE
                        WHEN a.time_in IS NOT NULL
                         AND a.time_out IS NOT NULL
                         AND a.status IN ('Present', 'Late')
                        THEN a.hours_worked ELSE 0
                    END), 0) AS total_hours
                FROM employees e
                LEFT JOIN attendance a
                  ON a.employee_id = e.employee_id
                 AND a.work_date BETWEEN ? AND ?
                 AND " . workDaySql('a.work_date') . "
                LEFT JOIN departments d ON d.department_id = e.department_id
                WHERE e.is_active = 1" . $employeeScope . "
                GROUP BY e.employee_id, e.employee_number, e.basic_salary, e.last_name, e.first_name, d.department_name
                ORDER BY e.last_name, e.first_name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scopeParams));
        $rows = $stmt->fetchAll();

        $undertimeStmt = $pdo->prepare(
            "SELECT time_out, minutes_late
             FROM attendance
             WHERE employee_id = ? AND work_date BETWEEN ? AND ?
               AND " . workDaySql('work_date') . "
               AND time_in IS NOT NULL
               AND time_out IS NOT NULL
               AND status IN ('Present', 'Late')"
        );

        $totals = [
            "expected_days"          => $expectedDays,
            "days_present"           => 0,
            "days_late"              => 0,
            "total_late_minutes"     => 0,
            "total_undertime_minutes"=> 0,
            "total_hours"            => 0,
            "absences"               => 0,
            "period_gross"           => 0,
        ];

        foreach ($rows as &$r) {
            $r["days_present"]       = (int) $r["days_present"];
            $r["days_late"]          = (int) $r["days_late"];
            $r["total_late_minutes"] = (int) $r["total_late_minutes"];
            $r["total_hours"]        = round((float) $r["total_hours"], 2);

            // No attendance records at all means the period has not been
            // recorded yet: do not auto-mark the employee absent. Once any
            // record exists, the uncovered working days are absences.
            $r["absences"] = ((int) $r["attendance_rows"] === 0)
                ? 0
                : max(0, $expectedDays - $r["days_present"]);

            $undertimeStmt->execute([(int) $r["employee_id"], $from, $to]);
            $undertime = 0;
            foreach ($undertimeStmt->fetchAll() as $att) {
                $undertime += attendanceUndertimeMinutes(
                    $att["time_out"] !== null ? (string) $att["time_out"] : null,
                    (int) $att["minutes_late"]
                );
            }
            $r["total_undertime_minutes"] = $undertime;

            $dailyRate = REGULAR_DAILY_GROSS;
            $r["daily_rate"] = $dailyRate;
            $r["period_gross"] = round($r["days_present"] * $dailyRate, 2);

            $totals["days_present"]            += $r["days_present"];
            $totals["days_late"]               += $r["days_late"];
            $totals["total_late_minutes"]      += $r["total_late_minutes"];
            $totals["total_undertime_minutes"] += $undertime;
            $totals["total_hours"]             += $r["total_hours"];
            $totals["absences"]           += $r["absences"];
            $totals["period_gross"]       += $r["period_gross"];
        }
        unset($r);

        $totals["total_hours"] = round($totals["total_hours"], 2);
        $totals["period_gross"] = round($totals["period_gross"], 2);

        if (!$showGross) {
            foreach ($rows as &$r) {
                unset($r["basic_salary"], $r["daily_rate"], $r["period_gross"]);
            }
            unset($r);
            unset($totals["period_gross"]);
        }

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
        assertTeamReadScope($pdo, (int) $row["employee_id"], "read this attendance record");
        respond(true, "", $row);
    }

    // Daily view
    if (isset($_GET["date"])) {
        $date = trim($_GET["date"]);

        // Team scope: a Supervisor's day view lists only their own team.
        $scope  = teamScopeIds($pdo);
        $params = [$date];
        $sql    = "SELECT a.*, e.employee_number,
                          CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                          d.department_name
                   FROM attendance a
                   JOIN employees e ON e.employee_id = a.employee_id
                   LEFT JOIN departments d ON d.department_id = e.department_id
                   WHERE a.work_date = ?";
        if ($scope !== null) {
            $sql .= $scope
                ? " AND a.employee_id IN (" . implode(",", array_fill(0, count($scope), "?")) . ")"
                : " AND 1=0";
            $params = array_merge($params, $scope);
        }
        $sql .= " ORDER BY e.last_name, e.first_name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Tell the page which rows are behind a Released/Approved payroll run,
        // so it can disable Edit/Delete instead of opening a form that will only
        // fail with 409 after scrolling the user to the top.
        foreach ($rows as &$row) {
            $lock = attendanceDateLock($pdo, (string) $row["work_date"]);
            $row["locked"]         = $lock !== null;
            $row["lock_run_id"]    = $lock["run_id"] ?? null;
            $row["lock_status"]    = $lock["status"] ?? null;
            $row["lock_period"]    = $lock
                ? ($lock["start_date"] . " to " . $lock["end_date"])
                : null;
        }
        unset($row);

        respond(true, "", $rows);
    }

    respond(false, "Missing query parameter (summary, employee_detail, id, or date).", null, 422);
}

// ---- POST (Time In only, or full log) ----
requireRole(MANAGER_ROLE);   // team Managers maintain attendance; Payroll/HR reviews

if ($method === "POST") {
    $in      = json_input();
    $action  = trim($in["action"] ?? "");
    $empId   = (int)   ($in["employee_id"] ?? 0);
    $workDay = trim($in["work_date"]     ?? "");

    // Explicit absence: store a zero-time record instead of silently deriving
    // an absence from a missing row.
    if ($action === "mark_absent") {
        if (!isManager()) {
            respond(false, "Only a Manager may mark attendance as absent.", null, 403);
        }
        if ($empId === 0 || $workDay === "") {
            respond(false, "Employee and Date are required.", null, 422);
        }
        if (isManager()) {
            assertManages($pdo, $empId, "manage attendance for this employee");
        }

        $dateCheck = DateTime::createFromFormat("!Y-m-d", $workDay);
        if (!$dateCheck || $dateCheck->format("Y-m-d") !== $workDay) {
            respond(false, "Work Date must be a real date in YYYY-MM-DD form.", null, 422);
        }
        if (!isWorkingDay($dateCheck)) {
            respond(false, "Absence can only be marked on a working day.", null, 422);
        }
        assertAttendanceDateUnlocked($pdo, $workDay, "Attendance for this date");

        $stmt = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id = ? AND work_date = ?");
        $stmt->execute([$empId, $workDay]);
        if ($stmt->fetchColumn()) {
            respond(false, "This employee already has an attendance record for that date.", null, 409);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO attendance
             (employee_id, qr_code_id, work_date, time_in, time_out,
              scan_device_id, hours_worked, minutes_late, status)
             VALUES (?, NULL, ?, NULL, NULL, 'Manual', 0, 0, 'Absent')"
        );
        $stmt->execute([$empId, $workDay]);

        respond(true, "Absence recorded.", ["attendance_id" => (int) $pdo->lastInsertId()]);
    }

    $timeIn  = trim($in["time_in"]       ?? "");
    $timeOut = trim($in["time_out"]      ?? "");

    if ($empId === 0 || $workDay === "" || $timeIn === "") {
        respond(false, "Employee, Date, and Time In are required.", null, 422);
    }
    if (isManager()) {
        assertManages($pdo, $empId, "manage attendance for this employee");
    }

    $dateCheck = DateTime::createFromFormat("!Y-m-d", $workDay);
    if (!$dateCheck || $dateCheck->format("Y-m-d") !== $workDay) {
        respond(false, "Work Date must be a real date in YYYY-MM-DD form.", null, 422);
    }
    assertAttendanceDateUnlocked($pdo, $workDay, "Attendance for this date");

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
    if (isManager()) {
        assertManages($pdo, $empId, "manage attendance for this employee");
    }

    $dateCheck = DateTime::createFromFormat("!Y-m-d", $workDay);
    if (!$dateCheck || $dateCheck->format("Y-m-d") !== $workDay) {
        respond(false, "Work Date must be a real date in YYYY-MM-DD form.", null, 422);
    }
    assertAttendanceDateUnlocked($pdo, $workDay, "Attendance for this date");

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
    if (isManager()) {
        assertManages($pdo, $empId, "manage attendance for this employee");
    }

    $dateCheck = DateTime::createFromFormat("!Y-m-d", $workDay);
    if (!$dateCheck || $dateCheck->format("Y-m-d") !== $workDay) {
        respond(false, "Work Date must be a real date in YYYY-MM-DD form.", null, 422);
    }

    $currentStmt = $pdo->prepare("SELECT work_date FROM attendance WHERE attendance_id = ?");
    $currentStmt->execute([$id]);
    $currentDate = $currentStmt->fetchColumn();
    if ($currentDate === false) {
        respond(false, "Attendance record not found.", null, 404);
    }
    assertAttendanceDateUnlocked($pdo, (string) $currentDate, "Attendance for this date");
    assertAttendanceDateUnlocked($pdo, $workDay, "Attendance for this date");

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

    $stmt = $pdo->prepare("SELECT employee_id, work_date FROM attendance WHERE attendance_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(false, "Attendance record not found.", null, 404);
    }
    if (isManager()) {
        assertManages($pdo, (int) $row["employee_id"], "manage attendance for this employee");
    }
    $workDate = $row["work_date"];
    assertAttendanceDateUnlocked($pdo, (string) $workDate, "Attendance for this date");

    $pdo->prepare("DELETE FROM attendance WHERE attendance_id = ?")->execute([$id]);
    respond(true, "Attendance record deleted.");
}

respond(false, "Method not allowed.", null, 405);