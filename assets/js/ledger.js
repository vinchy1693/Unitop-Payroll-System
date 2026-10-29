// =====================================================================
// assets/js/ledger.js
// =====================================================================

const form       = document.getElementById("filter-form");
const resultCard = document.getElementById("result-card");
const output     = document.getElementById("ledger-output");
const printBtn   = document.getElementById("print-btn");

async function loadEmployees() {
    const select = document.getElementById("employee_id");
    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        select.innerHTML = `<option value="">-- Select an employee --</option>` +
            data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
    } catch (err) {
        showMessage("Could not load employees: " + apiErrorMessage(err), "error");
    }
}

function ledgerPeriodRange() {
    const month = document.getElementById("ledger_month").value;
    const half = document.getElementById("ledger_period").value;
    if (!/^\d{4}-\d{2}$/.test(month)) return null;
    const [year, monthNo] = month.split("-").map(Number);
    const last = new Date(Date.UTC(year, monthNo, 0)).getUTCDate();
    return {
        from: half === "first" ? `${month}-01` : `${month}-16`,
        to: half === "first" ? `${month}-15` : `${month}-${String(last).padStart(2, "0")}`,
    };
}

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const employeeId = document.getElementById("employee_id").value;
    const range = ledgerPeriodRange();
    if (!employeeId || !range) return;
    const { from, to } = range;

    resultCard.classList.remove("hidden");
    output.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("ledger.php", {
            params: { employee_id: employeeId, from, to },
        });
        renderLedger(data.data);
        printBtn.disabled = false;
    } catch (err) {
        output.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
});

function renderLedger(payload) {
    const { employee, from, to, balances, ledger, totals } = payload;

    const balanceChips = balances.length
        ? balances.map((b) => `
            <div class="ledger-balance">
                <span class="ledger-balance-label">${escapeHtml(b.name)}</span>
                <span class="ledger-balance-value">${Number(b.remaining_days).toFixed(2)} <small>/ ${Number(b.days_per_year).toFixed(2)} days</small></span>
            </div>
        `).join("")
        : `<div style="color:#888;font-size:12.5px;">No leave types configured.</div>`;

    const rows = ledger.length
        ? ledger.map((r) => {
            let statusCell = "";
            let rowStyle = "";

            switch (r.event) {
                case "Weekend":
                    rowStyle = 'style="opacity:0.45;"';
                    statusCell = `<span style="color:#9ca3af;font-style:italic;font-size:12px;">Weekend</span>`;
                    break;
                case "Present":
                    statusCell = `<span class="badge active">Present</span>`;
                    break;
                case "Late":
                    statusCell = `<span class="badge" style="background:#fef3c7;color:#92400e;">Late ${r.late}m</span>`;
                    break;
                case "Leave":
                    if (r.status === "Approved") {
                        statusCell = `<span class="badge" style="background:#dbeafe;color:#1e40af;">Leave</span>`;
                    } else {
                        statusCell = `<span class="badge" style="background:#fef3c7;color:#92400e;">${escapeHtml(r.status)}</span>`;
                    }
                    break;
                case "Absent":
                    statusCell = `<span class="badge inactive">Absent</span>`;
                    break;
                default:
                    statusCell = `<span class="badge inactive">${escapeHtml(r.status ?? "—")}</span>`;
            }

            const typeCell = r.leave_type ? escapeHtml(r.leave_type) : "—";
            const hoursCell = r.hours !== null && r.hours !== undefined ? Number(r.hours).toFixed(2) : "—";

            return `
                <tr ${rowStyle}>
                    <td>${escapeHtml(r.date)}</td>
                    <td class="col-center" style="font-size:12px;color:#888;">${escapeHtml(r.day_label)}</td>
                    <td>${escapeHtml(r.detail)}</td>
                    <td>${typeCell}</td>
                    <td style="text-align:right;">${hoursCell}</td>
                    <td class="col-center">${statusCell}</td>
                </tr>
            `;
        }).join("")
        : `<tr class="empty-row"><td colspan="6">No records in this date range.</td></tr>`;

    output.innerHTML = `
        <div class="report-doc">
            <div class="report-header">
                <div>
                    <h2>UNITOP</h2>
                    <div class="report-sub">Attendance / Leave Ledger</div>
                </div>
                <div class="report-meta">
                    <div><strong>Employee:</strong> ${escapeHtml(employee.employee_name)}</div>
                    <div><strong>Emp No.:</strong> ${escapeHtml(employee.employee_number)}</div>
                    <div><strong>Department:</strong> ${escapeHtml(employee.department_name ?? "—")}</div>
                    <div><strong>Position:</strong> ${escapeHtml(employee.position_title ?? "—")}</div>
                    <div><strong>Coverage:</strong> ${escapeHtml(from)} → ${escapeHtml(to)}</div>
                </div>
            </div>

            <div class="ledger-balances">
                ${balanceChips}
            </div>

            <div class="ledger-stats">
                <div class="ledger-stat"><span>Present</span><strong style="color:#1e7d32;">${totals.present}</strong></div>
                <div class="ledger-stat"><span>Late Days</span><strong style="color:#92400e;">${totals.late}</strong></div>
                <div class="ledger-stat"><span>Absences</span><strong style="color:#b3261e;">${totals.absent}</strong></div>
                <div class="ledger-stat"><span>Leave Days</span><strong style="color:#1e40af;">${totals.leave}</strong></div>
                <div class="ledger-stat"><span>Late Minutes</span><strong>${totals.late_minutes}</strong></div>
                <div class="ledger-stat"><span>Total Hours</span><strong>${Number(totals.total_hours).toFixed(2)}</strong></div>
            </div>

            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="col-center">Day</th>
                            <th>Details</th>
                            <th>Leave Type</th>
                            <th style="text-align:right;">Hours</th>
                            <th class="col-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td colspan="2" style="text-align:right;font-weight:700;">
                                TOTALS (${totals.working_days} working day${totals.working_days === 1 ? "" : "s"})
                            </td>
                            <td colspan="2" style="font-size:12px;color:#666;">
                                Present: ${totals.present} · Late: ${totals.late} · Absent: ${totals.absent} · Leave: ${totals.leave}
                            </td>
                            <td style="text-align:right;font-weight:700;">${Number(totals.total_hours).toFixed(2)}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="report-footer">
                <div>
                    <div class="signature-line">Prepared by</div>
                    <div class="signature-label">HR Staff</div>
                </div>
                <div>
                    <div class="signature-line">Acknowledged by</div>
                    <div class="signature-label">Employee</div>
                </div>
            </div>
        </div>
    `;
}

printBtn.addEventListener("click", () => {
    document.body.dataset.printTarget = "ledger";
    window.print();
});
window.addEventListener("afterprint", () => { delete document.body.dataset.printTarget; });

document.addEventListener("DOMContentLoaded", () => {
    const now = new Date();
    document.getElementById("ledger_month").value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
    document.getElementById("ledger_period").value = "first";
    loadEmployees();
});