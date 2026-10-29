// =====================================================================
// assets/js/users.js
// =====================================================================

let showAll = false;
let editId  = null;

const form          = document.getElementById("user-form");
const submitBtn     = document.getElementById("submit-btn");
const cancelBtn     = document.getElementById("cancel-btn");
const formTitle     = document.getElementById("form-title");
const passwordEl    = document.getElementById("password");
const passwordLabel = document.getElementById("password-label");
const rowsEl        = document.getElementById("rows");
const countEl       = document.getElementById("record-count");
const toggleLink    = document.getElementById("toggle-filter");

// ---------------------------------------------------------------------
// Employee dropdown
// ---------------------------------------------------------------------
async function loadEmployeeOptions() {
    const select = document.getElementById("employee_id");
    const currentVal = select.value;
    try {
        const { data } = await api.get("employees_lookup.php");
        select.innerHTML = `<option value="">-- None --</option>` +
            data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        if (currentVal) select.value = currentVal;
    } catch (err) {
        showToast("Could not load employees: " + apiErrorMessage(err), "error");
    }
}

// ---------------------------------------------------------------------
// Load rows
// ---------------------------------------------------------------------
async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading…</td></tr>`;
    try {
        const { data } = await api.get("users.php", { params: showAll ? { all: 1 } : {} });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">No records found.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr class="${r.is_active ? "" : "inactive-row"}">
            <td>#${r.user_id}</td>
            <td>${escapeHtml(r.username)}</td>
            <td>${escapeHtml(r.role ?? "—")}</td>
            <td>${r.employee_name ? escapeHtml(r.employee_name) : `<span style="color:#9ca3af;">—</span>`}</td>
            <td class="col-center"><span class="badge ${r.is_active ? "active" : "inactive"}">${r.is_active ? "Active" : "Inactive"}</span></td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.user_id}">Edit</button>
                <button class="btn ${r.is_active ? "btn-delete" : "btn-restore"}" data-toggle="${r.user_id}" data-active="${r.is_active ? 0 : 1}">
                    ${r.is_active ? "Deactivate" : "Restore"}
                </button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.user_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-toggle]").forEach((btn) => {
        btn.addEventListener("click", () => toggleStatus(btn.dataset.toggle, Number(btn.dataset.active)));
    });
}

// ---------------------------------------------------------------------
// Edit / reset
// ---------------------------------------------------------------------
function startEdit(row) {
    if (!row) return;
    editId = row.user_id;
    document.getElementById("user_id").value     = row.user_id;
    document.getElementById("username").value    = row.username;
    document.getElementById("role").value        = row.role ?? "Administrator";
    document.getElementById("employee_id").value = row.employee_id ?? "";
    passwordEl.value = "";
    passwordEl.required = false;
    passwordLabel.textContent = "Password (leave blank to keep current)";
    formTitle.textContent = "Edit User";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("user_id").value = "";
    document.getElementById("employee_id").value = "";
    passwordEl.required = true;
    passwordLabel.textContent = "Password";
    formTitle.textContent = "Add New User";
    submitBtn.textContent = "Add User";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

// ---------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------
form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        username:    document.getElementById("username").value.trim(),
        role:        document.getElementById("role").value,
        employee_id: document.getElementById("employee_id").value || 0,
        password:    passwordEl.value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.user_id = editId;
            const { data } = await api.put("users.php", payload);
            showToast(data.message, "success");
        } else {
            const { data } = await api.post("users.php", payload);
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

// ---------------------------------------------------------------------
// Deactivate / restore
// ---------------------------------------------------------------------
async function toggleStatus(id, newStatus) {
    const verb = newStatus ? "Restore" : "Deactivate";
    if (!await confirmDialog(`${verb} this user?`)) return;
    try {
        const { data } = await api.patch("users.php", { user_id: id, is_active: newStatus });
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

// ---------------------------------------------------------------------
// Role-dependent hint.
//
// A Supervisor leads the team of the employee their account is linked to, so
// an unlinked Supervisor account supervises nobody and every team guard
// answers 403. Point that out where the role is chosen instead of letting it
// surprise them on the attendance queue.
// ---------------------------------------------------------------------
const roleSelect       = document.getElementById("role");
const employeeLinkHint = document.getElementById("employee-link-hint");
const EMPLOYEE_LINKED_ROLES = ["Employee", "Manager"];

function applyRoleHint() {
    if (!roleSelect || !employeeLinkHint) return;
    const role = roleSelect.value;
    employeeLinkHint.textContent = EMPLOYEE_LINKED_ROLES.indexOf(role) !== -1
        ? "(required for the " + role + " role)"
        : "(optional)";
}

if (roleSelect) roleSelect.addEventListener("change", applyRoleHint);
applyRoleHint();

document.addEventListener("DOMContentLoaded", () => {
    loadEmployeeOptions();
    loadRows();
    applyRoleHint();
});