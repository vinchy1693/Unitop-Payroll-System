// =====================================================================
// assets/js/departments.js
// =====================================================================

let showAll = false;
let editId = null;

const form = document.getElementById("dept-form");
const submitBtn = document.getElementById("submit-btn");
const cancelBtn = document.getElementById("cancel-btn");
const formTitle = document.getElementById("form-title");
const rowsEl = document.getElementById("rows");
const countEl = document.getElementById("record-count");
const toggleLink = document.getElementById("toggle-filter");

// ---- Load managers dropdown (active employees) ----
async function loadManagerOptions() {
    const select = document.getElementById("manager_employee_id");
    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        const options = data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        select.innerHTML = `<option value="">-- None --</option>${options}`;
    } catch (err) {
        showMessage("Could not load managers list: " + apiErrorMessage(err), "error");
    }
}

// ---- Load + render the department list ----
async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="5">Loading…</td></tr>`;
    try {
        const { data } = await api.get("departments.php", { params: showAll ? { all: 1 } : {} });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="5">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="5">No records found.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr class="${r.is_active ? "" : "inactive-row"}">
            <td>#${r.department_id}</td>
            <td>${escapeHtml(r.department_name)}</td>
            <td>${r.manager_name ? escapeHtml(r.manager_name) : "—"}</td>
            <td><span class="badge ${r.is_active ? "active" : "inactive"}">${r.is_active ? "Active" : "Inactive"}</span></td>
            <td>
                <button class="btn btn-edit" data-edit="${r.department_id}">Edit</button>
                <button class="btn ${r.is_active ? "btn-delete" : "btn-restore"}" data-toggle="${r.department_id}" data-active="${r.is_active ? 0 : 1}">
                    ${r.is_active ? "Deactivate" : "Restore"}
                </button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.department_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-toggle]").forEach((btn) => {
        btn.addEventListener("click", () => toggleStatus(btn.dataset.toggle, Number(btn.dataset.active)));
    });
}

// ---- Edit / cancel ----
function startEdit(row) {
    if (!row) return;
    editId = row.department_id;
    document.getElementById("department_id").value = row.department_id;
    document.getElementById("department_name").value = row.department_name;
    document.getElementById("manager_employee_id").value = row.manager_employee_id ?? "";
    formTitle.textContent = "Edit Department";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("department_id").value = "";
    formTitle.textContent = "Add New Department";
    submitBtn.textContent = "Add Department";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

// ---- Create / update ----
form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        department_name: document.getElementById("department_name").value.trim(),
        manager_employee_id: document.getElementById("manager_employee_id").value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.department_id = editId;
            const { data } = await api.put("departments.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("departments.php", payload);
            showMessage(data.message, "success");
        }
        resetForm();
        await Promise.all([loadRows(), loadManagerOptions()]);
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    } finally {
        submitBtn.disabled = false;
    }
});

// ---- Deactivate / restore ----
async function toggleStatus(id, newStatus) {
    const verb = newStatus ? "Restore" : "Deactivate";
    if (!confirm(`${verb} this department?`)) return;
    try {
        const { data } = await api.patch("departments.php", { department_id: id, is_active: newStatus });
        showMessage(data.message, "success");
        await Promise.all([loadRows(), loadManagerOptions()]);
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
}

// ---- Show all / active only toggle ----
toggleLink.addEventListener("click", (e) => {
    e.preventDefault();
    showAll = !showAll;
    toggleLink.textContent = showAll ? "Show active only" : "Show all (incl. deactivated)";
    loadRows();
});

// ---- Init ----
document.addEventListener("DOMContentLoaded", () => {
    loadManagerOptions();
    loadRows();
});
