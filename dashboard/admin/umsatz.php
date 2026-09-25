<?php
/**
 * Athletikclub Steiermark – Admin: Umsatzübersicht aller Trainer
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db = getDB();

// Kursumsatz pro Trainer
$stmt = $db->prepare(
    "SELECT u.id, u.vorname, u.nachname,
            -- nur bezahlte Anmeldungen zählen (LEFT JOIN liefert sonst für Kurse ohne Zahlung den Preis einmal mit)
            COALESCE(SUM(CASE WHEN ka.id IS NOT NULL THEN k.preis ELSE 0 END), 0) AS kursumsatz,
            COUNT(ka.id) AS bezahlte_anmeldungen
     FROM users u
     LEFT JOIN kurse k ON k.trainer_id = u.id AND k.organization_id = u.organization_id
     LEFT JOIN kurs_anmeldungen ka ON ka.kurs_id = k.id AND ka.bezahlt = 1
     WHERE u.rolle IN ('trainer','admin') AND u.aktiv = 1 AND u.organization_id = ?
     GROUP BY u.id
     ORDER BY kursumsatz DESC"
);
$stmt->execute([currentOrgId()]);
$kursumsatz_pro_trainer = [];
foreach ($stmt->fetchAll() as $row) {
    $kursumsatz_pro_trainer[$row['id']] = $row;
}

// Manueller Umsatz pro Trainer
$stmt = $db->prepare(
    "SELECT trainer_id, COALESCE(SUM(betrag), 0) AS manuell
     FROM umsatz_eintraege WHERE organization_id = ? GROUP BY trainer_id"
);
$stmt->execute([currentOrgId()]);
$manuell_pro_trainer = [];
foreach ($stmt->fetchAll() as $row) {
    $manuell_pro_trainer[$row['trainer_id']] = moneyRound($row['manuell']);
}

// Zusammenführen
$trainer_gesamt = [];
foreach ($kursumsatz_pro_trainer as $id => $row) {
    $kurs_summe = moneyRound($row['kursumsatz']);
    $manuell    = $manuell_pro_trainer[$id] ?? '0.00';
    $trainer_gesamt[] = [
        'id'         => $id,
        'name'       => $row['vorname'] . ' ' . $row['nachname'],
        'kursumsatz' => $kurs_summe,
        'manuell'    => $manuell,
        'gesamt'     => bcadd($kurs_summe, $manuell, 2),
        'anmeldungen'=> (int)$row['bezahlte_anmeldungen'],
    ];
}
usort($trainer_gesamt, fn($a, $b) => bccomp($b['gesamt'], $a['gesamt'], 2));

$gesamtumsatz_verein = moneySum(array_column($trainer_gesamt, 'gesamt'));

// Fördermittel (eigene Kategorie, ausbezahlte Förderungen)
$stmt = $db->prepare(
    "SELECT * FROM foerderungen
     WHERE organization_id = ? AND status IN ('ausbezahlt', 'abgeschlossen')
     ORDER BY ausbezahlt_am DESC"
);
$stmt->execute([currentOrgId()]);
$foerderungen_ausbezahlt = $stmt->fetchAll();
// Tatsächlich ausbezahlter Betrag (Altbestand ohne Auszahlungsbetrag: bewilligter Betrag)
$summe_foerdermittel = moneySum(array_map(fn($f) => $f['betrag_ausbezahlt'] ?? $f['betrag_bewilligt'] ?? '0', $foerderungen_ausbezahlt));

$page_title = 'Umsatzübersicht';
$breadcrumb = 'Umsatzübersicht';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Umsatzübersicht</h1>
    <p class="dashboard-subtitle">Gesamtumsatz über alle Trainer*innen</p>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="kpi-value"><?= number_format($gesamtumsatz_verein, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Gesamtumsatz Verein</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
        <div class="kpi-value"><?= count($trainer_gesamt) ?></div>
        <div class="kpi-label">Trainer mit Umsatz</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg></div>
        <div class="kpi-value"><?= number_format((float)$summe_foerdermittel, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Fördermittel (ausbezahlt)</div>
    </div>
</div>

<div class="table-card" style="margin-top: 0.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Umsatz nach Trainer*in</h2>
    </div>
    <?php if (empty($trainer_gesamt)): ?>
        <div class="empty-state"><h3>Noch kein Umsatz erfasst</h3></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Trainer*in</th>
                        <th>Kursumsatz</th>
                        <th>Manuelle Einträge</th>
                        <th>Gesamt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trainer_gesamt as $t): ?>
                    <tr>
                        <td class="text-primary"><?= e($t['name']) ?></td>
                        <td><?= number_format($t['kursumsatz'], 2, ',', '.') ?> €</td>
                        <td><?= number_format($t['manuell'], 2, ',', '.') ?> €</td>
                        <td style="font-weight: 700;"><?= number_format($t['gesamt'], 2, ',', '.') ?> €</td>
                        <td><a href="<?= APP_URL ?>/dashboard/admin/umsatz-trainer.php?id=<?= $t['id'] ?>" class="btn btn-ghost-light btn-sm">Details</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="table-card-title">Fördermittel</h2>
        <a href="<?= APP_URL ?>/dashboard/admin/foerderungen.php" class="btn btn-ghost-light btn-sm">Zum Fördermanagement</a>
    </div>
    <?php if (empty($foerderungen_ausbezahlt)): ?>
        <div class="empty-state"><h3>Noch keine ausbezahlten Förderungen</h3></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Förderstelle</th>
                        <th>Betrag</th>
                        <th>Ausbezahlt am</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($foerderungen_ausbezahlt as $f): ?>
                    <tr>
                        <td class="text-primary"><?= e($f['titel']) ?></td>
                        <td><?= e($f['foerderstelle']) ?></td>
                        <td style="font-weight: 700;"><?= number_format((float)($f['betrag_ausbezahlt'] ?? $f['betrag_bewilligt']), 2, ',', '.') ?> €</td>
                        <td><?= $f['ausbezahlt_am'] ? date('d.m.Y', strtotime($f['ausbezahlt_am'])) : '–' ?></td>
                        <td><a href="<?= APP_URL ?>/dashboard/admin/foerderung-detail.php?id=<?= $f['id'] ?>" class="btn btn-ghost-light btn-sm">Details</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
