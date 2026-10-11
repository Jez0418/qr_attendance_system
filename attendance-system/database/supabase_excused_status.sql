-- Excused attendance status (includes/attendance_override.php).
-- A teacher can change an Absent record of their own class to Excused (reason required, one-way), so a
-- legitimate absence no longer counts against the student. Excused counts as neither attended nor absent.
-- Safe to run more than once. Run in the Supabase SQL Editor BEFORE deploying the code that uses it.
ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS attendance_records_status_check;
ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_check
    CHECK (status IN ('Present','Late','Absent','Excused'));
