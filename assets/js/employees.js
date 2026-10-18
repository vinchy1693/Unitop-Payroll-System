// =====================================================================
// assets/js/employees.js
// =====================================================================

let showAll = false;
let editId  = null;

const FIELDS = ["employee_number", "first_name", "last_name", "birth_date", "civil_status",
                "position_id", "department_id", "date_hired", "employment_status",
                "basic_salary",
                "tin_number", "sss_number", "philhealth_number", "pagibig_number",
                // Payment details: read by the Bank file export. They are only
                // writable by the maker and never exposed to the employee portal.
                "bank_name", "bank_account_name", "bank_account_number",
                "mobile_number", "email", "address",
                "emergency_contact_name", "emergency_contact_number"];

const form       = document.getElementById("emp-form");
const submitBtn  = document.getElementById("submit-btn");
const cancelBtn  = document.getElementById("cancel-btn");
const formTitle  = document.getElementById("form-title");
const rowsEl     = document.getElementById("rows");
const countEl    = document.getElementById("record-count");
const toggleLink = document.getElementById("toggle-filter");

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function loadDropdowns() {
    try {
        const [posRes, deptRes] = await Promise.all([
            api.get("positions.php", { params: { simple: 1 } }),
            api.get("departments.php", { params: { simple: 1 } }),
        ]);
        const posSel = document.getElementById("position_id");
        const deptSel = document.getElementById("department_id");
        const posVal = posSel.value, deptVal = deptSel.value;
        posSel.innerHTML = `<option value="">-- Select --</option>` +
            posRes.data.data.map((p) => `<option value="${p.id}">${escapeHtml(p.label)}</option>`).join("");
        deptSel.innerHTML = `<option value="">-- Select --</option>` +
            deptRes.data.data.map((d) => `<option value="${d.id}">${escapeHtml(d.label)}</option>`).join("");
        if (posVal) posSel.value = posVal;
        if (deptVal) deptSel.value = deptVal;
    } catch (err) {
        showToast("Could not load position/department lists: " + apiErrorMessage(err), "error");
    }
}

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="7">Loading…</td></tr>`;
    try {
        const { data } = await api.get("employees.php", { params: showAll ? { all: 1 } : {} });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">No records found.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr class="${r.is_active ? "" : "inactive-row"}">
            <td>${escapeHtml(r.employee_number)}</td>
            <td>${escapeHtml(r.last_name + ", " + r.first_name)}</td>
            <td>${escapeHtml(r.position_title ?? "—")}</td>
            <td>${escapeHtml(r.department_name ?? "—")}</td>
            <td style="text-align:right;">${formatMoney(r.basic_salary)}</td>
            <td class="col-center"><span class="badge ${r.is_active ? "active" : "inactive"}">${r.is_active ? "Active" : "Inactive"}</span></td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.employee_id}">Edit</button>
                <button class="btn ${r.is_active ? "btn-delete" : "btn-restore"}" data-toggle="${r.employee_id}" data-active="${r.is_active ? 0 : 1}">
                    ${r.is_active ? "Deactivate" : "Restore"}
                </button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.employee_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-toggle]").forEach((btn) => {
        btn.addEventListener("click", () => toggleStatus(btn.dataset.toggle, Number(btn.dataset.active)));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.employee_id;
    document.getElementById("employee_id").value = row.employee_id;
    FIELDS.forEach((f) => {
        const el = document.getElementById(f);
        if (el) el.value = row[f] ?? "";
    });
    formTitle.textContent = "Edit Employee";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("employee_id").value = "";
    formTitle.textContent = "Add New Employee";
    submitBtn.textContent = "Add Employee";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {};
    FIELDS.forEach((f) => { payload[f] = document.getElementById(f).value.trim(); });

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.employee_id = editId;
            const { data } = await api.put("employees.php", payload);
            showToast(data.message, "success");
        } else {
            const { data } = await api.post("employees.php", payload);
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

async function toggleStatus(id, newStatus) {
    const verb = newStatus ? "Restore" : "Deactivate";
    if (!await confirmDialog(`${verb} this employee record?`)) return;
    try {
        const { data } = await api.patch("employees.php", { employee_id: id, is_active: newStatus });
        showToast(data.message, "success");
        await loadRows();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

toggleLink.addEventListener("click", (e) => {
    e.preventDefault();
    showAll = !showAll;
    toggleLink.textContent = showAll ? "Show active only" : "Show all (incl. deactivated)";
    loadRows();
});

document.addEventListener("DOMContentLoaded", () => {
    loadDropdowns();
    loadRows();
});