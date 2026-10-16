<?php
// =====================================================================
// api/_bootstrap.php
// Session + DB + JSON helpers.
// =====================================================================

if (session_status() === PHP_SESSION_NONE) {
    // Own session cookie name, so an employee-portal login cannot be replayed
    // against this admin site (and vice versa).
    session_name("admin_sid");
    session_start();
}

// Roles permitted to manage master data and run payroll. Endpoints that are
// admin-only call requireRole(PAYROLL_ROLES) straight after this include.
const PAYROLL_ROLES = ["Administrator", "Payroll Officer"];

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

function json_input(): array {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    return $_POST ?: [];
}

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
 * Includes `employee_id` when the user is linked to an employee record.
 */
function currentUser(): ?array {
    if (empty($_SESSION["user_id"])) return null;
    return [
        "user_id"     => (int) $_SESSION["user_id"],
        "username"    => $_SESSION["username"] ?? "",
        "role"        => $_SESSION["role"] ?? "",
        "employee_id" => isset($_SESSION["employee_id"]) && $_SESSION["employee_id"] !== null
            ? (int) $_SESSION["employee_id"]
            : null,
    ];
}

function requireLogin(): void {
    if (!currentUser()) {
        respond(false, "Not authenticated. Please log in.", null, 401);
    }
}

function requireRole($roles): void {
    requireLogin();
    $allowed = is_array($roles) ? $roles : [$roles];
    $user    = currentUser();
    if (!in_array($user["role"], $allowed, true)) {
        respond(false, "You do not have permission to perform this action.", null, 403);
    }
}

if (!defined("AUTH_ENDPOINT")) {
    requireLogin();
}