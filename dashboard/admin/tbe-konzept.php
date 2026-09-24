<?php
/**
 * Athletikclub Steiermark – Admin: TBE-Gesamtkonzept Detail
 * (Projekte nach TBE Fix/Flex erfassen, Voraussetzungen prüfen,
 *  Bericht einreichen, Budgetrahmen verteilen)
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

const TBE_PROJEKT_CHECKS = [
    'chk_kooperation'   => 'Kooperationsvereinbarung unterschrieben',
    'chk_schulforum'    => 'Schulforum-Beschluss (nur Volksschule, Bewegungscoach-Stunden)',
    'chk_qualifikation' => 'Qualifikation Coach/Übungsleiter:in erfüllt',
    'chk_haftpflicht'   => 'Haftpflichtversicherung aufrecht',
];

/** Liest die Projektfelder aus dem Formular und prüft sie. */
function tbeProjektAusPost(array &$errors): array
{
    $zahl = fn(string $feld) => ($_POST[$feld] ?? '') !== '' ? max(0, (int)$_POST[$feld]) : null;
    $p = [
        'modell'              => $_POST['modell'] ?? 'fix',
        'einrichtung'         => trim($_POST['einrichtung'] ?? ''),
        'einrichtungstyp'     => $_POST['einrichtungstyp'] ?? 'volksschule',
        'ort'                 => trim($_POST['ort'] ?? ''),
        'kennzahl'            => trim($_POST['kennzahl'] ?? ''),
        'ansprechperson'      => trim($_POST['ansprechperson'] ?? ''),
        'bewegungsangebot'    => trim($_POST['bewegungsangebot'] ?? ''),
        'zielgruppe'          => trim($_POST['zielgruppe'] ?? ''),
        'klassen_gesamt'      => $zahl('klassen_gesamt'),
        'anzahl_kinder'       => $zahl('anzahl_kinder'),
        'anzahl_gruppen'      => (int)($_POST['anzahl_gruppen'] ?? 0),
        'einheiten_pro_woche' => (float)str_replace(',', '.', $_POST['einheiten_pro_woche'] ?? '0'),
        'dauer_minuten'       => (int)($_POST['dauer_minuten'] ?? 0),
        'anzahl_wochen'       => (int)($_POST['anzahl_wochen'] ?? 0),
        'flex_einheiten'      => $zahl('flex_einheiten'),
        'trainer'             => trim($_POST['trainer'] ?? ''),
        'beschreibung'        => trim($_POST['beschreibung'] ?? ''),
    ];
    foreach (array_keys(TBE_PROJEKT_CHECKS) as $chk) $p[$chk] = !empty($_POST[$chk]) ? 1 : 0;
    if (!isset(TBE_MODELLE[$p['modell']])) $p['modell'] = 'fix';
    if (!isset(TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']])) $p['einrichtungstyp'] = 'sonstige';

    if ($p['einrichtung'] === '')      $errors['einrichtung'] = 'Bitte die Schule bzw. den Kindergarten angeben.';
    if ($p['bewegungsangebot'] === '') $errors['bewegungsangebot'] = 'Bitte das Bewegungsangebot angeben.';
    if ($p['anzahl_gruppen'] < 1)      $errors['anzahl_gruppen'] = 'Mindestens 1 teilnehmende Klasse/Gruppe.';
    if ($p['dauer_minuten'] < 1)       $errors['dauer_minuten'] = 'Bitte die Dauer einer Einheit in Minuten angeben.';

    if (tbeIstFlex($p)) {
        if ((int)$p['flex_einheiten'] < 1) $errors['flex_einheiten'] = 'Bitte die geplanten Flex-Einheiten angeben (mind. ' . TBE_FLEX_PAKET . ').';
        // Für Flex spielt das Wochenraster keine Rolle
        $p['einheiten_pro_woche'] = 1;
        $p['anzahl_wochen']       = 1;
        $p['chk_schulforum']      = 0;
    } else {
        if ($p['einheiten_pro_woche'] <= 0 || $p['einheiten_pro_woche'] > 10) $errors['einheiten_pro_woche'] = 'Bewegungscoach-Stunden pro Woche und Klasse zwischen 1 und 10.';
        if ($p['anzahl_wochen'] < 1)   $errors['anzahl_wochen'] = 'Mindestens 1 Woche.';
        $p['flex_einheiten'] = null;
        if ($p['einrichtungstyp'] !== 'volksschule') $p['chk_schulforum'] = 0;
    }
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
            $spalten = ['modell', 'einrichtung', 'einrichtungstyp', 'ort', 'kennzahl', 'ansprechperson', 'bewegungsangebot', 'zielgruppe',
                        'klassen_gesamt', 'anzahl_kinder', 'anzahl_gruppen', 'einheiten_pro_woche', 'dauer_minuten', 'anzahl_wochen',
                        'flex_einheiten', 'trainer', 'beschreibung', ...array_keys(TBE_PROJEKT_CHECKS)];
            $werte = array_map(fn($s) => $p[$s] === '' ? null : $p[$s], $spalten);

            if ($projekt_id > 0) {
                $db->prepare('UPDATE tbe_projekte SET ' . implode(' = ?, ', $spalten) . ' = ? WHERE id = ? AND konzept_id = ?')
                   ->execute(array_merge($werte, [$projekt_id, $konzept_id]));
                logActivity('tbe_projekt_bearbeitet', "Konzept-ID: {$konzept_id}, Projekt-ID: {$projekt_id}");
                flashMessage('success', 'Projekt gespeichert.');
            } else {
                $db->prepare('INSERT INTO tbe_projekte (' . implode(', ', $spalten) . ', konzept_id) VALUES (' . implode(', ', array_fill(0, count($spalten) + 1, '?')) . ')')
                   ->execute(array_merge($werte, [$konzept_id]));
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
                'UPDATE tbe_konzepte SET bezeichnung = ?, zeitraum_von = ?, zeitraum_bis = ?, stundensatz = ?, chk_kinderangebot = ?, chk_fit_siegel = ?,
                    konzeptbeschreibung = ?, notizen = ?
                 WHERE id = ?'
            )->execute([
                $bezeichnung,
                date('Y-m-d', strtotime($zeitraum_von)),
                date('Y-m-d', strtotime($zeitraum_bis)),
                $stundensatz !== '' ? moneyRound($stundensatz) : null,
                !empty($_POST['chk_kinderangebot']) ? 1 : 0,
                !empty($_POST['chk_fit_siegel']) ? 1 : 0,
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
// Daten laden und auswerten
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM tbe_projekte WHERE konzept_id = ? ORDER BY sortierung ASC, einrichtung ASC, id ASC');
$stmt->execute([$konzept_id]);
$projekte = $stmt->fetchAll();

// Gespeicherter Stundensatz (nicht der evtl. fehlerhafte Formularwert)
$stundensatz = $konzept['stundensatz'];
$mit_kosten  = $stundensatz !== null;

$summe = ['stunden' => 0.0, 'fix_woche' => 0.0, 'flex_pakete' => 0, 'foerderung' => [], 'foerderung_fix' => [], 'foerderung_flex' => [], 'kosten' => [], 'budget' => []];
$pruefung = [];
foreach ($projekte as &$p) {
    $p['foerderung'] = tbeFoerderung($p);
    $p['kosten']     = tbeKosten($p, $stundensatz);
    $p['meldungen']  = tbePruefeProjekt($konzept, $p);
    $summe['stunden']     += tbeStunden($p);
    $summe['fix_woche']   += tbeFixStundenWoche($p);
    $summe['flex_pakete'] += tbeFlexPakete($p);
    $summe['foerderung'][] = $p['foerderung'];
    $summe[tbeIstFlex($p) ? 'foerderung_flex' : 'foerderung_fix'][] = $p['foerderung'];
    if ($p['kosten'] !== null) $summe['kosten'][] = $p['kosten'];
    if ($p['budget_zugeteilt'] !== null) $summe['budget'][] = $p['budget_zugeteilt'];
    foreach ($p['meldungen'] as $mld) $pruefung[] = $mld + ['bezug' => $p['einrichtung']];
}
unset($p);
if (!empty($projekte) && empty($konzept['chk_kinderangebot'])) {
    array_unshift($pruefung, ['typ' => 'fehler', 'text' => 'Teilnahmevoraussetzung: Der Verein braucht ein eigenes Kinderangebot (unten in den Konzeptdaten bestätigen).', 'bezug' => 'Verein']);
}

$foerderung_gesamt = moneySum($summe['foerderung']);
$kosten_gesamt     = $mit_kosten ? moneySum($summe['kosten']) : null;
$budget_verteilt   = moneySum($summe['budget']);
$budget_frei       = $konzept['budget_rahmen'] !== null ? bcsub($konzept['budget_rahmen'], $budget_verteilt, 2) : null;
$anzahl_einrichtungen = count(array_unique(array_map(fn($p) => mb_strtolower($p['einrichtung']), $projekte)));
$anzahl_fehler = count(array_filter($pruefung, fn($m) => $m['typ'] === 'fehler'));

// Projekt zum Bearbeiten (oder leeres Formular / Formular mit Fehlern)
$bearbeiten_id = (int)($_POST['projekt_id'] ?? $_GET['projekt'] ?? 0);
$projekt_form  = [
    'modell' => 'fix', 'einrichtung' => '', 'einrichtungstyp' => 'volksschule', 'ort' => '', 'kennzahl' => '', 'ansprechperson' => '',
    'bewegungsangebot' => '', 'zielgruppe' => '', 'klassen_gesamt' => '', 'anzahl_kinder' => '', 'anzahl_gruppen' => 1,
    'einheiten_pro_woche' => 1, 'dauer_minuten' => 50, 'anzahl_wochen' => 36, 'flex_einheiten' => 10, 'trainer' => '', 'beschreibung' => '',
    'chk_kooperation' => 0, 'chk_schulforum' => 0, 'chk_qualifikation' => 0, 'chk_haftpflicht' => 0,
];
if ($bearbeiten_id > 0) {
    foreach ($projekte as $p) {
        if ((int)$p['id'] !== $bearbeiten_id) continue;
        $projekt_form = array_merge($projekt_form, array_map(fn($v) => is_array($v) ? $v : ($v ?? ''), $p));
        // Bei Flex-Projekten sinnvolle Vorgaben für einen Wechsel auf Fix anbieten
        if (tbeIstFlex($p)) $projekt_form['anzahl_wochen'] = 36;
        if (!tbeIstFlex($p)) $projekt_form['flex_einheiten'] = 10;
    }
}
if (($_POST['action'] ?? '') === 'projekt_speichern') $projekt_form = array_merge($projekt_form, $_POST);
if (($_POST['action'] ?? '') === 'konzept_speichern') $konzept = array_merge($konzept, $_POST);

$s = TBE_STATUS[$konzept['status']] ?? ['label' => $konzept['status'], 'class' => 'badge-gray'];
$v = fn($wert) => e((string)($wert ?? ''));

$page_title = 'TBE-Gesamtkonzept ' . $konzept['bezeichnung'];
$breadcrumb = 'TBE-Gesamtkonzept';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.tbe-meldung { display: flex; gap: 0.5rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; margin-bottom: 0.4rem; font-size: 0.875rem; }
.tbe-meldung-fehler  { background: rgba(239, 68, 68, 0.12); }
.tbe-meldung-warnung { background: rgba(245, 158, 11, 0.14); }
.tbe-meldung-info    { background: rgba(59, 130, 246, 0.10); }
.tbe-hinweis { background: var(--gold-dim); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; }
.tbe-abschnitt { font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin: 1.25rem 0 0.75rem; }
</style>

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
        <div class="kpi-value"><?= tbeZahl($summe['fix_woche']) ?> · <?= $summe['flex_pakete'] ?></div>
        <div class="kpi-label">Fix-Wochenstunden · Flex-Pakete (<?= tbeZahl($summe['stunden']) ?> Std. gesamt)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #F59E0B;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="kpi-value"><?= moneyFormat($foerderung_gesamt) ?></div>
        <div class="kpi-label">Förderrahmen (Fix <?= moneyFormat(moneySum($summe['foerderung_fix'])) ?> · Flex <?= moneyFormat(moneySum($summe['foerderung_flex'])) ?>)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #22C55E;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg></div>
        <div class="kpi-value"><?= $konzept['budget_rahmen'] !== null ? moneyFormat($konzept['budget_rahmen']) : '–' ?></div>
        <div class="kpi-label"><?= $budget_frei !== null ? 'Bewilligt, davon ' . moneyFormat($budget_frei) . ' frei' : 'Bewilligter Budgetrahmen offen' ?></div>
    </div>
</div>

<!-- Voraussetzungen-Check -->
<?php if (!empty($projekte)): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">
            Voraussetzungen-Check
            <?php if (empty($pruefung)): ?><span class="badge badge-success">alles erfüllt</span>
            <?php elseif ($anzahl_fehler): ?><span class="badge badge-danger"><?= $anzahl_fehler ?> offen</span><?php endif; ?>
        </h2>
    </div>
    <div style="padding: 1.25rem;">
        <?php if (empty($pruefung)): ?>
            <p style="margin: 0;">Alle Teilnahmevoraussetzungen sind bestätigt.</p>
        <?php else: foreach ($pruefung as $mld): ?>
            <div class="tbe-meldung tbe-meldung-<?= $mld['typ'] ?>">
                <strong style="white-space: nowrap;"><?= $mld['typ'] === 'fehler' ? 'Fehlt' : ($mld['typ'] === 'warnung' ? 'Achtung' : 'Info') ?> · <?= e($mld['bezug']) ?>:</strong>
                <span><?= e($mld['text']) ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Projekte -->
<div class="table-card" id="projekte" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Projekte (<?= count($projekte) ?>)</h2>
    </div>

    <?php if (empty($projekte)): ?>
        <div class="empty-state">
            <h3>Noch keine Projekte erfasst</h3>
            <p>Trage unten für jede Schule bzw. jeden Kindergarten ein, ob Bewegungscoach-Stunden (Fix) oder flexible Einheiten (Flex) geplant sind.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Einrichtung</th>
                        <th>Modell</th>
                        <th>Angebot</th>
                        <th>Umfang</th>
                        <th>Stunden</th>
                        <th>Förderrahmen</th>
                        <?php if ($mit_kosten): ?><th>Kosten</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projekte as $p): $mod = TBE_MODELLE[$p['modell']] ?? TBE_MODELLE['fix'];
                        $fehler_p = count(array_filter($p['meldungen'], fn($m) => $m['typ'] === 'fehler')); ?>
                    <tr>
                        <td>
                            <div class="text-primary"><?= e($p['einrichtung']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e(TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']] ?? '') ?><?= $p['ort'] ? ' · ' . e($p['ort']) : '' ?></div>
                            <?php if ($fehler_p): ?><span class="badge badge-danger"><?= $fehler_p ?> offen</span><?php endif; ?>
                        </td>
                        <td><span class="badge <?= $mod['class'] ?>"><?= e($mod['kurz']) ?></span></td>
                        <td>
                            <div><?= e($p['bewegungsangebot']) ?></div>
                            <?php if ($p['zielgruppe']): ?><div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($p['zielgruppe']) ?></div><?php endif; ?>
                        </td>
                        <td style="font-size: 0.85rem;">
                            <?php if (tbeIstFlex($p)): ?>
                                <?= (int)$p['flex_einheiten'] ?> EH = <?= tbeFlexPakete($p) ?> Paket<?= tbeFlexPakete($p) === 1 ? '' : 'e' ?><br>
                                <span style="color: var(--text-muted);"><?= (int)$p['anzahl_gruppen'] ?> Kl./Gr. · <?= (int)$p['dauer_minuten'] ?> Min.</span>
                            <?php else: ?>
                                <?= (int)$p['anzahl_gruppen'] ?> × <?= tbeZahl((float)$p['einheiten_pro_woche']) ?> Std./Woche<br>
                                <span style="color: var(--text-muted);"><?= (int)$p['anzahl_wochen'] ?> Wochen · <?= (int)$p['dauer_minuten'] ?> Min.</span>
                            <?php endif; ?>
                        </td>
                        <td><?= tbeZahl(tbeStunden($p)) ?></td>
                        <td style="font-weight: 700;"><?= moneyFormat($p['foerderung']) ?></td>
                        <?php if ($mit_kosten): ?><td><?= moneyFormat($p['kosten']) ?></td><?php endif; ?>
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
                        <td colspan="4">Gesamt</td>
                        <td><?= tbeZahl($summe['stunden']) ?></td>
                        <td><?= moneyFormat($foerderung_gesamt) ?></td>
                        <?php if ($mit_kosten): ?><td><?= moneyFormat($kosten_gesamt) ?></td><?php endif; ?>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php if ($mit_kosten && bccomp($kosten_gesamt, $foerderung_gesamt, 2) > 0): ?>
            <p class="form-hint" style="padding: 0 1.25rem 1rem;">Die geschätzten Kosten liegen <?= moneyFormat(bcsub($kosten_gesamt, $foerderung_gesamt, 2)) ?> über dem Förderrahmen – die Differenz trägt der Verein.</p>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Projektformular -->
    <div id="projekt-formular" style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 class="tbe-abschnitt" style="margin-top: 0;"><?= $bearbeiten_id > 0 ? 'Projekt bearbeiten' : 'Projekt hinzufügen' ?></h3>
        <form method="POST" action="<?= $self_url ?>#projekt-formular" id="tbe-projekt-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="projekt_speichern">
            <input type="hidden" name="projekt_id" value="<?= $bearbeiten_id ?>">

            <div class="form-group">
                <label class="form-label">Modell <span class="required">*</span></label>
                <select class="form-control" name="modell" id="tbe-modell">
                    <?php foreach (TBE_MODELLE as $val => $mod): ?>
                        <option value="<?= $val ?>" <?= $projekt_form['modell'] === $val ? 'selected' : '' ?>><?= e($mod['label']) ?> (<?= e($mod['saeule']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tbe-hinweis" id="tbe-modell-text"></div>

            <h4 class="tbe-abschnitt">Einrichtung</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Schule / Kindergarten <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['einrichtung']) ? 'error' : '' ?>" type="text" name="einrichtung" maxlength="150" value="<?= $v($projekt_form['einrichtung']) ?>" placeholder="z.B. VS St. Georgen an der Stiefing">
                </div>
                <div class="form-group">
                    <label class="form-label">Art der Einrichtung</label>
                    <select class="form-control" name="einrichtungstyp">
                        <?php foreach (TBE_EINRICHTUNGSTYPEN as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $projekt_form['einrichtungstyp'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ort</label>
                    <input class="form-control" type="text" name="ort" maxlength="150" value="<?= $v($projekt_form['ort']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Kennzahl der Einrichtung</label>
                    <input class="form-control" type="text" name="kennzahl" maxlength="20" value="<?= $v($projekt_form['kennzahl']) ?>" placeholder="laut Kooperationsvereinbarung">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ansprechperson der Einrichtung</label>
                    <input class="form-control" type="text" name="ansprechperson" maxlength="150" value="<?= $v($projekt_form['ansprechperson']) ?>" placeholder="z.B. Direktion, Leitung">
                </div>
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Klassen/Gruppen gesamt</label>
                        <input class="form-control" type="number" min="0" name="klassen_gesamt" value="<?= $v($projekt_form['klassen_gesamt']) ?>">
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Kinder</label>
                        <input class="form-control" type="number" min="0" name="anzahl_kinder" value="<?= $v($projekt_form['anzahl_kinder']) ?>">
                    </div>
                </div>
            </div>

            <h4 class="tbe-abschnitt">Angebot &amp; Umfang</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Bewegungsangebot <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['bewegungsangebot']) ? 'error' : '' ?>" type="text" name="bewegungsangebot" maxlength="150" value="<?= $v($projekt_form['bewegungsangebot']) ?>" placeholder="z.B. Polysportive Bewegungsstunde, Calisthenics">
                </div>
                <div class="form-group">
                    <label class="form-label">Zielgruppe</label>
                    <input class="form-control" type="text" name="zielgruppe" maxlength="150" value="<?= $v($projekt_form['zielgruppe']) ?>" placeholder="z.B. 1.–4. Klasse, 4–6 Jahre">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Teilnehmende Klassen/Gruppen <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['anzahl_gruppen']) ? 'error' : '' ?>" type="number" min="1" name="anzahl_gruppen" value="<?= $v($projekt_form['anzahl_gruppen']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Dauer einer Einheit (Minuten) <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['dauer_minuten']) ? 'error' : '' ?>" type="number" min="1" name="dauer_minuten" value="<?= $v($projekt_form['dauer_minuten']) ?>">
                </div>
            </div>
            <div class="form-row tbe-nur-fix">
                <div class="form-group">
                    <label class="form-label">Bewegungscoach-Stunden pro Woche und Klasse <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['einheiten_pro_woche']) ? 'error' : '' ?>" type="number" min="1" max="10" step="1" name="einheiten_pro_woche" value="<?= $v((float)$projekt_form['einheiten_pro_woche']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Schulwochen (September–Juni) <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['anzahl_wochen']) ? 'error' : '' ?>" type="number" min="1" name="anzahl_wochen" value="<?= $v($projekt_form['anzahl_wochen']) ?>">
                </div>
            </div>
            <div class="form-row tbe-nur-flex">
                <div class="form-group">
                    <label class="form-label">Geplante Flex-Einheiten gesamt <span class="required">*</span></label>
                    <input class="form-control tbe-calc <?= isset($errors['flex_einheiten']) ? 'error' : '' ?>" type="number" min="0" step="1" name="flex_einheiten" value="<?= $v($projekt_form['flex_einheiten']) ?>">
                </div>
                <div class="form-group"></div>
            </div>
            <p style="font-weight: 700; margin-bottom: 1rem;">Ergibt: <span id="tbe_calc_anzeige">–</span></p>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" id="tbe-trainer-label">Bewegungscoach</label>
                    <input class="form-control" type="text" name="trainer" maxlength="150" value="<?= $v($projekt_form['trainer']) ?>">
                </div>
                <div class="form-group"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Kurzbeschreibung (erscheint im Bericht)</label>
                <textarea class="form-control" name="beschreibung" rows="3" placeholder="Inhalte und Ziele des Angebots"><?= $v($projekt_form['beschreibung']) ?></textarea>
            </div>

            <h4 class="tbe-abschnitt">Voraussetzungen</h4>
            <?php foreach (TBE_PROJEKT_CHECKS as $feld => $label): ?>
                <label class="<?= $feld === 'chk_schulforum' ? 'tbe-nur-schulforum' : '' ?>" style="display: flex; gap: 0.6rem; align-items: center; margin-bottom: 0.5rem; font-size: 0.9rem;">
                    <input type="checkbox" name="<?= $feld ?>" value="1" <?= !empty($projekt_form[$feld]) ? 'checked' : '' ?>>
                    <?= e($label) ?>
                </label>
            <?php endforeach; ?>

            <div style="margin-top: 1rem;">
                <button type="submit" class="btn btn-navy btn-sm"><?= $bearbeiten_id > 0 ? 'Änderungen speichern' : 'Projekt hinzufügen' ?></button>
                <?php if ($bearbeiten_id > 0): ?><a href="<?= $self_url ?>#projekte" class="btn btn-ghost-light btn-sm">Abbrechen</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Einreichung & Budget -->
<div class="table-card" id="budget" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Einreichung &amp; Budgetrahmen</h2>
    </div>
    <div style="padding: 1.25rem;">
        <p class="form-hint" style="margin-bottom: 1rem;">Ablauf: Projekte erfassen → Kooperationsvereinbarungen mit den Einrichtungen unterschreiben → Bericht als PDF erzeugen und einreichen → bewilligten Budgetrahmen eintragen und verteilen → Einheiten laufend in der TBE-Datenbank bestätigen.</p>
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
                    <input class="form-control" type="date" name="eingereicht_am" value="<?= $v($konzept['eingereicht_am']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Bewilligter Budgetrahmen (€)</label>
                    <input class="form-control" type="number" min="0" step="0.01" name="budget_rahmen" value="<?= $v($konzept['budget_rahmen']) ?>" placeholder="beantragt: <?= e(moneyFormat($foerderung_gesamt)) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Bewilligt am</label>
                    <input class="form-control" type="date" name="budget_bewilligt_am" value="<?= $v($konzept['budget_bewilligt_am']) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        </form>
    </div>

    <?php if ($konzept['budget_rahmen'] !== null && !empty($projekte)): ?>
    <div style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 class="tbe-abschnitt" style="margin-top: 0;">Budget auf die Projekte verteilen</h3>
        <form method="POST" id="budget-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="budget_verteilen">
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr><th>Projekt</th><th>Förderrahmen</th><th>Anteil (€)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projekte as $p): ?>
                        <tr>
                            <td><?= e($p['einrichtung']) ?> · <?= e(TBE_MODELLE[$p['modell']]['kurz'] ?? '') ?> · <?= e($p['bewegungsangebot']) ?></td>
                            <td><?= moneyFormat($p['foerderung']) ?></td>
                            <td style="min-width: 140px;">
                                <input class="form-control budget-anteil" type="number" min="0" step="0.01" data-gewicht="<?= e($p['foerderung']) ?>"
                                       name="budget_zugeteilt[<?= $p['id'] ?>]" value="<?= $v($p['budget_zugeteilt']) ?>">
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
            <button type="button" class="btn btn-ghost-light btn-sm" id="budget_anteilig">Anteilig nach Förderrahmen aufteilen</button>
            <button type="submit" class="btn btn-navy btn-sm">Verteilung speichern</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Konzeptdaten -->
<div class="table-card" id="konzept" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Konzeptdaten &amp; Voraussetzungen des Vereins</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= $self_url ?>#konzept">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="konzept_speichern">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Schuljahr <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['bezeichnung']) ? 'error' : '' ?>" type="text" name="bezeichnung" maxlength="20" value="<?= $v($konzept['bezeichnung']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Stundensatz Coach (€, nur für die Kostenschätzung)</label>
                    <input class="form-control" type="number" min="0" step="0.01" name="stundensatz" value="<?= $v($konzept['stundensatz']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Zeitraum von</label>
                    <input class="form-control <?= isset($errors['zeitraum']) ? 'error' : '' ?>" type="date" name="zeitraum_von" value="<?= $v($konzept['zeitraum_von']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">bis</label>
                    <input class="form-control <?= isset($errors['zeitraum']) ? 'error' : '' ?>" type="date" name="zeitraum_bis" value="<?= $v($konzept['zeitraum_bis']) ?>">
                </div>
            </div>
            <label style="display: flex; gap: 0.6rem; align-items: center; margin-bottom: 0.5rem; font-size: 0.9rem;">
                <input type="checkbox" name="chk_kinderangebot" value="1" <?= !empty($konzept['chk_kinderangebot']) ? 'checked' : '' ?>>
                Der Verein hat ein eigenes Kinderangebot (Teilnahmevoraussetzung)
            </label>
            <label style="display: flex; gap: 0.6rem; align-items: center; margin-bottom: 1rem; font-size: 0.9rem;">
                <input type="checkbox" name="chk_fit_siegel" value="1" <?= !empty($konzept['chk_fit_siegel']) ? 'checked' : '' ?>>
                Mind. ein Kinder-/Jugendangebot ist mit dem Fit-Sport-Austria-Qualitätssiegel zertifiziert (Pflicht für Flex)
            </label>
            <div class="form-group">
                <label class="form-label">Konzeptbeschreibung (Einleitung im Bericht)</label>
                <textarea class="form-control" name="konzeptbeschreibung" rows="5" placeholder="Ziele, pädagogischer Ansatz, wie die Projekte die Tägliche Bewegungseinheit umsetzen …"><?= $v($konzept['konzeptbeschreibung']) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Interne Notizen (nicht im Bericht)</label>
                <textarea class="form-control" name="notizen" rows="3"><?= $v($konzept['notizen']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Konzeptdaten speichern</button>
        </form>

        <h4 class="tbe-abschnitt">Unterlagen &amp; Kontakt</h4>
        <ul style="margin-left: 1.25rem; font-size: 0.9rem;">
            <?php foreach (TBE_LINKS as $label => $url): ?>
                <li><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <p class="form-hint"><?= e(TBE_KONTAKT['name']) ?> · <a href="mailto:<?= e(TBE_KONTAKT['email']) ?>"><?= e(TBE_KONTAKT['email']) ?></a> · <?= e(TBE_KONTAKT['tel']) ?></p>
        <p class="form-hint">Abrechenbar sind direkte Umsetzungskosten: Personal (Gehalt, Honorar, PRAE – immer im vollen Umfang), Material und Bekleidung, Aus- und Fortbildung, Hallenmiete/Eintritte, Mobilität, Personalverwaltung. Nachweis mit Originalbelegen und Zahlungsbestätigung.</p>
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
const MODELLE = <?= json_encode(TBE_MODELLE, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const FIX_SATZ = <?= TBE_FIX_SATZ ?>, FLEX_SATZ = <?= TBE_FLEX_SATZ ?>, PAKET = <?= TBE_FLEX_PAKET ?>;
const eur = (v) => v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
const zahl = (v) => v.toLocaleString('de-DE', { maximumFractionDigits: 2 });
const f = document.getElementById('tbe-projekt-form');
const istFlex = () => f.modell.value !== 'fix';

// Felder und Hinweise passend zum Modell einblenden
function zeigeModell() {
    document.getElementById('tbe-modell-text').textContent = MODELLE[f.modell.value].text;
    document.querySelectorAll('.tbe-nur-fix').forEach(el => el.style.display = istFlex() ? 'none' : '');
    document.querySelectorAll('.tbe-nur-flex').forEach(el => el.style.display = istFlex() ? '' : 'none');
    document.querySelectorAll('.tbe-nur-schulforum').forEach(el => el.style.display = (!istFlex() && f.einrichtungstyp.value === 'volksschule') ? 'flex' : 'none');
    document.getElementById('tbe-trainer-label').textContent = istFlex() ? 'Übungsleiter:in' : 'Bewegungscoach';
    berechneProjekt();
}

// Live-Berechnung Umfang und Förderrahmen (gleiche Regeln wie serverseitig)
function berechneProjekt() {
    const n = (name) => parseFloat(f[name].value) || 0;
    const dauer = n('dauer_minuten');
    let text;
    if (istFlex()) {
        const eh = n('flex_einheiten'), pakete = Math.floor(eh / PAKET);
        text = zahl(eh) + ' Einheiten = ' + pakete + ' Paket' + (pakete === 1 ? '' : 'e') + ' à ' + PAKET + ' → Förderrahmen ' + eur(pakete * FLEX_SATZ) + ' · ' + zahl(eh * dauer / 60) + ' Std.';
    } else {
        const woche = n('anzahl_gruppen') * n('einheiten_pro_woche'), eh = woche * n('anzahl_wochen');
        text = zahl(woche) + ' Bewegungscoach-Stunde' + (woche === 1 ? '' : 'n') + ' pro Woche → Förderrahmen ' + eur(woche * FIX_SATZ) + ' · ' + zahl(eh) + ' Einheiten = ' + zahl(eh * dauer / 60) + ' Std.';
    }
    document.getElementById('tbe_calc_anzeige').textContent = text;
}
f.modell.addEventListener('change', zeigeModell);
f.einrichtungstyp.addEventListener('change', zeigeModell);
document.querySelectorAll('.tbe-calc').forEach(el => el.addEventListener('input', berechneProjekt));
zeigeModell();

// Budgetverteilung: Summe live anzeigen, optional anteilig nach Förderrahmen aufteilen
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
    document.getElementById('budget_anteilig').addEventListener('click', () => {
        const gewichtGesamt = [...anteile].reduce((s, el) => s + parseFloat(el.dataset.gewicht), 0);
        if (gewichtGesamt <= 0) return;
        let verteilt = 0;
        anteile.forEach((el, i) => {
            // Rundungsdifferenz landet beim letzten Projekt, damit die Summe exakt stimmt
            const betrag = i === anteile.length - 1
                ? Math.round((budgetRahmen - verteilt) * 100) / 100
                : Math.round(budgetRahmen * parseFloat(el.dataset.gewicht) / gewichtGesamt * 100) / 100;
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
