<?php
require __DIR__ . "/_bootstrap.php";

requireRole(array_merge(PAYROLL_ROLES, [MANAGER_ROLE, SUPERVISOR_ROLE]));   // payroll + team leads may read

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list (?all=1 for incl. inactive) ----
if ($method === "GET") {
    $showAll = isset($_GET["all"]);
    $sql = "SELECT * FROM leave_types" . ($showAll ? "" : " WHERE is_active = 1") . " ORDER BY leave_type_id";
    $rows = $pdo->query($sql)->fetchAll();
    respond(true, "", $rows);
}

// ---- POST: create ----
requireRole(MAKER_ROLES);   // only the Payroll Officer may write leave setup

if ($method === "POST") {
    $in   = json_input();
    $name = trim($in["name"] ?? "");
    $days = (float) ($in["days_per_year"] ?? 0);

    if ($name === "") {
        respond(false, "Leave type name is required.", null, 422);
    }

    // All leave types are paid — is_paid is not user-editable
    $stmt = $pdo->prepare(
        "INSERT INTO leave_types (name, days_per_year, is_paid, is_active)
         VALUES (?, ?, 1, 1)"
    );
    $stmt->execute([$name, $days]);

    respond(true, "Leave type added.", ["leave_type_id" => (int) $pdo->lastInsertId()]);
}

// ---- PUT: update ----
if ($method === "PUT") {
    $in   = json_input();
    $id   = (int)  ($in["leave_type_id"] ?? 0);
    $name = trim($in["name"] ?? "");
    $days = (float) ($in["days_per_year"] ?? 0);

    if ($id === 0 || $name === "") {
        respond(false, "Leave type name is required.", null, 422);
    }

    // Force is_paid = 1 regardless of what the client sent
    $stmt = $pdo->prepare(
        "UPDATE leave_types SET name = ?, days_per_year = ?, is_paid = 1 WHERE leave_type_id = ?"
    );
    $stmt->execute([$name, $days, $id]);

    respond(true, "Leave type updated.");
}

// ---- PATCH: soft delete / restore ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int)  ($in["leave_type_id"] ?? 0);
    $status = (int)  ($in["is_active"]     ?? 1);

    if ($id === 0) {
        respond(false, "Missing leave_type_id.", null, 422);
    }

    $stmt = $pdo->prepare("UPDATE leave_types SET is_active = ? WHERE leave_type_id = ?");
    $stmt->execute([$status, $id]);

    respond(true, $status ? "Leave type restored." : "Leave type deactivated.");
}

respond(false, "Method not allowed.", null, 405);