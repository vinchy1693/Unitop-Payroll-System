-- 013_premium_ot_request_undertime.sql
--
-- 1. Premium pay for work on rest days / special holidays / regular holidays is
--    paid as a separate earning line, so the payslip can show it independently
--    of ordinary overtime.
-- 2. Overtime requests now carry the employee's reason, the requested window and
--    the server-derived day type. The multiplier is derived from that day type,
--    not trusted from the client.
--
-- Existing overtime rows are backfilled to the correct day type and at least the
-- statutory multiplier (a row stored as 1.25 on a holiday is raised).

ALTER TABLE `payslips`
    ADD COLUMN `premium_pay` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `overtime_pay`;

ALTER TABLE `overtime_records`
    ADD COLUMN `reason` VARCHAR(500) NULL AFTER `rate_multiplier`,
    ADD COLUMN `requested_start` TIME NULL AFTER `reason`,
    ADD COLUMN `requested_end` TIME NULL AFTER `requested_start`,
    ADD COLUMN `day_type` VARCHAR(30) NULL AFTER `requested_end`;

-- Day type by date: holiday takes precedence, then Sunday (the system's rest day).
UPDATE `overtime_records`
SET `day_type` = CASE
        WHEN `ot_date` IN (SELECT `holiday_date` FROM `holidays` WHERE `holiday_type` = 'Regular') THEN 'Regular Holiday'
        WHEN `ot_date` IN (SELECT `holiday_date` FROM `holidays` WHERE `holiday_type` = 'Special') THEN 'Special Holiday'
        WHEN DAYOFWEEK(`ot_date`) = 1 THEN 'Rest Day'
        ELSE 'Ordinary Day'
    END
WHERE `day_type` IS NULL;

-- Raise (never lower) the stored multiplier to the statutory minimum for the day.
UPDATE `overtime_records`
SET `rate_multiplier` = CASE `day_type`
        WHEN 'Regular Holiday' THEN 2.60
        WHEN 'Special Holiday' THEN 1.69
        WHEN 'Rest Day'       THEN 1.69
        ELSE 1.25
    END
WHERE `rate_multiplier` IS NULL
   OR `rate_multiplier` < CASE `day_type`
        WHEN 'Regular Holiday' THEN 2.60
        WHEN 'Special Holiday' THEN 1.69
        WHEN 'Rest Day'       THEN 1.69
        ELSE 1.25
    END;

-- Keep the human label in step with the derived type.
UPDATE `overtime_records`
SET `ot_type` = `day_type`
WHERE `ot_type` = 'Ordinary Day' AND `day_type` <> 'Ordinary Day';

-- A completed block already knew its actual window; keep it as the requested one
-- for display. Pending blocks stay NULL until the employee reviews them.
UPDATE `overtime_records`
SET `requested_start` = `time_in`,
    `requested_end`   = `time_out`
WHERE `requested_start` IS NULL AND `time_in` IS NOT NULL;
