<?php
// =====================================================================
// api/payroll_run_expenses.php
//
// Aggregate payroll expense review for Finance & Accounting.
//
// This intentionally returns NO employee payslip rows and no employee names:
// it is the cost-control view Finance needs before approving/releasing a run.
// Only aggregate totals and a department-level breakdown are exposed.
//
// GET ?run_id=N
// =====================================================================
require __DIR__ . "/_bootstrap.php";
requireRole(PAYROLL_ROLES);   // Payroll/HR + Finance & Accounting

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$runId = (int) ($_GET["run_id"] ?? 0);
if ($runId <= 0) {
    respond(false, "run_id is required.", null, 422);
}

$stmt = $pdo->prepare(
    "SELECT pr.run_id, pr.run_date, pr.status,
            pp.start_date, pp.end_date, pp.pay_date, pp.period_type
     FROM payroll_runs pr
     JOIN payroll_periods pp ON pp.period_id = pr.period_id
     WHERE pr.run_id = ?"
);
$stmt->execute([$runId]);
$run = $stmt->fetch();
if (!$run) {
    respond(false, "Payroll run not found.", null, 404);
}

// One payslip per employee. LEFT JOIN so a partial/missing contribution row
// does not hide the payroll cost.
$stmt = $pdo->prepare(
    "SELECT
        COUNT(ps.payslip_id) AS headcount,
        COALESCE(SUM(ps.basic_pay), 0)      AS basic_pay,
        COALESCE(SUM(ps.overtime_pay), 0)   AS overtime_pay,
        COALESCE(SUM(ps.premium_pay), 0)    AS premium_pay,
        COALESCE(SUM(ps.gross_pay), 0)      AS adjusted_gross,
        COALESCE(SUM(ps.net_pay), 0)        AS net_pay,
        COALESCE(SUM(gc.sss_ee), 0)         AS sss_ee,
        COALESCE(SUM(gc.philhealth_ee), 0)  AS philhealth_ee,
        COALESCE(SUM(gc.pagibig_ee), 0)     AS pagibig_ee,
        COALESCE(SUM(gc.withholding_tax), 0) AS withholding_tax,
        COALESCE(SUM(gc.sss_er), 0)         AS sss_er,
        COALESCE(SUM(gc.philhealth_er), 0)  AS philhealth_er,
        COALESCE(SUM(gc.pagibig_er), 0)     AS pagibig_er
     FROM payslips ps
     LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
     WHERE ps.run_id = ?"
);
$stmt->execute([$runId]);
$t = $stmt->fetch();

foreach ($t as $key => $value) {
    if ($key !== "headcount") $t[$key] = round((float) $value, 2);
}
$t["headcount"] = (int) $t["headcount"];

// Allowances are separate line items; show them as part of the cost picture.
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(a.amount), 0)
     FROM allowances a
     JOIN payslips ps ON ps.payslip_id = a.payslip_id
     WHERE ps.run_id = ?"
);
$stmt->execute([$runId]);
$t["allowances"] = round((float) $stmt->fetchColumn(), 2);

// Loan deductions are the payroll-controlled non-statutory deduction.
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(d.amount), 0)
     FROM deductions d
     JOIN payslips ps ON ps.payslip_id = d.payslip_id
     WHERE ps.run_id = ? AND d.deduction_type LIKE 'Loan:%'"
);
$stmt->execute([$runId]);
$t["loans"] = round((float) $stmt->fetchColumn(), 2);

$t["statutory_employee"] = round($t["sss_ee"] + $t["philhealth_ee"] + $t["pagibig_ee"] + $t["withholding_tax"], 2);
$t["statutory_employer"] = round($t["sss_er"] + $t["philhealth_er"] + $t["pagibig_er"], 2);
$t["employer_cost"] = round($t["adjusted_gross"] + $t["statutory_employer"], 2);

// Department-level cost; no employee names or payslip IDs.
$stmt = $pdo->prepare(
    "SELECT
        COALESCE(d.department_name, 'Unassigned') AS department,
        COUNT(ps.payslip_id) AS headcount,
        COALESCE(SUM(ps.gross_pay), 0) AS adjusted_gross,
        COALESCE(SUM(ps.net_pay), 0) AS net_pay,
        COALESCE(SUM(gc.sss_er), 0) AS sss_er,
        COALESCE(SUM(gc.philhealth_er), 0) AS philhealth_er,
        COALESCE(SUM(gc.pagibig_er), 0) AS pagibig_er
     FROM payslips ps
     JOIN employees e ON e.employee_id = ps.employee_id
     LEFT JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
     WHERE ps.run_id = ?
     GROUP BY d.department_id, d.department_name
     ORDER BY adjusted_gross DESC"
);
$stmt->execute([$runId]);
$departments = $stmt->fetchAll();

foreach ($departments as &$d) {
    $d["headcount"] = (int) $d["headcount"];
    foreach (["adjusted_gross", "net_pay", "sss_er", "philhealth_er", "pagibig_er"] as $key) {
        $d[$key] = round((float) $d[$key], 2);
    }
    $d["employer_contributions"] = round($d["sss_er"] + $d["philhealth_er"] + $d["pagibig_er"], 2);
    $d["employer_cost"] = round($d["adjusted_gross"] + $d["employer_contributions"], 2);
}
unset($d);

respond(true, "", [
    "run" => $run,
    "totals" => $t,
    "departments" => $departments,
]);
