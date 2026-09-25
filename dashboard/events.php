<?php
/**
 * Athletikclub Steiermark – Events (Sommerfest, Contest, Workshop, Schnuppertag …)
 *
 * Ein Event verbindet vorhandene Bausteine statt Daten doppelt zu führen:
 *   Projekt (Kategorie „Veranstaltung“): Verantwortliche:r, Team/Helfer, Aufgaben, Budget, Buchungen, Dokumente
 *   Anmelde-Kurs (art = event): Teilnehmerlimit, Warteliste, Anmeldeschluss, Alter, Preis, öffentliche Anmeldung, Check-in
 *   Einheit (typ = event): Kalender, eingeteilte Trainer:innen, Ressourcen, Anwesenheit
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kursanmeldung.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireDarf('events.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$self   = APP_URL . '/dashboard/events.php';
$bearb  = darf('events.bearbeiten');

/** Event (Kurs mit art = event) inkl. Projekt und erster Einheit laden. */
function eventLaden(PDO $db, int $kurs_id): ?array
{
    $stmt = $db->prepare("SELECT k.*, p.name AS projekt_name, p.leitung_id, p.budget, p.status AS projekt_status, u.vorname AS leitung_vorname, u.nachname AS leitung_nachname
                          FROM kurse k LEFT JOIN projekte p ON p.id = k.projekt_id LEFT JOIN users u ON u.id = p.leitung_id
                          WHERE k.id = ? AND k.organization_id = ? AND k.art = 'event'");
    $stmt->execute([$kurs_id, currentOrgId()]);
    $e = $stmt->fetch();
    if (!$e) return null;
    $stmt = $db->prepare("SELECT * FROM einheiten WHERE kurs_id = ? ORDER BY start LIMIT 1");
    $stmt->execute([$kurs_id]);
    $e['einheit'] = $stmt->fetch() ?: null;
    return $e;
}

/** Finanz- und Teilnahmekennzahlen eines Events (für Übersicht und Bericht). */
function eventKennzahlen(PDO $db, array $e): array
{
    $k = ['angemeldet' => 0, 'warteliste' => 0, 'angefragt' => 0, 'eingecheckt' => 0, 'einnahmen_teilnahme' => '0.00', 'einnahmen' => '0.00', 'ausgaben' => '0.00', 'helfer' => 0, 'aufgaben_offen' => 0];
    $stmt = $db->prepare('SELECT status, bezahlt, eingecheckt_am FROM kurs_anmeldungen WHERE kurs_id = ?');
    $stmt->execute([$e['id']]);
    foreach ($stmt->fetchAll() as $a) {
        if (in_array($a['status'], ['angemeldet', 'teilgenommen'], true)) $k['angemeldet']++;
        if (isset($k[$a['status']]) && in_array($a['status'], ['warteliste', 'angefragt'], true)) $k[$a['status']]++;
        if ($a['eingecheckt_am']) $k['eingecheckt']++;
        if ((int)$a['bezahlt'] && (float)$e['preis'] > 0) $k['einnahmen_teilnahme'] = bcadd($k['einnahmen_teilnahme'], moneyRound($e['preis']), 2);
    }
    if ($e['einheit']) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM anwesenheiten WHERE einheit_id = ? AND status IN ('anwesend','probetraining')");
        $stmt->execute([$e['einheit']['id']]);
        $k['eingecheckt'] = max($k['eingecheckt'], (int)$stmt->fetchColumn());
    }
    if ($e['projekt_id']) {
        $stmt = $db->prepare('SELECT art, betrag FROM buchungen WHERE projekt_id = ?');
        $stmt->execute([$e['projekt_id']]);
        foreach ($stmt->fetchAll() as $b) $k[$b['art'] === 'einnahme' ? 'einnahmen' : 'ausgaben'] = bcadd($k[$b['art'] === 'einnahme' ? 'einnahmen' : 'ausgaben'], moneyRound($b['betrag']), 2);
        $stmt = $db->prepare('SELECT COUNT(*) FROM projekt_team WHERE projekt_id = ?');
        $stmt->execute([$e['projekt_id']]);
        $k['helfer'] = (int)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM aufgaben WHERE projekt_id = ? AND status <> 'erledigt'");
        $stmt->execute([$e['projekt_id']]);
        $k['aufgaben_offen'] = (int)$stmt->fetchColumn();
    }
    $k['einnahmen_gesamt'] = bcadd($k['einnahmen'], $k['einnahmen_teilnahme'], 2);
    $k['ergebnis'] = bcsub($k['einnahmen_gesamt'], $k['ausgaben'], 2);
    return $k;
}

// ----------------------------------------------------------------
// Event anlegen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!$bearb) { flashMessage('error', 'Keine Berechtigung.'); redirect($self); }
    $t = fn($k, $max) => mb_substr(trim($_POST[$k] ?? ''), 0, $max);
    $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['datum'] ?? '') ? $_POST['datum'] : null;
    $datum_bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['datum_bis'] ?? '') ? $_POST['datum_bis'] : $datum;
    $von = preg_match('/^\d{2}:\d{2}$/', $_POST['von'] ?? '') ? $_POST['von'] : '10:00';
    $bis = preg_match('/^\d{2}:\d{2}$/', $_POST['bis'] ?? '') ? $_POST['bis'] : '16:00';
    $name = $t('name', 200);
    $leitung = (int)($_POST['leitung_id'] ?? 0) ?: $me;
    $start = "$datum $von:00";
    $ende = "$datum_bis $bis:00";
    $fehler = [];
    if (mb_strlen($name) < 3) $fehler[] = 'Bitte einen Namen angeben.';
    if (!$datum) $fehler[] = 'Bitte ein Datum angeben.';
    elseif ($ende <= $start) $fehler[] = 'Das Ende muss nach dem Beginn liegen.';
    $max = trim($_POST['max_teilnehmer'] ?? '');
    $preis = str_replace(',', '.', trim($_POST['preis'] ?? '0')) ?: '0';
    $budget = str_replace(',', '.', trim($_POST['budget'] ?? ''));
    if ($max !== '' && (!ctype_digit($max) || (int)$max < 1)) $fehler[] = 'Teilnehmerlimit ungültig.';
    if (!is_numeric($preis) || (float)$preis < 0) $fehler[] = 'Preis ungültig.';
    if ($budget !== '' && !is_numeric($budget)) $fehler[] = 'Budget ungültig.';
    if ($fehler) { flashMessage('error', implode(' ', $fehler)); redirect("$self?neu=1"); }
    $alter = fn($k) => ctype_digit(trim($_POST[$k] ?? '')) ? min(120, (int)$_POST[$k]) : null;
    $schluss = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_POST['anmeldeschluss'] ?? '') ? str_replace('T', ' ', $_POST['anmeldeschluss']) . ':00' : null;

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO projekte (organization_id, name, beschreibung, kategorie, status, leitung_id, start_datum, end_datum, budget, erstellt_von) VALUES (?, ?, ?, 'veranstaltung', 'aktiv', ?, ?, ?, ?, ?)")
           ->execute([$org_id, $name, trim($_POST['beschreibung'] ?? '') ?: null, $leitung, $datum, $datum_bis, $budget !== '' ? moneyRound($budget) : null, $me]);
        $projekt_id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO kurse (organization_id, titel, beschreibung, kurzbeschreibung, trainer_id, sportart, ort, start_datum, end_datum, max_teilnehmer, preis, status, erstellt_von,
                      anmeldeschluss, min_alter, max_alter, projekt_id, oeffentlich, art) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'geplant', ?, ?, ?, ?, ?, ?, 'event')")
           ->execute([$org_id, $name, trim($_POST['beschreibung'] ?? '') ?: null, $t('kurzbeschreibung', 300) ?: null, $leitung, $t('sportart', 60) ?: null, $t('ort', 150) ?: null,
                      $start, $ende, $max !== '' ? (int)$max : null, moneyRound($preis), $me, $schluss, $alter('min_alter'), $alter('max_alter'), $projekt_id, !empty($_POST['oeffentlich']) ? 1 : 0]);
        $kurs_id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO einheiten (organization_id, typ, titel, start, ende, ort, projekt_id, kurs_id, erwartete_teilnehmer, erstellt_von) VALUES (?, 'event', ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$org_id, $name, $start, $ende, $t('ort', 150) ?: null, $projekt_id, $kurs_id, $max !== '' ? (int)$max : null, $me]);
        $einheit_id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO einheit_trainer (einheit_id, user_id, rolle) VALUES (?, ?, 'leitung')")->execute([$einheit_id, $leitung]);
        $db->prepare('INSERT INTO projekt_team (projekt_id, user_id, rolle) VALUES (?, ?, ?)')->execute([$projekt_id, $leitung, 'Eventleitung']);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    auditLog('erstellt', 'kurse', $kurs_id, null, ['event' => $name, 'projekt_id' => $projekt_id, 'einheit_id' => $einheit_id], 'Event angelegt');
    if ($leitung !== $me) benachrichtigen($leitung, 'projekt', 'Du leitest das Event „' . $name . '“', date('d.m.Y', strtotime($start)), '/dashboard/events.php?id=' . $kurs_id);
    flashMessage('success', 'Event angelegt – Projekt, Anmeldung und Kalendertermin sind verknüpft.');
    redirect("$self?id=$kurs_id");
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$detail = !empty($_GET['id']) ? eventLaden($db, (int)$_GET['id']) : null;
$page_title = $detail ? $detail['titel'] : 'Events';
$breadcrumb = 'Events';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.ev-karten { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 1rem; }
.ev-karte { background: var(--surface, #fff); border: 1px solid var(--border-light); border-radius: 1rem; padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 0.45rem; color: var(--text-primary); }
.ev-karte:hover { border-color: var(--gold-accent); }
.ev-datum { font-family: 'Montserrat', sans-serif; font-weight: 800; color: var(--gold-accent); font-size: 0.8rem; letter-spacing: 0.05em; text-transform: uppercase; }
.ev-mini { font-size: 0.78rem; color: var(--text-muted); }
.ev-links { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.75rem; }
.ev-link { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.9rem 1rem; border: 1px solid var(--border-light); border-radius: 0.8rem; color: var(--text-primary); background: var(--surface, #fff); }
.ev-link:hover { border-color: var(--gold-accent); }
.ev-link strong { font-size: 0.95rem; }
</style>

<?php if (!empty($_GET['neu']) && $bearb): ?>
    <div class="dashboard-header"><a href="<?= $self ?>" class="ev-mini">← Events</a><h1 class="dashboard-title">Neues Event</h1><p class="dashboard-subtitle">Legt Projekt, Anmeldung und Kalendertermin in einem Schritt an.</p></div>
    <form method="POST" class="form-card" style="max-width: 820px;"><?= csrfField() ?>
        <div class="form-row"><div class="form-group" style="flex: 2;"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="200" placeholder="z.B. Skateboard Contest 2026"></div>
            <div class="form-group"><label class="form-label">Sportart</label><input class="form-control" name="sportart" maxlength="60"></div></div>
        <div class="form-group"><label class="form-label">Kurzbeschreibung</label><input class="form-control" name="kurzbeschreibung" maxlength="300"></div>
        <div class="form-group"><label class="form-label">Beschreibung</label><textarea class="form-control" name="beschreibung" rows="3"></textarea></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Datum *</label><input class="form-control" type="date" name="datum" required></div>
            <div class="form-group"><label class="form-label">von</label><input class="form-control" type="time" name="von" value="10:00"></div>
            <div class="form-group"><label class="form-label">bis Datum</label><input class="form-control" type="date" name="datum_bis"></div>
            <div class="form-group"><label class="form-label">bis</label><input class="form-control" type="time" name="bis" value="16:00"></div>
        </div>
        <div class="form-row"><div class="form-group"><label class="form-label">Ort</label><input class="form-control" name="ort" maxlength="150"></div>
            <div class="form-group"><label class="form-label">Verantwortlich</label><select class="form-control" name="leitung_id"><?php foreach (plattformTeam($db) as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$u['id'] === $me ? 'selected' : '' ?>><?= e($u['vorname'] . ' ' . $u['nachname']) ?></option><?php endforeach; ?></select></div></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Teilnehmerlimit</label><input class="form-control" type="number" min="1" name="max_teilnehmer" placeholder="leer = unbegrenzt"></div>
            <div class="form-group"><label class="form-label">Preis (€)</label><input class="form-control" name="preis" value="0" inputmode="decimal"><span class="form-hint">0 = kostenlos</span></div>
            <div class="form-group"><label class="form-label">Budget (€)</label><input class="form-control" name="budget" inputmode="decimal"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Anmeldeschluss</label><input class="form-control" type="datetime-local" name="anmeldeschluss"></div>
            <div class="form-group"><label class="form-label">Alter von – bis</label><div style="display: flex; gap: 0.4rem;"><input class="form-control" type="number" min="0" name="min_alter" placeholder="von"><input class="form-control" type="number" min="0" name="max_alter" placeholder="bis"></div></div>
        </div>
        <label class="form-check" style="margin-bottom: 1rem;"><input type="checkbox" name="oeffentlich" value="1" checked><span class="form-check-label">Öffentliche Anmeldung im Kursportal</span></label>
        <button class="btn btn-primary">Event anlegen</button>
    </form>

<?php elseif ($detail): $e = $detail; $k = eventKennzahlen($db, $e); $ein = $e['einheit']; ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div><a href="<?= $self ?>" class="ev-mini">← Events</a><h1 class="dashboard-title"><?= e($e['titel']) ?></h1>
            <p class="dashboard-subtitle"><?= date('d.m.Y, H:i', strtotime($e['start_datum'])) ?>–<?= date(substr($e['start_datum'], 0, 10) === substr($e['end_datum'], 0, 10) ? 'H:i' : 'd.m.Y, H:i', strtotime($e['end_datum'])) ?> Uhr<?= $e['ort'] ? ' · ' . e($e['ort']) : '' ?> · Verantwortlich: <?= e(trim(($e['leitung_vorname'] ?? '') . ' ' . ($e['leitung_nachname'] ?? '')) ?: '–') ?></p></div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if ($e['oeffentlich']): ?><a class="btn btn-ghost-light btn-sm" href="<?= e(kursOeffentlichLink((int)$e['id'])) ?>" target="_blank" rel="noopener">Öffentliche Seite</a><?php endif; ?>
            <a class="btn btn-navy btn-sm" href="<?= APP_URL ?>/dashboard/berichte.php?typ=eventbericht&id=<?= (int)$e['id'] ?>&format=pdf" target="_blank" rel="noopener">Eventbericht (PDF)</a>
        </div>
    </div>
    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value"><?= $k['angemeldet'] ?><?= $e['max_teilnehmer'] ? ' / ' . (int)$e['max_teilnehmer'] : '' ?></div><div class="kpi-label">Anmeldungen<?= $k['warteliste'] ? ' · ' . $k['warteliste'] . ' Warteliste' : '' ?><?= $k['angefragt'] ? ' · ' . $k['angefragt'] . ' unbestätigt' : '' ?></div></div>
        <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value"><?= $k['eingecheckt'] ?></div><div class="kpi-label">Eingecheckt / anwesend</div></div>
        <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value" style="font-size: 1.4rem;"><?= moneyFormat($k['ergebnis']) ?></div><div class="kpi-label">Ergebnis · Einnahmen <?= moneyFormat($k['einnahmen_gesamt']) ?> · Ausgaben <?= moneyFormat($k['ausgaben']) ?></div></div>
        <div class="kpi-card" style="--kpi-color: #7C3AED;"><div class="kpi-value" style="font-size: 1.4rem;"><?= $e['budget'] !== null ? moneyFormat(bcsub(moneyRound($e['budget']), $k['ausgaben'], 2)) : '–' ?></div><div class="kpi-label">Restbudget<?= $e['budget'] !== null ? ' von ' . moneyFormat($e['budget']) : '' ?></div></div>
    </div>
    <div class="ev-links">
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= (int)$e['id'] ?>"><strong>Teilnehmende</strong><span class="ev-mini">Anmeldungen, Warteliste, Zahlungen, Einstellungen</span></a>
        <?php if ($ein): ?><a class="ev-link" href="<?= APP_URL ?>/dashboard/checkin.php?einheit=<?= (int)$ein['id'] ?>"><strong>Check-in</strong><span class="ev-mini">QR-Code scannen oder Liste antippen</span></a>
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/einheit-planen.php?id=<?= (int)$ein['id'] ?>"><strong>Trainer:innen &amp; Ressourcen</strong><span class="ev-mini">Einteilung mit Überschneidungsprüfung, Material buchen</span></a><?php endif; ?>
        <?php if ($e['projekt_id']): ?>
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= (int)$e['projekt_id'] ?>&tab=team"><strong>Team &amp; Helfer (<?= $k['helfer'] ?>)</strong><span class="ev-mini">Helfer:innen mit Rolle eintragen</span></a>
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= (int)$e['projekt_id'] ?>&tab=aufgaben"><strong>Aufgaben (<?= $k['aufgaben_offen'] ?> offen)</strong><span class="ev-mini">To-dos mit Verantwortlichen und Fristen</span></a>
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= (int)$e['projekt_id'] ?>&tab=finanzen"><strong>Budget, Einnahmen, Ausgaben</strong><span class="ev-mini">Buchungen mit Belegen</span></a>
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= (int)$e['projekt_id'] ?>&tab=dokumente"><strong>Dokumente</strong><span class="ev-mini">Genehmigungen, Pläne, Fotos (PDF)</span></a>
        <?php endif; ?>
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/kommunikation.php?gruppe=event&ref_event=<?= (int)$e['id'] ?>"><strong>Nachricht an Teilnehmende</strong><span class="ev-mini">Infos, Änderungen, Dank</span></a>
        <a class="ev-link" href="<?= APP_URL ?>/dashboard/kurs-erstellen.php?id=<?= (int)$e['id'] ?>"><strong>Bearbeiten</strong><span class="ev-mini">Zeit, Ort, Limit, Preis, öffentliche Anmeldung</span></a>
    </div>

<?php else:
    $zeit = ($_GET['zeit'] ?? 'kommend') === 'vergangen' ? 'vergangen' : 'kommend';
    $stmt = $db->prepare("SELECT k.*, (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status IN ('angemeldet','teilgenommen')) AS anm
                          FROM kurse k WHERE k.organization_id = ? AND k.art = 'event' AND k.end_datum " . ($zeit === 'kommend' ? '>=' : '<') . " ? ORDER BY k.start_datum " . ($zeit === 'kommend' ? 'ASC' : 'DESC') . ' LIMIT 100');
    $stmt->execute([$org_id, date('Y-m-d H:i:s')]);
    $events = $stmt->fetchAll(); ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div><h1 class="dashboard-title">Events</h1><p class="dashboard-subtitle">Veranstaltungen mit Anmeldung, Check-in, Team, Aufgaben und Budget.</p></div>
        <?php if ($bearb): ?><a href="?neu=1" class="btn btn-primary btn-sm">+ Neues Event</a><?php endif; ?>
    </div>
    <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;"><a href="?zeit=kommend" class="btn <?= $zeit === 'kommend' ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm">Kommend</a><a href="?zeit=vergangen" class="btn <?= $zeit === 'vergangen' ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm">Vergangen</a></div>
    <?php if (!$events): ?><div class="table-card"><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Events</h3><p>Sommerfest, Contest oder Schnuppertag anlegen – Anmeldung und Planung sind gleich verknüpft.</p></div></div>
    <?php else: ?><div class="ev-karten"><?php foreach ($events as $ev): ?>
        <a class="ev-karte" href="?id=<?= (int)$ev['id'] ?>">
            <span class="ev-datum"><?= date('d.m.Y · H:i', strtotime($ev['start_datum'])) ?></span>
            <strong><?= e($ev['titel']) ?></strong>
            <span class="ev-mini"><?= e($ev['ort'] ?: '') ?></span>
            <span><span class="badge badge-navy"><?= (int)$ev['anm'] ?><?= $ev['max_teilnehmer'] ? ' / ' . (int)$ev['max_teilnehmer'] : '' ?> Anmeldungen</span> <?= (float)$ev['preis'] > 0 ? '<span class="badge badge-gold">' . moneyFormat($ev['preis']) . '</span>' : '<span class="badge badge-gray">kostenlos</span>' ?> <?= $ev['oeffentlich'] ? '<span class="badge badge-success">online</span>' : '' ?></span>
        </a>
    <?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
