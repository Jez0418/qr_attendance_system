-- Make Asia/Manila the default time zone of the app's database role.
--
-- includes/db_connect.php runs SET TIME ZONE 'Asia/Manila' on every connection, but behind
-- Supabase's transaction pooler (DB_PORT 6543) consecutive queries can run on different
-- server connections, so a per-connection SET is not reliable there. Every timestamp
-- column is "timestamp without time zone" filled from NOW(), so a query that ran in UTC
-- would store a time 8 hours off. With the role default, every new server connection
-- starts in Manila time. Applied to the live project on 2026-10-26.
--
-- Takes effect for NEW connections only. Undo: ALTER ROLE postgres RESET timezone;
ALTER ROLE postgres SET timezone = 'Asia/Manila';

-- End any connection that sits inside an open transaction for more than 60 s (it is rolled back).
-- A client that vanished mid-transaction (network drop) would otherwise keep its row locks until
-- the pooler noticed, blocking edits to those rows. The app's longest transaction (CSV import,
-- 8 s budget) stays far below this. Applied 2026-10-26. Undo: ALTER ROLE postgres RESET idle_in_transaction_session_timeout;
ALTER ROLE postgres SET idle_in_transaction_session_timeout = '60s';
