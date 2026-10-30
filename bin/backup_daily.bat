@echo off
REM ====================================================================
REM  Car Wash Back Office - Automated Daily Backup Runner (2:00 AM)
REM  Task Scheduler Command: C:\xampp\htdocs\Car-WashService\bin\backup_daily.bat
REM ====================================================================

set PHP_BIN=C:\xampp\php\php.exe
if not exist "%PHP_BIN%" (
    set PHP_BIN=php.exe
)

set SCRIPT_DIR=%~dp0..
cd /d "%SCRIPT_DIR%"

echo [%date% %time%] Starting automated 2:00 AM daily backup...
"%PHP_BIN%" "%SCRIPT_DIR%\scripts\backup_daily.php"
set EXIT_CODE=%ERRORLEVEL%

if %EXIT_CODE% EQU 0 (
    echo [%date% %time%] Backup completed successfully.
) else (
    echo [%date% %time%] Backup failed with exit code %EXIT_CODE%.
)

exit /b %EXIT_CODE%
