// =====================================================================
// assets/js/api.js
// Shared axios instance + helpers. Also injects the user/logout UI
// into the nav on every page that includes it.
// =====================================================================

const api = axios.create({
    baseURL: "api/",
    headers: { "Content-Type": "application/json" },
});

// ---- 401 interceptor: kick to login if the session expires ----
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            window.location.href = "login.html";
        }
        return Promise.reject(error);
    }
);

/** Escape text before injecting into innerHTML. */
function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

/** Show a success/error banner in the given element id (default #msg). */
function showMessage(text, type = "success", elId = "msg") {
    const el = document.getElementById(elId);
    if (!el) return;
    el.textContent = text;
    el.className = `msg ${type}`;
    el.classList.remove("hidden");
    if (type === "success") {
        clearTimeout(el._hideTimer);
        el._hideTimer = setTimeout(() => el.classList.add("hidden"), 3500);
    }
}

/** Pull a human-readable message out of an axios error. */
function apiErrorMessage(err) {
    return err?.response?.data?.message || err.message || "Something went wrong.";
}

/** Mark the current page's nav link as active based on the file name. */
function highlightNav() {
    const page = location.pathname.split("/").pop() || "index.html";
    document.querySelectorAll("nav a[data-page]").forEach((a) => {
        if (a.dataset.page === page) a.classList.add("active");
    });
}

/**
 * Inject the logged-in user's name and a logout button into the nav.
 * Called on every page that includes api.js.
 */
async function injectNavUser() {
    const nav = document.querySelector("nav");
    if (!nav) return;

    // Remove any old injection
    nav.querySelectorAll(".nav-user").forEach((el) => el.remove());

    try {
        const { data } = await api.get("auth.php?action=me");
        const user = data.data;

        const wrap = document.createElement("div");
        wrap.className = "nav-user";
        wrap.innerHTML = `
            <span class="nav-user-info">
                <span class="nav-user-name">${escapeHtml(user.username)}</span>
                <span class="nav-user-role">${escapeHtml(user.role)}</span>
            </span>
            <a href="#" class="nav-logout" id="nav-logout-btn">Log out</a>
        `;
        nav.appendChild(wrap);

        wrap.querySelector("#nav-logout-btn").addEventListener("click", async (e) => {
            e.preventDefault();
            if (!confirm("Log out of the payroll system?")) return;
            try {
                await api.post("auth.php?action=logout");
            } catch (_) { /* ignore */ }
            window.location.href = "login.html";
        });
    } catch (err) {
        // 401 is handled by the interceptor — this is a fallback for other errors
    }
}

document.addEventListener("DOMContentLoaded", () => {
    highlightNav();
    injectNavUser();
});