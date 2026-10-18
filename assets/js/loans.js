// =====================================================================
// assets/js/loans.js
//
// The maker (Payroll Officer) adds / edits / cancels a loan. A Supervisor
// gets ONE action here: "Recommend" on a team member's Pending request. That
// is advice only - it records who recommended it and never changes the
// status, because the Approver still decides. The API enforces exactly the
// same rule (SUPERVISOR_ROLE on action=recommend, CHECKER_ROLES on
// action=approve, MAKER_ROLES everywhere else).
// =====================================================================

let editId = null;
let currentUser = null;

const form      = document.getElementById("loan-form");
const submitBtn = document.getElementById("submit-btn");
const cancelBtn = document.getElementById("cancel-btn");
const formTitle = document.getElementById("form-title");
const rowsEl    = document.getElementById("rows");
const countEl   = document.getElementById("record-count");

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function isSupervisor() { return !!currentUser && currentUser.role === "Supervisor"; }

function getEmployeeLabel() {
    const sel = document.getElementById("employee_id");
    if (!sel || sel.selectedIndex < 0) return "this employee";
    return sel.options[sel.selectedIndex].text || "this employee";
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
        showToast("Could not load employees: " + apiErrorMessage(err), "error");
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
        } else if (r.status === "Pending" && isSupervisor()) {
            // Advice only: the Approver still decides.
            actions = `<button class="btn btn-edit" data-recommend="${r.loan_id}">Recommend</button>`;
        } else {
            actions = `<span style="color:#888;font-size:12px;">Closed</span>`;
        }

        const recommendation = r.has_recommendation
            ? `<div style="font-size:11px;color:var(--muted);margin-top:4px;">`
              + `recommended by ${escapeHtml(r.recommended_by_name || "Supervisor")}</div>`
            : "";

        return `
            <tr class="${isActive ? "" : "inactive-row"}">
                <td>${escapeHtml(r.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
                <td>${escapeHtml(r.loan_type)}</td>
                <td style="text-align:right;">${formatMoney(r.principal_amount)}</td>
                <td style="text-align:right;">${formatMoney(r.total_paid)}</td>
                <td style="text-align:right;font-weight:600;">${formatMoney(r.remaining_balance)}</td>
                <td style="text-align:right;">${formatMoney(r.monthly_amortization)}</td>
                <td class="col-center">${statusBadge(r.status)}${recommendation}</td>
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
    rowsEl.querySelectorAll("[data-recommend]").forEach((btn) => {
        btn.addEventListener("click", () => recommendLoan(Number(btn.dataset.recommend)));
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

    const empId     = document.getElementById("employee_id").value;
    const loanType  = document.getElementById("loan_type").value;
    const principal = document.getElementById("principal_amount").value;
    const amort     = document.getElementById("monthly_amortization").value;
    const empLabel  = getEmployeeLabel();

    if (!empId || !loanType || !principal || !amort) return;

    if (!editId) {
        if (!await confirmDialog(
            `Add a ${loanType} for ${empLabel} with ₱${Number(principal).toLocaleString()} principal and ₱${Number(amort).toLocaleString()} monthly amortization?`,
            { title: "Confirm New Loan", icon: "question", confirmText: "Add Loan" }
        )) return;
    }

    const payload = {
        employee_id:          empId,
        loan_type:            loanType,
        principal_amount:     principal,
        monthly_amortization: amort,
    };

    submitBtn.disabled = true;
    try {
        if (editId) {
            payload.loan_id = editId;
            const { data } = await api.put("loans.php", payload);
            showToast(data.message, "success");
        } else {
            const { data } = await api.post("loans.php", payload);
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
// Supervisor: recommend a team member's Pending loan.
//
// Records the advice and reloads. The loan keeps its status - the recommendation
// is advisory and the Approver still decides - which the toast says explicitly
// so nobody mistakes it for an approval.
// ---------------------------------------------------------------------
async function recommendLoan(loanId) {
    const row = (await api.get("loans.php")).data.data.find((r) => Number(r.loan_id) === loanId);
    const who = row ? `${row.employee_name} (${row.loan_type}, ${formatMoney(row.principal_amount)})` : "this loan";

    const result = await Swal.fire({
        title: "Recommend this loan",
        html: `<div style="font-size:13px;text-align:left;margin-bottom:10px;">
                 Recommend <strong>${escapeHtml(who)}</strong> to the Approver.
                 This does <strong>not</strong> approve it - the Approver still decides.
               </div>`,
        input: "textarea",
        inputPlaceholder: "Why do you recommend it? (optional, the Approver will see this)",
        inputAttributes: { maxlength: 500, rows: 3 },
        showCancelButton: true,
        confirmButtonText: "Recommend",
        cancelButtonText: "Cancel",
        reverseButtons: true,
    });
    if (!result.isConfirmed) return;

    try {
        const { data } = await api.patch("loans.php", {
            loan_id: loanId,
            action: "recommend",
            remarks: String(result.value || "").trim(),
        });
        showToast(data.message, "success");
        await loadRows();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

async function setStatus(id, status) {
    const isCancel = status === "Cancelled";
    const verb = isCancel ? "Cancel" : "Reactivate";

    const options = isCancel
        ? { title: "Cancel Loan", icon: "warning", confirmText: "Cancel Loan" }
        : { title: "Reactivate Loan", icon: "question", confirmText: "Reactivate" };

    if (!await confirmDialog(
        isCancel
            ? "Cancel this loan? It will stop being deducted from future payroll runs."
            : "Reactivate this loan? It will resume being deducted from payroll.",
        options
    )) return;

    try {
        const { data } = await api.patch("loans.php", { loan_id: Number(id), status });
        showToast(data.message, "success");
        await loadRows();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

document.addEventListener("DOMContentLoaded", async () => {
    // nav.js already asked auth.php?action=me; reuse that one request.
    try {
        currentUser = (typeof dshCurrentUser === "function")
            ? await dshCurrentUser()
            : (await api.get("auth.php?action=me")).data.data;
    } catch (err) {
        currentUser = null;
    }

    if (isSupervisor()) {
        // A Supervisor cannot create or edit loans, and employees.php is
        // closed to them, so the add form and its employee lookup are hidden.
        const addCard = formTitle ? formTitle.closest(".card") : null;
        if (addCard) addCard.classList.add("hidden");
    } else {
        loadEmployeeOptions();
    }

    await loadRows();
});