<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // admin / payroll staff only

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list all QR codes ----
if ($method === "GET") {
    $sql = "SELECT q.qr_code_id, q.employee_id, q.code_value, q.issued_date, q.status,
                   e.employee_number,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                   d.department_name
            FROM qr_codes q
            JOIN employees e ON e.employee_id = q.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            ORDER BY e.last_name, e.first_name, q.qr_code_id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: generate QR for an employee ----
if ($method === "POST") {
    $in    = json_input();
    $empId = (int) ($in["employee_id"] ?? 0);

    if ($empId === 0) {
        respond(false, "Employee is required.", null, 422);
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

    $pdo->prepare("UPDATE qr_codes SET status = ? WHERE qr_code_id = ?")->execute([$status, $id]);
    respond(true, $status === "Revoked" ? "QR code revoked." : "QR code reactivated.");
}

respond(false, "Method not allowed.", null, 405);