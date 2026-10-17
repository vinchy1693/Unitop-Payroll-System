// =====================================================================
// assets/js/overtime.js
// Admin overtime approval queue.
//
// This page no longer creates overtime by hand. It lists the requests
// employees file from the kiosk and lets an admin approve or reject the
// Pending ones. Hours are filled in by the employee's overtime clock-out.
// =====================================================================

const rowsEl        = document.getElementById("rows");
const countEl       = document.getElementById("record-count");
const pendingEl     = document.getElementById("pending-count");
const filterStatus  = document.getElementById("filter-status");
const filterDate    = document.getElementById("filter-date");
const clearDateBtn  = document.getElementById("clear-date");

let currentRows = [];

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

function statusBadge(status) {
    const styles = {
        Pending:   "background:var(--yellow-lite);color:var(--yellow-dark)",
        Approved:  "background:var(--blue-lite);color:var(--blue)",
        Rejected:  "background:var(--red-lite);color:var(--red)",
        Completed: "background:var(--green-lite);color:var(--green)",
    };
    return `<span class="badge" style="${styles[status] || ""}">${escapeHtml(status)}</span>`;
}

function hoursCell(row) {
    if (row.hours !== null && row.hours !== undefined) {
        return Number(row.hours).toFixed(2);
    }
    if (row.time_in && !row.time_out) {
        return `${Number(row.pending_hours || 0).toFixed(2)}<br><span style="color:var(--muted);font-size:11px;">running</span>`;
    }
    return "&mdash;";
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        const label = filterStatus.value ? filterStatus.value.toLowerCase() : "";
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">No ${escapeHtml(label)} overtime requests${filterDate.value ? " for this date" : ""}.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const canDecide = r.status === "Pending";
        const decidedBy = r.approved_by_name
            ? `<div style="font-size:11px;color:var(--muted);">by ${escapeHtml(r.approved_by_name)}</div>`
            : "";
        const actions = canDecide
            ? `<button class="btn btn-restore" data-approve="${r.ot_id}">Approve</button>
               <button class="btn btn-delete" data-reject="${r.ot_id}">Reject</button>`
            : `<span style="color:var(--muted);font-size:12px;">&mdash;</span>`;

        return `
        <tr>
            <td>${escapeHtml(r.employee_name)}<br><span style="color:var(--muted);font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
            <td class="col-center">${escapeHtml(r.ot_date)}</td>
            <td class="col-center">${escapeHtml(r.ot_type)}<br><span style="color:var(--muted);font-size:11px;">${Number(r.rate_multiplier).toFixed(2)}x</span></td>
            <td class="col-center">${formatDateTime(r.requested_at)}</td>
            <td class="col-center">${formatTime(r.time_in)}</td>
            <td class="col-center">${formatTime(r.time_out)}</td>
            <td style="text-align:right;">${hoursCell(r)}</td>
            <td class="col-center">${statusBadge(r.status)}${decidedBy}</td>
            <td class="col-center">${actions}</td>
        </tr>`;
    }).join("");

    rowsEl.querySelectorAll("[data-approve]").forEach((btn) => {
        btn.addEventListener("click", () => decide(btn.dataset.approve, "approve"));
    });
    rowsEl.querySelectorAll("[data-reject]").forEach((btn) => {
        btn.addEventListener("click", () => decide(btn.dataset.reject, "reject"));
    });
}

async function loadRows() {
    const params = {};
    if (filterStatus.value) params.status = filterStatus.value;
    if (filterDate.value) params.date = filterDate.value;
    if (!filterStatus.value && !filterDate.value) params.all = 1;

    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="9">Loading...</td></tr>`;
    try {
        const { data } = await api.get("overtime_records.php", { params });
        currentRows = data.data || [];
        renderRows(currentRows);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

async function loadPendingCount() {
    try {
        const { data } = await api.get("overtime_records.php", { params: { status: "Pending" } });
        pendingEl.textContent = String((data.data || []).length);
    } catch (err) {
        pendingEl.textContent = "?";
    }
}

async function decide(id, action) {
    const row = currentRows.find((r) => String(r.ot_id) === String(id));
    if (!row) return;

    const verb = action === "approve" ? "Approve" : "Reject";
    const detail = action === "approve"
        ? "The employee will then be able to start and end the overtime with their QR code."
        : "It stays visible as Rejected and is excluded from payroll. The employee can file a new request.";

    if (!await confirmDialog(
        `${verb} ${row.employee_name}'s overtime request for ${row.ot_date}?\n\n${detail}`,
        { title: `${verb} Overtime Request`, icon: "question", confirmText: verb }
    )) return;

    try {
        const { data } = await api.patch("overtime_records.php", { ot_id: row.ot_id, action });
        showToast(data.message, "success");
        await Promise.all([loadRows(), loadPendingCount()]);
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

filterStatus.addEventListener("change", loadRows);
filterDate.addEventListener("change", () => {
    clearDateBtn.classList.toggle("hidden", !filterDate.value);
    loadRows();
});
clearDateBtn.addEventListener("click", () => {
    filterDate.value = "";
    clearDateBtn.classList.add("hidden");
    loadRows();
});

document.addEventListener("DOMContentLoaded", () => {
    loadPendingCount();
    loadRows();
});
