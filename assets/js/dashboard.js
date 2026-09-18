// =====================================================================
// assets/js/dashboard.js
// =====================================================================

async function loadDashboard() {
    const grid = document.getElementById("dash-grid");

    try {
        const { data: body } = await api.get("dashboard.php");
        if (!body.success) throw new Error(body.message || "Dashboard request failed.");

        const { counts, latest_run } = body.data;
        renderWorkflow(counts, latest_run);
    } catch (err) {
        grid.innerHTML = `
            <div class="workflow-card">
                <div class="workflow-title" style="color:#b3261e;">Failed to load</div>
                <p style="color:#888;font-size:13px;margin:6px 0 0;">${escapeHtml(apiErrorMessage(err))}</p>
            </div>`;
    }
}

function renderWorkflow(c, latestRun) {
    const grid = document.getElementById("dash-grid");

    // Step 1: Setup
    const setupComplete = c.departments > 0 && c.positions > 0 && c.employees > 0 && c.with_salary > 0;
    const setupStatus   = setupComplete ? "ok" : "warn";
    const setupNote     = setupComplete
        ? `${c.employees} employee${c.employees === 1 ? "" : "s"} · ${c.with_salary} with basic salary`
        : "Some setup is incomplete";

    // Step 2: Daily Logs
    const dailyStatus = c.pending_leaves > 0 ? "warn" : "ok";
    const dailyNote = c.pending_leaves > 0
        ? `${c.pending_leaves} pending leave request${c.pending_leaves === 1 ? "" : "s"} to review`
        : "No pending items";

    // Step 3: Run Payroll
    const hasOpen   = c.open_periods > 0;
    const runStatus = hasOpen ? "go" : (latestRun ? "ok" : "warn");
    const runNote   = hasOpen
        ? `${c.open_periods} Open period${c.open_periods === 1 ? "" : "s"} ready to process`
        : (latestRun
            ? `Last run: #${latestRun.run_id} · ${latestRun.payslip_count} payslip${latestRun.payslip_count === 1 ? "" : "s"}`
            : "Create a period to start");

    // Step 4: Reports
    const reportsReady  = !!latestRun;
    const reportsStatus = reportsReady ? "ok" : "warn";
    const reportsNote   = reportsReady
        ? `Latest run #${latestRun.run_id} available`
        : "Run payroll first";

    grid.innerHTML = `
        <div class="workflow-card status-${setupStatus}">
            <div class="workflow-step">STEP 1</div>
            <div class="workflow-title">Setup Master Data</div>
            <div class="workflow-note">${escapeHtml(setupNote)}</div>
            <div class="workflow-links">
                <a href="departments.html">Departments</a>
                <a href="positions.html">Positions</a>
                <a href="employees.html">Employees</a>
                <a href="leave_types.html">Leave Types</a>
                <a href="users.html">Users</a>
            </div>
        </div>

        <div class="workflow-card status-${dailyStatus}">
            <div class="workflow-step">STEP 2</div>
            <div class="workflow-title">Daily Logs</div>
            <div class="workflow-note">${escapeHtml(dailyNote)}</div>
            <div class="workflow-links">
                <a href="attendance.html">Attendance</a>
                <a href="leave_requests.html">Leave Requests</a>
                <a href="leave_balances.html">Leave Balances</a>
                <a href="overtime.html">Overtime</a>
                <a href="loans.html">Loans</a>
            </div>
        </div>

        <div class="workflow-card status-${runStatus}">
            <div class="workflow-step">STEP 3</div>
            <div class="workflow-title">Run Payroll</div>
            <div class="workflow-note">${escapeHtml(runNote)}</div>
            <div class="workflow-links">
                <a href="payroll_periods.html">Payroll Periods</a>
                <a href="payroll_runs.html">Payroll Runs</a>
                ${latestRun ? `<a href="payslips.html?run_id=${latestRun.run_id}">Latest Payslips</a>` : ""}
            </div>
        </div>

        <div class="workflow-card status-${reportsStatus}">
            <div class="workflow-step">STEP 4</div>
            <div class="workflow-title">Reports</div>
            <div class="workflow-note">${escapeHtml(reportsNote)}</div>
            <div class="workflow-links">
                <a href="reports.html">Payroll Summary</a>
                <a href="reports.html">Register</a>
                <a href="reports.html">Remittance</a>
                <a href="ledger.html">Employee Ledger</a>
            </div>
        </div>
    `;
}

document.addEventListener("DOMContentLoaded", loadDashboard);