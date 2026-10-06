// =====================================================================
// assets/js/payroll_periods.js
// Combined Payroll page — periods + runs in one view.
// =====================================================================

let editId = null;

const form      = document.getElementById("pp-form");
const submitBtn = document.getElementById("submit-btn");
const cancelBtn = document.getElementById("cancel-btn");
const formTitle = document.getElementById("form-title");
const rowsEl    = document.getElementById("rows");
const countEl   = document.getElementById("record-count");

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="8">Loading…</td></tr>`;
    try {
        const { data } = await api.get("payroll_periods.php");
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function statusBadge(status) {
    const map = {
        "Open":      { cls: "active",   label: "Open" },
        "Processed": { cls: "active",   label: "Processed" },
        "Closed":    { cls: "inactive", label: "Closed" },
    };
    const s = map[status] || { cls: "inactive", label: status ?? "—" };
    return `<span class="badge ${s.cls}">${s.label}</span>`;
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `
            <tr class="empty-row">
                <td colspan="8" style="padding:40px 20px;">
                    <div style="font-size:14px;color:#666;margin-bottom:12px;">
                        No payroll periods yet.
                    </div>
                    <div style="font-size:12.5px;color:#888;">
                        Create a period above to get started.
                    </div>
                </td>
            </tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const isOpen      = r.status === "Open";
        const isProcessed = r.status === "Processed";
        const isClosed    = r.status === "Closed";

        let actions = "";
        if (isOpen) {
            actions += `<button class="btn btn-primary" data-run="${r.period_id}" title="Generate payslips for this period">▶ Run Payroll</button> `;
            actions += `<button class="btn btn-edit" data-edit="${r.period_id}">Edit</button> `;
            actions += `<button class="btn btn-delete" data-status="${r.period_id}" data-new="Closed">Close</button>`;
        } else if (isClosed) {
            actions += `<button class="btn btn-restore" data-status="${r.period_id}" data-new="Open">Reopen</button>`;
        } else if (isProcessed) {
            if (r.run_id) {
                actions += `<a class="btn btn-primary" href="payslips.html?run_id=${r.run_id}">View Payslips</a>`;
            } else {
                actions += `<span style="color:#888;font-size:12px;">Locked</span>`;
            }
        }

        const runCell = r.run_id
            ? `#${r.run_id}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.run_date ?? "")}</span>`
            : `<span style="color:#9ca3af;">—</span>`;

        const payslipCell = r.run_id
            ? `<strong style="color:#2f5496;">${r.payslip_count ?? 0}</strong>`
            : `<span style="color:#9ca3af;">—</span>`;

        return `
        <tr>
            <td>#${r.period_id}</td>
            <td>${escapeHtml(r.period_type)}</td>
            <td>${escapeHtml(r.start_date)}</td>
            <td>${escapeHtml(r.end_date)}</td>
            <td class="col-center">${statusBadge(r.status)}</td>
            <td class="col-center">${runCell}</td>
            <td class="col-center">${payslipCell}</td>
            <td class="col-center">${actions}</td>
        </tr>`;
    }).join("");

    rowsEl.querySelectorAll("[data-run]").forEach((btn) => {
        btn.addEventListener("click", () => runPayroll(Number(btn.dataset.run), btn));
    });
    rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
        btn.addEventListener("click", () => startEdit(rows.find((r) => r.period_id == btn.dataset.edit)));
    });
    rowsEl.querySelectorAll("[data-status]").forEach((btn) => {
        btn.addEventListener("click", () => changeStatus(btn.dataset.status, btn.dataset.new));
    });
}

// ---------------------------------------------------------------------
// One-click run payroll
// ---------------------------------------------------------------------
async function runPayroll(periodId, btn) {
    if (!confirm("Run payroll for this period? This will generate payslips and lock the period.")) return;

    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Running…";

    try {
        const { data } = await api.post("payroll_runs.php", { period_id: periodId });
        showMessage(data.message, "success");

        const runId = data.data?.run_id;
        if (runId) {
            setTimeout(() => {
                window.location.href = `payslips.html?run_id=${runId}`;
            }, 800);
        } else {
            btn.disabled = false;
            btn.textContent = originalText;
            await loadRows();
        }
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

// ---------------------------------------------------------------------
// Edit / create
// ---------------------------------------------------------------------
function startEdit(row) {
    if (!row) return;
    editId = row.period_id;
    document.getElementById("period_id").value   = row.period_id;
    document.getElementById("period_type").value = row.period_type ?? "Semi-monthly";
    document.getElementById("start_date").value  = row.start_date ?? "";
    document.getElementById("end_date").value    = row.end_date ?? "";
    formTitle.textContent = "Edit Payroll Period";
    submitBtn.textContent = "Save Changes";
    cancelBtn.classList.remove("hidden");
    window.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
    editId = null;
    form.reset();
    document.getElementById("period_id").value = "";
    formTitle.textContent = "New Payroll Period";
    submitBtn.textContent = "Add Period";
    cancelBtn.classList.add("hidden");
}

cancelBtn.addEventListener("click", resetForm);

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        period_type: document.getElementById("period_type").value,
        start_date:  document.getElementById("start_date").value,
        end_date:    document.getElementById("end_date").value,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.period_id = editId;
            const { data } = await api.put("payroll_periods.php", payload);
            showMessage(data.message, "success");
        } else {
            const { data } = await api.post("payroll_periods.php", payload);
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

// ---------------------------------------------------------------------
// Close / reopen
// ---------------------------------------------------------------------
async function changeStatus(id, newStatus) {
    const verb = newStatus === "Closed" ? "Close" : "Reopen";
    if (!confirm(`${verb} this period?`)) return;
    try {
        const { data } = await api.patch("payroll_periods.php", { period_id: Number(id), status: newStatus });
        showMessage(data.message, "success");
        await loadRows();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
}

// ---------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------
document.addEventListener("DOMContentLoaded", loadRows);