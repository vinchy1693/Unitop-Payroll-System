// =====================================================================
// assets/js/qr_codes.js
// =====================================================================

const form        = document.getElementById("qr-form");
const gridEl      = document.getElementById("qr-grid");
const countEl     = document.getElementById("record-count");
const printAllBtn = document.getElementById("print-all-btn");

async function loadEmployees() {
    const select = document.getElementById("employee_id");
    const cur = select.value;
    try {
        const { data } = await api.get("employees.php", { params: { simple: 1 } });
        select.innerHTML = `<option value="">-- Select an employee --</option>` +
            data.data.map((e) => `<option value="${e.id}">${escapeHtml(e.label)}</option>`).join("");
        if (cur) select.value = cur;
    } catch (err) {
        showToast("Could not load employees: " + apiErrorMessage(err), "error");
    }
}

async function loadCodes() {
    gridEl.innerHTML = `<div style="text-align:center;padding:30px;color:#888;">Loading…</div>`;
    try {
        const { data } = await api.get("qr_codes.php");
        renderCards(data.data);
    } catch (err) {
        gridEl.innerHTML = `<div style="text-align:center;padding:30px;color:#CE1126;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
}

function renderCards(codes) {
    const byEmployee = {};
    codes.forEach((c) => {
        if (!byEmployee[c.employee_id]) byEmployee[c.employee_id] = [];
        byEmployee[c.employee_id].push(c);
    });

    const employees = Object.keys(byEmployee).map((id) => {
        const list = byEmployee[id];
        const active = list.find((c) => c.status === "Active");
        return { sample: list[0], active };
    });

    if (employees.length === 0) {
        gridEl.innerHTML = `<div style="text-align:center;padding:40px;color:#888;">
            No QR codes yet. Generate one using the form above.
        </div>`;
        countEl.textContent = "0 QR code(s)";
        printAllBtn.disabled = true;
        return;
    }

    const activeCount = codes.filter((c) => c.status === "Active").length;
    countEl.textContent = `${activeCount} active QR code(s)`;

    gridEl.innerHTML = employees.map(({ sample, active }) => {
        const isActive = active && active.status === "Active";
        const codeValue = isActive ? active.code_value : "";

        return `
            <div class="qr-card">
                <div class="qr-card-header">
                    <div class="qr-card-name">${escapeHtml(sample.employee_name)}</div>
                    <div class="qr-card-num">${escapeHtml(sample.employee_number)}</div>
                    <div class="qr-card-dept">${escapeHtml(sample.department_name ?? "—")}</div>
                </div>
                <div class="qr-code-area" data-code="${escapeHtml(codeValue)}">
                    ${isActive ? `<div class="qr-canvas-holder"></div>` : `<div class="qr-empty">No active QR</div>`}
                </div>
                <div class="qr-card-footer">
                    ${isActive
                        ? `<button class="btn btn-plain" data-revoke="${active.qr_code_id}">Revoke</button>
                           <button class="btn btn-plain" data-regen="${sample.employee_id}">Regenerate</button>`
                        : `<button class="btn btn-primary" data-gen="${sample.employee_id}">Generate QR</button>`}
                </div>
            </div>
        `;
    }).join("");

    gridEl.querySelectorAll(".qr-canvas-holder").forEach((holder) => {
        const area = holder.closest(".qr-code-area");
        const code = area.dataset.code;
        if (!code) return;
        holder.innerHTML = "";
        new QRCode(holder, {
            text: code,
            width: 140,
            height: 140,
            colorDark: "#1e3a6b",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H,
        });
    });

    gridEl.querySelectorAll("[data-gen]").forEach((btn) => {
        btn.addEventListener("click", () => generateFor(Number(btn.dataset.gen)));
    });
    gridEl.querySelectorAll("[data-regen]").forEach((btn) => {
        btn.addEventListener("click", () => generateFor(Number(btn.dataset.regen)));
    });
    gridEl.querySelectorAll("[data-revoke]").forEach((btn) => {
        btn.addEventListener("click", () => revoke(Number(btn.dataset.revoke)));
    });

    printAllBtn.disabled = activeCount === 0;
}

async function generateFor(employeeId) {
    if (!await confirmDialog(
        "Generate a QR code for this employee? Any existing active QR will be revoked.",
        { title: "Generate QR Code", icon: "question", confirmText: "Generate" }
    )) return;

    try {
        const { data } = await api.post("qr_codes.php", { employee_id: employeeId });
        showToast("QR code generated.", "success");
        await loadCodes();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

async function revoke(id) {
    if (!await confirmDialog(
        "Revoke this QR code? It will no longer work at the scanner.",
        { title: "Revoke QR", icon: "warning", confirmText: "Revoke" }
    )) return;

    try {
        const { data } = await api.patch("qr_codes.php", { qr_code_id: id, status: "Revoked" });
        showToast(data.message, "success");
        await loadCodes();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const empId = document.getElementById("employee_id").value;
    if (!empId) return;
    await generateFor(Number(empId));
    form.reset();
});

printAllBtn.addEventListener("click", () => {
    document.body.dataset.printTarget = "qr";
    window.print();
});
window.addEventListener("afterprint", () => { delete document.body.dataset.printTarget; });

document.addEventListener("DOMContentLoaded", () => {
    loadEmployees();
    loadCodes();
});