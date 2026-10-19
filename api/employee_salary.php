<?php
// =====================================================================
// api/employee_salary.php
//
// The maker prepares the employee record; the checker changes the basic
// salary. Keeping the salary behind the Approver removes the maker's ability
// to raise their own pay immediately before processing a run.
//
// PUT { employee_id, basic_salary, reason }
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireRole(CHECKER_ROLES);

if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    respond(false, "Method not allowed.", null, 405);
}

$in       = json_input();
$empId    = (int)   ($in["employee_id"] ?? 0);
$salary   = (float) ($in["basic_salary"] ?? 0);
$reason   = trim((string) ($in["reason"] ?? ""));

if ($empId === 0) {
    respond(false, "employee_id is required.", null, 422);
}
if ($salary <= 0) {
    respond(false, "Basic salary must be greater than zero.", null, 422);
}
if (strlen($reason) < 10) {
    respond(false, "A reason of at least 10 characters is required for a salary change.", null, 422);
}
if (strlen($reason) > 500) {
    respond(false, "The reason must be at most 500 characters.", null, 422);
}

assertNotOwnEmployee($empId, "change your own basic salary");

$stmt = $pdo->prepare(
    "SELECT employee_id, employee_number, basic_salary, is_active
     FROM employees WHERE employee_id = ?"
);
$stmt->execute([$empId]);
$employee = $stmt->fetch();
if (!$employee) {
    respond(false, "Employee not found.", null, 404);
}
if ((int) $employee["is_active"] !== 1) {
    respond(false, "Cannot change the salary of an inactive employee.", null, 409);
}

$oldSalary = round((float) $employee["basic_salary"], 2);
$newSalary = round($salary, 2);

if (abs($newSalary - $oldSalary) < 0.005) {
    respond(false, "The new salary is the same as the current salary.", null, 422);
}

$pdo->prepare("UPDATE employees SET basic_salary = ? WHERE employee_id = ?")
    ->execute([$newSalary, $empId]);

respond(true, "Basic salary updated.", [
    "employee_id"     => $empId,
    "employee_number" => $employee["employee_number"],
    "old_salary"      => $oldSalary,
    "new_salary"      => $newSalary,
    "reason"          => $reason,
]);
