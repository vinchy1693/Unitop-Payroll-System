# Defence Payroll Sample — Vinchy Fantonial, 16–30 November 2026

Sample payroll for the final defence demo, seeded by `tests/defense_seed.php` and
proved by `tests/defense_verify.php`. Every figure below was produced by that
verification run against the live `payroll_db`, not by hand.

| Item | Value |
|---|---|
| Employee | `employee_id` 3 — `EMP-0001` Vinchy Fantonial |
| Monthly basic salary | PHP 60,000.00 |
| Period | 2026-11-16 to 2026-11-30 (`period_id` 10 as currently seeded) |
| Period type / status | `Semi-monthly` / **Open** |
| Working days in period (W) | **13** |

The period is deliberately left **Open**, with no payslip, so it can be processed
live in front of the panel. The seeded inputs (attendance, approved leave,
overtime) are already in the database.

---

## 1. The working days (W = 13)

The work week is Monday–Saturday; Sunday is the only non-working day. Counted by
hand from the calendar (not with the app's `countWorkingDays()`):

- Week 1: Mon 16, Tue 17, Wed 18, Thu 19, Fri 20, Sat 21 — 6 days
- Week 2: Mon 23, Tue 24, Wed 25, Thu 26, Fri 27, Sat 28 — 6 days
- Week 3: Mon 30 — 1 day
- Sundays 22 and 29 are excluded.

**W = 6 + 6 + 1 = 13.**

## 2. Day-by-day

Attendance is one row per present working day. The two approved-leave days and
the two absence days have no attendance row.

| Date | Weekday | Status | Time in | Time out | Hours | Late |
|---|---|---|---|---|---|---|
| 2026-11-16 | Monday | Present | 08:00 | 17:00 | 8.00 | 0 |
| 2026-11-17 | Tuesday | Present | 08:00 | 17:00 | 8.00 | 0 |
| 2026-11-18 | Wednesday | **Approved leave** | — | — | — | — |
| 2026-11-19 | Thursday | **Approved leave** | — | — | — | — |
| 2026-11-20 | Friday | **Late 15 min** | 08:15 | 17:00 | 7.75 | 15 |
| 2026-11-21 | Saturday | Present | 08:00 | 17:00 | 8.00 | 0 |
| 2026-11-22 | Sunday | Sunday (non-working) | — | — | — | — |
| 2026-11-23 | Monday | Present | 08:00 | 17:00 | 8.00 | 0 |
| 2026-11-24 | Tuesday | **ABSENT** | — | — | — | — |
| 2026-11-25 | Wednesday | Present | 08:00 | 17:00 | 8.00 | 0 |
| 2026-11-26 | Thursday | **Late 30 min** | 08:30 | 17:00 | 7.50 | 30 |
| 2026-11-27 | Friday | **ABSENT** | — | — | — | — |
| 2026-11-28 | Saturday | Present | 08:00 | 17:00 | 8.00 | 0 |
| 2026-11-29 | Sunday | Sunday (non-working) | — | — | — | — |
| 2026-11-30 | Monday | Present | 08:00 | 17:00 | 8.00 | 0 |

Counts: **9 present** (2 of them late, 45 late minutes total), **2 approved paid
leave days**, **2 absences**.

- The leave is a **Sick Leave** request for **2026-11-18 to 2026-11-19**,
  status **Approved**, deducted from the Sick Leave balance for 2026
  (100.00 → 98.00). The seed creates it with exactly the same balance math the
  approve action in `api/leave_requests.php` applies (2 Mon–Fri business days).
- The late rows use `scan_device_id = 'Seed'`, `minutes_late` 15 / 30, and
  `hours_worked` consistent with the clock times and the 60-minute lunch rule:
  08:15→17:00 is 8 h 45 m, less 60 m lunch = 7.75 h; 08:30→17:00 is 8 h 30 m,
  less 60 m = 7.50 h.
- All other present rows are 08:00–17:00, `minutes_late` 0, `hours_worked` 8.00,
  status `Present`.

> A separate **Birthday Leave** request also exists on 2026-11-17. That day has
> an attendance row, and the payroll rule gives presence priority over leave, so
> 2026-11-17 is **Present**, not leave. The older approved Sick Leave
> (2026-10-17 to 2026-10-25) is outside this period and is untouched.

## 3. Overtime records

| OT date | Hours | OT type | Rate multiplier | Present that day? |
|---|---|---|---|---|
| 2026-11-20 | 3.00 | Regular OT | 1.25 | yes (late 15 min) |
| 2026-11-25 | 2.00 | Regular OT | 1.30 | yes |

## 4. Computation walkthrough (real numbers, rounding at every step)

Semi-monthly basic:

```
basic_pay = 60,000.00 / 2 = 30,000.00
```

Daily rate, spread over this period's 13 working days (not a fixed 22 or 11):

```
daily_rate (raw) = 30,000.00 / 13 = 2,307.692307692307...
daily_rate (reported, 2 dp)        = 2,307.69
```

The reported daily rate is for display. The engine multiplies with the
**unrounded** 2,307.692307... — that is why `2,307.69 × 13 = 29,999.97`, not
30,000.00, and the difference is never charged to the employee.

Attendance-based deductions (inputs: present 9, leave 2, absent 2, late 45 min):

```
absent            = max(0, 13 - 9 - 2) = 2

absence_deduction = 2 × 2,307.692307692307... = 4,615.384615384614...  -> 4,615.38
per_minute        = 2,307.692307692307... / 480 = 4.807692307692...
late_deduction    = 45 × 4.807692307692... = 216.346153846153...       -> 216.35
```

Overtime (the engine prices OT at the rounded daily rate / 8):

```
hourly_rate = 2,307.69 / 8 = 288.46125

2026-11-20: 3.00 × 1.25 × 288.46125 = 1,081.7296875
2026-11-25: 2.00 × 1.30 × 288.46125 =   749.99925

overtime_pay = 1,081.7296875 + 749.99925 = 1,831.7289375  -> 1,831.73
```

(Using the unrounded daily rate gives 1,831.730769... — still **1,831.73** at
2 dp, so this choice changes nothing in the final cent.)

Gross pay:

```
gross_pay = 30,000.00 + 1,831.73 = 31,831.73
```

Statutory contributions, from the **monthly** 60,000.00 salary (employee half):

```
SSS        = 5% × min(35,000, 60,000) / 2 = 1,750.00 / 2 =   875.00
PhilHealth = 2.5% × 60,000 / 2            = 1,500.00 / 2 =   750.00
Pag-IBIG   = 2% × min(10,000, 60,000) / 2 =   200.00 / 2 =   100.00
                                              statutory = 1,725.00
```

Taxable compensation (attendance deductions reduce the taxable base; the
statutory contributions are already excluded):

```
taxable = 30,000.00 − (4,615.38 + 216.35) − 1,725.00
        = 30,000.00 − 4,831.73 − 1,725.00
        = 23,443.27
```

BIR TRAIN withholding, standard semi-monthly table (not annualised):

```
taxable = 23,443.27
        (16,667–33,332 bracket: 937.50 + 20% of excess over 16,667)
withholding tax = 937.50 + (23,443.27 − 16,667) × 0.20
                = 937.50 + 6,776.27 × 0.20
                = 937.50 + 1,355.254
                = 2,292.754  -> 2,292.75
```

Totals:

```
total_deductions = 1,725.00 + 2,292.75 + 4,615.38 + 216.35 = 8,849.48
net_pay          = 31,831.73 − 8,849.48 = 22,982.25
```

No capping was applied: the attendance deductions (4,831.73) are below the
remaining room after statutory deductions (30,000.00 + 1,831.73 − 4,017.82 =
27,813.91), and net pay is comfortably above zero.

## 5. Expected payslip (the side-by-side the verifier prints)

| Metric | Expected | Actual (payslip / DB) |
|---|---:|---:|
| W (working days) | 13 | 13 |
| Present days | 9 | 9 |
| Leave days | 2 | 2 |
| Absent days | 2 | 2 |
| basic_pay | 30,000.00 | 30,000.00 |
| daily_rate | 2,307.69 | 2,307.69 |
| absence_deduction | 4,615.38 | 4,615.38 |
| late_deduction | 216.35 | 216.35 |
| overtime_pay | 1,831.73 | 1,831.73 |
| gross_pay | 31,831.73 | 31,831.73 |
| SSS EE | 875.00 | 875.00 |
| PhilHealth EE | 750.00 | 750.00 |
| Pag-IBIG EE | 100.00 | 100.00 |
| withholding tax | 2,292.75 | 2,292.75 |
| total_deductions | 8,849.48 | 8,849.48 |
| **net_pay** | **22,982.25** | **22,982.25** |

The generated payslip has exactly two non-government deduction lines:

```
Absences (2 day(s))          4,615.38
Late/Undertime (45 min)        216.35
```

There are no loans and no allowances for this employee, so the only other
deductions are the three statutory contributions and the withholding tax.

## 6. Reproduce it live

The database is already in the demo state. In the browser:

1. Log in at `http://localhost/master_file_module_api/login.html` as an
   Administrator.
2. **Attendance** → *Attendance Summary* → set **From** `2026-11-16`, **To**
   `2026-11-30` → **Generate Summary**. Then click the Vinchy Fantonial row to
   open the daily detail: 2026-11-20 shows **Late 15 min**, 2026-11-26 shows
   **Late 30 min**, and the other present days show 08:00–17:00.
3. **Payroll** (`payroll_periods.html`) → find the period **2026-11-16 to
   2026-11-30**, type *Semi-monthly*, status **Open** → click **▶ Run Payroll**
   → confirm. You should see “Payroll run completed. 1 payslip(s) generated…”.
4. **Payslips** (`payslips.html`) → **View** on the new payslip. It shows Basic
   Pay 30,000.00, Overtime Pay 1,831.73, Gross 31,831.73, the statutory
   contributions (875.00 / 750.00 / 100.00), withholding tax 2,292.75, the two
   attendance deduction lines, Total Deductions 8,849.48 and Net Pay 22,982.25.

### One screen that disagrees — say this before the panel asks

The **Attendance Summary/detail** screen labels *every* working day without an
attendance row as “Absent”, and it does not read leave requests. So it says
**4 absent**, while payroll pays **2** of those days as approved paid leave and
docks only **2**. This is a display limitation of the attendance screen, not a
payroll error: the payroll engine deliberately excuses approved paid leave
(`computeAttendanceDeductions()` subtracts leave days before computing absences).
If you show the Attendance screen, point this out yourself: 18–19 Nov = approved
Sick Leave; 24 and 27 Nov = genuine absences.

## 7. Verifying it non-interactively

```
C:\xampp\php\php.exe tests\defense_seed.php     (idempotent; safe to run twice)
C:\xampp\php\php.exe tests\defense_verify.php   (31 checks, restores the demo state)
```

`defense_verify.php` does **not** include `api/_payroll_helpers.php`. It counts
the working days and derives every peso with its own PHP loops straight from the
published rules, then:

1. creates a temporary Administrator (the `tests/_harness.php` fixture pattern),
   logs in and POSTs the period to the real `api/payroll_runs.php` endpoint;
2. reads the generated payslip, `govt_contributions` and deduction lines from
   MySQL and asserts every figure **to the cent**;
3. prints the expected-vs-actual table above;
4. deletes that run’s payslip, its deductions/allowances/govt contributions and
   the run, sets the period back to **Open**, removes the temporary user, and
   confirms the 9 attendance rows, 2 overtime rows, approved leave and 98.00
   Sick Leave balance are still intact.

Exit code 0 means every check passed and the demo state was restored. If you run
the live demo first and then want to verify again, `defense_verify.php` resets
the period (it deletes that run’s payslip) and re-verifies.

## 8. Rounding and ambiguity decisions

1. **Working day count** is done by hand from the calendar (Mon–Sat), not with
   `countWorkingDays()`, so a change to that helper cannot make the check agree
   with itself.
2. **Daily rate** is reported to 2 dp (2,307.69) but the absence and late
   arithmetic uses the unrounded 30,000/13. If the rounded rate were used,
   `2 × 2,307.69 = 4,615.38` (same), and `45 × 2,307.69/480 = 216.34` — one
   centavo lower. The app uses the unrounded rate, so the correct figure is
   **216.35**.
3. **Overtime** uses the rounded daily rate / 8 in the app. Here that yields
   1,831.73, identical to the unrounded-rate result at 2 dp, so the ambiguity
   is immaterial in this sample.
4. **Tax** is rounded once, at the semi-monthly figure (`round($tax, 2)`),
   giving 2,292.75. The bracket arithmetic is kept at full precision until that
   last step.
5. **Absence and late deductions** are each rounded to 2 dp before being summed
   into `total_deductions` (4,615.38 + 216.35), which is what the app stores.
6. The **leave balance** uses Mon–Fri business days, matching the approve action
   in `api/leave_requests.php` (the payroll work week is Mon–Sat, but leave
   balances are still counted Mon–Fri). For 2026-11-18..19 both rules give 2.

## 9. Honest observations

- The absence deduction is **4,615.38**, about **15.4%** of the 30,000.00
  semi-monthly basic. That is large but not unreasonable, and it is exactly the
  rule: two missing working days out of thirteen. The employee still nets
  **22,982.25** (72.2% of gross).
- The late deduction is only **216.35** (0.68% of gross) even though the employee
  was late twice, because lateness is charged per minute (daily rate / 480).
- The period looks slightly “busy”: 2 absences and 2 late days in 13 working
  days. That is intentional — the sample has to demonstrate tardiness, overtime,
  approved leave and absences in a single period, and those 2 absences are what
  create the visible absence deduction.
- The Attendance screen’s “4 absences” figure disagrees with payroll’s 2 (see
  §6). Nothing was changed in the application to hide this; it is documented
  here so the presenter can explain it.

## 10. Current state after seeding/verification

- Period **2026-11-16 to 2026-11-30** is **Open** and has **no payroll run and
  no payslip**.
- Seeded inputs are present: 9 attendance rows (`scan_device_id = 'Seed'`), 2
  overtime records, 1 approved Sick Leave for 18–19 Nov, Sick Leave balance
  98.00, employee basic_salary 60,000.00.
- The temporary verification Administrator has been deleted.
- Older periods and the older approved Sick Leave were not touched.

To regenerate the sample from scratch, run `tests/defense_seed.php` (it refuses
to run while a payroll run exists on the period, so run `tests/defense_verify.php`
first to restore the period to Open).
