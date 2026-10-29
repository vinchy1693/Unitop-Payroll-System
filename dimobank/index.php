<?php
// =====================================================================
// dimobank/index.php
// Red/white demo bank console. Finance & Accounting or Administrator.
// =====================================================================
define("AUTH_ENDPOINT", true);
require __DIR__ . "/../api/_bootstrap.php";
require __DIR__ . "/state.php";

header("Content-Type: text/html; charset=utf-8");

$user = currentUser();
if (!$user) {
    header("Location: ../login.html");
    exit;
}

$isFinance = in_array((string) ($user["role"] ?? ""), [FINANCE_ROLE, "Administrator"], true);
if (!$isFinance) {
    http_response_code(403);
}
$state = dimobank_read_state();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DimoBank — Demo Bank Console</title>
<style>
    :root {
        --red: #b00020;
        --red-dark: #7d0017;
        --red-soft: #fdecef;
        --ink: #1f1f1f;
        --muted: #6b6b6b;
        --line: #ead6da;
        --white: #ffffff;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        font-family: "Segoe UI", Arial, sans-serif;
        background: #f4f5f7;
        color: var(--ink);
    }
    .topbar {
        background: var(--red);
        color: var(--white);
        padding: 18px 26px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }
    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 800;
        letter-spacing: 1.5px;
        font-size: 20px;
    }
    .brand-mark {
        width: 38px; height: 38px;
        display: grid; place-items: center;
        background: var(--white);
        color: var(--red);
        border-radius: 9px;
        font-size: 18px;
        font-weight: 900;
    }
    .brand small {
        display: block;
        font-size: 10px;
        letter-spacing: 2px;
        opacity: .78;
        font-weight: 600;
        margin-top: 2px;
    }
    .topbar .user {
        font-size: 12.5px;
        opacity: .92;
        text-align: right;
    }
    .wrap {
        max-width: 760px;
        margin: 38px auto;
        padding: 0 20px;
    }
    .card {
        background: var(--white);
        border: 1px solid var(--line);
        border-top: 5px solid var(--red);
        border-radius: 12px;
        box-shadow: 0 10px 28px rgba(120, 0, 24, .10);
        padding: 30px;
    }
    h1 { margin: 0 0 6px; font-size: 26px; color: var(--red); }
    .sub { margin: 0 0 24px; color: var(--muted); font-size: 14px; line-height: 1.6; }
    .status-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        border: 1px solid var(--line);
        background: var(--red-soft);
        border-radius: 10px;
        padding: 16px 18px;
        margin-bottom: 20px;
    }
    .status-copy span {
        display: block;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 1.3px;
        font-size: 10.5px;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .status-copy strong { font-size: 20px; }
    .pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border-radius: 999px;
        padding: 7px 13px;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .5px;
        text-transform: uppercase;
    }
    .pill::before {
        content: "";
        width: 8px; height: 8px;
        border-radius: 50%;
        background: currentColor;
    }
    .pill.online { background: #e7f6ec; color: #17753a; }
    .pill.offline { background: #fbe4e7; color: var(--red); }
    .meta {
        color: var(--muted);
        font-size: 12.5px;
        margin: -8px 0 20px;
    }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    button {
        border: 0;
        border-radius: 7px;
        padding: 11px 18px;
        font: inherit;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
    }
    button:disabled { opacity: .55; cursor: wait; }
    .primary { background: var(--red); color: var(--white); }
    .primary:hover { background: var(--red-dark); }
    .plain { background: var(--white); color: var(--red); border: 1px solid var(--red); }
    .plain:hover { background: var(--red-soft); }
    .link-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 43px;
        padding: 10px 18px;
        border: 1px solid var(--red);
        border-radius: 7px;
        background: var(--white);
        color: var(--red);
        font-size: 13.5px;
        font-weight: 700;
        text-decoration: none;
    }
    .link-button:hover { background: var(--red-soft); }
    .back {
        color: var(--red);
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
    }
    .back:hover { text-decoration: underline; }
    .notice {
        margin-top: 22px;
        border-left: 4px solid var(--red);
        background: #fff8f9;
        padding: 13px 15px;
        color: #5e2730;
        font-size: 13px;
        line-height: 1.6;
        border-radius: 0 7px 7px 0;
    }
    .error {
        display: none;
        margin-top: 16px;
        background: #fdecef;
        border: 1px solid #efb5bf;
        color: #8b1024;
        border-radius: 7px;
        padding: 11px 13px;
        font-size: 13px;
        line-height: 1.5;
    }
    .error.show { display: block; }
    .forbidden { max-width: 620px; margin: 60px auto; padding: 0 20px; }
    .forbidden .card { border-top-color: var(--red); }
    @media (max-width: 560px) {
        .topbar { padding: 15px 18px; }
        .wrap { margin: 22px auto; }
        .card { padding: 22px 20px; }
        h1 { font-size: 22px; }
        .status-copy strong { font-size: 17px; }
    }
</style>
</head>
<body>

<header class="topbar">
    <div class="brand">
        <div class="brand-mark">D</div>
        <div>DIMO BANK<small>DEMO BANK CONSOLE</small></div>
    </div>
    <div class="user">
        Signed in as <strong><?= htmlspecialchars((string) ($user["username"] ?? "")) ?></strong><br>
        <?= htmlspecialchars((string) ($user["role"] ?? "")) ?>
    </div>
</header>

<main class="wrap">
    <div class="card">
        <?php if (!$isFinance): ?>
            <h1>Access restricted</h1>
            <p class="sub">
                DimoBank controls are available only to Finance &amp; Accounting.
                This page cannot change payroll release status for your role.
            </p>
            <a class="back" href="../index.html">← Back to Dashboard</a>
        <?php else: ?>
            <h1>Bank status</h1>
            <p class="sub">
                Use this demo console to simulate whether DimoBank can accept payroll
                payouts. When the bank is offline, payroll release is blocked and
                returned to Finance &amp; Accounting.
            </p>

            <div class="status-box">
                <div class="status-copy">
                    <span>Current status</span>
                    <strong id="status-label"><?= $state["online"] ? "Online" : "Offline" ?></strong>
                </div>
                <div id="status-pill" class="pill <?= $state["online"] ? "online" : "offline" ?>">
                    <?= $state["online"] ? "Accepting payouts" : "Rejecting payouts" ?>
                </div>
            </div>

            <p class="meta" id="updated-at">
                <?php if (!empty($state["updated_at"])): ?>
                    Last changed: <?= htmlspecialchars(date("M j, Y g:i A", strtotime($state["updated_at"]))) ?>
                <?php else: ?>
                    Last changed: not changed yet
                <?php endif; ?>
            </p>

            <div class="actions">
                <button type="button" class="primary" id="toggle-btn" data-online="<?= $state["online"] ? "1" : "0" ?>">
                    <?= $state["online"] ? "Take DimoBank offline" : "Bring DimoBank online" ?>
                </button>
                <a class="link-button" href="../payroll_runs.html">Payroll Runs</a>
            </div>

            <div class="notice">
                <strong>How this demo works:</strong> release is checked on the server.
                If DimoBank is offline, the payroll run stays <strong>Approved</strong>
                and Finance sees a bank-unavailable message. Bring the bank online, then
                retry the release.
            </div>

            <div id="error-box" class="error" role="alert"></div>
        <?php endif; ?>
    </div>
</main>

<?php if ($isFinance): ?>
<script>
(function () {
    var toggle = document.getElementById("toggle-btn");
    var label = document.getElementById("status-label");
    var pill = document.getElementById("status-pill");
    var updated = document.getElementById("updated-at");
    var errorBox = document.getElementById("error-box");
    var csrf = <?= json_encode($csrf) ?>;

    function render(state) {
        var online = !!state.online;
        label.textContent = online ? "Online" : "Offline";
        pill.textContent = online ? "Accepting payouts" : "Rejecting payouts";
        pill.className = "pill " + (online ? "online" : "offline");
        toggle.dataset.online = online ? "1" : "0";
        toggle.textContent = online ? "Take DimoBank offline" : "Bring DimoBank online";
        updated.textContent = state.updated_at
            ? "Last changed: " + new Date(state.updated_at).toLocaleString()
            : "Last changed: not changed yet";
    }

    toggle.addEventListener("click", async function () {
        var currentlyOnline = toggle.dataset.online === "1";
        toggle.disabled = true;
        errorBox.className = "error";
        try {
            var response = await fetch("api.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrf
                },
                body: JSON.stringify({ online: !currentlyOnline })
            });
            var body = await response.json();
            if (!response.ok || !body.success) {
                throw new Error(body.message || "The bank status could not be updated.");
            }
            render(body.data);
        } catch (err) {
            errorBox.textContent = err.message || "The bank status could not be updated.";
            errorBox.className = "error show";
        } finally {
            toggle.disabled = false;
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
