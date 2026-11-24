-- End-of-class attendance summary email records (includes/class_summary_mailer.php).
-- Tracks attendance summary emails sent to teachers when class meetings end.
-- Safe to run more than once. Run in the Supabase SQL Editor.

CREATE TABLE IF NOT EXISTS session_email_summaries (
    session_id INT PRIMARY KEY REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    teacher_id INT NOT NULL REFERENCES teachers(teacher_id) ON DELETE CASCADE,
    recipient_email VARCHAR(100) NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    present_count INT NOT NULL DEFAULT 0,
    late_count INT NOT NULL DEFAULT 0,
    absent_count INT NOT NULL DEFAULT 0,
    excused_count INT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_ses_sum_teacher ON session_email_summaries (teacher_id, sent_at DESC);

-- Same as every other table: no public API access; only the app's own database connection uses it.
ALTER TABLE session_email_summaries ENABLE ROW LEVEL SECURITY;
