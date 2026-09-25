<?php
/**
 * Athletikclub Steiermark – Kursdetail (Dashboard)
 * Anmeldung (selbst oder Kind) mit Anmeldeschluss, Altersgrenzen, Voraussetzungen,
 * Warteliste mit automatischem Nachrücken, Teilnehmerverwaltung und Kurs-Einheiten.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kursanmeldung.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireLogin();

$db      = getDB();
$user    = getCurrentUser();
$kurs_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    "SELECT k.*, u.vorname AS trainer_vorname, u.nachname AS trainer_nachname
     FROM kurse k LEFT JOIN users u ON k.trainer_id = u.id
     WHERE k.id = ? AND k.organization_id = ? LIMIT 1"
);
$stmt->execute([$kurs_id, currentOrgId()]);
$kurs = $stmt->fetch();

if (!$kurs) {
    flashMessage('error', 'Kurs nicht gefunden.');
    redirect(APP_URL . '/dashboard/kurse.php');
}

$ist_eigentuemer = isAdmin() || (isTrainer() && (int)$kurs['trainer_id'] === (int)$user['id']);
$zurueck = APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id;
$kinder  = meineKinder($db, (int)$user['id']);

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // Für wen? 0 = ich selbst, sonst eigenes Kind
    $kind_id = (int)($_POST['kind_id'] ?? 0);
    $kind = null;
    if ($kind_id) {
        foreach ($kinder as $k) if ((int)$k['id'] === $kind_id) $kind = $k;
        if (!$kind) {
            flashMessage('error', 'Dieses Kind ist nicht in deinem Konto hinterlegt.');
            redirect($zurueck);
        }
    }

    if ($action === 'anmelden') {
        if ($ist_eigentuemer && !$kind) {
            flashMessage('error', 'Als Kursleitung kannst du dich nicht selbst anmelden.');
            redirect($zurueck);
        }
        if ($fehler = kursAnmeldungFehler($db, $kurs, $user, $kind, !empty($_POST['voraussetzungen_ok']))) {
            flashMessage('error', $fehler);
            redirect($zurueck);
        }
        $r = kursAnmelden($db, $kurs, (int)$user['id'], $kind_id);
        logActivity('kurs_anmeldung', "Kurs-ID: {$kurs_id}" . ($kind_id ? ", Kind-ID: {$kind_id}" : ''));
        $wer = $kind ? $kind['vorname'] . ' ist' : 'Du bist';
        if ($r['schon']) flashMessage('info', $wer . ' bereits ' . ($r['status'] === 'warteliste' ? 'auf der Warteliste.' : 'angemeldet.'));
        elseif ($r['status'] === 'warteliste') flashMessage('success', 'Der Kurs ist voll – ' . ($kind ? $kind['vorname'] . ' steht' : 'du stehst') . ' auf der Warteliste (Platz ' . $r['position'] . '). Wird ein Platz frei, erfolgt das Nachrücken automatisch – mit Benachrichtigung.');
        else flashMessage('success', 'Anmeldung erfolgreich! ' . $wer . ' fix dabei.');
        redirect($zurueck);
    }

    if ($action === 'abmelden') {
        $meine = meineKursAnmeldungen($db, $kurs_id, (int)$user['id']);
        $a = $meine[$kind_id] ?? null;
        if ($a && in_array($a['status'], ['angemeldet', 'warteliste'], true)) {
            kursStornieren($db, $kurs, $a);
            logActivity('kurs_abmeldung', "Kurs-ID: {$kurs_id}" . ($kind_id ? ", Kind-ID: {$kind_id}" : ''));
            flashMessage('success', $a['status'] === 'warteliste' ? 'Von der Warteliste entfernt.' : 'Abmeldung durchgeführt.');
        }
        redirect($zurueck);
    }

    // Ab hier nur Trainer (Eigentümer) / Admin
    if (!$ist_eigentuemer) {
        flashMessage('error', 'Keine Berechtigung für diese Aktion.');
        redirect($zurueck);
    }

    if ($action === 'status_aendern') {
        $neuer_status = $_POST['status'] ?? '';
        if (in_array($neuer_status, ['geplant', 'aktiv', 'abgesagt', 'abgeschlossen'], true) && $neuer_status !== $kurs['status']) {
            $db->prepare('UPDATE kurse SET status = ? WHERE id = ?')->execute([$neuer_status, $kurs_id]);
            logActivity('kurs_status_geaendert', "Kurs-ID: {$kurs_id} -> {$neuer_status}");
            auditLog('status', 'kurse', $kurs_id, ['status' => $kurs['status']], ['status' => $neuer_status]);
            if ($neuer_status === 'abgesagt') {
                $stmt = $db->prepare("SELECT DISTINCT user_id FROM kurs_anmeldungen WHERE kurs_id = ? AND status IN ('angemeldet','warteliste')");
                $stmt->execute([$kurs_id]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    benachrichtigen((int)$uid, 'kurs', 'Kurs abgesagt: ' . $kurs['titel'], 'Der Kurs ab ' . date('d.m.Y', strtotime($kurs['start_datum'])) . ' findet nicht statt.', '/dashboard/kurs-detail.php?id=' . $kurs_id);
                }
            }
            flashMessage('success', 'Status aktualisiert.');
        }
        redirect($zurueck);
    }

    if ($action === 'teilnehmer_status') {
        $anmeldung_id  = (int)($_POST['anmeldung_id'] ?? 0);
        $neuer_status  = $_POST['teilnehmer_neuer_status'] ?? '';
        $stmt = $db->prepare('SELECT * FROM kurs_anmeldungen WHERE id = ? AND kurs_id = ?');
        $stmt->execute([$anmeldung_id, $kurs_id]);
        $a = $stmt->fetch();
        if ($a && in_array($neuer_status, ['angemeldet', 'warteliste', 'storniert', 'teilgenommen'], true) && $neuer_status !== $a['status']) {
            $db->prepare('UPDATE kurs_anmeldungen SET status = ? WHERE id = ?')->execute([$neuer_status, $anmeldung_id]);
            logActivity('teilnehmer_status_geaendert', "Anmeldung-ID: {$anmeldung_id} -> {$neuer_status}");
            auditLog('status', 'kurs_anmeldungen', $anmeldung_id, ['status' => $a['status']], ['status' => $neuer_status]);
            if ($a['status'] === 'warteliste' && $neuer_status === 'angemeldet') {
                benachrichtigen((int)$a['user_id'], 'kurs', 'Platz frei: ' . $kurs['titel'], 'Die Anmeldung wurde von der Warteliste übernommen.', '/dashboard/kurs-detail.php?id=' . $kurs_id);
            }
            // Platz frei geworden → Warteliste nachziehen
            if ($a['status'] === 'angemeldet' && in_array($neuer_status, ['storniert', 'warteliste'], true)) {
                $n = kursNachruecken($db, $kurs);
                if ($n) flashMessage('success', count($n) . ' Person(en) von der Warteliste nachgerückt.');
            }
        }
        redirect($zurueck);
    }

    if ($action === 'nachruecken') {
        $n = kursNachruecken($db, $kurs);
        flashMessage($n ? 'success' : 'info', $n ? count($n) . ' Person(en) von der Warteliste nachgerückt.' : 'Keine freien Plätze oder niemand auf der Warteliste.');
        redirect($zurueck);
    }

    if ($action === 'bezahlt_umschalten') {
        $anmeldung_id = (int)($_POST['anmeldung_id'] ?? 0);
        $stmt = $db->prepare('SELECT bezahlt FROM kurs_anmeldungen WHERE id = ? AND kurs_id = ?');
        $stmt->execute([$anmeldung_id, $kurs_id]);
        $bezahlt = $stmt->fetchColumn();
        if ($bezahlt !== false) {
            $neu = (int)$bezahlt ? 0 : 1;
            $db->prepare('UPDATE kurs_anmeldungen SET bezahlt = ?, bezahlt_am = ? WHERE id = ?')
               ->execute([$neu, $neu ? date('Y-m-d H:i:s') : null, $anmeldung_id]);
            logActivity('teilnehmer_bezahlt_umgeschaltet', "Anmeldung-ID: {$anmeldung_id}");
            auditLog('geaendert', 'kurs_anmeldungen', $anmeldung_id, ['bezahlt' => (int)$bezahlt], ['bezahlt' => $neu]);
        }
        redirect($zurueck);
    }
}

// ----------------------------------------------------------------
// Daten für Anzeige laden
// ----------------------------------------------------------------
$anmeldungen = [];
if ($ist_eigentuemer) {
    $stmt = $db->prepare(
        "SELECT ka.*, u.vorname, u.nachname, u.email, mp.geburtsdatum,
                ki.vorname AS kind_vorname, ki.nachname AS kind_nachname, ki.geburtsdatum AS kind_geburtsdatum, ki.hinweise AS kind_hinweise
         FROM kurs_anmeldungen ka JOIN users u ON ka.user_id = u.id LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id LEFT JOIN kinder ki ON ki.id = ka.kind_id
         WHERE ka.kurs_id = ?
         ORDER BY CASE ka.status WHEN 'angemeldet' THEN 0 WHEN 'teilgenommen' THEN 1 WHEN 'warteliste' THEN 2 ELSE 3 END, ka.angemeldet_am ASC, ka.id ASC"
    );
    $stmt->execute([$kurs_id]);
    $anmeldungen = $stmt->fetchAll();
}

$belegt      = kursBelegt($db, $kurs_id);
$warteliste  = kursWarteliste($db, $kurs_id);
$meine       = meineKursAnmeldungen($db, $kurs_id, (int)$user['id']);
$geschlossen = kursAnmeldungGeschlossen($kurs);
$voll        = $kurs['max_teilnehmer'] && $belegt >= (int)$kurs['max_teilnehmer'];

// Einheiten des Kurses (Termine)
$einheiten = [];
try {
    $stmt = $db->prepare('SELECT e.* FROM einheiten e WHERE e.kurs_id = ? AND e.organization_id = ? ORDER BY e.start');
    $stmt->execute([$kurs_id, currentOrgId()]);
    $einheiten = $stmt->fetchAll();
    if ($einheiten) {
        $namen = [];
        $ids = array_map(fn($x) => (int)$x['id'], $einheiten);
        $stmt = $db->query('SELECT et.einheit_id, u.vorname FROM einheit_trainer et JOIN users u ON u.id = et.user_id WHERE et.einheit_id IN (' . implode(',', $ids) . ') ORDER BY u.vorname');
        foreach ($stmt->fetchAll() as $r) $namen[(int)$r['einheit_id']][] = $r['vorname'];
        foreach ($einheiten as &$x) $x['trainer_namen'] = implode(', ', $namen[(int)$x['id']] ?? []);
        unset($x);
    }
} catch (Exception $e) {
    $einheiten = [];
}
$darf_einheiten = $ist_eigentuemer || einheitDarfBearbeiten($db, ['kurs_id' => $kurs_id, 'projekt_id' => $kurs['projekt_id'] ?? null]);
$einheiten_sehen = $darf_einheiten || darf('kalender.anzeigen') || array_filter($meine, fn($a) => in_array($a['status'], ['angemeldet', 'teilgenommen'], true));

$projekt = null;
if (!empty($kurs['projekt_id'])) {
    $stmt = $db->prepare('SELECT id, name FROM projekte WHERE id = ?');
    $stmt->execute([$kurs['projekt_id']]);
    $projekt = $stmt->fetch() ?: null;
}

$page_title = $kurs['titel'];
$breadcrumb = 'Kurse';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$status_map = [
    'geplant'       => ['label' => 'Geplant',       'class' => 'badge-info'],
    'aktiv'         => ['label' => 'Aktiv',         'class' => 'badge-success'],
    'abgesagt'      => ['label' => 'Abgesagt',      'class' => 'badge-danger'],
    'abgeschlossen' => ['label' => 'Abgeschlossen', 'class' => 'badge-gray'],
];
$teilnehmer_status_labels = [
    'angemeldet'   => ['label' => 'Angemeldet',  'class' => 'badge-success'],
    'warteliste'   => ['label' => 'Warteliste',  'class' => 'badge-info'],
    'storniert'    => ['label' => 'Storniert',   'class' => 'badge-danger'],
    'teilgenommen' => ['label' => 'Teilgenommen','class' => 'badge-gray'],
];
$einheit_status = ['geplant' => 'badge-info', 'durchgefuehrt' => 'badge-success', 'storniert' => 'badge-danger'];
$alter_text = '';
if ($kurs['min_alter'] !== null || $kurs['max_alter'] !== null) {
    if ($kurs['min_alter'] !== null && $kurs['max_alter'] !== null) $alter_text = (int)$kurs['min_alter'] . '–' . (int)$kurs['max_alter'] . ' Jahre';
    elseif ($kurs['min_alter'] !== null) $alter_text = 'ab ' . (int)$kurs['min_alter'] . ' Jahren';
    else $alter_text = 'bis ' . (int)$kurs['max_alter'] . ' Jahre';
}
// Für wen kann ich (noch) anmelden?
$personen = [];
if (!$ist_eigentuemer) $personen[0] = 'mich selbst';
foreach ($kinder as $k) $personen[(int)$k['id']] = $k['vorname'] . ' ' . $k['nachname'];
$anmeldbar = array_filter($personen, fn($name, $kid) => !isset($meine[$kid]) || in_array($meine[$kid]['status'], ['storniert'], true), ARRAY_FILTER_USE_BOTH);
?>

<style>
.kurs-meine { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1.25rem; }
.kurs-meine-zeile { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; padding: 0.6rem 0.8rem; border: 1px solid var(--border-light); border-radius: 0.6rem; font-size: 0.875rem; }
.kurs-voraus { margin-top: 1rem; padding: 0.75rem 0.9rem; border-left: 3px solid var(--gold-accent); background: var(--bg-muted); border-radius: 0.4rem; font-size: 0.85rem; line-height: 1.6; }
.kurs-platz-balken { height: 6px; border-radius: 3px; background: var(--bg-muted); overflow: hidden; margin-top: 0.35rem; }
.kurs-platz-balken span { display: block; height: 100%; background: var(--gold-accent); }
</style>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/kurse.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Zurück zu Kurse
        </a>
        <h1 class="dashboard-title"><?= e($kurs['titel']) ?></h1>
        <p class="dashboard-subtitle">
            <?= date('d.m.Y H:i', strtotime($kurs['start_datum'])) ?> bis <?= date('d.m.Y H:i', strtotime($kurs['end_datum'])) ?>
            <?php if ($kurs['ort']): ?> · <?= e($kurs['ort']) ?><?php endif; ?>
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <?php if ($ist_eigentuemer): ?><a href="<?= APP_URL ?>/dashboard/kurs-erstellen.php?id=<?= $kurs_id ?>" class="btn btn-ghost-light btn-sm">Bearbeiten</a><?php endif; ?>
        <?php $s = $status_map[$kurs['status']] ?? ['label' => $kurs['status'], 'class' => 'badge-gray']; ?>
        <span class="badge <?= $s['class'] ?>" style="font-size: 0.8rem;"><?= e($s['label']) ?></span>
    </div>
</div>

<div class="<?= $ist_eigentuemer ? 'grid-2' : '' ?>" style="display: grid; gap: 1.5rem; align-items: start;">

    <!-- Kursinfo -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Details</h2>
        </div>
        <div style="padding: 1.25rem;">
            <?php if ($kurs['beschreibung']): ?>
                <p style="line-height: 1.7; margin-bottom: 1.5rem;"><?= nl2br(e($kurs['beschreibung'])) ?></p>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; font-size: 0.875rem;">
                <?php if ($kurs['sportart']): ?>
                <div><strong>Sportart</strong><br><span class="badge badge-gold" style="margin-top: 4px;"><?= e($kurs['sportart']) ?></span></div>
                <?php endif; ?>
                <div><strong>Trainer*in</strong><br><?= $kurs['trainer_vorname'] ? e($kurs['trainer_vorname'] . ' ' . $kurs['trainer_nachname']) : 'k. A.' ?></div>
                <div><strong>Teilnehmer</strong><br><?= $belegt ?><?= $kurs['max_teilnehmer'] ? ' / ' . (int)$kurs['max_teilnehmer'] : ' (unlimitiert)' ?>
                    <?php if ($warteliste): ?><br><span class="badge badge-info" style="margin-top: 4px;"><?= $warteliste ?> auf Warteliste</span><?php endif; ?>
                    <?php if ($kurs['max_teilnehmer']): ?><div class="kurs-platz-balken"><span style="width: <?= min(100, round($belegt / max(1, (int)$kurs['max_teilnehmer']) * 100)) ?>%"></span></div><?php endif; ?>
                </div>
                <div><strong>Preis</strong><br><?= $kurs['preis'] > 0 ? number_format((float)$kurs['preis'], 2, ',', '.') . ' €' : 'Kostenlos' ?></div>
                <?php if ($alter_text): ?><div><strong>Alter</strong><br><?= e($alter_text) ?></div><?php endif; ?>
                <?php if (!empty($kurs['anmeldeschluss'])): ?><div><strong>Anmeldeschluss</strong><br><?= date('d.m.Y, H:i', strtotime($kurs['anmeldeschluss'])) ?></div><?php endif; ?>
                <?php if ($projekt): ?><div><strong>Projekt</strong><br><a href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= $projekt['id'] ?>"><?= e($projekt['name']) ?></a></div><?php endif; ?>
            </div>

            <?php if (trim((string)($kurs['voraussetzungen'] ?? '')) !== ''): ?>
                <div class="kurs-voraus"><strong>Voraussetzungen</strong><br><?= nl2br(e($kurs['voraussetzungen'])) ?></div>
            <?php endif; ?>

            <?php if ($meine): ?>
                <div class="kurs-meine">
                    <?php foreach ($meine as $kid => $a): if ($a['status'] === 'storniert') continue;
                        $wer = $kid ? ($personen[$kid] ?? 'Kind') : 'Du';
                        $ts = $teilnehmer_status_labels[$a['status']] ?? ['label' => $a['status'], 'class' => 'badge-gray']; ?>
                    <div class="kurs-meine-zeile">
                        <span><strong><?= e($wer) ?></strong>
                            <span class="badge <?= $ts['class'] ?>" style="margin-left: 0.35rem;"><?= e($ts['label']) ?><?= $a['status'] === 'warteliste' ? ' · Platz ' . kursWartelistePosition($db, $kurs_id, (int)$a['id']) : '' ?></span>
                            <?php if ($a['status'] === 'angemeldet' && $kurs['preis'] > 0): ?><span class="badge <?= $a['bezahlt'] ? 'badge-success' : 'badge-warning' ?>" style="margin-left: 0.25rem;"><?= $a['bezahlt'] ? 'bezahlt' : 'Zahlung offen' ?></span><?php endif; ?>
                        </span>
                        <?php if (in_array($a['status'], ['angemeldet', 'warteliste'], true)): ?>
                        <form method="POST" onsubmit="return confirm('<?= $a['status'] === 'warteliste' ? 'Von der Warteliste nehmen?' : 'Wirklich abmelden? Der Platz geht an die Warteliste.' ?>');"><?= csrfField() ?>
                            <input type="hidden" name="action" value="abmelden"><input type="hidden" name="kind_id" value="<?= (int)$kid ?>">
                            <button type="submit" class="btn btn-ghost-light btn-sm"><?= $a['status'] === 'warteliste' ? 'Warteliste verlassen' : 'Abmelden' ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($anmeldbar): ?>
                <div style="margin-top: 1.5rem;">
                    <?php if ($geschlossen): ?>
                        <div class="flash-message flash-info" style="border-radius: 0.5rem;"><span><?= e($geschlossen) ?></span></div>
                    <?php else: ?>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="anmelden">
                            <?php if (count($anmeldbar) > 1 || array_key_first($anmeldbar) !== 0): ?>
                            <div class="form-group">
                                <label class="form-label" for="kind_id">Anmelden für</label>
                                <select class="form-control" id="kind_id" name="kind_id">
                                    <?php foreach ($anmeldbar as $kid => $name): ?><option value="<?= (int)$kid ?>"><?= e($kid ? $name : 'Mich selbst') ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (trim((string)($kurs['voraussetzungen'] ?? '')) !== ''): ?>
                            <label class="form-check" style="margin-bottom: 0.75rem;"><input type="checkbox" name="voraussetzungen_ok" value="1" required>
                                <span class="form-check-label">Die Voraussetzungen sind erfüllt.</span></label>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary w-full btn-lg"><?= $voll ? 'Auf die Warteliste setzen' : 'Jetzt anmelden' ?></button>
                            <?php if ($voll): ?><p class="form-hint" style="margin-top: 0.5rem;">Der Kurs ist voll. Wird ein Platz frei, rückt die Warteliste automatisch nach – du wirst benachrichtigt.</p><?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ist_eigentuemer): ?>
    <!-- Verwaltung (Trainer/Admin) -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Verwaltung</h2>
        </div>
        <div style="padding: 1.25rem;">
            <label class="form-label">Kurs-Status ändern</label>
            <form method="POST" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status_aendern">
                <select name="status" class="form-control" style="flex: 1; min-width: 160px;">
                    <?php foreach ($status_map as $val => $info): ?>
                        <option value="<?= $val ?>" <?= $kurs['status'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
            </form>
            <p class="form-hint">Bei „Abgesagt“ werden alle Angemeldeten benachrichtigt.</p>
            <?php if ($warteliste && !$voll): ?>
            <form method="POST" style="margin-top: 1rem;"><?= csrfField() ?><input type="hidden" name="action" value="nachruecken">
                <button type="submit" class="btn btn-primary btn-sm">Freie Plätze an die Warteliste vergeben</button></form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($einheiten_sehen): ?>
<!-- Einheiten / Termine des Kurses -->
<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
        <h2 class="table-card-title">Termine / Einheiten (<?= count($einheiten) ?>)</h2>
        <?php if ($darf_einheiten && isTrainer()): ?><a href="<?= APP_URL ?>/dashboard/einheit-planen.php?kurs=<?= $kurs_id ?>" class="btn btn-navy btn-sm">+ Einheiten planen</a><?php endif; ?>
    </div>
    <?php if (!$einheiten): ?>
        <div class="empty-state" style="padding: 1.5rem 1rem;"><p>Noch keine Einzeltermine geplant<?= $darf_einheiten ? ' – mit „Einheiten planen“ z.B. eine wöchentliche Serie anlegen.' : '.' ?></p></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead><tr><th>Datum</th><th>Zeit</th><th>Trainer:in</th><th>Status</th><?php if ($ist_eigentuemer): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($einheiten as $ein): ?>
                    <tr<?= $ein['status'] === 'storniert' ? ' style="opacity: 0.55;"' : '' ?>>
                        <td><?= ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', strtotime($ein['start']))] ?>, <?= date('d.m.Y', strtotime($ein['start'])) ?></td>
                        <td><?= date('H:i', strtotime($ein['start'])) ?>–<?= date('H:i', strtotime($ein['ende'])) ?></td>
                        <td><?= e($ein['trainer_namen'] ?: '–') ?></td>
                        <td><span class="badge <?= $einheit_status[$ein['status']] ?? 'badge-gray' ?>"><?= e(EINHEIT_STATUS[$ein['status']] ?? $ein['status']) ?></span></td>
                        <?php if ($ist_eigentuemer): ?><td><a href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $ein['id'] ?>" class="btn btn-ghost-light btn-sm">Öffnen</a></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($ist_eigentuemer): ?>
<!-- Teilnehmerliste -->
<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Teilnehmer (<?= $belegt ?><?= $warteliste ? ' + ' . $warteliste . ' Warteliste' : '' ?>)</h2>
    </div>
    <?php if (empty($anmeldungen)): ?>
        <div class="empty-state">
            <h3>Noch keine Anmeldungen</h3>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Kontakt</th>
                        <th>Alter</th>
                        <th>Angemeldet am</th>
                        <th>Status</th>
                        <th>Bezahlt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $wl_pos = 0;
                    foreach ($anmeldungen as $a):
                        $ts = $teilnehmer_status_labels[$a['status']] ?? ['label' => $a['status'], 'class' => 'badge-gray'];
                        $ist_kind = (int)$a['kind_id'] > 0 && $a['kind_vorname'] !== null;
                        $alter = alterAm($ist_kind ? $a['kind_geburtsdatum'] : $a['geburtsdatum'], $kurs['start_datum']);
                        if ($a['status'] === 'warteliste') $wl_pos++;
                    ?>
                    <tr>
                        <td class="text-primary">
                            <?php if ($ist_kind): ?>
                                <?= e($a['kind_vorname'] . ' ' . $a['kind_nachname']) ?>
                                <br><span style="font-size: 0.75rem; color: var(--text-muted);">Kind von <?= e($a['vorname'] . ' ' . $a['nachname']) ?></span>
                                <?php if ($a['kind_hinweise']): ?><br><span class="badge badge-warning" title="<?= e($a['kind_hinweise']) ?>">Hinweis</span><?php endif; ?>
                            <?php else: ?>
                                <?= e($a['vorname'] . ' ' . $a['nachname']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($a['email']) ?></td>
                        <td><?= $alter !== null ? $alter : '–' ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($a['angemeldet_am'])) ?></td>
                        <td><span class="badge <?= $ts['class'] ?>"><?= e($ts['label']) ?><?= $a['status'] === 'warteliste' ? ' #' . $wl_pos : '' ?></span></td>
                        <td>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="bezahlt_umschalten">
                                <input type="hidden" name="anmeldung_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="badge <?= $a['bezahlt'] ? 'badge-success' : 'badge-gray' ?>" style="border: none; cursor: pointer;">
                                    <?= $a['bezahlt'] ? '✓ Bezahlt' : 'Offen' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" style="display: flex; gap: 0.4rem;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="teilnehmer_status">
                                <input type="hidden" name="anmeldung_id" value="<?= $a['id'] ?>">
                                <select name="teilnehmer_neuer_status" class="form-control" style="font-size: 0.8rem; padding: 0.35rem 0.5rem;" onchange="this.form.submit()" aria-label="Status ändern">
                                    <?php foreach ($teilnehmer_status_labels as $val => $info): ?>
                                        <option value="<?= $val ?>" <?= $a['status'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
