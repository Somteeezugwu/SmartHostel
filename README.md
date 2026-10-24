# SmartHostel

SmartHostel is a PHP 8.2+ student accommodation application using SQLite through PDO, semantic HTML, CSS, and vanilla JavaScript. It supports student profiles, eligibility review, weighted roommate compatibility, hostel inventory, 48-hour bed reservations, development-mode payment evidence review, allocations, notifications, audit logs, and CSV reports. The SQLite database remains in `storage/`, outside the public document root.

## Public site and application pages

The public homepage at `http://127.0.0.1:8000/` contains About SmartHostel, an eight-step How to Use guide, and a contact form. Contact submissions are validated and stored in SQLite; they are not emailed. Authorized administrators can search messages at Dashboard → Contact messages and mark them handled. No support email or phone is configured.

- `/login.php` — sign in.
- `/register.php` — create a student account.
- `/dashboard.php` — authenticated role-based dashboard. Students see their accommodation journey and activities; admins see management and reporting; super admins also have eligibility, payment review, and audit access.
- `/api.php?action=...` — existing JSON API and preserved workflows.

Student dashboard activities include profile, lifestyle questionnaire, roommate matches, bed browsing, reservations, payment details, allocation, notifications, and account settings information. Admin activities include student records, hostel/room/bed management, reservation/allocation overview, contact message management, and CSV reports. Only `super_admin` can approve or reject school-fee and payment evidence. Payment evidence review is in development mode and is not real Remita verification.

Protected pages redirect unauthenticated visitors to sign in. Server-side API authentication and authorization remain authoritative; hiding a dashboard link does not grant or remove a permission.

## Run locally on Windows

Install PHP 8.2+ and enable `pdo_sqlite`. No Apache, MySQL, XAMPP, Node, framework, or Composer install is required. Check the PHP extensions:

```powershell
php -v
php -m
php -r "print_r(PDO::getAvailableDrivers());"
```

From the project folder, start the built-in server:

```powershell
php -S 127.0.0.1:8000 -t public
```

Then open `http://127.0.0.1:8000/`. `start.bat` starts the same server. On first request, the app creates `storage/smarthostel.sqlite` and initializes its schema. Existing database records are preserved. Optionally configure `SQLITE_DATABASE_PATH` in `.env` (copy `.env.example` if needed); keep the database outside `public/`.

## Create the first administrator securely

The setup script creates the first administrator as a `super_admin`, prompts for a password, and refuses to create one if any administrator already exists. No default admin account is shipped.

```powershell
php scripts/create-admin.php admin@example.edu.ng
```

Use a unique password with at least 12 characters. Keep the terminal private while entering it.

## Database migrations and data safety

SQLite uses `PRAGMA user_version`. Version 2 adds `contact_messages` and its status/time index. Fresh databases receive the complete schema; a version 1 database applies `database/migrations/003_contact_messages.sql` transactionally on its next connection. No existing tables or records are deleted. Keep regular backups of the database and stop the local server before making a file copy. SQLite foreign keys remain enabled.

## Tests

Run the existing checks from the project folder:

```powershell
php tests/compatibility.php
php tests/allocation_workflow.php
```

The allocation workflow creates a separate temporary SQLite database and starts `reservation_worker.php` as part of its concurrency check. It checks reservation races, the 48-hour expiry, cancellation, payment review permissions, and allocation uniqueness. It does not reset or use the normal local database.

Test the seed utility separately with:

```powershell
php tests/seed_test_users.php
```

This test creates and removes its own temporary database, verifies all 80 profiles and questionnaires, checks a repeated run creates no duplicates, verifies the shared password hash, compares compatibility scores, and confirms a pre-existing super-admin row remains.

## Development test students

The seed script adds 80 clearly labelled student accounts to the configured SQLite database. It is disabled unless **both** explicit opt-ins are set. Use this only with a local development or testing database:

```powershell
$env:APP_ENV = 'development'
$env:ALLOW_TEST_USER_SEEDING = '1'
php scripts/seed-test-users.php
Remove-Item Env:ALLOW_TEST_USER_SEEDING
```

Accounts use `student001@test.smarthostel.local` through `student080@test.smarthostel.local`; every account shares the password `SmartHostelTest2026!`. These are fictional development accounts. Their profiles are marked eligible only to let the existing compatibility matcher run immediately. The script does not create school-fee verification records or payment evidence and does not change unrelated users. It reports created, already-present, and failed accounts and can be run again safely.

Sign in at `/login.php` with, for example, `student001@test.smarthostel.local` and the shared password. Open Dashboard → Roommate matches. The profiles contain complete, varied answers using the existing six questionnaire fields. Students of the same gender and similar preference groups should rank more highly; other groups share some habits and differ on others. Matching still uses the existing weighted algorithm and eligibility rules.

## Security and operational notes

State-changing API requests require the session CSRF token. User input is validated on the server and sent through prepared statements. Contact messages are length-limited, email-validated, protected by a honeypot and per-session submission delay, and escaped in the admin interface. Notifications are scoped to the signed-in user; marking one read checks ownership. The SQLite file is rejected if located under the public document root.

Roommate compatibility continues to use the configured weighted criteria and existing eligibility rules. Reservation duration remains the existing 48 hours by default and can be set with `RESERVATION_HOURS`. Payment evidence is stored as development-mode manual review; the app does not claim to verify a Remita transaction. The built-in PHP server is intended for local development, not public production hosting.
