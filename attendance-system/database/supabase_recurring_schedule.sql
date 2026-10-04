-- ============================================================
-- Recurring schedules + per-date exceptions
-- Run in the Supabase SQL Editor (click in the editor, Ctrl+A, Run)
-- BEFORE deploying code that uses it. Safe to re-run, and safe
-- whether or not database/supabase_class_schedules.sql ran before.
-- ------------------------------------------------------------
-- teacher_subjects  = the class assignment (who / what / which class / where)
-- class_schedules   = its weekly rules: weekday + time, optionally limited
--                     to an effective date range (e.g. one semester)
-- schedule_exceptions = changes to ONE meeting date of a class:
--                     CANCELLED, or RESCHEDULED (new date and/or time,
--                     lab, or substitute teacher)
-- Individual meetings ("occurrences") are never stored; they are
-- generated from these tables by includes/schedule.php.
--
-- day_of_week uses ISO numbering: 1 = Monday ... 7 = Sunday
-- (PHP date('N'), Postgres EXTRACT(ISODOW ...)).
-- ============================================================

-- ---------- class_schedules ----------
CREATE TABLE IF NOT EXISTS class_schedules (
    schedule_id SERIAL PRIMARY KEY,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    day_of_week SMALLINT NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    effective_start_date DATE NULL,
    effective_end_date DATE NULL,
    CHECK (end_time > start_time)
);
-- If the table came from supabase_class_schedules.sql, add the new columns.
ALTER TABLE class_schedules ADD COLUMN IF NOT EXISTS effective_start_date DATE NULL;
ALTER TABLE class_schedules ADD COLUMN IF NOT EXISTS effective_end_date DATE NULL;
-- The older table had UNIQUE (teacher_subject_id, day_of_week, start_time), which
-- would block the same weekday/time in two different effective date ranges.
ALTER TABLE class_schedules DROP CONSTRAINT IF EXISTS class_schedules_teacher_subject_id_day_of_week_start_time_key;
ALTER TABLE class_schedules DROP CONSTRAINT IF EXISTS class_schedules_effective_range_chk;
ALTER TABLE class_schedules ADD CONSTRAINT class_schedules_effective_range_chk
    CHECK (effective_start_date IS NULL OR effective_end_date IS NULL OR effective_end_date >= effective_start_date);

CREATE INDEX IF NOT EXISTS idx_cs_class ON class_schedules (teacher_subject_id);
CREATE INDEX IF NOT EXISTS idx_cs_day ON class_schedules (day_of_week);
CREATE INDEX IF NOT EXISTS idx_cs_effective ON class_schedules (effective_start_date, effective_end_date);

-- ---------- schedule_exceptions ----------
CREATE TABLE IF NOT EXISTS schedule_exceptions (
    exception_id SERIAL PRIMARY KEY,
    teacher_subject_id INT NOT NULL REFERENCES teacher_subjects(teacher_subject_id) ON DELETE CASCADE,
    original_date DATE NOT NULL,
    exception_type VARCHAR(12) NOT NULL CHECK (exception_type IN ('CANCELLED','RESCHEDULED')),
    new_date DATE NULL,             -- RESCHEDULED: NULL = same date
    new_start_time TIME NULL,       -- RESCHEDULED: NULL = original times
    new_end_time TIME NULL,
    new_lab_id INT NULL REFERENCES laboratories(lab_id) ON DELETE SET NULL,      -- NULL = usual lab
    new_teacher_id INT NULL REFERENCES teachers(teacher_id) ON DELETE SET NULL,  -- NULL = usual teacher
    reason VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT schedule_exceptions_class_date_key UNIQUE (teacher_subject_id, original_date),
    CONSTRAINT schedule_exceptions_times_chk CHECK ((new_start_time IS NULL) = (new_end_time IS NULL)
        AND (new_start_time IS NULL OR new_end_time > new_start_time))
);
CREATE INDEX IF NOT EXISTS idx_se_original_date ON schedule_exceptions (original_date);
CREATE INDEX IF NOT EXISTS idx_se_new_date ON schedule_exceptions (new_date);
CREATE INDEX IF NOT EXISTS idx_se_new_teacher ON schedule_exceptions (new_teacher_id);
CREATE INDEX IF NOT EXISTS idx_se_new_lab ON schedule_exceptions (new_lab_id);

-- ---------- migrate old single-meeting assignments ----------
-- Every teacher_subjects row with a meeting_date becomes one weekly rule on
-- that date's weekday at the same times (skipped if the class already has rules).
INSERT INTO class_schedules (teacher_subject_id, day_of_week, start_time, end_time)
SELECT ts.teacher_subject_id, EXTRACT(ISODOW FROM ts.meeting_date)::smallint, ts.start_time, ts.end_time
FROM teacher_subjects ts
WHERE ts.meeting_date IS NOT NULL
  AND ts.start_time IS NOT NULL AND ts.end_time > ts.start_time
  AND NOT EXISTS (SELECT 1 FROM class_schedules cs WHERE cs.teacher_subject_id = ts.teacher_subject_id);

-- ---------- old columns: keep, but no longer required ----------
ALTER TABLE teacher_subjects ALTER COLUMN meeting_date DROP NOT NULL;
ALTER TABLE teacher_subjects ALTER COLUMN schedule_day DROP NOT NULL;
ALTER TABLE teacher_subjects ALTER COLUMN start_time DROP NOT NULL;
ALTER TABLE teacher_subjects ALTER COLUMN end_time DROP NOT NULL;
COMMENT ON COLUMN teacher_subjects.meeting_date IS 'Deprecated: replaced by class_schedules + schedule_exceptions.';
