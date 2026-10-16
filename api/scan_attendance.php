<?php
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

$in         = json_input();
$codeValue  = trim($in["code_value"] ?? "");

if ($codeValue === "") {
    respond(false, "QR code value is required.", null, 422);
}

// ---- 1. Look up the QR code ----
$stmt = $pdo->prepare(
    "SELECT q.qr_code_id, q.employee_id, q.status,
            CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
            e.employee_number
     FROM qr_codes q
     JOIN employees e ON e.employee_id = q.employee_id
     WHERE q.code_value = ? LIMIT 1"
);
$stmt->execute([$codeValue]);
$qr = $stmt->fetch();

if (!$qr) {
    respond(false, "Unrecognized QR code.", null, 404);
}
if ($qr["status"] !== "Active") {
    respond(false, "This QR code has been revoked.", null, 403);
}

$empId   = (int) $qr["employee_id"];
$empName = $qr["employee_name"];
$today   = date("Y-m-d");

// ---- 2. Check today's attendance ----
$stmt = $pdo->prepare(
    "SELECT attendance_id, time_in, time_out
     FROM attendance
     WHERE employee_id = ? AND work_date = ?"
);
$stmt->execute([$empId, $today]);
$existing = $stmt->fetch();

// ---- 3. Decide: clock in or clock out ----
if (!$existing) {
    // --- Clock In ---
    $now = date("H:i:s");

    // Compute late minutes based on 08:00 (will be shift-aware in a later phase)
    $workStart  = new DateTime("08:00:00");
    $actualTime = new DateTime($now);
    $minutesLate = 0;
    if ($actualTime > $workStart) {
        $diff = $workStart->diff($actualTime);
        $minutesLate = ($diff->h * 60) + $diff->i;
    }
    $status = $minutesLate > 0 ? "Late" : "Clocked In";

    $stmt = $pdo->prepare(
        "INSERT INTO attendance
         (employee_id, qr_code_id, work_date, time_in, time_out,
          scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, ?, ?, ?, NULL, 'QR Kiosk', NULL, ?, ?)"
    );
    $stmt->execute([$empId, $qr["qr_code_id"], $today, $now, $minutesLate, $status]);

    respond(true, "$empName clocked in at " . date("h:i A"), [
        "action"       => "in",
        "employee_id"  => $empId,
        "employee_name"=> $empName,
        "employee_number" => $qr["employee_number"],
        "time"         => date("h:i A"),
        "late_minutes" => $minutesLate,
        "status"       => $status,
    ]);
}

if ($existing["time_out"] === null) {
    // --- Clock Out ---
    $now = date("H:i:s");

    $timeIn  = new DateTime($existing["time_in"]);
    $timeOut = new DateTime($now);
    $diff = $timeIn->diff($timeOut);
    $totalMinutes = ($diff->h * 60) + $diff->i;
    $netMinutes = $totalMinutes > 300 ? $totalMinutes - 60 : $totalMinutes; // lunch break
    $hoursWorked = round($netMinutes / 60, 2);

    // Keep existing minutes_late; update status to Present if there was no late
    $stmt = $pdo->prepare("SELECT minutes_late FROM attendance WHERE attendance_id = ?");
    $stmt->execute([$existing["attendance_id"]]);
    $minutesLate = (int) $stmt->fetchColumn();
    $status = $minutesLate > 0 ? "Late" : "Present";

    $stmt = $pdo->prepare(
        "UPDATE attendance
         SET time_out = ?, hours_worked = ?, status = ?
         WHERE attendance_id = ?"
    );
    $stmt->execute([$now, $hoursWorked, $status, $existing["attendance_id"]]);

    respond(true, "$empName clocked out at " . date("h:i A"), [
        "action"       => "out",
        "employee_id"  => $empId,
        "employee_name"=> $empName,
        "employee_number" => $qr["employee_number"],
        "time"         => date("h:i A"),
        "hours_worked" => $hoursWorked,
        "status"       => $status,
    ]);
}

// Already clocked in AND out today
respond(false, "$empName has already clocked out today.", [
    "action"      => "duplicate",
    "employee_name" => $empName,
], 409);