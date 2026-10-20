# Defence Checklist — UNITOP Payroll System

Legend: `[ ]` to do · `[x]` verified done · ⚠️ = could cost marks if unaddressed

---

## A. Fix before you present

### A1. Security (highest risk first)

- [x] **`api/scan_attendance.php` — `demo_mode` is maker-only.** Every other role gets 403;
      the demo date/time are validated and `ALLOW_DEMO_SCANS` can disable the branch.
- [x] **CSRF + JSON-only bodies.** Per-session `admin_csrf` / `portal_csrf` token required in
      `X-CSRF-Token`; `json_input()` no longer falls back to `$_POST` (415 otherwise).
- [x] **The payroll lock is enforced on attendance / overtime writes.**
      `assertAttendanceDateUnlocked()` is now called from `attendance.php`, `scan_attendance.php`,
      `overtime_records.php` and `attendance_corrections.php`; a signed date answers **409**.
- [ ] **`verify` is a credential oracle** with no rate limiting.
- [x] **Session cookie flags** — HttpOnly, SameSite=Lax, Secure on HTTPS (both apps).
- [x] **`PDOException` text no longer reaches the client** — display_errors off, generic 500,
      detail logged.
- [x] Audit log redacts QR values, QR tokens and bank / government numbers; `sql/012` scrubs old rows.

### A2. Correctness

- [x] **Allowances are taxed when taxable.** `allowances.is_taxable` (migration 011) feeds
      `recomputePayslipTotals()`, so a taxable line raises gross **and** withholding tax; a
      de minimis line raises gross only.
- [x] **Two concurrent Process clicks cannot create two runs** — `UNIQUE KEY uq_runs_period (period_id)`.
- [x] **`Processed` is no longer terminal** — `api/payroll_void.php` voids a run (Approver, reason,
      audited) and reopens the period for reprocessing.
- [x] **Public holidays are excluded from expected working days** (Art. 94) via the `holidays` table.
- [x] **Rest-day / holiday premiums** — first 8h 1.30/2.00, OT 1.69/2.60, paid as `payslips.premium_pay`
      (migration 013); holiday takes precedence over the Sunday rest day.
- [x] **Undertime** — early clock-out is deducted from `time_out`; a missing clock-out docks the remaining
      shift; late arrival is not double-docked.
- [x] **Overtime is filed by the Supervisor only**, with a reason and requested window; the day type/rate
      are derived on the server, never trusted from the client. The employee portal is read-only and its
      OT POST returns 403; the kiosk never creates an OT request.
- [x] **QR demo date** — the attendance QR card can use the same simulated date as kiosk Demo Mode;
      `ALLOW_DEMO_QR_DATE` in `employee_portal/api/me_qr.php` must be false in production.
- [x] **The kiosk enforces the approved OT window** — clock-in >5 min early or after the end is 409;
      clock-out >5 min early is 409; a late clock-out is recorded but paid only to the approved end.
      The after-shift fallback has no requested end, so it keeps the old behaviour.
- [x] **Short-lived QR tokens** — `me_qr.php` issues a 120-second, one-time, purpose-bound token
      only when the employee has an **Active `qr_codes` badge**; with no active badge it returns 404
      and the attendance page shows no QR. After clock-out the purpose is `complete` and no token/QR
      is issued, **unless an Approved OT for today is still open**, in which case it is `ot` for the
      kiosk OT clock-in/out. Pending/Completed OT produces no QR. Only the SHA-256 hash is stored.
- [x] **Revoke actually revokes** — the QR screen revokes all active badges for the employee and
      invalidates unused tokens; a missing id is 404; the portal refuses to mint until a badge is
      generated/reactivated.
- [x] **Portal payslips are Released-only** — `me_payslips.php` and `me_dashboard.php` filter
      `payroll_runs.status = 'Released'`; Draft/Submitted/Approved payslips, including direct `?id=`
      requests, are hidden until release.
- [x] **Period status follows approval** — a processed period moves to `For Approval` while the run is
      Submitted, `Returned` if returned, and becomes `Processed` only when the run is Approved or Released.
- [x] **Screenshot limit is documented, not claimed fixed** — the token expires after 120 seconds
      and is single-use, so a screenshot is stale after that. A capture inside the window or a
      second camera still works; a native/managed kiosk is required for true blocking.
- [x] **OT is filed by the Supervisor and decided by the Approver** — a Supervisor may also decide a
      team request they did not file; the Payroll Officer cannot file or approve (403).
- [x] **Basic salary is checker-only** — `api/employee_salary.php` (Approver, reason >=10, audited);
      `employees.php` PUT returns 403 if the maker changes `basic_salary`.
- [x] **Loans start Pending** — the maker cannot create Active; the Approver activates. Payroll withholds at
      most the remaining net pay, a manual payment is capped at the outstanding principal, and voiding a run
      re-opens a loan that was marked Paid.
- [x] **Period types are whitelisted** — Semi-monthly / 13th Month; same-type overlap is checked on POST
      and PUT, and a 13th-month run may overlap a working period.
- [x] **Meal break** — a span over 300 minutes loses 60 minutes for both the regular shift and OT.
- [x] **Contribution bases** — SSS/PhilHealth/Pag-IBIG stay on basic salary per RA 1161 §8, RA 11223 and
      RA 9679 §4 (they exclude OT/allowances).
- [ ] **No `UNIQUE(employee_id, work_date)`** — duplicate attendance days are double-credited.
- [ ] **Unvalidated FK inserts** return 500 instead of 422 (leave, loans, overtime, salary rates, positions, departments).

### A3. Features that look done but are not

- [x] **Supervisor teams are assigned in the Employees form** — `employees.supervisor_id` is accepted
      by `employees.php` and listed by `supervisors_lookup.php`; a Supervisor only sees/filers their team.
- [x] **Supervisor Dashboard is team-scoped** — `api/dashboard.php` returns team counts, pending queues,
      today's attendance and recent team OT; it does not expose salary or payroll-wide figures.
- [x] **`salary_rates`** removed from the nav and the API; payroll reads `employees.basic_salary`.
- [x] **13th month** is generated by a `13th Month` payroll period run.
- [ ] **No loyalty / hire-date proration** — employees hired mid-period get a full semi-monthly basic.

### A4. Tests and housekeeping

- [x] `overtime_workflow_test.php` — timezone pinned to Asia/Manila in `_harness.php`; `pending_hours` ~1.50.
- [x] `attendance_correction_test.php` — assertions updated to the 365-day window.
- [x] `salary_rates` / `cash_disbursements` references removed from the matrices; dead endpoints stay deleted.
- [x] `nav_matrix_test.php` / `supervisor_scope_test.php` read the HTML by absolute path.
- [x] Tests that depended on employee id 3 or live leave data now build their own fixtures.
- [x] `premium_undertime_test.php` — Sunday + Regular holiday premiums, 2.60 holiday OT, 60-minute undertime.
- [x] `ot_window_test.php` — early/late OT in, early out, late out capped at the approved end (25/0).
- [x] `money_guard_test.php` — loan void reopens Paid loans, loan payment capped at net, period overlap/PUT, locked attendance/OT (35/0).
- [x] `sod_guard_test.php` — Administrator cannot mint payroll roles, loans start Pending, self-approval blocked, salary checker-only (16/0).
- [x] `manual_qr_test.php` — on-site enforcement: portal 403, no manual UI, staff kiosk 200, Employee kiosk 403.
- [x] `employee_portal_test.php` — the employee cannot file overtime (403); supervisor-filed history is visible read-only.
- [ ] **Change every password before presenting.** The accounts use per-account demo passwords; do not ship the presentation credentials.

---

## B. Data to set up before demoing

- [ ] **Leave types** — only Maternity and Birthday exist. Add Vacation 5, Sick 5, Paternity 7 per the study's table.
- [ ] **QR codes for all employees** — 0 static badges after the cleanup; generate as needed. The
      attendance page now issues a short-lived token, so a static badge is only a legacy/backup path.
- [ ] **Bank accounts for all employees** — the bank file **refuses (422)** if any participant is missing one.
- [ ] **Supervisor team links** — `UPDATE employees SET supervisor_id = <lead> WHERE employee_id IN (...)`; leave the lead's own `supervisor_id` NULL.
- [ ] **Attendance for the demo period** — at least one full shift so overtime and payroll have inputs.
- [ ] **A payroll period left `Open`** so you can process it live.

---

## C. Demo script (in this order)

1. [ ] **Login as `payroll`** → Departments, Positions, Employees (show bank + contact fields).
2. [ ] **Kiosk** (`kiosk.html`) as `payroll` — the kiosk is staff-only and on-site → scan or type the QR value.
   - Phone: **Portal → Attendance → My QR** (120-second token) → scan at the kiosk.
   - clock in → clock out → **scan again = refused** ("No approved overtime request… ask your supervisor").
   - **The kiosk never files OT.** The Supervisor files it from Admin → Overtime.
   - The Employee portal cannot record attendance (`me_scan_attendance.php` = 403) or file OT
     (`me_overtime_requests.php` POST = 403).
3. [ ] **Login `supervisor` → Overtime → File Overtime Request** → pick the team member, date, window and reason.
4. [ ] **Overtime page as `approver`** → approve. (The Supervisor files; the Approver decides.)
5. [ ] **Scan the employee's QR at the kiosk again** → overtime starts → scan again → hours computed.
6. [ ] **Portal on the phone** → Attendance → **Request a correction** → file it → watch the table populate.
7. [ ] **Corrections page as `payroll`** → approve → point out `minutes_late` recomputes to 0.
8. [ ] **Payroll Periods** → the pay date auto-fills (20th / 5th next month) → **Process**.
8. [ ] **Show a payslip** → every column, and that the deduction lines **sum to the total**.
9. [ ] **Try to approve your own run as the maker** → **403 "Segregation of duties"**. Try again as `approver` → succeeds.
10. [ ] **Show the lock**: edit a deduction on the approved run → **409 "A formal adjustment is required"**.
11. [ ] **Exports** → bank file (or the 422 naming who is missing an account), register, remittance.
12. [ ] **Login as `admin`** → Users + **Audit Log** showing every action above, with no payroll access.

---

## D. State these limitations before the panel finds them

- [x] Taxable allowances affect withholding tax; a de minimis allowance can be marked non-taxable.
- [x] Public holidays from the `holidays` table are excluded from expected working days (Art. 94).
- [x] A wrong run can be voided by the Approver (reason required, audited) and the period reprocessed.
- [x] Rest-day / holiday premiums are paid by day type; holiday OT is at least 2.60.
- [x] Undertime and missing clock-outs are deducted; late arrival is not double-docked.
- [x] OT requests carry a reason and requested window; the rate is server-derived.
- [x] A reviewer cannot decide their own OT, leave, correction or loan (`assertNotOwnEmployee`).
- [x] Supervisor-files / portal read-only overtime + approval + kiosk time-in/out (updated portal suite).
- [x] `premium_undertime_test.php` 17/0: Sunday/holiday premium, 2.60 holiday OT, 60-min undertime.
- [ ] Night differential (+10%, Art. 86) is **not** computed.
- [ ] Overtime actually worked without approval is **not** paid — align with company policy.
- [ ] SSS uses the straight `min(35,000, salary) × 5%` rather than the official MSC bracket table (identical above ₱35,000).
- [ ] Rates are **2025 hard-coded** values; a change is a code edit, not configuration.
- [ ] The phone **camera cannot work** over `http://<LAN-IP>` — browsers require HTTPS; use the **staff kiosk at the office** (its manual code field), not the portal. Portal clocking is disabled.
- [x] Administrator is technical-only by design: cannot run/approve payroll, assign a Payroll Officer/Approver role, or set a payroll-role password.

---

## E. Commands

```powershell
# every payslip recomputed independently — exit 0 means all figures match
C:\xampp\php\php.exe C:\xampp\master_file_tests\verify_computations.php

# payroll flow
C:\xampp\php\php.exe C:\xampp\master_file_tests\full_payroll_test.php
C:\xampp\php\php.exe C:\xampp\master_file_tests\payroll_void_test.php

# role separation
C:\xampp\php\php.exe C:\xampp\master_file_tests\role_matrix_test.php
C:\xampp\php\php.exe C:\xampp\master_file_tests\payroll_approval_test.php

# audit trail + hardening
C:\xampp\php\php.exe C:\xampp\master_file_tests\audit_log_test.php
C:\xampp\php\php.exe C:\xampp\master_file_tests\security_test.php
```

---

## F. Accounts

| Login | Role | Can | Cannot |
|---|---|---|---|
| `payroll` | Payroll Officer (maker) | master data, attendance, payroll, loans preparation, exports | approve payroll; approve OT/loans; users, audit |
| `approver` | Approver (checker) | approve / return / release payroll, approve OT/loans | edit any amount; run payroll |
| `admin` | Administrator (technical) | Users, Audit Log | any payroll or salary data |
| `vinchy` | Employee (emp 4) | own portal data, files leave/OT/corrections | the admin app |
| `juan.delacruz` | Employee (emp 5) | same, own data only | same |

⚠️ Passwords are per-account. **Change them before presenting.**

---

## G. Known-good, verified

- [x] 36/36 payslip fields recomputed independently — 0 mismatches
- [x] Working days, daily rate, absences, tardiness, overtime pricing
- [x] Public holidays excluded from expected working days (Art. 94)
- [x] Taxable allowances raise the withholding base; de minimis lines do not
- [x] SSS / PhilHealth / Pag-IBIG ceilings and shares
- [x] TRAIN brackets, and tax now includes overtime
- [x] Attendance-deduction cap; net never negative
- [x] Deduction lines sum exactly to `total_deductions`
- [x] One run per period (`uq_runs_period`) and a void/reprocess path
- [x] CSRF token required on every mutation; JSON-only bodies (415 for forms)
- [x] Session cookies are HttpOnly + SameSite=Lax (both apps)
- [x] Errors are generic; PDOException detail stays in the server log
- [x] Audit log redacts QR values, QR tokens and bank / government numbers
- [x] Demo scanning is maker-only; `ALLOW_DEMO_SCANS` can disable it
- [x] Database dumps, `tests/`, and `.git/` are no longer web-accessible (403)
- [x] Maker cannot approve their own payroll (403, API-enforced)
- [x] Zero response-shape mismatches across 44 endpoints
- [x] No SQL injection, no mass assignment, no `me_*` IDOR
