<?php
/**
 * Athletikclub Steiermark – Hauptkonfiguration
 * Passe APP_URL und DB_* Konstanten für die Produktion an.
 */

// ----------------------------------------------------------------
// Umgebung: 'development' | 'production'
// ----------------------------------------------------------------
define('APP_ENV', 'production');

// ----------------------------------------------------------------
// App-Grundeinstellungen
// ----------------------------------------------------------------
define('APP_NAME',    'Athletikclub Steiermark');
define('APP_SHORT',   'AC Steiermark');

// APP_URL automatisch aus dem aktuellen Request ableiten, damit Assets
// (CSS/JS) immer über das Protokoll geladen werden, mit dem die Seite
// aufgerufen wurde (verhindert "Mixed Content"-Blockierungen, solange
// http und https parallel möglich sind).
$_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    ? 'https'
    : 'http';
define('APP_URL', $_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'aci-stmk.at'));
define('APP_VERSION', '1.0.0');

// ----------------------------------------------------------------
// Verzeichnisse (absolute Serverpfade)
// ----------------------------------------------------------------
define('ROOT_PATH',    dirname(__DIR__));
define('UPLOAD_PATH',  ROOT_PATH . '/uploads');
define('PDF_PATH',     UPLOAD_PATH . '/pdfs');
define('IMG_PATH',     UPLOAD_PATH . '/images');

// ----------------------------------------------------------------
// Uploads
// ----------------------------------------------------------------
define('MAX_PDF_SIZE',   10 * 1024 * 1024); // 10 MB
define('MAX_IMAGE_SIZE',  5 * 1024 * 1024); // 5 MB
define('ALLOWED_PDF_TYPES',   ['application/pdf']);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

// ----------------------------------------------------------------
// E-Mail
// ----------------------------------------------------------------
define('MAIL_FROM',      'noreply@athletikclub-steiermark.at');
define('MAIL_FROM_NAME', 'Athletikclub Steiermark');
define('MAIL_ADMIN',     'office@athletikclub-steiermark.at');

// ----------------------------------------------------------------
// Session
// ----------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    if (APP_ENV === 'production' && str_starts_with(APP_URL, 'https://')) {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// ----------------------------------------------------------------
// Fehlerausgabe
// ----------------------------------------------------------------
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ----------------------------------------------------------------
// Zeitzone
// ----------------------------------------------------------------
date_default_timezone_set('Europe/Vienna');

// ----------------------------------------------------------------
// CSRF-Token generieren
// ----------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
