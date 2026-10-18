<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker (read) + checker (read); Administrator has no access

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$report = $_GET["report"] ?? "";

// =====================================================================
// REPORT 1: Payroll Register
// =====================================================================
if ($report === "payroll_register") {
    $runId = (int) ($_GET["run_id"] ?? 0);
    if ($runId === 0) respond(false, "Missing run_id.", null, 422);

    $stmt = $pdo->prepare(
        "SELECT pr.run_id, pr.run_date, pr.status,
                pp.start_date, pp.end_date, pp.period_type
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         WHERE pr.run_id = ?"
    );
    $stmt->execute([$runId]);
    $runInfo = $stmt->fetch();
    if (!$runInfo) respond(false, "Run not found.", null, 404);

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
         LEFT JOIN positions p ON p.position_id = e.position_id
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE ps.run_id = ?
         ORDER BY e.last_name, e.first_name"
    );
    $stmt->execute([$runId]);
    $payslips = $stmt->fetchAll();

    $aStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM allowances WHERE payslip_id = ?");
    $dStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM deductions WHERE payslip_id = ?");
    foreach ($payslips as &$ps) {
        $aStmt->execute([$ps["payslip_id"]]);
        $ps["allowance_total"] = (float) $aStmt->fetchColumn();
        $dStmt->execute([$ps["payslip_id"]]);
        $ps["other_deductions"] = (float) $dStmt->fetchColumn();
    }
    unset($ps);

    $totals = [
        "basic_pay" => 0, "overtime_pay" => 0, "allowance_total" => 0,
        "gross_pay" => 0, "sss_ee" => 0, "philhealth_ee" => 0,
        "pagibig_ee" => 0, "withholding_tax" => 0, "other_deductions" => 0,
        "total_deductions" => 0, "net_pay" => 0,
    ];
    foreach ($payslips as $ps) {
        foreach ($totals as $k => $v) $totals[$k] += (float) ($ps[$k] ?? 0);
    }
    foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

    respond(true, "", [
        "run" => $runInfo, "payslips" => $payslips, "totals" => $totals,
    ]);
}

// =====================================================================
// REPORT 2: Government Remittance Summary
// =====================================================================
if ($report === "remittance") {
    $year  = (int) ($_GET["year"]  ?? 0);
    $month = (int) ($_GET["month"] ?? 0);
    if ($year < 2000 || $month < 1 || $month > 12) {
        respond(false, "Valid year and month are required.", null, 422);
    }

    $start = sprintf("%04d-%02d-01", $year, $month);
    $end   = (new DateTime($start))->modify('last day of this month')->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT ps.payslip_id)               AS payslip_count,
            COALESCE(SUM(gc.sss_ee), 0)                 AS sss_ee,
            COALESCE(SUM(gc.sss_er), 0)                 AS sss_er,
            COALESCE(SUM(gc.philhealth_ee), 0)          AS philhealth_ee,
            COALESCE(SUM(gc.philhealth_er), 0)          AS philhealth_er,
            COALESCE(SUM(gc.pagibig_ee), 0)             AS pagibig_ee,
            COALESCE(SUM(gc.pagibig_er), 0)             AS pagibig_er,
            COALESCE(SUM(gc.withholding_tax), 0)        AS withholding_tax
         FROM payslips ps
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE pp.period_type <> '13th Month'
           AND pp.start_date <= ?
           AND pp.end_date   >= ?"
    );
    $stmt->execute([$end, $start]);
    $totals = $stmt->fetch();
    foreach ($totals as $k => $v) {
        $totals[$k] = ($k === "payslip_count") ? (int) $v : round((float) $v, 2);
    }

    respond(true, "", [
        "year" => $year, "month" => $month, "start" => $start, "end" => $end, "totals" => $totals,
    ]);
}

// =====================================================================
// REPORT 3: Employee Payslip History
// =====================================================================
if ($report === "employee_history") {
    $empId = (int) ($_GET["employee_id"] ?? 0);
    if ($empId === 0) respond(false, "Missing employee_id.", null, 422);

    $stmt = $pdo->prepare(
        "SELECT e.employee_id, e.employee_number,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                e.date_hired, e.employment_status,
                d.department_name,
                p.title AS position_title
         FROM employees e
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN positions p ON p.position_id = e.position_id
         WHERE e.employee_id = ?"
    );
    $stmt->execute([$empId]);
    $employee = $stmt->fetch();
    if (!$employee) respond(false, "Employee not found.", null, 404);

    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, ps.gross_pay, ps.total_deductions, ps.net_pay,
                pp.period_type, pp.start_date, pp.end_date,
                pr.run_id
         FROM payslips ps
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         WHERE ps.employee_id = ?
         ORDER BY pp.start_date ASC, ps.payslip_id ASC"
    );
    $stmt->execute([$empId]);
    $payslips = $stmt->fetchAll();

    $totals = ["gross_pay" => 0, "total_deductions" => 0, "net_pay" => 0];
    foreach ($payslips as $ps) {
        $totals["gross_pay"]        += (float) $ps["gross_pay"];
        $totals["total_deductions"] += (float) $ps["total_deductions"];
        $totals["net_pay"]          += (float) $ps["net_pay"];
    }
    foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

    respond(true, "", [
        "employee" => $employee, "payslips" => $payslips, "totals" => $totals,
    ]);
}

// =====================================================================
// REPORT 4: Department Cost Summary
// =====================================================================
if ($report === "department_summary") {
    $from = trim($_GET["from"] ?? "");
    $to   = trim($_GET["to"]   ?? "");
    if ($from === "" || $to === "") respond(false, "Both from and to dates are required.", null, 422);
    if ($from > $to) respond(false, "The 'from' date must be on or before the 'to' date.", null, 422);

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(d.department_name, '— Unassigned —')  AS department_name,
            COUNT(DISTINCT ps.employee_id)                 AS headcount,
            COUNT(DISTINCT ps.payslip_id)                  AS payslip_count,
            COALESCE(SUM(ps.gross_pay), 0)                 AS total_gross,
            COALESCE(SUM(ps.net_pay), 0)                   AS total_net,
            COALESCE(SUM(gc.sss_er), 0)                    AS sss_er,
            COALESCE(SUM(gc.philhealth_er), 0)             AS philhealth_er,
            COALESCE(SUM(gc.pagibig_er), 0)                AS pagibig_er
         FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE pp.start_date <= ?
           AND pp.end_date   >= ?
         GROUP BY d.department_id, d.department_name
         ORDER BY total_gross DESC"
    );
    $stmt->execute([$to, $from]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r["total_gross"]   = round((float) $r["total_gross"], 2);
        $r["total_net"]     = round((float) $r["total_net"], 2);
        $r["sss_er"]        = round((float) $r["sss_er"], 2);
        $r["philhealth_er"] = round((float) $r["philhealth_er"], 2);
        $r["pagibig_er"]    = round((float) $r["pagibig_er"], 2);
        $r["er_total"]      = round($r["sss_er"] + $r["philhealth_er"] + $r["pagibig_er"], 2);
        $r["employer_cost"] = round($r["total_gross"] + $r["er_total"], 2);
        $r["headcount"]     = (int) $r["headcount"];
        $r["payslip_count"] = (int) $r["payslip_count"];
    }
    unset($r);

    $totals = [
        "headcount" => 0, "payslip_count" => 0, "total_gross" => 0, "total_net" => 0,
        "sss_er" => 0, "philhealth_er" => 0, "pagibig_er" => 0,
        "er_total" => 0, "employer_cost" => 0,
    ];
    foreach ($rows as $r) {
        $totals["headcount"]     += $r["headcount"];
        $totals["payslip_count"] += $r["payslip_count"];
        $totals["total_gross"]   += $r["total_gross"];
        $totals["total_net"]     += $r["total_net"];
        $totals["sss_er"]        += $r["sss_er"];
        $totals["philhealth_er"] += $r["philhealth_er"];
        $totals["pagibig_er"]    += $r["pagibig_er"];
        $totals["er_total"]      += $r["er_total"];
        $totals["employer_cost"] += $r["employer_cost"];
    }
    foreach ($totals as $k => $v) $totals[$k] = round($v, 2);

    respond(true, "", [
        "from" => $from, "to" => $to, "rows" => $rows, "totals" => $totals,
    ]);
}

// =====================================================================
// REPORT 5: Payroll Summary (aggregate totals for a run)
// =====================================================================
if ($report === "payroll_summary") {
    $runId = (int) ($_GET["run_id"] ?? 0);
    if ($runId === 0) respond(false, "Missing run_id.", null, 422);

    $stmt = $pdo->prepare(
        "SELECT pr.run_id, pr.run_date, pr.status,
                pp.start_date, pp.end_date, pp.period_type
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         WHERE pr.run_id = ?"
    );
    $stmt->execute([$runId]);
    $runInfo = $stmt->fetch();
    if (!$runInfo) respond(false, "Run not found.", null, 404);

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT ps.payslip_id)              AS headcount,
            COALESCE(SUM(ps.basic_pay), 0)             AS total_basic,
            COALESCE(SUM(ps.overtime_pay), 0)          AS total_ot,
            COALESCE(SUM(ps.gross_pay), 0)             AS total_gross,
            COALESCE(SUM(ps.total_deductions), 0)      AS total_deductions,
            COALESCE(SUM(ps.net_pay), 0)               AS total_net,
            COALESCE(SUM(gc.sss_ee), 0)                AS sss_ee,
            COALESCE(SUM(gc.sss_er), 0)                AS sss_er,
            COALESCE(SUM(gc.philhealth_ee), 0)         AS philhealth_ee,
            COALESCE(SUM(gc.philhealth_er), 0)         AS philhealth_er,
            COALESCE(SUM(gc.pagibig_ee), 0)            AS pagibig_ee,
            COALESCE(SUM(gc.pagibig_er), 0)            AS pagibig_er,
            COALESCE(SUM(gc.withholding_tax), 0)       AS withholding_tax
         FROM payslips ps
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE ps.run_id = ?"
    );
    $stmt->execute([$runId]);
    $totals = $stmt->fetch();
    foreach ($totals as $k => $v) {
        $totals[$k] = ($k === "headcount") ? (int) $v : round((float) $v, 2);
    }

    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id,
                e.employee_number,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                d.department_name,
                ps.net_pay
         FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         WHERE ps.run_id = ?
         ORDER BY e.last_name, e.first_name"
    );
    $stmt->execute([$runId]);
    $employees = $stmt->fetchAll();

    respond(true, "", [
        "run" => $runInfo, "totals" => $totals, "employees" => $employees,
    ]);
}

respond(false, "Unknown report.", null, 422);