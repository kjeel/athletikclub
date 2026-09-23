<?php
/**
 * Athletikclub Steiermark – Abrechnungsdetail (druckbar)
 * Trainer sehen nur eigene Abrechnungen, Admin sieht alle.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireTrainer();

$db            = getDB();
$user          = getCurrentUser();
$abrechnung_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    "SELECT a.*, u.vorname, u.nachname, u.email
     FROM abrechnungen a JOIN users u ON a.trainer_id = u.id
     WHERE a.id = ? AND a.organization_id = ? LIMIT 1"
);
$stmt->execute([$abrechnung_id, currentOrgId()]);
$abrechnung = $stmt->fetch();

if (!$abrechnung || (!isAdmin() && (int)$abrechnung['trainer_id'] !== (int)$user['id'])) {
    flashMessage('error', 'Abrechnung nicht gefunden.');
    redirect(APP_URL . '/dashboard/umsatz.php');
}

$stmt = $db->prepare('SELECT * FROM abrechnung_positionen WHERE abrechnung_id = ? ORDER BY datum ASC');
$stmt->execute([$abrechnung_id]);
$positionen = $stmt->fetchAll();

$page_title = 'Abrechnung #' . $abrechnung['id'];
$breadcrumb = 'Abrechnung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header no-print" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/umsatz.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Zurück
        </a>
        <h1 class="dashboard-title">Abrechnung #<?= $abrechnung['id'] ?></h1>
    </div>
    <button type="button" class="btn btn-navy btn-sm" onclick="window.print()">Drucken / Als PDF speichern</button>
</div>

<div class="table-card" id="beleg" style="max-width: 720px; margin: 0 auto;">
    <div style="padding: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <div style="font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.1rem; color: var(--text-primary);">ATHLETIKCLUB STEIERMARK</div>
                <div style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.6;">
                    St. Georgen an der Stiefing 14<br>
                    8413 Sankt Georgen an der Stiefing<br>
                    office@athletikclub-steiermark.at
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.25rem; text-transform: uppercase;">Abrechnung</div>
                <div style="font-size: 0.85rem; color: var(--text-muted);">Nr. <?= $abrechnung['id'] ?></div>
                <div style="font-size: 0.85rem; color: var(--text-muted);">Erstellt am <?= date('d.m.Y', strtotime($abrechnung['created_at'])) ?></div>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <div style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); margin-bottom: 0.25rem;">Trainer*in</div>
            <div style="font-weight: 700;"><?= e($abrechnung['vorname'] . ' ' . $abrechnung['nachname']) ?></div>
            <div style="font-size: 0.85rem; color: var(--text-muted);"><?= e($abrechnung['email']) ?></div>
        </div>

        <div style="margin-bottom: 1.5rem; font-size: 0.85rem; color: var(--text-muted);">
            Abrechnungszeitraum: <?= date('d.m.Y', strtotime($abrechnung['zeitraum_von'])) ?> bis <?= date('d.m.Y', strtotime($abrechnung['zeitraum_bis'])) ?>
        </div>

        <table class="data-table" style="margin-bottom: 1.5rem;">
            <thead>
                <tr><th>Datum</th><th>Leistung</th><th style="text-align: right;">Betrag</th></tr>
            </thead>
            <tbody>
                <?php foreach ($positionen as $p): ?>
                <tr>
                    <td><?= date('d.m.Y', strtotime($p['datum'])) ?></td>
                    <td><?= e($p['beschreibung']) ?></td>
                    <td style="text-align: right;"><?= number_format((float)$p['betrag'], 2, ',', '.') ?> €</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-left: auto; max-width: 320px; display: flex; flex-direction: column; gap: 0.5rem;">
            <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                <span>Umsatz gesamt</span>
                <span><?= number_format((float)$abrechnung['umsatz_gesamt'], 2, ',', '.') ?> €</span>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; color: var(--text-muted);">
                <span>Provisionssatz</span>
                <span><?= number_format((float)$abrechnung['provisionssatz'], 0) ?>%</span>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 1.1rem; font-weight: 800; padding-top: 0.5rem; border-top: 2px solid var(--navy-primary);">
                <span>Auszahlungsbetrag</span>
                <span><?= number_format((float)$abrechnung['provisionsbetrag'], 2, ',', '.') ?> €</span>
            </div>
        </div>

        <div style="margin-top: 2rem; display: flex; align-items: center; gap: 0.75rem;">
            <span class="badge <?= $abrechnung['status'] === 'ausgezahlt' ? 'badge-success' : 'badge-info' ?>">
                <?= $abrechnung['status'] === 'ausgezahlt' ? 'Ausgezahlt am ' . date('d.m.Y', strtotime($abrechnung['ausgezahlt_am'])) : 'Noch nicht ausgezahlt' ?>
            </span>
        </div>

        <p style="margin-top: 2rem; font-size: 0.7rem; color: var(--text-muted); line-height: 1.6;">
            Diese Abrechnung ist ein interner Beleg über die vereinsinterne Provisionsauszahlung
            und stellt keine umsatzsteuerrechtliche Rechnung dar.
        </p>
    </div>
</div>

<style>
@media print {
    .no-print, .site-header, .sidebar, .dashboard-header, .site-footer { display: none !important; }
    .dashboard-layout { display: block !important; }
    #beleg { box-shadow: none !important; border: none !important; }
    body { background: white !important; }
}
</style>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
