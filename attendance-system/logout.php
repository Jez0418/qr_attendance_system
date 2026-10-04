<?php
/**
 * logout.php - destroys the session and returns to the login page.
 */
require_once __DIR__ . '/includes/auth.php';
do_logout($pdo);
set_flash('success', 'You have been logged out successfully.');
redirect('login.php');
