<?php
/**
 * Authenticated scheduler endpoint. Supabase Cron calls this once a minute
 * to finalize ended sessions and send any outstanding teacher summaries.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

$expected = (string) getenv('CRON_SECRET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($expected === '' || !hash_equals('Bearer ' . $expected, $authorization)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// This endpoint has no browser user session; avoid creating one for each cron run.
define('APP_SKIP_SESSION', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/absences.php';
require_once __DIR__ . '/../includes/class_summary_mailer.php';
require_once __DIR__ . '/../includes/supabase_auth.php';

try {
    $pdo = app_connect();

    // Link a few legacy/new app profiles to Supabase Auth each minute. This lets
    // Auth handle password checks and recovery without delaying normal requests.
    $authMigration = ['linked' => 0, 'failed' => []];
    $authMigrationOnly = in_array(strtolower(trim((string) getenv('SUPABASE_AUTH_MIGRATE_ONLY'))), ['1', 'true', 'yes', 'on'], true);
    if ((supabase_auth_enabled() || $authMigrationOnly) && supabase_auth_configured(true)) {
        // Pre-link all accounts while legacy login still works; clear the local
        // hashes only after Supabase Auth is switched on.
        $authMigration = supabase_auth_migrate_pending_profiles($pdo, supabase_auth_enabled(), 5);
    }
    $authHashesCleared = 0;
    if (supabase_auth_enabled()) {
        $authHashesCleared = supabase_auth_clear_linked_legacy_passwords($pdo);
    }

    // Close sessions and finalize any absences before composing the reports.
    auto_expire_sessions($pdo);
    mark_absent_for_ended_sessions($pdo);

    // This is idempotent: a session is recorded after its summary is accepted.
    $sent = send_pending_class_summary_emails($pdo);
    echo json_encode([
        'success' => true,
        'sent' => $sent,
        'auth_profiles_linked' => $authMigration['linked'],
        'auth_profiles_pending' => supabase_auth_pending_profile_count($pdo),
        'auth_legacy_hashes_cleared' => $authHashesCleared,
    ]);
} catch (Throwable $e) {
    error_log('class_summary_cron: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Summary processing failed.']);
}
