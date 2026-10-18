<?php
// Verifies the overlap message names the ACTUAL conflicting leave, and that a
// non-overlapping birthday leave still files. Only removes what it creates.
require __DIR__ . '/_harness.php';

$pdo = $GLOBALS['pdo'];
$bt  = dbRow("SELECT * FROM leave_types WHERE name LIKE '%Birthday%' LIMIT 1");
$typeId = (int) $bt['leave_type_id'];
$empId  = (int) dbRow("SELECT employee_id FROM employees WHERE is_active = 1 ORDER BY employee_id LIMIT 1")['employee_id'];

// Use a date inside the existing approved leave for the conflict case.
$busy = dbRow("SELECT lr.start_date, lr.end_date, lr.leave_id, lt.name
               FROM leave_requests lr JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
               WHERE lr.employee_id = $empId AND lr.status = 'Approved' ORDER BY lr.start_date LIMIT 1");
if (!$busy) { echo "No approved leave to test the conflict against.\n"; exit(1); }
$inside = (new DateTime($busy['start_date']))->modify('+1 day')->format('Y-m-d');
printf("existing approved leave: #%s %s (%s..%s)\n", $busy['leave_id'], $busy['name'], $busy['start_date'], $busy['end_date']);
printf("birthday date to attempt: %s (inside it)\n\n", $inside);

mkUser('dsh_admin', 'Payroll Officer');
$jar = login('dsh_admin');

$createdId = 0;
try {
    section('1. Overlapping request names the real conflict');
    $r = req('POST', 'leave_requests.php', [
        'employee_id' => $empId, 'leave_type_id' => $typeId,
        'start_date' => $inside, 'end_date' => $inside,
    ], $jar);
    eq('overlapping request -> 409', $r['code'], 409);
    $msg = (string) ($r['json']['message'] ?? '');
    printf("  message: %s\n", $msg);
    is_true('message names the SICK leave, not a birthday leave', stripos($msg, 'Sick') !== false, $msg);
    is_true('message includes the conflicting dates', str_contains($msg, $busy['start_date']) && str_contains($msg, $busy['end_date']), $msg);
    is_true('message says it does NOT mention Birthday', stripos($msg, 'Birthday') === false, $msg);
    is_true('response carries a conflicts_with object', isset($r['json']['data']['conflicts_with']), 'missing data.conflicts_with');

    section('2. A non-overlapping birthday leave still files');
    // find a Saturday outside every pending/approved request
    $d = new DateTime('today');
    $free = null;
    for ($i = 0; $i < 200 && $free === null; $i++) {
        if ((int) $d->format('N') === 6) {
            $cand = $d->format('Y-m-d');
            $st = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status IN ('Pending','Approved') AND NOT (end_date < ? OR start_date > ?)");
            $st->execute([$empId, $cand, $cand]);
            if ((int) $st->fetchColumn() === 0) $free = $cand;
        }
        $d->modify('+1 day');
    }
    printf("  free Saturday: %s\n", $free);
    $r = req('POST', 'leave_requests.php', [
        'employee_id' => $empId, 'leave_type_id' => $typeId,
        'start_date' => $free, 'end_date' => $free,
    ], $jar);
    eq('non-overlapping single-day request -> 200', $r['code'], 200);
    $createdId = (int) ($r['json']['data']['leave_id'] ?? 0);
} finally {
    if ($createdId) $GLOBALS['pdo']->exec("DELETE FROM leave_requests WHERE leave_id = $createdId");
    $GLOBALS['pdo']->exec("DELETE FROM leave_balances WHERE employee_id=$empId AND leave_type_id=$typeId AND year=2026");
    sweepTestData();
    // prove the real data survived
    $still = dbCount('leave_requests');
    is_true('the pre-existing approved leave is untouched', $still === 1, "leave_requests has $still row(s)");
}

summary();
