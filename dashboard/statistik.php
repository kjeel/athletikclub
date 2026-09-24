<?php
/**
 * Athletikclub Steiermark – Statistik & Auswertungen
 * Admin: gesamte Trainer:innen-Struktur (optional gefiltert auf eine Trainer:in).
 * Trainer:in: nur eigene Kurse und Kund:innen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/statistik.php';
require_once ROOT_PATH . '/includes/plaene.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$admin  = isAdmin();

// Trainer:innen sehen nur sich selbst, Admins wahlweise alle oder eine Person
$trainer_id = $admin ? ((int)($_GET['trainer'] ?? 0) ?: null) : (int)getCurrentUserId();
$daten = statLaden($db, $org_id, $trainer_id);
if ($admin && $trainer_id && !isset($daten['trainer'][$trainer_id])) {
    $trainer_id = null;
    $daten = statLaden($db, $org_id, null);
}
$vereinssicht = $admin && !$trainer_id;

$z = statZeitraum($_GET);
if ($z['preset'] === 'alles') $z['von'] = min(statErstesDatum($daten) ?? $z['bis'], $z['bis']);
[$von, $bis] = [$z['von'], $z['bis']];
$stichtag = min($bis, $z['heute']);

$kz   = statKennzahlen($daten, $von, $bis);
$kz_v = $z['vergleich_von'] ? statKennzahlen($daten, $z['vergleich_von'], $z['vergleich_bis']) : null;

$verlauf       = statVerlauf($daten, $von, $bis);
$sportarten    = statSportarten($daten, $von, $bis);
$kurse_liste   = statKurse($daten, $von, $bis);
$heatmap       = statHeatmap($daten, $von, $bis);
$top_kunden    = statTopKunden($daten, $von, $bis, 10);
$reaktivierung = statReaktivierung($daten, $stichtag);
$plan_stats    = statPlaene($daten, TP_ZIELE);
$trainer_tab   = $vereinssicht ? statTrainerUebersicht($daten, $von, $bis) : [];

// Personengruppe für Alter/Wohnort/Interessen: Verein = aktive Mitglieder, Trainer:in = eigene Kund:innen
if ($vereinssicht) {
    $personen = array_filter($daten['mitglieder'], fn($m) => (int)$m['aktiv'] && ($m['mitgliedsstatus'] ?? 'aktiv') !== 'inaktiv');
} else {
    $personen = array_intersect_key($daten['mitglieder'], statErsterKontakt($daten));
}
$demografie = statDemografie($personen, $stichtag);
$interessen = statInteressen($personen);

$mitglieder_status = [];
if ($vereinssicht) {
    foreach ($daten['mitglieder'] as $m) {
        $s = $m['mitgliedsstatus'] ?: 'ohne Profil';
        $mitglieder_status[$s] = ($mitglieder_status[$s] ?? 0) + 1;
    }
    $wachstum = statMitgliederwachstum($daten['mitglieder'], $von, $bis);
}

$trainer_name = $trainer_id ? trim(($daten['trainer'][$trainer_id]['vorname'] ?? '') . ' ' . ($daten['trainer'][$trainer_id]['nachname'] ?? '')) : '';
$satz = $trainer_id ? ($daten['trainer'][$trainer_id]['provisionssatz'] ?? null) : null;

// ----------------------------------------------------------------
// CSV-Export (Semikolon + BOM, damit Excel Umlaute und Spalten erkennt)
// ----------------------------------------------------------------
$geld  = fn($b) => number_format((float)$b, 2, ',', '');
$quote = fn($q) => $q === null ? '' : number_format($q, 1, ',', '');
$export = $_GET['export'] ?? '';
$exporte = [
    'verlauf' => ['Zeitverlauf', ['Zeitraum', 'Kursumsatz', 'Sonstiger Umsatz', 'Anmeldungen', 'Neue Kund:innen', 'Protokollierte Einheiten'],
        array_map(fn($i, $l) => [$l, $geld($verlauf['umsatz_kurs'][$i]), $geld($verlauf['umsatz_manuell'][$i]), $verlauf['anmeldungen'][$i], $verlauf['neue_kunden'][$i], $verlauf['einheiten'][$i]],
                  array_keys($verlauf['labels']), $verlauf['labels'])],
    'sportarten' => ['Trainingsangebote', ['Sportart', 'Kurse', 'Anmeldungen', 'Kund:innen', 'Umsatz', 'Auslastung %', 'Stornoquote %'],
        array_map(fn($s) => [$s['sportart'], $s['kurse'], $s['anmeldungen'], $s['kunden'], $geld($s['umsatz']), $quote($s['auslastung']), $quote($s['stornoquote'])], $sportarten)],
    'kurse' => ['Kurse', ['Kurs', 'Sportart', 'Beginn', 'Status', 'Anmeldungen', 'Max. Plätze', 'Auslastung %', 'Warteliste', 'Storniert', 'Umsatz'],
        array_map(fn($k) => [$k['kurs']['titel'], $k['kurs']['sportart'], date('d.m.Y H:i', strtotime($k['kurs']['start_datum'])), $k['kurs']['status'], $k['anmeldungen'],
                             $k['kurs']['max_teilnehmer'], $quote($k['auslastung']), $k['warteliste'], $k['storniert'], $geld($k['umsatz'])], $kurse_liste)],
    'kunden' => ['Kundinnen', ['Name', 'Buchungen', 'Umsatz', 'Letzte Buchung', 'Sportarten'],
        array_map(fn($k) => [$k['name'], $k['buchungen'], $geld($k['umsatz']), $k['letzte'] ? date('d.m.Y', strtotime($k['letzte'])) : '', $k['sportarten']], statTopKunden($daten, $von, $bis, 100000))],
];
if ($vereinssicht) {
    $exporte['trainer'] = ['Trainerinnen', ['Trainer:in', 'Kurse', 'Anmeldungen', 'Aktive Kund:innen', 'Neue Kund:innen', 'Kund:innen gesamt', 'Umsatz', 'Provision', 'Vereinsanteil', 'Auslastung %', 'Stornoquote %', 'Aktive Trainingspläne', 'Protokollierte Einheiten'],
        array_map(fn($t) => [$t['name'], $t['kurse'], $t['anmeldungen'], $t['kunden'], $t['neue_kunden'], $t['kunden_gesamt'], $geld($t['umsatz']), $geld($t['provision']), $geld($t['vereinsanteil']),
                             $quote($t['auslastung']), $quote($t['stornoquote']), $t['trainingsplaene_aktiv'], $t['einheiten_protokolliert']], $trainer_tab)];
}
if (isset($exporte[$export])) {
    [$name, $kopf, $zeilen] = $exporte[$export];
    $datei = 'Statistik_' . $name . ($trainer_name ? '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $trainer_name) : '') . "_{$von}_{$bis}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $datei . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $kopf, ';');
    foreach ($zeilen as $zeile) fputcsv($out, $zeile, ';');
    fclose($out);
    exit;
}

// ----------------------------------------------------------------
// Anzeige-Helfer
// ----------------------------------------------------------------
$url = function (array $aenderung = []) use ($z, $trainer_id, $admin) {
    $q = ['zeitraum' => $z['preset']];
    if ($z['preset'] === 'frei') $q += ['von' => $z['von'], 'bis' => $z['bis']];
    if ($admin && $trainer_id) $q['trainer'] = $trainer_id;
    $q = array_filter(array_merge($q, $aenderung), fn($v) => $v !== null && $v !== '');
    return APP_URL . '/dashboard/statistik.php?' . http_build_query($q);
};
$prozent = fn(?float $p) => $p === null ? '–' : number_format($p, 1, ',', '.') . ' %';
$zahl    = fn($n) => number_format((float)$n, 0, ',', '.');
$datum   = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '–';

/** KPI-Karte mit Veränderung ggü. Vergleichszeitraum. */
$kpi = function (string $wert, string $label, string $farbe, ?string $schluessel = null, ?string $hinweis = null, bool $mehr_ist_gut = true) use ($kz, $kz_v) {
    // Quoten vergleichen wir in Prozentpunkten, alles andere relativ in %
    $ist_quote = in_array($schluessel, ['auslastung', 'stornoquote', 'wiederkehrquote', 'teilnahmequote'], true);
    $delta = null;
    if ($schluessel && $kz_v) {
        $delta = $ist_quote
            ? (($kz[$schluessel] !== null && $kz_v[$schluessel] !== null) ? round($kz[$schluessel] - $kz_v[$schluessel], 1) : null)
            : statDelta($kz[$schluessel], $kz_v[$schluessel]);
    }
    $html  = '<div class="kpi-card" style="--kpi-color: ' . $farbe . ';"><div class="kpi-value">' . $wert . '</div><div class="kpi-label">' . e($label) . '</div>';
    if ($delta !== null) {
        $gut = $delta == 0 ? null : (($delta > 0) === $mehr_ist_gut);
        $html .= '<div class="kpi-change ' . ($gut === null ? '' : ($gut ? 'up' : 'down')) . '" title="Veränderung gegenüber dem Vergleichszeitraum">'
               . ($delta > 0 ? '▲ +' : ($delta < 0 ? '▼ ' : '■ ')) . number_format($delta, 1, ',', '.') . ($ist_quote ? ' Pp.' : ' %') . '</div>';
    } elseif ($schluessel && $kz_v && (float)$kz[$schluessel] > 0) {
        $html .= '<div class="kpi-change up" title="Im Vergleichszeitraum 0">neu</div>';
    }
    if ($hinweis) $html .= '<div class="stat-kpi-hinweis">' . $hinweis . '</div>';
    return $html . '</div>';
};

/** Horizontale Balkenliste ohne Chart-Bibliothek. */
$balken = function (array $werte, int $limit = 10, string $einheit = '') use ($zahl) {
    if (!$werte) return '<p class="stat-leer">Noch keine Daten.</p>';
    $werte = array_slice($werte, 0, $limit, true);
    $max = max($werte) ?: 1;
    $html = '<div class="stat-balken">';
    foreach ($werte as $label => $wert) {
        $html .= '<div class="stat-balken-zeile"><span class="stat-balken-label" title="' . e((string)$label) . '">' . e((string)$label) . '</span>'
               . '<span class="stat-balken-spur"><span style="width: ' . max(2, round($wert / $max * 100)) . '%"></span></span>'
               . '<span class="stat-balken-wert">' . $zahl($wert) . $einheit . '</span></div>';
    }
    return $html . '</div>';
};

$chart_daten = [
    'verlauf'    => $verlauf,
    'sportarten' => ['labels' => array_column(array_slice($sportarten, 0, 10), 'sportart'),
                     'anmeldungen' => array_column(array_slice($sportarten, 0, 10), 'anmeldungen'),
                     'umsatz' => array_map('floatval', array_column(array_slice($sportarten, 0, 10), 'umsatz'))],
    'status'     => ['labels' => array_values(STAT_ANMELDESTATUS), 'werte' => array_values($kz['status'])],
    'alter'      => ['labels' => array_keys($demografie['alter']), 'werte' => array_values($demografie['alter'])],
    'ziele'      => ['labels' => array_keys($plan_stats['ziele']), 'werte' => array_values($plan_stats['ziele'])],
    'trainer'    => ['labels' => array_column($trainer_tab, 'name'),
                     'provision' => array_map('floatval', array_column($trainer_tab, 'provision')),
                     'verein' => array_map('floatval', array_column($trainer_tab, 'vereinsanteil'))],
    'wachstum'   => $wachstum ?? null,
];

$titel = $vereinssicht ? 'Statistik & Auswertungen' : ($admin ? 'Statistik: ' . $trainer_name : 'Meine Statistik');
$page_title = $titel;
$breadcrumb = 'Statistik';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.stat-filter { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; margin-bottom: 1.25rem; }
.stat-filter .form-group { margin: 0; }
.stat-filter .form-control { min-width: 170px; }
.stat-vergleich { font-size: 0.8rem; color: var(--text-muted); margin: -0.5rem 0 1.25rem; }
.stat-kpi-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
.stat-kpi-grid .kpi-value { font-size: clamp(1.25rem, 2vw, 1.6rem); white-space: nowrap; }
.stat-name { min-width: 190px; }
.stat-kpi-hinweis { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.35rem; line-height: 1.35; }
.kpi-change { font-size: 0.75rem; font-weight: 600; margin-top: 0.35rem; color: var(--text-muted); }
.stat-abschnitt { font-family: var(--font-heading); font-size: 0.8rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted); margin: 2rem 0 0.75rem; }
.stat-chart { position: relative; height: 290px; padding: 1rem 1.25rem 1.25rem; }
.stat-chart-klein { height: 240px; }
.stat-inhalt { padding: 1.1rem 1.25rem 1.25rem; }
.stat-leer { color: var(--text-muted); font-size: 0.85rem; margin: 0; }
.stat-balken { display: flex; flex-direction: column; gap: 0.5rem; }
.stat-balken-zeile { display: grid; grid-template-columns: minmax(90px, 38%) 1fr auto; gap: 0.6rem; align-items: center; font-size: 0.85rem; }
.stat-balken-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.stat-balken-spur { height: 9px; background: var(--bg-muted); border-radius: 99px; overflow: hidden; }
.stat-balken-spur span { display: block; height: 100%; background: linear-gradient(90deg, var(--gold-accent), #D4AF37); border-radius: 99px; }
.stat-balken-wert { font-variant-numeric: tabular-nums; color: var(--text-secondary); min-width: 2.5rem; text-align: right; }
.stat-heatmap { width: 100%; border-collapse: separate; border-spacing: 4px; font-size: 0.8rem; }
.stat-heatmap th { font-weight: 600; color: var(--text-muted); text-align: center; padding: 0.25rem; }
.stat-heatmap td { text-align: center; padding: 0.55rem 0.25rem; border-radius: 6px; font-variant-numeric: tabular-nums; }
.data-table td.zahl, .data-table th.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.stat-export { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.stat-mini { font-size: 0.75rem; color: var(--text-muted); }
@media (max-width: 768px) {
    .stat-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; }
    .stat-kpi-grid .kpi-card { padding: 0.85rem; }
    .stat-kpi-grid .kpi-value { font-size: 1.05rem; white-space: normal; overflow-wrap: anywhere; }
    .stat-kpi-grid .kpi-label { font-size: 0.62rem; }
    .stat-kpi-hinweis { display: none; }
    .stat-chart { height: 250px; padding: 0.75rem; }
    .stat-balken-zeile { grid-template-columns: minmax(80px, 42%) 1fr auto; font-size: 0.8rem; }
}
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title"><?= e($titel) ?></h1>
    <p class="dashboard-subtitle">
        <?php if ($vereinssicht): ?>
            Kennzahlen der gesamten Trainer:innen-Struktur: Umsatz, Kund:innen, Trainingsangebote, Auslastung und Entwicklung.
        <?php else: ?>
            Kennzahlen <?= $admin ? 'von ' . e($trainer_name) : 'deiner Kurse und Kund:innen' ?>: Umsatz<?= $satz !== null ? ' und Anteil (' . number_format((float)$satz, 0, ',', '.') . ' %)' : '' ?>, Kund:innen, Trainingsangebote und Auslastung.
        <?php endif; ?>
    </p>
</div>

<form method="GET" class="stat-filter">
    <div class="form-group">
        <label class="form-label">Zeitraum</label>
        <select class="form-control" name="zeitraum" onchange="document.getElementById('stat-frei').style.display = this.value === 'frei' ? 'flex' : 'none'; if (this.value !== 'frei') this.form.submit();">
            <?php foreach (STAT_ZEITRAEUME as $wert => $label): ?><option value="<?= $wert ?>" <?= $z['preset'] === $wert ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div id="stat-frei" style="display: <?= $z['preset'] === 'frei' ? 'flex' : 'none' ?>; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group"><label class="form-label">Von</label><input class="form-control" type="date" name="von" value="<?= e($z['preset'] === 'frei' ? $von : '') ?>"></div>
        <div class="form-group"><label class="form-label">Bis</label><input class="form-control" type="date" name="bis" value="<?= e($z['preset'] === 'frei' ? $bis : '') ?>"></div>
    </div>
    <?php if ($admin): ?>
    <div class="form-group">
        <label class="form-label">Trainer:in</label>
        <select class="form-control" name="trainer" onchange="this.form.submit()">
            <option value="">Alle – gesamter Verein</option>
            <?php foreach ($daten['trainer'] as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $trainer_id === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?><?= (int)$t['aktiv'] ? '' : ' (inaktiv)' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-navy btn-sm">Anzeigen</button>
</form>
<p class="stat-vergleich">
    <strong><?= e($z['label']) ?></strong> (<?= $datum($von) ?> – <?= $datum($bis) ?>)
    <?php if ($kz_v): ?> · Pfeile vergleichen mit <?= $datum($z['vergleich_von']) ?> – <?= $datum($z['vergleich_bis']) ?> (Quoten in Prozentpunkten, Pp.)<?php endif; ?>
</p>

<!-- ============================================================ Kennzahlen -->
<div class="kpi-grid stat-kpi-grid">
    <?= $kpi(moneyFormat($kz['umsatz']), 'Umsatz', '#C6A135', 'umsatz', 'Kurse ' . moneyFormat($kz['umsatz_kurs']) . ' · Sonstiges ' . moneyFormat($kz['umsatz_manuell'])) ?>
    <?php if ($vereinssicht): ?>
        <?= $kpi(moneyFormat($kz['vereinsanteil']), 'Vereinsanteil', '#1F3556', 'vereinsanteil', 'Provisionen Trainer:innen ' . moneyFormat($kz['provision'])) ?>
    <?php else: ?>
        <?= $kpi(moneyFormat($kz['provision']), $admin ? 'Provision' : 'Mein Anteil', '#1F3556', 'provision', $satz !== null ? number_format((float)$satz, 0, ',', '.') . ' % vom Umsatz · Verein ' . moneyFormat($kz['vereinsanteil']) : null) ?>
    <?php endif; ?>
    <?= $kpi($zahl($kz['kunden']), 'Aktive Kund:innen', '#3B82F6', 'kunden', 'mit Buchung, neuem Plan oder Training im Zeitraum') ?>
    <?= $kpi($zahl($kz['neue_kunden']), 'Neue Kund:innen', '#22C55E', 'neue_kunden', 'erste Buchung bzw. erster Plan') ?>
    <?= $kpi($zahl($kz['anmeldungen']), 'Anmeldungen', '#8B5CF6', 'anmeldungen', $kz['buchungen_pro_kurs'] !== null ? 'Ø ' . number_format($kz['buchungen_pro_kurs'], 1, ',', '.') . ' pro Kurs' : null) ?>
    <?= $kpi($zahl($kz['kurse']), 'Kurse', '#14B8A6', 'kurse', $kz['kurse_abgesagt'] ? $kz['kurse_abgesagt'] . ' abgesagt' : null) ?>
    <?= $kpi($prozent($kz['auslastung']), 'Auslastung', '#F59E0B', 'auslastung', $kz['plaetze'] ? 'belegte von ' . $zahl($kz['plaetze']) . ' Plätzen' : 'Kurse ohne Platzlimit') ?>
    <?= $kpi($prozent($kz['stornoquote']), 'Stornoquote', '#EF4444', 'stornoquote', null, false) ?>
    <?= $kpi($kz['umsatz_pro_kunde'] !== null ? moneyFormat($kz['umsatz_pro_kunde']) : '–', 'Ø Umsatz pro Kund:in', '#C6A135', 'umsatz_pro_kunde') ?>
    <?= $kpi($prozent($kz['wiederkehrquote']), 'Wiederkehrquote', '#22C55E', 'wiederkehrquote', 'Kund:innen mit 2+ Buchungen') ?>
    <?= $kpi(moneyFormat($kz['offen_betrag']), 'Offene Zahlungen', '#EF4444', null, $kz['offen_anzahl'] . ' unbezahlte Anmeldung' . ($kz['offen_anzahl'] === 1 ? '' : 'en')) ?>
    <?php if ($kz['teilnahmequote'] !== null): ?>
        <?= $kpi($prozent($kz['teilnahmequote']), 'Anwesenheit', '#3B82F6', 'teilnahmequote', 'als „teilgenommen“ erfasst') ?>
    <?php endif; ?>
    <?php if ($vereinssicht): ?>
        <?= $kpi($zahl(count($personen)), 'Aktive Mitglieder', '#1F3556', null, $kz['registrierungen'] . ' Registrierungen im Zeitraum') ?>
    <?php endif; ?>
</div>

<!-- ============================================================ Entwicklung -->
<h2 class="stat-abschnitt">Entwicklung</h2>
<div class="grid-2" style="align-items: start;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Umsatz je <?= ['woche' => 'Woche', 'monat' => 'Monat', 'jahr' => 'Jahr'][$verlauf['einheit']] ?></h2></div>
        <div class="stat-chart"><canvas id="chart-umsatz" aria-label="Umsatzentwicklung"></canvas></div>
    </div>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Anmeldungen &amp; neue Kund:innen</h2></div>
        <div class="stat-chart"><canvas id="chart-anmeldungen" aria-label="Anmeldungen und neue Kund:innen"></canvas></div>
    </div>
</div>

<?php if ($vereinssicht): ?>
<!-- ============================================================ Trainer:innen -->
<h2 class="stat-abschnitt">Trainer:innen-Struktur</h2>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Trainer:innen im Vergleich</h2>
        <a href="<?= e($url(['export' => 'trainer'])) ?>" class="btn btn-ghost-light btn-sm">CSV</a>
    </div>
    <?php if (!$trainer_tab): ?>
        <div class="stat-inhalt"><p class="stat-leer">Keine Trainer:innen mit Aktivität im Zeitraum.</p></div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr>
                <th>Trainer:in</th><th class="zahl">Kurse</th><th class="zahl">Anmeldungen</th><th class="zahl">Aktive Kund:innen</th><th class="zahl">Neu</th>
                <th class="zahl">Umsatz</th><th class="zahl">Anteil</th><th class="zahl">Provision</th><th class="zahl">Verein</th><th class="zahl">Auslastung</th><th class="zahl">Storno</th><th class="zahl">Pläne aktiv</th>
            </tr></thead>
            <tbody>
                <?php foreach ($trainer_tab as $t): ?>
                <tr>
                    <td class="stat-name">
                        <?php if ($t['id']): ?><a class="text-primary" href="<?= e($url(['trainer' => $t['id']])) ?>"><?= e($t['name']) ?></a><?php else: ?><span class="text-primary"><?= e($t['name']) ?></span><?php endif; ?>
                        <div class="stat-mini"><?= $t['kunden_gesamt'] ?> Kund:innen gesamt<?= $t['provisionssatz'] !== null ? ' · ' . number_format((float)$t['provisionssatz'], 0, ',', '.') . ' %' : '' ?><?= $t['aktiv'] ? '' : ' · inaktiv' ?></div>
                    </td>
                    <td class="zahl"><?= $t['kurse'] ?></td>
                    <td class="zahl"><?= $t['anmeldungen'] ?></td>
                    <td class="zahl"><?= $t['kunden'] ?></td>
                    <td class="zahl"><?= $t['neue_kunden'] ?></td>
                    <td class="zahl"><strong><?= moneyFormat($t['umsatz']) ?></strong></td>
                    <td class="zahl"><?= $prozent(statQuote((float)$t['umsatz'], (float)$kz['umsatz'])) ?></td>
                    <td class="zahl"><?= moneyFormat($t['provision']) ?></td>
                    <td class="zahl"><?= moneyFormat($t['vereinsanteil']) ?></td>
                    <td class="zahl"><?= $prozent($t['auslastung']) ?></td>
                    <td class="zahl"><?= $prozent($t['stornoquote']) ?></td>
                    <td class="zahl"><?= $t['trainingsplaene_aktiv'] + $t['ernaehrungsplaene_aktiv'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="stat-chart"><canvas id="chart-trainer" aria-label="Umsatz je Trainer:in"></canvas></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================================ Trainingsangebote -->
<h2 class="stat-abschnitt">Trainingsangebote</h2>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Beliebteste Trainings (nach Sportart)</h2>
        <a href="<?= e($url(['export' => 'sportarten'])) ?>" class="btn btn-ghost-light btn-sm">CSV</a>
    </div>
    <?php if (!$sportarten): ?>
        <div class="stat-inhalt"><p class="stat-leer">Keine Kurse im Zeitraum.</p></div>
    <?php else: ?>
    <div class="stat-chart"><canvas id="chart-sportarten" aria-label="Anmeldungen und Umsatz je Sportart"></canvas></div>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Sportart</th><th class="zahl">Kurse</th><th class="zahl">Anmeldungen</th><th class="zahl">Kund:innen</th><th class="zahl">Umsatz</th><th class="zahl">Auslastung</th><th class="zahl">Storno</th></tr></thead>
            <tbody>
                <?php foreach ($sportarten as $s): ?>
                <tr>
                    <td class="text-primary"><?= e($s['sportart']) ?></td>
                    <td class="zahl"><?= $s['kurse'] ?></td>
                    <td class="zahl"><?= $s['anmeldungen'] ?></td>
                    <td class="zahl"><?= $s['kunden'] ?></td>
                    <td class="zahl"><?= moneyFormat($s['umsatz']) ?></td>
                    <td class="zahl"><?= $prozent($s['auslastung']) ?></td>
                    <td class="zahl"><?= $prozent($s['stornoquote']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Top-Kurse</h2>
        <a href="<?= e($url(['export' => 'kurse'])) ?>" class="btn btn-ghost-light btn-sm">CSV (alle Kurse)</a>
    </div>
    <?php if (!$kurse_liste): ?>
        <div class="stat-inhalt"><p class="stat-leer">Keine Kurse im Zeitraum.</p></div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Kurs</th><th>Beginn</th><?php if ($vereinssicht): ?><th>Trainer:in</th><?php endif; ?><th class="zahl">Anmeldungen</th><th class="zahl">Auslastung</th><th class="zahl">Warteliste</th><th class="zahl">Umsatz</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($kurse_liste, 0, 10) as $k): $tid = (int)$k['kurs']['trainer_id']; ?>
                <tr>
                    <td><a class="text-primary" href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= (int)$k['kurs']['id'] ?>"><?= e($k['kurs']['titel']) ?></a>
                        <div class="stat-mini"><?= e($k['kurs']['sportart'] ?: 'Ohne Sportart') ?><?= $k['kurs']['status'] === 'abgesagt' ? ' · abgesagt' : '' ?></div></td>
                    <td><?= date('d.m.Y', strtotime($k['kurs']['start_datum'])) ?></td>
                    <?php if ($vereinssicht): ?><td><?= e($tid && isset($daten['trainer'][$tid]) ? $daten['trainer'][$tid]['vorname'] . ' ' . $daten['trainer'][$tid]['nachname'] : '–') ?></td><?php endif; ?>
                    <td class="zahl"><?= $k['anmeldungen'] ?><?= (int)$k['kurs']['max_teilnehmer'] > 0 ? ' / ' . (int)$k['kurs']['max_teilnehmer'] : '' ?></td>
                    <td class="zahl"><?= $prozent($k['auslastung']) ?></td>
                    <td class="zahl"><?= $k['warteliste'] ?></td>
                    <td class="zahl"><?= moneyFormat($k['umsatz']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="grid-2" style="align-items: start;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Wann trainiert wird</h2></div>
        <div class="stat-inhalt" style="overflow-x: auto;">
            <?php $heat_max = max(1, max(array_map('max', $heatmap))); ?>
            <table class="stat-heatmap">
                <thead><tr><th></th><?php foreach (STAT_TAGESZEITEN as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($heatmap as $tag => $zeiten): ?>
                    <tr>
                        <th><?= STAT_WOCHENTAGE[$tag] ?></th>
                        <?php foreach ($zeiten as $n): $a = $n ? 0.12 + 0.88 * $n / $heat_max : 0; ?>
                        <td style="background: <?= $n ? 'rgba(198,161,53,' . round($a, 2) . ')' : 'var(--bg-muted)' ?>; color: <?= $a > 0.55 ? '#1a1a1a' : 'var(--text-secondary)' ?>;"><?= $n ?: '·' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="stat-mini" style="margin-top: 0.5rem;">Anmeldungen nach Wochentag und Uhrzeit des Kursbeginns.</p>
        </div>
    </div>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Anmeldestatus</h2></div>
        <div class="stat-chart stat-chart-klein"><canvas id="chart-status" aria-label="Anmeldestatus"></canvas></div>
    </div>
</div>

<!-- ============================================================ Kund:innen -->
<h2 class="stat-abschnitt"><?= $vereinssicht ? 'Mitglieder & Kund:innen' : 'Meine Kund:innen' ?></h2>
<div class="grid-2" style="align-items: start;">
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Altersstruktur</h2>
            <span class="stat-mini"><?= $vereinssicht ? 'aktive Mitglieder' : 'alle Kund:innen' ?><?= $demografie['durchschnittsalter'] !== null ? ' · Ø ' . number_format($demografie['durchschnittsalter'], 1, ',', '.') . ' Jahre' : '' ?></span>
        </div>
        <div class="stat-chart stat-chart-klein"><canvas id="chart-alter" aria-label="Altersstruktur"></canvas></div>
    </div>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Wohnorte</h2></div>
        <div class="stat-inhalt"><?= $balken($demografie['orte'], 8) ?></div>
    </div>
</div>

<div class="grid-2" style="align-items: start; margin-top: 1.5rem;">
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Top-Kund:innen</h2>
            <a href="<?= e($url(['export' => 'kunden'])) ?>" class="btn btn-ghost-light btn-sm">CSV (alle)</a>
        </div>
        <?php if (!$top_kunden): ?>
            <div class="stat-inhalt"><p class="stat-leer">Keine Buchungen im Zeitraum.</p></div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead><tr><th>Name</th><th class="zahl">Buchungen</th><th class="zahl">Umsatz</th><th>Zuletzt</th></tr></thead>
                <tbody>
                    <?php foreach ($top_kunden as $k): ?>
                    <tr>
                        <td><?php if ($k['mitglied']): ?><a class="text-primary" href="<?= APP_URL ?>/dashboard/mitglied-detail.php?id=<?= $k['id'] ?>"><?= e($k['name']) ?></a><?php else: ?><?= e($k['name']) ?><?php endif; ?>
                            <?php if ($k['sportarten']): ?><div class="stat-mini"><?= e($k['sportarten']) ?></div><?php endif; ?></td>
                        <td class="zahl"><?= $k['buchungen'] ?></td>
                        <td class="zahl"><?= moneyFormat($k['umsatz']) ?></td>
                        <td><?= $datum($k['letzte']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Wieder ansprechen</h2>
            <span class="stat-mini">seit über <?= STAT_INAKTIV_TAGE ?> Tagen ohne Kurs</span>
        </div>
        <?php if (!$reaktivierung): ?>
            <div class="stat-inhalt"><p class="stat-leer">Alle Kund:innen waren in den letzten <?= STAT_INAKTIV_TAGE ?> Tagen aktiv oder sind bereits angemeldet.</p></div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead><tr><th>Name</th><th class="zahl">Buchungen gesamt</th><th>Letzter Kurs</th></tr></thead>
                <tbody>
                    <?php foreach ($reaktivierung as $k): ?>
                    <tr>
                        <td><a class="text-primary" href="<?= APP_URL ?>/dashboard/mitglied-detail.php?id=<?= $k['id'] ?>"><?= e($k['name']) ?></a></td>
                        <td class="zahl"><?= $k['buchungen'] ?></td>
                        <td><?= $datum($k['letzte']) ?> <span class="stat-mini">(vor <?= $k['tage'] ?> Tagen)</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2" style="align-items: start; margin-top: 1.5rem;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Sportinteressen laut Profil</h2></div>
        <div class="stat-inhalt"><?= $balken($interessen, 10) ?></div>
    </div>
    <?php if ($vereinssicht): ?>
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Mitgliederbestand</h2>
            <span class="stat-mini"><?php foreach ($mitglieder_status as $s => $n): ?><?= e(ucfirst($s)) ?>: <?= $n ?> &nbsp;<?php endforeach; ?></span>
        </div>
        <div class="stat-chart stat-chart-klein"><canvas id="chart-wachstum" aria-label="Mitgliederentwicklung"></canvas></div>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================ Pläne -->
<?php if ($daten['plaene_verfuegbar']): ?>
<h2 class="stat-abschnitt">Trainings- &amp; Ernährungspläne</h2>
<div class="kpi-grid stat-kpi-grid">
    <?= $kpi($zahl($kz['trainingsplaene_aktiv']), 'Aktive Trainingspläne', '#1F3556') ?>
    <?= $kpi($zahl($kz['ernaehrungsplaene_aktiv']), 'Aktive Ernährungspläne', '#22C55E') ?>
    <?= $kpi($zahl($kz['plaene_neu']), 'Neue Pläne', '#C6A135', 'plaene_neu') ?>
    <?= $kpi($zahl($kz['einheiten_protokolliert']), 'Absolvierte Einheiten', '#3B82F6', 'einheiten_protokolliert', $plan_stats['rpe_schnitt'] !== null ? 'Ø Anstrengung ' . number_format($plan_stats['rpe_schnitt'], 1, ',', '.') . ' / 10' : 'von Mitgliedern eingetragen') ?>
</div>
<div class="grid-2" style="align-items: start; margin-top: 1rem;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Trainingsziele</h2></div>
        <?php if ($plan_stats['ziele']): ?>
            <div class="stat-chart stat-chart-klein"><canvas id="chart-ziele" aria-label="Trainingsziele"></canvas></div>
        <?php else: ?>
            <div class="stat-inhalt"><p class="stat-leer">Noch keine Trainingspläne für Mitglieder.</p></div>
        <?php endif; ?>
    </div>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Meistverwendete Übungen</h2></div>
        <div class="stat-inhalt"><?= $balken($plan_stats['uebungen'], 10, '×') ?></div>
    </div>
</div>
<?php endif; ?>

<h2 class="stat-abschnitt">Export</h2>
<div class="stat-export">
    <?php foreach ($exporte as $schluessel => [$name]): ?>
    <a href="<?= e($url(['export' => $schluessel])) ?>" class="btn btn-ghost-light btn-sm">CSV: <?= e(['verlauf' => 'Zeitverlauf', 'sportarten' => 'Trainingsangebote', 'kurse' => 'Kurse', 'kunden' => 'Kund:innen', 'trainer' => 'Trainer:innen'][$schluessel]) ?></a>
    <?php endforeach; ?>
</div>
<p class="stat-mini" style="margin-top: 1rem;">
    Umsatz = bezahlte Kursanmeldungen × Kurspreis (Datum der Zahlung, sonst Kursbeginn) + sonstige Umsatzeinträge.
    Kurse, Anmeldungen und Auslastung werden dem Zeitraum über den Kursbeginn zugeordnet.
</p>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart) return;
    var D = <?= json_encode($chart_daten, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var css = getComputedStyle(document.documentElement);
    var farbe = function (name, ersatz) { return (css.getPropertyValue(name) || '').trim() || ersatz; };
    var text = farbe('--text-secondary', '#4A5568'), linie = farbe('--border-light', '#E2E8F0');
    var F = { gold: '#C6A135', blau: '#3B82F6', navy: '#4A6FA5', gruen: '#22C55E', rot: '#EF4444', orange: '#F59E0B', lila: '#8B5CF6', tuerkis: '#14B8A6' };
    var palette = [F.gold, F.navy, F.blau, F.gruen, F.lila, F.orange, F.tuerkis, F.rot, '#A3A3A3', '#EC4899'];
    Chart.defaults.color = text;
    Chart.defaults.borderColor = linie;
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    var euro = function (v) { return Number(v).toLocaleString('de-AT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }); };
    var euroTooltip = { callbacks: { label: function (c) { return c.dataset.label + ': ' + Number(c.parsed.y ?? c.parsed.x ?? c.parsed).toLocaleString('de-AT', { style: 'currency', currency: 'EUR' }); } } };
    var el = function (id) { return document.getElementById(id); };

    if (el('chart-umsatz')) new Chart(el('chart-umsatz'), {
        type: 'bar',
        data: { labels: D.verlauf.labels, datasets: [
            { label: 'Kurse', data: D.verlauf.umsatz_kurs, backgroundColor: F.gold, borderRadius: 4, stack: 'u' },
            { label: 'Sonstiges', data: D.verlauf.umsatz_manuell, backgroundColor: F.navy, borderRadius: 4, stack: 'u' }
        ] },
        options: { scales: { y: { beginAtZero: true, ticks: { callback: euro } }, x: { grid: { display: false } } }, plugins: { tooltip: euroTooltip } }
    });

    if (el('chart-anmeldungen')) new Chart(el('chart-anmeldungen'), {
        type: 'line',
        data: { labels: D.verlauf.labels, datasets: [
            { label: 'Anmeldungen', data: D.verlauf.anmeldungen, borderColor: F.blau, backgroundColor: 'rgba(59,130,246,0.12)', fill: true, tension: 0.3 },
            { label: 'Neue Kund:innen', data: D.verlauf.neue_kunden, borderColor: F.gruen, backgroundColor: F.gruen, tension: 0.3 },
            { label: 'Absolvierte Plan-Einheiten', data: D.verlauf.einheiten, borderColor: F.gold, backgroundColor: F.gold, borderDash: [5, 4], tension: 0.3 }
        ] },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }, interaction: { mode: 'index', intersect: false } }
    });

    if (el('chart-trainer') && D.trainer.labels.length) new Chart(el('chart-trainer'), {
        type: 'bar',
        data: { labels: D.trainer.labels, datasets: [
            { label: 'Provision', data: D.trainer.provision, backgroundColor: F.gold, borderRadius: 4, stack: 't' },
            { label: 'Vereinsanteil', data: D.trainer.verein, backgroundColor: F.navy, borderRadius: 4, stack: 't' }
        ] },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true, ticks: { callback: euro } }, y: { grid: { display: false } } },
                   plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + Number(c.parsed.x).toLocaleString('de-AT', { style: 'currency', currency: 'EUR' }); } } } } }
    });

    if (el('chart-sportarten')) new Chart(el('chart-sportarten'), {
        type: 'bar',
        data: { labels: D.sportarten.labels, datasets: [
            { label: 'Anmeldungen', data: D.sportarten.anmeldungen, backgroundColor: F.blau, borderRadius: 4, yAxisID: 'y' },
            { label: 'Umsatz', data: D.sportarten.umsatz, backgroundColor: F.gold, borderRadius: 4, yAxisID: 'y1' }
        ] },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Anmeldungen' } },
                             y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: euro } }, x: { grid: { display: false } } },
                   plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.yAxisID === 'y1' ? 'Umsatz: ' + Number(c.parsed.y).toLocaleString('de-AT', { style: 'currency', currency: 'EUR' }) : 'Anmeldungen: ' + c.parsed.y; } } } } }
    });

    var ring = function (id, labels, werte, farben) {
        if (!el(id)) return;
        new Chart(el(id), { type: 'doughnut', data: { labels: labels, datasets: [{ data: werte, backgroundColor: farben || palette, borderColor: farbe('--surface', '#fff'), borderWidth: 2 }] },
            options: { cutout: '60%', plugins: { legend: { position: 'right' } } } });
    };
    ring('chart-status', D.status.labels, D.status.werte, [F.blau, F.gruen, F.orange, F.rot]);
    ring('chart-ziele', D.ziele.labels, D.ziele.werte);

    if (el('chart-alter')) new Chart(el('chart-alter'), {
        type: 'bar',
        data: { labels: D.alter.labels, datasets: [{ label: 'Personen', data: D.alter.werte, backgroundColor: D.alter.labels.map(function (l) { return l === 'unbekannt' ? '#A3A3A3' : F.navy; }), borderRadius: 4 }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
    });

    if (el('chart-wachstum') && D.wachstum) new Chart(el('chart-wachstum'), {
        data: { labels: D.wachstum.labels, datasets: [
            { type: 'line', label: 'Mitglieder gesamt', data: D.wachstum.gesamt, borderColor: F.gold, backgroundColor: F.gold, tension: 0.3, yAxisID: 'y' },
            { type: 'bar', label: 'Neu registriert', data: D.wachstum.neu, backgroundColor: F.navy, borderRadius: 4, yAxisID: 'y1' }
        ] },
        options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
    });
})();
</script>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
