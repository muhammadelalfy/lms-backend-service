# Mudarisy Laravel Backend

This repository is the Laravel 13 API for the Mudarisy school-management platform (the web app is in `front-end-service`, the mobile app in `lms-mobile`). It uses Eloquent models and migrations for MySQL and Laravel Sanctum for token-based authentication.

## Schools and modules

One installation serves many schools. **Each school has its own database** and is reached at its own subdomain (`alnour.example.com`); the platform's own tables (schools, addresses, cache, jobs) live in the central database. Business code is split into feature modules under `Modules/` (`Auth`, `Students`, `Groups`, `Attendance`, `Payments`, `Exams`, `Learning`, `Notifications`, `Chat`, `Calls`, `Reports`, `PluginStore`, `Tenancy`), each with its own models, controllers, routes, migrations (`Database/Migrations`) and tests (`Tests`). Design: `zewal-mobile/docs/SYSTEM_DESIGN.md` §12.

```bash
php artisan migrate                                         # central tables only
php artisan school:create alnour --name="مركز النور" --plan=basic --admin-email=owner@alnour.example
php artisan school:adopt alnour --database=database.sqlite  # or: keep an existing single-school database as a school
php artisan school:list
php artisan school:status alnour suspended                  # turn a school away (or `active`)
php artisan tenants:migrate                                 # run a release's migrations in every school
```

The operator can do the same over HTTP on a central address (`CENTRAL_DOMAINS`) with `Authorization: Bearer $CENTRAL_API_TOKEN`: `GET/POST /api/central/schools`, `PATCH/DELETE /api/central/schools/{id}`, `POST /api/central/schools/{id}/domains`. Without a token configured these routes do not exist.

For local development set `DEFAULT_SCHOOL=demo` so a plain `http://127.0.0.1:8000` serves that school (ignored outside `local` and `testing`), and use `php artisan lms:reset-development-data` to open it with the Arabic demo data.

Periodic work is queued once per active school (`schools:dispatch <command>` in `routes/console.php`) and needs a running queue worker besides `schedule:run`.
## Domain model

The backend models users, students, student accounts, worksheets, worksheet assignments, attendance records, exam results, and payments. A worksheet assignment belongs to exactly one worksheet and student, and its lifecycle is `assigned`, `in_progress`, `submitted`, or `graded`.

## MySQL configuration

Production should use MySQL 8 or a compatible managed MySQL service. Configure the backend environment with:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mudarisy
DB_USERNAME=...
DB_PASSWORD=...
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Never commit a real `.env` file or credentials. Run `php artisan migrate --force` against the configured MySQL database during deployment.

## API contract

Authentication is exposed through `/api/auth/register`, `/api/auth/login`, `/api/auth/me`, and `/api/auth/logout`. Protected LMS endpoints use Sanctum bearer tokens. Admins and teachers can manage students, create worksheets, assign work, and view reports. Students can submit their own assignments, while parents and students can read only the records authorized by their account relationship.

The current report endpoint is `/api/reports/summary`; it aggregates student count, attendance statuses, exam totals, and payment totals from Eloquent queries.

## Local verification

The feature suite runs against SQLite (the central database in memory, and every school in a throwaway file) so it does not require local production credentials; every test runs inside a school:

```bash
php artisan test --compact
```

The test suite currently covers registration/login, teacher assignment, student submission, and role authorization. Production validation must also run the migration and endpoint smoke checks against MySQL.

## Arabic local demo data

The project includes localized factories and a guarded `ArabicDemoSeeder` for local development and automated testing. Run it with:

```bash
APP_ENV=local php artisan db:seed --class=Database\\Seeders\\ArabicDemoSeeder --force
```

The seeder is intentionally blocked outside `local` and `testing`. It is idempotent and creates six Arabic students, parent and student accounts, unique QR tokens, published mathematics worksheets, assignments, five days of attendance history, exam results, and payment records. Development credentials are printed by the command and must not be reused in production.
