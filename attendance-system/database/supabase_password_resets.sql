-- "Forgot password" links. Only a SHA-256 hash of each token is stored (never the token itself),
-- so a database leak cannot be turned into working reset links. A link is valid for one hour and once.
-- Safe to run more than once. Run in the Supabase SQL Editor before deploying the code that uses it.
CREATE TABLE IF NOT EXISTS password_resets (
    reset_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_password_resets_ip ON password_resets (ip_address, created_at DESC);

-- Same as every other table: no public API access; only the app's own database connection uses it.
ALTER TABLE password_resets ENABLE ROW LEVEL SECURITY;
