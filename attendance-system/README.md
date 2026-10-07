# MCNP-ISAP QR Laboratory Attendance System

A web system for recording laboratory-class attendance at **Medical Colleges of Northern
Philippines (MCNP)** and **International School of Asia and the Pacific (ISAP)**. A teacher or
admin shows a QR code, students scan it with their phone, and the server decides whether the
student is Present, Late or rejected, using the class schedule, enrollment and the student's GPS
location.

Plain PHP (no framework, no Composer) + vanilla JavaScript, **PostgreSQL on Supabase**, hosted on
**Vercel**. Developer notes for AI assistants and maintainers are in [`../CLAUDE.md`](../CLAUDE.md).

---

## How attendance works

1. An admin creates a **class assignment**: subject, teacher, laboratory, program/year/section and a
   **recurring weekly schedule** (e.g. Mon + Wed, 3:00-5:00 PM, optionally between two term dates).
2. Meetings are never stored; they are generated from the schedule (`includes/schedule.php`) in
   Asia/Manila time. Each meeting's status is **calculated**, never set by hand:
   `UPCOMING` -> `ACTIVE` (start <= now < end) -> `EXPIRED`, or `CANCELLED` / rescheduled by a
   per-date **schedule exception**.
3. When a meeting is ACTIVE, an attendance session opens automatically with a fresh random QR token.
   It closes at the meeting's end time (or when closed manually); a manually closed session can be reopened, with the same QR code, while the meeting is still in progress.
4. A student scans the QR (`student/ajax_scan.php`). The server checks, in order: logged in ->
   QR signature -> session active and not expired -> student enrolled in that class -> meeting is
   today and inside its time window -> GPS available and accurate enough -> inside the laboratory's
   radius (Haversine) -> not already recorded. Then **Present**, or **Late** after the grace period
   (Admin > Settings, default 15 minutes).

Nothing is trusted from the browser: every rule is re-checked in PHP.

## Roles and pages

| Role | What they can do |
|---|---|
| **Admin** | Dashboard (today's classes, live status), Students / Teachers / Subjects / Laboratories, Class Assignments, Class Schedule (week/month, cancel or reschedule one date), Attendance Sessions, Attendance Monitoring, Enrollment Requests, Reports & Analytics (Excel/PDF export), Settings, Notifications, **CSV import of student accounts** |
| **Teacher** | Dashboard, own subjects/classes, student enrollment (one by one or **CSV import**), enrollment requests, live QR session page, attendance history, late students, notifications |
| **Student** | Dashboard, request enrollment, my requests, my subjects, scan attendance, attendance history, profile (photo, contact, password), notifications |

**Academic structure:** Institution -> Department -> Program -> Year level -> Section. A section is
written the same way everywhere: year level + letter, e.g. `3A` (the program is stored separately).

**Enrollment rules:** a *regular* student can request only classes in their own institution,
program, year level and section; an *irregular* student can request any active class (for example
an MCNP student taking ISAP subjects). Every request is approved or rejected by the class's teacher
or an admin. Direct enrollment by a teacher applies the same cohort rule.

---

## Run it locally

Requirements: PHP 8.1+ with `pdo_pgsql`, and a PostgreSQL database (a local one, or a free Supabase
project).

```bash
# 1. create the database objects (see "Database files" below for the order)
createdb qr_attendance
psql qr_attendance -f attendance-system/database/supabase_schema.sql
# ...then the other supabase_*.sql files in the listed order

# 2. configure the connection (environment variables, see .env.example)
export DB_HOST=localhost DB_PORT=5432 DB_NAME=qr_attendance DB_USER=postgres DB_PASS=secret DB_SSL=0

# 3. start PHP from the folder that CONTAINS attendance-system/
php -S localhost:8000 -t .
# open http://localhost:8000/attendance-system/login.php
```

Optional: `SHOW_DEMO_LOGINS=1` shows demo logins on the login page (local testing only).

## Deploy (Vercel + Supabase)

1. **Supabase:** create a project, then run the SQL files in the order below (SQL Editor: click in
   the editor, **Ctrl+A**, **Run**, so the whole file runs).
2. **Vercel:** import the GitHub repo, set **Root Directory** to `attendance-system`, and add the
   environment variables below. `vercel.json` sets the PHP runtime and the `syd1` region (keep it
   next to a Sydney Supabase project). Production branch is `main`; every push redeploys.
3. Open `/login.php`, sign in as admin and **change every demo password** (below).

| Variable | Value |
|---|---|
| `DB_HOST`, `DB_PORT` | Supabase **Session pooler** host and port 5432 (6543 = transaction pooler) |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `postgres` / `postgres.<project-ref>` / your database password |
| `DB_SSL` | `1` |
| `MAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` | Brevo API key and a sender address verified in Brevo; used by "Forgot password" (without them the reset page says email is not set up) |
| `APP_URL` | optional, public address used in reset links (e.g. `https://yourapp.vercel.app`); defaults to Vercel's production URL |
| `QR_SECRET_KEY` | long random string (`php -r "echo bin2hex(random_bytes(32));"`); if unset a key is derived from the DB credentials |

After changing variables, redeploy. Never commit real values (`.env` is git-ignored).

### Database files (`database/`), run in this order on a fresh Supabase project

| # | File | Purpose |
|---|---|---|
| 1 | `supabase_schema.sql` | Full schema + demo data (drops and recreates the app's tables!) |
| 2 | `supabase_departments_programs.sql` | MCNP / ISAP departments and programs |
| 3 | `supabase_class_schedules.sql` | Weekly class schedules |
| 4 | `supabase_recurring_schedule.sql` | Term dates and per-date exceptions |
| 5 | `supabase_late_grace_setting.sql` | Late grace setting (optional, default 15) |
| 6 | `supabase_profile_photos.sql` | Profile photos stored in the database |
| 7 | `supabase_remove_shs_jhs.sql` | Removes Senior/Junior High programs |
| 8 | `supabase_remove_general_department.sql` | Removes the placeholder department |
| 9 | `supabase_normalize_sections.sql` | One section format (`3A`) |
| 10 | `supabase_login_attempts.sql` | Login lockout table |

Supabase does **not** update from git pushes: any new table or column needs a new SQL file here
**and** a manual run in the SQL Editor, before the code that needs it is deployed.

### Demo accounts (seed data)

`admin`, teachers `tcruz` / `jsantos`, students `s2023001` ... `s2023004`, all with password
`password`. These are public: change or delete them before real use.

## CSV batch imports

* **Admin > Students > Import CSV**: creates up to 50 student accounts per file. Download the template
  from the page. Required columns: `student_number, full_name, email, institution_code, program_code,
  year_level, section`; optional: `username`, `password`, `student_type`, `contact_number`. A blank
  password becomes the student number, so prefer filling the `password` column.
* **Teacher > Student Enrollment > Import CSV**: enrolls up to 100 student numbers into one of the
  teacher's classes.

Both show a preview first (nothing is saved), then save everything in one transaction.

## Tests

```bash
php attendance-system/tests/schedule_test.php      # recurring schedule engine (add --db for the database part)
php attendance-system/tests/import_csv_test.php    # CSV parsing
php attendance-system/tests/security_test.php      # CSRF, error messages, QR key, login throttle helpers
```

`tests/` is not deployed. Please also check pages for SQL errors after any change (PostgreSQL is
stricter than MySQL).

## Security

Passwords are hashed (bcrypt); SQL uses prepared statements; role checks on every page; CSRF token on
every POST; login lockout (5 failures per username, 50 per IP, in 15 minutes); session cookie is
HttpOnly + SameSite=Lax (+ Secure on HTTPS); database errors are logged, never shown to users; QR
payloads are signed and each token is single-session; attendance requires the student's GPS location
to be inside the laboratory radius. Student locations are stored only to verify attendance, so tell
students this and decide how long to keep them.

## Project structure

```
attendance-system/
  index.php, login.php, logout.php
  admin/ teacher/ student/     one PHP file per page; ajax_*.php are the JSON endpoints
  includes/                    auth, config, db (+ MySQL-to-PostgreSQL SQL shim), schedule engine,
                               CSRF + helpers (functions.php), CSV import, login throttle, layout
  qr/                          QR payload signing, automatic attendance sessions
  api/index.php                Vercel entry point: maps each URL to its PHP file
  assets/                      css, js, school emblem
  database/                    supabase_*.sql files (run in Supabase)
  tests/                       PHP test scripts (not deployed)
  vercel.json, .vercelignore, .env.example
```

## Troubleshooting

* **Blank page / HTTP 500 on Vercel**: open the deployment's *Logs*; the real error is logged there.
* **"Service temporarily unavailable"**: the database could not be reached; check the `DB_*` variables.
* **Pages are slow**: keep the Vercel region (`syd1`) next to the Supabase region.
* **"Too many failed sign-in attempts"**: wait 15 minutes, or an admin runs
  `DELETE FROM login_attempts WHERE username_key = 'the.username';` in Supabase.
* **"Please reload the page and try again"**: the page's security token expired; reload it.
* **Icons or charts missing**: fonts, icons and charts load from public CDNs.

## Known limits

* Vercel functions are short-lived, so imports are capped (50 student rows, 100 enrollment rows).
* Uploaded files do not persist on Vercel: profile photos are stored as small images in the database.
* Browser GPS can be inaccurate indoors; the accuracy limit and default radius are in Admin > Settings.
