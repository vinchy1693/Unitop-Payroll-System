<?php
// Opt out of the login guard — this endpoint *is* the login.
define("AUTH_ENDPOINT", true);
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];
$action = $_GET["action"] ?? "";

// =====================================================================
// POST ?action=login
// =====================================================================
if ($method === "POST" && $action === "login") {
    $in       = json_input();
    $username = trim($in["username"] ?? "");
    $password = $in["password"] ?? "";

    if ($username === "" || $password === "") {
        respond(false, "Username and password are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "SELECT user_id, username, role, password_hash, is_active
         FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "Invalid username or password.", null, 401);
    }
    if ((int) $user["is_active"] !== 1) {
        respond(false, "This account has been deactivated.", null, 403);
    }
    if (!$user["password_hash"] || !password_verify($password, $user["password_hash"])) {
        respond(false, "Invalid username or password.", null, 401);
    }

    // Fresh session ID to prevent fixation
    // session_regenerate_id(true);

    $_SESSION["user_id"]  = (int) $user["user_id"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["role"]     = $user["role"];

    respond(true, "Welcome, " . $user["username"] . ".", [
        "user_id"  => (int) $user["user_id"],
        "username" => $user["username"],
        "role"     => $user["role"],
    ]);
}

// =====================================================================
// POST ?action=logout
// =====================================================================
if ($method === "POST" && $action === "logout") {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]);
    }
    session_destroy();
    respond(true, "Logged out.");
}

// =====================================================================
// GET ?action=me
// =====================================================================
if ($method === "GET" && $action === "me") {
    $user = currentUser();
    if (!$user) {
        respond(false, "Not authenticated.", null, 401);
    }
    respond(true, "", $user);
}

// =====================================================================
// POST ?action=change_password
// =====================================================================
if ($method === "POST" && $action === "change_password") {
    $user = currentUser();
    if (!$user) {
        respond(false, "Not authenticated.", null, 401);
    }

    $in          = json_input();
    $currentPw   = $in["current_password"] ?? "";
    $newPw       = $in["new_password"]     ?? "";
    $confirmPw   = $in["confirm_password"] ?? "";

    if ($currentPw === "" || $newPw === "" || $confirmPw === "") {
        respond(false, "All password fields are required.", null, 422);
    }
    if (strlen($newPw) < 6) {
        respond(false, "New password must be at least 6 characters.", null, 422);
    }
    if ($newPw !== $confirmPw) {
        respond(false, "New password and confirmation do not match.", null, 422);
    }

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$user["user_id"]]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($currentPw, $hash)) {
        respond(false, "Current password is incorrect.", null, 401);
    }

    $newHash = password_hash($newPw, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
        ->execute([$newHash, $user["user_id"]]);

    respond(true, "Password changed successfully.");
}

respond(false, "Method or action not allowed.", null, 405);