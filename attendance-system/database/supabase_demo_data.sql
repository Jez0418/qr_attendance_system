-- ------------------------------------------------------------
-- supabase_demo_data.sql
-- Bookkeeping for the demo semester (tests/demo_seed.php / tests/demo_cleanup.php):
-- one row per row the seed created, so the cleanup deletes exactly those and nothing else.
-- Demo students, their enrollments, requests, attendance and own notifications are removed
-- through their user account (ON DELETE CASCADE); this table covers the rest: sessions,
-- schedule exceptions, activity logs and the notifications sent to teachers.
-- The app never reads it. Supabase SQL Editor: click in the editor, Ctrl+A, Run.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS demo_data_rows (
    table_name VARCHAR(40) NOT NULL,
    row_id     INTEGER     NOT NULL,
    PRIMARY KEY (table_name, row_id)
);

-- Like every other app table: not reachable through Supabase's public REST API.
ALTER TABLE demo_data_rows ENABLE ROW LEVEL SECURITY;
