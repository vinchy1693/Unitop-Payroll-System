<?php
// =====================================================================
// GET supervisors_lookup.php
//
// Minimal list of employees who have an active Supervisor login. Used by the
// Employees form so a team lead can be assigned without exposing salary or
// government numbers.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireRole(array_merge(ADMIN_ROLES, PAYROLL_ROLES));

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$rows = $pdo->query(
    "SELECT e.employee_id, e.employee_number, e.first_name, e.last_name
     FROM employees e
     JOIN users u ON u.employee_id = e.employee_id
                 AND u.role = 'Supervisor'
                 AND u.is_active = 1
     WHERE e.is_active = 1
     GROUP BY e.employee_id, e.employee_number, e.first_name, e.last_name
     ORDER BY e.last_name, e.first_name"
)->fetchAll();

$out = [];
foreach ($rows as $r) {
    $out[] = [
        "id"    => (int) $r["employee_id"],
        "label" => $r["employee_number"] . " - " . trim($r["last_name"] . ", " . $r["first_name"]),
    ];
}

respond(true, "", $out);
