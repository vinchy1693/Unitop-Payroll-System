-- =====================================================================
-- 020_official_holidays_2026_2027.sql
--
-- Official holidays covering the December 2026 and January 2027
-- payroll periods used by the system.
-- =====================================================================

INSERT INTO holidays (holiday_date, name, holiday_type, is_paid)
VALUES
    ('2026-12-08', 'Feast of the Immaculate Conception', 'Special', 1),
    ('2026-12-24', 'Christmas Eve', 'Special', 1),
    ('2026-12-25', 'Christmas Day', 'Regular', 1),
    ('2026-12-30', 'Rizal Day', 'Regular', 1),
    ('2026-12-31', 'Last Day of the Year', 'Special', 1),
    ('2027-01-01', 'New Year''s Day', 'Regular', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    holiday_type = VALUES(holiday_type),
    is_paid = VALUES(is_paid);
