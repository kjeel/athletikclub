<?php
/**
 * Athletikclub Steiermark – Admin: Provisionsabrechnungen
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db   = getDB();
$user = getCurrentUser();

// ----------------------------------------------------------------
// Provisionssatz eines Trainers ändern
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'satz_aendern') {
    requireCsrf();
    $trainer_id = (int)($_POST['trainer_id'] ?? 0);
    $satz       = $_POST['provisionssatz'] ?? '';

    if ($trainer_id && is_numeric($satz) && (float)$satz >= 0 && (float)$satz <= 100) {
        $db->prepare(
            'INSERT INTO trainer_profile (organization_id, user_id, provisionssatz) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE provisionssatz = VALUES(provisionssatz)'
        )->execute([currentOrgId(), $trainer_id, moneyRound($satz)]);
        logActivity('provisionssatz_geaendert', "Trainer-ID: {$trainer_id} -> {$satz}%");
        flashMessage('success', 'Provisionssatz aktualisiert.');
    }
    redirect(APP_URL . '/dashboard/admin/abrechnungen.php');
}

// ----------------------------------------------------------------
// Abrechnung erstellen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'abrechnung_erstellen') {
    requireCsrf();
    $trainer_id = (int)($_POST['trainer_id'] ?? 0);

    $org_id = currentOrgId();

    $stmt = $db->prepare("SELECT id, vorname, nachname FROM users WHERE id = ? AND rolle IN ('trainer','admin') AND organization_id = ? LIMIT 1");
    $stmt->execute([$trainer_id, $org_id]);
    $trainer = $stmt->fetch();

    if (!$trainer) {
        flashMessage('error', 'Trainer nicht gefunden.');
        redirect(APP_URL . '/dashboard/admin/abrechnungen.php');
    }

    $stmt = $db->prepare(
        "SELECT k.id, k.titel, k.start_datum, k.preis,
                COUNT(ka.id) AS bezahlte_teilnehmer,
                (COUNT(ka.id) * k.preis) AS summe
         FROM kurse k
         JOIN kurs_anmeldungen ka ON ka.kurs_id = k.id AND ka.bezahlt = 1 AND ka.abgerechnet_id IS NULL
         WHERE k.trainer_id = ? AND k.organization_id = ?
         GROUP BY k.id"
    );
    $stmt->execute([$trainer_id, $org_id]);
    $kurs_positionen = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT * FROM umsatz_eintraege WHERE trainer_id = ? AND organization_id = ? AND abgerechnet_id IS NULL');
    $stmt->execute([$trainer_id, $org_id]);
    $manuelle_positionen = $stmt->fetchAll();

    if (empty($kurs_positionen) && empty($manuelle_positionen)) {
        flashMessage('error', 'Kein offener Umsatz für diesen Trainer.');
        redirect(APP_URL . '/dashboard/admin/abrechnungen.php');
    }

    $stmt = $db->prepare('SELECT provisionssatz FROM trainer_profile WHERE user_id = ?');
    $stmt->execute([$trainer_id]);
    $provisionssatz = (string)($stmt->fetchColumn() ?: '80.00');

    $alle_daten = array_merge(
        array_map(fn($k) => $k['start_datum'], $kurs_positionen),
        array_map(fn($m) => $m['leistungsdatum'], $manuelle_positionen)
    );
    $zeitraum_von = date('Y-m-d', min(array_map('strtotime', $alle_daten)));
    $zeitraum_bis = date('Y-m-d');

    $umsatz_gesamt    = bcadd(moneySum(array_column($kurs_positionen, 'summe')), moneySum(array_column($manuelle_positionen, 'betrag')), 2);
    $provisionsbetrag = moneyPercent($umsatz_gesamt, $provisionssatz);

    try {
        $db->beginTransaction();

        $db->prepare(
            'INSERT INTO abrechnungen (organization_id, trainer_id, zeitraum_von, zeitraum_bis, umsatz_gesamt, provisionssatz, provisionsbetrag, erstellt_von)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$org_id, $trainer_id, $zeitraum_von, $zeitraum_bis, $umsatz_gesamt, $provisionssatz, $provisionsbetrag, $user['id']]);
        $abrechnung_id = (int)$db->lastInsertId();

        $pos_stmt = $db->prepare(
            'INSERT INTO abrechnung_positionen (organization_id, abrechnung_id, typ, beschreibung, datum, betrag) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($kurs_positionen as $k) {
            $beschreibung = $k['titel'] . ' (' . (int)$k['bezahlte_teilnehmer'] . 'x bezahlt à ' . number_format((float)$k['preis'], 2, ',', '.') . ' €)';
            $pos_stmt->execute([$org_id, $abrechnung_id, 'kurs', $beschreibung, date('Y-m-d', strtotime($k['start_datum'])), $k['summe']]);
        }
        foreach ($manuelle_positionen as $m) {
            $pos_stmt->execute([$org_id, $abrechnung_id, 'manuell', $m['beschreibung'], $m['leistungsdatum'], $m['betrag']]);
        }

        $db->prepare(
            "UPDATE kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
             SET ka.abgerechnet_id = ?
             WHERE k.trainer_id = ? AND k.organization_id = ? AND ka.bezahlt = 1 AND ka.abgerechnet_id IS NULL"
        )->execute([$abrechnung_id, $trainer_id, $org_id]);

        $db->prepare(
            'UPDATE umsatz_eintraege SET abgerechnet_id = ? WHERE trainer_id = ? AND organization_id = ? AND abgerechnet_id IS NULL'
        )->execute([$abrechnung_id, $trainer_id, $org_id]);

        $db->commit();

        logActivity('abrechnung_erstellt', "Trainer-ID: {$trainer_id}, Abrechnung-ID: {$abrechnung_id}, Betrag: {$provisionsbetrag}");
        flashMessage('success', 'Abrechnung für ' . $trainer['vorname'] . ' ' . $trainer['nachname'] . ' erstellt.');
    } catch (Exception $e) {
        $db->rollBack();
        flashMessage('error', 'Abrechnung konnte nicht erstellt werden.');
    }

    redirect(APP_URL . '/dashboard/admin/abrechnungen.php');
}

// ----------------------------------------------------------------
// Als ausgezahlt markieren
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'als_ausgezahlt') {
    requireCsrf();
    $abrechnung_id = (int)($_POST['abrechnung_id'] ?? 0);
    $db->prepare("UPDATE abrechnungen SET status = 'ausgezahlt', ausgezahlt_am = NOW() WHERE id = ? AND organization_id = ?")
       ->execute([$abrechnung_id, currentOrgId()]);
    logActivity('abrechnung_ausgezahlt', "Abrechnung-ID: {$abrechnung_id}");
    redirect(APP_URL . '/dashboard/admin/abrechnungen.php');
}

// ----------------------------------------------------------------
// Daten für Übersicht laden
// ----------------------------------------------------------------
$stmt = $db->prepare(
    "SELECT u.id, u.vorname, u.nachname, COALESCE(tp.provisionssatz, 80.00) AS provisionssatz,
            COALESCE((
                SELECT SUM(k.preis) FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                WHERE k.trainer_id = u.id AND ka.bezahlt = 1 AND ka.abgerechnet_id IS NULL
            ), 0) AS offener_kursumsatz,
            COALESCE((
                SELECT SUM(betrag) FROM umsatz_eintraege WHERE trainer_id = u.id AND abgerechnet_id IS NULL
            ), 0) AS offener_manueller_umsatz
     FROM users u
     LEFT JOIN trainer_profile tp ON tp.user_id = u.id
     WHERE u.rolle IN ('trainer','admin') AND u.aktiv = 1 AND u.organization_id = ?
     ORDER BY u.vorname ASC"
);
$stmt->execute([currentOrgId()]);
$trainer_liste = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT a.*, u.vorname, u.nachname
     FROM abrechnungen a JOIN users u ON a.trainer_id = u.id
     WHERE a.organization_id = ?
     ORDER BY a.created_at DESC LIMIT 50"
);
$stmt->execute([currentOrgId()]);
$abrechnungen_liste = $stmt->fetchAll();

$page_title = 'Provisionsabrechnungen';
$breadcrumb = 'Abrechnungen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Provisionsabrechnungen</h1>
    <p class="dashboard-subtitle">Trainer erhalten standardmäßig 80% des von ihnen erzielten Umsatzes</p>
</div>

<!-- Trainer-Übersicht mit offenem Umsatz -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Offener Umsatz je Trainer*in</h2>
    </div>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Trainer*in</th>
                    <th>Offener Umsatz</th>
                    <th>Provisionssatz</th>
                    <th>Provision (offen)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trainer_liste as $t):
                    $offen = bcadd((string)$t['offener_kursumsatz'], (string)$t['offener_manueller_umsatz'], 2);
                    $provision = moneyPercent($offen, (string)$t['provisionssatz']);
                ?>
                <tr>
                    <td class="text-primary"><?= e($t['vorname'] . ' ' . $t['nachname']) ?></td>
                    <td><?= number_format((float)$offen, 2, ',', '.') ?> €</td>
                    <td>
                        <form method="POST" style="display: flex; align-items: center; gap: 0.4rem;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="satz_aendern">
                            <input type="hidden" name="trainer_id" value="<?= $t['id'] ?>">
                            <input type="number" name="provisionssatz" value="<?= e((string)$t['provisionssatz']) ?>" min="0" max="100" step="1" class="form-control" style="width: 70px; padding: 0.35rem 0.5rem;">
                            <span>%</span>
                            <button type="submit" class="btn btn-ghost-light btn-sm">Speichern</button>
                        </form>
                    </td>
                    <td style="font-weight: 700;"><?= number_format($provision, 2, ',', '.') ?> €</td>
                    <td>
                        <?php if ($offen > 0): ?>
                        <form method="POST" onsubmit="return confirm('Abrechnung über <?= number_format($provision, 2, ',', '.') ?> € für <?= e($t['vorname']) ?> erstellen? Der offene Umsatz wird damit als abgerechnet markiert.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="abrechnung_erstellen">
                            <input type="hidden" name="trainer_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Abrechnung erstellen</button>
                        </form>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.8rem;">Nichts offen</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Alle Abrechnungen -->
<div class="table-card">
    <div class="table-card-header">
        <h2 class="table-card-title">Alle Abrechnungen</h2>
    </div>
    <?php if (empty($abrechnungen_liste)): ?>
        <div class="empty-state"><h3>Noch keine Abrechnung erstellt</h3></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Trainer*in</th>
                        <th>Zeitraum</th>
                        <th>Umsatz</th>
                        <th>Provision</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($abrechnungen_liste as $a): ?>
                    <tr>
                        <td class="text-primary"><?= e($a['vorname'] . ' ' . $a['nachname']) ?></td>
                        <td><?= date('d.m.Y', strtotime($a['zeitraum_von'])) ?> bis <?= date('d.m.Y', strtotime($a['zeitraum_bis'])) ?></td>
                        <td><?= number_format((float)$a['umsatz_gesamt'], 2, ',', '.') ?> €</td>
                        <td style="font-weight: 700;"><?= number_format((float)$a['provisionsbetrag'], 2, ',', '.') ?> €</td>
                        <td><span class="badge <?= $a['status'] === 'ausgezahlt' ? 'badge-success' : 'badge-info' ?>"><?= $a['status'] === 'ausgezahlt' ? 'Ausgezahlt' : 'Erstellt' ?></span></td>
                        <td style="white-space: nowrap;">
                            <a href="<?= APP_URL ?>/dashboard/abrechnung-detail.php?id=<?= $a['id'] ?>" class="btn btn-ghost-light btn-sm">Ansehen</a>
                            <?php if ($a['status'] !== 'ausgezahlt'): ?>
                            <form method="POST" style="display: inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="als_ausgezahlt">
                                <input type="hidden" name="abrechnung_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Als ausgezahlt markieren</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
