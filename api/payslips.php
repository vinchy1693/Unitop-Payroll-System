<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read
require __DIR__ . "/_payroll_helpers.php";

$method = $_SERVER["REQUEST_METHOD"];

// ---- PUT: edit a payslip's basic / overtime pay (maker, unlocked only) ----
// Line items (allowances / deductions) have their own endpoints; this is the
// payslip-level correction the matrix calls "write".
if ($method === "PUT") {
    requireRole(MAKER_ROLES);   // only the Payroll Officer may edit a payslip

    $in        = json_input();
    $id        = (int)   ($in["payslip_id"] ?? 0);
    $basic     = (float) ($in["basic_pay"] ?? -1);
    $overtime  = (float) ($in["overtime_pay"] ?? 0);

    if ($id === 0) {
        respond(false, "payslip_id is required.", null, 422);
    }
    if (array_key_exists("basic_pay", $in) && $basic < 0) {
        respond(false, "basic_pay cannot be negative.", null, 422);
    }

    // Approved / Released runs are immutable: a formal adjustment is required.
    assertPayslipUnlocked($pdo, $id, "This payslip");

    $stmt = $pdo->prepare("SELECT basic_pay, overtime_pay FROM payslips WHERE payslip_id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if (!$current) respond(false, "Payslip not found.", null, 404);

    $newBasic = array_key_exists("basic_pay", $in) ? round($basic, 2) : (float) $current["basic_pay"];
    $newOt    = array_key_exists("overtime_pay", $in) ? round($overtime, 2) : (float) $current["overtime_pay"];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE payslips SET basic_pay = ?, overtime_pay = ? WHERE payslip_id = ?")
            ->execute([$newBasic, $newOt, $id]);
        $totals = recomputePayslipTotals($pdo, $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respondServerError("The payslip could not be updated. Please try again.", $e, "payslips");
    }

    respond(true, "Payslip updated.", ["payslip_id" => $id, "totals" => $totals]);
}

if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

// ---- GET ?run_id=N : all payslips for a specific run ----
if (isset($_GET["run_id"])) {
    $runId = (int) $_GET["run_id"];

    // Official payslips are released documents. Payroll/HR may review the
    // run-level totals before approval, but cannot open employee payslips
    // until the run reaches Released.
    $statusStmt = $pdo->prepare("SELECT status FROM payroll_runs WHERE run_id = ?");
    $statusStmt->execute([$runId]);
    $runStatus = $statusStmt->fetchColumn();
    if ($runStatus === false) {
        respond(false, "Payroll run not found.", null, 404);
    }
    if ($runStatus !== "Released") {
        respond(false, "Payslips are available only after the payroll run is released.", ["run_status" => $runStatus], 403);
    }

    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, ps.run_id, ps.employee_id,
                ps.basic_pay, ps.overtime_pay, ps.premium_pay, ps.gross_pay,
                ps.total_deductions, ps.net_pay,
                e.employee_number,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                d.department_name,
                p.title AS position_title,
                gc.sss_ee, gc.sss_er,
                gc.philhealth_ee, gc.philhealth_er,
                gc.pagibig_ee, gc.pagibig_er,
                gc.withholding_tax
         FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN positions   p ON p.position_id   = e.position_id
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE ps.run_id = ?
         ORDER BY e.last_name, e.first_name"
    );
    $stmt->execute([$runId]);
    respond(true, "", $stmt->fetchAll());
}

// ---- GET ?id=N : single payslip detail with line items ----
if (isset($_GET["id"])) {
    $id = (int) $_GET["id"];

    $stmt = $pdo->prepare(
        "SELECT ps.*, e.employee_number,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                d.department_name,
                p.title AS position_title,
                pp.start_date, pp.end_date, pp.period_type,
                pr.status AS run_status,
                gc.sss_ee, gc.sss_er,
                gc.philhealth_ee, gc.philhealth_er,
                gc.pagibig_ee, gc.pagibig_er,
                gc.withholding_tax
         FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN positions   p ON p.position_id   = e.position_id
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE ps.payslip_id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, "Payslip not found.", null, 404);
    if (($row["run_status"] ?? "") !== "Released") {
        respond(false, "Payslips are available only after the payroll run is released.", ["run_status" => $row["run_status"] ?? null], 403);
    }

    // Attach allowances
    $stmt = $pdo->prepare(
        "SELECT allowance_id, allowance_type, amount
         FROM allowances WHERE payslip_id = ? ORDER BY allowance_id"
    );
    $stmt->execute([$id]);
    $row["allowances"] = $stmt->fetchAll();

    // Attach non-government deductions
    $stmt = $pdo->prepare(
        "SELECT deduction_id, deduction_type, amount
         FROM deductions WHERE payslip_id = ? ORDER BY deduction_id"
    );
    $stmt->execute([$id]);
    $row["deductions"] = $stmt->fetchAll();

    // Attendance is subtracted from Adjusted Gross, not counted again in
    // Total Deductions. Return the split so the UI can show it correctly.
    $attendanceDed = 0.0;
    $otherDed = 0.0;
    foreach ($row["deductions"] as &$d) {
        $type = (string) ($d["deduction_type"] ?? "");
        if (preg_match('/^(Absences|Late\/Undertime|Undertime)/', $type)) {
            $attendanceDed += (float) $d["amount"];
        } else {
            $otherDed += (float) $d["amount"];
        }
        $d["is_attendance"] = (bool) preg_match('/^(Absences|Late\/Undertime|Undertime)/', $type);
    }
    unset($d);
    $row["attendance_deductions"] = round($attendanceDed, 2);
    $row["other_deductions"] = round($otherDed, 2);

    respond(true, "", $row);
}

respond(false, "Missing run_id or id parameter.", null, 422);