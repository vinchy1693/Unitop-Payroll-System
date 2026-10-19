<?php
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

$in        = json_input();
$codeValue = trim($in["code_value"] ?? "");

if ($codeValue === "") {
    respond(false, "QR code value is required.", null, 422);
}

// ---- Demo mode ----
$isDemoMode = !empty($in["demo_mode"]);
$demoTime   = trim($in["demo_time"] ?? "");   // "HH:MM" or "HH:MM:SS"
$demoDate   = trim($in["demo_date"] ?? "");   // "YYYY-MM-DD"

// Demo mode writes attendance on a caller-chosen date and time, so it must not
// be a feature every kiosk user can reach. The kiosk itself stays open to every
// role; only the demo branch is restricted - to the maker - and the deployment
// switch ALLOW_DEMO_SCANS can disable it outright.
if ($isDemoMode) {
    if (!ALLOW_DEMO_SCANS) {
        respond(false, "Demo scanning is disabled on this deployment.", null, 403);
    }
    requireRole(MAKER_ROLES);
}

// Validate the demo inputs. Without this a malformed value went straight into the
// attendance row (demo_time "25:99" was accepted), and the audit log then records
// nonsense. Format only - demo scanning itself is governed by ALLOW_DEMO_SCANS.
if ($isDemoMode) {
    if ($demoDate !== "") {
        $dd = DateTime::createFromFormat("Y-m-d", $demoDate);
        if (!$dd || $dd->format("Y-m-d") !== $demoDate) {
            respond(false, "demo_date must be a real date in YYYY-MM-DD form.", null, 422);
        }
    }
    if ($demoTime !== "") {
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $demoTime)) {
            respond(false, "demo_time must be a valid 24-hour time (HH:MM).", null, 422);
        }
        if (strlen($demoTime) === 5) $demoTime .= ":00";
    }
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
$today   = ($isDemoMode && $demoDate !== "") ? $demoDate : date("Y-m-d");

// ---- 2. Determine the "current time" ----
$nowTime = date("H:i:s");
if ($isDemoMode && $demoTime !== "") {
    $nowTime = (strlen($demoTime) === 5) ? $demoTime . ":00" : $demoTime;
}

// ---- 3. Duplicate-scan guard -----------------------------------------
// A kiosk camera can fire twice for one physical scan. Real scans by the
// same employee inside 3 seconds are treated as one. Demo mode advances by
// simulated times, so the same simulated timestamp is the duplicate test.
$guardKey = "scan_guard_" . $empId;
$guard    = $_SESSION[$guardKey] ?? null;
$finger   = $today . " " . $nowTime;

if ($guard !== null) {
    $elapsed = microtime(true) - (float) ($guard["at"] ?? 0);
    $same    = ($guard["fp"] ?? "") === $finger;
    $dup     = $isDemoMode ? $same : ($elapsed < 3.0);
    if ($dup) {
        respond(false, "This scan was already recorded. Please wait a moment and scan again.", [
            "action"        => "duplicate",
            "employee_id"   => $empId,
            "employee_name" => $empName,
            "demo_mode"     => $isDemoMode,
        ], 409);
    }
}

$guardData = [
    "action"          => "in",
    "employee_id"     => $empId,
    "employee_name"   => $empName,
    "employee_number" => $qr["employee_number"],
    "time"            => date("h:i A", strtotime($nowTime)),
    "time_24"         => date("H:i", strtotime($nowTime)),
    "demo_mode"       => $isDemoMode,
];

// Mark this scan as handled. Saved before the response (respond exits).
$rememberScan = function (string $action) use ($finger, $guardKey) {
    $_SESSION[$guardKey] = ["fp" => $finger, "at" => microtime(true), "action" => $action];
};

// ---- 4. Today's attendance row ----
$stmt = $pdo->prepare(
    "SELECT attendance_id, time_in, time_out
     FROM attendance
     WHERE employee_id = ? AND work_date = ?
     ORDER BY attendance_id DESC LIMIT 1"
);
$stmt->execute([$empId, $today]);
$existing = $stmt->fetch();

// ---- 5. Clock IN ------------------------------------------------------
if (!$existing) {
    $workStart   = new DateTime("08:00:00");
    $actualTime  = new DateTime($nowTime);
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
    $stmt->execute([$empId, $qr["qr_code_id"], $today, $nowTime, $minutesLate, $status]);

    $rememberScan("in");
    $guardData["action"]       = "in";
    $guardData["late_minutes"] = $minutesLate;
    $guardData["status"]       = $status;

    respond(true, "$empName clocked in at " . date("h:i A", strtotime($nowTime)), $guardData);
}

// ---- 6. Clock OUT (the normal shift ends) -----------------------------
if ($existing["time_out"] === null) {
    $timeIn  = new DateTime($existing["time_in"]);
    $timeOut = new DateTime($nowTime);
    $diff    = $timeIn->diff($timeOut);
    $totalMinutes = ($diff->h * 60) + $diff->i;

    // FIX: Prevent accidental immediate clock-out (must be at least 1 minute)
    if ($totalMinutes < 1) {
        respond(false, "Clock-out time must be at least 1 minute after clock-in. If you are testing, please clear today's attendance record first.", null, 422);
    }

    $netMinutes  = $totalMinutes > 300 ? $totalMinutes - 60 : $totalMinutes;
    $hoursWorked = round($netMinutes / 60, 2);

    $stmt = $pdo->prepare("SELECT minutes_late FROM attendance WHERE attendance_id = ?");
    $stmt->execute([$existing["attendance_id"]]);
    $minutesLate = (int) $stmt->fetchColumn();
    $status = $minutesLate > 0 ? "Late" : "Present";

    $stmt = $pdo->prepare(
        "UPDATE attendance
         SET time_out = ?, hours_worked = ?, status = ?
         WHERE attendance_id = ?"
    );
    $stmt->execute([$nowTime, $hoursWorked, $status, $existing["attendance_id"]]);

    $rememberScan("out");
    $guardData["action"]       = "out";
    $guardData["hours_worked"] = $hoursWorked;
    $guardData["status"]       = $status;

    respond(true, "$empName clocked out at " . date("h:i A", strtotime($nowTime)), $guardData);
}

// ---- 7. Overtime states (only after the shift has ended) --------------
// The shift is over, so this scan is about overtime. Overtime NEVER creates
// another attendance row; it lives in overtime_records.
$stmt = $pdo->prepare(
    "SELECT ot_id, employee_id, ot_date, hours, ot_type, rate_multiplier,
            status, time_in, time_out, requested_at, approved_by, approved_at
     FROM overtime_records
     WHERE employee_id = ? AND ot_date = ?
     ORDER BY ot_id DESC LIMIT 1"
);
$stmt->execute([$empId, $today]);
$ot = $stmt->fetch();

$fileRequest = function () use ($pdo, $empId, $today, $nowTime) {
    // A request has no hours and no time_in: those only exist once an admin
    // has approved it AND the employee has actually started/finished.
    $stmt = $pdo->prepare(
        "INSERT INTO overtime_records
         (employee_id, ot_date, hours, ot_type, rate_multiplier,
          status, time_in, time_out, requested_at, approved_by, approved_at)
         VALUES (?, ?, NULL, 'Ordinary Day', 1.25, 'Pending', NULL, NULL, ?, NULL, NULL)"
    );
    $requestedAt = $today . " " . $nowTime;
    $stmt->execute([$empId, $today, $requestedAt]);
    return (int) $pdo->lastInsertId();
};

// 7a. No request yet, or the last block is finished: file a new request.
if (!$ot || $ot["status"] === "Completed"
    || ($ot["status"] === "Approved" && $ot["time_in"] !== null && $ot["time_out"] !== null)) {
    $otId = $fileRequest();

    $rememberScan("ot_request");
    $guardData["action"]       = "ot_request";
    $guardData["ot_id"]        = $otId;
    $guardData["status"]       = "Pending";
    $guardData["requested_at"] = $today . " " . $nowTime;

    respond(true, "Overtime request submitted - waiting for approval.", $guardData);
}

// 7b. Still waiting for the admin: refuse, and never start overtime.
if ($ot["status"] === "Pending") {
    $rememberScan("ot_pending");
    $guardData["action"] = "ot_pending";
    $guardData["ot_id"]  = (int) $ot["ot_id"];
    $guardData["status"] = "Pending";

    respond(false, "Your overtime request is still awaiting approval.", $guardData, 409);
}

// 7c. Rejected: never start overtime, but let the employee file a fresh
// request with this same scan so a rejection is not a dead end.
if ($ot["status"] === "Rejected") {
    $otId = $fileRequest();

    $rememberScan("ot_rejected");
    $guardData["action"]       = "ot_rejected";
    $guardData["ot_id"]        = $otId;
    $guardData["rejected_ot_id"] = (int) $ot["ot_id"];
    $guardData["status"]       = "Pending";

    respond(true, "Your overtime request was not approved. A new request has been submitted - waiting for approval.", $guardData);
}

// 7d. Approved but not started: this scan starts the overtime.
if ($ot["status"] === "Approved" && $ot["time_in"] === null) {
    $pdo->prepare("UPDATE overtime_records SET time_in = ? WHERE ot_id = ?")
        ->execute([$nowTime, $ot["ot_id"]]);

    $rememberScan("ot_in");
    $guardData["action"] = "ot_in";
    $guardData["ot_id"]  = (int) $ot["ot_id"];
    $guardData["status"] = "Approved";

    respond(true, "Overtime started at " . date("H:i", strtotime($nowTime)) . ".", $guardData);
}

// 7e. Approved and started but not ended: compute the overtime hours.
if ($ot["status"] === "Approved" && $ot["time_in"] !== null && $ot["time_out"] === null) {
    $inMinutes  = ((int) substr($ot["time_in"], 0, 2)) * 60 + (int) substr($ot["time_in"], 3, 2);
    $outMinutes = ((int) substr($nowTime, 0, 2)) * 60 + (int) substr($nowTime, 3, 2);
    if ($outMinutes < $inMinutes) $outMinutes += 24 * 60;   // crossed midnight
    $minutes = $outMinutes - $inMinutes;

    if ($minutes < 1) {
        respond(false, "Overtime clock-out must be at least 1 minute after overtime clock-in.", null, 422);
    }

    $hours = round($minutes / 60, 2);

    $stmt = $pdo->prepare(
        "UPDATE overtime_records
         SET time_out = ?, hours = ?, status = 'Completed'
         WHERE ot_id = ?"
    );
    $stmt->execute([$nowTime, $hours, $ot["ot_id"]]);

    $rememberScan("ot_out");
    $guardData["action"]   = "ot_out";
    $guardData["ot_id"]    = (int) $ot["ot_id"];
    $guardData["status"]   = "Completed";
    $guardData["hours"]    = $hours;
    $guardData["time_out"] = date("H:i", strtotime($nowTime));

    respond(true, "Overtime clock-out: " . number_format($hours, 2) . " hour(s).", $guardData);
}

// ---- 8. Anything else (unknown legacy state) files a fresh request ----
$otId = $fileRequest();

$rememberScan("ot_request");
$guardData["action"]       = "ot_request";
$guardData["ot_id"]        = $otId;
$guardData["status"]       = "Pending";
$guardData["requested_at"] = $today . " " . $nowTime;

respond(true, "Overtime request submitted - waiting for approval.", $guardData);
