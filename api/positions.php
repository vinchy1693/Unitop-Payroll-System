<?php
require __DIR__ . "/_bootstrap.php";

requireRole(PAYROLL_ROLES);   // maker + checker may read

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list (or ?simple=1 for dropdown options, ?all=1 for incl. inactive) ----
if ($method === "GET") {
    if (isset($_GET["simple"])) {
        $rows = $pdo->query(
            "SELECT position_id AS id, title AS label
             FROM positions WHERE is_active = 1 ORDER BY title"
        )->fetchAll();
        respond(true, "", $rows);
    }

    $showAll = isset($_GET["all"]);
    $sql = "SELECT p.*, d.department_name
            FROM positions p
            JOIN departments d ON d.department_id = p.department_id"
            . ($showAll ? "" : " WHERE p.is_active = 1")
            . " ORDER BY p.position_id";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: create ----
requireRole(MAKER_ROLES);   // only the Payroll Officer may write master data

if ($method === "POST") {
    $in    = json_input();
    $title = trim($in["title"] ?? "");
    $dept  = (int) ($in["department_id"] ?? 0);

    if ($title === "" || $dept === 0) {
        respond(false, "Title and Department are required.", null, 422);
    }

    $stmt = $pdo->prepare("INSERT INTO positions (department_id, title, is_active) VALUES (?, ?, 1)");
    $stmt->execute([$dept, $title]);

    respond(true, "Position added.", ["position_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in    = json_input();
    $id    = (int) ($in["position_id"] ?? 0);
    $title = trim($in["title"] ?? "");
    $dept  = (int) ($in["department_id"] ?? 0);

    if ($id === 0 || $title === "" || $dept === 0) {
        respond(false, "Title and Department are required.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE positions SET department_id = ?, title = ? WHERE position_id = ?");
    $stmt->execute([$dept, $title, $id]);

    respond(true, "Position updated.");
}

// ---- PATCH: soft delete / restore ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int) ($in["position_id"] ?? 0);
    $status = (int) ($in["is_active"] ?? 1);

    if ($id === 0) {
        respond(false, "Missing position_id.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE positions SET is_active = ? WHERE position_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status ? "Position restored." : "Position deactivated.");
}

respond(false, "Method not allowed.", null, 405);
