-- =====================================================================
-- 019_diverse_org_structure.sql
--
-- Give the non-Vinchy employees distinct departments, positions and
-- realistic salaries. Vinchy Fantonial (employee 1) is deliberately not
-- modified.
--
-- Existing ids 1 (HR), 2 (IT), 3 (Maintenance) and position ids 1-4 are
-- retained. New records use the next free ids.
-- =====================================================================

INSERT INTO departments (department_id, department_name, manager_employee_id, is_active)
VALUES
    (4, 'Finance & Accounting', 2, 1),
    (5, 'Operations', 3, 1),
    (6, 'Logistics', 4, 1)
ON DUPLICATE KEY UPDATE
    department_name = VALUES(department_name),
    manager_employee_id = VALUES(manager_employee_id),
    is_active = 1;

INSERT INTO positions (position_id, department_id, title, is_active)
VALUES
    (5, 4, 'Finance Manager', 1),
    (6, 5, 'Operations Supervisor', 1),
    (7, 6, 'Logistics Coordinator', 1)
ON DUPLICATE KEY UPDATE
    department_id = VALUES(department_id),
    title = VALUES(title),
    is_active = 1;

-- Do not update employee_id 1.
UPDATE employees
SET department_id = 4, position_id = 5, basic_salary = 55000.00
WHERE employee_id = 2;

UPDATE employees
SET department_id = 5, position_id = 6, basic_salary = 38000.00
WHERE employee_id = 3;

UPDATE employees
SET department_id = 6, position_id = 7, basic_salary = 28000.00
WHERE employee_id = 4;

-- Repair the employee link for Juan's login account.
UPDATE users
SET employee_id = 2
WHERE username = 'juan.delacruz';

INSERT INTO audit_log
    (user_id, username, role, action, method, http_status, success, message, target, details, ip)
VALUES
    (1, 'admin', 'Administrator', 'ORG_STRUCTURE_UPDATED', 'POST', 200, 1,
     'Moved Juan, Famacion and Harry to distinct departments/positions with realistic salaries; Vinchy employee row untouched.',
     'employees:2,3,4',
     JSON_OBJECT('employees_updated',3,'vinchy_employee_id',1,'vinchy_untouched',1),
     '127.0.0.1');
