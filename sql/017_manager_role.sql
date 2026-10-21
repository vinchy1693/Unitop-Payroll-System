-- 017_manager_role.sql
-- Replace the retired Approver role with Manager.
-- The existing approver login keeps its password hash; the account is linked
-- to employee 2 so the Manager has the same team mapping as the Supervisor
-- until a separate manager_id assignment is introduced.

UPDATE users
   SET role = 'Manager', username = 'manager', employee_id = 2
 WHERE role = 'Approver' OR username = 'approver';

-- Safety: make sure no assignable role remains named Approver.
UPDATE users SET role = 'Manager' WHERE role = 'Approver';
