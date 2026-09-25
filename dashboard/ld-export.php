<?php
/**
 * Athletikclub Steiermark – Export der Leistungsdiagnostik für JASP
 *
 * JASP liest CSV mit Punkt als Dezimaltrennzeichen. Dateiname und Spalten-
 * namen bleiben stabil, damit JASP beim Überschreiben der Datei über die
 * Daten-Synchronisation alle Analysen automatisch neu berechnet.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/leistung.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$tests  = ldTests($db, false);

const LD_FORMATE = [
    'messungen'    => ['label' => 'Messungen (eine Zeile je Testung)', 'datei' => 'aci_leistung_messungen.csv',
                       'info' => 'Für Deskriptive Statistik, Korrelationen, Regression und Gruppenvergleiche.'],
    'wiederholung' => ['label' => 'Messwiederholung (eine Zeile je Person, T1, T2, …)', 'datei' => 'aci_leistung_messwiederholung.csv',
                       'info' => 'Für den t-Test bei abhängigen Stichproben (T1 vs. T2) und die ANOVA mit Messwiederholung.'],
    'lang'         => ['label' => 'Langformat (eine Zeile je Messwert)', 'datei' => 'aci_leistung_langformat.csv',
                       'info' => 'Für flexible Auswertungen, Pivot-Tabellen in Excel oder R/Python.'],
];

// Tests mit Messwerten (für die Auswahl)
$stmt = $db->prepare('SELECT e.test_id, COUNT(*) FROM ld_ergebnisse e JOIN ld_sitzungen s ON s.id = e.sitzung_id WHERE s.organization_id = ? AND e.wert IS NOT NULL GROUP BY e.test_id');
$stmt->execute([$org_id]);
$anzahl_werte = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
$tests_mit_daten = array_intersect_key($tests, $anzahl_werte);

if (isset($_GET['download'])) {
    $format = isset(LD_FORMATE[$_GET['format'] ?? '']) ? $_GET['format'] : 'messungen';
    $pseudonym = !isset($_GET['klarnamen']);
    $gewaehlt = array_values(array_intersect(array_map('intval', (array)($_GET['tests'] ?? [])), array_keys($tests_mit_daten)));
    if (!$gewaehlt) $gewaehlt = array_keys($tests_mit_daten);
    $auswahl = array_intersect_key($tests_mit_daten, array_flip($gewaehlt));
    uasort($auswahl, fn($a, $b) => [$a['sortierung'], $a['name']] <=> [$b['sortierung'], $b['name']]);
    $variablen = ldVariablen($auswahl);
    $messzeitpunkte = max(2, min(8, (int)($_GET['zeitpunkte'] ?? 3)));

    $where = "s.organization_id = ? AND s.status = 'durchgefuehrt'";
    $params = [$org_id];
    foreach (['von' => '>=', 'bis' => '<='] as $feld => $op) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$feld] ?? '')) { $where .= " AND s.datum {$op} ?"; $params[] = $_GET[$feld]; }
    }
    // Personenbezogener Export aller Mitglieder nur mit Recht „export.personen“, sonst nur eigene Testungen
    if (!empty($_GET['meine']) || !darf('export.personen')) { $where .= ' AND s.trainer_id = ?'; $params[] = getCurrentUserId(); }

    $stmt = $db->prepare("SELECT s.id, s.mitglied_id, s.trainer_id, s.datum, m.vorname, m.nachname, mp.geburtsdatum
                          FROM ld_sitzungen s JOIN users m ON m.id = s.mitglied_id LEFT JOIN mitglieder_profile mp ON mp.user_id = m.id
                          WHERE {$where} ORDER BY s.mitglied_id, s.datum, s.id");
    $stmt->execute($params);
    $sitzungen = $stmt->fetchAll();
    $werte = [];
    if ($sitzungen) {
        $ids = implode(',', array_map(fn($s) => (int)$s['id'], $sitzungen));
        foreach ($db->query("SELECT sitzung_id, test_id, wert FROM ld_ergebnisse WHERE wert IS NOT NULL AND sitzung_id IN ({$ids})")->fetchAll() as $r) {
            $werte[(int)$r['sitzung_id']][(int)$r['test_id']] = (float)$r['wert'];
        }
    }

    // Messzeitpunkt je Person (nur Testungen mit mindestens einem gewählten Test)
    $zeitpunkt = $start = [];
    foreach ($sitzungen as &$s) {
        $s['werte'] = array_intersect_key($werte[(int)$s['id']] ?? [], $auswahl);
        if (!$s['werte']) continue;
        $uid = (int)$s['mitglied_id'];
        $zeitpunkt[$uid] = ($zeitpunkt[$uid] ?? 0) + 1;
        $start[$uid] ??= $s['datum'];
        $s['mzp'] = $zeitpunkt[$uid];
        $s['tage'] = (int)round((strtotime($s['datum']) - strtotime($start[$uid])) / 86400);
        $s['alter'] = ($s['geburtsdatum'] && strtotime($s['geburtsdatum'])) ? (int)date_diff(date_create($s['geburtsdatum']), date_create($s['datum']))->y : null;
    }
    unset($s);
    $sitzungen = array_values(array_filter($sitzungen, fn($s) => !empty($s['werte'])));

    $person = fn($s) => $pseudonym ? ['P' . str_pad((string)$s['mitglied_id'], 4, '0', STR_PAD_LEFT)] : ['P' . str_pad((string)$s['mitglied_id'], 4, '0', STR_PAD_LEFT), $s['vorname'], $s['nachname']];
    $person_kopf = $pseudonym ? ['ID'] : ['ID', 'Vorname', 'Nachname'];
    $zahl = fn($w) => $w === null ? '' : rtrim(rtrim(number_format($w, 3, '.', ''), '0'), '.');

    $zeilen = [];
    if ($format === 'messungen') {
        $kopf = array_merge($person_kopf, ['Trainer', 'Datum', 'Messzeitpunkt', 'Tage_seit_Start', 'Alter', 'Altersgruppe'], array_values($variablen));
        foreach ($sitzungen as $s) {
            $zeile = array_merge($person($s), ['T' . (int)$s['trainer_id'], $s['datum'], $s['mzp'], $s['tage'], $s['alter'] ?? '', ldAltersgruppe($s['alter'])]);
            foreach ($variablen as $tid => $_) $zeile[] = $zahl($s['werte'][$tid] ?? null);
            $zeilen[] = $zeile;
        }
    } elseif ($format === 'wiederholung') {
        $kopf = array_merge($person_kopf, ['Alter_Start', 'Altersgruppe', 'Anzahl_Testungen']);
        foreach ($variablen as $var) for ($i = 1; $i <= $messzeitpunkte; $i++) $kopf[] = "{$var}_T{$i}";
        $je_person = [];
        foreach ($sitzungen as $s) $je_person[(int)$s['mitglied_id']][] = $s;
        foreach ($je_person as $liste) {
            $erste = $liste[0];
            $zeile = array_merge($person($erste), [$erste['alter'] ?? '', ldAltersgruppe($erste['alter']), count($liste)]);
            foreach ($variablen as $tid => $_) {
                // je Test die ersten n tatsächlich gemessenen Werte (T1 = erste Messung dieses Tests)
                $reihe = array_values(array_filter(array_map(fn($s) => $s['werte'][$tid] ?? null, $liste), fn($w) => $w !== null));
                for ($i = 0; $i < $messzeitpunkte; $i++) $zeile[] = $zahl($reihe[$i] ?? null);
            }
            $zeilen[] = $zeile;
        }
    } else {
        $kopf = array_merge($person_kopf, ['Trainer', 'Datum', 'Messzeitpunkt', 'Tage_seit_Start', 'Alter', 'Altersgruppe', 'Test', 'Kategorie', 'Einheit', 'Wert']);
        foreach ($sitzungen as $s) {
            foreach ($variablen as $tid => $var) {
                if (!isset($s['werte'][$tid])) continue;
                $zeilen[] = array_merge($person($s), ['T' . (int)$s['trainer_id'], $s['datum'], $s['mzp'], $s['tage'], $s['alter'] ?? '', ldAltersgruppe($s['alter']),
                                                       $var, $auswahl[$tid]['kategorie'], $auswahl[$tid]['einheit'], $zahl($s['werte'][$tid])]);
            }
        }
    }

    logActivity('leistung_export', "Format: {$format}, Zeilen: " . count($zeilen) . ($pseudonym ? ', pseudonymisiert' : ', mit Namen'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . LD_FORMATE[$format]['datei'] . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $kopf, ',', '"', '');
    foreach ($zeilen as $z) fputcsv($out, $z, ',', '"', '');
    fclose($out);
    exit;
}

$page_title = 'Export für JASP';
$breadcrumb = 'Leistungsdiagnostik';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ld-schritte { counter-reset: schritt; list-style: none; padding: 0; margin: 0; }
.ld-schritte li { counter-increment: schritt; position: relative; padding: 0 0 1rem 2.4rem; font-size: 0.9rem; line-height: 1.6; }
.ld-schritte li::before { content: counter(schritt); position: absolute; left: 0; top: 0; width: 1.7rem; height: 1.7rem; border-radius: 50%; background: var(--gold-dim); color: var(--gold-accent); font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; }
.ld-tests { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 0.2rem 1rem; }
.ld-tests label, .ld-option { display: flex; gap: 0.5rem; align-items: flex-start; font-size: 0.85rem; padding: 0.2rem 0; cursor: pointer; }
.ld-tests label input, .ld-option input { margin-top: 0.2rem; flex-shrink: 0; }
code.ld-var { font-size: 0.8rem; background: var(--bg-muted); padding: 1px 5px; border-radius: 4px; }
</style>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/leistungsdiagnostik.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Leistungsdiagnostik
    </a>
    <h1 class="dashboard-title">Export für JASP</h1>
    <p class="dashboard-subtitle">Messwerte als CSV für <a href="https://jasp-stats.org" target="_blank" rel="noopener">JASP</a> (kostenlose Statistiksoftware) – mit festen Datei- und Variablennamen, damit JASP deine Analysen bei jedem neuen Export automatisch aktualisiert.</p>
</div>

<div class="grid-2" style="align-items: start;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Export erstellen</h2></div>
        <div style="padding: 1.25rem;">
            <?php if (!$tests_mit_daten): ?>
                <p style="color: var(--text-muted);">Noch keine Messwerte vorhanden. Sobald Testungen eingetragen sind, kannst du sie hier exportieren.</p>
            <?php else: ?>
            <form method="GET">
                <input type="hidden" name="download" value="1">
                <div class="form-group">
                    <label class="form-label">Format</label>
                    <?php foreach (LD_FORMATE as $key => $f): ?>
                    <label class="ld-option"><input type="radio" name="format" value="<?= $key ?>" <?= $key === 'messungen' ? 'checked' : '' ?>>
                        <span><strong><?= e($f['label']) ?></strong><br><span style="color: var(--text-muted); font-size: 0.78rem;"><?= e($f['info']) ?> Datei: <code class="ld-var"><?= e($f['datei']) ?></code></span></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Messzeitpunkte (nur Messwiederholung)</label>
                        <select class="form-control" name="zeitpunkte"><?php for ($i = 2; $i <= 8; $i++): ?><option value="<?= $i ?>" <?= $i === 3 ? 'selected' : '' ?>>T1 bis T<?= $i ?></option><?php endfor; ?></select></div>
                    <div class="form-group"><label class="form-label">Zeitraum (optional)</label>
                        <div style="display: flex; gap: 0.5rem;"><input class="form-control" type="date" name="von" aria-label="von"><input class="form-control" type="date" name="bis" aria-label="bis"></div></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Tests</label>
                    <div class="ld-tests">
                        <?php foreach ($tests_mit_daten as $t): ?>
                        <label><input type="checkbox" name="tests[]" value="<?= $t['id'] ?>" checked> <span><?= e($t['name']) ?> <span style="color: var(--text-muted); font-size: 0.75rem;">(<?= $anzahl_werte[(int)$t['id']] ?> Werte)</span></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (darf('export.personen')): ?><label class="ld-option"><input type="checkbox" name="meine" value="1"> <span>Nur meine Testungen</span></label><?php else: ?><span class="ld-option" style="color: var(--text-muted);">Export umfasst deine eigenen Testungen.</span><?php endif; ?>
                <label class="ld-option"><input type="checkbox" name="klarnamen" value="1"> <span>Mit Vor- und Nachnamen exportieren <span style="color: var(--text-muted); font-size: 0.78rem;">(Standard: pseudonymisiert mit fester ID, z.B. P0012)</span></span></label>
                <button type="submit" class="btn btn-navy" style="margin-top: 1rem;">CSV herunterladen</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">So verknüpfst du JASP</h2></div>
        <div style="padding: 1.25rem;">
            <ol class="ld-schritte">
                <li><strong>JASP installieren</strong> – kostenlos auf <a href="https://jasp-stats.org/download/" target="_blank" rel="noopener">jasp-stats.org</a> (Windows, Mac, Linux).</li>
                <li><strong>Export herunterladen</strong> und in einem festen Ordner speichern, z.B. <code class="ld-var">Dokumente/ACI-Statistik</code>. Den Dateinamen nicht ändern.</li>
                <li><strong>In JASP öffnen:</strong> Menü ☰ → Öffnen → Computer → CSV-Datei auswählen. Die Variablen erscheinen als Spalten (z.B. <code class="ld-var">Klimmzuege_max</code>).</li>
                <li><strong>Analysen anlegen</strong>, z.B.
                    <br>· <em>Descriptives</em>: Mittelwert, Streuung, Boxplots je Test
                    <br>· <em>T-Tests → Paired Samples</em>: <code class="ld-var">…_T1</code> gegen <code class="ld-var">…_T2</code> (Format Messwiederholung) – hat sich die Gruppe signifikant verbessert?
                    <br>· <em>ANOVA → Repeated Measures</em>: T1, T2, T3 als Faktor „Zeit“
                    <br>· <em>Regression → Correlation</em>: z.B. Sprung vs. Sprint (Format Messungen)
                    <br>Das JASP-Projekt (<code class="ld-var">.jasp</code>) danach speichern.</li>
                <li><strong>Aktualisieren:</strong> neuen Export herunterladen und die alte Datei <em>überschreiben</em>. JASP übernimmt die neuen Daten und rechnet alle Analysen neu – sonst im Datenbereich „Daten synchronisieren“ (Strg+Y).</li>
            </ol>
            <p class="form-hint" style="margin: 0;">
                Datenschutz: Auch pseudonymisierte Daten sind personenbezogen. Exporte nur auf Vereinsgeräten speichern und nicht weitergeben.
                Die ID (P + Mitgliedsnummer) bleibt über alle Exporte gleich, damit Messwiederholungen zusammenpassen. Trainer:innen erscheinen als T + Nummer.
            </p>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
