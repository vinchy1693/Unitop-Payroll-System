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
| **Payroll Officer** (maker) | Employees, attendance, leave, overtime, loans, payroll periods/runs, payslips, exports | Approve payroll; approve loans; open Users or Audit Log |
| **Approver** (checker) | Approve / return / release payroll; approve loans; approve team requests; view reports | **Edit any amount**; run payroll |
| **Administrator** (technical) | Create/disable users, assign roles, Audit Log | **Any payroll or salary data** |
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

---

## 3. Legal basis of every deduction

> Say this: *"Every deduction is traceable to a statute except tardiness and absence, which follow the no-work-no-pay principle. Each is computed on the base its law specifies — which is why the effective percentages differ."*

| Deduction | Law | Rate (employee share) | Base | Ceiling / floor |
|---|---|---|---|---|
| **SSS** | RA 11199, Social Security Act of 2018 | **5%** | Monthly Salary Credit | MSC **₱5,000 – ₱35,000** (Jan 2025 tranche) |
| **PhilHealth** | RA 11223, Universal Health Care Act | **2.5%** | Monthly basic salary | floor **₱10,000**, ceiling **₱100,000** |
| **Pag-IBIG** | RA 9679, HDMF Law of 2009 | **2%** | Monthly compensation | **Max Fund Salary ₱10,000** |
| **Withholding tax** | RA 10963 (TRAIN) + BIR RR 11-2018 | progressive | Annualised **taxable** income | ₱250,000 exempt |
| **Tardiness / absence** | Labor Code "no work, no pay" + company policy | — | daily rate | capped at remaining pay |
| **Overtime premium** | Labor Code Art. 87 | **+25%** of hourly rate | hourly rate | — |
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
| 1 | Working days `W` | Mon–Sat in the period | **13** (Oct 4 & 11 are Sundays) |
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

### Overtime
```php
// api/payroll_runs.php
$hourlyRate = $att["daily_rate"] / 8.0;
$otPay += (float) $ot["hours"] * (float) $ot["rate_multiplier"] * $hourlyRate;
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

> **Approval gates the work, not just the payment.** Hours are measured only from the approved block's `time_in` to `time_out` — staying late without approval earns nothing.

Every employee may also **type** the QR value ("Scanner not working?"), which is the practical path on a phone: browsers block camera access outside HTTPS/`localhost`.

A **Demo mode** strip (collapsed by default, red when on) lets a demonstration supply a date and time; both are validated, and `ALLOW_DEMO_SCANS` in `api/_bootstrap.php` disables it entirely for a real deployment.

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

Once `Approved` or `Released`, any edit to payslips, deductions, allowances or loan payments is refused:

```php
const PAYROLL_LOCKED_RUN_STATUSES = ["Approved", "Released"];
// … 409: "Payroll run #N is Approved and locked. … A formal adjustment is required."
```

### Audit trail

One hook in `api/_bootstrap.php`, inside `respond()`:

```php
// every non-GET request writes exactly one row:
//   actor · script · method · HTTP status · success · message · IP · target · redacted body
```

> Placed in `respond()` rather than in 20 endpoints, so **every mutating request is captured automatically, including endpoints written later.** It logs failures too — a rejected 403 from an employee probing payroll is itself evidence. Passwords, tokens and CSRF values are replaced with `***`; GETs are never logged.

### Maker cannot approve their own payroll

Enforced **in the API** (see §2), not by hiding a button — a crafted request fails the same way a click would.

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
**409**, "A formal adjustment is required." §8.

**"How do you know the figures are right?"**
§9 — a second, independent implementation agrees on all 36 checked values.

**"What about holidays, rest days, night differential?"**
Labor Code Arts. 86–94. **Not implemented**: overtime is priced flat at 1.25 because the system has no holiday calendar. Say this plainly — it is a scope boundary, not an oversight.

---

## 11. Limitations — state these before they find them

| Limitation | Honest framing |
|---|---|
| Overtime has no rest-day / holiday premium | Needs a holiday calendar; flat 1.25 is the ordinary-day rate |
| Allowances added after a payslip do not affect its tax | Known gap; the fix is recomputing tax when a line is added |
| No hire-date proration | An employee hired mid-period receives a full semi-monthly basic |
| 13th month uses current salary for all 12 months | A mid-year raise is not weighted |
| `salary_rates` table exists but payroll reads `employees.basic_salary` | Redundant; either wire it or remove it |
| No CSRF tokens; session cookies lack HttpOnly/SameSite | Hardening outstanding |
| Attendance writes do not check the payroll lock | Only the corrections endpoint enforces it; the helper exists and needs to be called |
| Supervisor team assignment has no UI | `employees.supervisor_id` must be set by SQL |

---

## 12. Demo script

1. **Login `payroll`** → Employees (contact + bank fields), Payroll Periods (pay date auto-fills: 20th / 5th next month)
2. **Kiosk** → scan or type QR → clock in → clock out → **scan again = overtime request** → **scan again = refused, "still awaiting approval"** ← *the strongest single moment*
3. **Overtime page as `payroll`** → approve
4. **Scan** → overtime starts → **scan** → hours computed
5. **Portal on the phone** → Attendance → **Request a correction** → submit
6. **Corrections page as `payroll`** → approve → `minutes_late` recomputes
7. **Process payroll** → show a payslip, and that the deduction lines **sum to the total**
8. **Try to approve your own run as `payroll`** → **403 Segregation of duties**; log in as `approver` → approves
9. **Edit a deduction on the approved run** → **409, formal adjustment required**
10. **Exports** → bank file / register / remittance
11. **Login `admin`** → Users + **Audit Log**, showing every action above — and no payroll access

---

## 13. File map — where each claim lives

| Claim | File |
|---|---|
| Roles and guards | `api/_bootstrap.php` |
| Working days, daily rate, absences | `api/_payroll_helpers.php` → `computeAttendanceDeductions()` |
| SSS / PhilHealth / Pag-IBIG | `api/_payroll_helpers.php` → `computeSSS()`, `computePhilHealth()`, `computePagIbig()` |
| TRAIN brackets | `api/_payroll_helpers.php` → `computeWithholdingTax()` |
| Tax base, cap, zero-net floor | `api/_payroll_helpers.php` → `computePayslipDeductions()`, `recomputePayslipTotals()` |
| Payroll assembly, maker/checker, lock | `api/payroll_runs.php` |
| Kiosk state machine | `api/scan_attendance.php` |
| Leave rules, overlap, balance | `api/leave_requests.php`, `api/me_leave_requests.php` |
| Attendance corrections + recompute | `api/attendance_corrections.php` |
| Audit hook | `api/_bootstrap.php` → `respond()` |
| Exports | `api/bank_file.php` |
| Schema | `sql/payroll_db_schema.sql` (structure), `sql/payroll_db_recovery.sql` (structure + data) |
| Migrations 001–008 | `sql/00*.sql` |
| Verification | `C:\xampp\master_file_tests\verify_computations.php` |

---

*Accounts: `payroll`, `approver`, `admin`, `vinchy`, `juan.delacruz`. Change the passwords before presenting — the maker/checker demonstration is worthless if a panelist can log in as both.*
