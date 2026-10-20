# UNITOP — Full System Test Checklist

**Scope:** everything except master-data setup (Departments, Positions, Employees, Users).
**Order:** Part A happy path → Part B error tests → Part C locks → Part D “if this / then that”.

**Current fixture:** Employee **4 — Vinchy Fantonial**, QR code **1 Active**, period **#1 Processed**, run **#1 Released**, no OT/leave/loan rows, **no supervisor team link**.

Legend: `P` = Payroll Officer, `A` = Approver, `S` = Supervisor, `E` = Employee, `Adm` = Administrator.

---

## 0. One-time prerequisites — not scored as feature tests

These are fixtures, not setup-screen tests. The Supervisor OT flow cannot run without them.

- [ ] **P0. Back up the database first.**
  ```powershell
  C:\xampp\mysql\bin\mysqldump.exe -u root --databases --result-file=C:\xampp\master_file_tests\before_full_system_test.sql payroll_db
  ```
- [ ] **P1. Assign Employee 4 to Supervisor 168** — Login `P` → Employees → Edit Vinchy → **Supervisor = supervisor famacion** → Save.
- [ ] **P2. Only if you are testing the payroll-run path (A8): create an Open period covering the test date** — Payroll → New Period → e.g. `2026-10-16` to `2026-10-31`.
  - Current fixture has **0 Open periods**; period #1 is `Processed` and run #1 is `Released`.
  - If you skip P2, skip **A8.1–A8.7** and record “payroll run not tested”; you can still use run #1 for A8.8–A8.10, A9 and A10.
- [ ] **P3. Confirm Employee 4 has an Active QR** — QR Codes page shows code `1`, status **Active**.
- [ ] **P4. Confirm current date has no attendance row for Employee 4** — Attendance page, today’s date, 0 records.

> If your test date is not 2026-10-20, use that date everywhere below and create the period around it.

---

# PART A — ORIGINAL FLOW (happy path, in order)

Do not jump ahead. Each step assumes the previous one passed.

## A1. Login, dashboard and navigation

- [ ] **A1.1** Login as `P` → Dashboard loads.
- [ ] **A1.2** Dashboard shows **4 active employees** and **0 Open periods** on the current fixture; period #1 is **Processed** and run #1 is **Released**.
  - If P2 was completed, the dashboard should instead show **1 Open period**.
- [ ] **A1.3** Sidebar links open without “You do not have access” for `P`.
- [ ] **A1.4** Login as `A` in a second browser/profile → Dashboard loads; Approver sees approval queue.
- [ ] **A1.5** Login as `S` → Dashboard/Overtime page loads; no salary/payroll menus.
- [ ] **A1.6** Login as `Adm` → Users/Audit only; no payroll menus.

## A2. QR and regular attendance

- [ ] **A2.1** Login as `E` (`vinchy`) in the employee portal → Attendance page loads.
- [ ] **A2.2** My QR card shows **Clock in**, with an expiry time.
- [ ] **A2.3** Login as `P` → Kiosk → **Demo Mode ON**, date = today, time `08:00` → scan the employee QR (or type the token).
- [ ] **A2.4** Kiosk says the employee **clocked in**; no duplicate warning.
- [ ] **A2.5** Refresh the portal QR → it now says **Clock out**.
- [ ] **A2.6** Kiosk Demo Mode, today, `17:00` → scan again.
- [ ] **A2.7** Kiosk says **clocked out**; portal QR disappears / attendance complete.
- [ ] **A2.8** Portal → Attendance history shows today’s row: `08:00–17:00`, `8.00` hours, `Present`.

## A3. Supervisor files overtime

- [ ] **A3.1** Portal `E` → Attendance → **Overtime history** has no request; no filing form is present.
- [ ] **A3.2** Login as `S` → Overtime → **File Overtime Request** card is visible.
- [ ] **A3.3** `S` selects **Vinchy**, date today, `18:00–20:00`, reason ≥10 chars → File request.
- [ ] **A3.4** Overtime queue shows the request as **Pending**, with **filed by supervisor**.
- [ ] **A3.5** Portal `E` → Overtime history shows the **Pending** request and the requested window.
- [ ] **A3.6** `A` → Overtime → approve the request.
- [ ] **A3.7** Request status becomes **Approved**; portal reflects **Approved**.

## A4. Overtime clock in/out

- [ ] **A4.1** Portal QR now shows **Overtime** (because an approved OT is open for today).
- [ ] **A4.2** Kiosk Demo Mode, today, `18:00` → scan → **Overtime started**.
- [ ] **A4.3** Kiosk Demo Mode, today, `20:00` → scan → **Overtime clock-out 2.00 hour(s)**.
- [ ] **A4.4** Portal Overtime history shows **Completed**, actual `18:00–20:00`, 2.00 hours.
- [ ] **A4.5** No extra attendance row was created for OT.

## A5. Leave request → supervisor approval → balance

Use a **future non-working test date** so it does not conflict with the payroll period.

- [ ] **A5.1** Portal `E` → Leave → file leave for a future date; status **Pending**.
- [ ] **A5.2** `S` → Leave queue → sees the team request.
- [ ] **A5.3** `S` approves → request **Approved**.
- [ ] **A5.4** Portal → Leave balance decreases by the number of leave days.
- [ ] **A5.5** Portal → Leave history shows the approval.

## A6. Attendance correction

- [ ] **A6.1** Portal `E` → Attendance → Request a correction for today (e.g. clock-out `17:05`), reason ≥10 chars.
- [ ] **A6.2** Correction queue as `P` shows **Pending**.
- [ ] **A6.3** `P` approves the correction.
- [ ] **A6.4** Attendance row updates to the corrected time; status/hours recompute.
- [ ] **A6.5** Correction history shows **Approved** and reviewer remarks.

## A7. Loan

- [ ] **A7.1** `P` → Loans → create a loan for Employee 4 (small principal, e.g. ₱400).
- [ ] **A7.2** Loan is **Pending**; no payroll deduction yet.
- [ ] **A7.3** `A` approves the loan → **Active**.
- [ ] **A7.4** Portal `E` → Loans shows the Active loan and balance.
- [ ] **A7.5** Payroll run later creates a loan payment/deduction.

## A8. Payroll run → approval → release

- [ ] **A8.1** `P` → Payroll → Process the Open period.
  - **If P2 was skipped:** mark A8.1–A8.7 as “not tested — no Open period”, then continue at A8.8 using run #1.
- [ ] **A8.2** Run status becomes **Submitted**; period status becomes **For Approval**.
- [ ] **A8.3** Portal `E` → Payslips does **not** show this run yet.
- [ ] **A8.4** `A` → Payroll Runs → **Approve** the run.
- [ ] **A8.5** Run status becomes **Approved**; period status becomes **Processed**.
- [ ] **A8.6** Payroll Runs → **Release** the run.
- [ ] **A8.7** Run status becomes **Released**.
- [ ] **A8.8** Portal `E` → Payslips now shows the Released payslip.
- [ ] **A8.9** Payslip detail shows **Basic Pay**, **Overtime Pay**, **Premium Pay** (if any), allowances, gross, deductions, net.
- [ ] **A8.10** Admin payslip and portal payslip show the same gross/net/total deductions.

## A9. Reports and exports

- [ ] **A9.1** Payroll Runs → **Payroll register** downloads/opens with the run’s employees.
- [ ] **A9.2** **Remittance** export opens and totals reconcile.
- [ ] **A9.3** **Bank file**:
  - if Employee 4 has bank details → file downloads;
  - if not → expected **422**, message names the missing employee. Record which case applies.
- [ ] **A9.4** Reports → Payroll Register / Remittance / Employee History / Department Cost / Payroll Summary all return data.
- [ ] **A9.5** Ledger shows attendance/leave entries for the period.

## A10. Audit and logout

- [ ] **A10.1** `Adm` → Audit Log shows readable actions:
      `Payroll run`, `Approve payroll`, `Release payroll`, `File overtime`, `Approve overtime`, `Kiosk attendance`, etc.
- [ ] **A10.2** Raw endpoint appears only in the row tooltip/title.
- [ ] **A10.3** Admin **Log out** shows the styled confirmation; **Cancel** stays logged in.
- [ ] **A10.4** Confirm logout → login page; Back does not restore the session.
- [ ] **A10.5** Portal **Log out** behaves the same way.
- [ ] **A10.6** Kiosk **Exit** opens the password modal; cancel returns to kiosk; valid staff password logs out.
- [ ] **A10.7** No browser native `confirm()` appears for admin/portal logout.

---

# PART B — Error and validation tests

## B1. Authentication

- [ ] **B1.1** Admin URL without login → redirected to login; direct API → **401**.
- [ ] **B1.2** Wrong password → **401**, no session created.
- [ ] **B1.3** Unknown username → **401**.
- [ ] **B1.4** Deactivated user → **403** (if you have a safe test user).
- [ ] **B1.5** Admin credentials on portal login → **403**.
- [ ] **B1.6** Portal session cannot call admin endpoints → **401/403**.
- [ ] **B1.7** Admin session cannot call portal `me_*` endpoints → **401**.

## B2. Roles

- [ ] **B2.1** `E` opening an admin page → 403/access card.
- [ ] **B2.2** `S` opening Payroll/Employees/Salaries/Reports → 403.
- [ ] **B2.3** `P` opening Users/Audit → 403.
- [ ] **B2.4** `A` opening Users → 403 (only Administrator).
- [ ] **B2.5** `Adm` opening payroll period/run → 403.
- [ ] **B2.6** Employee portal `POST me_overtime_requests.php` → **403** “filed by your Supervisor”.
- [ ] **B2.7** `P` or `A` `POST overtime_records.php` → **403**.
- [ ] **B2.8** `S` files OT for a non-team employee → **403**.
- [ ] **B2.9** `S` files OT for themselves → **403** (own-request rule).
- [ ] **B2.10** `S` approves an OT request they filed → **403**.
- [ ] **B2.11** `P` tries to approve an OT/loan/payroll → **403**.

## B3. QR and kiosk errors

- [ ] **B3.1** Unknown QR value → **404**.
- [ ] **B3.2** Empty QR → **422**.
- [ ] **B3.3** Expired token → **401/409** with “expired”.
- [ ] **B3.4** Reused token → **409** (one-time).
- [ ] **B3.5** Same QR scanned twice within 3 seconds → **409 duplicate**.
- [ ] **B3.6** Employee with **no Active QR** → portal shows no QR (404/403 API).
- [ ] **B3.7** Revoked QR → portal refuses (403); reactivation restores.
- [ ] **B3.8** Employee login on kiosk → kiosk page refused/403.
- [ ] **B3.9** Portal `me_scan_attendance.php` → **403** (no portal clocking).
- [ ] **B3.10** Kiosk clock-out with no clock-in → appropriate 404/409.

## B4. Attendance and corrections

- [ ] **B4.1** Duplicate attendance date → **409**.
- [ ] **B4.2** Missing employee/date/time-in → **422**.
- [ ] **B4.3** Invalid date / time → **422**.
- [ ] **B4.4** Correction with reason <10 chars → **422**.
- [ ] **B4.5** Future correction date → **422**.
- [ ] **B4.6** Second Pending correction for same employee/date → **409**.
- [ ] **B4.7** Clock-out correction without existing clock-in → **422**.
- [ ] **B4.8** Approve a correction twice → **409**.
- [ ] **B4.9** Reject correction without remarks → **422**.

## B5. Overtime errors

- [ ] **B5.1** OT reason <10 chars → **422**.
- [ ] **B5.2** End time before/equal start → **422**.
- [ ] **B5.3** Duplicate Pending/Approved OT for same employee/date → **409**.
- [ ] **B5.4** OT clock-in >5 min before requested start → **409** (`ot_not_started`).
- [ ] **B5.5** OT clock-in after requested end → **409** (`ot_window_passed`).
- [ ] **B5.6** OT clock-out >5 min before requested end → **409** (`ot_too_early_out`).
- [ ] **B5.7** Late OT clock-out is recorded but paid hours cap at the approved end (`capped: true`).
- [ ] **B5.8** Kiosk after clock-out with no approved OT → **409 `ot_none`**; **no OT row is created**.
- [ ] **B5.9** Kiosk scan while OT Pending → **409 `ot_pending`**.
- [ ] **B5.10** Kiosk scan after OT Rejected → **409 `ot_rejected`**; no replacement request.
- [ ] **B5.11** Employee portal OT list is read-only even if an old client posts to it → **403**.

## B6. Leave errors

- [ ] **B6.1** Overlapping leave → **409**, names the conflicting request.
- [ ] **B6.2** Invalid range (end before start) → **422**.
- [ ] **B6.3** Short reason/remarks where required → **422**.
- [ ] **B6.4** Employee cancels another employee’s request → **403**.
- [ ] **B6.5** Cancel an already Approved request → **409**.
- [ ] **B6.6** Insufficient leave balance → should refuse; confirm the exact message.

## B7. Loan errors

- [ ] **B7.1** Delete a loan with payments → **409**.
- [ ] **B7.2** Overpay principal → **422** or capped; record behavior.
- [ ] **B7.3** Approve an already Approved loan → **409**.
- [ ] **B7.4** `S` tries to approve a loan → **403**.
- [ ] **B7.5** Loan deduction cannot exceed remaining net pay.

## B8. Payroll errors

- [ ] **B8.1** Process the same period twice → **409**.
- [ ] **B8.2** Return a run → status **Returned**; resubmit → **Submitted**.
- [ ] **B8.3** Approve/Release a returned run → **409**.
- [ ] **B8.4** Void a Released run → **409**.
- [ ] **B8.5** Approve a non-Pending run → **409**.
- [ ] **B8.6** Run a period with no employees/basic salary → clear response; no negative payslips.
- [ ] **B8.7** Negative net pay is floored at **0**, not displayed as negative.
- [ ] **B8.8** Bank file with a missing employee bank detail → **422**, names all affected employees.
- [ ] **B8.9** Invalid report type/date/run id → **404/422**, not a 500.

## B9. CSRF and method checks

- [ ] **B9.1** POST/PATCH/PUT/DELETE without `X-CSRF-Token` → **403**.
- [ ] **B9.2** Form-encoded body instead of JSON → **415**.
- [ ] **B9.3** Wrong HTTP method on a protected endpoint → **405**.

---

# PART C — Lock tests

Do these **after** the payroll run is at least **Approved**. Repeat key ones after **Released**.

## C1. Attendance locks

- [ ] **C1.1** Attendance page shows the locked day with a **Locked** badge.
- [ ] **C1.2** Edit a locked attendance row → **409**.
- [ ] **C1.3** Delete a locked attendance row → **409**.
- [ ] **C1.4** Add a new attendance row on a locked date → **409**.
- [ ] **C1.5** Kiosk scan on a locked date → **409**.
- [ ] **C1.6** Correction for a locked date: filing → **409**; approving → **409**.

## C2. Overtime locks

- [ ] **C2.1** Supervisor files a new OT request on a locked date → **409**.
- [ ] **C2.2** Edit an OT request on a locked date → **409**.
- [ ] **C2.3** Delete an OT on a locked date → **409**.
- [ ] **C2.4** Approve/reject an OT on a locked date → **409**.
- [ ] **C2.5** Kiosk OT clock-in/out on a locked date → **409**.

## C3. Payslip/run locks

- [ ] **C3.1** Edit a payslip line after Approved/Released → **409**.
- [ ] **C3.2** Add/remove an allowance or deduction after lock → **409**.
- [ ] **C3.3** Add/remove a loan payment after lock → **409**.
- [ ] **C3.4** Re-run the period while a run exists → **409**.
- [ ] **C3.5** Void an Approved run → allowed only by the checker; verify.
- [ ] **C3.6** Void a Released run → **409**; requires a reversing adjustment.

## C4. Period locks

- [ ] **C4.1** Edit a `Processed` period → **403/409**.
- [ ] **C4.2** Set a `Processed` period back to Open → **409**.
- [ ] **C4.3** Create an overlapping period of the same type → **409**.
- [ ] **C4.4** Period with end before start → **422**.

---

# PART D — “If this, then that” matrix

## D1. QR / attendance

- [ ] **If no attendance today** → portal QR purpose = **Clock in**.
- [ ] **If clocked in, no timeout** → portal QR purpose = **Clock out**.
- [ ] **If clocked out and no approved OT** → no QR; kiosk scan = **409 `ot_none`**.
- [ ] **If clocked out and Approved OT time_out is NULL** → portal QR purpose = **OT**.
- [ ] **If OT Pending** → no OT QR; kiosk = **409 `ot_pending`**.
- [ ] **If OT Rejected** → no OT QR; kiosk = **409 `ot_rejected`**; no new request.
- [ ] **If QR revoked** → portal refuses; no token.
- [ ] **If QR reactivated** → portal issues a new token.
- [ ] **If token expired** → portal auto-refreshes; old screenshot fails.
- [ ] **If attendance is in a Released period** → read-only; all writes 409.

## D2. Overtime

- [ ] **If boss/supervisor files** → only their team member; `requested_by` recorded.
- [ ] **If supervisor files for another team** → 403.
- [ ] **If supervisor files for self** → 403.
- [ ] **If same supervisor tries to approve what they filed** → 403.
- [ ] **If an Approver approves** → 200; employee can start OT.
- [ ] **If Payroll Officer tries to file/approve OT** → 403.
- [ ] **If employee tries to file in portal** → 403.
- [ ] **If kiosk scans after clock-out with no approved OT** → no request created.

## D3. Payroll / portal visibility

- [ ] **If run Submitted** → portal payslip hidden.
- [ ] **If run Approved** → portal payslip hidden.
- [ ] **If run Released** → portal payslip visible.
- [ ] **If run Returned** → portal payslip hidden; resubmit possible.
- [ ] **If period Open** → run can be processed.
- [ ] **If period For Approval** → run exists; no second run.
- [ ] **If period Processed** → no edits.
- [ ] **If bank details missing** → Bank file 422.
- [ ] **If all bank details present** → Bank file downloads.
- [ ] **If a non-taxable allowance is added** → gross rises; tax unchanged.
- [ ] **If a taxable allowance is added** → gross and withholding tax rise.
- [ ] **If 13th Month run** → allowance is non-taxable; first ₱90,000 exempt.

## D4. Leave / loans

- [ ] **If leave overlaps** → 409 with the conflicting request.
- [ ] **If paid leave approved** → payroll treats the day as excused, not absent.
- [ ] **If loan is Pending** → no payroll deduction.
- [ ] **If loan is Active** → amortisation deducted (capped at remaining net).
- [ ] **If loan fully paid by payroll** → status **Paid**.
- [ ] **If run is voided** → a loan marked Paid by that run reopens Active.

## D5. Auth / audit

- [ ] **If no session** → 401.
- [ ] **If wrong role** → 403.
- [ ] **If own request** → 403 for approval/recommendation.
- [ ] **If logout confirmed** → session destroyed; Back does not restore.
- [ ] **If logout cancelled** → still on the same page/session.
- [ ] **If any mutation succeeds** → Audit Log has one row with friendly action label.
- [ ] **If mutation is refused** → Audit Log records the refusal status; no sensitive token/bank/password stored.

---

# PART E — Finish

- [ ] **E1.** Record every FAIL with: page, role, input, expected, actual, screenshot.
- [ ] **E2.** No browser console errors on happy-path pages.
- [ ] **E3.** No 500s in `C:\xampp\apache\logs\error.log` for the test session.
- [ ] **E4.** Restore the pre-test database if needed:
  ```powershell
  C:\xampp\mysql\bin\mysql.exe -u root payroll_db < C:\xampp\master_file_tests\before_full_system_test.sql
  ```
- [ ] **E5.** Confirm final counts/states: employees 4, users 6, QR code 1 Active, one Released run, no unintended test rows.
