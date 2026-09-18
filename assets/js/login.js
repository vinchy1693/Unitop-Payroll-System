// =====================================================================
// assets/js/login.js
// Login page — uses a bare axios instance (no auth interceptor).
// =====================================================================

const api = axios.create({
    baseURL: "api/",
    headers: { "Content-Type": "application/json" },
});

const form     = document.getElementById("login-form");
const submitBtn = document.getElementById("login-btn");
const msgEl    = document.getElementById("msg");

function showMsg(text, type = "error") {
    msgEl.textContent = text;
    msgEl.className = `msg ${type}`;
    msgEl.classList.remove("hidden");
}

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const username = document.getElementById("username").value.trim();
    const password = document.getElementById("password").value;

    if (!username || !password) return;

    submitBtn.disabled = true;
    submitBtn.textContent = "Logging in…";

    try {
        const { data } = await api.post("auth.php?action=login", { username, password });
        showMsg(data.message || "Login successful. Redirecting…", "success");
        setTimeout(() => { window.location.href = "index.html"; }, 400);
    } catch (err) {
        const text = err?.response?.data?.message || err.message || "Login failed.";
        showMsg(text, "error");
        submitBtn.disabled = false;
        submitBtn.textContent = "Log In";
    }
});

// If already logged in, skip the login page
document.addEventListener("DOMContentLoaded", async () => {
    try {
        await api.get("auth.php?action=me");
        // Successful = we have a session, redirect to dashboard
        window.location.href = "index.html";
    } catch (err) {
        // 401 = not logged in yet, stay on this page
    }
});