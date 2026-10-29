<?php
// =====================================================================
// api/bank_file.php
//
// The maker's payment outputs. One GET endpoint, three CSV files:
//
//   GET bank_file.php?run_id=<id>&format=bank|register|remittance
//
//   bank       - the bank file: one credit line per payslip + a total row.
//                Refused with 422 when any included employee has no bank
//                name / account number: a payment file with missing
//                accounts is worse than no file.
//   register   - the payroll register for the whole run + a totals row.
//   remittance - SSS / PhilHealth / Pag-IBIG employee AND employer amounts
//                per employee + a grand total, for filing with the agencies.
//
// Guard: PAYROLL_ROLES (the maker prepares the file, the checker reviews
// it). Employee and Administrator -> 403.
//
// A Returned run is being reworked, so its figures are not final: every
// format answers 409 until it is resubmitted.
//
// respond() only audits non-GET requests, and a CSV response never goes
// through respond() at all, so every attempt that reaches this endpoint
// writes its own audit_log row (exactly like the portal's me_profile.php).
// Account numbers are deliberately NOT copied into the audit details.
// =====================================================================

require __DIR__ . "/_bootstrap.php";

const BANK_FILE_FORMATS = ["bank", "register", "remittance"];

// =====================================================================
// Helpers
// =====================================================================

/**
 * One audit row for this export attempt. Written before the response, for
 * successes and refusals alike, so the Administrator can see who tried to
 * export what. Never let a failing audit break a download.
 */
function bank_file_audit(
    string $format,
    int $runId,
    int $status,
    bool $success,
    string $message,
    array $details = []
): void {
    $pdo = $GLOBALS["pdo"] ?? null;
    if (!($pdo instanceof PDO)) return;

    $actor = audit_actor();

    // The attempt itself, then whatever the caller adds (never amounts or
    // account numbers - the audit trail is not a payroll register).
    $details = ["run_id" => $runId > 0 ? $runId : null]
             + ($format !== "" ? ["format" => $format] : [])
             + $details;

    $encoded = json_encode(
        $details,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO `audit_log`
                (`user_id`, `username`, `role`, `action`, `method`, `http_status`,
                 `success`, `message`, `target`, `details`, `ip`)
             VALUES (?, ?, ?, 'bank_file.php', 'GET', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $actor["user_id"],
            audit_trim($actor["username"], 100),
            audit_trim($actor["role"], 50),
            $status,
            ($success && $status < 400) ? 1 : 0,
            audit_trim($message, 500),
            $runId > 0 ? "run_id=" . $runId : null,
            is_string($encoded) ? $encoded : null,
            audit_trim($_SERVER["REMOTE_ADDR"] ?? null, 45),
        ]);
    } catch (Throwable $e) {
        error_log("bank_file audit_log write failed: " . $e->getMessage());
    }
}

/**
 * One CSV cell.
 *
 * A leading =, +, - or @ is what a spreadsheet treats as the start of a
 * formula, so any such cell is prefixed with a single quote - a name like
 * "=cmd|' /C calc'!A0" can never execute. The cell is then quoted only if it
 * contains a comma, a double quote or a line break (RFC 4180).
 */
function bank_file_cell($value): string {
    $s = (string) $value;

    if ($s !== "" && strpbrk($s[0], "=+-@\t\r") !== false) {
        $s = "'" . $s;
    }
    if (strpbrk($s, ",\"\n\r") !== false) {
        $s = '"' . str_replace('"', '""', $s) . '"';
    }
    return $s;
}

/** One CSV record, LF line ending only. */
function bank_file_line(array $cells): string {
    return implode(",", array_map("bank_file_cell", $cells)) . "\n";
}

/** Two-decimal money, machine-readable (no thousands separator), never -0.00. */
function bank_file_money($value): string {
    $n = (float) $value;
    if (abs($n) < 0.005) $n = 0.0;
    return number_format($n, 2, ".", "");
}

/** Emit the file and stop. The audit row must already be written. */
function bank_file_send(array $lines, string $filename): void {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    header("Cache-Control: no-store");
    echo implode("", $lines);
    exit;
}

// =====================================================================
// Request
// =====================================================================

$method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
$runId  = (int) ($_GET["run_id"] ?? 0);
$format = strtolower(trim((string) ($_GET["format"] ?? "")));

// ---- role guard: maker + checker + Administrator ----
// Spelled out rather than requireRole() so that a refused attempt is itself
// audited: an export attempt by the wrong role is exactly what the trail is
// for.
requireLogin();
$user = currentUser();
if (!in_array($user["role"], array_merge(PAYROLL_ROLES, ADMIN_ROLES), true)) {
    bank_file_audit(
        $format,
        $runId,
        403,
        false,
        "Export refused: the {$user['role']} role may not export payroll files."
    );
    respond(false, "You do not have permission to perform this action.", null, 403);
}

if ($method !== "GET") {
    // respond() audits non-GET requests itself.
    respond(false, "Method not allowed.", null, 405);
}

// File exports are disabled until the bank and government-agency templates
// are supplied and tested. Refuse before any employee or account data is read.
if (!PAYROLL_EXPORTS_ENABLED) {
    bank_file_audit(
        $format,
        $runId,
        501,
        false,
        "Payroll file exports are disabled until bank and agency formats are configured."
    );
    respond(
        false,
        "Payroll file exports are disabled until bank and agency formats are configured and tested.",
        null,
        501
    );
}

if ($runId <= 0) {
    bank_file_audit($format, 0, 422, false, "Export refused: run_id is required.");
    respond(false, "run_id is required.", null, 422);
}

if (!in_array($format, BANK_FILE_FORMATS, true)) {
    bank_file_audit(
        $format,
        $runId,
        422,
        false,
        "Export refused: format must be bank, register or remittance."
    );
    respond(false, "format must be bank, register or remittance.", null, 422);
}

// ---- the run ----
$stmt = $pdo->prepare(
    "SELECT pr.run_id, pr.status, pr.run_date,
            pp.start_date, pp.end_date, pp.period_type
     FROM payroll_runs pr
     JOIN payroll_periods pp ON pp.period_id = pr.period_id
     WHERE pr.run_id = ?"
);
$stmt->execute([$runId]);
$run = $stmt->fetch();

if (!$run) {
    bank_file_audit($format, $runId, 404, false, "Export refused: payroll run #$runId was not found.");
    respond(false, "Payroll run not found.", null, 404);
}

if ((string) $run["status"] === "Returned") {
    $message = "Payroll run #$runId is Returned and is being reworked, so its figures are not final. "
             . "Exports are refused until it is resubmitted.";
    bank_file_audit($format, $runId, 409, false, $message, ["status" => "Returned"]);
    respond(false, $message, ["run_id" => $runId, "status" => "Returned"], 409);
}

// The file name always carries the period dates and the format.
$prefixes = [
    "bank"       => "bank-file",
    "register"   => "payroll-register",
    "remittance" => "govt-remittance",
];
$filename = $prefixes[$format] . "_" . $run["start_date"] . "_" . $run["end_date"] . ".csv";
$auditTail = [
    "status"    => (string) $run["status"],
    "period"    => (string) $run["start_date"] . " to " . (string) $run["end_date"],
    "file"      => $filename,
];

// =====================================================================
// format=bank
// =====================================================================
if ($format === "bank") {
    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, ps.net_pay,
                e.employee_id, e.employee_number,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                e.bank_name, e.bank_account_name, e.bank_account_number
         FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         WHERE ps.run_id = ?
         ORDER BY e.last_name, e.first_name, ps.payslip_id"
    );
    $stmt->execute([$runId]);
    $payslips = $stmt->fetchAll();

    // ---- refuse the whole file if any account is incomplete ----
    $missing = [];
    foreach ($payslips as $ps) {
        $noBank     = trim((string) $ps["bank_name"]) === "";
        $noAccount  = trim((string) $ps["bank_account_number"]) === "";
        if (!$noBank && !$noAccount) continue;

        $wanted = [];
        if ($noBank)    $wanted[] = "bank name";
        if ($noAccount) $wanted[] = "bank account number";

        $missing[] = [
            "employee_id"     => (int) $ps["employee_id"],
            "employee_number" => (string) $ps["employee_number"],
            "employee_name"   => (string) $ps["employee_name"],
            "missing"         => $wanted,
        ];
    }

    if ($missing) {
        $labels = [];
        foreach ($missing as $m) {
            $labels[] = $m["employee_number"] . " - " . $m["employee_name"]
                      . " (missing " . implode(" and ", $m["missing"]) . ")";
        }
        $message = "Bank file not generated: " . count($missing)
                 . " employee(s) have no complete bank details on file. "
                 . "Add the payment details and export again. Missing: "
                 . implode("; ", $labels) . ".";

        bank_file_audit($format, $runId, 422, false, $message, $auditTail + [
            "missing_employees" => array_column($missing, "employee_number"),
        ]);
        respond(false, $message, ["run_id" => $runId, "missing" => $missing], 422);
    }

    $lines = [bank_file_line([
        "employee_number", "employee_name", "bank_name", "bank_account_name",
        "bank_account_number", "net_pay",
    ])];

    $total = 0.0;
    foreach ($payslips as $ps) {
        $net    = (float) $ps["net_pay"];
        $total += $net;
        $lines[] = bank_file_line([
            $ps["employee_number"],
            $ps["employee_name"],
            $ps["bank_name"],
            $ps["bank_account_name"],
            $ps["bank_account_number"],
            bank_file_money($net),
        ]);
    }
    // Trailing total row: label in the first cell, blank filler in between.
    $lines[] = bank_file_line(["TOTAL", "", "", "", "", bank_file_money($total)]);

    bank_file_audit(
        $format,
        $runId,
        200,
        true,
        "Bank file exported: " . count($payslips) . " payment row(s).",
        $auditTail + ["payslips" => count($payslips)]
    );
    bank_file_send($lines, $filename);
}

// =====================================================================
// format=register
// =====================================================================
if ($format === "register") {
    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, ps.basic_pay, ps.overtime_pay, ps.gross_pay,
                ps.total_deductions, ps.net_pay,
                e.employee_number,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                d.department_name,
                p.title AS position_title,
                COALESCE(gc.sss_ee, 0)          AS sss_ee,
                COALESCE(gc.philhealth_ee, 0)   AS philhealth_ee,
                COALESCE(gc.pagibig_ee, 0)      AS pagibig_ee,
                COALESCE(gc.withholding_tax, 0) AS withholding_tax
         FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN positions   p ON p.position_id   = e.position_id
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE ps.run_id = ?
         ORDER BY e.last_name, e.first_name, ps.payslip_id"
    );
    $stmt->execute([$runId]);
    $payslips = $stmt->fetchAll();

    // "Other deductions" is every row in `deductions` for the payslip; the
    // statutory shares and the withholding tax come from govt_contributions.
    // total_deductions on the payslip is exactly their sum (see
    // recomputePayslipTotals), capped at gross pay when pay is insufficient.
    $detailStmt = $pdo->prepare(
        "SELECT d.payslip_id, COALESCE(SUM(d.amount), 0) AS amount
         FROM deductions d
         JOIN payslips ps ON ps.payslip_id = d.payslip_id
         WHERE ps.run_id = ?
         GROUP BY d.payslip_id"
    );
    $detailStmt->execute([$runId]);
    $otherDeductions = [];
    foreach ($detailStmt->fetchAll() as $row) {
        $otherDeductions[(int) $row["payslip_id"]] = (float) $row["amount"];
    }

    $lines = [bank_file_line([
        "employee_number", "employee_name", "position", "department",
        "basic_pay", "overtime_pay", "gross_pay",
        "sss_ee", "philhealth_ee", "pagibig_ee", "withholding_tax",
        "other_deductions", "total_deductions", "net_pay",
    ])];

    $totals = array_fill(0, 10, 0.0);
    foreach ($payslips as $ps) {
        $numbers = [
            (float) $ps["basic_pay"],
            (float) $ps["overtime_pay"],
            (float) $ps["gross_pay"],
            (float) $ps["sss_ee"],
            (float) $ps["philhealth_ee"],
            (float) $ps["pagibig_ee"],
            (float) $ps["withholding_tax"],
            $otherDeductions[(int) $ps["payslip_id"]] ?? 0.0,
            (float) $ps["total_deductions"],
            (float) $ps["net_pay"],
        ];
        foreach ($numbers as $i => $value) {
            $totals[$i] += $value;
        }
        $lines[] = bank_file_line(array_merge([
            $ps["employee_number"],
            $ps["employee_name"],
            $ps["position_title"],
            $ps["department_name"],
        ], array_map("bank_file_money", $numbers)));
    }

    $totalRow = ["TOTAL", "", "", ""];
    foreach ($totals as $value) {
        $totalRow[] = bank_file_money($value);
    }
    $lines[] = bank_file_line($totalRow);

    bank_file_audit(
        $format,
        $runId,
        200,
        true,
        "Payroll register exported: " . count($payslips) . " payslip(s).",
        $auditTail + ["payslips" => count($payslips)]
    );
    bank_file_send($lines, $filename);
}

// =====================================================================
// format=remittance
// =====================================================================
$stmt = $pdo->prepare(
    "SELECT ps.payslip_id,
            e.employee_number,
            CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
            COALESCE(gc.sss_ee, 0)        AS sss_ee,
            COALESCE(gc.sss_er, 0)        AS sss_er,
            COALESCE(gc.philhealth_ee, 0) AS philhealth_ee,
            COALESCE(gc.philhealth_er, 0) AS philhealth_er,
            COALESCE(gc.pagibig_ee, 0)    AS pagibig_ee,
            COALESCE(gc.pagibig_er, 0)    AS pagibig_er
     FROM payslips ps
     JOIN employees e ON e.employee_id = ps.employee_id
     LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
     WHERE ps.run_id = ?
     ORDER BY e.last_name, e.first_name, ps.payslip_id"
);
$stmt->execute([$runId]);
$payslips = $stmt->fetchAll();

$lines = [bank_file_line([
    "employee_number", "employee_name",
    "sss_ee", "sss_er", "sss_total",
    "philhealth_ee", "philhealth_er", "philhealth_total",
    "pagibig_ee", "pagibig_er", "pagibig_total",
    "grand_total",
])];

$totals = array_fill(0, 10, 0.0);
foreach ($payslips as $ps) {
    $sssEe = (float) $ps["sss_ee"];
    $sssEr = (float) $ps["sss_er"];
    $phEe  = (float) $ps["philhealth_ee"];
    $phEr  = (float) $ps["philhealth_er"];
    $piEe  = (float) $ps["pagibig_ee"];
    $piEr  = (float) $ps["pagibig_er"];

    $sssTotal = round($sssEe + $sssEr, 2);
    $phTotal  = round($phEe + $phEr, 2);
    $piTotal  = round($piEe + $piEr, 2);

    $numbers = [
        $sssEe, $sssEr, $sssTotal,
        $phEe, $phEr, $phTotal,
        $piEe, $piEr, $piTotal,
        round($sssTotal + $phTotal + $piTotal, 2),
    ];
    foreach ($numbers as $i => $value) {
        $totals[$i] += $value;
    }

    $lines[] = bank_file_line(array_merge(
        [$ps["employee_number"], $ps["employee_name"]],
        array_map("bank_file_money", $numbers)
    ));
}

$grandRow = ["GRAND TOTAL", ""];
foreach ($totals as $value) {
    $grandRow[] = bank_file_money($value);
}
$lines[] = bank_file_line($grandRow);

bank_file_audit(
    $format,
    $runId,
    200,
    true,
    "Remittance file exported: " . count($payslips) . " employee(s).",
    $auditTail + ["payslips" => count($payslips)]
);
bank_file_send($lines, $filename);
