<?php
/**
 * Athletikclub Steiermark – Terminkalender
 * Monats- und Listenansicht mit Kursen, Leistungsdiagnostik und eigenen Terminen.
 * Trainer:innen legen Termine an (auch wiederkehrend); alle können den Kalender
 * über einen persönlichen Link im Handy-/Outlook-Kalender abonnieren.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kalender.php';

requireLogin();

$db      = getDB();
$org_id  = currentOrgId();
$user_id = (int)getCurrentUserId();
$trainer = isTrainer();
$errors  = [];

$ansicht = in_array($_GET['ansicht'] ?? '', ['monat', 'woche', 'tag', 'liste'], true) ? $_GET['ansicht'] : 'monat';
$datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['datum'] ?? '') && strtotime($_GET['datum']) ? $_GET['datum']
       : (preg_match('/^\d{4}-\d{2}$/', $_GET['monat'] ?? '') ? $_GET['monat'] . '-01' : date('Y-m-d'));
$monat = substr($datum, 0, 7);
$quellen = isset($_GET['f']) ? array_values(array_intersect((array)($_GET['q'] ?? []), array_keys(KAL_QUELLEN))) : array_keys(KAL_QUELLEN);
// Filter „Trainer:in“: leer = alle sichtbaren, „meine“ = eigene Einsätze, Zahl = bestimmte Person (nur mit Planungsrecht)
$planer = darf('kalender.anzeigen');
$t_filter = $_GET['t'] ?? '';
$nur_trainer = $t_filter === 'meine' ? $user_id : ($planer && ctype_digit((string)$t_filter) ? (int)$t_filter : null);
$basis = fn(array $mehr = []) => APP_URL . '/dashboard/kalender.php?' . http_build_query(array_merge(['ansicht' => $ansicht, 'datum' => $datum],
    isset($_GET['f']) ? ['f' => 1, 'q' => $quellen] : [], $t_filter !== '' ? ['t' => $t_filter] : [], $mehr));

/** Termin laden (nur sichtbare), inkl. Bearbeitungsrecht. */
$termin_laden = function (int $id) use ($db, $org_id, $user_id, $trainer): ?array {
    try {
        $stmt = $db->prepare('SELECT * FROM termine WHERE id = ? AND organization_id = ?');
        $stmt->execute([$id, $org_id]);
        $t = $stmt->fetch();
    } catch (PDOException $e) { return null; }
    if (!$t) return null;
    $sichtbar = $trainer
        ? (in_array($t['sichtbar'], ['trainer', 'alle'], true) || (int)$t['erstellt_von'] === $user_id || (int)$t['mitglied_id'] === $user_id)
        : ($t['sichtbar'] === 'alle' || (int)$t['mitglied_id'] === $user_id);
    if (!$sichtbar) return null;
    $t['bearbeitbar'] = $trainer && ((int)$t['erstellt_von'] === $user_id || isAdmin());
    return $t;
};

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'termin_speichern' && $trainer) {
        $id = (int)($_POST['id'] ?? 0);
        $bestehend = $id ? $termin_laden($id) : null;
        if ($id && (!$bestehend || !$bestehend['bearbeitbar'])) {
            flashMessage('error', 'Diesen Termin kannst du nicht bearbeiten.');
            redirect($basis());
        }
        $titel    = mb_substr(trim($_POST['titel'] ?? ''), 0, 150);
        $t_datum    = $_POST['datum'] ?? '';
        $datum_bis = ($_POST['datum_bis'] ?? '') ?: $t_datum;
        $ganztags = !empty($_POST['ganztags']);
        $zeit_von = preg_match('/^\d{2}:\d{2}$/', $_POST['zeit_von'] ?? '') ? $_POST['zeit_von'] : '18:00';
        $zeit_bis = preg_match('/^\d{2}:\d{2}$/', $_POST['zeit_bis'] ?? '') ? $_POST['zeit_bis'] : '19:00';
        $wiederholung = isset(KAL_WIEDERHOLUNG[$_POST['wiederholung'] ?? '']) ? $_POST['wiederholung'] : 'keine';
        $wdh_bis  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['wiederholung_bis'] ?? '') ? $_POST['wiederholung_bis'] : null;
        $mitglied = (int)($_POST['mitglied_id'] ?? 0) ?: null;

        if ($titel === '') $errors['titel'] = 'Bitte einen Titel angeben.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t_datum) || !strtotime($t_datum)) $errors['datum'] = 'Bitte ein gültiges Datum angeben.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum_bis) || $datum_bis < $t_datum) $datum_bis = $t_datum;
        $start = $t_datum . ' ' . ($ganztags ? '00:00' : $zeit_von) . ':00';
        $ende  = $datum_bis . ' ' . ($ganztags ? '23:59' : $zeit_bis) . ':' . ($ganztags ? '59' : '00');
        if (!$ganztags && $ende <= $start) $errors['zeit'] = 'Das Ende muss nach dem Beginn liegen.';
        if ($wiederholung !== 'keine' && $wdh_bis && $wdh_bis < $t_datum) $errors['wiederholung_bis'] = 'Die Wiederholung muss nach dem ersten Termin enden.';
        if ($mitglied) {
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ? AND rolle = 'mitglied'");
            $stmt->execute([$mitglied, $org_id]);
            if (!$stmt->fetch()) $mitglied = null;
        }

        if (empty($errors)) {
            $werte = [$titel, isset(KAL_TYPEN[$_POST['typ'] ?? '']) ? $_POST['typ'] : 'termin', $start, $ende, (int)$ganztags,
                      mb_substr(trim($_POST['ort'] ?? ''), 0, 150) ?: null, trim($_POST['beschreibung'] ?? '') ?: null,
                      isset(KAL_SICHTBAR[$_POST['sichtbar'] ?? '']) ? $_POST['sichtbar'] : 'trainer', $mitglied,
                      $wiederholung, $wiederholung !== 'keine' ? $wdh_bis : null];
            if ($id) {
                $db->prepare('UPDATE termine SET titel = ?, typ = ?, start = ?, ende = ?, ganztags = ?, ort = ?, beschreibung = ?, sichtbar = ?, mitglied_id = ?, wiederholung = ?, wiederholung_bis = ? WHERE id = ? AND organization_id = ?')
                   ->execute(array_merge($werte, [$id, $org_id]));
            } else {
                $db->prepare('INSERT INTO termine (titel, typ, start, ende, ganztags, ort, beschreibung, sichtbar, mitglied_id, wiederholung, wiederholung_bis, organization_id, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute(array_merge($werte, [$org_id, $user_id]));
            }
            logActivity('termin_gespeichert', $titel);
            flashMessage('success', 'Termin „' . $titel . '“ gespeichert.');
            redirect(APP_URL . '/dashboard/kalender.php?monat=' . substr($t_datum, 0, 7));
        }
    }

    if ($action === 'termin_loeschen' && $trainer) {
        $t = $termin_laden((int)($_POST['id'] ?? 0));
        if ($t && $t['bearbeitbar']) {
            $db->prepare('DELETE FROM termine WHERE id = ? AND organization_id = ?')->execute([(int)$t['id'], $org_id]);
            flashMessage('success', 'Termin gelöscht' . ($t['wiederholung'] !== 'keine' ? ' (alle Wiederholungen).' : '.'));
        }
        redirect($basis());
    }

    if ($action === 'abo_erzeugen' || $action === 'abo_loeschen') {
        try {
            $db->prepare('DELETE FROM kalender_abos WHERE user_id = ?')->execute([$user_id]);
            if ($action === 'abo_erzeugen') {
                $db->prepare('INSERT INTO kalender_abos (user_id, token) VALUES (?, ?)')->execute([$user_id, bin2hex(random_bytes(32))]);
                flashMessage('success', 'Neuer Abo-Link erstellt. Ein zuvor verwendeter Link funktioniert nicht mehr.');
            } else {
                flashMessage('success', 'Abo-Link deaktiviert.');
            }
        } catch (PDOException $e) {
            flashMessage('error', 'Das Kalender-Abo ist noch nicht eingerichtet (Datenbank-Update 009 fehlt).');
        }
        redirect($basis() . '#abo');
    }
}

// ----------------------------------------------------------------
// Daten
// ----------------------------------------------------------------
$erster   = $monat . '-01';
$letzter  = date('Y-m-t', strtotime($erster));
$heute    = date('Y-m-d');
if ($ansicht === 'woche') {
    $raster_von = date('Y-m-d', strtotime('monday this week', strtotime($datum)));
    $raster_bis = date('Y-m-d', strtotime($raster_von . ' +6 days'));
} elseif ($ansicht === 'tag') {
    $raster_von = $raster_bis = $datum;
} else {
    $raster_von = date('Y-m-d', strtotime('monday this week', strtotime($erster)));
    $raster_bis = date('Y-m-d', strtotime('sunday this week', strtotime($letzter)));
}
$eintraege = kalEintraege($db, $org_id, $user_id, $trainer, $raster_von, $raster_bis, $quellen, ['alle_einheiten' => $planer, 'nur_trainer' => $nur_trainer]);
$je_tag    = kalJeTag($eintraege, $raster_von, $raster_bis);

$monate = [1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$monat_label = $monate[(int)substr($monat, 5, 2)] . ' ' . substr($monat, 0, 4);
$wochentage = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$wochentage_lang = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
[$vor, $nach, $titel_zeitraum] = match ($ansicht) {
    'woche' => [date('Y-m-d', strtotime($raster_von . ' -7 days')), date('Y-m-d', strtotime($raster_von . ' +7 days')),
                'KW ' . (int)date('W', strtotime($raster_von)) . ' · ' . date('d.m.', strtotime($raster_von)) . '–' . date('d.m.Y', strtotime($raster_bis))],
    'tag'   => [date('Y-m-d', strtotime($datum . ' -1 day')), date('Y-m-d', strtotime($datum . ' +1 day')),
                $wochentage_lang[(int)date('N', strtotime($datum))] . ', ' . date('d.m.Y', strtotime($datum))],
    default => [date('Y-m-d', strtotime($erster . ' -1 month')), date('Y-m-d', strtotime($erster . ' +1 month')), $monat_label],
};
$ist_heute = match ($ansicht) { 'woche' => $heute >= $raster_von && $heute <= $raster_bis, 'tag' => $datum === $heute, default => $monat === date('Y-m') };
$darf_planen = darf('kalender.erstellen') || $trainer;
$trainer_liste = $planer ? plattformTrainer($db) : [];

/** Einträge eines Tages für das Zeitraster (mit Spalten bei Überschneidung). */
$zeitraster = function (array $evs, string $tag): array {
    $evs = array_values(array_filter($evs, fn($e) => !$e['ganztags'] && substr($e['start'], 0, 10) <= $tag && substr($e['ende'], 0, 10) >= $tag));
    usort($evs, fn($a, $b) => $a['start'] <=> $b['start']);
    // Gruppen sich überschneidender Termine; innerhalb einer Gruppe nebeneinander
    $gruppe = $ergebnis = [];
    $gruppe_ende = 0;
    $abschliessen = function () use (&$gruppe, &$ergebnis) {
        $spalten = max(1, max(array_column($gruppe, '_spalte') ?: [0]) + 1);
        foreach ($gruppe as $g) { $g['_breite'] = 100 / $spalten; $ergebnis[] = $g; }
        $gruppe = [];
    };
    foreach ($evs as $e) {
        $s = max(strtotime($e['start']), strtotime($tag . ' 06:00'));
        $en = min(strtotime($e['ende']), strtotime($tag . ' 23:00'));
        if ($gruppe && $s >= $gruppe_ende) $abschliessen();
        $e['_top'] = max(0, ($s - strtotime($tag . ' 06:00')) / 60);
        $e['_hoehe'] = max(22, ($en - $s) / 60);
        $belegt = array_map(fn($g) => $g['_ende'] > $s ? $g['_spalte'] : -1, $gruppe);
        $spalte = 0;
        while (in_array($spalte, $belegt, true)) $spalte++;
        $e['_spalte'] = $spalte;
        $e['_ende'] = $en;
        $gruppe[] = $e;
        $gruppe_ende = max($gruppe_ende, $en);
    }
    if ($gruppe) $abschliessen();
    return $ergebnis;
};

// Termin-Formular / -Details
$termin = null;
if (!empty($_GET['termin'])) $termin = $termin_laden((int)$_GET['termin']);
$form = null;
if ($trainer && ($termin ? $termin['bearbeitbar'] : (isset($_GET['neu']) || !empty($errors)))) {
    $neu_datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['neu'] ?? '') ? $_GET['neu'] : ($monat === date('Y-m') ? $heute : $erster);
    $form = $termin ? [
        'id' => $termin['id'], 'titel' => $termin['titel'], 'typ' => $termin['typ'], 'datum' => substr($termin['start'], 0, 10),
        'datum_bis' => substr($termin['ende'], 0, 10) !== substr($termin['start'], 0, 10) ? substr($termin['ende'], 0, 10) : '',
        'ganztags' => (int)$termin['ganztags'], 'zeit_von' => substr($termin['start'], 11, 5), 'zeit_bis' => substr($termin['ende'], 11, 5),
        'ort' => $termin['ort'], 'beschreibung' => $termin['beschreibung'], 'sichtbar' => $termin['sichtbar'], 'mitglied_id' => $termin['mitglied_id'],
        'wiederholung' => $termin['wiederholung'], 'wiederholung_bis' => $termin['wiederholung_bis'],
    ] : ['id' => 0, 'titel' => '', 'typ' => 'termin', 'datum' => $neu_datum, 'datum_bis' => '', 'ganztags' => 0, 'zeit_von' => '18:00', 'zeit_bis' => '19:00',
         'ort' => '', 'beschreibung' => '', 'sichtbar' => 'trainer', 'mitglied_id' => '', 'wiederholung' => 'keine', 'wiederholung_bis' => ''];
    if (($_POST['action'] ?? '') === 'termin_speichern') $form = array_merge($form, $_POST, ['ganztags' => !empty($_POST['ganztags'])]);
}
$mitglieder = [];
if ($form) {
    $stmt = $db->prepare("SELECT id, vorname, nachname FROM users WHERE organization_id = ? AND rolle = 'mitglied' AND aktiv = 1 ORDER BY nachname, vorname");
    $stmt->execute([$org_id]);
    $mitglieder = $stmt->fetchAll();
}

$abo_token = null;
try {
    $stmt = $db->prepare('SELECT token FROM kalender_abos WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $abo_token = $stmt->fetchColumn() ?: null;
} catch (PDOException $e) {}
$abo_url = $abo_token ? APP_URL . '/dashboard/kalender-feed.php?token=' . $abo_token : null;

$v = fn($wert) => e((string)($wert ?? ''));
$page_title = 'Kalender';
$breadcrumb = 'Kalender';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.kal-kopf { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }
.kal-nav { display: flex; align-items: center; gap: 0.4rem; }
.kal-nav h2 { font-family: var(--font-heading); font-size: 1.15rem; font-weight: 800; text-transform: uppercase; margin: 0 0.5rem; min-width: 11rem; text-align: center; }
.kal-filter { display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; }
.kal-chip { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.78rem; padding: 0.3rem 0.75rem; border-radius: 99px; border: 1px solid var(--border-color); cursor: pointer; user-select: none; color: var(--text-secondary); }
.kal-chip input { display: none; }
.kal-chip i { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.kal-chip:has(input:not(:checked)) { opacity: 0.45; text-decoration: line-through; }
.kal-raster { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border: 1px solid var(--border-light); border-radius: 1rem; overflow: hidden; background: var(--surface); }
.kal-wt { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); padding: 0.55rem 0.6rem; border-bottom: 1px solid var(--border-light); background: var(--bg-muted); }
.kal-tag { min-height: 118px; padding: 0.35rem 0.4rem 0.5rem; border-right: 1px solid var(--border-light); border-bottom: 1px solid var(--border-light); display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.kal-tag:nth-child(7n + 7) { border-right: none; }
.kal-tag.fremd { background: var(--bg-muted); opacity: 0.6; }
.kal-tag-nr { display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem; font-weight: 600; color: var(--text-secondary); padding: 0 0.2rem 2px; }
.kal-tag.heute .kal-tag-nr span { background: var(--gold-accent); color: #1a1a1a; border-radius: 99px; padding: 0 0.45rem; }
.kal-tag-nr a.plus { opacity: 0; color: var(--text-muted); font-size: 1rem; line-height: 1; }
.kal-tag:hover .kal-tag-nr a.plus { opacity: 1; }
.kal-ev { display: block; font-size: 0.72rem; line-height: 1.3; padding: 2px 5px; border-radius: 5px; border-left: 3px solid var(--ev); background: color-mix(in srgb, var(--ev) 14%, transparent); color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kal-ev:hover { background: color-mix(in srgb, var(--ev) 26%, transparent); }
.kal-ev.abgesagt { text-decoration: line-through; opacity: 0.6; }
.kal-ev b { font-weight: 600; }
.kal-mehr { font-size: 0.7rem; color: var(--text-muted); padding-left: 5px; }
.kal-liste { display: flex; flex-direction: column; gap: 1rem; }
.kal-liste-tag h3 { font-family: var(--font-heading); font-size: 0.8rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin: 0 0 0.4rem; }
.kal-liste-tag.heute h3 { color: var(--gold-accent); }
.kal-liste-ev { display: grid; grid-template-columns: 5.5rem 1fr; gap: 0.75rem; padding: 0.7rem 0.9rem; background: var(--surface); border: 1px solid var(--border-light); border-left: 4px solid var(--ev); border-radius: 0.75rem; margin-bottom: 0.4rem; color: var(--text-primary); }
.kal-liste-ev .zeit { font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); font-variant-numeric: tabular-nums; }
.kal-liste-ev .titel { font-weight: 600; font-size: 0.9rem; }
.kal-liste-ev .info { font-size: 0.75rem; color: var(--text-muted); }
.kal-liste-ev.abgesagt .titel { text-decoration: line-through; }
.kal-ansicht-monat .kal-liste { display: none; }
.kal-ansicht-liste .kal-raster, .kal-ansicht-woche .kal-raster, .kal-ansicht-tag .kal-raster, .kal-ansicht-woche .kal-liste, .kal-ansicht-tag .kal-liste { display: none; }
.kal-ansichten { display: inline-flex; border: 1px solid var(--border-color); border-radius: 99px; overflow: hidden; }
.kal-ansichten a { padding: 0.4rem 0.9rem; font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); }
.kal-ansichten a.aktiv { background: var(--navy-primary); color: #fff; }
.kal-zeit { border: 1px solid var(--border-light); border-radius: 1rem; background: var(--surface); overflow: hidden; margin-bottom: 1rem; }
.kal-zeit-kopf, .kal-zeit-koerper { display: grid; grid-template-columns: 3.4rem repeat(var(--tage), minmax(0, 1fr)); }
.kal-zeit-kopf { border-bottom: 1px solid var(--border-light); background: var(--bg-muted); }
.kal-zeit-kopf > div { padding: 0.5rem 0.4rem; font-size: 0.78rem; color: var(--text-secondary); min-width: 0; }
.kal-zeit-kopf > div.heute strong { color: var(--gold-accent); }
.kal-zeit-skala span { display: block; height: 48px; font-size: 0.68rem; color: var(--text-muted); text-align: right; padding: 0 0.4rem; transform: translateY(-0.45em); }
.kal-zeit-spalte { position: relative; height: 816px; border-left: 1px solid var(--border-light); background-image: repeating-linear-gradient(to bottom, var(--border-light) 0, var(--border-light) 1px, transparent 1px, transparent 48px); }
.kal-zeit-spalte.heute { background-color: color-mix(in srgb, var(--gold-accent) 6%, transparent); }
.kal-zeit-neu { position: absolute; inset: 0; z-index: 0; }
.kal-zeit-ev { position: absolute; z-index: 1; overflow: hidden; font-size: 0.72rem; line-height: 1.25; padding: 3px 5px; border-radius: 6px; border-left: 3px solid var(--ev); background: color-mix(in srgb, var(--ev) 18%, var(--surface)); color: var(--text-primary); }
.kal-zeit-ev:hover { z-index: 2; box-shadow: var(--shadow-md); }
.kal-zeit-ev.abgesagt { text-decoration: line-through; opacity: 0.55; }
.kal-zeit-ev.erledigt::after { content: "✓"; position: absolute; right: 4px; top: 2px; color: var(--success); font-weight: 700; }
.kal-zeit-trainer { display: block; color: var(--text-muted); font-size: 0.68rem; }
@media (max-width: 768px) {
    .kal-ansicht-monat .kal-raster { display: none; }
    .kal-ansicht-monat .kal-liste, .kal-ansicht-woche .kal-liste { display: flex; }
    .kal-zeit-7 { display: none; }
    .kal-ansichten a { padding: 0.35rem 0.65rem; }
    .kal-nav h2 { min-width: 0; font-size: 1rem; }
    .kal-liste-ev { grid-template-columns: 4.5rem 1fr; }
}
</style>

<div class="dashboard-header" style="margin-bottom: 1rem;">
    <h1 class="dashboard-title">Kalender</h1>
    <p class="dashboard-subtitle"><?= $trainer ? 'Kurse, Leistungsdiagnostik und Vereinstermine auf einen Blick.' : 'Deine Kurse, Testungen und Vereinstermine.' ?></p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.25rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<div class="kal-kopf">
    <div class="kal-nav">
        <a href="<?= e($basis(['datum' => $vor])) ?>" class="btn btn-ghost-light btn-sm" aria-label="Zurück">‹</a>
        <h2><?= e($titel_zeitraum) ?></h2>
        <a href="<?= e($basis(['datum' => $nach])) ?>" class="btn btn-ghost-light btn-sm" aria-label="Weiter">›</a>
        <?php if (!$ist_heute): ?><a href="<?= e($basis(['datum' => $heute])) ?>" class="btn btn-ghost-light btn-sm">Heute</a><?php endif; ?>
    </div>
    <div class="kal-ansichten" role="tablist" aria-label="Ansicht">
        <?php foreach (['tag' => 'Tag', 'woche' => 'Woche', 'monat' => 'Monat', 'liste' => 'Liste'] as $a => $label): ?>
        <a href="<?= e($basis(['ansicht' => $a])) ?>" class="<?= $ansicht === $a ? 'aktiv' : '' ?>" role="tab" aria-selected="<?= $ansicht === $a ? 'true' : 'false' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>
<form method="GET" class="kal-filter" style="margin-bottom: 1rem;">
    <input type="hidden" name="datum" value="<?= e($datum) ?>"><input type="hidden" name="ansicht" value="<?= e($ansicht) ?>"><input type="hidden" name="f" value="1">
    <?php foreach (KAL_QUELLEN as $q => $info): ?>
    <label class="kal-chip"><input type="checkbox" name="q[]" value="<?= $q ?>" <?= in_array($q, $quellen, true) ? 'checked' : '' ?> onchange="this.form.submit()"><i style="background: <?= $info['farbe'] ?>"></i><?= e($info['label']) ?></label>
    <?php endforeach; ?>
    <?php if ($trainer): ?>
    <select class="form-control" name="t" onchange="this.form.submit()" style="max-width: 200px; padding-top: 0.3rem; padding-bottom: 0.3rem;" aria-label="Trainer:in">
        <option value="">Alle Einsätze</option>
        <option value="meine" <?= $t_filter === 'meine' ? 'selected' : '' ?>>Nur meine Einsätze</option>
        <?php foreach ($trainer_liste as $tr): ?><option value="<?= (int)$tr['id'] ?>" <?= (string)$t_filter === (string)$tr['id'] ? 'selected' : '' ?>><?= e($tr['vorname'] . ' ' . $tr['nachname']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <span style="flex: 1;"></span>
    <?php if ($darf_planen): ?><a href="<?= APP_URL ?>/dashboard/einheit-planen.php?datum=<?= e($ansicht === 'monat' && $monat !== date('Y-m') ? $erster : $datum) ?>" class="btn btn-navy btn-sm">+ Einheit planen</a><?php endif; ?>
    <?php if ($trainer): ?><a href="<?= e($basis(['neu' => $datum])) ?>#termin" class="btn btn-ghost-light btn-sm">+ Persönlicher Termin</a><?php endif; ?>
</form>

<?php if ($ansicht === 'woche' || $ansicht === 'tag'): $tage = []; for ($d = $raster_von; $d <= $raster_bis; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $tage[] = $d; ?>
<!-- Zeitraster (Tag/Woche) -->
<div class="kal-zeit kal-zeit-<?= count($tage) ?>" style="--tage: <?= count($tage) ?>;">
    <div class="kal-zeit-kopf"><div></div>
        <?php foreach ($tage as $d): $ganztags = array_filter($je_tag[$d] ?? [], fn($e) => $e['ganztags']); ?>
        <div class="<?= $d === $heute ? 'heute' : '' ?>">
            <a href="<?= e($basis(['ansicht' => 'tag', 'datum' => $d])) ?>"><?= $wochentage[(int)date('N', strtotime($d)) - 1] ?> <strong><?= date('d.m.', strtotime($d)) ?></strong></a>
            <?php foreach ($ganztags as $e): ?><a class="kal-ev" style="--ev: <?= $e['farbe'] ?>; margin-top: 3px;" href="<?= e(APP_URL . $e['url']) ?>"><?= e($e['titel']) ?></a><?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="kal-zeit-koerper">
        <div class="kal-zeit-skala"><?php for ($h = 6; $h < 23; $h++): ?><span><?= sprintf('%02d:00', $h) ?></span><?php endfor; ?></div>
        <?php foreach ($tage as $d): ?>
        <div class="kal-zeit-spalte <?= $d === $heute ? 'heute' : '' ?>">
            <?php if ($darf_planen): ?><a class="kal-zeit-neu" href="<?= APP_URL ?>/dashboard/einheit-planen.php?datum=<?= $d ?>" title="Einheit am <?= date('d.m.', strtotime($d)) ?> planen" aria-label="Einheit planen"></a><?php endif; ?>
            <?php foreach ($zeitraster($je_tag[$d] ?? [], $d) as $e): ?>
            <a class="kal-zeit-ev <?= $e['abgesagt'] ? 'abgesagt' : '' ?> <?= !empty($e['erledigt']) ? 'erledigt' : '' ?>" href="<?= e(APP_URL . $e['url']) ?>"
               style="--ev: <?= $e['farbe'] ?>; top: <?= round($e['_top'] * 0.8) ?>px; height: <?= round($e['_hoehe'] * 0.8) ?>px; left: <?= round($e['_spalte'] * $e['_breite'], 2) ?>%; width: calc(<?= round($e['_breite'], 2) ?>% - 3px);"
               title="<?= e(kalZeit($e) . ' · ' . $e['titel'] . ($e['ort'] ? ' · ' . $e['ort'] : '') . (!empty($e['trainer']) ? ' · ' . implode(', ', $e['trainer']) : '')) ?>">
                <b><?= date('H:i', strtotime($e['start'])) ?></b> <?= e($e['titel']) ?>
                <?php if (!empty($e['trainer'])): ?><span class="kal-zeit-trainer"><?= e(implode(', ', $e['trainer'])) ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="kal-ansicht-<?= $ansicht ?>">
    <!-- Monatsraster -->
    <div class="kal-raster" role="grid" aria-label="<?= e($monat_label) ?>">
        <?php foreach ($wochentage as $wt): ?><div class="kal-wt"><?= $wt ?></div><?php endforeach; ?>
        <?php for ($d = $raster_von; $d <= $raster_bis; $d = date('Y-m-d', strtotime($d . ' +1 day'))): $evs = $je_tag[$d] ?? []; ?>
        <div class="kal-tag <?= substr($d, 0, 7) !== $monat ? 'fremd' : '' ?> <?= $d === $heute ? 'heute' : '' ?>">
            <div class="kal-tag-nr"><span><?= (int)substr($d, 8, 2) ?></span><?php if ($darf_planen): ?><a class="plus" href="<?= APP_URL ?>/dashboard/einheit-planen.php?datum=<?= $d ?>" title="Einheit am <?= date('d.m.', strtotime($d)) ?> anlegen">+</a><?php endif; ?></div>
            <?php foreach (array_slice($evs, 0, 4) as $e): ?>
            <a class="kal-ev <?= $e['abgesagt'] ? 'abgesagt' : '' ?>" style="--ev: <?= $e['farbe'] ?>" href="<?= e(APP_URL . $e['url']) ?>" title="<?= e(kalZeit($e) . ' · ' . $e['titel'] . ($e['ort'] ? ' · ' . $e['ort'] : '')) ?>">
                <?php if (!$e['ganztags'] && substr($e['start'], 0, 10) === $d): ?><b><?= date('H:i', strtotime($e['start'])) ?></b> <?php endif; ?><?= e($e['titel']) ?>
            </a>
            <?php endforeach; ?>
            <?php if (count($evs) > 4): ?><a class="kal-mehr" href="<?= e($basis(['ansicht' => 'tag', 'datum' => $d])) ?>">+<?= count($evs) - 4 ?> weitere</a><?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Liste (Handy / Listenansicht) -->
    <div class="kal-liste">
        <?php $im_monat = array_filter($je_tag, fn($d) => in_array($ansicht, ['woche', 'tag'], true) ? ($d >= $raster_von && $d <= $raster_bis) : substr($d, 0, 7) === $monat, ARRAY_FILTER_USE_KEY); ksort($im_monat); ?>
        <?php if (!$im_monat): ?>
            <div class="table-card"><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Einträge (<?= e($titel_zeitraum) ?>)</h3><p><?= $trainer ? 'Plane mit „+ Einheit planen“ eine neue Einheit.' : 'Sobald du für Kurse angemeldet bist, erscheinen sie hier.' ?></p></div></div>
        <?php endif; ?>
        <?php foreach ($im_monat as $d => $evs): ?>
        <div class="kal-liste-tag <?= $d === $heute ? 'heute' : '' ?>" id="tag-<?= $d ?>">
            <h3><?= $wochentage_lang[(int)date('N', strtotime($d))] ?>, <?= date('d.m.Y', strtotime($d)) ?><?= $d === $heute ? ' · Heute' : '' ?></h3>
            <?php foreach ($evs as $e): ?>
            <a class="kal-liste-ev <?= $e['abgesagt'] ? 'abgesagt' : '' ?>" style="--ev: <?= $e['farbe'] ?>" href="<?= e(APP_URL . $e['url']) ?>">
                <span class="zeit"><?= e(kalZeit($e)) ?></span>
                <span>
                    <span class="titel"><?= e($e['titel']) ?></span><?= $e['abgesagt'] ? ' <span class="badge badge-danger">abgesagt</span>' : '' ?>
                    <span class="info" style="display: block;"><?= e($e['typ']) ?><?= $e['ort'] ? ' · ' . e($e['ort']) : '' ?><?= $e['wiederkehrend'] ? ' · wiederkehrend' : '' ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($termin && !$form): ?>
<!-- Termin-Details (nur lesen) -->
<div class="table-card" id="termin" style="margin-top: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title"><?= e($termin['titel']) ?></h2><span class="badge badge-gray"><?= e(KAL_TYPEN[$termin['typ']]['label'] ?? 'Termin') ?></span></div>
    <div style="padding: 1.25rem; line-height: 1.8;">
        <div><strong>Wann:</strong> <?= date('d.m.Y', strtotime($termin['start'])) ?><?= $termin['ganztags'] ? ' (ganztägig)' : ', ' . date('H:i', strtotime($termin['start'])) . '–' . date('H:i', strtotime($termin['ende'])) . ' Uhr' ?><?= $termin['wiederholung'] !== 'keine' ? ' · ' . e(KAL_WIEDERHOLUNG[$termin['wiederholung']]) . ($termin['wiederholung_bis'] ? ' bis ' . date('d.m.Y', strtotime($termin['wiederholung_bis'])) : '') : '' ?></div>
        <?php if ($termin['ort']): ?><div><strong>Wo:</strong> <?= e($termin['ort']) ?></div><?php endif; ?>
        <?php if ($termin['beschreibung']): ?><div style="white-space: pre-line; margin-top: 0.5rem;"><?= e($termin['beschreibung']) ?></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($form): ?>
<!-- Termin anlegen / bearbeiten -->
<div class="table-card" id="termin" style="margin-top: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title"><?= $form['id'] ? 'Termin bearbeiten' : 'Neuer Termin' ?></h2><a href="<?= e($basis()) ?>" class="btn btn-ghost-light btn-sm">Schließen</a></div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= e($basis()) ?>#termin">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="termin_speichern">
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Titel <span class="required">*</span></label><input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" name="titel" maxlength="150" value="<?= $v($form['titel']) ?>" required placeholder="z.B. Vorstandssitzung, Vereinsausflug"></div>
                <div class="form-group"><label class="form-label">Art</label>
                    <select class="form-control" name="typ"><?php foreach (KAL_TYPEN as $val => $info): ?><option value="<?= $val ?>" <?= $form['typ'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Datum <span class="required">*</span></label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;"><input class="form-control" type="date" name="datum" value="<?= $v($form['datum']) ?>" required><span style="color: var(--text-muted);">bis</span><input class="form-control" type="date" name="datum_bis" value="<?= $v($form['datum_bis']) ?>" title="Nur bei mehrtägigen Terminen"></div></div>
                <div class="form-group"><label class="form-label">Uhrzeit</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <input class="form-control" type="time" name="zeit_von" value="<?= $v($form['zeit_von']) ?>" style="max-width: 120px;"><span style="color: var(--text-muted);">–</span><input class="form-control" type="time" name="zeit_bis" value="<?= $v($form['zeit_bis']) ?>" style="max-width: 120px;">
                        <label style="display: flex; gap: 0.35rem; align-items: center; font-size: 0.85rem;"><input type="checkbox" name="ganztags" value="1" <?= $form['ganztags'] ? 'checked' : '' ?>> ganztägig</label>
                    </div></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Wiederholung</label>
                    <select class="form-control" name="wiederholung"><?php foreach (KAL_WIEDERHOLUNG as $val => $label): ?><option value="<?= $val ?>" <?= $form['wiederholung'] === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Wiederholen bis</label><input class="form-control" type="date" name="wiederholung_bis" value="<?= $v($form['wiederholung_bis']) ?>"><p class="form-hint">Leer = ohne Enddatum</p></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Ort</label><input class="form-control" type="text" name="ort" maxlength="150" value="<?= $v($form['ort']) ?>"></div>
                <div class="form-group"><label class="form-label">Sichtbar für</label>
                    <select class="form-control" name="sichtbar"><?php foreach (KAL_SICHTBAR as $val => $label): ?><option value="<?= $val ?>" <?= $form['sichtbar'] === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Mit Mitglied (optional)</label>
                <select class="form-control" name="mitglied_id"><option value="">– kein bestimmtes Mitglied –</option><?php foreach ($mitglieder as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$form['mitglied_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option><?php endforeach; ?></select>
                <p class="form-hint">Das Mitglied sieht den Termin dann in seinem Kalender (z.B. Einzeltraining, Beratung).</p></div>
            <div class="form-group"><label class="form-label">Beschreibung</label><textarea class="form-control" name="beschreibung" rows="3"><?= $v($form['beschreibung']) ?></textarea></div>
            <button type="submit" class="btn btn-navy"><?= $form['id'] ? 'Änderungen speichern' : 'Termin anlegen' ?></button>
        </form>
        <?php if ($form['id']): ?>
        <form method="POST" style="margin-top: 1rem;" onsubmit="return confirm('Termin<?= $form['wiederholung'] !== 'keine' ? ' mit allen Wiederholungen' : '' ?> löschen?')">
            <?= csrfField() ?><input type="hidden" name="action" value="termin_loeschen"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Termin löschen</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Kalender-Abo -->
<div class="table-card" id="abo" style="margin-top: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Im Handy-Kalender abonnieren</h2></div>
    <div style="padding: 1.25rem;">
        <?php if ($abo_url): ?>
            <p style="font-size: 0.9rem; margin-bottom: 0.75rem;">Dein persönlicher Link – der Kalender auf deinem Handy oder in Outlook aktualisiert sich damit automatisch (je nach App alle paar Stunden):</p>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <input class="form-control" type="text" id="abo-url" value="<?= e($abo_url) ?>" readonly style="flex: 1; min-width: 240px; font-size: 0.8rem;" onclick="this.select()">
                <button type="button" class="btn btn-ghost-light btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('abo-url').value).then(function () { this.textContent = 'Kopiert ✓'; }.bind(this))">Kopieren</button>
                <a class="btn btn-navy btn-sm" href="<?= e(preg_replace('#^https?://#', 'webcal://', $abo_url)) ?>">Direkt abonnieren</a>
            </div>
            <ul style="font-size: 0.82rem; color: var(--text-muted); margin: 1rem 0 0; padding-left: 1.1rem; line-height: 1.7;">
                <li><strong>iPhone:</strong> „Direkt abonnieren“ antippen – oder Einstellungen → Kalender → Accounts → Account hinzufügen → Andere → Kalenderabo hinzufügen → Link einfügen.</li>
                <li><strong>Android / Google Kalender:</strong> am Computer calendar.google.com → „Weitere Kalender +“ → „Per URL“ → Link einfügen. Erscheint danach auch am Handy.</li>
                <li><strong>Outlook:</strong> Kalender hinzufügen → „Aus dem Internet“ → Link einfügen.</li>
            </ul>
            <div style="display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap;">
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="abo_erzeugen"><button type="submit" class="btn btn-ghost-light btn-sm" onclick="return confirm('Neuen Link erzeugen? Der alte Link funktioniert dann nicht mehr.')">Neuen Link erzeugen</button></form>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="abo_loeschen"><button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Abo deaktivieren</button></form>
            </div>
            <p class="form-hint">Den Link nicht weitergeben – wer ihn kennt, sieht deine Termine (nur lesend).</p>
        <?php else: ?>
            <p style="font-size: 0.9rem; margin-bottom: 1rem;">Erstelle einen persönlichen Link, um <?= $trainer ? 'Kurse, Testungen und Vereinstermine' : 'deine Kurse, Testungen und Vereinstermine' ?> in deinem Handy-, Google- oder Outlook-Kalender zu sehen. Er aktualisiert sich automatisch.</p>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="abo_erzeugen"><button type="submit" class="btn btn-navy btn-sm">Abo-Link erstellen</button></form>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
