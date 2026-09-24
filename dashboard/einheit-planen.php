<?php
/**
 * Athletikclub Steiermark – Einheit / Serie planen und bearbeiten
 * Trainer:innen einteilen (mit Überschneidungsprüfung), Ressourcen buchen
 * (ohne Doppelbuchung), Serien z.B. „jeden Freitag 09:00–11:00“.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$planer = darf('kalender.erstellen');
$errors = $konflikte = [];

$id = (int)($_GET['id'] ?? 0);
$einheit = $id ? einheitLaden($db, $id) : null;
if ($id && (!$einheit || !(einheitDarfBearbeiten($db, $einheit) || (int)$einheit['erstellt_von'] === $me))) {
    flashMessage('error', 'Diese Einheit kannst du nicht bearbeiten.');
    redirect(APP_URL . '/dashboard/kalender.php');
}
$zugeteilt = $einheit ? einheitTrainer($db, $id) : [];

// Auswahllisten: Planer:innen sehen alles, Trainer:innen nur eigene Kurse/Projekte
$stmt = $db->prepare("SELECT id, titel, ort, trainer_id FROM kurse WHERE organization_id = ? AND status IN ('geplant','aktiv')" . ($planer ? '' : ' AND trainer_id = ?') . ' ORDER BY titel');
$stmt->execute($planer ? [$org_id] : [$org_id, $me]);
$kurse = $stmt->fetchAll();
$projekte = plattformProjekte($db);
if (!$planer) {
    $stmt = $db->prepare('SELECT p.id FROM projekte p LEFT JOIN projekt_team t ON t.projekt_id = p.id AND t.user_id = ? WHERE p.organization_id = ? AND (p.leitung_id = ? OR t.user_id IS NOT NULL)');
    try { $stmt->execute([$me, $org_id, $me]); $meine_projekte = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)); } catch (Exception $e) { $meine_projekte = []; }
    $projekte = array_values(array_filter($projekte, fn($p) => in_array((int)$p['id'], $meine_projekte, true)));
}
$trainer_liste = $planer ? plattformTrainer($db) : array_values(array_filter(plattformTrainer($db), fn($t) => (int)$t['id'] === $me));
try {
    $stmt = $db->prepare('SELECT id, name, kategorie, anzahl FROM ressourcen WHERE organization_id = ? AND verfuegbar = 1 ORDER BY kategorie, name');
    $stmt->execute([$org_id]);
    $ressourcen = $stmt->fetchAll();
} catch (Exception $e) { $ressourcen = []; }
$gebucht = [];
if ($einheit) {
    $stmt = $db->prepare('SELECT * FROM ressourcen_buchungen WHERE einheit_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $b) $gebucht[(int)$b['ressource_id']] = (int)$b['menge'];
}

// ----------------------------------------------------------------
// Speichern
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'speichern';

    if ($einheit && in_array($action, ['absagen', 'loeschen'], true)) {
        $umfang = ($_POST['umfang'] ?? '') === 'folgende' && $einheit['serie_id'] ? 'folgende' : 'diese';
        $stmt = $db->prepare('SELECT id, titel, start FROM einheiten WHERE ' . ($umfang === 'folgende' ? 'serie_id = ? AND start >= ?' : 'id = ?') . " AND status = 'geplant' AND organization_id = ?");
        $stmt->execute($umfang === 'folgende' ? [$einheit['serie_id'], $einheit['start'], $org_id] : [$id, $org_id]);
        $betroffen = $stmt->fetchAll();
        $n = 0;
        foreach ($betroffen as $b) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM einheit_trainer WHERE einheit_id = ? AND status NOT IN ('geplant','storniert')");
            $stmt->execute([$b['id']]);
            if ($stmt->fetchColumn()) continue; // bereits bestätigt → nicht löschen/absagen
            foreach (einheitTrainer($db, (int)$b['id']) as $t) {
                benachrichtigen((int)$t['user_id'], 'termin', ($action === 'absagen' ? 'Abgesagt: ' : 'Entfernt: ') . $b['titel'], date('d.m.Y H:i', strtotime($b['start'])), '/dashboard/kalender.php?ansicht=tag&datum=' . substr($b['start'], 0, 10));
            }
            if ($action === 'absagen') {
                $db->prepare("UPDATE einheiten SET status = 'storniert' WHERE id = ?")->execute([$b['id']]);
                $db->prepare("UPDATE einheit_trainer SET status = 'storniert' WHERE einheit_id = ? AND status = 'geplant'")->execute([$b['id']]);
            } else {
                $db->prepare('DELETE FROM einheiten WHERE id = ?')->execute([$b['id']]);
            }
            auditLog($action === 'absagen' ? 'status' : 'geloescht', 'einheiten', (int)$b['id'], ['status' => 'geplant'], ['status' => $action === 'absagen' ? 'storniert' : 'gelöscht'], $b['titel']);
            $n++;
        }
        flashMessage($n ? 'success' : 'error', $n ? "{$n} Einheit(en) " . ($action === 'absagen' ? 'abgesagt.' : 'gelöscht.') : 'Keine Einheit geändert – bereits bestätigte Einheiten bleiben erhalten.');
        redirect(APP_URL . '/dashboard/kalender.php?ansicht=woche&datum=' . substr($einheit['start'], 0, 10));
    }

    $d = [
        'titel'   => mb_substr(trim($_POST['titel'] ?? ''), 0, 200),
        'typ'     => isset(EINHEIT_TYPEN[$_POST['typ'] ?? '']) ? $_POST['typ'] : 'training',
        'datum'   => $_POST['datum'] ?? '',
        'von'     => $_POST['von'] ?? '',
        'bis'     => $_POST['bis'] ?? '',
        'ort'     => mb_substr(trim($_POST['ort'] ?? ''), 0, 150) ?: null,
        'projekt_id' => (int)($_POST['projekt_id'] ?? 0) ?: null,
        'kurs_id' => (int)($_POST['kurs_id'] ?? 0) ?: null,
        'erwartete_teilnehmer' => ($_POST['erwartete_teilnehmer'] ?? '') !== '' ? max(0, min(999, (int)$_POST['erwartete_teilnehmer'])) : null,
        'notiz'   => trim($_POST['notiz'] ?? '') ?: null,
        'status'  => $einheit && isset(EINHEIT_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : ($einheit['status'] ?? 'geplant'),
    ];
    if ($d['kurs_id'] && !in_array($d['kurs_id'], array_map(fn($k) => (int)$k['id'], $kurse), true)) $d['kurs_id'] = $einheit['kurs_id'] ?? null;
    if ($d['projekt_id'] && !in_array($d['projekt_id'], array_map(fn($p) => (int)$p['id'], $projekte), true)) $d['projekt_id'] = $einheit['projekt_id'] ?? null;
    if ($d['titel'] === '' && $d['kurs_id']) foreach ($kurse as $k) if ((int)$k['id'] === $d['kurs_id']) { $d['titel'] = $k['titel']; $d['ort'] ??= $k['ort']; }
    if ($d['titel'] === '') $errors['titel'] = 'Bitte einen Titel angeben.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum']) || !strtotime($d['datum'])) $errors['datum'] = 'Bitte ein gültiges Datum angeben.';
    if (!preg_match('/^\d{2}:\d{2}$/', $d['von']) || !preg_match('/^\d{2}:\d{2}$/', $d['bis']) || $d['bis'] <= $d['von']) $errors['zeit'] = 'Bitte Beginn und Ende angeben (Ende nach Beginn).';

    // Trainer:innen
    $gewaehlt = [];
    foreach ((array)($_POST['trainer'] ?? []) as $tid) {
        $tid = (int)$tid;
        if (!in_array($tid, array_map(fn($t) => (int)$t['id'], $trainer_liste), true)) continue;
        $gewaehlt[$tid] = ($_POST['rolle'][$tid] ?? '') === 'assistenz' ? 'assistenz' : 'leitung';
    }
    if (!$planer && !$einheit) $gewaehlt[$me] ??= 'leitung'; // Trainer:innen planen für sich selbst
    if (!$planer && $einheit) foreach ($zugeteilt as $z) $gewaehlt[(int)$z['user_id']] ??= $z['rolle']; // andere nicht entfernen

    // Termine (Serie nur beim Anlegen)
    $wiederholung = !$einheit && in_array($_POST['wiederholung'] ?? '', ['woechentlich', '14taegig'], true) ? $_POST['wiederholung'] : null;
    $serie_bis = $_POST['serie_bis'] ?? '';
    if ($wiederholung && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $serie_bis) || $serie_bis < $d['datum'])) $errors['serie_bis'] = 'Bitte das Ende der Serie angeben.';
    $umfang = $einheit && $einheit['serie_id'] && ($_POST['umfang'] ?? '') === 'folgende' ? 'folgende' : 'diese';

    $termine = [];
    if (empty($errors)) {
        if ($einheit) {
            $termine[] = ['id' => $id, 'datum' => $d['datum']];
            if ($umfang === 'folgende') {
                $stmt = $db->prepare("SELECT id, start FROM einheiten WHERE serie_id = ? AND start > ? AND status = 'geplant' AND organization_id = ? ORDER BY start");
                $stmt->execute([$einheit['serie_id'], $einheit['start'], $org_id]);
                foreach ($stmt->fetchAll() as $f) $termine[] = ['id' => (int)$f['id'], 'datum' => substr($f['start'], 0, 10)];
            }
        } else {
            foreach ($wiederholung ? serienDaten($d['datum'], $serie_bis, $wiederholung) : [$d['datum']] as $dt) $termine[] = ['id' => null, 'datum' => $dt];
        }
        if (count($termine) > 120) $errors['serie_bis'] = 'Eine Serie kann höchstens 120 Termine umfassen.';
    }

    // Ressourcen (harte Prüfung) und Überschneidungen (Warnung, bestätigbar)
    $res_wahl = [];
    foreach ((array)($_POST['res_menge'] ?? []) as $rid => $menge) if ((int)$menge > 0) $res_wahl[(int)$rid] = (int)$menge;
    if (empty($errors)) {
        foreach ($termine as $t) {
            $start = $t['datum'] . ' ' . $d['von'] . ':00';
            $ende  = $t['datum'] . ' ' . $d['bis'] . ':00';
            foreach ($gewaehlt as $tid => $_) {
                foreach (trainerKonflikte($db, $tid, $start, $ende, $t['id']) as $k) {
                    $name = '';
                    foreach ($trainer_liste as $tl) if ((int)$tl['id'] === $tid) $name = $tl['vorname'] . ' ' . $tl['nachname'];
                    $konflikte[] = "{$name} ist am " . date('d.m.Y', strtotime($k['start'])) . ' von ' . date('H:i', strtotime($k['start'])) . ' bis ' . date('H:i', strtotime($k['ende'])) . " bereits eingeplant („{$k['titel']}“).";
                }
            }
            foreach ($res_wahl as $rid => $menge) {
                $frei = ressourceFrei($db, $rid, $start, $ende, null, $t['id']);
                if ($frei !== null && $frei < $menge) {
                    $rname = '';
                    foreach ($ressourcen as $r) if ((int)$r['id'] === $rid) $rname = $r['name'];
                    $errors['ressource'] = "„{$rname}“ ist am " . date('d.m.Y', strtotime($t['datum'])) . " nicht verfügbar (frei: {$frei}, benötigt: {$menge}).";
                    break 2;
                }
            }
        }
        $konflikte = array_values(array_unique($konflikte));
    }

    if (empty($errors) && (!$konflikte || !empty($_POST['trotzdem']))) {
        $db->beginTransaction();
        try {
            $serie_id = $einheit['serie_id'] ?? null;
            if ($wiederholung) {
                $db->prepare('INSERT INTO einheit_serien (organization_id, titel, typ, projekt_id, kurs_id, ort, rhythmus, startzeit, endzeit, von, bis, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$org_id, $d['titel'], $d['typ'], $d['projekt_id'], $d['kurs_id'], $d['ort'], $wiederholung, $d['von'] . ':00', $d['bis'] . ':00', $d['datum'], $serie_bis, $me]);
                $serie_id = (int)$db->lastInsertId();
            }
            $neue_ids = [];
            foreach ($termine as $i => $t) {
                $start = $t['datum'] . ' ' . $d['von'] . ':00';
                $ende  = $t['datum'] . ' ' . $d['bis'] . ':00';
                if ($t['id']) {
                    $stmt = $db->prepare('SELECT * FROM einheiten WHERE id = ?');
                    $stmt->execute([$t['id']]);
                    $alt = $stmt->fetch();
                    $werte = ['titel' => $d['titel'], 'typ' => $d['typ'], 'start' => $start, 'ende' => $ende, 'ort' => $d['ort'], 'projekt_id' => $d['projekt_id'], 'kurs_id' => $d['kurs_id'],
                              'erwartete_teilnehmer' => $d['erwartete_teilnehmer'], 'notiz' => $i === 0 ? $d['notiz'] : $alt['notiz'], 'status' => $i === 0 ? $d['status'] : $alt['status']];
                    $db->prepare('UPDATE einheiten SET titel = ?, typ = ?, start = ?, ende = ?, ort = ?, projekt_id = ?, kurs_id = ?, erwartete_teilnehmer = ?, notiz = ?, status = ? WHERE id = ?')
                       ->execute(array_merge(array_values($werte), [$t['id']]));
                    auditLog('geaendert', 'einheiten', $t['id'], $alt, $werte, $d['titel']);
                    $eid = $t['id'];
                    if ($alt['start'] !== $start || $alt['ende'] !== $ende || (string)$alt['ort'] !== (string)$d['ort']) {
                        foreach (einheitTrainer($db, $eid) as $z) benachrichtigen((int)$z['user_id'], 'termin', 'Terminänderung: ' . $d['titel'],
                            'Neu: ' . date('d.m.Y H:i', strtotime($start)) . '–' . date('H:i', strtotime($ende)) . ($d['ort'] ? ', ' . $d['ort'] : ''), '/dashboard/einheit.php?id=' . $eid);
                    }
                } else {
                    $db->prepare('INSERT INTO einheiten (organization_id, serie_id, typ, titel, start, ende, ort, projekt_id, kurs_id, erwartete_teilnehmer, notiz, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                       ->execute([$org_id, $serie_id, $d['typ'], $d['titel'], $start, $ende, $d['ort'], $d['projekt_id'], $d['kurs_id'], $d['erwartete_teilnehmer'], $d['notiz'], $me]);
                    $eid = (int)$db->lastInsertId();
                    $neue_ids[] = $eid;
                }
                // Trainer:innen abgleichen (bestätigte Einsätze bleiben unangetastet)
                $stmt = $db->prepare('SELECT user_id, status FROM einheit_trainer WHERE einheit_id = ?');
                $stmt->execute([$eid]);
                $vorhanden = array_column($stmt->fetchAll(), 'status', 'user_id');
                foreach ($gewaehlt as $tid => $rolle) {
                    if (isset($vorhanden[$tid])) {
                        $db->prepare('UPDATE einheit_trainer SET rolle = ? WHERE einheit_id = ? AND user_id = ?')->execute([$rolle, $eid, $tid]);
                    } else {
                        $db->prepare('INSERT INTO einheit_trainer (einheit_id, user_id, rolle) VALUES (?, ?, ?)')->execute([$eid, $tid, $rolle]);
                        if ($i === 0 && $tid !== $me) benachrichtigen($tid, 'einsatz', 'Neuer Einsatz: ' . $d['titel'],
                            date('d.m.Y H:i', strtotime($start)) . ($wiederholung ? ' (Serie, ' . count($termine) . ' Termine)' : '') . ($d['ort'] ? ', ' . $d['ort'] : ''), '/dashboard/einheit.php?id=' . $eid);
                    }
                }
                foreach ($vorhanden as $tid => $status) {
                    if (!isset($gewaehlt[$tid]) && $status === 'geplant') $db->prepare('DELETE FROM einheit_trainer WHERE einheit_id = ? AND user_id = ?')->execute([$eid, $tid]);
                }
                // Ressourcen
                $db->prepare('DELETE FROM ressourcen_buchungen WHERE einheit_id = ?')->execute([$eid]);
                foreach ($res_wahl as $rid => $menge) {
                    $db->prepare('INSERT INTO ressourcen_buchungen (ressource_id, einheit_id, kurs_id, start, ende, menge, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?)')
                       ->execute([$rid, $eid, $d['kurs_id'], $start, $ende, $menge, $me]);
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        if ($neue_ids) auditLog('erstellt', 'einheiten', $neue_ids[0], null, ['titel' => $d['titel'], 'anzahl' => count($neue_ids), 'start' => $termine[0]['datum']], $d['titel']);
        logActivity('einheit_gespeichert', $d['titel'] . ' (' . count($termine) . ' Termin(e))');
        flashMessage('success', $einheit ? 'Einheit gespeichert' . (count($termine) > 1 ? ' (' . count($termine) . ' Termine der Serie).' : '.') : (count($termine) > 1 ? count($termine) . ' Einheiten der Serie angelegt.' : 'Einheit angelegt.'));
        redirect(APP_URL . '/dashboard/einheit.php?id=' . ($einheit ? $id : $neue_ids[0]));
    }
}

// ----------------------------------------------------------------
// Formular
// ----------------------------------------------------------------
$vorlage_kurs = (int)($_GET['kurs'] ?? 0);
$form = $einheit ? [
    'titel' => $einheit['titel'], 'typ' => $einheit['typ'], 'datum' => substr($einheit['start'], 0, 10), 'von' => substr($einheit['start'], 11, 5), 'bis' => substr($einheit['ende'], 11, 5),
    'ort' => $einheit['ort'], 'projekt_id' => $einheit['projekt_id'], 'kurs_id' => $einheit['kurs_id'], 'erwartete_teilnehmer' => $einheit['erwartete_teilnehmer'],
    'notiz' => $einheit['notiz'], 'status' => $einheit['status'],
] : ['titel' => '', 'typ' => $vorlage_kurs ? 'kurs' : 'training', 'datum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['datum'] ?? '') ? $_GET['datum'] : date('Y-m-d'),
     'von' => '17:00', 'bis' => '18:30', 'ort' => '', 'projekt_id' => (int)($_GET['projekt'] ?? 0) ?: null, 'kurs_id' => $vorlage_kurs ?: null, 'erwartete_teilnehmer' => '', 'notiz' => '', 'status' => 'geplant'];
if ($vorlage_kurs && !$einheit) foreach ($kurse as $k) if ((int)$k['id'] === $vorlage_kurs) { $form['titel'] = $k['titel']; $form['ort'] = $k['ort']; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') $form = array_merge($form, array_intersect_key($_POST, $form));
$gewaehlte_trainer = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_flip(array_map('intval', (array)($_POST['trainer'] ?? [])))
    : ($einheit ? array_flip(array_map(fn($z) => (int)$z['user_id'], $zugeteilt)) : [$me => 0]);
$rollen = array_column($zugeteilt, 'rolle', 'user_id');
if ($_SERVER['REQUEST_METHOD'] === 'POST') $rollen = array_map(fn($r) => $r === 'assistenz' ? 'assistenz' : 'leitung', (array)($_POST['rolle'] ?? []));
$bestaetigt = array_filter($zugeteilt, fn($z) => !in_array($z['status'], ['geplant', 'storniert'], true));
$v = fn($wert) => e((string)($wert ?? ''));

$page_title = $einheit ? 'Einheit bearbeiten' : 'Einheit planen';
$breadcrumb = 'Kalender';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ep-trainer { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 0.4rem 1rem; }
.ep-trainer label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.87rem; padding: 0.35rem 0; }
.ep-trainer select { padding: 0.15rem 0.4rem; font-size: 0.78rem; max-width: 120px; }
.ep-box { border-radius: 0.75rem; padding: 0.9rem 1.1rem; margin-bottom: 1.25rem; font-size: 0.88rem; }
.ep-warnung { background: rgba(245, 158, 11, 0.12); border-left: 4px solid var(--warning); }
.ep-res { display: grid; grid-template-columns: 1fr 90px; gap: 0.4rem 0.75rem; align-items: center; font-size: 0.87rem; max-width: 520px; }
.ep-mini { font-size: 0.75rem; color: var(--text-muted); }
</style>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/<?= $einheit ? 'einheit.php?id=' . $id : 'kalender.php?ansicht=woche&datum=' . e($form['datum']) ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        <?= $einheit ? 'Zur Einheit' : 'Kalender' ?>
    </a>
    <h1 class="dashboard-title"><?= $einheit ? 'Einheit bearbeiten' : 'Einheit planen' ?></h1>
    <p class="dashboard-subtitle">Kurs-, Trainings-, Kindergarten- oder Schuleinheit, Event oder Meeting – einmalig oder als Serie, mit Trainer:innen und Material.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.25rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<form method="POST" class="table-card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="speichern">
    <div style="padding: 1.25rem;">
        <?php if ($konflikte): ?>
        <div class="ep-box ep-warnung">
            <strong>Überschneidungen gefunden:</strong>
            <ul style="margin: 0.4rem 0 0.6rem; padding-left: 1.1rem; list-style: disc;"><?php foreach (array_slice($konflikte, 0, 12) as $k): ?><li><?= e($k) ?></li><?php endforeach; ?></ul>
            <?php if (count($konflikte) > 12): ?><div class="ep-mini">… und <?= count($konflikte) - 12 ?> weitere</div><?php endif; ?>
            <label style="display: flex; gap: 0.5rem; align-items: center;"><input type="checkbox" name="trotzdem" value="1"> Trotzdem speichern</label>
        </div>
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group"><label class="form-label">Titel <span class="required">*</span></label><input class="form-control" type="text" name="titel" maxlength="200" value="<?= $v($form['titel']) ?>" placeholder="z.B. Bewegungseinheit Kindergarten Tillmitsch"></div>
            <div class="form-group"><label class="form-label">Art</label>
                <select class="form-control" name="typ"><?php foreach (EINHEIT_TYPEN as $val => $t): ?><option value="<?= $val ?>" <?= $form['typ'] === $val ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Datum <span class="required">*</span></label><input class="form-control" type="date" name="datum" value="<?= $v($form['datum']) ?>" required></div>
            <div class="form-group"><label class="form-label">Uhrzeit <span class="required">*</span></label>
                <div style="display: flex; gap: 0.5rem; align-items: center;"><input class="form-control" type="time" name="von" value="<?= $v($form['von']) ?>" required><span>–</span><input class="form-control" type="time" name="bis" value="<?= $v($form['bis']) ?>" required></div></div>
        </div>
        <?php if (!$einheit): ?>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Wiederholung</label>
                <select class="form-control" name="wiederholung" onchange="document.getElementById('serie-bis').style.display = this.value ? 'block' : 'none'">
                    <option value="">Einmalig</option>
                    <option value="woechentlich" <?= ($_POST['wiederholung'] ?? '') === 'woechentlich' ? 'selected' : '' ?>>Jede Woche am selben Wochentag</option>
                    <option value="14taegig" <?= ($_POST['wiederholung'] ?? '') === '14taegig' ? 'selected' : '' ?>>Alle 2 Wochen</option>
                </select></div>
            <div class="form-group" id="serie-bis" style="display: <?= !empty($_POST['wiederholung']) ? 'block' : 'none' ?>;"><label class="form-label">Serie bis</label>
                <input class="form-control" type="date" name="serie_bis" value="<?= $v($_POST['serie_bis'] ?? '') ?>"><p class="form-hint">Jede Einheit der Serie wird einzeln angelegt (eigene Anwesenheit und Bestätigung).</p></div>
        </div>
        <?php elseif ($einheit['serie_id']): ?>
        <div class="form-group"><label class="form-label">Teil einer Serie – Änderungen übernehmen für</label>
            <label style="display: flex; gap: 0.5rem; align-items: center; font-size: 0.87rem;"><input type="radio" name="umfang" value="diese" checked> nur diese Einheit</label>
            <label style="display: flex; gap: 0.5rem; align-items: center; font-size: 0.87rem;"><input type="radio" name="umfang" value="folgende" <?= ($_POST['umfang'] ?? '') === 'folgende' ? 'checked' : '' ?>> diese und alle folgenden geplanten Einheiten der Serie</label></div>
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group"><label class="form-label">Projekt</label>
                <select class="form-control" name="projekt_id"><option value="">– kein Projekt –</option><?php foreach ($projekte as $p): ?><option value="<?= $p['id'] ?>" <?= (int)$form['projekt_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Kurs</label>
                <select class="form-control" name="kurs_id"><option value="">– kein Kurs –</option><?php foreach ($kurse as $k): ?><option value="<?= $k['id'] ?>" <?= (int)$form['kurs_id'] === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['titel']) ?></option><?php endforeach; ?></select>
                <p class="form-hint">Mit Kurs stehen die Kursteilnehmer:innen automatisch in der Anwesenheitsliste.</p></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Ort</label><input class="form-control" type="text" name="ort" maxlength="150" value="<?= $v($form['ort']) ?>"></div>
            <div class="form-group"><label class="form-label">Erwartete Teilnehmer:innen</label><input class="form-control" type="number" min="0" max="999" name="erwartete_teilnehmer" value="<?= $v($form['erwartete_teilnehmer']) ?>"></div>
        </div>
        <?php if ($einheit): ?>
        <div class="form-group"><label class="form-label">Status</label>
            <select class="form-control" name="status" style="max-width: 240px;"><?php foreach (EINHEIT_STATUS as $val => $s): ?><option value="<?= $val ?>" <?= $form['status'] === $val ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label">Trainer:innen</label>
            <div class="ep-trainer">
                <?php foreach ($trainer_liste as $t): $tid = (int)$t['id']; $fest = isset(array_column($bestaetigt, 'user_id', 'user_id')[$tid]); ?>
                <label>
                    <input type="checkbox" name="trainer[]" value="<?= $tid ?>" <?= isset($gewaehlte_trainer[$tid]) ? 'checked' : '' ?> <?= $fest ? 'onclick="return false" title="Bereits bestätigt"' : '' ?>>
                    <span style="flex: 1;"><?= e($t['vorname'] . ' ' . $t['nachname']) ?><?= $fest ? ' <span class="ep-mini">(bestätigt)</span>' : '' ?></span>
                    <select class="form-control" name="rolle[<?= $tid ?>]" aria-label="Rolle"><option value="leitung">Leitung</option><option value="assistenz" <?= ($rollen[$tid] ?? '') === 'assistenz' ? 'selected' : '' ?>>Assistenz</option></select>
                </label>
                <?php endforeach; ?>
            </div>
            <?php if (!$planer): ?><p class="form-hint">Du planst diese Einheit für dich selbst. Weitere Trainer:innen teilt die Vereinsleitung ein.</p><?php endif; ?>
        </div>

        <?php if ($ressourcen): ?>
        <div class="form-group">
            <label class="form-label">Material &amp; Orte buchen</label>
            <details <?= $gebucht || !empty($_POST['res_menge']) ? 'open' : '' ?>>
                <summary class="ep-mini" style="cursor: pointer;">Ressourcen auswählen (<?= count($ressourcen) ?> verfügbar)</summary>
                <div class="ep-res" style="margin-top: 0.6rem;">
                    <?php foreach ($ressourcen as $r): $m = (int)($_POST['res_menge'][$r['id']] ?? ($gebucht[(int)$r['id']] ?? 0)); ?>
                    <span><?= e($r['name']) ?> <span class="ep-mini">(<?= e(RESSOURCE_KATEGORIEN[$r['kategorie']] ?? $r['kategorie']) ?>, gesamt <?= (int)$r['anzahl'] ?>)</span></span>
                    <input class="form-control" type="number" min="0" max="<?= (int)$r['anzahl'] ?>" name="res_menge[<?= $r['id'] ?>]" value="<?= $m ?: '' ?>" placeholder="0" aria-label="Menge <?= e($r['name']) ?>">
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>

        <div class="form-group"><label class="form-label">Notiz</label><textarea class="form-control" name="notiz" rows="3" placeholder="z.B. Treffpunkt, Inhalte, Hinweise für Trainer:innen"><?= $v($form['notiz']) ?></textarea></div>
        <button type="submit" class="btn btn-navy"><?= $einheit ? 'Speichern' : 'Einheit anlegen' ?></button>
    </div>
</form>

<?php if ($einheit && $einheit['status'] === 'geplant'): ?>
<form method="POST" class="table-card" style="margin-top: 1.25rem;" onsubmit="return confirm('Wirklich ausführen?')">
    <?= csrfField() ?>
    <div class="table-card-header"><h2 class="table-card-title">Absagen oder löschen</h2></div>
    <div style="padding: 1.25rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
        <?php if ($einheit['serie_id']): ?>
        <select class="form-control" name="umfang" style="max-width: 280px;"><option value="diese">nur diese Einheit</option><option value="folgende">diese und alle folgenden der Serie</option></select>
        <?php endif; ?>
        <button type="submit" name="action" value="absagen" class="btn btn-ghost-light btn-sm">Absagen</button>
        <button type="submit" name="action" value="loeschen" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Löschen</button>
        <span class="ep-mini">Absagen informiert die Trainer:innen und bleibt im Kalender sichtbar. Bereits bestätigte Einheiten bleiben unverändert.</span>
    </div>
</form>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
