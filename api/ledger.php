<?php
require __DIR__ . "/_bootstrap.php";

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

// ---- Leave requests overlapping range ----
$stmt = $pdo->prepare(
    "SELECT lr.leave_id AS id, lr.start_date, lr.end_date, lr.status,
            lt.leave_type_id, lt.name AS leave_type, lt.is_paid
     FROM leave_requests lr
     JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
     WHERE lr.employee_id = ?
       AND lr.start_date <= ?
       AND lr.end_date   >= ?
     ORDER BY lr.start_date"
);
$stmt->execute([$employeeId, $to, $from]);
$leaves = $stmt->fetchAll();

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
     WHERE lt.is_active = 1 AND lt.is_paid = 1
     ORDER BY lt.name"
);
$stmt->execute([$employeeId, $year]);
$balances = $stmt->fetchAll();

// ---- Build chronological ledger ----
$ledger = [];

foreach ($attendance as $a) {
    $ledger[] = [
        "date"       => $a["work_date"],
        "event"      => "Attendance",
        "detail"     => ($a["time_in"] && $a["time_out"])
            ? substr($a["time_in"], 0, 5) . " – " . substr($a["time_out"], 0, 5)
            : "—",
        "hours"      => $a["hours_worked"],
        "late"       => (int) $a["minutes_late"],
        "status"     => $a["status"],
        "leave_type" => null,
        "paid"       => null,
    ];
}

foreach ($leaves as $lv) {
    $overlapStart = max($lv["start_date"], $from);
    $overlapEnd   = min($lv["end_date"],   $to);

    $cursor = new DateTime($overlapStart);
    $endEx  = (new DateTime($overlapEnd))->modify("+1 day");

    while ($cursor < $endEx) {
        $dow = (int) $cursor->format("N");
        if ($dow <= 5) {
            $ledger[] = [
                "date"       => $cursor->format("Y-m-d"),
                "event"      => "Leave",
                "detail"     => $lv["leave_type"] . " (" . $lv["status"] . ")",
                "hours"      => null,
                "late"       => null,
                "status"     => $lv["status"],
                "leave_type" => $lv["leave_type"],
                "paid"       => (int) $lv["is_paid"] === 1,
            ];
        }
        $cursor->modify("+1 day");
    }
}

usort($ledger, fn($a, $b) => strcmp($a["date"], $b["date"]));

// ---- Running totals ----
$totals = [
    "days_present"        => 0,
    "days_late"           => 0,
    "total_late_minutes"  => 0,
    "total_hours"         => 0,
    "leave_days"          => 0,
    "paid_leave_days"     => 0,
    "unpaid_leave_days"   => 0,
];
foreach ($ledger as $row) {
    if ($row["event"] === "Attendance") {
        $totals["days_present"]++;
        $totals["total_hours"] += (float) $row["hours"];
        if ((int) $row["late"] > 0) {
            $totals["days_late"]++;
            $totals["total_late_minutes"] += (int) $row["late"];
        }
    } elseif ($row["event"] === "Leave" && $row["status"] === "Approved") {
        $totals["leave_days"]++;
        if ($row["paid"]) $totals["paid_leave_days"]++;
        else               $totals["unpaid_leave_days"]++;
    }
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