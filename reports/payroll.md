# Payroll computation correctness audit

**Target:** `C:\xampp\htdocs\master_file_module_api` — PHP 8.2.12 (`C:\xampp\php\php.exe`), MariaDB `payroll_db` (root / no password)
**Mode:** READ-ONLY. No file, schema or row was created, updated or deleted. Only `SELECT`s were run, plus `php.exe` invocations of the pure helper functions in `api/_payroll_helpers.php` (that file only defines functions/constants — including it has no side effects) and a scratch script in `%TEMP%`, outside the project.
**Date of audit:** live DB state as found: 4 employees, 1 payroll period (`period_id` 1, 2026-10-01 → 2026-10-15, Semi-monthly, `Processed`), 1 run (`run_id` 1, `Submitted`), 4 payslips, 12 attendance rows, 1 completed overtime record, 1 active loan with 1 payment.

Files read in full: `api/_payroll_helpers.php`, `api/payroll_runs.php`, `api/payslips.php`, `api/payroll_periods.php`, `api/overtime_records.php`, `api/loans.php`, `api/deductions.php`, `api/allowances.php`, `api/attendance.php`. Cross-checked against `api/attendance_corrections.php`, `api/scan_attendance.php`, `api/leave_requests.php`, `api/cash_disbursements.php`, `api/salary_rates.php`, `api/ledger.php`, `api/reports.php`, `api/bank_file.php`, `api/_bootstrap.php`, `assets/js/payslips.js`, `DEFENSE_PAYROLL_SAMPLE.md`, and the live `information_schema`.

---

## 0. Baseline: what I re-computed and confirmed correct

These were verified to the cent against the four live payslips, so the rest of the audit runs against a known-good baseline.

| # | Rule | Verdict | Evidence |
|---|---|---|---|
| 1 | Work week Mon–Sat, Sunday only off | **Correct and consistent** | `isWorkingDay()` (`_payroll_helpers.php:20-22`, ISO `N <= 6`), `workDaySql()` (`:38-45`, `DAYOFWEEK BETWEEN 2 AND 7`), `countWorkingDays()` (`:24-34`), `attendance.php:119,172,194,221` and `ledger.php:119` all consume the same helper. `countWorkingDays('2026-10-01','2026-10-15') = 13`, `('2026-11-16','2026-11-30') = 13`, Saturday-only = 1, Sunday-only = 0. |
| 2 | Inclusive date ranges | **Correct** | `countWorkingDays` returns 0 when `from > to` and iterates `cursor < to+1`; leave overlap `NOT (end < start OR start > end)` (`_payroll_helpers.php:217`); period overlap same shape (`payroll_periods.php:52`); `attendanceDateLock` uses `? BETWEEN start AND end` (`:366`). A period starting on a Saturday or ending on a Sunday counts exactly the right days. |
| 3 | `basic_pay = monthly / 2` | **Correct** | `payroll_runs.php:336`, live: 60000 → 30000.00, 45000 → 22500.00, 10000 → 5000.00, 75000 → 37500.00. |
| 4 | `daily_rate = basic / working days in period` | **Correct** | `_payroll_helpers.php:192-193` with `$semiBasic` passed from `payroll_runs.php:343-350`; 30000/13 = 2307.692307…, reported 2307.69. |
| 5 | `absent = max(0, W − present − leave)`; present/leave de-duplicated | **Correct** | `_payroll_helpers.php:197-255`. A worked day is removed from the leave set (`:234-244`), so a day cannot be both present and leave. Live: emp 4 present 12, leave 0, absent 1. |
| 6 | `absence = absent × daily_rate`, `late = minutes × daily_rate/480` | **Correct** | Live emp 4: `1 × 2307.692307 = 2307.69`; `11 × 2307.692307/480 = 52.88`. Both stored exactly. |
| 7 | OT gated on approved/completed + hours > 0 + both clock times | **Correct** | `payroll_runs.php:304-311` is byte-for-byte the same predicate as `overtime_records.php:52-54` (`is_payable`), so the queue and the engine agree. |
| 8 | SSS 5 % of MSC 5 000–35 000; PhilHealth 2.5 % of 10 000–100 000; Pag-IBIG 2 % of min(salary,10 000); all monthly, halved | **Correct** | `_payroll_helpers.php:48-63`, `:89-94`. Live 60000 → 875.00 / 750.00 / 100.00; 10000 → 250.00 / 125.00 / 100.00; 45000 → 875.00 / 562.50 / 100.00. |
| 9 | TRAIN brackets annualised ×24, rounded once at the end | **Correct** | `_payroll_helpers.php:66-77`. Bracket joins are continuous (250 000→0, 400 000→22 500, 800 000→102 500, 2 M→402 500, 8 M→2 202 500). Live emp 4: taxable 25 914.43 → 2 787.05 stored. |
| 10 | Attendance deductions capped so net ≥ 0 | **Works for attendance** | `payroll_runs.php:371-373` + `_payroll_helpers.php:153-156`. Live payslips 2/3/4 each have `net_pay = 0.00` and `total_deductions = gross_pay` exactly (22 500 / 5 000 / 37 500) — no negative net, no overshoot. |
| 11 | No divide-by-zero on a zero-working-day period | **Correct** | `_payroll_helpers.php:193` guards `$expectedDays > 0`; a Sunday-only period yields `daily_rate 0`, not `NaN`/`INF`. |

The defects below are therefore *not* in the daily-rate/absence/late arithmetic — that layer is sound. They concentrate in the **tax base**, the **deduction cap's interaction with loans/hand-entered lines**, **input validation**, and **lock/period lifecycle**.

---

## 1. HIGH — Withholding tax is computed on `basic_pay` only: overtime pay and allowances never enter the taxable base (systematic under-withholding)

**Where:** `api/payroll_runs.php:366` (call) + `api/_payroll_helpers.php:96` (base) + `api/_payroll_helpers.php:158-162` (no recompute afterwards)

```php
// payroll_runs.php:362-367
$otPay = round($otPay, 2);                                  // included in gross …
$calc  = computePayslipDeductions($monthly, $basic, $attendanceDeduction);
//                                                                   ^^^^^^ basic only, $otPay missing
// _payroll_helpers.php:96
$semiTaxable = round($semiBasic - $preTaxDeductions - $sssEE - $phEE - $piEE, 2);
```

**What is wrong:** the stated rule is `taxable = gross − attendance deductions − statutory`. The code uses `basic − attendance − statutory`, so overtime is taxed at zero and every allowance is treated as tax-free. `gross_pay` is then written with the OT included (`:381`), so the payslip's own gross contradicts its tax line. Three separate manifestations:

**(a) At run time — overtime escapes tax.** The project's own defence sample (`DEFENSE_PAYROLL_SAMPLE.md` §4) has basic 30 000.00, attendance deductions 4 831.73, OT 1 831.73, statutory 1 725.00:

```
per rule:  taxable = 31 831.73 − 4 831.73 − 1 725.00 = 25 275.00
           annual  = 606 600.00 → 22 500 + (606 600 − 400 000) × 0.20 = 63 820.00 → /24 = 2 659.17
actual:    taxable = 30 000.00 − 4 831.73 − 1 725.00 = 23 443.27 → tax = 2 292.82   (verified with computeWithholdingTax())
shortfall 366.35 per payslip ≈ 732.70 per month for one employee
```

The live payslip 1 shows the same pattern: stored `withholding_tax 2 787.05` is exactly `computeWithholdingTax(25 914.43)`, i.e. it was computed without the 721.15 overtime.

**(b) An allowance added after the run is never taxed.** `allowances.php:49` only calls `recomputePayslipTotals()`, which re-adds the amount to gross and leaves `govt_contributions` untouched. Payslip 1 + a taxable 10 000 allowance:

```
gross becomes 40 721.15, tax stays 2 787.05
per rule: taxable = 40 721.15 − 2 360.57 − 1 725.00 = 36 635.58 → 5 096.40   (computeWithholdingTax(36635.58))
under-withheld 2 309.35 on one allowance
```

**(c) Editing the payslip does not re-derive the tax either** (`payslips.php:40-42`). Setting payslip 1 to `basic_pay = 30000` (from 15000) doubles taxable income and leaves the old tax.

**Minimal fix:** compute the taxable base from gross inside `computePayslipDeductions` — pass `$basic + $otPay` (plus the allowance total when `recomputePayslipTotals()` runs) instead of `$basic`, and move the withholding-tax calculation into `recomputePayslipTotals()` so it always follows the earnings it observes:

```php
// _payroll_helpers.php, inside recomputePayslipTotals(), after $gross is known:
$monthly = (float) $pdo->prepare("SELECT e.basic_salary FROM payslips ps JOIN employees e USING(employee_id) WHERE ps.payslip_id = ?")…
$taxable = max(0.0, $gross - $attendanceLines - $sssEE - $phEE - $piEE);
$tax     = computeWithholdingTax(round($taxable, 2));
```
If non-taxable allowances must stay exempt, add an `is_taxable` flag to `allowances` rather than omitting the whole table.

---

## 2. HIGH — Loan amortisation is never capped by available pay: the loan is credited in full while `total_deductions` is silently capped to gross

**Where:** `api/payroll_runs.php:419-442` (loan insert) together with `api/_payroll_helpers.php:153-156` (the cap)

```php
// payroll_runs.php:427-430 — capped only by the remaining principal
$amountDue = round((float) $loan["monthly_amortization"] / $loanDivisor, 2);
if ($amountDue > $remaining) $amountDue = $remaining;
$loanPayStmt->execute([$loan["loan_id"], $payslipId, $amountDue]);   // full amount recorded
$dedStmt->execute([$payslipId, "Loan: " . $loan["loan_type"], $amountDue]);
...
recomputePayslipTotals($pdo, $payslipId);   // this is where the excess is thrown away
```

`$room` (`:371`) protects *attendance* deductions only; loan amortisations are added afterwards with no reference to the remaining net. `recomputePayslipTotals()` then notices `net < 0`, sets `total_deductions = gross` and `net = 0`, and — per its own comment (`:152`) — does **not** carry the excess forward. But `loan_payments.amount_paid` was already written with the full amount, so the loan balance is reduced by money that was never withheld.

**Worked example** (employee on ₱12 000/month, semi-monthly basic ₱6 000, fully absent period, loan principal ₱24 000 at ₱12 000/month amortisation):

```
basic 6 000.00, W 13, absent 13, daily_rate 461.538461…
statutory (verified via computePayslipDeductions(12000, 6000, 6000)): SSS 300.00 PH 150.00 PI 100.00 = 550.00, tax 0.00
attendance deduction 6 000.00 → capped to room = 6 000.00 − 550.00          = 5 450.00
loan amountDue = 12 000.00 / 2 = 6 000.00  (≤ remaining 24 000.00)          = 6 000.00
deduction lines: 550.00 (govt) + 5 450.00 (absence) + 6 000.00 (loan)       = 12 000.00
gross = 6 000.00 → net = −6 000.00 → capped: total_deductions = 6 000.00, net = 0.00

Money actually withheld = 6 000.00 of which 550 statutory + 5 450 absence = 6 000 → 0.00 left for the loan.
But loan_payments records 6 000.00 → the borrower's balance drops by 6 000.00 he never paid.
```

That 6 000.00 is unrecoverable: the run is a one-shot snapshot (finding 6) and the cap explicitly discards the excess.

**Minimal fix:** cap the loan by what is actually left, and record only that:

```php
$recovered = round((float) $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deductions WHERE payslip_id = ?")
                        ->execute([$payslipId]) ?: 0, 2);   // or keep a running $used variable
$available = max(0.0, round($basic + $otPay + $allowTotal - $statutory - $absenceDeduction - $lateDeduction - $used, 2));
$amountDue = min($amountDue, $available);
if ($amountDue <= 0) continue;                 // skip entirely; leave the loan Active
```
and mark the shortfall explicitly on the payslip (a "Loan (partial, X deferred)" line) instead of dropping it.

---

## 3. MEDIUM — `total_deductions` stops equalling the sum of the deduction lines; the printed payslip and the register then disagree with the total

**Where:** `api/_payroll_helpers.php:153-156`; consequence visible at `assets/js/payslips.js:198-206` (lines vs "Total Deductions") and `api/bank_file.php:325-326` (which asserts the equality that no longer holds)

```php
if ($net < 0) { $totalDed = $gross; $net = 0.0; }   // total only; no line is adjusted
```

**Reproducible with the existing data.** Payslip 3 (employee 8) already sits exactly on the cap: gross 5 000.00, `total_deductions` 5 000.00, net 0.00, lines = 475.00 statutory + 4 525.00 absence. Now add any extra deduction through the normal UI path (`deductions.php:26-56`, and `payslips.js:203-205` "Add Deduction" offers it):

```
POST deductions.php {payslip_id: 3, deduction_type: "Uniform", amount: 200}
→ recompute: gross 5 000.00, govt 475.00, extra 4 525.00 + 200.00 = 4 725.00
   totalDed = 5 200.00, net = −200.00 → capped: total_deductions = 5 000.00, net = 0.00

payslip renders:  SSS 250.00 + PhilHealth 125.00 + Pag-IBIG 100.00
                + "Absences (13 day(s), capped…)" 4 525.00 + "Uniform" 200.00
                = 5 200.00 of visible lines, but the "Total Deductions" block prints 5 000.00
```

The same 200.00 discrepancy appears in the register export: `other_deductions` (summed from `deductions`) is 4 725.00, `total_deductions` is 5 000.00, and `net_pay` was derived from 5 000.00, so the CSV columns only reconcile by chance. `bank_file.php:323-326` states the opposite as a guarantee.

**Minimal fix:** make `recomputePayslipTotals()` either (a) refuse to persist an over-capped state and return an error listing the excess, or (b) record the shortfall as its own row — e.g. `INSERT INTO deductions (…, 'Unrecovered deduction (deferred)', $excess)` with a negative sign — so `total_deductions = SUM(lines)` always holds. Option (b) also fixes finding 2's reporting half. At minimum, drop the false claim in the comment at `bank_file.php:325`.

---

## 4. MEDIUM — `overtime_pay` is not validated: a negative value produces a negative gross and a negative `total_deductions`

**Where:** `api/payslips.php:17-25` and `:36`

```php
$overtime = (float) ($in["overtime_pay"] ?? 0);
if (array_key_exists("basic_pay", $in) && $basic < 0) { respond(false, "basic_pay cannot be negative.", …); }
// no equivalent test for $overtime
$newOt = array_key_exists("overtime_pay", $in) ? round($overtime, 2) : (float) $current["overtime_pay"];
```

**Worked example** on live payslip 1 (basic 30 000.00, statutory 4 512.05, extra deductions 2 860.57, so `gross` normally 30 721.15):

```
PUT payslips.php {payslip_id: 1, overtime_pay: -40000}
gross    = round(30 000.00 + (−40 000.00) + 0, 2) = −10 000.00
govtDed  = 875.00 + 750.00 + 100.00 + 2 787.05   =   4 512.05
extraDed = 2 307.69 + 52.88 + 500.00             =   2 860.57
totalDed = 7 372.62 ; net = −17 372.62 < 0 → capped: total_deductions = −10 000.00, net_pay = 0.00

stored payslip: gross_pay −10 000.00, total_deductions −10 000.00, net_pay 0.00
```

`net_pay` stays 0 (so the bank file cannot pay a negative amount), but every aggregate that sums `gross_pay`/`total_deductions` is corrupted: payroll register, department cost, dashboard totals, and the printed payslip (which displays "Gross Pay −10,000.00 / Total Deductions −10,000.00").

**Minimal fix:** reject negatives symmetrically, and reject values that exceed a sanity bound:

```php
if (array_key_exists("overtime_pay", $in) && $overtime < 0) {
    respond(false, "overtime_pay cannot be negative.", null, 422);
}
```
Also validate `basic_pay` is numeric (`is_numeric`) rather than relying on `(float)` coercion — `"abc"` currently becomes `0.00` and passes.

---

## 5. MEDIUM — The payroll lock is not enforced on the source data: attendance, overtime and leave for an Approved/Released run stay fully editable

**Where:** `api/attendance.php:318` (POST), `:373` (PATCH), `:419` (PUT), `:467` (DELETE); `api/overtime_records.php:182` (POST), `:222` (PUT), `:252` (PATCH), `:296` (DELETE); `api/leave_requests.php:171` (PATCH)

Only `attendance_corrections.php:361` consults the lock:

```php
$lock = attendanceDateLock($pdo, $workDate);     // corrections: 409 on a locked date
```

`attendance.php`, `overtime_records.php` and `leave_requests.php` never call `attendanceDateLock()` / `assertAttendanceDateUnlocked()` / `assertPayslipUnlocked()`. `assertPayrollRunUnlocked()` (`_payroll_helpers.php:288`) has **zero callers** anywhere in `api/`.

**What is wrong:** for the same date inside a Released run, the correction queue answers `409 … locked. A formal adjustment is required.` while `PUT attendance.php` answers `200 "Attendance updated."`. Concrete: period 2026-10-01…15, run 1 Released. Approve a correction for employee 4 / 2026-10-08 → 409. Then `PUT attendance.php {attendance_id: 8, employee_id: 4, work_date: "2026-10-08", time_in: "09:06", time_out: "17:00"}` → 200, `minutes_late` rewritten 6 → 66.

This cannot retroactively change the stored `net_pay` (nothing recomputes a payslip — see finding 6), so it is not a direct money mutation, but it destroys the property the lock exists for: the payslip no longer reconciles to its inputs, and the 11-minute late deduction on the payslip (52.88) can no longer be reproduced from the attendance table. `DELETE overtime_records.php?id=7` on a Released run removes the only evidence for the 721.15 overtime that was paid.

**Minimal fix:** add the same guard already used by corrections to every mutation that touches a payroll-relevant date:

```php
// attendance.php POST / PATCH / PUT / DELETE, before writing (PUT uses both the old and the new date):
assertAttendanceDateUnlocked($pdo, $workDay, "Attendance for $workDay");
assertAttendanceDateUnlocked($pdo, $oldWorkDate, "Attendance for $oldWorkDate");   // when work_date changes
// overtime_records.php writes: resolve ot_date (or ot_date being newly set) and call the same helper
// leave_requests.php PATCH approve: call it for start_date … end_date, or refuse when any day is locked
```

---

## 6. MEDIUM — A processed run can never be recalculated or voided, so anything approved after the run is lost permanently

**Where:** `api/payroll_periods.php:88-90` and `:115-117` (both refuse a `Processed` period); `api/payroll_runs.php:202-204` (only an `Open` period can be processed); `api/payroll_runs.php` has **no** `DELETE`/void/cancel action at all.

**What is wrong:** attendance, leave and overtime are legitimate only after approval, and approval routinely happens after the payroll cut-off. Once the run is created, the period is `Processed` and there is no code path back to `Open` (the UI cannot do it either). A late-approved leave or overtime is therefore never paid and there is no reprocess/void endpoint:

```
Run for 2026-10-01…15 processed on 2026-10-16. OT record for 2026-10-14 (2.00 h × 1.25) is still Pending.
It is approved on 2026-10-17 → payroll_runs.php POST refuses ("Only Open periods can be processed",
period = Processed) and payroll_periods.php PUT/PATCH refuse ("Cannot edit/change status of a processed
period"). The employee is short 2.00 × 1.25 × (2307.69/8) = 721.15 for ever.
```

The only remedy left is a manual `PUT payslips.php {overtime_pay: 721.15}`, which (per finding 1) leaves the withholding tax computed on the old figures and (per finding 3) does not touch the deduction lines. The same gap applies in the other direction: a run whose payslips are demonstrably wrong cannot be withdrawn — `Submitted → Approved|Returned` and `Returned → Submitted|Approved` are the only transitions (`payroll_runs.php:119-173`).

**Minimal fix:** add a `void`/`cancel` transition for a run that is not yet `Released` (delete its payslips' dependent rows and set the period back to `Open`), or add a `reprocess` action that recreates the payslips for the period while the run is `Returned`. At minimum, have `payroll_periods.php` allow `Processed → Open` when the only run on the period is `Returned`.

---

## 7. MEDIUM — Leave balances are charged Mon–Fri while payroll excuses Mon–Sat: a Saturday inside an approved leave is paid but not charged

**Where:** `api/leave_requests.php:18-29` (`if ($dow <= 5)` — Mon–Fri) and `:218-220` (balance deduction), versus `api/_payroll_helpers.php:20-22,227` (`isWorkingDay` — Mon–Sat, used to excuse leave days).

**What is wrong:** the payroll work week is Mon–Sat, so a Saturday covered by approved paid leave is a paid day off, but the balance is only debited for the Mon–Fri part. Worked example — approve leave for Wed 2026-11-18 → Sat 2026-11-21 for an employee with 5 remaining days:

```
countBusinessDays("2026-11-18","2026-11-21") = Wed, Thu, Fri = 3   → balance 5.00 → 2.00
payroll excuses 2026-11-18, 19, 20, 21                            = 4 paid days
→ 4 paid days for 3 credits; repeat 5 times → 15 credits consumed, 20 days paid
```

`DEFENSE_PAYROLL_SAMPLE.md` §8.6 documents this as a deliberate choice ("leave balances are still counted Mon–Fri"), but it is inconsistent with the work week that pays the day, and it is the same class of defect the file's own header warns about elsewhere.

**Minimal fix:** use `countWorkingDays()` from `_payroll_helpers.php` (the Mon–Sat definition) in `countBusinessDays()`, or delete `countBusinessDays()` and call the helper — the leave endpoint already has no reason to define a second calendar.

---

## 8. MEDIUM — Period overlap is checked per `period_type`, and the regular run branch pays `monthly / 2` for *any* non-13th-Month type

**Where:** `api/payroll_periods.php:50-57` and `api/payroll_runs.php:212,336`

```php
// payroll_periods.php:52 — only same-type overlaps are refused
"SELECT COUNT(*) FROM payroll_periods WHERE period_type = ? AND NOT (end_date < ? OR start_date > ?)"
// payroll_runs.php:212 / :336
if ($period["period_type"] === "13th Month") { … }        // everything else falls through
$basic = round($monthly / 2, 2);                          // … and is paid as half a month
```

**What is wrong:** two periods of different types may cover the same days, both can be `Open`, and both process into separate runs. Nothing in `payroll_runs.php` checks that the employee has not already been paid for those days. Concrete: create `2026-12-01 … 2026-12-31` with `period_type = "Monthly"` alongside the two semi-monthly periods:

```
Semi-monthly 12-01…15 → basic 60000/2 = 30 000.00 (13 working days)
Semi-monthly 12-16…31 → basic 60000/2 = 30 000.00
"Monthly"    12-01…31 → basic 60000/2 = 30 000.00 over 27 working days   ← third payment, same days
total 90 000.00 in December for a 60 000.00 salary
```

The UI (`payroll_periods.html:65-67`) only offers `Semi-monthly` and `13th Month`, but the API accepts an arbitrary `period_type` string and the run branch never validates it, so the payment rule silently becomes "half a month for whatever length period you name".

**Minimal fix:** make the overlap check type-independent (`WHERE NOT (end_date < ? OR start_date > ?)`), and in `payroll_runs.php` reject a `period_type` other than `Semi-monthly`/`13th Month` (or add an explicit `Monthly` branch that pays `monthly`, not `monthly/2`).

---

## 9. LOW — 13th month: current salary applied to the whole year (salary history and absences ignored), and a flat 20 % tax over ₱90 000

**Where:** `api/_payroll_helpers.php:399-438`

```php
$monthly = (float) $emp["basic_salary"];                 // :408  today's rate, not the year's rates
$amount  = round($monthly * $months / 12.0, 2);          // :427  no absence/undertime adjustment
$tax     = round(max(0.0, $amount - 90000.0) * 0.20, 2); // :429  flat 20 %
```

**What is wrong / worked example:** an employee hired 2025-01-01 on 20 000.00 whose salary rose to 30 000.00 on 2026-07-01:

```
13th month per DOLE rule = (6 × 20 000 + 6 × 30 000) / 12 = 25 000.00
code                     = 30 000.00 × 12 / 12            = 30 000.00   (overstated 5 000.00)
tax                      = (30 000 − 90 000) → 0 … at higher amounts the flat 20 % also departs from
                           the TRAIN marginal schedule the rest of the payroll uses
```

The same overstatement occurs when the year contains unpaid absences: they reduce `basic_pay` in every semi-monthly payslip but never reduce the 13th month. Note also that `employees.basic_salary` is the *only* salary source in the system — `salary_rates` (with `effective_date`) is written by `api/salary_rates.php` but read by no payroll code at all (see finding 15).

**Minimal fix:** drive the 13th month from the payslips actually processed in the year (`SUM(total_deductions? / basic_pay) / 12` across that employee's payslips for the year) rather than from the current salary, and apply `computeWithholdingTax()`-style marginal treatment (or state the flat-20 % policy explicitly in the report).

---

## 10. LOW — The 300-minute lunch rule has a 59-minute cliff and ignores when the lunch window actually falls (no money impact)

**Where:** `api/attendance.php:8-9,26-28`; duplicated verbatim in `api/scan_attendance.php:136` and `api/attendance_corrections.php:86-88`

```php
const LUNCH_THRESHOLD_MINUTES = 300; const LUNCH_DEDUCTION_MINUTES = 60;
$netMinutes = $totalMinutes > LUNCH_THRESHOLD_MINUTES ? $totalMinutes - 60 : $totalMinutes;
```

**What is wrong:** one extra minute of span removes a full hour, so a longer shift is paid less than a shorter one, and the hour is deducted even when the span cannot contain lunch:

```
in 08:00, out 13:00 → 300 min → 5.00 h
in 08:00, out 13:01 → 301 min → 241 min → 4.02 h     (−0.98 h, i.e. 59 minutes lost for +1 minute)
in 13:00, out 18:01 → 301 min → 4.02 h               (no lunch possible, still docked 60 min)
```

**Money impact: none.** Payroll never reads `hours_worked`; it uses presence and `minutes_late` only (`_payroll_helpers.php:197-206`), and `minutes_late` is derived from `time_in` alone. This is a display/reporting defect only — but it is a real one, and the same constant is copy-pasted in three files, so a change must be made in three places.

**Minimal fix:** only deduct lunch when the span crosses the lunch window (e.g. `time_in <= 12:00 && time_out >= 13:00`), and move the two constants and the computation into `_payroll_helpers.php` so `attendance.php`, `scan_attendance.php` and `attendance_corrections.php` share one definition.

---

## 11. LOW — Present days are counted with `COUNT(*)`, but `attendance` has no unique `(employee_id, work_date)` key

**Where:** `api/_payroll_helpers.php:197-205` (`COUNT(*) AS days`), `api/attendance.php:437-446` (PUT can rewrite `work_date` onto an existing date with no uniqueness check), schema: `attendance` has only `PRIMARY` and `employee_id` indexes.

**What is wrong / worked example:** two rows for the same working day count as two present days, which then cancels a genuine absence:

```
W = 13, employee actually attended 12 distinct days + 1 duplicate row for 2026-10-08 → present = 13
absent = max(0, 13 − 13 − 0) = 0   instead of 1  →  absence deduction 2307.69 lost
```

(The other three write paths are individually guarded against duplicates — `attendance.php:329-333`, `scan_attendance.php:88-95`, `attendance_corrections.php:388-397` — but the guard is per-endpoint, not in the schema, and `PUT` bypasses it. There were 0 duplicate groups in the current data.)

**Minimal fix:** `ALTER TABLE attendance ADD UNIQUE KEY uq_attendance_emp_date (employee_id, work_date);` and count with `COUNT(DISTINCT work_date)` as belt-and-braces.

---

## 12. LOW — The attendance screen and the payroll engine disagree about absences (approved leave is invisible to the screen), and "Undertime" is never charged

**Where:** `api/attendance.php:245` (`$r["absences"] = max(0, $expectedDays - $r["days_present"])`) and `:147-165` (detail walk marks every recordless working day "Absent"); the label at `payroll_runs.php:405-412` says "Late/Undertime".

**What is wrong:** the summary/detail never reads `leave_requests`, so approved paid leave shows as an absence the payslip does not charge. `DEFENSE_PAYROLL_SAMPLE.md` §6/§9 already documents the inconsistency for the 2026-11-16 sample: the screen says **4 absent**, payroll charges **2**. A user reconciling the payslip against the attendance screen sees a 2-day (₱4 615.38 in that sample) discrepancy with no way to explain it in the UI. Separately, the deduction line is named "Late/Undertime" but `minutes_late` only measures arrival; leaving at 12:00 after an 08:00 clock-in is charged nothing.

**Minimal fix:** have the summary/detail query approved paid leave (the same predicate as `_payroll_helpers.php:212-219`) and label those days "Leave", and either rename the line to "Late" or compute an `undertime_minutes` from `hours_worked`.

---

## 13. LOW — Removing a loan payment does not reverse the payslip deduction, so the next run charges it twice

**Where:** `api/loans.php:280-304`

```php
assertLoanPaymentUnlocked($pdo, $paymentId, "This loan payment");   // good: lock is honoured
$pdo->prepare("DELETE FROM loan_payments WHERE payment_id = ?")->execute([$paymentId]);
// … re-opens the loan if no longer fully paid …
// nothing removes the matching `deductions` row, nothing calls recomputePayslipTotals()
```

**What is wrong:** the loan balance is restored (the loan goes back to `Active`) and the loan is amortised again next period, but the employee's already-recorded `Loan: …` deduction and the payslip's `total_deductions` are untouched — so the same amortisation is withheld twice with no refund line. Concrete: payslip 1 deducted 500.00 (`deduction_id` 3, `loan_payments` id 1). Delete payment 1 → loan 3 goes back to `Active` with `remaining 4500.00`; the next run deducts 500.00 again; the employee has now paid 1000.00 for 500.00 of recorded amortisation.

**Minimal fix:** inside the same transaction, delete the matching `deductions` row (or insert a reversing negative line), call `recomputePayslipTotals()`, and only re-open the loan when the payslip's deduction was actually removed.

---

## 14. LOW — Overtime is priced from the *rounded* daily rate while absences/late use the unrounded one (1-cent drift, documented inconsistency)

**Where:** `api/payroll_runs.php:357` (`$hourlyRate = $att["daily_rate"] / 8.0;` — `$att["daily_rate"]` is `round($dailyRate, 2)` from `_payroll_helpers.php:264`) versus `:265-266` which multiply with the unrounded `$dailyRate`/`$perMinute`.

```
monthly 40 000.10 → basic 20 000.05, W 13 → daily = 1538.4653846 (rounds to 1538.47)
16 OT hours at 1.0: 16 × 1538.47/8 = 3 076.94
                   16 × 1538.4653…/8 = 3 076.93        → 1 cent overpaid (verified)
```

`DEFENSE_PAYROLL_SAMPLE.md` §8.3 acknowledges the convention and shows it is immaterial in that sample (1 831.73 either way). No larger drift was reproducible in the cases I tried, so this is a consistency defect, not a material money error.

**Minimal fix:** pass the unrounded rate (or the unrounded `basic / workingDays` calculation) to the OT loop — i.e. add `"daily_rate_raw" => $dailyRate` to the return array and use it at `payroll_runs.php:357`.

---

## 15. LOW — An overtime block that overlaps the normal shift is counted twice (shift pay + OT), and nothing prevents it

**Where:** `api/payroll_runs.php:304-311` — the OT query filters on `employee_id`, `ot_date`, `status`, `hours` and the two timestamps only. There is no comparison against `attendance.time_in`/`time_out`.

**What is wrong:** the kiosk normally prevents the overlap by sequencing (`scan_attendance.php:125-157` handles clock-out, `:159-265` only then starts OT), so a live scan cannot overlap. But `attendance.php` PUT/PATCH and `attendance_corrections.php` can move the shift afterwards, and `overtime_records.php` PUT can move `ot_date`. A record with `time_in` 15:00 (`ot_date` = a day the shift ran 08:00–17:00) and `time_out` 19:00 is paid 4 OT hours on top of 8 attendance hours.

```
attendance 08:00–17:00 → present (8 h, no deduction)
OT         15:00–19:00 → hours 4.00 × 1.25 × 288.46125 = 1 442.31 paid, of which 2 h (721.15) duplicate the shift
```

**Minimal fix:** in the OT loop, clamp the paid hours to the part after `attendance.time_out` (or reject/flag any OT row whose `time_in` is earlier than the same day's `time_out`).

---

## 16. INFO — Dead code and orphaned features I could confirm

| Item | Evidence | Effect |
|---|---|---|
| `salary_rates` is write-only | `api/salary_rates.php` writes it; no payroll code reads it (`grep salary_rates` → only the CRUD endpoint and tests). Payroll uses `employees.basic_salary` (`payroll_runs.php:279,332`). | A Payroll Officer adding an effective-dated rate change sees nothing happen; the 13th month (finding 9) and any mid-period change are computed from the single current figure. |
| `assertPayrollRunUnlocked()` never called | `_payroll_helpers.php:288`; no call site in `api/` | Dead safety helper — the run-level lock has no enforcement point, only the payslip-level one. |
| `defaultPayDate()` never called | `payroll_periods.php:11-17` | `pay_date` is whatever the client sends, `NULL` when omitted; no run-time consequence today. |
| No hire-date / termination proration | `payroll_runs.php:278-280` (`WHERE is_active = 1` only) | Live data: employee 8 was hired **2026-10-16** and employee 168 on **2026-10-18**, both *after* period 2026-10-01…15, yet payslips 3 and 4 were generated for them with 13 "absences" each and `net_pay 0.00`. The arithmetic is arithmetically equivalent to proration, but it produces zero-net payslips and a false 13-day absence record for people who were not yet employed. Filter `date_hired <= period.end_date` (and `NULL`/inactive handling) at `:278`. |
| Reports ignore run status | `reports.php:102-110` (remittance), `:178+` (department cost) | Sums every payslip in the date window regardless of `payroll_runs.status`, so a `Submitted` or `Returned` run is counted as incurred payroll cost. |

---

## 17. Not confirmed / not tested

- **Concurrency on run creation.** `payroll_runs.php:197-204` reads `payroll_periods.status` *before* `beginTransaction()` (`:209`) and without `FOR UPDATE`; the status is only changed at `:449`. Two simultaneous `POST`s can both see `Open` and both insert a run for the same period (the second's `UPDATE` merely queues behind the first's row lock). This is a plausible double-pay path, but I did not reproduce it (it needs two overlapping requests) and the code alone does not prove the timing. Fix: `SELECT … FOR UPDATE` the period row inside the transaction and re-check `status = 'Open'` there.
- **`payslips.js` renders Add/Remove controls on locked runs.** `assets/js/payslips.js:190-206` always draws "+ Add Allowance"/"+ Add Deduction" and the remove buttons; the server correctly answers 409 (`allowances.php:37`, `deductions.php:37`), so this is cosmetic and I did not treat it as a finding.
- **13th-month legal treatment** (whether statutory contributions should be zero, `payroll_runs.php:232-237`) matches common PH practice and the stated rules; I did not audit it further.

---

## 18. Findings by severity

| # | Severity | File:line | One-line |
|---|---|---|---|
| 1 | **HIGH** | `api/_payroll_helpers.php:96`, `api/payroll_runs.php:366` | Taxable base omits overtime and allowances → withholding tax understated (366.35/payslip in the project's own sample) |
| 2 | **HIGH** | `api/payroll_runs.php:427-441` | Loan amortisation uncapped by available pay → loan credited for money never withheld (6 000.00 example) |
| 3 | **MEDIUM** | `api/_payroll_helpers.php:153-156` | `total_deductions` capped to gross without adjusting the lines → total ≠ sum of lines (200.00 example on live payslip 3) |
| 4 | **MEDIUM** | `api/payslips.php:17-25` | Negative `overtime_pay` accepted → negative gross and negative total_deductions (−10 000.00 example) |
| 5 | **MEDIUM** | `api/attendance.php:318-472`, `api/overtime_records.php:182-303`, `api/leave_requests.php:171` | Payroll lock not enforced on attendance / overtime / leave for Approved or Released runs |
| 6 | **MEDIUM** | `api/payroll_periods.php:88-90,115-117`, `api/payroll_runs.php:202-204` | `Processed` is terminal and no void/reprocess exists → late-approved OT/leave lost for ever (721.15 example) |
| 7 | **MEDIUM** | `api/leave_requests.php:18-29` vs `api/_payroll_helpers.php:20-22` | Leave balance charged Mon–Fri, payroll excuses Mon–Sat → Saturday leave is free (4 paid days for 3 credits) |
| 8 | **MEDIUM** | `api/payroll_periods.php:50-57`, `api/payroll_runs.php:212,336` | Overlap checked per type only; any non-13th-Month type is paid `monthly/2` → 90 000.00 paid for a 60 000.00 month |
| 9 | LOW | `api/_payroll_helpers.php:399-438` | 13th month uses the current salary for the whole year, ignores absences; flat 20 % over 90 000 |
| 10 | LOW | `api/attendance.php:8-9,26-28` | 300-minute lunch rule: 301 min → 4.02 h vs 300 min → 5.00 h; lunch docked even outside the lunch window |
| 11 | LOW | `api/_payroll_helpers.php:197-205` | `COUNT(*)` present days with no unique `(employee_id, work_date)` → duplicates erase a real absence |
| 12 | LOW | `api/attendance.php:245`, `api/payroll_runs.php:409` | Attendance screen ignores approved leave (4 vs 2 absences); "Undertime" is never charged |
| 13 | LOW | `api/loans.php:280-304` | Deleting a loan payment does not reverse the payslip deduction → next run charges it twice |
| 14 | LOW | `api/payroll_runs.php:357` | OT priced from the rounded daily rate while absences/late use the unrounded one (1-cent drift) |
| 15 | LOW | `api/payroll_runs.php:304-311` | No overlap check between OT hours and the attendance shift → overlapping OT paid twice |
| 16 | INFO | `api/salary_rates.php`, `api/_payroll_helpers.php:288`, `api/payroll_periods.php:11`, `api/payroll_runs.php:278` | Dead `salary_rates`, dead `assertPayrollRunUnlocked`/`defaultPayDate`, no hire-date proration (employees hired after the period get zero-net payslips) |

**Total: 16 findings — 2 High, 6 Medium, 7 Low, 1 Info.**
