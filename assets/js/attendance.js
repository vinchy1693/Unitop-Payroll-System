// =====================================================================
// assets/js/attendance.js
// =====================================================================

let editId = null;

const form        = document.getElementById("att-form");
const submitBtn   = document.getElementById("submit-btn");
const cancelBtn   = document.getElementById("cancel-btn");
const formTitle   = document.getElementById("form-title");
const rowsEl      = document.getElementById("rows");
const countEl     = document.getElementById("record-count");
const filterDate  = document.getElementById("filter-date");

// Default filter to today
function todayISO() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

async function loadEmployeeOptions() {
    const select = document.getElementById("employee_id");
    const currentVal = select.value;
    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        const options = data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        select.innerHTML = `<option value="">-- Select --</option>${options}`;
        if (currentVal) select.value = currentVal;
    } catch (err) {
        showMessage("Could not load employees list: " + apiErrorMessage(err), "error");
    }
}

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

    rowsEl.innerHTML = rows.map((r) => `
        <tr>
            <td>${escapeHtml(r.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
            <td>${escapeHtml(r.department_name ?? "—")}</td>
            <td class="col-center">${escapeHtml(r.time_in)}</td>
            <td class="col-center">${escapeHtml(r.time_out)}</td>
            <td style="text-align:right;">${Number(r.hours_worked).toFixed(2)}</td>
            <td style="text-align:right;">${r.minutes_late ?? 0}</td>
            <td class="col-center">${statusBadge(r.status, Number(r.minutes_late || 0))}</td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.attendance_id}">Edit</button>
                <button class="btn btn-delete" data-delete="${r.attendance_id}">Delete</button>
            </td>
        </tr>
    `).join("");

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
    document.getElementById("attendance_id").value = row.attendance_id;
    document.getElementById("employee_id").value   = row.employee_id;
    document.getElementById("work_date").value     = row.work_date ?? "";
    document.getElementById("time_in").value       = (row.time_in  ?? "").slice(0, 5);
    document.getElementById("time_out").value      = (row.time_out ?? "").slice(0, 5);
    formTitle.textContent = "Edit Attendance";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("attendance_id").value = "";
    document.getElementById("time_in").value  = "08:00";
    document.getElementById("time_out").value = "17:00";
    document.getElementById("work_date").value = filterDate.value || todayISO();
    formTitle.textContent = "Log Attendance";
    submitBtn.textContent = "Log Attendance";
    cancelBtn.classList.add("hidden");
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

document.addEventListener("DOMContentLoaded", () => {
    filterDate.value = todayISO();
    document.getElementById("work_date").value = todayISO();
    loadEmployeeOptions();
    loadRows();
});