<?php
// =====================================================================
// dimobank/api.php
//
// GET  ?action=status       -> current demo bank status
// POST { online: bool }     -> change status (Finance & Accounting only)
//
// CSRF, login and JSON handling are inherited from the admin bootstrap.
// =====================================================================
require __DIR__ . "/../api/_bootstrap.php";
require __DIR__ . "/state.php";

requireRole(FINANCE_ROLE);

$method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));

if ($method === "GET") {
    respond(true, "", dimobank_read_state());
}

if ($method !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

$in = json_input();
if (!array_key_exists("online", $in) || !is_bool($in["online"])) {
    respond(false, "online must be true or false.", null, 422);
}

try {
    $state = dimobank_set_online((bool) $in["online"]);
    respond(
        true,
        $state["online"] ? "DimoBank is online." : "DimoBank is offline.",
        $state
    );
} catch (Throwable $e) {
    respondServerError("The demo bank status could not be updated.", $e, "dimobank/api.php");
}
