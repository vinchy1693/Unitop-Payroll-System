// =====================================================================
// assets/js/nav.js
//
// Role-aware navigation for every admin page (segregation of duties).
//
// The sidebar is static HTML, so without this a role sees links it cannot
// open and every click answers 403. Payroll can open operational pages but
// not Users/Audit; Administrator can open every admin page.
//
// This script:
//   1. resolves the current role ONCE per page load (auth.php?action=me),
//      caching the request for every other script on the page
//   2. hides (never removes) the links that role may not use, from the single
//      table below, keyed by each link's existing data-page value
//   3. keeps the nav invisible until that decision is made: pages load this
//      script with <nav class="sidebar nav-locked">, so no forbidden link can
//      flash into view or be clicked first. The script tag's onerror handler
//      removes the lock if this file cannot load, and a failure to resolve the
//      role leaves the nav exactly as it is rather than stranding the user.
//   4. replaces the page body with a clear "no access" message when a role
//      opens a page directly (typed URL, bookmark) that it may not use
//
// The roles are the ones api/_bootstrap.php defines:
//   MAKER_ROLES      = Payroll Officer
//   CHECKER_ROLES    = Finance & Accounting
//   SUPERVISOR_ROLE  = Supervisor
//   ADMIN_ROLES      = Administrator
// Every page whose API requires PAYROLL_ROLES is visible to maker + checker.
// A Supervisor is a team lead, so they get a team-scoped Dashboard plus the
// five team screens (Attendance, Overtime, Attendance Corrections, Leave,
// Loans) and the kiosk: never Employees, Payroll Periods / Runs, Payslips,
// Reports, Users or Audit Log. Their API answers match - dashboard.php returns
// a reduced team payload, and the payroll-only endpoints still require
// PAYROLL_ROLES, which does not include Supervisor.
// The Administrator can open every admin page. Users and Audit Log remain
// available only to the Administrator; Employee accounts use the portal.
//
// data-page values are a FROZEN contract: assets/js/api.js highlightNav() and
// the active-link styling depend on them, so entries below must match the
// existing values exactly. tests/nav_matrix_test.php proves the table covers
// every data-page in the HTML and matches the API guard page by page.
//
// The table is strict JSON between the two markers below: the test decodes it.
// =====================================================================

// NAV_ACCESS_BEGIN
const NAV_ACCESS = {
    "index.html":                  ["Payroll Officer", "Manager", "Finance & Accounting", "Administrator"],
    "departments.html":            ["Payroll Officer", "Administrator"],
    "positions.html":              ["Payroll Officer", "Administrator"],
    "employees.html":              ["Payroll Officer", "Manager", "Finance & Accounting", "Administrator"],
    "attendance.html":             ["Payroll Officer", "Manager", "Finance & Accounting", "Administrator"],
    "qr_codes.html":               ["Manager", "Administrator"],
    "leave.html":                  ["Payroll Officer", "Manager", "Administrator"],
    "leave_types.html":            ["Payroll Officer", "Administrator"],
    "leave_balances.html":         ["Payroll Officer", "Administrator"],
    "overtime.html":               ["Payroll Officer", "Manager", "Administrator"],
    "attendance_corrections.html": ["Manager", "Administrator"],
    "loans.html":                  ["Payroll Officer", "Manager", "Finance & Accounting", "Administrator"],
    "payroll_periods.html":        ["Payroll Officer", "Administrator"],
    "payroll_runs.html":           ["Payroll Officer", "Finance & Accounting", "Administrator"],
    "payslips.html":               ["Payroll Officer", "Finance & Accounting", "Administrator"],
    "ledger.html":                 ["Payroll Officer", "Finance & Accounting", "Administrator"],
    "reports.html":                ["Payroll Officer", "Finance & Accounting", "Administrator"],
    "users.html":                  ["Administrator"],
    "audit.html":                  ["Administrator"],
    "kiosk.html":                  ["Manager", "Administrator"]
};
// NAV_ACCESS_END

(function () {
    // One auth.php?action=me request per page load, shared by every script.
    var userPromise = null;

    function currentUser() {
        if (!userPromise) {
            userPromise = api.get("auth.php?action=me")
                .then(function (response) {
                    var payload = response && response.data ? response.data.data : null;
                    return payload && payload.role ? payload : null;
                })
                .catch(function () { return null; });
        }
        return userPromise;
    }

    function currentRole() {
        return currentUser().then(function (user) {
            return user && user.role ? String(user.role) : null;
        });
    }

    function pageFromPath() {
        return location.pathname.split("/").pop() || "index.html";
    }

    /** Allowed roles for a page, or null when the page is not in the table. */
    function allowedRoles(page) {
        return Object.prototype.hasOwnProperty.call(NAV_ACCESS, page) ? NAV_ACCESS[page] : null;
    }

    function canAccess(role, page) {
        var allowed = allowedRoles(page);
        if (allowed === null) return true;              // unknown page: never hide
        if (!role) return true;                         // unresolved: leave as-is
        return allowed.indexOf(role) !== -1;
    }

    /** Hide the links this role may not use; drop section headers with none left. */
    function filterLinks(role) {
        var links = document.querySelectorAll("nav.sidebar .sidebar-links a[data-page]");
        var i;
        for (i = 0; i < links.length; i++) {
            var link = links[i];
            if (!canAccess(role, link.dataset.page)) {
                link.classList.add("nav-hidden");       // hidden, never removed
            }
        }

        var headers = document.querySelectorAll("nav.sidebar .sidebar-links .nav-section");
        for (i = 0; i < headers.length; i++) {
            var header = headers[i];
            var node = header.nextElementSibling;
            var visible = false;
            while (node && !node.classList.contains("nav-section")) {
                if (node.tagName === "A" && !node.classList.contains("nav-hidden")) {
                    visible = true;
                }
                node = node.nextElementSibling;
            }
            // The theme toggle (a <button>) is not counted, so it stays usable.
            if (!visible) header.classList.add("nav-hidden");
        }
    }

    function unlock() {
        var navs = document.querySelectorAll("nav.sidebar.nav-locked");
        for (var i = 0; i < navs.length; i++) navs[i].classList.remove("nav-locked");
    }

    function pageLabel(page) {
        var link = document.querySelector('nav.sidebar a[data-page="' + page + '"]');
        if (link && link.textContent) return link.textContent.trim();
        return page;
    }

    function roleLabel(roles) {
        if (!roles || roles.length === 0) return "no role";
        if (roles.length === 1) return roles[0];
        return roles.slice(0, -1).join(", ") + " or " + roles[roles.length - 1];
    }

    /** Clear message instead of a raw API error / broken table. */
    function guardPage(role) {
        var page = pageFromPath();
        var allowed = allowedRoles(page);
        if (allowed === null || canAccess(role, page)) return;

        var container = document.querySelector(".container");
        if (!container) return;

        var card = document.createElement("div");
        card.className = "card";
        card.id = "access-denied-card";
        card.setAttribute("role", "alert");
        card.innerHTML =
            '<h2 style="margin-top:0;">You do not have access to this page</h2>'
            + '<p style="margin:0 0 10px;">The <strong>' + escapeHtml(pageLabel(page))
            + '</strong> page is limited to <strong>' + escapeHtml(roleLabel(allowed))
            + '</strong>. Your role is <strong>' + escapeHtml(String(role || "unknown"))
            + '</strong>.</p>'
            + '<p style="margin:0;color:var(--muted);font-size:13px;">'
            + 'Use the navigation to open a page your role can use.</p>';

        // Hide whatever the page had already rendered (tables, error rows).
        var children = Array.prototype.slice.call(container.children);
        for (var i = 0; i < children.length; i++) {
            if (children[i] !== card) children[i].classList.add("hidden");
        }
        container.appendChild(card);
    }

    function init() {
        currentRole().then(function (role) {
            if (role) {
                filterLinks(role);
                guardPage(role);
            }
            unlock();   // never leave the nav invisible
        }).catch(function () {
            unlock();
        });
    }

    // Exposed so page scripts reuse this one resolved identity.
    window.dshCurrentUser = currentUser;
    window.dshCurrentRole = currentRole;
    window.dshNavAccess   = NAV_ACCESS;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
