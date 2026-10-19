-- 012_scrub_audit_sensitive.sql
--
-- The audit hook used to persist a few identifiers that are themselves live
-- secrets: scan_attendance.php stored the QR value in `details`, and the
-- employee endpoint stored bank and government numbers. The hook now redacts
-- them (api/_bootstrap.php -> audit_is_secret_key()), and this migration
-- removes the copies that are already in the table.
--
-- Only the named keys are touched; contribution amounts (sss_ee) and record
-- ids (qr_code_id) are deliberately left readable.

UPDATE `audit_log`
SET `details` = REGEXP_REPLACE(
        `details`,
        '"(code_value|bank_account_number|tin_number|sss_number|philhealth_number|pagibig_number)"[[:space:]]*:[[:space:]]*"[^"]*"',
        '"\\1":"***"'
    )
WHERE `details` REGEXP
      '"(code_value|bank_account_number|tin_number|sss_number|philhealth_number|pagibig_number)"[[:space:]]*:[[:space:]]*"[^"]*"';
