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
define('VEREIN_ZVR',      '1545056798');
define('VEREIN_ADRESSE',  'Sankt Georgen an der Stiefing 14, 8413 Sankt Georgen an der Stiefing');

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

// Suchmaschinen: EINE Hauptadresse, auf die sich alle canonical-Links,
// die Sitemap und Social-Media-Vorschauen beziehen (ohne / am Ende).
define('SITE_URL', 'https://aci-stmk.at');
// Google Search Console → "HTML-Tag"-Bestätigung: nur den content-Wert eintragen
define('GOOGLE_SITE_VERIFICATION', '');

/**
 * Liefert eine Asset-URL mit Cache-Busting (?v=Dateiänderungszeit),
 * damit Browser nach einem Deploy nie eine veraltete CSS-/JS-Datei
 * aus dem Cache anzeigen. $path relativ zum Projekt-Root, z.B.
 * '/assets/css/style.css'.
 */
function asset_url(string $path): string
{
    $file = ROOT_PATH . $path;
    $version = is_file($file) ? filemtime($file) : time();
    return APP_URL . $path . '?v=' . $version;
}

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
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
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
    // Produktion: nichts anzeigen, aber alles protokollieren (logs/ ist per .htaccess gesperrt)
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL & ~E_DEPRECATED);
    $_logdir = dirname(__DIR__) . '/logs';
    if (!is_dir($_logdir)) @mkdir($_logdir, 0750, true);
    if (is_dir($_logdir) && is_writable($_logdir)) {
        if (!is_file($_logdir . '/.htaccess')) @file_put_contents($_logdir . '/.htaccess', "Require all denied\nDeny from all\n");
        ini_set('error_log', $_logdir . '/php-error.log');
    }
    unset($_logdir);
}

/**
 * Nicht abgefangene Fehler: technische Details nur ins Log, Benutzer sehen eine neutrale Meldung
 * (keine SQLSTATE-Texte, Pfade oder Stacktraces im Browser).
 */
set_exception_handler(function (Throwable $e): void {
    error_log('[ERROR] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
              . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Fehler: " . $e->getMessage() . "\n"); exit(1); }
    if (APP_ENV === 'development') { echo '<pre>' . htmlspecialchars((string)$e) . '</pre>'; return; }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fehler</title></head>'
       . '<body style="font-family: system-ui, sans-serif; background: #F4F6F9; color: #1F3556; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; padding: 1rem;">'
       . '<div style="max-width: 440px; background: #fff; border-radius: 14px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,.08); text-align: center;">'
       . '<h1 style="font-size: 1.25rem;">Es ist ein Fehler aufgetreten.</h1><p style="color: #555;">Bitte versuche es in ein paar Minuten erneut. Der Fehler wurde protokolliert.</p>'
       . '<p><a href="/" style="color: #C6A135; font-weight: 600;">Zur Startseite</a></p></div></body></html>';
});

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
