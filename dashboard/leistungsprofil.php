<?php
/**
 * Athletikclub Steiermark – Leistungsprofil eines Mitglieds
 * Diagramme zur Leistungssteigerung je Test. Mitglieder sehen ihr eigenes Profil,
 * Trainer:innen das Profil jedes Mitglieds (?mitglied=ID).
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/leistung.php';

requireLogin();

$db      = getDB();
$trainer = isTrainer();
$mitglied_id = $trainer ? (int)($_GET['mitglied'] ?? 0) : (int)getCurrentUserId();
$mitglied = $mitglied_id ? ldMitglied($db, $mitglied_id) : null;

if ($trainer && !$mitglied) {
    // Auswahl, wessen Profil angezeigt werden soll
    $stmt = $db->prepare("SELECT u.id, u.vorname, u.nachname, COUNT(s.id) AS testungen, MAX(s.datum) AS zuletzt
                          FROM users u JOIN ld_sitzungen s ON s.mitglied_id = u.id AND s.status = 'durchgefuehrt'
                          WHERE u.organization_id = ? GROUP BY u.id, u.vorname, u.nachname ORDER BY u.nachname, u.vorname");
    $stmt->execute([currentOrgId()]);
    $getestet = $stmt->fetchAll();
}

$tests = ldTests($db, false);
$verlauf = $mitglied ? ldVerlauf($db, $mitglied_id) : [];
$filter_kat = isset(LD_KATEGORIEN[$_GET['kategorie'] ?? '']) ? $_GET['kategorie'] : '';

$karten = [];
foreach ($verlauf as $tid => $reihe) {
    if (!isset($tests[$tid]) || ($filter_kat && $tests[$tid]['kategorie'] !== $filter_kat)) continue;
    $karten[$tid] = ['test' => $tests[$tid], 'reihe' => $reihe, 'kz' => ldReihe($reihe, $tests[$tid])];
}
uasort($karten, fn($a, $b) => [$a['test']['sortierung'], $a['test']['name']] <=> [$b['test']['sortierung'], $b['test']['name']]);
$kategorien_mit_daten = array_unique(array_map(fn($tid) => $tests[$tid]['kategorie'] ?? '', array_keys($verlauf)));

$sitzungen = $geplant = [];
if ($mitglied) {
    $stmt = $db->prepare("SELECT s.*, (SELECT COUNT(*) FROM ld_ergebnisse e WHERE e.sitzung_id = s.id AND e.wert IS NOT NULL) AS gemessen
                          FROM ld_sitzungen s WHERE s.mitglied_id = ? AND s.organization_id = ? ORDER BY s.datum DESC, s.id DESC");
    $stmt->execute([$mitglied_id, currentOrgId()]);
    foreach ($stmt->fetchAll() as $s) {
        if ($s['status'] === 'geplant' && $s['datum'] >= date('Y-m-d')) $geplant[] = $s; else $sitzungen[] = $s;
    }
}
$bewertet = array_filter($karten, fn($k) => $k['kz']['gesamt'] !== null && $k['test']['richtung'] !== 'neutral');
$verbessert = count(array_filter($bewertet, fn($k) => $k['kz']['gesamt'] > 0));
$bestwerte = count(array_filter($karten, fn($k) => $k['kz']['ist_bestwert']));

$chart_daten = [];
foreach ($karten as $tid => $k) {
    $chart_daten[$tid] = [
        'labels' => array_map(fn($p) => date('d.m.y', strtotime($p['datum'])), $k['reihe']),
        'werte'  => array_map(fn($p) => round($p['wert'], 3), $k['reihe']),
        'einheit' => $k['test']['einheit'],
        'dezimalen' => (int)$k['test']['dezimalen'],
        'umgedreht' => $k['test']['richtung'] === 'niedriger',
    ];
}
$prozent = fn(?float $p) => $p === null ? '–' : ($p > 0 ? '+' : '') . number_format($p, 1, ',', '.') . ' %';

$page_title = $mitglied ? ($trainer ? 'Leistungsprofil ' . $mitglied['vorname'] . ' ' . $mitglied['nachname'] : 'Meine Leistungswerte') : 'Leistungsprofile';
$breadcrumb = 'Leistungsdiagnostik';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ld-karten { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; }
.ld-karte { background: var(--surface); border: 1px solid var(--border-light); border-radius: 1rem; padding: 1.1rem 1.2rem; box-shadow: var(--shadow-sm); }
.ld-karte h3 { font-family: var(--font-heading); font-size: 0.9rem; font-weight: 700; margin: 0; }
.ld-karte-kopf { display: flex; justify-content: space-between; gap: 0.75rem; align-items: flex-start; margin-bottom: 0.5rem; }
.ld-karte-wert { font-family: var(--font-heading); font-size: 1.35rem; font-weight: 800; white-space: nowrap; }
.ld-karte-zahlen { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.78rem; color: var(--text-muted); margin-top: 0.5rem; }
.ld-karte-zahlen strong { color: var(--text-primary); }
.ld-chart { position: relative; height: 170px; margin-top: 0.5rem; }
.ld-plus { color: var(--success); font-weight: 700; } .ld-minus { color: var(--danger); font-weight: 700; }
.ld-mini { font-size: 0.75rem; color: var(--text-muted); }
.ld-best { display: inline-block; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; background: var(--gold-dim); color: #8B6914; border-radius: 99px; padding: 1px 7px; }
:root[data-theme="dark"] .ld-best { color: #E8CE7A; }
.ld-naechste { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; padding: 0.85rem 1.1rem; margin-bottom: 1.25rem; border-radius: 0.75rem; background: var(--gold-dim); border-left: 4px solid var(--gold-accent); color: var(--text-primary); font-size: 0.9rem; }
.ld-chips { display: flex; gap: 0.4rem; flex-wrap: wrap; margin: 0 0 1.25rem; }
.ld-chips a { font-size: 0.78rem; padding: 0.35rem 0.8rem; border-radius: 99px; border: 1px solid var(--border-color); color: var(--text-secondary); }
.ld-chips a.aktiv { background: var(--navy-primary); border-color: var(--navy-primary); color: #fff; }
@media (max-width: 768px) { .ld-karten { grid-template-columns: 1fr; } }
</style>

<?php if (!$mitglied && $trainer): ?>
<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/leistungsdiagnostik.php" style="font-size: 0.8rem; color: var(--text-muted);">← Leistungsdiagnostik</a>
    <h1 class="dashboard-title">Leistungsprofile</h1>
    <p class="dashboard-subtitle">Mitglieder mit mindestens einer durchgeführten Testung.</p>
</div>
<div class="table-card">
    <?php if (!$getestet): ?>
        <div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Noch keine Testungen</h3><p><a href="<?= APP_URL ?>/dashboard/leistungsdiagnostik.php#neu">Erste Testung anlegen</a></p></div>
    <?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Mitglied</th><th>Testungen</th><th>Zuletzt</th></tr></thead>
        <tbody><?php foreach ($getestet as $g): ?>
            <tr data-row-href="<?= APP_URL ?>/dashboard/leistungsprofil.php?mitglied=<?= $g['id'] ?>"><td><a class="text-primary" href="<?= APP_URL ?>/dashboard/leistungsprofil.php?mitglied=<?= $g['id'] ?>"><?= e($g['vorname'] . ' ' . $g['nachname']) ?></a></td><td><?= (int)$g['testungen'] ?></td><td><?= date('d.m.Y', strtotime($g['zuletzt'])) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php elseif (!$mitglied): ?>
<div class="empty-state"><h3>Kein Profil gefunden</h3></div>

<?php else: ?>
<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <?php if ($trainer): ?>
        <a href="<?= APP_URL ?>/dashboard/mitglied-detail.php?id=<?= $mitglied_id ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <?= e($mitglied['vorname'] . ' ' . $mitglied['nachname']) ?>
        </a>
        <?php endif; ?>
        <h1 class="dashboard-title"><?= $trainer ? 'Leistungsprofil' : 'Meine Leistungswerte' ?></h1>
        <p class="dashboard-subtitle"><?= $trainer ? e($mitglied['vorname'] . ' ' . $mitglied['nachname']) . ' · ' : '' ?>Entwicklung aller Testergebnisse – grün heißt verbessert.</p>
    </div>
    <?php if ($trainer): ?><a href="<?= APP_URL ?>/dashboard/leistungsdiagnostik.php?mitglied=<?= $mitglied_id ?>#neu" class="btn btn-navy btn-sm">+ Neue Testung</a><?php endif; ?>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value"><?= count($sitzungen) ?></div><div class="kpi-label">Testungen</div></div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;"><div class="kpi-value"><?= count($verlauf) ?></div><div class="kpi-label">Getestete Disziplinen</div></div>
    <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value"><?= $bewertet ? $verbessert . ' / ' . count($bewertet) : '–' ?></div><div class="kpi-label">Verbessert seit Start</div></div>
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value"><?= $bestwerte ?></div><div class="kpi-label">Aktuelle Bestwerte</div></div>
</div>

<?php if ($geplant): ?>
<div class="ld-naechste">
    <span>Nächste Testung: <strong><?= date('d.m.Y', strtotime($geplant[count($geplant) - 1]['datum'])) ?></strong><?= $geplant[count($geplant) - 1]['uhrzeit'] ? ', ' . substr($geplant[count($geplant) - 1]['uhrzeit'], 0, 5) . ' Uhr' : '' ?><?= $geplant[count($geplant) - 1]['ort'] ? ' · ' . e($geplant[count($geplant) - 1]['ort']) : '' ?></span>
    <a href="<?= APP_URL ?>/dashboard/leistungstest.php?id=<?= $geplant[count($geplant) - 1]['id'] ?>" class="btn btn-ghost-light btn-sm">Details</a>
</div>
<?php endif; ?>

<?php if (!$verlauf): ?>
    <div class="table-card"><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Noch keine Messwerte</h3><p><?= $trainer ? 'Lege eine Testung an und trage die Ergebnisse ein.' : 'Sobald deine Trainer:in eine Testung einträgt, siehst du hier deine Entwicklung.' ?></p></div></div>
<?php else: ?>
    <div class="ld-chips">
        <a href="?<?= $trainer ? 'mitglied=' . $mitglied_id : '' ?>" class="<?= $filter_kat ? '' : 'aktiv' ?>">Alle</a>
        <?php foreach (LD_KATEGORIEN as $kat => $label): if (!in_array($kat, $kategorien_mit_daten, true)) continue; ?>
        <a href="?<?= $trainer ? 'mitglied=' . $mitglied_id . '&' : '' ?>kategorie=<?= $kat ?>" class="<?= $filter_kat === $kat ? 'aktiv' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="ld-karten">
        <?php foreach ($karten as $tid => $k): $t = $k['test']; $kz = $k['kz']; ?>
        <div class="ld-karte">
            <div class="ld-karte-kopf">
                <div>
                    <h3><?= e($t['name']) ?></h3>
                    <div class="ld-mini"><?= e(LD_KATEGORIEN[$t['kategorie']] ?? '') ?><?= $t['richtung'] === 'niedriger' ? ' · niedriger ist besser (im Diagramm oben)' : '' ?></div>
                </div>
                <div style="text-align: right;">
                    <div class="ld-karte-wert"><?= ldWert($kz['letzter'], $t) ?></div>
                    <?php if ($kz['ist_bestwert']): ?><span class="ld-best">Bestwert</span><?php endif; ?>
                </div>
            </div>
            <?php if ($kz['anzahl'] > 1): ?>
            <div class="ld-chart"><canvas id="ld-chart-<?= $tid ?>" aria-label="Verlauf <?= e($t['name']) ?>"></canvas></div>
            <?php endif; ?>
            <div class="ld-karte-zahlen">
                <?php if ($kz['anzahl'] > 1): ?>
                    <span>seit Start <?php if ($t['richtung'] === 'neutral'): ?><strong><?= ($kz['differenz'] > 0 ? '+' : '') . ldWert($kz['differenz'], $t) ?></strong><?php else: ?><strong class="<?= ($kz['gesamt'] ?? 0) >= 0 ? 'ld-plus' : 'ld-minus' ?>"><?= $prozent($kz['gesamt']) ?></strong><?php endif; ?></span>
                    <?php if ($kz['zuletzt'] !== null && $t['richtung'] !== 'neutral'): ?><span>zuletzt <strong class="<?= $kz['zuletzt'] >= 0 ? 'ld-plus' : 'ld-minus' ?>"><?= $prozent($kz['zuletzt']) ?></strong></span><?php endif; ?>
                    <span>Start <strong><?= ldWert($kz['erster'], $t) ?></strong></span>
                    <?php if ($t['richtung'] !== 'neutral'): ?><span>Bestwert <strong><?= ldWert($kz['bester'], $t) ?></strong></span><?php endif; ?>
                <?php else: ?>
                    <span>Erste Messung am <?= date('d.m.Y', strtotime($k['reihe'][0]['datum'])) ?> – beim nächsten Test siehst du hier die Entwicklung.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($sitzungen): ?>
<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Alle Testungen</h2></div>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Datum</th><th>Gemessene Tests</th><th>Ort</th><th></th></tr></thead>
        <tbody><?php foreach ($sitzungen as $s): ?>
            <tr data-row-href="<?= APP_URL ?>/dashboard/leistungstest.php?id=<?= $s['id'] ?>">
                <td><?= date('d.m.Y', strtotime($s['datum'])) ?></td><td><?= (int)$s['gemessen'] ?></td><td><?= e($s['ort'] ?? '–') ?></td>
                <td><a href="<?= APP_URL ?>/dashboard/leistungstest.php?id=<?= $s['id'] ?>" class="btn btn-ghost-light btn-sm">Ergebnisse</a></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>
<p class="form-hint" style="margin-top: 1rem;"><?= e(LD_HINWEIS) ?></p>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart) return;
    var D = <?= json_encode($chart_daten, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var css = getComputedStyle(document.documentElement);
    Chart.defaults.color = (css.getPropertyValue('--text-secondary') || '').trim() || '#4A5568';
    Chart.defaults.borderColor = (css.getPropertyValue('--border-light') || '').trim() || '#E2E8F0';
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Object.keys(D).forEach(function (id) {
        var el = document.getElementById('ld-chart-' + id);
        if (!el) return;
        var d = D[id];
        var fmt = function (v) { return Number(v).toLocaleString('de-AT', { minimumFractionDigits: d.dezimalen, maximumFractionDigits: d.dezimalen }) + ' ' + d.einheit; };
        new Chart(el, {
            type: 'line',
            data: { labels: d.labels, datasets: [{ data: d.werte, borderColor: '#C6A135', backgroundColor: 'rgba(198,161,53,0.15)', fill: true, tension: 0.25, pointRadius: 4, pointBackgroundColor: '#C6A135' }] },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return fmt(c.parsed.y); } } } },
                // „niedriger ist besser“: Achse umdrehen, damit oben immer „besser“ bedeutet
                scales: { y: { reverse: d.umgedreht, grace: '10%', ticks: { maxTicksLimit: 5, callback: function (v) { return Number(v).toLocaleString('de-AT'); } } }, x: { grid: { display: false } } }
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
