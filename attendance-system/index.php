<?php
/**
 * index.php - entry point. Redirects to the correct place.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    // Opened from the installed app (assets/manifest.json start_url): students go straight to the scanner.
    if (($_GET['source'] ?? '') === 'pwa' && $_SESSION['role'] === 'student') redirect('student/scanner.php');
    switch ($_SESSION['role']) {
        case 'admin':   redirect('admin/dashboard.php');
        case 'teacher': redirect('teacher/dashboard.php');
        case 'student': redirect('student/dashboard.php');
    }
}
redirect('login.php');
