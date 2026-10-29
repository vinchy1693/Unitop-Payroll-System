-- =====================================================================
-- 018_fixed_leave_types.sql
--
-- Make leave types a fixed statutory catalogue. Payroll/HR can no longer
-- create, rename, or deactivate leave types from the UI.
--
-- Existing leave_requests and leave_balances keep their leave_type_id;
-- Birthday Leave and Vacation Leave are retired but not deleted so history
-- remains valid.
-- =====================================================================

ALTER TABLE leave_types
    ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS service_months INT NOT NULL DEFAULT 0 AFTER days_per_year,
    ADD COLUMN IF NOT EXISTS is_cash_convertible TINYINT(1) NOT NULL DEFAULT 0 AFTER is_paid;

-- Retire non-statutory company types. Existing requests stay linked by id.
UPDATE leave_types
SET is_active = 0,
    description = 'Retired company leave type. Use Service Incentive Leave (SIL) for vacation or sick leave.'
WHERE leave_type_id = 1;

UPDATE leave_types
SET is_active = 0,
    description = 'Retired company leave type. Use Service Incentive Leave (SIL) for vacation or sick leave.'
WHERE leave_type_id = 3;

-- Normalize the existing maternity record.
UPDATE leave_types
SET name = 'Maternity Leave',
    days_per_year = 105,
    is_paid = 1,
    is_active = 1,
    description = '105 paid days for live childbirth under RA 11210.',
    service_months = 0,
    is_cash_convertible = 0
WHERE leave_type_id = 2;

INSERT INTO leave_types (name, description, days_per_year, service_months, is_paid, is_cash_convertible, is_active)
SELECT 'Service Incentive Leave (SIL)',
       '5 paid days per year after at least one year of service; unused days are convertible to cash at year-end.',
       5, 12, 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Service Incentive Leave (SIL)');

INSERT INTO leave_types (name, description, days_per_year, service_months, is_paid, is_cash_convertible, is_active)
SELECT 'Maternity Leave - Solo Parent',
       '120 paid days for a solo mother for live childbirth under RA 11210.',
       120, 0, 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Maternity Leave - Solo Parent');

INSERT INTO leave_types (name, description, days_per_year, service_months, is_paid, is_cash_convertible, is_active)
SELECT 'Maternity Leave - Miscarriage',
       '60 paid days for miscarriage under RA 11210.',
       60, 0, 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Maternity Leave - Miscarriage');

INSERT INTO leave_types (name, description, days_per_year, service_months, is_paid, is_cash_convertible, is_active)
SELECT 'Paternity Leave',
       '7 paid days for married male employees for the first four deliveries or miscarriages of their legitimate spouse.',
       7, 0, 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Paternity Leave');

INSERT INTO leave_types (name, description, days_per_year, service_months, is_paid, is_cash_convertible, is_active)
SELECT 'Solo Parent Leave',
       '7 paid days per year for qualified solo parents after at least one year of service under RA 8972.',
       7, 12, 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Solo Parent Leave');

INSERT INTO leave_types (name, description, days_per_year, service_months, is_paid, is_cash_convertible, is_active)
SELECT 'VAWC Leave',
       'Up to 10 paid days for female employees who are victims of violence against women and their children under RA 9262.',
       10, 0, 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'VAWC Leave');

INSERT INTO leave_types (name, description, days_per_year, service_months, is_paid, is_cash_convertible, is_active)
SELECT 'Magna Carta for Women Special Leave',
       'Up to 2 months (60 days) of paid leave for gynecological surgery after at least 6 months of continuous service.',
       60, 6, 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE name = 'Magna Carta for Women Special Leave');
