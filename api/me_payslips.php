<?php
require __DIR__ . "/_bootstrap.php";

$user = currentUser();
if (!$user || !$user["employee_id"]) {
    respond(false, "Your account is not linked to an employee record.", null, 403);
}

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list own payslips ----
if ($method === "GET") {
    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, ps.basic_pay, ps.overtime_pay, ps.gross_pay,
                ps.total_deductions, ps.net_pay,
                pp.start_date, pp.end_date, pp.period_type,
                pr.run_id, pr.run_date
         FROM payslips ps
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         WHERE ps.employee_id = ?
         ORDER BY pp.end_date DESC, ps.payslip_id DESC"
    );
    $stmt->execute([$user["employee_id"]]);
    respond(true, "", $stmt->fetchAll());
}

respond(false, "Method not allowed.", null, 405);