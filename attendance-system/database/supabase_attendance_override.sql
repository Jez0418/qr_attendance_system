-- Teacher override: a teacher can change an Absent record to Present. These columns record who did it,
-- when and why, so a manual Present never looks like a real scan. All NULL for normal records.
-- Safe to run more than once. Run in the Supabase SQL Editor BEFORE deploying the code that uses it.
ALTER TABLE attendance_records
    ADD COLUMN IF NOT EXISTS marked_by_user_id INT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS marked_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS override_reason VARCHAR(255) NULL;
