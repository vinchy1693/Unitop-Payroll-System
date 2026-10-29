// =====================================================================
// assets/js/leave.js
// Combined Leave page — Requests, Balances, Types.
// =====================================================================

// ---------------------------------------------------------------------
// Tab switching
// ---------------------------------------------------------------------
document.querySelectorAll(".tab").forEach((btn) => {
    btn.addEventListener("click", () => {
        document.querySelectorAll(".tab").forEach((b) => b.classList.remove("active"));
        document.querySelectorAll(".tab-panel").forEach((p) => p.classList.add("hidden"));
        btn.classList.add("active");
        document.getElementById("tab-" + btn.dataset.tab).classList.remove("hidden");
    });
});

// =====================================================================
// SECTION 1 — Leave Requests
// =====================================================================
(function initRequests() {
    let editId = null;
    let currentRole = "";

    const form      = document.getElementById("req-form");
    const submitBtn = document.getElementById("req_submit_btn");
    const cancelBtn = document.getElementById("req_cancel_btn");
    const formTitle = document.getElementById("req-form-title");
    const rowsEl    = document.getElementById("req_rows");
    const countEl   = document.getElementById("req_count");
    const filterEl  = document.getElementById("req_filter_status");

    function getEmployeeLabel() {
        const sel = document.getElementById("req_employee_id");
        if (!sel || sel.selectedIndex < 0) return "this employee";
        return sel.options[sel.selectedIndex].text || "this employee";
    }

    async function loadEmployees() {
        const select = document.getElementById("req_employee_id");
        const cur = select.value;
        try {
            const { data } = await api.get("employees.php", { params: { simple: 1 } });
            select.innerHTML = `<option value="">-- Select --</option>` +
                data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
            if (cur) select.value = cur;
        } catch (err) {
            showToast("Could not load employees: " + apiErrorMessage(err), "error");
        }
    }

    async function loadLeaveTypes() {
        const select = document.getElementById("req_leave_type_id");
        const cur = select.value;
        try {
            const { data } = await api.get("leave_types.php");
            select.innerHTML = `<option value="">-- Select --</option>` +
                data.data.map((t) => `<option value="${t.leave_type_id}">${escapeHtml(t.name)}</option>`).join("");
            if (cur) select.value = cur;
        } catch (err) {
            showToast("Could not load leave types: " + apiErrorMessage(err), "error");
        }
    }

    function countBusinessDays(start, end) {
        if (!start || !end || start > end) return 0;
        if (start === end) return 1;   // a single-day request always counts as one day
        const s = new Date(start), e = new Date(end);
        let days = 0;
        for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
            const dow = d.getDay();
            // Mon-Sat, matching the payroll work week; only Sunday is excluded.
            if (dow !== 0) days++;
        }
        return days;
    }

    function statusBadge(status) {
        const map = {
            "Pending":  { cls: "",         bg: "#fef3c7", color: "#92400e" },
            "Approved": { cls: "active",   bg: "",        color: "" },
            "Rejected": { cls: "inactive", bg: "",        color: "" },
        };
        const s = map[status] || { cls: "inactive" };
        const style = s.bg ? ` style="background:${s.bg};color:${s.color};"` : "";
        return `<span class="badge ${s.cls}"${style}>${escapeHtml(status)}</span>`;
    }

    function canDecide() {
        return currentRole === "Manager" || currentRole === "Supervisor";
    }

    async function loadRole() {
        try {
            const { data } = await api.get("auth.php?action=me");
            currentRole = data.data?.role || "";
        } catch (_) {
            currentRole = "";
        }
    }

    async function loadRows() {
        rowsEl.innerHTML = `<tr class="loading-row"><td colspan="7">Loading…</td></tr>`;
        const status = filterEl.value;
        const params = status ? { status } : {};
        try {
            const { data } = await api.get("leave_requests.php", { params });
            renderRows(data.data);
        } catch (err) {
            rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">Failed: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
        }
    }

    function renderRows(rows) {
        countEl.textContent = `${rows.length} record(s)`;
        if (!rows.length) {
            rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">No leave requests.</td></tr>`;
            return;
        }

        rowsEl.innerHTML = rows.map((r) => {
            const days = countBusinessDays(r.start_date, r.end_date);
            let actions = "";
            if (r.status === "Pending" && canDecide()) {
                actions = `
                    <button class="btn btn-restore" data-approve="${r.leave_id}">Approve</button>
                    <button class="btn btn-delete"  data-reject="${r.leave_id}">Reject</button>
                `;
                if (currentRole === "Manager") {
                    actions += ` <button class="btn btn-edit" data-edit="${r.leave_id}">Edit</button>`;
                }
            } else if (r.status === "Pending") {
                actions = `<span style="color:#888;font-size:12px;">Pending review</span>`;
            } else {
                actions = `<span style="color:#888;font-size:12px;">Finalized</span>`;
            }
            return `
            <tr>
                <td>${escapeHtml(r.employee_name)}<br><span style="color:#888;font-size:11px;">${escapeHtml(r.employee_number)}</span></td>
                <td>${escapeHtml(r.leave_type_name)}</td>
                <td>${escapeHtml(r.start_date)}</td>
                <td>${escapeHtml(r.end_date)}</td>
                <td class="col-center">${days}</td>
                <td class="col-center">${statusBadge(r.status)}</td>
                <td class="col-center">${actions}</td>
            </tr>`;
        }).join("");

        rowsEl.querySelectorAll("[data-approve]").forEach((btn) => {
            btn.addEventListener("click", () => changeStatus(btn.dataset.approve, "Approved"));
        });
        rowsEl.querySelectorAll("[data-reject]").forEach((btn) => {
            btn.addEventListener("click", () => changeStatus(btn.dataset.reject, "Rejected"));
        });
        rowsEl.querySelectorAll("[data-edit]").forEach((btn) => {
            btn.addEventListener("click", () => startEdit(rows.find((r) => r.leave_id == btn.dataset.edit)));
        });
    }

    function startEdit(row) {
        if (!row) return;
        editId = row.leave_id;
        document.getElementById("req_leave_id").value      = row.leave_id;
        document.getElementById("req_employee_id").value   = row.employee_id;
        document.getElementById("req_leave_type_id").value = row.leave_type_id;
        document.getElementById("req_start_date").value    = row.start_date ?? "";
        document.getElementById("req_end_date").value      = row.end_date ?? "";
        formTitle.textContent = "Edit Leave Request";
        submitBtn.textContent = "Save Changes";
        cancelBtn.classList.remove("hidden");
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function resetForm() {
        editId = null;
        form.reset();
        document.getElementById("req_leave_id").value = "";
        formTitle.textContent = "File Leave Request";
        submitBtn.textContent = "File Request";
        cancelBtn.classList.add("hidden");
    }

    cancelBtn.addEventListener("click", resetForm);

    form.addEventListener("submit", async (e) => {
        e.preventDefault();

        const empId   = document.getElementById("req_employee_id").value;
        const typeId  = document.getElementById("req_leave_type_id").value;
        const start   = document.getElementById("req_start_date").value;
        const end     = document.getElementById("req_end_date").value;
        const empName = getEmployeeLabel();

        if (!empId || !typeId || !start || !end) return;

        if (!editId) {
            // Confirm filing
            const days = countBusinessDays(start, end);
            if (!await confirmDialog(
                `File a ${days}-day leave for ${empName} from ${start} to ${end}?`,
                { title: "Confirm Leave Request", icon: "question", confirmText: "File Request" }
            )) return;
        }

        const payload = {
            employee_id:   empId,
            leave_type_id: typeId,
            start_date:    start,
            end_date:      end,
        };

        submitBtn.disabled = true;
        try {
            if (editId) {
                payload.leave_id = editId;
                const { data } = await api.put("leave_requests.php", payload);
                showToast(data.message, "success");
            } else {
                const { data } = await api.post("leave_requests.php", payload);
                showToast(data.message, "success");
            }
            resetForm();
            await loadRows();
        } catch (err) {
            showToast(apiErrorMessage(err), "error");
        } finally {
            submitBtn.disabled = false;
        }
    });

    async function changeStatus(id, status) {
        const verb = status === "Approved" ? "Approve" : "Reject";
        const row = document.querySelector(`[data-approve="${id}"], [data-reject="${id}"]`);
        const tr = row ? row.closest("tr") : null;
        const empName = tr ? tr.querySelector("td").textContent.trim().split("\n")[0] : "this request";

        const options = status === "Approved"
            ? { title: "Approve Leave", icon: "success", confirmText: "Approve", iconColor: "#1e7d32" }
            : { title: "Reject Leave", icon: "warning", confirmText: "Reject" };

        if (!await confirmDialog(`${verb} the leave request for ${empName}?`, options)) return;

        try {
            const { data } = await api.patch("leave_requests.php", { leave_id: Number(id), status });
            showToast(data.message, "success");
            await loadRows();
        } catch (err) {
            showToast(apiErrorMessage(err), "error");
        }
    }

    filterEl.addEventListener("change", loadRows);

    window.__reloadLeaveTypesForRequests = loadLeaveTypes;

    document.addEventListener("DOMContentLoaded", async () => {
        await loadRole();
        if (currentRole === "Supervisor") {
            const card = form.closest(".card");
            if (card) card.classList.add("hidden");
        }
        loadEmployees();
        loadLeaveTypes();
        loadRows();
    });
})();

// =====================================================================
// SECTION 2 — Leave Balances
// =====================================================================
(function initBalances() {
    const yearEl  = document.getElementById("bal_year");
    const gridEl  = document.getElementById("bal_grid");
    const countEl = document.getElementById("bal_count");

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
                they'll be seeded when the employee's first leave is approved. Click
                <strong>Initialize Balances</strong> to create them all now.
            </p>
        `;
    }

    document.getElementById("bal_reload_btn").addEventListener("click", loadGrid);

    document.getElementById("bal_init_btn").addEventListener("click", async () => {
        const year = parseInt(yearEl.value, 10);
        if (!year) return;
        if (!await confirmDialog(
            `Initialize leave balances for ${year}? This creates missing rows for all active employees and leave types.`,
            { title: "Initialize Balances", icon: "question", confirmText: "Initialize" }
        )) return;
        try {
            const { data } = await api.post("leave_balances.php", { year });
            showToast(data.message, "success");
            await loadGrid();
        } catch (err) {
            showToast(apiErrorMessage(err), "error");
        }
    });

    document.addEventListener("DOMContentLoaded", () => {
        yearEl.value = new Date().getFullYear();
        loadGrid();
    });
})();

// =====================================================================
// SECTION 3 - Leave Types (READ-ONLY)
// =====================================================================
(function initReadOnlyTypes() {
    const rowsEl  = document.getElementById("type_rows");
    const countEl = document.getElementById("type_count");
    if (!rowsEl || !countEl) return;

    function serviceLabel(months) {
        const n = Number(months || 0);
        if (!n) return "None";
        if (n % 12 === 0) return `${n / 12} year${n === 12 ? "" : "s"}`;
        return `${n} month${n === 1 ? "" : "s"}`;
    }

    function renderTypes(rows) {
        countEl.textContent = `${rows.length} leave type(s)`;
        if (!rows.length) {
            rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">No leave types found.</td></tr>`;
            return;
        }

        rowsEl.innerHTML = rows.map((r) => {
            const days = Number(r.days_per_year || 0);
            const daysLabel = Number.isInteger(days) ? String(days) : days.toFixed(2);
            const cash = Number(r.is_cash_convertible) === 1
                ? `<span class="badge active">Convertible</span>`
                : `<span style="color:var(--muted);">No</span>`;
            const status = Number(r.is_active) === 1
                ? `<span class="badge active">Active</span>`
                : `<span class="badge inactive">Retired</span>`;

            return `
                <tr>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
                    <td class="col-center">${escapeHtml(daysLabel)}</td>
                    <td class="col-center">${escapeHtml(serviceLabel(r.service_months))}</td>
                    <td class="col-center">${cash}</td>
                    <td style="min-width:280px;white-space:normal;line-height:1.55;">${escapeHtml(r.description || "—")}</td>
                    <td class="col-center">${status}</td>
                </tr>
            `;
        }).join("");
    }

    async function loadTypes() {
        rowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading…</td></tr>`;
        try {
            const { data } = await api.get("leave_types.php");
            renderTypes(data.data);
        } catch (err) {
            rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
        }
    }

    document.addEventListener("DOMContentLoaded", loadTypes);
})();
