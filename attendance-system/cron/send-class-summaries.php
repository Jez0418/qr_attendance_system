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

try {
    $pdo = app_connect();

    // Close sessions and finalize any absences before composing the reports.
    auto_expire_sessions($pdo);
    mark_absent_for_ended_sessions($pdo);

    // This is idempotent: a session is recorded after its summary is accepted.
    $sent = send_pending_class_summary_emails($pdo);
    echo json_encode(['success' => true, 'sent' => $sent]);
} catch (Throwable $e) {
    error_log('class_summary_cron: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Summary processing failed.']);
}
