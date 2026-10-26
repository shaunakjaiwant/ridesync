@echo off
setlocal
cd /d "%~dp0\.."

echo =====================================================================
echo    RideSync - Local Data Export & Cloud Synchronization Utility
echo =====================================================================
echo.
echo Step 1: Exporting all local RideSync tables and records...
php tools/export_all_data.php
if %errorlevel% neq 0 (
    echo [ERROR] Data export failed. Please ensure local XAMPP MySQL is running.
    pause
    exit /b %errorlevel%
)

echo.
echo =====================================================================
echo Step 2: Push / Sync data to Deployed Cloud Database (Aiven / Render)
echo =====================================================================
echo.
set /p CLOUD_PASS="Enter Aiven Cloud MySQL password for 'avnadmin' (or press Enter to skip cloud push): "

if "%CLOUD_PASS%"=="" (
    echo.
    echo Cloud push skipped. Your export files are saved in:
    echo   - storage\exports\ridesync_local_full_data.sql (Full Local Dump)
    echo   - storage\exports\ridesync_cloud_import.sql   (Cloud Import Dump)
    echo   - storage\exports\json\                       (Table JSONs)
    echo   - storage\exports\csv\                        (Table CSVs)
    echo.
    pause
    exit /b 0
)

python tools/sync_local_to_cloud.py --password="%CLOUD_PASS%"

echo.
pause
