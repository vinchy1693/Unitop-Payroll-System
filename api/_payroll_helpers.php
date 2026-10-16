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
function computePayslipDeductions(float $monthlySalary, float $semiBasic, float $preTaxDeductions = 0.0): array {
    $sss = computeSSS($monthlySalary);
    $ph  = computePhilHealth($monthlySalary);
    $pi  = computePagIbig($monthlySalary);

    $sssEE = round($sss["ee"] / 2, 2);
    $sssER = round($sss["er"] / 2, 2);
    $phEE  = round($ph["ee"]  / 2, 2);
    $phER  = round($ph["er"]  / 2, 2);
    $piEE  = round($pi["ee"]  / 2, 2);
    $piER  = round($pi["er"]  / 2, 2);

    $semiTaxable = round($semiBasic - $preTaxDeductions - $sssEE - $phEE - $piEE, 2);
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
    $stmt = $pdo->prepare("SELECT basic_pay, overtime_pay FROM payslips WHERE payslip_id = ?");
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

    $gross    = round($basic + $ot + $allowTotal, 2);
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
        "allowance_total"  => round($allowTotal, 2),
        "extra_deductions" => round($extraDed, 2),
    ];
}

// ---- Attendance-based deductions ----
// Any logged attendance means the period is "actively tracked" and
// absence deductions apply normally. Only if ZERO records exist do we
// assume HR hasn't finished logging and skip deductions.
function computeAttendanceDeductions(
    PDO $pdo,
    int $employeeId,
    string $startDate,
    string $endDate,
    float $monthlySalary,
    ?float $semiMonthlyBasic = null
): array {
    $expectedDays = countWorkingDays($startDate, $endDate);

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
        "SELECT COUNT(*) AS days, COALESCE(SUM(minutes_late), 0) AS late_total
         FROM attendance
         WHERE employee_id = ? AND work_date BETWEEN ? AND ?
           AND " . workDaySql('work_date')
    );
    $stmt->execute([$employeeId, $startDate, $endDate]);
    $att = $stmt->fetch();
    $presentDays = (int) $att["days"];
    $lateMinutes = (int) $att["late_total"];

    // If HR logged nothing for this employee, assume perfect attendance
    if ($presentDays === 0) {
        return [
            "expected_days"     => $expectedDays,
            "present_days"      => 0,
            "absent_days"       => 0,
            "late_minutes"      => 0,
            "daily_rate"        => round($dailyRate, 2),
            "absence_deduction" => 0.0,
            "late_deduction"    => 0.0,
        ];
    }

    $absentDays = max(0, $expectedDays - $presentDays);
    $perMinute  = $dailyRate / 480.0;

    return [
        "expected_days"     => $expectedDays,
        "present_days"      => $presentDays,
        "absent_days"       => $absentDays,
        "late_minutes"      => $lateMinutes,
        "daily_rate"        => round($dailyRate, 2),
        "absence_deduction" => round($absentDays * $dailyRate, 2),
        "late_deduction"    => round($lateMinutes * $perMinute, 2),
    ];
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