<?php
/**
 * Athletikclub Steiermark – Auth-Helper-Funktionen
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/money.php';

// ----------------------------------------------------------------
// Session-Zugriff
// ----------------------------------------------------------------

/** Gibt die User-ID der aktuellen Session zurück oder null. */
function getCurrentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/** Gibt die Rolle des aktuellen Nutzers zurück oder null. */
function getCurrentUserRole(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

/** Gibt das vollständige User-Array der Session zurück. */
function getCurrentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) return null;
    return [
        'id'       => (int)$_SESSION['user_id'],
        'vorname'  => $_SESSION['user_vorname'] ?? '',
        'nachname' => $_SESSION['user_nachname'] ?? '',
        'email'    => $_SESSION['user_email'] ?? '',
        'rolle'    => $_SESSION['user_role'] ?? 'mitglied',
    ];
}

/** Ist der Benutzer eingeloggt? */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/** Ist der Benutzer Admin? */
function isAdmin(): bool
{
    return getCurrentUserRole() === 'admin';
}

/** Ist der Benutzer Trainer oder Admin? */
function isTrainer(): bool
{
    return in_array(getCurrentUserRole(), ['trainer', 'admin'], true);
}

// ----------------------------------------------------------------
// Zugriffschutz
// ----------------------------------------------------------------

/** Leitet zur Login-Seite weiter, wenn nicht eingeloggt. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . APP_URL . '/auth/login.php');
        exit;
    }
}

/** Erlaubt nur Trainer oder Admins. */
function requireTrainer(): void
{
    requireLogin();
    if (!isTrainer()) {
        header('Location: ' . APP_URL . '/dashboard/index.php?error=no_permission');
        exit;
    }
}

/** Erlaubt nur Admins. */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . APP_URL . '/dashboard/index.php?error=no_permission');
        exit;
    }
}

// ----------------------------------------------------------------
// Login / Logout
// ----------------------------------------------------------------

/**
 * Loggt einen Benutzer ein und befüllt die Session.
 */
function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']         = $user['id'];
    $_SESSION['user_vorname']    = $user['vorname'];
    $_SESSION['user_nachname']   = $user['nachname'];
    $_SESSION['user_email']      = $user['email'];
    $_SESSION['user_role']       = $user['rolle'];
    $_SESSION['organization_id'] = (int)($user['organization_id'] ?? 1);
    $_SESSION['login_time']      = time();
}

/**
 * Loggt den aktuellen Benutzer aus.
 */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// ----------------------------------------------------------------
// CSRF-Schutz
// ----------------------------------------------------------------

/** Gibt das CSRF-Token für Formulare zurück. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">';
}

/** Validiert ein POST-CSRF-Token. */
function verifyCsrf(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/** CSRF prüfen oder abbrechen. */
function requireCsrf(): void
{
    if (!verifyCsrf()) {
        http_response_code(403);
        die('Ungültige Anfrage (CSRF).');
    }
}

// ----------------------------------------------------------------
// Passwort
// ----------------------------------------------------------------

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

// ----------------------------------------------------------------
// Tokens
// ----------------------------------------------------------------

function generateToken(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

// ----------------------------------------------------------------
// Hilfsfunktionen
// ----------------------------------------------------------------

function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flashMessage(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Aktivitätslog-Eintrag erstellen.
 */
function logActivity(string $aktion, ?string $details = null): void
{
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO aktivitaets_log (user_id, aktion, details, ip_adresse) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            getCurrentUserId(),
            $aktion,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        // Logging-Fehler still ignorieren
    }
}
