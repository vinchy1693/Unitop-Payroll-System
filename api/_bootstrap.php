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

// Identity snapshot for the audit trail. respond() is the single write point,
// and logout destroys the session before it calls respond(), so remember who
// started the request while the session is still available.
$GLOBALS["AUDIT_ACTOR"] = [
    "user_id"  => isset($_SESSION["user_id"]) ? (int) $_SESSION["user_id"] : null,
    "username" => isset($_SESSION["username"]) && $_SESSION["username"] !== ""
        ? (string) $_SESSION["username"] : null,
    "role"     => isset($_SESSION["role"]) && $_SESSION["role"] !== ""
        ? (string) $_SESSION["role"] : null,
];

// =====================================================================
// Segregation of duties (maker / checker)
//
//   Employee        - portal self-service only
//   Payroll Officer - the MAKER: prepares master data and payroll runs
//   Approver        - the CHECKER: reads everything, decides payroll runs
//   Administrator   - technical only: users + audit trail
//
// PAYROLL_ROLES is deliberately maker + checker: reading payroll data is
// shared, deciding it is not. Administrator is NOT part of it.
// =====================================================================
const MAKER_ROLES   = ["Payroll Officer"];
const CHECKER_ROLES = ["Approver"];
const ADMIN_ROLES   = ["Administrator"];
const PAYROLL_ROLES = ["Payroll Officer", "Approver"];   // maker + checker

// Every role the users screen may assign.
const ALL_ROLES = ["Employee", "Payroll Officer", "Approver", "Administrator"];

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
    $raw = audit_raw_body();
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    return $_POST ?: [];
}

function respond(bool $success, string $message = "", $data = null, int $status = 200): void {
    http_response_code($status);

    // Every mutating request writes exactly one audit row from this single
    // place. Logging must never break the response, so any failure is
    // swallowed (and reported to the server log instead).
    try {
        audit_log_mutation($success, $message, $status);
    } catch (Throwable $e) {
        error_log("audit_log write failed: " . $e->getMessage());
    }

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data"    => $data,
    ]);
    exit;
}

// =====================================================================
// Audit trail
// =====================================================================

/**
 * Read the raw request body once and cache it. json_input() and the audit
 * hook both need it, and php://input is not rewindable on every SAPI.
 */
function audit_raw_body(): string {
    if (!array_key_exists("AUDIT_RAW_BODY", $GLOBALS)) {
        $GLOBALS["AUDIT_RAW_BODY"] = (string) @file_get_contents("php://input");
    }
    return $GLOBALS["AUDIT_RAW_BODY"];
}

/**
 * Who made this request. Prefers the live session; falls back to the
 * bootstrap-time snapshot so a logout is still attributed to the user who
 * performed it. Anonymous requests produce nulls.
 */
function audit_actor(): array {
    $user = currentUser();
    if ($user !== null) {
        return [
            "user_id"  => (int) $user["user_id"],
            "username" => $user["username"] !== "" ? $user["username"] : null,
            "role"     => $user["role"] !== "" ? $user["role"] : null,
        ];
    }

    $snapshot = $GLOBALS["AUDIT_ACTOR"] ?? [];
    return [
        "user_id"  => $snapshot["user_id"] ?? null,
        "username" => $snapshot["username"] ?? null,
        "role"     => $snapshot["role"] ?? null,
    ];
}

/**
 * True for any key whose value must never be persisted: password fields,
 * tokens, CSRF values, secrets and API keys (case / punctuation insensitive).
 */
function audit_is_secret_key(string $key): bool {
    $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);
    foreach (["password", "passwd", "token", "csrf", "secret", "apikey"] as $needle) {
        if (str_contains($normalized, $needle)) return true;
    }
    return false;
}

/**
 * Recursively replace secret values with "***" before serializing the body.
 * Truncates very long strings so one huge field cannot blow up the row.
 */
function audit_sanitize($value, int $depth = 0) {
    if ($depth > 8) return "[truncated]";

    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && audit_is_secret_key($key)) {
                $clean[$key] = "***";
            } else {
                $clean[$key] = audit_sanitize($item, $depth + 1);
            }
        }
        return $clean;
    }

    if (is_string($value) && strlen($value) > 2000) {
        return substr($value, 0, 2000) . "...";
    }

    return $value;
}

/**
 * Derive the affected record where it is cheap and unambiguous. Priority:
 *   1. an explicit `id` in the body
 *   2. the endpoint's own `<entity>_id` (department_id for departments.php)
 *   3. for PUT/PATCH/DELETE, the first `*_id` in the body
 * Creates normally reference other entities (employee_id on a leave
 * request), so they deliberately stay NULL.
 */
function audit_target(array $body, string $method, string $script): ?string {
    $entity = strtolower(pathinfo($script, PATHINFO_FILENAME));

    $candidates = ["id"];
    if ($entity !== "") {
        $candidates[] = $entity . "_id";
        if (str_ends_with($entity, "s")) {
            $candidates[] = substr($entity, 0, -1) . "_id";
        }
    }

    foreach ($candidates as $key) {
        if (array_key_exists($key, $body) && is_scalar($body[$key]) && (string) $body[$key] !== "") {
            return $key . "=" . (string) $body[$key];
        }
    }

    if (in_array($method, ["PUT", "PATCH", "DELETE"], true)) {
        foreach ($body as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z0-9]+_id$/i', $key)
                && is_scalar($value) && (string) $value !== "") {
                return $key . "=" . (string) $value;
            }
        }
    }

    return null;
}

/** Trim a value to a column width, marking anything that was cut. */
function audit_trim(?string $value, int $max): ?string {
    if ($value === null) return null;

    if (function_exists("mb_strlen") && function_exists("mb_substr")) {
        if (mb_strlen($value, "UTF-8") > $max) {
            return mb_substr($value, 0, max(0, $max - 3), "UTF-8") . "...";
        }
        return $value;
    }

    if (strlen($value) > $max) {
        return substr($value, 0, max(0, $max - 3)) . "...";
    }
    return $value;
}

/**
 * Write one audit row for a mutating request. GET/HEAD/OPTIONS are never
 * logged. Called only from respond(), wrapped in try/catch there.
 */
function audit_log_mutation(bool $success, string $message, int $status): void {
    $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if ($method === "GET" || $method === "HEAD" || $method === "OPTIONS") {
        return;
    }

    $pdo = $GLOBALS["pdo"] ?? null;
    if (!($pdo instanceof PDO)) {
        return;
    }

    $script = basename((string) parse_url((string) ($_SERVER["SCRIPT_NAME"] ?? ""), PHP_URL_PATH));
    if ($script === "" || $script === ".") {
        $script = "unknown.php";
    }

    $actor  = audit_actor();
    $body   = json_input();
    $target = audit_target($body, $method, $script);

    $details = null;
    if (!empty($body)) {
        $encoded = json_encode(
            audit_sanitize($body),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (is_string($encoded)) {
            if (strlen($encoded) > 60000) {
                $encoded = json_encode([
                    "_note"  => "Request body too large to store.",
                    "_bytes" => strlen($encoded),
                ]);
            }
            $details = $encoded;
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO `audit_log`
            (`user_id`, `username`, `role`, `action`, `method`, `http_status`,
             `success`, `message`, `target`, `details`, `ip`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $actor["user_id"],
        audit_trim($actor["username"], 100),
        audit_trim($actor["role"], 50),
        audit_trim($script, 80),
        audit_trim($method, 10),
        $status,
        ($success && $status < 400) ? 1 : 0,
        audit_trim($message, 500),
        audit_trim($target, 200),
        $details,
        audit_trim($_SERVER["REMOTE_ADDR"] ?? null, 45),
    ]);
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