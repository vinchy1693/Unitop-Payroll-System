// =====================================================================
// assets/js/api.js
// Shared axios instance + helpers (including SweetAlert dialogs)
// =====================================================================

const api = axios.create({
    baseURL: "api/",
    headers: { "Content-Type": "application/json" },
});

// ---------------------------------------------------------------------
// CSRF: the server publishes a per-session token in a readable cookie and
// requires it in X-CSRF-Token on every non-GET request. A cross-site page
// can make the browser send the cookie, but it cannot read it (same-origin
// policy), so it cannot forge the header.
// ---------------------------------------------------------------------
function readCookie(name) {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const match = document.cookie.match(new RegExp("(?:^|; )" + escaped + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : "";
}

api.interceptors.request.use((config) => {
    const token = readCookie("admin_csrf");
    if (token) {
        config.headers = config.headers || {};
        config.headers["X-CSRF-Token"] = token;
    }
    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            window.location.href = "login.html";
        }
        return Promise.reject(error);
    }
);

// ---------------------------------------------------------------------
// Text utilities
// ---------------------------------------------------------------------
function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function apiErrorMessage(err) {
    return err?.response?.data?.message || err.message || "Something went wrong.";
}

// ---------------------------------------------------------------------
// Inline banner (kept for backward compatibility — not used anymore
// in most pages since we switched to toasts)
// ---------------------------------------------------------------------
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

// ---------------------------------------------------------------------
// SweetAlert2 helpers
// ---------------------------------------------------------------------

/**
 * Confirmation dialog. Returns true if user confirms, false otherwise.
 * Usage: if (!await confirmDialog("Deactivate this?")) return;
 */
async function confirmDialog(message, options = {}) {
    const result = await Swal.fire({
        title: options.title || "Are you sure?",
        text: message,
        icon: options.icon || "question",
        showCancelButton: true,
        confirmButtonText: options.confirmText || "Yes, proceed",
        cancelButtonText: options.cancelText || "Cancel",
        confirmButtonColor: "#0038A8",
        cancelButtonColor: "#6b7280",
        reverseButtons: true,
        focusCancel: true,
    });
    return result.isConfirmed;
}

/**
 * Info / error / success dialog.
 * Usage: await alertDialog("Something went wrong", "error");
 */
async function alertDialog(message, type = "info", title = null) {
    const titles = {
        success: title || "Success",
        error:   title || "Oops!",
        warning: title || "Warning",
        info:    title || "Note",
    };
    await Swal.fire({
        title: titles[type],
        text: message,
        icon: type,
        confirmButtonColor: "#0038A8",
        confirmButtonText: "OK",
    });
}

/**
 * Toast notification — slides in from top-right.
 * Usage: showToast("Record saved", "success");
 */
function showToast(message, type = "success") {
    Swal.fire({
        toast: true,
        position: "top-end",
        icon: type,
        title: message,
        showConfirmButton: false,
        timer: 2500,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener("mouseenter", Swal.stopTimer);
            toast.addEventListener("mouseleave", Swal.resumeTimer);
        },
    });
}

// ---------------------------------------------------------------------
// Navigation highlighting
// ---------------------------------------------------------------------
function highlightNav() {
    const page = location.pathname.split("/").pop() || "index.html";
    document.querySelectorAll("nav a[data-page]").forEach((a) => {
        if (a.dataset.page === page) a.classList.add("active");
    });
}

// ---------------------------------------------------------------------
// User info + logout (injected into sidebar)
// ---------------------------------------------------------------------
async function injectNavUser() {
    const nav = document.querySelector("nav");
    if (!nav) return;

    nav.querySelectorAll(".nav-user").forEach((el) => el.remove());

    try {
        const { data } = await api.get("auth.php?action=me");
        const user = data.data;

        const wrap = document.createElement("div");
        wrap.className = "nav-user";
        wrap.innerHTML = `
            <span class="nav-user-info">
                <span class="nav-user-name">${escapeHtml(user.username)}</span>
                <span class="nav-user-role">${escapeHtml(user.role || "")}</span>
            </span>
            <a href="api/auth.php?action=logout" class="nav-logout" id="nav-logout-btn"
               onclick="return confirm('Log out of the payroll system?');">Log out</a>
        `;
        nav.appendChild(wrap);
    } catch (err) {
        // 401 handled by interceptor
    }
}

// ---------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------
document.addEventListener("DOMContentLoaded", () => {
    highlightNav();
    injectNavUser();
});