-- =====================================================================
-- 009_holidays.sql
-- Philippine holidays, so a national holiday is never counted as an absence.
--
-- Basis: Labor Code Art. 94 - a covered employee is entitled to the regular
-- daily wage on a REGULAR holiday even when no work is performed. The payroll
-- previously counted every Mon-Sat date as a working day, so a regular holiday
-- with no attendance was treated as an ABSENCE and the employee was docked for
-- a day they are legally entitled to be paid.
--
-- A holiday is now excluded from the expected working days: it is neither
-- present, excused nor absent, so nobody is docked for it.
--
-- Movable holidays must be added per proclamation: Holy Week (Maundy Thursday,
-- Good Friday), Eid'l Fitr and Eid'l Adha. Only fixed-date holidays are seeded.
-- =====================================================================
CREATE TABLE IF NOT EXISTS holidays (
    holiday_id   BIGINT NOT NULL AUTO_INCREMENT,
    holiday_date DATE NOT NULL,
    name         VARCHAR(100) NOT NULL,
    holiday_type VARCHAR(20) NOT NULL DEFAULT 'Regular',   -- Regular | Special
    is_paid      TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (holiday_id),
    UNIQUE KEY uq_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO holidays (holiday_date, name, holiday_type, is_paid) VALUES
  ('2026-01-01', 'New Year''s Day',            'Regular', 1),
  ('2026-04-09', 'Araw ng Kagitingan',          'Regular', 1),
  ('2026-05-01', 'Labor Day',                   'Regular', 1),
  ('2026-06-12', 'Independence Day',            'Regular', 1),
  ('2026-08-31', 'National Heroes Day',         'Regular', 1),
  ('2026-11-30', 'Bonifacio Day',               'Regular', 1),
  ('2026-12-25', 'Christmas Day',               'Regular', 1),
  ('2026-12-30', 'Rizal Day',                   'Regular', 1),
  ('2026-08-21', 'Ninoy Aquino Day',            'Special', 1),
  ('2026-11-01', 'All Saints'' Day',            'Special', 1),
  ('2026-12-08', 'Feast of the Immaculate Conception', 'Special', 1),
  ('2026-12-31', 'Last Day of the Year',        'Special', 1);