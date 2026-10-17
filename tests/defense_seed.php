<?php
// =====================================================================
// tests/defense_seed.php
//
// Idempotent sample payroll data for the FINAL DEFENCE DEMO.
//
//   Employee : 3 — EMP-0001 Vinchy Fantonial (basic_salary 60,000.00)
//   Period   : 2026-11-16 .. 2026-11-30, type "Semi-monthly", status Open
//   Inputs   : 9 present days (2 of them late), 2 approved paid leave days,
//              2 absences, 2 overtime records.
//
// Running this script twice leaves the same rows and the same figures: it
// first removes only the rows it previously seeded (attendance marked
// scan_device_id = 'Seed', overtime on its two known dates and type, and its
// own leave request on its two known dates) before inserting them again. The
// leave request is re-created, so its leave_id may change between runs; every
// other value is stable.
//
// It never touches:
//   * the older Semi-monthly periods (2026-10-16..10-31, 2026-11-01..11-15)
//   * the older approved Sick Leave (2026-10-17..2026-10-25)
//   * the Birthday Leave request on 2026-11-17 (whatever its status)
//   * any payroll run / payslip
//
// The leave request is inserted directly, but with EXACTLY the balance math
// api/leave_requests.php performs on approval: the covered Mon–Fri business
// days are deducted from leave_balances (Sick Leave, year 2026) inside the
// same transaction. No API login is needed to seed.
//
// Run:  C:\xampp\php\php.exe tests\defense_seed.php
// =====================================================================

declare(strict_types=1);

const DB_DSN  = 'mysql:host=localhost;dbname=payroll_db;charset=utf8mb4';
const DB_USER = 'root';
const DB_PASS = '';

const EMPLOYEE_ID     = 3;
const EMPLOYEE_NUMBER = 'EMP-0001';
const PERIOD_START    = '2026-11-16';
const PERIOD_END      = '2026-11-30';
const PERIOD_TYPE     = 'Semi-monthly';
const MONTHLY_SALARY  = 60000.00;
const SEED_DEVICE     = 'Seed';

const LEAVE_TYPE_NAME = 'Sick Leave';
const LEAVE_START     = '2026-11-18';
const LEAVE_END       = '2026-11-19';

// The two working days deliberately left with no attendance record.
const ABSENT_DATES = ['2026-11-24', '2026-11-27'];

// The two late days. hours_worked follows the app's rule: clock span minus a
// 60-minute lunch when more than 5 hours were worked.
//   08:15 -> 17:00 = 8h45m -> 7.75 h
//   08:30 -> 17:00 = 8h30m -> 7.50 h
const LATE_DAYS = [
    '2026-11-20' => ['time_in' => '08:15:00', 'time_out' => '17:00:00', 'minutes_late' => 15, 'hours_worked' => 7.75],
    '2026-11-26' => ['time_in' => '08:30:00', 'time_out' => '17:00:00', 'minutes_late' => 30, 'hours_worked' => 7.50],
];

const OT_TYPE = 'Regular OT';
const OT_RECORDS = [
    ['ot_date' => '2026-11-20', 'hours' => 3.00, 'rate_multiplier' => 1.25, 'ot_type' => OT_TYPE,
     'time_in' => '18:00:00', 'time_out' => '21:00:00'],
    ['ot_date' => '2026-11-25', 'hours' => 2.00, 'rate_multiplier' => 1.30, 'ot_type' => OT_TYPE,
     'time_in' => '18:00:00', 'time_out' => '20:00:00'],
];

// ---------------------------------------------------------------------
// Small independent date helpers. The seed does NOT include the app's
// payroll helpers: its work week (Mon–Sat) is stated here explicitly so the
// sample data is reproducible even if that helper file changes.
// ---------------------------------------------------------------------

function out(string $line = ''): void { echo $line . PHP_EOL; }

function dayList(string $from, string $to): array {
    $days   = [];
    $cursor = new DateTime($from);
    $end    = new DateTime($to);
    while ($cursor <= $end) {
        $days[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
    return $days;
}

function isoDow(string $ymd): int { return (int) (new DateTime($ymd))->format('N'); }

function isWorkDay(string $ymd): bool { return isoDow($ymd) <= 6; }   // Sunday = 7

function workDays(string $from, string $to): array {
    return array_values(array_filter(dayList($from, $to), 'isWorkDay'));
}

// Mirrors countBusinessDays() in api/leave_requests.php (Mon–Fri) — used only
// for the leave-balance deduction, exactly as the approve action does.
function businessDaysMonFri(string $from, string $to): int {
    $n = 0;
    foreach (dayList($from, $to) as $d) {
        if (isoDow($d) <= 5) $n++;
    }
    return $n;
}

function money(float $amount): string { return number_format($amount, 2, '.', ','); }
function pad(string $v, int $width): string { return str_pad($v, $width, ' ', STR_PAD_RIGHT); }
function lpad(string $v, int $width): string { return str_pad($v, $width, ' ', STR_PAD_LEFT); }

// ---------------------------------------------------------------------

out('=====================================================================');
out(' DEFENCE PAYROLL SEED');
out(' employee 3 (EMP-0001 Vinchy Fantonial), 2026-11-16 .. 2026-11-30');
out('=====================================================================');
out();

$pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    // -----------------------------------------------------------------
    // 0. Preconditions + schedule sanity (read-only)
    // -----------------------------------------------------------------
    $stmt = $pdo->prepare(
        "SELECT employee_number, first_name, last_name, basic_salary, is_active
         FROM employees WHERE employee_id = ?"
    );
    $stmt->execute([EMPLOYEE_ID]);
    $emp = $stmt->fetch();
    if (!$emp) {
        throw new RuntimeException('employee_id ' . EMPLOYEE_ID . ' was not found in the database.');
    }
    if ($emp['employee_number'] !== EMPLOYEE_NUMBER) {
        throw new RuntimeException(
            'employee_id ' . EMPLOYEE_ID . ' is ' . $emp['employee_number'] .
            ', expected ' . EMPLOYEE_NUMBER . ' — refusing to seed the wrong employee.'
        );
    }
    if (abs((float) $emp['basic_salary'] - MONTHLY_SALARY) > 0.005) {
        throw new RuntimeException(
            'employee ' . EMPLOYEE_NUMBER . ' has basic_salary ' . $emp['basic_salary'] .
            ', expected ' . money(MONTHLY_SALARY) . ' — refusing to seed.'
        );
    }
    if ((int) $emp['is_active'] !== 1) {
        throw new RuntimeException('employee ' . EMPLOYEE_NUMBER . ' is not active; payroll would skip it.');
    }

    $allWorkDays = workDays(PERIOD_START, PERIOD_END);
    $W           = count($allWorkDays);
    if ($W !== 13) {
        throw new RuntimeException("expected 13 working days in the period, computed $W.");
    }

    $leaveDates = workDays(LEAVE_START, LEAVE_END);
    if (count($leaveDates) !== 2) {
        throw new RuntimeException(
            'the approved leave ' . LEAVE_START . '..' . LEAVE_END .
            ' covers ' . count($leaveDates) . ' working day(s), expected exactly 2.'
        );
    }

    $absentSet = array_fill_keys(ABSENT_DATES, true);
    $leaveSet  = array_fill_keys($leaveDates, true);

    if (count($absentSet) !== 2) {
        throw new RuntimeException('ABSENT_DATES must contain 2 distinct dates.');
    }
    foreach (ABSENT_DATES as $d) {
        if (!in_array($d, $allWorkDays, true)) {
            throw new RuntimeException("absence date $d is not a working day inside the period.");
        }
        if (isset($leaveSet[$d])) {
            throw new RuntimeException("absence date $d is also an approved leave day.");
        }
    }

    $presentDays = [];
    foreach ($allWorkDays as $d) {
        if (!isset($absentSet[$d]) && !isset($leaveSet[$d])) {
            $presentDays[] = $d;
        }
    }
    if (count($presentDays) !== 9) {
        throw new RuntimeException('expected 9 present days, computed ' . count($presentDays) . '.');
    }

    foreach (array_keys(LATE_DAYS) as $d) {
        if (!in_array($d, $presentDays, true)) {
            throw new RuntimeException("late day $d is not one of the seeded present days.");
        }
    }
    foreach (OT_RECORDS as $ot) {
        if (!in_array($ot['ot_date'], $presentDays, true)) {
            throw new RuntimeException("overtime date {$ot['ot_date']} is not one of the seeded present days.");
        }
    }
    foreach (LATE_DAYS as $d => $late) {
        $spanMinutes = (int) ((new DateTime($late['time_out']))->getTimestamp()
                            - (new DateTime($late['time_in']))->getTimestamp()) / 60;
        $netMinutes  = $spanMinutes > 300 ? $spanMinutes - 60 : $spanMinutes;
        $expected    = round($netMinutes / 60, 2);
        if (abs($expected - (float) $late['hours_worked']) > 0.005) {
            throw new RuntimeException(
                "late day $d: {$late['time_in']}..{$late['time_out']} implies " .
                "hours_worked $expected, not {$late['hours_worked']}."
            );
        }
    }

    // -----------------------------------------------------------------
    // 1. Period (create it Open if missing, after an overlap check)
    // -----------------------------------------------------------------
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT period_id, status FROM payroll_periods
         WHERE start_date = ? AND end_date = ? AND period_type = ?"
    );
    $stmt->execute([PERIOD_START, PERIOD_END, PERIOD_TYPE]);
    $period = $stmt->fetch();

    if ($period) {
        $periodId = (int) $period['period_id'];
        if ($period['status'] !== 'Open') {
            throw new RuntimeException(
                "period #$periodId is '{$period['status']}', not 'Open'. " .
                "Run tests/defense_verify.php to restore the demo state (it leaves the period Open)."
            );
        }
    } else {
        $stmt = $pdo->prepare(
            "SELECT period_id, start_date, end_date, status FROM payroll_periods
             WHERE period_type = ? AND NOT (end_date < ? OR start_date > ?)"
        );
        $stmt->execute([PERIOD_TYPE, PERIOD_START, PERIOD_END]);
        $overlaps = $stmt->fetchAll();
        if ($overlaps) {
            $parts = array_map(
                fn($p) => "#{$p['period_id']} {$p['start_date']}..{$p['end_date']} ({$p['status']})",
                $overlaps
            );
            throw new RuntimeException(
                PERIOD_START . '..' . PERIOD_END . ' overlaps existing ' . PERIOD_TYPE .
                ' period(s): ' . implode(', ', $parts) . '.'
            );
        }

        $pdo->prepare(
            "INSERT INTO payroll_periods (start_date, end_date, period_type, status)
             VALUES (?, ?, ?, 'Open')"
        )->execute([PERIOD_START, PERIOD_END, PERIOD_TYPE]);
        $periodId = (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll_runs WHERE period_id = ?");
    $stmt->execute([$periodId]);
    $runCount = (int) $stmt->fetchColumn();
    if ($runCount > 0) {
        throw new RuntimeException(
            "period #$periodId already has $runCount payroll run(s). " .
            "Re-seeding now would make that run's payslip inconsistent. " .
            "Run tests/defense_verify.php to reset the demo period to Open, or delete the run in the UI first."
        );
    }

    // -----------------------------------------------------------------
    // 2. Remove ONLY our previously seeded rows
    // -----------------------------------------------------------------
    $stmt = $pdo->prepare(
        "DELETE FROM attendance
         WHERE employee_id = ? AND scan_device_id = ? AND work_date BETWEEN ? AND ?"
    );
    $stmt->execute([EMPLOYEE_ID, SEED_DEVICE, PERIOD_START, PERIOD_END]);
    $removedAttendance = $stmt->rowCount();

    $clauses = [];
    $params  = [EMPLOYEE_ID];
    foreach (OT_RECORDS as $ot) {
        $clauses[] = '(ot_date = ? AND ot_type = ?)';
        $params[]  = $ot['ot_date'];
        $params[]  = $ot['ot_type'];
    }
    $params[] = PERIOD_START;
    $params[] = PERIOD_END;
    $stmt = $pdo->prepare(
        "DELETE FROM overtime_records
         WHERE employee_id = ? AND (" . implode(' OR ', $clauses) . ")
           AND ot_date BETWEEN ? AND ?"
    );
    $stmt->execute($params);
    $removedOvertime = $stmt->rowCount();

    // Leave type drives the balance math; only a PAID type excuses absences.
    $stmt = $pdo->prepare("SELECT leave_type_id, days_per_year, is_paid FROM leave_types WHERE name = ? LIMIT 1");
    $stmt->execute([LEAVE_TYPE_NAME]);
    $leaveType = $stmt->fetch();
    if (!$leaveType) {
        throw new RuntimeException("leave type '" . LEAVE_TYPE_NAME . "' was not found.");
    }
    if ((int) $leaveType['is_paid'] !== 1) {
        throw new RuntimeException("leave type '" . LEAVE_TYPE_NAME . "' is not paid; it could not excuse absences.");
    }
    $leaveTypeId = (int) $leaveType['leave_type_id'];
    $leaveYear   = (int) (new DateTime(LEAVE_START))->format('Y');
    $leaveDays   = (LEAVE_START === LEAVE_END) ? 1 : businessDaysMonFri(LEAVE_START, LEAVE_END);

    // Our own leave request is identified by the exact employee/type/date range.
    // The older approved Sick Leave (2026-10-17..2026-10-25) has other dates.
    $stmt = $pdo->prepare(
        "SELECT leave_id, status, start_date, end_date FROM leave_requests
         WHERE employee_id = ? AND leave_type_id = ? AND start_date = ? AND end_date = ?"
    );
    $stmt->execute([EMPLOYEE_ID, $leaveTypeId, LEAVE_START, LEAVE_END]);
    $oldLeaves = $stmt->fetchAll();
    $removedLeave = 0;
    foreach ($oldLeaves as $old) {
        if ($old['status'] === 'Approved') {
            // Reverse the approval using the STORED request's own dates, not the
            // new constants, so a code change between runs cannot drift the
            // balance. This is exactly what the API deducted when it approved.
            $storedDays = ($old['start_date'] === $old['end_date'])
                ? 1
                : businessDaysMonFri($old['start_date'], $old['end_date']);
            $pdo->prepare(
                "UPDATE leave_balances SET remaining_days = remaining_days + ?
                 WHERE employee_id = ? AND leave_type_id = ? AND year = ?"
            )->execute([$storedDays, EMPLOYEE_ID, $leaveTypeId, $leaveYear]);
        }
        $pdo->prepare("DELETE FROM leave_requests WHERE leave_id = ?")->execute([$old['leave_id']]);
        $removedLeave++;
    }

    // -----------------------------------------------------------------
    // 3. Approved paid leave, with the same balance math the API applies
    // -----------------------------------------------------------------
    $stmt = $pdo->prepare(
        "SELECT lr.leave_id, lr.start_date, lr.end_date, lr.status,
                COALESCE(lt.name, 'Leave') AS type_name
         FROM leave_requests lr
         LEFT JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
         WHERE lr.employee_id = ? AND lr.status IN ('Pending','Approved')
           AND NOT (lr.end_date < ? OR lr.start_date > ?)
         ORDER BY lr.start_date
         LIMIT 1"
    );
    $stmt->execute([EMPLOYEE_ID, LEAVE_START, LEAVE_END]);
    $conflict = $stmt->fetch();
    if ($conflict) {
        throw new RuntimeException(
            "refusing to seed: employee " . EMPLOYEE_ID . " already has an overlapping leave: " .
            "{$conflict['type_name']} ({$conflict['status']}) " .
            "{$conflict['start_date']}..{$conflict['end_date']} — request #{$conflict['leave_id']}."
        );
    }

    $stmt = $pdo->prepare(
        "SELECT balance_id, remaining_days FROM leave_balances
         WHERE employee_id = ? AND leave_type_id = ? AND year = ?"
    );
    $stmt->execute([EMPLOYEE_ID, $leaveTypeId, $leaveYear]);
    $balance = $stmt->fetch();

    if (!$balance) {
        $pdo->prepare(
            "INSERT INTO leave_balances (employee_id, leave_type_id, year, remaining_days)
             VALUES (?, ?, ?, ?)"
        )->execute([EMPLOYEE_ID, $leaveTypeId, $leaveYear, (float) $leaveType['days_per_year']]);
        $remainingBefore = (float) $leaveType['days_per_year'];
    } else {
        $remainingBefore = (float) $balance['remaining_days'];
    }

    if ($leaveDays > $remainingBefore) {
        throw new RuntimeException(
            "insufficient " . LEAVE_TYPE_NAME . " balance: need $leaveDays, have $remainingBefore."
        );
    }

    $pdo->prepare(
        "UPDATE leave_balances SET remaining_days = remaining_days - ?
         WHERE employee_id = ? AND leave_type_id = ? AND year = ?"
    )->execute([$leaveDays, EMPLOYEE_ID, $leaveTypeId, $leaveYear]);

    $pdo->prepare(
        "INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, status)
         VALUES (?, ?, ?, ?, 'Approved')"
    )->execute([EMPLOYEE_ID, $leaveTypeId, LEAVE_START, LEAVE_END]);
    $leaveId = (int) $pdo->lastInsertId();

    // Invariant: approving must have deducted exactly $leaveDays.
    $stmt = $pdo->prepare(
        "SELECT remaining_days FROM leave_balances
         WHERE employee_id = ? AND leave_type_id = ? AND year = ?"
    );
    $stmt->execute([EMPLOYEE_ID, $leaveTypeId, $leaveYear]);
    $remainingAfter = (float) $stmt->fetchColumn();
    if (abs(($remainingBefore - $leaveDays) - $remainingAfter) > 0.001) {
        throw new RuntimeException(
            "leave balance math is incoherent: before $remainingBefore, deducted $leaveDays, " .
            "stored $remainingAfter (expected " . ($remainingBefore - $leaveDays) . ")."
        );
    }

    // -----------------------------------------------------------------
    // 4. Attendance: one row per present working day
    // -----------------------------------------------------------------
    $insAttendance = $pdo->prepare(
        "INSERT INTO attendance
         (employee_id, qr_code_id, work_date, time_in, time_out,
          scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($presentDays as $d) {
        if (isset(LATE_DAYS[$d])) {
            $late = LATE_DAYS[$d];
            $insAttendance->execute([
                EMPLOYEE_ID, $d, $late['time_in'], $late['time_out'],
                SEED_DEVICE, $late['hours_worked'], $late['minutes_late'], 'Late',
            ]);
        } else {
            $insAttendance->execute([
                EMPLOYEE_ID, $d, '08:00:00', '17:00:00',
                SEED_DEVICE, 8.00, 0, 'Present',
            ]);
        }
    }

    // -----------------------------------------------------------------
    // 5. Overtime: 2 records on two different present days
    // -----------------------------------------------------------------
    $insOt = $pdo->prepare(
        "INSERT INTO overtime_records
         (employee_id, ot_date, hours, ot_type, rate_multiplier, status,
          time_in, time_out, requested_at, approved_at)
         VALUES (?, ?, ?, ?, ?, 'Completed', ?, ?, ?, ?)"
    );
    foreach (OT_RECORDS as $ot) {
        $insOt->execute([
            EMPLOYEE_ID, $ot['ot_date'], $ot['hours'], $ot['ot_type'], $ot['rate_multiplier'],
            $ot['time_in'], $ot['time_out'],
            $ot['ot_date'] . ' 17:00:00', $ot['ot_date'] . ' 17:30:00',
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, PHP_EOL . 'SEED FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------
// 6. Read back what is actually stored, and print it
// ---------------------------------------------------------------------
out('Previously seeded rows removed : attendance ' . $removedAttendance .
    ', overtime ' . $removedOvertime . ', leave ' . $removedLeave);
out('Inserted                        : attendance ' . count($presentDays) .
    ', overtime ' . count(OT_RECORDS) . ', leave 1 (leave_id ' . $leaveId . ')');
out();

$stmt = $pdo->prepare(
    "SELECT work_date, time_in, time_out, hours_worked, minutes_late, status
     FROM attendance
     WHERE employee_id = ? AND work_date BETWEEN ? AND ?
     ORDER BY work_date"
);
$stmt->execute([EMPLOYEE_ID, PERIOD_START, PERIOD_END]);
$attendance = [];
foreach ($stmt->fetchAll() as $row) {
    $attendance[$row['work_date']] = $row;
}

$stmt = $pdo->prepare(
    "SELECT ot_date, hours, rate_multiplier, ot_type
     FROM overtime_records
     WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
     ORDER BY ot_date"
);
$stmt->execute([EMPLOYEE_ID, PERIOD_START, PERIOD_END]);
$overtime = $stmt->fetchAll();

out('DAY-BY-DAY (every working day in the period; Sunday is the only non-working day)');
out('+------------+-----------+------------------+----------+----------+-------+------+');
out('| Date       | Weekday   | Status           | Time in  | Time out | Hours | Late |');
out('+------------+-----------+------------------+----------+----------+-------+------+');

$lateMinutesTotal = 0;
foreach ($allWorkDays as $d) {
    $weekday = (new DateTime($d))->format('l');
    if (isset($attendance[$d])) {
        $r = $attendance[$d];
        $minutesLate = (int) $r['minutes_late'];
        $lateMinutesTotal += $minutesLate;
        $status = $minutesLate > 0 ? 'Late (' . $minutesLate . ' min)' : 'Present';
        out('| ' . pad($d, 10) . ' | ' . pad($weekday, 9) . ' | ' . pad($status, 16) . ' | ' .
            pad($r['time_in'], 8) . ' | ' . pad($r['time_out'], 8) . ' | ' .
            lpad(number_format((float) $r['hours_worked'], 2), 5) . ' | ' .
            lpad((string) $minutesLate, 4) . ' |');
    } elseif (isset($leaveSet[$d])) {
        out('| ' . pad($d, 10) . ' | ' . pad($weekday, 9) . ' | ' . pad('Approved leave', 16) . ' | ' .
            pad('-', 8) . ' | ' . pad('-', 8) . ' | ' . lpad('-', 5) . ' | ' . lpad('-', 4) . ' |');
    } else {
        out('| ' . pad($d, 10) . ' | ' . pad($weekday, 9) . ' | ' . pad('ABSENT', 16) . ' | ' .
            pad('-', 8) . ' | ' . pad('-', 8) . ' | ' . lpad('-', 5) . ' | ' . lpad('-', 4) . ' |');
    }
}
out('+------------+-----------+------------------+----------+----------+-------+------+');
out('(2026-11-22 and 2026-11-29 are Sundays — non-working, no rows.)');
out();

out('OVERTIME RECORDS');
out('+------------+--------+------------------+-------------+');
out('| OT date    | Hours  | Type             | Multiplier  |');
out('+------------+--------+------------------+-------------+');
foreach ($overtime as $ot) {
    out('| ' . pad($ot['ot_date'], 10) . ' | ' .
        lpad(number_format((float) $ot['hours'], 2), 6) . ' | ' .
        pad($ot['ot_type'], 16) . ' | ' .
        lpad(number_format((float) $ot['rate_multiplier'], 2), 11) . ' |');
}
out('+------------+--------+------------------+-------------+');
out();

$otHours = 0.0;
foreach ($overtime as $ot) {
    $otHours += (float) $ot['hours'];
}

$stmt = $pdo->prepare("SELECT status FROM payroll_periods WHERE period_id = ?");
$stmt->execute([$periodId]);
$periodStatus = $stmt->fetchColumn();

out('SEEDED INPUTS');
out('  Period                  : #' . $periodId . '  ' . PERIOD_START . ' .. ' . PERIOD_END .
    '  (' . PERIOD_TYPE . ', ' . $periodStatus . ')');
out('  Employee                : ' . EMPLOYEE_NUMBER . '  ' .
    $emp['first_name'] . ' ' . $emp['last_name'] . '  basic_salary ' . money((float) $emp['basic_salary']));
out('  Working days (W)        : ' . $W);
out('  Present (attendance)    : ' . count($presentDays) . '  (2 late, ' . $lateMinutesTotal . ' late minutes)');
out('  Approved paid leave     : 2  (' . LEAVE_START . ' .. ' . LEAVE_END . ', ' .
    LEAVE_TYPE_NAME . ', leave_id ' . $leaveId . ')');
out('  Absent                  : 2  (' . implode(', ', ABSENT_DATES) . ')');
out('  Overtime                : ' . count($overtime) . ' record(s), ' .
    number_format($otHours, 2) . ' hours total');
out('  Sick Leave balance      : ' . number_format($remainingBefore, 2) . ' -> ' .
    number_format($remainingAfter, 2) . '  (2 days deducted for ' . LEAVE_START . '..' . LEAVE_END . ')');
out();
out('Seed complete. Period is Open and has no payslip.');
out('Next: C:\\xampp\\php\\php.exe tests\\defense_verify.php');
