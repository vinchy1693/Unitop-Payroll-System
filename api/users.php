<?php
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];

requireRole("Administrator");

// ---- GET: list (?all=1 for incl. inactive) — never returns password_hash ----
if ($method === "GET") {
    $showAll = isset($_GET["all"]);
    $sql = "SELECT user_id, username, role, is_active FROM users"
            . ($showAll ? "" : " WHERE is_active = 1")
            . " ORDER BY user_id";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: create ----
if ($method === "POST") {
    $in       = json_input();
    $username = trim($in["username"] ?? "");
    $role     = trim($in["role"] ?? "");
    $password = $in["password"] ?? "";

    if ($username === "" || $role === "") {
        respond(false, "Username and Role are required.", null, 422);
    }

    try {
        $hash = password_hash($password !== "" ? $password : bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, role, password_hash, is_active) VALUES (?, ?, ?, 1)");
        $stmt->execute([$username, $role, $hash]);
        respond(true, "User added.", ["user_id" => (int) $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        respond(false, "Error: username may already exist.", null, 409);
    }
}

// ---- PUT: update (password optional — leave blank to keep current) ----
if ($method === "PUT") {
    $in       = json_input();
    $id       = (int) ($in["user_id"] ?? 0);
    $username = trim($in["username"] ?? "");
    $role     = trim($in["role"] ?? "");
    $password = $in["password"] ?? "";

    if ($id === 0 || $username === "" || $role === "") {
        respond(false, "Username and Role are required.", null, 422);
    }

    try {
        if ($password !== "") {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ?, password_hash = ? WHERE user_id = ?");
            $stmt->execute([$username, $role, $hash, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE user_id = ?");
            $stmt->execute([$username, $role, $id]);
        }
        respond(true, "User updated.");
    } catch (PDOException $e) {
        respond(false, "Error: username may already exist.", null, 409);
    }
}

// ---- PATCH: soft delete / restore ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int) ($in["user_id"] ?? 0);
    $status = (int) ($in["is_active"] ?? 1);

    if ($id === 0) {
        respond(false, "Missing user_id.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status ? "User restored." : "User deactivated.");
}

respond(false, "Method not allowed.", null, 405);
