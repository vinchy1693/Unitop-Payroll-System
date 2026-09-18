// =====================================================================
// assets/js/payroll_runs.js
// =====================================================================

const form      = document.getElementById("run-form");
const submitBtn = document.getElementById("submit-btn");
const rowsEl    = document.getElementById("rows");
const countEl   = document.getElementById("record-count");

async function loadOpenPeriods() {
    const select = document.getElementById("period_id");
    try {
        const { data } = await api.get("payroll_periods.php");
        const open = data.data.filter((p) => p.status === "Open");
        if (open.length === 0) {
            select.innerHTML = `<option value="">-- No Open periods available --</option>`;
            submitBtn.disabled = true;
            return;
        }
        select.innerHTML = `<option value="">-- Select an Open period --</option>` +
            open.map((p) => `<option value="${p.period_id}">${escapeHtml(p.period_type)} · ${p.start_date} → ${p.end_date}</option>`).join("");
        submitBtn.disabled = false;
    } catch (err) {
        showMessage("Could not load periods: " + apiErrorMessage(err), "error");
    }
}

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="7">Loading…</td></tr>`;
    try {
        const { data } = await api.get("payroll_runs.php");
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} run(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `
            <tr class="empty-row">
                <td colspan="7" style="padding:40px 20px;">
                    <div style="font-size:15px;color:#444;font-weight:600;margin-bottom:8px;">
                        No payroll runs yet
                    </div>
                    <div style="font-size:13px;color:#888;margin-bottom:18px;">
                        To run payroll, you first need an Open period.
                    </div>
                    <a href="payroll_periods.html" class="btn btn-primary">Create a Payroll Period →</a>
                </td>
            </tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr>
            <td>#${r.run_id}</td>
            <td>${escapeHtml(r.period_type)}</td>
            <td>${escapeHtml(r.start_date)}</td>
            <td>${escapeHtml(r.end_date)}</td>
            <td>${escapeHtml(r.run_date)}</td>
            <td class="col-center">${r.payslip_count}</td>
            <td class="col-center">
                <a class="btn btn-primary" href="payslips.html?run_id=${r.run_id}">View Payslips</a>
            </td>
        </tr>
    `).join("");
}

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const periodId = document.getElementById("period_id").value;
    if (!periodId) return;

    if (!confirm("Run payroll for this period? This will generate payslips and lock the period.")) return;

    submitBtn.disabled = true;
    try {
        const { data } = await api.post("payroll_runs.php", { period_id: Number(periodId) });
        showMessage(data.message, "success");
        await Promise.all([loadRows(), loadOpenPeriods()]);
        const runId = data.data?.run_id;
        if (runId) {
            setTimeout(() => { window.location.href = `payslips.html?run_id=${runId}`; }, 700);
        }
    } catch (err) {
        showMessage(apiErrorMessage(err), "error");
    } finally {
        submitBtn.disabled = false;
    }
});

document.addEventListener("DOMContentLoaded", () => {
    loadOpenPeriods();
    loadRows();
});