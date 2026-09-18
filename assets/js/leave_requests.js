// =====================================================================
// assets/js/leave_requests.js
// =====================================================================

let editId = null;

const form      = document.getElementById("lr-form");
const submitBtn = document.getElementById("submit-btn");
const cancelBtn = document.getElementById("cancel-btn");
const formTitle = document.getElementById("form-title");
const rowsEl    = document.getElementById("rows");
const countEl   = document.getElementById("record-count");
const filterEl  = document.getElementById("filter-status");

async function loadEmployees() {
    const select = document.getElementById("employee_id");
    const cur = select.value;
    const { data } = await api.get("employees.php", { params: { simple: 1 } });
    select.innerHTML = `<option value="">-- Select --</option>` +
        data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
    if (cur) select.value = cur;
}

async function loadLeaveTypes() {
    const select = document.getElementById("leave_type_id");
    const cur = select.value;
    const { data } = await api.get("leave_types.php");
    select.innerHTML = `<option value="">-- Select --</option>` +
        data.data.map((t) => `<option value="${t.leave_type_id}">${escapeHtml(t.name)}</option>`).join("");
    if (cur) select.value = cur;
}

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="7">Loading…</td></tr>`;
    const status = filterEl.value;
    const params = status ? { status } : {};
    try {
        const { data } = await api.get("leave_requests.php", { params });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">Failed: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function countBusinessDays(start, end) {
    if (!start || !end || start > end) return 0;
    const s = new Date(start);
    const e = new Date(end);
    let days = 0;
    for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
        const dow = d.getDay();
        if (dow !== 0 && dow !== 6) days++;
    }
    return days;
}

function statusBadge(status) {
    const map = {
        "Pending":  { cls: "",         bg: "#fef3c7", color: "#92400e" },
        "Approved": { cls: "active",   bg: "",        color: "" },
        "Rejected": { cls: "inactive", bg: "",        color: "" },
    };
    const s = map[status] || { cls: "inactive" };
    const style = s.bg ? ` style="background:${s.bg};color:${s.color};"` : "";
    return `<span class="badge ${s.cls}"${style}>${escapeHtml(status)}</span>`;
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;
    if (!rows.length) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">No leave requests.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const days = countBusinessDays(r.start_date, r.end_date);
        let actions = "";

        if (r.status === "Pending") {
            actions = `
                <button class="btn btn-restore" data-approve="${r.leave_id}">Approve</button>
                <button class="btn btn-delete"  data-reject="${r.leave_id}">Reject</button>
                <button class="btn btn-edit"    data-edit="${r.leave_id}">Edit</button>
            `;
        } else {
            actions = `<span style="color:#888;font-size:12px;">Finalized</span>`;
        }

        return `
        <tr>
            <td>${escapeHtml(r.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
            <td>${escapeHtml(r.leave_type_name)}</td>
            <td>${escapeHtml(r.start_date)}</td>
            <td>${escapeHtml(r.end_date)}</td>
            <td class="col-center">${days}</td>
            <td class="col-center">${statusBadge(r.status)}</td>
            <td class="col-center">${actions}</td>
        </tr>`;
    }).join("");

    rowsEl.querySelectorAll("[data-approve]").forEach((btn) => {
        btn.addEventListener("click", () => changeStatus(btn.dataset.approve, "Approved"));
    });
    rowsEl.querySelectorAll("[data-reject]").forEach((btn) => {
        btn.addEventListener("click", () => changeStatus(btn.dataset.reject, "Rejected"));
    });
    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.leave_id == btn.dataset.edit)));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.leave_id;
    document.getElementById("leave_id").value      = row.leave_id;
    document.getElementById("employee_id").value   = row.employee_id;
    document.getElementById("leave_type_id").value = row.leave_type_id;
    document.getElementById("start_date").value    = row.start_date ?? "";
    document.getElementById("end_date").value      = row.end_date ?? "";
    formTitle.textContent = "Edit Leave Request";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("leave_id").value = "";
    formTitle.textContent = "File Leave Request";
    submitBtn.textContent = "File Request";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        employee_id:   document.getElementById("employee_id").value,
        leave_type_id: document.getElementById("leave_type_id").value,
        start_date:    document.getElementById("start_date").value,
        end_date:      document.getElementById("end_date").value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.leave_id = editId;
            const { data } = await api.put("leave_requests.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("leave_requests.php", payload);
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

async function changeStatus(id, status) {
    const verb = status === "Approved" ? "Approve" : "Reject";
    if (!confirm(`${verb} this leave request?`)) return;
    try {
        const { data } = await api.patch("leave_requests.php", { leave_id: Number(id), status });
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
}

filterEl.addEventListener("change", loadRows);

document.addEventListener("DOMContentLoaded", () => {
    loadEmployees();
    loadLeaveTypes();
    loadRows();
});