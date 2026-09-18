// =====================================================================
// assets/js/leave_balances.js
// =====================================================================

const yearEl  = document.getElementById("year");
const gridEl  = document.getElementById("grid-wrap");
const countEl = document.getElementById("record-count");

async function loadGrid() {
    const year = parseInt(yearEl.value, 10);
    if (!year) return;

    gridEl.innerHTML = `<div style="text-align:center;padding:20px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("leave_balances.php", { params: { year } });
        renderGrid(data.data);
    } catch (err) {
        gridEl.innerHTML = `<div style="padding:20px;color:#b3261e;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
}

function renderGrid(payload) {
    const { year, types, employees } = payload;

    countEl.textContent = `${employees.length} employee(s)`;

    if (!types.length) {
        gridEl.innerHTML = `<div style="padding:20px;color:#888;">No active leave types.</div>`;
        return;
    }
    if (!employees.length) {
        gridEl.innerHTML = `<div style="padding:20px;color:#888;">No active employees.</div>`;
        return;
    }

    const header = `<tr><th>Employee</th>${types.map((t) => `<th class="col-center">${escapeHtml(t.name)}<br><span style="font-weight:400;font-size:11px;color:#888;">(${Number(t.days_per_year).toFixed(1)}/yr)</span></th>`).join("")}</tr>`;

    const rows = employees.map((emp) => `
        <tr>
            <td>${escapeHtml(emp.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(emp.employee_number)}</span></td>
            ${emp.balances.map((b) => `
                <td class="col-center">
                    <strong>${Number(b.remaining).toFixed(2)}</strong>
                    ${b.from_db ? "" : `<br><span style="font-size:10px;color:#999;">default</span>`}
                </td>
            `).join("")}
        </tr>
    `).join("");

    gridEl.innerHTML = `
        <div class="table-scroll">
            <table class="report-table">
                <thead>${header}</thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <p style="font-size:12px;color:#666;margin-top:14px;">
            Year: <strong>${year}</strong>. Rows showing "default" haven't been initialized yet —
            they'll be seeded when the employee's first leave is approved. Click <strong>Initialize Balances</strong> to create them all now.
        </p>
    `;
}

document.getElementById("reload-btn").addEventListener("click", loadGrid);

document.getElementById("init-btn").addEventListener("click", async () => {
    const year = parseInt(yearEl.value, 10);
    if (!year) return;
    if (!confirm(`Initialize balances for ${year}? This creates missing rows for all active employees and leave types.`)) return;
    try {
        const { data } = await api.post("leave_balances.php", { year });
        showMessage(data.message, "success");
        await loadGrid();
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    }
});

document.addEventListener("DOMContentLoaded", () => {
    yearEl.value = new Date().getFullYear();
    loadGrid();
});