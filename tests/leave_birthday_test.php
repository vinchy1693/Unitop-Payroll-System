<?php
// Verifies the birthday-leave fix: a 1-day leave must be requestable even when
// the day is a weekend, and approving it must consume exactly 1 day from its
// OWN leave type. Only touches rows this script creates.
require __DIR__ . '/_harness.php';

$pdo = $GLOBALS['pdo'];
$bt  = dbRow("SELECT * FROM leave_types WHERE name LIKE '%Birthday%' LIMIT 1");
if (!$bt) { echo "No Birthday Leave type.\n"; exit(1); }
$typeId = (int) $bt['leave_type_id'];
printf("Birthday Leave id=%d days_per_year=%s\n", $typeId, $bt['days_per_year']);

$empId = (int) dbRow("SELECT employee_id FROM employees WHERE is_active = 1 ORDER BY employee_id LIMIT 1")['employee_id'];

// Find Saturdays that no existing Pending/Approved request covers, so the test
// is not blocked by real leave data (e.g. an approved sick leave).
function freeSaturdays(PDO $pdo, int $empId, int $want = 2): array {
    $out = [];
    $scan = new DateTime('today');
    for ($i = 0; $i < 400 && count($out) < $want; $i++) {
        if ((int) $scan->format('N') === 6) {
            $d = $scan->format('Y-m-d');
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM leave_requests
                 WHERE employee_id = ? AND status IN ('Pending','Approved')
                   AND NOT (end_date < ? OR start_date > ?)"
            );
            $st->execute([$empId, $d, $d]);
            if ((int) $st->fetchColumn() === 0) $out[] = $d;
        }
        $scan->modify('+1 day');
    }
    return $out;
}
$free = freeSaturdays($pdo, $empId, 2);
if (count($free) < 2) { echo "Could not find two free Saturdays.\n"; exit(1); }
$sat     = $free[0];
$satNext = $free[1];
$sun     = (new DateTime($sat))->modify('+1 day')->format('Y-m-d');
printf("test dates: free Sat=%s, second free Sat=%s, Sun=%s\n\n", $sat, $satNext, $sun);

mkUser('dsh_admin', 'Payroll Officer');
$jar = login('dsh_admin');

$created = [];
onCleanup(function () use ($pdo, $created) {
    foreach ($created as $lid) { $pdo->exec("DELETE FROM leave_requests WHERE leave_id = $lid"); }
});

section('1. A 1-day weekend leave can now be filed');
$r = req('POST', 'leave_requests.php', [
    'employee_id' => $empId, 'leave_type_id' => $typeId,
    'start_date' => $sat, 'end_date' => $sat,
], $jar);
eq("single-day leave on a Saturday ($sat) -> 200", $r['code'], 200);
$l1 = (int) ($r['json']['data']['leave_id'] ?? 0);
if ($l1) $created[] = $l1;

$r = req('POST', 'leave_requests.php', [
    'employee_id' => $empId, 'leave_type_id' => $typeId,
    'start_date' => $satNext, 'end_date' => $satNext,
], $jar);
eq("single-day leave on another Saturday ($satNext) -> 200", $r['code'], 200);
$l2 = (int) ($r['json']['data']['leave_id'] ?? 0);
if ($l2) $created[] = $l2;

section('2. Multi-day ranges still require a working day');
$r = req('POST', 'leave_requests.php', [
    'employee_id' => $empId, 'leave_type_id' => $typeId,
    'start_date' => $sat, 'end_date' => $sun,
], $jar);
eq('Sat..Sun range is still rejected -> 422', $r['code'], 422);
printf("  message: %s\n", $r['json']['message'] ?? '');

section('3. Approval consumes exactly 1 day from the RIGHT leave type');
if ($l1) {
    $before = dbRow("SELECT remaining_days FROM leave_balances WHERE employee_id=$empId AND leave_type_id=$typeId AND year=2026");
    printf("  balance before: %s\n", $before ? $before['remaining_days'] : '(no row -> falls back to days_per_year)');

    $r = req('PATCH', 'leave_requests.php', ['leave_id' => $l1, 'status' => 'Approved'], $jar);
    eq('approve -> 200', $r['code'], 200);
    printf("  message: %s\n", $r['json']['message'] ?? '');
    is_true('message reports 1 working day', str_contains((string) ($r['json']['message'] ?? ''), '1 working day'), $r['json']['message'] ?? '');

    $bal = dbRow("SELECT * FROM leave_balances WHERE employee_id=$empId AND leave_type_id=$typeId AND year=2026");
    is_true('a balance row exists for the BIRTHDAY type (not another type)', $bal !== false);
    if ($bal) {
        eq('remaining_days = 0 after 1 of 1 day used', $bal['remaining_days'], 0.00);
        eq('the row belongs to the Birthday type, not Sick Leave', (int) $bal['leave_type_id'], $typeId);
    }
    onCleanup(fn() => $GLOBALS['pdo']->exec("DELETE FROM leave_balances WHERE employee_id=$empId AND leave_type_id=$typeId AND year=2026"));

    // second approval should now be refused (0 left)
    if ($l2) {
        $r = req('PATCH', 'leave_requests.php', ['leave_id' => $l2, 'status' => 'Approved'], $jar);
        eq('approving a second day with 0 remaining -> 409', $r['code'], 409);
        printf("  message: %s\n", $r['json']['message'] ?? '');
    }
}

runCleanup();
sweepTestData();
summary();
