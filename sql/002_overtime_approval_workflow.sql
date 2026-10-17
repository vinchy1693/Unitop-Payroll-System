-- =====================================================================
-- 002_overtime_approval_workflow.sql
--
-- Turns overtime_records into an approval workflow:
--   * a request is filed first (Pending, requested_at set, no time_in yet)
--   * an admin approves or rejects it (approved_by / approved_at)
--   * only an approved request may be clocked in (time_in) and clocked
--     out (time_out); the clock-out computes hours and marks it Completed
--
-- status : Pending | Approved | Rejected | Completed
--   Pending   = employee asked for overtime, waiting for the admin
--   Approved  = admin said yes; the employee may start overtime
--   Rejected  = admin said no (kept for audit, excluded from payroll)
--   Completed = overtime actually started and ended; hours are payable
--
-- Idempotent: safe to run more than once.
-- =====================================================================

ALTER TABLE `overtime_records`
    ADD COLUMN IF NOT EXISTS `status`       VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER `rate_multiplier`,
    ADD COLUMN IF NOT EXISTS `time_in`      TIME        NULL DEFAULT NULL             AFTER `status`,
    ADD COLUMN IF NOT EXISTS `time_out`     TIME        NULL DEFAULT NULL             AFTER `time_in`,
    ADD COLUMN IF NOT EXISTS `requested_at` DATETIME    NULL DEFAULT NULL             AFTER `time_out`,
    ADD COLUMN IF NOT EXISTS `approved_by`  BIGINT      NULL DEFAULT NULL             AFTER `requested_at`,
    ADD COLUMN IF NOT EXISTS `approved_at`  DATETIME    NULL DEFAULT NULL             AFTER `approved_by`;

-- The approval queue reads "today's requests" and filters by status.
CREATE INDEX IF NOT EXISTS `idx_overtime_status_date` ON `overtime_records` (`status`, `ot_date`);
CREATE INDEX IF NOT EXISTS `idx_overtime_approved_by` ON `overtime_records` (`approved_by`);
