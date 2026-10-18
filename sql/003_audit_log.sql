-- =====================================================================
-- 003_audit_log.sql
--
-- Audit trail for every mutating API request (POST / PUT / PATCH / DELETE).
-- The write happens centrally in respond() (api/_bootstrap.php), so no
-- endpoint has to remember to log anything.
--
--   action      : endpoint file name, e.g. "departments.php"
--   method      : HTTP verb of the request
--   http_status : status passed to respond()
--   success     : 1 when http_status < 400, otherwise 0
--   message     : response message shown to the caller
--   target      : affected record when it can be derived cheaply
--   details     : sanitized JSON snapshot of the request body. Secret
--                 keys (password / token / csrf ...) are replaced by "***"
--                 before the row is written.
--   ip          : REMOTE_ADDR of the caller
--
-- user_id / username / role are NULL for anonymous requests (failed
-- logins, kiosk scans). No foreign key on user_id on purpose: audit rows
-- must survive user deletion.
--
-- Idempotent: safe to run more than once.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `audit_log` (
    `audit_id`    BIGINT       NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT       NULL DEFAULT NULL,
    `username`    VARCHAR(100) NULL DEFAULT NULL,
    `role`        VARCHAR(50)  NULL DEFAULT NULL,
    `action`      VARCHAR(80)  NOT NULL,
    `method`      VARCHAR(10)  NOT NULL,
    `http_status` SMALLINT     NOT NULL,
    `success`     TINYINT(1)   NOT NULL,
    `message`     VARCHAR(500) NULL DEFAULT NULL,
    `target`      VARCHAR(200) NULL DEFAULT NULL,
    `details`     TEXT         NULL DEFAULT NULL,
    `ip`          VARCHAR(45)  NULL DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`audit_id`),
    KEY `idx_audit_created_at` (`created_at`),
    KEY `idx_audit_action`     (`action`),
    KEY `idx_audit_username`   (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
