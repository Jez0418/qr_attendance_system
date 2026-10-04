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
- Any new table/column: write the SQL as a new file in `database/` AND run it in Supabase
  (SQL Editor: click in editor, Ctrl+A, Run so the whole script runs). Run SQL before pushing code that needs it.
- Old MySQL files (`schema.sql`, `migration_v*.sql`, `database/*.php` helpers) are legacy and don't work on Postgres.
- Demo logins (seed data): admin / tcruz / jsantos / s2023001-s2023004, password `password`. Change in production.

## Workflow rules
- UI: `assets/css/style.css` (colors in `:root` variables), `includes/header.php`,
  `includes/sidebar.php`, `includes/footer.php`, `login.php`, per-page files in `admin/`, `teacher/`, `student/`.
- Don't rewrite history or force-push `main`. Open a PR from the working branch into `main`.
- Never hard-code credentials. Keep Vercel (`vercel.json`, `api/index.php`) and DB config intact when editing.
- Before finishing a change, check pages for SQL errors (Postgres is stricter than MySQL: typed params,
  GROUP BY rules, case-sensitive LIKE already handled by the shim).
