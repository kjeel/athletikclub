<?php
/**
 * Athletikclub Steiermark – Logout
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

if (isLoggedIn()) {
    logActivity('logout', 'Abgemeldet');
    logoutUser();
}

// Remember-me Cookie löschen
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

flashMessage('success', 'Du wurdest erfolgreich abgemeldet.');
redirect(APP_URL . '/auth/login.php');
