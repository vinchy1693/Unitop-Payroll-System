<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read

$method = $_SERVER["REQUEST_METHOD"];

// The only period labels the payroll engine understands. "13th Month" is a
// separate extra run and may legitimately overlap a working period; two
// periods of the same label may not overlap.
const PERIOD_TYPES = ["Semi-monthly", "13th Month"];

function validPeriodDate(string $value): bool {
    $dt = DateTime::createFromFormat("!Y-m-d", $value);
    return $dt !== false && $dt->format("Y-m-d") === $value;
}

// Pay date per Philippine practice: the 1-15 cut-off is paid on the 20th, and
// the 16-end cut-off on the 5th of the following month. The business rule is
// at least five calendar days after the period end; both defaults equal that.
function minimumPayDate(string $endDate): string {
    return (new DateTime($endDate))->modify("+5 days")->format("Y-m-d");
}

function defaultPayDate(string $endDate): string {
    return minimumPayDate($endDate);
}

/**
 * Derive the two statutory semi-monthly periods from a month + half.
 * half = first  -> 01..15
 * half = second -> 16..last day
 */
function periodDatesFromSelection(string $month, string $half): ?array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return null;
    $first = DateTime::createFromFormat('!Y-m-d', $month . '-01');
    if (!$first || $first->format('Y-m') !== $month) return null;

    if ($half === 'first') {
        return [$month . '-01', $month . '-15'];
    }
    if ($half === 'second') {
        return [$month . '-16', $month . '-' . $first->format('t')];
    }
    return null;
}

function assertValidPayDate(string $payDate, string $end, string $label = "Pay Date"): void {
    if (!validPeriodDate($payDate)) {
        respond(false, "$label must be a real date in YYYY-MM-DD form.", null, 422);
    }
    $minimum = minimumPayDate($end);
    if ($payDate < $minimum) {
        respond(false, "$label must be at least 5 days after the period end (minimum $minimum).", null, 422);
    }
}

// ---- GET: list periods with their latest run info ----
if ($method === "GET") {
    $sql = "SELECT pp.period_id, pp.start_date, pp.end_date, pp.pay_date, pp.period_type, pp.status,
                   pr.run_id, pr.run_date, pr.status AS run_status,
                   (SELECT COUNT(*) FROM payslips ps WHERE ps.run_id = pr.run_id) AS payslip_count
            FROM payroll_periods pp
            LEFT JOIN payroll_runs pr ON pr.run_id = (
                SELECT MAX(run_id) FROM payroll_runs WHERE period_id = pp.period_id
            )
            ORDER BY pp.start_date DESC, pp.period_id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: create ----
requireRole(MAKER_ROLES);   // only the Payroll Officer may change periods

if ($method === "POST") {
    $in    = json_input();
    $month = trim($in["period_month"] ?? "");
    $half  = trim($in["period_half"] ?? "");
    $type  = trim($in["period_type"] ?? "Semi-monthly");
    $payDate = trim($in["pay_date"] ?? "");

    if ($month !== "" || $half !== "") {
        $dates = periodDatesFromSelection($month, $half);
        if ($dates === null) {
            respond(false, "Choose a valid month and payroll period (First period or Second period).", null, 422);
        }
        [$start, $end] = $dates;
        $type = "Semi-monthly";
    } else {
        // Legacy API clients may still send explicit dates.
        $start = trim($in["start_date"] ?? "");
        $end   = trim($in["end_date"] ?? "");
    }

    if ($start === "" || $end === "") {
        respond(false, "Choose the payroll month and period.", null, 422);
    }
    if (!validPeriodDate($start) || !validPeriodDate($end)) {
        respond(false, "Period dates must be real dates in YYYY-MM-DD form.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End Date must be on or after Start Date.", null, 422);
    }
    if (!in_array($type, PERIOD_TYPES, true)) {
        respond(false, "Period type must be one of: " . implode(", ", PERIOD_TYPES) . ".", null, 422);
    }
    if ($payDate === "") $payDate = defaultPayDate($end);
    assertValidPayDate($payDate, $end);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM payroll_periods
         WHERE period_type = ? AND NOT (end_date < ? OR start_date > ?)"
    );
    $stmt->execute([$type, $start, $end]);
    if ((int) $stmt->fetchColumn() > 0) {
        respond(false, "This period overlaps with an existing $type period.", null, 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO payroll_periods (start_date, end_date, pay_date, period_type, status)
         VALUES (?, ?, ?, ?, 'Open')"
    );
    $stmt->execute([$start, $end, $payDate, $type]);

    respond(true, "Payroll period created.", [
        "period_id" => (int) $pdo->lastInsertId(),
        "start_date" => $start,
        "end_date" => $end,
        "pay_date" => $payDate,
    ]);
}

// ---- PUT: update (only if not Processed) ----
if ($method === "PUT") {
    $in    = json_input();
    $id    = (int)  ($in["period_id"]   ?? 0);
    $month = trim($in["period_month"] ?? "");
    $half  = trim($in["period_half"] ?? "");
    $type  = trim($in["period_type"] ?? "Semi-monthly");
    $payDate = trim($in["pay_date"] ?? "");

    if ($id === 0) {
        respond(false, "period_id is required.", null, 422);
    }

    if ($month !== "" || $half !== "") {
        $dates = periodDatesFromSelection($month, $half);
        if ($dates === null) {
            respond(false, "Choose a valid month and payroll period (First period or Second period).", null, 422);
        }
        [$start, $end] = $dates;
        $type = "Semi-monthly";
    } else {
        $start = trim($in["start_date"] ?? "");
        $end   = trim($in["end_date"] ?? "");
    }

    if ($start === "" || $end === "") {
        respond(false, "Choose the payroll month and period.", null, 422);
    }
    if (!validPeriodDate($start) || !validPeriodDate($end)) {
        respond(false, "Period dates must be real dates in YYYY-MM-DD form.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End Date must be on or after Start Date.", null, 422);
    }
    if (!in_array($type, PERIOD_TYPES, true)) {
        respond(false, "Period type must be one of: " . implode(", ", PERIOD_TYPES) . ".", null, 422);
    }
    if ($payDate === "") $payDate = defaultPayDate($end);
    assertValidPayDate($payDate, $end);

    $stmt = $pdo->prepare("SELECT status FROM payroll_periods WHERE period_id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current === false) respond(false, "Payroll period not found.", null, 404);
    if ($current !== "Open") {
        respond(false, "Only Open periods can be edited. This one is $current.", null, 409);
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM payroll_periods
         WHERE period_type = ? AND period_id <> ?
           AND NOT (end_date < ? OR start_date > ?)"
    );
    $stmt->execute([$type, $id, $start, $end]);
    if ((int) $stmt->fetchColumn() > 0) {
        respond(false, "This period overlaps with an existing $type period.", null, 409);
    }

    $stmt = $pdo->prepare(
        "UPDATE payroll_periods
         SET start_date = ?, end_date = ?, pay_date = ?, period_type = ?
         WHERE period_id = ?"
    );
    $stmt->execute([$start, $end, $payDate, $type, $id]);

    respond(true, "Payroll period updated.");
}

// ---- PATCH: change status (Open <-> Closed) ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int)  ($in["period_id"] ?? 0);
    $status = trim($in["status"] ?? "");

    if ($id === 0 || !in_array($status, ["Open", "Closed"], true)) {
        respond(false, "Valid period_id and status (Open or Closed) are required.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT status FROM payroll_periods WHERE period_id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if (!in_array($current, ["Open", "Closed"], true)) {
        respond(false, "Cannot change status of a period that is $current.", null, 409);
    }

    $stmt = $pdo->prepare("UPDATE payroll_periods SET status = ? WHERE period_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status === "Closed" ? "Period closed." : "Period reopened.");
}

respond(false, "Method not allowed.", null, 405);