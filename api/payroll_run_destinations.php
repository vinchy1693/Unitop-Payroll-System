<?php
// =====================================================================
// api/payroll_run_destinations.php
//
// Payslip destinations for Finance review BEFORE approval/release.
// Returns each employee's email, portal account and DimoBank details.
//
// GET ?run_id=N
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // Payroll/HR and Finance; Administrator bypasses

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$runId = (int) ($_GET["run_id"] ?? 0);
if ($runId <= 0) {
    respond(false, "run_id is required.", null, 422);
}

$stmt = $pdo->prepare(
    "SELECT pr.run_id, pr.status, pr.run_date,
            pp.start_date, pp.end_date, pp.pay_date
     FROM payroll_runs pr
     JOIN payroll_periods pp ON pp.period_id = pr.period_id
     WHERE pr.run_id = ?"
);
$stmt->execute([$runId]);
$run = $stmt->fetch();
if (!$run) {
    respond(false, "Payroll run not found.", null, 404);
}

$stmt = $pdo->prepare(
    "SELECT e.employee_id, e.employee_number,
            CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
            e.email, e.bank_name, e.bank_account_name, e.bank_account_number,
            (SELECT u.username FROM users u
              WHERE u.employee_id = e.employee_id
                AND u.role = 'Employee'
                AND u.is_active = 1
              ORDER BY u.user_id LIMIT 1) AS portal_username,
            ps.net_pay
     FROM payslips ps
     JOIN employees e ON e.employee_id = ps.employee_id
     WHERE ps.run_id = ?
     ORDER BY e.last_name, e.first_name"
);
$stmt->execute([$runId]);

respond(true, "", [
    "run"       => $run,
    "employees" => $stmt->fetchAll(),
]);
