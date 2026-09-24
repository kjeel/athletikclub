<?php
/**
 * Athletikclub Steiermark – PDF Download Handler
 * Sicherer Download: Prüft Zugriffsberechtigung, erhöht Download-Zähler
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Ungültige Anfrage.');
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM dokumente WHERE id = ?');
$stmt->execute([$id]);
$dok = $stmt->fetch();

if (!$dok) {
    http_response_code(404);
    die('Dokument nicht gefunden.');
}

// Zugriffsprüfung
require_once ROOT_PATH . '/includes/plattform.php';
$allowed = false;

/** Leitung oder Team des Projekts? */
$imProjekt = function (int $projekt_id) use ($db): bool {
    try {
        $stmt = $db->prepare('SELECT 1 FROM projekte WHERE id = ? AND leitung_id = ? UNION SELECT 1 FROM projekt_team WHERE projekt_id = ? AND user_id = ?');
        $stmt->execute([$projekt_id, getCurrentUserId(), $projekt_id, getCurrentUserId()]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
};

if (!empty($dok['qualifikation_id'])) {
    // Qualifikationsnachweis: die Person selbst oder Qualifikations-Verwaltung
    $allowed = (int)$dok['mitglied_id'] === getCurrentUserId() || darf('qualifikationen.anzeigen');
} elseif (!empty($dok['vertrag_id'])) {
    $allowed = darf('vertraege.anzeigen');
} elseif (!empty($dok['partner_id'])) {
    $allowed = darf('partner.anzeigen');
} elseif (!empty($dok['buchung_id']) || !empty($dok['projekt_id'])) {
    // Projekt-Dokumente und Belege: Projektrechte, Finanz-/Förderrechte oder Projektteam
    $allowed = darfEines('projekte.anzeigen', 'finanzen.anzeigen', 'foerderungen.anzeigen') || (!empty($dok['projekt_id']) && $imProjekt((int)$dok['projekt_id']));
} elseif (!empty($dok['foerderung_id'])) {
    $allowed = darf('foerderungen.anzeigen');
} elseif (!empty($dok['kooperation_id'])) {
    // Förder- bzw. Kooperationsdokument: nur Admin
    $allowed = isAdmin();
} elseif (!empty($dok['mitglied_id'])) {
    // Dokument im persönlichen Archiv eines Mitglieds: nur das Mitglied selbst + Trainer/Admin
    $allowed = isTrainer() || (int)$dok['mitglied_id'] === getCurrentUserId();
} else {
    switch ($dok['sichtbar_fuer']) {
        case 'alle':
            $allowed = true;
            break;
        case 'mitglieder':
            $allowed = isLoggedIn();
            break;
        case 'trainer':
            $allowed = isTrainer();
            break;
        case 'admin':
            $allowed = isAdmin();
            break;
    }
}

if (!$allowed) {
    http_response_code(403);
    die('Kein Zugriff.');
}

$file = UPLOAD_PATH . '/' . $dok['datei_pfad'];

if (!file_exists($file)) {
    http_response_code(404);
    die('Datei nicht gefunden.');
}

// Download-Zähler erhöhen
$db->prepare('UPDATE dokumente SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);
logActivity('dokument_download', "Dok-ID: {$id}, Datei: {$dok['datei_name']}");

// Sicherer Download
$filename = $dok['datei_name'];
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
ob_clean();
flush();
readfile($file);
exit;
