# UNITOP — System Test Results

**Run date:** 2026-10-21
**Environment:** XAMPP, PHP 8.2.12, MariaDB `payroll_db`
**Test driver:** PHP API suites + headless Chrome smoke tests.

## Final result

| Layer | Result |
|---|---|
| Full API suite on a clean fixture | **1764 passed, 0 failed** |
| Full API suite on the live fixture | 1759 passed, **8 fixture-dependent failures** |
| Browser smoke pages | **11/11 loaded** |
| Application exceptions in Apache error log | none during the run |
| Database restore | exact pre-test backup restored |

## Full API suite

All 25 test files passed against the clean snapshot:

```text
attendance_correction_test.php   146/0
audit_log_test.php                78/0
bank_file_test.php               212/0
employee_portal_test.php          51/0
full_crud_test.php                71/0
full_payroll_test.php             63/0
http_test.php                     63/0
kiosk_exit_auth_test.php           6/0
leave_birthday_test.php            9/0
leave_conflict_msg_test.php       11/0
logic_test.php                    58/0
manual_qr_test.php                14/0
me_selfservice_test.php          116/0
money_guard_test.php              35/0
nav_matrix_test.php               37/0
ot_window_test.php                25/0
overtime_workflow_test.php        38/0
payroll_approval_test.php         83/0
payroll_run_test.php              21/0
payroll_void_test.php             23/0
premium_undertime_test.php        17/0
role_matrix_test.php             328/0
security_test.php                 29/0
sod_guard_test.php                16/0
supervisor_scope_test.php        214/0
```

### Live-fixture failures are test assumptions

The live fixture has bank details on Employee 4, three assigned Supervisor team links, an existing Released payroll run, and existing leave/loan/correction rows. These make eight assertions from five suites fail because those suites expect a clean fixture:

```text
bank_file_test.php          employees 4/5/8 have no bank details (current data does)
leave_birthday_test.php     existing leave data
logic_test.php              existing payslip/period state
role_matrix_test.php        Released run locks today's kiosk scan
supervisor_scope_test.php   employees 4/5/8 have no supervisor_id (current data does)
```

Re-running the same files against the clean snapshot gives **1764/0**. This is test isolation, not an application failure.

## Browser smoke tests

Loaded and rendered successfully with headless Chrome:

- Admin — Payroll Officer dashboard
- Admin — Payroll Runs
- Admin — Loans as maker
- Admin — Loans as Approver
- Supervisor — team Dashboard
- Supervisor — Overtime filing form
- Administrator — Audit Log
- Portal — Dashboard
- Portal — Payslips
- Portal — Attendance
- Kiosk

Screenshots were inspected for layout and data. The kiosk camera itself is not testable in headless Chrome; the kiosk page and API scan paths are covered by `manual_qr_test.php`, `ot_window_test.php`, and `kiosk_exit_auth_test.php`.

## Defects found and fixed during testing

| Defect | Fix |
|---|---|
| Approver had no **Approve** button on a Pending loan | Added the Approver branch and hid maker-only actions |
| Correction approval did not ask for or save remarks | Added an optional remarks prompt and sent `remarks` |
| Portal attendance showed corrupted punctuation and a washed-out Demo date panel | Fixed UTF-8 bytes and changed the panel to theme variables |
| Supervisor Recent Team Overtime displayed 24-hour times | Formatted as 12-hour AM/PM |
| Kiosk Back button could reveal the previous admin page | Added history trap; documented Chrome kiosk/Assigned Access |
| Checklist used 24-hour times | Converted to 12-hour |

## Evidence and limitations

- The clean-suite run is the authoritative application result: **1764/0**.
- The live data was intentionally non-clean because it contains user test records.
- The phone/browser hotspot and physical camera were not automated; those are environment tests.
- The Bank file currently refuses export if any included employee lacks bank details; this is intentional.
- Security hardening (MySQL root/blank password, HTTP, demo flags) was reviewed separately and still needs deployment changes before production.

## Restore

The pre-test database backup is:

```text
C:\xampp\master_file_tests\before_agent_full_test.sql
```

Restore:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root payroll_db < C:\xampp\master_file_tests\before_agent_full_test.sql
```
