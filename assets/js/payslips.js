// =====================================================================
// assets/js/payslips.js
// =====================================================================

const rowsEl     = document.getElementById("rows");
const countEl    = document.getElementById("record-count");
const subtitleEl = document.getElementById("subtitle");
const modal      = document.getElementById("payslip-modal");
const modalBody  = document.getElementById("modal-body");

let currentPayslipId = null;

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getRunId() {
    const params = new URLSearchParams(location.search);
    return Number(params.get("run_id") || 0);
}

async function loadRows() {
    const runId = getRunId();
    if (!runId) {
        subtitleEl.textContent = "No run selected.";
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">Go to <a href="payroll_runs.html">Payroll Runs</a> and pick a run.</td></tr>`;
        return;
    }

    subtitleEl.textContent = `Payslips generated for Run #${runId}`;

    try {
        const { data } = await api.get("payslips.php", { params: { run_id: runId } });
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} payslip(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">No payslips for this run.</td></tr>`;
        return;
    }

    // Share of gross for one deduction component, shown under its amount.
    function pctOf(part, whole) {
        const w = Number(whole || 0);
        if (!(w > 0)) return "";
        return `<br><span style="color:var(--muted); font-size:10.5px;">${((Number(part || 0) / w) * 100).toFixed(1)}% of gross</span>`;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr class="payslip-row" data-id="${r.payslip_id}" style="cursor:pointer;">
            <td>
                ${escapeHtml(r.employee_name)}<br>
                <span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)} · ${escapeHtml(r.department_name ?? "—")}</span>
            </td>
            <td style="text-align:right;">${formatMoney(r.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(r.sss_ee)}${pctOf(r.sss_ee, r.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(r.philhealth_ee)}${pctOf(r.philhealth_ee, r.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(r.pagibig_ee)}${pctOf(r.pagibig_ee, r.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(r.withholding_tax)}${pctOf(r.withholding_tax, r.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(r.total_deductions)}${pctOf(r.total_deductions, r.gross_pay)}</td>
            <td style="text-align:right; font-weight:600;">${formatMoney(r.net_pay)}</td>
            <td class="col-center">
                <button class="btn btn-edit" data-view="${r.payslip_id}">View</button>
            </td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-view]").forEach((btn) => {
        btn.addEventListener("click", (e) => {
            e.stopPropagation();
            openPayslip(Number(btn.dataset.view));
        });
    });
    rowsEl.querySelectorAll(".payslip-row").forEach((row) => {
        row.addEventListener("click", () => openPayslip(Number(row.dataset.id)));
    });
}

// ---------------------------------------------------------------------
// Modal
// ---------------------------------------------------------------------
async function openPayslip(payslipId) {
    currentPayslipId = payslipId;
    modal.classList.remove("hidden");
    modalBody.innerHTML = `<div style="text-align:center;padding:40px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("payslips.php", { params: { id: payslipId } });
        renderPayslip(data.data);
    } catch (err) {
        modalBody.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed to load: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
}

async function refreshPayslip() {
    if (!currentPayslipId) return;
    const { data } = await api.get("payslips.php", { params: { id: currentPayslipId } });
    renderPayslip(data.data);
}

function closeModal() {
    modal.classList.add("hidden");
    currentPayslipId = null;
    // Refresh the table so updated totals show
    loadRows();
}

function renderPayslip(p) {
    const gross    = Number(p.gross_pay || 0);
    const basic    = Number(p.basic_pay || 0);
    const ot       = Number(p.overtime_pay || 0);
    const premium  = Number(p.premium_pay || 0);
    const sss      = Number(p.sss_ee || 0);
    const ph       = Number(p.philhealth_ee || 0);
    const pi       = Number(p.pagibig_ee || 0);
    const tax      = Number(p.withholding_tax || 0);
    const totalDed = Number(p.total_deductions || 0);
    const net      = Number(p.net_pay || 0);
    const sssEr    = Number(p.sss_er || 0);
    const phEr     = Number(p.philhealth_er || 0);
    const piEr     = Number(p.pagibig_er || 0);

    const allowances = p.allowances || [];
    const deductions = p.deductions || [];

    const allowanceRows = allowances.map((a) => `
        <div class="line editable-line">
            <span>${escapeHtml(a.allowance_type)}</span>
            <span>
                ${formatMoney(a.amount)}
                <button class="line-remove" data-remove-allowance="${a.allowance_id}" title="Remove">&times;</button>
            </span>
        </div>
    `).join("");

    const deductionRows = deductions.map((d) => `
        <div class="line editable-line">
            <span>${escapeHtml(d.deduction_type)}</span>
            <span>
                ${formatMoney(d.amount)}
                <button class="line-remove" data-remove-deduction="${d.deduction_id}" title="Remove">&times;</button>
            </span>
        </div>
    `).join("");

    modalBody.innerHTML = `
        <div class="payslip-doc">
            <div class="payslip-header">
                <div>
                    <h2>UNITOP</h2>
                    <div class="payslip-company-sub">Payroll Payslip</div>
                </div>
                <div class="payslip-period">
                    <div><strong>Period:</strong> ${escapeHtml(p.start_date)} → ${escapeHtml(p.end_date)}</div>
                    <div><strong>Type:</strong> ${escapeHtml(p.period_type ?? "—")}</div>
                    <div><strong>Payslip #:</strong> ${escapeHtml(p.payslip_id)}</div>
                </div>
            </div>

            <div class="payslip-employee">
                <div>
                    <div class="label">Employee</div>
                    <div class="value">${escapeHtml(p.employee_name)}</div>
                </div>
                <div>
                    <div class="label">Employee No.</div>
                    <div class="value">${escapeHtml(p.employee_number)}</div>
                </div>
                <div>
                    <div class="label">Department</div>
                    <div class="value">${escapeHtml(p.department_name ?? "—")}</div>
                </div>
                <div>
                    <div class="label">Position</div>
                    <div class="value">${escapeHtml(p.position_title ?? "—")}</div>
                </div>
            </div>

            <div class="payslip-sections">
                <div class="payslip-section">
                    <h4>Earnings</h4>
                    <div class="line"><span>Basic Pay</span><span>${formatMoney(basic)}</span></div>
                    <div class="line"><span>Overtime Pay</span><span>${formatMoney(ot)}</span></div>
                    <div class="line"><span>Premium Pay (Rest Day / Holiday)</span><span>${formatMoney(premium)}</span></div>
                    ${allowanceRows}
                    <div class="line-add">
                        <button class="btn-add-line" data-add-allowance>+ Add Allowance</button>
                    </div>
                    <div class="line total"><span>Gross Pay</span><span>${formatMoney(gross)}</span></div>
                </div>

                <div class="payslip-section">
                    <h4>Deductions</h4>
                    <div class="line"><span>SSS (Employee)</span><span>${formatMoney(sss)}</span></div>
                    <div class="line"><span>PhilHealth (Employee)</span><span>${formatMoney(ph)}</span></div>
                    <div class="line"><span>Pag-IBIG (Employee)</span><span>${formatMoney(pi)}</span></div>
                    <div class="line"><span>Withholding Tax</span><span>${formatMoney(tax)}</span></div>
                    ${deductionRows}
                    <div class="line-add">
                        <button class="btn-add-line" data-add-deduction>+ Add Deduction</button>
                    </div>
                    <div class="line total"><span>Total Deductions</span><span>${formatMoney(totalDed)}</span></div>
                </div>
            </div>

            <div class="payslip-netpay">
                <span>NET PAY</span>
                <span>${formatMoney(net)}</span>
            </div>

            <div class="payslip-er-block">
                <div class="label">Employer Contributions (for remittance, not deducted)</div>
                <div class="er-line"><span>SSS (Employer)</span><span>${formatMoney(sssEr)}</span></div>
                <div class="er-line"><span>PhilHealth (Employer)</span><span>${formatMoney(phEr)}</span></div>
                <div class="er-line"><span>Pag-IBIG (Employer)</span><span>${formatMoney(piEr)}</span></div>
            </div>
        </div>
    `;

    // Wire up buttons
    modalBody.querySelectorAll("[data-add-allowance]").forEach((btn) => {
        btn.addEventListener("click", () => promptAdd("allowance"));
    });
    modalBody.querySelectorAll("[data-add-deduction]").forEach((btn) => {
        btn.addEventListener("click", () => promptAdd("deduction"));
    });
    modalBody.querySelectorAll("[data-remove-allowance]").forEach((btn) => {
        btn.addEventListener("click", () => removeLine("allowance", btn.dataset.removeAllowance));
    });
    modalBody.querySelectorAll("[data-remove-deduction]").forEach((btn) => {
        btn.addEventListener("click", () => removeLine("deduction", btn.dataset.removeDeduction));
    });
}

async function promptAdd(kind) {
    const label = kind === "allowance" ? "Allowance" : "Deduction";
    const type = window.prompt(`Enter ${label} type (e.g. Meal, Loan Payment):`);
    if (!type) return;
    const amountStr = window.prompt(`Enter ${label} amount (₱):`);
    if (!amountStr) return;
    const amount = parseFloat(amountStr);
    if (isNaN(amount) || amount <= 0) {
        alert("Amount must be a positive number.");
        return;
    }

    try {
        if (kind === "allowance") {
            await api.post("allowances.php", {
                payslip_id: currentPayslipId,
                allowance_type: type,
                amount: amount,
            });
        } else {
            await api.post("deductions.php", {
                payslip_id: currentPayslipId,
                deduction_type: type,
                amount: amount,
            });
        }
        await refreshPayslip();
    } catch (err) {
        alert("Failed: " + apiErrorMessage(err));
    }
}

async function removeLine(kind, id) {
    const label = kind === "allowance" ? "allowance" : "deduction";
    if (!confirm(`Remove this ${label}?`)) return;
    try {
        if (kind === "allowance") {
            await api.delete("allowances.php", { params: { id } });
        } else {
            await api.delete("deductions.php", { params: { id } });
        }
        await refreshPayslip();
    } catch (err) {
        alert("Failed: " + apiErrorMessage(err));
    }
}

document.getElementById("modal-close").addEventListener("click", closeModal);
document.getElementById("modal-done").addEventListener("click", closeModal);
document.getElementById("modal-print").addEventListener("click", () => window.print());
modal.addEventListener("click", (e) => {
    if (e.target === modal) closeModal();
});
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !modal.classList.contains("hidden")) closeModal();
});

document.addEventListener("DOMContentLoaded", loadRows);