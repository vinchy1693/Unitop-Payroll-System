// =====================================================================
// assets/js/roles.js
// =====================================================================

let showAll = false;
let editId  = null;

const form       = document.getElementById("role-form");
const submitBtn  = document.getElementById("submit-btn");
const cancelBtn  = document.getElementById("cancel-btn");
const formTitle  = document.getElementById("form-title");
const rowsEl     = document.getElementById("rows");
const countEl    = document.getElementById("record-count");
const toggleLink = document.getElementById("toggle-filter");

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="5">Loading…</td></tr>`;
    try {
        const { data } = await api.get("roles.php", { params: showAll ? { all: 1 } : {} });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="5">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="5">No roles found.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr class="${r.is_active ? "" : "inactive-row"}">
            <td>#${r.role_id}</td>
            <td><strong>${escapeHtml(r.role_name)}</strong></td>
            <td>${escapeHtml(r.description ?? "—")}</td>
            <td class="col-center"><span class="badge ${r.is_active ? "active" : "inactive"}">${r.is_active ? "Active" : "Inactive"}</span></td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.role_id}">Edit</button>
                <button class="btn ${r.is_active ? "btn-delete" : "btn-restore"}" data-toggle="${r.role_id}" data-active="${r.is_active ? 0 : 1}">
                    ${r.is_active ? "Deactivate" : "Restore"}
                </button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.role_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-toggle]").forEach((btn) => {
        btn.addEventListener("click", () => toggleStatus(btn.dataset.toggle, Number(btn.dataset.active)));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.role_id;
    document.getElementById("role_id").value     = row.role_id;
    document.getElementById("role_name").value   = row.role_name;
    document.getElementById("description").value = row.description ?? "";
    formTitle.textContent = "Edit Role";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("role_id").value = "";
    formTitle.textContent = "Add New Role";
    submitBtn.textContent = "Add Role";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        role_name:   document.getElementById("role_name").value.trim(),
        description: document.getElementById("description").value.trim(),
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.role_id = editId;
            const { data } = await api.put("roles.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("roles.php", payload);
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
    if (!confirm(`${verb} this role?`)) return;
    try {
        const { data } = await api.patch("roles.php", { role_id: Number(id), is_active: newStatus });
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