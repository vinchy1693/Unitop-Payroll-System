-- =====================================================================
-- 007_attendance_corrections.sql
--
-- Attendance correction requests (segregation of duties, step 5).
--
-- An employee who forgot to scan out (or scanned at the wrong time) has no
-- way to fix the record: api/attendance.php is MAKER-writable only and the
-- kiosk endpoint never edits a stored time. This table is the request queue:
--
--   Employee  files a correction for their OWN attendance (portal)
--   Maker     approves / rejects it      -> api/attendance_corrections.php
--   Checker   reads the queue            -> same endpoint, GET
--
--   correction_type : clock_in | clock_out | both
--   status          : Pending | Approved | Rejected
--
-- attendance_id is NULL while no attendance row exists for that
-- employee + work_date. On approval the endpoint creates the row if needed,
-- stores its id here, and recomputes hours_worked / minutes_late with the
-- exact rules api/scan_attendance.php uses (shift starts 08:00; a span over
-- 300 minutes loses the 60-minute lunch).
--
-- The foreign key on attendance_id is ON DELETE SET NULL: deleting an
-- attendance record must not fail because a correction once pointed at it,
-- and the correction keeps its own work_date/requested times.
--
-- Idempotent: safe to run more than once.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `attendance_corrections` (
    `correction_id`      BIGINT       NOT NULL AUTO_INCREMENT,
    `employee_id`        BIGINT       NOT NULL,
    `attendance_id`      BIGINT       NULL DEFAULT NULL,
    `work_date`          DATE         NOT NULL,
    `correction_type`    VARCHAR(20)  NOT NULL,
    `requested_time_in`  TIME         NULL DEFAULT NULL,
    `requested_time_out` TIME         NULL DEFAULT NULL,
    `reason`             VARCHAR(500) NOT NULL,
    `status`             VARCHAR(20)  NOT NULL DEFAULT 'Pending',
    `requested_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_by`        BIGINT       NULL DEFAULT NULL,
    `reviewed_at`        DATETIME     NULL DEFAULT NULL,
    `review_remarks`     VARCHAR(500) NULL DEFAULT NULL,
    PRIMARY KEY (`correction_id`),
    KEY `idx_corrections_status` (`status`),
    KEY `idx_corrections_employee_date` (`employee_id`, `work_date`),
    KEY `idx_corrections_attendance` (`attendance_id`),
    CONSTRAINT `fk_corrections_employee`
        FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
    CONSTRAINT `fk_corrections_attendance`
        FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The queue is read as "Pending first, newest first" and filtered by
-- employee + date range, which the two indexes above already cover.
