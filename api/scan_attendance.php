<?php
require __DIR__ . "/_bootstrap.php";
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

// The kiosk is a staff-operated, on-site device. Without this guard an Employee
// who logs into the admin app could open kiosk.html and clock in from anywhere.
// Employees record attendance only by scanning at the office kiosk.
requireRole(TEAM_QUEUE_ROLES);

$in         = json_input();
$tokenValue = trim((string) ($in["qr_token"] ?? ""));
$codeValue  = trim((string) ($in["code_value"] ?? ""));

// The portal displays a short-lived token (UNITOP-T-...). A legacy printed
// badge still sends the static code_value.
if ($tokenValue === "" && str_starts_with($codeValue, "UNITOP-T-")) {
    $tokenValue = $codeValue;
}
$isToken = $tokenValue !== "";

if (!$isToken && $codeValue === "") {
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

// ---- 1. Look up the QR code / token --------------------------------
$tokenId      = null;
$tokenPurpose = null;

if ($isToken) {
    $tokenHash = hash("sha256", $tokenValue);

    $stmt = $pdo->prepare(
        "SELECT t.token_id, t.employee_id, t.purpose, t.expires_at, t.used_at,
                (t.expires_at <= NOW()) AS expired,
                e.employee_number, e.is_active AS employee_active,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name
         FROM qr_tokens t
         JOIN employees e ON e.employee_id = t.employee_id
         WHERE t.token_hash = ?
         LIMIT 1"
    );
    $stmt->execute([$tokenHash]);
    $token = $stmt->fetch();

    if (!$token) {
        respond(false, "This QR code is invalid or has expired. Refresh your QR code and try again.", null, 401);
    }
    if ($token["used_at"] !== null) {
        respond(false, "This QR code has already been used. Refresh your QR code.", null, 409);
    }
    if ((int) $token["expired"] === 1) {
        respond(false, "This QR code has expired. Refresh your QR code and try again.", null, 409);
    }
    if ((int) $token["employee_active"] !== 1) {
        respond(false, "This employee record is inactive.", null, 403);
    }

    $qr = [
        "qr_code_id"      => null,
        "employee_id"     => $token["employee_id"],
        "status"          => "Active",
        "employee_name"   => $token["employee_name"],
        "employee_number" => $token["employee_number"],
    ];
    $tokenId      = (int) $token["token_id"];
    $tokenPurpose = (string) $token["purpose"];
} else {
    if (!ALLOW_LEGACY_QR) {
        respond(false, "This is a legacy static QR code. Ask HR for a short-lived QR code.", null, 403);
    }

    $stmt = $pdo->prepare(
        "SELECT q.qr_code_id, q.employee_id, q.status,
                e.employee_number, e.is_active AS employee_active,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name
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
    if ((int) $qr["employee_active"] !== 1) {
        respond(false, "This employee record is inactive.", null, 403);
    }
}

$empId   = (int) $qr["employee_id"];
$empName = $qr["employee_name"];
$today   = ($isDemoMode && $demoDate !== "") ? $demoDate : date("Y-m-d");

// ---- 2. Determine the "current time" ----
$nowTime = date("H:i:s");
if ($isDemoMode && $demoTime !== "") {
    $nowTime = (strlen($demoTime) === 5) ? $demoTime . ":00" : $demoTime;
}

// No kiosk write on a date whose payroll has already been signed off.
assertAttendanceDateUnlocked($pdo, $today, "Attendance for this date");

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

$consumeToken = function () use ($pdo, $tokenId): void {
    if ($tokenId !== null) {
        $pdo->prepare("UPDATE qr_tokens SET used_at = NOW() WHERE token_id = ? AND used_at IS NULL")
            ->execute([$tokenId]);
    }
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

// A time_in token must find no attendance yet; a time_out token must find an
// open shift. This stops a stale in-token from clocking out (or vice versa).
if ($isToken) {
    if ($tokenPurpose === "time_in" && $existing) {
        respond(false, "You are already clocked in for today. Refresh your QR code to clock out.", null, 409);
    }
    if ($tokenPurpose === "time_out"
        && (!$existing || $existing["time_in"] === null || $existing["time_out"] !== null)) {
        respond(false, "There is no open shift to clock out of. Refresh your QR code.", null, 409);
    }
    if ($tokenPurpose === "ot"
        && (!$existing || $existing["time_out"] === null)) {
        respond(false, "Complete your regular shift before using an overtime QR code.", null, 409);
    }
    if (!in_array($tokenPurpose, ["time_in", "time_out", "ot"], true)) {
        respond(false, "This QR code is not valid for an attendance action.", null, 422);
    }
}

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

    $consumeToken();
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

    $consumeToken();
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
            requested_start, requested_end,
            status, time_in, time_out, requested_at, approved_by, approved_at
     FROM overtime_records
     WHERE employee_id = ? AND ot_date = ?
     ORDER BY ot_id DESC LIMIT 1"
);
$stmt->execute([$empId, $today]);
$ot = $stmt->fetch();

$fileRequest = function () use ($pdo, $empId, $today, $nowTime) {
    // The kiosk request is after the shift, so it has no requested end; the
    // actual block is measured by the OT clock-in/clock-out. The day type and
    // rate are still derived from the date, not hardcoded to an ordinary day.
    $dayType    = dayTypeForDate($pdo, $today);
    $multiplier = overtimeMultiplierForDayType($dayType);

    // A request has no hours and no time_in: those only exist once an admin
    // has approved it AND the employee has actually started/finished.
    $stmt = $pdo->prepare(
        "INSERT INTO overtime_records
         (employee_id, ot_date, hours, ot_type, rate_multiplier, reason,
          requested_start, requested_end, day_type, status, time_in, time_out,
          requested_at, approved_by, approved_at)
         VALUES (?, ?, NULL, ?, ?, ?, ?, NULL, ?, 'Pending', NULL, NULL, ?, NULL, NULL)"
    );
    $requestedAt = $today . " " . $nowTime;
    $stmt->execute([
        $empId, $today, $dayType, $multiplier,
        "Kiosk overtime request filed after the shift.",
        $nowTime, $dayType, $requestedAt,
    ]);
    return (int) $pdo->lastInsertId();
};

// 7a. No request yet, or the last block is finished: file a new request.
if (!$ot || $ot["status"] === "Completed"
    || ($ot["status"] === "Approved" && $ot["time_in"] !== null && $ot["time_out"] !== null)) {
    $otId = $fileRequest();

    $consumeToken();
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

    $consumeToken();
    $rememberScan("ot_rejected");
    $guardData["action"]       = "ot_rejected";
    $guardData["ot_id"]        = $otId;
    $guardData["rejected_ot_id"] = (int) $ot["ot_id"];
    $guardData["status"]       = "Pending";

    respond(true, "Your overtime request was not approved. A new request has been submitted - waiting for approval.", $guardData);
}

// 7d. Approved but not started: this scan starts the overtime.
if ($ot["status"] === "Approved" && $ot["time_in"] === null) {
    $window = overtimeWindowViolation($ot, $nowTime, "in");
    if ($window !== null) {
        $guardData["action"] = "ot_window";
        $guardData["code"]   = $window["code"];
        respond(false, $window["message"], $guardData, 409);
    }

    $pdo->prepare("UPDATE overtime_records SET time_in = ? WHERE ot_id = ?")
        ->execute([$nowTime, $ot["ot_id"]]);

    $consumeToken();
    $rememberScan("ot_in");
    $guardData["action"] = "ot_in";
    $guardData["ot_id"]  = (int) $ot["ot_id"];
    $guardData["status"] = "Approved";

    respond(true, "Overtime started at " . date("H:i", strtotime($nowTime)) . ".", $guardData);
}

// 7e. Approved and started but not ended: compute the overtime hours.
if ($ot["status"] === "Approved" && $ot["time_in"] !== null && $ot["time_out"] === null) {
    $window = overtimeWindowViolation($ot, $nowTime, "out");
    if ($window !== null && $window["code"] === "ot_too_early_out") {
        $guardData["action"] = "ot_window";
        $guardData["code"]   = $window["code"];
        respond(false, $window["message"], $guardData, 409);
    }

    // A late clock-out is recorded as-is, but the paid block stops at the
    // approved end; the excess needs a new approved request.
    $payTo = $window["cap_end"] ?? $nowTime;

    // Same meal-break rule as the regular shift: a block longer than five hours
    // loses one 60-minute meal break. computeOvertimeHours() also handles a
    // block that crosses midnight.
    $hours = computeOvertimeHours((string) $ot["time_in"], $payTo);

    if ($hours <= 0) {
        respond(false, "Overtime clock-out must be at least 1 minute after overtime clock-in.", null, 422);
    }

    $stmt = $pdo->prepare(
        "UPDATE overtime_records
         SET time_out = ?, hours = ?, status = 'Completed'
         WHERE ot_id = ?"
    );
    $stmt->execute([$nowTime, $hours, $ot["ot_id"]]);

    $consumeToken();
    $rememberScan("ot_out");
    $guardData["action"]   = "ot_out";
    $guardData["ot_id"]    = (int) $ot["ot_id"];
    $guardData["status"]   = "Completed";
    $guardData["hours"]    = $hours;
    $guardData["time_out"] = date("H:i", strtotime($nowTime));
    $guardData["capped"]   = $window !== null && $window["code"] === "ot_pay_capped";

    $message = "Overtime clock-out: " . number_format($hours, 2) . " hour(s).";
    if ($guardData["capped"]) {
        $message .= " " . $window["message"];
    }
    respond(true, $message, $guardData);
}

// ---- 8. Anything else (unknown legacy state) files a fresh request ----
$otId = $fileRequest();

$rememberScan("ot_request");
$guardData["action"]       = "ot_request";
$guardData["ot_id"]        = $otId;
$guardData["status"]       = "Pending";
$guardData["requested_at"] = $today . " " . $nowTime;

respond(true, "Overtime request submitted - waiting for approval.", $guardData);
