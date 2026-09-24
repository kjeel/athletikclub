<?php
/**
 * Athletikclub Steiermark – Admin: TBE-Gesamtkonzept Detail
 * (Projekte erfassen, Bericht einreichen, Budgetrahmen verteilen)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/money.php';
require_once ROOT_PATH . '/includes/tbe.php';

requireAdmin();

$db         = getDB();
$konzept_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM tbe_konzepte WHERE id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([$konzept_id, currentOrgId()]);
$konzept = $stmt->fetch();

if (!$konzept) {
    flashMessage('error', 'Gesamtkonzept nicht gefunden.');
    redirect(APP_URL . '/dashboard/admin/tbe.php');
}

$self_url = APP_URL . '/dashboard/admin/tbe-konzept.php?id=' . $konzept_id;
$errors   = [];

/** Liest die Projektfelder aus dem Formular und prüft sie. */
function tbeProjektAusPost(array &$errors): array
{
    $p = [
        'einrichtung'         => trim($_POST['einrichtung'] ?? ''),
        'einrichtungstyp'     => $_POST['einrichtungstyp'] ?? 'volksschule',
        'ort'                 => trim($_POST['ort'] ?? ''),
        'ansprechperson'      => trim($_POST['ansprechperson'] ?? ''),
        'bewegungsangebot'    => trim($_POST['bewegungsangebot'] ?? ''),
        'zielgruppe'          => trim($_POST['zielgruppe'] ?? ''),
        'anzahl_gruppen'      => (int)($_POST['anzahl_gruppen'] ?? 0),
        'einheiten_pro_woche' => (float)str_replace(',', '.', $_POST['einheiten_pro_woche'] ?? '0'),
        'dauer_minuten'       => (int)($_POST['dauer_minuten'] ?? 0),
        'anzahl_wochen'       => (int)($_POST['anzahl_wochen'] ?? 0),
        'trainer'             => trim($_POST['trainer'] ?? ''),
        'beschreibung'        => trim($_POST['beschreibung'] ?? ''),
    ];
    if (!isset(TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']])) $p['einrichtungstyp'] = 'sonstige';

    if ($p['einrichtung'] === '')      $errors['einrichtung'] = 'Bitte die Schule bzw. den Kindergarten angeben.';
    if ($p['bewegungsangebot'] === '') $errors['bewegungsangebot'] = 'Bitte das Bewegungsangebot angeben.';
    if ($p['anzahl_gruppen'] < 1)      $errors['anzahl_gruppen'] = 'Mindestens 1 Gruppe.';
    if ($p['einheiten_pro_woche'] <= 0 || $p['einheiten_pro_woche'] > 99) $errors['einheiten_pro_woche'] = 'Einheiten pro Woche zwischen 0,5 und 99.';
    if ($p['dauer_minuten'] < 1)       $errors['dauer_minuten'] = 'Bitte die Dauer einer Einheit in Minuten angeben.';
    if ($p['anzahl_wochen'] < 1)       $errors['anzahl_wochen'] = 'Mindestens 1 Woche.';
    return $p;
}

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'projekt_speichern') {
        $projekt_id = (int)($_POST['projekt_id'] ?? 0);
        $p = tbeProjektAusPost($errors);

        if (empty($errors)) {
            $werte = [
                $p['einrichtung'], $p['einrichtungstyp'], $p['ort'] ?: null, $p['ansprechperson'] ?: null,
                $p['bewegungsangebot'], $p['zielgruppe'] ?: null, $p['anzahl_gruppen'], $p['einheiten_pro_woche'],
                $p['dauer_minuten'], $p['anzahl_wochen'], $p['trainer'] ?: null, $p['beschreibung'] ?: null,
            ];
            if ($projekt_id > 0) {
                $db->prepare(
                    'UPDATE tbe_projekte SET einrichtung = ?, einrichtungstyp = ?, ort = ?, ansprechperson = ?, bewegungsangebot = ?,
                        zielgruppe = ?, anzahl_gruppen = ?, einheiten_pro_woche = ?, dauer_minuten = ?, anzahl_wochen = ?, trainer = ?, beschreibung = ?
                     WHERE id = ? AND konzept_id = ?'
                )->execute(array_merge($werte, [$projekt_id, $konzept_id]));
                logActivity('tbe_projekt_bearbeitet', "Konzept-ID: {$konzept_id}, Projekt-ID: {$projekt_id}");
                flashMessage('success', 'Projekt gespeichert.');
            } else {
                $db->prepare(
                    'INSERT INTO tbe_projekte (einrichtung, einrichtungstyp, ort, ansprechperson, bewegungsangebot, zielgruppe,
                        anzahl_gruppen, einheiten_pro_woche, dauer_minuten, anzahl_wochen, trainer, beschreibung, konzept_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute(array_merge($werte, [$konzept_id]));
                logActivity('tbe_projekt_hinzugefuegt', "Konzept-ID: {$konzept_id}, {$p['einrichtung']}");
                flashMessage('success', 'Projekt hinzugefügt.');
            }
            redirect($self_url . '#projekte');
        }
    }

    if ($action === 'projekt_loeschen') {
        $projekt_id = (int)($_POST['projekt_id'] ?? 0);
        $db->prepare('DELETE FROM tbe_projekte WHERE id = ? AND konzept_id = ?')->execute([$projekt_id, $konzept_id]);
        logActivity('tbe_projekt_geloescht', "Konzept-ID: {$konzept_id}, Projekt-ID: {$projekt_id}");
        flashMessage('success', 'Projekt entfernt.');
        redirect($self_url . '#projekte');
    }

    if ($action === 'konzept_speichern') {
        $bezeichnung  = trim($_POST['bezeichnung'] ?? '');
        $zeitraum_von = trim($_POST['zeitraum_von'] ?? '');
        $zeitraum_bis = trim($_POST['zeitraum_bis'] ?? '');
        $stundensatz  = trim($_POST['stundensatz'] ?? '');

        if ($bezeichnung === '') $errors['bezeichnung'] = 'Bitte eine Bezeichnung angeben.';
        if (!strtotime($zeitraum_von) || !strtotime($zeitraum_bis) || strtotime($zeitraum_bis) < strtotime($zeitraum_von)) {
            $errors['zeitraum'] = 'Bitte einen gültigen Zeitraum angeben.';
        }

        if (empty($errors)) {
            $db->prepare(
                'UPDATE tbe_konzepte SET bezeichnung = ?, zeitraum_von = ?, zeitraum_bis = ?, stundensatz = ?, konzeptbeschreibung = ?, notizen = ?
                 WHERE id = ?'
            )->execute([
                $bezeichnung,
                date('Y-m-d', strtotime($zeitraum_von)),
                date('Y-m-d', strtotime($zeitraum_bis)),
                $stundensatz !== '' ? moneyRound($stundensatz) : null,
                trim($_POST['konzeptbeschreibung'] ?? '') ?: null,
                trim($_POST['notizen'] ?? '') ?: null,
                $konzept_id,
            ]);
            logActivity('tbe_konzept_bearbeitet', "Konzept-ID: {$konzept_id}");
            flashMessage('success', 'Konzeptdaten gespeichert.');
            redirect($self_url . '#konzept');
        }
    }

    if ($action === 'einreichung_speichern') {
        $status         = $_POST['status'] ?? 'entwurf';
        $eingereicht_am = trim($_POST['eingereicht_am'] ?? '');
        $budget_rahmen  = trim($_POST['budget_rahmen'] ?? '');
        $bewilligt_am   = trim($_POST['budget_bewilligt_am'] ?? '');
        if (!isset(TBE_STATUS[$status])) $status = 'entwurf';

        // Datum der Einreichung automatisch setzen, wenn der Status umgestellt wird
        if ($status !== 'entwurf' && $eingereicht_am === '') $eingereicht_am = date('Y-m-d');

        $db->prepare(
            'UPDATE tbe_konzepte SET status = ?, eingereicht_am = ?, budget_rahmen = ?, budget_bewilligt_am = ? WHERE id = ?'
        )->execute([
            $status,
            strtotime($eingereicht_am) ? date('Y-m-d', strtotime($eingereicht_am)) : null,
            $budget_rahmen !== '' ? moneyRound(max(0, (float)$budget_rahmen)) : null,
            strtotime($bewilligt_am) ? date('Y-m-d', strtotime($bewilligt_am)) : null,
            $konzept_id,
        ]);
        logActivity('tbe_konzept_status', "Konzept-ID: {$konzept_id}, Status: {$status}");
        flashMessage('success', 'Einreichung und Budgetrahmen gespeichert.');
        redirect($self_url . '#budget');
    }

    if ($action === 'budget_verteilen') {
        $zuteilung = $_POST['budget_zugeteilt'] ?? [];
        $upd = $db->prepare('UPDATE tbe_projekte SET budget_zugeteilt = ? WHERE id = ? AND konzept_id = ?');
        foreach ((array)$zuteilung as $projekt_id => $betrag) {
            $betrag = trim((string)$betrag);
            $upd->execute([$betrag !== '' ? moneyRound(max(0, (float)$betrag)) : null, (int)$projekt_id, $konzept_id]);
        }
        logActivity('tbe_budget_verteilt', "Konzept-ID: {$konzept_id}");
        flashMessage('success', 'Budgetverteilung gespeichert.');
        redirect($self_url . '#budget');
    }

    if ($action === 'konzept_loeschen') {
        $db->prepare('DELETE FROM tbe_konzepte WHERE id = ? AND organization_id = ?')->execute([$konzept_id, currentOrgId()]);
        logActivity('tbe_konzept_geloescht', "Konzept-ID: {$konzept_id}, {$konzept['bezeichnung']}");
        flashMessage('success', 'Gesamtkonzept gelöscht.');
        redirect(APP_URL . '/dashboard/admin/tbe.php');
    }
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM tbe_projekte WHERE konzept_id = ? ORDER BY sortierung ASC, einrichtung ASC, id ASC');
$stmt->execute([$konzept_id]);
$projekte = $stmt->fetchAll();

// Gespeicherter Stundensatz (nicht der evtl. fehlerhafte Formularwert)
$stundensatz = $konzept['stundensatz'];
$mit_kosten  = $stundensatz !== null;

$summe_einheiten = 0.0;
$summe_stunden   = 0.0;
$summe_kosten    = [];
$summe_budget    = [];
foreach ($projekte as $p) {
    $summe_einheiten += tbeEinheiten($p);
    $summe_stunden   += tbeStunden($p);
    $kosten = tbeKosten($p, $stundensatz);
    if ($kosten !== null) $summe_kosten[] = $kosten;
    if ($p['budget_zugeteilt'] !== null) $summe_budget[] = $p['budget_zugeteilt'];
}
$anzahl_einrichtungen = count(array_unique(array_map(fn($p) => mb_strtolower($p['einrichtung']), $projekte)));
$kosten_gesamt  = $mit_kosten ? moneySum($summe_kosten) : null;
$budget_verteilt = moneySum($summe_budget);
$budget_frei     = $konzept['budget_rahmen'] !== null ? bcsub($konzept['budget_rahmen'], $budget_verteilt, 2) : null;

// Projekt zum Bearbeiten (oder leeres Formular / Formular mit Fehlern)
$bearbeiten_id = (int)($_POST['projekt_id'] ?? $_GET['projekt'] ?? 0);
$projekt_form  = [
    'einrichtung' => '', 'einrichtungstyp' => 'volksschule', 'ort' => '', 'ansprechperson' => '', 'bewegungsangebot' => '',
    'zielgruppe' => '', 'anzahl_gruppen' => 1, 'einheiten_pro_woche' => 1, 'dauer_minuten' => 50, 'anzahl_wochen' => 30,
    'trainer' => '', 'beschreibung' => '',
];
if ($bearbeiten_id > 0) {
    foreach ($projekte as $p) {
        if ((int)$p['id'] === $bearbeiten_id) $projekt_form = array_merge($projekt_form, array_map(fn($v) => $v ?? '', $p));
    }
}
if (($_POST['action'] ?? '') === 'projekt_speichern') $projekt_form = array_merge($projekt_form, $_POST);
if (($_POST['action'] ?? '') === 'konzept_speichern') $konzept = array_merge($konzept, $_POST);

$s = TBE_STATUS[$konzept['status']] ?? ['label' => $konzept['status'], 'class' => 'badge-gray'];

$page_title = 'TBE-Gesamtkonzept ' . $konzept['bezeichnung'];
$breadcrumb = 'TBE-Gesamtkonzept';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/admin/tbe.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Alle Gesamtkonzepte
        </a>
        <h1 class="dashboard-title">TBE-Gesamtkonzept <?= e($konzept['bezeichnung']) ?> <span class="badge <?= $s['class'] ?>" style="vertical-align: middle;"><?= e($s['label']) ?></span></h1>
        <p class="dashboard-subtitle"><?= date('d.m.Y', strtotime($konzept['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($konzept['zeitraum_bis'])) ?></p>
    </div>
    <a href="<?= APP_URL ?>/dashboard/admin/tbe-pdf.php?id=<?= $konzept_id ?>" target="_blank" class="btn btn-primary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Bericht als PDF
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg></div>
        <div class="kpi-value"><?= count($projekte) ?></div>
        <div class="kpi-label">Projekte an <?= $anzahl_einrichtungen ?> <?= $anzahl_einrichtungen === 1 ? 'Einrichtung' : 'Einrichtungen' ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="kpi-value"><?= tbeZahl($summe_stunden) ?></div>
        <div class="kpi-label">Stunden (<?= tbeZahl($summe_einheiten) ?> Einheiten)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #F59E0B;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="kpi-value"><?= $kosten_gesamt !== null ? moneyFormat($kosten_gesamt) : '–' ?></div>
        <div class="kpi-label"><?= $kosten_gesamt !== null ? 'Geschätzte Kosten' : 'Kosten: Stundensatz fehlt' ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color: #22C55E;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg></div>
        <div class="kpi-value"><?= $konzept['budget_rahmen'] !== null ? moneyFormat($konzept['budget_rahmen']) : '–' ?></div>
        <div class="kpi-label"><?= $budget_frei !== null ? 'Budgetrahmen, davon ' . moneyFormat($budget_frei) . ' frei' : 'Budgetrahmen noch offen' ?></div>
    </div>
</div>

<!-- Projekte -->
<div class="table-card" id="projekte" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Projekte (<?= count($projekte) ?>)</h2>
    </div>

    <?php if (empty($projekte)): ?>
        <div class="empty-state">
            <h3>Noch keine Projekte erfasst</h3>
            <p>Trage unten für jede Schule bzw. jeden Kindergarten das geplante Bewegungsangebot ein.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Einrichtung</th>
                        <th>Angebot</th>
                        <th>Gruppen</th>
                        <th>Einh./Woche</th>
                        <th>Dauer</th>
                        <th>Wochen</th>
                        <th>Stunden</th>
                        <?php if ($mit_kosten): ?><th>Kosten</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projekte as $p): $kosten = tbeKosten($p, $stundensatz); ?>
                    <tr>
                        <td>
                            <div class="text-primary"><?= e($p['einrichtung']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e(TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']] ?? '') ?><?= $p['ort'] ? ' · ' . e($p['ort']) : '' ?></div>
                        </td>
                        <td>
                            <div><?= e($p['bewegungsangebot']) ?></div>
                            <?php if ($p['zielgruppe']): ?><div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($p['zielgruppe']) ?></div><?php endif; ?>
                        </td>
                        <td><?= (int)$p['anzahl_gruppen'] ?></td>
                        <td><?= tbeZahl((float)$p['einheiten_pro_woche']) ?></td>
                        <td><?= (int)$p['dauer_minuten'] ?> Min.</td>
                        <td><?= (int)$p['anzahl_wochen'] ?></td>
                        <td style="font-weight: 700;"><?= tbeZahl(tbeStunden($p)) ?></td>
                        <?php if ($mit_kosten): ?><td><?= moneyFormat($kosten) ?></td><?php endif; ?>
                        <td style="white-space: nowrap;">
                            <a href="<?= $self_url ?>&amp;projekt=<?= $p['id'] ?>#projekt-formular" class="btn btn-ghost-light btn-sm">Bearbeiten</a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Projekt wirklich entfernen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="projekt_loeschen">
                                <input type="hidden" name="projekt_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: 700;">
                        <td colspan="6">Gesamt</td>
                        <td><?= tbeZahl($summe_stunden) ?></td>
                        <?php if ($mit_kosten): ?><td><?= moneyFormat($kosten_gesamt) ?></td><?php endif; ?>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div id="projekt-formular" style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">
            <?= $bearbeiten_id > 0 ? 'Projekt bearbeiten' : 'Projekt hinzufügen' ?>
        </h3>
        <form method="POST" action="<?= $self_url ?>#projekt-formular">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="projekt_speichern">
            <input type="hidden" name="projekt_id" value="<?= $bearbeiten_id ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Schule / Kindergarten <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['einrichtung']) ? 'error' : '' ?>" type="text" name="einrichtung" maxlength="150" value="<?= e((string)$projekt_form['einrichtung']) ?>" placeholder="z.B. VS St. Georgen an der Stiefing">
                </div>
                <div class="form-group">
                    <label class="form-label">Art der Einrichtung</label>
                    <select class="form-control" name="einrichtungstyp">
                        <?php foreach (TBE_EINRICHTUNGSTYPEN as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $projekt_form['einrichtungstyp'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ort</label>
                    <input class="form-control" type="text" name="ort" maxlength="150" value="<?= e((string)$projekt_form['ort']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Ansprechperson der Einrichtung</label>
                    <input class="form-control" type="text" name="ansprechperson" maxlength="150" value="<?= e((string)$projekt_form['ansprechperson']) ?>" placeholder="z.B. Direktion, Leitung">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Bewegungsangebot <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['bewegungsangebot']) ? 'error' : '' ?>" type="text" name="bewegungsangebot" maxlength="150" value="<?= e((string)$projekt_form['bewegungsangebot']) ?>" placeholder="z.B. Koordination & Calisthenics">
                </div>
                <div class="form-group">
                    <label class="form-label">Zielgruppe</label>
                    <input class="form-control" type="text" name="zielgruppe" maxlength="150" value="<?= e((string)$projekt_form['zielgruppe']) ?>" placeholder="z.B. 1.–4. Klasse, 4–6 Jahre">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Anzahl Gruppen <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['anzahl_gruppen']) ? 'error' : '' ?>" type="number" min="1" name="anzahl_gruppen" value="<?= e((string)$projekt_form['anzahl_gruppen']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Einheiten pro Woche und Gruppe <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['einheiten_pro_woche']) ? 'error' : '' ?>" type="number" min="0.5" step="0.5" name="einheiten_pro_woche" value="<?= e((string)(float)$projekt_form['einheiten_pro_woche']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Dauer einer Einheit (Minuten) <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['dauer_minuten']) ? 'error' : '' ?>" type="number" min="1" name="dauer_minuten" value="<?= e((string)$projekt_form['dauer_minuten']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Anzahl Wochen <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['anzahl_wochen']) ? 'error' : '' ?>" type="number" min="1" name="anzahl_wochen" value="<?= e((string)$projekt_form['anzahl_wochen']) ?>">
                </div>
            </div>
            <p style="font-weight: 700; margin-bottom: 1rem;">Ergibt: <span id="tbe_calc_anzeige">–</span></p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Trainer:in</label>
                    <input class="form-control" type="text" name="trainer" maxlength="150" value="<?= e((string)$projekt_form['trainer']) ?>">
                </div>
                <div class="form-group"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Kurzbeschreibung (erscheint im Bericht)</label>
                <textarea class="form-control" name="beschreibung" rows="3" placeholder="Inhalte und Ziele des Angebots"><?= e((string)$projekt_form['beschreibung']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-navy btn-sm"><?= $bearbeiten_id > 0 ? 'Änderungen speichern' : 'Projekt hinzufügen' ?></button>
            <?php if ($bearbeiten_id > 0): ?>
                <a href="<?= $self_url ?>#projekte" class="btn btn-ghost-light btn-sm">Abbrechen</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Einreichung & Budget -->
<div class="table-card" id="budget" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Einreichung &amp; Budgetrahmen</h2>
    </div>
    <div style="padding: 1.25rem;">
        <p class="form-hint" style="margin-bottom: 1rem;">Ablauf: Projekte erfassen → Bericht als PDF erzeugen und einreichen → Status auf „Eingereicht“ setzen → bewilligten Budgetrahmen eintragen und auf die Projekte verteilen.</p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="einreichung_speichern">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status">
                        <?php foreach (TBE_STATUS as $val => $st): ?>
                            <option value="<?= $val ?>" <?= $konzept['status'] === $val ? 'selected' : '' ?>><?= $st['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Eingereicht am</label>
                    <input class="form-control" type="date" name="eingereicht_am" value="<?= e((string)($konzept['eingereicht_am'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Bewilligter Budgetrahmen (€)</label>
                    <input class="form-control" type="number" min="0" step="0.01" name="budget_rahmen" value="<?= e((string)($konzept['budget_rahmen'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Bewilligt am</label>
                    <input class="form-control" type="date" name="budget_bewilligt_am" value="<?= e((string)($konzept['budget_bewilligt_am'] ?? '')) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        </form>
    </div>

    <?php if ($konzept['budget_rahmen'] !== null && !empty($projekte)): ?>
    <div style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Budget auf die Projekte verteilen</h3>
        <form method="POST" id="budget-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="budget_verteilen">
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Projekt</th><th>Stunden</th><th>Anteil (€)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projekte as $p): ?>
                        <tr>
                            <td><?= e($p['einrichtung']) ?> · <?= e($p['bewegungsangebot']) ?></td>
                            <td><?= tbeZahl(tbeStunden($p)) ?></td>
                            <td style="min-width: 140px;">
                                <input class="form-control budget-anteil" type="number" min="0" step="0.01" data-stunden="<?= tbeStunden($p) ?>"
                                       name="budget_zugeteilt[<?= $p['id'] ?>]" value="<?= e((string)($p['budget_zugeteilt'] ?? '')) ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-weight: 700; margin: 1rem 0;">
                Verteilt: <span id="budget_verteilt">–</span> von <?= moneyFormat($konzept['budget_rahmen']) ?>
                · <span id="budget_rest">–</span>
            </p>
            <button type="button" class="btn btn-ghost-light btn-sm" id="budget_nach_stunden">Nach Stunden aufteilen</button>
            <button type="submit" class="btn btn-navy btn-sm">Verteilung speichern</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Konzeptdaten -->
<div class="table-card" id="konzept" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Konzeptdaten</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= $self_url ?>#konzept">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="konzept_speichern">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Förderjahr <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['bezeichnung']) ? 'error' : '' ?>" type="text" name="bezeichnung" maxlength="20" value="<?= e((string)$konzept['bezeichnung']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Stundensatz Trainer:in (€)</label>
                    <input class="form-control" type="number" min="0" step="0.01" name="stundensatz" value="<?= e((string)($konzept['stundensatz'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Zeitraum von</label>
                    <input class="form-control <?= isset($errors['zeitraum']) ? 'error' : '' ?>" type="date" name="zeitraum_von" value="<?= e((string)$konzept['zeitraum_von']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">bis</label>
                    <input class="form-control <?= isset($errors['zeitraum']) ? 'error' : '' ?>" type="date" name="zeitraum_bis" value="<?= e((string)$konzept['zeitraum_bis']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Konzeptbeschreibung (Einleitung im Bericht)</label>
                <textarea class="form-control" name="konzeptbeschreibung" rows="5" placeholder="Ziele, pädagogischer Ansatz, wie die Projekte die Tägliche Bewegungseinheit umsetzen …"><?= e((string)($konzept['konzeptbeschreibung'] ?? '')) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Interne Notizen (nicht im Bericht)</label>
                <textarea class="form-control" name="notizen" rows="3"><?= e((string)($konzept['notizen'] ?? '')) ?></textarea>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Konzeptdaten speichern</button>
        </form>
    </div>
</div>

<div class="table-card">
    <div style="padding: 1.25rem;">
        <form method="POST" onsubmit="return confirm('Gesamtkonzept mit allen Projekten wirklich unwiderruflich löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="konzept_loeschen">
            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Gesamtkonzept löschen</button>
        </form>
    </div>
</div>

<script>
(() => {
const eur = (v) => v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
const zahl = (v) => v.toLocaleString('de-DE', { maximumFractionDigits: 2 });

// Live-Berechnung Einheiten/Stunden im Projektformular
function berechneProjekt() {
    const f = document.querySelector('#projekt-formular form');
    const gruppen = parseFloat(f.anzahl_gruppen.value) || 0;
    const proWoche = parseFloat(f.einheiten_pro_woche.value) || 0;
    const dauer = parseFloat(f.dauer_minuten.value) || 0;
    const wochen = parseFloat(f.anzahl_wochen.value) || 0;
    const einheiten = gruppen * proWoche * wochen;
    document.getElementById('tbe_calc_anzeige').textContent = zahl(einheiten) + ' Einheiten = ' + zahl(einheiten * dauer / 60) + ' Stunden';
}
document.querySelectorAll('.tbe-calc').forEach(el => el.addEventListener('input', berechneProjekt));
berechneProjekt();

// Budgetverteilung: Summe live anzeigen, optional proportional nach Stunden aufteilen
const budgetRahmen = <?= json_encode($konzept['budget_rahmen'] !== null ? (float)$konzept['budget_rahmen'] : null) ?>;
const anteile = document.querySelectorAll('.budget-anteil');
function zeigeBudget() {
    let summe = 0;
    anteile.forEach(el => summe += parseFloat(el.value) || 0);
    const rest = budgetRahmen - summe;
    document.getElementById('budget_verteilt').textContent = eur(summe);
    const restEl = document.getElementById('budget_rest');
    restEl.textContent = rest >= -0.005 ? eur(rest) + ' frei' : eur(-rest) + ' über dem Rahmen!';
    restEl.style.color = rest >= -0.005 ? '' : 'var(--danger)';
}
if (anteile.length && budgetRahmen !== null) {
    anteile.forEach(el => el.addEventListener('input', zeigeBudget));
    document.getElementById('budget_nach_stunden').addEventListener('click', () => {
        const stundenGesamt = [...anteile].reduce((s, el) => s + parseFloat(el.dataset.stunden), 0);
        if (stundenGesamt <= 0) return;
        let verteilt = 0;
        anteile.forEach((el, i) => {
            // Rundungsdifferenz landet beim letzten Projekt, damit die Summe exakt stimmt
            const betrag = i === anteile.length - 1
                ? Math.round((budgetRahmen - verteilt) * 100) / 100
                : Math.round(budgetRahmen * parseFloat(el.dataset.stunden) / stundenGesamt * 100) / 100;
            el.value = betrag.toFixed(2);
            verteilt += betrag;
        });
        zeigeBudget();
    });
    zeigeBudget();
}
})();
</script>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
