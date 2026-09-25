<?php
/**
 * Athletikclub Steiermark – Kursbilder ausliefern
 * uploads/ ist per .htaccess gesperrt; Bilder öffentlicher Kurse/Events werden hier
 * ausgeliefert, alle anderen nur für eingeloggte Personen.
 *   ?k=KURS_ID
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

$stmt = getDB()->prepare('SELECT bild, oeffentlich FROM kurse WHERE id = ? AND organization_id = ?');
$stmt->execute([(int)($_GET['k'] ?? 0), currentOrgId()]);
$k = $stmt->fetch();
$pfad = $k && $k['bild'] && preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_]+\.(jpg|png|webp)$#', $k['bild']) ? IMG_PATH . '/' . $k['bild'] : null;
if (!$pfad || !is_file($pfad) || (!(int)$k['oeffentlich'] && !isLoggedIn())) {
    http_response_code(404);
    exit;
}
$typ = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($pfad, PATHINFO_EXTENSION)];
header('Content-Type: ' . $typ);
header('Content-Length: ' . filesize($pfad));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($pfad);
