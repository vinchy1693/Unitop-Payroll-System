-- =====================================================================
-- 010_one_run_per_period.sql
--
-- A payroll period must have at most ONE run.
--
-- The application checked "status = Open" OUTSIDE its transaction and
-- payroll_runs had no unique key, so two concurrent Process requests - a double
-- click, a slow page, a retried request - both saw Open and both created a run:
-- duplicate payslips, and loan payments booked twice against one amortisation.
--
-- The unique key makes that impossible at the storage layer, which is the only
-- place a race can actually be stopped.
--
-- The application should additionally move its "period is Open" check inside the
-- transaction with SELECT ... FOR UPDATE, so the second request receives a clean
-- 409 instead of a duplicate-key error.
-- =====================================================================

ALTER TABLE payroll_runs
    ADD UNIQUE KEY uq_runs_period (period_id);
