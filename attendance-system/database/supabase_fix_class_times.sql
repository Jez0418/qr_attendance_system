-- ------------------------------------------------------------
-- supabase_fix_class_times.sql
-- Replaces three test-looking weekly schedules with realistic class hours
-- (run BEFORE tests/demo_seed.php, which follows class_schedules).
--
--   #4 IT201 BSIT 3A (Cruz, Chemistry Lab 1)  Wed + Sun 7:53-9:53 PM  -> Wed + Sat 8:00-10:00 AM
--   #6 IT401 BSN 2A  (Santos, Physics Lab)    Tue + Fri 8:52-11:52 AM -> Tue + Fri 8:00-11:00 AM
--   #7 IT301 BSIT 3A (Ganal, Zoology Lab)     Wed + Sat 10:14-11:14 PM -> Wed + Sat 1:00-2:30 PM
--
-- Checked against the other classes: no teacher, lab or BSIT 3A overlap.
-- Each UPDATE matches the old values too, so running the file twice changes nothing.
-- Supabase SQL Editor: click in the editor, Ctrl+A, Run.
-- ------------------------------------------------------------
BEGIN;

UPDATE class_schedules SET start_time = '08:00', end_time = '10:00'
WHERE teacher_subject_id = 4 AND day_of_week = 3 AND start_time = '19:53';
UPDATE class_schedules SET day_of_week = 6, start_time = '08:00', end_time = '10:00'
WHERE teacher_subject_id = 4 AND day_of_week = 7 AND start_time = '19:53';

UPDATE class_schedules SET start_time = '08:00', end_time = '11:00'
WHERE teacher_subject_id = 6 AND day_of_week IN (2, 5) AND start_time = '08:52';

UPDATE class_schedules SET start_time = '13:00', end_time = '14:30'
WHERE teacher_subject_id = 7 AND day_of_week IN (3, 6) AND start_time = '22:14';

COMMIT;

SELECT teacher_subject_id, day_of_week, start_time, end_time
FROM class_schedules WHERE teacher_subject_id IN (4, 6, 7) ORDER BY 1, 2;
