<?php
/**
 * Athletikclub Steiermark – Admin: Basisförderung – Förderansuchen bearbeiten
 * (Vereinsdaten, Fördergegenstände mit Kostenaufstellung/Finanzierungsplan,
 *  Antrags-Check, Zusage und Abrechnung)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/money.php';
require_once ROOT_PATH . '/includes/basisfoerderung.php';

requireAdmin();

$db        = getDB();
$antrag_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM su_antraege WHERE id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([$antrag_id, currentOrgId()]);
$antrag = $stmt->fetch();

if (!$antrag) {
    flashMessage('error', 'Förderansuchen nicht gefunden.');
    redirect(APP_URL . '/dashboard/admin/basisfoerderung.php');
}

$self_url = APP_URL . '/dashboard/admin/basisfoerderung-antrag.php?id=' . $antrag_id;
$errors   = [];

/** Betrag aus einem Formularfeld (akzeptiert Komma), leer = null. */
function suBetragAusPost(string $feld): ?string
{
    $wert = trim(str_replace(',', '.', (string)($_POST[$feld] ?? '')));
    return $wert === '' ? null : moneyRound(max(0, (float)$wert));
}

function suDatumAusPost(string $feld): ?string
{
    $wert = trim((string)($_POST[$feld] ?? ''));
    return $wert !== '' && strtotime($wert) ? date('Y-m-d', strtotime($wert)) : null;
}

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'position_speichern') {
        $position_id = (int)($_POST['position_id'] ?? 0);
        $foerderart  = $_POST['foerderart'] ?? '';
        $titel       = trim($_POST['titel'] ?? '');
        $stufe       = $_POST['ausbildungsstufe'] ?? '';

        if (!isset(SU_FOERDERARTEN[$foerderart])) $errors['foerderart'] = 'Bitte eine Förderart wählen.';
        if ($titel === '') $errors['titel'] = 'Bitte das Vorhaben kurz benennen.';

        // Kostenaufstellung (Zeilen ohne Bezeichnung werden ignoriert)
        $kosten = [];
        foreach ((array)($_POST['kosten_bezeichnung'] ?? []) as $i => $bez) {
            $bez = trim((string)$bez);
            if ($bez === '') continue;
            $kosten[] = [
                'bezeichnung' => mb_substr($bez, 0, 200),
                'menge'       => max(0, (float)str_replace(',', '.', $_POST['kosten_menge'][$i] ?? '1')),
                'einzelpreis' => moneyRound(max(0, (float)str_replace(',', '.', $_POST['kosten_einzelpreis'][$i] ?? '0'))),
                'anbieter'    => trim((string)($_POST['kosten_anbieter'][$i] ?? '')) ?: null,
            ];
        }

        if (empty($errors)) {
            // Nur die Zusatzfelder speichern, die zur Förderart gehören (ausgeblendete Felder verwerfen)
            $felder = SU_FOERDERARTEN[$foerderart]['felder'];
            $hat    = fn(string $feld) => in_array($feld, $felder, true);
            $werte = [
                $foerderart,
                mb_substr($titel, 0, 200),
                trim($_POST['beschreibung'] ?? '') ?: null,
                trim($_POST['nutzen'] ?? '') ?: null,
                suDatumAusPost('massnahme_von'),
                suDatumAusPost('massnahme_bis'),
                $hat('anzahl_personen') && ($_POST['anzahl_personen'] ?? '') !== '' ? max(0, (int)$_POST['anzahl_personen']) : null,
                $hat('ausbildungsstufe') && isset(SU_AUSBILDUNG_SAETZE[$stufe]) ? $stufe : null,
                $hat('mit_uebernachtung') && !empty($_POST['mit_uebernachtung']) ? 1 : 0,
                $hat('wettkampf') ? (trim($_POST['wettkampf'] ?? '') ?: null) : null,
                $hat('platzierung') ? (trim($_POST['platzierung'] ?? '') ?: null) : null,
                suBetragAusPost('eigenmittel') ?? '0.00',
                suBetragAusPost('andere_foerderungen') ?? '0.00',
                trim($_POST['andere_foerderungen_text'] ?? '') ?: null,
                suBetragAusPost('betrag_beantragt'),
                $hat('kategorie') && isset(SU_SOZIAL_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : null,
                // Nur Prüfpunkte der gewählten Förderart übernehmen
                json_encode(array_values(array_intersect(array_keys(SU_CHECKS[$foerderart] ?? []), (array)($_POST['checks'] ?? [])))),
            ];

            $db->beginTransaction();
            if ($position_id > 0) {
                $db->prepare(
                    'UPDATE su_antrag_positionen SET foerderart = ?, titel = ?, beschreibung = ?, nutzen = ?, massnahme_von = ?, massnahme_bis = ?,
                        anzahl_personen = ?, ausbildungsstufe = ?, mit_uebernachtung = ?, wettkampf = ?, platzierung = ?,
                        eigenmittel = ?, andere_foerderungen = ?, andere_foerderungen_text = ?, betrag_beantragt = ?, kategorie = ?, checks = ?
                     WHERE id = ? AND antrag_id = ?'
                )->execute(array_merge($werte, [$position_id, $antrag_id]));
                $db->prepare('DELETE FROM su_antrag_kosten WHERE position_id = ?')->execute([$position_id]);
            } else {
                $db->prepare(
                    'INSERT INTO su_antrag_positionen (foerderart, titel, beschreibung, nutzen, massnahme_von, massnahme_bis,
                        anzahl_personen, ausbildungsstufe, mit_uebernachtung, wettkampf, platzierung,
                        eigenmittel, andere_foerderungen, andere_foerderungen_text, betrag_beantragt, kategorie, checks, antrag_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute(array_merge($werte, [$antrag_id]));
                $position_id = (int)$db->lastInsertId();
            }
            $ins = $db->prepare('INSERT INTO su_antrag_kosten (position_id, bezeichnung, menge, einzelpreis, anbieter, sortierung) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($kosten as $i => $k) {
                $ins->execute([$position_id, $k['bezeichnung'], $k['menge'], $k['einzelpreis'], $k['anbieter'], $i]);
            }
            $db->commit();

            logActivity('su_position_gespeichert', "Antrag-ID: {$antrag_id}, Position-ID: {$position_id}");
            flashMessage('success', 'Fördergegenstand „' . $titel . '“ gespeichert.');
            redirect($self_url . '#positionen');
        }
    }

    if ($action === 'position_loeschen') {
        $position_id = (int)($_POST['position_id'] ?? 0);
        $db->prepare('DELETE FROM su_antrag_positionen WHERE id = ? AND antrag_id = ?')->execute([$position_id, $antrag_id]);
        logActivity('su_position_geloescht', "Antrag-ID: {$antrag_id}, Position-ID: {$position_id}");
        flashMessage('success', 'Fördergegenstand entfernt.');
        redirect($self_url . '#positionen');
    }

    if ($action === 'stammdaten_speichern') {
        $jahr  = (int)($_POST['jahr'] ?? 0);
        $titel = trim($_POST['titel'] ?? '');
        $kontakt_email = trim($_POST['kontakt_email'] ?? '');
        if ($jahr < 2020 || $jahr > 2100) $errors['jahr'] = 'Bitte ein gültiges Förderjahr angeben.';
        if ($titel === '') $errors['titel_antrag'] = 'Bitte eine Bezeichnung angeben.';
        if ($kontakt_email !== '' && !filter_var($kontakt_email, FILTER_VALIDATE_EMAIL)) $errors['kontakt_email'] = 'Die E-Mail-Adresse ist ungültig.';

        if (empty($errors)) {
            $funktion = ($_POST['vertreter2_funktion'] ?? '') === 'schriftfuehrer' ? 'schriftfuehrer' : 'kassier';
            // Erklärungen plus Vereinsbonus-Voraussetzungen (Häkchen)
            $haken = array_merge(array_keys(SU_ERKLAERUNGEN), ['vb_fit_siegel', 'vb_beratung']);
            $erkl  = array_map(fn($k) => !empty($_POST[$k]) ? 1 : 0, $haken);
            $db->prepare(
                'UPDATE su_antraege SET jahr = ?, titel = ?, sportarten = ?, mitglieder_gesamt = ?, mitglieder_jugend = ?, vereinsbeschreibung = ?,
                    obmann_name = ?, vertreter2_funktion = ?, vertreter2_name = ?, kontakt_name = ?, kontakt_email = ?, kontakt_telefon = ?,
                    kontoinhaber = ?, iban = ?, bic = ?, bank = ?, ' . implode(' = ?, ', $haken) . ' = ?, notizen = ?
                 WHERE id = ?'
            )->execute(array_merge([
                $jahr, mb_substr($titel, 0, 150),
                trim($_POST['sportarten'] ?? '') ?: null,
                ($_POST['mitglieder_gesamt'] ?? '') !== '' ? max(0, (int)$_POST['mitglieder_gesamt']) : null,
                ($_POST['mitglieder_jugend'] ?? '') !== '' ? max(0, (int)$_POST['mitglieder_jugend']) : null,
                trim($_POST['vereinsbeschreibung'] ?? '') ?: null,
                trim($_POST['obmann_name'] ?? '') ?: null,
                $funktion,
                trim($_POST['vertreter2_name'] ?? '') ?: null,
                trim($_POST['kontakt_name'] ?? '') ?: null,
                $kontakt_email ?: null,
                trim($_POST['kontakt_telefon'] ?? '') ?: null,
                trim($_POST['kontoinhaber'] ?? '') ?: null,
                strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? '')) ?: null,
                strtoupper(trim($_POST['bic'] ?? '')) ?: null,
                trim($_POST['bank'] ?? '') ?: null,
            ], $erkl, [trim($_POST['notizen'] ?? '') ?: null, $antrag_id]));
            logActivity('su_antrag_stammdaten', "Antrag-ID: {$antrag_id}");
            flashMessage('success', 'Vereinsdaten und Erklärungen gespeichert.');
            redirect($self_url . '#stammdaten');
        }
    }

    if ($action === 'status_speichern') {
        $status = $_POST['status'] ?? 'entwurf';
        if (!isset(SU_STATUS[$status])) $status = 'entwurf';
        $eingereicht_am = suDatumAusPost('eingereicht_am');
        if ($status !== 'entwurf' && $eingereicht_am === null) $eingereicht_am = date('Y-m-d');

        $db->beginTransaction();
        $db->prepare('UPDATE su_antraege SET status = ?, eingereicht_am = ?, zusage_datum = ?, abgerechnet_am = ? WHERE id = ?')
           ->execute([$status, $eingereicht_am, suDatumAusPost('zusage_datum'), suDatumAusPost('abgerechnet_am'), $antrag_id]);
        $upd = $db->prepare('UPDATE su_antrag_positionen SET betrag_zugesagt = ? WHERE id = ? AND antrag_id = ?');
        foreach ((array)($_POST['zugesagt'] ?? []) as $position_id => $betrag) {
            $betrag = trim(str_replace(',', '.', (string)$betrag));
            $upd->execute([$betrag !== '' ? moneyRound(max(0, (float)$betrag)) : null, (int)$position_id, $antrag_id]);
        }
        $db->commit();
        logActivity('su_antrag_status', "Antrag-ID: {$antrag_id}, Status: {$status}");
        flashMessage('success', 'Status und Zusage gespeichert.');
        redirect($self_url . '#status');
    }

    if ($action === 'antrag_loeschen') {
        $db->prepare('DELETE FROM su_antraege WHERE id = ? AND organization_id = ?')->execute([$antrag_id, currentOrgId()]);
        logActivity('su_antrag_geloescht', "Antrag-ID: {$antrag_id}, {$antrag['titel']}");
        flashMessage('success', 'Förderansuchen gelöscht.');
        redirect(APP_URL . '/dashboard/admin/basisfoerderung.php');
    }
}

// ----------------------------------------------------------------
// Daten laden und auswerten
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM su_antrag_positionen WHERE antrag_id = ? ORDER BY sortierung ASC, id ASC');
$stmt->execute([$antrag_id]);
$positionen = $stmt->fetchAll();

$kosten_je_position = [];
if ($positionen) {
    $ids = array_column($positionen, 'id');
    $stmt = $db->prepare('SELECT * FROM su_antrag_kosten WHERE position_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sortierung ASC, id ASC');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $k) $kosten_je_position[$k['position_id']][] = $k;
}

$kosten_gesamt = []; $beantragt_gesamt = []; $zugesagt_gesamt = []; $pruefung = []; $mit_bau = false;
$beantragt_programm = ['landesverband' => [], 'vereinsbonus' => [], 'land' => []];
foreach ($positionen as &$p) {
    $p['kosten']      = $kosten_je_position[$p['id']] ?? [];
    $p['kostensumme'] = suKostenSumme($p['kosten']);
    $p['richtwert']   = suRichtwert($p, $p['kostensumme']);
    $p['beantragt']   = suBeantragt($p, $p['kostensumme']);
    $p['meldungen']   = suPruefePosition($antrag, $p, $p['kosten'], $p['kostensumme'], $p['beantragt']);
    $kosten_gesamt[]    = $p['kostensumme'];
    $beantragt_gesamt[] = $p['beantragt'];
    $beantragt_programm[suProgramm($p['foerderart'])][] = $p['beantragt'];
    if ($p['betrag_zugesagt'] !== null) $zugesagt_gesamt[] = $p['betrag_zugesagt'];
    if ($p['foerderart'] === 'bau') $mit_bau = true;
    foreach ($p['meldungen'] as $mld) $pruefung[] = $mld + ['bezug' => $p['titel']];
}
unset($p);
$kosten_gesamt    = moneySum($kosten_gesamt);
$beantragt_gesamt = moneySum($beantragt_gesamt);
$zugesagt_gesamt  = $zugesagt_gesamt ? moneySum($zugesagt_gesamt) : null;
$mit_vereinsbonus = !empty($beantragt_programm['vereinsbonus']);
$beantragt_lv = moneySum($beantragt_programm['landesverband']);
$beantragt_vb = moneySum($beantragt_programm['vereinsbonus']);
$beantragt_land = moneySum($beantragt_programm['land']);
$land_positionen = array_values(array_filter($positionen, fn($p) => suProgramm($p['foerderart']) === 'land'));
foreach (array_reverse(suPruefeAntrag($antrag, $beantragt_gesamt, count($positionen), $mit_vereinsbonus, $land_positionen)) as $mld) array_unshift($pruefung, $mld + ['bezug' => 'Ansuchen']);

$anzahl_fehler   = count(array_filter($pruefung, fn($m) => $m['typ'] === 'fehler'));
$anzahl_warnung  = count(array_filter($pruefung, fn($m) => $m['typ'] === 'warnung'));
$abrechnungsfrist = suAbrechnungsfrist($antrag, $mit_bau);

// Nachweise für die Abrechnung (je Förderart gesammelt)
$nachweise = ['Originalrechnungen auf den Namen des Vereins (bei E-Rechnungen: Richtigkeitsvermerk, vereinsmäßig gezeichnet)', 'Lückenloser Zahlungsnachweis (Auftragsbestätigung + Kontoauszug bzw. Kassabuch)'];
foreach ($positionen as $p) foreach (suFoerderart($p['foerderart'])['nachweise'] as $n) $nachweise[] = $n;
$nachweise = array_values(array_unique($nachweise));

// Formular für Fördergegenstand (neu / bearbeiten / nach Fehler)
$bearbeiten_id = (int)($_POST['position_id'] ?? $_GET['position'] ?? 0);
$pos_form = [
    'foerderart' => $_GET['art'] ?? 'geraete', 'titel' => '', 'beschreibung' => '', 'nutzen' => '', 'massnahme_von' => '', 'massnahme_bis' => '',
    'anzahl_personen' => '', 'ausbildungsstufe' => '', 'mit_uebernachtung' => 0, 'wettkampf' => '', 'platzierung' => '',
    'eigenmittel' => '', 'andere_foerderungen' => '', 'andere_foerderungen_text' => '', 'betrag_beantragt' => '', 'kosten' => [],
    'kategorie' => '', 'checks' => '[]',
];
if (!isset(SU_FOERDERARTEN[$pos_form['foerderart']])) $pos_form['foerderart'] = 'geraete';
if ($bearbeiten_id > 0) {
    foreach ($positionen as $p) {
        if ((int)$p['id'] === $bearbeiten_id) $pos_form = array_merge($pos_form, $p);
    }
}
if (($_POST['action'] ?? '') === 'position_speichern') {
    $pos_form = array_merge($pos_form, $_POST);
    $pos_form['kosten'] = $kosten ?? [];
    $pos_form['checks'] = json_encode(array_values((array)($_POST['checks'] ?? [])));
}
$pos_checks = suChecks($pos_form);
if (($_POST['action'] ?? '') === 'stammdaten_speichern') $antrag = array_merge($antrag, $_POST);
$kosten_zeilen = $pos_form['kosten'] ?: [['bezeichnung' => '', 'menge' => 1, 'einzelpreis' => '', 'anbieter' => '']];

$s = SU_STATUS[$antrag['status']] ?? ['label' => $antrag['status'], 'class' => 'badge-gray'];
$v = fn($wert) => e((string)($wert ?? ''));

$page_title = $antrag['titel'];
$breadcrumb = 'Basisförderung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.su-meldung { display: flex; gap: 0.5rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; margin-bottom: 0.4rem; font-size: 0.875rem; }
.su-meldung-fehler  { background: rgba(239, 68, 68, 0.12); }
.su-meldung-warnung { background: rgba(245, 158, 11, 0.14); }
.su-meldung-info    { background: rgba(59, 130, 246, 0.10); }
.su-regeln { background: var(--gold-dim); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; }
.su-regeln ul { margin: 0.4rem 0 0 1.1rem; }
.su-kosten-tabelle input { min-width: 90px; }
.su-summe { font-weight: 700; margin: 0.75rem 0; }
.su-abschnitt { font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin: 1.25rem 0 0.75rem; }
</style>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/admin/basisfoerderung.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Basisförderung &amp; Förderkatalog
        </a>
        <h1 class="dashboard-title"><?= e($antrag['titel']) ?> <span class="badge <?= $s['class'] ?>" style="vertical-align: middle;"><?= e($s['label']) ?></span></h1>
        <p class="dashboard-subtitle">Förderansuchen an die SPORTUNION Steiermark<?= $land_positionen ? ' und das Land Steiermark' : '' ?> · Förderzeitraum 01.01.–31.12.<?= (int)$antrag['jahr'] ?></p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <?php // Je Förderstelle ein eigenes PDF, weil SPORTUNION und Land getrennt eingereicht werden
        foreach (SU_STELLEN as $stelle => $info):
            if ($stelle === 'land' && !$land_positionen) continue;
            if ($stelle === 'sportunion' && $land_positionen && count($land_positionen) === count($positionen)) continue; ?>
            <a href="<?= APP_URL ?>/dashboard/admin/basisfoerderung-pdf.php?id=<?= $antrag_id ?>&amp;stelle=<?= $stelle ?>" target="_blank" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                PDF <?= e($info['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>
        <div class="kpi-value"><?= count($positionen) ?></div>
        <div class="kpi-label">Fördergegenstände</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div>
        <div class="kpi-value"><?= moneyFormat($kosten_gesamt) ?></div>
        <div class="kpi-label">Gesamtkosten</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #F59E0B;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="kpi-value"><?= moneyFormat($beantragt_gesamt) ?></div>
        <div class="kpi-label">Beantragt (SPORTUNION <?= moneyFormat(moneySum([$beantragt_lv, $beantragt_vb])) ?> · Land <?= moneyFormat($beantragt_land) ?>)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #22C55E;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div class="kpi-value"><?= $zugesagt_gesamt !== null ? moneyFormat($zugesagt_gesamt) : '–' ?></div>
        <div class="kpi-label"><?= $abrechnungsfrist ? 'Zugesagt · abrechnen bis ' . date('d.m.Y', strtotime($abrechnungsfrist)) : 'Zugesagt' ?></div>
    </div>
</div>

<!-- Antrags-Check -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">
            Antrags-Check
            <?php if ($anzahl_fehler === 0 && $anzahl_warnung === 0): ?>
                <span class="badge badge-success">bereit zum Einreichen</span>
            <?php else: ?>
                <?php if ($anzahl_fehler): ?><span class="badge badge-danger"><?= $anzahl_fehler ?> offen</span><?php endif; ?>
                <?php if ($anzahl_warnung): ?><span class="badge badge-warning"><?= $anzahl_warnung ?> Hinweis<?= $anzahl_warnung === 1 ? '' : 'e' ?></span><?php endif; ?>
            <?php endif; ?>
        </h2>
    </div>
    <div style="padding: 1.25rem;">
        <?php if (empty($pruefung)): ?>
            <p style="margin: 0;">Alles vollständig. Das PDF erzeugen, von Obmann/Obfrau und <?= $antrag['vertreter2_funktion'] === 'schriftfuehrer' ? 'Schriftführer:in' : 'Kassier:in' ?> unterschreiben lassen und über die <a href="<?= e(SU_VEREINSDATENBANK) ?>" target="_blank" rel="noopener">Vereinsdatenbank</a> einreichen.</p>
        <?php else: ?>
            <?php foreach ($pruefung as $mld): ?>
                <div class="su-meldung su-meldung-<?= $mld['typ'] ?>">
                    <strong style="white-space: nowrap;"><?= $mld['typ'] === 'fehler' ? 'Fehlt' : ($mld['typ'] === 'warnung' ? 'Achtung' : 'Info') ?> · <?= e($mld['bezug']) ?>:</strong>
                    <span><?= e($mld['text']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Fördergegenstände -->
<div class="table-card" id="positionen" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Fördergegenstände (<?= count($positionen) ?>)</h2>
    </div>

    <?php if (empty($positionen)): ?>
        <div class="empty-state">
            <h3>Noch nichts erfasst</h3>
            <p>Lege unten alles an, was gefördert werden soll – z.B. Geräte, Ausbildungen, Jugendprojekte oder Veranstaltungen.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr><th>#</th><th>Vorhaben</th><th>Förderart</th><th>Kosten</th><th>Beantragt</th><th>Zugesagt</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($positionen as $i => $p): $art = suFoerderart($p['foerderart']);
                        $fehler_pos = count(array_filter($p['meldungen'], fn($m) => $m['typ'] === 'fehler')); ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="text-primary"><?= e($p['titel']) ?></div>
                            <?php if ($fehler_pos): ?><span class="badge badge-danger"><?= $fehler_pos ?> offen</span><?php endif; ?>
                        </td>
                        <td>
                            <?php $prog = SU_PROGRAMME[suProgramm($p['foerderart'])]; ?>
                            <span class="badge <?= $prog['class'] ?>"><?= e($prog['kurz']) ?></span>
                            <div><?= e($art['label']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($p['kategorie'] ? (SU_SOZIAL_KATEGORIEN[$p['kategorie']] ?? '') : (SU_BEREICHE[$art['bereich']] ?? '')) ?></div>
                        </td>
                        <td><?= bccomp($p['kostensumme'], '0', 2) > 0 ? moneyFormat($p['kostensumme']) : '–' ?></td>
                        <td>
                            <strong><?= moneyFormat($p['beantragt']) ?></strong>
                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= $p['betrag_beantragt'] !== null ? 'manuell' : 'Richtwert' ?></div>
                        </td>
                        <td><?= $p['betrag_zugesagt'] !== null ? moneyFormat($p['betrag_zugesagt']) : '–' ?></td>
                        <td style="white-space: nowrap;">
                            <a href="<?= $self_url ?>&amp;position=<?= $p['id'] ?>#position-formular" class="btn btn-ghost-light btn-sm">Bearbeiten</a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Fördergegenstand wirklich entfernen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="position_loeschen">
                                <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: 700;">
                        <td colspan="3">Gesamt</td>
                        <td><?= moneyFormat($kosten_gesamt) ?></td>
                        <td><?= moneyFormat($beantragt_gesamt) ?></td>
                        <td><?= $zugesagt_gesamt !== null ? moneyFormat($zugesagt_gesamt) : '–' ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Formular Fördergegenstand -->
    <div id="position-formular" style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 class="su-abschnitt" style="margin-top: 0;"><?= $bearbeiten_id > 0 ? 'Fördergegenstand bearbeiten' : 'Fördergegenstand hinzufügen' ?></h3>
        <form method="POST" action="<?= $self_url ?>#position-formular" id="su-position-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="position_speichern">
            <input type="hidden" name="position_id" value="<?= $bearbeiten_id ?>">

            <div class="form-group">
                <label class="form-label">Förderart <span class="required">*</span></label>
                <select class="form-control <?= isset($errors['foerderart']) ? 'error' : '' ?>" name="foerderart" id="su-foerderart">
                    <?php foreach (SU_BEREICHE as $bereich => $bereich_label): ?>
                        <optgroup label="<?= e($bereich_label) ?>">
                            <?php foreach (SU_FOERDERARTEN as $schluessel => $art): if ($art['bereich'] !== $bereich) continue; ?>
                                <option value="<?= $schluessel ?>" <?= $pos_form['foerderart'] === $schluessel ? 'selected' : '' ?>><?= e($art['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="su-regeln" id="su-regeln"></div>

            <div class="form-group">
                <label class="form-label">Vorhaben (Kurzbezeichnung) <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" name="titel" maxlength="200" value="<?= $v($pos_form['titel']) ?>" id="su-titel">
            </div>
            <div class="form-group">
                <label class="form-label">Beschreibung des Fördergegenstandes <span class="required">*</span></label>
                <textarea class="form-control" name="beschreibung" rows="4" placeholder="Was genau wird angeschafft / durchgeführt? Wo, wann, für wen?"><?= $v($pos_form['beschreibung']) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Ziel und Nutzen für den Verein</label>
                <textarea class="form-control" name="nutzen" rows="3" placeholder="z.B. mehr Trainingsgruppen, Nachwuchsarbeit, Qualifikation der Trainer:innen, Kooperation mit Schulen"><?= $v($pos_form['nutzen']) ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Maßnahme von</label>
                    <input class="form-control" type="date" name="massnahme_von" value="<?= $v($pos_form['massnahme_von']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">bis</label>
                    <input class="form-control" type="date" name="massnahme_bis" value="<?= $v($pos_form['massnahme_bis']) ?>">
                </div>
            </div>

            <!-- Felder je nach Förderart -->
            <div class="form-row">
                <div class="form-group su-feld" data-feld="ausbildungsstufe">
                    <label class="form-label">Ausbildungsstufe <span class="required">*</span></label>
                    <select class="form-control su-calc" name="ausbildungsstufe">
                        <option value="">– bitte wählen –</option>
                        <?php foreach (SU_AUSBILDUNG_SAETZE as $stufe => $satz): ?>
                            <option value="<?= $stufe ?>" <?= $pos_form['ausbildungsstufe'] === $stufe ? 'selected' : '' ?>><?= e($satz['label']) ?> (<?= moneyFormat($satz['betrag']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group su-feld" data-feld="anzahl_personen">
                    <label class="form-label" id="su-personen-label">Anzahl Personen</label>
                    <input class="form-control su-calc" type="number" min="0" name="anzahl_personen" value="<?= $v($pos_form['anzahl_personen']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group su-feld" data-feld="wettkampf">
                    <label class="form-label" id="su-wettkampf-label">Wettkampf / Veranstaltung / Ausbildung</label>
                    <input class="form-control" type="text" name="wettkampf" maxlength="200" value="<?= $v($pos_form['wettkampf']) ?>" placeholder="Name, Ort, Veranstalter">
                </div>
                <div class="form-group su-feld" data-feld="platzierung">
                    <label class="form-label">Platzierung</label>
                    <input class="form-control" type="text" name="platzierung" maxlength="100" value="<?= $v($pos_form['platzierung']) ?>" placeholder="z.B. 2. Platz ÖM U17">
                </div>
            </div>
            <div class="form-group su-feld" data-feld="mit_uebernachtung">
                <label style="display: flex; gap: 0.5rem; align-items: center;">
                    <input type="checkbox" class="su-calc" name="mit_uebernachtung" value="1" <?= !empty($pos_form['mit_uebernachtung']) ? 'checked' : '' ?>>
                    Mit Übernachtung (€ 50,– statt € 25,– je Teilnehmer:in)
                </label>
            </div>
            <div class="form-group su-feld" data-feld="kategorie">
                <label class="form-label">Kategorie der sozialen Maßnahme <span class="required">*</span></label>
                <select class="form-control" name="kategorie">
                    <option value="">– bitte wählen –</option>
                    <?php foreach (SU_SOZIAL_KATEGORIEN as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($pos_form['kategorie'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Voraussetzungen der gewählten Förderart (per JS befüllt) -->
            <h4 class="su-abschnitt">Voraussetzungen</h4>
            <div id="su-checks" data-init="<?= e(json_encode($pos_checks)) ?>"></div>

            <!-- Kostenaufstellung -->
            <h4 class="su-abschnitt">Kostenaufstellung</h4>
            <div style="overflow-x: auto;">
                <table class="data-table su-kosten-tabelle">
                    <thead><tr><th>Bezeichnung</th><th>Menge</th><th>Einzelpreis (€)</th><th>Anbieter / Angebot</th><th>Summe</th><th></th></tr></thead>
                    <tbody id="su-kosten-zeilen">
                        <?php foreach ($kosten_zeilen as $k): ?>
                        <tr>
                            <td><input class="form-control" type="text" name="kosten_bezeichnung[]" maxlength="200" value="<?= $v($k['bezeichnung']) ?>"></td>
                            <td><input class="form-control su-calc su-menge" type="number" min="0" step="0.01" name="kosten_menge[]" value="<?= $v((float)$k['menge']) ?>"></td>
                            <td><input class="form-control su-calc su-preis" type="number" min="0" step="0.01" name="kosten_einzelpreis[]" value="<?= $v($k['einzelpreis']) ?>"></td>
                            <td><input class="form-control" type="text" name="kosten_anbieter[]" maxlength="150" value="<?= $v($k['anbieter']) ?>"></td>
                            <td class="su-zeilensumme" style="white-space: nowrap;">–</td>
                            <td><button type="button" class="btn btn-ghost-light btn-sm su-zeile-weg" title="Zeile entfernen">✕</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-ghost-light btn-sm" id="su-zeile-neu" style="margin-top: 0.5rem;">+ Kostenposition</button>
            <p class="su-summe">Gesamtkosten: <span id="su-kosten-summe">–</span></p>

            <!-- Finanzierung -->
            <h4 class="su-abschnitt">Finanzierungsplan</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Eigenmittel des Vereins (€)</label>
                    <input class="form-control su-calc" type="number" min="0" step="0.01" name="eigenmittel" value="<?= $v($pos_form['eigenmittel']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Andere Förderungen (€)</label>
                    <input class="form-control su-calc" type="number" min="0" step="0.01" name="andere_foerderungen" value="<?= $v($pos_form['andere_foerderungen']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Andere Fördergeber</label>
                    <input class="form-control" type="text" name="andere_foerderungen_text" maxlength="255" value="<?= $v($pos_form['andere_foerderungen_text']) ?>" placeholder="z.B. Gemeinde, Land Steiermark, Fachverband">
                </div>
                <div class="form-group">
                    <label class="form-label">Beantragter Betrag (€)</label>
                    <input class="form-control su-calc" type="number" min="0" step="0.01" name="betrag_beantragt" id="su-beantragt" value="<?= $v($pos_form['betrag_beantragt']) ?>" placeholder="leer = Richtwert übernehmen">
                </div>
            </div>
            <div class="su-regeln" style="background: transparent; border: 1px dashed var(--border-light);">
                <div>Richtwert: <strong id="su-richtwert">–</strong> <span id="su-richtwert-text" style="color: var(--text-muted);"></span></div>
                <div id="su-finanzierung" style="margin-top: 0.25rem;"></div>
            </div>

            <button type="submit" class="btn btn-navy btn-sm"><?= $bearbeiten_id > 0 ? 'Änderungen speichern' : 'Fördergegenstand hinzufügen' ?></button>
            <?php if ($bearbeiten_id > 0): ?><a href="<?= $self_url ?>#positionen" class="btn btn-ghost-light btn-sm">Abbrechen</a><?php endif; ?>
        </form>
    </div>
</div>

<!-- Vereinsdaten & Erklärungen -->
<div class="table-card" id="stammdaten" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Antragsteller, Vertretung &amp; Erklärungen</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= $self_url ?>#stammdaten">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="stammdaten_speichern">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Bezeichnung <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['titel_antrag']) ? 'error' : '' ?>" type="text" name="titel" maxlength="150" value="<?= $v($antrag['titel']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Förderjahr <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['jahr']) ? 'error' : '' ?>" type="number" min="2020" max="2100" name="jahr" value="<?= $v($antrag['jahr']) ?>">
                </div>
            </div>
            <p class="form-hint">Verein: <?= e(APP_NAME) ?> · ZVR <?= e(VEREIN_ZVR) ?> · <?= e(VEREIN_ADRESSE) ?></p>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Sportarten / Sparten</label>
                    <input class="form-control" type="text" name="sportarten" maxlength="255" value="<?= $v($antrag['sportarten']) ?>">
                </div>
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Mitglieder gesamt</label>
                        <input class="form-control" type="number" min="0" name="mitglieder_gesamt" value="<?= $v($antrag['mitglieder_gesamt']) ?>">
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">davon unter 19</label>
                        <input class="form-control" type="number" min="0" name="mitglieder_jugend" value="<?= $v($antrag['mitglieder_jugend']) ?>">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Kurzvorstellung des Vereins (erscheint im Antrag)</label>
                <textarea class="form-control" name="vereinsbeschreibung" rows="4" placeholder="Wer wir sind, was wir anbieten, Zielgruppen, Trainingsbetrieb, Kooperationen"><?= $v($antrag['vereinsbeschreibung']) ?></textarea>
            </div>

            <h4 class="su-abschnitt">Statutarische Vertretung (unterzeichnet den Antrag)</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Obmann / Obfrau</label>
                    <input class="form-control" type="text" name="obmann_name" maxlength="150" value="<?= $v($antrag['obmann_name']) ?>">
                </div>
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Zweite Unterschrift</label>
                        <select class="form-control" name="vertreter2_funktion">
                            <option value="kassier" <?= $antrag['vertreter2_funktion'] === 'kassier' ? 'selected' : '' ?>>Kassier:in</option>
                            <option value="schriftfuehrer" <?= $antrag['vertreter2_funktion'] === 'schriftfuehrer' ? 'selected' : '' ?>>Schriftführer:in</option>
                        </select>
                    </div>
                    <div style="flex: 2;">
                        <label class="form-label">Name</label>
                        <input class="form-control" type="text" name="vertreter2_name" maxlength="150" value="<?= $v($antrag['vertreter2_name']) ?>">
                    </div>
                </div>
            </div>

            <h4 class="su-abschnitt">Ansprechperson</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input class="form-control" type="text" name="kontakt_name" maxlength="150" value="<?= $v($antrag['kontakt_name']) ?>">
                </div>
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">E-Mail</label>
                        <input class="form-control <?= isset($errors['kontakt_email']) ? 'error' : '' ?>" type="email" name="kontakt_email" maxlength="180" value="<?= $v($antrag['kontakt_email']) ?>">
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Telefon</label>
                        <input class="form-control" type="text" name="kontakt_telefon" maxlength="30" value="<?= $v($antrag['kontakt_telefon']) ?>">
                    </div>
                </div>
            </div>

            <h4 class="su-abschnitt">Bankverbindung für die Auszahlung</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Kontoinhaber</label>
                    <input class="form-control" type="text" name="kontoinhaber" maxlength="150" value="<?= $v($antrag['kontoinhaber']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Bank</label>
                    <input class="form-control" type="text" name="bank" maxlength="150" value="<?= $v($antrag['bank']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">IBAN</label>
                    <input class="form-control" type="text" name="iban" maxlength="50" value="<?= $v($antrag['iban']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">BIC</label>
                    <input class="form-control" type="text" name="bic" maxlength="20" value="<?= $v($antrag['bic']) ?>">
                </div>
            </div>

            <h4 class="su-abschnitt">Erklärungen des Vereins</h4>
            <?php foreach (SU_ERKLAERUNGEN as $feld => $text): ?>
                <label style="display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.6rem; font-size: 0.9rem;">
                    <input type="checkbox" name="<?= $feld ?>" value="1" <?= !empty($antrag[$feld]) ? 'checked' : '' ?> style="margin-top: 0.2rem;">
                    <span><?= e($text) ?></span>
                </label>
            <?php endforeach; ?>

            <h4 class="su-abschnitt">Voraussetzungen SPORTUNION Vereinsbonus</h4>
            <label style="display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.6rem; font-size: 0.9rem;">
                <input type="checkbox" name="vb_fit_siegel" value="1" <?= !empty($antrag['vb_fit_siegel']) ? 'checked' : '' ?> style="margin-top: 0.2rem;">
                <span>Der Verein hat mindestens ein aktives Fit-Sport-Austria-Qualitätssiegel (Kurs mit mind. 10 Einheiten à 45 Min. pro Semester, max. 20 Teilnehmer:innen je ÜL, ÜL mit mind. 57 EH Ausbildung).</span>
            </label>
            <label style="display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.6rem; font-size: 0.9rem;">
                <input type="checkbox" name="vb_beratung" value="1" <?= !empty($antrag['vb_beratung']) ? 'checked' : '' ?> style="margin-top: 0.2rem;">
                <span>Das verpflichtende Beratungsgespräch mit der SPORTUNION Steiermark wurde geführt.</span>
            </label>

            <div class="form-group" style="margin-top: 1rem;">
                <label class="form-label">Interne Notizen (nicht im Antrag)</label>
                <textarea class="form-control" name="notizen" rows="3"><?= $v($antrag['notizen']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        </form>
    </div>
</div>

<!-- Status, Zusage & Abrechnung -->
<div class="table-card" id="status" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Einreichung, Zusage &amp; Abrechnung</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= $self_url ?>#status">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="status_speichern">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status">
                        <?php foreach (SU_STATUS as $val => $st): ?>
                            <option value="<?= $val ?>" <?= $antrag['status'] === $val ? 'selected' : '' ?>><?= e($st['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Eingereicht am</label>
                    <input class="form-control" type="date" name="eingereicht_am" value="<?= $v($antrag['eingereicht_am']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Zusage vom</label>
                    <input class="form-control" type="date" name="zusage_datum" value="<?= $v($antrag['zusage_datum']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Abgerechnet am</label>
                    <input class="form-control" type="date" name="abgerechnet_am" value="<?= $v($antrag['abgerechnet_am']) ?>">
                </div>
            </div>
            <?php if ($positionen): ?>
                <div style="overflow-x: auto; margin-bottom: 1rem;">
                    <table class="data-table">
                        <thead><tr><th>Fördergegenstand</th><th>Beantragt</th><th>Zugesagt (€)</th></tr></thead>
                        <tbody>
                            <?php foreach ($positionen as $p): ?>
                            <tr>
                                <td><?= e($p['titel']) ?></td>
                                <td><?= moneyFormat($p['beantragt']) ?></td>
                                <td style="min-width: 140px;"><input class="form-control" type="number" min="0" step="0.01" name="zugesagt[<?= $p['id'] ?>]" value="<?= $v($p['betrag_zugesagt']) ?>"></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        </form>

        <h4 class="su-abschnitt">Für die Abrechnung bereithalten<?= $abrechnungsfrist ? ' – Frist ' . date('d.m.Y', strtotime($abrechnungsfrist)) : '' ?></h4>
        <ul style="margin-left: 1.25rem; font-size: 0.9rem;">
            <?php foreach ($nachweise as $n): ?><li><?= e($n) ?></li><?php endforeach; ?>
            <li>Rechnungs- und Zahlungsdatum im Förderjahr <?= (int)$antrag['jahr'] ?>; Rechnungen ab € 400,– mit Vereinsanschrift und UID-Nr. des Unternehmens</li>
            <li>Nicht anerkannt: Alkohol, Rauchwaren, Trinkgelder, Geschenke (außer Ehrenpreise), Mahnspesen/Strafgelder, Kantine/Vereinslokal, Aufschließungskosten</li>
            <li>Trainer:innen-Honorare: PRAE (max. € 120,–/Tag, € 720,–/Monat, Jahresmeldung bis Ende Februar) oder Honorarnote mit Versteuerungsvermerk</li>
            <?php if (bccomp($beantragt_gesamt, (string)SU_BERICHT_AB, 2) >= 0): ?><li><strong>Bericht über die geförderte Maßnahme (ab <?= moneyFormat(SU_BERICHT_AB) ?>)</strong></li><?php endif; ?>
        </ul>
        <p class="form-hint" style="margin-bottom: 0.4rem;">Vorlagen der SPORTUNION für die Abrechnung:</p>
        <p class="form-hint">
            <?php $links = []; foreach (SU_ABRECHNUNGSFORMULARE as $label => $url) $links[] = '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e($label) . '</a>'; ?>
            <?= implode(' · ', $links) ?>
        </p>
        <p class="form-hint">Ansprechperson Abrechnung: Ina Werni, ina.werni@sportunion-steiermark.at, +43 316 32 44 30 71<?= $mit_vereinsbonus ? ' · Vereinsbonus: Abrechnung nach den Richtlinien der Bundes-Sport GmbH, Leistungs- und Abrechnungszeitraum ist das Kalenderjahr.' : '' ?></p>
        <?php if ($land_positionen): ?>
            <h4 class="su-abschnitt">Abrechnung Land Steiermark</h4>
            <ul style="margin-left: 1.25rem; font-size: 0.9rem;">
                <li>Förderungsvertrag binnen <strong>1 Monat</strong> unterschrieben an die Abteilung 9 retournieren – sonst verfällt die Förderung.</li>
                <li>Verwendungsnachweis grundsätzlich <strong>2 Monate nach Ende</strong> der Maßnahme bzw. laut Förderungsvertrag, per E-Mail an sport@stmk.gv.at mit Geschäftszahl ABT09-…</li>
                <li>Bis <?= moneyFormat(SU_LAND_BAGATELLGRENZE) ?>: Bagatellgrenze, kein Nachweis (Stichproben möglich) · bis <?= moneyFormat(SU_LAND_NACHWEIS_EINFACH) ?>: Tätigkeits-/Projektbericht + Einnahmen-Ausgaben-Aufstellung · darüber zusätzlich Belegaufstellungen.</li>
                <li>Kassenbelege erst ab € 25,– brutto; Honorarnoten mit Leistungszeitraum und Steuervermerk; Eigenhonorare nur mit Leistungsverzeichnis.</li>
            </ul>
            <p class="form-hint">
                <?php $links = []; foreach (SU_LAND_FORMULARE as $label => $url) $links[] = '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e($label) . '</a>'; ?>
                Vorlagen des Landes: <?= implode(' · ', $links) ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="table-card">
    <div style="padding: 1.25rem;">
        <form method="POST" onsubmit="return confirm('Förderansuchen mit allen Fördergegenständen wirklich unwiderruflich löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="antrag_loeschen">
            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Förderansuchen löschen</button>
        </form>
    </div>
</div>

<script>
(() => {
    const ARTEN   = <?= json_encode(SU_FOERDERARTEN, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const STUFEN  = <?= json_encode(SU_AUSBILDUNG_SAETZE, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const CHECKS  = <?= json_encode(SU_CHECKS, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const FP_AB   = <?= (int)SU_FINANZIERUNGSPLAN_AB ?>;
    const form    = document.getElementById('su-position-form');
    const artSel  = document.getElementById('su-foerderart');
    const eur = (v) => v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    const num = (el) => parseFloat(el && el.value !== '' ? el.value : 0) || 0;
    const esc = (s) => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    // Regeln und passende Felder zur gewählten Förderart einblenden
    function zeigeArt() {
        const art = ARTEN[artSel.value];
        document.getElementById('su-regeln').innerHTML =
            '<strong>' + esc(art.label) + '</strong> – ' + esc(art.kurz) +
            (art.beispiel ? ' <em>' + esc(art.beispiel) + '</em>' : '') +
            '<ul>' + art.regeln.map(r => '<li>' + esc(r) + '</li>').join('') +
            '<li>Einreichfrist: ' + esc(art.frist) + (art.nachtraeglich ? ' (auch nachträglich möglich)' : ' – vor Beginn der Maßnahme einreichen') + '</li>' +
            '<li>Nachweise: ' + esc(art.nachweise.join(' · ')) + '</li></ul>';
        document.querySelectorAll('.su-feld').forEach(el => {
            el.style.display = art.felder.includes(el.dataset.feld) ? '' : 'none';
        });
        const labels = art.feld_labels || {};
        document.getElementById('su-personen-label').textContent = labels.anzahl_personen ||
            (art.berechnung === 'ausbildung' ? 'Anzahl Absolvent:innen' : 'Anzahl Teilnehmer:innen');
        document.getElementById('su-wettkampf-label').textContent = labels.wettkampf ||
            (art.berechnung === 'ausbildung' ? 'Ausbildung (Bezeichnung, Anbieter)' : 'Wettkampf / Veranstaltung (Name, Ort)');

        // Prüfpunkte der Förderart als Häkchen anzeigen (bereits erfüllte bleiben erhalten)
        const box = document.getElementById('su-checks');
        const gesetzt = new Set([...box.querySelectorAll('input:checked')].map(i => i.value).concat(box.dataset.init ? JSON.parse(box.dataset.init) : []));
        box.dataset.init = '';
        const checks = CHECKS[artSel.value] || {};
        box.innerHTML = Object.keys(checks).length
            ? Object.entries(checks).map(([k, label]) =>
                '<label style="display:flex; gap:0.6rem; align-items:center; margin-bottom:0.5rem; font-size:0.9rem;">' +
                '<input type="checkbox" name="checks[]" value="' + esc(k) + '"' + (gesetzt.has(k) ? ' checked' : '') + '> ' + esc(label) + '</label>').join('')
            : '<p class="form-hint">Keine besonderen Voraussetzungen.</p>';
        berechne();
    }

    // Kostensumme, Richtwert und Finanzierungsplan live berechnen (gleiche Regeln wie serverseitig)
    function berechne() {
        const art = ARTEN[artSel.value];
        let kosten = 0;
        document.querySelectorAll('#su-kosten-zeilen tr').forEach(tr => {
            const summe = num(tr.querySelector('.su-menge')) * num(tr.querySelector('.su-preis'));
            tr.querySelector('.su-zeilensumme').textContent = summe ? eur(summe) : '–';
            kosten += summe;
        });
        document.getElementById('su-kosten-summe').textContent = eur(kosten);

        const eigen = num(form.eigenmittel), andere = num(form.andere_foerderungen);
        const luecke = Math.max(0, kosten - eigen - andere);
        const personen = num(form.anzahl_personen);
        let richtwert = null, text = '';
        if (art.berechnung === 'rahmen' && kosten > 0) {
            richtwert = Math.min(luecke, art.hoechst);
            text = '(Lücke ' + eur(luecke) + ', Rahmen ' + eur(art.grund) + ' – ' + eur(art.hoechst) + ')';
        } else if (art.berechnung === 'ausbildung' && STUFEN[form.ausbildungsstufe.value]) {
            const p = Math.max(1, personen);
            richtwert = p * STUFEN[form.ausbildungsstufe.value].betrag;
            text = '(' + p + ' × ' + eur(STUFEN[form.ausbildungsstufe.value].betrag) + ' Fixbetrag)';
        } else if (art.berechnung === 'pro_person' && personen > 0) {
            const satz = form.mit_uebernachtung.checked ? art.satz_uebernachtung : art.satz;
            richtwert = personen * satz;
            text = '(' + personen + ' × ' + eur(satz) + ')';
        } else if (art.berechnung === 'fix') {
            richtwert = art.betrag; text = '(Fixbetrag)';
        } else if (art.berechnung === 'land') {
            const max = art.max !== undefined ? art.max : (art.max_prozent_kosten && kosten > 0 ? Math.round(kosten * art.max_prozent_kosten) / 100 : null);
            richtwert = art.standard;
            if (max !== null) richtwert = Math.min(richtwert, max);
            if (kosten > 0) richtwert = Math.min(richtwert, luecke);
            text = '(Standardförderung ' + eur(art.standard) + ', Bandbreite ' + eur(art.min) + ' – ' +
                (art.max !== undefined ? eur(art.max) : art.max_prozent_kosten + ' % des Budgets' + (max !== null ? ' = ' + eur(max) : '')) + ')';
        } else if (art.berechnung === 'deckel') {
            const menge = art.menge_zaehlt ? Math.max(1, personen) : 1;
            const deckel = menge * art.max_je;
            richtwert = kosten > 0 ? Math.min(luecke, deckel) : deckel;
            text = '(' + (menge > 1 ? menge + ' × ' : '') + 'max. ' + eur(art.max_je) + (kosten > 0 ? ', Lücke ' + eur(luecke) : ', Höchstbetrag') + ')';
        } else if (art.berechnung === 'ermessen' && kosten > 0) {
            richtwert = luecke; text = '(Finanzierungslücke, Höhe nach Ermessen)';
        }
        document.getElementById('su-richtwert').textContent = richtwert !== null ? eur(richtwert) : '–';
        document.getElementById('su-richtwert-text').textContent = richtwert !== null ? text : '(Angaben unvollständig)';

        const feld = document.getElementById('su-beantragt');
        feld.placeholder = richtwert !== null ? 'leer = Richtwert ' + eur(richtwert) : 'leer = Richtwert übernehmen';
        const beantragt = feld.value !== '' ? num(feld) : (richtwert || 0);
        let info = '';
        if (art.berechnung === 'deckel') {
            const max = (art.menge_zaehlt ? Math.max(1, personen) : 1) * art.max_je;
            if (beantragt > max) info += '<span style="color: var(--danger);">Über dem Höchstbetrag von ' + eur(max) + '!</span><br>';
        }
        if (art.berechnung === 'land') {
            const max = art.max !== undefined ? art.max : (art.max_prozent_kosten && kosten > 0 ? Math.round(kosten * art.max_prozent_kosten) / 100 : null);
            if (max !== null && beantragt > max) info += '<span style="color: var(--danger);">Über dem Höchstbetrag von ' + eur(max) + '!</span><br>';
            if (kosten > 0 && beantragt + andere >= kosten) info += '<span style="color: var(--danger);">Das Land fördert keine Vollfinanzierung – Eigenmittel ausweisen!</span><br>';
            if (art.kosten_hinweis) info += 'Kostenaufstellung: ' + esc(art.kosten_hinweis) + '.<br>';
        }
        if (kosten > 0 && ['rahmen', 'ermessen', 'deckel', 'land'].includes(art.berechnung)) {
            const diff = Math.round((kosten - eigen - andere - beantragt) * 100) / 100;
            info = 'Finanzierung: Kosten ' + eur(kosten) + ' = Eigenmittel ' + eur(eigen) + ' + andere ' + eur(andere) + ' + SPORTUNION ' + eur(beantragt) +
                (diff === 0 ? ' ✓ ausgeglichen' : (diff > 0 ? ' → ' + eur(diff) + ' ungedeckt' : ' → ' + eur(-diff) + ' überfinanziert'));
        }
        if (art.berechnung === 'rahmen' && beantragt > art.hoechst) info += (info ? '<br>' : '') + '<span style="color: var(--danger);">Über dem Höchstbetrag von ' + eur(art.hoechst) + '!</span>';
        if (art.bereich !== 'land' && beantragt >= FP_AB) info += (info ? '<br>' : '') + 'Ab ' + eur(FP_AB) + ' ist ein Finanzierungsplan Pflicht – wird im PDF mitgedruckt.';
        document.getElementById('su-finanzierung').innerHTML = info;
    }

    function zeileBinden(tr) {
        tr.querySelectorAll('.su-calc').forEach(el => el.addEventListener('input', berechne));
        tr.querySelector('.su-zeile-weg').addEventListener('click', () => {
            const tbody = document.getElementById('su-kosten-zeilen');
            if (tbody.rows.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(i => i.value = i.classList.contains('su-menge') ? 1 : '');
            berechne();
        });
    }

    document.getElementById('su-zeile-neu').addEventListener('click', () => {
        const tbody = document.getElementById('su-kosten-zeilen');
        const neu = tbody.rows[0].cloneNode(true);
        neu.querySelectorAll('input').forEach(i => i.value = i.classList.contains('su-menge') ? 1 : '');
        tbody.appendChild(neu);
        zeileBinden(neu);
        neu.querySelector('input').focus();
        berechne();
    });

    document.querySelectorAll('#su-kosten-zeilen tr').forEach(zeileBinden);
    form.querySelectorAll('.su-calc').forEach(el => { if (!el.closest('#su-kosten-zeilen')) el.addEventListener('input', berechne); });
    form.querySelectorAll('input[type=checkbox].su-calc, select.su-calc').forEach(el => el.addEventListener('change', berechne));
    artSel.addEventListener('change', zeigeArt);
    zeigeArt();
})();
</script>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
