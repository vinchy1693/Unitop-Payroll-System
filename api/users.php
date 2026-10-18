<?php
require __DIR__ . "/_bootstrap.php";

requireRole(ADMIN_ROLES);   // user administration is technical-only

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list (?all=1 for incl. inactive) ----
if ($method === "GET") {
    $showAll = isset($_GET["all"]);
    $sql = "SELECT u.user_id, u.username, u.role, u.employee_id, u.is_active,
                   CONCAT(e.last_name, ', ', e.first_name) AS employee_name
            FROM users u
            LEFT JOIN employees e ON e.employee_id = u.employee_id"
            . ($showAll ? "" : " WHERE u.is_active = 1")
            . " ORDER BY u.user_id";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: create ----
if ($method === "POST") {
    $in         = json_input();
    $username   = trim($in["username"] ?? "");
    $role       = trim($in["role"] ?? "");
    $password   = $in["password"] ?? "";
    $employeeId = (int) ($in["employee_id"] ?? 0);
    $employeeId = $employeeId > 0 ? $employeeId : null;

    if ($username === "" || $role === "") {
        respond(false, "Username and Role are required.", null, 422);
    }
    if (!in_array($role, ALL_ROLES, true)) {
        respond(false, "Unknown role. Use one of: " . implode(", ", ALL_ROLES) . ".", null, 422);
    }
    if ($role === "Employee" && $employeeId === null) {
        respond(false, "Employee role requires a linked employee record.", null, 422);
    }

    try {
        $hash = password_hash($password !== "" ? $password : bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, role, password_hash, employee_id, is_active)
             VALUES (?, ?, ?, ?, 1)"
        );
        $stmt->execute([$username, $role, $hash, $employeeId]);
        respond(true, "User added.", ["user_id" => (int) $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        respond(false, "Error: username may already exist.", null, 409);
    }
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in         = json_input();
    $id         = (int)  ($in["user_id"] ?? 0);
    $username   = trim($in["username"] ?? "");
    $role       = trim($in["role"] ?? "");
    $password   = $in["password"] ?? "";
    $employeeId = (int) ($in["employee_id"] ?? 0);
    $employeeId = $employeeId > 0 ? $employeeId : null;

    if ($id === 0 || $username === "" || $role === "") {
        respond(false, "Username and Role are required.", null, 422);
    }
    if (!in_array($role, ALL_ROLES, true)) {
        respond(false, "Unknown role. Use one of: " . implode(", ", ALL_ROLES) . ".", null, 422);
    }
    if ($role === "Employee" && $employeeId === null) {
        respond(false, "Employee role requires a linked employee record.", null, 422);
    }

    try {
        if ($password !== "") {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "UPDATE users SET username = ?, role = ?, password_hash = ?, employee_id = ?
                 WHERE user_id = ?"
            );
            $stmt->execute([$username, $role, $hash, $employeeId, $id]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE users SET username = ?, role = ?, employee_id = ? WHERE user_id = ?"
            );
            $stmt->execute([$username, $role, $employeeId, $id]);
        }
        respond(true, "User updated.");
    } catch (PDOException $e) {
        respond(false, "Error: username may already exist.", null, 409);
    }
}

// ---- PATCH: soft delete / restore ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int) ($in["user_id"]   ?? 0);
    $status = (int) ($in["is_active"] ?? 1);

    if ($id === 0) {
        respond(false, "Missing user_id.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status ? "User restored." : "User deactivated.");
}

respond(false, "Method not allowed.", null, 405);