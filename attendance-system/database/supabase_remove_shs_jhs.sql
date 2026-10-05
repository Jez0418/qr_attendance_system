-- ============================================================
-- Remove the Senior High School (SHS) and Junior High School (JHS) programs.
-- Run in Supabase SQL Editor (click in editor, Ctrl+A, Run).
-- Safe to re-run.
--
-- A program still used by a student or class assignment is only set to
-- 'inactive' (hidden from every dropdown) so those records keep their program;
-- unused ones are deleted outright.
-- ============================================================

UPDATE programs SET status = 'inactive'
WHERE program_code IN ('SHS', 'JHS');

DELETE FROM programs p
WHERE p.program_code IN ('SHS', 'JHS')
  AND NOT EXISTS (SELECT 1 FROM students s WHERE s.program_id = p.program_id)
  AND NOT EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.program_id = p.program_id);

-- Check the result (rows left here are inactive and still referenced)
SELECT p.program_code, p.status,
       (SELECT COUNT(*) FROM students s WHERE s.program_id = p.program_id) AS students,
       (SELECT COUNT(*) FROM teacher_subjects ts WHERE ts.program_id = p.program_id) AS assignments
FROM programs p
WHERE p.program_code IN ('SHS', 'JHS');
