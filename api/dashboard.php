<?php
require __DIR__ . "/_bootstrap.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

try {
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

    $stmt = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'");
    $counts["pending_leaves"] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM payroll_periods WHERE status = 'Open'");
    $counts["open_periods"] = (int) $stmt->fetchColumn();

    // Count employees who have a basic salary set
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE is_active = 1 AND basic_salary > 0");
    $counts["with_salary"] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query(
        "SELECT pr.run_id, pr.run_date,
                pp.period_type, pp.start_date, pp.end_date,
                (SELECT COUNT(*) FROM payslips ps WHERE ps.run_id = pr.run_id) AS payslip_count
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         ORDER BY pr.run_id DESC LIMIT 1"
    );
    $latestRun = $stmt->fetch() ?: null;

    respond(true, "", [
        "counts"     => $counts,
        "latest_run" => $latestRun,
    ]);

} catch (PDOException $e) {
    respond(false, "Dashboard database error: " . $e->getMessage(), null, 500);
}