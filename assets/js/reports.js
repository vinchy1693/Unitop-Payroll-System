// =====================================================================
// assets/js/reports.js
// =====================================================================

const MONTH_NAMES = ["", "January","February","March","April","May","June",
                     "July","August","September","October","November","December"];

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

// ---------------------------------------------------------------------
// INIT
// ---------------------------------------------------------------------
async function loadRuns() {
    const targets = ["summary_run_id", "run_id"];
    try {
        const { data } = await api.get("payroll_runs.php");
        const runs = data.data;
        targets.forEach((id) => {
            const select = document.getElementById(id);
            if (!select) return;
            if (!runs.length) {
                select.innerHTML = `<option value="">-- No runs yet --</option>`;
                return;
            }
            select.innerHTML = `<option value="">-- Select a run --</option>` +
                runs.map((r) => `<option value="${r.run_id}">Run #${r.run_id} · ${escapeHtml(r.period_type)} · ${escapeHtml(r.start_date)} → ${escapeHtml(r.end_date)} (${r.payslip_count} payslips)</option>`).join("");
        });
    } catch (err) {
        showMessage("Could not load runs: " + apiErrorMessage(err), "error");
    }
}

async function loadEmployees() {
    const select = document.getElementById("history_employee_id");
    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        select.innerHTML = `<option value="">-- Select an employee --</option>` +
            data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
    } catch (err) {
        showMessage("Could not load employees: " + apiErrorMessage(err), "error");
    }
}

// =====================================================================
// PAYROLL SUMMARY
// =====================================================================
document.getElementById("summary-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const runId = document.getElementById("summary_run_id").value;
    if (!runId) return;

    const card = document.getElementById("summary-card");
    const out  = document.getElementById("summary-output");
    card.classList.remove("hidden");
    out.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("reports.php", {
            params: { report: "payroll_summary", run_id: runId },
        });
        renderSummary(data.data);
        document.getElementById("print-summary").disabled = false;
    } catch (err) {
        out.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
});

function renderSummary(payload) {
    const { run, totals, employees } = payload;
    const out = document.getElementById("summary-output");

    const eeDeductionsTotal = totals.sss_ee + totals.philhealth_ee + totals.pagibig_ee + totals.withholding_tax;
    const erTotal = totals.sss_er + totals.philhealth_er + totals.pagibig_er;

    const employeeRows = employees.map((e) => `
        <tr>
            <td>${escapeHtml(e.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(e.employee_number)} · ${escapeHtml(e.department_name ?? "—")}</span></td>
            <td style="text-align:right;font-weight:600;">${formatMoney(e.net_pay)}</td>
        </tr>
    `).join("");

    out.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div>
                    <h2>UNITOP</h2>
                    <div class="report-sub">Payroll Summary</div>
                </div>
                <div class="report-meta">
                    <div><strong>Run #:</strong> ${run.run_id}</div>
                    <div><strong>Type:</strong> ${escapeHtml(run.period_type)}</div>
                    <div><strong>Period:</strong> ${escapeHtml(run.start_date)} → ${escapeHtml(run.end_date)}</div>
                    <div><strong>Run Date:</strong> ${escapeHtml(run.run_date)}</div>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-tile primary">
                    <div class="summary-tile-label">Total Gross Pay</div>
                    <div class="summary-tile-value">${formatMoney(totals.total_gross)}</div>
                </div>
                <div class="summary-tile">
                    <div class="summary-tile-label">Total Deductions</div>
                    <div class="summary-tile-value">${formatMoney(totals.total_deductions)}</div>
                </div>
                <div class="summary-tile success">
                    <div class="summary-tile-label">Total Net Pay</div>
                    <div class="summary-tile-value">${formatMoney(totals.total_net)}</div>
                </div>
                <div class="summary-tile">
                    <div class="summary-tile-label">Employees Paid</div>
                    <div class="summary-tile-value">${totals.headcount}</div>
                </div>
            </div>

            <div class="summary-sections">
                <div class="summary-section">
                    <h4>Employee Deductions</h4>
                    <div class="line"><span>SSS (Employee)</span><span>${formatMoney(totals.sss_ee)}</span></div>
                    <div class="line"><span>PhilHealth (Employee)</span><span>${formatMoney(totals.philhealth_ee)}</span></div>
                    <div class="line"><span>Pag-IBIG (Employee)</span><span>${formatMoney(totals.pagibig_ee)}</span></div>
                    <div class="line"><span>Withholding Tax</span><span>${formatMoney(totals.withholding_tax)}</span></div>
                    <div class="line total"><span>Subtotal</span><span>${formatMoney(eeDeductionsTotal)}</span></div>
                </div>

                <div class="summary-section">
                    <h4>Employer Contributions <small style="font-weight:400;color:#888;">(for remittance)</small></h4>
                    <div class="line"><span>SSS (Employer)</span><span>${formatMoney(totals.sss_er)}</span></div>
                    <div class="line"><span>PhilHealth (Employer)</span><span>${formatMoney(totals.philhealth_er)}</span></div>
                    <div class="line"><span>Pag-IBIG (Employer)</span><span>${formatMoney(totals.pagibig_er)}</span></div>
                    <div class="line total"><span>Subtotal</span><span>${formatMoney(erTotal)}</span></div>
                </div>
            </div>

            <h4 style="margin:22px 0 8px;font-size:13px;color:#2f5496;text-transform:uppercase;letter-spacing:0.5px;">Employee Net Pay</h4>
            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr><th>Employee</th><th style="text-align:right;">Net Pay</th></tr>
                    </thead>
                    <tbody>${employeeRows}</tbody>
                </table>
            </div>

            <p style="font-size:12px;color:#666;margin-top:14px;">
                Employer cost for this run: <strong>${formatMoney(totals.total_gross + erTotal)}</strong>
                (gross pay + employer contributions).
            </p>

            ${signatureBlock("Prepared by", "Payroll Officer", "Approved by", "Finance Manager")}
        </div>
    `;
}

// =====================================================================
// PAYROLL REGISTER
// =====================================================================
document.getElementById("register-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const runId = document.getElementById("run_id").value;
    if (!runId) return;

    const card = document.getElementById("register-card");
    const out  = document.getElementById("register-output");
    card.classList.remove("hidden");
    out.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("reports.php", {
            params: { report: "payroll_register", run_id: runId },
        });
        renderRegister(data.data);
        document.getElementById("print-register").disabled = false;
    } catch (err) {
        out.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
});

function renderRegister(payload) {
    const { run, payslips, totals } = payload;
    const out = document.getElementById("register-output");

    if (!payslips.length) {
        out.innerHTML = `<div style="padding:20px;color:#888;">No payslips in this run.</div>`;
        return;
    }

    const rows = payslips.map((p) => `
        <tr>
            <td>${escapeHtml(p.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(p.employee_number)}</span></td>
            <td>${escapeHtml(p.department_name ?? "—")}</td>
            <td style="text-align:right;">${formatMoney(p.basic_pay)}</td>
            <td style="text-align:right;">${formatMoney(p.overtime_pay)}</td>
            <td style="text-align:right;">${formatMoney(p.allowance_total)}</td>
            <td style="text-align:right;font-weight:600;">${formatMoney(p.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(p.sss_ee)}</td>
            <td style="text-align:right;">${formatMoney(p.philhealth_ee)}</td>
            <td style="text-align:right;">${formatMoney(p.pagibig_ee)}</td>
            <td style="text-align:right;">${formatMoney(p.withholding_tax)}</td>
            <td style="text-align:right;">${formatMoney(p.other_deductions)}</td>
            <td style="text-align:right;font-weight:600;">${formatMoney(p.total_deductions)}</td>
            <td style="text-align:right;font-weight:700;">${formatMoney(p.net_pay)}</td>
        </tr>
    `).join("");

    out.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div>
                    <h2>UNITOP</h2>
                    <div class="report-sub">Payroll Register</div>
                </div>
                <div class="report-meta">
                    <div><strong>Run #:</strong> ${run.run_id}</div>
                    <div><strong>Type:</strong> ${escapeHtml(run.period_type)}</div>
                    <div><strong>Period:</strong> ${escapeHtml(run.start_date)} → ${escapeHtml(run.end_date)}</div>
                    <div><strong>Run Date:</strong> ${escapeHtml(run.run_date)}</div>
                </div>
            </div>
            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th rowspan="2">Employee</th>
                            <th rowspan="2">Dept</th>
                            <th colspan="4" class="grp-earn">Earnings</th>
                            <th colspan="6" class="grp-ded">Deductions</th>
                            <th rowspan="2" style="text-align:right;">Net Pay</th>
                        </tr>
                        <tr>
                            <th style="text-align:right;">Basic</th>
                            <th style="text-align:right;">OT</th>
                            <th style="text-align:right;">Allow.</th>
                            <th style="text-align:right;">Gross</th>
                            <th style="text-align:right;">SSS</th>
                            <th style="text-align:right;">PH</th>
                            <th style="text-align:right;">PI</th>
                            <th style="text-align:right;">Tax</th>
                            <th style="text-align:right;">Other</th>
                            <th style="text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td colspan="2" style="text-align:right;font-weight:700;">TOTALS</td>
                            <td style="text-align:right;">${formatMoney(totals.basic_pay)}</td>
                            <td style="text-align:right;">${formatMoney(totals.overtime_pay)}</td>
                            <td style="text-align:right;">${formatMoney(totals.allowance_total)}</td>
                            <td style="text-align:right;font-weight:700;">${formatMoney(totals.gross_pay)}</td>
                            <td style="text-align:right;">${formatMoney(totals.sss_ee)}</td>
                            <td style="text-align:right;">${formatMoney(totals.philhealth_ee)}</td>
                            <td style="text-align:right;">${formatMoney(totals.pagibig_ee)}</td>
                            <td style="text-align:right;">${formatMoney(totals.withholding_tax)}</td>
                            <td style="text-align:right;">${formatMoney(totals.other_deductions)}</td>
                            <td style="text-align:right;font-weight:700;">${formatMoney(totals.total_deductions)}</td>
                            <td style="text-align:right;font-weight:700;">${formatMoney(totals.net_pay)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            ${signatureBlock("Prepared by", "Payroll Officer", "Approved by", "Manager")}
        </div>
    `;
}

// =====================================================================
// GOVERNMENT REMITTANCE
// =====================================================================
document.getElementById("remittance-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const year  = parseInt(document.getElementById("year").value, 10);
    const month = parseInt(document.getElementById("month").value, 10);
    if (!year || !month) return;

    const card = document.getElementById("remittance-card");
    const out  = document.getElementById("remittance-output");
    card.classList.remove("hidden");
    out.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("reports.php", {
            params: { report: "remittance", year, month },
        });
        renderRemittance(data.data);
        document.getElementById("print-remittance").disabled = false;
    } catch (err) {
        out.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
});

function renderRemittance(payload) {
    const { year, month, start, end, totals } = payload;
    const out = document.getElementById("remittance-output");

    const sssTotal     = totals.sss_ee + totals.sss_er;
    const phTotal      = totals.philhealth_ee + totals.philhealth_er;
    const piTotal      = totals.pagibig_ee + totals.pagibig_er;
    const contribTotal = sssTotal + phTotal + piTotal;
    const grandTotal   = contribTotal + totals.withholding_tax;

    out.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div>
                    <h2>UNITOP</h2>
                    <div class="report-sub">Government Remittance Summary</div>
                </div>
                <div class="report-meta">
                    <div><strong>Month:</strong> ${MONTH_NAMES[month]} ${year}</div>
                    <div><strong>Coverage:</strong> ${start} → ${end}</div>
                    <div><strong>Payslips counted:</strong> ${totals.payslip_count}</div>
                </div>
            </div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Agency</th>
                        <th style="text-align:right;">Employee Share</th>
                        <th style="text-align:right;">Employer Share</th>
                        <th style="text-align:right;">Total to Remit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>SSS</td><td style="text-align:right;">${formatMoney(totals.sss_ee)}</td><td style="text-align:right;">${formatMoney(totals.sss_er)}</td><td style="text-align:right;">${formatMoney(sssTotal)}</td></tr>
                    <tr><td>PhilHealth</td><td style="text-align:right;">${formatMoney(totals.philhealth_ee)}</td><td style="text-align:right;">${formatMoney(totals.philhealth_er)}</td><td style="text-align:right;">${formatMoney(phTotal)}</td></tr>
                    <tr><td>Pag-IBIG</td><td style="text-align:right;">${formatMoney(totals.pagibig_ee)}</td><td style="text-align:right;">${formatMoney(totals.pagibig_er)}</td><td style="text-align:right;">${formatMoney(piTotal)}</td></tr>
                    <tr class="totals-row"><td style="font-weight:700;">Contributions Subtotal</td><td style="text-align:right;">${formatMoney(totals.sss_ee + totals.philhealth_ee + totals.pagibig_ee)}</td><td style="text-align:right;">${formatMoney(totals.sss_er + totals.philhealth_er + totals.pagibig_er)}</td><td style="text-align:right;font-weight:700;">${formatMoney(contribTotal)}</td></tr>
                    <tr><td>BIR Withholding Tax</td><td style="text-align:right;">${formatMoney(totals.withholding_tax)}</td><td style="text-align:right;">—</td><td style="text-align:right;">${formatMoney(totals.withholding_tax)}</td></tr>
                    <tr class="grand-total-row"><td style="font-weight:700;">GRAND TOTAL</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.sss_ee + totals.philhealth_ee + totals.pagibig_ee + totals.withholding_tax)}</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.sss_er + totals.philhealth_er + totals.pagibig_er)}</td><td style="text-align:right;font-weight:700;">${formatMoney(grandTotal)}</td></tr>
                </tbody>
            </table>
            <p style="font-size:12px;color:#666;margin-top:14px;">Note: 13th Month runs are excluded. Figures are cumulative across all regular payroll runs within the month.</p>
            ${signatureBlock("Prepared by", "Payroll Officer", "Received by", "Authorized Representative")}
        </div>
    `;
}

// =====================================================================
// EMPLOYEE HISTORY
// =====================================================================
document.getElementById("history-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const empId = document.getElementById("history_employee_id").value;
    if (!empId) return;

    const card = document.getElementById("history-card");
    const out  = document.getElementById("history-output");
    card.classList.remove("hidden");
    out.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("reports.php", {
            params: { report: "employee_history", employee_id: empId },
        });
        renderHistory(data.data);
        document.getElementById("print-history").disabled = false;
    } catch (err) {
        out.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
});

function renderHistory(payload) {
    const { employee, payslips, totals } = payload;
    const out = document.getElementById("history-output");

    if (!payslips.length) {
        out.innerHTML = `<div class="report-doc"><div class="report-header"><div><h2>UNITOP</h2><div class="report-sub">Employee Payslip History</div></div><div class="report-meta"><div><strong>Employee:</strong> ${escapeHtml(employee.employee_name)}</div></div></div><div style="padding:20px;color:#888;">This employee has no payslips yet.</div></div>`;
        return;
    }

    const rows = payslips.map((p) => `
        <tr>
            <td>${escapeHtml(p.start_date)} → ${escapeHtml(p.end_date)}</td>
            <td>${escapeHtml(p.period_type)}</td>
            <td style="text-align:right;">${formatMoney(p.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(p.total_deductions)}</td>
            <td style="text-align:right;font-weight:600;">${formatMoney(p.net_pay)}</td>
        </tr>
    `).join("");

    out.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div><h2>UNITOP</h2><div class="report-sub">Employee Payslip History</div></div>
                <div class="report-meta">
                    <div><strong>Employee:</strong> ${escapeHtml(employee.employee_name)}</div>
                    <div><strong>Emp No.:</strong> ${escapeHtml(employee.employee_number)}</div>
                    <div><strong>Department:</strong> ${escapeHtml(employee.department_name ?? "—")}</div>
                    <div><strong>Position:</strong> ${escapeHtml(employee.position_title ?? "—")}</div>
                    <div><strong>Date Hired:</strong> ${escapeHtml(employee.date_hired ?? "—")}</div>
                </div>
            </div>
            <div class="table-scroll">
                <table class="report-table">
                    <thead><tr><th>Period</th><th>Type</th><th style="text-align:right;">Gross Pay</th><th style="text-align:right;">Total Deductions</th><th style="text-align:right;">Net Pay</th></tr></thead>
                    <tbody>${rows}</tbody>
                    <tfoot><tr class="totals-row"><td colspan="2" style="text-align:right;font-weight:700;">TOTALS (${payslips.length} payslip${payslips.length === 1 ? "" : "s"})</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.gross_pay)}</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.total_deductions)}</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.net_pay)}</td></tr></tfoot>
                </table>
            </div>
            ${signatureBlock("Prepared by", "Payroll Officer", "Acknowledged by", "Employee")}
        </div>
    `;
}

// =====================================================================
// DEPARTMENT COST
// =====================================================================
function reportPeriodRange() {
    const month = document.getElementById("dept_month").value;
    const half = document.getElementById("dept_period").value;
    if (!/^\d{4}-\d{2}$/.test(month)) return null;
    const [year, monthNo] = month.split("-").map(Number);
    const last = new Date(Date.UTC(year, monthNo, 0)).getUTCDate();
    return {
        from: half === "first" ? `${month}-01` : `${month}-16`,
        to: half === "first" ? `${month}-15` : `${month}-${String(last).padStart(2, "0")}`,
    };
}

document.getElementById("deptcost-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const range = reportPeriodRange();
    if (!range) return;
    const { from, to } = range;

    const card = document.getElementById("deptcost-card");
    const out  = document.getElementById("deptcost-output");
    card.classList.remove("hidden");
    out.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("reports.php", {
            params: { report: "department_summary", from, to },
        });
        renderDeptCost(data.data);
        document.getElementById("print-deptcost").disabled = false;
    } catch (err) {
        out.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
});

function renderDeptCost(payload) {
    const { from, to, rows, totals } = payload;
    const out = document.getElementById("deptcost-output");

    if (!rows.length) {
        out.innerHTML = `<div class="report-doc"><div class="report-header"><div><h2>UNITOP</h2><div class="report-sub">Department Cost Summary</div></div><div class="report-meta"><div><strong>Coverage:</strong> ${escapeHtml(from)} → ${escapeHtml(to)}</div></div></div><div style="padding:20px;color:#888;">No payslips in this range.</div></div>`;
        return;
    }

    const bodyRows = rows.map((r) => `
        <tr>
            <td>${escapeHtml(r.department_name)}</td>
            <td style="text-align:center;">${r.headcount}</td>
            <td style="text-align:right;">${formatMoney(r.total_gross)}</td>
            <td style="text-align:right;">${formatMoney(r.total_net)}</td>
            <td style="text-align:right;">${formatMoney(r.sss_er)}</td>
            <td style="text-align:right;">${formatMoney(r.philhealth_er)}</td>
            <td style="text-align:right;">${formatMoney(r.pagibig_er)}</td>
            <td style="text-align:right;font-weight:600;">${formatMoney(r.employer_cost)}</td>
        </tr>
    `).join("");

    out.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div><h2>UNITOP</h2><div class="report-sub">Department Cost Summary</div></div>
                <div class="report-meta"><div><strong>Coverage:</strong> ${escapeHtml(from)} → ${escapeHtml(to)}</div><div><strong>Departments:</strong> ${rows.length}</div></div>
            </div>
            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr><th rowspan="2">Department</th><th rowspan="2" style="text-align:center;">Headcount</th><th rowspan="2" style="text-align:right;">Total Gross</th><th rowspan="2" style="text-align:right;">Total Net</th><th colspan="3" class="grp-ded">Employer Contributions</th><th rowspan="2" style="text-align:right;">Employer Cost</th></tr>
                        <tr><th style="text-align:right;">SSS</th><th style="text-align:right;">PH</th><th style="text-align:right;">PI</th></tr>
                    </thead>
                    <tbody>${bodyRows}</tbody>
                    <tfoot><tr class="totals-row"><td style="font-weight:700;">TOTALS</td><td style="text-align:center;">${totals.headcount}</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.total_gross)}</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.total_net)}</td><td style="text-align:right;">${formatMoney(totals.sss_er)}</td><td style="text-align:right;">${formatMoney(totals.philhealth_er)}</td><td style="text-align:right;">${formatMoney(totals.pagibig_er)}</td><td style="text-align:right;font-weight:700;">${formatMoney(totals.employer_cost)}</td></tr></tfoot>
                </table>
            </div>
            <p style="font-size:12px;color:#666;margin-top:14px;"><strong>Employer Cost</strong> = Total Gross Pay + SSS ER + PhilHealth ER + Pag-IBIG ER.</p>
            ${signatureBlock("Prepared by", "Payroll Officer", "Reviewed by", "Finance Manager")}
        </div>
    `;
}

// ---------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------
function signatureBlock(leftLabel, leftTitle, rightLabel, rightTitle) {
    return `
        <div class="report-footer">
            <div><div class="signature-line">${escapeHtml(leftLabel)}</div><div class="signature-label">${escapeHtml(leftTitle)}</div></div>
            <div><div class="signature-line">${escapeHtml(rightLabel)}</div><div class="signature-label">${escapeHtml(rightTitle)}</div></div>
        </div>
    `;
}

// ---------------------------------------------------------------------
// Print buttons
// ---------------------------------------------------------------------
["summary","register","remittance","history","deptcost"].forEach((name) => {
    const btn = document.getElementById(`print-${name}`);
    if (btn) {
        btn.addEventListener("click", () => {
            document.body.dataset.printTarget = name;
            window.print();
        });
    }
});
window.addEventListener("afterprint", () => { delete document.body.dataset.printTarget; });

// ---------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------
document.addEventListener("DOMContentLoaded", () => {
    const now = new Date();
    document.getElementById("year").value  = now.getFullYear();
    document.getElementById("month").value = now.getMonth() + 1;

    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    document.getElementById("dept_month").value = `${y}-${m}`;
    document.getElementById("dept_period").value = "first";

    loadRuns();
    loadEmployees();
});