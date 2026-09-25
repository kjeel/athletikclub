<?php
/**
 * Athletikclub Steiermark – Cron-Einstieg für die Automatisierungs-Engine
 *
 * Aufruf (z.B. alle 15 Minuten über den Cron-Dienst des Hostings):
 *   https://…/cron.php?key=GEHEIMER_SCHLUESSEL
 *   oder auf der Konsole: php cron.php
 * Den Schlüssel erzeugt die Seite „Automatisierungen“ im Dashboard.
 */
define('ROOT_PATH', __DIR__);
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/automation.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

if (PHP_SAPI !== 'cli') {
    $soll = (string)einstellung('cron_schluessel', '');
    $ist = (string)($_GET['key'] ?? '');
    if ($soll === '' || strlen($soll) < 32 || !hash_equals($soll, $ist)) {
        http_response_code(403);
        exit("Zugriff verweigert.\n");
    }
}

$erg = automationLauf(getDB());
if ($erg === null) {
    echo "Ein anderer Lauf ist gerade aktiv.\n";
    exit;
}
foreach ($erg['ausgefuehrt'] as $code => $n) echo str_pad($code, 26) . " $n\n";
foreach ($erg['fehler'] as $code => $f) echo "FEHLER $code: $f\n";
echo 'OK ' . date('Y-m-d H:i:s') . "\n";
