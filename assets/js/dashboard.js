// =====================================================================
// assets/js/dashboard.js
// =====================================================================

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function formatMoneyShort(n) {
    const v = Number(n || 0);
    if (v >= 1000000) return "₱" + (v / 1000000).toFixed(2) + "M";
    if (v >= 1000)    return "₱" + (v / 1000).toFixed(1) + "k";
    return "₱" + v.toFixed(2);
}

async function loadDashboard() {
    try {
        const { data: body } = await api.get("dashboard.php");
        if (!body.success) throw new Error(body.message || "Dashboard request failed.");
        render(body.data);
    } catch (err) {
        document.getElementById("kpi-grid").innerHTML = `
            <div class="kpi-tile" style="grid-column: 1 / -1; border-left-color: var(--red);">
                <div class="kpi-label">Failed to load dashboard</div>
                <div class="kpi-value" style="font-size:14px;color:var(--red);font-weight:500;">
                    ${escapeHtml(apiErrorMessage(err))}
                </div>
            </div>`;
    }
}

// ---------------------------------------------------------------------
// Workflow card renderer
// ---------------------------------------------------------------------
function workflowCard(cfg) {
    const sublinksHtml = cfg.sublinks && cfg.sublinks.length
        ? cfg.sublinks.map((l, i) =>
            `${i > 0 ? '<span class="sep">·</span>' : ''}<a href="${l.href}">${escapeHtml(l.label)}</a>`
          ).join("")
        : "";

    return `
        <div class="workflow-card status-${cfg.status}">
            <div class="workflow-head">
                <div class="workflow-step-badge">${cfg.step}</div>
                <div class="workflow-status-pill">${escapeHtml(cfg.statusLabel)}</div>
            </div>
            <div class="workflow-title">${escapeHtml(cfg.title)}</div>
            <div class="workflow-note">${escapeHtml(cfg.note)}</div>
            ${sublinksHtml ? `<div class="workflow-sublinks">${sublinksHtml}</div>` : ""}
            <a class="workflow-cta" href="${cfg.ctaHref}">${escapeHtml(cfg.ctaLabel)} →</a>
        </div>
    `;
}

function render(data) {
    const { counts, latest_run, active_loans, today_attendance, recent_runs, today } = data;

    // =================================================================
    // Row 1 — KPI tiles
    // =================================================================
    const latestPayrollLabel = latest_run
        ? `Last Run #${latest_run.run_id} · ${formatMoney(latest_run.total_net)}`
        : "No payroll runs yet";
    const latestPayrollValue = latest_run
        ? formatMoneyShort(latest_run.total_net)
        : "—";

    const tiles = [
        {
            label: "Active Employees",
            value: counts.employees,
            note: `${counts.with_salary} with basic salary`,
            tone: "blue",
        },
        {
            label: "Latest Payroll (Net)",
            value: latestPayrollValue,
            note: latestPayrollLabel,
            tone: "green",
            href: latest_run ? `payslips.html?run_id=${latest_run.run_id}` : null,
        },
        {
            label: "Pending Leaves",
            value: counts.pending_leaves,
            note: counts.pending_leaves > 0 ? "Requires review" : "No pending items",
            tone: counts.pending_leaves > 0 ? "yellow" : "neutral",
            href: counts.pending_leaves > 0 ? "leave.html" : null,
        },
        {
            label: "Open Periods",
            value: counts.open_periods,
            note: counts.open_periods > 0 ? "Ready to process" : "Nothing to run",
            tone: counts.open_periods > 0 ? "blue" : "neutral",
            href: counts.open_periods > 0 ? "payroll_periods.html" : null,
        },
        {
            label: "Active Loans",
            value: active_loans.count,
            note: `${formatMoneyShort(active_loans.total_remaining)} remaining`,
            tone: "yellow",
            href: active_loans.count > 0 ? "loans.html" : null,
        },
    ];

    document.getElementById("kpi-grid").innerHTML = tiles.map((t) => {
        const tag = t.href ? "a" : "div";
        const hrefAttr = t.href ? `href="${t.href}"` : "";
        return `
            <${tag} ${hrefAttr} class="kpi-tile kpi-${t.tone}">
                <div class="kpi-label">${escapeHtml(t.label)}</div>
                <div class="kpi-value">${escapeHtml(String(t.value))}</div>
                <div class="kpi-note">${escapeHtml(t.note)}</div>
            </${tag}>
        `;
    }).join("");

    // =================================================================
    // Row 2 — Workflow cards
    // =================================================================

    // Step 1: Setup
    const setupComplete = counts.departments > 0 && counts.positions > 0 && counts.employees > 0 && counts.with_salary > 0;
    const setupCard = workflowCard({
        step: "1",
        status: setupComplete ? "ok" : "warn",
        statusLabel: setupComplete ? "Complete" : "In Progress",
        title: "Setup Master Data",
        note: setupComplete
            ? `${counts.employees} employee${counts.employees === 1 ? "" : "s"} · ${counts.with_salary} with basic salary`
            : "Finish adding departments, positions, and employees with salaries.",
        sublinks: [
            { label: "Departments", href: "departments.html" },
            { label: "Positions",   href: "positions.html" },
            { label: "Employees",   href: "employees.html" },
            { label: "Users",       href: "users.html" },
        ],
        ctaLabel: "Open Setup",
        ctaHref: "employees.html",
    });

    // Step 2: Daily Logs
    const hasPendingLeaves = counts.pending_leaves > 0;
    const dailyCard = workflowCard({
        step: "2",
        status: hasPendingLeaves ? "warn" : "ok",
        statusLabel: hasPendingLeaves ? "Review" : "All Clear",
        title: "Daily Logs",
        note: hasPendingLeaves
            ? `${counts.pending_leaves} pending leave request${counts.pending_leaves === 1 ? "" : "s"} waiting for review.`
            : "No pending items. Log attendance and leave as needed.",
        sublinks: [
            { label: "Attendance", href: "attendance.html" },
            { label: "Leave",      href: "leave.html" },
            { label: "Overtime",   href: "overtime.html" },
            { label: "Loans",      href: "loans.html" },
        ],
        ctaLabel: "Log Attendance",
        ctaHref: "attendance.html",
    });

    // Step 3: Run Payroll
    const hasOpen = counts.open_periods > 0;
    let payrollStatus, payrollStatusLabel, payrollNote, payrollCta, payrollCtaHref;

    if (hasOpen) {
        payrollStatus = "go";
        payrollStatusLabel = "Ready";
        payrollNote = `${counts.open_periods} Open period${counts.open_periods === 1 ? "" : "s"} ready to process.`;
        payrollCta = "Run Payroll";
        payrollCtaHref = "payroll_periods.html";
    } else if (latest_run) {
        payrollStatus = "ok";
        payrollStatusLabel = "Up to Date";
        payrollNote = `Last run #${latest_run.run_id} on ${latest_run.run_date} · ${latest_run.payslip_count} payslip${latest_run.payslip_count === 1 ? "" : "s"}.`;
        payrollCta = "View Latest Payslips";
        payrollCtaHref = `payslips.html?run_id=${latest_run.run_id}`;
    } else {
        payrollStatus = "warn";
        payrollStatusLabel = "Waiting";
        payrollNote = "Create a payroll period to start processing.";
        payrollCta = "Create Period";
        payrollCtaHref = "payroll_periods.html";
    }

    const payrollCard = workflowCard({
        step: "3",
        status: payrollStatus,
        statusLabel: payrollStatusLabel,
        title: "Run Payroll",
        note: payrollNote,
        sublinks: [],
        ctaLabel: payrollCta,
        ctaHref: payrollCtaHref,
    });

    // Step 4: Reports
    const reportsReady = !!latest_run;
    const reportsCard = workflowCard({
        step: "4",
        status: reportsReady ? "ok" : "warn",
        statusLabel: reportsReady ? "Available" : "Waiting",
        title: "Reports",
        note: reportsReady
            ? `Payroll Register, Remittance Summary, and Ledger are ready for run #${latest_run.run_id}.`
            : "Run payroll first to unlock reports.",
        sublinks: [
            { label: "Reports", href: "reports.html" },
            { label: "Ledger",  href: "ledger.html" },
        ],
        ctaLabel: "Open Reports",
        ctaHref: "reports.html",
    });

    document.getElementById("workflow-grid").innerHTML =
        setupCard + dailyCard + payrollCard + reportsCard;

    // =================================================================
    // Row 3 — Today's attendance snapshot
    // =================================================================
    const totalToday = today_attendance.present + today_attendance.late + today_attendance.absent;
    document.getElementById("attendance-snapshot").innerHTML = `
        <div class="snapshot-header">
            <h3 style="margin:0;">Today's Attendance</h3>
            <span style="font-size:12.5px;color:var(--muted);">${escapeHtml(today)}</span>
        </div>
        <div class="snapshot-bars">
            <div class="snapshot-item">
                <div class="snapshot-label">Present</div>
                <div class="snapshot-value" style="color:var(--green);">${today_attendance.present}</div>
            </div>
            <div class="snapshot-item">
                <div class="snapshot-label">Late</div>
                <div class="snapshot-value" style="color:var(--yellow-dark);">${today_attendance.late}</div>
            </div>
            <div class="snapshot-item">
                <div class="snapshot-label">Absent</div>
                <div class="snapshot-value" style="color:var(--red);">${today_attendance.absent}</div>
            </div>
            <div class="snapshot-item">
                <div class="snapshot-label">Total</div>
                <div class="snapshot-value">${totalToday}</div>
            </div>
        </div>
    `;

    // =================================================================
    // Row 4 — Recent payroll runs
    // =================================================================
    const runsEl = document.getElementById("recent-runs");
    if (recent_runs.length === 0) {
        runsEl.innerHTML = `
            <div class="empty-state">
                No payroll runs yet.
                <a href="payroll_periods.html">Create a period →</a>
            </div>`;
    } else {
        runsEl.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th>Run #</th>
                        <th>Period</th>
                        <th>Type</th>
                        <th class="col-center">Payslips</th>
                        <th style="text-align:right;">Net Pay</th>
                        <th class="col-center"></th>
                    </tr>
                </thead>
                <tbody>
                    ${recent_runs.map((r) => `
                        <tr>
                            <td>#${r.run_id}</td>
                            <td>${escapeHtml(r.start_date)} → ${escapeHtml(r.end_date)}</td>
                            <td>${escapeHtml(r.period_type)}</td>
                            <td class="col-center">${r.payslip_count}</td>
                            <td style="text-align:right;font-weight:600;">${formatMoney(r.total_net)}</td>
                            <td class="col-center">
                                <a class="btn btn-plain" href="payslips.html?run_id=${r.run_id}">View</a>
                            </td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
        `;
    }
}

document.addEventListener("DOMContentLoaded", loadDashboard);