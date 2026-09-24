<?php
/**
 * Athletikclub Steiermark – Kalender-Abo (iCalendar-Feed)
 * Aufruf ohne Login über den persönlichen, geheimen Token aus kalender_abos.
 * Liefert die Einträge von 60 Tagen zurück bis 12 Monate voraus.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kalender.php';

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Kalender nicht gefunden.');
}

$db = getDB();
try {
    $stmt = $db->prepare("SELECT u.id, u.organization_id, u.rolle, u.vorname
                          FROM kalender_abos a JOIN users u ON u.id = a.user_id
                          WHERE a.token = ? AND u.aktiv = 1 LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $user = null;
}
if (!$user) {
    http_response_code(404);
    exit('Kalender nicht gefunden.');
}

$trainer = in_array($user['rolle'], ['trainer', 'admin'], true);
$eintraege = kalEintraege($db, (int)$user['organization_id'], (int)$user['id'], $trainer,
                          date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+12 months')));

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="aci-kalender.ics"');
header('Cache-Control: private, max-age=900');
header('X-Robots-Tag: noindex');
echo kalIcs($eintraege, 'Athletikclub Steiermark', APP_URL);
