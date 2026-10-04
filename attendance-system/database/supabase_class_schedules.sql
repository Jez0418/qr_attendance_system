-- ============================================================
-- Recurring class schedules (run in Supabase SQL Editor BEFORE
-- deploying the code that uses it). Safe to re-run.
-- ------------------------------------------------------------
-- A class assignment (teacher_subjects row) defines WHO teaches
-- WHAT to WHICH class and WHERE. WHEN it meets is now a set of
-- weekly slots in class_schedules (e.g. Mon 08:00-11:00 and
-- Thu 13:00-15:00), instead of one hand-entered meeting date per
-- assignment. "Is this class meeting today?" is derived from these
-- slots and today's day of week.
--
-- day_of_week uses ISO numbering: 1 = Monday ... 7 = Sunday
-- (same as PHP date('N') and Postgres EXTRACT(ISODOW ...)).
-- ============================================================

CREATE TABLE IF NOT EXISTS class_schedules (
    schedule_id SERIAL PRIMARY KEY,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    day_of_week SMALLINT NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    CHECK (end_time > start_time),
    UNIQUE (teacher_subject_id, day_of_week, start_time)
);
CREATE INDEX IF NOT EXISTS idx_cs_class ON class_schedules (teacher_subject_id);
CREATE INDEX IF NOT EXISTS idx_cs_day ON class_schedules (day_of_week);

-- Backfill 1: assignments that had a single meeting_date become a weekly
-- slot on that date's weekday, at the same times.
INSERT INTO class_schedules (teacher_subject_id, day_of_week, start_time, end_time)
SELECT ts.teacher_subject_id, EXTRACT(ISODOW FROM ts.meeting_date)::smallint, ts.start_time, ts.end_time
FROM teacher_subjects ts
WHERE ts.meeting_date IS NOT NULL
  AND ts.start_time IS NOT NULL AND ts.end_time > ts.start_time
  AND NOT EXISTS (SELECT 1 FROM class_schedules cs WHERE cs.teacher_subject_id = ts.teacher_subject_id)
ON CONFLICT DO NOTHING;

-- Backfill 2: older assignments with only a day name ("Monday", "Mon/Wed", ...)
INSERT INTO class_schedules (teacher_subject_id, day_of_week, start_time, end_time)
SELECT ts.teacher_subject_id, d.dow, ts.start_time, ts.end_time
FROM teacher_subjects ts
JOIN (VALUES (1,'mon'),(2,'tue'),(3,'wed'),(4,'thu'),(5,'fri'),(6,'sat'),(7,'sun')) AS d(dow, abbr)
  ON lower(ts.schedule_day) LIKE '%' || d.abbr || '%'
WHERE ts.meeting_date IS NULL
  AND ts.start_time IS NOT NULL AND ts.end_time > ts.start_time
  AND NOT EXISTS (SELECT 1 FROM class_schedules cs WHERE cs.teacher_subject_id = ts.teacher_subject_id)
ON CONFLICT DO NOTHING;

-- teacher_subjects.schedule_day / start_time / end_time are now only a
-- display summary written by the app (first slot); class_schedules is
-- the source of truth. meeting_date is no longer used.
ALTER TABLE teacher_subjects ALTER COLUMN schedule_day DROP NOT NULL;
ALTER TABLE teacher_subjects ALTER COLUMN start_time DROP NOT NULL;
ALTER TABLE teacher_subjects ALTER COLUMN end_time DROP NOT NULL;
COMMENT ON COLUMN teacher_subjects.meeting_date IS 'Deprecated: replaced by class_schedules (recurring weekly slots).';
