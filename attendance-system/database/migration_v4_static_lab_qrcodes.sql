-- ============================================================
-- MIGRATION v4: Static, non-changing QR code per laboratory
-- ============================================================
-- Run this ONCE against an existing qr_attendance_system database
-- that already has migration_v2 (and, if applied, v3) in place.
--
-- What changes: QR codes are no longer generated per attendance
-- session with a rotating token. Instead, every laboratory has ONE
-- fixed, permanent QR code (7 laboratories = 7 QR codes total),
-- meant to be printed and posted on the wall. Activating an
-- attendance session now just opens a time window during which
-- scans of that room's fixed code get recorded — see
-- qr/qr_helper.php and admin/lab_qrcodes.php.
--
-- How to run it:
--   phpMyAdmin -> select qr_attendance_system -> SQL tab -> paste
--   this whole file -> Go.
-- ============================================================

USE qr_attendance_system;

-- The qr_token column was NOT NULL with no default — since sessions
-- are no longer created with a token at all, it must be dropped (not
-- just made nullable) or every new INSERT would fail. Dropping the
-- column automatically drops its associated indexes/unique constraint
-- too, so no separate DROP INDEX is needed.
ALTER TABLE attendance_sessions
    DROP COLUMN qr_token,
    DROP COLUMN qr_token_rotated_at;

-- The rotation-interval setting no longer applies; leaving it in the
-- settings table is harmless, but this keeps Admin > Settings tidy.
DELETE FROM settings WHERE setting_key = 'qr_token_rotation_seconds';

SELECT 'Migration v4 complete: laboratories now use one fixed QR code each — see Admin > Laboratory QR Codes.' AS status;
