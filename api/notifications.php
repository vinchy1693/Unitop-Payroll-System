<?php
// =====================================================================
// api/notifications.php
//
// Live, role-aware notification feed for the admin dashboard.
// Notifications are derived from current pending work, so no extra table
// or read/unread state is required.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireRole(array_merge(PAYROLL_ROLES, ADMIN_ROLES, [MANAGER_ROLE, SUPERVISOR_ROLE]));

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$user  = currentUser();
$role  = (string) $user["role"];
$items = [];

function adminCount(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$team = teamScopeIds($pdo);
$teamSql = $team === null
    ? ""
    : ($team
        ? " AND employee_id IN (" . implode(",", array_fill(0, count($team), "?")) . ")"
        : " AND 1=0");
$teamParams = $team ?? [];

if (in_array($role, ["Payroll Officer", MANAGER_ROLE, SUPERVISOR_ROLE, "Administrator"], true)) {
    $n = adminCount($pdo, "SELECT COUNT(*) FROM leave_requests WHERE status='Pending'" . $teamSql, $teamParams);
    if ($n > 0) $items[] = ["title" => "Pending leave requests", "detail" => "$n request(s) waiting for review", "count" => $n, "url" => "leave.html"];
}

if (in_array($role, ["Payroll Officer", MANAGER_ROLE, SUPERVISOR_ROLE, "Administrator"], true)) {
    $n = adminCount($pdo, "SELECT COUNT(*) FROM overtime_records WHERE status='Pending'" . $teamSql, $teamParams);
    if ($n > 0) $items[] = ["title" => "Pending overtime requests", "detail" => "$n request(s) waiting for approval", "count" => $n, "url" => "overtime.html"];
}

if (in_array($role, [MANAGER_ROLE, SUPERVISOR_ROLE, "Administrator"], true)) {
    $n = adminCount($pdo, "SELECT COUNT(*) FROM attendance_corrections WHERE status='Pending'" . $teamSql, $teamParams);
    if ($n > 0) $items[] = ["title" => "Pending attendance corrections", "detail" => "$n correction(s) waiting for review", "count" => $n, "url" => "attendance_corrections.html"];
}

if (in_array($role, ["Payroll Officer", FINANCE_ROLE, "Administrator"], true)) {
    $n = adminCount($pdo, "SELECT COUNT(*) FROM loans WHERE status='Pending'");
    if ($n > 0) $items[] = ["title" => "Pending loan requests", "detail" => "$n loan request(s) waiting for approval", "count" => $n, "url" => "loans.html"];
}

if (in_array($role, [FINANCE_ROLE, "Administrator"], true)) {
    $n = adminCount($pdo, "SELECT COUNT(*) FROM payroll_runs WHERE status='Submitted'");
    if ($n > 0) $items[] = ["title" => "Payroll awaiting approval", "detail" => "$n payroll run(s) ready for Finance review", "count" => $n, "url" => "payroll_runs.html"];

    $n = adminCount($pdo, "SELECT COUNT(*) FROM payroll_runs WHERE status='Approved'");
    if ($n > 0) $items[] = ["title" => "Payroll awaiting release", "detail" => "$n approved run(s) ready to release", "count" => $n, "url" => "payroll_runs.html"];
}

respond(true, "", [
    "count" => array_sum(array_column($items, "count")),
    "items" => $items,
]);
