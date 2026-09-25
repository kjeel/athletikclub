<?php
/**
 * Athletikclub Steiermark – Trainer-Onboarding
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/onboarding.php';

requireDarf('onboarding.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$self   = APP_URL . '/dashboard/admin/onboarding.php';
$bearb  = darf('onboarding.bearbeiten');
$tab    = ($_GET['tab'] ?? '') === 'checkliste' && $bearb ? 'checkliste' : 'vorgaenge';

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!$bearb) { flashMessage('error', 'Keine Berechtigung.'); redirect($self); }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $o = $id ? onboardingLaden($db, $id) : null;
    $ziel = $id ? "$self?id=$id" : $self;
    $t = fn($k, $max) => mb_substr(trim($_POST[$k] ?? ''), 0, $max);

    if ($action === 'neu' || ($action === 'stammdaten' && $o)) {
        $d = ['vorname' => $t('vorname', 80), 'nachname' => $t('nachname', 80), 'email' => strtolower($t('email', 180)), 'telefon' => $t('telefon', 40) ?: null,
              'schwerpunkt' => $t('schwerpunkt', 200) ?: null, 'nachricht' => trim($_POST['nachricht'] ?? '') ?: null];
        if ($d['vorname'] === '' || $d['nachname'] === '' || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            flashMessage('error', 'Bitte Vorname, Nachname und eine gültige E-Mail-Adresse angeben.');
            redirect($o ? $ziel : "$self?neu=1");
        }
        if ($o) {
            $d += ['betreuer_id' => (int)($_POST['betreuer_id'] ?? 0) ?: null, 'einschulung_am' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['einschulung_am'] ?? '') ? $_POST['einschulung_am'] : null,
                   'notiz' => trim($_POST['notiz'] ?? '') ?: null];
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $db->prepare("UPDATE onboarding SET $sets WHERE id = ?")->execute([...array_values($d), $id]);
            auditLog('geaendert', 'onboarding', $id, $o, $d, $d['vorname'] . ' ' . $d['nachname']);
            if ($d['betreuer_id'] && $d['betreuer_id'] !== (int)$o['betreuer_id'] && $d['betreuer_id'] !== $me) {
                benachrichtigen($d['betreuer_id'], 'projekt', 'Onboarding-Betreuung: ' . $d['vorname'] . ' ' . $d['nachname'], null, '/dashboard/admin/onboarding.php?id=' . $id);
            }
            flashMessage('success', 'Gespeichert.');
        } else {
            $d['status'] = in_array($_POST['status'] ?? '', ONBOARDING_ABLAUF, true) ? $_POST['status'] : 'aufnahme';
            $id = onboardingAnlegen($db, $d);
            flashMessage('success', 'Onboarding angelegt.');
        }
        redirect("$self?id=$id");
    }

    if ($o && $action === 'status') {
        $neu = $_POST['status'] ?? '';
        if (!isset(ONBOARDING_STATUS[$neu]) || $neu === $o['status']) redirect($ziel);
        if ($neu === 'aktiv') {
            onboardingAutoPruefen($db, $o);
            $fp = onboardingFortschritt($db, $id);
            if (!$o['user_id']) { flashMessage('error', 'Bitte zuerst ein Konto anlegen.'); redirect($ziel); }
            if ($fp['fehlend']) { flashMessage('error', 'Noch offen: ' . implode(', ', $fp['fehlend']) . '. Punkte erledigen oder als „nicht erforderlich“ markieren.'); redirect($ziel); }
            $db->prepare('UPDATE users SET aktiv = 1 WHERE id = ?')->execute([$o['user_id']]);
            benachrichtigen((int)$o['user_id'], 'projekt', 'Willkommen im Trainer:innen-Team!', 'Dein Onboarding ist abgeschlossen – du kannst jetzt eingeteilt werden.', '/dashboard/index.php');
        }
        $db->prepare('UPDATE onboarding SET status = ?, abgeschlossen_am = ? WHERE id = ?')->execute([$neu, in_array($neu, ['aktiv', 'abgelehnt', 'zurueckgezogen'], true) ? date('Y-m-d H:i:s') : null, $id]);
        auditLog('status', 'onboarding', $id, ['status' => $o['status']], ['status' => $neu], $o['vorname'] . ' ' . $o['nachname']);
        flashMessage('success', 'Status: ' . ONBOARDING_STATUS[$neu]['label'] . '.');
        redirect($ziel);
    }

    if ($o && $action === 'punkt') {
        $status = in_array($_POST['status'] ?? '', ['offen', 'erledigt', 'nicht_erforderlich'], true) ? $_POST['status'] : 'offen';
        $pid = (int)($_POST['punkt_id'] ?? 0);
        $db->prepare('UPDATE onboarding_status SET status = ?, erledigt_am = ?, erledigt_von = ?, notiz = ? WHERE onboarding_id = ? AND punkt_id = ?')
           ->execute([$status, $status === 'offen' ? null : date('Y-m-d H:i:s'), $status === 'offen' ? null : $me, $t('notiz', 300) ?: null, $id, $pid]);
        auditLog('geaendert', 'onboarding_status', $id, null, ['punkt_id' => $pid, 'status' => $status], $o['vorname'] . ' ' . $o['nachname']);
        redirect($ziel . '#checkliste');
    }

    if ($o && $action === 'konto') {
        $erg = onboardingKontoAnlegen($db, $o);
        if (isset($erg['fehler'])) flashMessage('error', $erg['fehler']);
        else flashMessage('success', $erg['neu'] ? 'Konto angelegt' . ($erg['gesendet'] ? ' und Einladung zum Passwort-Setzen verschickt.' : ' – Einladungsmail konnte nicht versendet werden (E-Mail-Einstellungen prüfen).') : 'Bestehendes Konto verknüpft und als Trainer:in freigeschaltet.');
        redirect($ziel);
    }

    // Checkliste verwalten
    if ($action === 'punkt_def') {
        $pid = (int)($_POST['punkt_id'] ?? 0);
        $d = ['titel' => $t('titel', 150), 'beschreibung' => $t('beschreibung', 300) ?: null, 'phase' => in_array($_POST['phase'] ?? '', ONBOARDING_ABLAUF, true) ? $_POST['phase'] : 'stammdaten',
              'pflicht' => !empty($_POST['pflicht']) ? 1 : 0, 'auto_pruefung' => isset(ONBOARDING_AUTO[$_POST['auto_pruefung'] ?? '']) ? $_POST['auto_pruefung'] : null,
              'reihenfolge' => (int)($_POST['reihenfolge'] ?? 0), 'aktiv' => !empty($_POST['aktiv']) ? 1 : 0];
        if ($d['titel'] === '') { flashMessage('error', 'Bitte einen Titel angeben.'); redirect("$self?tab=checkliste"); }
        if ($pid) {
            $db->prepare('UPDATE onboarding_punkte SET titel = ?, beschreibung = ?, phase = ?, pflicht = ?, auto_pruefung = ?, reihenfolge = ?, aktiv = ? WHERE id = ? AND organization_id = ?')->execute([...array_values($d), $pid, $org_id]);
            auditLog('geaendert', 'onboarding_punkte', $pid, null, $d, $d['titel']);
        } else {
            $db->prepare('INSERT INTO onboarding_punkte (organization_id, titel, beschreibung, phase, pflicht, auto_pruefung, reihenfolge, aktiv) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$org_id, ...array_values($d)]);
            auditLog('erstellt', 'onboarding_punkte', (int)$db->lastInsertId(), null, $d, $d['titel']);
            // Neuen Punkt in laufende Vorgänge übernehmen
            $s = $db->prepare("SELECT id FROM onboarding WHERE organization_id = ? AND status NOT IN ('aktiv','abgelehnt','zurueckgezogen')");
            $s->execute([$org_id]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $oid) onboardingPunkteErgaenzen($db, (int)$oid);
        }
        flashMessage('success', 'Checkliste gespeichert.');
        redirect("$self?tab=checkliste");
    }
    redirect($ziel);
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$detail = !empty($_GET['id']) ? onboardingLaden($db, (int)$_GET['id']) : null;
if ($detail) {
    onboardingPunkteErgaenzen($db, (int)$detail['id']);
    onboardingAutoPruefen($db, $detail);
}
$page_title = $detail ? 'Onboarding: ' . $detail['vorname'] . ' ' . $detail['nachname'] : 'Trainer-Onboarding';
$breadcrumb = 'Onboarding';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$phase_label = array_map(fn($s) => $s['label'], ONBOARDING_STATUS);
?>

<style>
.ob-karten { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.ob-karte { background: var(--surface, #fff); border: 1px solid var(--border-light); border-radius: 1rem; padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 0.5rem; color: var(--text-primary); }
.ob-karte:hover { border-color: var(--gold-accent); }
.ob-balken { height: 10px; border-radius: 5px; background: var(--bg-muted); overflow: hidden; }
.ob-balken span { display: block; height: 100%; background: linear-gradient(90deg, var(--gold-accent), #D4AF37); }
.ob-mini { font-size: 0.78rem; color: var(--text-muted); }
.ob-fehlt { font-size: 0.8rem; color: #B45309; }
.ob-ablauf { display: flex; flex-wrap: wrap; gap: 0.35rem; margin: 0.5rem 0 1.25rem; }
.ob-schritt { font-size: 0.72rem; padding: 0.3rem 0.6rem; border-radius: 99px; background: var(--bg-muted); color: var(--text-muted); font-weight: 600; letter-spacing: 0.02em; }
.ob-schritt.fertig { background: rgba(34, 197, 94, 0.14); color: #15803D; }
.ob-schritt.jetzt { background: var(--navy-primary); color: #fff; }
.ob-punkt { display: grid; grid-template-columns: 1fr auto; gap: 0.75rem; align-items: center; padding: 0.75rem 1.25rem; border-bottom: 1px solid var(--border-light); }
.ob-punkt:last-child { border-bottom: none; }
.ob-punkt form { display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: center; }
.ob-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 1.5rem; align-items: start; }
@media (max-width: 900px) { .ob-grid { grid-template-columns: minmax(0, 1fr); } .ob-punkt { grid-template-columns: minmax(0, 1fr); } }
</style>

<?php if ($tab === 'checkliste'): $punkte = $db->prepare('SELECT * FROM onboarding_punkte WHERE organization_id = ? ORDER BY reihenfolge, id'); $punkte->execute([$org_id]); $punkte = $punkte->fetchAll(); ?>
    <div class="dashboard-header"><a href="<?= $self ?>" class="ob-mini">← Onboarding</a><h1 class="dashboard-title">Onboarding-Checkliste</h1><p class="dashboard-subtitle">Punkte mit automatischer Prüfung werden aus den vorhandenen Modulen erkannt.</p></div>
    <div class="table-card">
        <?php foreach (array_merge($punkte, [['id' => 0, 'titel' => '', 'beschreibung' => '', 'phase' => 'stammdaten', 'pflicht' => 1, 'auto_pruefung' => null, 'reihenfolge' => (count($punkte) + 1) * 10, 'aktiv' => 1]]) as $p): ?>
        <form method="POST" class="ob-punkt" style="grid-template-columns: 1fr;"><?= csrfField() ?><input type="hidden" name="action" value="punkt_def"><input type="hidden" name="punkt_id" value="<?= (int)$p['id'] ?>">
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="margin: 0; flex: 1; min-width: 160px;"><label class="form-label"><?= $p['id'] ? 'Punkt' : 'Neuer Punkt' ?></label><input class="form-control" name="titel" maxlength="150" value="<?= e($p['titel']) ?>" <?= $p['id'] ? 'required' : '' ?>></div>
                <div class="form-group" style="margin: 0; flex: 1; min-width: 180px;"><label class="form-label">Beschreibung</label><input class="form-control" name="beschreibung" maxlength="300" value="<?= e($p['beschreibung'] ?? '') ?>"></div>
                <div class="form-group" style="margin: 0;"><label class="form-label">Phase</label><select class="form-control" name="phase"><?php foreach (array_slice(ONBOARDING_ABLAUF, 2, 7) as $ph): ?><option value="<?= $ph ?>" <?= $p['phase'] === $ph ? 'selected' : '' ?>><?= e($phase_label[$ph]) ?></option><?php endforeach; ?></select></div>
                <div class="form-group" style="margin: 0;"><label class="form-label">Automatisch prüfen</label><select class="form-control" name="auto_pruefung"><option value="">– manuell –</option><?php foreach (ONBOARDING_AUTO as $k => $l): ?><option value="<?= $k ?>" <?= $p['auto_pruefung'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="form-group" style="margin: 0; width: 80px;"><label class="form-label">Reihenf.</label><input class="form-control" type="number" name="reihenfolge" value="<?= (int)$p['reihenfolge'] ?>"></div>
                <label class="form-check" style="margin-bottom: 0.6rem;"><input type="checkbox" name="pflicht" value="1" <?= $p['pflicht'] ? 'checked' : '' ?>><span class="form-check-label">Pflicht</span></label>
                <label class="form-check" style="margin-bottom: 0.6rem;"><input type="checkbox" name="aktiv" value="1" <?= $p['aktiv'] ? 'checked' : '' ?>><span class="form-check-label">aktiv</span></label>
                <button class="btn <?= $p['id'] ? 'btn-ghost-light' : 'btn-primary' ?> btn-sm"><?= $p['id'] ? 'Speichern' : 'Hinzufügen' ?></button>
            </div>
        </form>
        <?php endforeach; ?>
    </div>

<?php elseif (!empty($_GET['neu']) && $bearb): ?>
    <div class="dashboard-header"><a href="<?= $self ?>" class="ob-mini">← Onboarding</a><h1 class="dashboard-title">Neue Trainer:in aufnehmen</h1></div>
    <form method="POST" class="form-card" style="max-width: 720px;"><?= csrfField() ?><input type="hidden" name="action" value="neu">
        <div class="form-row"><div class="form-group"><label class="form-label">Vorname *</label><input class="form-control" name="vorname" required maxlength="80"></div><div class="form-group"><label class="form-label">Nachname *</label><input class="form-control" name="nachname" required maxlength="80"></div></div>
        <div class="form-row"><div class="form-group"><label class="form-label">E-Mail *</label><input class="form-control" type="email" name="email" required maxlength="180"></div><div class="form-group"><label class="form-label">Telefon</label><input class="form-control" name="telefon" maxlength="40"></div></div>
        <div class="form-row"><div class="form-group"><label class="form-label">Schwerpunkt</label><input class="form-control" name="schwerpunkt" maxlength="200" placeholder="z.B. Skateboard, Kinderturnen"></div>
            <div class="form-group"><label class="form-label">Startet in Phase</label><select class="form-control" name="status"><?php foreach (array_slice(ONBOARDING_ABLAUF, 0, 9) as $s): ?><option value="<?= $s ?>" <?= $s === 'aufnahme' ? 'selected' : '' ?>><?= e($phase_label[$s]) ?></option><?php endforeach; ?></select></div></div>
        <div class="form-group"><label class="form-label">Notiz / Bewerbungstext</label><textarea class="form-control" name="nachricht" rows="3"></textarea></div>
        <button class="btn btn-primary">Anlegen</button>
    </form>

<?php elseif ($detail): $o = $detail; $fp = onboardingFortschritt($db, (int)$o['id']); $idx = array_search($o['status'], ONBOARDING_ABLAUF, true);
    $st = ONBOARDING_STATUS[$o['status']]; $naechst = onboardingNaechsterStatus($o['status']);
    $punkte = $db->prepare('SELECT s.*, p.titel, p.beschreibung, p.phase, p.pflicht, p.auto_pruefung, u.vorname AS von_vorname FROM onboarding_status s JOIN onboarding_punkte p ON p.id = s.punkt_id LEFT JOIN users u ON u.id = s.erledigt_von WHERE s.onboarding_id = ? AND p.aktiv = 1 ORDER BY p.reihenfolge, p.id');
    $punkte->execute([$o['id']]); $punkte = $punkte->fetchAll(); ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div><a href="<?= $self ?>" class="ob-mini">← Onboarding</a>
            <h1 class="dashboard-title"><?= e($o['vorname'] . ' ' . $o['nachname']) ?></h1>
            <p class="dashboard-subtitle"><span class="badge <?= $st['class'] ?>"><?= e($st['label']) ?></span> · <?= $fp['prozent'] ?> % erledigt<?= $o['schwerpunkt'] ? ' · ' . e($o['schwerpunkt']) : '' ?></p></div>
        <?php if ($bearb && !in_array($o['status'], ['aktiv', 'abgelehnt', 'zurueckgezogen'], true)): ?>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if ($naechst): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="status" value="<?= $naechst ?>"><button class="btn btn-primary btn-sm">Weiter: <?= e($phase_label[$naechst]) ?> →</button></form><?php endif; ?>
            <form method="POST" onsubmit="return confirm('Bewerbung ablehnen?');"><?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="status" value="abgelehnt"><button class="btn btn-ghost-light btn-sm">Ablehnen</button></form>
        </div>
        <?php endif; ?>
    </div>
    <div class="ob-ablauf" aria-label="Ablauf">
        <?php foreach (ONBOARDING_ABLAUF as $i => $s): ?><span class="ob-schritt <?= $idx !== false && $i < $idx ? 'fertig' : ($s === $o['status'] ? 'jetzt' : '') ?>"><?= e($phase_label[$s]) ?></span><?php endforeach; ?>
    </div>
    <div class="ob-balken" style="margin-bottom: 0.4rem;"><span style="width: <?= $fp['prozent'] ?>%"></span></div>
    <?php if ($fp['fehlend']): ?><p class="ob-fehlt" style="margin-bottom: 1.25rem;">Fehlend: <?= e(implode(', ', $fp['fehlend'])) ?></p><?php endif; ?>

    <div class="ob-grid">
        <div class="table-card" id="checkliste">
            <div class="table-card-header"><h2 class="table-card-title">Checkliste</h2><span class="ob-mini"><?= $fp['erledigt'] ?> / <?= $fp['gesamt'] ?></span></div>
            <?php foreach ($punkte as $p): ?>
            <div class="ob-punkt">
                <div>
                    <strong><?= e($p['titel']) ?></strong><?= $p['pflicht'] ? '' : ' <span class="ob-mini">(optional)</span>' ?>
                    <span class="badge <?= ['offen' => 'badge-gray', 'erledigt' => 'badge-success', 'nicht_erforderlich' => 'badge-navy'][$p['status']] ?>" style="margin-left: 0.3rem;"><?= e(['offen' => 'offen', 'erledigt' => 'erledigt', 'nicht_erforderlich' => 'nicht erforderlich'][$p['status']]) ?></span>
                    <div class="ob-mini"><?= e($p['beschreibung'] ?? '') ?><?= $p['auto_pruefung'] ? ' · automatisch: ' . e(ONBOARDING_AUTO[$p['auto_pruefung']] ?? '') : '' ?><?= $p['erledigt_am'] ? ' · ' . date('d.m.Y', strtotime($p['erledigt_am'])) . ($p['von_vorname'] ? ' (' . e($p['von_vorname']) . ')' : '') : '' ?><?= $p['notiz'] ? ' · ' . e($p['notiz']) : '' ?></div>
                </div>
                <?php if ($bearb): ?>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="punkt"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="punkt_id" value="<?= $p['punkt_id'] ?>">
                    <?php foreach (['erledigt' => '✓', 'nicht_erforderlich' => 'n. e.', 'offen' => '↺'] as $k => $l): if ($k === $p['status']) continue; ?>
                    <button class="btn btn-ghost-light btn-sm" name="status" value="<?= $k ?>" title="<?= e(['erledigt' => 'Erledigt', 'nicht_erforderlich' => 'Nicht erforderlich', 'offen' => 'Wieder öffnen'][$k]) ?>"><?= $l ?></button>
                    <?php endforeach; ?>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div style="padding: 0.9rem 1.25rem; display: flex; gap: 0.5rem; flex-wrap: wrap; border-top: 1px solid var(--border-light);">
                <?php if ($o['user_id']): ?>
                    <a class="btn btn-ghost-light btn-sm" href="<?= APP_URL ?>/dashboard/qualifikationen.php?user=<?= (int)$o['user_id'] ?>">Qualifikationen</a>
                    <a class="btn btn-ghost-light btn-sm" href="<?= APP_URL ?>/dashboard/admin/vertraege.php?neu=1">Vereinbarung anlegen</a>
                    <a class="btn btn-ghost-light btn-sm" href="<?= APP_URL ?>/dashboard/admin/prae-empfaenger.php">Bankdaten (PRAE)</a>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="table-card">
                <div class="table-card-header"><h2 class="table-card-title">Systemzugang</h2></div>
                <div style="padding: 1rem 1.25rem; font-size: 0.9rem;">
                    <?php if ($o['user_id']): ?>Konto verknüpft (<?= e($o['email']) ?>).
                    <?php elseif ($bearb): ?>
                        <p class="ob-mini" style="margin-bottom: 0.75rem;">Legt ein Trainer:innen-Konto an und schickt einen Link zum Passwort-Setzen.</p>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="konto"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn btn-navy btn-sm">Konto anlegen &amp; einladen</button></form>
                    <?php else: ?>Noch kein Konto.<?php endif; ?>
                </div>
            </div>
            <?php if ($bearb): ?>
            <form method="POST" class="form-card" style="margin-top: 1.5rem;"><?= csrfField() ?><input type="hidden" name="action" value="stammdaten"><input type="hidden" name="id" value="<?= $o['id'] ?>">
                <div class="form-row"><div class="form-group"><label class="form-label">Vorname</label><input class="form-control" name="vorname" value="<?= e($o['vorname']) ?>" required></div><div class="form-group"><label class="form-label">Nachname</label><input class="form-control" name="nachname" value="<?= e($o['nachname']) ?>" required></div></div>
                <div class="form-group"><label class="form-label">E-Mail</label><input class="form-control" type="email" name="email" value="<?= e($o['email']) ?>" required></div>
                <div class="form-row"><div class="form-group"><label class="form-label">Telefon</label><input class="form-control" name="telefon" value="<?= e($o['telefon'] ?? '') ?>"></div><div class="form-group"><label class="form-label">Einschulung am</label><input class="form-control" type="date" name="einschulung_am" value="<?= e($o['einschulung_am'] ?? '') ?>"></div></div>
                <div class="form-group"><label class="form-label">Schwerpunkt</label><input class="form-control" name="schwerpunkt" value="<?= e($o['schwerpunkt'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">Betreut von</label><select class="form-control" name="betreuer_id"><option value="">–</option><?php foreach (plattformTeam($db) as $t): ?><option value="<?= $t['id'] ?>" <?= (int)$o['betreuer_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label">Bewerbung / Nachricht</label><textarea class="form-control" name="nachricht" rows="3"><?= e($o['nachricht'] ?? '') ?></textarea></div>
                <div class="form-group"><label class="form-label">Interne Notiz</label><textarea class="form-control" name="notiz" rows="2"><?= e($o['notiz'] ?? '') ?></textarea></div>
                <button class="btn btn-primary btn-sm">Speichern</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

<?php else:
    $f_status = $_GET['status'] ?? 'laufend';
    $sql = 'SELECT * FROM onboarding WHERE organization_id = ?';
    if ($f_status === 'laufend') $sql .= " AND status NOT IN ('aktiv','abgelehnt','zurueckgezogen')";
    elseif (isset(ONBOARDING_STATUS[$f_status])) $sql .= ' AND status = ' . $db->quote($f_status);
    $stmt = $db->prepare($sql . ' ORDER BY created_at DESC LIMIT 200');
    $stmt->execute([$org_id]);
    $liste = $stmt->fetchAll();
    foreach ($liste as $o) if (!in_array($o['status'], ['aktiv', 'abgelehnt', 'zurueckgezogen'], true)) onboardingAutoPruefen($db, $o); ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div><h1 class="dashboard-title">Trainer-Onboarding</h1><p class="dashboard-subtitle">Von der Bewerbung bis zur Freigabe – mit Checkliste und automatischer Prüfung der Nachweise.</p></div>
        <?php if ($bearb): ?><div style="display: flex; gap: 0.5rem;"><a href="?tab=checkliste" class="btn btn-ghost-light btn-sm">Checkliste verwalten</a><a href="?neu=1" class="btn btn-primary btn-sm">+ Trainer:in aufnehmen</a></div><?php endif; ?>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
        <?php foreach (['laufend' => 'Laufend', 'bewerbung' => 'Bewerbungen', 'aktiv' => 'Abgeschlossen', 'abgelehnt' => 'Abgelehnt', 'alle' => 'Alle'] as $k => $l): ?><a href="?status=<?= $k ?>" class="btn <?= $f_status === $k ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm"><?= e($l) ?></a><?php endforeach; ?>
    </div>
    <?php if (!$liste): ?><div class="table-card"><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Vorgänge</h3><p>Bewerbungen über „Trainer*in werden“ erscheinen hier automatisch.</p></div></div>
    <?php else: ?><div class="ob-karten">
        <?php foreach ($liste as $o): $fp = onboardingFortschritt($db, (int)$o['id']); $st = ONBOARDING_STATUS[$o['status']]; ?>
        <a class="ob-karte" href="?id=<?= $o['id'] ?>">
            <div style="display: flex; justify-content: space-between; gap: 0.5rem;"><strong><?= e($o['vorname'] . ' ' . $o['nachname']) ?></strong><span class="badge <?= $st['class'] ?>"><?= e($st['label']) ?></span></div>
            <div class="ob-mini"><?= e($o['schwerpunkt'] ?: $o['email']) ?> · seit <?= date('d.m.Y', strtotime($o['created_at'])) ?></div>
            <div style="display: flex; align-items: center; gap: 0.6rem;"><div class="ob-balken" style="flex: 1;"><span style="width: <?= $fp['prozent'] ?>%"></span></div><strong style="font-size: 0.85rem;"><?= $fp['prozent'] ?> %</strong></div>
            <?php if ($fp['fehlend'] && !in_array($o['status'], ['aktiv', 'abgelehnt', 'zurueckgezogen'], true)): ?><div class="ob-fehlt">Fehlend: <?= e(implode(', ', array_slice($fp['fehlend'], 0, 3))) ?><?= count($fp['fehlend']) > 3 ? ' …' : '' ?></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div><?php endif; ?>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
