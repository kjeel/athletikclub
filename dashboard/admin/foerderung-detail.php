<?php
/**
 * Athletikclub Steiermark – Admin: Förderungsdetail
 * Fördergeber → Programm → Antrag → Projekt → Budget → Kosten → Belege → Verwendungsnachweis.
 * Kosten kommen automatisch aus freigegebenen Trainerabrechnungen (Projekt mit dieser
 * Standard-Förderung) oder werden hier direkt mit Beleg erfasst; Restbudget automatisch.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireDarf('foerderungen.anzeigen');

$db  = getDB();
$user = getCurrentUser();
$foerderung_id = (int)($_GET['id'] ?? 0);
$bearbeiten = darf('foerderungen.bearbeiten');
$finanzen   = $bearbeiten || darf('finanzen.bearbeiten');
$self = APP_URL . '/dashboard/admin/foerderung-detail.php?id=' . $foerderung_id;

$stmt = $db->prepare('SELECT * FROM foerderungen WHERE id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([$foerderung_id, currentOrgId()]);
$foerderung = $stmt->fetch();

if (!$foerderung) {
    flashMessage('error', 'Förderung nicht gefunden.');
    redirect(APP_URL . '/dashboard/admin/foerderungen.php');
}

$status_map = FOERDER_STATUS;

$kat_labels = [
    'foerderansuchen'     => 'Förderansuchen',
    'foerderbescheid'     => 'Bescheid',
    'verwendungsnachweis' => 'Verwendungsnachweis',
    'beleg'               => 'Beleg / Rechnung',
    'sonstiges'           => 'Sonstiges',
];
$kosten_kategorien = array_diff_key(BUCHUNG_KATEGORIEN, array_flip(['kursbeitrag', 'foerderung', 'sponsoring', 'spende', 'mitgliedsbeitrag']));

$errors  = [];
$success = '';

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $braucht_finanzen = in_array($action, ['kosten_neu', 'kosten_loeschen', 'auszahlung'], true);
    if (!($braucht_finanzen ? $finanzen : $bearbeiten)) {
        flashMessage('error', 'Keine Berechtigung für diese Aktion.');
        redirect($self);
    }

    if ($action === 'update_details') {
        $titel             = trim($_POST['titel'] ?? '');
        $foerderstelle     = trim($_POST['foerderstelle'] ?? '');
        $foerderprogramm   = trim($_POST['foerderprogramm'] ?? '');
        $beschreibung      = trim($_POST['beschreibung'] ?? '');
        $betrag_beantragt  = trim($_POST['betrag_beantragt'] ?? '');
        $betrag_bewilligt  = trim($_POST['betrag_bewilligt'] ?? '');
        $betrag_ausbezahlt = trim($_POST['betrag_ausbezahlt'] ?? '');
        $einreichfrist     = trim($_POST['einreichfrist'] ?? '');
        $bewilligungsdatum = trim($_POST['bewilligungsdatum'] ?? '');
        $nachweisfrist     = trim($_POST['nachweisfrist'] ?? '');
        $abrechnungsfrist  = trim($_POST['abrechnungsfrist'] ?? '');
        $ausbezahlt_am     = trim($_POST['ausbezahlt_am'] ?? '');
        $projekt_id        = (int)($_POST['projekt_id'] ?? 0);
        $ansprechpartner_name    = trim($_POST['ansprechpartner_name'] ?? '');
        $ansprechpartner_email   = trim($_POST['ansprechpartner_email'] ?? '');
        $ansprechpartner_telefon = trim($_POST['ansprechpartner_telefon'] ?? '');
        $notizen           = trim($_POST['notizen'] ?? '');

        if (mb_strlen($titel) < 3) $errors['titel'] = 'Titel muss mindestens 3 Zeichen haben.';
        if (mb_strlen($foerderstelle) < 2) $errors['foerderstelle'] = 'Förderstelle ist Pflichtfeld.';
        foreach (['betrag_beantragt' => $betrag_beantragt, 'betrag_bewilligt' => $betrag_bewilligt, 'betrag_ausbezahlt' => $betrag_ausbezahlt] as $feld => $wert) {
            if ($wert !== '' && (!is_numeric($wert) || (float)$wert < 0)) $errors[$feld] = 'Bitte einen gültigen Betrag eingeben.';
        }
        if ($ansprechpartner_email !== '' && !filter_var($ansprechpartner_email, FILTER_VALIDATE_EMAIL)) {
            $errors['ansprechpartner_email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }
        $datum = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        if ($projekt_id && !in_array($projekt_id, array_map(fn($p) => (int)$p['id'], plattformProjekte($db, false)), true)) $projekt_id = 0;

        if (empty($errors)) {
            $neu = [
                'titel' => $titel, 'foerderstelle' => $foerderstelle, 'foerderprogramm' => mb_substr($foerderprogramm, 0, 200) ?: null, 'beschreibung' => $beschreibung ?: null,
                'betrag_beantragt'  => $betrag_beantragt !== '' ? moneyRound($betrag_beantragt) : null,
                'betrag_bewilligt'  => $betrag_bewilligt !== '' ? moneyRound($betrag_bewilligt) : null,
                'betrag_ausbezahlt' => $betrag_ausbezahlt !== '' ? moneyRound($betrag_ausbezahlt) : null,
                'einreichfrist' => $datum($einreichfrist), 'bewilligungsdatum' => $datum($bewilligungsdatum), 'nachweisfrist' => $datum($nachweisfrist),
                'abrechnungsfrist' => $datum($abrechnungsfrist), 'ausbezahlt_am' => $datum($ausbezahlt_am), 'projekt_id' => $projekt_id ?: null,
                'ansprechpartner_name' => $ansprechpartner_name ?: null, 'ansprechpartner_email' => $ansprechpartner_email ?: null,
                'ansprechpartner_telefon' => $ansprechpartner_telefon ?: null, 'notizen' => $notizen ?: null,
            ];
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($neu)));
            $db->prepare("UPDATE foerderungen SET $sets WHERE id = ?")->execute([...array_values($neu), $foerderung_id]);
            // Projekt ohne Standard-Förderung → diese Förderung als Standard setzen (Kosten fließen automatisch zu)
            if ($neu['projekt_id']) {
                $db->prepare('UPDATE projekte SET foerderung_id = ? WHERE id = ? AND foerderung_id IS NULL')->execute([$foerderung_id, $neu['projekt_id']]);
            }
            logActivity('foerderung_aktualisiert', "Förderung-ID: {$foerderung_id}");
            auditLog('geaendert', 'foerderungen', $foerderung_id, $foerderung, $neu, $titel);
            flashMessage('success', 'Förderung aktualisiert.');
            redirect($self);
        }
    }

    if ($action === 'status_aendern') {
        $neuer_status = $_POST['status'] ?? '';
        if (isset($status_map[$neuer_status]) && $neuer_status !== $foerderung['status']) {
            $extra = [];
            if ($neuer_status === 'ausbezahlt' && empty($foerderung['ausbezahlt_am'])) $extra['ausbezahlt_am'] = date('Y-m-d');
            if ($neuer_status === 'bewilligt' && empty($foerderung['bewilligungsdatum'])) $extra['bewilligungsdatum'] = date('Y-m-d');
            $sets = implode('', array_map(fn($k) => ", $k = ?", array_keys($extra)));
            $db->prepare("UPDATE foerderungen SET status = ?{$sets} WHERE id = ?")->execute([$neuer_status, ...array_values($extra), $foerderung_id]);
            logActivity('foerderung_status_geaendert', "Förderung-ID: {$foerderung_id} -> {$neuer_status}");
            auditLog('status', 'foerderungen', $foerderung_id, ['status' => $foerderung['status']], ['status' => $neuer_status] + $extra, $foerderung['titel']);
            // Projektleitung informieren
            if (!empty($foerderung['projekt_id'])) {
                $stmt = $db->prepare('SELECT leitung_id FROM projekte WHERE id = ?');
                $stmt->execute([$foerderung['projekt_id']]);
                $lid = (int)$stmt->fetchColumn();
                if ($lid && $lid !== (int)$user['id']) {
                    benachrichtigen($lid, 'foerderung', 'Förderung „' . $foerderung['titel'] . '“: ' . $status_map[$neuer_status]['label'], null, '/dashboard/projekt.php?id=' . (int)$foerderung['projekt_id'] . '&tab=foerderungen');
                }
            }
            flashMessage('success', 'Status aktualisiert.');
        }
        redirect($self);
    }

    if ($action === 'upload_dokument') {
        $titel     = trim($_POST['dok_titel'] ?? '');
        $kategorie = $_POST['dok_kategorie'] ?? 'sonstiges';
        if (!isset($kat_labels[$kategorie])) $kategorie = 'sonstiges';
        if (empty($titel)) $errors['dok_titel'] = 'Titel ist Pflichtfeld.';

        if (empty($errors)) {
            // Prüft Dateisignatur + MIME-Typ serverseitig und vergibt einen zufälligen Dateinamen
            $r = plattformPdfUpload($db, $_FILES['pdf_file'] ?? [], $titel, $kategorie, ['foerderung_id' => $foerderung_id], 'admin');
            if (isset($r['fehler'])) {
                $errors['dok_titel'] = $r['fehler'];
            } else {
                logActivity('foerderung_dokument_upload', "Förderung-ID: {$foerderung_id}");
                flashMessage('success', 'Dokument hochgeladen.');
                redirect($self);
            }
        }
    }

    if ($action === 'delete_dokument') {
        $dok_id = (int)($_POST['dok_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM dokumente WHERE id = ? AND foerderung_id = ?');
        $stmt->execute([$dok_id, $foerderung_id]);
        $dok = $stmt->fetch();
        if ($dok) {
            $file = UPLOAD_PATH . '/' . $dok['datei_pfad'];
            if (file_exists($file)) unlink($file);
            $db->prepare('DELETE FROM dokumente WHERE id = ?')->execute([$dok_id]);
            logActivity('foerderung_dokument_geloescht', "Förderung-ID: {$foerderung_id}, Dok-ID: {$dok_id}");
            auditLog('geloescht', 'dokumente', $dok_id, ['titel' => $dok['titel'], 'foerderung_id' => $foerderung_id], null, $dok['titel']);
            flashMessage('success', 'Dokument gelöscht.');
        }
        redirect($self);
    }

    if ($action === 'kosten_neu') {
        $betrag = trim(str_replace(',', '.', $_POST['betrag'] ?? ''));
        $datum  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['datum'] ?? '') ? $_POST['datum'] : date('Y-m-d');
        $beschreibung = mb_substr(trim($_POST['beschreibung'] ?? ''), 0, 255);
        $kategorie = isset($kosten_kategorien[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : 'sonstiges';
        if (!is_numeric($betrag) || (float)$betrag <= 0 || $beschreibung === '') {
            flashMessage('error', 'Bitte Beschreibung und einen Betrag größer 0 angeben.');
            redirect($self . '#kosten');
        }
        $werte = ['organization_id' => currentOrgId(), 'datum' => $datum, 'art' => 'ausgabe', 'betrag' => moneyRound($betrag), 'kategorie' => $kategorie,
                  'beschreibung' => $beschreibung, 'projekt_id' => $foerderung['projekt_id'] ?? null, 'foerderung_id' => $foerderung_id,
                  'belegnummer' => mb_substr(trim($_POST['belegnummer'] ?? ''), 0, 60) ?: null, 'status' => ($_POST['status'] ?? '') === 'offen' ? 'offen' : 'bezahlt',
                  'erstellt_von' => (int)$user['id']];
        $db->prepare('INSERT INTO buchungen (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')->execute(array_values($werte));
        $bid = (int)$db->lastInsertId();
        auditLog('erstellt', 'buchungen', $bid, null, $werte, $beschreibung);
        $meldung = 'Kosten erfasst.';
        $fehler = false;
        if (!empty($_FILES['beleg']['name'])) {
            $r = plattformPdfUpload($db, $_FILES['beleg'], 'Beleg ' . ($werte['belegnummer'] ?? '') . ': ' . $beschreibung, 'beleg', ['buchung_id' => $bid, 'foerderung_id' => $foerderung_id, 'projekt_id' => $werte['projekt_id']], 'admin');
            $fehler = isset($r['fehler']);
            $meldung = $fehler ? 'Kosten erfasst, Beleg nicht hochgeladen: ' . $r['fehler'] : 'Kosten mit Beleg erfasst.';
        }
        $b = foerderBudget($db, $foerderung);
        if (bccomp($b['bewilligt'], '0', 2) > 0 && bccomp($b['rest'], '0', 2) < 0) {
            $meldung .= ' Achtung: Das bewilligte Budget ist um ' . moneyFormat(bcmul($b['rest'], '-1', 2)) . ' überschritten.';
            $fehler = true;
        }
        flashMessage($fehler ? 'error' : 'success', $meldung);
        redirect($self . '#kosten');
    }

    if ($action === 'kosten_loeschen') {
        $stmt = $db->prepare('SELECT * FROM buchungen WHERE id = ? AND foerderung_id = ?');
        $stmt->execute([(int)($_POST['buchung_id'] ?? 0), $foerderung_id]);
        $b = $stmt->fetch();
        if ($b && $b['trainer_abrechnung_id']) {
            flashMessage('error', 'Diese Kosten stammen aus einer Trainerabrechnung und werden dort verwaltet.');
        } elseif ($b) {
            $db->prepare('UPDATE dokumente SET buchung_id = NULL WHERE buchung_id = ?')->execute([$b['id']]);
            $db->prepare('DELETE FROM buchungen WHERE id = ?')->execute([$b['id']]);
            if ($b['art'] === 'einnahme' && $b['kategorie'] === 'foerderung') {
                $neu_ausbezahlt = bcsub(moneyRound($foerderung['betrag_ausbezahlt'] ?? 0), moneyRound($b['betrag']), 2);
                if (bccomp($neu_ausbezahlt, '0', 2) < 0) $neu_ausbezahlt = '0.00';
                $db->prepare('UPDATE foerderungen SET betrag_ausbezahlt = ? WHERE id = ?')->execute([$neu_ausbezahlt, $foerderung_id]);
            }
            auditLog('geloescht', 'buchungen', (int)$b['id'], $b, null, $b['beschreibung']);
            flashMessage('success', 'Buchung entfernt (ein Beleg-PDF bleibt bei den Dokumenten erhalten).');
        }
        redirect($self . '#kosten');
    }

    if ($action === 'auszahlung') {
        $betrag = trim(str_replace(',', '.', $_POST['betrag'] ?? ''));
        $datum  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['datum'] ?? '') ? $_POST['datum'] : date('Y-m-d');
        if (!is_numeric($betrag) || (float)$betrag <= 0) { flashMessage('error', 'Bitte einen Betrag größer 0 angeben.'); redirect($self . '#budget'); }
        $werte = ['organization_id' => currentOrgId(), 'datum' => $datum, 'art' => 'einnahme', 'betrag' => moneyRound($betrag), 'kategorie' => 'foerderung',
                  'beschreibung' => mb_substr('Auszahlung ' . $foerderung['foerderstelle'] . ': ' . $foerderung['titel'], 0, 255), 'projekt_id' => $foerderung['projekt_id'] ?? null,
                  'foerderung_id' => $foerderung_id, 'status' => 'bezahlt', 'erstellt_von' => (int)$user['id']];
        $db->prepare('INSERT INTO buchungen (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')->execute(array_values($werte));
        $neu_ausbezahlt = bcadd(moneyRound($foerderung['betrag_ausbezahlt'] ?? 0), $werte['betrag'], 2);
        $db->prepare('UPDATE foerderungen SET betrag_ausbezahlt = ?, ausbezahlt_am = ? WHERE id = ?')->execute([$neu_ausbezahlt, $datum, $foerderung_id]);
        auditLog('geaendert', 'foerderungen', $foerderung_id, ['betrag_ausbezahlt' => $foerderung['betrag_ausbezahlt']], ['betrag_ausbezahlt' => $neu_ausbezahlt, 'ausbezahlt_am' => $datum], $foerderung['titel']);
        flashMessage('success', 'Auszahlung über ' . moneyFormat($werte['betrag']) . ' verbucht.');
        redirect($self . '#budget');
    }

    if ($action === 'delete_foerderung') {
        $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM buchungen WHERE foerderung_id = ?) + (SELECT COUNT(*) FROM dokumente WHERE foerderung_id = ?)');
        $stmt->execute([$foerderung_id, $foerderung_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            flashMessage('error', 'Die Förderung hat verbuchte Kosten/Einnahmen oder Dokumente und kann nicht gelöscht werden – bitte stattdessen auf „Abgeschlossen“ bzw. „Abgelehnt“ setzen.');
            redirect($self);
        }
        $db->prepare('DELETE FROM foerderungen WHERE id = ?')->execute([$foerderung_id]);
        logActivity('foerderung_geloescht', "Förderung-ID: {$foerderung_id}, Titel: {$foerderung['titel']}");
        auditLog('geloescht', 'foerderungen', $foerderung_id, $foerderung, null, $foerderung['titel']);
        flashMessage('success', 'Förderung gelöscht.');
        redirect(APP_URL . '/dashboard/admin/foerderungen.php');
    }

    // Bei Fehlern: aktuelle Werte für erneute Anzeige übernehmen
    $foerderung = array_merge($foerderung, $_POST);
}

// ----------------------------------------------------------------
// Daten laden: Dokumente, Budget, Kosten, Projekte
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM dokumente WHERE foerderung_id = ? AND buchung_id IS NULL ORDER BY created_at DESC');
$stmt->execute([$foerderung_id]);
$dokumente = $stmt->fetchAll();

$budget = foerderBudget($db, $foerderung);
$stmt = $db->prepare('SELECT b.*, p.name AS projekt_name, (SELECT MIN(d.id) FROM dokumente d WHERE d.buchung_id = b.id) AS beleg_id
                      FROM buchungen b LEFT JOIN projekte p ON p.id = b.projekt_id WHERE b.foerderung_id = ? ORDER BY b.datum DESC, b.id DESC');
$stmt->execute([$foerderung_id]);
$buchungen = $stmt->fetchAll();
$kosten_je_kat = [];
foreach ($buchungen as $b) {
    if ($b['art'] === 'ausgabe') $kosten_je_kat[$b['kategorie']] = bcadd($kosten_je_kat[$b['kategorie']] ?? '0.00', moneyRound($b['betrag']), 2);
}
uasort($kosten_je_kat, fn($a, $b) => bccomp($b, $a, 2));

$stmt = $db->prepare('SELECT id, name, status FROM projekte WHERE organization_id = ? AND (foerderung_id = ? OR id = ?) ORDER BY name');
$stmt->execute([currentOrgId(), $foerderung_id, (int)($foerderung['projekt_id'] ?? 0)]);
$projekte_verknuepft = $stmt->fetchAll();

$page_title = $foerderung['titel'];
$breadcrumb = 'Fördermanagement';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$s = $status_map[$foerderung['status']] ?? ['label' => $foerderung['status'], 'class' => 'badge-gray'];
$frist = fn($d) => $d ? date('d.m.Y', strtotime($d)) . ' <span style="color: var(--text-muted); font-size: 0.75rem;">(' . e(plattformFrist($d)) . ')</span>' : '–';
$ueberzogen = bccomp($budget['rest'], '0', 2) < 0;
?>

<style>
.fd-balken { height: 10px; border-radius: 5px; background: var(--bg-muted); overflow: hidden; margin: 0.5rem 0 0.25rem; }
.fd-balken span { display: block; height: 100%; background: var(--gold-accent); }
.fd-balken.ueber span { background: #EF4444; }
.fd-mini { font-size: 0.78rem; color: var(--text-muted); }
.fd-inline { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.fd-inline .form-group { margin: 0; }
.fd-zahl { text-align: right; white-space: nowrap; }
</style>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/admin/foerderungen.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Zurück zum Fördermanagement
        </a>
        <h1 class="dashboard-title"><?= e($foerderung['titel']) ?></h1>
        <p class="dashboard-subtitle"><?= e($foerderung['foerderstelle']) ?><?= !empty($foerderung['foerderprogramm']) ? ' · ' . e($foerderung['foerderprogramm']) : '' ?></p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <a href="<?= APP_URL ?>/dashboard/admin/foerderung-nachweis.php?id=<?= $foerderung_id ?>" class="btn btn-navy btn-sm" target="_blank" rel="noopener">Verwendungsnachweis (PDF)</a>
        <a href="<?= APP_URL ?>/dashboard/admin/foerderung-nachweis.php?id=<?= $foerderung_id ?>&format=csv" class="btn btn-ghost-light btn-sm">Kostenliste (CSV)</a>
        <span class="badge <?= $s['class'] ?>" style="font-size: 0.8rem;"><?= e($s['label']) ?></span>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<!-- Budget -->
<div class="kpi-grid" id="budget">
    <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value"><?= moneyFormat($budget['bewilligt']) ?></div><div class="kpi-label">Bewilligt<?= $foerderung['betrag_beantragt'] !== null ? ' · beantragt ' . moneyFormat($foerderung['betrag_beantragt']) : '' ?></div></div>
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value"><?= moneyFormat($budget['verbraucht']) ?></div><div class="kpi-label">Verbraucht (<?= $budget['anzahl'] ?> Kostenposten)</div></div>
    <div class="kpi-card" style="--kpi-color: <?= $ueberzogen ? '#EF4444' : '#22C55E' ?>;"><div class="kpi-value"><?= moneyFormat($budget['rest']) ?></div><div class="kpi-label"><?= $ueberzogen ? 'Budget überschritten' : 'Restbudget' ?></div></div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;"><div class="kpi-value"><?= moneyFormat($budget['ausbezahlt']) ?></div><div class="kpi-label">Ausbezahlt erhalten<?= $foerderung['ausbezahlt_am'] ? ' · zuletzt ' . date('d.m.Y', strtotime($foerderung['ausbezahlt_am'])) : '' ?></div></div>
</div>
<?php if (bccomp($budget['bewilligt'], '0', 2) > 0): ?>
<div class="fd-balken <?= $ueberzogen ? 'ueber' : '' ?>" aria-label="Budgetverbrauch"><span style="width: <?= min(100, $budget['quote']) ?>%"></span></div>
<p class="fd-mini" style="margin-bottom: 1.5rem;"><?= number_format($budget['quote'], 1, ',', '.') ?> % des bewilligten Budgets verbraucht<?= bccomp($budget['offen'], '0', 2) > 0 ? ' · davon ' . moneyFormat($budget['offen']) . ' noch nicht bezahlt' : '' ?></p>
<?php else: ?>
<p class="fd-mini" style="margin: 0.5rem 0 1.5rem;">Sobald ein bewilligter Betrag eingetragen ist, wird das Restbudget automatisch berechnet.</p>
<?php endif; ?>

<div class="grid-2" style="display: grid; gap: 1.5rem; align-items: start; margin-bottom: 1.5rem;">

    <!-- Status & Verwaltung -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Status</h2>
        </div>
        <div style="padding: 1.25rem;">
            <?php if ($bearbeiten): ?>
            <form method="POST" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status_aendern">
                <select name="status" class="form-control" style="flex: 1; min-width: 160px;">
                    <?php foreach ($status_map as $val => $info): ?>
                        <option value="<?= $val ?>" <?= $foerderung['status'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
            </form>
            <p class="form-hint">Ablauf: Vorbereitung → Eingereicht → In Prüfung → Bewilligt → In Umsetzung → Abgerechnet → Abgeschlossen. Bei „Bewilligt“ bzw. „Ausbezahlt“ wird das Datum automatisch gesetzt, falls leer.</p>
            <?php else: ?>
            <p><span class="badge <?= $s['class'] ?>"><?= e($s['label']) ?></span></p>
            <?php endif; ?>

            <?php if ($projekte_verknuepft): ?>
            <div style="margin-top: 1.25rem; font-size: 0.875rem;"><strong>Projekte</strong>
                <?php foreach ($projekte_verknuepft as $p): ?><br><a href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= $p['id'] ?>&tab=finanzen"><?= e($p['name']) ?></a> <span class="badge <?= PROJEKT_STATUS[$p['status']]['class'] ?? 'badge-gray' ?>"><?= e(PROJEKT_STATUS[$p['status']]['label'] ?? $p['status']) ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($bearbeiten): ?>
            <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border-light);">
                <form method="POST" onsubmit="return confirm(<?= e(json_encode('Förderung „' . $foerderung['titel'] . '“ wirklich endgültig löschen?', JSON_UNESCAPED_UNICODE)) ?>)">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_foerderung">
                    <button type="submit" class="btn btn-ghost-light btn-sm w-full" style="color: var(--danger);">Förderung löschen</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Eckdaten -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Eckdaten &amp; Fristen</h2>
        </div>
        <div style="padding: 1.25rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.875rem;">
            <div><strong>Fördergeber</strong><br><?= e($foerderung['foerderstelle']) ?></div>
            <div><strong>Programm</strong><br><?= e($foerderung['foerderprogramm'] ?? '') ?: '–' ?></div>
            <div><strong>Einreichfrist</strong><br><?= $frist($foerderung['einreichfrist']) ?></div>
            <div><strong>Bewilligungsdatum</strong><br><?= $foerderung['bewilligungsdatum'] ? date('d.m.Y', strtotime($foerderung['bewilligungsdatum'])) : '–' ?></div>
            <div><strong>Verwendungsnachweis</strong><br><?= $frist($foerderung['nachweisfrist']) ?></div>
            <div><strong>Abrechnungsfrist</strong><br><?= $frist($foerderung['abrechnungsfrist'] ?? null) ?></div>
            <div><strong>Ausbezahlt am</strong><br><?= $foerderung['ausbezahlt_am'] ? date('d.m.Y', strtotime($foerderung['ausbezahlt_am'])) : '–' ?></div>
            <?php if ($foerderung['ansprechpartner_name']): ?><div><strong>Ansprechpartner:in</strong><br><?= e($foerderung['ansprechpartner_name']) ?></div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Kosten & Belege -->
<div class="table-card" id="kosten" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Kosten, Belege &amp; Auszahlungen</h2>
    </div>
    <?php if ($kosten_je_kat): ?>
    <div style="padding: 1rem 1.25rem 0; display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <?php foreach ($kosten_je_kat as $kat => $summe): ?><span class="badge badge-navy"><?= e(BUCHUNG_KATEGORIEN[$kat] ?? $kat) ?>: <?= moneyFormat($summe) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (!$buchungen): ?>
        <div class="empty-state" style="padding: 1.5rem 1rem;"><p>Noch keine Kosten. Freigegebene Trainerabrechnungen von Projekten mit dieser Förderung werden automatisch hier verbucht.</p></div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Datum</th><th>Beschreibung</th><th>Kategorie</th><th>Beleg-Nr.</th><th class="fd-zahl">Betrag</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($buchungen as $b): $einnahme = $b['art'] === 'einnahme'; ?>
                <tr>
                    <td><?= date('d.m.Y', strtotime($b['datum'])) ?></td>
                    <td><?= e($b['beschreibung']) ?>
                        <div class="fd-mini"><?= $b['trainer_abrechnung_id'] ? 'aus Trainerabrechnung' : '' ?><?= $b['projekt_name'] ? ($b['trainer_abrechnung_id'] ? ' · ' : '') . e($b['projekt_name']) : '' ?><?= $b['status'] === 'offen' ? ' <span class="badge badge-warning">offen</span>' : '' ?></div></td>
                    <td><?= e(BUCHUNG_KATEGORIEN[$b['kategorie']] ?? $b['kategorie']) ?></td>
                    <td><?= e($b['belegnummer'] ?? '') ?><?php if ($b['beleg_id']): ?> <a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= (int)$b['beleg_id'] ?>" title="Beleg öffnen">📄</a><?php endif; ?></td>
                    <td class="fd-zahl" style="color: <?= $einnahme ? '#15803D' : 'inherit' ?>;"><?= $einnahme ? '+ ' : '' ?><?= moneyFormat($b['betrag']) ?></td>
                    <td><?php if ($finanzen && !$b['trainer_abrechnung_id']): ?><form method="POST" onsubmit="return confirm('Buchung entfernen?');"><?= csrfField() ?><input type="hidden" name="action" value="kosten_loeschen"><input type="hidden" name="buchung_id" value="<?= $b['id'] ?>"><button class="btn btn-ghost-light btn-sm" aria-label="Entfernen">✕</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($finanzen): ?>
    <form method="POST" enctype="multipart/form-data" class="fd-inline"><?= csrfField() ?><input type="hidden" name="action" value="kosten_neu">
        <div class="form-group"><label class="form-label">Datum</label><input class="form-control" type="date" name="datum" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group" style="flex: 1; min-width: 200px;"><label class="form-label">Beschreibung</label><input class="form-control" name="beschreibung" maxlength="255" required placeholder="z.B. 10 Helme Kindergröße"></div>
        <div class="form-group"><label class="form-label">Kategorie</label><select class="form-control" name="kategorie"><?php foreach ($kosten_kategorien as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'material' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Betrag (€)</label><input class="form-control" name="betrag" inputmode="decimal" required style="width: 110px;"></div>
        <div class="form-group"><label class="form-label">Beleg-Nr.</label><input class="form-control" name="belegnummer" maxlength="60" style="width: 120px;"></div>
        <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status"><option value="bezahlt">bezahlt</option><option value="offen">offen</option></select></div>
        <div class="form-group"><label class="form-label">Beleg (PDF)</label><input class="form-control" type="file" name="beleg" accept="application/pdf,.pdf"></div>
        <button type="submit" class="btn btn-navy btn-sm">Kosten erfassen</button>
    </form>
    <form method="POST" class="fd-inline"><?= csrfField() ?><input type="hidden" name="action" value="auszahlung">
        <div class="form-group"><label class="form-label">Auszahlung erhalten am</label><input class="form-control" type="date" name="datum" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label class="form-label">Betrag (€)</label><input class="form-control" name="betrag" inputmode="decimal" required style="width: 130px;"></div>
        <button type="submit" class="btn btn-ghost-light btn-sm">Auszahlung verbuchen</button>
        <span class="fd-mini">Wird als Einnahme gebucht und zum ausbezahlten Betrag addiert.</span>
    </form>
    <?php endif; ?>
</div>

<?php if ($bearbeiten): ?>
<!-- Bearbeiten -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Details bearbeiten</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="" data-validate novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_details">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="titel">Titel <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" id="titel" name="titel" value="<?= e($foerderung['titel']) ?>" required>
                    <?php if (isset($errors['titel'])): ?><span class="form-error"><?= e($errors['titel']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="foerderstelle">Förderstelle / Fördergeber <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['foerderstelle']) ? 'error' : '' ?>" type="text" id="foerderstelle" name="foerderstelle" value="<?= e($foerderung['foerderstelle']) ?>" required>
                    <?php if (isset($errors['foerderstelle'])): ?><span class="form-error"><?= e($errors['foerderstelle']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="foerderprogramm">Förderprogramm</label>
                    <input class="form-control" type="text" id="foerderprogramm" name="foerderprogramm" maxlength="200" value="<?= e($foerderung['foerderprogramm'] ?? '') ?>" placeholder="z.B. Bewegungsland Steiermark, Fit Sport Austria">
                </div>
                <div class="form-group">
                    <label class="form-label" for="projekt_id">Projekt</label>
                    <select class="form-control" id="projekt_id" name="projekt_id">
                        <option value="">– kein Projekt –</option>
                        <?php foreach (plattformProjekte($db, false) as $p): ?><option value="<?= $p['id'] ?>" <?= (int)($foerderung['projekt_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                    </select>
                    <span class="form-hint">Hat das Projekt noch keine Förderung, wird diese als Standard gesetzt – Trainerkosten fließen dann automatisch zu.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="beschreibung">Beschreibung</label>
                <textarea class="form-control" id="beschreibung" name="beschreibung" rows="3"><?= e($foerderung['beschreibung'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="betrag_beantragt">Beantragter Betrag (€)</label>
                    <input class="form-control <?= isset($errors['betrag_beantragt']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="betrag_beantragt" name="betrag_beantragt" value="<?= e($foerderung['betrag_beantragt'] ?? '') ?>">
                    <?php if (isset($errors['betrag_beantragt'])): ?><span class="form-error"><?= e($errors['betrag_beantragt']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="betrag_bewilligt">Bewilligter Betrag (€)</label>
                    <input class="form-control <?= isset($errors['betrag_bewilligt']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="betrag_bewilligt" name="betrag_bewilligt" value="<?= e($foerderung['betrag_bewilligt'] ?? '') ?>">
                    <?php if (isset($errors['betrag_bewilligt'])): ?><span class="form-error"><?= e($errors['betrag_bewilligt']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="betrag_ausbezahlt">Ausbezahlt (€)</label>
                    <input class="form-control <?= isset($errors['betrag_ausbezahlt']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="betrag_ausbezahlt" name="betrag_ausbezahlt" value="<?= e($foerderung['betrag_ausbezahlt'] ?? '') ?>">
                    <?php if (isset($errors['betrag_ausbezahlt'])): ?><span class="form-error"><?= e($errors['betrag_ausbezahlt']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="einreichfrist">Einreichfrist</label>
                    <input class="form-control" type="date" id="einreichfrist" name="einreichfrist" value="<?= e($foerderung['einreichfrist'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="bewilligungsdatum">Bewilligungsdatum</label>
                    <input class="form-control" type="date" id="bewilligungsdatum" name="bewilligungsdatum" value="<?= e($foerderung['bewilligungsdatum'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="nachweisfrist">Frist Verwendungsnachweis</label>
                    <input class="form-control" type="date" id="nachweisfrist" name="nachweisfrist" value="<?= e($foerderung['nachweisfrist'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="abrechnungsfrist">Abrechnungsfrist</label>
                    <input class="form-control" type="date" id="abrechnungsfrist" name="abrechnungsfrist" value="<?= e($foerderung['abrechnungsfrist'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="ausbezahlt_am">Ausbezahlt am</label>
                    <input class="form-control" type="date" id="ausbezahlt_am" name="ausbezahlt_am" value="<?= e($foerderung['ausbezahlt_am'] ?? '') ?>">
                </div>
            </div>

            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin: 1.5rem 0 1rem;">Ansprechpartner</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="ansprechpartner_name">Name</label>
                    <input class="form-control" type="text" id="ansprechpartner_name" name="ansprechpartner_name" value="<?= e($foerderung['ansprechpartner_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="ansprechpartner_telefon">Telefon</label>
                    <input class="form-control" type="text" id="ansprechpartner_telefon" name="ansprechpartner_telefon" value="<?= e($foerderung['ansprechpartner_telefon'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="ansprechpartner_email">E-Mail</label>
                <input class="form-control <?= isset($errors['ansprechpartner_email']) ? 'error' : '' ?>" type="email" id="ansprechpartner_email" name="ansprechpartner_email" value="<?= e($foerderung['ansprechpartner_email'] ?? '') ?>">
                <?php if (isset($errors['ansprechpartner_email'])): ?><span class="form-error"><?= e($errors['ansprechpartner_email']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="notizen">Interne Notizen</label>
                <textarea class="form-control" id="notizen" name="notizen" rows="3" placeholder="Verlauf, Kommunikation, Besonderheiten…"><?= e($foerderung['notizen'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Änderungen speichern</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Dokumente -->
<div class="table-card">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="table-card-title">Dokumente (<?= count($dokumente) ?>)</h2>
        <?php if ($bearbeiten): ?>
        <button class="btn btn-primary btn-sm" data-modal-open="upload-modal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            PDF hochladen
        </button>
        <?php endif; ?>
    </div>
    <?php if (empty($dokumente)): ?>
        <div class="empty-state">
            <h3>Noch keine Dokumente</h3>
            <p>Lade Förderansuchen, Bescheid oder Verwendungsnachweis als PDF hoch. Belege zu Kosten werden oben direkt bei der Buchung abgelegt.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Kategorie</th>
                        <th>Hochgeladen am</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dokumente as $dok): ?>
                    <tr>
                        <td class="text-primary"><?= e($dok['titel']) ?></td>
                        <td><span class="badge badge-navy"><?= e($kat_labels[$dok['kategorie']] ?? $dok['kategorie']) ?></span></td>
                        <td><?= date('d.m.Y', strtotime($dok['created_at'])) ?></td>
                        <td style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                            <a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $dok['id'] ?>" class="btn btn-ghost-light btn-sm">Download</a>
                            <?php if ($bearbeiten): ?>
                            <form method="POST" onsubmit="return confirm('Dokument wirklich löschen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_dokument">
                                <input type="hidden" name="dok_id" value="<?= $dok['id'] ?>">
                                <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Löschen</button>
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

<?php if ($bearbeiten): ?>
<!-- Upload Modal -->
<div class="modal" id="upload-modal" style="
    position: fixed; inset: 0; z-index: 2000;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
    opacity: 0; visibility: hidden; transition: all 0.25s;
">
    <div class="modal-overlay" style="position: absolute; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);"></div>
    <div style="
        background: var(--surface);
        border-radius: 1.5rem;
        width: 100%;
        max-width: 520px;
        position: relative;
        z-index: 1;
        box-shadow: 0 25px 50px rgba(0,0,0,0.2);
        animation: authCardIn 0.3s ease;
    ">
        <div style="padding: 1.5rem 1.75rem; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center;">
            <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.1rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin: 0;">PDF hochladen</h2>
            <button data-modal-close style="font-size: 1.5rem; opacity: 0.5; line-height: 1;">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data" style="padding: 1.75rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_dokument">

            <div class="form-group">
                <label class="form-label">Titel <span class="required">*</span></label>
                <input class="form-control" type="text" name="dok_titel" required placeholder="z.B. Förderbescheid 2026">
            </div>

            <div class="form-group">
                <label class="form-label">Kategorie</label>
                <select class="form-control" name="dok_kategorie">
                    <?php foreach ($kat_labels as $val => $label): ?>
                        <option value="<?= $val ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">PDF-Datei <span class="required">*</span></label>
                <label class="upload-zone" for="pdf_file_input">
                    <div class="upload-zone-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    </div>
                    <h3>PDF hierher ziehen oder klicken</h3>
                    <p>Maximale Dateigröße: 10 MB</p>
                    <input type="file" name="pdf_file" id="pdf_file_input" accept=".pdf" required>
                </label>
                <p class="form-hint" id="pdf-filename"></p>
            </div>

            <button type="submit" class="btn btn-primary w-full">Hochladen</button>
        </form>
    </div>
</div>

<style>
.modal.active { opacity: 1 !important; visibility: visible !important; }
</style>

<script>
const pdfInput = document.getElementById('pdf_file_input');
if (pdfInput) {
    pdfInput.addEventListener('change', function() {
        const preview = document.getElementById('pdf-filename');
        if (preview && this.files[0]) {
            preview.textContent = '📄 ' + this.files[0].name;
        }
    });
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
