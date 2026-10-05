-- ============================================================
-- Login lockout: remembers FAILED sign-ins so the app can lock an account
-- (5 failures in 15 minutes) or an IP address (50 failures in 15 minutes).
-- Run in the Supabase SQL Editor (click in the editor, Ctrl+A, Run). Safe to re-run.
-- The app keeps working if this table does not exist yet (lockout is simply off).
--
-- To unlock someone before the 15 minutes pass:
--   DELETE FROM login_attempts WHERE username_key = 'their.username';
-- ============================================================
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id   BIGSERIAL PRIMARY KEY,
    username_key VARCHAR(100) NOT NULL,                 -- lower-cased username as typed (may not exist)
    ip_address   VARCHAR(64)  NOT NULL DEFAULT '',
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_user ON login_attempts (username_key, attempted_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip   ON login_attempts (ip_address, attempted_at);

-- Same as the other tables: block Supabase's public REST API from reading it.
ALTER TABLE login_attempts ENABLE ROW LEVEL SECURITY;
