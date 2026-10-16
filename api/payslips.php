<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only

$method = $_SERVER["REQUEST_METHOD"];

if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

// ---- GET ?run_id=N : all payslips for a specific run ----
if (isset($_GET["run_id"])) {
    $runId = (int) $_GET["run_id"];

    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, ps.run_id, ps.employee_id,
                ps.basic_pay, ps.overtime_pay, ps.gross_pay,
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

    respond(true, "", $row);
}

respond(false, "Missing run_id or id parameter.", null, 422);