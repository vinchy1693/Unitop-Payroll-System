# Payroll System — Master File Module (Milestone 1)

HTML/CSS/JS frontend (axios) talking to a PHP + MySQL **JSON REST API**
backend, for the 5 master/lookup tables:
**Departments, Positions, Employees, Leave Types, Users**

This is a rebuild of the original plain-PHP (server-rendered forms)
version into a proper split frontend/backend:

- **Backend** (`api/*.php`) — stateless JSON endpoints, one file per
  entity, using PDO + prepared statements. No HTML is rendered here.
- **Frontend** (`*.html` + `assets/js/*.js`) — static pages that call
  the API with **axios** and render the DOM in JavaScript. No page
  reloads for create/update/deactivate/restore.

## Setup

1. Make sure your base payroll schema (the `CREATE TABLE ...` script
   with all 20 tables) is already created in a database named
   `payroll_db` (or update `config.php` to match your DB name).

2. Run `sql/setup.sql`. It will:
   - Turn the 5 master tables' primary keys into `AUTO_INCREMENT`
   - Add an `is_active` column to each (soft-delete flag)
   - Add a `password_hash` column to `users`
   - Insert sample data into all 5 tables

3. Open `config.php` (project root) and set your MySQL host/username/
   password if they differ from the defaults (`localhost` / `root` /
   no password). This one file is shared by every endpoint in `api/`.

4. Copy this whole folder into your PHP server's web root
   (e.g. `htdocs/master_file_module` if using XAMPP) and visit:
   `http://localhost/master_file_module/index.html`

   The frontend and the API must be served by the same PHP server
   (same origin) — `index.html` calls `api/dashboard.php`,
   `departments.html` calls `api/departments.php`, etc., using
   relative axios requests. No separate server/build step needed.

## Structure

```
master_file_module/
├── config.php              # DB connection (shared by all api/*.php)
├── api/
│   ├── _bootstrap.php       # JSON headers, DB include, response helpers
│   ├── dashboard.php        # GET  -> active-record counts for all 5 tables
│   ├── departments.php      # GET/POST/PUT/PATCH
│   ├── positions.php        # GET/POST/PUT/PATCH
│   ├── employees.php        # GET/POST/PUT/PATCH
│   ├── leave_types.php      # GET/POST/PUT/PATCH
│   └── users.php            # GET/POST/PUT/PATCH
├── assets/
│   ├── css/style.css
│   └── js/
│       ├── api.js           # shared axios instance + helpers
│       ├── dashboard.js
│       ├── departments.js
│       ├── positions.js
│       ├── employees.js
│       ├── leave_types.js
│       └── users.js
├── index.html                Dashboard
├── departments.html
├── positions.html
├── employees.html
├── leave_types.html
├── users.html
└── sql/setup.sql
```

## API contract

Every `api/{entity}.php` endpoint follows the same shape. Responses
are always `{ "success": bool, "message": string, "data": ... }`.

| Method | URL example                    | Body (JSON)                                | Purpose |
|--------|---------------------------------|---------------------------------------------|---------|
| GET    | `api/departments.php`           | —                                            | List active rows |
| GET    | `api/departments.php?all=1`     | —                                            | List all rows (incl. inactive) |
| GET    | `api/departments.php?simple=1`  | —                                            | Minimal `{id,label}` list for `<select>` options |
| POST   | `api/departments.php`           | entity fields                                | Create |
| PUT    | `api/departments.php`           | entity fields + primary key                  | Update |
| PATCH  | `api/departments.php`           | `{ "<id_field>": 5, "is_active": 0 }`        | Soft delete (`is_active:0`) or restore (`is_active:1`) |

`employees.php` and `positions.php` also support `?simple=1` (used to
populate dropdowns on other pages, e.g. the Employees form's
Position/Department selects, or the Departments form's Manager select).

## Delete strategy

All 5 tables use **soft delete** (`is_active` flag) instead of a hard
`DELETE`, because every one of them is referenced by foreign keys from
transaction tables (attendance, payslips, loans, leave requests,
payroll runs, etc.). Deleting a row physically would either fail on
the FK constraint or silently orphan historical payroll records.

- The **Deactivate** button calls `PATCH` with `is_active: 0` — the
  record disappears from the default list and from dropdowns used to
  create new transactions, but stays intact for historical records
  that already reference it.
- **"Show all (incl. deactivated)"** reveals inactive rows, each with
  a **Restore** button (`PATCH` with `is_active: 1`).

## Known gaps to mention during checking

- `users.password_hash` didn't exist in the original schema — added
  here since a login/dashboard implies authentication. The seeded
  sample passwords are placeholders; edit each user once through the
  UI to set a real password. The API never returns `password_hash` in
  list responses.
- No login/session-guard is implemented on these pages/endpoints yet
  (any client that can reach the URL can call the API). Add a
  `session_start()` + auth check at the top of `api/_bootstrap.php` if
  your checking requires an actual login wall — every endpoint already
  funnels through that one file.
- CORS headers in `api/_bootstrap.php` are commented out because the
  frontend and API are served from the same origin by default. Only
  uncomment/configure them if you split the frontend onto a different
  host/port later.
