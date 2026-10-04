-- ============================================================
-- MIGRATION v3: Admin can also approve/reject enrollment requests
-- ============================================================
-- Run this ONCE against an existing qr_attendance_system database
-- that already has migration_v2 (or a v2 schema.sql) applied.
--
-- How to run it:
--   phpMyAdmin -> select qr_attendance_system -> SQL tab -> paste
--   this whole file -> Go.
-- ============================================================

USE qr_attendance_system;

ALTER TABLE enrollment_requests
    MODIFY COLUMN reviewed_by INT NULL COMMENT 'teacher_id, set only when a teacher reviewed it',
    ADD COLUMN reviewed_by_role ENUM('teacher','admin') NULL AFTER reviewed_by,
    ADD COLUMN reviewed_by_user_id INT NULL COMMENT 'users.user_id of whoever actually reviewed it' AFTER reviewed_by_role,
    ADD CONSTRAINT fk_request_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL;

-- Backfill: any already-reviewed rows get marked as reviewed by the teacher
-- on record (since prior to this migration only teachers could review).
UPDATE enrollment_requests r
JOIN teachers t ON t.teacher_id = r.reviewed_by
JOIN users u ON u.user_id = t.user_id
SET r.reviewed_by_role = 'teacher', r.reviewed_by_user_id = u.user_id
WHERE r.reviewed_by IS NOT NULL AND r.reviewed_by_role IS NULL;

SELECT 'Migration v3 complete: admins can now approve/reject enrollment requests directly.' AS status;
