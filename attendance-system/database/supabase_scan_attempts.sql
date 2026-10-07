-- Rejected QR scans (outside the radius, not enrolled, class ended, ...), so a teacher can see them
-- together with the student's distance from the laboratory. Accepted scans stay in attendance_records.
-- Safe to run more than once. Run in the Supabase SQL Editor before deploying the code that uses it.
CREATE TABLE IF NOT EXISTS scan_attempts (
    attempt_id SERIAL PRIMARY KEY,
    session_id INT NOT NULL REFERENCES attendance_sessions(session_id) ON DELETE CASCADE,
    student_id INT NOT NULL REFERENCES students(student_id) ON DELETE CASCADE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255) NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_accuracy DECIMAL(7,2) NULL,
    distance_from_location DECIMAL(10,2) NULL
);
CREATE INDEX IF NOT EXISTS idx_scan_attempts_session ON scan_attempts (session_id, attempted_at DESC);

-- Same as every other table: no public API access; only the app's own database connection uses it.
ALTER TABLE scan_attempts ENABLE ROW LEVEL SECURITY;
