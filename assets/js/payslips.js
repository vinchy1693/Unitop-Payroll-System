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

function amountInWords(amount) {
    const ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine",
                  "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen",
                  "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
    const tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
    const scales = ["", "Thousand", "Million", "Billion"];

    const abs = Math.abs(Number(amount) || 0);
    const whole = Math.floor(abs);
    const cents = Math.round((abs - whole) * 100);

    function underThousand(n) {
        const parts = [];
        if (n >= 100) {
            parts.push(ones[Math.floor(n / 100)] + " Hundred");
            n %= 100;
        }
        if (n >= 20) {
            parts.push(tens[Math.floor(n / 10)] + (n % 10 ? "-" + ones[n % 10] : ""));
        } else if (n > 0) {
            parts.push(ones[n]);
        }
        return parts.join(" ");
    }

    function words(n) {
        if (n === 0) return "Zero";
        const parts = [];
        let scale = 0;
        while (n > 0) {
            const chunk = n % 1000;
            if (chunk) parts.unshift(underThousand(chunk) + (scales[scale] ? " " + scales[scale] : ""));
            n = Math.floor(n / 1000);
            scale++;
        }
        return parts.join(" ");
    }

    return `${words(whole)} Peso${whole === 1 ? "" : "s"} and ${String(cents).padStart(2, "0")}/100`;
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

    const allowances = p.allowances || [];
    const deductions = p.deductions || [];
    const attendanceDeductions = deductions.filter((d) => d.is_attendance);
    const otherDeductions = deductions.filter((d) => !d.is_attendance);

    // gross_pay stores gross earnings, and total_deductions includes
    // attendance, so the printed totals reconcile directly.
    const grossEarnings = Math.max(0, gross);
    const displayTotalDed = Math.max(0, totalDed);

    const holidayPremiums = Array.isArray(p.holiday_premiums) ? p.holiday_premiums : [];
    const holidayNote = holidayPremiums.map((h) => {
        const type = h.holiday_type === "Regular"
            ? "Regular Holiday"
            : h.holiday_type === "Special"
                ? "Special Holiday"
                : "Holiday";
        return `${escapeHtml(type)} (${Number(h.percentage || 0)}%)`;
    }).join("<br>");
    const premiumLabel = `Premium Pay${holidayNote ? `<span class="payslip-premium-note">${holidayNote}</span>` : ""}`;

    const earningsLines = [
        { label: "Basic Pay", amount: basic },
        { label: "Overtime Pay", amount: ot },
        { label: "Premium Pay", amount: premium, labelHtml: premiumLabel },
        ...allowances.map((a) => ({
            label: a.allowance_type,
            amount: a.amount,
            remove: `<button class="line-remove" data-remove-allowance="${a.allowance_id}" title="Remove">&times;</button>`,
        })),
    ];

    const deductionLines = [
        { label: "SSS (Employee)", amount: sss },
        { label: "PhilHealth (Employee)", amount: ph },
        { label: "Pag-IBIG (Employee)", amount: pi },
        { label: "Withholding Tax", amount: tax },
        ...attendanceDeductions.map((d) => ({
            label: d.deduction_type,
            amount: d.amount,
        })),
        ...otherDeductions.map((d) => ({
            label: d.deduction_type,
            amount: d.amount,
            remove: `<button class="line-remove" data-remove-deduction="${d.deduction_id}" title="Remove">&times;</button>`,
        })),
    ];

    const rowCount = Math.max(earningsLines.length, deductionLines.length);
    const tableRows = Array.from({ length: rowCount }, (_, i) => {
        const earn = earningsLines[i];
        const ded  = deductionLines[i];
        return `
            <tr>
                <td>${earn ? (earn.labelHtml || escapeHtml(earn.label)) + (earn.remove || "") : ""}</td>
                <td class="amount">${earn ? formatMoney(earn.amount) : ""}</td>
                <td>${ded ? escapeHtml(ded.label) + (ded.remove || "") : ""}</td>
                <td class="amount">${ded ? formatMoney(ded.amount) : ""}</td>
            </tr>
        `;
    }).join("");

    modalBody.innerHTML = `
        <div class="payslip-doc payslip-formal">
            <div class="payslip-letterhead">
                <h2>UNITOP</h2>
                <div class="payslip-company">Payroll System</div>
                <div class="payslip-title">Payslip</div>
            </div>

            <div class="payslip-info">
                <div><span>Date of Joining</span><strong>${escapeHtml(p.date_hired || "—")}</strong></div>
                <div><span>Employee Name</span><strong>${escapeHtml(p.employee_name)}</strong></div>
                <div><span>Pay Period</span><strong>${escapeHtml(p.start_date)} → ${escapeHtml(p.end_date)}</strong></div>
                <div><span>Designation</span><strong>${escapeHtml(p.position_title ?? "—")}</strong></div>
                <div><span>Worked Days</span><strong>${escapeHtml(String(p.worked_days ?? "—"))}</strong></div>
                <div><span>Department</span><strong>${escapeHtml(p.department_name ?? "—")}</strong></div>
                <div><span>Employee No.</span><strong>${escapeHtml(p.employee_number)}</strong></div>
                <div><span>Payslip No.</span><strong>${escapeHtml(p.payslip_id)}</strong></div>
            </div>

            <table class="payslip-table">
                <thead>
                    <tr>
                        <th>Earnings</th>
                        <th class="amount">Amount</th>
                        <th>Deductions</th>
                        <th class="amount">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ${tableRows}
                    <tr class="payslip-table-total">
                        <td>Total Earnings</td>
                        <td class="amount">${formatMoney(grossEarnings)}</td>
                        <td>Total Deductions</td>
                        <td class="amount">${formatMoney(displayTotalDed)}</td>
                    </tr>
                    <tr class="payslip-table-net">
                        <td colspan="2"></td>
                        <td>Net Pay</td>
                        <td class="amount">${formatMoney(net)}</td>
                    </tr>
                </tbody>
            </table>

            <div class="payslip-words">
                <strong>${formatMoney(net)}</strong>
                <div>${escapeHtml(amountInWords(net))}</div>
            </div>

            <div class="payslip-signatures">
                <div><span>Employer Signature</span><div class="signature-line"></div></div>
                <div><span>Employee Signature</span><div class="signature-line"></div></div>
            </div>

            <div class="payslip-footer">This is a system-generated payslip.</div>
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