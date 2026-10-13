-- Deleting a student or teacher now ARCHIVES the account instead of removing it, so their attendance
-- history, sessions and classes stay. Run once in the Supabase SQL Editor (click in editor, Ctrl+A, Run).
ALTER TABLE users ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;
