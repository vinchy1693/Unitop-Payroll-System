<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read

$method = $_SERVER["REQUEST_METHOD"];

// Pay date per Philippine practice: the 1-15 cut-off is paid on the 20th, and
// the 16-end cut-off on the 5th of the following month. An explicit pay_date
// supplied by the client always wins.
function defaultPayDate(string $endDate): string {
    $d = new DateTime($endDate);
    if ((int) $d->format("d") <= 15) {
        return $d->format("Y-m-20");
    }
    return (new DateTime($d->format("Y-m-01")))->modify("+1 month")->format("Y-m-05");
}

// ---- GET: list periods with their latest run info ----
if ($method === "GET") {
    $sql = "SELECT pp.period_id, pp.start_date, pp.end_date, pp.pay_date, pp.period_type, pp.status,
                   pr.run_id, pr.run_date,
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
    $start = trim($in["start_date"]  ?? "");
    $end   = trim($in["end_date"]    ?? "");
    $type  = trim($in["period_type"] ?? "Semi-monthly");
    $payDate = trim($in["pay_date"] ?? "");

    if ($start === "" || $end === "") {
        respond(false, "Start Date and End Date are required.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End Date must be on or after Start Date.", null, 422);
    }

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
    // defaultPayDate() existed but was never called, so a blank Pay Date field
    // wrote '' into a DATE column; with no STRICT_TRANS_TABLES that becomes
    // 0000-00-00 and the periods table displays it as a real date.
    if ($payDate === "") $payDate = defaultPayDate($end);

    $stmt->execute([$start, $end, $payDate, $type]);

    respond(true, "Payroll period created.", ["period_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update (only if not Processed) ----
if ($method === "PUT") {
    $in    = json_input();
    $id    = (int)  ($in["period_id"]   ?? 0);
    $start = trim($in["start_date"]  ?? "");
    $end   = trim($in["end_date"]    ?? "");
    $type  = trim($in["period_type"] ?? "Semi-monthly");
    $payDate = trim($in["pay_date"] ?? "");

    if ($id === 0 || $start === "" || $end === "") {
        respond(false, "All fields are required.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End Date must be on or after Start Date.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT status FROM payroll_periods WHERE period_id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current === false) respond(false, "Payroll period not found.", null, 404);
    if ($current === "Processed") {
        respond(false, "Cannot edit a period that has already been processed.", null, 409);
    }

    $stmt = $pdo->prepare(
        "UPDATE payroll_periods
         SET start_date = ?, end_date = ?, pay_date = ?, period_type = ?
         WHERE period_id = ?"
    );
    if ($payDate === "") $payDate = defaultPayDate($end);

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
    if ($current === "Processed") {
        respond(false, "Cannot change status of a processed period.", null, 409);
    }

    $stmt = $pdo->prepare("UPDATE payroll_periods SET status = ? WHERE period_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status === "Closed" ? "Period closed." : "Period reopened.");
}

respond(false, "Method not allowed.", null, 405);