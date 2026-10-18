-- =====================================================================
-- 008_supervisor_teams.sql
--
-- The Supervisor role (segregation of duties, step 6).
--
--   Employee   - portal self-service only
--   Supervisor - a team lead: reads and decides ONLY their own team's
--                attendance, overtime corrections, overtime and leave, and
--                RECOMMENDS (never approves) a team member's loan.
--   Maker      - Payroll Officer: unchanged, still the full master-data /
--                payroll writer
--   Checker    - Approver: unchanged, still decides loans and payroll runs
--
-- A "team" is defined once, structurally: every employee whose
-- employees.supervisor_id points at this employee. Nothing is denormalised
-- into a teams table, so moving somebody between teams is one UPDATE, and
-- deleting the supervisor sets their team members back to supervisor_id NULL
-- instead of failing the delete.
--
--   employees.supervisor_id  -> team membership (self-referencing FK)
--   loans.recommended_by/at/recommendation_remarks
--                            -> the Supervisor's ADVICE on a loan request.
--                               loans.status is deliberately NOT touched by a
--                               recommendation: the Approver still decides.
--
-- Idempotent: safe to run more than once.
-- =====================================================================

-- ---- team membership -------------------------------------------------
ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `supervisor_id` BIGINT NULL DEFAULT NULL AFTER `department_id`;

CREATE INDEX IF NOT EXISTS `idx_employees_supervisor` ON `employees` (`supervisor_id`);

-- MariaDB has no ADD CONSTRAINT IF NOT EXISTS, so add the self-referencing
-- foreign key only when it is not already there. ON DELETE SET NULL: removing
-- a supervisor must never block on, or cascade into, their team members.
SET @has_supervisor_fk := (
    SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME        = 'employees'
       AND CONSTRAINT_NAME   = 'fk_employees_supervisor'
);
SET @add_supervisor_fk := IF(
    @has_supervisor_fk > 0,
    'SELECT 1',
    'ALTER TABLE `employees`
       ADD CONSTRAINT `fk_employees_supervisor`
       FOREIGN KEY (`supervisor_id`) REFERENCES `employees` (`employee_id`)
       ON DELETE SET NULL'
);
PREPARE stmt FROM @add_supervisor_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---- loan recommendation ---------------------------------------------
-- recommended_by is a users.user_id, exactly like attendance_corrections
-- .reviewed_by and overtime_records.approved_by: those columns carry no FK
-- either, so a user can be removed without orphaning a decision.
ALTER TABLE `loans`
    ADD COLUMN IF NOT EXISTS `recommended_by`        BIGINT       NULL DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `recommended_at`        DATETIME     NULL DEFAULT NULL AFTER `recommended_by`,
    ADD COLUMN IF NOT EXISTS `recommendation_remarks` VARCHAR(500) NULL DEFAULT NULL AFTER `recommended_at`;

-- The loans list is read as "Pending first, newest first" and a supervisor
-- list is filtered by employee_id, which the existing loan_id PK and status
-- scan already serve. No extra index is needed at this size.
