# UNITOP — Feature Code Map

**Purpose:** answer “where does this feature actually work?” for every major feature.

This is a **map, not a line count**. It names the primary file, the function or block, and the approximate line range. Line numbers are a snapshot and will drift; the function/block name is the durable locator.

| | |
|---|---|
| Admin app | `C:\xampp\htdocs\master_file_module_api` |
| Employee portal | `C:\xampp\htdocs\employee_portal` |
| Verified against admin commit | `35b2c24` |
| Verified against portal commit | `6417ed0` |
| Snapshot date | 2026-10-20 |

> Use the symbol first:
> ```powershell
> Select-String -Path .\api\_payroll_helpers.php -Pattern '^function computeWithholdingTax'
> ```

---

## 1. Request pipeline, sessions, roles and audit

| Concern | Primary code | Supporting code |
|---|---|---|
| Session cookie + flags | `api/_bootstrap.php:12–35` | `employee_portal/api/_bootstrap.php:12–35` |
| JSON-only input | `api/_bootstrap.php:169` (`json_input`) | `employee_portal/api/_bootstrap.php:101` |
| CSRF publish / rotate / clear | `api/_bootstrap.php:45–90` | portal equivalents `:37–75` |
| CSRF enforcement | `api/_bootstrap.php:227` (`assertCsrf`) | `employee_portal/api/_bootstrap.php:129` |
| Role constants | `api/_bootstrap.php:124–132` (`MAKER_ROLES`, `CHECKER_ROLES`, `PAYROLL_ROLES`, `TEAM_QUEUE_ROLES`) | `assets/js/nav.js` |
| `requireRole` | `api/_bootstrap.php:486` | every protected endpoint |
| Own-request refusal | `api/_bootstrap.php:546` (`assertNotOwnEmployee`) | leave, OT, corrections, loans |
| Supervisor team scope | `api/_bootstrap.php:602` (`assertSupervises`) | supervisor tests |
| Central response + audit | `api/_bootstrap.php:177` (`respond`), `:406` (`audit_log_mutation`) | Audit Log page |
| Login / me / verify | `api/auth.php:31–73`, `:93–102`, `:150` | `assets/js/api.js`, login pages |
| Logout | `api/auth.php:14–27` (GET), `:77–89` (POST) | `api.js` injected link, `kiosk.js:279` |
| Audit filters | `api/audit_log.php:27–60` | `assets/js/audit.js:131` |
| Audit action labels | `assets/js/audit.js:52–117` | — |

**Tables:** `users`, `audit_log`.

---

## 2. Master data and user administration

| Feature | UI | API | Main block |
|---|---|---|---|
| Departments | `departments.html` | `api/departments.php` | GET `:9`; POST `:36`; PUT `:52`; PATCH `:69` |
| Positions | `positions.html` | `api/positions.php` | GET `:9`; POST `:31`; PUT `:47`; PATCH `:64` |
| Employees | `employees.html`, `assets/js/employees.js` | `api/employees.php` | GET `:20`; POST `:43`; PUT `:71`; PATCH `:110` |
| Salary checker | `employees.html` salary card; `employees.js:32–52` | `api/employee_salary.php:13` | Approver-only; reason ≥10; audited |
| Users | `users.html`, `users.js` | `api/users.php` | GET `:9`; POST `:22`; PUT `:60`; PATCH `:123` |
| QR records | `qr_codes.html`, `qr_codes.js` | `api/qr_codes.php` | GET `:9`; POST `:25`; PATCH `:58` |
| Leave types | `leave_types.html` | `api/leave_types.php` | GET `:9`; POST `:19`; PUT `:39`; PATCH `:59` |
| Leave balances | `leave_balances.html` | `api/leave_balances.php` | GET `:9`; initialize POST `:69` |

**Tables:** `departments`, `positions`, `employees`, `users`, `qr_codes`, `leave_types`, `leave_balances`.

---

## 3. Attendance, kiosk and QR

| Feature | Primary code | Block / symbol |
|---|---|---|
| QR token issuance | `employee_portal/api/me_qr.php` | TTL `:21`; active-badge gate `:36–47`; purpose `:60–75`; complete/no token `:86–97`; insert token `:99–107` |
| Portal QR display | `employee_portal/attendance.html` QR card; `assets/js/attendance.js` | `loadMyQR` `:22`; refresh/label; token in QR |
| Static QR fallback | `api/qr_codes.php` | POST `:25`; PATCH revoke `:58` |
| Kiosk UI | `kiosk.html`, `assets/js/kiosk.js` | CSRF helper `:25–34`; scan post `:136`; exit verify `:274`; boot `:292` |
| Kiosk scan validation | `api/scan_attendance.php` | token/static lookup `:64`; expiry/used/active checks `:84–140`; duplicate guard `:151`; attendance state `:198` |
| Clock IN | `api/scan_attendance.php` | `:227` |
| Clock OUT | `api/scan_attendance.php` | `:255` |
| Overtime state machine | `api/scan_attendance.php` | `:291–435` |
| Manual attendance CRUD | `api/attendance.php` | GET `:56`; POST `:334`; PATCH `:395`; PUT `:447`; DELETE `:509` |
| Locked-row display | `api/attendance.php` | locked flags `:310–320`; UI `assets/js/attendance.js:342–387` |
| Lock enforcement | `api/_payroll_helpers.php` | `PAYROLL_LOCKED_RUN_STATUSES :606`; `assertAttendanceDateUnlocked :715` |
| QR token table | `sql/014_qr_tokens.sql` | `qr_tokens` |

**Tables:** `attendance`, `qr_codes`, `qr_tokens`, `employees`.

---

## 4. Overtime

| Feature | Primary code | Block |
|---|---|---|
| Employee filing | `employee_portal/api/me_overtime_requests.php` | constants `:24–33`; POST `:114`; demo mode `:122–140`; date/window checks `:150–190`; insert/audit `:200+` |
| Portal form | `employee_portal/attendance.html` OT section; `assets/js/attendance.js` | form/checkbox; submit `:385`; `demo_mode` payload |
| Admin list / detail | `api/overtime_records.php` | decorate `:29`; select `:72`; GET `:110` |
| Admin create/update/delete | `api/overtime_records.php` | POST `:207`; PUT `:275`; DELETE `:389` |
| Approve / reject | `api/overtime_records.php` | PATCH `:342`; Approver/Supervisor; lock check |
| Kiosk actual OT | `api/scan_attendance.php` | OT branch `:291–435`; window check in helpers |
| Window/pay cap | `api/_payroll_helpers.php` | constants `:122–123`; `overtimeWindowViolation :135`; `computeOvertimeHours :109` |

**Tables:** `overtime_records`; payslip `overtime_pay`.

---

## 5. Leave

| Feature | Primary code | Block |
|---|---|---|
| Employee portal | `employee_portal/api/me_leave_requests.php`; `employee_portal/leave.html`, `employee_portal/assets/js/leave.js` | GET `:25`; POST `:39`; cancel `:91` |
| Admin list/create/update/delete | `api/leave_requests.php` | GET `:35`; POST `:85`; PUT `:140`; DELETE `:273` |
| Approve / reject | `api/leave_requests.php` | PATCH `:174`; `FOR UPDATE`; own-request/team checks |
| Business-day count | `api/leave_requests.php:18`; portal copy `me_leave_requests.php:9` | Mon–Sat |
| Balances | `api/leave_balances.php`, `leave_balances.html` | GET `:9`; POST `:69` |
| Portal balance | `employee_portal/api/me_leave_balances.php` | GET |

**Tables:** `leave_requests`, `leave_balances`, `leave_types`.

---

## 6. Attendance corrections

| Feature | Primary code | Block |
|---|---|---|
| Employee filing | `employee_portal/api/me_attendance_corrections.php` | constants `:35–37`; GET `:97`; POST `:147`; date/reason/type `:155–230`; lock `:244`; insert `:255` |
| Portal UI | `employee_portal/attendance.html` correction form; `employee_portal/assets/js/attendance.js:153–315` | correction form section |
| Admin queue | `api/attendance_corrections.php` | helpers `:74–173`; GET `:184`; PATCH `:285` |
| Approval application | `api/attendance_corrections.php` | recompute attendance after approval; `assertAttendanceDateUnlocked` |

**Tables:** `attendance_corrections`, `attendance`.

---

## 7. Loans

| Feature | Primary code | Block |
|---|---|---|
| Admin list | `api/loans.php` | GET `:28` |
| Create loan / manual payment | `api/loans.php` | POST `:155` |
| Approve / recommend | `api/loans.php` | PATCH recommend `:90`; approve `:132` |
| Update / status / delete | `api/loans.php` | PUT `:264`; PATCH `:287`; DELETE `:303` |
| Portal loans | `employee_portal/api/me_loans.php` | GET `:12`; `loans.html`; `loans.js` |
| Payroll deduction | `api/payroll_runs.php` | loan block in POST; cap by remaining net |
| Void repayment repair | `api/payroll_void.php:83–95` | reopens a loan marked Paid |

**Tables:** `loans`, `loan_payments`.

---

## 8. Payroll periods

| Feature | Primary code | Block |
|---|---|---|
| Types + date helpers | `api/payroll_periods.php:11–27` | `PERIOD_TYPES`, `validPeriodDate`, `defaultPayDate` |
| List | `api/payroll_periods.php:30` | latest run/payslip count |
| Create | `api/payroll_periods.php:46` | overlap check, Open |
| Edit | `api/payroll_periods.php:93` | only Open |
| Open/Closed | `api/payroll_periods.php:148` | only Open/Closed periods |
| Status lifecycle | `api/payroll_runs.php` | POST → `For Approval`; return → `Returned`; approve/release → `Processed` |
| UI | `payroll_periods.html`, `assets/js/payroll_periods.js` | badges `:25`; actions `:53`; run `:109`; status `:239` |

**Table:** `payroll_periods`.

---

## 9. Payroll runs, approval, payslips and void

| Feature | Primary code | Block |
|---|---|---|
| List runs | `api/payroll_runs.php:38` | joins submitters/approvers |
| Approve / return / release / resubmit | `api/payroll_runs.php:72` | `:119` approve; `:131` return; `:150` release; `:162` resubmit |
| Process a period | `api/payroll_runs.php:195` | transaction; lock period `FOR UPDATE`; insert Submitted; generate payslips |
| Payslip totals/recompute | `api/_payroll_helpers.php` | `computePayslipDeductions :300`; `recomputePayslipTotals :348` |
| Payslip list/detail | `api/payslips.php` | PUT edit `:12`; GET run `:56`; GET id `:84` |
| Void | `api/payroll_void.php` | role `:24`; lock/released refusal `:41–55`; delete children/payslips/run `:77–96`; period Open `:100` |
| Run UI | `payroll_runs.html`, `assets/js/payroll_runs.js` | actions `:95`; exports `:129`; handle actions `:251`; run `:333` |

**Tables:** `payroll_runs`, `payslips`, `deductions`, `allowances`, `govt_contributions`, `loan_payments`, `cash_disbursements`.

---

## 10. Payroll computation engine

All in `api/_payroll_helpers.php`:

| Calculation | Symbol | Line |
|---|---|---|
| Working days / work week | `isWorkingDay`, `countWorkingDays`, `workDaySql` | `:20–62` |
| Day type / holiday | `holidayTypeForDate`, `dayTypeForDate` | `:66–83` |
| Premium multipliers | `firstEightMultiplierForDayType`, `overtimeMultiplierForDayType` | `:85–107` |
| Overtime hours | `computeOvertimeHours` | `:109` |
| OT window + pay cap | `overtimeWindowViolation` | `:135` |
| Premium pay | `computePremiumPay` | `:207` |
| SSS / PhilHealth / Pag-IBIG | `computeSSS`, `computePhilHealth`, `computePagIbig` | `:264–280` |
| Withholding tax | `computeWithholdingTax` | `:282` |
| Payslip deductions | `computePayslipDeductions` | `:300` |
| Recompute line totals | `recomputePayslipTotals` | `:348` |
| Holidays in range | `countHolidaysInRange` | `:465` |
| Absences / tardiness / undertime | `computeAttendanceDeductions` | `:471` |
| Payroll lock helpers | `payrollRunIsLocked`, `assertPayrollRunUnlocked`, `assertPayslipUnlocked` | `:606–660` |
| Date lock | `attendanceDateLock`, `assertAttendanceDateUnlocked` | `:688–725` |
| 13th month | `compute13thMonthPay` | `:727` |

---

## 11. Reports and exports

| Feature | Primary code | Block |
|---|---|---|
| Payroll register | `api/reports.php` | REPORT 1 `:16–78` |
| Government remittance | `api/reports.php` | REPORT 2 `:82–120` |
| Employee payslip history | `api/reports.php` | REPORT 3 `:124–168` |
| Department cost | `api/reports.php` | REPORT 4 `:172–237` |
| Payroll summary | `api/reports.php` | REPORT 5 `:241–299` |
| Reports UI | `reports.html`, `assets/js/reports.js` | summary render `:73`; tiles `:102–115` |
| Bank file / register / remittance | `api/bank_file.php` | helpers `:42–128`; run lookup `:176`; format branches `:212+`; CSV send `:122` |
| Exports UI | `payroll_runs.html`, `assets/js/payroll_runs.js` | `:129–199` |
| Ledger | `api/ledger.php`, `ledger.html` | attendance/leave/balance walk `:38–100` |

**Tables:** `payslips`, `govt_contributions`, `employees`, `attendance`, `leave_requests`, `leave_balances`.

---

## 12. Employee portal

| Feature | Page / JS | API |
|---|---|---|
| Login/logout | `employee_portal/login.html`, `employee_portal/assets/js/login.js`; sidebar `employee_portal/assets/js/api.js:57` | `employee_portal/api/auth.php:11–100` |
| Dashboard | `employee_portal/index.html`, `employee_portal/assets/js/dashboard.js` | `employee_portal/api/me_dashboard.php` — Latest Released payslip `:12–21`; attendance `:24`; leave `:38–56` |
| Payslips (Released only) | `employee_portal/payslips.html`, `employee_portal/assets/js/payslips.js` | `employee_portal/api/me_payslips.php:10–24` list; `:27–59` detail |
| Attendance history | `employee_portal/attendance.html`, `employee_portal/assets/js/attendance.js` | `employee_portal/api/me_attendance.php` |
| QR token | `employee_portal/attendance.html` QR card | `employee_portal/api/me_qr.php` |
| Overtime filing | `employee_portal/attendance.html` OT form, `employee_portal/assets/js/attendance.js:385` | `employee_portal/api/me_overtime_requests.php` |
| Leave | `employee_portal/leave.html`, `employee_portal/assets/js/leave.js` | `employee_portal/api/me_leave_requests.php`, `me_leave_balances.php` |
| Loans | `employee_portal/loans.html`, `employee_portal/assets/js/loans.js` | `employee_portal/api/me_loans.php` |
| Corrections | `employee_portal/attendance.html` correction form | `employee_portal/api/me_attendance_corrections.php` |
| Profile | `employee_portal/profile.html`, `employee_portal/assets/js/profile.js` | `employee_portal/api/me_profile.php` |
| Portal clocking forbidden | no UI | `employee_portal/api/me_scan_attendance.php` → 403 |

**Tables:** `users`, `employees`, `attendance`, `qr_codes`, `qr_tokens`, `overtime_records`, `leave_requests`, `leave_balances`, `loans`, `loan_payments`, `payslips`, `govt_contributions`.

---

## 13. Database migrations

| Migration | Feature |
|---|---|
| `002_overtime_approval_workflow.sql` | OT approval states |
| `003_audit_log.sql` | Audit table |
| `004_employee_contact_fields.sql` | Employee contact fields |
| `005_payroll_approval_workflow.sql` | Run approval/release columns |
| `006_employee_bank_fields.sql` | Bank file fields |
| `007_attendance_corrections.sql` | Corrections |
| `008_supervisor_teams.sql` | Supervisor scope |
| `009_holidays.sql` | Holiday calendar |
| `010_one_run_per_period.sql` | Unique run per period |
| `011_allowance_taxability.sql` | Taxable allowance flag |
| `012_scrub_audit_sensitive.sql` | Audit redaction |
| `012_seed_users.sql` | Seeded demo users |
| `013_premium_ot_request_undertime.sql` | Premium/OT request/undertime |
| `014_qr_tokens.sql` | Short-lived QR tokens |
| `payroll_db_schema.sql` | Current schema snapshot |
| `payroll_db_recovery.sql` | Schema + data recovery dump |

---

## 14. Tests — where each claim is proven

Tests live outside the two app repos, in:

```text
C:\xampp\master_file_tests
```

| Area | Suite |
|---|---|
| Roles / endpoint matrix | `role_matrix_test.php` |
| Payroll computation | `full_payroll_test.php`, `premium_undertime_test.php`, `logic_test.php` |
| Payroll approval / release / void | `payroll_approval_test.php`, `payroll_run_test.php`, `payroll_void_test.php` |
| Attendance / kiosk / QR | `manual_qr_test.php`, `ot_window_test.php`, `kiosk_exit_auth_test.php` |
| Overtime workflow | `overtime_workflow_test.php` |
| Leave / corrections | `leave_*_test.php`, `attendance_correction_test.php` |
| Supervisor scope | `supervisor_scope_test.php` |
| Loans / money guards | `money_guard_test.php` |
| Portal | `employee_portal_test.php`, `me_selfservice_test.php` |
| Reports / bank file | `bank_file_test.php` |
| Audit / security | `audit_log_test.php`, `security_test.php` |
| Separation of duties | `sod_guard_test.php` |

Last full clean run: **1817 passed, 0 failed**.

---

## 15. Suggestions

1. **Keep this as a map, not a LOC document.** Line numbers are useful for a defence walkthrough, but they drift after every edit. The function names and blocks are the stable references.
2. **Add this file to the docs index** (`README.md` / `DEFENSE_GUIDE.md` section 13) so it is not a hidden appendix.
3. **Regenerate the line index with a script** when a feature is added. A small `tools/code_map.ps1` can print `file:line function` for PHP/JS; do not maintain 200 line numbers by hand.
4. **Do not present LOC as coverage.** A feature can have many lines and still miss a case; pair every feature with its test suite instead.
5. **Add a “page → script → endpoint → table” column** for new modules. That is the chain that makes a feature work end to end.
6. **Exclude vendor/minified files** (`axios.min.js`, `sweetalert2.min.js`, QR libraries) from any LOC count.
7. **Update the commit hash at the top whenever this map changes** so a reviewer knows which snapshot the lines refer to.
