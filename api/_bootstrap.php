<?php
// Pin the application timezone. Without this PHP falls back to the php.ini
// default (here Europe/Berlin) while MySQL runs on the machine's local time
// (Asia/Manila), so work_date and the scan times drifted by six hours - a scan
// at 14:00 local was recorded as 08:00, which silently made every late
// calculation wrong and could stamp attendance on the wrong day.
date_default_timezone_set("Asia/Manila");

// Never render PHP errors into a JSON response: a PDOException message can
// contain the SQL, the schema and the credentials. Log it instead.
ini_set("display_errors", "0");
ini_set("log_errors", "1");
// =====================================================================
// api/_bootstrap.php
// Session + DB + JSON helpers.
// =====================================================================

if (session_status() === PHP_SESSION_NONE) {
    // Own session cookie name, so an employee-portal login cannot be replayed
    // against this admin site (and vice versa).
    session_name("admin_sid");

    // Session cookie flags: HttpOnly keeps the SID away from JavaScript,
    // SameSite=Lax stops a cross-site form from carrying it, and Secure is set
    // whenever the site is served over HTTPS (it would break plain localhost).
    $cookieSecure = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => $cookieSecure,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    session_start();
}

// =====================================================================
// CSRF: a per-session token, published in a readable cookie and required in
// the X-CSRF-Token header on every mutating request. A cross-site form can
// send the cookie but cannot read it, so it cannot set the header; and it
// cannot send application/json at all.
// =====================================================================

const CSRF_COOKIE = "admin_csrf";

function csrf_is_https(): bool {
    return !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
}

function csrf_token(): string {
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION["csrf_token"];
}

function csrf_cookie_options(int $expires = 0): array {
    return [
        "expires"  => $expires,
        "path"     => "/",
        "secure"   => csrf_is_https(),
        "httponly" => false,   // the page script must read it to set the header
        "samesite" => "Lax",
    ];
}

/** Publish the current token to the readable cookie (idempotent). */
function csrf_publish(): void {
    if (!headers_sent()) {
        setcookie(CSRF_COOKIE, csrf_token(), csrf_cookie_options());
    }
}

/** Issue a fresh token, e.g. after login, and republish the cookie. */
function csrf_rotate(): string {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    csrf_publish();
    return (string) $_SESSION["csrf_token"];
}

/** Drop the token on logout. */
function csrf_clear(): void {
    if (!headers_sent()) {
        setcookie(CSRF_COOKIE, "", csrf_cookie_options(time() - 3600));
    }
    unset($_SESSION["csrf_token"]);
}

csrf_publish();

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
// Segregation of duties (manager / supervisor / payroll)
//
//   Employee        - portal self-service only
//   Manager         - team lead: manages team attendance, files overtime,
//                     and gives the single approval on team leave/corrections
//   Supervisor      - reviews attendance across teams and gives the single
//                     approval on leave/corrections and overtime
//   Payroll Officer - combined Payroll/HR: master data, loans and payroll
//   Administrator   - technical only: users + audit trail
//
// PAYROLL_ROLES is deliberately one combined Payroll/HR role. It opens
// salaries, payslips, payroll periods/runs, reports and the dashboard.
// A Manager or Supervisor never sees company payroll figures.
//
// TEAM_QUEUE_ROLES is the roles that reach the team queues (attendance,
// overtime, leave, loans). Payroll keeps the whole queue; a Manager or
// Supervisor read is narrowed to their own team by teamScopeIds(), and a
// write is checked by assertManages() / assertSupervises().
// =====================================================================
const MAKER_ROLES      = ["Payroll Officer"];
const CHECKER_ROLES    = ["Payroll Officer"];  // combined Payroll/HR
const ADMIN_ROLES      = ["Administrator"];
const MANAGER_ROLE     = "Manager";
const SUPERVISOR_ROLE  = "Supervisor";
const PAYROLL_ROLES    = ["Payroll Officer"];
const TEAM_QUEUE_ROLES = ["Payroll Officer", "Manager", "Supervisor"];

// Every role the users screen may assign.
const ALL_ROLES = ["Employee", "Payroll Officer", "Manager", "Supervisor", "Administrator"];

// Demo scanning lets the caller supply a date and time, so it writes attendance
// outside the real clock. It is a training aid, not a kiosk feature: the maker
// is the only role allowed to use it, and a real deployment turns it off here.
const ALLOW_DEMO_SCANS = true;

// Short-lived QR tokens. The portal displays a token, not the static badge:
// 120 seconds, bound to the next action, single use. The kiosk accepts the
// legacy static qr_codes value while printed badges are still in circulation;
// flip this to false once every employee has a token QR.
const QR_TOKEN_TTL_SECONDS = 120;
const ALLOW_LEGACY_QR = true;

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

try {
    require __DIR__ . "/../config.php";
} catch (PDOException $e) {
    // Never echo the driver message: it names the host, the schema and can
    // include the SQL. The operator reads the server log; the client gets a
    // neutral message.
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed. Please contact the administrator.",
        "data"    => null,
    ]);
    exit;
}

function json_input(): array {
    $raw = audit_raw_body();
    $data = json_decode($raw, true);
    // JSON only. The old $_POST fallback let a cross-site form reach an
    // endpoint without the browser ever sending application/json.
    return is_array($data) ? $data : [];
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
// CSRF guard
// =====================================================================

/**
 * Log the real exception and return a neutral 500. The client must never see
 * a PDOException message (it names the SQL, the schema and sometimes the
 * credentials); the operator reads the server log instead.
 */
function respondServerError(string $clientMessage, Throwable $e, string $logContext = ""): void {
    $prefix = $logContext !== "" ? $logContext . ": " : "";
    error_log(
        $prefix . get_class($e) . ": " . $e->getMessage()
        . " in " . $e->getFile() . ":" . $e->getLine()
    );
    respond(false, $clientMessage, null, 500);
}

/** The login form has no session token yet; JSON-only + SameSite cover it. */
function csrf_request_is_exempt(): bool {
    $script = basename((string) parse_url((string) ($_SERVER["SCRIPT_NAME"] ?? ""), PHP_URL_PATH));
    $action = strtolower(trim((string) ($_GET["action"] ?? "")));
    return $script === "auth.php" && $action === "login";
}

/**
 * Require the session token on every non-GET request, and require a JSON
 * content type on POST/PUT/PATCH. respond() exits, so this never returns when
 * it refuses.
 */
function assertCsrf(): void {
    $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if (in_array($method, ["GET", "HEAD", "OPTIONS"], true)) return;
    if (csrf_request_is_exempt()) return;

    $expected = (string) ($_SESSION["csrf_token"] ?? "");
    $sent     = (string) ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    if ($expected === "" || $sent === "" || !hash_equals($expected, $sent)) {
        respond(
            false,
            "Your session token is missing or has expired. Refresh the page and try again.",
            ["code" => "csrf_failed"],
            403
        );
    }

    // A browser form cannot send application/json. DELETE is exempt because it
    // normally carries no body; its token check above is what protects it.
    if ($method !== "DELETE") {
        $ctype = strtolower((string) ($_SERVER["CONTENT_TYPE"] ?? ""));
        if (!str_contains($ctype, "application/json")) {
            respond(false, "This endpoint accepts JSON requests only.", null, 415);
        }
    }
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
 * True for any key whose value must never be persisted: credentials, tokens,
 * and the identifiers that are themselves live secrets (a QR code is a bearer
 * credential; bank and government numbers are personal data under RA 10173).
 * Case / punctuation insensitive.
 */
function audit_is_secret_key(string $key): bool {
    $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);

    // Substring match: these must also catch compound names (password_hash,
    // bank_account_number is handled exactly below so sss_ee is not hit).
    foreach (["password", "passwd", "token", "csrf", "secret", "apikey"] as $needle) {
        if (str_contains($normalized, $needle)) return true;
    }

    // Exact match, so a contribution amount like sss_ee or an id like
    // qr_code_id is still visible while the number / code itself is not.
    return in_array($normalized, [
        "codevalue",          // the QR value the kiosk scans (a bearer credential)
        "qrcodevalue",
        "qrtoken",            // short-lived kiosk token (also a bearer credential)
        "tokenhash",
        "bankaccountnumber",
        "accountnumber",
        "tinnumber",
        "sssnumber",
        "philhealthnumber",
        "pagibignumber",
    ], true);
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

// =====================================================================
// Supervisor team scope
//
// A team is defined structurally and only once: every employee whose
// employees.supervisor_id points at this employee. There is no teams table
// and no cached membership list, so the guard and the query can never
// disagree about who is on a team.
//
// The four functions below are the ONLY place team scope is decided; every
// supervisor-aware endpoint calls them rather than writing its own rule.
//
//   currentEmployeeId()                        -> ?int    acting user's employee
//   supervisorTeamIds($pdo, $supervisorEmpId)  -> int[]   that employee's team
//   supervisorScopeIds($pdo)                   -> ?int[]  read scope for a GET
//                                                          (null = no scope)
//   assertSupervises($pdo, $employeeId, $what) -> void    403 unless a
//                                                          team-member request
//   assertTeamReadScope($pdo, $employeeId, $what) -> void 403 for an
//                                                          out-of-team read
//
// The own-request rule lives in assertSupervises(): the acting Supervisor's
// own employee_id is never a member of their own team, so approving your own
// request is refused BOTH by the explicit self check and by the team test.
//
// A Supervisor user with no employee_id supervises nobody: supervisorTeamIds()
// returns [], supervisorScopeIds() returns [], and every guard answers 403
// (never a PHP error, never a 500).
// =====================================================================

/** True when the acting user's role is Supervisor. */
function isSupervisor(): bool {
    $user = currentUser();
    return $user !== null && (string) $user["role"] === SUPERVISOR_ROLE;
}

/** True when the acting user's role is Manager. */
function isManager(): bool {
    $user = currentUser();
    return $user !== null && (string) $user["role"] === MANAGER_ROLE;
}

/**
 * The acting user's employee_id, or null for a staff account that is not
 * linked to an employee record (payroll, approver, admin and a Supervisor
 * created without a link).
 */
function currentEmployeeId(): ?int {
    $user = currentUser();
    if ($user === null) return null;
    return $user["employee_id"] !== null ? (int) $user["employee_id"] : null;
}

/**
 * Refuse a decision on the acting user's own request. This applies to every
 * role, not only Supervisors: a Payroll Officer whose account is linked to an
 * employee must not approve their own overtime, leave, correction or loan.
 */
function assertNotOwnEmployee(int $employeeId, string $what): void {
    $self = currentEmployeeId();
    if ($self !== null && $employeeId === $self) {
        respond(
            false,
            "You cannot $what because it is your own request. Another reviewer must decide it.",
            null,
            403
        );
    }
}

/**
 * Employee ids whose supervisor_id is $supervisorEmployeeId (the team that
 * employee leads). Never includes the supervisor themselves. A non-positive
 * id - the shape a NULL employee link takes - yields an empty team.
 */
function supervisorTeamIds(PDO $pdo, int $supervisorEmployeeId): array {
    if ($supervisorEmployeeId <= 0) return [];
    $stmt = $pdo->prepare(
        "SELECT employee_id FROM employees WHERE supervisor_id = ? ORDER BY employee_id"
    );
    $stmt->execute([$supervisorEmployeeId]);
    return array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The employee ids a GET list must be filtered to.
 *
 *   null  - the caller is not a Supervisor: no scope, keep the full queue
 *   []    - the caller is a Supervisor who supervises nobody (no employee
 *           link, or an empty team): the list must come back empty
 *   int[] - the caller's team
 *
 * Callers splice the ids into an `employee_id IN (...)` condition; they never
 * build one themselves, so a new endpoint cannot forget the scope.
 */
function supervisorScopeIds(PDO $pdo): ?array {
    if (!isSupervisor()) return null;
    // Supervisors have full attendance/queue visibility. Manager remains
    // team-scoped through managerScopeIds().
    return null;
}

/**
 * Manager scope. Managers use the same team assignment as Supervisors for
 * now: employees.supervisor_id points at the team lead. This lets a Manager
 * and a Supervisor share a team without adding a parallel assignment table.
 */
function managerScopeIds(PDO $pdo): ?array {
    if (!isManager()) return null;
    $self = currentEmployeeId();
    if ($self === null) return [];
    return supervisorTeamIds($pdo, $self);
}

/** Team scope for a Manager or Supervisor; null for payroll/admin. */
function teamScopeIds(PDO $pdo): ?array {
    if (isManager()) return managerScopeIds($pdo);
    if (isSupervisor()) return supervisorScopeIds($pdo);
    return null;
}

/**
 * Authorise a Manager to decide a team member's request. The Manager uses the
 * same employee team mapping as a Supervisor until a separate manager_id
 * assignment is introduced.
 */
function assertManages(PDO $pdo, int $employeeId, string $what): void {
    if (!isManager()) {
        respond(false, "Only a Manager may $what.", null, 403);
    }

    $self = currentEmployeeId();
    if ($self === null) {
        respond(
            false,
            "This Manager account is not linked to an employee record, so it leads no team "
            . "and cannot $what.",
            null,
            403
        );
    }

    if ($employeeId === $self) {
        respond(
            false,
            "You cannot $what because it is your own request. Another reviewer must decide it.",
            null,
            403
        );
    }

    if (!in_array($employeeId, supervisorTeamIds($pdo, $self), true)) {
        respond(
            false,
            "Employee #$employeeId is not in your team, so you cannot $what. "
            . "A Manager may only decide their own team's requests.",
            null,
            403
        );
    }
}

/**
 * Authorise a Supervisor to decide a team member's request.
 *
 * 403 unless ALL of these hold:
 *   - the acting user is a Supervisor
 *   - their account is linked to an employee (otherwise they lead no team)
 *   - the target employee is not the Supervisor themselves (own-request rule)
 *   - the target employee's supervisor_id is that Supervisor (same team)
 *
 * respond() exits, so this never returns when it refuses. $what is a verb
 * phrase such as "approve or reject this attendance correction".
 */
function assertSupervises(PDO $pdo, int $employeeId, string $what): void {
    if (!isSupervisor()) {
        respond(false, "Only a Supervisor may $what.", null, 403);
    }

    $self = currentEmployeeId();
    if ($self === null) {
        respond(
            false,
            "This Supervisor account is not linked to an employee record, so it leads no team "
            . "and cannot $what.",
            null,
            403
        );
    }

    // Own-request rule, stated in the message because it is the one refusal a
    // Supervisor is most likely to hit by accident.
    if ($employeeId === $self) {
        respond(
            false,
            "You cannot $what because it is your own request. A Supervisor may never approve, "
            . "reject or recommend their own request - another reviewer must decide it.",
            null,
            403
        );
    }

    // Supervisors have full operational visibility; only the own-request
    // rule above is employee-specific. Managers remain team-scoped.
}

/**
 * Restrict a single-record READ to the acting Supervisor's team.
 *
 * A no-op for every other role, so the maker and the checker keep the full
 * view and a read branch can call it unconditionally. 403 for a Supervisor
 * asking about an employee outside their team (an explicit `?employee_id=`
 * or `?id=` lookup is answered, not silently emptied, so the caller is told
 * why). respond() exits, so this never returns when it refuses.
 */
function assertTeamReadScope(PDO $pdo, int $employeeId, string $what): void {
    if (isSupervisor()) return;
    if (!isManager()) return;

    $self = currentEmployeeId();
    $team = $self !== null ? supervisorTeamIds($pdo, $self) : [];
    if (!in_array($employeeId, $team, true)) {
        respond(
            false,
            "A Manager may only $what for their own team. "
            . "Employee #$employeeId is not in your team.",
            null,
            403
        );
    }
}

assertCsrf();
if (!defined("AUTH_ENDPOINT")) {
    requireLogin();
}

// Last-resort handler: an uncaught exception must never reach the client as a
// stack trace or a PDOException message. The detail goes to the server log.
set_exception_handler(function (Throwable $e): void {
    error_log(
        "Unhandled " . get_class($e) . ": " . $e->getMessage()
        . " in " . $e->getFile() . ":" . $e->getLine()
    );
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
    }
    echo json_encode([
        "success" => false,
        "message" => "Internal server error. Please try again or contact the administrator.",
        "data"    => null,
    ]);
});