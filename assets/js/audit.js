// =====================================================================
// assets/js/audit.js
// Read-only audit trail viewer (Administrator only; the API enforces it).
// =====================================================================

const rowsEl      = document.getElementById("rows");
const countEl     = document.getElementById("record-count");
const pageInfoEl  = document.getElementById("page-info");
const prevBtn     = document.getElementById("prev-page");
const nextBtn     = document.getElementById("next-page");
const filterForm  = document.getElementById("audit-filter-form");
const filterAction   = document.getElementById("filter-action");
const filterUsername = document.getElementById("filter-username");
const filterSuccess  = document.getElementById("filter-success");
const filterFrom     = document.getElementById("filter-from");
const filterTo       = document.getElementById("filter-to");

const PAGE_SIZE = 100;

let offset = 0;
let total  = 0;

// ---------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------
function displayTime(value) {
    // created_at is a plain "YYYY-MM-DD HH:MM:SS" string from the database.
    return value ? String(value) : "-";
}

function detailsCell(details) {
    if (!details) {
        return `<span style="color:var(--muted);">-</span>`;
    }

    let pretty = String(details);
    try {
        pretty = JSON.stringify(JSON.parse(pretty), null, 2);
    } catch (_) { /* keep the raw text if it is not valid JSON */ }

    if (pretty.length > 4000) {
        pretty = pretty.slice(0, 4000) + "\n...";
    }

    return `
        <details>
            <summary style="cursor:pointer;color:var(--blue);font-weight:600;">View JSON</summary>
            <pre style="margin:8px 0 0;padding:10px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;font-size:11px;line-height:1.45;white-space:pre-wrap;word-break:break-word;max-height:260px;overflow:auto;">${escapeHtml(pretty)}</pre>
        </details>`;
}

const ACTION_LABELS = {
    "auth.php": "Authentication",
    "payroll_runs.php": "Payroll run",
    "payroll_void.php": "Void payroll",
    "payroll_periods.php": "Payroll period",
    "attendance.php": "Attendance",
    "scan_attendance.php": "Kiosk attendance",
    "overtime_records.php": "Overtime",
    "attendance_corrections.php": "Attendance correction",
    "leave_requests.php": "Leave",
    "leave_balances.php": "Leave balance",
    "leave_types.php": "Leave type",
    "loans.php": "Loan",
    "employees.php": "Employee",
    "departments.php": "Department",
    "positions.php": "Position",
    "users.php": "User account",
    "qr_codes.php": "QR code",
    "reports.php": "Report",
    "bank_file.php": "Bank file export",
    "payslips.php": "Payslip",
    "allowances.php": "Allowance",
    "deductions.php": "Deduction",
    "me_overtime_requests.php": "Portal overtime",
    "me_leave_requests.php": "Portal leave",
    "me_attendance_corrections.php": "Portal correction",
    "me_loans.php": "Portal loan",
    "me_profile.php": "Portal profile",
    "me_payslips.php": "Portal payslip",
};

function actionLabel(action) {
    const raw = String(action || "").trim();
    if (raw === "") return "-";
    const known = ACTION_LABELS[raw.toLowerCase()];
    if (known) return known;
    return raw
        .replace(/\.php$/i, "")
        .replace(/[_-]+/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function renderRows(rows) {
    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">No audit records match these filters.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const ok      = Number(r.success) === 1;
        const user    = r.username ? escapeHtml(r.username) : `<span style="color:var(--muted);">(anonymous)</span>`;
        const role    = r.role ? escapeHtml(r.role) : `<span style="color:var(--muted);">-</span>`;
        return `
            <tr>
                <td style="white-space:nowrap;">${escapeHtml(displayTime(r.created_at))}</td>
                <td>${user}</td>
                <td>${role}</td>
                <td title="Source endpoint: ${escapeHtml(r.action || "-")}">${escapeHtml(actionLabel(r.action))}</td>
                <td>${escapeHtml(r.method)}</td>
                <td class="col-center">${escapeHtml(r.http_status)}</td>
                <td class="col-center"><span class="badge ${ok ? "active" : "inactive"}">${ok ? "Success" : "Failed"}</span></td>
                <td>${r.target ? escapeHtml(r.target) : `<span style="color:var(--muted);">-</span>`}</td>
                <td>${detailsCell(r.details)}</td>
            </tr>`;
    }).join("");
}

function renderPager() {
    const shown = rowsEl.querySelectorAll("tr").length;
    const from  = total === 0 ? 0 : offset + 1;
    const to    = Math.min(offset + (shown || 0), total);
    pageInfoEl.textContent = total === 0 ? "" : `Showing ${from}-${to} of ${total}`;
    prevBtn.disabled = offset <= 0;
    nextBtn.disabled = offset + PAGE_SIZE >= total;
}

// ---------------------------------------------------------------------
// Load
// ---------------------------------------------------------------------
function buildParams() {
    const params = { limit: PAGE_SIZE, offset: offset };
    const action = filterAction.value.trim();
    const username = filterUsername.value.trim();
    if (action) params.action = action;
    if (username) params.username = username;
    if (filterSuccess.value !== "") params.success = filterSuccess.value;
    if (filterFrom.value) params.from = filterFrom.value;
    if (filterTo.value) params.to = filterTo.value;
    return params;
}

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="9">Loading...</td></tr>`;
    countEl.textContent = "0 record(s)";

    try {
        const { data } = await api.get("audit_log.php", { params: buildParams() });
        total = Number(data.data.total || 0);
        const rows = data.data.rows || [];
        countEl.textContent = `${total} record(s)`;
        renderRows(rows);
        renderPager();
    } catch (err) {
        const status = err?.response?.status;
        const message = status === 403
            ? "Only an Administrator can view the audit log."
            : "Failed to load audit log: " + apiErrorMessage(err);
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">${escapeHtml(message)}</td></tr>`;
        pageInfoEl.textContent = "";
        prevBtn.disabled = true;
        nextBtn.disabled = true;
        showMessage(message, "error");
    }
}

// ---------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------
filterForm.addEventListener("submit", (e) => {
    e.preventDefault();
    offset = 0;
    loadRows();
});

document.getElementById("reset-filters").addEventListener("click", () => {
    filterForm.reset();
    offset = 0;
    loadRows();
});

prevBtn.addEventListener("click", () => {
    offset = Math.max(0, offset - PAGE_SIZE);
    loadRows();
});

nextBtn.addEventListener("click", () => {
    if (offset + PAGE_SIZE >= total) return;
    offset += PAGE_SIZE;
    loadRows();
});

document.addEventListener("DOMContentLoaded", loadRows);
