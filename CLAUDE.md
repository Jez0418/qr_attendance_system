# QR Laboratory Attendance System — project notes for Claude

Read this first. It records how the project is set up and deployed so changes
don't break the Vercel + Supabase connection. (No secrets belong in this file.)

## Stack
- Plain PHP (no framework/Composer), vanilla JS/CSS. App code is in `attendance-system/`.
- Database: **PostgreSQL on Supabase** (project region ap-southeast-2, Sydney).
  The app was ported from MySQL; queries are still written MySQL-style and
  `includes/db_connect.php` (`pg_compat_sql`) rewrites them for Postgres
  (double-quoted strings, CURDATE, DATE_SUB/ADD, LIKE->ILIKE, SUM(bool)).
  Prefer writing new SQL in plain Postgres-compatible form.
- Hosting: **Vercel** using the community `vercel-php@0.7.3` runtime.
  Vercel project Root Directory = `attendance-system`, region `syd1` (set in `vercel.json`).
  Production branch = `main`; every push to `main` redeploys automatically.

## How requests work on Vercel
- `vercel.json` sends every URL to `api/index.php`, which maps the path to the
  matching `.php` file and runs it. It blocks `includes/`, `database/`, `api/`, `qr/` helpers.
  Static files in `assets/` are served directly.
- `BASE_URL` is `/` on Vercel and `/attendance-system/` locally (see `includes/config.php`).
- PHP sessions are stored in the DB table `php_sessions` (`includes/session_db.php`)
  because Vercel has no shared disk.
- File uploads (`uploads/photos/`) do NOT persist on Vercel; they need object storage.

## Configuration (environment variables — set in Vercel, never commit)
`DB_HOST`, `DB_PORT` (5432 session pooler / 6543 transaction pooler), `DB_NAME` (postgres),
`DB_USER` (`postgres.<project-ref>`), `DB_PASS`, `DB_SSL=1`. See `attendance-system/.env.example`
(placeholders only). `.env` is git-ignored. After changing env vars, redeploy.

## Database changes (Supabase does NOT update from git pushes)
- Full schema + seed: `attendance-system/database/supabase_schema.sql` (final state; v1-v5 merged).
- ISAP/MCNP departments + programs: `attendance-system/database/supabase_departments_programs.sql`.
- Recurring class schedules: `attendance-system/database/supabase_class_schedules.sql` (adds `class_schedules`,
  backfills it from old `meeting_date`/`schedule_day`). A class assignment (`teacher_subjects`) = who/what/which class/where;
  its weekly meetings live in `class_schedules` (day_of_week 1=Mon..7=Sun). `meeting_date`, `schedule_day`,
  `start_time` and `end_time` on `teacher_subjects` are legacy columns: nothing reads or writes them any more.
  Read schedules ONLY through `includes/schedule.php` (e.g. `get_class_schedule_summaries()` for label/status/next class).
- Recurring schedule + exceptions: `attendance-system/database/supabase_recurring_schedule.sql` (adds effective dates
  to `class_schedules`, adds `schedule_exceptions` for CANCELLED/RESCHEDULED single meetings). Meetings are never
  stored; `includes/schedule.php` generates them (`get_occurrences`, `get_occurrence_status`, `get_todays_occurrences`,
  Asia/Manila) and is meant to be the single source of truth for "when does a class meet". Test:
  `php attendance-system/tests/schedule_test.php` (add `--db` with DB_* env vars; it rolls back). `tests/` is not deployed.
- Attendance sessions are automatic: `qr/session_manager.php` `ensure_session_for_occurrence()` opens one session per
  ACTIVE meeting (session_date = occurrence date, scheduled_start = its start, session_end = its end, fresh qr_token),
  called from teacher/session.php, admin/qr_management.php and student/ajax_scan.php. No manual activate; manual close
  only, and a closed session is never reopened. Late = scan after start + settings `late_grace_minutes` (default 15).
- Any new table/column: write the SQL as a new file in `database/` AND run it in Supabase
  (SQL Editor: click in editor, Ctrl+A, Run so the whole script runs). Run SQL before pushing code that needs it.
- Old MySQL files (`schema.sql`, `migration_v*.sql`, `database/*.php` helpers) are legacy and don't work on Postgres.
- Demo logins (seed data): admin / tcruz / jsantos / s2023001-s2023004, password `password`. Change in production.

## Enrollment rules
- Regular students may only request classes in their own institution, program, year level and section
  (`student_eligible_for_class()` + `section_key()` in `includes/functions.php`). Irregular students may request
  any active class, including other programs and institutions (e.g. an MCNP student taking ISAP subjects).
  Every request still needs teacher/admin approval; direct enrollment by a teacher/admin skips these rules.

## Batch CSV imports (no database changes)
- Admin: `admin/students_import.php` (button on Students page) creates student accounts; teacher: `teacher/enrollment_import.php`
  (button on Student Enrollment) enrolls a CSV list of student numbers into one of the teacher's classes.
  Flow = upload -> validate/preview (nothing saved) -> confirm -> one transaction. Shared helpers: `includes/import_csv.php`
  (CSRF, CSV parsing, template) and `includes/import_students.php`. Limits: 1 MB, 50 student rows / 100 enrollment rows
  (bcrypt is slow on serverless). Password column blank = student number (app convention). Test: `php attendance-system/tests/import_csv_test.php`.

## Workflow rules
- UI: `assets/css/style.css` (colors in `:root` variables), `includes/header.php`,
  `includes/sidebar.php`, `includes/footer.php`, `login.php`, per-page files in `admin/`, `teacher/`, `student/`.
- Don't rewrite history or force-push `main`. Open a PR from the working branch into `main`.
- Never hard-code credentials. Keep Vercel (`vercel.json`, `api/index.php`) and DB config intact when editing.
- Before finishing a change, check pages for SQL errors (Postgres is stricter than MySQL: typed params,
  GROUP BY rules, case-sensitive LIKE already handled by the shim).
