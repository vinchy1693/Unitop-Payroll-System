<?php
// =====================================================================
// api/_payroll_helpers.php
// Philippine statutory contribution + tax helpers.
// =====================================================================

// ---------------------------------------------------------------------
// WORK WEEK: Monday .. Saturday. Sunday is the only non-working day.
//   PHP   date('N')   : 1 = Monday .. 6 = Saturday, 7 = Sunday
//   MySQL DAYOFWEEK() : 1 = Sunday, 2 = Monday .. 7 = Saturday
// Change the bounds below to move the work week; every attendance, absence,
// daily-rate and overtime calculation derives from them. This is the single
// definition -- api/attendance.php and api/ledger.php include this file and
// use the helpers rather than repeating the bounds.
// ---------------------------------------------------------------------
const WORK_WEEK_LAST_ISO_DOW    = 6;   // Saturday
const WORK_WEEK_FIRST_MYSQL_DOW = 2;   // Monday
const WORK_WEEK_LAST_MYSQL_DOW  = 7;   // Saturday

function isWorkingDay(DateTimeInterface $day): bool {
    return (int) $day->format('N') <= WORK_WEEK_LAST_ISO_DOW;
}

function countWorkingDays(string $from, string $to): int {
    if ($from > $to) return 0;
    $cursor = new DateTime($from);
    $end    = (new DateTime($to))->modify('+1 day');
    $days   = 0;
    while ($cursor < $end) {
        if (isWorkingDay($cursor)) $days++;
        $cursor->modify('+1 day');
    }
    return $days;
}

// SQL fragment restricting a query to rows landing on a working day, so that a
// Sunday scan can never cancel a genuine absence.
function workDaySql(string $column): string {
    return sprintf(
        'DAYOFWEEK(%s) BETWEEN %d AND %d',
        $column,
        WORK_WEEK_FIRST_MYSQL_DOW,
        WORK_WEEK_LAST_MYSQL_DOW
    );
}

// =====================================================================
// Day types and statutory premiums
//
// The work week is Monday-Saturday, so Sunday is the rest day. A date is a
// holiday when the `holidays` table says so; holiday takes precedence over the
// rest day (a holiday that falls on a Sunday is paid at the holiday rate).
//
// Rates (Labor Code):
//   ordinary day       first 8h 1.00   OT 1.25
//   rest day           first 8h 1.30   OT 1.69  (1.30 x 1.30)
//   special holiday    first 8h 1.30   OT 1.69
//   regular holiday    first 8h 2.00   OT 2.60  (2.00 x 1.30)
//
// For a holiday the semimonthly basic already pays the 1.00 non-working-day
// part, so computePremiumPay() adds only the premium above it. For a rest day
// the basic does not cover the day at all, so the whole 1.30 is added.
// =====================================================================
const REST_DAY_ISO_DOW = 7;

function holidayTypeForDate(PDO $pdo, string $date): ?string {
    $stmt = $pdo->prepare(
        "SELECT holiday_type FROM holidays WHERE holiday_date = ?
         ORDER BY holiday_type = 'Regular' DESC LIMIT 1"
    );
    $stmt->execute([$date]);
    $type = $stmt->fetchColumn();
    return $type === false ? null : (string) $type;
}

function dayTypeForDate(PDO $pdo, string $date): string {
    $holiday = holidayTypeForDate($pdo, $date);
    if ($holiday === "Regular") return "Regular Holiday";
    if ($holiday === "Special") return "Special Holiday";
    if ((int) (new DateTime($date))->format("N") === REST_DAY_ISO_DOW) return "Rest Day";
    return "Ordinary Day";
}

/** Multiplier for the first eight hours of work on the date. */
function firstEightMultiplierForDayType(string $dayType): float {
    return match ($dayType) {
        "Rest Day"        => 1.30,
        "Special Holiday" => 1.30,
        "Regular Holiday" => 2.00,
        default           => 1.00,
    };
}

/** Statutory overtime multiplier for work beyond eight hours on the date. */
function overtimeMultiplierForDayType(string $dayType): float {
    return match ($dayType) {
        "Rest Day"        => 1.69,
        "Special Holiday" => 1.69,
        "Regular Holiday" => 2.60,
        default           => 1.25,
    };
}

/**
 * Hours between two clock times, less one 60-minute meal break when the span
 * exceeds 300 minutes. Used for overtime blocks and for attendance rows whose
 * hours_worked was not stored. Mirrors the kiosk's regular-shift rule.
 */
function computeOvertimeHours(string $timeIn, string $timeOut): float {
    $in  = new DateTime($timeIn);
    $out = new DateTime($timeOut);
    $minutes = ($out->getTimestamp() - $in->getTimestamp()) / 60.0;
    if ($minutes < 0) $minutes += 1440;   // crossed midnight
    if ($minutes > 300) $minutes -= 60;   // meal break
    return round(max(0.0, $minutes) / 60.0, 2);
}

// Kiosk OT window tolerance. Clock-in may be scanned up to five minutes early
// (a clock-skew allowance); clock-out may be scanned up to five minutes before
// the approved end. A later clock-out is recorded, but paid only to the
// approved end — the extra time needs an approved extension.
const OT_CLOCK_IN_GRACE_MINUTES = 5;
const OT_CLOCK_OUT_EARLY_GRACE_MINUTES = 5;

/**
 * Compare the kiosk scan time with the approved requested window.
 *
 * @param array  $ot      overtime_records row (requested_start/requested_end)
 * @param string $nowTime current scan time, HH:MM[:SS]
 * @param string $phase   "in" or "out"
 * @return array|null     null when allowed; otherwise code/message, plus
 *                        cap_end when a late clock-out must be paid only to
 *                        the approved end
 */
function overtimeWindowViolation(array $ot, string $nowTime, string $phase): ?array {
    $start = $ot["requested_start"] ?? null;
    $end   = $ot["requested_end"] ?? null;

    // Legacy rows and the kiosk's after-shift fallback have no requested end;
    // they fall back to the old behaviour rather than blocking the employee.
    if ($start === null || $start === "" || $end === null || $end === "") {
        return null;
    }

    $startTs = strtotime((string) $start);
    $endTs   = strtotime((string) $end);
    $nowTs   = strtotime($nowTime);
    if ($startTs === false || $endTs === false || $nowTs === false) {
        return null;
    }

    // An end before the start is an overnight window. The kiosk looks the
    // overtime row up by ot_date, so after-midnight scans are a separate gap;
    // do not apply a same-day window check to those rows.
    if ($endTs < $startTs) {
        return null;
    }

    $hhmm = static fn (int $ts): string => date("H:i", $ts);

    if ($phase === "in") {
        $earliest = $startTs - OT_CLOCK_IN_GRACE_MINUTES * 60;
        if ($nowTs < $earliest) {
            return [
                "code"    => "ot_not_started",
                "message" => "Overtime is approved for " . $hhmm($startTs) . "–" . $hhmm($endTs)
                    . ". You can clock in from " . $hhmm($earliest) . ".",
            ];
        }
        if ($nowTs > $endTs) {
            return [
                "code"    => "ot_window_passed",
                "message" => "The approved overtime window ended at " . $hhmm($endTs)
                    . ". File a new request.",
            ];
        }
        return null;
    }

    $earliestOut = $endTs - OT_CLOCK_OUT_EARLY_GRACE_MINUTES * 60;
    if ($nowTs < $earliestOut) {
        return [
            "code"    => "ot_too_early_out",
            "message" => "The approved overtime runs until " . $hhmm($endTs)
                . ". You can clock out from " . $hhmm($earliestOut) . ".",
        ];
    }
    if ($nowTs > $endTs) {
        return [
            "code"    => "ot_pay_capped",
            "cap_end" => date("H:i:s", $endTs),
            "message" => "Overtime clock-out after the approved end; hours are paid only to "
                . $hhmm($endTs) . ".",
        ];
    }

    return null;
}

/**
 * Premium pay for work actually recorded on rest days and holidays.
 *
 * Returns the total and a per-day breakdown. The first eight hours use the
 * day-type rate; the excess uses the day-type OT rate. Ordinary-day work is not
 * included here - it is covered by the semimonthly basic (and its own OT line).
 */
function computePremiumPay(
    PDO $pdo,
    int $employeeId,
    string $startDate,
    string $endDate,
    float $dailyRate
): array {
    $stmt = $pdo->prepare(
        "SELECT work_date, time_in, time_out, hours_worked
         FROM attendance
         WHERE employee_id = ? AND work_date BETWEEN ? AND ?
         ORDER BY work_date"
    );
    $stmt->execute([$employeeId, $startDate, $endDate]);

    $hourly  = $dailyRate / 8.0;
    $total   = 0.0;
    $days    = [];

    foreach ($stmt->fetchAll() as $row) {
        $dayType = dayTypeForDate($pdo, $row["work_date"]);
        if ($dayType === "Ordinary Day") continue;

        $hours = $row["hours_worked"] !== null
            ? (float) $row["hours_worked"]
            : (($row["time_in"] !== null && $row["time_out"] !== null)
                ? computeOvertimeHours($row["time_in"], $row["time_out"]) : 0.0);
        if ($hours <= 0) continue;

        $first  = min($hours, 8.0);
        $beyond = max(0.0, $hours - 8.0);

        if ($dayType === "Rest Day") {
            // The basic does not cover Sunday, so the full premium is added.
            $pay = $first * $hourly * firstEightMultiplierForDayType($dayType)
                 + $beyond * $hourly * overtimeMultiplierForDayType($dayType);
        } elseif ($dayType === "Regular Holiday") {
            // Basic already paid 1.00 for the holiday; add 1.00 / 1.60.
            $pay = $first * $hourly * 1.00 + $beyond * $hourly * 1.60;
        } else { // Special Holiday
            // Basic already paid 1.00; add 0.30 / 0.69.
            $pay = $first * $hourly * 0.30 + $beyond * $hourly * 0.69;
        }

        $total += $pay;
        $days[] = [
            "date"    => $row["work_date"],
            "type"    => $dayType,
            "hours"   => round($hours, 2),
            "premium" => round($pay, 2),
        ];
    }

    return ["total" => round($total, 2), "days" => $days];
}

// ---- SSS (2025): 15% total, 5% EE / 10% ER, MSC ₱5,000 – ₱35,000 ----
function computeSSS(float $monthlySalary): array {
    $msc = max(5000.0, min(35000.0, $monthlySalary));
    return ["ee" => round($msc * 0.05, 2), "er" => round($msc * 0.10, 2)];
}

// ---- PhilHealth (2025): 2.5% EE / 2.5% ER, floor ₱10,000, cap ₱100,000 ----
function computePhilHealth(float $monthlySalary): array {
    $base = max(10000.0, min(100000.0, $monthlySalary));
    return ["ee" => round($base * 0.025, 2), "er" => round($base * 0.025, 2)];
}

// ---- Pag-IBIG: 2% EE / 2% ER, Max Fund Salary ₱10,000 ----
function computePagIbig(float $monthlySalary): array {
    $base = min(10000.0, $monthlySalary);
    return ["ee" => round($base * 0.02, 2), "er" => round($base * 0.02, 2)];
}

// ---- BIR Withholding Tax (TRAIN Law) ----
function computeWithholdingTax(float $semiMonthlyTaxable): float {
    $annual = $semiMonthlyTaxable * 24.0;

    if ($annual <= 250000)          $annualTax = 0.0;
    elseif ($annual <= 400000)      $annualTax = ($annual - 250000) * 0.15;
    elseif ($annual <= 800000)      $annualTax = 22500 + ($annual - 400000) * 0.20;
    elseif ($annual <= 2000000)     $annualTax = 102500 + ($annual - 800000) * 0.25;
    elseif ($annual <= 8000000)     $annualTax = 402500 + ($annual - 2000000) * 0.30;
    else                            $annualTax = 2202500 + ($annual - 8000000) * 0.35;

    return round($annualTax / 24.0, 2);
}

// ---- One-shot calculation for a semi-monthly payslip ----
// $preTaxDeductions: attendance-based amounts (absences / undertime). These
// reduce taxable compensation, so they MUST be removed before the withholding
// tax is computed. Statutory contributions are based on the monthly salary and
// are unaffected by attendance.
function computePayslipDeductions(
    float $monthlySalary,
    float $semiBasic,
    float $preTaxDeductions = 0.0,
    float $additionalTaxable = 0.0
): array {
    // Contribution bases are statutory, not gross pay. RA 1161 sec. 8 defines
    // SSS compensation as excluding bonuses, allowances and overtime pay; RA
    // 11223 uses the monthly BASIC salary for PhilHealth; RA 9679 sec. 4 defines
    // Pag-IBIG compensation as basic monthly salary plus mandated COLA. All
    // three therefore stay on basic salary - deliberately not on gross pay.
    $sss = computeSSS($monthlySalary);
    $ph  = computePhilHealth($monthlySalary);
    $pi  = computePagIbig($monthlySalary);

    $sssEE = round($sss["ee"] / 2, 2);
    $sssER = round($sss["er"] / 2, 2);
    $phEE  = round($ph["ee"]  / 2, 2);
    $phER  = round($ph["er"]  / 2, 2);
    $piEE  = round($pi["ee"]  / 2, 2);
    $piER  = round($pi["er"]  / 2, 2);

    // Taxable compensation is the WHOLE taxable pay for the period, not just the
    // basic: BIR taxes overtime and allowances too, net of attendance deductions
    // and the mandatory contributions. Omitting overtime under-withheld tax on
    // every payslip that had any.
    $semiTaxable = round($semiBasic + $additionalTaxable - $preTaxDeductions - $sssEE - $phEE - $piEE, 2);
    if ($semiTaxable < 0) $semiTaxable = 0;

    $tax = computeWithholdingTax($semiTaxable);

    $totalDeductions = round($sssEE + $phEE + $piEE + $tax, 2);
    $netPay          = round($semiBasic - $totalDeductions, 2);

    return [
        "sss_ee"           => $sssEE,
        "sss_er"           => $sssER,
        "philhealth_ee"    => $phEE,
        "philhealth_er"    => $phER,
        "pagibig_ee"       => $piEE,
        "pagibig_er"       => $piER,
        "withholding_tax"  => $tax,
        "total_deductions" => $totalDeductions,
        "net_pay"          => $netPay,
    ];
}

// ---- Recompute payslip totals ----
function recomputePayslipTotals(PDO $pdo, int $payslipId): array {
    $stmt = $pdo->prepare("SELECT basic_pay, overtime_pay, premium_pay FROM payslips WHERE payslip_id = ?");
    $stmt->execute([$payslipId]);
    $ps = $stmt->fetch();
    if (!$ps) throw new RuntimeException("Payslip not found: $payslipId");

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM allowances WHERE payslip_id = ?");
    $stmt->execute([$payslipId]);
    $allowTotal = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM deductions WHERE payslip_id = ?");
    $stmt->execute([$payslipId]);
    $extraDed = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT sss_ee, philhealth_ee, pagibig_ee, withholding_tax
         FROM govt_contributions WHERE payslip_id = ?"
    );
    $stmt->execute([$payslipId]);
    $gc = $stmt->fetch() ?: ["sss_ee"=>0,"philhealth_ee"=>0,"pagibig_ee"=>0,"withholding_tax"=>0];

    $basic = (float) $ps["basic_pay"];
    $ot    = (float) $ps["overtime_pay"];
    $premium = (float) $ps["premium_pay"];

    // Recompute withholding tax on the CURRENT lines. Allowances are added after
    // the payslip is created, so without this a taxable allowance raised gross pay
    // but never the tax base - under-withholding on every allowance-bearing
    // payslip. The base matches the creation path: basic + overtime + TAXABLE
    // allowances - attendance deductions - statutory contributions.
    $monthly = (float) $pdo->query(
        "SELECT e.basic_salary FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         WHERE ps.payslip_id = " . (int) $payslipId
    )->fetchColumn();

    $taxStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM allowances WHERE payslip_id = ? AND is_taxable = 1");
    $taxStmt->execute([$payslipId]);
    $taxableAllow = (float) $taxStmt->fetchColumn();

    $attStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM deductions
         WHERE payslip_id = ? AND (deduction_type LIKE 'Absences%'
                                    OR deduction_type LIKE 'Late/Undertime%'
                                    OR deduction_type LIKE 'Undertime%')"
    );
    $attStmt->execute([$payslipId]);
    $attendanceDed = (float) $attStmt->fetchColumn();

    $statutoryOnly = round(
        (float) $gc["sss_ee"] + (float) $gc["philhealth_ee"] + (float) $gc["pagibig_ee"], 2
    );
    $taxBase = round($basic + $ot + $premium + $taxableAllow - $attendanceDed - $statutoryOnly, 2);
    if ($taxBase < 0) $taxBase = 0;

    // 13th Month Pay is a special payslip: its tax is the excess over the
    // ₱90,000 exemption, computed by compute13thMonthPay() and stored when the
    // run creates the line. Recomputing it through the ordinary base would
    // withhold on the exempt portion, so keep the amount that was stored.
    $is13thMonth = (int) $pdo->query(
        "SELECT COUNT(*) FROM allowances
         WHERE payslip_id = " . (int) $payslipId . " AND allowance_type = '13th Month Pay'"
    )->fetchColumn() > 0;

    if (!$is13thMonth) {
        $recomputedTax = computeWithholdingTax($taxBase);
        if (abs($recomputedTax - (float) $gc["withholding_tax"]) >= 0.01) {
            $pdo->prepare("UPDATE govt_contributions SET withholding_tax = ? WHERE payslip_id = ?")
                ->execute([$recomputedTax, $payslipId]);
            $gc["withholding_tax"] = $recomputedTax;
        }
    }

    $gross    = round($basic + $ot + $premium + $allowTotal, 2);
    $govtDed  = round((float)$gc["sss_ee"] + (float)$gc["philhealth_ee"] + (float)$gc["pagibig_ee"] + (float)$gc["withholding_tax"], 2);
    $totalDed = round($govtDed + $extraDed, 2);
    $net      = round($gross - $totalDed, 2);

    // Take-home pay can never be negative. Deductions may legitimately exceed
    // gross pay (a full-period absence on top of full statutory contributions,
    // or a loan amortisation larger than the remaining pay). Cap the recorded
    // deductions at gross so the payslip stays internally consistent
    // (gross_pay - total_deductions = net_pay) and report a zero net pay.
    // The unrecovered excess is NOT carried forward to the next period.
    if ($net < 0) {
        $totalDed = $gross;
        $net      = 0.0;
    }

    $pdo->prepare(
        "UPDATE payslips
         SET gross_pay = ?, total_deductions = ?, net_pay = ?
         WHERE payslip_id = ?"
    )->execute([$gross, $totalDed, $net, $payslipId]);

    return [
        "gross_pay"        => $gross,
        "total_deductions" => $totalDed,
        "net_pay"          => $net,
        "premium_pay"      => round($premium, 2),
        "allowance_total"  => round($allowTotal, 2),
        "extra_deductions" => round($extraDed, 2),
    ];
}

// ---- Attendance-based deductions ----
// Any logged attendance means the period is "actively tracked" and
// absence deductions apply normally. Only if ZERO records exist do we
// assume HR hasn't finished logging and skip deductions.
/**
 * Working-day dates that are actually public holidays inside a range.
 *
 * Labor Code Art. 94 entitles a covered employee to the regular daily wage on a
 * REGULAR holiday even when no work is performed, so those dates must not be
 * counted as days the employee was expected to attend - otherwise a holiday with
 * no attendance is scored as an absence and the pay is docked.
 */
function countHolidaysInRange(PDO $pdo, string $from, string $to): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    return (int) $stmt->fetchColumn();
}

function computeAttendanceDeductions(
    PDO $pdo,
    int $employeeId,
    string $startDate,
    string $endDate,
    float $monthlySalary,
    ?float $semiMonthlyBasic = null
): array {
    // Exclude public holidays: a holiday is not a day the employee was expected
    // to attend, so it must not become an absence (Labor Code Art. 94).
    $holidayDays  = countHolidaysInRange($pdo, $startDate, $endDate);
    $expectedDays = max(0, countWorkingDays($startDate, $endDate) - $holidayDays);
    $expectedDaysExHoliday = countWorkingDays($startDate, $endDate);

    // Self-balancing daily rate: the semi-monthly basic spread across the
    // working days actually present in THIS period. A fully-absent period
    // therefore docks exactly the basic -- never more. This is what keeps the
    // model correct now that a period holds 13-14 working days (Mon-Sat)
    // instead of 11; for a 5-day week it equals the old monthly/22 exactly.
    $semiBasic = $semiMonthlyBasic ?? round($monthlySalary / 2.0, 2);
    $dailyRate = $expectedDays > 0 ? ($semiBasic / $expectedDays) : 0.0;

    // Only working-day attendance counts as "present" (Saturday counts,
    // Sunday does not), matching $expectedDays above.
    $stmt = $pdo->prepare(
        "SELECT work_date, time_in, time_out, minutes_late, hours_worked
         FROM attendance
         WHERE employee_id = ? AND work_date BETWEEN ? AND ?
           AND " . workDaySql('work_date') . "
         ORDER BY work_date"
    );
    $stmt->execute([$employeeId, $startDate, $endDate]);
    $attRows     = $stmt->fetchAll();
    $presentDays = count($attRows);
    $lateMinutes = 0;
    foreach ($attRows as $row) $lateMinutes += (int) $row["minutes_late"];

    // Undertime: leaving before 17:00. minutes_late already accounts for a late
    // arrival, so only the early-departure side is added here - otherwise a late
    // employee who still leaves on time would be docked twice. A row with no
    // clock-out proves no hours for the rest of the shift, so the remaining
    // (480 - late) minutes are docked rather than paying a full day.
    $holidayStmt = $pdo->prepare(
        "SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?"
    );
    $holidayStmt->execute([$startDate, $endDate]);
    $holidaySet = array_fill_keys($holidayStmt->fetchAll(PDO::FETCH_COLUMN), true);

    $undertimeMinutes = 0;
    $shiftEnd = new DateTime("17:00:00");
    foreach ($attRows as $row) {
        if (isset($holidaySet[$row["work_date"]])) continue; // premium day, not an ordinary shift
        if ($row["time_out"] === null || $row["time_out"] === "") {
            $undertimeMinutes += max(0, 480 - (int) $row["minutes_late"]);
            continue;
        }
        $out = new DateTime($row["time_out"]);
        if ($out < $shiftEnd) {
            $diff = $out->diff($shiftEnd);
            $undertimeMinutes += min(480, ($diff->h * 60) + $diff->i);
        }
    }

    // Approved PAID leave excuses an absence. Without this, an employee on
    // approved leave has no attendance row for those days and is docked for
    // them, which is wrong. Only working days inside this period count, and a
    // day that already has an attendance row is "present", not "on leave".
    $leaveStmt = $pdo->prepare(
        "SELECT lr.start_date, lr.end_date
         FROM leave_requests lr
         JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
         WHERE lr.employee_id = ? AND lr.status = 'Approved' AND lt.is_paid = 1
           AND NOT (lr.end_date < ? OR lr.start_date > ?)"
    );
    $leaveStmt->execute([$employeeId, $startDate, $endDate]);

    $leaveDates = [];
    foreach ($leaveStmt->fetchAll() as $lr) {
        $c = new DateTime($lr["start_date"]);
        $e = (new DateTime($lr["end_date"]))->modify("+1 day");
        while ($c < $e) {
            $d = $c->format("Y-m-d");
            if ($d >= $startDate && $d <= $endDate && isWorkingDay($c)) {
                $leaveDates[$d] = true;
            }
            $c->modify("+1 day");
        }
    }

    if ($leaveDates) {
        $attDates = $pdo->prepare(
            "SELECT work_date FROM attendance
             WHERE employee_id = ? AND work_date BETWEEN ? AND ?
               AND " . workDaySql('work_date')
        );
        $attDates->execute([$employeeId, $startDate, $endDate]);
        foreach ($attDates->fetchAll(PDO::FETCH_COLUMN) as $workedDate) {
            unset($leaveDates[$workedDate]);
        }
    }
    $leaveDays = count($leaveDates);

    // No short-circuit here on purpose. An earlier version assumed "no attendance
    // rows means HR has not finished logging" and returned zero deductions, which
    // paid an employee in full for a period they never attended. Every working day
    // not covered by attendance or approved leave is now an absence. The caller
    // (payroll_runs.php) caps the resulting deduction at basic + overtime minus
    // statutory contributions, so a fully absent employee nets 0.00 and never a
    // negative amount.
    // A day is present, excused (approved paid leave), or absent.
    $absentDays = max(0, $expectedDays - $presentDays - $leaveDays);
    $perMinute  = $dailyRate / 480.0;

    return [
        "expected_days"     => $expectedDays,
        "present_days"      => $presentDays,
        "leave_days"        => $leaveDays,
        "absent_days"       => $absentDays,
        "late_minutes"      => $lateMinutes,
        "undertime_minutes" => $undertimeMinutes,
        "daily_rate"        => round($dailyRate, 2),
        "absence_deduction" => round($absentDays * $dailyRate, 2),
        "late_deduction"    => round($lateMinutes * $perMinute, 2),
        "undertime_deduction" => round($undertimeMinutes * $perMinute, 2),
    ];
}

// =====================================================================
// Payroll run lock (segregation of duties, step 3)
//
// A run becomes immutable the moment a checker approves it. Every endpoint
// that can change payslips, deductions, allowances, loan payments or cash
// disbursements calls one of these helpers BEFORE writing, so a locked run
// cannot be reached through a side door. Returned runs stay editable.
// =====================================================================
const PAYROLL_LOCKED_RUN_STATUSES = ["Approved", "Released"];

function payrollRunIsLocked(?string $status): bool {
    return in_array((string) $status, PAYROLL_LOCKED_RUN_STATUSES, true);
}

/**
 * Refuse every write that targets a run that has been approved / released.
 * respond() exits, so this function never returns on a locked run.
 */
function assertPayrollRunUnlocked(PDO $pdo, int $runId, string $what = "This payroll data"): void {
    $stmt = $pdo->prepare("SELECT status FROM payroll_runs WHERE run_id = ?");
    $stmt->execute([$runId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        respond(false, "Payroll run not found.", null, 404);
    }
    if (payrollRunIsLocked((string) $status)) {
        respond(
            false,
            "Payroll run #$runId is {$status} and locked. $what cannot be changed. A formal adjustment is required.",
            ["run_id" => $runId, "status" => $status],
            409
        );
    }
}

/**
 * Resolve a payslip's run and enforce the lock. Returns the run row
 * (run_id + status) so callers can reuse it.
 */
function assertPayslipUnlocked(PDO $pdo, int $payslipId, string $what = "This payslip"): array {
    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, pr.run_id, pr.status
         FROM payslips ps
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         WHERE ps.payslip_id = ?"
    );
    $stmt->execute([$payslipId]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(false, "Payslip not found.", null, 404);
    }
    if (payrollRunIsLocked((string) $row["status"])) {
        respond(
            false,
            "Payroll run #{$row['run_id']} is {$row['status']} and locked. $what cannot be changed. A formal adjustment is required.",
            ["run_id" => (int) $row["run_id"], "status" => $row["status"]],
            409
        );
    }
    return $row;
}

/** Enforce the lock for a loan payment (row already exists). */
function assertLoanPaymentUnlocked(PDO $pdo, int $paymentId, string $what = "This loan payment"): void {
    $stmt = $pdo->prepare("SELECT payslip_id FROM loan_payments WHERE payment_id = ?");
    $stmt->execute([$paymentId]);
    $payslipId = $stmt->fetchColumn();
    if ($payslipId === false) {
        respond(false, "Loan payment not found.", null, 404);
    }
    assertPayslipUnlocked($pdo, (int) $payslipId, $what);
}

// =====================================================================
// Attendance-date lock (segregation of duties, step 5)
//
// The helpers above are addressed by run_id / payslip_id. An attendance
// correction is addressed by employee + date, so the run it belongs to has to
// be resolved through the payroll period window instead. Without this a
// correction could silently rewrite the hours behind an approved payslip.
//
// Rule (identical to the rest of the payroll lock): a date is locked when ANY
// run whose period covers it is Approved or Released. Submitted and Returned
// runs are still editable, and a date with no run at all is always editable.
// =====================================================================

/**
 * The locked run covering $date, or null when that date is still editable.
 * Returns run_id / status / start_date / end_date so the caller can explain it.
 */
function attendanceDateLock(PDO $pdo, string $date): ?array {
    $statuses = PAYROLL_LOCKED_RUN_STATUSES;
    $stmt = $pdo->prepare(
        "SELECT pr.run_id, pr.status, pp.start_date, pp.end_date
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         WHERE ? BETWEEN pp.start_date AND pp.end_date
           AND pr.status IN (" . implode(", ", array_fill(0, count($statuses), "?")) . ")
         ORDER BY pr.run_id DESC
         LIMIT 1"
    );
    $stmt->execute(array_merge([$date], $statuses));
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The 409 message shared by assertAttendanceDateUnlocked() and its callers. */
function attendanceDateLockedMessage(array $lock, string $what): string {
    return "Payroll run #{$lock['run_id']} ({$lock['start_date']} to {$lock['end_date']}) "
        . "is {$lock['status']} and locked. $what cannot be changed. "
        . "A formal adjustment is required.";
}

/**
 * Refuse every write that targets attendance on a locked payroll date.
 * respond() exits, so this function never returns on a locked date.
 */
function assertAttendanceDateUnlocked(
    PDO $pdo,
    string $date,
    string $what = "Attendance for this date"
): void {
    $lock = attendanceDateLock($pdo, $date);
    if ($lock !== null) {
        respond(false, attendanceDateLockedMessage($lock, $what), $lock, 409);
    }
}

// ---- 13th Month Pay (reads basic_salary from employees) ----
function compute13thMonthPay(PDO $pdo, int $employeeId, int $year): array {
    $stmt = $pdo->prepare("SELECT basic_salary, date_hired FROM employees WHERE employee_id = ?");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch();

    if (!$emp || (float) $emp["basic_salary"] <= 0) {
        return ["amount" => 0.0, "tax" => 0.0, "net" => 0.0, "months" => 0, "monthly" => 0.0];
    }

    $monthly = (float) $emp["basic_salary"];
    $hired   = $emp["date_hired"];

    $jan1    = new DateTime(sprintf("%04d-01-01", $year));
    $dec31Dt = new DateTime(sprintf("%04d-12-31", $year));

    $start = $jan1;
    if ($hired && $hired !== "0000-00-00") {
        $hireDt = new DateTime($hired);
        if ($hireDt > $jan1) $start = $hireDt;
    }

    if ($start > $dec31Dt) {
        return ["amount" => 0.0, "tax" => 0.0, "net" => 0.0, "months" => 0, "monthly" => $monthly];
    }

    $diff   = $start->diff($dec31Dt);
    $months = ($diff->y * 12) + $diff->m + 1;

    $amount = round($monthly * $months / 12.0, 2);
    $excess = max(0.0, $amount - 90000.0);
    $tax    = round($excess * 0.20, 2);
    $net    = round($amount - $tax, 2);

    return [
        "amount"  => $amount,
        "tax"     => $tax,
        "net"     => $net,
        "months"  => $months,
        "monthly" => $monthly,
    ];
}