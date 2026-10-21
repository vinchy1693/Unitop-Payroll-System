<?php
require __DIR__ . "/_bootstrap.php";

requireRole(array_merge(PAYROLL_ROLES, [MANAGER_ROLE]));   // Payroll/HR or a team Manager

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list all QR codes (Manager: team only) ----
if ($method === "GET") {
    $scope = teamScopeIds($pdo);
    $params = [];
    $where = "";
    if ($scope !== null) {
        if (!$scope) respond(true, "", []);
        $where = " WHERE q.employee_id IN (" . implode(",", array_fill(0, count($scope), "?")) . ")";
        $params = $scope;
    }
    $sql = "SELECT q.qr_code_id, q.employee_id, q.code_value, q.issued_date, q.status,
                   e.employee_number,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   d.department_name
            FROM qr_codes q
            JOIN employees e ON e.employee_id = q.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id"
            . $where
            . " ORDER BY e.last_name, e.first_name, q.qr_code_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    respond(true, "", $stmt->fetchAll());
}

// ---- POST: generate QR for an employee ----
requireRole(array_merge(MAKER_ROLES, [MANAGER_ROLE]));

if ($method === "POST") {
    $in    = json_input();
    $empId = (int) ($in["employee_id"] ?? 0);

    if ($empId === 0) {
        respond(false, "Employee is required.", null, 422);
    }
    if (isManager()) {
        assertManages($pdo, $empId, "generate a QR code for this employee");
    }

    $stmt = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND is_active = 1");
    $stmt->execute([$empId]);
    if (!$stmt->fetchColumn()) {
        respond(false, "Employee not found or inactive.", null, 404);
    }

    // Revoke any existing active QR for this employee
    $pdo->prepare("UPDATE qr_codes SET status = 'Revoked' WHERE employee_id = ? AND status = 'Active'")
        ->execute([$empId]);

    $codeValue = "UNITOP-" . strtoupper(bin2hex(random_bytes(16)));

    $stmt = $pdo->prepare(
        "INSERT INTO qr_codes (employee_id, code_value, issued_date, status)
         VALUES (?, ?, CURDATE(), 'Active')"
    );
    $stmt->execute([$empId, $codeValue]);

    respond(true, "QR code generated.", [
        "qr_code_id" => (int) $pdo->lastInsertId(),
        "code_value" => $codeValue,
    ]);
}

// ---- PATCH: revoke / reactivate ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int) ($in["qr_code_id"] ?? 0);
    $status = trim($in["status"] ?? "Revoked");

    if ($id === 0) {
        respond(false, "Missing qr_code_id.", null, 422);
    }
    if (!in_array($status, ["Active", "Revoked"], true)) {
        respond(false, "Status must be Active or Revoked.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT qr_code_id, employee_id FROM qr_codes WHERE qr_code_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(false, "QR code not found.", null, 404);
    }
    $empId = (int) $row["employee_id"];
    if (isManager()) {
        assertManages($pdo, $empId, "change this employee's QR code");
    }

    if ($status === "Revoked") {
        // Revoking a QR means revoking the employee's QR access, not just one
        // printed badge. Kill any other active badge and every unused
        // short-lived token already issued by the portal.
        $pdo->prepare("UPDATE qr_codes SET status = 'Revoked' WHERE employee_id = ? AND status = 'Active'")
            ->execute([$empId]);
        $pdo->prepare("UPDATE qr_tokens SET used_at = NOW() WHERE employee_id = ? AND used_at IS NULL")
            ->execute([$empId]);
    } else {
        // Keep exactly one active badge per employee.
        $pdo->prepare(
            "UPDATE qr_codes SET status = 'Revoked'
             WHERE employee_id = ? AND qr_code_id <> ? AND status = 'Active'"
        )->execute([$empId, $id]);
        $pdo->prepare("UPDATE qr_codes SET status = 'Active' WHERE qr_code_id = ?")->execute([$id]);
    }

    respond(true, $status === "Revoked" ? "QR code revoked." : "QR code reactivated.", [
        "qr_code_id"  => $id,
        "employee_id" => $empId,
        "status"      => $status,
    ]);
}

respond(false, "Method not allowed.", null, 405);