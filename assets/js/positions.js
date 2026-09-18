// =====================================================================
// assets/js/positions.js
// =====================================================================

let showAll = false;
let editId = null;

const form = document.getElementById("pos-form");
const submitBtn = document.getElementById("submit-btn");
const cancelBtn = document.getElementById("cancel-btn");
const formTitle = document.getElementById("form-title");
const rowsEl = document.getElementById("rows");
const countEl = document.getElementById("record-count");
const toggleLink = document.getElementById("toggle-filter");

async function loadDepartmentOptions() {
    const select = document.getElementById("department_id");
    const currentVal = select.value;
    try {
        const { data } = await api.get("departments.php", { params: { simple: 1 } });
        const options = data.data.map((d) => `<option value="${d.id}">${escapeHtml(d.label)}</option>`).join("");
        select.innerHTML = `<option value="">-- Select --</option>${options}`;
        if (currentVal) select.value = currentVal;
    } catch (err) {
        showMessage("Could not load departments list: " + apiErrorMessage(err), "error");
    }
}

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="5">Loading…</td></tr>`;
    try {
        const { data } = await api.get("positions.php", { params: showAll ? { all: 1 } : {} });
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
            <td>#${r.position_id}</td>
            <td>${escapeHtml(r.title)}</td>
            <td>${escapeHtml(r.department_name)}</td>
            <td class="col-center"><span class="badge ${r.is_active ? "active" : "inactive"}">${r.is_active ? "Active" : "Inactive"}</span></td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.position_id}">Edit</button>
                <button class="btn ${r.is_active ? "btn-delete" : "btn-restore"}" data-toggle="${r.position_id}" data-active="${r.is_active ? 0 : 1}">
                    ${r.is_active ? "Deactivate" : "Restore"}
                </button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.position_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-toggle]").forEach((btn) => {
        btn.addEventListener("click", () => toggleStatus(btn.dataset.toggle, Number(btn.dataset.active)));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.position_id;
    document.getElementById("position_id").value = row.position_id;
    document.getElementById("department_id").value = row.department_id;
    document.getElementById("title").value = row.title;
    formTitle.textContent = "Edit Position";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("position_id").value = "";
    formTitle.textContent = "Add New Position";
    submitBtn.textContent = "Add Position";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        department_id: document.getElementById("department_id").value,
        title: document.getElementById("title").value.trim(),
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.position_id = editId;
            const { data } = await api.put("positions.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("positions.php", payload);
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
    if (!confirm(`${verb} this position?`)) return;
    try {
        const { data } = await api.patch("positions.php", { position_id: id, is_active: newStatus });
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

document.addEventListener("DOMContentLoaded", () => {
    loadDepartmentOptions();
    loadRows();
});