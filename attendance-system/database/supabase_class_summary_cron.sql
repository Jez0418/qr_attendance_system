-- Runs the protected Vercel endpoint once per minute so class summaries are
-- sent at the scheduled class end even when nobody is browsing the app.
--
-- Before scheduling:
--   1. Deploy the app with cron/send-class-summaries.php.
--   2. Set CRON_SECRET in Vercel to a strong random value.
--   3. Store the same value in Supabase Vault under the name
--      ilabq_class_summary_cron_secret, for example:
--      SELECT vault.create_secret('<same-random-value>', 'ilabq_class_summary_cron_secret');
--
-- This endpoint also requires the existing MAIL_API_KEY and MAIL_FROM Vercel
-- environment variables for Brevo delivery.

CREATE EXTENSION IF NOT EXISTS pg_cron WITH SCHEMA pg_catalog;
CREATE EXTENSION IF NOT EXISTS pg_net WITH SCHEMA extensions;
GRANT USAGE ON SCHEMA cron TO postgres;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA cron TO postgres;

-- Make this setup safe to re-run without creating duplicate scheduled jobs.
SELECT cron.unschedule(jobid)
FROM cron.job
WHERE jobname = 'ilabq-class-summary-email';

SELECT cron.schedule(
    'ilabq-class-summary-email',
    '* * * * *',
    $job$
        SELECT net.http_post(
            url := 'https://qrattendancesystem-attendance-syste.vercel.app/cron/send-class-summaries.php',
            headers := jsonb_build_object(
                'Content-Type', 'application/json',
                'Authorization', 'Bearer ' || (
                    SELECT decrypted_secret
                    FROM vault.decrypted_secrets
                    WHERE name = 'ilabq_class_summary_cron_secret'
                )
            ),
            body := '{}'::jsonb,
            timeout_milliseconds := 10000
        );
    $job$
);
