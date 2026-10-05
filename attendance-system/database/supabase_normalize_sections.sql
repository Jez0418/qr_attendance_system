-- ============================================================
-- Make every section use ONE format: year level + letter, e.g. "3A"
-- (the format the Add Student form produces). Fixes class sections
-- stored as "BSIT-3A" / "BSIT 3A" so the Class Schedule section filter
-- and the enrollment checks all agree.
--
-- Safe to run more than once. Run it in the Supabase SQL Editor
-- (click in the editor, Ctrl+A, Run). Look at the preview first:
-- ============================================================

-- 1) PREVIEW (read-only): what will change
SELECT 'class' AS kind, teacher_subject_id AS id, section AS old_section,
       year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '') AS new_section
FROM teacher_subjects
WHERE section IS NOT NULL AND year_level IS NOT NULL
  AND section IS DISTINCT FROM year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '')
UNION ALL
SELECT 'student', student_id, section,
       year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '')
FROM students
WHERE section IS NOT NULL AND year_level IS NOT NULL
  AND section IS DISTINCT FROM year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '')
ORDER BY 1, 2;

-- 2) APPLY
UPDATE teacher_subjects
SET section = year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '')
WHERE section IS NOT NULL AND year_level IS NOT NULL
  AND section IS DISTINCT FROM year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '');

UPDATE students
SET section = year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '')
WHERE section IS NOT NULL AND year_level IS NOT NULL
  AND section IS DISTINCT FROM year_level::text || regexp_replace(regexp_replace(upper(trim(section)), '^.*[- ]', ''), '^[0-9]+', '');

-- 3) CHECK: the distinct sections now in use
SELECT 'class' AS kind, section, count(*) FROM teacher_subjects GROUP BY section
UNION ALL
SELECT 'student', section, count(*) FROM students GROUP BY section
ORDER BY 1, 2;
