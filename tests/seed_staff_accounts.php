<?php
// =====================================================================
// tests/seed_staff_accounts.php
//
// Idempotent seeder for the three staff logins the maker/checker workflow
// needs. Run once (or any time) with:
//
//   C:\xampp\php\php.exe tests\seed_staff_accounts.php
//
// Accounts
//   payroll  -> Payroll Officer (the maker)
//   approver -> Approver        (the checker)
//   admin    -> Administrator   (technical only)
//
// Password rule: a new account gets "test123". An account that already
// exists keeps its current password and is never overwritten; only its role
// and active flag are aligned with the matrix. The real master data
// (employees 4/5/8, users 4/5/34, positions 4/5, departments 4/5) is never
// touched, and nothing is ever deleted.
// =====================================================================

require __DIR__ . "/../config.php";

const SEED_PASSWORD = "test123";

$accounts = [
    ["username" => "payroll",  "role" => "Payroll Officer"],
    ["username" => "approver", "role" => "Approver"],
    ["username" => "admin",    "role" => "Administrator"],
];

$created = 0;
$kept    = 0;

echo "Seeding staff accounts in payroll_db\n";
echo str_repeat("=", 60) . "\n";

foreach ($accounts as $account) {
    $username = $account["username"];
    $role     = $account["role"];

    $stmt = $pdo->prepare("SELECT user_id, role, is_active FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Never touch password_hash: an existing operator's password stays.
        if ($existing["role"] !== $role || (int) $existing["is_active"] !== 1) {
            $pdo->prepare("UPDATE users SET role = ?, is_active = 1 WHERE user_id = ?")
                ->execute([$role, $existing["user_id"]]);
            printf("  kept     %-8s (user #%d) - role aligned to %s, password unchanged\n",
                $username, $existing["user_id"], $role);
        } else {
            printf("  kept     %-8s (user #%d) - %s, password unchanged\n",
                $username, $existing["user_id"], $role);
        }
        $kept++;
        continue;
    }

    $hash = password_hash(SEED_PASSWORD, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, role, password_hash, employee_id, is_active)
         VALUES (?, ?, ?, NULL, 1)"
    );
    $stmt->execute([$username, $role, $hash]);
    printf("  created  %-8s (user #%d) - %s / %s\n",
        $username, (int) $pdo->lastInsertId(), $role, SEED_PASSWORD);
    $created++;
}

echo str_repeat("=", 60) . "\n";
printf("created: %d   existing (password preserved): %d\n", $created, $kept);
echo "Done.\n";
