# UNITOP Role Model

The **Approver** role is removed. Its work is split between Manager, Supervisor and the combined Payroll/HR role.

## Roles

| Role | Scope |
|---|---|
| **Employee** | Own portal data |
| **Manager** | Team-scoped attendance, leave/correction approval, overtime requests, team QR codes |
| **Supervisor** | Full attendance visibility, leave/correction approval, overtime approval |
| **Payroll/HR** | Employee master data, loans, payroll processing/approval/release |
| **Administrator** | Users, roles, audit and system configuration |

The existing `approver` account was converted to the `manager` login.

## Approval rules

| Request | Approval |
|---|---|
| Attendance correction | **Manager OR Supervisor** — one approval |
| Leave | **Manager OR Supervisor** — one approval |
| Overtime | Manager requests; **Supervisor approves** |
| Loan | **Payroll/HR processes and approves** |
| Payroll run | **Payroll/HR processes, approves and releases** |

No second approval is required. A user cannot approve their own request.

Manager has team-scoped QR-code generation. Payroll/HR can view attendance in
full but cannot edit, create, delete, scan, or open the attendance-correction
queue.

## Team mapping

Managers use the existing `employees.supervisor_id` team mapping for now. A Manager account must be linked to an employee whose `employee_id` is the team lead. Supervisors have full attendance visibility.

## Employee loans

Employees can submit a loan request from the portal:

```text
Employee Portal → My Loans → Request a Loan
```

The request starts **Pending**. Payroll/HR reviews and approves it from:

```text
Admin → Loans → Approve
```

## Important control note

Payroll/HR is one combined role, so the same role processes and approves loans and payroll. This is acceptable for the current scope but is weaker segregation of duties than the former maker/checker split.
