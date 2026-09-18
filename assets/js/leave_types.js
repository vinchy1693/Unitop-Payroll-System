// =====================================================================
// assets/js/leave_types.js
// =====================================================================

let showAll = false;
let editId  = null;

const form       = document.getElementById("lt-form");
const submitBtn  = document.getElementById("submit-btn");
const cancelBtn  = document.getElementById("cancel-btn");
const formTitle  = document.getElementById("form-title");
const rowsEl     = document.getElementById("rows");
const countEl    = document.getElementById("record-count");
const toggleLink = document.getElementById("toggle-filter");

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="5">Loading…</td></tr>`;
    try {
        const { data } = await api.get("leave_types.php", { params: showAll ? { all: 1 } : {} });
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
            <td>#${r.leave_type_id}</td>
            <td>${escapeHtml(r.name)}</td>
            <td>${escapeHtml(r.days_per_year)}</td>
            <td class="col-center"><span class="badge ${r.is_active ? "active" : "inactive"}">${r.is_active ? "Active" : "Inactive"}</span></td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.leave_type_id}">Edit</button>
                <button class="btn ${r.is_active ? "btn-delete" : "btn-restore"}" data-toggle="${r.leave_type_id}" data-active="${r.is_active ? 0 : 1}">
                    ${r.is_active ? "Deactivate" : "Restore"}
                </button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.leave_type_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-toggle]").forEach((btn) => {
        btn.addEventListener("click", () => toggleStatus(btn.dataset.toggle, Number(btn.dataset.active)));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.leave_type_id;
    document.getElementById("leave_type_id").value = row.leave_type_id;
    document.getElementById("name").value          = row.name;
    document.getElementById("days_per_year").value = row.days_per_year;
    formTitle.textContent = "Edit Leave Type";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("leave_type_id").value = "";
    document.getElementById("days_per_year").value = "0";
    formTitle.textContent = "Add New Leave Type";
    submitBtn.textContent = "Add Leave Type";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        name:          document.getElementById("name").value.trim(),
        days_per_year: document.getElementById("days_per_year").value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.leave_type_id = editId;
            const { data } = await api.put("leave_types.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("leave_types.php", payload);
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
    if (!confirm(`${verb} this leave type?`)) return;
    try {
        const { data } = await api.patch("leave_types.php", { leave_type_id: Number(id), is_active: newStatus });
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