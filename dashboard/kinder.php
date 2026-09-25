<?php
/**
 * Athletikclub Steiermark – Kinder & Einwilligungen
 * Eltern verwalten ihre Kinder (Stammdaten, Notfallkontakt, Hinweise) und erteilen/
 * widerrufen Einwilligungen. Jede Änderung ist ein neuer Eintrag (Datum, Status,
 * erfasst von) – der Verlauf bleibt nachvollziehbar. Mit „kinder.anzeigen“ sieht
 * das Team alle Kinder; Kursleitungen sehen Kinder ihrer Kurse (Notfallinfos).
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kursanmeldung.php';

requireLogin();

$db     = getDB();
$me     = (int)getCurrentUserId();
$org_id = currentOrgId();
$team_sehen      = darf('kinder.anzeigen');
$team_bearbeiten = darf('kinder.bearbeiten');

function kindLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT ki.*, u.vorname AS eltern_vorname, u.nachname AS eltern_nachname, u.email AS eltern_email, u.telefon AS eltern_telefon
                          FROM kinder ki JOIN users u ON u.id = ki.elternteil_id WHERE ki.id = ? AND ki.organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/** Kursleitung eines Kurses, in dem das Kind angemeldet ist? */
function kindInMeinemKurs(PDO $db, int $kind_id): bool
{
    $stmt = $db->prepare("SELECT 1 FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                          WHERE ka.kind_id = ? AND ka.status IN ('angemeldet','warteliste','teilgenommen') AND k.trainer_id = ?");
    $stmt->execute([$kind_id, getCurrentUserId()]);
    return (bool)$stmt->fetchColumn();
}

$darf_sehen      = fn(array $k) => (int)$k['elternteil_id'] === $me || $team_sehen || (isTrainer() && kindInMeinemKurs($db, (int)$k['id']));
$darf_bearbeiten = fn(array $k) => (int)$k['elternteil_id'] === $me || $team_bearbeiten;

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $kind   = $id ? kindLaden($db, $id) : null;
    $ziel   = APP_URL . '/dashboard/kinder.php' . ($id ? '?id=' . $id : '');

    if ($id && (!$kind || !$darf_bearbeiten($kind))) {
        flashMessage('error', 'Keine Berechtigung.');
        redirect(APP_URL . '/dashboard/kinder.php');
    }

    if ($action === 'speichern') {
        $d = [
            'vorname'           => trim($_POST['vorname'] ?? ''),
            'nachname'          => trim($_POST['nachname'] ?? ''),
            'geburtsdatum'      => trim($_POST['geburtsdatum'] ?? '') ?: null,
            'telefon'           => trim($_POST['telefon'] ?? '') ?: null,
            'notfall_name'      => trim($_POST['notfall_name'] ?? '') ?: null,
            'notfall_telefon'   => trim($_POST['notfall_telefon'] ?? '') ?: null,
            'notfall_beziehung' => trim($_POST['notfall_beziehung'] ?? '') ?: null,
            'hinweise'          => trim($_POST['hinweise'] ?? '') ?: null,
        ];
        $fehler = [];
        if ($d['vorname'] === '' || $d['nachname'] === '') $fehler[] = 'Vor- und Nachname sind Pflichtfelder.';
        if (mb_strlen($d['vorname']) > 100 || mb_strlen($d['nachname']) > 100) $fehler[] = 'Name zu lang.';
        if ($d['geburtsdatum'] && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['geburtsdatum']) || $d['geburtsdatum'] > date('Y-m-d') || $d['geburtsdatum'] < '1990-01-01')) $fehler[] = 'Bitte ein gültiges Geburtsdatum eingeben.';
        foreach (['telefon', 'notfall_telefon'] as $t) if ($d[$t] && !preg_match('/^[0-9 +\/()-]{4,40}$/', $d[$t])) $fehler[] = 'Telefonnummer ungültig.';
        if ($fehler) {
            flashMessage('error', implode(' ', array_unique($fehler)));
            redirect($ziel . ($id ? '' : '?neu=1'));
        }
        if ($kind) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $db->prepare("UPDATE kinder SET $sets WHERE id = ?")->execute([...array_values($d), $id]);
            auditLog('geaendert', 'kinder', $id, $kind, $d);
            flashMessage('success', 'Daten gespeichert.');
        } else {
            $db->prepare('INSERT INTO kinder (organization_id, elternteil_id, vorname, nachname, geburtsdatum, telefon, notfall_name, notfall_telefon, notfall_beziehung, hinweise) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$org_id, $me, ...array_values($d)]);
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'kinder', $id, null, $d);
            // Einwilligungen direkt beim Anlegen
            foreach (array_keys(EINWILLIGUNG_TYPEN) as $typ) {
                if (!empty($_POST['einw'][$typ])) {
                    $db->prepare('INSERT INTO einwilligungen (organization_id, kind_id, user_id, typ, erteilt, erfasst_von, ip_adresse) VALUES (?, ?, ?, ?, 1, ?, ?)')
                       ->execute([$org_id, $id, $me, $typ, $me, $_SERVER['REMOTE_ADDR'] ?? null]);
                }
            }
            flashMessage('success', 'Kind angelegt.');
            $ziel = APP_URL . '/dashboard/kinder.php?id=' . $id;
        }
        redirect($ziel);
    }

    if ($kind && $action === 'einwilligung') {
        $typ = $_POST['typ'] ?? '';
        $erteilt = (int)($_POST['erteilt'] ?? 0) === 1 ? 1 : 0;
        if (isset(EINWILLIGUNG_TYPEN[$typ])) {
            $vorher = einwilligungAktuell($db, $typ, (int)$kind['elternteil_id'], $id);
            if (!$vorher || (int)$vorher['erteilt'] !== $erteilt) {
                $db->prepare('INSERT INTO einwilligungen (organization_id, kind_id, user_id, typ, erteilt, erfasst_von, ip_adresse) VALUES (?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$org_id, $id, (int)$kind['elternteil_id'], $typ, $erteilt, $me, $_SERVER['REMOTE_ADDR'] ?? null]);
                auditLog('erstellt', 'einwilligungen', (int)$db->lastInsertId(), null, ['kind_id' => $id, 'typ' => $typ, 'erteilt' => $erteilt]);
                flashMessage('success', EINWILLIGUNG_TYPEN[$typ] . ': ' . ($erteilt ? 'erteilt' : 'widerrufen') . '.');
            }
        }
        redirect($ziel);
    }

    if ($kind && $action === 'entfernen') {
        // Kein echtes Löschen: Verlauf (Einwilligungen, Anwesenheit) bleibt erhalten
        $db->prepare('UPDATE kinder SET aktiv = 0 WHERE id = ?')->execute([$id]);
        $stmt = $db->prepare("SELECT ka.*, k.titel, k.status AS kurs_status, k.max_teilnehmer, k.trainer_id, k.start_datum, k.id AS kid FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                              WHERE ka.kind_id = ? AND ka.status IN ('angemeldet','warteliste') AND k.end_datum >= ?");
        $stmt->execute([$id, date('Y-m-d H:i:s')]);
        foreach ($stmt->fetchAll() as $a) {
            $k = ['id' => $a['kid'], 'titel' => $a['titel'], 'status' => $a['kurs_status'], 'max_teilnehmer' => $a['max_teilnehmer'], 'trainer_id' => $a['trainer_id'], 'start_datum' => $a['start_datum']];
            kursStornieren($db, $k, $a);
        }
        auditLog('geaendert', 'kinder', $id, ['aktiv' => 1], ['aktiv' => 0]);
        flashMessage('success', $kind['vorname'] . ' wurde entfernt; offene Kursanmeldungen wurden storniert.');
        redirect(APP_URL . '/dashboard/kinder.php');
    }
    redirect($ziel);
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$detail = null;
if (!empty($_GET['id'])) {
    $detail = kindLaden($db, (int)$_GET['id']);
    if (!$detail || !$darf_sehen($detail)) {
        flashMessage('error', 'Kein Zugriff.');
        redirect(APP_URL . '/dashboard/kinder.php');
    }
}
$neu  = !empty($_GET['neu']);
$ansicht = ($team_sehen && ($_GET['ansicht'] ?? '') === 'alle') ? 'alle' : 'meine';

$verlauf = $kurse_kind = [];
$einw = [];
if ($detail) {
    foreach (array_keys(EINWILLIGUNG_TYPEN) as $typ) $einw[$typ] = einwilligungAktuell($db, $typ, (int)$detail['elternteil_id'], (int)$detail['id']);
    $stmt = $db->prepare('SELECT e.*, u.vorname, u.nachname FROM einwilligungen e LEFT JOIN users u ON u.id = e.erfasst_von WHERE e.kind_id = ? ORDER BY e.created_at DESC, e.id DESC');
    $stmt->execute([$detail['id']]);
    $verlauf = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT ka.status, ka.angemeldet_am, k.id, k.titel, k.start_datum FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id WHERE ka.kind_id = ? ORDER BY k.start_datum DESC');
    $stmt->execute([$detail['id']]);
    $kurse_kind = $stmt->fetchAll();
}

$meine_kinder = meineKinder($db, $me);
$einw_stand = function (int $eltern_id, int $kind_id) use ($db): array {
    $r = [];
    foreach (array_keys(EINWILLIGUNG_TYPEN) as $typ) {
        $e = einwilligungAktuell($db, $typ, $eltern_id, $kind_id);
        $r[$typ] = $e ? (int)$e['erteilt'] : null;
    }
    return $r;
};

$alle = [];
if ($ansicht === 'alle') {
    $suche = trim($_GET['suche'] ?? '');
    $sql = "SELECT ki.*, u.vorname AS eltern_vorname, u.nachname AS eltern_nachname,
                   (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kind_id = ki.id AND ka.status IN ('angemeldet','warteliste')) AS kurse_aktiv
            FROM kinder ki JOIN users u ON u.id = ki.elternteil_id WHERE ki.organization_id = ? AND ki.aktiv = 1";
    $p = [$org_id];
    if ($suche !== '') {
        $sql .= ' AND (ki.vorname LIKE ? OR ki.nachname LIKE ? OR u.nachname LIKE ?)';
        array_push($p, "%$suche%", "%$suche%", "%$suche%");
    }
    $stmt = $db->prepare($sql . ' ORDER BY ki.nachname, ki.vorname LIMIT 500');
    $stmt->execute($p);
    $alle = $stmt->fetchAll();
}

$titel = $detail ? $detail['vorname'] . ' ' . $detail['nachname'] : 'Kinder & Einwilligungen';
$page_title = $titel;
$breadcrumb = 'Kinder';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$einw_kurz = ['teilnahme' => 'Teilnahme', 'datenschutz' => 'Datenschutz', 'foto_video' => 'Foto/Video', 'notfallkontakt' => 'Notfall'];
$badge_einw = fn(?int $s) => $s === 1 ? 'badge-success' : ($s === 0 ? 'badge-danger' : 'badge-gray');
$kind_formular = function (?array $k) use ($neu) { ?>
    <form method="POST" class="form-card" style="max-width: 760px;">
        <?= csrfField() ?><input type="hidden" name="action" value="speichern"><input type="hidden" name="id" value="<?= $k ? (int)$k['id'] : 0 ?>">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Vorname <span class="required">*</span></label><input class="form-control" name="vorname" maxlength="100" required value="<?= e($k['vorname'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Nachname <span class="required">*</span></label><input class="form-control" name="nachname" maxlength="100" required value="<?= e($k['nachname'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Geburtsdatum</label><input class="form-control" type="date" name="geburtsdatum" max="<?= date('Y-m-d') ?>" value="<?= e($k['geburtsdatum'] ?? '') ?>"><span class="form-hint">Wird für Altersgrenzen von Kursen benötigt.</span></div>
            <div class="form-group"><label class="form-label">Telefon des Kindes</label><input class="form-control" name="telefon" maxlength="40" value="<?= e($k['telefon'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Notfallkontakt</label><input class="form-control" name="notfall_name" maxlength="150" placeholder="Name" value="<?= e($k['notfall_name'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Notfall-Telefon</label><input class="form-control" name="notfall_telefon" maxlength="40" value="<?= e($k['notfall_telefon'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Beziehung</label><input class="form-control" name="notfall_beziehung" maxlength="60" placeholder="z.B. Oma" value="<?= e($k['notfall_beziehung'] ?? '') ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Hinweise für Trainer:innen</label><textarea class="form-control" name="hinweise" rows="3" placeholder="Allergien, Medikamente, Besonderheiten …"><?= e($k['hinweise'] ?? '') ?></textarea>
            <span class="form-hint">Sichtbar für die Kursleitung, bei der das Kind angemeldet ist.</span></div>
        <?php if (!$k): ?>
        <div class="form-group"><label class="form-label">Einwilligungen</label>
            <?php foreach (EINWILLIGUNG_TYPEN as $typ => $text): ?>
            <label class="form-check" style="margin-bottom: 0.4rem;"><input type="checkbox" name="einw[<?= $typ ?>]" value="1" <?= $typ !== 'foto_video' ? 'checked' : '' ?>><span class="form-check-label"><?= e($text) ?></span></label>
            <?php endforeach; ?>
            <span class="form-hint">Die Teilnahme-Einwilligung ist für Kursanmeldungen nötig. Alle Einwilligungen können jederzeit widerrufen werden.</span>
        </div>
        <?php endif; ?>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary"><?= $k ? 'Speichern' : 'Kind anlegen' ?></button>
            <a href="<?= APP_URL ?>/dashboard/kinder.php<?= $k ? '?id=' . (int)$k['id'] : '' ?>" class="btn btn-ghost-light">Abbrechen</a>
        </div>
    </form>
<?php };
?>

<style>
.kind-karten { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.kind-karte { background: var(--bg-card, #fff); border: 1px solid var(--border-light); border-radius: 1rem; padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 0.6rem; }
.kind-karte h3 { margin: 0; font-size: 1.05rem; }
.kind-einw { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.einw-zeile { display: grid; grid-template-columns: 1fr auto; gap: 0.75rem; align-items: center; padding: 0.8rem 1.25rem; border-bottom: 1px solid var(--border-light); }
.einw-zeile:last-child { border-bottom: none; }
.einw-meta { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem; }
.kind-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; padding: 1.25rem; font-size: 0.875rem; }
</style>

<?php if ($detail && !isset($_GET['bearbeiten'])): ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <a href="<?= APP_URL ?>/dashboard/kinder.php" style="font-size: 0.8rem; color: var(--text-muted);">← Kinder</a>
            <h1 class="dashboard-title"><?= e($titel) ?></h1>
            <p class="dashboard-subtitle"><?= $detail['geburtsdatum'] ? 'geb. ' . date('d.m.Y', strtotime($detail['geburtsdatum'])) . ' · ' . alterAm($detail['geburtsdatum'], date('Y-m-d')) . ' Jahre' : 'Geburtsdatum fehlt' ?><?= !(int)$detail['aktiv'] ? ' · entfernt' : '' ?></p>
        </div>
        <?php if ($darf_bearbeiten($detail) && (int)$detail['aktiv']): ?>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="?id=<?= $detail['id'] ?>&bearbeiten=1" class="btn btn-navy btn-sm">Bearbeiten</a>
            <form method="POST" onsubmit="return confirm('<?= e($detail['vorname']) ?> entfernen? Offene Kursanmeldungen werden storniert. Der Einwilligungsverlauf bleibt gespeichert.');"><?= csrfField() ?>
                <input type="hidden" name="action" value="entfernen"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm">Entfernen</button></form>
        </div>
        <?php endif; ?>
    </div>

    <div class="grid-2" style="display: grid; gap: 1.5rem; align-items: start;">
        <div class="table-card">
            <div class="table-card-header"><h2 class="table-card-title">Kontakt & Notfall</h2></div>
            <div class="kind-info">
                <div><strong>Elternteil</strong><br><?= e($detail['eltern_vorname'] . ' ' . $detail['eltern_nachname']) ?><?php if ((int)$detail['elternteil_id'] !== $me): ?><br><a href="mailto:<?= e($detail['eltern_email']) ?>"><?= e($detail['eltern_email']) ?></a><?= $detail['eltern_telefon'] ? '<br>' . e($detail['eltern_telefon']) : '' ?><?php endif; ?></div>
                <div><strong>Notfallkontakt</strong><br><?= $detail['notfall_name'] ? e($detail['notfall_name']) . ($detail['notfall_beziehung'] ? ' (' . e($detail['notfall_beziehung']) . ')' : '') . '<br>' . e($detail['notfall_telefon'] ?? '') : '<span style="color: var(--text-muted);">nicht angegeben</span>' ?></div>
                <?php if ($detail['telefon']): ?><div><strong>Telefon Kind</strong><br><?= e($detail['telefon']) ?></div><?php endif; ?>
            </div>
            <?php if ($detail['hinweise']): ?><div class="kurs-voraus" style="margin: 0 1.25rem 1.25rem; padding: 0.75rem 0.9rem; border-left: 3px solid var(--gold-accent); background: var(--bg-muted); border-radius: 0.4rem; font-size: 0.85rem;"><strong>Hinweise</strong><br><?= nl2br(e($detail['hinweise'])) ?></div><?php endif; ?>
        </div>

        <div class="table-card">
            <div class="table-card-header"><h2 class="table-card-title">Einwilligungen</h2></div>
            <?php foreach (EINWILLIGUNG_TYPEN as $typ => $text): $e = $einw[$typ]; $s = $e ? (int)$e['erteilt'] : null; ?>
            <div class="einw-zeile">
                <div>
                    <span class="badge <?= $badge_einw($s) ?>"><?= $s === 1 ? 'erteilt' : ($s === 0 ? 'widerrufen' : 'offen') ?></span>
                    <span style="font-size: 0.875rem; margin-left: 0.3rem;"><?= e($text) ?></span>
                    <?php if ($e): ?><div class="einw-meta">seit <?= date('d.m.Y, H:i', strtotime($e['created_at'])) ?> · Fassung <?= e($e['text_version']) ?></div><?php endif; ?>
                </div>
                <?php if ($darf_bearbeiten($detail) && (int)$detail['aktiv']): ?>
                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="einwilligung"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><input type="hidden" name="typ" value="<?= $typ ?>">
                    <input type="hidden" name="erteilt" value="<?= $s === 1 ? 0 : 1 ?>">
                    <button type="submit" class="btn <?= $s === 1 ? 'btn-ghost-light' : 'btn-primary' ?> btn-sm" <?= $s === 1 ? "onclick=\"return confirm('Einwilligung widerrufen?');\"" : '' ?>><?= $s === 1 ? 'Widerrufen' : 'Erteilen' ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="table-card" style="margin-top: 1.5rem;">
        <div class="table-card-header"><h2 class="table-card-title">Kurse</h2></div>
        <?php if (!$kurse_kind): ?><div class="empty-state" style="padding: 1.25rem;"><p>Noch keine Kursanmeldungen. <a href="<?= APP_URL ?>/dashboard/kurse.php">Kurse ansehen</a></p></div>
        <?php else: ?><div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Kurs</th><th>Beginn</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($kurse_kind as $k): ?><tr data-row-href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $k['id'] ?>"><td class="text-primary"><?= e($k['titel']) ?></td><td><?= date('d.m.Y', strtotime($k['start_datum'])) ?></td><td><span class="badge <?= ['angemeldet' => 'badge-success', 'warteliste' => 'badge-info', 'storniert' => 'badge-danger'][$k['status']] ?? 'badge-gray' ?>"><?= e(ucfirst($k['status'])) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>

    <div class="table-card" style="margin-top: 1.5rem;">
        <div class="table-card-header"><h2 class="table-card-title">Verlauf der Einwilligungen</h2></div>
        <?php if (!$verlauf): ?><div class="empty-state" style="padding: 1.25rem;"><p>Noch keine Einträge.</p></div>
        <?php else: ?><div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Datum</th><th>Einwilligung</th><th>Status</th><th>Erfasst von</th><th>Fassung</th></tr></thead><tbody>
            <?php foreach ($verlauf as $v): ?><tr><td><?= date('d.m.Y H:i', strtotime($v['created_at'])) ?></td><td><?= e($einw_kurz[$v['typ']] ?? $v['typ']) ?></td>
                <td><span class="badge <?= (int)$v['erteilt'] ? 'badge-success' : 'badge-danger' ?>"><?= (int)$v['erteilt'] ? 'erteilt' : 'widerrufen' ?></span></td>
                <td><?= $v['vorname'] ? e($v['vorname'] . ' ' . $v['nachname']) : '–' ?></td><td><?= e($v['text_version']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>

<?php elseif ($neu || ($detail && isset($_GET['bearbeiten']) && $darf_bearbeiten($detail))): ?>
    <div class="dashboard-header">
        <h1 class="dashboard-title"><?= $detail ? e($titel) . ' bearbeiten' : 'Kind hinzufügen' ?></h1>
        <p class="dashboard-subtitle">Stammdaten, Notfallkontakt und Hinweise für Trainer:innen.</p>
    </div>
    <?php $kind_formular($detail); ?>

<?php else: ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 class="dashboard-title">Kinder & Einwilligungen</h1>
            <p class="dashboard-subtitle">Kinder anlegen, für Kurse anmelden und Einwilligungen verwalten.</p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if ($team_sehen): ?>
                <a href="?ansicht=<?= $ansicht === 'alle' ? 'meine' : 'alle' ?>" class="btn btn-ghost-light btn-sm"><?= $ansicht === 'alle' ? 'Meine Kinder' : 'Alle Kinder (Team)' ?></a>
            <?php endif; ?>
            <a href="?neu=1" class="btn btn-primary btn-sm">+ Kind hinzufügen</a>
        </div>
    </div>

    <?php if ($ansicht === 'alle'): ?>
        <form method="GET" style="margin-bottom: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;"><input type="hidden" name="ansicht" value="alle">
            <input class="form-control" style="max-width: 280px;" name="suche" value="<?= e($_GET['suche'] ?? '') ?>" placeholder="Name Kind oder Elternteil …"><button class="btn btn-navy btn-sm">Suchen</button></form>
        <div class="table-card"><div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Kind</th><th>Alter</th><th>Elternteil</th><th>Einwilligungen</th><th>Kurse</th><th>Notfall</th></tr></thead><tbody>
            <?php if (!$alle): ?><tr><td colspan="6" style="text-align: center; color: var(--text-muted);">Keine Kinder gefunden.</td></tr><?php endif; ?>
            <?php foreach ($alle as $k): $st = $einw_stand((int)$k['elternteil_id'], (int)$k['id']); ?>
                <tr data-row-href="<?= APP_URL ?>/dashboard/kinder.php?id=<?= $k['id'] ?>">
                    <td class="text-primary"><?= e($k['vorname'] . ' ' . $k['nachname']) ?><?= $k['hinweise'] ? ' <span class="badge badge-warning">Hinweis</span>' : '' ?></td>
                    <td><?= alterAm($k['geburtsdatum'], date('Y-m-d')) ?? '–' ?></td>
                    <td><?= e($k['eltern_vorname'] . ' ' . $k['eltern_nachname']) ?></td>
                    <td><div class="kind-einw"><?php foreach ($st as $typ => $s): ?><span class="badge <?= $badge_einw($s) ?>"><?= e($einw_kurz[$typ]) ?></span><?php endforeach; ?></div></td>
                    <td><?= (int)$k['kurse_aktiv'] ?></td>
                    <td><?= $k['notfall_telefon'] ? e($k['notfall_telefon']) : '<span class="badge badge-warning">fehlt</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div></div>
    <?php elseif (!$meine_kinder): ?>
        <div class="table-card"><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Noch keine Kinder hinterlegt</h3>
            <p>Lege dein Kind an, um es für Kurse anzumelden. Einwilligungen und Notfallkontakt werden dabei gleich miterfasst.</p>
            <a href="?neu=1" class="btn btn-primary btn-sm">Kind hinzufügen</a></div></div>
    <?php else: ?>
        <div class="kind-karten">
        <?php foreach ($meine_kinder as $k): $st = $einw_stand($me, (int)$k['id']); ?>
            <div class="kind-karte">
                <div style="display: flex; justify-content: space-between; gap: 0.5rem;">
                    <h3><a href="?id=<?= $k['id'] ?>"><?= e($k['vorname'] . ' ' . $k['nachname']) ?></a></h3>
                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?= $k['geburtsdatum'] ? alterAm($k['geburtsdatum'], date('Y-m-d')) . ' Jahre' : '' ?></span>
                </div>
                <div class="kind-einw"><?php foreach ($st as $typ => $s): ?><span class="badge <?= $badge_einw($s) ?>" title="<?= e(EINWILLIGUNG_TYPEN[$typ]) ?>"><?= e($einw_kurz[$typ]) ?></span><?php endforeach; ?></div>
                <?php if ($st['teilnahme'] !== 1): ?><span class="form-hint" style="color: #B45309;">Für Kursanmeldungen ist die Teilnahme-Einwilligung nötig.</span><?php endif; ?>
                <?php if (!$k['notfall_telefon']): ?><span class="form-hint">Notfallkontakt fehlt.</span><?php endif; ?>
                <div style="display: flex; gap: 0.5rem; margin-top: auto;"><a href="?id=<?= $k['id'] ?>" class="btn btn-navy btn-sm">Öffnen</a><a href="<?= APP_URL ?>/dashboard/kurse.php" class="btn btn-ghost-light btn-sm">Kurs suchen</a></div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
