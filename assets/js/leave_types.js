// =====================================================================
// assets/js/leave_types.js
//
// Fixed leave catalogue: READ-ONLY. No add, edit, deactivate or restore.
// =====================================================================

const rowsEl  = document.getElementById("rows");
const countEl = document.getElementById("record-count");

function serviceLabel(months) {
    const n = Number(months || 0);
    if (!n) return "None";
    if (n % 12 === 0) return `${n / 12} year${n === 12 ? "" : "s"}`;
    return `${n} month${n === 1 ? "" : "s"}`;
}

function renderRows(rows) {
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

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading…</td></tr>`;
    try {
        const { data } = await api.get("leave_types.php");
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed to load: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

document.addEventListener("DOMContentLoaded", loadRows);
