-- 011_allowance_taxability.sql
-- Some allowances are non-taxable (de minimis benefits, BIR RR 11-2018 2.78.1:
-- rice, uniform, medical cash, laundry, meal). is_taxable lets an allowance be
-- marked exempt so it raises gross pay without raising the tax base.
ALTER TABLE allowances ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 1 AFTER allowance_type;
