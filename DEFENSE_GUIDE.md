# UNITOP Payroll System — Defence Guide

**Everything in this document has been verified against the running system**, not written from memory. Where a claim can be reproduced, the command is given.

**How to use this:** §3–§5 are the legal and mathematical groundwork ("what is our basis?"). §6–§8 are the controls ("how do you prevent fraud?"). §9 is your proof. §10 is the questions they will ask. §11 is what to admit before they find it.

---

## 1. What the system is

Two separate web applications sharing one database (`payroll_db`):

| | Admin app | Employee portal |
|---|---|---|
| Path | `C:\xampp\htdocs\master_file_module_api` | `C:\xampp\htdocs\employee_portal` |
| URL | `http://localhost/master_file_module_api/` | `http://localhost/employee_portal/` |
| Users | Payroll Officer, Approver, Administrator | Employee |
| Purpose | Prepare and approve payroll | View own payslips, attendance, loans; file leave/OT/corrections |

They are **separate deployments** with **separate PHP sessions** (`admin_sid` / `portal_sid`), so signing into one cannot leak into the other.

---

## 2. Roles and separation of duties

| Role | Can | **Cannot** |
|---|---|---|
| **Payroll Officer** (maker) | Employees, attendance, leave, overtime preparation, loans preparation, payroll periods/runs, payslips, exports | Approve payroll; **approve overtime or loans**; assign Payroll/Approver roles; open Users or Audit Log |
| **Approver** (checker) | Approve / return / release payroll; **approve overtime and loans**; approve team requests; view reports | **Edit any amount**; run payroll; create Payroll/Approver accounts |
| **Administrator** (technical) | Create/disable users, assign Employee/Supervisor/Administrator roles, Audit Log | **Any payroll or salary data**; assign a Payroll Officer or Approver role; set a payroll-role password |
| **Supervisor** | Approve corrections/overtime/leave **for their own team only**; **recommend** loans | Approve loans; see salaries; approve own requests |
| **Employee** | Own data via the portal | The admin app entirely |

**Basis:** this is committee-style segregation of duties — the person who *prepares* cannot *approve*.

**Code:**
```
api/_bootstrap.php
  const MAKER_ROLES    = ["Payroll Officer"];
  const CHECKER_ROLES  = ["Approver"];
  const ADMIN_ROLES    = ["Administrator"];
  const PAYROLL_ROLES   = ["Payroll Officer", "Approver"];   // maker + checker
  const SUPERVISOR_ROLE = "Supervisor";
  const ALL_ROLES = ["Employee","Payroll Officer","Approver","Supervisor","Administrator"];
```
Every endpoint calls `requireRole(...)`. `api/payroll_runs.php` enforces the maker/approver split and refuses self-approval:

```php
// api/payroll_runs.php  (PATCH approve | return | release)
if ($action !== "resubmit" && $run["submitted_by"] !== null
    && (int) $run["submitted_by"] === (int) $user["user_id"]) {
    respond(false,
        "Segregation of duties: the user who submitted this run cannot approve, "
        . "return or release it. A different Approver must decide it.", null, 403);
}
```

**Proof it works:** log in as `payroll`, process a period, then try to approve it → **403**. Log in as `approver` → succeeds.

The same own-request rule is enforced for OT, leave, corrections and loans by `assertNotOwnEmployee()`: a reviewer with an employee link cannot decide their own request. The Administrator screen cannot create or promote a Payroll Officer/Approver, nor set a payroll-role password — those accounts are provisioned outside it, so the technical role cannot mint the checker who approves payroll.

---

## 3. Legal basis of every deduction

> Say this: *"Every deduction is traceable to a statute except tardiness and absence, which follow the no-work-no-pay principle. Each is computed on the base its law specifies — which is why the effective percentages differ."*

| Deduction | Law | Rate (employee share) | Base | Ceiling / floor |
|---|---|---|---|---|
| **SSS** | RA 11199, Social Security Act of 2018 | **5%** | Monthly Salary Credit | MSC **₱5,000 – ₱35,000** (Jan 2025 tranche) |
| **PhilHealth** | RA 11223, Universal Health Care Act | **2.5%** | Monthly basic salary | floor **₱10,000**, ceiling **₱100,000** |
| **Pag-IBIG** | RA 9679, HDMF Law of 2009 | **2%** | Monthly compensation | **Max Fund Salary ₱10,000** |
| **Withholding tax** | RA 10963 (TRAIN) + BIR RR 11-2018 | progressive | Annualised **taxable** income — basic + overtime + taxable allowances, less attendance deductions and the three contributions | ₱250,000 exempt |
| **Tardiness / absence** | Labor Code "no work, no pay" + company policy | — | daily rate | capped at remaining pay |
| **Overtime premium** | Labor Code Arts. 87, 93, 94 | **+25%** ordinary; **+30%** rest day; holiday rates | hourly rate | — |
| **13th month** | PD 851 | 1/12 of annual basic | — | first ₱90,000 exempt (TRAIN) |
| **Service Incentive Leave** | Labor Code Art. 95 | ≥5 days after 1 yr | — | — |
| **Maternity** | RA 11210 | 105 days paid | — | — |
| **Paternity** | RA 8187 | 7 days, first 4 deliveries | — | — |

**Source for the ₱35,000 ceiling** — the SSS itself, [7 January 2025](https://www.sss.gov.ph/news-and-updates/sss-clarifies-1-contribution-rate-hike-bares-plans-for-2025/):

> "…bring the contribution rate to **15%** from the previous 14%, pursuant to **RA 11199**… increases in the minimum Monthly Salary Credit to **P5,000.00** and in the maximum MSC to **P35,000.00** from the previous P30,000.00."

### Why the percentages differ from each other

The percentage a payslip shows is **not** the statutory rate — it is *amount ÷ gross pay*, and each contribution uses a different base:

```
shown %  =  legal rate  ×  (that law's base ÷ salary)
```

For Juan Dela Cruz (₱45,000/month, ₱22,500 semi-monthly):

| | Rate | Base | Amount/mo | Per cutoff | ÷ ₱22,500 |
|---|---|---|---|---|---|
| SSS | 5% | ₱35,000 (capped) | 1,750.00 | 875.00 | **3.9%** |
| PhilHealth | 2.5% | ₱45,000 (full) | 1,125.00 | 562.50 | **2.5%** |
| Pag-IBIG | 2% | ₱10,000 (capped) | 200.00 | 100.00 | **0.4%** |
| Tax | progressive | taxable income | — | — | **7.8%** |

```
5% × (35,000 ÷ 45,000) = 3.9%   ← capped
2% × (10,000 ÷ 45,000) = 0.4%   ← capped
2.5% × (45,000 ÷ 45,000) = 2.5%  ← the only one whose base IS the salary
```

PhilHealth looks "correct" only because it is the one contribution charged on full salary. **The differences are the three statutory ceilings.**

---

## 4. The payroll computation, step by step

Rules: **work week is Monday–Saturday**; Sunday is the only non-working day. `const WORK_WEEK_LAST_ISO_DOW = 6` in `api/_payroll_helpers.php`.

Worked example — **Vinchy Fantonial, ₱60,000/month, period 2026-10-01 → 10-15**:

| # | Step | Formula | Value |
|---|---|---|---|
| 1 | Working days `W` | Mon–Sat in the period, **less any public holiday** | **13** (Oct 4 & 11 are Sundays; no holiday falls in this period) |
| 2 | Basic | monthly ÷ 2 | **30,000.00** |
| 3 | Daily rate | basic ÷ W | **2,307.69** |
| 4 | Present | attendance rows on working days | **12** |
| 5 | Excused (leave) | approved leave whose type `is_paid = 1`, not already attended | **0** |
| 6 | Absent | `max(0, W − present − leave)` | **1** |
| 7 | Absence deduction | absent × daily rate | **2,307.69** |
| 8 | Late deduction | `minutes_late × (daily ÷ 480)` = 11 × 4.8077 | **52.88** |
| 9 | Overtime | `hours × multiplier × (daily ÷ 8)` = 2.0 × 1.25 × 288.4615 | **721.15** |
| 10 | Gross | basic + OT + allowances | **30,721.15** |
| 11 | SSS | 5% of clamp(60,000, 5k, 35k) ÷ 2 | **875.00** |
| 12 | PhilHealth | 2.5% of clamp(60,000, 10k, 100k) ÷ 2 | **750.00** |
| 13 | Pag-IBIG | 2% of min(60,000, 10k) ÷ 2 | **100.00** |
| 14 | **Taxable base** | gross − attendance deductions − statutory | **26,635.58** |
| 15 | Annualised | × 24 | **639,253.92** |
| 16 | TRAIN | 22,500 + 20% of excess over 400,000 | **70,350.78** |
| 17 | Withholding tax | ÷ 24 | **2,931.28** |
| 18 | Loan amortisation | monthly ÷ 2 (₱1,000 → per cutoff) | **500.00** |
| 19 | Total deductions | 875 + 750 + 100 + 2,931.28 + 2,307.69 + 52.88 + 500 | **7,516.85** |
| 20 | **Net pay** | gross − total deductions | **23,204.30** |

These are the actual stored values — see §9 for the command that proves it.

### The cap (why net can never go negative)

`api/payroll_runs.php`:

```php
$room             = max(0.0, round($basic + $otPay - $statutory, 2));
$absenceDeduction = min($att["absence_deduction"], $room);
$lateDeduction    = min($att["late_deduction"], max(0.0, round($room - $absenceDeduction, 2)));
```

`api/_payroll_helpers.php`, `recomputePayslipTotals()`:

```php
if ($net < 0) { $totalDed = $gross; $net = 0.0; }
```

An employee absent the whole period nets **exactly ₱0.00** — verified on three live payslips. The excess is **not** carried forward.

---

## 5. The code behind each step

All in `api/_payroll_helpers.php` unless stated.

### Working days & daily rate
```php
const WORK_WEEK_LAST_ISO_DOW = 6;   // Saturday
function isWorkingDay(DateTimeInterface $day): bool {
    return (int) $day->format("N") <= WORK_WEEK_LAST_ISO_DOW;
}
```
```php
// computeAttendanceDeductions()
$expectedDays = countWorkingDays($startDate, $endDate);
$semiBasic    = $semiMonthlyBasic ?? round($monthlySalary / 2.0, 2);
$dailyRate    = $expectedDays > 0 ? ($semiBasic / $expectedDays) : 0.0;
```
> The daily rate is **self-balancing**: the semi-monthly basic spread across the working days actually in *that* period. A fully absent period docks exactly the basic — never more. For a 5-day week this equals the old monthly ÷ 22.

### Public holidays (Labor Code Art. 94)

A **regular holiday is paid even when no work is performed**, so it must never be scored as an absence. The expected-day count is therefore reduced by every public holiday inside the period:

```php
// api/_payroll_helpers.php -> computeAttendanceDeductions()
$holidayDays  = countHolidaysInRange($pdo, $startDate, $endDate);
$expectedDays = max(0, countWorkingDays($startDate, $endDate) - $holidayDays);
```

```php
function countHolidaysInRange(PDO $pdo, string $from, string $to): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    return (int) $stmt->fetchColumn();
}
```

The `holidays` table (`sql/009_holidays.sql`) is the calendar: `holiday_date`, `name` and `holiday_type` (`Regular` / `Special`). Twelve fixed-date 2026 holidays are loaded; **Holy Week, Eid'l Fitr and Eid'l Adha are not**, because their dates are proclaimed each year — they are data, not code.

> This is why a holiday with no attendance row no longer docks pay. The absence test is `expected − present − excused`, and a holiday has already left `expected`.

### Day classification
```php
$leaveStmt = $pdo->prepare(
    "SELECT lr.start_date, lr.end_date FROM leave_requests lr
     JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
     WHERE lr.employee_id = ? AND lr.status = 'Approved' AND lt.is_paid = 1 ..."
);
...
$absentDays = max(0, $expectedDays - $presentDays - $leaveDays);
```
> A day is **present**, **excused** (approved paid leave), or **absent** — never two of them.

### SSS / PhilHealth / Pag-IBIG
```php
function computeSSS(float $monthlySalary): array {
    $msc = max(5000.0, min(35000.0, $monthlySalary));
    return ["ee" => round($msc * 0.05, 2), "er" => round($msc * 0.10, 2)];
}
function computePhilHealth(float $monthlySalary): array {
    $base = max(10000.0, min(100000.0, $monthlySalary));
    return ["ee" => round($base * 0.025, 2), "er" => round($base * 0.025, 2)];
}
function computePagIbig(float $monthlySalary): array {
    $base = min(10000.0, $monthlySalary);
    return ["ee" => round($base * 0.02, 2), "er" => round($base * 0.02, 2)];
}
```

### Withholding tax and its base
```php
function computeWithholdingTax(float $semiMonthlyTaxable): float {
    $annual = $semiMonthlyTaxable * 24.0;
    if ($annual <= 250000)      $annualTax = 0.0;
    elseif ($annual <= 400000)  $annualTax = ($annual - 250000) * 0.15;
    elseif ($annual <= 800000)  $annualTax = 22500 + ($annual - 400000) * 0.20;
    elseif ($annual <= 2000000) $annualTax = 102500 + ($annual - 800000) * 0.25;
    elseif ($annual <= 8000000) $annualTax = 402500 + ($annual - 2000000) * 0.30;
    else                        $annualTax = 2202500 + ($annual - 8000000) * 0.35;
    return round($annualTax / 24, 2);
}
```
```php
// computePayslipDeductions()
$semiTaxable = round($semiBasic + $additionalTaxable - $preTaxDeductions
                     - $sssEE - $phEE - $piEE, 2);
if ($semiTaxable < 0) $semiTaxable = 0;
$tax = computeWithholdingTax($semiTaxable);
```
> `$additionalTaxable` carries **overtime**. This mattered: the base was previously `$semiBasic` alone, so overtime went untaxed and every payslip with OT under-withheld. On the example above the difference is **₱2,787.05 → ₱2,931.28**.

### Taxable and non-taxable allowances

Allowances are added *after* the payslip row exists, so the tax has to be recomputed when a line changes. `allowances.is_taxable` (migration `011_allowance_taxability.sql`) decides whether a line raises the tax base:

```php
// api/_payroll_helpers.php -> recomputePayslipTotals()
$taxStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) FROM allowances WHERE payslip_id = ? AND is_taxable = 1"
);
$taxBase = round($basic + $ot + $taxableAllow - $attendanceDed - $statutoryOnly, 2);
```

- **Taxable** (the default): a rice allowance, a cash allowance, a taxable incentive — it raises gross **and** withholding tax.
- **Non-taxable**: a de minimis benefit under BIR RR 11-2018 (rice, uniform, medical cash, laundry, meal) — it raises gross pay only.

> The earlier behaviour taxed nothing that was added after creation, under-withholding on every allowance-bearing payslip. The base now matches the creation path exactly: basic + overtime + taxable allowances − attendance deductions − statutory contributions.

### 13th month pay and its ₱90,000 exemption

A `13th Month` period run writes a **non-taxable** allowance line and stores the tax that `compute13thMonthPay()` derives — 20% of the amount above **₱90,000** (RA 10963, Sec. 32(B)(7)(a) NIRC):

```php
// api/payroll_runs.php (13th Month branch)
INSERT INTO allowances (payslip_id, allowance_type, amount, is_taxable)
VALUES (?, '13th Month Pay', ?, 0)
```

`recomputePayslipTotals()` deliberately **does not** re-tax a payslip that carries a `13th Month Pay` line: the ordinary base would otherwise withhold on the exempt portion. For a ₱60,000 13th month the tax is **₱0** and net is the full ₱60,000.

### Contribution bases (why basic only)

The three contributions are deliberately computed on **`basic_salary`**, not on gross pay:

- **SSS** — RA 1161 §8 defines compensation as excluding “bonuses, allowances, or overtime pay given in addition to the regular or base pay.”
- **PhilHealth** — RA 11223 uses the **monthly basic salary**.
- **Pag-IBIG** — RA 9679 §4 defines compensation as “basic monthly salary plus mandated cost of living allowance.”

So overtime, holiday premium and allowances do **not** raise the contribution base. This is the statute, not an omission; do not “fix” it by passing gross pay.

### Day types and premium pay

`dayTypeForDate()` classifies every date: **Regular Holiday** (`holidays.holiday_type`), **Special Holiday**, **Rest Day** (Sunday, the only non-working day), or **Ordinary Day**. The date drives the rate; the client never supplies it.

| Day type | First 8 hours | Overtime beyond 8h |
|---|---|---|
| Ordinary day | 1.00 (basic) | **1.25** |
| Rest day | **1.30** | **1.69** (1.30 × 1.30) |
| Special holiday | **1.30** | **1.69** |
| Regular holiday | **2.00** | **2.60** (2.00 × 1.30) |

- The first 8 hours of rest-day/holiday work are **premium pay** (`payslips.premium_pay`), not overtime. Only the excess, or a separate approved overtime block, uses the day-type OT rate.
- For a holiday the semimonthly basic already pays the 1.00 non-working-day part, so the premium line adds only the part above it. For a rest day the basic does not cover the day, so the full 1.30 is added.
- `premium_pay` is taxable compensation: it is included in gross and in the withholding base.
- If a holiday falls on a Sunday, the holiday rate takes precedence (a documented simplification; the special “holiday on rest day” rate is not compounded).

```php
// api/payroll_runs.php
$premium    = computePremiumPay($pdo, $employeeId, $start, $end, $att["daily_rate"]);
$otDayType  = dayTypeForDate($pdo, $ot["ot_date"]);
$multiplier = max((float) $ot["rate_multiplier"], overtimeMultiplierForDayType($otDayType));
$otPay += (float) $ot["hours"] * $multiplier * $hourlyRate;
```

> `max(stored, derived)` means an old row stored at 1.25 on a holiday is still paid at 2.60. The migration backfills the stored value too, but payroll does not rely on it.

### Undertime

`computeAttendanceDeductions()` counts the minutes between the 17:00 shift end and `time_out`; a missing clock-out docks the remaining shift (480 − late minutes). Lateness and undertime are separate, so a 6-minute late arrival that still clocks out at 17:00 is not docked twice. The payslip line is `Undertime (N min)`, capped like absence and late deductions.

### Meal break

A span longer than **300 minutes** loses one **60-minute** meal break — for the regular shift and for overtime (`computeOvertimeHours()`). A 5-hour block is paid in full; a 5.5-hour block is paid 4.5 hours. This is a policy assumption: the system does not capture a separate meal-out/meal-in scan.

### Overtime
```php
// api/payroll_runs.php
$hourlyRate = $att["daily_rate"] / 8.0;
$otPay += (float) $ot["hours"] * $multiplier * $hourlyRate;
```
Only records matching **all** of these count:
```sql
AND status IN ('Approved','Completed')
AND hours IS NOT NULL AND hours > 0
AND time_in IS NOT NULL AND time_out IS NOT NULL
```
> Pending, Rejected, and approved-but-never-started overtime each contribute **₱0**.

---

## 6. Attendance: the kiosk state machine

`kiosk.html` + `api/scan_attendance.php`. One QR scan advances a state:

| Scan | Condition | Result |
|---|---|---|
| 1 | no attendance today | **clock IN** |
| 2 | clocked in, no clock-out | **clock OUT** |
| 3 | already clocked out | **overtime REQUEST** — `Pending`, no attendance row |
| 3b | request still Pending | **refused** — "still awaiting approval", no duplicate |
| 3c | request Rejected | refuses the clock-in, files a **fresh** request |
| 4 | request **Approved** | **overtime clock-IN** |
| 5 | approved + started | **overtime clock-OUT** — hours computed, `Completed` |

> **Approval gates the work, not just the payment.** Hours are measured only from the approved block's `time_in` to `time_out` — staying late without approval is not recorded or paid. That is the system's control; legally, work actually rendered is generally compensable, so this is the one control to align with company policy (see §11).

The employee can also file the request from the **portal attendance page** with a date, requested window and reason — the preferred path for planned overtime. The kiosk remains the time clock: after approval, the employee scans for OT clock-in and clock-out. The day type and rate are derived on the server either way.

**The kiosk enforces the approved window.** OT clock-in is refused more than 5 minutes before `requested_start` or after `requested_end`; OT clock-out is refused more than 5 minutes before `requested_end`. A clock-out after the approved end is recorded, but the paid hours stop at the approved end — the response carries `capped: true` and the message says so; the excess needs a new/extended request. Rows with no requested end (the kiosk's after-shift fallback) keep the old behaviour.

The kiosk is **staff-operated and on-site**. Employees do **not** type their code in the portal: the old portal "Record attendance" fallback was removed, and `employee_portal/api/me_scan_attendance.php` now returns **403**. `api/scan_attendance.php` also requires a staff role (`TEAM_QUEUE_ROLES`), so an Employee who logs into the admin app cannot use `kiosk.html` from home either. The staff kiosk's manual code field remains for a broken camera at the office.

**Who decides OT:** the **Approver** or the employee's **Supervisor** (PATCH allows `CHECKER_ROLES + SUPERVISOR_ROLE`). The Payroll Officer prepares/edits the request but cannot approve it; the UI hides the buttons for that role.

A **Demo mode** strip (collapsed by default, red when on) lets a demonstration supply a date and time; both are validated. It is **maker-only** (`requireRole(MAKER_ROLES)`), so an Employee, Approver, Supervisor or Administrator gets **403**, and `ALLOW_DEMO_SCANS` in `api/_bootstrap.php` can disable it outright for a real deployment.

The portal overtime form has a separate **Demo mode** checkbox for a same-day window that has already passed, or a past date up to 30 days back. It is accepted only while `ALLOW_DEMO_OT` is true in `employee_portal/api/me_overtime_requests.php`; set it to **false** in production, otherwise an employee can file retroactive overtime. Normal reason/time/order/open-request checks still apply.

### Short-lived QR tokens

The portal no longer displays the static `qr_codes.code_value`. `employee_portal/api/me_qr.php` issues a **120-second, one-time, purpose-bound token** (`UNITOP-T-…`):

- `purpose` is `time_in` or `time_out`, derived from today's attendance; after both punches it returns `complete` and **no token/QR**, unless an **Approved overtime record for today is still open** — then it returns `ot` so the kiosk can clock the approved OT in/out. A Pending or Completed OT does not produce a QR.
- Only a SHA-256 hash is stored in `qr_tokens`; the token is marked used when the kiosk writes the punch.
- The kiosk accepts it through `qr_token`, validates expiry/used/employee-active, and consumes it. The legacy static badge is still accepted while `ALLOW_LEGACY_QR` is true; flip that after the printed badges are replaced.
- An employee needs an **Active `qr_codes` badge**; with no active badge the portal returns 404 and shows no QR. Revoking a badge revokes **all active badges** for that employee and invalidates every unused token; the portal then refuses to mint another (`403`). Reactivating or generating a badge restores access.
- `employee_portal/attendance.html` renders the token in the existing QR card and refreshes it 30 seconds before expiry. There is no separate QR-only page.

**Honest limit:** a browser cannot block the OS screenshot key or a second camera. The 120-second expiry and one-time use make a screenshot stale; true screen-capture blocking needs a native/managed kiosk (`FLAG_SECURE`). A token can still be relayed inside its 120-second window; the staffed face/photo check is still the authenticator.

**Throughput:** the token is generated when the page loads/refreshes, not per kiosk scan. The kiosk validation is one small query; 50 phones refreshing every 90 seconds is well under one request per second. The camera/identity step, not token generation, is the line bottleneck.

---

## 7. Leave

- Employee files (portal or admin) → **Pending**
- A **Supervisor** may approve their own team's; a **Payroll Officer** may approve any
- On approval the balance is deducted in the **same transaction** (`SELECT … FOR UPDATE` on the request, so two concurrent approvals cannot deduct twice)
- Single-day requests are allowed **even on a weekend** — Birthday Leave is one day and a Saturday birthday must be filable
- Leave days are counted **Monday–Saturday**, matching the payroll work week
- An **overlapping** request is refused, naming the actual conflicting leave (type, dates, id) rather than "you already have a leave"

Balance rule: `leave_balances.remaining_days −= days`, falling back to `leave_types.days_per_year` when no row exists yet.

---

## 8. The controls that make it auditable

### Payroll approval and lock

```
Payroll Officer processes ──► Submitted
                                 │
                    ┌────────────┴────────────┐
              Approver approves           Approver returns (+ remarks required)
                    │                          │
                Approved ◄──── resubmit ── Returned
                    │                    (editable again)
            Approver releases
                    │
                Released
```

The **period** follows the run: `Open` → `For Approval` when the run is Submitted → `Returned` if the checker returns it → `For Approval` again on resubmit → **`Processed` only when the run is Approved or Released**. A Submitted/Returned period is not editable and cannot be re-run; the run page owns the next action.

The portal exposes a payslip only when its payroll run is **`Released`**. `me_payslips.php` and `me_dashboard.php` filter on `payroll_runs.status = 'Released'`, so a Draft/Submitted/Approved run is invisible — including a direct `?id=` request.

Once `Approved` or `Released`, any edit to payslips, deductions, allowances or loan payments is refused, and the **same date lock** now covers manual attendance, kiosk scans, and overtime create/edit/delete:

```php
const PAYROLL_LOCKED_RUN_STATUSES = ["Approved", "Released"];
// … 409: "Payroll run #N is Approved and locked. … A formal adjustment is required."
```

`assertAttendanceDateUnlocked()` is called from `attendance.php`, `scan_attendance.php`, `overtime_records.php` and `attendance_corrections.php`, so deleting or rewriting the evidence behind a signed payslip is no longer possible.

### Voiding a run — the formal adjustment

"Formal adjustment required" used to be a dead end: an approved run could not be edited and nothing existed to unwind it. `api/payroll_void.php` is that path.

- **Approver only** (`CHECKER_ROLES`). The maker cannot void their own payroll.
- **Remarks are mandatory** — at least 10 characters, because a void removes the payslip rows.
- A `Released` run is refused with **409**: payment has already been authorised, so it needs a reversing entry, not a void.
- One transaction, with `SELECT … FOR UPDATE` on the run: delete the run's deduction / allowance / contribution / loan-payment / cash rows, delete the payslips, delete the run, then set its period back to `Open` so it can be processed again.
- **Loan status is repaired**: a loan the run marked `Paid` is re-opened to `Active` when its payments are removed and the principal is still outstanding. Without this, voiding a run forgave the remaining loan balance.
- Audited by the same `respond()` hook as every other mutation, with the reason in the audit row.

```php
requireRole(CHECKER_ROLES);
// body: { run_id, remarks }   remarks >= 10 characters
if ($run["status"] === "Released") {
    respond(false, "… payment has already been authorised. … needs a reversing adjustment.", null, 409);
}
// … delete payslip children, payslips, the run
$pdo->prepare("UPDATE payroll_periods SET status = 'Open' WHERE period_id = ?")->execute([$periodId]);
```

> Flow: `Submitted / Approved ──void (Approver + reason)──► period Open ──Process again──► new run`. The wrong run is removed rather than silently overwritten; the audit trail keeps who voided what, when and why.

### Audit trail

One hook in `api/_bootstrap.php`, inside `respond()`:

```php
// every non-GET request writes exactly one row:
//   actor · script · method · HTTP status · success · message · IP · target · redacted body
```

> Placed in `respond()` rather than in 20 endpoints, so **every mutating request is captured automatically, including endpoints written later.** It logs failures too — a rejected 403 from an employee probing payroll is itself evidence. Passwords, tokens and CSRF values are replaced with `***`, and so are the identifiers that are themselves live secrets: the **QR value** (`code_value`) and the **bank / government numbers** (`bank_account_number`, `tin_number`, `sss_number`, `philhealth_number`, `pagibig_number`). GETs are never logged. `sql/012_scrub_audit_sensitive.sql` removes the copies already stored.

### Request hardening

| Control | Implementation |
|---|---|
| **CSRF** | A per-session token is published in a readable `admin_csrf` / `portal_csrf` cookie; every non-GET request must echo it in `X-CSRF-Token`. A cross-site form can send the cookie but cannot read it, so it cannot set the header. The login action is exempt (it has no session yet) and is covered by SameSite + JSON-only. |
| **JSON-only bodies** | `json_input()` accepts `application/json` only; the old `$_POST` fallback is gone, and a form-encoded mutation answers **415**. A browser form cannot send `application/json`. |
| **Session cookies** | `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the site is on HTTPS. `admin_sid` and `portal_sid` are separate names. |
| **Errors** | `display_errors=0`; uncaught exceptions and PDO failures return a neutral **500** and the detail goes to the server log. No SQL, schema or credential text reaches the browser. |
| Demo scans | Maker-only, and `ALLOW_DEMO_SCANS` can turn the branch off. |
| Portal OT demo mode | `ALLOW_DEMO_OT` must be **false** in production; when true, an employee can file a past overtime date/window (up to 30 days back) for testing. |

### Maker cannot approve their own payroll

Enforced **in the API** (see §2), not by hiding a button — a crafted request fails the same way a click would.

### Salary is checker-only

`api/employee_salary.php` is `CHECKER_ROLES` (Approver) only. It requires a reason of at least 10 characters, refuses the checker's own salary, and is audited. `employees.php` PUT still lets the maker maintain names, contacts, bank details and position, but a changed `basic_salary` returns **403** — the maker can no longer raise pay immediately before a run. New employees can still be created with a starting salary; later changes go through the checker.

### Loan activation and payment cap

- A new loan starts **Pending**; `POST loans.php` refuses `status=Active`. The Approver activates it.
- Payroll withholds at most the employee's **remaining net pay**. If attendance/statutory deductions leave no room, no `loan_payments` row is written and the loan stays Active for the next run. The old code credited the full amortisation and then clamped net pay to zero, forgiving debt the employee never paid.
- A manual payment is capped at the outstanding principal and is accepted only while the loan is **Active**; `payment_date` is validated.
- Voiding the run deletes the loan payments and re-opens a loan that was marked `Paid`.

### Period overlap and type

`payroll_periods.php` accepts only **Semi-monthly** and **13th Month**. Same-type periods may not overlap, on POST **and** on PUT; a 13th-month run is deliberately allowed to overlap a working period. The free-text type field previously allowed a “Monthly” period to bypass the check and pay the same days twice.

### Data protection

`sql/.htaccess`, `.git/.htaccess` and root `.htaccess` deny HTTP access to dumps, tests and version-control metadata. Verified: `403` on `sql/payroll_db_recovery.sql` and `.git/config`.

---

## 9. Your proof — run this in front of them

```powershell
C:\xampp\php\php.exe C:\xampp\master_file_tests\verify_computations.php
```

It **reimplements the entire money path from the stated rules without calling the app's helpers** — working days, daily rate, absences, leave, tardiness, payout-gated overtime, SSS/PhilHealth/Pag-IBIG, the TRAIN brackets, the attendance cap and the zero-net floor — then compares every field against what is stored.

**Current result: `36 checks, 0 mismatches`** across 9 fields × 4 payslips. Exit code 0 = all match.

> Why a second implementation: if the verifier called the same helpers, it would only prove the code agrees with itself.

Other suites worth showing:

```powershell
C:\xampp\php\php.exe C:\xampp\master_file_tests\audit_log_test.php          # 78 / 0
C:\xampp\php\php.exe C:\xampp\master_file_tests\bank_file_test.php          # 212 / 0
C:\xampp\php\php.exe C:\xampp\master_file_tests\me_selfservice_test.php     # 116 / 0
C:\xampp\php\php.exe C:\xampp\master_file_tests\full_payroll_test.php       # overtime, late, loan, 13th month
C:\xampp\php\php.exe C:\xampp\master_file_tests\payroll_approval_test.php   # maker/checker + lock
C:\xampp\php\php.exe C:\xampp\master_file_tests\payroll_void_test.php       # void + reprocess + audit
C:\xampp\php\php.exe C:\xampp\master_file_tests\security_test.php           # CSRF, cookie flags, audit redaction
C:\xampp\php\php.exe C:\xampp\master_file_tests\premium_undertime_test.php   # day-type premium, 2.60 holiday OT, undertime
C:\xampp\php\php.exe C:\xampp\master_file_tests\ot_window_test.php            # approved OT window: early/late in/out + pay cap
C:\xampp\php\php.exe C:\xampp\master_file_tests\money_guard_test.php          # loan void/cap, period overlap, post-lock writes
C:\xampp\php\php.exe C:\xampp\master_file_tests\sod_guard_test.php            # admin role limit, loan Pending, self-approval, salary checker
```

---

## 10. Questions they will ask

**"What is your basis for the deductions?"**
§3. Lead with: *"Three different statutory ceilings"* — that single point explains why the percentages differ and shows you know these are not flat percentages of salary.

**"Why is SSS 3.9% and not 5%?"**
Because SSS is 5% of the **Monthly Salary Credit**, capped at ₱35,000. On a ₱45,000 salary: `5% × (35,000 ÷ 45,000) = 3.9%`.

**"SSS uses a contribution table, not a formula."**
True. Concede it: *"We implemented the straight-line formula; the official table steps the MSC in ₱500 brackets. Above ₱35,000 the results are identical — the bracket lookup is a data change, not a logic change."*

**"Are those rates current?"**
They are the **January 2025** figures under RA 11199, cited from the SSS. Rates change by circular; they are constants so a change is a configuration edit.

**"Why does tax change when someone is absent?"**
Because taxable income is net of attendance deductions and mandatory contributions. An absence lowers the taxable base, so tax falls — that is correct, not a bug.

**"Can one person prepare and approve payroll?"**
No. §2 — the API returns **403** with "Segregation of duties" if the submitter tries to approve. Demonstrate it.

**"What happens if you change something after approval?"**
**409**, "A formal adjustment is required." §8. The adjustment is a **void**: the Approver voids the run with a written reason, the period reopens, and payroll is processed again.

**"The payroll was wrong after it was approved — can you fix it?"**
Yes. `api/payroll_void.php`: Approver-only, mandatory reason (≥10 characters), one transaction, period back to `Open`, and an audit row. A **Released** run is refused (409) because payment is already authorised — that needs a reversing entry.

**"How do you know the figures are right?"**
§9 — a second, independent implementation agrees on all 36 checked values.

**"What about holidays, rest days, night differential?"**
Public holidays in the `holidays` table are **excluded from expected working days** (Art. 94), so a regular holiday never becomes an absence. The day-type engine pays the first 8 hours at **1.30** on a rest day/special holiday and **2.00** on a regular holiday, with overtime at **1.69 / 2.60**. **Night differential** (Art. 86, +10% for 22:00–06:00) is **not** computed. Say that plainly — it is a scope boundary, not an oversight.

---

## 11. Limitations — state these before they find them

| Limitation | Honest framing |
|---|---|
| Night differential is not computed | Art. 86 gives +10% for work between 22:00 and 06:00. Holiday/rest-day premiums are implemented; the night window is the remaining gap |
| Overtime actually worked without approval is not paid | The kiosk refuses to record an unapproved block and payroll only pays Approved/Completed. Legally, work actually rendered is generally compensable; align the exception/approval policy before the defence |
| The kiosk's after-shift fallback has no requested end | The third-scan request has a start but no end, so the window check does not apply to it; the actual block is still measured. Use the portal request for planned OT with a window |
| Overnight OT windows are not window-checked | If `requested_end` is before `requested_start`, the kiosk skips the window check because OT is looked up by `ot_date`; after-midnight OT needs a separate design |
| A holiday that falls on a rest day uses the holiday rate | The compounded “holiday on rest day” rate is not modelled; the holiday rate takes precedence |
| The meal break is assumed, not scanned | Any span over 300 minutes loses 60 minutes; a shift that works through lunch is underpaid and a long OT block that takes a longer break is overpaid |
| SSS uses the straight-line formula, not the official MSC bracket table | Identical above the ₱35,000 cap; the bracket lookup is a data change, not a logic change |
| No hire-date proration | An employee hired mid-period receives a full semi-monthly basic |
| 13th month uses current salary for all 12 months | A mid-year raise is not weighted |
| `salary_rates` table exists but payroll reads `employees.basic_salary` | Redundant; either wire it or remove the table |
| The kiosk `verify` credential check has no rate limiting | It verifies an existing staff password to exit kiosk mode; a lockout/backoff is still outstanding |
| Browser QR pages cannot block OS screenshots | The token expires after 120 seconds and is single-use, so a screenshot is stale after that; a capture inside the window or a second camera still works. True blocking needs a native/managed kiosk (`FLAG_SECURE`). No screenshot-prevention claim is made for the web page |
| The legacy static QR is still accepted | `ALLOW_LEGACY_QR = true` keeps printed badges working during migration; a photograph of a static badge does not expire. Flip it to false once every employee uses the portal token |
| QR tokens are not device-bound | A token can be relayed within its 120-second window; sharing a portal login can mint another. Hardware/device binding or the staffed face check is still needed |
| Kiosk photo/face confirmation is not implemented yet | The kiosk shows the employee name after a scan but does not capture a photo/snapshot or require an operator confirmation. The token expiry is not a substitute for the identity gate |
| Attendance/OT writes are locked only after Approval/Release | A `Submitted` run can still have its source attendance/OT changed while its payslips are frozen; the Submitted lock/recompute is the remaining state fix |
| Supervisor team assignment has no UI | `employees.supervisor_id` must be set by SQL |

---

## 12. Demo script

1. **Login `payroll`** → Employees (contact + bank fields), Payroll Periods (pay date auto-fills: 20th / 5th next month)
2. **Kiosk** → scan or type QR → **clock in → clock out** (the time clock)
3. **Portal on the phone → Attendance → Request overtime** → date, requested window, reason → **the server shows the day type and rate** (e.g. 2026-12-25 = Regular Holiday, 2.60x)
4. **Overtime page as `approver`** → approve; the row shows the reason, requested window and derived day type. (The Payroll Officer can prepare it but gets 403 on approve.)
5. **Kiosk** → **overtime clock-in → overtime clock-out** → hours computed
6. **Portal → Attendance → Request a correction** → submit
7. **Corrections page as `payroll`** → approve → `minutes_late` recomputes
8. **Process payroll** → show a payslip: Basic, Overtime, **Premium Pay**, and the Absences / Late / **Undertime** lines
9. **Try to approve your own run as `payroll`** → **403 Segregation of duties**; log in as `approver` → approves
10. **Edit a deduction on the approved run** → **409, formal adjustment required**
11. **Void the wrong run as `approver`** → period reopens → **process it again** (the void reason is in the Audit Log)
12. **Exports** → bank file / register / remittance
13. **Login `admin`** → Users + **Audit Log**, showing every action above — and no payroll access

---

## 13. File map — where each claim lives

| Claim | File |
|---|---|
| Roles and guards | `api/_bootstrap.php` |
| Working days, daily rate, absences | `api/_payroll_helpers.php` → `computeAttendanceDeductions()` |
| SSS / PhilHealth / Pag-IBIG | `api/_payroll_helpers.php` → `computeSSS()`, `computePhilHealth()`, `computePagIbig()` |
| TRAIN brackets | `api/_payroll_helpers.php` → `computeWithholdingTax()` |
| Tax base, cap, zero-net floor | `api/_payroll_helpers.php` → `computePayslipDeductions()`, `recomputePayslipTotals()` |
| Public holidays excluded from working days | `api/_payroll_helpers.php` → `countHolidaysInRange()`; `sql/009_holidays.sql` |
| Day types, premium pay, day-type OT multiplier | `api/_payroll_helpers.php` → `dayTypeForDate()`, `computePremiumPay()`, `overtimeMultiplierForDayType()`; `sql/013_premium_ot_request_undertime.sql` |
| Undertime deduction | `api/_payroll_helpers.php` → `computeAttendanceDeductions()` |
| OT request reason / window / portal form | `api/overtime_records.php`, `employee_portal/api/me_overtime_requests.php` |
| Taxable / non-taxable allowances | `api/_payroll_helpers.php` → `recomputePayslipTotals()`; `sql/011_allowance_taxability.sql` |
| Payroll assembly, maker/checker, lock | `api/payroll_runs.php` |
| Void + reprocess (the formal adjustment) | `api/payroll_void.php` |
| Salary change (checker-only) | `api/employee_salary.php`; `api/employees.php` |
| Loan activation, payment cap, void reset | `api/loans.php`, `api/payroll_runs.php`, `api/payroll_void.php` |
| Period type whitelist and overlap | `api/payroll_periods.php` |
| Post-lock attendance / OT | `api/attendance.php`, `api/scan_attendance.php`, `api/overtime_records.php` |
| Kiosk state machine | `api/scan_attendance.php` |
| Leave rules, overlap, balance | `api/leave_requests.php`, `api/me_leave_requests.php` |
| Attendance corrections + recompute | `api/attendance_corrections.php` |
| Audit hook | `api/_bootstrap.php` → `respond()` |
| Feature → code map | `FEATURE_CODE_MAP.md` |
| Audit redaction (QR / token / bank / govt numbers) | `api/_bootstrap.php` → `audit_is_secret_key()`; `sql/012_scrub_audit_sensitive.sql` |
| CSRF, session cookie flags, safe errors | `api/_bootstrap.php` (admin) and `employee_portal/api/_bootstrap.php` |
| Short-lived QR tokens | `employee_portal/api/me_qr.php`, `api/scan_attendance.php`, `employee_portal/attendance.html`; `sql/014_qr_tokens.sql` |
| Exports | `api/bank_file.php` |
| Schema | `sql/payroll_db_schema.sql` (structure), `sql/payroll_db_recovery.sql` (structure + data) |
| Migrations 002–014 | `sql/00*.sql` |
| Verification | `C:\xampp\master_file_tests\verify_computations.php` |

---

*Accounts: `payroll`, `approver`, `admin`, `vinchy`, `juan.delacruz`. Change the passwords before presenting — the maker/checker demonstration is worthless if a panelist can log in as both.*
---

## 3a. Detailed statutory citations

| # | Deduction / basis | Law (full title) | Provision | What it fixes | Implemented? |
|---|---|---|---|---|---|
| 1 | **SSS** | **RA 11199** — *Social Security Act of 2018* | Sec. 4, amending Sec. 18 of RA 1161 (as amended by RA 8282) | Total contribution **15%** (employee **5%**, employer 10%); Monthly Salary Credit **₱5,000–₱35,000** from Jan 2025 | Yes |
| 2 | **PhilHealth** | **RA 11223** — *Universal Health Care Act of 2019* | Sec. 10 (premium contributions) | **5%** of monthly basic salary (employee **2.5%**, employer 2.5%); income floor ₱10,000, ceiling ₱100,000 | Yes |
| 3 | **Pag-IBIG** | **RA 9679** — *Home Development Mutual Fund Law of 2009* | Sec. 3 (rate of contribution) | **2%** employee + 2% employer of monthly compensation; **Maximum Fund Salary ₱10,000** | Yes |
| 4 | **Withholding tax** | **RA 10963** — *Tax Reform for Acceleration and Inclusion (TRAIN) Act*, amending **RA 8424** (National Internal Revenue Code) | Sec. 24(A) — individual income tax rates; BIR **Revenue Regulations No. 11-2018** | Progressive rates on annualised taxable compensation; **first ₱250,000 exempt** | Yes |
| 5 | **13th month pay** | **PD 851** — *Requiring All Employers to Pay Their Employees a 13th Month Pay* (1975) | Sec. 1–3 | One-twelfth of annual basic; **first ₱90,000 exempt** under RA 10963, Sec. 32(B)(7)(a) NIRC | Yes — a `13th Month` period run |
| 6 | **Service Incentive Leave** | **PD 442** — *Labor Code of the Philippines* | **Art. 95** | ≥**5 days** paid leave after 1 year of service | Not auto-granted |
| 7 | **Maternity leave** | **RA 11210** — *105-Day Expanded Maternity Leave Law* (2019) | Amends Labor Code Art. 133 | **105 days** paid (60 for miscarriage); +15 for solo parents | Leave type only |
| 8 | **Paternity leave** | **RA 8187** — *Paternity Leave Act of 1996* | Sec. 3 | **7 days** paid, first **4** deliveries of legitimate spouse | Leave type only |
| 9 | **Solo parent leave** | **RA 11861** — *Expanded Solo Parents Welfare Act* (2022) | Sec. 8 | **7 days** (15 for sole caregivers) | Not implemented |
| 10 | **Overtime premium** | **PD 442** — *Labor Code* | **Art. 87** (ordinary day), **Art. 93** (rest day), **Art. 94** (holiday) | **+25%** ordinary; **+30%** rest day; holiday rates | Yes — 1.25 / 1.69 / 2.60 by day type |
| 11 | **Hours of work** | **PD 442** — *Labor Code* | **Arts. 83–85** | Normal hours **8 per day**; lunch break not counted | Yes |
| 12 | **Absences / tardiness** | **PD 442** — *Labor Code*; "no work, no pay" principle | Arts. 97(f) ("wage"), Art. 82; settled jurisprudence | No pay for time not worked | Yes |
| 13 | **Data privacy** | **RA 10173** — *Data Privacy Act of 2012* | Sec. 11–13 (processing, security) | Government IDs, bank accounts are personal data; requires safeguards | Partly |
| 14 | **Electronic records** | **RA 8792** — *E-Commerce Act of 2000* | Sec. 7–11 | Electronic payslips and records are legally recognised | Yes (electronic payslips) |
| 15 | **Holiday pay (non-absence)** | **PD 442** — *Labor Code* | **Art. 94** | A regular holiday is paid even with no work, so it cannot be scored as an absence | Yes — `holidays` table, excluded from expected working days |

### Rate schedule authority — state it this way

**SSS** and **PhilHealth** rates are not fixed by the statute directly; the statutes set a *schedule*, and the agencies implement it by circular. So:

> "RA 11199 set the schedule reaching 15% by 2025; the SSS implements it through its annual contribution circular. RA 11223 sets the 5% premium; PhilHealth issues the premium schedule. Our constants are the **January 2025** figures, taken from the SSS announcement of 7 January 2025."

**Pag-IBIG** and **withholding tax** are direct: the rates appear in RA 9679 and RA 10963 respectively.

### What this system does NOT compute — say it before they ask

| Benefit | Law | Status |
|---|---|---|
| Night shift differential (+10%) | Labor Code **Art. 86** | Not implemented |
| Holiday-on-rest-day compounded premium | Labor Code **Arts. 93–94** | The holiday rate takes precedence; the compounded rate is not modelled |
| De minimis benefits (non-taxable) | BIR RR 11-2018, Sec. 2.78.1 | Modelled as an `allowances.is_taxable = 0` line; the statutory limits are not auto-enforced |
| Separation / retirement pay | Labor Code **Arts. 283–287** | Out of scope |
| SSS sickness / unemployment benefits | RA 11199 | Out of scope |

> Framing: *"The payslip covers the semi-monthly wage computation, the four mandatory contributions plus withholding tax, and the day-type premiums for rest days and holidays. Night differential, holiday-on-rest-day compounding, and statutory benefits like separation pay are outside this module."*
