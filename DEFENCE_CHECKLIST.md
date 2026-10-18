# Defence Checklist — UNITOP Payroll System

Legend: `[ ]` to do · `[x]` verified done · ⚠️ = could cost marks if unaddressed

---

## A. Fix before you present

### A1. Security (highest risk first)

- [ ] ⚠️ **`api/scan_attendance.php` — no role guard + `demo_mode`/`demo_date` unvalidated.**
      Any logged-in Employee can stamp attendance for any date, including inside a locked period.
      Fix: add `requireRole` for the demo branch, validate `demo_date` against a sane window.
- [ ] ⚠️ **No CSRF protection** and `json_input()` falls back to `$_POST`.
      A cross-site form can create an Administrator. Fix: a token, or reject non-JSON bodies.
- [ ] ⚠️ **The payroll lock is not enforced on attendance / overtime / leave writes.**
      `assertAttendanceDateUnlocked()` exists but has **zero callers** outside corrections.
      Fix: call it in `attendance.php`, `scan_attendance.php`, `overtime_records.php`.
- [ ] **`verify` is a credential oracle** with no rate limiting.
- [ ] **No session cookie flags** (HttpOnly / SameSite / Secure).
- [ ] **`PDOException` text reaches the client** with `display_errors=1`.
- [ ] **Audit log stores live QR codes** and bank / government numbers in clear text.

### A2. Correctness

- [ ] ⚠️ **Allowances are not taxed.** Added after the payslip exists; nothing recomputes tax.
      ~₱2,309 under-withheld on a ₱10,000 allowance. Decide: recompute, or declare non-taxable.
- [ ] **Two concurrent Process clicks create two runs** for one period (no `UNIQUE(period_id)`, no `FOR UPDATE`).
- [ ] **`Processed` is terminal** — overtime or leave approved after a run is lost, with no void/reprocess.
- [ ] **No `UNIQUE(employee_id, work_date)`** — duplicate attendance days are double-credited.
- [ ] **Unvalidated FK inserts** return 500 instead of 422 (leave, loans, overtime, salary rates, positions, departments).

### A3. Features that look done but are not

- [ ] ⚠️ **`employees.supervisor_id` is written by nothing** — no API field, no form input.
      The Supervisor role therefore resolves to an empty team and every approval returns 403.
      Fix: add a Supervisor dropdown to the Employees form and accept it in `employees.php`.
- [ ] **`salary_rates`** is write-only and unused by payroll — either wire it or remove it from the nav.
- [ ] **No 13th-month page** (`compute13thMonthPay()` exists in the helper only).
- [ ] **No loyalty / hire-date proration** — employees hired mid-period get a full semi-monthly basic.

### A4. Tests and housekeeping

- [ ] **`tests/overtime_workflow_test.php` → 101/1** — `pending_hours` fixture still encodes the old 6-hour timezone offset.
- [ ] **`tests/attendance_correction_test.php` → 144/2** — assertions still expect a future-date rejection and a "90-day window" that the widened date range removed.
- [ ] **Change every password.** `admin`, `payroll`, `approver`, `vinchy`, `juan.delacruz` are all `test123`.
- [ ] **Admin repo `main` is far behind `checking`** and is the GitHub default — fast-forward it or flip the default branch.

---

## B. Data to set up before demoing

- [ ] **Leave types** — only Maternity and Birthday exist. Add Vacation 5, Sick 5, Paternity 7 per the study's table.
- [ ] **QR codes for all employees** — currently 1 of 3. Juan and Harry cannot clock in.
- [ ] **Bank accounts for all employees** — the bank file **refuses (422)** if any participant is missing one.
- [ ] **Supervisor team links** — `UPDATE employees SET supervisor_id = <lead> WHERE employee_id IN (...)`; leave the lead's own `supervisor_id` NULL.
- [ ] **Attendance for the demo period** — at least one full shift so overtime and payroll have inputs.
- [ ] **A payroll period left `Open`** so you can process it live.

---

## C. Demo script (in this order)

1. [ ] **Login as `payroll`** → Departments, Positions, Employees (show bank + contact fields).
2. [ ] **Kiosk** (`kiosk.html`) → scan or type the QR value.
   - clock in → clock out → **scan again = overtime request** → shows "awaiting approval".
   - **scan again = refused** ("still awaiting approval") — this is the strongest single demo.
3. [ ] **Overtime page as `payroll`** → approve.
4. [ ] **Scan again as the employee** → overtime starts → scan again → hours computed.
5. [ ] **Portal on the phone** → Attendance → **Request a correction** → file it → watch the table populate.
6. [ ] **Corrections page as `payroll`** → approve → point out `minutes_late` recomputes to 0.
7. [ ] **Payroll Periods** → the pay date auto-fills (20th / 5th next month) → **Process**.
8. [ ] **Show a payslip** → every column, and that the deduction lines **sum to the total**.
9. [ ] **Try to approve your own run as the maker** → **403 "Segregation of duties"**. Try again as `approver` → succeeds.
10. [ ] **Show the lock**: edit a deduction on the approved run → **409 "A formal adjustment is required"**.
11. [ ] **Exports** → bank file (or the 422 naming who is missing an account), register, remittance.
12. [ ] **Login as `admin`** → Users + **Audit Log** showing every action above, with no payroll access.

---

## D. State these limitations before the panel finds them

- [ ] Allowances do not affect withholding tax (or fix it).
- [ ] Overtime is priced flat at **1.25** — no rest-day or holiday premium, because there is no holiday calendar.
- [ ] SSS uses the straight `min(35,000, salary) × 5%` rather than the official MSC bracket table (identical above ₱35,000).
- [ ] Rates are **2025 hard-coded** values; a change is a code edit, not configuration.
- [ ] The phone **camera cannot work** over `http://<LAN-IP>` — browsers require HTTPS; use manual QR entry.
- [ ] Administrator is technical-only by design and **cannot** run or approve payroll.
- [ ] No CSRF tokens (item A1) — say it is known, not unnoticed.

---

## E. Commands

```powershell
# every payslip recomputed independently — exit 0 means all figures match
C:\xampp\php\php.exe tests\verify_computations.php

# payroll flow
C:\xampp\php\php.exe tests\full_payroll_test.php

# role separation
C:\xampp\php\php.exe tests\role_matrix_test.php
C:\xampp\php\php.exe tests\payroll_approval_test.php

# audit trail
C:\xampp\php\php.exe tests\audit_log_test.php
```

---

## F. Accounts

| Login | Role | Can | Cannot |
|---|---|---|---|
| `payroll` | Payroll Officer (maker) | master data, attendance, payroll, loans, exports | approve payroll, users, audit |
| `approver` | Approver (checker) | approve / return / release payroll, approve loans | edit any amount |
| `admin` | Administrator (technical) | Users, Audit Log | any payroll or salary data |
| `vinchy` | Employee (emp 4) | own portal data, files leave/OT/corrections | the admin app |
| `juan.delacruz` | Employee (emp 5) | same, own data only | same |

⚠️ All passwords are `test123`. **Change them.**

---

## G. Known-good, verified

- [x] 36/36 payslip fields recomputed independently — 0 mismatches
- [x] Working days, daily rate, absences, tardiness, overtime pricing
- [x] SSS / PhilHealth / Pag-IBIG ceilings and shares
- [x] TRAIN brackets, and tax now includes overtime
- [x] Attendance-deduction cap; net never negative
- [x] Deduction lines sum exactly to `total_deductions`
- [x] Database dumps, `tests/`, and `.git/` are no longer web-accessible (403)
- [x] Maker cannot approve their own payroll (403, API-enforced)
- [x] Zero response-shape mismatches across 44 endpoints
- [x] No SQL injection, no mass assignment, no `me_*` IDOR
