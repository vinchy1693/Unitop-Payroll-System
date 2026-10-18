<?php
// =====================================================================
// api/audit_log.php
//
// Read-only audit trail viewer. Administrator only, GET only.
//
// Filters (all optional):
//   ?limit=   rows per page  (default 100, max 500)
//   ?offset=  pagination offset (default 0)
//   ?action=  exact endpoint file name, e.g. departments.php
//   ?username=
//   ?success= 1 or 0
//   ?from=    YYYY-MM-DD (inclusive, from 00:00:00)
//   ?to=      YYYY-MM-DD (inclusive, to 23:59:59)
//
// Returns the matching rows plus `total` for the same filter set.
// =====================================================================

require __DIR__ . "/_bootstrap.php";

requireRole(ADMIN_ROLES);   // audit trail is Administrator-only

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
    respond(false, "Method not allowed. The audit log is read-only.", null, 405);
}

// ---- limit / offset --------------------------------------------------
$limit = isset($_GET["limit"]) && $_GET["limit"] !== "" ? (int) $_GET["limit"] : 100;
if ($limit < 1)   $limit = 100;
if ($limit > 500) $limit = 500;

$offset = isset($_GET["offset"]) && $_GET["offset"] !== "" ? (int) $_GET["offset"] : 0;
if ($offset < 0) $offset = 0;

// ---- filter clauses --------------------------------------------------
$where  = [];
$params = [];

$action = trim((string) ($_GET["action"] ?? ""));
if ($action !== "") {
    $where[]  = "`action` = ?";
    $params[] = $action;
}

$username = trim((string) ($_GET["username"] ?? ""));
if ($username !== "") {
    $where[]  = "`username` = ?";
    $params[] = $username;
}

if (isset($_GET["success"]) && $_GET["success"] !== "") {
    $success = (string) $_GET["success"];
    if ($success === "1" || strtolower($success) === "true") {
        $where[] = "`success` = 1";
    } elseif ($success === "0" || strtolower($success) === "false") {
        $where[] = "`success` = 0";
    } else {
        respond(false, "success must be 0 or 1.", null, 422);
    }
}

function audit_filter_date(string $value): ?string {
    $value = trim($value);
    if ($value === "") return null;
    $dt = DateTime::createFromFormat("!Y-m-d", $value);
    if (!$dt || $dt->format("Y-m-d") !== $value) return null;
    return $value;
}

$from = array_key_exists("from", $_GET) ? audit_filter_date((string) $_GET["from"]) : null;
if (array_key_exists("from", $_GET) && trim((string) $_GET["from"]) !== "" && $from === null) {
    respond(false, "from must be a valid date (YYYY-MM-DD).", null, 422);
}
if ($from !== null) {
    $where[]  = "`created_at` >= ?";
    $params[] = $from . " 00:00:00";
}

$to = array_key_exists("to", $_GET) ? audit_filter_date((string) $_GET["to"]) : null;
if (array_key_exists("to", $_GET) && trim((string) $_GET["to"]) !== "" && $to === null) {
    respond(false, "to must be a valid date (YYYY-MM-DD).", null, 422);
}
if ($to !== null) {
    $where[]  = "`created_at` <= ?";
    $params[] = $to . " 23:59:59";
}

$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

try {
    // ---- total for the filtered set ---------------------------------
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `audit_log`" . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // ---- page of rows ------------------------------------------------
    $sql = "SELECT `audit_id`, `user_id`, `username`, `role`, `action`, `method`,
                   `http_status`, `success`, `message`, `target`, `details`, `ip`, `created_at`
            FROM `audit_log`" . $whereSql . "
            ORDER BY `created_at` DESC, `audit_id` DESC
            LIMIT " . $limit . " OFFSET " . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row["audit_id"]    = (int) $row["audit_id"];
        $row["user_id"]     = $row["user_id"] !== null ? (int) $row["user_id"] : null;
        $row["http_status"] = (int) $row["http_status"];
        $row["success"]     = (int) $row["success"];
    }
    unset($row);

    respond(true, "", [
        "rows"   => $rows,
        "total"  => $total,
        "limit"  => $limit,
        "offset" => $offset,
    ]);
} catch (PDOException $e) {
    respond(false, "Audit log database error: " . $e->getMessage(), null, 500);
}
