<?php
require __DIR__ . "/_bootstrap.php";

// The dashboard exposes company-wide payroll figures, so only payroll staff
// may read it. The kiosk does not call this endpoint (it uses auth.php and
// scan_attendance.php), so kiosk mode is unaffected.
requireRole(PAYROLL_ROLES);

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

try {
    $today = date("Y-m-d");

    // ---- Master counts ----
    $counts = [];
    foreach ([
        "departments" => "departments",
        "positions"   => "positions",
        "employees"   => "employees",
        "leave_types" => "leave_types",
        "users"       => "users",
    ] as $key => $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table` WHERE is_active = 1");
        $counts[$key] = (int) $stmt->fetchColumn();
    }

    // ---- Pending leaves ----
    $counts["pending_leaves"] = (int) $pdo->query(
        "SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'"
    )->fetchColumn();

    // ---- Open periods ----
    $counts["open_periods"] = (int) $pdo->query(
        "SELECT COUNT(*) FROM payroll_periods WHERE status = 'Open'"
    )->fetchColumn();

    // ---- Employees with salary ----
    $counts["with_salary"] = (int) $pdo->query(
        "SELECT COUNT(*) FROM employees WHERE is_active = 1 AND basic_salary > 0"
    )->fetchColumn();

    // ---- Latest payroll run + its totals ----
    $stmt = $pdo->query(
        "SELECT pr.run_id, pr.run_date,
                pp.period_type, pp.start_date, pp.end_date,
                (SELECT COUNT(*) FROM payslips ps WHERE ps.run_id = pr.run_id) AS payslip_count,
                (SELECT COALESCE(SUM(ps.gross_pay), 0)        FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_gross,
                (SELECT COALESCE(SUM(ps.total_deductions), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_deductions,
                (SELECT COALESCE(SUM(ps.net_pay), 0)          FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_net
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         ORDER BY pr.run_id DESC LIMIT 1"
    );
    $latestRun = $stmt->fetch() ?: null;

    // ---- Active loans ----
    $loanInfo = $pdo->query(
        "SELECT COUNT(*) AS active_count,
                COALESCE(SUM(l.principal_amount -
                    COALESCE((SELECT SUM(lp.amount_paid) FROM loan_payments lp WHERE lp.loan_id = l.loan_id), 0)
                ), 0) AS total_remaining
         FROM loans l WHERE l.status = 'Active'"
    )->fetch();
    $activeLoans = [
        "count"           => (int) $loanInfo["active_count"],
        "total_remaining" => round((float) $loanInfo["total_remaining"], 2),
    ];

    // ---- Today's attendance snapshot ----
    $todayAttendance = [
        "present" => 0,
        "late"    => 0,
        "absent"  => 0,
    ];
    $stmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN minutes_late = 0 THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN minutes_late > 0 THEN 1 ELSE 0 END) AS late,
            COUNT(*) AS total
         FROM attendance WHERE work_date = ?"
    );
    $stmt->execute([$today]);
    $att = $stmt->fetch();
    $todayAttendance["present"] = (int) ($att["present"] ?? 0);
    $todayAttendance["late"]    = (int) ($att["late"]    ?? 0);
    $todayAttendance["absent"]  = max(0, $counts["employees"] - (int) ($att["total"] ?? 0));

    // ---- Recent payroll runs (last 5) ----
    $recentRuns = $pdo->query(
        "SELECT pr.run_id, pr.run_date,
                pp.period_type, pp.start_date, pp.end_date,
                (SELECT COUNT(*) FROM payslips ps WHERE ps.run_id = pr.run_id) AS payslip_count,
                (SELECT COALESCE(SUM(ps.net_pay), 0) FROM payslips ps WHERE ps.run_id = pr.run_id) AS total_net
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         ORDER BY pr.run_id DESC LIMIT 5"
    )->fetchAll();

    // Format money values
    foreach ($recentRuns as &$r) {
        $r["total_net"] = round((float) $r["total_net"], 2);
    }
    unset($r);

    respond(true, "", [
        "counts"            => $counts,
        "latest_run"        => $latestRun,
        "active_loans"      => $activeLoans,
        "today_attendance"  => $todayAttendance,
        "recent_runs"       => $recentRuns,
        "today"             => $today,
    ]);

} catch (PDOException $e) {
    respond(false, "Dashboard database error: " . $e->getMessage(), null, 500);
}