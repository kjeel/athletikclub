<?php
/**
 * Athletikclub Steiermark – Admin: Umsatzdetail eines Trainers
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db          = getDB();
$trainer_id  = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT id, vorname, nachname FROM users WHERE id = ? AND rolle IN ('trainer','admin') AND organization_id = ? LIMIT 1");
$stmt->execute([$trainer_id, currentOrgId()]);
$trainer = $stmt->fetch();

if (!$trainer) {
    flashMessage('error', 'Trainer nicht gefunden.');
    redirect(APP_URL . '/dashboard/admin/umsatz.php');
}

$stmt = $db->prepare(
    "SELECT k.id, k.titel, k.start_datum, k.preis,
            COUNT(ka.id) AS bezahlte_teilnehmer,
            (COUNT(ka.id) * k.preis) AS summe
     FROM kurse k
     JOIN kurs_anmeldungen ka ON ka.kurs_id = k.id AND ka.bezahlt = 1
     WHERE k.trainer_id = ? AND k.organization_id = ?
     GROUP BY k.id
     ORDER BY k.start_datum DESC"
);
$stmt->execute([$trainer_id, currentOrgId()]);
$kurs_umsatz = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM umsatz_eintraege WHERE trainer_id = ? AND organization_id = ? ORDER BY leistungsdatum DESC');
$stmt->execute([$trainer_id, currentOrgId()]);
$manuelle_eintraege = $stmt->fetchAll();

$kursumsatz_summe = array_sum(array_column($kurs_umsatz, 'summe'));
$manuell_summe    = array_sum(array_column($manuelle_eintraege, 'betrag'));
$gesamt_summe     = $kursumsatz_summe + $manuell_summe;

$page_title = 'Umsatz: ' . $trainer['vorname'] . ' ' . $trainer['nachname'];
$breadcrumb = 'Umsatzübersicht';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/admin/umsatz.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Zurück zur Übersicht
    </a>
    <h1 class="dashboard-title"><?= e($trainer['vorname'] . ' ' . $trainer['nachname']) ?></h1>
    <p class="dashboard-subtitle">Umsatzdetail</p>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-value"><?= number_format($gesamt_summe, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Gesamtumsatz</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-value"><?= number_format($kursumsatz_summe, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Aus Kursen</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #4EBA6F;">
        <div class="kpi-value"><?= number_format($manuell_summe, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Manuelle Einträge</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; margin-top: 0.5rem;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Kursumsatz</h2></div>
        <?php if (empty($kurs_umsatz)): ?>
            <div class="empty-state"><h3>Kein Kursumsatz</h3></div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead><tr><th>Kurs</th><th>Bezahlt</th><th>Betrag</th></tr></thead>
                    <tbody>
                        <?php foreach ($kurs_umsatz as $k): ?>
                        <tr>
                            <td><?= e($k['titel']) ?><br><span style="font-size: 0.75rem; color: var(--text-muted);"><?= date('d.m.Y', strtotime($k['start_datum'])) ?></span></td>
                            <td><?= (int)$k['bezahlte_teilnehmer'] ?></td>
                            <td><?= number_format((float)$k['summe'], 2, ',', '.') ?> €</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Manuelle Einträge</h2></div>
        <?php if (empty($manuelle_eintraege)): ?>
            <div class="empty-state"><h3>Keine manuellen Einträge</h3></div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead><tr><th>Beschreibung</th><th>Datum</th><th>Betrag</th></tr></thead>
                    <tbody>
                        <?php foreach ($manuelle_eintraege as $e): ?>
                        <tr>
                            <td><?= e($e['beschreibung']) ?></td>
                            <td><?= date('d.m.Y', strtotime($e['leistungsdatum'])) ?></td>
                            <td><?= number_format((float)$e['betrag'], 2, ',', '.') ?> €</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
