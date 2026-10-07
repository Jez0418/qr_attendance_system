-- Attendance insights: per-class late grace + absence-limit settings. Safe to re-run.
-- Run this in the Supabase SQL Editor BEFORE deploying the code that uses it
-- (includes/schedule.php selects teacher_subjects.late_grace_minutes).

-- Per-class late grace period (Admin > Class Assignments). NULL = use the default from
-- Admin > Settings (settings.late_grace_minutes). Labs and lectures often need different values.
ALTER TABLE teacher_subjects
    ADD COLUMN IF NOT EXISTS late_grace_minutes SMALLINT NULL
    CHECK (late_grace_minutes IS NULL OR late_grace_minutes BETWEEN 0 AND 180);

-- Attendance warnings (Admin > Settings). The app uses these defaults when the rows are missing.
--   absence_limit        a student who reaches this many absences in one class is flagged (0 = off)
--   min_attendance_rate  a student below this % in one class is flagged, once the class has
--                        had at least 5 recorded meetings for them (0 = off)
INSERT INTO settings (setting_key, setting_value) VALUES ('absence_limit', '3')
ON CONFLICT (setting_key) DO NOTHING;
INSERT INTO settings (setting_key, setting_value) VALUES ('min_attendance_rate', '80')
ON CONFLICT (setting_key) DO NOTHING;
