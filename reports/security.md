# Security & Authorization Audit — Admin + Employee Portal (shared `payroll_db`)

**Date:** 2026-10-18
**Scope:** `C:\xampp\htdocs\master_file_module_api` (admin, `http://localhost/master_file_module_api/`) and
`C:\xampp\htdocs\employee_portal` (portal, `http://localhost/employee_portal/`), PHP 8.2.12, MariaDB `payroll_db` (root, no password).
**Mode:** READ-ONLY. No file, schema or data was modified and nothing was "fixed". No migrations run, nothing committed.

## Method, and what this audit actually verified

* Every file in both `api/` directories was read end-to-end (admin: 33 files incl. helpers; portal: 13 files), plus both
  `config.php`, both `_bootstrap.php`, `nav.js` / `login.js` / `api.js` / `kiosk.js` / `attendance.js`, and the
  `tests/` directory.
* Live verification was limited to **unauthenticated GETs** and one documented **read-only** script, because logging in
  writes an `audit_log` row (a DB mutation). Therefore every role-guard conclusion below is from **code reading**;
  where a request was made it is stated explicitly.
* Read-only SQL `SELECT`s were used to confirm live data (user roles/hash algorithm, audit-log contents, QR code states).
* `php -l` on sampled files: clean.

### Severity ranking summary

| # | Sev | Finding | File:line |
|---|-----|---------|-----------|
| 1 | **Critical** | Full DB snapshots (bcrypt hashes, salaries, bank accounts, TIN/SSS/PhilHealth/Pag-IBIG) publicly downloadable | `backups/*.sql`, `sql/payroll_db_recovery.sql`, `payroll_db (1).sql` |
| 2 | **Critical** | `tests/` scripts execute unauthenticated — salary disclosure proven; account creation / payroll processing from code | `tests/preview_payroll.php`, `tests/run_payroll_month.php`, `tests/_harness.php` |
| 3 | **High** | `scan_attendance.php` has **no role guard** and accepts `demo_mode` + arbitrary `demo_date`/`demo_time` | `api/scan_attendance.php:2,17,42,114` |
| 4 | **High** | No CSRF defence anywhere; form-encoded bodies are accepted → CSRF can create an Administrator | `api/_bootstrap.php:81-86`, `api/users.php:22` |
| 5 | **High** | Attendance writes ignore the Approved/Released payroll lock (`assertAttendanceDateUnlocked()` is dead code) | `api/attendance.php:341,405,437,470`; `api/_payroll_helpers.php:387` |
| 6 | **High** | `.git` of **both** apps served over HTTP → full source + history + DB credentials | `/.git/config`, `/.git/objects/`, `/.git/logs/HEAD` |
| 7 | **High** | `auth.php?action=verify` is a credential-confirmation oracle for any logged-in user; no rate limiting anywhere | `api/auth.php:124-155` |
| 8 | **Medium** | Pre-auth username (and role) enumeration in both logins | `employee_portal/api/auth.php:31-39`; `master_file_module_api/api/auth.php:30-35` |
| 9 | **Medium** | `PDOException` text / DB credentials leaked to clients; `display_errors=1` | `api/_bootstrap.php:75` + 10 endpoints |
| 10 | **Medium** | Audit log stores **live QR code values** (bearer credentials) and bank/government numbers in clear text | `api/_bootstrap.php:150-156,259-277` |
| 11 | **Medium** | Portal accepts attendance corrections up to **365 days in the future** | `employee_portal/api/me_attendance_corrections.php:163-166` |
| 12 | **Medium** | Session cookies: no `HttpOnly`, no `SameSite`, no `Secure`; `session.use_strict_mode=0` | `api/_bootstrap.php:13-18`, `employee_portal/api/_bootstrap.php:12-17` |
| 13 | **Low** | Deactivation / role change does not invalidate an existing session | `api/_bootstrap.php:304-314` |
| 14 | **Low** | `users.php` blank-password accounts get a 32-bit auto-generated password | `api/users.php:41` |

**Totals: 14 findings — 2 Critical, 5 High, 5 Medium, 2 Low.**
No SQL injection, no mass assignment, no `me_*` IDOR, and no Supervisor scope escape was found (see *Verified non-issues*).

---

# 1. CRITICAL — Database snapshots are publicly downloadable

**Files (all inside the admin document root, no `.htaccess` in either app; `http://localhost/master_file_module_api/` returns an Apache directory index):**

* `backups\payroll_db-before-del-payroll-20261018-155329.sql` (and 16 sibling dumps in `backups\`)
* `sql\payroll_db_recovery.sql`
* `payroll_db (1).sql` (administrator root)
* directory index at `/backups/`, `/sql/`, `/tests/` (all verified `HTTP 200`)

**What is wrong.** These are complete `mysqldump`s sitting in the web root with no authentication and no access
control. `payroll_db_recovery.sql` contains:

```
INSERT INTO `users` VALUES (4,'admin',NULL,'Administrator',1,'$2y$10$Ey5ttBp8vVD3tVHwjnZvN...'),
(5,'vinchy',4,'Employee',1,'$2y$10$Vl/K9Me...'),(34,'juan.delacruz',5,'Employee',1,'$2y$10$MGGVmr...')...
```

**Verification (live, unauthenticated):**

```
GET /master_file_module_api/.git/...                          -> 200
GET /master_file_module_api/sql/payroll_db_recovery.sql       -> 200, 63725 bytes, 6 bcrypt hashes, bank_account_number x2
GET /master_file_module_api/backups/payroll_db-before-del-payroll-20261018-155329.sql
                                                             -> 200, 64045 bytes, 6 bcrypt hashes
GET /master_file_module_api/backups/                          -> 200 Apache index listing all 17 dumps
```

**Exploitation.** No credentials needed. Fetch the directory index, download every dump, and you have: all six
accounts and their bcrypt hashes (offline-crackable; the seeded staff passwords are `test123` / `TestPass123`), every
employee's `basic_salary`, `bank_name` / `bank_account_number`, `tin_number`, `sss_number`, `philhealth_number`,
`pagibig_number`, plus all payslips, loans and payroll runs. That is the entire payroll dataset plus the credential
material — a single-request total compromise.

**Minimal fix.** Move `backups/`, `sql/` and the loose `*.sql` files out of `C:\xampp\htdocs` (or add
`<Directory>`/`.htaccess` `Require all denied` for `backups`, `sql`, `tests`, `\.git`), and stop writing dump files
into the document root.

---

# 2. CRITICAL — `tests/` directory executes unauthenticated (salary disclosure proven; account creation and payroll processing from code)

**Files:** `tests\preview_payroll.php`, `tests\run_payroll_month.php`, `tests\_harness.php:9-10,108-126`,
`tests\seed_demo_2employees.php`, `tests\seed_staff_accounts.php`, `tests\role_matrix_test.php`, `tests\defense_seed.php`,
`tests\seed_attendance_15.php`, `tests\repair_payslips.php` (29 `.php` scripts in the directory, index verified reachable).

**What is wrong.** None of these scripts has a CLI guard (`php_sapi_name`), a session guard, or `requireLogin()`.
They `require ../config.php` (or `_harness.php`, which opens a PDO to `payroll_db` directly) and act on the shared
database. They live under the served document root and the directory is browsable.

**Verification (live, unauthenticated, read-only script — no writes):**

```
GET http://localhost/master_file_module_api/tests/preview_payroll.php  -> 200
  "EMP-0001 Vinchy Fantonial (id 4)  basic_pay 30000.00 ... withholding_tax 2787.05 ...
   >> NET PAY 23127.38   ... EMP-0002 Juan Dela Cruz ... supervisor famacion John Famacion"
```

An anonymous visitor received every employee's salary, deduction breakdown and net pay.

**Exploitation (from code, not executed by me).** The same directory exposes scripts that mutate:

* `tests/run_payroll_month.php` — calls `mkUser('dsh_run_admin','Payroll Officer')`, then `login()`, then
  `POST payroll_runs.php` for **every Open period**: an anonymous GET processes payroll for the whole company.
* `tests/_harness.php:10,108-126` — `const TEST_PW = 'TestPass123'`; `mkUser()` inserts any role (including
  `Payroll Officer` / `Approver` / `Administrator`) with a hash of `TEST_PW` and registers the delete only in a
  shutdown cleanup that a partial/aborted request may skip.
* `tests/seed_demo_2employees.php` — creates the `juan.delacruz / test123` login.
* `tests/seed_attendance_15.php`, `tests/defense_seed.php` — rewrite attendance / overtime / leave across all employees.

Chained with finding 6 (`.git` discloses the source, hence `TEST_PW`), an anonymous attacker can mint a known-password
Payroll Officer account and then use the normal login at `login.html`.

**Minimal fix.** Move `tests/` outside the document root, or make every script start with
`if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`, and deny the directory in Apache.

---

# 3. HIGH — `api/scan_attendance.php` has no role guard, and `demo_mode` lets the caller pick the date and time

**File:line:** `api\scan_attendance.php:2` (`require __DIR__ . "/_bootstrap.php";` — the *only* guard, which resolves
to `requireLogin()`), `:15-19` (`demo_mode` / `demo_time` / `demo_date` from the body), `:42` (`$today` from
`demo_date`), `:46-48` (`$nowTime` from `demo_time`), `:114` (INSERT).

**What is wrong.** The effective guard is "any logged-in user". There is no `requireRole()` at all — the file's own
sibling comment (`api/attendance.php:15-16`) records this omission as intentional. It is not safe, for two reasons.

1. **The admin login accepts every role** (`api/auth.php:11-50` has no role check), and `assets/js/nav.js:72` opens
   `kiosk.html` to `Employee`. So the lowest-privileged user — or an `Administrator`, who by the matrix
   (`api/_bootstrap.php:42-45`) must never touch payroll data — obtains a session that satisfies `requireLogin()`.
2. **`demo_mode` is a server-side switch, not a UI flag.** `kiosk.js:106` never sends it, but the endpoint honours it
   regardless of who sends it. `demo_date` and `demo_time` are taken verbatim.

**Exploitation.** Log in with any account (e.g. an `Employee` account, or `admin`), then:

```
POST /master_file_module_api/api/scan_attendance.php
{"code_value":"UNITOP-<any known employee's code>","demo_mode":true,
 "demo_date":"2026-10-01","demo_time":"07:55:00"}
```

This creates a clean on-time clock-in for **any date in the past or future**, for **any employee whose QR value you
know** (badge values are printed and, per finding 10, also readable from the audit log by an `Administrator`). Repeat
with `time_out` to manufacture a full 8-hour day, or scan for a colleague. Attendance drives `hours_worked`,
`minutes_late`, absence deductions and overtime pay (`api/_payroll_helpers.php:177-268`,
`api/payroll_runs.php:343-373`), so this is direct payroll fraud, and it also bypasses the "at least 1 minute" and
duplicate-scan guards (both keyed on the simulated timestamp). Already present in the audit log as
`{"code_value":"UNITOP-21208AD52624A49DE01A00AA83CA113C","demo_mode":true,"demo_date":"2026-10-01"}`, and that code is
still `Active` for employee 4 (verified by `SELECT`).

**Minimal fix.** Add `requireRole(array_merge(TEAM_QUEUE_ROLES, ...))`-style gating and confine `demo_mode` to the
maker: e.g. immediately after the bootstrap,
`if (!empty($in['demo_mode'])) { requireRole(MAKER_ROLES); }` — or better, delete demo mode from the endpoint and pass
the simulated clock from a test-only CLI harness.

---

# 4. HIGH — No CSRF protection, and form-encoded bodies are accepted

**File:line:** `api\_bootstrap.php:81-86` and `employee_portal\api\_bootstrap.php:38-43` — `json_input()` falls back to
`$_POST` whenever the body is not valid JSON; no endpoint in either app checks a token, `Content-Type` or an
`X-Requested-With` header (`grep -i csrf` finds only the *audit masking* list).

**What is wrong.** Three weaknesses compose:

1. No anti-CSRF token on any state-changing endpoint (admin: `users.php`, `employees.php`, `payroll_runs.php`,
   `payroll_periods.php`, `leave_requests.php`, `loans.php`, `qr_codes.php`, `attendance.php`, `scan_attendance.php`,
   `allowances.php`, `deductions.php`, `cash_disbursements.php`, `salary_rates.php`, `auth.php` login/logout;
   portal: `me_leave_requests.php`, `me_scan_attendance.php`, `me_attendance_corrections.php`, `auth.php`).
2. `SameSite` is unset (finding 12), so the browser attaches the session cookie to a cross-site POST.
3. Because `json_input()` degrades to `$_POST`, an ordinary HTML form — which needs no CORS preflight — is a valid
   request. The JSON-only assumption is what silently supplies the missing CSRF protection.

**Exploitation.** Any page the victim visits while logged in can host:

```html
<form action="http://localhost/master_file_module_api/api/users.php" method="POST">
  <input name="username" value="backup">
  <input name="role"     value="Administrator">
  <input name="password" value="Passw0rd!">
</form><script>document.forms[0].submit()</script>
```

The administrator's cookie is sent, `$_POST` is populated, `users.php:22-51` creates a fully privileged account the
attacker knows the password to. With a Payroll Officer's cookie the same trick posts `period_id` to
`payroll_runs.php` and processes a payroll run; with a Supervisor's, `leave_requests.php` has no POST branch, but
`scan_attendance.php` accepts a form post too, so attendance can be forged remotely. Login CSRF is also possible
(`auth.php?action=login`), forcing a victim into the attacker's session.

*Caveat:* `PUT`/`PATCH`/`DELETE` endpoints are not form-reachable and no `Access-Control-Allow-Origin` is sent, so
those methods are not exploitable this way — the POST endpoints are.

**Minimal fix.** Require `Content-Type: application/json` in `json_input()` (reject form bodies) **and** send a
per-session token that every mutating request must echo, and set `session.cookie_samesite=Lax`.

---

# 5. HIGH — Attendance writes ignore the Approved/Released payroll lock

**File:line:** `api\attendance.php:341-350` (POST insert), `:405-413` (PATCH time-out), `:437-460` (PUT edit),
`:470` (DELETE); `api\scan_attendance.php:108-114,144-149,250-255`;
`api\_payroll_helpers.php:387-396` (`assertAttendanceDateUnlocked`) — **defined and never called anywhere**
(`grep` finds only the definition and the doc comment; the only live lock check is the manual
`attendanceDateLock()` call inside `api/attendance_corrections.php:361`).

**What is wrong.** The documented invariant (`api/_payroll_helpers.php:270-277`) is that a run becomes
immutable when a checker approves it and "every endpoint that can change ... calls one of these helpers BEFORE
writing". `attendance.php` and `scan_attendance.php` do not. The maker can therefore rewrite or delete the hours that
an already-approved payslip was calculated from — the exact bypass the correction workflow (`:359-370`) and the
portal (`me_attendance_corrections.php:244-253`) both refuse.

**Exploitation.** As Payroll Officer (or via CSRF against their session, finding 4):

```
DELETE /master_file_module_api/api/attendance.php?id=<row inside an Approved run>
PUT    /master_file_module_api/api/attendance.php  {attendance_id: N, employee_id: <any>, work_date: "2026-10-10",
                                                    time_in: "08:00", time_out: "20:00"}
```

The attendance row behind a signed-off payslip silently becomes a 12-hour day, and no audit entry records the old
values (the audit trail stores only the request body, `_bootstrap.php:259-277`). `PUT` additionally allows
re-pointing `employee_id`, moving a record from one employee to another.

**Minimal fix.** Call `assertAttendanceDateUnlocked($pdo, $workDate)` (and `assertAttendanceDateUnlocked` on the
DELETE's row date) at the top of each write branch in `attendance.php` and `scan_attendance.php`; delete the unused
helper or wire it up.

---

# 6. HIGH — `.git` is served for both applications

**File:line:** `C:\xampp\htdocs\master_file_module_api\.git\` and `C:\xampp\htdocs\employee_portal\.git\`;
no `.htaccess` in either tree (verified) and Apache has `AllowOverride All` for `C:/xampp/htdocs`.

**Verification (live, unauthenticated):**

```
GET /master_file_module_api/.git/config        -> 200  ([remote "origin"] url = github.com/vinchy1693/Unitop-Payroll-System.git)
GET /master_file_module_api/.git/HEAD          -> 200
GET /master_file_module_api/.git/objects/      -> 200  (50917-byte object index)
GET /master_file_module_api/.git/logs/HEAD     -> 200  (34270 bytes of reflog)
GET /employee_portal/.git/config               -> 200
```

**Exploitation.** Fetch `.git/objects/` and `.git/logs/HEAD`, then reconstruct any blob — including every `.php` file
(and therefore `config.php` with `root` / empty password), every value in `tests/_harness.php` (`TEST_PW =
'TestPass123'`), and anything ever committed then deleted (old dumps, old passwords). Source disclosure of this depth
turns the code-reading findings above into a published exploit guide and hands over the test-account password.

**Minimal fix.** `Require all denied` for `.git` (and `.gitignore`, `*.bak`, `*.sql`) in the vhost config; keep the
working copy outside `htdocs`.

---

# 7. HIGH — `auth.php?action=verify` is a credential oracle usable by any logged-in account; no rate limiting

**File:line:** `api\auth.php:124-155`.

**What is wrong.** The kiosk-exit check requires only `currentUser()` — any authenticated session, including an
`Employee` (who legitimately reaches `kiosk.html`, `nav.js:72`). It then re-verifies *any* username/password pair and
returns three distinguishable answers:

| Response | Meaning |
|---|---|
| `401 Invalid username or password.` | username unknown **or** password wrong |
| `403 Only an Administrator, Payroll Officer or Approver can exit the kiosk.` | **password correct**, role is Employee/Supervisor |
| `200 Verified` + `{"role": "..."}` | **password correct** and the account is payroll/admin — the role is disclosed |

There is no attempt counter, lockout, delay or CAPTCHA anywhere in either app (`grep -i 'attempt|lockout|throttle|
rate_limit|sleep'` in both `api/` trees returns only unrelated comments), and the login endpoints have the same gap.

**Exploitation.** From any employee account, spray a password list at `POST api/auth.php?action=verify` against
`username=admin` (and `payroll`, `approver`). The 403/200 answer is an unambiguous "this password is correct", with
the target's role attached, and it needs no logout or session juggling. That is precisely the oracle an attacker wants
before using the (equally unthrottled) real login. Existing audit rows show this endpoint being exercised
(`audit_id 157-162`, `action=auth.php`), so at least it is logged — but logging is not prevention.

**Minimal fix.** Restrict `verify` to the roles that may legitimately exit the kiosk
(`requireRole(array_merge(PAYROLL_ROLES, ADMIN_ROLES))`) so a low-privileged account cannot act as an oracle at all,
and add per-IP/per-username attempt throttling with backoff to `login` and `verify`.

---

# 8. MEDIUM — Pre-auth username (and role) enumeration in both logins

**File:line:** `employee_portal\api\auth.php:31-39`; `master_file_module_api\api\auth.php:30-35`.

**What is wrong.** Both logins check `is_active` — and the portal also checks `role !== 'Employee'` — **before**
`password_verify()`. So an unauthenticated attacker learns facts about an account without knowing its password:

* portal, `username=admin`, any password → `403 "This portal is for employees only. Please use the admin login."`
  → confirms `admin` exists **and** is not an Employee.
* either app, existing-but-disabled account → `403 "This account has been deactivated."` (a distinct message).
* non-existent username → `401 "Invalid username or password."`

**Exploitation.** Enumerate the staff roster, identify which usernames hold payroll/admin roles (the 403 text says so
outright), and target those with the oracle in finding 7. No password is needed for the enumeration step.

**Minimal fix.** Move `password_verify()` before the `is_active` / role checks and return the same
`"Invalid username or password."` 401 for every failure; keep the specific reason in the audit log only.

---

# 9. MEDIUM — `PDOException` / driver text and DB credentials reach the client; `display_errors=1`

**File:line:** `api\_bootstrap.php:69-79` (renders `"Database connection failed: " . $e->getMessage()`);
`api\attendance_corrections.php:483`; `api\payroll_runs.php:178,458`; `api\payslips.php:46`;
`api\allowances.php:59,93`; `api\deductions.php:59,93`; `api\loans.php:208`; `api\leave_requests.php:264`;
`api\audit_log.php:121`; `api\dashboard.php:118` — each `respond(false, "...: " . $e->getMessage(), null, 500)`.

**What is wrong.** Raw driver text is echoed into the JSON `message`. A `PDOException` typically carries
`SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x' in 'field list'` — table and column names, and for the
connection path `Access denied for user 'root'@'localhost'`. `php.ini` also has `display_errors=1`
(`C:\xampp\php\php.ini`, read via `php -r 'ini_get(...)'`), so any PHP warning/notice is injected into the body
alongside the JSON, disclosing absolute paths such as `C:\xampp\htdocs\master_file_module_api\...`.

**Exploitation.** Drive an endpoint into an error state (an over-long value past a column width, an invalid date
reaching `new DateTime()`, a `JSON` type mismatch) and read schema/version/paths out of the 500 body — reconnaissance
that shortens every other attack, and one of the few ways an attacker learns the internal file layout without `.git`.

**Minimal fix.** Log `$e->getMessage()` with `error_log()` and return a fixed message
(`"The request could not be completed."`); same for the bootstrap's connection error. Set `display_errors=0` and
`log_errors=1` for this virtual host.

*Not verified:* I did not trigger a fatal PHP error over HTTP, so "warnings render into the response" is inferred from
`display_errors=1`, not observed. The `$e->getMessage()` echoes are certain from code.

---

# 10. MEDIUM — The audit log stores live QR credentials and full bank/government numbers in clear text

**File:line:** `api\_bootstrap.php:150-156` (`audit_is_secret_key()` masks only keys containing
`password|passwd|token|csrf|secret|apikey`), `:259-277` (the whole request body is serialised into `details`);
`api\scan_attendance.php` (body carries `code_value`); `api\employees.php` (body carries `bank_*`, `tin_number`,
`sss_number`, ...).

**What is wrong.** The masking list misses `code_value`, `bank_account_number`, `tin_number`, `sss_number`,
`philhealth_number` and `pagibig_number`. `api/bank_file.php:26,56` deliberately keeps account numbers out of the
trail ("the audit trail is not a payroll register"), so the intent exists; the central hook contradicts it.

**Verification (live DB read):**

```
audit_id 144 | scan_attendance.php | {"code_value":"UNITOP-21208AD52624A49DE01A00AA83CA113C",
                                      "demo_mode":true,"demo_time":"19:05:00","demo_date":"2026-10-01"}
audit_id 151 | employees.php        | {"...","tin_number":"123-456-789-010","sss_number":"SS...","bank_account_number":...
```

`SELECT` confirms that `UNITOP-21208AD...` is `qr_code_id 1`, `employee_id 4`, **status `Active`**.

**Exploitation.** `api/audit_log.php` is Administrator-only (`:21`), and the Administrator is explicitly excluded from
payroll data — yet the trail hands them a working clock-in credential and every employee's bank/government numbers.
Because `scan_attendance.php` needs only `requireLogin()` (finding 3), the Administrator can take a QR value straight
out of the audit viewer and clock that employee in or out at any time and date. The leak is what makes the missing
guard exploitable by the one role the matrix most wants kept out.

**Minimal fix.** Add `code_value`, `bank_account`, `account_number`, `tin`, `sss`, `philhealth`, `pagibig` to
`audit_is_secret_key()`'s needles, and mask the matched *values* (`***`) rather than dropping the key.

---

# 11. MEDIUM — The portal accepts attendance corrections dated up to a year in the future

**File:line:** `employee_portal\api\me_attendance_corrections.php:36` (`CORRECTION_MAX_AGE = 365`) and `:159-172`.

**What is wrong.** The file's own header states the rule as "`work_date` today or earlier, and not older than 90
days" (:16), and the code comments admit "Production should clamp the future side to today". Instead the same
constant is used in both directions: `$maxAhead = date("Y-m-d", strtotime("+365 days"))`, and only `$workDate >
$maxAhead` is refused. The admin approval side (`api/attendance_corrections.php:359-450`) will then *create* an
attendance row for that future date.

**Exploitation.** An employee files `{"work_date":"<next month>","correction_type":"both",
"requested_time_in":"08:00","requested_time_out":"20:00","reason":"<10+ chars>"}`. If a reviewer approves it in a
batch (the queue sorts Pending first, `attendance_corrections.php:268`), 12 future hours exist before the day is
worked — and they are inside the next payroll period, so the fabricated hours are paid. The same trick backdates up to
365 days, far beyond the documented 90.

**Minimal fix.** Clamp to the past: reject `$workDate > date("Y-m-d")` (a correction is for something that already
happened), and set the window to the documented value.

---

# 12. MEDIUM — Session cookie hygiene: no `HttpOnly`, no `SameSite`, no `Secure`, no `use_strict_mode`

**File:line:** `api\_bootstrap.php:13-18` and `employee_portal\api\_bootstrap.php:12-17` — `session_name()` +
`session_start()` with **no** `session_set_cookie_params()` anywhere in either app; php.ini values (read via
`php -r`): `session.cookie_httponly` = *empty*, `session.cookie_samesite` = *empty*,
`session.cookie_secure = 0`, `session.use_strict_mode = 0`.

**What is wrong / exploitation.** The session cookie is readable from JavaScript, so any XSS (a stored payload in a
free-text field such as `reason`, `remarks`, `address`, `emergency_contact_name`, or a future front-end regression)
becomes immediate session theft rather than a contained injection — and `$_SESSION` holds only `user_id`/
`role`/`employee_id` plus an `AUDIT_ACTOR` snapshot, i.e. it is a complete identity. No `SameSite` also removes the
browser's default CSRF mitigation, which is what makes finding 4 work. `use_strict_mode=0` allows a
caller-chosen session id (mitigated after login by `session_regenerate_id(true)` at `auth.php:37` /
portal `auth.php:41`, which is correct).

**Minimal fix.** `session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => <true in prod>,
'path' => '/'])` before `session_start()` in both bootstraps; set `session.use_strict_mode=1`.

---

# 13. LOW — Deactivation and role changes do not invalidate an existing session

**File:line:** `api\_bootstrap.php:304-314` (`currentUser()` trusts `$_SESSION` only), portal `_bootstrap.php:55-65`;
`api\users.php:94-107` (`PATCH is_active`).

**What is wrong.** Every request re-reads the role from the session, never from `users`. When an Administrator
deactivates an account or moves a user from `Payroll Officer` to `Employee` (`users.php:53-91`), the live session keeps
its old privileges until it expires or the user logs out.

**Exploitation.** Narrow, but real: an employee being offboarded, or a demoted Payroll Officer, keeps full payroll
write access — including generating a payroll run — for as long as the browser keeps the session. The deactivated
account cannot *start* a new session (login checks `is_active`), which limits this to sessions already open.

**Minimal fix.** Re-read `role`/`is_active` from `users` in `currentUser()` (one prepared statement, or a
`users.updated_at` stamp in the session), and destroy the target's sessions on deactivate/role change.

---

# 14. LOW — Blank-password accounts get a 32-bit auto-generated password

**File:line:** `api\users.php:41` — `password_hash($password !== "" ? $password : bin2hex(random_bytes(4)), ...)`.

**What is wrong.** When the Administrator leaves the password field empty, the account is created with four random
bytes (8 hex characters, 32 bits of entropy) and the plaintext is never shown to anyone. 32 bits is small enough for
online guessing against an unthrottled login (finding 7), and the administrator cannot communicate the password, so it
tends to be reset to something weak by hand.

**Exploitation.** Only against an account created this way: brute-force its 8-hex password at `auth.php?action=login`
with no lockout to stop you. (Compare a real `password_hash`+`password_verify` flow, which otherwise is correct.)

**Minimal fix.** Require an explicit password of at least 12 characters, or generate ≥16 bytes
(`bin2hex(random_bytes(16))`), and surface it once in the response so the admin can hand it over.

---

# Verified non-issues (checked, not findings — stated so they are not re-audited blind)

* **SQL injection — none found.** Every interpolation in both apps is either an `(int)` cast (`LIMIT`/`OFFSET` at
  `audit_log.php:101`, `attendance_corrections.php:269`), an allow-listed key list (`me_profile.php:157`
  `{$assignments}`; `dashboard.php:26` `FROM \`$table\`` over a hard-coded array; `_payroll_helpers.php:38`
  `workDaySql()` with `%d`), or a count of generated `?` placeholders
  (`attendance.php:203,301`, `loans.php:39`, `overtime_records.php:108`, `me_loans.php:40`). No `$_GET`/`$_POST`
  reaches a SQL string.
* **Mass assignment — none.** `me_profile.php:17-23` walks `PROFILE_CONTACT_FIELDS`, never the body keys, so
  `basic_salary`, `employee_id`, `tin_number`, `position_id`, `is_active` are unreachable (verified by code);
  `employees.php:8-17` and `users.php:53-91` use explicit field lists.
* **`me_*` IDOR — none.** All 11 portal `me_*` endpoints and the 4 duplicated in the admin tree derive
  `employee_id` from `$_SESSION` via `requireEmployee()` / `currentUser()`, and the only body-supplied ids are
  record ids that are re-checked against the
  session (`me_leave_requests.php:98-110`, admin `me_leave_requests.php:86-98`); `me_payslips.php:43` adds
  `AND ps.employee_id = ?`; `me_scan_attendance.php:32-37` scopes the QR lookup to the caller.
* **Supervisor team scope — consistent.** All five team endpoints filter with `supervisorScopeIds()`
  (`attendance.php:199,290`, `attendance_corrections.php:200`, `leave_requests.php:56`, `overtime_records.php:103`,
  `loans.php:32`) and every single-record read/write uses `assertTeamReadScope()` / `assertSupervises()`
  (`attendance.php:73,281`; `attendance_corrections.php:324-332`; `leave_requests.php:184-192`;
  `overtime_records.php:273-275`; `loans.php:108`). A Supervisor keeps no path to `employees.php`, `payslips.php`,
  `reports.php`, `ledger.php`, `payroll_runs.php` or `bank_file.php` — all require `PAYROLL_ROLES`, which excludes
  Supervisor. I found no out-of-team read.
* **Maker/checker separation holds** in every endpoint reviewed: reads are `PAYROLL_ROLES`, writes fall through to
  `requireRole(MAKER_ROLES)`, `payroll_runs.php:85-89` splits `resubmit` (maker) from `approve/return/release`
  (checker), `payroll_runs.php:105-115` refuses self-approval, and `loans.php:91,133` keeps `recommend` (Supervisor,
  advice only) separate from `approve` (checker).
* **CSV export** (`api/bank_file.php:97-107`) escapes leading `= + - @ \t \r` before RFC-4180 quoting — spreadsheet
  formula injection is handled, and `:144` correctly gates on `PAYROLL_ROLES` with the refusal itself audited.
* **Password storage** is `password_hash(PASSWORD_DEFAULT)` / `password_verify` throughout (bcrypt `$2y$10$`, verified
  in the `users` table); **logout** (`auth.php:55-65` both apps) empties `$_SESSION`, expires the cookie and calls
  `session_destroy()`, and login calls `session_regenerate_id(true)` — session fixation is not exploitable.
* **Audit masking of passwords works** — `{"username":"supervisor","password":"***"}` (verified in `audit_log`).

# What I could not verify

1. **Role-guard behaviour was not exercised with real sessions.** Logging in writes an `audit_log` row, which the
   read-only mandate forbids; so findings 3, 4, 5, 7, 8, 13 are conclusions from code (plus, for 3, the audit log's
   own recorded `demo_mode` requests proving the endpoint accepted them).
2. **I did not execute any mutating `tests/` script** (finding 2). The payroll-processing and account-creation impact
   is read from code; only `tests/preview_payroll.php` (self-described read-only) was requested, to prove reachability.
3. **`display_errors=1` rendering over HTTP** (finding 9) is from php.ini, not from an observed fatal error.
4. **Front-end XSS** was spot-checked only (`escapeHtml()` used consistently in `employees.js`, `users.js`,
   `reports.js`); no full review of the 30 admin / 13 portal JS files was performed, so finding 12 is stated as a
   *conditional* amplifier rather than a confirmed XSS.
5. **No dependency review** (`axios.min.js`, `sweetalert2.min.js`, `html5-qrcode.min.js`, `qrcode.min.js` were not
   version-checked against CVE databases).
