// =====================================================================
// assets/js/payroll_runs.js
//
// Payroll runs + the maker/checker approval workflow.
//   Payroll Officer (maker) : process an Open period, resubmit a Returned run
//   Approver (checker)      : approve, return (with remarks), release
// Action buttons are only rendered for the role that may use them; the API
// enforces the same rules, so hiding a button is never the only guard.
// =====================================================================

const form      = document.getElementById("run-form");
const submitBtn = document.getElementById("submit-btn");
const rowsEl    = document.getElementById("rows");
const countEl   = document.getElementById("record-count");
const processCard = document.getElementById("process-card");
const demoCard     = document.getElementById("payroll-demo-card");
const demoToggle   = document.getElementById("payroll-demo-toggle");
const demoDateEl   = document.getElementById("payroll-demo-date");

let currentUser = null;

function todayISO() {
    const now = new Date();
    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, "0"),
        String(now.getDate()).padStart(2, "0"),
    ].join("-");
}

function effectivePayrollDate() {
    if (demoToggle && demoToggle.checked && demoDateEl && demoDateEl.value) {
        return demoDateEl.value;
    }
    return todayISO();
}

function canFinalize(r) {
    return !r.pay_date || effectivePayrollDate() >= r.pay_date;
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function isMaker()   { return !!currentUser && currentUser.role === "Payroll Officer"; }
function isChecker() { return !!currentUser && currentUser.role === "Payroll Officer"; }

// The maker prepares the files, the checker may review them. Employee and
// Administrator cannot export at all; the API answers 403 for them, so hiding
// the buttons is never the only guard.
function canExport() { return isMaker() || isChecker(); }

function fmtDate(v) {
    if (!v) return "-";
    return escapeHtml(String(v).replace("T", " "));
}

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function statusBadge(status) {
    const map = {
        "Submitted": { bg: "var(--blue-lite)",   fg: "var(--blue)" },
        "Approved":  { bg: "var(--green-lite)",  fg: "var(--green)" },
        "Returned":  { bg: "var(--yellow-lite)", fg: "var(--yellow-dark)" },
        "Released":  { bg: "var(--green)",       fg: "var(--on-accent)" },
    };
    const s = map[status] || { bg: "var(--red-lite)", fg: "var(--red)" };
    return `<span class="badge" style="background:${s.bg};color:${s.fg};">${escapeHtml(status || "-")}</span>`;
}

function personCell(name, at) {
    if (!name && !at) return `<span style="color:var(--muted);">-</span>`;
    return `${escapeHtml(name || "-")}<br><span style="color:var(--muted);font-size:11px;">${fmtDate(at)}</span>`;
}

// ---------------------------------------------------------------------
// Load current user first, so the page knows which buttons to show.
// ---------------------------------------------------------------------
async function loadMe() {
    const { data } = await api.get("auth.php?action=me");
    currentUser = data.data;
    processCard.classList.toggle("hidden", !isMaker());
}

// ---------------------------------------------------------------------
// Open periods for the process form (maker only)
// ---------------------------------------------------------------------
async function loadOpenPeriods() {
    if (!isMaker()) return;
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
            open.map((p) => `<option value="${p.period_id}">${escapeHtml(p.period_type)} - ${escapeHtml(p.start_date)} to ${escapeHtml(p.end_date)}</option>`).join("");
        submitBtn.disabled = false;
    } catch (err) {
        showMessage("Could not load periods: " + apiErrorMessage(err), "error");
    }
}

// ---------------------------------------------------------------------
// Runs table
// ---------------------------------------------------------------------
async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="9">Loading...</td></tr>`;
    try {
        const { data } = await api.get("payroll_runs.php");
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="9">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function actionsFor(r) {
    const buttons = [];

    if (isChecker()) {
        if (r.status === "Submitted" || r.status === "Returned") {
            if (canFinalize(r)) {
                buttons.push(`<button class="btn btn-restore" data-action="approve" data-run="${r.run_id}">Approve</button>`);
            } else {
                buttons.push(`<span style="font-size:11px;color:var(--muted);">Approve on/after ${escapeHtml(r.pay_date || "")}</span>`);
            }
        }
        if (r.status === "Submitted") {
            buttons.push(`<button class="btn btn-delete" data-action="return" data-run="${r.run_id}">Return</button>`);
        }
        if (r.status === "Approved") {
            if (canFinalize(r)) {
                buttons.push(`<button class="btn btn-primary" data-action="release" data-run="${r.run_id}">Release</button>`);
            } else {
                buttons.push(`<span style="font-size:11px;color:var(--muted);">Release on/after ${escapeHtml(r.pay_date || "")}</span>`);
            }
        }
        // Void is the formal adjustment: it removes the run and its lines and
        // reopens the period. A Released run is refused by the API (payment is
        // already authorised), so it never gets the button.
        if (r.status !== "Released") {
            buttons.push(`<button class="btn btn-delete" data-action="void" data-run="${r.run_id}">Void</button>`);
        }
    }

    if (isMaker() && r.status === "Returned") {
        buttons.push(`<button class="btn btn-edit" data-action="resubmit" data-run="${r.run_id}">Resubmit</button>`);
    }

    // Official payslips are available only after release. Before that,
    // Payroll/HR reviews run-level totals and approvals.
    if (r.status === "Released") {
        buttons.push(`<a class="btn btn-plain" href="payslips.html?run_id=${r.run_id}">Payslips</a>`);
    } else {
        buttons.push(`<span style="font-size:11.5px;color:var(--muted);">Payslips after release</span>`);
    }
    return buttons.join(" ") + exportButtonsFor(r);
}

// ---------------------------------------------------------------------
// Exports: the maker's three payment files, one GET endpoint per format.
// Rendered only for the maker/checker. The API still enforces the guard and
// still refuses a Returned run (409) or missing bank details (422).
// ---------------------------------------------------------------------
const EXPORTS = [
    { format: "bank",       label: "Bank file" },
    { format: "register",   label: "Payroll register" },
    { format: "remittance", label: "Remittance" },
];

function exportButtonsFor(r) {
    if (!canExport()) return "";
    const buttons = EXPORTS.map((exp) =>
        `<button class="btn btn-plain" data-export="${exp.format}" data-run="${r.run_id}">${exp.label}</button>`
    );
    return `<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;justify-content:center;">${buttons.join(" ")}</div>`;
}

/** A refused export answers JSON, but it is fetched with responseType=blob. */
async function exportErrorMessage(err) {
    const data = err?.response?.data;
    if (data && typeof data === "object" && typeof data.text === "function") {
        try {
            const parsed = JSON.parse(await data.text());
            if (parsed && parsed.message) return parsed.message;
        } catch (_) { /* not JSON - fall through */ }
    }
    return apiErrorMessage(err);
}

async function exportRun(runId, format, btn) {
    if (btn) btn.disabled = true;
    try {
        const res = await api.get("bank_file.php", {
            params: { run_id: Number(runId), format },
            responseType: "blob",
        });

        const disposition = res.headers["content-disposition"] || "";
        const match = /filename="([^"]+)"/i.exec(disposition);
        const filename = match ? match[1] : `${format}_run${runId}.csv`;

        const url = URL.createObjectURL(res.data);
        const link = document.createElement("a");
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);

        showToast(`Downloaded ${filename}`, "success");
    } catch (err) {
        const message = await exportErrorMessage(err);
        if (format === "bank") {
            // A refused bank file names every employee with no bank details.
            await Swal.fire({
                title: "Bank file refused",
                html: `<div style="text-align:left;font-size:13.5px;line-height:1.55;">${escapeHtml(message).replace(/\n/g, "<br>")}</div>`,
                icon: "warning",
                confirmButtonColor: "#0038A8",
                confirmButtonText: "OK",
            });
        } else {
            showToast(message, "error");
        }
    } finally {
        if (btn) btn.disabled = false;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} run(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `
            <tr class="empty-row">
                <td colspan="9" style="padding:36px 20px;text-align:center;">
                    No payroll runs yet. Process an Open period to create one.
                </td>
            </tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        // Who decided this run, and when. Released shows the release approver.
        let approval = personCell(r.approved_by_name, r.approved_at);
        if (r.status === "Returned") {
            approval = personCell(r.returned_by_name, r.returned_at);
        } else if (r.status === "Released") {
            approval = personCell(r.release_approved_by_name, r.release_approved_at);
        }

        // A returned run shows the checker's remarks right on the row.
        const remarks = (r.status === "Returned" && r.remarks)
            ? `<div style="margin-top:6px;font-size:11.5px;color:var(--yellow-dark);max-width:260px;white-space:normal;">
                   <strong>Remarks:</strong> ${escapeHtml(r.remarks)}
               </div>`
            : "";

        return `
        <tr>
            <td>#${r.run_id}</td>
            <td>${escapeHtml(r.start_date)}<br>
                <span style="color:var(--muted);font-size:11px;">to ${escapeHtml(r.end_date)}</span></td>
            <td>${escapeHtml(r.period_type)}</td>
            <td>${fmtDate(r.run_date)}</td>
            <td class="col-center">${r.payslip_count}
                <div style="font-size:10.5px;color:var(--muted);margin-top:3px;">
                    Adj ${formatMoney(r.total_gross || 0)}<br>
                    Ded ${formatMoney(r.total_deductions || 0)}<br>
                    Net ${formatMoney(r.total_net || 0)}
                </div>
            </td>
            <td class="col-center">${statusBadge(r.status)}${remarks}</td>
            <td>${personCell(r.submitted_by_name, r.submitted_at)}</td>
            <td>${approval}</td>
            <td class="col-center">${actionsFor(r)}</td>
        </tr>`;
    }).join("");

    rowsEl.querySelectorAll("[data-action]").forEach((btn) => {
        btn.addEventListener("click", () => handleAction(btn.dataset.action, Number(btn.dataset.run), btn));
    });
    rowsEl.querySelectorAll("[data-export]").forEach((btn) => {
        btn.addEventListener("click", () => exportRun(btn.dataset.run, btn.dataset.export, btn));
    });
}

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
async function handleAction(action, runId, btn) {
    let remarks = null;

    if (action === "approve") {
        if (!await confirmDialog(`Approve payroll run #${runId}? This locks its payslips, deductions, allowances and loan payments.`,
            { title: "Approve Run", icon: "question", confirmText: "Approve" })) return;
    } else if (action === "release") {
        if (!await confirmDialog(`Authorise payment release for run #${runId}? This is the final approval step.`,
            { title: "Release Payment", icon: "warning", confirmText: "Release" })) return;
    } else if (action === "resubmit") {
        if (!await confirmDialog(`Resubmit run #${runId} for approval?`,
            { title: "Resubmit Run", icon: "question", confirmText: "Resubmit" })) return;
    } else if (action === "return") {
        const result = await Swal.fire({
            title: "Return this run?",
            text: `Tell Payroll/HR what must be corrected in run #${runId}.`,
            input: "textarea",
            inputPlaceholder: "Explain the correction required...",
            inputValidator: (value) => (!value || !value.trim()) ? "Remarks are required when returning a run." : null,
            showCancelButton: true,
            confirmButtonText: "Return to maker",
            confirmButtonColor: "#b3261e",
            cancelButtonColor: "#6b7280",
            reverseButtons: true,
        });
        if (!result.isConfirmed) return;
        remarks = result.value.trim();
    } else if (action === "void") {
        // Payroll/HR-only; the API enforces the same rule, this is the UI half.
        if (!isChecker()) return;

        const result = await Swal.fire({
            title: `Void payroll run #${runId}?`,
            html: `<div style="text-align:left;font-size:13.5px;line-height:1.55;">
                       This removes the run and every payslip, deduction, allowance, contribution
                       and loan-payment line, then reopens the period so it can be processed again.
                       <strong>A Released run cannot be voided.</strong>
                   </div>`,
            input: "textarea",
            inputPlaceholder: "Why is this run being voided? (at least 10 characters)",
            inputValidator: (value) => {
                const v = (value || "").trim();
                return v.length < 10
                    ? "A reason of at least 10 characters is required — a void is a formal adjustment."
                    : null;
            },
            showCancelButton: true,
            confirmButtonText: "Void run",
            confirmButtonColor: "#b3261e",
            cancelButtonColor: "#6b7280",
            reverseButtons: true,
        });
        if (!result.isConfirmed) return;
        remarks = result.value.trim();
    } else {
        return;
    }

    if (btn) btn.disabled = true;
    try {
        let data;
        if (action === "void") {
            ({ data } = await api.post("payroll_void.php", { run_id: runId, remarks }));
        } else {
            const payload = { run_id: runId, action };
            if (remarks !== null) payload.remarks = remarks;
            payload.demo_mode = !!(demoToggle && demoToggle.checked);
            payload.demo_date = (demoDateEl && demoDateEl.value) || todayISO();
            ({ data } = await api.patch("payroll_runs.php", payload));
        }
        showToast(data.message, "success");
        await Promise.all([loadRows(), loadOpenPeriods()]);
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ---------------------------------------------------------------------
// Process an Open period (maker)
// ---------------------------------------------------------------------
form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const periodId = document.getElementById("period_id").value;
    if (!periodId) return;

    if (!await confirmDialog(
        "Run payroll for this period? Payslips are generated and the run is submitted for approval.",
        { title: "Run Payroll", icon: "warning", confirmText: "Yes, run payroll" }
    )) return;

    submitBtn.disabled = true;
    try {
        const { data } = await api.post("payroll_runs.php", { period_id: Number(periodId) });
        showToast(data.message, "success");
        await Promise.all([loadRows(), loadOpenPeriods()]);
        if (runId) {
            // Payslips stay locked until the run is released.
            setTimeout(() => { window.location.href = "payroll_runs.html"; }, 700);
        }
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    } finally {
        submitBtn.disabled = false;
    }
});

document.addEventListener("DOMContentLoaded", async () => {
    if (demoDateEl) demoDateEl.value = todayISO();
    if (demoToggle) demoToggle.addEventListener("change", loadRows);
    if (demoDateEl) demoDateEl.addEventListener("change", () => { if (demoToggle?.checked) loadRows(); });

    try {
        await loadMe();
        await Promise.all([loadOpenPeriods(), loadRows()]);
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
});
