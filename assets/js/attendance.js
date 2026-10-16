// =====================================================================
// assets/js/attendance.js
// =====================================================================

let editId = null;
let lastSummary = null;
let scanner = null;
let scannerRunning = false;
let lastScannedCode = null;
let lastScannedAt = 0;

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

const startBtn    = document.getElementById("start-btn");
const stopBtn     = document.getElementById("stop-btn");
const cameraSel   = document.getElementById("camera-select");
const statusBox   = document.getElementById("scanner-status");
const statusIcon  = document.getElementById("scanner-icon");
const statusTitle = document.getElementById("scanner-title");
const statusDetail= document.getElementById("scanner-detail");

function todayISO() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

// =====================================================================
// QR SCANNER
// =====================================================================
function resetStatus() {
    statusBox.classList.add("hidden");
    statusIcon.className = "scanner-icon";
    statusIcon.textContent = "";
    statusTitle.textContent = "Ready";
    statusDetail.textContent = "";
}

function showStatus(kind, title, detail) {
    statusBox.classList.remove("hidden");
    statusIcon.className = "scanner-icon " + kind;
    statusIcon.textContent = kind === "success" ? "✓" : kind === "error" ? "✕" : "ℹ";
    statusTitle.textContent = title;
    statusDetail.textContent = detail;
}

async function startScanner(preferredDeviceId = null) {
    if (scannerRunning) return;

    try {
        const cameras = await Html5Qrcode.getCameras();
        if (!cameras || cameras.length === 0) {
            showToast("No camera detected. Connect a webcam and try again.", "error");
            return;
        }

        // Populate the camera dropdown
        cameraSel.innerHTML = cameras
            .map((c) => `<option value="${c.id}">${escapeHtml(c.label || c.id)}</option>`)
            .join("");
        cameraSel.classList.remove("hidden");

        // Pick which camera to use
        let deviceId = preferredDeviceId;

        if (!deviceId) {
            const preferred = cameras.find((c) => {
                const label = (c.label || "").toLowerCase();
                return label.includes("integrated") ||
                       label.includes("webcam") ||
                       label.includes("usb");
            });
            const nonVirtual = cameras.find((c) => {
                const label = (c.label || "").toLowerCase();
                return !label.includes("obs") &&
                       !label.includes("virtual") &&
                       !label.includes("lvs");
            });
            deviceId = (preferred || nonVirtual || cameras[0]).id;
        }

        cameraSel.value = deviceId;

        scanner = new Html5Qrcode("reader");
        await scanner.start(
            { deviceId: { exact: deviceId } },
            { fps: 10 },
            onScanSuccess,
            () => {}
        );

        scannerRunning = true;
        startBtn.classList.add("hidden");
        stopBtn.classList.remove("hidden");
        resetStatus();
        showStatus("info", "Scanning…", "Point an employee QR code at the camera.");

    } catch (err) {
        console.error("Camera start error:", err);
        showToast("Could not start camera: " + (err.message || err), "error");
        startBtn.classList.remove("hidden");
        stopBtn.classList.add("hidden");
        cameraSel.classList.add("hidden");
    }
}

async function stopScanner() {
    if (!scannerRunning || !scanner) return;
    try {
        await scanner.stop();
        await scanner.clear();
    } catch (err) { /* ignore */ }
    scannerRunning = false;
    scanner = null;
    startBtn.classList.remove("hidden");
    stopBtn.classList.add("hidden");
    cameraSel.classList.add("hidden");
    resetStatus();
}

function shouldIgnoreScan(code) {
    const now = Date.now();
    if (code === lastScannedCode && (now - lastScannedAt) < 3000) return true;
    lastScannedCode = code;
    lastScannedAt = now;
    return false;
}

async function onScanSuccess(decodedText) {
    if (shouldIgnoreScan(decodedText)) return;

    try { scanner.pause(true); } catch (e) {}

    try {
        const { data } = await api.post("scan_attendance.php", { code_value: decodedText });
        const d = data.data;

        if (d.action === "in") {
            const lateMsg = d.late_minutes > 0 ? ` • Late by ${d.late_minutes} min` : "";
            showStatus("success",
                `Welcome, ${d.employee_name}!`,
                `Clocked IN at ${d.time}${lateMsg}`
            );
        } else if (d.action === "out") {
            showStatus("success",
                `Goodbye, ${d.employee_name}!`,
                `Clocked OUT at ${d.time} • ${d.hours_worked} hrs worked`
            );
        }

        await loadRows();

        setTimeout(() => {
            resetStatus();
            try { scanner.resume(); } catch (e) {}
        }, 3000);

    } catch (err) {
        const msg = apiErrorMessage(err);
        showStatus("error", "Scan Rejected", msg);

        setTimeout(() => {
            resetStatus();
            try { scanner.resume(); } catch (e) {}
        }, 3000);
    }
}

// ---- Event listeners (only declared ONCE at the top level) ----
startBtn.addEventListener("click", () => startScanner());
stopBtn.addEventListener("click", () => stopScanner());

cameraSel.addEventListener("change", async () => {
    const newDeviceId = cameraSel.value;
    if (!scannerRunning) return;
    await stopScanner();
    await new Promise((resolve) => setTimeout(resolve, 700));
    await startScanner(newDeviceId);
});

// =====================================================================
// Employee dropdown (edit form)
// =====================================================================
async function loadEmployeeOptions() {
    const select = document.getElementById("employee_id");
    const cur = select.value;
    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        select.innerHTML = `<option value="">-- Select --</option>` +
            data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        if (cur) select.value = cur;
    } catch (err) {
        showToast("Could not load employees: " + apiErrorMessage(err), "error");
    }
}

// =====================================================================
// Daily view
// =====================================================================
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
        </tr>`;
    }).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.attendance_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-delete]").forEach((btn) => {
        btn.addEventListener("click", () => deleteRow(btn.dataset.delete));
    });
}

// =====================================================================
// Edit form
// =====================================================================
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
            showToast(data.message, "success");
        }
        resetForm();
        await loadRows();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    } finally {
        submitBtn.disabled = false;
    }
});

// =====================================================================
// Delete
// =====================================================================
async function deleteRow(id) {
    if (!await confirmDialog(
        "Delete this attendance record? This cannot be undone.",
        { title: "Confirm Delete", icon: "warning", confirmText: "Delete" }
    )) return;

    try {
        const { data } = await api.delete("attendance.php", { params: { id } });
        showToast(data.message, "success");
        await loadRows();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

filterDate.addEventListener("change", loadRows);

// =====================================================================
// Summary view
// =====================================================================
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

    summaryOut.querySelectorAll(".clickable-row").forEach((row) => {
        row.addEventListener("click", () => openEmployeeDetail(Number(row.dataset.emp)));
    });
}

// =====================================================================
// Employee detail modal
// =====================================================================
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

document.getElementById("detail-close").addEventListener("click", () => detailModal.classList.add("hidden"));
document.getElementById("detail-done").addEventListener("click", () => detailModal.classList.add("hidden"));
document.getElementById("detail-print").addEventListener("click", () => window.print());
detailModal.addEventListener("click", (e) => { if (e.target === detailModal) detailModal.classList.add("hidden"); });
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !detailModal.classList.contains("hidden")) {
        detailModal.classList.add("hidden");
    }
});

// =====================================================================
// Init
// =====================================================================
document.addEventListener("DOMContentLoaded", () => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    document.getElementById("summary_from").value = `${y}-${m}-01`;
    const last = new Date(y, now.getMonth() + 1, 0).getDate();
    document.getElementById("summary_to").value = `${y}-${m}-${String(last).padStart(2, "0")}`;

    filterDate.value = todayISO();
    document.getElementById("work_date").value = todayISO();

    loadEmployeeOptions();
    loadRows();
});