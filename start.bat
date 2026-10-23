@echo off
setlocal
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
  echo PHP was not found. Install PHP 8.2 or newer and add its folder to PATH.
  echo Then close and reopen this window and run start.bat again.
  pause
  exit /b 1
)

for /f %%V in ('php -r "echo PHP_VERSION_ID;"') do set "PHP_VERSION_ID=%%V"
if %PHP_VERSION_ID% LSS 80200 (
  echo PHP 8.2 or newer is required. Found version:
  php -v
  pause
  exit /b 1
)

php -r "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);"
if errorlevel 1 (
  echo PDO SQLite is not enabled in this PHP installation.
  echo Enable extension=pdo_sqlite in php.ini, then reopen this window.
  pause
  exit /b 1
)

if not exist storage mkdir storage
echo SmartHostel is starting at http://127.0.0.1:8000
echo Keep this window open while using the portal. Press Ctrl+C to stop.
php -S 127.0.0.1:8000 -t public
