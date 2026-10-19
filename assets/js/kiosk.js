// =====================================================================
// kiosk.js - the attendance kiosk.
//
// Job-site screen: the ONLY actions available are (a) scan a QR code with the
// camera and (b) type/paste a QR code value. Everything else is hidden until
// the operator signs out.
//
// It posts to the same endpoint the admin scanner uses (scan_attendance.php),
// which decides between clock-in, clock-out and the overtime states:
//   in  / out              normal shift
//   ot_request             employee asked for overtime (waiting for approval)
//   ot_rejected            last request was rejected; a new one was filed
//   ot_in / ot_out         approved overtime started / ended (hours computed)
// =====================================================================
(function () {
    "use strict";

    var API = "api/";
    var IGNORE_MS = 5000;   // ignore the same code again within this window
    var RESET_MS  = 6000;   // how long the result stays on screen

    var el = {
        boot:      document.getElementById("boot"),
        signin:    document.getElementById("signin"),
        shell:     document.getElementById("kiosk"),
        time:      document.getElementById("kiosk-time"),
        date:      document.getElementById("kiosk-date"),
        exit:      document.getElementById("kiosk-exit"),
        result:    document.getElementById("kiosk-result"),
        rIcon:     document.getElementById("kiosk-result-icon"),
        rTitle:    document.getElementById("kiosk-result-title"),
        rDetail:   document.getElementById("kiosk-result-detail"),
        reader:    document.getElementById("reader"),
        start:     document.getElementById("kiosk-start"),
        stop:      document.getElementById("kiosk-stop"),
        camState:  document.getElementById("kiosk-cam-state"),
        form:      document.getElementById("kiosk-form"),
        code:      document.getElementById("kiosk-code"),
        submit:    document.getElementById("kiosk-submit"),
        unlock:       document.getElementById("unlock"),
        unlockForm:   document.getElementById("unlock-form"),
        unlockUser:   document.getElementById("unlock-user"),
        unlockPass:   document.getElementById("unlock-pass"),
        unlockMsg:    document.getElementById("unlock-msg"),
        unlockCancel: document.getElementById("unlock-cancel"),
        demoToggle:   document.getElementById("demo-toggle"),
        demoPanel:    document.getElementById("demo-panel"),
        demoDate:     document.getElementById("demo-date"),
        demoTime:     document.getElementById("demo-time")
    };

    var scanner = null;
    var running = false;
    var busy = false;
    var lastCode = null;
    var lastAt = 0;
    var resetTimer = null;

    // ---------- clock ----------
    function tick() {
        var now = new Date();
        el.time.textContent = now.toLocaleTimeString();
        el.date.textContent = now.toLocaleDateString(undefined, {
            weekday: "long", year: "numeric", month: "long", day: "numeric"
        });
    }
    tick();
    setInterval(tick, 1000);

    // ---------- result banner ----------
    function clearResult() {
        if (resetTimer) { clearTimeout(resetTimer); resetTimer = null; }
        el.result.className = "kiosk-result hidden";
    }

    function showResult(kind, title, detail) {
        if (resetTimer) clearTimeout(resetTimer);
        el.result.className = "kiosk-result " + kind;
        el.rIcon.textContent = kind === "error" ? "X" : "OK";
        el.rTitle.textContent = title;
        el.rDetail.textContent = detail || "";
        resetTimer = setTimeout(function () {
            clearResult();
            el.code.value = "";
            el.code.focus();
        }, RESET_MS);
    }

    // ---------- submit one code ----------
    function shouldIgnore(code) {
        var now = Date.now();
        if (code === lastCode && (now - lastAt) < IGNORE_MS) return true;
        lastCode = code;
        lastAt = now;
        return false;
    }

    function submitCode(code, fromCamera) {
        code = (code || "").trim();
        if (code === "") {
            showResult("error", "No code entered", "Type or paste your QR code value.");
            return;
        }
        if (busy) return;
        if (fromCamera && shouldIgnore(code)) return;

        busy = true;
        el.submit.disabled = true;

        // Demo mode lets a demo supply the date and time. It is OFF unless the
        // operator explicitly opens the strip and fills a field, and the server
        // should set ALLOW_DEMO_SCANS to false on a real deployment, which makes
        // these fields (and this whole feature) inert.
        var payload = code.indexOf("UNITOP-T-") === 0
            ? { qr_token: code }
            : { code_value: code };
        if (!el.demoPanel.classList.contains("hidden")) {
            if (el.demoDate.value) { payload.demo_mode = true; payload.demo_date = el.demoDate.value; }
            if (el.demoTime.value) { payload.demo_mode = true; payload.demo_time = el.demoTime.value; }
        }

        axios.post(API + "scan_attendance.php", payload)
            .then(function (res) {
                var d = res.data.data || {};
                if (d.action === "in") {
                    var late = d.late_minutes > 0 ? "  late by " + d.late_minutes + " min" : "";
                    showResult("in", "Welcome, " + d.employee_name + "!",
                        "Clocked IN at " + d.time + late);
                } else if (d.action === "out") {
                    showResult("out", "Goodbye, " + d.employee_name + "!",
                        "Clocked OUT at " + d.time + "  " + d.hours_worked + " hrs");
                } else if (d.action === "ot_request") {
                    showResult("in", "Overtime request submitted",
                        "Waiting for approval. You have not started overtime yet.");
                } else if (d.action === "ot_rejected") {
                    showResult("in", "New overtime request submitted",
                        "Your last request was not approved. Waiting for approval.");
                } else if (d.action === "ot_in") {
                    showResult("in", "Overtime started",
                        "Started at " + d.time + ". Scan again when your overtime ends.");
                } else if (d.action === "ot_out") {
                    showResult("out", "Overtime ended",
                        Number(d.hours).toFixed(2) + " hour(s) recorded.");
                } else {
                    showResult("in", d.employee_name || "Recorded", res.data.message || "");
                }
                el.code.value = "";
            })
            .catch(function (err) {
                var payload = (err.response && err.response.data) ? err.response.data : null;
                var msg = payload && payload.message
                    ? payload.message
                    : "Could not record attendance. Please ask HR.";
                var act = payload && payload.data ? payload.data.action : null;
                var title = act === "ot_pending" ? "Still awaiting approval" : "Not recorded";
                showResult("error", title, msg);
            })
            .then(function () {
                busy = false;
                el.submit.disabled = false;
            });
    }

    // ---------- camera ----------
    function startCamera() {
        if (running) return;
        el.camState.textContent = "Starting camera";

        try {
            scanner = new Html5Qrcode("reader");
        } catch (e) {
            el.camState.textContent = "Camera unavailable";
            return;
        }

        scanner.start(
            { facingMode: "environment" },
            { fps: 10, qrbox: { width: 240, height: 240 } },
            function (text) { submitCode(text, true); },
            function () { /* per-frame decode errors are noise */ }
        ).then(function () {
            running = true;
            el.camState.textContent = "Scanning";
            el.start.classList.add("hidden");
            el.stop.classList.remove("hidden");
        }).catch(function () {
            el.camState.textContent = "Camera unavailable - type the code instead";
            el.start.classList.remove("hidden");
            el.stop.classList.add("hidden");
            running = false;
        });
    }

    function stopCamera() {
        if (!scanner || !running) return;
        scanner.stop().then(function () {
            try { scanner.clear(); } catch (e) {}
            scanner = null;
            running = false;
            el.camState.textContent = "Camera off";
            el.start.classList.remove("hidden");
            el.stop.classList.add("hidden");
        }).catch(function () {
            scanner = null;
            running = false;
            el.camState.textContent = "Camera off";
            el.start.classList.remove("hidden");
            el.stop.classList.add("hidden");
        });
    }

    // ---------- wiring ----------
    el.demoToggle.addEventListener("click", function () {
        var hidden = el.demoPanel.classList.toggle("hidden");
        el.demoToggle.classList.toggle("is-on", !hidden);
        el.demoToggle.textContent = hidden ? "Demo mode" : "Demo mode: on";
        if (!hidden && !el.demoDate.value) {
            // Local YYYY-MM-DD. Do not reach for a ymd() helper: there is none in
            // this file (that lives in the portal), and optional chaining does
            // not protect an undeclared identifier - it throws.
            var t = new Date();
            el.demoDate.value = t.getFullYear() + "-"
                + String(t.getMonth() + 1).padStart(2, "0") + "-"
                + String(t.getDate()).padStart(2, "0");
        }
    });

    el.start.addEventListener("click", startCamera);
    el.stop.addEventListener("click", stopCamera);

    el.form.addEventListener("submit", function (e) {
        e.preventDefault();
        submitCode(el.code.value, false);
        el.code.value = "";
    });

    // Exiting kiosk mode requires administrator credentials, so an employee at
    // the terminal cannot walk away with the shared session or the admin panel.
    el.exit.addEventListener("click", function (e) {
        e.preventDefault();
        clearResult();
        el.unlockMsg.textContent = "";
        el.unlockUser.value = "";
        el.unlockPass.value = "";
        el.unlock.classList.remove("hidden");
        el.unlockUser.focus();
    });

    el.unlockCancel.addEventListener("click", function () {
        el.unlock.classList.add("hidden");
        el.code.focus();
    });

    el.unlockForm.addEventListener("submit", function (e) {
        e.preventDefault();
        el.unlockMsg.textContent = "";
        var btn = el.unlockForm.querySelector('button[type="submit"]');
        btn.disabled = true;

        axios.post(API + "auth.php?action=verify", {
            username: el.unlockUser.value.trim(),
            password: el.unlockPass.value
        }).then(function () {
            stopCamera();
            window.location.href = API + "auth.php?action=logout";
        }).catch(function (err) {
            el.unlockMsg.textContent =
                (err.response && err.response.data && err.response.data.message)
                    ? err.response.data.message
                    : "Could not verify. Please try again.";
            el.unlockPass.value = "";
            el.unlockPass.focus();
            btn.disabled = false;
        });
    });

    // ---------- boot: require a session, then lock to the scanner ----------
    axios.get(API + "auth.php?action=me")
        .then(function (res) {
            if (!res.data || res.data.success !== true) throw new Error("no session");
            el.boot.classList.add("hidden");
            el.shell.classList.remove("hidden");
            el.code.focus();
            startCamera();                 // kiosks should just work
        })
        .catch(function () {
            el.boot.classList.add("hidden");
            el.signin.classList.remove("hidden");
        });
})();
