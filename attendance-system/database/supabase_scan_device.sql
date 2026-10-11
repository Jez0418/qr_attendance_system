-- One phone checks in one student per meeting (includes/device.php).
-- The browser's random device ID (HttpOnly cookie) is stored with each scanned record; a scan from a
-- device that already checked in another student for the same session is refused. Absent rows and
-- teacher overrides stay NULL. Existing records stay NULL, so past data is unaffected.
-- Safe to run more than once. Run in the Supabase SQL Editor BEFORE deploying the code that uses it.
ALTER TABLE attendance_records ADD COLUMN IF NOT EXISTS device_id CHAR(32) NULL;
CREATE INDEX IF NOT EXISTS idx_ar_session_device ON attendance_records (session_id, device_id) WHERE device_id IS NOT NULL;
