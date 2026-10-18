# Data-integrity & API-contract audit — `payroll_db` (admin + employee portal)

**Date:** 2026-10-18
**Scope:** `C:\xampp\htdocs\master_file_module_api` (admin) and `C:\xampp\htdocs\employee_portal` (portal), both on MariaDB `payroll_db` (root, no password).
**Mode:** read-only. No file in either application was modified; no migration, no commit, no fix. The only file written is this report.

---

## 0. Method and evidence base

| What | How |
|---|---|
| Schema, FKs, delete rules, indexes, `sql_mode` | `information_schema` + `SHOW` via `mysql.exe` |
| Every `api.get/post/put/patch/delete(...)` and `fetch(...)` target | extracted with `Select-String`, matched against the files on disk |
| Every field each JS file reads | read in full, compared to each endpoint's `respond()` payload |
| Every `getElementById()` id used by a page script | cross-checked against its HTML (all 22 admin pages, all 7 portal pages) |
| Column coercion (`''`, `'abc'`, `'2026-13-45'` into DATE / INT / DECIMAL; PDO return types) | executed read-only via `C:\xampp\php\php.exe` and a session-scoped `TEMPORARY TABLE` (no table outside the session was touched) |

Two runtime facts drive several findings and were **verified**, not assumed:

```
sql_mode = NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION   -- no STRICT_TRANS_TABLES
INSERT '' / 'abc' / '2026-13-45' into a DATE column  ->  '0000-00-00'   (warning, not error)
INSERT '' into an INT column                          ->  0
INSERT '' into a DECIMAL(12,2) column                 ->  0.00
PDO fetch: INT -> int, DECIMAL -> string ("60000.00")
```

**Limit of the evidence:** no endpoint was executed over HTTP (there is no test account I could use without writing to the DB). Findings 1–12 are proven from source + schema + the probes above; nothing in this report is a guess about runtime behaviour that the code does not force.

---

## 1. Headline: the "blank dropdown" class of bug is currently absent

The specific failure mode that shipped before (`e.id` / `e.label` read from a payload that returned `employee_id` / `name`) does **not** exist in either app today. Every dropdown source returns the keys its consumer reads, and every `data.data` / `data.data.rows` / bare-array unwrap matches the endpoint:

| Dropdown / list source | Returns | Consumers read | Verdict |
|---|---|---|---|
| `employees.php?simple=1` | `id`, `label` | `departments.js:21`, `employees.js`, `attendance.js:301`, `attendance_corrections.js:158`, `leave.js:44`, `ledger.js:15`, `loans.js:40`, `qr_codes.js:16`, `reports.js:43` | ✓ |
| `departments.php?simple=1` | `id`, `label` | `employees.js:42`, `positions.js:21` | ✓ |
| `positions.php?simple=1` | `id`, `label` | `employees.js:40` | ✓ |
| `employees_lookup.php` | `id`, `label`, `employee_id` | `users.js:27`, `users.js:88` | ✓ |
| `leave_types.php` | `leave_type_id`, `name` | `leave.js:57`, `leave_types.js`, `leave_requests.js:29` | ✓ |
| `me_leave_types.php` (portal) | `leave_type_id`, `name` | `leave.js:69` | ✓ (see I-11 for its fallback) |
| `payroll_periods.php` | `period_id`, `period_type`, `start_date`, `end_date` | `payroll_runs.js:75`, `payroll_periods.js:99` | ✓ |
| `payroll_runs.php` | `run_id`, `period_type`, `start_date`, `end_date`, `payslip_count` | `reports.js:31` | ✓ |

Response-shape audit (all 32 admin endpoints + 12 portal endpoints, every consumer): **zero** `data`↔`data.rows` mismatches, **zero** `<entity>_id`↔`id` mismatches, **zero** `name`↔`full_name` mismatches, **zero** int↔string arithmetic bugs (every money/decimal field is a JSON string and every consumer routes it through `Number()`/`formatMoney()`).

**Zero missing endpoints on a live page path.** Every URL a page can call exists on disk. The one `api/roles.php` call is in `assets/js/roles.js`, which no page loads (I-14).

---

## 2. Findings

Severity legend: **HIGH** = wrong money/payroll data or a lost/duplicated financial record; **MEDIUM** = wrong non-financial data, a 500 where a 422 belongs, or a user-visible broken flow; **LOW** = latent, cosmetic, or dead code.

---

### I-01 · HIGH · Two payroll runs for one period, doubling payslips and loan payments
**Both sides:** `api/payroll_runs.php:197-204` (the `Open` test) and `api/payroll_runs.php:209` (where the transaction opens) — the guard runs *before*, and *outside*, the transaction, and the `SELECT` has no `FOR UPDATE`.

**What is wrong.** The period is read with a plain autocommit `SELECT`, its status is tested, and only then does `beginTransaction()` start. Two concurrent `POST payroll_runs.php` calls (double-click on **▶ Run Payroll** in `payroll_periods.js:143`, or two officers) both observe `status = 'Open'`, both pass the test, and both run the full generation loop: `INSERT payroll_runs` (:271), `INSERT payslips` (:282), `INSERT loan_payments` (:323), `INSERT deductions`, then both set the period `Processed` (:449). There is **no `UNIQUE` index on `payroll_runs.period_id`** (verified in `information_schema.STATISTICS`: only `PRIMARY`, `fk_payroll_runs_period` non-unique, `idx_payroll_runs_status`, `idx_payroll_runs_submitted_by`), so nothing in the database stops it.

**What the user would see.** Two runs for the same period, two payslip sets per employee, a doubled **Net Pay** in `reports.php?report=remittance` (it sums every payslip in every overlapping period), and — worse — each loan amortization booked twice in `loan_payments`, so a loan is repaid and marked `Paid` at double speed. Nothing in the UI flags it.

**Minimal fix.** Open the transaction first, then lock the period row:
```php
$pdo->beginTransaction();
$stmt = $pdo->prepare("SELECT ... FROM payroll_periods WHERE period_id = ? FOR UPDATE");
```
and add `ALTER TABLE payroll_runs ADD UNIQUE KEY uq_payroll_runs_period (period_id);` so a second run is impossible even if the code regresses.

---

### I-02 · HIGH · Double approval of one leave request deducts the balance twice
**Both sides:** `api/leave_requests.php:197-205` (the `SELECT` that reads `status`) and `api/leave_requests.php:249-253` (`UPDATE leave_balances ... remaining_days = remaining_days - ?`).

**What is wrong.** `PATCH leave_requests.php` correctly wraps its work in a transaction, but the request row is read with a **plain, non-locking** `SELECT` inside `REPEATABLE READ`, so both concurrent transactions see `status = 'Pending'` (the `if ($lr["status"] !== "Pending")` test at :211 passes for both). The balance check at :244 also uses that stale snapshot. Both then execute the relative decrement — which is not idempotent — and both commit `status='Approved'`. Compare `api/attendance_corrections.php:288-290`, which does this correctly with `... FOR UPDATE`; the leave path is the one that was missed.

**What the user would see.** One approval, two days deducted from the leave balance (a 3-day leave costs 6). The `Insufficient leave balance` refusal can be bypassed the same way. The admin approve button is a normal button on a queue that two officers can have open at once.

**Minimal fix.** `SELECT ... FROM leave_requests lr JOIN leave_types lt ... WHERE lr.leave_id = ? FOR UPDATE`. Same class, same fix, for the overlapping-request guard at `api/leave_requests.php:107-131` and `employee_portal/api/me_leave_requests.php:59-83`: both are check-then-insert with no unique constraint behind them, so two concurrent filings of overlapping dates both succeed.

---

### I-03 · HIGH · Attendance edits bypass the payroll lock that corrections enforce
**Both sides:** `api/attendance.php:419-464` (`PUT`) and `api/attendance.php:467-472` (`DELETE`) vs `api/attendance_corrections.php:361` (`$lock = attendanceDateLock($pdo, $workDate);`).

**What is wrong.** `attendance.php` includes `_payroll_helpers.php` at line 3, so `attendanceDateLock()` / `assertAttendanceDateUnlocked()` are available — but neither write path calls them. The corrections endpoint refuses (409) any change to a date covered by an `Approved`/`Released` run; the attendance screen happily `UPDATE`s `time_in`, `time_out`, `hours_worked`, `minutes_late`, `status` (and even `employee_id`/`work_date`) on exactly the same date, and `DELETE`s the row outright. `scan_attendance.php` and `employee_portal/api/me_scan_attendance.php` have no lock either.

**What the user would see.** A payslip for an approved run says "2 absences, 3 late days"; the ledger and a later reprint disagree because the attendance rows behind it were edited afterwards. `reports.php?report=payroll_register` (which reads `payslips`, not `attendance`) and `ledger.php` (which reads `attendance`) silently diverge, and nothing tells the user the figures were signed off.

**Minimal fix.** In `PUT`: `assertAttendanceDateUnlocked($pdo, $workDay, "Attendance for $workDay")` before the UPDATE. In `DELETE`: read the row's `work_date` first, then assert, then delete.

---

### I-04 · HIGH · Demo mode lets any logged-in user stamp attendance on an arbitrary date
**Both sides:** `api/scan_attendance.php:16-20` (`demo_mode`/`demo_date`/`demo_time` accepted from the body), `:42` (`$today = $demoDate`), `:114` (INSERT with that date); `assets/js/attendance.js:158-162` (the client's Demo Mode checkbox), `assets/js/kiosk.js:106` (the same endpoint, no demo fields).

**What is wrong.** Demo mode is gated **only by a front-end checkbox** (`attendance.html` → `#demo-toggle`). The endpoint has no role check (it sits behind `requireLogin()` only, `api/_bootstrap.php:485`), never validates `demo_date` as a date, and never clamps it — so `work_date` can be any day, past or future, and `time_in` any time. An `Employee`-role account can reach it: `api/auth.php:20-42` does not restrict login by role, and `nav.js:72` allows `kiosk.html` for every role. The endpoint also bypasses I-03's lock, so attendance behind an approved run can be created this way.

**What the user would see.** Fabricated attendance rows appear for dates the employee was not at work, and those rows feed `computeAttendanceDeductions()` in the next payroll run (fewer absences → higher net pay) and the ledger.

**Minimal fix.** Require `requireRole(MAKER_ROLES)` when `demo_mode` is truthy, and validate:
```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $demoDate)) respond(false, "Invalid demo date.", null, 422);
if ($demoDate > date("Y-m-d"))                     respond(false, "Demo date cannot be in the future.", null, 422);
```
ideally compile demo mode out entirely (`const ALLOW_DEMO_SCAN = false;`).

---

### I-05 · MEDIUM · A blank optional Pay Date is stored as `0000-00-00`
**Both sides:** `assets/js/payroll_periods.js:212` (`pay_date: document.getElementById("pay_date").value` → `""`) and `payroll_periods.html:80` (`<input type="date" id="pay_date">`, not `required`) → `api/payroll_periods.php:41` (`$payDate = trim($in["pay_date"] ?? "")`) → `:63` (INSERT as-is). Same at `:75`/`:97` for `PUT`.

**What is wrong.** `defaultPayDate()` is defined at `api/payroll_periods.php:11-17` — and **never called**. The empty string is passed straight into a `DATE` column; this server is non-strict (see §0), so MariaDB stores `0000-00-00` with a warning instead of raising. There is no `CASE WHEN $payDate = '' THEN defaultPayDate($end)` anywhere.

**What the user would see.** Create a period without picking a pay date → the Pay Date column in `payroll_periods.js:87` renders `0000-00-00` (a non-empty string is truthy, so the intended `-` fallback never fires). Any later consumer of `pay_date` gets a zero date.

**Minimal fix.**
```php
if ($payDate === "") $payDate = defaultPayDate($end);
$dt = DateTime::createFromFormat("!Y-m-d", $payDate);
if (!$dt || $dt->format("Y-m-d") !== $payDate) respond(false, "Invalid pay date.", null, 422);
```
and the same validation for `start_date` / `end_date`.

---

### I-06 · MEDIUM · Portal correction form throws `ReferenceError: ymd is not defined` after a successful filing
**Both sides:** `employee_portal/assets/js/attendance.js:315` (`correctionDateEl.value = ymd(new Date());`) vs `employee_portal/assets/js/attendance.js:326` (`const ymd = (d) => ...` — declared **inside** `initCorrectionDates()`, so it is function-scoped and invisible to the submit handler).

**What is wrong.** The `submit` handler on `#correction-form` runs `api.post(...)`, shows the success toast, calls `correctionForm.reset()`, and then hits `ymd(...)`. Because `ymd` does not exist in that scope the handler aborts with a `ReferenceError`, so the three statements after it never run: `syncCorrectionInputs()`, `loadCorrections()`, and the date re-fill.

**What the user would see.** "Correction request filed" toast, then the work-date field goes blank, the requested-time inputs keep whatever enabled/disabled state the previous type gave them, and **the new request does not appear** in "My correction requests" — it looks like the filing failed until the page is reloaded. The request *was* filed, so the employee may file it again.

**Minimal fix.** Hoist the helper out of the closure (or use a plain expression at line 315):
```js
correctionDateEl.value = new Date().toISOString().slice(0, 10);
```
`pad`/`ymd` are already duplicated as a module-level pattern in `dashboard.js`; a single shared `todayISO()` in `api.js` is the tidier fix.

---

### I-07 · MEDIUM · Duplicate attendance rows are possible; three check-then-insert sites, no unique key
**Both sides:** `api/attendance.php:329-333` (guard) → `:341-350` / `:358-367` (insert); `api/scan_attendance.php:88-95` (guard) → `:108-114` (insert); `employee_portal/api/me_scan_attendance.php:51-57` (guard) → `:69-74` (insert).

**What is wrong.** All three read "does a row exist for employee+date?" and then insert, with no transaction and **no `UNIQUE(employee_id, work_date)`** on `attendance` (verified: the table has only `PRIMARY`, `fk_attendance_employee`, `fk_attendance_qr_code`). Two submissions inside the same second — a kiosk camera double-fire that beats the 3-second session guard, or the manual fallback clicked twice — produce two rows for one day.

**What the user would see.** `computeAttendanceDeductions()` (`api/_payroll_helpers.php:197-206`) counts *rows*, so a duplicated day credits two "present" days: an absence disappears and the payslip overpays. `api/scan_attendance.php:88-93` picks `ORDER BY attendance_id DESC LIMIT 1`, so the clock-out lands on the newer duplicate and the older one is stuck at `Clocked In` forever.

**Minimal fix.** `ALTER TABLE attendance ADD UNIQUE KEY uq_attendance_employee_date (employee_id, work_date);` and map the resulting `23000` to a 409 in the three handlers. (Clean up any existing duplicates first — check with `SELECT employee_id, work_date, COUNT(*) FROM attendance GROUP BY 1,2 HAVING COUNT(*) > 1`.)

---

### I-08 · MEDIUM · Unvalidated foreign keys produce a 500 and no audit row (6 endpoints)
**Both sides:** the writes have no `try/catch` and no existence check:
- `api/leave_requests.php:127-131` (`leave_type_id`, `employee_id`) and `:160-165` (`PUT`)
- `api/loans.php:227-231` (`employee_id`)
- `api/overtime_records.php:207-213` (`employee_id`)
- `api/salary_rates.php:34-38` (`employee_id`)
- `api/positions.php:40-41` (`department_id`)
- `api/departments.php:45-46` (`manager_employee_id`)

**What is wrong.** Every referenced column is a `RESTRICT` FK. A stale id (a dropdown fetched before someone else deleted the row, or a direct API call) raises `PDOException 23000`. `PDO::ERRMODE_EXCEPTION` is on (`config.php:14`) and nothing catches it, so PHP emits a fatal error instead of JSON. `respond()` never runs — which also means **`audit_log_mutation()` never writes a row**, so the attempt leaves no trace in `audit.html`.

**What the user would see.** `apiErrorMessage()` (`assets/js/api.js:34-36`) falls back to `err.message` → the toast reads **"Request failed with status code 500"**. No hint which field was wrong.

**Minimal fix.** One shared guard before each insert, mirroring `api/qr_codes.php:33-37`:
```php
if (!$pdo->query("SELECT 1 FROM leave_types WHERE leave_type_id = " . (int)$typeId)->fetchColumn())
    respond(false, "That leave type no longer exists. Reload the page.", null, 422);
```
or wrap each write in `try { ... } catch (PDOException $e) { if ($e->getCode()==='23000') respond(false, "…", null, 422); throw $e; }`.

---

### I-09 · MEDIUM · Balance initialisation is not transactional: partial seed + 500 on a concurrent click
**Both sides:** `api/leave_balances.php:80-101` (per-row `INSERT ... WHERE NOT EXISTS`, no transaction) and `assets/js/leave.js:318` / `assets/js/leave_balances.js:72` (the **Initialize Balances** button).

**What is wrong.** The `NOT EXISTS` guard and the insert are two statements per row, looped over every employee × leave type. `leave_balances` carries `uq_leave_balances (employee_id, leave_type_id, year)` (verified), so two officers clicking Initialize at the same time — or one double-click — make the second request's guard pass and then fail the unique key. The exception is uncaught, so the request dies **after** an arbitrary number of rows have already been committed by autocommit.

**What the user would see.** A 500, an unknown number of employees seeded, no message saying how many. Re-clicking fixes the remainder, but the run is never reported accurately.

**Minimal fix.** `INSERT IGNORE INTO leave_balances (...) SELECT ...` (the unique key then makes it naturally idempotent) inside a single transaction, and `$pdo->commit()` before responding with `rowCount()`.

---

### I-10 · MEDIUM · QR regeneration revokes before it inserts, outside a transaction
**Both sides:** `api/qr_codes.php:40-41` (`UPDATE qr_codes SET status='Revoked'`) then `:45-49` (`INSERT ... 'Active'`).

**What is wrong.** Two statements, no transaction. If the insert fails (deadlock, disk, a `code_value` collision — it is uniquely indexed), the employee is left with **zero** active QR codes.

**What the user would see.** The employee cannot clock in at all: the kiosk answers "This QR code has been revoked." and `employee_portal/api/me_qr.php:23-24` answers 404, so their portal QR panel shows "No QR code on file". Nothing records that the regenerate half-failed.

**Minimal fix.** Wrap both statements in `beginTransaction()` / `commit()` (roll back on failure), or insert the new code first and revoke the others afterwards.

---

### I-11 · MEDIUM · Hard-coded leave-type fallback supplies ids that may not exist
**Both sides:** `employee_portal/assets/js/leave.js:71-81` (catch block builds `<option value="1">Vacation Leave</option>` … `5`) → `employee_portal/api/me_leave_requests.php:79-83` (INSERT with that `leave_type_id`).

**What is wrong.** `api/me_leave_types.php` exists and works, so the fallback should be unreachable — but it is the exact bug the endpoint's own header comment (`me_leave_types.php:5-9`) says was fixed: ids 1–5 are invented, not read from `leave_types`. Any transient failure (session expiry mid-load, a 500, a slow request) silently installs a dropdown whose ids point at nothing or, worse, at *different* types than the labels claim.

**What the user would see.** Today: the request is refused with "Request failed with status code 500" (unsatisfied `fk_leave_requests_type`, per I-08). Worse case: id 3 happens to be `Maternity Leave`, and the employee who picked "Maternity Leave" gets a different type approved.

**Minimal fix.** Delete the fallback; on error render `-- could not load leave types --` and disable submit. Never synthesise ids on the client.

---

### I-12 · MEDIUM · Overtime writes are not payroll-locked
**Both sides:** `api/overtime_records.php:241-246` (`PUT ot_date / ot_type / rate_multiplier`), `:282-286` (`PATCH` approve/reject), `:296-302` (`DELETE`).

**What is wrong.** The same gap as I-03, in a table payroll *does* price: `api/payroll_runs.php:304-311` sums `hours × rate_multiplier × hourlyRate` for `ot_date BETWEEN period_start AND period_end`. None of these three handlers calls `attendanceDateLock()` / `assertPayrollRunUnlocked()`, so `rate_multiplier` (and `ot_date`, which moves the row into or out of a period) can be changed after the run was approved. `PUT` is blocked once `time_in` is set (`:237`), which is the only protection.

**What the user would see.** An approved payslip's overtime differs from every live calculation, with nothing to explain it.

**Minimal fix.** In `PUT`/`PATCH`/`DELETE`, resolve the row's `ot_date` and call `assertAttendanceDateUnlocked($pdo, $otDate, "Overtime for $otDate")`.

---

### I-13 · LOW · Ledger totals do not reconcile against a pending leave day
**Both sides:** `api/ledger.php:191-204` (a pending leave day is emitted with `event = "Leave"`) vs `:136` (`$totals["working_days"]++`) and `:175-189` (only *Approved* leave increments `$totals["leave"]`).

**What is wrong.** A working day covered by a `Pending` leave is rendered as a Leave row but added to **no** bucket: `present + late + leave + absent < working_days`. `assets/js/ledger.js:144-148` prints the footer straight from those four counters.

**What the user would see.** "TOTALS (14 working days) — Present: 10 · Late: 1 · Absent: 2 · Leave: 0" — 13 of 14 accounted for, on a document that has a signature block.

**Minimal fix.** Add `$totals["pending_leave"]++` in that branch and render it in `ledger.js`.

---

### I-14 · LOW · Dead and broken assets (no page loads them — but they would 404 if wired up)
**Both sides:** `assets/js/roles.js:19,91,94,110` calls `roles.php`; **`api/roles.php` does not exist**, and no HTML page loads `roles.js` (no `roles.html`). `assets/js/leave_requests.js` is loaded by nothing — `leave_requests.html:6` is a `<meta http-equiv="refresh" content="0; url=leave.html">` stub. `api/me_attendance.php`, `api/me_leave_balances.php`, `api/me_leave_requests.php`, `api/me_payslips.php` and `api/cash_disbursements.php` in the admin app have **no caller in either app** (the portal uses its own copies).

**What is wrong / visible.** Nothing today. But `nav.js:56` still lists `salary_rates.html` in `NAV_ACCESS` while the file on disk is `salary_rates.html.bak`, and `canAccess()` returns `true` for any page **not** in the table (`nav.js:109`) — so `leave_types.html` and `leave_balances.html`, which are not in the table, are unrestricted by the nav gate (their APIs still enforce roles).

**Minimal fix.** Delete `roles.js`, `leave_requests.js` and the five unused admin `me_*`/`cash_disbursements` endpoints, or add `api/roles.php`. Add the two live pages to `NAV_ACCESS`.

---

### I-15 · LOW-MEDIUM · `employees.supervisor_id` is writable by nothing, so the whole Supervisor scope is dead
**Both sides:** `api/_bootstrap.php:382-483` (`supervisorTeamIds()` / `assertSupervises()` / `supervisorScopeIds()`) depends entirely on `employees.supervisor_id`; `api/employees.php:8-17` (`$fields`) **omits `supervisor_id`**, `api/employees.php:43-61` / `:71-95` are the only INSERT/UPDATE on `employees` in the admin app, and `employee_portal/api/me_profile.php:17-23` only touches contact columns.

**What is wrong.** No code path in either application ever sets `employees.supervisor_id` (grep across every `.php` and `.html`: only reads and comments). `employees.html` has no such field either.

**What the user would see.** A Supervisor account always resolves to an empty team: `attendance.html`, `overtime.html`, `attendance_corrections.html`, `leave.html`, `loans.html` all render empty, and every decide/recommend action answers 403 — "Employee #N is not in your team". The role looks broken. `users.js:175-182` even tells the admin that a Supervisor needs an employee link, which is necessary but not sufficient.

**Minimal fix.** Add `supervisor_id` to `employees.php` `$fields` (with a `SELECT employee_id … WHERE employee_id = ?` check, or it becomes another I-08 500), a `<select id="supervisor_id">` in `employees.html`, and one line in `employees.js` `FIELDS`. `fk_employees_supervisor` is already `ON DELETE SET NULL`, so an orphan is not a risk.

---

### I-16 · LOW · No self-protection on user administration
**Both sides:** `api/users.php:94-107` (`PATCH is_active`) and `:54-91` (`PUT` role) — both `requireRole(ADMIN_ROLES)` with no check on the target, called from `assets/js/users.js:146-156` and `:126-133`.

**What is wrong / visible.** The last active Administrator can deactivate themselves or demote themselves to `Employee`. The response is a success toast, the session keeps working, and the next page load answers 403 on `users.php` / `audit_log.php` with no way back except phpMyAdmin.

**Minimal fix.** Refuse when `$id === currentUser()['user_id']` and the change is deactivating, or when it would leave `SELECT COUNT(*) FROM users WHERE role='Administrator' AND is_active=1` at zero.

---

## 3. Categories checked and found clean

- **`respond()` between writes / missing rollback.** Clean. Every multi-statement write either commits before responding or rolls back before responding: `api/attendance_corrections.php:334-484`, `api/payroll_runs.php:93-179` and `:208-459`, `api/payslips.php:38-47`, `api/allowances.php:39-94`, `api/deductions.php:39-93`, `api/loans.php:186-209`, `api/reports.php` (read-only). The only uncaught-exception holes are the inserts in I-08/I-09/I-10.
- **`beginTransaction()` without `commit()` on a code path.** None found.
- **`SELECT … FOR UPDATE`.** Present where it matters in `payroll_runs.php:95` (PATCH state machine), `attendance_corrections.php:336` and `:394`. Absent in I-01/I-02, which is the finding.
- **Enum values outside the allowed set.** Clean where an enum exists: `users.php:33,66` (ALL_ROLES), `loans.php:223,267`, `payroll_periods.php:108`, `qr_codes.php:66`, `overtime_records.php:262`, `attendance_corrections.php:300`, `bank_file.php:165`. The status columns are `varchar`, not `ENUM`, so nothing is rejected by the database.
- **Booleans as `0/1`.** Clean. `is_active` arrives as a JSON number (verified: PDO returns PHP `int` for `TINYINT`), and `0` is falsy in every consumer — `departments.js:48,52,55`, `employees.js:69,75,78`, `leave_types.js:35,39,42`, `users.js:56,61,64`, `qr_codes.js:42`.
- **`''` vs `NULL` in comparisons.** Handled where it is load-bearing: `employees.php:61,87` (blank → NULL), `attendance.php:394` (`time_out !== null`), `me_profile.php:122-123`, `payroll_runs.php:105` (`submitted_by !== null`), `ledger.php:77`, `reports.php:106`. The exception is I-05.
- **NULL returned where JS expects a number/array.** Clean. Every nullable aggregate is `COALESCE`d server-side (`attendance.php:215-216`, `dashboard.php:50-52,62`, `reports.php:38-41,94-101,183-187`), and every nullable object is guarded (`dashboard.js:84-89`, `overtime.js:50-56`, `attendance_corrections.js:76-82`, `payslips.js:128-129`, `portal payslips.js:64-65`).
- **Orphan risk on delete.** `ON DELETE SET NULL` is used exactly twice: `attendance_corrections.attendance_id` (correct — the correction survives, `api/attendance.php:470` can delete the row) and `employees.supervisor_id` (correct). Everything else is `RESTRICT`, so a delete cannot orphan a child — but it can produce I-08's 500: `DELETE attendance.php` fails with 500 if a `deduction` row points at it (`fk_deductions_attendance` is RESTRICT), and `DELETE loans.php` is already guarded at `:311-315`.
- **`users.employee_id` has no FK.** Verified in `information_schema`. A hard-deleted employee would leave a dangling link, but `employees.php` only ever soft-deletes (`PATCH is_active`), so this is latent, not live.
- **Path/404 audit.** Every `api.*()` and `fetch()` target across both apps resolves to a file on disk except `roles.php` (I-14). Both `api.js` files use `baseURL: "api/"` and every page lives at the app root, so no relative-path breakage.

---

## 4. Counting

| Severity | Count |
|---|---|
| HIGH | 4 (I-01 … I-04) |
| MEDIUM | 8 (I-05 … I-12) |
| LOW | 4 (I-13 … I-16) |
| **Total** | **16** |

Contract-matrix mismatches: **0** (one client-only `ReferenceError`, I-06). Missing endpoints on a reachable path: **0** (one on a dead path, I-14).
