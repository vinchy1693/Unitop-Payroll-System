<?php
// =====================================================================
// Independent recomputation of every live payslip.
// Deliberately does NOT call the app's helpers: the arithmetic is
// reimplemented here from the stated rules so agreement is meaningful.
// =====================================================================
$pdo = new PDO("mysql:host=localhost;dbname=payroll_db;charset=utf8mb4", "root", "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function workingDaysMonSat(string $from, string $to): int {
    $c = 0; $d = new DateTime($from); $endEx = (new DateTime($to))->modify('+1 day');
    while ($d < $endEx) { if ((int) $d->format('N') <= 6) $c++; $d->modify('+1 day'); }
    return $c;
}
function trainTax(float $semiTaxable): float {
    $annual = $semiTaxable * 24;
    if ($annual <= 250000)      $t = 0.0;
    elseif ($annual <= 400000)  $t = ($annual - 250000) * 0.15;
    elseif ($annual <= 800000)  $t = 22500 + ($annual - 400000) * 0.20;
    elseif ($annual <= 2000000) $t = 102500 + ($annual - 800000) * 0.25;
    elseif ($annual <= 8000000) $t = 402500 + ($annual - 2000000) * 0.30;
    else                        $t = 2202500 + ($annual - 8000000) * 0.35;
    return round($t / 24, 2);
}

$runs = $pdo->query("SELECT r.run_id, p.period_id, p.start_date, p.end_date
                     FROM payroll_runs r JOIN payroll_periods p ON p.period_id = r.period_id")->fetchAll();

$fail = 0; $checks = 0;
foreach ($runs as $run) {
    $W = workingDaysMonSat($run["start_date"], $run["end_date"]);
    printf("\n=== run %d | period %s..%s | W=%d ===\n", $run["run_id"], $run["start_date"], $run["end_date"], $W);

    $slips = $pdo->prepare(
        "SELECT ps.*, e.employee_number, e.basic_salary, e.employee_id
         FROM payslips ps JOIN employees e ON e.employee_id = ps.employee_id
         WHERE ps.run_id = ? ORDER BY ps.payslip_id"
    );
    $slips->execute([$run["run_id"]]);

    foreach ($slips->fetchAll() as $ps) {
        $eid = (int) $ps["employee_id"];
        $monthly = (float) $ps["basic_salary"];
        $basic   = round($monthly / 2, 2);
        $daily   = $W > 0 ? $basic / $W : 0.0;

        // present = attendance rows on working days in the period
        $q = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(minutes_late),0) AS late FROM attendance
                            WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND DAYOFWEEK(work_date) BETWEEN 2 AND 7");
        $q->execute([$eid, $run["start_date"], $run["end_date"]]);
        $a = $q->fetch();
        $present = (int) $a["n"]; $lateMin = (int) $a["late"];

        // approved paid leave on working days not already attended
        $q = $pdo->prepare("SELECT lr.start_date, lr.end_date FROM leave_requests lr
                            JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
                            WHERE lr.employee_id = ? AND lr.status = 'Approved' AND lt.is_paid = 1
                              AND NOT (lr.end_date < ? OR lr.start_date > ?)");
        $q->execute([$eid, $run["start_date"], $run["end_date"]]);
        $leaveDates = [];
        foreach ($q->fetchAll() as $lr) {
            $d = new DateTime($lr["start_date"]); $ex = (new DateTime($lr["end_date"]))->modify('+1 day');
            while ($d < $ex) {
                $ds = $d->format('Y-m-d');
                if ($ds >= $run["start_date"] && $ds <= $run["end_date"] && (int) $d->format('N') <= 6) $leaveDates[$ds] = true;
                $d->modify('+1 day');
            }
        }
        if ($leaveDates) {
            $q = $pdo->prepare("SELECT work_date FROM attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ?");
            $q->execute([$eid, $run["start_date"], $run["end_date"]]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $wd) unset($leaveDates[$wd]);
        }
        $leaveDays = count($leaveDates);
        $absent = max(0, $W - $present - $leaveDays);

        // overtime (payable only)
        $q = $pdo->prepare("SELECT COALESCE(SUM(hours * rate_multiplier),0) AS h FROM overtime_records
                            WHERE employee_id = ? AND ot_date BETWEEN ? AND ?
                              AND status IN ('Approved','Completed') AND hours > 0
                              AND time_in IS NOT NULL AND time_out IS NOT NULL");
        $q->execute([$eid, $run["start_date"], $run["end_date"]]);
        $otUnits = (float) $q->fetchColumn();
        $otPay = round($otUnits * ($daily / 8.0), 2);

        // statutory (monthly based, halved)
        $sss = round(round(max(5000, min(35000, $monthly)) * 0.05, 2) / 2, 2);
        $ph  = round(round(max(10000, min(100000, $monthly)) * 0.025, 2) / 2, 2);
        $pi  = round(round(min(10000, $monthly) * 0.02, 2) / 2, 2);

        $absenceRaw = round($absent * $daily, 2);
        $lateRaw    = round($lateMin * ($daily / 480), 2);
        $gross      = round($basic + $otPay, 2);

        // tax: base = gross - attendance - statutory  (the fixed rule)
        $taxBase = round($gross - $absenceRaw - $lateRaw - $sss - $ph - $pi, 2);
        if ($taxBase < 0) $taxBase = 0;
        $tax = trainTax($taxBase);

        // cap attendance deductions at what remains after statutory + tax
        $room = max(0.0, round($gross - ($sss + $ph + $pi + $tax), 2));
        $absenceDed = min($absenceRaw, $room);
        $lateDed = min($lateRaw, max(0.0, round($room - $absenceDed, 2)));

        // other deduction lines (loan etc.) as recorded
        $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM allowances WHERE payslip_id = ?");
        $q->execute([$ps["payslip_id"]]); $allow = (float) $q->fetchColumn();
        // Only the NON-attendance lines here: absence and late are stored as
        // deduction rows too, so summing every row would double-count them.
        $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deductions
                            WHERE payslip_id = ?
                              AND deduction_type NOT LIKE 'Absences%'
                              AND deduction_type NOT LIKE 'Late/Undertime%'");
        $q->execute([$ps["payslip_id"]]); $extraDed = (float) $q->fetchColumn();
        $grossWithAllow = round($gross + $allow, 2);

        $q = $pdo->prepare("SELECT sss_ee, philhealth_ee, pagibig_ee, withholding_tax FROM govt_contributions WHERE payslip_id = ?");
        $q->execute([$ps["payslip_id"]]); $gc = $q->fetch() ?: [];

        $totalDedCalc = round($sss + $ph + $pi + $tax + $absenceDed + $lateDed + $extraDed, 2);
        $netCalc = round($grossWithAllow - $totalDedCalc, 2);
        if ($netCalc < 0) { $totalDedCalc = $grossWithAllow; $netCalc = 0.0; }

        $cmp = [
            "basic_pay"        => [$basic, (float) $ps["basic_pay"]],
            "overtime_pay"     => [$otPay, (float) $ps["overtime_pay"]],
            "gross_pay"        => [$grossWithAllow, (float) $ps["gross_pay"]],
            "sss_ee"           => [$sss, (float) ($gc["sss_ee"] ?? -1)],
            "philhealth_ee"    => [$ph, (float) ($gc["philhealth_ee"] ?? -1)],
            "pagibig_ee"       => [$pi, (float) ($gc["pagibig_ee"] ?? -1)],
            "withholding_tax"  => [$tax, (float) ($gc["withholding_tax"] ?? -1)],
            "total_deductions" => [$totalDedCalc, (float) $ps["total_deductions"]],
            "net_pay"          => [$netCalc, (float) $ps["net_pay"]],
        ];
        printf("  %-14s W=%d present=%d leave=%d absent=%d late=%dmin OT=%.2fh\n",
            $ps["employee_number"], $W, $present, $leaveDays, $absent, $lateMin, $otUnits);
        foreach ($cmp as $k => [$exp, $act]) {
            $checks++;
            $ok = abs($exp - $act) < 0.011;
            if (!$ok) $fail++;
            printf("     %-18s expected %11.2f   stored %11.2f   %s\n", $k, $exp, $act, $ok ? "ok" : "** MISMATCH **");
        }
    }
}
printf("\n================  %d checks, %d mismatches  ================\n", $checks, $fail);
exit($fail === 0 ? 0 : 1);
