<?php
// =====================================================================
// api/_bootstrap.php
// Session + DB + JSON helpers. Every endpoint includes this file.
// =====================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

try {
    require __DIR__ . "/../config.php";
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $e->getMessage(),
        "data"    => null,
    ]);
    exit;
}

/**
 * Decode the JSON request body into an assoc array.
 */
function json_input(): array {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    return $_POST ?: [];
}

/**
 * Send a standard JSON response and stop execution.
 */
function respond(bool $success, string $message = "", $data = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data"    => $data,
    ]);
    exit;
}

/**
 * Return the currently-logged-in user or null.
 */
function currentUser(): ?array {
    if (empty($_SESSION["user_id"])) return null;
    return [
        "user_id"  => (int) $_SESSION["user_id"],
        "username" => $_SESSION["username"] ?? "",
        "role"     => $_SESSION["role"] ?? "",
    ];
}

/**
 * Require an authenticated session. Sends 401 and exits if not logged in.
 * Skipped automatically for auth.php (see AUTH_ENDPOINT constant).
 */
function requireLogin(): void {
    if (!currentUser()) {
        respond(false, "Not authenticated. Please log in.", null, 401);
    }
}

/**
 * Require a specific role (or one of several roles).
 */
function requireRole($roles): void {
    requireLogin();
    $allowed = is_array($roles) ? $roles : [$roles];
    $user = currentUser();
    if (!in_array($user["role"], $allowed, true)) {
        respond(false, "You do not have permission to perform this action.", null, 403);
    }
}

// Auth endpoints opt out of the guard by defining AUTH_ENDPOINT first.
if (!defined("AUTH_ENDPOINT")) {
    requireLogin();
}