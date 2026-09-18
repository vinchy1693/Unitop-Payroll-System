// =====================================================================
// assets/js/users.js
// =====================================================================

let showAll = false;
let editId = null;

const form = document.getElementById("user-form");
const submitBtn = document.getElementById("submit-btn");
const cancelBtn = document.getElementById("cancel-btn");
const formTitle = document.getElementById("form-title");
const passwordEl = document.getElementById("password");
const passwordLabel = document.getElementById("password-label");
const rowsEl = document.getElementById("rows");
const countEl = document.getElementById("record-count");
const toggleLink = document.getElementById("toggle-filter");

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="5">Loading…</td></tr>`;
    try {
        const { data } = await api.get("users.php", { params: showAll ? { all: 1 } : {} });
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
            <td>#${r.user_id}</td>
            <td>${escapeHtml(r.username)}</td>
            <td>${escapeHtml(r.role)}</td>
            <td><span class="badge ${r.is_active ? "active" : "inactive"}">${r.is_active ? "Active" : "Inactive"}</span></td>
            <td>
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

function startEdit(row) {
    if (!row) return;
    editId = row.user_id;
    document.getElementById("user_id").value = row.user_id;
    document.getElementById("username").value = row.username;
    document.getElementById("role").value = row.role;
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
    passwordEl.required = true;
    passwordLabel.textContent = "Password";
    formTitle.textContent = "Add New User";
    submitBtn.textContent = "Add User";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        username: document.getElementById("username").value.trim(),
        role: document.getElementById("role").value,
        password: passwordEl.value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.user_id = editId;
            const { data } = await api.put("users.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("users.php", payload);
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

async function toggleStatus(id, newStatus) {
    const verb = newStatus ? "Restore" : "Deactivate";
    if (!confirm(`${verb} this user?`)) return;
    try {
        const { data } = await api.patch("users.php", { user_id: id, is_active: newStatus });
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
}

toggleLink.addEventListener("click", (e) => {
    e.preventDefault();
    showAll = !showAll;
    toggleLink.textContent = showAll ? "Show active only" : "Show all (incl. deactivated)";
    loadRows();
});

document.addEventListener("DOMContentLoaded", loadRows);
