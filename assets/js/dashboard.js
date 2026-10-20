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

// ---------------------------------------------------------------------
// Dates — the API sends plain "YYYY-MM-DD" strings, which read badly on a
// dashboard. Formatting is done here (not by a stylesheet) so every panel
// shows the same wording, and UTC parsing keeps the day from shifting.
// ---------------------------------------------------------------------
const DASH_MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
                     "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const DASH_WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function parseApiDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value == null ? "" : value));
    if (!match) return null;
    return { y: Number(match[1]), m: Number(match[2]), d: Number(match[3]) };
}

function pad2(n) {
    return (n < 10 ? "0" : "") + n;
}

// "2025-08-18" -> "Mon, 18 Aug 2025"
function formatLongDate(value) {
    const p = parseApiDate(value);
    if (!p) return String(value == null ? "" : value);
    const weekday = DASH_WEEKDAYS[new Date(Date.UTC(p.y, p.m - 1, p.d)).getUTCDay()];
    return weekday + ", " + pad2(p.d) + " " + DASH_MONTHS[p.m - 1] + " " + p.y;
}

// "18:00:00" -> "6:00 PM"
function formatClock(value) {
    if (!value) return "—";
    const m = String(value).match(/^(\d{1,2}):(\d{2})/);
    if (!m) return String(value);
    let h = Number(m[1]);
    const suffix = h >= 12 ? "PM" : "AM";
    h = h % 12 || 12;
    return h + ":" + m[2] + " " + suffix;
}

// "2025-08-01", "2025-08-15" -> "01–15 Aug 2025"; a range that crosses a
// month repeats the month, and one that crosses a year repeats the year.
function formatPeriod(startValue, endValue) {
    const s = parseApiDate(startValue);
    const e = parseApiDate(endValue);
    if (!s || !e) return String(startValue || "—") + " → " + String(endValue || "—");
    const sameMonth = s.m === e.m && s.y === e.y;
    const startText = pad2(s.d) + (sameMonth ? "" : " " + DASH_MONTHS[s.m - 1]);
    const endText = pad2(e.d) + " " + DASH_MONTHS[e.m - 1];
    if (s.y !== e.y) {
        return startText + " " + s.y + " – " + endText + " " + e.y;
    }
    return startText + "–" + endText + " " + s.y;
}

// Replace every dashboard panel with one short notice. Used when the session
// is not allowed to read payroll-wide figures (for example an Employee).
function renderDashboardNotice(message) {
    const tile = `
        <div class="kpi-tile kpi-neutral kpi-wide">
            <div class="kpi-label">Dashboard unavailable</div>
            <div class="kpi-note">${escapeHtml(message)}</div>
        </div>`;
    const kpi = document.getElementById("kpi-grid");
    if (kpi) kpi.innerHTML = tile;

    const notice = `<div class="empty-state">${escapeHtml(message)}</div>`;
    ["workflow-grid", "attendance-snapshot", "recent-runs"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = notice;
    });

    showMessage(message, "error");
}

async function loadDashboard() {
    try {
        const { data: body } = await api.get("dashboard.php");
        if (!body.success) throw new Error(body.message || "Dashboard request failed.");
        if (body.data && body.data.scope === "supervisor") {
            renderSupervisor(body.data);
            return;
        }
        render(body.data);
    } catch (err) {
        const status = err?.response?.status;
        if (status === 403 || status === 401) {
            renderDashboardNotice("You do not have access to the payroll dashboard.");
            return;
        }
        renderDashboardNotice("Failed to load dashboard: " + apiErrorMessage(err));
    }
}

// ---------------------------------------------------------------------
// Supervisor dashboard: team-scoped only, no salary/payroll-wide figures.
// ---------------------------------------------------------------------
function renderSupervisor(data) {
    const counts = data.counts || {};
    const att = data.today_attendance || { present: 0, late: 0, absent: 0 };
    const recent = Array.isArray(data.recent_overtime) ? data.recent_overtime : [];

    const subtitle = document.querySelector(".dash-head .subtitle");
    if (subtitle) subtitle.textContent = "Team overview — attendance, overtime and requests.";

    const tiles = [
        { label: "Team Members", value: counts.employees || 0, note: "Active in your team", tone: "blue", href: "attendance.html" },
        { label: "Pending Overtime", value: counts.pending_overtime || 0, note: "Awaiting review", tone: (counts.pending_overtime || 0) > 0 ? "yellow" : "neutral", href: "overtime.html" },
        { label: "Pending Leave", value: counts.pending_leaves || 0, note: "Team requests", tone: (counts.pending_leaves || 0) > 0 ? "yellow" : "neutral", href: "leave.html" },
        { label: "Pending Corrections", value: counts.pending_corrections || 0, note: "Attendance fixes", tone: (counts.pending_corrections || 0) > 0 ? "yellow" : "neutral", href: "attendance_corrections.html" },
        { label: "Active Team Loans", value: counts.active_loans || 0, note: "Active records", tone: "neutral", href: "loans.html" },
    ];

    document.getElementById("kpi-grid").innerHTML = tiles.map((t) => `
        <a href="${t.href}" class="kpi-tile kpi-${t.tone}">
            <div class="kpi-label">${escapeHtml(t.label)}</div>
            <div class="kpi-value">${escapeHtml(String(t.value))}</div>
            <div class="kpi-note">${escapeHtml(t.note)}</div>
            <span class="kpi-arrow" aria-hidden="true">→</span>
        </a>
    `).join("");

    document.getElementById("workflow-grid").innerHTML =
        workflowCard({
            step: "1",
            status: "go",
            statusLabel: "Open",
            title: "Team Attendance",
            note: "View your team's attendance and today's log.",
            sublinks: [
                { label: "Attendance", href: "attendance.html" },
                { label: "Corrections", href: "attendance_corrections.html" },
            ],
            ctaLabel: "Open Attendance",
            ctaHref: "attendance.html",
        }) +
        workflowCard({
            step: "2",
            status: (counts.pending_overtime || 0) > 0 ? "warn" : "ok",
            statusLabel: (counts.pending_overtime || 0) > 0 ? "Review" : "Clear",
            title: "Overtime Requests",
            note: (counts.pending_overtime || 0) > 0
                ? `${counts.pending_overtime} pending request${counts.pending_overtime === 1 ? "" : "s"}.`
                : "No pending overtime.",
            sublinks: [{ label: "Overtime", href: "overtime.html" }],
            ctaLabel: "Open Overtime",
            ctaHref: "overtime.html",
        }) +
        workflowCard({
            step: "3",
            status: (counts.pending_leaves || 0) > 0 ? "warn" : "ok",
            statusLabel: (counts.pending_leaves || 0) > 0 ? "Review" : "Clear",
            title: "Team Leave",
            note: (counts.pending_leaves || 0) > 0
                ? `${counts.pending_leaves} pending leave request${counts.pending_leaves === 1 ? "" : "s"}.`
                : "No pending leave.",
            sublinks: [{ label: "Leave", href: "leave.html" }],
            ctaLabel: "Open Leave",
            ctaHref: "leave.html",
        }) +
        workflowCard({
            step: "4",
            status: "neutral",
            statusLabel: "View",
            title: "Team Loans",
            note: "View team loan records and recommendations.",
            sublinks: [{ label: "Loans", href: "loans.html" }],
            ctaLabel: "Open Loans",
            ctaHref: "loans.html",
        });

    const total = att.present + att.late + att.absent;
    const share = (n) => total > 0 ? Math.round((n / total) * 100) : 0;
    document.getElementById("attendance-snapshot").innerHTML = `
        <div class="snapshot-header">
            <div class="snapshot-title">
                <h3>Today's Team Attendance</h3>
                <span class="snapshot-date">${escapeHtml(formatLongDate(data.today))}</span>
            </div>
            <span class="snapshot-total"><strong>${total}</strong> on the roster</span>
        </div>
        <div class="snapshot-bars">
            <div class="snapshot-item snap-present"><div class="snapshot-label">Present</div><div class="snapshot-value">${att.present}</div><div class="snapshot-share">${share(att.present)}% of ${total}</div></div>
            <div class="snapshot-item snap-late"><div class="snapshot-label">Late</div><div class="snapshot-value">${att.late}</div><div class="snapshot-share">${share(att.late)}% of ${total}</div></div>
            <div class="snapshot-item snap-absent"><div class="snapshot-label">Absent</div><div class="snapshot-value">${att.absent}</div><div class="snapshot-share">${share(att.absent)}% of ${total}</div></div>
        </div>
    `;

    const recentCard = document.getElementById("recent-runs-card");
    if (recentCard) {
        recentCard.innerHTML = `
            <div class="card-head">
                <h3>Recent Team Overtime</h3>
                <a class="card-link" href="overtime.html">Open overtime →</a>
            </div>
            <div id="recent-runs">
                ${recent.length === 0
                    ? '<div class="empty-state">No team overtime requests yet.</div>'
                    : `<div class="table-wrap"><table>
                        <thead><tr><th>Employee</th><th>Date</th><th>Requested</th><th>Status</th></tr></thead>
                        <tbody>
                        ${recent.map((r) => `<tr>
                            <td>${escapeHtml(r.employee_name || "")}</td>
                            <td>${escapeHtml(formatLongDate(r.ot_date))}</td>
                            <td>${escapeHtml(formatClock(r.requested_start) + " – " + formatClock(r.requested_end))}</td>
                            <td>${escapeHtml(r.status || "")}</td>
                        </tr>`).join("")}
                        </tbody>
                    </table></div>`}
            </div>
        `;
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
        ? `Run #${latest_run.run_id} · ${formatLongDate(latest_run.run_date)}`
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
        const arrow = t.href ? '<span class="kpi-arrow" aria-hidden="true">→</span>' : "";
        return `
            <${tag} ${hrefAttr} class="kpi-tile kpi-${t.tone}">
                <div class="kpi-label">${escapeHtml(t.label)}</div>
                <div class="kpi-value">${escapeHtml(String(t.value))}</div>
                <div class="kpi-note">${escapeHtml(t.note)}</div>
                ${arrow}
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
        payrollNote = `Last run #${latest_run.run_id} on ${formatLongDate(latest_run.run_date)} · ${latest_run.payslip_count} payslip${latest_run.payslip_count === 1 ? "" : "s"}.`;
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
    // Colour lives in the stylesheet (see .snap-* rules), never in this
    // markup: the markup is shared by both themes, and inline colours would
    // pin one of them. Only the proportions are inline, and those are unitless.
    const totalToday = today_attendance.present + today_attendance.late + today_attendance.absent;
    const partOf = (n) => (totalToday > 0 ? Math.round((n / totalToday) * 10000) / 10000 : 0);
    const shareOf = (n) => (totalToday > 0 ? Math.round((n / totalToday) * 100) : 0);

    const trackHtml = totalToday > 0
        ? [
            `<span class="seg-present" style="flex:${partOf(today_attendance.present)}"></span>`,
            `<span class="seg-late" style="flex:${partOf(today_attendance.late)}"></span>`,
            `<span class="seg-absent" style="flex:${partOf(today_attendance.absent)}"></span>`,
        ].join("")
        : '<span class="snapshot-track-empty" style="flex:1"></span>';

    const attendanceStats = [
        { key: "present", label: "Present", value: today_attendance.present },
        { key: "late",    label: "Late",    value: today_attendance.late },
        { key: "absent",  label: "Absent",  value: today_attendance.absent },
    ].map((s) => `
        <div class="snapshot-item snap-${s.key}">
            <div class="snapshot-label">${s.label}</div>
            <div class="snapshot-value">${s.value}</div>
            <div class="snapshot-share">${shareOf(s.value)}% of ${totalToday}</div>
        </div>`).join("");

    document.getElementById("attendance-snapshot").innerHTML = `
        <div class="snapshot-header">
            <div class="snapshot-title">
                <h3>Today's Attendance</h3>
                <span class="snapshot-date">${escapeHtml(formatLongDate(today))}</span>
            </div>
            <span class="snapshot-total"><strong>${totalToday}</strong> on the roster</span>
        </div>
        <div class="snapshot-track"
             role="img"
             aria-label="Today: ${today_attendance.present} present, ${today_attendance.late} late, ${today_attendance.absent} absent">
            ${trackHtml}
        </div>
        <div class="snapshot-bars">
            ${attendanceStats}
            <div class="snapshot-item snap-total">
                <div class="snapshot-label">Total</div>
                <div class="snapshot-value">${totalToday}</div>
                <div class="snapshot-share">roster size</div>
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
                        <th scope="col">Run</th>
                        <th scope="col">Period covered</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="col-center">Payslips</th>
                        <th scope="col" class="col-num">Net pay</th>
                        <th scope="col" class="col-center"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    ${recent_runs.map((r) => `
                        <tr>
                            <td><span class="run-id">#${r.run_id}</span></td>
                            <td class="run-period">${escapeHtml(formatPeriod(r.start_date, r.end_date))}</td>
                            <td><span class="run-type">${escapeHtml(r.period_type)}</span></td>
                            <td class="col-center">${r.payslip_count}</td>
                            <td class="col-num num-strong">${formatMoney(r.total_net)}</td>
                            <td class="col-center">
                                <a class="btn btn-plain" href="payslips.html?run_id=${r.run_id}">View</a>
                            </td>
                        </tr>
                    `).join("")}
                </tbody>
            </table>
            <div class="card-foot">
                <span>${recent_runs.length} most recent run${recent_runs.length === 1 ? "" : "s"} · net pay per run</span>
            </div>
        `;
    }
}

document.addEventListener("DOMContentLoaded", loadDashboard);
