#!/bin/bash
# Back up the live Supabase database to this PC (Supabase Free has no backups of its own).
#
#   bash scripts/backup_db.sh                 -> C:\Users\<you>\QR-Attendance-Backups\qr_attendance_<date>-<time>.dump
#   BACKUP_DIR=/d/backups bash scripts/backup_db.sh
#
# READ-ONLY: it only runs pg_dump. Credentials come from attendance-system/.env (DB_HOST, DB_NAME, DB_USER,
# DB_PASS); the password goes to pg_dump through PGPASSWORD, never on the command line. It always uses
# port 5432 (session pooler): pg_dump does not work through the transaction pooler (6543).
#
# What is saved: every table in the `public` schema (structure + data), custom format (-Fc, compressed).
# Left out on purpose: the DATA of php_sessions (login sessions) and password_resets (reset tokens) -- short-lived
# and sensitive; their tables are still created on restore. The dumps DO contain personal data (names, emails,
# password hashes): keep the folder private, do not commit it, do not share it.
#
# Needs pg_dump/pg_restore 17 (the server is PostgreSQL 17): PG_BIN, else C:\Users\<you>\tools\pgsql\bin, else PATH.
# Keeps the newest $KEEP backups (default 30) and deletes older ones.
#
# RESTORE -- into a NEW, empty Supabase project, never over the live one:
#   1. Create the project; get its session-pooler host/user and the DB password.
#   2. PGPASSWORD='<new db password>' pg_restore --no-owner --no-privileges \
#        -h <new pooler host> -p 5432 -U postgres.<new project ref> -d postgres <file>.dump
#      Expect exactly ONE error, harmless: 'schema "public" already exists' ("errors ignored on restore: 1").
#      Any other error: stop and look. (Trial restore 2026-10-26 into a fresh PostgreSQL 17.11: every table's
#      row count, 65 indexes, 34 foreign keys, ID sequences and row-level security matched the live database.)
#   3. Run database/supabase_role_timezone.sql on the new project (role settings are not in the dump).
#   4. Point Vercel's DB_* env vars at the new project and redeploy.
#   Check a file without restoring it: pg_restore --list <file>.dump
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENVF="$ROOT/attendance-system/.env"
BACKUP_DIR="${BACKUP_DIR:-$HOME/QR-Attendance-Backups}"
KEEP="${KEEP:-30}"

for dir in "${PG_BIN:-}" "$HOME/tools/pgsql/bin"; do
    if [ -n "$dir" ] && [ -x "$dir/pg_dump.exe" -o -x "$dir/pg_dump" ]; then PATH="$dir:$PATH"; break; fi
done
command -v pg_dump >/dev/null || { echo "pg_dump not found (set PG_BIN or install it in ~/tools/pgsql/bin)" >&2; exit 1; }
pg_dump --version | grep -q ' 17\.' || { echo "pg_dump 17 needed, found: $(pg_dump --version)" >&2; exit 1; }

[ -f "$ENVF" ] || { echo "Missing $ENVF" >&2; exit 1; }
# Tolerates spaces around '=' and quotes; never prints a value.
get() { grep -E "^[[:space:]]*$1[[:space:]]*=" "$ENVF" | head -1 | cut -d= -f2- | tr -d '\r' \
        | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/'; }
DB_HOST=$(get DB_HOST); DB_NAME=$(get DB_NAME); DB_USER=$(get DB_USER)
export PGPASSWORD; PGPASSWORD=$(get DB_PASS)
for v in DB_HOST DB_NAME DB_USER PGPASSWORD; do [ -n "${!v}" ] || { echo "$v is empty in .env" >&2; exit 1; }; done
case "$DB_HOST" in localhost|127.0.0.1) echo "DB_HOST is local ($DB_HOST): this would back up the local DB, not Supabase" >&2; exit 1;; esac

mkdir -p "$BACKUP_DIR"
FILE="$BACKUP_DIR/qr_attendance_$(date +%Y%m%d-%H%M%S).dump"
echo "Backing up $DB_NAME on $DB_HOST to $FILE"
if ! pg_dump -h "$DB_HOST" -p 5432 -U "$DB_USER" -d "$DB_NAME" \
        --format=custom --schema=public --no-owner --no-privileges \
        --exclude-table-data=public.php_sessions --exclude-table-data=public.password_resets \
        --file="$FILE.part"; then
    rm -f "$FILE.part"; echo "Backup FAILED (nothing saved)" >&2; exit 1
fi
mv "$FILE.part" "$FILE"

# Verify the file is readable and holds the tables we expect.
TABLES=$(pg_restore --list "$FILE" | grep -c ' TABLE public ' || true)
DATA=$(pg_restore --list "$FILE" | grep -c ' TABLE DATA public ' || true)
[ "$TABLES" -gt 0 ] || { echo "Backup file has no tables: $FILE" >&2; exit 1; }
echo "OK: $(du -h "$FILE" | cut -f1), $TABLES tables, $DATA with data"

# Keep only the newest $KEEP backups.
ls -1t "$BACKUP_DIR"/qr_attendance_*.dump 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
    rm -f "$old"; echo "Deleted old backup $(basename "$old")"
done
