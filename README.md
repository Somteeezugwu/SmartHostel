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

SQLite uses `PRAGMA user_version`. Version 2 adds `contact_messages`; version 3 adds room-switch requests; version 4 prevents multiple pending switch requests for one destination bed. Fresh databases receive the complete schema, and existing databases apply the required migrations transactionally on connection. No existing tables or records are deleted. Keep regular backups of the database and stop the local server before making a file copy. SQLite foreign keys remain enabled.

## Tests

Run the existing checks from the project folder:

```powershell
php tests/compatibility.php
php tests/allocation_workflow.php
php tests/bed_browsing_matching.php
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

## Seed hostel rooms and assignments

For an explicitly authorized local seed, run `scripts/seed-hostel-assignments.php` with `ALLOW_HOSTEL_ASSIGNMENT_SEEDING=1`. It requires exactly one active hostel, fills missing beds in active rooms, creates additional rooms using the existing room capacity, and assigns eligible students who have no confirmed allocation or active reservation hold. Assignments use confirmed reservations and allocations but do not create payment records. The operation is transactional and safe to rerun; keep the opt-in unset outside a deliberate seed operation.

```powershell
$env:ALLOW_HOSTEL_ASSIGNMENT_SEEDING = '1'
php scripts/seed-hostel-assignments.php
Remove-Item Env:ALLOW_HOSTEL_ASSIGNMENT_SEEDING
```

## Security and operational notes

State-changing API requests require the session CSRF token. User input is validated on the server and sent through prepared statements. Contact messages are length-limited, email-validated, protected by a honeypot and per-session submission delay, and escaped in the admin interface. Notifications are scoped to the signed-in user; marking one read checks ownership. The SQLite file is rejected if located under the public document root.

Roommate compatibility continues to use the configured weighted criteria and existing eligibility rules. Reservation duration remains the existing 48 hours by default and can be set with `RESERVATION_HOURS`. Payment evidence is stored as development-mode manual review; the app does not claim to verify a Remita transaction. The built-in PHP server is intended for local development, not public production hosting.

## Bed browsing

Students browse the live inventory in three steps: hostels with available beds, rooms in a selected hostel, then beds in a selected room. The availability queries exclude inactive inventory, active reservation holds, and confirmed allocations; they also apply the hostel's gender eligibility. Counts are calculated from the same availability rules on each request. The final reservation endpoint rechecks eligibility and availability inside a write transaction, so an out-of-date browser result cannot reserve a held or allocated bed. Room details are limited to the selected hostel and bed details are checked against both selected IDs.

## RRR and reservation payment review

The project has no Remita credentials, gateway adapter, or live status-verification integration. Its payment route stores evidence for a privileged manual decision; submitting an RRR never verifies payment. A pending record remains pending until a super administrator rejects it or manually approves it in the explicitly enabled local development workflow.

The existing code accepted 4–100 characters and did not check whether an RRR existed with Remita. The updated reservation payment form/API normalizes references to uppercase alphanumeric characters by removing spaces and hyphens, then accepts 4–100 normalized characters. It rejects other characters and uses the database's global unique constraint to prevent reusing a reference. This range is the application's development-review validation, not a claimed official Remita format. Remita receipts show 12-digit references (sometimes grouped with hyphens), but this project has no provider integration to validate existence, status, amount, or transaction ownership. See the [Remita API status-check documentation](https://api.remita.net/) for the provider's RRR verification operation and required integration credentials.

The original RRR error cannot be attributed to one specific submitted value because the report did not include the response, reservation ID/status, or server log entry. In this code, a random reference that meets the local character rule is not rejected for being nonexistent; likely rejection paths are a missing/expired/cancelled hold, unapproved eligibility, an already pending submission for that hold, or a reference already used elsewhere. The user-facing API errors identify those workflow conditions; unexpected database errors are logged without returning SQL details.

Development review is disabled unless both `APP_ENV=development` and `ALLOW_DEVELOPMENT_PAYMENT_REVIEW=1` are set. `ALLOW_DEVELOPMENT_PAYMENT_REVIEW` defaults to `0`. For a local demonstration only, copy `.env.example` to `.env`, keep `APP_ENV=development`, and set the opt-in to `1`. A student submission demonstrates the pending outcome; a super administrator can choose reject or approve to demonstrate those outcomes. Approval is a manually simulated development decision, not proof that money was paid. Set `APP_ENV=production` and keep the opt-in at `0` in production. There is no live provider configuration to provide; a future real integration requires Remita-issued merchant credentials and an implementation based on its authenticated status response.

## Roommate matching rules

Matching does not require a reservation or allocation. The student must have approved school-fee eligibility and all six validated lifestyle answers: sleep time and study time (minutes after midnight, 0–1439), plus cleanliness, noise tolerance, social style, and guest frequency (integer ratings 1–5). Candidates must be other student accounts, approved eligible, the same gender under the existing pairing rule, and have all six valid answers. Candidates with incomplete questionnaires are excluded, and the empty state says whether no eligible peers exist or eligible peers have not completed the questionnaire.

The actual score compares only those six preference fields. For sleep/study, similarity is `max(0, 1 - min(|x-y|,1440-|x-y|)/720)`; for 1–5 ratings, it is `1 - |x-y|/4`. Each factor's points equal similarity times its enabled database weight. The percentage is the rounded weighted-points sum divided by the sum of enabled weights that have valid shared answers, multiplied by 100. With no enabled shared weight, no score is returned. All scored candidates are ranked highest first and can be filtered by name, faculty, department, or minimum score and sorted by score or name. Cards show the candidate's name, faculty/department, academic level, score, and each contributing preference factor. A room-switch action appears only for allocated students when a compatible candidate's active room has an available bed. Booking status is not part of candidate filtering.

Students with confirmed allocations can request a switch into an available bed in a compatible student's current active room. Both students must remain eligible, satisfy the existing same-gender rule, have complete compatible questionnaires, and pass destination-hostel rules. Requests remain pending until an administrator approves or rejects them; the requester can cancel while pending, and both participants can view the request status. Approval rechecks current allocations and bed availability inside a write transaction, releases the old reservation/allocation, and creates the new confirmed allocation. A unique partial index prevents multiple pending requests for one destination bed.

The specific earlier “why no results” event cannot be traced without the active student's profile/questionnaire and API response. The code's previous endpoint required the current student's eligibility and returned eligible same-gender peers with any shared valid answer, but returned no reason when results were empty. The updated endpoint distinguishes pending eligibility, missing current answers, no eligible same-gender peers, incomplete peer questionnaires, and no scored candidates. Database failures now stay errors rather than being rendered as an empty match list.
