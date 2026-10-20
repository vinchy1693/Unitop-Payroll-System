<?php
// =====================================================================
// GET employees_lookup.php
//
// A minimal employee list for screens that must reference an employee
// without exposing their pay: the Users page needs to link an account to an
// employee (especially a Supervisor, who leads no team until linked), but
// Administrator is deliberately excluded from employees.php because that
// endpoint returns salary and government numbers.
//
// Returns ONLY id, number, name and active flag. No salary, no TIN/SSS/
// PhilHealth/Pag-IBIG, no bank details.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

// The technical admin needs this to assign roles; the payroll roles already
// have the full employees.php.
// The technical admin needs this to assign roles; the payroll roles already
// have the full employees.php. A Supervisor may use it to file overtime, but
// only sees their own team (the scope is applied below).
requireRole(array_merge(ADMIN_ROLES, PAYROLL_ROLES, [SUPERVISOR_ROLE]));

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

if (isSupervisor()) {
    $scope = supervisorScopeIds($pdo);
    if ($scope === []) {
        respond(true, "", []);
    }
    $placeholders = implode(",", array_fill(0, count($scope), "?"));
    $stmt = $pdo->prepare(
        "SELECT employee_id, employee_number, first_name, last_name, is_active
         FROM employees
         WHERE employee_id IN ($placeholders) AND is_active = 1
         ORDER BY last_name, first_name"
    );
    $stmt->execute($scope);
    $rows = $stmt->fetchAll();
} else {
    $rows = $pdo->query(
        "SELECT employee_id, employee_number, first_name, last_name, is_active
         FROM employees
         ORDER BY last_name, first_name"
    )->fetchAll();
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        // "id" and "label" keep the shape users.js already expects (it renders
        // option value=e.id / text=e.label); the rest is for other callers.
        "id"              => (int) $r["employee_id"],
        "label"           => $r["employee_number"] . " - " . trim($r["last_name"] . ", " . $r["first_name"]),
        "employee_id"     => (int) $r["employee_id"],
        "employee_number" => $r["employee_number"],
        "first_name"      => $r["first_name"],
        "last_name"       => $r["last_name"],
        "name"            => trim($r["last_name"] . ", " . $r["first_name"]),
        "is_active"       => (int) $r["is_active"],
    ];
}

respond(true, "", $out);
