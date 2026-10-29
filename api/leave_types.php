<?php
// =====================================================================
// api/leave_types.php
//
// Leave types are a fixed catalogue. This endpoint is READ-ONLY:
// Payroll/HR cannot add, rename, deactivate or restore a leave type.
//
// The fixed catalogue is seeded by sql/018_fixed_leave_types.sql.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireRole(array_merge(PAYROLL_ROLES, [MANAGER_ROLE, SUPERVISOR_ROLE]));

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    $showAll = isset($_GET["all"]);
    $sql = "SELECT leave_type_id, name, description, days_per_year,
                   service_months, is_paid, is_cash_convertible, is_active
            FROM leave_types"
         . ($showAll ? "" : " WHERE is_active = 1")
         . " ORDER BY is_active DESC, leave_type_id";
    respond(true, "", $pdo->query($sql)->fetchAll());
}

respond(
    false,
    "Leave types are fixed and cannot be added, edited, deactivated or restored.",
    null,
    405
);
