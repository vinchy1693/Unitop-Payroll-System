// =====================================================================
// assets/js/loans.js
// =====================================================================

let editId = null;

const form      = document.getElementById("loan-form");
const submitBtn = document.getElementById("submit-btn");
const cancelBtn = document.getElementById("cancel-btn");
const formTitle = document.getElementById("form-title");
const rowsEl    = document.getElementById("rows");
const countEl   = document.getElementById("record-count");

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="8">Loading…</td></tr>`;
    try {
        const { data } = await api.get("loans.php");
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function statusBadge(status) {
    const map = {
        "Active":    { cls: "active",   label: "Active" },
        "Paid":      { cls: "active",   label: "Fully Paid" },
        "Cancelled": { cls: "inactive", label: "Cancelled" },
    };
    const s = map[status] || { cls: "inactive", label: status ?? "—" };
    return `<span class="badge ${s.cls}">${s.label}</span>`;
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">No loans registered.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const isActive = r.status === "Active";

        let actions = "";
        if (isActive) {
            actions = `
                <button class="btn btn-edit" data-edit="${r.loan_id}">Edit</button>
                <button class="btn btn-delete" data-cancel="${r.loan_id}">Cancel</button>
            `;
        } else if (r.status === "Cancelled") {
            actions = `<button class="btn btn-restore" data-restore="${r.loan_id}">Reactivate</button>`;
        } else {
            actions = `<span style="color:#888;font-size:12px;">Closed</span>`;
        }

        return `
            <tr class="${isActive ? "" : "inactive-row"}">
                <td>${escapeHtml(r.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
                <td>${escapeHtml(r.loan_type)}</td>
                <td style="text-align:right;">${formatMoney(r.principal_amount)}</td>
                <td style="text-align:right;">${formatMoney(r.total_paid)}</td>
                <td style="text-align:right;font-weight:600;">${formatMoney(r.remaining_balance)}</td>
                <td style="text-align:right;">${formatMoney(r.monthly_amortization)}</td>
                <td class="col-center">${statusBadge(r.status)}</td>
                <td class="col-center">${actions}</td>
            </tr>
        `;
    }).join("");

    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.loan_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-cancel]").forEach((btn) => {
        btn.addEventListener("click", () => setStatus(btn.dataset.cancel, "Cancelled"));
    });
    rowsEl.querySelectorAll("[data-restore]").forEach((btn) => {
        btn.addEventListener("click", () => setStatus(btn.dataset.restore, "Active"));
    });
}

function startEdit(row) {
    if (!row) return;
    editId = row.loan_id;
    document.getElementById("loan_id").value              = row.loan_id;
    document.getElementById("employee_id").value          = row.employee_id;
    document.getElementById("loan_type").value            = row.loan_type;
    document.getElementById("principal_amount").value     = row.principal_amount;
    document.getElementById("monthly_amortization").value = row.monthly_amortization;
    formTitle.textContent = "Edit Loan";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("loan_id").value = "";
    formTitle.textContent = "Add New Loan";
    submitBtn.textContent = "Add Loan";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        employee_id:          document.getElementById("employee_id").value,
        loan_type:            document.getElementById("loan_type").value,
        principal_amount:     document.getElementById("principal_amount").value,
        monthly_amortization: document.getElementById("monthly_amortization").value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.loan_id = editId;
            const { data } = await api.put("loans.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("loans.php", payload);
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

async function setStatus(id, status) {
    const verb = status === "Cancelled" ? "Cancel" : "Reactivate";
    if (!confirm(`${verb} this loan?`)) return;
    try {
        const { data } = await api.patch("loans.php", { loan_id: Number(id), status });
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
}

document.addEventListener("DOMContentLoaded", () => {
    loadEmployeeOptions();
    loadRows();
});