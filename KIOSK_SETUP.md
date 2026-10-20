# Kiosk Setup and Security

## What the web page can and cannot do

A normal browser tab **cannot** disable:

- the Back button or back gesture
- the address bar
- Ctrl+W / Alt+F4 / window close
- opening another URL manually

`kiosk.js` now keeps its own history entry and blocks Alt+Left / Backspace, so an ordinary Back press does not reveal the previous admin page. That is only a **browser-side speed bump**, not a security boundary.

For real fraud-resistant kiosk operation, use one of the OS/browser-level modes below.

---

## Recommended: Chrome kiosk mode

This removes the address bar, tabs and Back button.

### One-time setup

1. Sign in to the admin app normally as `payroll` in Chrome.
2. Confirm the kiosk opens:
   ```text
   http://localhost/master_file_module_api/kiosk.html
   ```
3. Close all Chrome windows so the launcher reuses the signed-in profile.

### Launch

Double-click:

```text
launch_kiosk.bat
```

Or run:

```bat
"C:\Program Files\Google\Chrome\Application\chrome.exe" ^
  --kiosk "http://localhost/master_file_module_api/kiosk.html" ^
  --no-first-run --disable-pinch
```

The kiosk page uses the existing staff session. If the session is missing, sign in first before launching kiosk mode.

### Leaving kiosk mode

- Use the kiosk **Exit** link and the staff password, **or**
- close the browser with `Alt+F4`.

For a fully locked terminal, restrict the Windows user so `Alt+F4` and Task Manager are not available.

---

## Strongest: Windows Assigned Access

Use this when the kiosk must survive a determined user:

1. Windows Settings → **Accounts → Other users**
2. Set up **Assigned Access** for a dedicated kiosk account.
3. Choose **Microsoft Edge** as the kiosk app.
4. Set the start URL to:
   ```text
   http://localhost/master_file_module_api/kiosk.html
   ```
5. Sign in to the admin app once in that kiosk profile so the session exists.

Assigned Access prevents access to the desktop, Task Manager, browser chrome and other applications.

---

## Operational controls

- Use a dedicated Windows account for the kiosk.
- Disable sleep and screen saver on that account.
- Keep the laptop physically supervised.
- Treat the staff session as sensitive: the kiosk Exit is password-gated in the app, not by the OS.
- If the kiosk is on a shared LAN, restrict the admin app to trusted devices.
- Do not rely on the browser history trap alone; it does not stop a user who types another URL or closes the browser.

---

## What the app already does

- Kiosk exit requires an Administrator / Payroll Officer / Approver password.
- An employee account is refused by the kiosk and by the kiosk exit check.
- QR scans are one-time, expiring tokens or an Active static QR.
- Every scan is audited.
- Duplicate scans are guarded.

Those protect attendance records; they do not turn a browser into an OS kiosk.
