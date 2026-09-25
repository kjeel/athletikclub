<?php
/**
 * Athletikclub Steiermark – Finanzen
 * Jahresübersicht aus allen Modulen: Kursbeiträge (bezahlte Anmeldungen), Buchungen
 * (Trainerhonorare aus freigegebenen Abrechnungen, Förderkosten, manuelle Einnahmen/
 * Ausgaben), Fördermittel, offene Posten, Projektbudgets. Buchungsjournal mit Beleg-
 * Upload und CSV-Export.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireDarf('finanzen.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$darf_buchen = darf('finanzen.bearbeiten');
$jahr   = max(2020, min(2100, (int)($_GET['jahr'] ?? date('Y'))));
$self   = APP_URL . '/dashboard/admin/finanzen.php?jahr=' . $jahr;
$von    = "$jahr-01-01";
$bis    = "$jahr-12-31";
$monate = ['', 'Jän', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!$darf_buchen) { flashMessage('error', 'Keine Berechtigung.'); redirect($self); }
    $action = $_POST['action'] ?? '';

    if ($action === 'buchen') {
        $betrag = trim(str_replace(',', '.', $_POST['betrag'] ?? ''));
        $art = ($_POST['art'] ?? '') === 'einnahme' ? 'einnahme' : 'ausgabe';
        $beschreibung = mb_substr(trim($_POST['beschreibung'] ?? ''), 0, 255);
        if (!is_numeric($betrag) || (float)$betrag <= 0 || $beschreibung === '') {
            flashMessage('error', 'Bitte Beschreibung und einen Betrag größer 0 angeben.');
            redirect($self . '#journal');
        }
        $projekt_id = (int)($_POST['projekt_id'] ?? 0);
        $foerderung_id = (int)($_POST['foerderung_id'] ?? 0);
        // Nur existierende Datensätze der eigenen Organisation verknüpfen
        if ($projekt_id && !in_array($projekt_id, array_map(fn($p) => (int)$p['id'], plattformProjekte($db, false)), true)) $projekt_id = 0;
        if ($foerderung_id) {
            $stmt = $db->prepare('SELECT 1 FROM foerderungen WHERE id = ? AND organization_id = ?');
            $stmt->execute([$foerderung_id, $org_id]);
            if (!$stmt->fetchColumn()) $foerderung_id = 0;
        }
        $werte = ['organization_id' => $org_id, 'datum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['datum'] ?? '') ? $_POST['datum'] : date('Y-m-d'),
                  'art' => $art, 'betrag' => moneyRound($betrag), 'kategorie' => isset(BUCHUNG_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : 'sonstiges',
                  'beschreibung' => $beschreibung, 'projekt_id' => $projekt_id ?: null, 'foerderung_id' => $foerderung_id ?: null,
                  'belegnummer' => mb_substr(trim($_POST['belegnummer'] ?? ''), 0, 60) ?: null, 'status' => ($_POST['status'] ?? '') === 'offen' ? 'offen' : 'bezahlt',
                  'erstellt_von' => $me];
        $db->prepare('INSERT INTO buchungen (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')->execute(array_values($werte));
        $bid = (int)$db->lastInsertId();
        auditLog('erstellt', 'buchungen', $bid, null, $werte, $beschreibung);
        $meldung = ($art === 'einnahme' ? 'Einnahme' : 'Ausgabe') . ' gebucht.';
        $typ = 'success';
        if (!empty($_FILES['beleg']['name'])) {
            $r = plattformPdfUpload($db, $_FILES['beleg'], 'Beleg ' . ($werte['belegnummer'] ?? '') . ': ' . $beschreibung, 'beleg',
                                    ['buchung_id' => $bid, 'projekt_id' => $werte['projekt_id'], 'foerderung_id' => $werte['foerderung_id']], 'admin');
            if (isset($r['fehler'])) { $meldung .= ' Beleg nicht hochgeladen: ' . $r['fehler']; $typ = 'error'; }
        }
        flashMessage($typ, $meldung);
        redirect(APP_URL . '/dashboard/admin/finanzen.php?jahr=' . substr($werte['datum'], 0, 4) . '#journal');
    }

    if ($action === 'status') {
        $stmt = $db->prepare('SELECT * FROM buchungen WHERE id = ? AND organization_id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0), $org_id]);
        if ($b = $stmt->fetch()) {
            $neu = $b['status'] === 'offen' ? 'bezahlt' : 'offen';
            $db->prepare('UPDATE buchungen SET status = ? WHERE id = ?')->execute([$neu, $b['id']]);
            auditLog('status', 'buchungen', (int)$b['id'], ['status' => $b['status']], ['status' => $neu], $b['beschreibung']);
        }
        redirect($self . '#journal');
    }

    if ($action === 'loeschen') {
        $stmt = $db->prepare('SELECT * FROM buchungen WHERE id = ? AND organization_id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0), $org_id]);
        $b = $stmt->fetch();
        if ($b && $b['trainer_abrechnung_id']) {
            flashMessage('error', 'Diese Buchung stammt aus einer Trainerabrechnung und wird dort verwaltet.');
        } elseif ($b) {
            $db->prepare('UPDATE dokumente SET buchung_id = NULL WHERE buchung_id = ?')->execute([$b['id']]);
            $db->prepare('DELETE FROM buchungen WHERE id = ?')->execute([$b['id']]);
            auditLog('geloescht', 'buchungen', (int)$b['id'], $b, null, $b['beschreibung']);
            flashMessage('success', 'Buchung gelöscht.');
        }
        redirect($self . '#journal');
    }
    redirect($self);
}

// ----------------------------------------------------------------
// Daten
// ----------------------------------------------------------------
$leer_monate = array_fill(1, 12, '0.00');
$ein_monat = $aus_monat = $leer_monate;
$ein_kat = $aus_kat = [];
$plus = fn(&$arr, $k, $v) => $arr[$k] = bcadd($arr[$k] ?? '0.00', moneyRound($v), 2);

// Kursbeiträge: bezahlte Anmeldungen nach Zahlungsdatum
$kursbeitraege = '0.00';
try {
    $stmt = $db->prepare('SELECT k.preis, ka.bezahlt_am FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                          WHERE k.organization_id = ? AND ka.bezahlt = 1 AND ka.bezahlt_am BETWEEN ? AND ? AND k.preis > 0');
    $stmt->execute([$org_id, "$von 00:00:00", "$bis 23:59:59"]);
    foreach ($stmt->fetchAll() as $r) {
        $plus($ein_monat, (int)date('n', strtotime($r['bezahlt_am'])), $r['preis']);
        $plus($ein_kat, 'kursbeitrag', $r['preis']);
        $kursbeitraege = bcadd($kursbeitraege, moneyRound($r['preis']), 2);
    }
} catch (Exception $e) {}

// Buchungen des Jahres
$f_art = in_array($_GET['art'] ?? '', ['einnahme', 'ausgabe'], true) ? $_GET['art'] : '';
$f_kat = isset(BUCHUNG_KATEGORIEN[$_GET['kategorie'] ?? '']) ? $_GET['kategorie'] : '';
$f_projekt = (int)($_GET['projekt'] ?? 0);
$journal = [];
$offen_buchungen = '0.00';
try {
    $stmt = $db->prepare('SELECT b.*, p.name AS projekt_name, f.titel AS foerderung_titel, (SELECT MIN(d.id) FROM dokumente d WHERE d.buchung_id = b.id) AS beleg_id
                          FROM buchungen b LEFT JOIN projekte p ON p.id = b.projekt_id LEFT JOIN foerderungen f ON f.id = b.foerderung_id
                          WHERE b.organization_id = ? AND b.datum BETWEEN ? AND ? ORDER BY b.datum DESC, b.id DESC');
    $stmt->execute([$org_id, $von, $bis]);
    foreach ($stmt->fetchAll() as $b) {
        $m = (int)date('n', strtotime($b['datum']));
        if ($b['art'] === 'einnahme') { $plus($ein_monat, $m, $b['betrag']); $plus($ein_kat, $b['kategorie'], $b['betrag']); }
        else { $plus($aus_monat, $m, $b['betrag']); $plus($aus_kat, $b['kategorie'], $b['betrag']); }
        if ($b['status'] === 'offen' && $b['art'] === 'ausgabe') $offen_buchungen = bcadd($offen_buchungen, moneyRound($b['betrag']), 2);
        if ($f_art && $b['art'] !== $f_art) continue;
        if ($f_kat && $b['kategorie'] !== $f_kat) continue;
        if ($f_projekt && (int)$b['projekt_id'] !== $f_projekt) continue;
        $journal[] = $b;
    }
} catch (Exception $e) {}

$einnahmen = moneySum(array_values($ein_monat));
$ausgaben  = moneySum(array_values($aus_monat));
$saldo     = bcsub($einnahmen, $ausgaben, 2);
uasort($ein_kat, fn($a, $b) => bccomp($b, $a, 2));
uasort($aus_kat, fn($a, $b) => bccomp($b, $a, 2));

// Offene Posten
$offene_kursbeitraege = '0.00';
$offene_honorare = '0.00';
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(k.preis), 0) FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                          WHERE k.organization_id = ? AND ka.status IN ('angemeldet','teilgenommen') AND ka.bezahlt = 0 AND k.preis > 0 AND k.status <> 'abgesagt' AND k.start_datum BETWEEN ? AND ?");
    $stmt->execute([$org_id, "$von 00:00:00", "$bis 23:59:59"]);
    $offene_kursbeitraege = moneyRound($stmt->fetchColumn() ?: 0);
    $stmt = $db->prepare("SELECT COALESCE(SUM(betrag), 0) FROM trainer_abrechnungen WHERE organization_id = ? AND status IN ('eingereicht','geprueft','freigegeben')");
    $stmt->execute([$org_id]);
    $offene_honorare = moneyRound($stmt->fetchColumn() ?: 0);
} catch (Exception $e) {}

// Fördermittel (bewilligt im Jahr bzw. laufend)
$foerder = ['zugesagt' => '0.00', 'ausbezahlt' => '0.00', 'beantragt' => '0.00', 'anzahl' => 0];
try {
    $stmt = $db->prepare('SELECT * FROM foerderungen WHERE organization_id = ?');
    $stmt->execute([$org_id]);
    foreach ($stmt->fetchAll() as $f) {
        $im_jahr = ($f['bewilligungsdatum'] && substr($f['bewilligungsdatum'], 0, 4) == $jahr) || (!$f['bewilligungsdatum'] && substr($f['created_at'], 0, 4) == $jahr);
        if (in_array($f['status'], FOERDER_ZUGESAGT, true) && $im_jahr) {
            $foerder['zugesagt'] = bcadd($foerder['zugesagt'], moneyRound($f['betrag_bewilligt'] ?? 0), 2);
            $foerder['anzahl']++;
        }
        if (in_array($f['status'], FOERDER_OFFEN, true)) $foerder['beantragt'] = bcadd($foerder['beantragt'], moneyRound($f['betrag_beantragt'] ?? 0), 2);
    }
    $foerder['ausbezahlt'] = $ein_kat['foerderung'] ?? '0.00';
} catch (Exception $e) {}

// PRAE (Info – steckt über Trainerabrechnungen bereits in den Ausgaben bzw. separat im PRAE-Modul)
$prae_ausbezahlt = '0.00';
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(betrag_gesamt), 0) FROM prae_abrechnungen WHERE organization_id = ? AND jahr = ? AND status = 'ausbezahlt'");
    $stmt->execute([$org_id, $jahr]);
    $prae_ausbezahlt = moneyRound($stmt->fetchColumn() ?: 0);
} catch (Exception $e) {}

// Projekte: Budget vs. Kosten (gesamt)
$projekt_finanzen = [];
try {
    $stmt = $db->prepare("SELECT p.id, p.name, p.status, p.budget,
                                 (SELECT COALESCE(SUM(b.betrag), 0) FROM buchungen b WHERE b.projekt_id = p.id AND b.art = 'ausgabe') AS kosten,
                                 (SELECT COALESCE(SUM(b.betrag), 0) FROM buchungen b WHERE b.projekt_id = p.id AND b.art = 'einnahme') AS einnahmen
                          FROM projekte p WHERE p.organization_id = ? AND p.status <> 'archiviert' ORDER BY p.name");
    $stmt->execute([$org_id]);
    foreach ($stmt->fetchAll() as $p) if ($p['budget'] !== null || (float)$p['kosten'] > 0 || (float)$p['einnahmen'] > 0) $projekt_finanzen[] = $p;
} catch (Exception $e) {}

// CSV-Export des (gefilterten) Journals
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Buchungsjournal_' . $jahr . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $zelle = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    fputcsv($out, ['Datum', 'Art', 'Kategorie', 'Beschreibung', 'Projekt', 'Förderung', 'Beleg-Nr.', 'Status', 'Betrag EUR'], ';');
    foreach (array_reverse($journal) as $b) {
        $texte = array_map($zelle, [date('d.m.Y', strtotime($b['datum'])), $b['art'] === 'einnahme' ? 'Einnahme' : 'Ausgabe', BUCHUNG_KATEGORIEN[$b['kategorie']] ?? $b['kategorie'],
            $b['beschreibung'], $b['projekt_name'] ?? '', $b['foerderung_titel'] ?? '', $b['belegnummer'] ?? '', $b['status']]);
        // Betrag ist eine selbst erzeugte Zahl → ohne Formel-Schutz, damit Excel sie als Zahl erkennt
        $texte[] = ($b['art'] === 'ausgabe' ? '-' : '') . number_format((float)$b['betrag'], 2, ',', '');
        fputcsv($out, $texte, ';');
    }
    fclose($out);
    exit;
}

$foerderungen_liste = [];
try {
    $stmt = $db->prepare("SELECT id, titel FROM foerderungen WHERE organization_id = ? AND status NOT IN ('abgelehnt','abgeschlossen') ORDER BY titel");
    $stmt->execute([$org_id]);
    $foerderungen_liste = $stmt->fetchAll();
} catch (Exception $e) {}

$page_title = 'Finanzen ' . $jahr;
$breadcrumb = 'Finanzen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$max_monat = max(1.0, ...array_map('floatval', array_merge(array_values($ein_monat), array_values($aus_monat))));
$filter_query = http_build_query(array_filter(['jahr' => $jahr, 'art' => $f_art, 'kategorie' => $f_kat, 'projekt' => $f_projekt ?: null]));
?>

<style>
.fi-mini { font-size: 0.78rem; color: var(--text-muted); }
.fi-chart { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 0.5rem; align-items: end; height: 190px; padding: 1.25rem 1.25rem 0; }
.fi-monat { display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end; }
.fi-balken { display: flex; gap: 3px; align-items: flex-end; height: calc(100% - 1.4rem); width: 100%; justify-content: center; }
.fi-balken span { width: 42%; max-width: 16px; border-radius: 3px 3px 0 0; min-height: 2px; }
.fi-ein { background: #22C55E; }
.fi-aus { background: var(--gold-accent); }
.fi-label { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.3rem; }
.fi-legende { display: flex; gap: 1rem; padding: 0.75rem 1.25rem 1rem; font-size: 0.78rem; color: var(--text-secondary); }
.fi-legende i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 0.35rem; vertical-align: middle; }
.fi-kat { padding: 0.5rem 1.25rem 1rem; }
.fi-kat-zeile { display: grid; grid-template-columns: 1fr auto; gap: 0.5rem; font-size: 0.85rem; padding: 0.45rem 0 0.2rem; }
.fi-kat-balken { height: 6px; border-radius: 3px; background: var(--bg-muted); overflow: hidden; }
.fi-kat-balken span { display: block; height: 100%; }
.fi-zahl { text-align: right; white-space: nowrap; }
.fi-inline { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.fi-inline .form-group { margin: 0; }
@media (max-width: 600px) { .fi-chart { gap: 0.2rem; padding: 1rem 0.5rem 0; } .fi-label { font-size: 0.6rem; } }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Finanzen <?= $jahr ?></h1>
        <p class="dashboard-subtitle">Kursbeiträge, Honorare, Förderungen und alle Buchungen im Überblick.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
        <a href="?jahr=<?= $jahr - 1 ?>" class="btn btn-ghost-light btn-sm" aria-label="Vorjahr">‹ <?= $jahr - 1 ?></a>
        <?php if ($jahr < (int)date('Y') + 1): ?><a href="?jahr=<?= $jahr + 1 ?>" class="btn btn-ghost-light btn-sm" aria-label="Folgejahr"><?= $jahr + 1 ?> ›</a><?php endif; ?>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value" style="font-size: 1.6rem;"><?= moneyFormat($einnahmen) ?></div><div class="kpi-label">Einnahmen · davon Kurse <?= moneyFormat($kursbeitraege) ?></div></div>
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value" style="font-size: 1.6rem;"><?= moneyFormat($ausgaben) ?></div><div class="kpi-label">Ausgaben · Honorare <?= moneyFormat($aus_kat['trainerhonorar'] ?? '0.00') ?></div></div>
    <div class="kpi-card" style="--kpi-color: <?= bccomp($saldo, '0', 2) < 0 ? '#EF4444' : '#1F3556' ?>;"><div class="kpi-value" style="font-size: 1.6rem;"><?= moneyFormat($saldo) ?></div><div class="kpi-label">Saldo <?= $jahr ?></div></div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;"><div class="kpi-value" style="font-size: 1.6rem;"><?= moneyFormat($foerder['zugesagt']) ?></div><div class="kpi-label">Förderungen zugesagt (<?= $foerder['anzahl'] ?>) · erhalten <?= moneyFormat($foerder['ausbezahlt']) ?></div></div>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #F59E0B;"><div class="kpi-value" style="font-size: 1.35rem;"><?= moneyFormat($offene_kursbeitraege) ?></div><div class="kpi-label">Offene Kursbeiträge (Forderungen)</div></div>
    <div class="kpi-card" style="--kpi-color: #EF4444;"><div class="kpi-value" style="font-size: 1.35rem;"><?= moneyFormat($offene_honorare) ?></div><div class="kpi-label">Offene Trainerabrechnungen</div></div>
    <div class="kpi-card" style="--kpi-color: #7C3AED;"><div class="kpi-value" style="font-size: 1.35rem;"><?= moneyFormat($offen_buchungen) ?></div><div class="kpi-label">Unbezahlte Ausgaben (Buchungen)</div></div>
    <div class="kpi-card" style="--kpi-color: #0EA5E9;"><div class="kpi-value" style="font-size: 1.35rem;"><?= moneyFormat($foerder['beantragt']) ?></div><div class="kpi-label">Förderungen beantragt/in Arbeit<?= bccomp($prae_ausbezahlt, '0', 2) > 0 ? ' · PRAE ' . moneyFormat($prae_ausbezahlt) : '' ?></div></div>
</div>

<div class="grid-2" style="display: grid; gap: 1.5rem; align-items: start; margin-bottom: 1.5rem;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Monatsverlauf</h2></div>
        <div class="fi-chart" role="img" aria-label="Einnahmen und Ausgaben je Monat">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <div class="fi-monat" title="<?= $monate[$m] ?>: Einnahmen <?= moneyFormat($ein_monat[$m]) ?>, Ausgaben <?= moneyFormat($aus_monat[$m]) ?>">
                <div class="fi-balken">
                    <span class="fi-ein" style="height: <?= round((float)$ein_monat[$m] / $max_monat * 100, 1) ?>%"></span>
                    <span class="fi-aus" style="height: <?= round((float)$aus_monat[$m] / $max_monat * 100, 1) ?>%"></span>
                </div>
                <div class="fi-label"><?= $monate[$m] ?></div>
            </div>
            <?php endfor; ?>
        </div>
        <div class="fi-legende"><span><i class="fi-ein"></i>Einnahmen</span><span><i class="fi-aus"></i>Ausgaben</span></div>
    </div>

    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Nach Kategorie</h2></div>
        <div class="fi-kat">
            <?php if (!$ein_kat && !$aus_kat): ?><p class="fi-mini" style="padding: 0.75rem 0;">Noch keine Bewegungen in <?= $jahr ?>.</p><?php endif; ?>
            <?php foreach (['Einnahmen' => [$ein_kat, $einnahmen, '#22C55E'], 'Ausgaben' => [$aus_kat, $ausgaben, 'var(--gold-accent)']] as $titel => [$kats, $summe, $farbe]): if (!$kats) continue; ?>
                <div class="fi-mini" style="margin-top: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em;"><?= $titel ?></div>
                <?php foreach ($kats as $k => $w): $anteil = (float)$summe > 0 ? (float)$w / (float)$summe * 100 : 0; ?>
                <div class="fi-kat-zeile"><span><?= e(BUCHUNG_KATEGORIEN[$k] ?? $k) ?></span><strong><?= moneyFormat($w) ?></strong></div>
                <div class="fi-kat-balken"><span style="width: <?= round($anteil, 1) ?>%; background: <?= $farbe ?>;"></span></div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($projekt_finanzen): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Projekte: Budget &amp; Kosten</h2></div>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Projekt</th><th class="fi-zahl">Budget</th><th class="fi-zahl">Kosten</th><th class="fi-zahl">Einnahmen</th><th class="fi-zahl">Rest</th><th style="min-width: 120px;">Verbrauch</th></tr></thead>
        <tbody>
        <?php foreach ($projekt_finanzen as $p): $rest = $p['budget'] !== null ? bcsub(moneyRound($p['budget']), moneyRound($p['kosten']), 2) : null; $q = $p['budget'] > 0 ? min(100, (float)$p['kosten'] / (float)$p['budget'] * 100) : 0; ?>
            <tr data-row-href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= $p['id'] ?>&tab=finanzen">
                <td class="text-primary"><?= e($p['name']) ?> <span class="badge <?= PROJEKT_STATUS[$p['status']]['class'] ?? 'badge-gray' ?>"><?= e(PROJEKT_STATUS[$p['status']]['label'] ?? $p['status']) ?></span></td>
                <td class="fi-zahl"><?= $p['budget'] !== null ? moneyFormat($p['budget']) : '–' ?></td>
                <td class="fi-zahl"><?= moneyFormat($p['kosten']) ?></td>
                <td class="fi-zahl"><?= moneyFormat($p['einnahmen']) ?></td>
                <td class="fi-zahl" style="<?= $rest !== null && bccomp($rest, '0', 2) < 0 ? 'color: #B91C1C; font-weight: 600;' : '' ?>"><?= $rest !== null ? moneyFormat($rest) : '–' ?></td>
                <td><?php if ($p['budget'] > 0): ?><div class="fi-kat-balken"><span style="width: <?= round($q, 1) ?>%; background: <?= $rest !== null && bccomp($rest, '0', 2) < 0 ? '#EF4444' : 'var(--gold-accent)' ?>;"></span></div><span class="fi-mini"><?= number_format($q, 0) ?> %</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<!-- Buchungsjournal -->
<div class="table-card" id="journal">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
        <h2 class="table-card-title">Buchungsjournal (<?= count($journal) ?>)</h2>
        <a href="?<?= e($filter_query) ?>&export=csv" class="btn btn-ghost-light btn-sm">CSV exportieren</a>
    </div>
    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; padding: 1rem 1.25rem 0;">
        <input type="hidden" name="jahr" value="<?= $jahr ?>">
        <select class="form-control" name="art" style="max-width: 150px;"><option value="">Alle Arten</option><option value="einnahme" <?= $f_art === 'einnahme' ? 'selected' : '' ?>>Einnahmen</option><option value="ausgabe" <?= $f_art === 'ausgabe' ? 'selected' : '' ?>>Ausgaben</option></select>
        <select class="form-control" name="kategorie" style="max-width: 200px;"><option value="">Alle Kategorien</option><?php foreach (BUCHUNG_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= $f_kat === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-control" name="projekt" style="max-width: 220px;"><option value="">Alle Projekte</option><?php foreach (plattformProjekte($db, false) as $p): ?><option value="<?= $p['id'] ?>" <?= $f_projekt === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-navy btn-sm">Filtern</button>
    </form>
    <p class="fi-mini" style="padding: 0.5rem 1.25rem 0;">Kursbeiträge werden direkt aus den bezahlten Kursanmeldungen gerechnet und erscheinen nicht als eigene Buchung.</p>
    <?php if (!$journal): ?>
        <div class="empty-state" style="padding: 1.5rem 1rem;"><p>Keine Buchungen für diese Auswahl.</p></div>
    <?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Datum</th><th>Beschreibung</th><th>Kategorie</th><th>Beleg</th><th>Status</th><th class="fi-zahl">Betrag</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($journal as $b): $ein = $b['art'] === 'einnahme'; ?>
            <tr>
                <td><?= date('d.m.Y', strtotime($b['datum'])) ?></td>
                <td><?= e($b['beschreibung']) ?><div class="fi-mini"><?= e(implode(' · ', array_filter([$b['projekt_name'], $b['foerderung_titel'] ? 'Förderung: ' . $b['foerderung_titel'] : null, $b['trainer_abrechnung_id'] ? 'aus Trainerabrechnung' : null]))) ?></div></td>
                <td><?= e(BUCHUNG_KATEGORIEN[$b['kategorie']] ?? $b['kategorie']) ?></td>
                <td><?= e($b['belegnummer'] ?? '') ?><?php if ($b['beleg_id']): ?> <a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= (int)$b['beleg_id'] ?>" title="Beleg öffnen">📄</a><?php endif; ?></td>
                <td><?php if ($darf_buchen): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="badge <?= $b['status'] === 'offen' ? 'badge-warning' : 'badge-success' ?>" style="border: none; cursor: pointer;" title="Status umschalten"><?= e($b['status']) ?></button></form><?php else: ?><span class="badge <?= $b['status'] === 'offen' ? 'badge-warning' : 'badge-success' ?>"><?= e($b['status']) ?></span><?php endif; ?></td>
                <td class="fi-zahl" style="color: <?= $ein ? '#15803D' : 'inherit' ?>;"><?= $ein ? '+' : '−' ?> <?= moneyFormat($b['betrag']) ?></td>
                <td><?php if ($darf_buchen && !$b['trainer_abrechnung_id']): ?><form method="POST" onsubmit="return confirm('Buchung löschen?');"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-ghost-light btn-sm" aria-label="Löschen">✕</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($darf_buchen): ?>
    <form method="POST" enctype="multipart/form-data" class="fi-inline"><?= csrfField() ?><input type="hidden" name="action" value="buchen">
        <div class="form-group"><label class="form-label">Datum</label><input class="form-control" type="date" name="datum" value="<?= $jahr == date('Y') ? date('Y-m-d') : "$jahr-12-31" ?>"></div>
        <div class="form-group"><label class="form-label">Art</label><select class="form-control" name="art"><option value="ausgabe">Ausgabe</option><option value="einnahme">Einnahme</option></select></div>
        <div class="form-group" style="flex: 1; min-width: 200px;"><label class="form-label">Beschreibung</label><input class="form-control" name="beschreibung" maxlength="255" required></div>
        <div class="form-group"><label class="form-label">Kategorie</label><select class="form-control" name="kategorie"><?php foreach (BUCHUNG_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'sonstiges' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Betrag (€)</label><input class="form-control" name="betrag" inputmode="decimal" required style="width: 110px;"></div>
        <div class="form-group"><label class="form-label">Projekt</label><select class="form-control" name="projekt_id"><option value="">–</option><?php foreach (plattformProjekte($db) as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Förderung</label><select class="form-control" name="foerderung_id"><option value="">–</option><?php foreach ($foerderungen_liste as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['titel']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Beleg-Nr.</label><input class="form-control" name="belegnummer" maxlength="60" style="width: 120px;"></div>
        <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status"><option value="bezahlt">bezahlt</option><option value="offen">offen</option></select></div>
        <div class="form-group"><label class="form-label">Beleg (PDF)</label><input class="form-control" type="file" name="beleg" accept="application/pdf,.pdf"></div>
        <button type="submit" class="btn btn-navy btn-sm">Buchen</button>
    </form>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
