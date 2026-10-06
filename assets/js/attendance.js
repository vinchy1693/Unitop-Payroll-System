// =====================================================================
// assets/js/attendance.js
// =====================================================================

let editId = null;
let lastSummary = null; // cache for drill-down

const form        = document.getElementById("att-form");
const submitBtn   = document.getElementById("submit-btn");
const cancelBtn   = document.getElementById("cancel-btn");
const formTitle   = document.getElementById("form-title");
const editCard    = document.getElementById("edit-card");
const rowsEl      = document.getElementById("rows");
const countEl     = document.getElementById("record-count");
const filterDate  = document.getElementById("filter-date");

const summaryForm = document.getElementById("summary-form");
const summaryCard = document.getElementById("summary-card");
const summaryOut  = document.getElementById("summary-output");

const detailModal = document.getElementById("detail-modal");
const detailBody  = document.getElementById("detail-body");

// Time In form elements
const timeinForm     = document.getElementById("timein-form");
const timeinBtn      = document.getElementById("timein-btn");

// Time Out form elements
const timeoutForm    = document.getElementById("timeout-form");
const timeoutBtn     = document.getElementById("timeout-btn");

function todayISO() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

// ---------------------------------------------------------------------
// Employee dropdown — populate all three selects
// ---------------------------------------------------------------------
async function loadEmployeeOptions() {
    const selects = [
        document.getElementById("employee_id"),
        document.getElementById("timein_employee"),
        document.getElementById("timeout_employee"),
    ];

    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        const optionsHtml = `<option value="">-- Select --</option>` +
            data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");

        selects.forEach((sel) => {
            const currentVal = sel.value;
            sel.innerHTML = optionsHtml;
            if (currentVal) sel.value = currentVal;
        });
    } catch (err) {
        showMessage("Could not load employees: " + apiErrorMessage(err), "error");
    }
}

// ---------------------------------------------------------------------
// TIME IN form
// ---------------------------------------------------------------------
timeinForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        employee_id: document.getElementById("timein_employee").value,
        work_date:   document.getElementById("timein_date").value,
        time_in:     document.getElementById("timein_time").value,
    };

    timeinBtn.disabled = true;
    try {
        const { data } = await api.post("attendance.php", payload);
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    } finally {
        timeinBtn.disabled = false;
    }
});

// ---------------------------------------------------------------------
// TIME OUT form
// ---------------------------------------------------------------------
timeoutForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        employee_id: document.getElementById("timeout_employee").value,
        work_date:   document.getElementById("timeout_date").value,
        time_out:    document.getElementById("timeout_time").value,
    };

    timeoutBtn.disabled = true;
    try {
        const { data } = await api.patch("attendance.php", payload);
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    } finally {
        timeoutBtn.disabled = false;
    }
});

// ---------------------------------------------------------------------
// Daily view
// ---------------------------------------------------------------------
async function loadRows() {
    const date = filterDate.value;
    if (!date) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">Pick a date above.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="8">Loading…</td></tr>`;
    try {
        const { data } = await api.get("attendance.php", { params: { date } });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function statusBadge(status, lateMin) {
    if (status === "Clocked In") return `<span class="badge" style="background:#e0f2fe;color:#0369a1;">Clocked In</span>`;
    if (lateMin > 0) return `<span class="badge" style="background:#fef3c7;color:#92400e;">Late</span>`;
    if (status === "Present") return `<span class="badge active">Present</span>`;
    return `<span class="badge inactive">${escapeHtml(status ?? "—")}</span>`;
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;
    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">No attendance for this date.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const timeOutDisplay = r.time_out
            ? escapeHtml(r.time_out)
            : `<span style="color:#b3261e;font-style:italic;font-size:12px;">Pending</span>`;
        const hoursDisplay = r.hours_worked !== null && r.hours_worked !== undefined
            ? Number(r.hours_worked).toFixed(2)
            : "—";

        return `
        <tr>
            <td>${escapeHtml(r.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
            <td>${escapeHtml(r.department_name ?? "—")}</td>
            <td class="col-center">${escapeHtml(r.time_in)}</td>
            <td class="col-center">${timeOutDisplay}</td>
            <td style="text-align:right;">${hoursDisplay}</td>
            <td style="text-align:right;">${r.minutes_late ?? 0}</td>
            <td class="col-center">${statusBadge(r.status, Number(r.minutes_late || 0))}</td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.attendance_id}">Edit</button>
                <button class="btn btn-delete" data-delete="${r.attendance_id}">Delete</button>
            </td>
        </tr>
    `}).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.attendance_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-delete]").forEach((btn) => {
        btn.addEventListener("click", () => deleteRow(btn.dataset.delete));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.attendance_id;
    editCard.classList.remove("hidden");
    document.getElementById("attendance_id").value = row.attendance_id;
    document.getElementById("employee_id").value   = row.employee_id;
    document.getElementById("work_date").value     = row.work_date ?? "";
    document.getElementById("time_in").value       = (row.time_in  ?? "").slice(0, 5);
    document.getElementById("time_out").value      = row.time_out ? (row.time_out).slice(0, 5) : "";
    formTitle.textContent = "Edit Attendance";
    submitBtn.textContent = "Save Changes";
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    editCard.classList.add("hidden");
    document.getElementById("attendance_id").value = "";
    document.getElementById("time_in").value  = "08:00";
    document.getElementById("time_out").value = "17:00";
    document.getElementById("work_date").value = filterDate.value || todayISO();
    formTitle.textContent = "Edit Attendance";
    submitBtn.textContent = "Save Changes";
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        employee_id: document.getElementById("employee_id").value,
        work_date:   document.getElementById("work_date").value,
        time_in:     document.getElementById("time_in").value,
        time_out:    document.getElementById("time_out").value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.attendance_id = editId;
            const { data } = await api.put("attendance.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("attendance.php", payload);
            showMessage(data.message, "success");
        }
        resetForm();
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    } finally {
        submitBtn.disabled = false;
    }
});

async function deleteRow(id) {
    if (!confirm("Delete this attendance record?")) return;
    try {
        const { data } = await api.delete("attendance.php", { params: { id } });
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
}

filterDate.addEventListener("change", loadRows);

// ---------------------------------------------------------------------
// Summary view
// ---------------------------------------------------------------------
summaryForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const from = document.getElementById("summary_from").value;
    const to   = document.getElementById("summary_to").value;
    if (!from || !to) return;

    summaryCard.classList.remove("hidden");
    summaryOut.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("attendance.php", {
            params: { summary: 1, from, to },
        });
        lastSummary = data.data;
        renderSummary(data.data);
    } catch (err) {
        summaryOut.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
});

function renderSummary(payload) {
    const { from, to, expected_days, employees, totals } = payload;

    if (!employees.length) {
        summaryOut.innerHTML = `<div style="padding:20px;color:#888;">No active employees.</div>`;
        return;
    }

    const rows = employees.map((e) => {
        const absentHtml = e.absences > 0
            ? `<span style="color:#b3261e;font-weight:600;">${e.absences}</span>`
            : `<span style="color:#888;">0</span>`;
        const lateHtml = e.days_late > 0
            ? `<span style="color:#92400e;font-weight:600;">${e.days_late}</span>`
            : `<span style="color:#888;">0</span>`;
        const presentHtml = `<span style="color:#1e7d32;font-weight:600;">${e.days_present}</span>`;

        return `
            <tr class="clickable-row" data-emp="${e.employee_id}" style="cursor:pointer;" title="Click to view daily detail">
                <td>${escapeHtml(e.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(e.employee_number)}</span></td>
                <td>${escapeHtml(e.department_name)}</td>
                <td class="col-center">${presentHtml}</td>
                <td class="col-center">${absentHtml}</td>
                <td class="col-center">${lateHtml}</td>
                <td style="text-align:right;">${e.total_late_minutes}</td>
                <td style="text-align:right;">${Number(e.total_hours).toFixed(2)}</td>
                <td class="col-center"><span style="color:#2f5496;font-size:12px;">View →</span></td>
            </tr>
        `;
    }).join("");

    summaryOut.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div>
                    <h2>UNITOP</h2>
                    <div class="report-sub">Attendance Summary</div>
                </div>
                <div class="report-meta">
                    <div><strong>Coverage:</strong> ${escapeHtml(from)} → ${escapeHtml(to)}</div>
                    <div><strong>Working Days:</strong> ${expected_days}</div>
                    <div><strong>Employees:</strong> ${employees.length}</div>
                </div>
            </div>

            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th class="col-center">Days Present</th>
                            <th class="col-center">Absences</th>
                            <th class="col-center">Days Late</th>
                            <th style="text-align:right;">Late Minutes</th>
                            <th style="text-align:right;">Hours Worked</th>
                            <th class="col-center"></th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td colspan="2" style="text-align:right;font-weight:700;">TOTALS</td>
                            <td class="col-center" style="font-weight:700;">${totals.days_present}</td>
                            <td class="col-center" style="font-weight:700;">${totals.absences}</td>
                            <td class="col-center" style="font-weight:700;">${totals.days_late}</td>
                            <td style="text-align:right;font-weight:700;">${totals.total_late_minutes}</td>
                            <td style="text-align:right;font-weight:700;">${Number(totals.total_hours).toFixed(2)}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p style="font-size:12px;color:#666;margin-top:14px;">
                <strong>Click any row</strong> to see the employee's day-by-day attendance.
            </p>
        </div>
    `;

    // Wire up row click
    summaryOut.querySelectorAll(".clickable-row").forEach((row) => {
        row.addEventListener("click", () => openEmployeeDetail(Number(row.dataset.emp)));
    });
}

// ---------------------------------------------------------------------
// Employee detail modal
// ---------------------------------------------------------------------
async function openEmployeeDetail(employeeId) {
    if (!lastSummary) return;
    const { from, to } = lastSummary;

    detailModal.classList.remove("hidden");
    detailBody.innerHTML = `<div style="text-align:center;padding:40px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("attendance.php", {
            params: { employee_detail: 1, employee_id: employeeId, from, to },
        });
        renderDetail(data.data);
    } catch (err) {
        detailBody.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
}

function renderDetail(payload) {
    const { employee, from, to, days, totals } = payload;

    const rows = days.map((d) => {
        let statusCell = "";
        if (d.is_weekend) {
            statusCell = `<span style="color:#9ca3af;font-style:italic;font-size:12px;">Weekend</span>`;
        } else if (d.has_record) {
            if (d.status === "Clocked In") {
                statusCell = `<span class="badge" style="background:#e0f2fe;color:#0369a1;">Clocked In</span>`;
            } else if ((d.minutes_late || 0) > 0) {
                statusCell = `<span class="badge" style="background:#fef3c7;color:#92400e;">Late ${d.minutes_late}m</span>`;
            } else {
                statusCell = `<span class="badge active">Present</span>`;
            }
        } else {
            statusCell = `<span class="badge inactive">Absent</span>`;
        }

        const rowStyle = d.is_weekend ? 'style="opacity:0.5;"' : "";

        return `
            <tr ${rowStyle}>
                <td>${escapeHtml(d.date)}</td>
                <td class="col-center" style="font-size:12px;color:#888;">${escapeHtml(d.day_label)}</td>
                <td class="col-center">${d.time_in ? escapeHtml(d.time_in) : "—"}</td>
                <td class="col-center">${d.time_out ? escapeHtml(d.time_out) : "—"}</td>
                <td style="text-align:right;">${d.hours_worked !== null ? Number(d.hours_worked).toFixed(2) : "—"}</td>
                <td style="text-align:right;">${d.minutes_late !== null ? d.minutes_late : "—"}</td>
                <td class="col-center">${statusCell}</td>
            </tr>
        `;
    }).join("");

    detailBody.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div>
                    <h2>${escapeHtml(employee.employee_name)}</h2>
                    <div class="report-sub">
                        ${escapeHtml(employee.employee_number)}
                        ${employee.department_name ? " · " + escapeHtml(employee.department_name) : ""}
                        ${employee.position_title ? " · " + escapeHtml(employee.position_title) : ""}
                    </div>
                </div>
                <div class="report-meta">
                    <div><strong>Coverage:</strong> ${escapeHtml(from)} → ${escapeHtml(to)}</div>
                    <div><strong>Working Days:</strong> ${totals.expected_days}</div>
                </div>
            </div>

            <div class="ledger-stats">
                <div class="ledger-stat"><span>Present</span><strong style="color:#1e7d32;">${totals.present}</strong></div>
                <div class="ledger-stat"><span>Absent</span><strong style="color:#b3261e;">${totals.absent}</strong></div>
                <div class="ledger-stat"><span>Late Days</span><strong style="color:#92400e;">${totals.late}</strong></div>
                <div class="ledger-stat"><span>Late Minutes</span><strong>${totals.late_minutes}</strong></div>
                <div class="ledger-stat"><span>Total Hours</span><strong>${Number(totals.hours).toFixed(2)}</strong></div>
            </div>

            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="col-center">Day</th>
                            <th class="col-center">Time In</th>
                            <th class="col-center">Time Out</th>
                            <th style="text-align:right;">Hours</th>
                            <th style="text-align:right;">Late</th>
                            <th class="col-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>
    `;
}

document.getElementById("detail-close").addEventListener("click", () => {
    detailModal.classList.add("hidden");
});
document.getElementById("detail-done").addEventListener("click", () => {
    detailModal.classList.add("hidden");
});
document.getElementById("detail-print").addEventListener("click", () => {
    window.print();
});
detailModal.addEventListener("click", (e) => {
    if (e.target === detailModal) detailModal.classList.add("hidden");
});
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !detailModal.classList.contains("hidden")) {
        detailModal.classList.add("hidden");
    }
});

// ---------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------
document.addEventListener("DOMContentLoaded", () => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    document.getElementById("summary_from").value = `${y}-${m}-01`;
    const last = new Date(y, now.getMonth() + 1, 0).getDate();
    document.getElementById("summary_to").value = `${y}-${m}-${String(last).padStart(2, "0")}`;

    const today = todayISO();
    filterDate.value = today;
    document.getElementById("work_date").value = today;
    document.getElementById("timein_date").value = today;
    document.getElementById("timeout_date").value = today;

    loadEmployeeOptions();
    loadRows();
});