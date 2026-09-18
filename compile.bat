@echo off
setlocal

set "BEDRIOX_RUNTIME_ROOT=%~dp0"
where powershell.exe >nul 2>nul
if errorlevel 1 (
    echo Bedriox Runtime requires Windows PowerShell 5.1 or newer. 1>&2
    exit /b 1
)

powershell.exe -NoLogo -NoProfile -File "%BEDRIOX_RUNTIME_ROOT%compile.ps1" %*
exit /b %ERRORLEVEL%
