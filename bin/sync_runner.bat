@echo off
REM ====================================================================
REM  Car Wash Back Office - Background Cloud Sync Runner
REM  Task Scheduler Command: C:\xampp\htdocs\Car-WashService\bin\sync_runner.bat
REM ====================================================================

set PHP_BIN=C:\xampp\php\php.exe
if not exist "%PHP_BIN%" (
    set PHP_BIN=php.exe
)

set SCRIPT_DIR=%~dp0..
cd /d "%SCRIPT_DIR%"

"%PHP_BIN%" "%SCRIPT_DIR%\scripts\sync_runner.php"
exit /b %ERRORLEVEL%
