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
`DB_USER` (`postgres.<project-ref>`), `DB_PASS`, `DB_SSL=1`, `QR_SECRET_KEY` (long random string signing QR payloads;
if unset a key is derived from the DB credentials), optional `SHOW_DEMO_LOGINS=1` (local only: shows demo logins on the login page). See `attendance-system/.env.example`
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
  called from teacher/session.php, admin/qr_management.php and student/ajax_scan.php. No manual activate; a teacher/admin can close a
  session early and reopen it (`reactivate_attendance_session_by_id()`, same qr_token) only while its meeting is still ACTIVE. Late = scan after start + settings `late_grace_minutes` (default 15).
- Absences: when a session's `session_end` passes, enrolled students with no record get an `Absent` attendance record
  (`includes/absences.php`, run lazily from `require_login()` at most once a minute; watermark = settings row `absence_processed_until`,
  first run only starts the clock, no back-fill). Only meetings that HAD a session are marked (no QR = nothing to scan). Absent rows use
  the meeting's end as `time_in`, so show them with `format_record_time()` and exclude them (`status <> 'Absent'`) from any count of
  check-ins/scans. Test: `php attendance-system/tests/absence_test.php`.
- Teacher override (Absent -> Present only, own classes, reason required): button on `teacher/history.php` -> `teacher/ajax_attendance_override.php`
  (`includes/attendance_override.php`; columns `marked_by_user_id/marked_at/override_reason` from `database/supabase_attendance_override.sql`).
  A manual Present has no GPS data and is shown as "marked by teacher" via `format_record_time()` (selects must include `ar.marked_by_user_id`).
  The student is notified and the activity log gets a line. Test: `php attendance-system/tests/attendance_override_test.php`.
- Any new table/column: write the SQL as a new file in `database/` AND run it in Supabase
  (SQL Editor: click in editor, Ctrl+A, Run so the whole script runs). Run SQL before pushing code that needs it.
- The old MySQL files (`schema.sql`, `migration_v*.sql`) and the `database/*.php` helper scripts were deleted (they are in git history only); never recreate them: they do not work on Postgres and `reset_passwords_to_id.php` could reset every password.
- Demo logins (seed data): admin / tcruz / jsantos / s2023001-s2023004, password `password`. Change in production.

## Section format
- ONE format for students and classes: year level + letter(s), e.g. `3A` (`build_section()` / `is_valid_section_letters()` in
  `includes/functions.php`). The program is never part of the section. `database/supabase_normalize_sections.sql` converts old
  `BSIT-3A` / `BSIT 3A` values; `section_key()` still tolerates old formats when comparing.

## Enrollment rules
- Regular students may only request classes in their own institution, program, year level and section
  (`student_eligible_for_class()` + `section_key()` in `includes/functions.php`). Irregular students may request
  any active class, including other programs and institutions (e.g. an MCNP student taking ISAP subjects).
  Every request still needs teacher/admin approval. Direct teacher enrollment (`teacher/ajax_enrollment.php`, the
  Available Students list in `teacher/enrollment.php`, and the CSV import `teacher/enrollment_import.php`) applies the
  same rule via `enrollment_block_reason()`: regular students only into their own cohort's class, irregular into any.

## Batch CSV imports (no database changes)
- Admin: `admin/students_import.php` (button on Students page) creates student accounts; teacher: `teacher/enrollment_import.php`
  (button on Student Enrollment) enrolls a CSV list of student numbers into one of the teacher's classes.
  Flow = upload -> validate/preview (nothing saved) -> confirm -> one transaction. Shared helpers: `includes/import_csv.php`
  (CSRF, CSV parsing, template) and `includes/import_students.php`. Limits: 1 MB, 50 student rows / 100 enrollment rows
  (bcrypt is slow on serverless). Password column blank = student number (app convention). Test: `php attendance-system/tests/import_csv_test.php`.

## Security conventions
- Session cookie is HttpOnly + SameSite=Lax (+ Secure over HTTPS), set in `includes/config.php`.
- Never return `$e->getMessage()` of a database error to the browser: use `safe_error_message($e)` (`includes/functions.php`);
  PDOExceptions are logged with `error_log()` (Vercel runtime logs) and the user sees a generic message.
  `php attendance-system/tests/security_test.php` fails if an endpoint echoes raw exception text again.
- Security headers (CSP, X-Frame-Options, nosniff, Permissions-Policy, HSTS over HTTPS) are sent from `includes/security_headers.php`
  via `config.php`. If a page needs a new external script/style/font host (or camera/location elsewhere), add it there. Test:
  `php attendance-system/tests/security_headers_test.php`. `QR_SECRET_KEY` unset = warning in the Vercel log + red banner for admins
  (it still works with a key derived from the DB credentials, but a DB password change would then break every QR).
- Data passed to an inline handler (`onclick='fn(...)'`) must go through `js_attr_json()` (`includes/functions.php`), never plain
  `json_encode()`: a name with an apostrophe (Women's Health, D'Souza) would end the attribute and break the button.
- Demo logins are never shown on the login page unless `SHOW_DEMO_LOGINS=1`.
- CSRF: `require_login()`/`require_role()` call `csrf_guard()`, so every POST of a logged-in user needs the session token.
  `ajaxPost()` (assets/js/app.js) sends it automatically (from `<meta name="csrf-token">` in header.php); every new
  `<form method="POST">` MUST contain `<?php echo csrf_field(); ?>` (tests/security_test.php fails otherwise). Never
  call `fetch()` with POST directly: use `ajaxPost()`.
- Forgot password (`forgot_password.php` -> emailed link -> `reset_password.php`, logic in `includes/password_reset.php`, table
  `password_resets` from `database/supabase_password_resets.sql`): token = 32 random bytes, only its SHA-256 is stored, valid 1 h and
  once, 3 links/account/h + 10/IP/h, same answer for unknown accounts. Email goes out through Brevo's HTTPS API (`includes/mailer.php`;
  env `MAIL_API_KEY`, `MAIL_FROM`, optional `MAIL_FROM_NAME`, `APP_URL`). A reset signs the account out everywhere (deletes its
  `php_sessions` rows). Accounts need a REAL email: seed/demo accounts have placeholders. Test: `php attendance-system/tests/password_reset_test.php`.
- Login lockout (`includes/login_throttle.php`, table `login_attempts` from `database/supabase_login_attempts.sql`):
  5 failures/15 min per username, 50/15 min per IP. Fails open if the table is missing. Unlock:
  `DELETE FROM login_attempts WHERE username_key = '...';`

## Workflow rules
- UI: `assets/css/style.css` (colors in `:root` variables), `includes/header.php`,
  `includes/sidebar.php`, `includes/footer.php`, `login.php`, per-page files in `admin/`, `teacher/`, `student/`.
- Don't rewrite history or force-push `main`. Open a PR from the working branch into `main`.
- Never hard-code credentials. Keep Vercel (`vercel.json`, `api/index.php`) and DB config intact when editing.
- Before finishing a change, check pages for SQL errors (Postgres is stricter than MySQL: typed params,
  GROUP BY rules, case-sensitive LIKE already handled by the shim).
