<?php
/**
 * index.php - entry point. Redirects to the correct place.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    switch ($_SESSION['role']) {
        case 'admin':   redirect('admin/dashboard.php');
        case 'teacher': redirect('teacher/dashboard.php');
        case 'student': redirect('student/dashboard.php');
    }
}
redirect('login.php');
