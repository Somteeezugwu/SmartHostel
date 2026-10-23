# SmartHostel

SmartHostel is a PHP 8.2 application for student accommodation, weighted roommate matching, hostel inventory, 48-hour reservations, payment evidence review, allocations, notifications, and CSV reports. The UI is native PHP/HTML/CSS/vanilla JavaScript. Its default local database is SQLite; there is no database server or Composer install needed.

## Run on Windows

### 1. Install PHP

If PHP is not already installed, download a current PHP 8.2+ Windows build from [windows.php.net/download](https://windows.php.net/download/). Extract it to a stable folder such as `C:\\php`. You do not need Apache or MySQL.

Add that folder to your Windows `Path` (Settings → System → About → Advanced system settings → Environment Variables → select `Path` → Edit → New). Close and reopen VS Code or PowerShell after changing `Path`.

In a new VS Code terminal, confirm PHP is available:

```powershell
php -v
php -m
php -r "print_r(PDO::getAvailableDrivers());"
```

The driver list must include `sqlite`. If it does not, find the loaded configuration file with `php --ini`, open its `php.ini`, and enable `extension=pdo_sqlite` (and `extension=sqlite3` if present but commented). Make sure `extension_dir` points to PHP’s `ext` folder, then reopen the terminal and check again. The app needs PHP 8.2+ and `pdo_sqlite`; it does not require Composer.

### 2. Configure and start

Optionally copy `.env.example` to `.env` and change `SQLITE_DATABASE_PATH`. The default is `storage/smarthostel.sqlite`. Keep this file outside `public/` so the browser cannot fetch it. If you already have a `.env`, add or edit just the SQLite path; do not replace your other local settings.

From the project folder, either double-click `start.bat` or run:

```powershell
php -S 127.0.0.1:8000 -t public
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000). Leave the terminal running while using the portal; press Ctrl+C to stop it. On first request the app creates `storage/` and the SQLite database, then installs the schema and default matching weights. It records schema version 1 in SQLite and does not recreate or reset a database that has already been initialized.

### 3. Create the first administrator

Register a student through the portal if desired. To create the first administrator, open a second terminal in the project folder and run:

```powershell
php scripts/create-admin.php admin@example.edu.ng
```

Enter a unique password of at least 12 characters. The command refuses to create another administrator after one exists. No default administrator or password is shipped.

## SQLite files, backup, and reset

The default database is `storage/smarthostel.sqlite`, outside the public document root. SQLite may also create `-wal` and `-shm` companion files while the app is running. To make a simple file backup, stop the PHP server first, then copy the database file:

```powershell
Copy-Item storage/smarthostel.sqlite storage/smarthostel-backup.sqlite
```

To restore, stop the server and copy the backup over `storage/smarthostel.sqlite`. To intentionally start with an empty local database, stop the server and remove the database and its sidecars:

```powershell
Remove-Item storage/smarthostel.sqlite -ErrorAction SilentlyContinue
Remove-Item storage/smarthostel.sqlite-wal -ErrorAction SilentlyContinue
Remove-Item storage/smarthostel.sqlite-shm -ErrorAction SilentlyContinue
```

The next request initializes a fresh database. This permanently removes local accounts and accommodation records, so back up first if you want to keep them.

## Optional import from an existing MySQL database

The app does not need MySQL after migration. If you already have data in the old MySQL database, keep that database intact and make a backup first. With the existing MySQL service still available, set the source connection variables in PowerShell and run the one-way importer. It will refuse to merge into a populated SQLite database, keeps record IDs, verifies foreign keys, and reports imported row counts; it never writes to MySQL.

```powershell
$env:MYSQL_DSN = 'mysql:host=127.0.0.1;dbname=smarthostel;charset=utf8mb4'
$env:MYSQL_USER = 'root'
$env:MYSQL_PASS = ''
$env:SQLITE_DATABASE_PATH = 'storage/smarthostel.sqlite'
php scripts/migrate-mysql-to-sqlite.php
```

This optional importer needs `pdo_mysql` only to read the old database. It does not need that driver for regular SmartHostel use. If there is no MySQL database containing data you need, omit this step and use the automatically initialized empty SQLite database.

## Tests

Run the checks from the project folder:

```powershell
php tests/compatibility.php
php tests/allocation_workflow.php
```

The workflow test creates a uniquely named temporary SQLite database, tests competing reservation processes, 48-hour expiry, bed release, cancellations, duplicate payment references, review permissions, and unique allocations, then removes its temporary files. It does not use your normal `storage/smarthostel.sqlite` database.

## Troubleshooting

- **`php` is not recognized:** add the PHP folder to Windows `Path`, then restart VS Code/PowerShell.
- **PDO SQLite is unavailable:** enable `pdo_sqlite` in the `php.ini` shown by `php --ini`; check `php -m` and `PDO::getAvailableDrivers()` again.
- **Database cannot be opened:** confirm the `SQLITE_DATABASE_PATH` parent folder is writable. Relative paths are resolved from the project directory. Close tools that may have the SQLite file locked and restart the server.
- **Address already in use:** stop the other local PHP server or change port `8000` in `start.bat` and use the matching URL.
- **Cannot create administrator:** the setup command allows this only when the database has no administrator. If an existing admin is unavailable, do not delete the database casually; use a deliberate local recovery procedure after backing it up.

## Design and payment notes

Compatibility scores are normalized as `100 × Σ(weight × similarity) / Σ(applicable weights)`. Schedule similarity uses circular minute distance to treat times near midnight as close; each 1–5 rating uses `1 − |difference| / 4`. Unanswered criteria are omitted from both numerator and denominator; no shared answers produce no score. The current configurable weights are sleep 20, study 20, cleanliness 20, noise tolerance 15, social style 15, guest frequency 10. Only eligible, gender-compatible students are compared.

SQLite foreign keys are enabled on every connection. Write workflows use `BEGIN IMMEDIATE`, a busy timeout and retry, transactions, and partial unique indexes for active holds and confirmed allocations. This serializes competing SQLite writers while database constraints remain the final protection against duplicate allocations.

Payment submission and review remain clearly marked development-mode evidence review. No Remita transaction is represented as externally verified. Real Remita verification needs approved institution merchant credentials, official integration documentation, and a server-side implementation that checks trusted transaction status, amount, currency, reference, and metadata.

For production deployment, use HTTPS, protect the SQLite file and backups, configure secure cookies, make regular consistent backups, and review whether SQLite fits the institution’s expected concurrent workload. The built-in PHP server is for local development only; use a properly configured production web server for public deployment.
