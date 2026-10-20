@echo off
setlocal
set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"

if not exist "%CHROME%" (
    echo Chrome was not found. Edit this file and set the correct path to chrome.exe.
    pause
    exit /b 1
)

start "" "%CHROME%" --kiosk "http://localhost/master_file_module_api/kiosk.html" --no-first-run --disable-pinch
