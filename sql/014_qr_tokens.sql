-- =====================================================================
-- 014_qr_tokens.sql
--
-- Short-lived, one-time QR tokens for the staffed kiosk.
--
-- The old qr_codes.code_value is a static bearer value: a screenshot is
-- valid until HR revokes it. The portal now displays a token that:
--   * expires after QR_TOKEN_TTL_SECONDS (120 by default),
--   * is bound to the next action (time_in / time_out),
--   * can be consumed once,
--   * is stored only as a SHA-256 hash.
--
-- qr_codes remains for legacy printed badges and the admin QR page.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `qr_tokens` (
  `token_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `purpose` varchar(20) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_qr_token_hash` (`token_hash`),
  KEY `idx_qr_token_employee` (`employee_id`,`expires_at`),
  CONSTRAINT `fk_qr_token_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
