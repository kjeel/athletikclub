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
 * Selbst registrierte Mitglieder starten mit Status "ausstehend" und dürfen
 * sich erst anmelden, wenn ein Admin sie in der Mitgliederverwaltung freigibt.
 * Mitglieder ohne Profil (Altbestand) werden nicht gesperrt.
 */
function mitgliedWartetAufFreigabe(PDO $db, int $userId): bool
{
    $stmt = $db->prepare('SELECT mitgliedsstatus FROM mitglieder_profile WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() === 'ausstehend';
}

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
        securityLog('csrf_ungueltig', ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        die('Das Formular ist abgelaufen oder ungültig. Bitte lade die Seite neu und versuche es noch einmal.');
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

/**
 * Einmal-Tokens (Passwort setzen/zurücksetzen, E-Mail bestätigen) werden nur als SHA-256-Hash gespeichert;
 * der Link enthält das Token selbst. Ein Datenbank-Auszug erlaubt so keine Kontoübernahme.
 */
function tokenHash(string $token): string
{
    return hash('sha256', $token);
}

// ----------------------------------------------------------------
// Hilfsfunktionen
// ----------------------------------------------------------------

/** HTML-Escaping; akzeptiert auch null/Zahlen (leere DB-Felder dürfen keinen Seitenabbruch auslösen). */
function e($str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
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

/**
 * Sicherheitsrelevante Ereignisse (fehlgeschlagene CSRF-Prüfung, Zugriff ohne Recht …):
 * Aktivitätsprotokoll mit Präfix „security:“ plus Server-Log. Keine Passwörter/Tokens übergeben.
 */
function securityLog(string $ereignis, ?string $details = null): void
{
    logActivity('security:' . $ereignis, $details !== null ? mb_substr($details, 0, 250) : null);
    error_log('[SECURITY] ' . $ereignis . ($details ? ' – ' . $details : '') . ' | user=' . (getCurrentUserId() ?? '-') . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
}

/**
 * onsubmit-/onclick-Attribut für eine konkrete Rückfrage (sicher für HTML-Attribut und JavaScript kodiert).
 * Beispiel: <form onsubmit="<?= bestaetigen('Kurs „' . $kurs['titel'] . '“ wirklich absagen?') ?>">
 */
function bestaetigen(string $frage): string
{
    return 'return confirm(' . htmlspecialchars(json_encode($frage, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') . ')';
}

// ----------------------------------------------------------------
// Öffentliche Formulare: Spam-Schutz
// ----------------------------------------------------------------
const FORMULAR_MAX_PRO_STUNDE = 5;

/** Unsichtbares Feld, das nur Bots ausfüllen (Name identisch mit dem Kursportal). */
function formularHoneypot(): string
{
    return '<div style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden;" aria-hidden="true">'
         . '<label>Website <input type="text" name="firma_web" tabindex="-1" autocomplete="off"></label></div>';
}

/**
 * Prüft ein abgeschicktes öffentliches Formular: 'honeypot' (Bot – so tun, als wäre alles ok),
 * 'limit' (zu viele Versuche dieser IP in der letzten Stunde) oder null. Jeder echte Versuch wird gezählt.
 */
function formularSpamPruefen(string $aktion, int $max = FORMULAR_MAX_PRO_STUNDE): ?string
{
    if (trim((string)($_POST['firma_web'] ?? '')) !== '') {
        securityLog('honeypot', $aktion);
        return 'honeypot';
    }
    try {
        $stmt = getDB()->prepare('SELECT COUNT(*) FROM aktivitaets_log WHERE aktion = ? AND ip_adresse = ? AND created_at > ?');
        $stmt->execute([$aktion, $_SERVER['REMOTE_ADDR'] ?? '', date('Y-m-d H:i:s', time() - 3600)]);
        if ((int)$stmt->fetchColumn() >= $max) {
            securityLog('rate_limit', $aktion);
            return 'limit';
        }
    } catch (Throwable $e) {}
    logActivity($aktion);
    return null;
}

// ----------------------------------------------------------------
// Session-Prüfung bei jedem Aufruf
// ----------------------------------------------------------------
const SESSION_LEERLAUF_SEK = 4 * 3600;   // Abmeldung nach 4 Stunden Inaktivität
const SESSION_PRUEF_SEK    = 60;         // Konto-Status höchstens 1× pro Minute nachladen

/**
 * Meldet ab, wenn die Sitzung zu lange inaktiv war oder das Konto inzwischen deaktiviert wurde,
 * und übernimmt Rollenänderungen sofort (statt erst beim nächsten Login).
 */
function sessionPruefen(): void
{
    if (PHP_SAPI === 'cli' || !isLoggedIn()) return;
    $jetzt = time();
    $letzte = (int)($_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? $jetzt);
    if ($jetzt - $letzte > SESSION_LEERLAUF_SEK) {
        logoutUser();
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        flashMessage('info', 'Du wurdest nach längerer Inaktivität abgemeldet. Bitte melde dich erneut an.');
        return;
    }
    $_SESSION['last_activity'] = $jetzt;
    if ($jetzt - (int)($_SESSION['konto_geprueft'] ?? 0) < SESSION_PRUEF_SEK) return;
    try {
        $stmt = getDB()->prepare('SELECT aktiv, rolle, organization_id FROM users WHERE id = ?');
        $stmt->execute([getCurrentUserId()]);
        $u = $stmt->fetch();
    } catch (Throwable $e) {
        return; // Datenbank kurz nicht erreichbar: Sitzung nicht beenden
    }
    if (!$u || !(int)$u['aktiv']) {
        logoutUser();
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        flashMessage('error', 'Dein Konto ist nicht (mehr) aktiv.');
        return;
    }
    if ($u['rolle'] !== ($_SESSION['user_role'] ?? null)) {
        $_SESSION['user_role'] = $u['rolle'];
        unset($_SESSION['berechtigungen']);
    }
    $_SESSION['organization_id'] = (int)($u['organization_id'] ?? 1);
    $_SESSION['konto_geprueft'] = $jetzt;
}
// Datenbank-Sitzung auf dieselbe Zeitzone wie PHP (Europe/Vienna inkl. Sommerzeit) stellen, damit
// NOW()/CURRENT_TIMESTAMP und in PHP berechnete Zeitfenster (Login-Sperre, Rate-Limits, Fristen) übereinstimmen.
try {
    if (getDB()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') getDB()->exec("SET time_zone = '" . date('P') . "'");
} catch (Throwable $e) {
    error_log('[WARNING] Zeitzone der Datenbank konnte nicht gesetzt werden: ' . $e->getMessage());
}

sessionPruefen();
