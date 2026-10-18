# Front-end & cross-app rule-duplication audit

**Scope:** admin app `C:\xampp\htdocs\master_file_module_api` and portal `C:\xampp\htdocs\employee_portal` — pages, `assets/js/`, `assets/css/`.
**Mode:** read-only. No file in either app was modified except this report. `node --check`/parsing used only.
**Method:** full read of every page + every first-party script, mechanical cross-check of every `getElementById` / `querySelector('#…')` against the HTML of the page that loads the script, script-tag inventory per page, and a line-by-line comparison of the rule functions that exist in more than one place.

**Totals: 19 findings — 4 High, 10 Medium, 5 Low.**

| # | Sev | Where | One-line |
|---|-----|-------|----------|
| H1 | High | `api/_payroll_helpers.php:16-34` vs `api/leave_requests.php:18-29` | Payroll work week is Mon–Sat; leave days are counted Mon–Fri |
| H2 | High | `assets/js/leave.js:64-73` vs `..\employee_portal\assets\js\leave.js:12` | Same single-day weekend leave shows **0** days in admin, **1** in portal; server charges 1 |
| H3 | High | `..\employee_portal\assets\js\leave.js:73-80` | Hardcoded fallback leave-type IDs still there; id 5 is Unpaid Leave, Birthday is 6 |
| H4 | High | `..\employee_portal\assets\js\attendance.js:315` | `ymd is not defined` after a successful correction filing |
| M1 | Med | `api/_payroll_helpers.php:360-374` vs `..\employee_portal\api\me_attendance_corrections.php:81-94` | Payroll-lock query copied with literal statuses instead of the constant |
| M2 | Med | `api/scan_attendance.php:88-95` vs `..\employee_portal\api\me_scan_attendance.php:51-57` | Clock arithmetic duplicated 4×; portal copy picks the *oldest* attendance row |
| M3 | Med | `..\employee_portal\api\me_attendance_corrections.php:159-172` | Corrections may be dated up to +365 days, contradicting the documented rule |
| M4 | Med | `assets/js/attendance.js:498`, `payroll_periods.js:78`, `reports.js:140` | `#2f5496` inline text on the dark card ≈1.6:1 — invisible in dark mode |
| M5 | Med | `..\employee_portal\assets\js\leave.js:27-29` + `assets/css/style.css:1191` | "Cancelled" badge keeps a light `#e5e7eb` bg in dark mode (text ≈2:1) |
| M6 | Med | `assets/js/leave_requests.js` (whole file) | Dead script; page is a meta-refresh stub; holds a 4th divergent day-count |
| M7 | Med | `api/me_attendance.php`, `me_leave_balances.php`, `me_leave_requests.php`, `me_payslips.php` | Four admin self-service endpoints with no caller; one holds a divergent rule |
| M8 | Med | `assets/js/loans.js:203` | First `await` of `recommendLoan()` is unguarded — clicking "Recommend" can silently do nothing |
| M9 | Med | `leave_types.html`, `leave_balances.html` | Reachable only by typed URL; their screens were duplicated into `leave.html` tabs |
| M10 | Med | all admin pages except `index.html:88` | `api.js` / `nav.js` / every page script load unversioned; `nav.js` changed 2026-10-18 |
| L1 | Low | `employees.html:62-108` and most admin forms | `<label>` not associated (`for`/`id`), no accessible name |
| L2 | Low | `..\employee_portal\assets\css\style.css:1396`, `:1457` | 40px tap targets on phones (project rule is ≥44px) |
| L3 | Low | `api/cash_disbursements.php`, `#msg` on portal pages, `assets/js/api.js:42` | Endpoints/elements/helpers with no consumer |
| L4 | Low | `..\employee_portal\assets\js\attendance.js:323`, `me_attendance_corrections.php:16`, `..\employee_portal\assets\js\leave.js:62-63` | Comments state rules the code no longer implements |
| L5 | Low | `assets/js/payroll_runs.js:320-327` | If `loadMe()` fails, the table stays on "Loading…" forever |

---

## 1. Duplicated business rules — inventory and drift verdict

Every rule that exists in both apps (or twice inside one app), with both locations and whether they currently agree.

### 1.1 Business-day counting — **DISAGREE (High, H1)**

Six implementations of "how many days is this range":

| Impl | Location | Week | Single-day weekend |
|---|---|---|---|
| `countWorkingDays()` | `api/_payroll_helpers.php:24-34` | **Mon–Sat** (`WORK_WEEK_LAST_ISO_DOW = 6`, line 16) | 0 |
| `countBusinessDays()` | `api/leave_requests.php:18-29` | Mon–Fri (`$dow <= 5`) | 0 (bypassed by caller, line 99) |
| `countBusinessDays()` | `api/me_leave_requests.php:12-23` | Mon–Fri | 0, **and used** (line 52) |
| `countBusinessDays()` | `..\employee_portal\api\me_leave_requests.php:9-20` | Mon–Fri | 0 (bypassed by caller, line 53) |
| `countBusinessDays()` | `assets/js/leave.js:64-73` | Mon–Fri | **0** |
| `countBusinessDays()` | `..\employee_portal\assets\js\leave.js:10-20` | Mon–Fri | **1** (explicit early return, line 12) |
| `countBusinessDays()` | `assets/js/leave_requests.js:45-55` | Mon–Fri | 0 — **dead file**, see M6 |

Why H1 is High: the payroll side (`api/_payroll_helpers.php:185`, `:195-201`, `:227`) treats **Saturday as a working day** — `expectedDays`, the daily rate, and approved-leave credit all count Mon–Sat — while leave approval (`api/leave_requests.php:220`) charges the balance Mon–Fri.

Concrete case: leave from **Sat 2026-10-03 to Sat 2026-10-10** (verified: both are Saturdays) is 6 working days to payroll but `countBusinessDays` returns 5 (Oct 5–9 only). The employee is excused for Sat Oct 3 and Sat Oct 10 in payroll but is charged only 5 days, so **every leave that spans a Saturday over-credits the balance by one day**. The mirror case — a range that contains only Saturdays, e.g. Sat 10-03 → Sun 10-04 — is rejected with "The selected range has no working days (Mon–Fri)" (`api/leave_requests.php:100-102`) even though Saturday is a working day everywhere else.

Minimal fix: one definition. Either have `api/leave_requests.php` / `api/me_leave_requests.php` (portal) call `countWorkingDays()` from `api/_payroll_helpers.php`, or — since the portal cannot include it — move `countWorkingDays()` into a tiny shared file the portal also includes; delete the three PHP copies. Then make both JS copies mirror the server, or (better) return `days` from the API so the client never computes it.

### 1.2 Single-day / weekend leave validation — **DISAGREE (High, H2)**

* Server, admin filing (`api/leave_requests.php:99-102`): single day allowed on any day, including Sat/Sun.
* Server, admin self-service (`api/me_leave_requests.php:52-54`): `countBusinessDays(...) === 0` → **rejects a single-day weekend leave**.
* Server, portal self-service (`..\employee_portal\api\me_leave_requests.php:53-55`): single day allowed (uses `$start !== $end`).
* Server, approval (`api/leave_requests.php:216-220`): a single-day request **always consumes 1 day**, even at a weekend.
* Admin UI (`assets/js/leave.js:64-73` → rendered at `:106`, `:123`): returns **0** for a weekend single day and prints "0" in the Days column.
* Portal UI (`..\employee_portal\assets\js\leave.js:12` → rendered at `:103`, `:116`): returns **1**, and the confirm dialog at `:143-146` says "File a 1-day leave request".

User-visible symptom: the same approved Saturday birthday leave reads **Days = 0** in the admin Leave queue (`leave.html`) and **Days = 1** in the portal (`leave.html`), while the balance was reduced by 1. Approving it in the admin also prints `($days working day(s))` = 0 (`api/leave_requests.php:260`). And an employee using the *admin* self-service endpoint is refused a Birthday Leave that the portal accepts.

Minimal fix: pick one rule (the code comments in `api/leave_requests.php:95-98` and portal `me_leave_requests.php:49-52` already argue for "single day always = 1 day"), apply it in all three PHP copies, and delete the `start === end` branch difference by computing days server-side for display.

### 1.3 Payroll lock (attendance-date) — **agree today, drift-prone (M1)**

* Admin: `api/_payroll_helpers.php:360-374`, statuses from `PAYROLL_LOCKED_RUN_STATUSES` (`:278` = `["Approved","Released"]`).
* Portal: `..\employee_portal\api\me_attendance_corrections.php:81-94` — same query, statuses **hardcoded** `('Approved','Released')`.

They agree today (`payroll_runs.php` only ever writes Submitted/Returned/Approved/Released). The portal comment at `:75-79` admits the copy and asks future maintainers to keep both in step. Adding one status to the constant silently desynchronises the portal, which is the softer surface (employees could still file corrections for a locked date; approval would still 409, so the failure is a late rejection, not corruption).

Minimal fix: have the portal `require` a shared, read-only `payroll_lock.php` (or at minimum a `const` in the portal that mirrors the admin name), and add a test asserting the two status sets are identical.

### 1.4 Payroll lock (payslip / loan payment) — no portal copy, fine

`assertPayrollRunUnlocked()` / `assertPayslipUnlocked()` / `assertLoanPaymentUnlocked()` exist once (`api/_payroll_helpers.php:288-341`). The portal's loan and payslip endpoints are read-only, so there is nothing to duplicate. No finding.

### 1.5 Scan arithmetic — **agree numerically, disagree on which row (M2)**

| Copy | Location | Late = past 08:00 | Lunch |
|---|---|---|---|
| kiosk | `api/scan_attendance.php:99-105`, `:126-137` | yes | `>300 → −60` |
| admin manual | `api/attendance.php:7-9`, `:19-38`, `:40-46` | yes | `>300 → −60` |
| correction approval | `api/attendance_corrections.php:69-90` | yes | `>300 → −60` |
| portal manual | `..\employee_portal\api\me_scan_attendance.php:49-50`, `:61-66`, `:86-95` | yes | `>300 → −60` |

Numbers agree exactly, including the "at least 1 minute" guard (`scan_attendance.php:132` / `me_scan_attendance.php:89`). The drift is in **row selection**: the kiosk resolves today's row with `ORDER BY attendance_id DESC LIMIT 1` (`scan_attendance.php:88-95`) and the admin correction path does the same (`api/attendance_corrections.php:388-396`), while the portal does `SELECT … WHERE employee_id=? AND work_date=?` with **no `ORDER BY`, no `LIMIT`** (`me_scan_attendance.php:51-57`). (One more copy of the same weakness sits in the admin manual path, `api/attendance.php:383-388`, which also omits `ORDER BY`/`LIMIT` — same fix.) When a day has two attendance rows (possible: an approved correction inserts one when none existed, `api/attendance_corrections.php:437-450`), the portal manual clock-out updates whichever row MySQL returns first, while the kiosk updates the newest.

Symptom: an employee who files a correction (creating/keeping a second row) and then uses the portal's manual "Record attendance" can end up with the clock-out on the stale row — the history shows two rows for one day, one still "Clocked In".

Minimal fix: add `ORDER BY attendance_id DESC LIMIT 1` to `me_scan_attendance.php:51-57` (and to `api/attendance.php:383-388` for symmetry).

### 1.6 Leave self-service overlap / cancel rules — **agree**

`api/me_leave_requests.php:57-65` vs `..\employee_portal\api\me_leave_requests.php:59-77`: same overlap predicate (`NOT (end_date < ? OR start_date > ?)`, status `Pending/Approved`), same 409, same cancel-only-own-Pending rule (`:77-104` / `:89-114`). Only the wording differs. The portal version additionally names the conflicting leave type — the admin version was left behind (its message is the generic "You already have a pending or approved leave…"). Not severitised: cosmetic + dead endpoint (M7), but it is the same rule in two places.

### 1.7 Correction filing rules — **agree, except the date window (M3)**

`..\employee_portal\api\me_attendance_corrections.php` duplicates the validation that the admin side later re-check (`api/attendance_corrections.php:379-419`): reason ≥10 chars (`:37`, `:178-180` vs JS `attendance.js:293-296`), ≤500 (`:181-183` vs `maxlength="500"` at `attendance.html:162`), clock-out needs a clock-in (`:207-211` vs `:406-415`), out must be after in (`:212-229` vs `:416-419`). These agree.

The **date window does not**: the portal allows **+365 days in the future** (`CORRECTION_MAX_AGE = 365` at `:36`, used at `:163-166`) and `..\employee_portal\assets\js\attendance.js:329` sets `max = today + 365`. Its own header (`:16`) says "work_date **today or earlier**, and not older than **90 days**", the JS comment at `attendance.js:323` says "Today is the newest date … 90 days back the oldest", and the server comment at `:159-161` admits "Production should clamp the future side to today". Approving such a request writes an attendance row for a future date (`api/attendance_corrections.php:437-450`) and payroll's absence maths (`_payroll_helpers.php:255`) counts it as present.

Minimal fix: clamp `$maxAhead` to `date("Y-m-d")` in `me_attendance_corrections.php:163` and set `attendance.js:329` to `ymd(today)`; correct the three comments.

### 1.8 Balance fallback ("no row yet ⇒ full entitlement") — **agree**

`api/leave_balances.php:52`, `..\employee_portal\api\me_leave_balances.php:16` and `..\employee_portal\api\me_dashboard.php:40` all fall back to `days_per_year` when no `leave_balances` row exists. Same rule three times, currently identical. Noted as a duplication that would drift if the fallback ever changes (e.g. pro-rating by date hired). No severity.

---

## 2. Dead / unreachable code

### M6 — `assets/js/leave_requests.js` is dead, and holds a 4th copy of the day rule
`leave_requests.html:6` is `<meta http-equiv="refresh" content="0; url=leave.html">` — a 19-line stub with no nav and no page script (`:17` loads only `theme.js`). Nothing else in the admin app references `leave_requests.js` (verified: no `<script src>` and no `data-page` for it). The 183-line file, including `countBusinessDays()` at `:45` (a slightly different variant — it caches `new Date(end)` in `e`, `:47-48`), is unreachable.
*Symptom:* none at runtime; the cost is a fourth rule copy that a future maintainer will "fix" without effect, plus ~7 KB of dead code that keeps showing up in greps.
*Minimal fix:* delete `assets/js/leave_requests.js` (the redirect stub can stay).

### M7 — four admin self-service endpoints with no caller
`api/me_attendance.php`, `api/me_leave_balances.php`, `api/me_leave_requests.php`, `api/me_payslips.php` (all created 2026-10-16) are called by **no** admin page or script — a mechanical scan of every `api.get/post/put/patch/delete` call in `assets/js/*.js` leaves exactly these four unmapped (plus `cash_disbursements.php` and the intentionally-unshipped `salary_rates.php`). They are only exercised by `tests/me_selfservice_test.php`.
The one in `api/me_leave_requests.php` carries the **divergent** single-day rule (H2, line 52) and the generic conflict message (1.6).
*Symptom:* an admin user opening `api/me_leave_requests.php` directly can file own leave under rules that differ from the portal's; nothing in the UI does it. Reportedly the earlier self-service attempt, superseded by the portal.
*Minimal fix:* delete the four endpoints (or move them under `tests/fixtures/`), and keep the portal as the single self-service surface.

### L3 — smaller dead items
* `api/cash_disbursements.php` — no front-end caller anywhere (only prose in `users.html:52` and tests). Writes to it are unreachable from the UI.
* `#msg` banners: `..\employee_portal\leave.html:38` and `..\employee_portal\attendance.html:39` are never touched by `leave.js` / `attendance.js`; `..\employee_portal\index.html` has no `#msg` at all (nothing needs one).
* `assets/js/api.js:42-52` `showMessage()` — no caller in either app; its own comment (`:39-41`) says it is kept "for backward compatibility — not used anymore".
* `assets/js/leave.js` exposes `window.__reloadLeaveTypesForRequests` (`:234`, used at `:430`, `:447`) — used, so not dead, but it is an ad-hoc cross-IIFE channel worth knowing about.

### M9 — two orphan pages
`leave_types.html` and `leave_balances.html` exist, ship their own scripts (`assets/js/leave_types.js`, `leave_balances.js`), and are listed in `NAV_ACCESS` (`nav.js:60-61`), but **no page links to them**: the sidebar has no entry (checked every admin page) and `leave.html` already contains the same two screens as tabs (`assets/js/leave.js:246-330` balances, `:335-463` types, tab wiring at `:9-16`). `nav_matrix_test.php` passes because it only checks pages that have the nav, and `NAV_ACCESS` is allowed to contain extra keys.
*Symptom:* a Payroll Officer cannot reach Leave Types / Leave Balances setup from the UI at all (the tabs cover it); if someone bookmarks the old pages they get a second, visually different implementation of the same CRUD, backed by the same endpoints.
*Minimal fix:* delete both pages + their two scripts, or link them from `leave.html` as "open full page" and drop the duplicate tabs. Either way, keep one implementation.

---

## 3. JS runtime errors

### H4 — `ymd is not defined` after every successful correction filing
`..\employee_portal\assets\js\attendance.js:315`:
```js
showToast(data.message, "success");
correctionForm.reset();
correctionDateEl.value = ymd(new Date());   // ← ReferenceError
```
`ymd` is **not** a global: it is a local `const` inside `initCorrectionDates()` (`:326`) and is not visible at `:315`. The throw happens inside the `try` at `:311`, so it is swallowed by the `catch` at `:318`.
*Symptom:* filing a correction shows the green success toast and then, immediately, a red toast reading **"ymd is not defined"**; the work date is left blank (`form.reset()` already ran) and `await loadCorrections()` at `:317` never runs, so the "My corrections" table keeps showing the old rows. The filing itself did succeed, which makes the message look like a failure.
*Minimal fix:* hoist `ymd`/`pad` out of `initCorrectionDates()` to module scope (two lines, next to `correctionTime` at `:192`), or inline `correctionDateEl.value = new Date().toISOString().slice(0, 10)`.

### M8 — unguarded first `await` makes "Recommend" silently do nothing
`assets/js/loans.js:202-204`: `recommendLoan()` starts with `const row = (await api.get("loans.php")).data.data.find(...)` **outside** any `try`. The rest of the function is guarded (`:222+`).
*Symptom:* when that list call fails (500, or a 403 for a Supervisor whose scope resolves empty), the promise rejects unhandled; the `Swal` at `:206` never opens and the button appears dead — no error is shown. (Confirmed by code path; not reproduced live.)
*Minimal fix:* wrap the lookup in `try { … } catch (err) { showToast(apiErrorMessage(err), "error"); return; }`.

### L5 — "Loading…" can persist
`assets/js/payroll_runs.js:320-327`: `await loadMe()` is inside the `try`, but `loadRows()` only runs in the same `try` block. If `loadMe()` rejects, the catch shows a toast and the table keeps its `Loading…` row (`:38`). Same shape at `assets/js/payslips.js:96-104` for the detail modal. Low: a toast does appear, the row is just stale.

### Checked and clean (negative results, so you don't re-audit them)
* **Every** literal `getElementById("…")` and `querySelector("#…")` in every first-party script resolves to an element in the HTML of the page that loads it. The only unmatched names are three non-literals — `nav-logout-btn` (created by `api.js:148` then queried at `:152`), `tab-` + `btn.dataset.tab` (`assets/js/leave.js:14`, concatenation), `print-${name}` (`reports.js:496`), and `qr-holder` (`..\employee_portal\assets\js\attendance.js:27` creates it, `:41` queries it). **No frozen-contract violation found** in either app (finding #4 of the brief: nil).
* No function is called before its definition: all page scripts use function declarations (hoisted) or call from `DOMContentLoaded`.
* Every `DOMContentLoaded` handler in both apps resolves (no `await` at top level of an unreferenced async IIFE). No missing `await` on a promise whose result is read.

---

## 4. Frozen-contract violations (IDs / classes)

**None.** Every ID and class the scripts read or write still exists in the markup, in both apps — mechanically verified per page, not spot-checked. Notes on the classes that form the contract (all present):

| Contract | Written by | Styled at |
|---|---|---|
| `.sidebar-links a.active` | `assets/js/api.js:121-126` | `assets/css/style.css:286-306` |
| `.sidebar-links a.nav-hidden` | `assets/js/nav.js:121` | `assets/css/style.css:322` |
| `nav.sidebar.nav-locked` | markup + `nav.js:141-144` | `assets/css/style.css:315` |
| `.nav-links a.active` (portal) | `..\employee_portal\assets\js\api.js:34-39` | `..\employee_portal\assets\css\style.css:343` |
| `#nav-logout-btn`, `.nav-user` | `api.js:141-159` (admin), `:51-62` (portal) | both stylesheets |
| inline `style="…"` colour literals | all page scripts | portal: an explicit 33-rule bridge at `style.css:1179-1216`; admin: **none** (see M4) |

`data-page` values also hold: every `data-page` in every admin page has a matching file, and `NAV_ACCESS` (`nav.js:51-73`) covers all of them plus two extras (`salary_rates.html`, which ships only as `.bak` — deliberate, documented at `nav.js:43-45` — and the orphan pair in M9). No `data-page` points at a missing page.

---

## 5. Cache-busting inconsistency (M10)

Full inventory, per page. `?v=` present = versioned.

**Admin — only one script on one page is versioned:**

| Page | versioned | unversioned (all load `axios`, `sweetalert2`, `dialogs`, `api.js`, `nav.js`, page script, `theme.js`) |
|---|---|---|
| `index.html:88` | `dashboard.js?v=6` | everything else on the page |
| all 19 other pages | — | `api.js`, `nav.js`, **and** the page script |

Recently modified, still unversioned (mobile/aggressive-cache risk):
* `nav.js` — 2026-10-18 14:07, the role-filtering rewrite. Unversioned on all 20 pages.
* `users.js` (15:47), `attendance_corrections.js`, `loans.js`, `attendance.js`, `overtime.js` (14:07), `employees.js`/`payroll_runs.js` (13:30), `dashboard.js` (12:21 — *versioned only on `index.html`*).
* `api.js` (2026-10-17 14:09).

*Symptom:* an Administrator or Payroll Officer with a cached `nav.js` from before 2026-10-18 sees the old unfiltered sidebar — Users / Audit Log / Payroll links that answer **403** on click. That is precisely the bug `nav.js` was written to fix, and it will come back for anyone who cached the pre-fix file. Same class of problem for the 2026-10-18 API-contract changes in `users.js` (new `employees_lookup.php`).
*Minimal fix:* one query string per release across every script tag on every page (e.g. `?v=20261018`) — a single find/replace; the tags are all plain `<script src="assets/js/x.js">`.

**Portal — partial, and the version strings are inconsistent:**

| Page | versioned | unversioned |
|---|---|---|
| `index.html:48-49` | `api.js?v=20261018b` | `dashboard.js` |
| `leave.html:123-124` | `api.js?v=20261018b`, `leave.js?v=20261018d` | — |
| `attendance.html:195-196` | `api.js?v=20261018b`, `attendance.js?v=20261018b` | — |
| `loans.html:107-108` | `api.js?v=20261018b`, `loans.js?v=20261018b` | — |
| `payslips.html:88-89` | `api.js?v=20261018b` | `payslips.js` |
| `profile.html:104-105` | `api.js?v=20261018b`, `profile.js?v=20261018b` | — |
| `login.html:42-43` | — | `login.js` |

There the discipline is better (the recently changed scripts *are* versioned), but: three different schemes (`b`, `d`, and none), and `api.js?v=20261018b` is stamped **newer** than the file it points at (`assets/js/api.js` last modified 2026-10-16 17:25) — the version does not track the artefact, so the next `api.js` edit will ship under an already-cached URL. Note that `payslips.js`/`dashboard.js`/`dialogs.js`/`theme.js` are all older than the versioned files, so the current risk is low.

---

## 6. Theming and accessibility

### M4 — admin dark mode: inline colours are not bridged at all
The admin stylesheet defines a full dark palette (`assets/css/style.css:59-89`) and every page ships the toggle (`<button data-theme-toggle>`, 20 pages), but there are **zero** `[style*="…"]` overrides in it (count: 0; the portal has 33, `..\employee_portal\assets\css\style.css:1179-1216`). The page scripts emit dozens of literal colours (full list gathered: `attendance.js` 23 occurrences, `reports.js` 17, `leave.js` 11, `ledger.js` 10, …), which therefore stay light-theme colours on a `#171e2e` card.

Worst offender, and the one I would fix first — `#2f5496` (dark navy-blue) on `--card` `#171e2e` is ≈**1.6:1**:
* `assets/js/attendance.js:498` — the "View →" row action in the Attendance Summary table becomes unreadable;
* `assets/js/payroll_periods.js:78` — the payslip count `<strong>` becomes unreadable;
* `assets/js/reports.js:140` — same pattern.

Also poor in dark mode: `color:#666` (`attendance.js:544`, `reports.js:150`, `payroll_periods.js:42`, `leave.js:300` — ≈3.1:1) and `color:#b3261e` on a dark card (`attendance.js:344`, `reports.js` ×6 — ≈3.0:1). Amber/blue chips (`background:#fef3c7;color:#92400e`, `#e0f2fe/#0369a1`) stay legible but glare.

*Symptom:* An Administrator who picks Dark mode gets a themed shell with unreadable bits of otherwise-normal tables — "View →" links and payslip counts effectively disappear.
*Minimal fix (either):* add the same `[style*="…"]` bridge block the portal uses (`#2f5496`→`#6f9bff`, `#666`→`var(--muted)`, `#b3261e`→`var(--red)`, `#fef3c7`→`#4a3a12`, …), or replace the literals in the JS with `var(--muted)` / `var(--blue)` / `var(--red)` (the scripts already do this in places — `attendance_corrections.js:56`, `payroll_runs.js:47`).

### M5 — portal dark mode: the Cancelled badge keeps a light plate
`..\employee_portal\assets\js\leave.js:27` and `:29` (default) emit `background:#e5e7eb;color:#6b7280`; `..\employee_portal\assets\js\loans.js:29` and `attendance.js:210` (fallback) do the same. The dark bridge maps `#6b7280` text → `#9aa4b8` (`style.css:1180`) but for `#e5e7eb` only maps **`border-color`** (`style.css:1191`) — the background is untouched.
*Symptom:* in dark mode every **Cancelled** leave request (and any unknown loan/correction status) renders as `#9aa4b8` text on a `#e5e7eb` light-grey pill ≈**2:1** — the status is the hardest thing on the row to read, and it looks like a rendering bug next to the themed chips.
*Minimal fix:* add `html[data-theme="dark"] [style*="background:#e5e7eb"] { background-color: #2b344b !important; }` next to the existing `#e5e7eb` rule at `style.css:1191`.

### L1 — admin form labels are not programmatically associated
Admin forms use `<label>First Name</label><input id="first_name">` with neither `for=` nor a wrapping element — e.g. `employees.html:62-108` (23 controls), `leave.html` (`req_employee_id`, `req_leave_type_id`, `req_start_date`, `req_end_date`, `req_filter_status`, `bal_year`, `type_name`, `type_days_per_year`), `users.html:…` (`username`, `role`, `password`, `employee_id`), `reports.html` (7 filters), `attendance.html` (`camera-select`, `manual-code`, `demo_date`, `demo_time`, `summary_from`, `summary_to`, `employee_id`, `work_date`, `time_in`, `time_out`), `departments.html`, `positions.html`, `loans.html`, `payroll_periods.html`, `payroll_runs.html`, `qr_codes.html`, `ledger.html`, `leave_types.html`, `leave_balances.html`, `attendance_corrections.html` (`filter-to` only), `kiosk.html` (3).
*Symptom:* a screen reader announces "edit text, blank" for the whole employee form; clicking the visible label does not focus its field; `getByLabel` automation cannot find them. (Visual users are unaffected — the text is there, just not linked.)
*Minimal fix:* `for="<id>"` on each `<label>`; ~90 mechanical edits, no layout change. The portal already does this correctly on every page (`for`/`id` on all controls, verified: 0 unlabelled).

### L2 — two sub-44px tap targets on phones
`..\employee_portal\assets\css\style.css:1396` (inside `@media (max-width: 560px)`, opened at `:1361`) sets `.table-wrap .btn { min-height: 40px; }` — this overrides `.btn { min-height: 44px }` (`:1398`) by specificity, so the in-table actions (Cancel leave, etc.) shrink to 40px on the device class that needs them most. `:1457`, inside `@media (hover: none) and (pointer: coarse)`, sets `.theme-toggle { min-height: 40px }`.
*Symptom:* the Cancel button on a leave row and the theme toggle are 4px under the project's own stated rule ("tap targets are ≥ 44px on small screens", `style.css:18`); mis-taps on a destructive Cancel are the risk.
*Minimal fix:* `min-height: 44px` in both places (they were presumably shrunk for row height — 4px is not worth it).

### Contrast items I checked and found acceptable
`--muted` on `--card` is documented and holds (5.9:1 light, 6.6:1 dark, `style.css:56`, `:110`); `#9ca3af` "—" placeholders on the dark card ≈5.6:1; nav labels use their own `--nav-text` (portal `:77-88`) so the brightened dark `--blue` never washes them out. The portal's dark bridge is genuinely thorough; the two gaps above are the only ones the bridge misses.

---

## 7. Broken navigation / role visibility

**No broken links, no missing pages.** All 20 admin pages' `data-page` values resolve to real files; all 6 portal nav entries resolve (`index`, `payslips`, `attendance`, `leave`, `loans`, `profile` — every file exists, and `aria-current="page"` is set on the active one in each). `nav.js` hides rather than removes links, and `onerror` on its script tag (`attendance.html:241` etc.) removes `nav-locked` if it fails to load, so a failed `nav.js` cannot strand the sidebar.

Two visibility observations:

1. **M9 above** — `leave_types.html` / `leave_balances.html` are in `NAV_ACCESS` but in no sidebar, so no role can reach them; their functions live in `leave.html`'s tabs. Not "a nav entry visible to a role that cannot use it" — the reverse: pages nothing points at.
2. Role/page agreement is otherwise sound: `nav.js:51-73` matches the API guards (Dashboard/Employees/Payroll/Reports/Users/Audit → Payroll Officer + Approver; Users/Audit → Administrator only; the five team queues + Kiosk → Supervisor). `kiosk.html` is the only page with a nav-free layout and is open to everyone by design. `payslips.html?run_id=N` (linked from `payroll_periods.js:67` and `payroll_runs.js:311`) is honoured by `payslips.js:18-19`, so the deep link works.

---

## 8. What I would fix first (ordered)

1. **H3** — delete the hardcoded fallback in `..\employee_portal\assets\js\leave.js:73-80`; show the fetch error instead. It is the historical bug, still live, and its IDs are provably wrong (`sql/001_initial_schema_and_seed.sql:217-218`: 5 = Unpaid Leave, 6 = Birthday Leave; `sql/payroll_db_recovery.sql:426` has a *different* ID map entirely, so the list is wrong under either seed).
2. **H4** — two-line `ymd` hoist in `..\employee_portal\assets\js\attendance.js`.
3. **H1 + H2** — collapse the six day-count implementations to one, Mon–Sat, single-day = 1.
4. **M10** — one `?v=` sweep across all admin script tags.
5. **M4/M5** — the two dark-mode gaps.

## Appendix — reproducibility notes

* Day-count comparison: implemented Mon–Fri as `dow <= 5` / `dow !== 0 && 6` and Mon–Sat as `format('N') <= 6`; verified `2026-10-03` and `2026-10-10` are Saturdays, `2026-10-05`–`2026-10-09` Mon–Fri.
* ID contract: script per app that, for each page, extracted `id="…"` from the HTML and all `getElementById("<literal>")` / `querySelector("#<literal>")` from each script that page loads (vendor bundles excluded), then diffed. Result: no misses beyond the four non-literal cases listed in §3.
* API-caller map: same approach over every `api.<verb>("<endpoint>"` in `assets/js/*.js`, diffed against `api/*.php`. Uncalled: the four admin `me_*.php`, `cash_disbursements.php`, `salary_rates.php`.
* Colour/contrast figures are computed from the token values in the stylesheets (WCAG relative-luminance formula) for text-on-surface pairs; no browser rendering was performed.
* Unconfirmed by execution: M8 (unhandled rejection path), L5 (persisting "Loading…"), and the cache hypothesis in M10. Everything else is traced through the code path end-to-end.
