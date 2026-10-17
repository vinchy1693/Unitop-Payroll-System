<?php
// =====================================================================
// tests/seed_demo_2employees.php
//
// Rebuilds a working demo from an empty database:
//   - 2 employees (Vinchy Fantonial, Juan Dela Cruz)
//   - a login for Juan Dela Cruz: juan.delacruz / test123
//   - 2 semi-monthly payroll periods covering the current month
//   - attendance for every working day of both periods for both employees,
//     with late days and one absence so the payroll output varies
//
// Idempotent: re-running does not duplicate employees, users or periods.
// Run:  C:\xampp\php\php.exe tests\seed_demo_2employees.php
// =====================================================================

require __DIR__ . '/../api/_payroll_helpers.php';

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tag = 'Demo';

// ---- periods: the current month, split semi-monthly ----
$now   = new DateTime('today');
$y     = (int) $now->format('Y');
$m     = (int) $now->format('m');
$first = sprintf('%04d-%02d-01', $y, $m);
$mid   = sprintf('%04d-%02d-16', $y, $m);
$last  = (new DateTime($first))->modify('last day of this month')->format('Y-m-d');

$periods = [[$first, sprintf('%04d-%02d-15', $y, $m)], [$mid, $last]];
$totalCalendar = (int) (new DateTime($first))->diff(new DateTime($last))->days + 1;
printf("Month under test: %s  (%d calendar days)\n", $now->format('F Y'), $totalCalendar);

function upsertEmployee(PDO $pdo, string $number, string $first, string $last, float $salary): int {
    $st = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_number = ?");
    $st->execute([$number]);
    $id = $st->fetchColumn();
    if ($id) { echo "  employee $number already exists (id $id)\n"; return (int) $id; }
    $pdo->prepare(
        "INSERT INTO employees (employee_number, first_name, last_name, birth_date, civil_status,
             position_id, department_id, date_hired, employment_status, basic_salary,
             tin_number, sss_number, philhealth_number, pagibig_number, is_active)
         VALUES (?,?,?,?,?,NULL,NULL,?,?,?,?,?,?,?,1)"
    )->execute([$number, $first, $last, '1995-01-01', 'Single', '2025-01-01', 'Active', $salary,
                'TIN-'.$number, 'SSS-'.$number, 'PH-'.$number, 'PI-'.$number]);
    $id = (int) $pdo->lastInsertId();
    echo "  created employee $number (id $id)\n";
    return $id;
}

function upsertUser(PDO $pdo, string $username, int $employeeId, string $role, ?string $password = null): void {
    $st = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $st->execute([$username]);
    $uid = $st->fetchColumn();
    if ($uid) {
        if ($password !== null) {
            $pdo->prepare("UPDATE users SET employee_id = ?, role = ?, is_active = 1, password_hash = ? WHERE user_id = ?")
                ->execute([$employeeId, $role, password_hash($password, PASSWORD_DEFAULT), $uid]);
            echo "  updated user $username (password reset)\n";
        } else {
            $pdo->prepare("UPDATE users SET employee_id = ?, role = ?, is_active = 1 WHERE user_id = ?")
                ->execute([$employeeId, $role, $uid]);
            echo "  relinked user $username\n";
        }
        return;
    }
    $pdo->prepare("INSERT INTO users (username, employee_id, role, is_active, password_hash) VALUES (?,?,?,1,?)")
        ->execute([$username, $employeeId, $role, password_hash($password ?? bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
    echo "  created user $username\n";
}

// ---- 1. employees ----
echo "\n[1] Employees\n";
$vinchy = upsertEmployee($pdo, 'EMP-0001', 'Vinchy',  'Fantonial', 60000.00);
$juan   = upsertEmployee($pdo, 'EMP-0002', 'Juan',    'Dela Cruz', 45000.00);

// ---- 2. logins ----
echo "\n[2] Login accounts\n";
upsertUser($pdo, 'vinchy', $vinchy, 'Employee', null);          // relink the existing one
upsertUser($pdo, 'juan.delacruz', $juan, 'Employee', 'test123'); // requested account
$st = $pdo->prepare("SELECT user_id FROM users WHERE username = 'admin'");
$st->execute();
if (!$st->fetchColumn()) {
    $pdo->prepare("INSERT INTO users (username, employee_id, role, is_active, password_hash) VALUES ('admin', NULL, 'Administrator', 1, ?)")
        ->execute([password_hash('test123', PASSWORD_DEFAULT)]);
    echo "  created admin (password test123)\n";
} else {
    $pdo->prepare("UPDATE users SET password_hash = ?, role = 'Administrator' WHERE username = 'admin'")
        ->execute([password_hash('test123', PASSWORD_DEFAULT)]);
    echo "  admin password set to test123\n";
}

// ---- 3. periods + attendance ----
echo "\n[3] Payroll periods and attendance\n";
$insAtt = $pdo->prepare(
    "INSERT INTO attendance (employee_id, qr_code_id, work_date, time_in, time_out,
                             scan_device_id, hours_worked, minutes_late, status)
     VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)"
);
$delAtt = $pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND scan_device_id = ?");

$plan = [
    // employee_id => ['late_days' => [index => minutes], 'absent_days' => [index], 'salary' => x]
    $vinchy => ['salary' => 60000.00, 'late' => [3 => 15], 'absent' => []],
    $juan   => ['salary' => 45000.00, 'late' => [1 => 20, 6 => 30], 'absent' => [4]],
];

$created = 0;
foreach ($periods as $pi => [$ps, $pe]) {
    $st = $pdo->prepare("SELECT period_id FROM payroll_periods WHERE start_date = ? AND end_date = ? AND period_type = 'Semi-monthly'");
    $st->execute([$ps, $pe]);
    $pid = $st->fetchColumn();
    if (!$pid) {
        $pdo->prepare("INSERT INTO payroll_periods (start_date, end_date, period_type, status) VALUES (?,?,'Semi-monthly','Open')")
            ->execute([$ps, $pe]);
        $pid = (int) $pdo->lastInsertId();
        echo "  period ".($pi+1).": $ps .. $pe (id $pid, Open) CREATED\n";
    } else {
        echo "  period ".($pi+1).": $ps .. $pe (id $pid, exists)\n";
    }

    // working days
    $days = [];
    $c = new DateTime($ps); $e = (new DateTime($pe))->modify('+1 day');
    while ($c < $e) { if (isWorkingDay($c)) $days[] = $c->format('Y-m-d'); $c->modify('+1 day'); }

    foreach ([$vinchy, $juan] as $empId) {
        $p = $plan[$empId];
        $delAtt->execute([$empId, $ps, $pe, $tag]);
        foreach ($days as $i => $d) {
            if ($pi === 1 && in_array($i, $p['absent'], true)) { $created++; continue; } // no row = absence
            $late = ($pi === 0 && isset($p['late'][$i])) ? $p['late'][$i] : (($pi === 1 && isset($p['late'][$i])) ? $p['late'][$i] : 0);
            if ($late > 0) {
                $in  = (new DateTime('08:00:00'))->modify("+$late minutes")->format('H:i:s');
                $mins = $late + 480;                        // 08:00 + late -> 17:00
                $net  = $mins > 300 ? $mins - 60 : $mins;
                $insAtt->execute([$empId, $d, $in, '17:00:00', $tag, round($net / 60, 2), $late, 'Late']);
            } else {
                $insAtt->execute([$empId, $d, '08:00:00', '17:00:00', $tag, 8.00, 0, 'Present']);
            }
            $created++;
        }
        printf("    emp %d: %d working days seeded for %s..%s\n", $empId, count($days), $ps, $pe);
    }
}
printf("\nAttendance rows written: %d\n", $created);

// ---- 4. report what payroll will compute ----
echo "\n[4] What each payroll run will compute\n";
foreach ($periods as $pi => [$ps, $pe]) {
    printf("\n  Period %d: %s .. %s  (working days: %d)\n", $pi + 1, $ps, $pe, countWorkingDays($ps, $pe));
    foreach ([$vinchy => 'Vinchy Fantonial', $juan => 'Juan Dela Cruz'] as $empId => $name) {
        $sal = (float) $pdo->query("SELECT basic_salary FROM employees WHERE employee_id = " . (int) $empId)->fetchColumn();
        $a = computeAttendanceDeductions($pdo, (int) $empId, $ps, $pe, $sal);
        printf("    %-18s expected=%d present=%d leave=%d absent=%d late=%d  daily=%.2f  absence_ded=%.2f  late_ded=%.2f\n",
            $name, $a['expected_days'], $a['present_days'], $a['leave_days'], $a['absent_days'],
            $a['late_minutes'], $a['daily_rate'], $a['absence_deduction'], $a['late_deduction']);
    }
}

echo "\nDone. Logins: admin/test123 (Administrator), juan.delacruz/test123 (Employee).\n";
