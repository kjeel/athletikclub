<?php
/**
 * Athletikclub Steiermark – Meine Daten (Selbstauskunft nach Art. 15/20 DSGVO)
 * Jede angemeldete Person kann die zu ihr gespeicherten Daten als JSON oder Excel herunterladen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einstellungen.php';
require_once ROOT_PATH . '/includes/datenschutz.php';
require_once ROOT_PATH . '/includes/export.php';

requireLogin();

$db = getDB();
$person = datenschutzPerson($db, (int)getCurrentUserId());
if (!$person) redirect(APP_URL . '/dashboard/index.php');

$format = $_GET['format'] ?? '';
if (in_array($format, ['json', 'xlsx'], true)) {
    // Einfache Drosselung: höchstens 10 Auskünfte pro Stunde und Person
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM aktivitaets_log WHERE user_id = ? AND aktion = 'datenauskunft_selbst' AND created_at >= ?");
        $stmt->execute([$person['id'], date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        if ((int)$stmt->fetchColumn() >= 10) { flashMessage('error', 'Bitte später erneut versuchen.'); redirect(APP_URL . '/dashboard/meine-daten.php'); }
    } catch (Exception $e) {}
    logActivity('datenauskunft_selbst', strtoupper($format));
    $daten = datenAuskunft($db, (int)$person['id']);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . exportDateiname('meine-daten', 'json') . '"');
        header('Cache-Control: private, no-store');
        echo datenAuskunftJson($person, $daten);
        exit;
    }
    exportAusliefern(datenAuskunftBericht($person, $daten) + ['dateiname' => 'meine-daten'], 'xlsx');
}

$uebersicht = array_map('count', datenAuskunft($db, (int)$person['id']));
$v = verein();

$page_title = 'Meine Daten';
$breadcrumb = 'Meine Daten';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<div class="dashboard-header">
    <h1 class="dashboard-title">Meine Daten</h1>
    <p class="dashboard-subtitle">Übersicht und Download aller Daten, die der Verein zu dir gespeichert hat.</p>
</div>

<div class="table-card" style="max-width: 640px;">
    <div class="table-card-header"><h2 class="table-card-title">Gespeicherte Daten</h2></div>
    <div style="padding: 1.25rem;">
        <ul style="list-style: none; margin: 0; padding: 0;">
            <?php foreach ($uebersicht as $bereich => $n): ?>
                <li style="display: flex; justify-content: space-between; padding: 0.45rem 0; border-bottom: 1px solid var(--border-light); font-size: 0.9rem;"><span><?= e($bereich) ?></span><strong><?= $n ?></strong></li>
            <?php endforeach; ?>
        </ul>
        <div style="display: flex; gap: 0.5rem; margin-top: 1.25rem; flex-wrap: wrap;">
            <a class="btn btn-primary btn-sm" href="?format=json">Als JSON herunterladen</a>
            <a class="btn btn-ghost-light btn-sm" href="?format=xlsx">Als Excel herunterladen</a>
        </div>
        <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 1rem;">
            Einwilligungen kannst du unter <a href="<?= APP_URL ?>/dashboard/kinder.php">Kinder &amp; Einwilligungen</a> ändern.
            Für Berichtigung oder Löschung wende dich an <a href="mailto:<?= e($v['email']) ?>"><?= e($v['email']) ?></a> –
            Rechnungen müssen wir gesetzlich 7 Jahre aufbewahren, alle anderen Daten werden auf Wunsch anonymisiert.
        </p>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
