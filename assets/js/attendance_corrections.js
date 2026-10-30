// =====================================================================
// assets/js/attendance_corrections.js
//
// Attendance correction approval queue.
//
// Employees file corrections from the portal; this page lets the Manager or
// Supervisor give the single approval. Payroll/HR reviews the approved queue.
//
// Action buttons are only rendered for a Manager or Supervisor (each for
// their own team). The API enforces the same rule.
// =====================================================================

const rowsEl       = document.getElementById("rows");
const countEl      = document.getElementById("record-count");
const pendingEl    = document.getElementById("pending-count");
const filterStatus = document.getElementById("filter-status");
const filterEmp    = document.getElementById("filter-employee");
const filterMonth  = document.getElementById("filter_month");
const filterPeriod = document.getElementById("filter_period");
const filterRange  = document.getElementById("filter_range");
const clearDates   = document.getElementById("clear-dates");
const directCard   = document.getElementById("direct-card");
const directForm   = document.getElementById("direct-form");
const directEmp    = document.getElementById("direct-employee");
const directDate   = document.getElementById("direct-date");
const directIn     = document.getElementById("direct-time-in");
const directOut    = document.getElementById("direct-time-out");
const directReason = document.getElementById("direct-reason");
const directSubmit = document.getElementById("direct-submit");

const PAGE_SIZE = 200;

let currentUser = null;
let currentRows = [];
let lastTotal   = 0;

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function isManager()    { return !!currentUser && currentUser.role === "Manager"; }
function isSupervisor() { return !!currentUser && currentUser.role === "Supervisor"; }
function canDecide()    { return isManager() || isSupervisor(); }

function formatTime(value) {
    if (!value) return "&mdash;";
    const parts = String(value).split(":");
    let hour = parseInt(parts[0], 10);
    if (isNaN(hour)) return escapeHtml(value);
    const minute = parts[1] || "00";
    const suffix = hour >= 12 ? "PM" : "AM";
    hour = hour % 12;
    if (hour === 0) hour = 12;
    return `${hour}:${minute} ${suffix}`;
}

function formatDateTime(value) {
    if (!value) return "&mdash;";
    const parts = String(value).split(" ");
    const date = parts[0] || "";
    const time = parts[1] || "";
    return `${escapeHtml(date)}<br><span style="color:var(--muted);font-size:11px;">${formatTime(time)}</span>`;
}

function typeLabel(type) {
    if (type === "clock_in")  return "Time in";
    if (type === "clock_out") return "Time out";
    if (type === "both")      return "Both times";
    return escapeHtml(type || "-");
}

function statusBadge(status) {
    const styles = {
        Pending:  "background:var(--yellow-lite);color:var(--yellow-dark)",
        Approved: "background:var(--green-lite);color:var(--green)",
        Rejected: "background:var(--red-lite);color:var(--red)",
    };
    return `<span class="badge" style="${styles[status] || ""}">${escapeHtml(status)}</span>`;
}

/** The record as it stands now. "no record" is a meaningful state here. */
function currentCell(row) {
    if (!row.has_attendance) {
        return `<span style="color:var(--red);font-size:12px;">no record</span>`;
    }
    return `in ${formatTime(row.current_time_in)}<br>`
        + `<span style="color:var(--muted);font-size:12px;">out ${formatTime(row.current_time_out)}</span>`;
}

/** Only the times this correction would set, so before/after reads at a glance. */
function requestedCell(row) {
    const kind = row.correction_type;
    const lineIn  = `in ${formatTime(row.requested_time_in)}`;
    const lineOut = `out ${formatTime(row.requested_time_out)}`;
    if (kind === "clock_in")  return `<strong>${lineIn}</strong>`;
    if (kind === "clock_out") return `<strong>${lineOut}</strong>`;
    return `<strong>${lineIn}</strong><br><strong>${lineOut}</strong>`;
}

function reasonCell(reason) {
    const text = String(reason || "");
    const short = text.length > 90 ? text.slice(0, 90) + "..." : text;
    return `<span title="${escapeHtml(text)}">${escapeHtml(short)}</span>`;
}

function statusCell(row) {
    const who = row.reviewed_by_name
        ? `<div style="font-size:11px;color:var(--muted);">by ${escapeHtml(row.reviewed_by_name)}</div>`
        : "";
    const remarks = row.review_remarks
        ? `<div style="font-size:11px;color:var(--muted);margin-top:4px;">${escapeHtml(row.review_remarks)}</div>`
        : "";
    return `${statusBadge(row.status)}${who}${remarks}`;
}

function renderRows(rows) {
    currentRows = rows;
    countEl.textContent = lastTotal > rows.length
        ? `${rows.length} of ${lastTotal} record(s)`
        : `${lastTotal} record(s)`;

    if (rows.length === 0) {
        const label = filterStatus.value ? filterStatus.value.toLowerCase() : "";
        const who = filterEmp.value ? " for this employee" : "";
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">No ${escapeHtml(label)} correction requests${who}.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const actions = (r.status === "Pending" && canDecide())
            ? `<button class="btn btn-restore" data-approve="${r.correction_id}">Approve</button>
               <button class="btn btn-delete" data-reject="${r.correction_id}">Reject</button>`
            : `<span style="color:var(--muted);font-size:12px;">&mdash;</span>`;

        return `
        <tr>
            <td>${escapeHtml(r.employee_name)}<br><span style="color:var(--muted);font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
            <td class="col-center">${escapeHtml(r.work_date)}<br><span style="color:var(--muted);font-size:11px;">filed ${formatDateTime(r.requested_at)}</span></td>
            <td class="col-center">${typeLabel(r.correction_type)}</td>
            <td class="col-center">${currentCell(r)}</td>
            <td class="col-center">${requestedCell(r)}</td>
            <td>${reasonCell(r.reason)}</td>
            <td class="col-center">${statusCell(r)}</td>
            <td class="col-center">${actions}</td>
        </tr>`;
    }).join("");

    rowsEl.querySelectorAll("[data-approve]").forEach((btn) => {
        btn.addEventListener("click", () => approveCorrection(btn.dataset.approve));
    });
    rowsEl.querySelectorAll("[data-reject]").forEach((btn) => {
        btn.addEventListener("click", () => rejectCorrection(btn.dataset.reject));
    });
}

// ---------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------
async function loadEmployees() {
    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        const list = data.data || [];
        filterEmp.innerHTML = `<option value="">All employees</option>` +
            list.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        if (directEmp) {
            directEmp.innerHTML = `<option value="">-- Select employee --</option>` +
                list.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        }
    } catch (err) {
        // The queue still works without the filter.
        filterEmp.innerHTML = `<option value="">All employees</option>`;
    }
}

async function loadRows() {
    const params = { limit: PAGE_SIZE, offset: 0 };
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterEmp.value)    params.employee_id = filterEmp.value;
    const range = periodFilterRange();
    if (range) {
        params.from = range.from;
        params.to = range.to;
    }

    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="8">Loading...</td></tr>`;
    try {
        const { data } = await api.get("attendance_corrections.php", { params });
        lastTotal = Number(data.data.total || 0);
        renderRows(data.data.rows || []);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

async function loadPendingCount() {
    try {
        const { data } = await api.get("attendance_corrections.php", {
            params: { status: "Pending", limit: 1 },
        });
        pendingEl.textContent = String(Number(data.data.total || 0));
    } catch (err) {
        pendingEl.textContent = "?";
    }
}

function refresh() {
    return Promise.all([loadRows(), loadPendingCount()]);
}

if (directForm) {
    directForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        const employeeId = Number(directEmp.value);
        const workDate   = directDate.value;
        const timeIn     = directIn.value;
        const timeOut    = directOut.value;
        const reason     = directReason.value.trim();

        if (!employeeId)   { showToast("Select an employee.", "error"); return; }
        if (!workDate)     { showToast("Select a work date.", "error"); return; }
        if (!timeIn || !timeOut) { showToast("Enter both time in and time out.", "error"); return; }
        if (timeOut <= timeIn)   { showToast("Time out must be after time in.", "error"); return; }
        if (reason.length < 10)  { showToast("Enter a reason of at least 10 characters.", "error"); return; }

        const employeeLabel = directEmp.options[directEmp.selectedIndex]?.textContent || "employee";
        if (!await confirmDialog(
            `Correct ${employeeLabel} for ${workDate} to ${timeIn}–${timeOut}? This applies immediately.`,
            { title: "Save Attendance Correction", icon: "question", confirmText: "Save Correction" }
        )) return;

        directSubmit.disabled = true;
        try {
            const { data } = await api.post("attendance_corrections.php", {
                employee_id: employeeId,
                work_date: workDate,
                time_in: timeIn,
                time_out: timeOut,
                reason,
            });
            showToast(data.message, "success");
            directIn.value = "";
            directOut.value = "";
            directReason.value = "";
            directDate.value = new Date().toISOString().slice(0, 10);
            await refresh();
        } catch (err) {
            showToast(apiErrorMessage(err), "error");
        } finally {
            directSubmit.disabled = false;
        }
    });
}

// ---------------------------------------------------------------------
// Decisions
// ---------------------------------------------------------------------
function rowById(id) {
    return currentRows.find((r) => String(r.correction_id) === String(id)) || null;
}

async function approveCorrection(id) {
    const row = rowById(id);
    if (!row) return;

    const detail = row.has_attendance
        ? `The existing record will be updated and its hours worked and late minutes recomputed.`
        : `No attendance record exists for that date yet, so one will be created.`;

    const result = await Swal.fire({
        title: "Approve Correction",
        html: `<div style="font-size:13px;text-align:left;margin-bottom:10px;">
                 Approve <strong>${escapeHtml(row.employee_name)}</strong>'s correction for
                 <strong>${escapeHtml(row.work_date)}</strong>.
                 ${escapeHtml(detail)}
               </div>`,
        input: "textarea",
        inputPlaceholder: "Optional remarks for the employee…",
        inputAttributes: { maxlength: 500, rows: 3 },
        showCancelButton: true,
        confirmButtonText: "Approve",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#0038A8",
        cancelButtonColor: "#6b7280",
        reverseButtons: true,
        inputValidator: (value) => (String(value || "").length <= 500)
            ? undefined
            : "Remarks must be at most 500 characters.",
    });
    if (!result.isConfirmed) return;

    try {
        const { data } = await api.patch("attendance_corrections.php", {
            correction_id: row.correction_id,
            action: "approve",
            remarks: String(result.value || "").trim(),
        });
        showToast(data.message, "success");
        await refresh();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

async function rejectCorrection(id) {
    const row = rowById(id);
    if (!row) return;

    const result = await Swal.fire({
        title: "Reject Correction Request",
        html: `<div style="font-size:13px;text-align:left;margin-bottom:10px;">
                 Rejecting <strong>${escapeHtml(row.employee_name)}</strong>'s correction for
                 <strong>${escapeHtml(row.work_date)}</strong>. Attendance will not change.
               </div>`,
        input: "textarea",
        inputPlaceholder: "Why is this being rejected? The employee will see this.",
        inputAttributes: { maxlength: 500, rows: 3 },
        showCancelButton: true,
        confirmButtonText: "Reject",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#CE1126",
        cancelButtonColor: "#6b7280",
        reverseButtons: true,
        inputValidator: (value) => (value && value.trim().length > 0)
            ? undefined
            : "Remarks are required when rejecting.",
    });
    if (!result.isConfirmed) return;

    try {
        const { data } = await api.patch("attendance_corrections.php", {
            correction_id: row.correction_id,
            action: "reject",
            remarks: String(result.value || "").trim(),
        });
        showToast(data.message, "success");
        await refresh();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

// ---------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------
filterStatus.addEventListener("change", loadRows);
filterEmp.addEventListener("change", loadRows);

function periodFilterRange() {
    const month = filterMonth.value;
    const half = filterPeriod.value;
    if (!/^\d{4}-\d{2}$/.test(month)) return null;
    const [year, monthNo] = month.split("-").map(Number);
    const last = new Date(Date.UTC(year, monthNo, 0)).getUTCDate();
    return {
        from: half === "first" ? `${month}-01` : `${month}-16`,
        to: half === "first" ? `${month}-15` : `${month}-${String(last).padStart(2, "0")}`,
    };
}

function syncDateClear() {
    const range = periodFilterRange();
    clearDates.classList.toggle("hidden", !range);
    filterRange.textContent = range ? `${range.from} → ${range.to}` : "";
}
filterMonth.addEventListener("change", () => { syncDateClear(); loadRows(); });
filterPeriod.addEventListener("change", () => { syncDateClear(); loadRows(); });
clearDates.addEventListener("click", () => {
    filterMonth.value = "";
    syncDateClear();
    loadRows();
});

document.addEventListener("DOMContentLoaded", async () => {
    // nav.js already asked auth.php?action=me; this reuses that one request.
    // The fallback keeps the page usable if nav.js ever fails to load.
    try {
        currentUser = (typeof dshCurrentUser === "function")
            ? await dshCurrentUser()
            : (await api.get("auth.php?action=me")).data.data;
    } catch (err) {
        currentUser = null;
    }

    const now = new Date();
    filterMonth.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
    filterPeriod.value = "first";
    if (directDate) directDate.value = now.toISOString().slice(0, 10);
    directCard?.classList.toggle("hidden", !isManager());
    syncDateClear();

    loadEmployees();
    loadPendingCount();
    await loadRows();
});
