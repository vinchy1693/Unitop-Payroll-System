// =====================================================================
// assets/js/ledger.js
// =====================================================================

const form       = document.getElementById("filter-form");
const resultCard = document.getElementById("result-card");
const output     = document.getElementById("ledger-output");
const printBtn   = document.getElementById("print-btn");

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

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

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const employeeId = document.getElementById("employee_id").value;
    const from       = document.getElementById("from_date").value;
    const to         = document.getElementById("to_date").value;
    if (!employeeId || !from || !to) return;

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
        : `<div style="color:#888;font-size:12.5px;">No paid leave types configured.</div>`;

    const rows = ledger.length
        ? ledger.map((r) => {
            let statusBadge = "";
            if (r.event === "Attendance") {
                if ((r.late || 0) > 0) {
                    statusBadge = `<span class="badge" style="background:#fef3c7;color:#92400e;">Late ${r.late}m</span>`;
                } else {
                    statusBadge = `<span class="badge active">Present</span>`;
                }
            } else {
                const cls = r.status === "Approved" ? "active" : r.status === "Pending" ? "" : "inactive";
                const style = r.status === "Pending" ? `style="background:#fef3c7;color:#92400e;"` : "";
                statusBadge = `<span class="badge ${cls}" ${style}>${escapeHtml(r.status)}</span>`;
            }

            const typeCell = r.event === "Leave"
                ? escapeHtml(r.leave_type)
                : "—";

            return `
                <tr>
                    <td>${escapeHtml(r.date)}</td>
                    <td class="col-center"><span class="badge ${r.event === "Attendance" ? "active" : "inactive"}">${escapeHtml(r.event)}</span></td>
                    <td>${escapeHtml(r.detail)}</td>
                    <td>${typeCell}</td>
                    <td style="text-align:right;">${r.hours !== null ? Number(r.hours).toFixed(2) : "—"}</td>
                    <td class="col-center">${statusBadge}</td>
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
                <div class="ledger-stat"><span>Days Present</span><strong>${totals.days_present}</strong></div>
                <div class="ledger-stat"><span>Total Hours</span><strong>${Number(totals.total_hours).toFixed(2)}</strong></div>
                <div class="ledger-stat"><span>Days Late</span><strong>${totals.days_late}</strong></div>
                <div class="ledger-stat"><span>Total Late Min.</span><strong>${totals.total_late_minutes}</strong></div>
                <div class="ledger-stat"><span>Leave Days Used</span><strong>${totals.leave_days}</strong></div>
            </div>

            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="col-center">Event</th>
                            <th>Details</th>
                            <th>Leave Type</th>
                            <th style="text-align:right;">Hours</th>
                            <th class="col-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
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
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    document.getElementById("from_date").value = `${y}-${m}-01`;
    const last = new Date(y, now.getMonth() + 1, 0).getDate();
    document.getElementById("to_date").value = `${y}-${m}-${String(last).padStart(2, "0")}`;
    loadEmployees();
});