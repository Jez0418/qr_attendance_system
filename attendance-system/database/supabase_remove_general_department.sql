-- ============================================================
-- Remove the "General / Unassigned Department" (GEN) from every institution.
-- Run AFTER supabase_departments_programs.sql (SQL Editor: click in editor,
-- Ctrl+A, Run). Safe to re-run.
--
-- GEN was only a placeholder from supabase_schema.sql. Anything still in it is
-- moved first: BSCS goes to CITE (as the old move_bscs.php intended), along with
-- its students and class assignments.
-- ============================================================

UPDATE programs p SET department_id = cite.department_id
FROM departments gen, departments cite
WHERE gen.department_id = p.department_id AND gen.department_code = 'GEN'
  AND cite.institution_id = gen.institution_id AND cite.department_code = 'CITE'
  AND p.program_code = 'BSCS';

-- Students and class assignments follow their program's department.
UPDATE students s SET department_id = p.department_id
FROM programs p, departments gen
WHERE p.program_id = s.program_id
  AND gen.department_id = s.department_id AND gen.department_code = 'GEN';

UPDATE teacher_subjects ts SET department_id = p.department_id
FROM programs p, departments gen
WHERE p.program_id = ts.program_id
  AND gen.department_id = ts.department_id AND gen.department_code = 'GEN';

-- Delete GEN where nothing uses it any more; otherwise just hide it.
UPDATE departments SET status = 'inactive' WHERE department_code = 'GEN';

DELETE FROM departments d
WHERE d.department_code = 'GEN'
  AND NOT EXISTS (SELECT 1 FROM programs p WHERE p.department_id = d.department_id)
  AND NOT EXISTS (SELECT 1 FROM students s WHERE s.department_id = d.department_id)
  AND NOT EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.department_id = d.department_id);

-- Check the result (rows left here are inactive and still referenced)
SELECT d.department_id, d.department_code, d.status FROM departments d WHERE d.department_code = 'GEN';
