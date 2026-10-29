// =====================================================================
// assets/js/overtime.js
// Admin overtime page.
//
// A Manager files a request for a team member; the Supervisor approves or
// rejects it. Employees cannot file overtime. Hours are filled in by the
// employee's overtime clock-out at the kiosk.
// =====================================================================

const rowsEl        = document.getElementById("rows");
const countEl       = document.getElementById("record-count");
const pendingEl     = document.getElementById("pending-count");
const filterStatus  = document.getElementById("filter-status");
const filterDate    = document.getElementById("filter-date");
const clearDateBtn  = document.getElementById("clear-date");
const fileCard      = document.getElementById("file-ot-card");
const fileForm      = document.getElementById("file-ot-form");
const fileEmployee  = document.getElementById("file-ot-employee");
const fileDate      = document.getElementById("file-ot-date");
const fileStart     = document.getElementById("file-ot-start");
const fileEnd       = document.getElementById("file-ot-end");
const fileReason    = document.getElementById("file-ot-reason");
const fileSubmit    = document.getElementById("file-ot-submit");

let currentRows = [];
let currentRole = "";
let currentUsername = "";

async function loadCurrentRole() {
    try {
        const { data } = await api.get("auth.php?action=me");
        currentRole = data.data?.role || "";
        currentUsername = data.data?.username || "";
    } catch (_) {
        currentRole = "";
        currentUsername = "";
    }
}

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
        const canDecide = r.status === "Pending"
            && currentRole === "Manager"
            && r.requested_by_name !== currentUsername;
        const decidedBy = r.approved_by_name
            ? `<div style="font-size:11px;color:var(--muted);">by ${escapeHtml(r.approved_by_name)}</div>`
            : "";
        const filedBy = r.requested_by_name
            ? `<div style="font-size:11px;color:var(--muted);">filed by ${escapeHtml(r.requested_by_name)}</div>`
            : "";
        const actions = canDecide
            ? `<button class="btn btn-restore" data-approve="${r.ot_id}">Approve</button>
               <button class="btn btn-delete" data-reject="${r.ot_id}">Reject</button>`
            : `<span style="color:var(--muted);font-size:12px;">&mdash;</span>`;

        return `
        <tr>
            <td>${escapeHtml(r.employee_name)}<br><span style="color:var(--muted);font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
            <td class="col-center">${escapeHtml(r.ot_date)}
                ${r.reason ? `<div style="max-width:240px;white-space:normal;text-align:left;margin-top:4px;font-size:11px;color:var(--muted);"><strong>Reason:</strong> ${escapeHtml(r.reason)}</div>` : ""}
            </td>
            <td class="col-center" style="white-space:nowrap;">${escapeHtml(r.day_type || r.ot_type)}<br><span style="color:var(--muted);font-size:11px;">${Number(r.rate_multiplier).toFixed(2)}x</span></td>
            <td class="col-center" style="white-space:nowrap;">${formatTime(r.requested_start)} &ndash; ${formatTime(r.requested_end)}
                <div style="margin-top:3px;font-size:11px;color:var(--muted);">filed ${formatDateTime(r.requested_at)}</div>
                ${filedBy}
            </td>
            <td class="col-center">${formatTime(r.time_in)}</td>
            <td class="col-center">${formatTime(r.time_out)}</td>
            <td style="text-align:right;">${hoursCell(r)}</td>
            <td class="col-center">${statusBadge(r.status)}${decidedBy}</td>
            <td class="col-center" style="white-space:nowrap;">${actions}</td>
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
        : "It stays visible as Rejected and is excluded from payroll. The Manager can file a new request.";

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

function ymd(date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0"),
    ].join("-");
}

async function initFileForm() {
    if (!fileCard) return;
    if (currentRole !== "Manager") {
        fileCard.classList.add("hidden");
        return;
    }

    fileCard.classList.remove("hidden");
    fileDate.value = ymd(new Date());

    try {
        const { data } = await api.get("employees_lookup.php");
        const rows = Array.isArray(data.data) ? data.data : [];
        if (!rows.length) {
            fileEmployee.innerHTML = `<option value="">No team members assigned — ask Payroll to assign your team</option>`;
            fileSubmit.disabled = true;
            return;
        }
        fileEmployee.innerHTML = `<option value="">Select a team member</option>` +
            rows.map((e) => `<option value="${e.employee_id}">${escapeHtml(e.label)}</option>`).join("");
        fileSubmit.disabled = false;
    } catch (err) {
        fileEmployee.innerHTML = `<option value="">Failed to load team: ${escapeHtml(apiErrorMessage(err))}</option>`;
        fileSubmit.disabled = true;
    }
}

if (fileForm) {
    fileForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        const employeeId = Number(fileEmployee.value || 0);
        const otDate     = fileDate.value;
        const start      = fileStart.value;
        const end        = fileEnd.value;
        const reason     = (fileReason.value || "").trim();

        if (!employeeId || !otDate || !start || !end) {
            await alertDialog("Pick the team member, overtime date, start and end.", "warning");
            return;
        }
        if (end <= start) {
            await alertDialog("The requested end must be after the requested start.", "warning");
            return;
        }
        if (reason.length < 10) {
            await alertDialog("Please describe the reason in at least 10 characters.", "warning");
            return;
        }

        const employeeLabel = fileEmployee.options[fileEmployee.selectedIndex]?.text || "this employee";
        if (!await confirmDialog(
            `File overtime for ${employeeLabel} on ${otDate}, ${start} to ${end}?`,
            { title: "File Overtime Request", icon: "question", confirmText: "File Request" }
        )) return;

        fileSubmit.disabled = true;
        try {
            const { data } = await api.post("overtime_records.php", {
                employee_id: employeeId,
                ot_date: otDate,
                requested_start: start,
                requested_end: end,
                reason: reason,
            });
            showToast(data.message, "success");
            fileForm.reset();
            fileDate.value = ymd(new Date());
            fileStart.value = "18:00";
            fileEnd.value = "20:00";
            await Promise.all([loadRows(), loadPendingCount()]);
        } catch (err) {
            showToast(apiErrorMessage(err), "error");
        } finally {
            fileSubmit.disabled = false;
        }
    });
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

document.addEventListener("DOMContentLoaded", async () => {
    await loadCurrentRole();
    await initFileForm();
    loadPendingCount();
    loadRows();
});
