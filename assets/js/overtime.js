// =====================================================================
// assets/js/overtime.js
// =====================================================================

let editId = null;

const form         = document.getElementById("ot-form");
const submitBtn    = document.getElementById("submit-btn");
const cancelBtn    = document.getElementById("cancel-btn");
const formTitle    = document.getElementById("form-title");
const rowsEl       = document.getElementById("rows");
const countEl      = document.getElementById("record-count");
const filterDate   = document.getElementById("filter-date");
const typeSelect   = document.getElementById("ot_type_select");
const multiplierEl = document.getElementById("rate_multiplier");

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
        select.innerHTML = `<option value="">-- Select --</option>` +
            data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        if (currentVal) select.value = currentVal;
    } catch (err) {
        showMessage("Could not load employees list: " + apiErrorMessage(err), "error");
    }
}

async function loadRows() {
    const date = filterDate.value;
    if (!date) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Pick a date above.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading…</td></tr>`;
    try {
        const { data } = await api.get("overtime_records.php", { params: { date } });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">No overtime for this date.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr>
            <td>${escapeHtml(r.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
            <td>${escapeHtml(r.department_name ?? "—")}</td>
            <td class="col-center">${escapeHtml(r.ot_type)}</td>
            <td style="text-align:right;">${Number(r.hours).toFixed(2)}</td>
            <td style="text-align:right;">${Number(r.rate_multiplier).toFixed(2)}×</td>
            <td class="col-center">
                <button class="btn btn-edit" data-edit="${r.ot_id}">Edit</button>
                <button class="btn btn-delete" data-delete="${r.ot_id}">Delete</button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.ot_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-delete]").forEach((btn) => {
        btn.addEventListener("click", () => deleteRow(btn.dataset.delete));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.ot_id;
    document.getElementById("ot_id").value    = row.ot_id;
    document.getElementById("employee_id").value = row.employee_id;
    document.getElementById("ot_date").value  = row.ot_date ?? "";
    document.getElementById("hours").value    = row.hours;

    // Match the type in the dropdown (or add it if it doesn't exist)
    let found = false;
    for (const opt of typeSelect.options) {
        if (opt.value === row.ot_type) { typeSelect.value = row.ot_type; found = true; break; }
    }
    if (!found) {
        const opt = document.createElement("option");
        opt.value = row.ot_type;
        opt.textContent = row.ot_type;
        opt.dataset.mult = row.rate_multiplier;
        typeSelect.appendChild(opt);
        typeSelect.value = row.ot_type;
    }

    multiplierEl.value = row.rate_multiplier;

    formTitle.textContent = "Edit Overtime";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("ot_id").value = "";
    document.getElementById("ot_date").value = filterDate.value || todayISO();
    typeSelect.selectedIndex = 0;
    multiplierEl.value = "1.25";
    formTitle.textContent = "Log Overtime";
    submitBtn.textContent = "Log Overtime";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

// Auto-fill multiplier when type changes
typeSelect.addEventListener("change", () => {
    const opt = typeSelect.options[typeSelect.selectedIndex];
    if (opt.dataset.mult) {
        multiplierEl.value = opt.dataset.mult;
    }
});

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        employee_id:     document.getElementById("employee_id").value,
        ot_date:         document.getElementById("ot_date").value,
        hours:           document.getElementById("hours").value,
        ot_type:         typeSelect.value,
        rate_multiplier: multiplierEl.value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.ot_id = editId;
            const { data } = await api.put("overtime_records.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("overtime_records.php", payload);
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
    if (!confirm("Delete this overtime record?")) return;
    try {
        const { data } = await api.delete("overtime_records.php", { params: { id } });
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
}

filterDate.addEventListener("change", loadRows);

document.addEventListener("DOMContentLoaded", () => {
    filterDate.value = todayISO();
    document.getElementById("ot_date").value = todayISO();
    loadEmployeeOptions();
    loadRows();
});