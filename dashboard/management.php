<?php
/**
 * Athletikclub Steiermark – Management / Präsidium
 * Kennzahlen aus allen Modulen mit Zeitfilter (Monat, Quartal, Jahr, frei) und Vergleich
 * zur Vorperiode. Werte ohne Datengrundlage werden als „keine Daten“ angezeigt.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einstellungen.php';
require_once ROOT_PATH . '/includes/management.php';

requireDarf('management.anzeigen');

$db = getDB();
$z  = managementZeitraum($_GET);
$ist = managementKennzahlen($db, $z['von'], $z['bis']);
$vor = managementKennzahlen($db, $z['v_von'], $z['v_bis']);
$vor_daten = $vor['daten'] > 0;
$stand = managementStand($db);
$projekte = managementProjekte($db);

// Soll-Ist Verein: Jahresbudget aus den Einstellungen, Ist = laufendes Jahr des Bezugsdatums
$jahr = (int)date('Y', strtotime($z['bis']));
$ist_jahr = $z['art'] === 'jahr' ? $ist : managementKennzahlen($db, "$jahr-01-01", "$jahr-12-31");
$soll_ein = moneyRound(einstellung('budget_einnahmen', '0') ?: 0);
$soll_aus = moneyRound(einstellung('budget_ausgaben', '0') ?: 0);

// Kurse im Zeitraum mit Auslastung
$stmt = $db->prepare("SELECT k.id, k.titel, k.max_teilnehmer, k.preis, k.status,
                             (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status IN ('angemeldet','teilgenommen')) AS belegt,
                             (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status = 'warteliste') AS warteliste
                      FROM kurse k WHERE k.organization_id = ? AND k.status <> 'abgesagt' AND k.start_datum <= ? AND k.end_datum >= ?
                      ORDER BY k.start_datum DESC LIMIT 30");
$stmt->execute([currentOrgId(), $z['bis'] . ' 23:59:59', $z['von'] . ' 00:00:00']);
$kurse = $stmt->fetchAll();

$fmt_delta = function (?float $d, bool $weniger_ist_gut = false): string {
    if ($d === null) return '<span class="mg-delta">Vorperiode: keine Daten</span>';
    $gut = $weniger_ist_gut ? $d <= 0 : $d >= 0;
    $pfeil = $d > 0 ? '▲' : ($d < 0 ? '▼' : '■');
    return '<span class="mg-delta ' . ($d == 0 ? '' : ($gut ? 'mg-gut' : 'mg-schlecht')) . '">' . $pfeil . ' ' . number_format(abs($d), 1, ',', '.') . ' % zur Vorperiode</span>';
};
$karte = function (string $label, string $wert, string $delta, string $farbe) {
    return '<div class="kpi-card" style="--kpi-color: ' . $farbe . ';"><div class="kpi-value" style="font-size: 1.45rem;">' . $wert . '</div><div class="kpi-label">' . e($label) . '</div>' . $delta . '</div>';
};
$m = fn($key) => moneyFormat($ist[$key]);
$d = function ($key, $inv = false) use ($ist, $vor, $vor_daten, $fmt_delta) {
    if ($vor_daten && (float)$vor[$key] == 0.0) return '<span class="mg-delta">Vorperiode: 0</span>';
    return $fmt_delta(managementDelta($ist[$key], $vor[$key], $vor_daten), $inv);
};
$balken = function (?float $quote): string {
    if ($quote === null) return '<span class="text-muted">–</span>';
    $farbe = $quote > 100 ? '#EF4444' : ($quote > 85 ? '#F59E0B' : '#22C55E');
    return '<div class="mg-balken" title="' . number_format($quote, 1, ',', '.') . ' %"><span style="width: ' . min(100, $quote) . '%; background: ' . $farbe . '"></span></div><small>' . number_format($quote, 1, ',', '.') . ' %</small>';
};

$page_title = 'Management';
$breadcrumb = 'Management';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.mg-filter { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: flex-end; }
.mg-filter .form-group { margin: 0; }
.mg-delta { display: block; font-size: 0.72rem; color: var(--text-muted); margin-top: 0.35rem; }
.mg-gut { color: #15803D; }
.mg-schlecht { color: #B91C1C; }
.mg-abschnitt { margin: 2rem 0 0.75rem; font-size: 0.8rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-secondary); font-weight: 700; }
.mg-balken { height: 6px; border-radius: 3px; background: var(--bg-muted); overflow: hidden; min-width: 80px; }
.mg-balken span { display: block; height: 100%; }
.mg-liste { list-style: none; margin: 0; padding: 0.5rem 1.25rem 1rem; }
.mg-liste li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.45rem 0; border-bottom: 1px solid var(--border-light); font-size: 0.88rem; }
.mg-liste li:last-child { border-bottom: 0; }
.mg-zwei { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 1.5rem; align-items: start; }
.fi-zahl { text-align: right; white-space: nowrap; }
.mg-hinweis { font-size: 0.8rem; color: var(--text-muted); padding: 0 1.25rem 1rem; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Management · <?= e($z['titel']) ?></h1>
        <p class="dashboard-subtitle">Kennzahlen aus Finanzen, Kursen, Trainern, Projekten und Förderungen. Vergleich: <?= date('d.m.Y', strtotime($z['v_von'])) ?> – <?= date('d.m.Y', strtotime($z['v_bis'])) ?></p>
    </div>
    <form method="get" class="mg-filter">
        <div class="form-group">
            <label class="form-label" for="zeitraum">Zeitraum</label>
            <select name="zeitraum" id="zeitraum" class="form-control" onchange="document.getElementById('mg-frei').style.display = this.value === 'frei' ? 'flex' : 'none'; document.getElementById('mg-ref').style.display = this.value === 'frei' ? 'none' : 'block';">
                <?php foreach (['monat' => 'Monat', 'quartal' => 'Quartal', 'jahr' => 'Jahr', 'frei' => 'Benutzerdefiniert'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $z['art'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="mg-ref" style="display: <?= $z['art'] === 'frei' ? 'none' : 'block' ?>;">
            <label class="form-label" for="datum">Bezugsdatum</label>
            <input type="date" name="datum" id="datum" class="form-control" value="<?= e($z['ref']) ?>">
        </div>
        <div id="mg-frei" style="display: <?= $z['art'] === 'frei' ? 'flex' : 'none' ?>; gap: 0.6rem;">
            <div class="form-group"><label class="form-label" for="von">Von</label><input type="date" name="von" id="von" class="form-control" value="<?= e($z['von']) ?>"></div>
            <div class="form-group"><label class="form-label" for="bis">Bis</label><input type="date" name="bis" id="bis" class="form-control" value="<?= e($z['bis']) ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Anzeigen</button>
    </form>
</div>

<?php if ($ist['daten'] === 0): ?>
    <div class="alert alert-info">Für diesen Zeitraum liegen noch keine Daten vor.</div>
<?php endif; ?>

<h2 class="mg-abschnitt">Finanzen</h2>
<div class="kpi-grid">
    <?= $karte('Umsatz (Kursbeiträge + Einnahmen)', $m('umsatz'), $d('umsatz'), '#22C55E') ?>
    <?= $karte('Ausgaben (Buchungen)', $m('ausgaben'), $d('ausgaben', true), '#C6A135') ?>
    <?= $karte('Saldo', $m('saldo'), $d('saldo'), bccomp($ist['saldo'], '0', 2) < 0 ? '#EF4444' : '#1F3556') ?>
    <?= $karte('Fördermittel eingegangen', $m('foerdermittel'), $d('foerdermittel'), '#3B82F6') ?>
</div>
<div class="kpi-grid">
    <?= $karte('Kursbeiträge bezahlt', $m('kursbeitraege'), $d('kursbeitraege'), '#0EA5E9') ?>
    <?= $karte('Trainerkosten (geleistete Einheiten)', $m('trainerkosten'), $d('trainerkosten', true), '#F59E0B') ?>
    <?= $karte('Offene Rechnungen (Stand heute)', moneyFormat($stand['rechnungen']['offen']), '<span class="mg-delta">' . (int)$stand['rechnungen']['anzahl'] . ' offen · ' . (int)$stand['rechnungen']['anzahl_ueberfaellig'] . ' überfällig</span>', '#7C3AED') ?>
    <?= $karte('Projektkosten', $m('projektkosten'), $d('projektkosten', true), '#64748B') ?>
</div>

<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Soll-Ist Verein <?= $jahr ?></h2></div>
    <?php if (bccomp($soll_ein, '0', 2) <= 0 && bccomp($soll_aus, '0', 2) <= 0): ?>
        <p class="mg-hinweis" style="padding-top: 1rem;">Kein Jahresbudget hinterlegt.<?php if (darf('einstellungen.bearbeiten')): ?> <a href="<?= APP_URL ?>/dashboard/admin/einstellungen.php#budget">In den Einstellungen festlegen</a>.<?php endif; ?></p>
    <?php else: ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th></th><th class="fi-zahl">Soll</th><th class="fi-zahl">Ist</th><th class="fi-zahl">Abweichung</th><th>Erreicht</th></tr></thead>
        <tbody>
        <?php foreach (['Einnahmen' => [$soll_ein, $ist_jahr['umsatz']], 'Ausgaben' => [$soll_aus, $ist_jahr['ausgaben']]] as $l => [$soll, $istw]): ?>
            <tr>
                <td><?= $l ?></td>
                <td class="fi-zahl"><?= bccomp($soll, '0', 2) > 0 ? moneyFormat($soll) : '–' ?></td>
                <td class="fi-zahl"><?= moneyFormat($istw) ?></td>
                <td class="fi-zahl"><?= bccomp($soll, '0', 2) > 0 ? moneyFormat(bcsub($istw, $soll, 2)) : '–' ?></td>
                <td><?= $balken(bccomp($soll, '0', 2) > 0 ? round((float)$istw / (float)$soll * 100, 1) : null) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<h2 class="mg-abschnitt">Kurse</h2>
<div class="kpi-grid">
    <?= $karte('Laufende Kurse', (string)$ist['kurse'], $d('kurse'), '#1F3556') ?>
    <?= $karte('Teilnehmende (Personen)', (string)$ist['teilnehmende'], $d('teilnehmende'), '#22C55E') ?>
    <?= $karte('Auslastung', $ist['auslastung'] === null ? '–' : number_format($ist['auslastung'], 1, ',', '.') . ' %', $ist['auslastung'] === null ? '<span class="mg-delta">keine Kurse mit Platzlimit</span>' : $d('auslastung'), '#0EA5E9') ?>
    <?= $karte('Trainerkosten Kurse', $m('trainerkosten_kurse'), $d('trainerkosten_kurse', true), '#F59E0B') ?>
</div>
<?php if ($kurse): ?>
<div class="table-card" style="margin-top: 1rem;">
    <?php if ($ist['kurse'] > count($kurse)): ?><p class="mg-hinweis" style="padding-top: 1rem;">Die <?= count($kurse) ?> zuletzt gestarteten von <?= $ist['kurse'] ?> Kursen im Zeitraum. <a href="<?= APP_URL ?>/dashboard/kurse.php">Alle Kurse</a></p><?php endif; ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Kurs</th><th class="fi-zahl">Belegt</th><th class="fi-zahl">Warteliste</th><th>Auslastung</th><th class="fi-zahl">Preis</th></tr></thead>
        <tbody>
        <?php foreach ($kurse as $k): ?>
            <tr>
                <td><a href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= (int)$k['id'] ?>"><?= e($k['titel']) ?></a></td>
                <td class="fi-zahl"><?= (int)$k['belegt'] ?><?= $k['max_teilnehmer'] ? ' / ' . (int)$k['max_teilnehmer'] : '' ?></td>
                <td class="fi-zahl"><?= (int)$k['warteliste'] ?: '–' ?></td>
                <td><?= $balken($k['max_teilnehmer'] ? round((int)$k['belegt'] / (int)$k['max_teilnehmer'] * 100, 1) : null) ?></td>
                <td class="fi-zahl"><?= $k['preis'] > 0 ? moneyFormat($k['preis']) : 'frei' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<h2 class="mg-abschnitt">Trainer</h2>
<div class="kpi-grid">
    <?= $karte('Aktive Trainer (Stand heute)', (string)$stand['trainer_aktiv'], '', '#1F3556') ?>
    <?= $karte('Geleistete Einheiten', (string)$ist['einheiten'], $d('einheiten'), '#22C55E') ?>
    <?= $karte('Trainerstunden', number_format($ist['stunden'], 1, ',', '.') . ' h', $d('stunden'), '#0EA5E9') ?>
    <?= $karte('Offene Abrechnungen', moneyFormat($stand['abrechnungen_betrag']), '<span class="mg-delta">' . $stand['abrechnungen_offen'] . ' eingereicht/geprüft/freigegeben</span>', '#F59E0B') ?>
</div>
<?php if ($stand['qual_abgelaufen'] || $stand['qual_bald']): ?>
    <div class="alert alert-warning" style="margin-top: 1rem;">Qualifikationen: <?= $stand['qual_abgelaufen'] ?> abgelaufen, <?= $stand['qual_bald'] ?> laufen in den nächsten 60 Tagen ab. <a href="<?= APP_URL ?>/dashboard/qualifikationen.php">Ansehen</a></div>
<?php endif; ?>

<h2 class="mg-abschnitt">Projekte</h2>
<div class="kpi-grid">
    <?= $karte('Aktive Projekte', (string)$stand['projekte_aktiv'], '', '#1F3556') ?>
    <?= $karte('Offene Aufgaben', (string)$stand['aufgaben_offen'], '<span class="mg-delta">' . $stand['aufgaben_ueber'] . ' überfällig</span>', $stand['aufgaben_ueber'] ? '#EF4444' : '#22C55E') ?>
</div>
<div class="table-card" style="margin-top: 1rem;">
    <div class="table-card-header"><h2 class="table-card-title">Soll-Ist Projekte</h2></div>
    <?php if (!$projekte): ?>
        <p class="mg-hinweis" style="padding-top: 1rem;">Keine laufenden Projekte.</p>
    <?php else: ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Projekt</th><th>Status</th><th class="fi-zahl">Budget</th><th class="fi-zahl">Kosten</th><th class="fi-zahl">Rest</th><th>Verbrauch</th><th class="fi-zahl">Aufgaben</th></tr></thead>
        <tbody>
        <?php foreach ($projekte as $p): ?>
            <tr>
                <td><a href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><?php if ($p['nachname']): ?><br><small class="text-muted"><?= e($p['vorname'] . ' ' . $p['nachname']) ?></small><?php endif; ?></td>
                <td><?= e(ucfirst($p['status'])) ?></td>
                <td class="fi-zahl"><?= $p['budget'] !== null ? moneyFormat($p['budget']) : '–' ?></td>
                <td class="fi-zahl"><?= moneyFormat($p['kosten']) ?></td>
                <td class="fi-zahl" style="<?= $p['rest'] !== null && bccomp($p['rest'], '0', 2) < 0 ? 'color: #B91C1C; font-weight: 600;' : '' ?>"><?= $p['rest'] !== null ? moneyFormat($p['rest']) : '–' ?></td>
                <td><?= $balken($p['quote']) ?></td>
                <td class="fi-zahl"><?= (int)$p['aufgaben'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="mg-hinweis">Kosten = Ausgaben-Buchungen mit Projektzuordnung (gesamte Laufzeit).</p>
    <?php endif; ?>
</div>

<div class="mg-zwei" style="margin-top: 1.5rem;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Förderungen (Stand heute)</h2></div>
        <ul class="mg-liste">
            <li><span>Beantragt / in Arbeit</span><strong><?= moneyFormat($stand['foerder']['beantragt']) ?></strong></li>
            <li><span>Bewilligt (laufend)</span><strong><?= moneyFormat($stand['foerder']['bewilligt']) ?></strong></li>
            <li><span>Verbraucht</span><strong><?= moneyFormat($stand['foerder']['verbraucht']) ?></strong></li>
            <li><span>Verfügbar</span><strong style="<?= bccomp($stand['foerder']['verfuegbar'], '0', 2) < 0 ? 'color: #B91C1C;' : '' ?>"><?= moneyFormat($stand['foerder']['verfuegbar']) ?></strong></li>
            <li><span>Fristen in den nächsten 30 Tagen</span><strong><?= $stand['foerder']['fristen'] ?></strong></li>
        </ul>
    </div>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Kooperationen &amp; Partner</h2></div>
        <ul class="mg-liste">
            <li><span>Gemeindekooperationen</span><strong><?= $stand['kooperationen'] ?></strong></li>
            <?php foreach (PARTNER_KATEGORIEN as $kat => $label): if (empty($stand['partner'][$kat])) continue; ?>
                <li><span><?= e($label) ?></span><strong><?= (int)$stand['partner'][$kat] ?></strong></li>
            <?php endforeach; ?>
            <?php if (!$stand['partner']): ?><li><span class="text-muted">Keine aktiven Partner erfasst.</span></li><?php endif; ?>
        </ul>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
