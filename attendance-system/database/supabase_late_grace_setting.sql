-- Optional: seed the late grace period setting (Admin > Settings > Late Grace Period).
-- The app already defaults to 15 minutes when this row is missing. Safe to re-run.
INSERT INTO settings (setting_key, setting_value) VALUES ('late_grace_minutes', '15')
ON CONFLICT (setting_key) DO NOTHING;
