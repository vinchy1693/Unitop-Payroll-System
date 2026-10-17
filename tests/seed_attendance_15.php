<?php
// =====================================================================
// tests/seed_attendance_15.php
//
// Seeds a full semi-monthly period of attendance for EVERY active employee,
// for payroll testing. Idempotent: re-running replaces only its own rows
// (scan_device_id = 'Test15').
//
// Run:  C:\xampp\php\php.exe tests\seed_attendance_15.php [start] [end]
//       default period: 2026-12-01 .. 2026-12-15
// =====================================================================

require __DIR__ . '/../api/_payroll_helpers.php';

$start = $argv[1] ?? '2026-12-01';
$end   = $argv[2] ?? '2026-12-15';
$tag   = 'Test15';

$pdo = new PDO('mysql:host=localhost;dbname=payroll_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

printf("Period: %s .. %s\n", $start, $end);
printf("Working days (Mon-Sat): %d\n", countWorkingDays($start, $end));
printf("Calendar days: %d\n\n", (int) (new DateTime($start))->diff(new DateTime($end))->days + 1);

// ---- 1. ensure the payroll period exists (don't disturb other periods) ----
$st = $pdo->prepare("SELECT period_id, status FROM payroll_periods WHERE start_date = ? AND end_date = ? AND period_type = 'Semi-monthly'");
$st->execute([$start, $end]);
$period = $st->fetch();

if ($period) {
    printf("Payroll period already exists: id=%d status=%s\n", $period['period_id'], $period['status']);
    if ($period['status'] !== 'Open') {
        $pdo->prepare("UPDATE payroll_periods SET status = 'Open' WHERE period_id = ?")->execute([$period['period_id']]);
        printf("  -> reset to Open so it can be processed for testing\n");
    }
    $periodId = (int) $period['period_id'];
} else {
    $pdo->prepare("INSERT INTO payroll_periods (start_date, end_date, period_type, status) VALUES (?, ?, 'Semi-monthly', 'Open')")
        ->execute([$start, $end]);
    $periodId = (int) $pdo->lastInsertId();
    printf("Created payroll period: id=%d (Open)\n", $periodId);
}

// ---- 2. build the working-day list for the period ----
$days = [];
$c = new DateTime($start);
$endEx = (new DateTime($end))->modify('+1 day');
$sundays = 0;
while ($c < $endEx) {
    if (isWorkingDay($c)) $days[] = $c->format('Y-m-d');
    else $sundays++;
    $c->modify('+1 day');
}
printf("Seeding %d working day(s); %d Sunday(s) skipped\n\n", count($days), $sundays);

// ---- 3. seed, per active employee ----
$employees = $pdo->query("SELECT employee_id, employee_number, first_name, last_name FROM employees WHERE is_active = 1 ORDER BY employee_id")->fetchAll();
if (!$employees) { echo "No active employees.\n"; exit(1); }

$del = $pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND scan_device_id = ?");
$ins = $pdo->prepare(
    "INSERT INTO attendance (employee_id, qr_code_id, work_date, time_in, time_out,
                             scan_device_id, hours_worked, minutes_late, status)
     VALUES (?, NULL, ?, '08:00:00', '17:00:00', ?, 8.00, 0, 'Present')"
);

$total = 0;
foreach ($employees as $e) {
    $del->execute([$e['employee_id'], $start, $end, $tag]);
    $removed = $del->rowCount();
    foreach ($days as $d) {
        $ins->execute([$e['employee_id'], $d, $tag]);
        $total++;
    }
    printf("%-10s %-20s  seeded %d day(s)%s\n",
        $e['employee_number'],
        $e['first_name'] . ' ' . $e['last_name'],
        count($days),
        $removed ? "  (replaced $removed previous $tag row(s))" : '');
}

printf("\nTotal attendance rows written: %d\n", $total);

// ---- 4. report what payroll will see ----
printf("\n--- payroll view for period %d ---\n", $periodId);
foreach ($employees as $e) {
    $att = computeAttendanceDeductions($pdo, (int) $e['employee_id'], $start, $end,
        (float) ($pdo->query("SELECT basic_salary FROM employees WHERE employee_id = " . (int) $e['employee_id'])->fetchColumn()));
    printf("  %s: expected=%d present=%d leave=%d absent=%d late_min=%d daily_rate=%.2f absence_ded=%.2f late_ded=%.2f\n",
        $e['employee_number'], $att['expected_days'], $att['present_days'], $att['leave_days'],
        $att['absent_days'], $att['late_minutes'], $att['daily_rate'],
        $att['absence_deduction'], $att['late_deduction']);
}
printf("\nDone. Process period %d from Payroll Periods to see the payslip.\n", $periodId);
