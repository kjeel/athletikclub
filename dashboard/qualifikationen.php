<?php
/**
 * Athletikclub Steiermark – Trainer-Qualifikationen
 * Eigene Nachweise pflegen (mit PDF); Status automatisch aus dem Ablaufdatum
 * (gültig / läuft bald ab / abgelaufen). Mit Recht: Übersicht aller Trainer:innen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$alle   = darf('qualifikationen.anzeigen');
$pflege = darf('qualifikationen.bearbeiten');
$uid    = $alle && !empty($_GET['user']) ? (int)$_GET['user'] : $me;
$darf_aendern = $uid === $me || $pflege;
$self   = APP_URL . '/dashboard/qualifikationen.php' . ($uid !== $me ? '?user=' . $uid : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!$darf_aendern) { flashMessage('error', 'Keine Berechtigung.'); redirect($self); }
    $action = $_POST['action'] ?? '';

    if ($action === 'speichern') {
        $id = (int)($_POST['id'] ?? 0);
        $alt = null;
        if ($id) {
            $stmt = $db->prepare('SELECT * FROM trainer_qualifikationen WHERE id = ? AND user_id = ? AND organization_id = ?');
            $stmt->execute([$id, $uid, $org_id]);
            $alt = $stmt->fetch() ?: null;
            if (!$alt) redirect($self);
        }
        $datum = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$f] ?? '') ? $_POST[$f] : null;
        $typ = isset(QUAL_TYPEN[$_POST['typ'] ?? '']) ? $_POST['typ'] : 'sonstiges';
        $werte = ['typ' => $typ, 'bezeichnung' => mb_substr(trim($_POST['bezeichnung'] ?? ''), 0, 200) ?: QUAL_TYPEN[$typ],
                  'institution' => mb_substr(trim($_POST['institution'] ?? ''), 0, 200) ?: null, 'ausgestellt_am' => $datum('ausgestellt_am'),
                  'gueltig_bis' => $datum('gueltig_bis'), 'notiz' => mb_substr(trim($_POST['notiz'] ?? ''), 0, 500) ?: null];
        if ($alt) {
            $db->prepare('UPDATE trainer_qualifikationen SET typ = ?, bezeichnung = ?, institution = ?, ausgestellt_am = ?, gueltig_bis = ?, notiz = ? WHERE id = ?')
               ->execute(array_merge(array_values($werte), [$id]));
            auditLog('geaendert', 'trainer_qualifikationen', $id, $alt, $werte, $werte['bezeichnung']);
        } else {
            $db->prepare('INSERT INTO trainer_qualifikationen (organization_id, user_id, typ, bezeichnung, institution, ausgestellt_am, gueltig_bis, notiz) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute(array_merge([$org_id, $uid], array_values($werte)));
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'trainer_qualifikationen', $id, null, $werte, $werte['bezeichnung']);
        }
        if (!empty($_FILES['nachweis']['name'])) {
            $r = plattformPdfUpload($db, $_FILES['nachweis'], 'Nachweis: ' . $werte['bezeichnung'], 'qualifikation', ['qualifikation_id' => $id, 'mitglied_id' => $uid], 'admin');
            if (isset($r['fehler'])) { flashMessage('error', 'Gespeichert, aber der Nachweis wurde nicht hochgeladen: ' . $r['fehler']); redirect($self); }
        }
        flashMessage('success', 'Qualifikation gespeichert.');
        redirect($self);
    }

    if ($action === 'loeschen') {
        $stmt = $db->prepare('SELECT * FROM trainer_qualifikationen WHERE id = ? AND user_id = ? AND organization_id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0), $uid, $org_id]);
        if ($q = $stmt->fetch()) {
            $db->prepare('DELETE FROM trainer_qualifikationen WHERE id = ?')->execute([$q['id']]);
            auditLog('geloescht', 'trainer_qualifikationen', (int)$q['id'], $q, null, $q['bezeichnung']);
            flashMessage('success', 'Qualifikation gelöscht.');
        }
        redirect($self);
    }
}

$stmt = $db->prepare('SELECT id, vorname, nachname FROM users WHERE id = ? AND organization_id = ?');
$stmt->execute([$uid, $org_id]);
$person = $stmt->fetch();
$stmt = $db->prepare('SELECT q.*, (SELECT d.id FROM dokumente d WHERE d.qualifikation_id = q.id ORDER BY d.id DESC LIMIT 1) AS dokument_id
                      FROM trainer_qualifikationen q WHERE q.user_id = ? AND q.organization_id = ? ORDER BY q.gueltig_bis IS NULL, q.gueltig_bis');
$stmt->execute([$uid, $org_id]);
$quals = $stmt->fetchAll();

// Übersicht aller Trainer:innen
$uebersicht = [];
if ($alle) {
    $stmt = $db->prepare('SELECT q.user_id, q.typ, q.bezeichnung, q.gueltig_bis FROM trainer_qualifikationen q WHERE q.organization_id = ?');
    $stmt->execute([$org_id]);
    foreach ($stmt->fetchAll() as $q) $uebersicht[(int)$q['user_id']][] = $q;
}
$trainer_liste = $alle ? plattformTrainer($db) : [];
$bearbeiten = null;
if (!empty($_GET['bearbeiten'])) foreach ($quals as $q) if ((int)$q['id'] === (int)$_GET['bearbeiten']) $bearbeiten = $q;
$form = $bearbeiten ?? ['id' => 0, 'typ' => 'erste_hilfe', 'bezeichnung' => '', 'institution' => '', 'ausgestellt_am' => '', 'gueltig_bis' => '', 'notiz' => ''];

$page_title = 'Qualifikationen';
$breadcrumb = 'Qualifikationen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ql-mini { font-size: 0.75rem; color: var(--text-muted); }
.ql-punkte { display: flex; gap: 0.3rem; flex-wrap: wrap; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Qualifikationen<?= $uid !== $me ? ': ' . e($person['vorname'] . ' ' . $person['nachname']) : '' ?></h1>
    <p class="dashboard-subtitle">Ausbildungen und Nachweise mit Ablaufdatum – das System erinnert rechtzeitig vor dem Ablauf (<?= QUAL_WARNTAGE ?> Tage vorher).</p>
</div>

<?php if ($alle): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Alle Trainer:innen</h2><span class="ql-mini">grün = gültig · gelb = läuft bald ab · rot = abgelaufen</span></div>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Trainer:in</th><th>Nachweise</th><th>Handlungsbedarf</th></tr></thead>
        <tbody><?php foreach ($trainer_liste as $t): $liste = $uebersicht[(int)$t['id']] ?? []; $bedarf = array_filter($liste, fn($q) => qualStatus($q['gueltig_bis'])['code'] !== 'gueltig'); ?>
            <tr><td><a class="text-primary" href="?user=<?= $t['id'] ?>"><?= e($t['vorname'] . ' ' . $t['nachname']) ?></a></td>
                <td><div class="ql-punkte"><?php foreach ($liste as $q): $s = qualStatus($q['gueltig_bis']); ?><span class="badge <?= $s['class'] ?>" title="<?= e($q['bezeichnung'] . ' – ' . $s['label']) ?>"><?= e(QUAL_TYPEN[$q['typ']] ?? $q['bezeichnung']) ?></span><?php endforeach; ?><?= $liste ? '' : '<span class="ql-mini">keine erfasst</span>' ?></div></td>
                <td class="ql-mini"><?php foreach ($bedarf as $q): ?><?= e($q['bezeichnung']) ?>: <?= e(qualStatus($q['gueltig_bis'])['label']) ?><br><?php endforeach; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title"><?= $uid === $me ? 'Meine Nachweise' : 'Nachweise' ?></h2></div>
    <?php if (!$quals): ?><div class="empty-state" style="padding: 2rem 1rem;"><h3>Noch keine Qualifikationen</h3><p>Erfasse z.B. Übungsleiter:in, Erste Hilfe oder Kinderschutz.</p></div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Qualifikation</th><th>Institution</th><th>Ausgestellt</th><th>Gültig bis</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($quals as $q): $s = qualStatus($q['gueltig_bis']); ?>
            <tr><td><strong><?= e($q['bezeichnung']) ?></strong><div class="ql-mini"><?= e(QUAL_TYPEN[$q['typ']] ?? '') ?><?= $q['notiz'] ? ' · ' . e($q['notiz']) : '' ?></div></td>
                <td><?= e($q['institution'] ?? '–') ?></td><td><?= plattformDatum($q['ausgestellt_am']) ?></td><td><?= $q['gueltig_bis'] ? plattformDatum($q['gueltig_bis']) : 'unbefristet' ?></td>
                <td><span class="badge <?= $s['class'] ?>"><?= e($s['label']) ?></span></td>
                <td style="white-space: nowrap;"><?php if ($q['dokument_id']): ?><a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $q['dokument_id'] ?>" class="btn btn-ghost-light btn-sm">PDF</a><?php endif; ?>
                    <?php if ($darf_aendern): ?><a href="<?= $self ?><?= $uid !== $me ? '&' : '?' ?>bearbeiten=<?= $q['id'] ?>#formular" class="btn btn-ghost-light btn-sm">Bearbeiten</a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Qualifikation löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= $q['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);" aria-label="Löschen">✕</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php if ($darf_aendern): ?>
<div class="table-card" id="formular">
    <div class="table-card-header"><h2 class="table-card-title"><?= $form['id'] ? 'Qualifikation bearbeiten' : 'Qualifikation hinzufügen' ?></h2></div>
    <form method="POST" enctype="multipart/form-data" style="padding: 1.25rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="speichern"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Art</label><select class="form-control" name="typ"><?php foreach (QUAL_TYPEN as $k => $l): ?><option value="<?= $k ?>" <?= $form['typ'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Bezeichnung</label><input class="form-control" type="text" name="bezeichnung" maxlength="200" value="<?= e((string)$form['bezeichnung']) ?>" placeholder="z.B. 16-Stunden-Erste-Hilfe-Kurs"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Institution</label><input class="form-control" type="text" name="institution" maxlength="200" value="<?= e((string)$form['institution']) ?>" placeholder="z.B. Rotes Kreuz, BSPA, SPORTUNION"></div>
            <div class="form-group"><label class="form-label">Ausgestellt / gültig bis</label><div style="display: flex; gap: 0.5rem;"><input class="form-control" type="date" name="ausgestellt_am" value="<?= e((string)$form['ausgestellt_am']) ?>"><input class="form-control" type="date" name="gueltig_bis" value="<?= e((string)$form['gueltig_bis']) ?>" title="Leer = unbefristet"></div></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Nachweis (PDF)</label><input class="form-control" type="file" name="nachweis" accept="application/pdf"></div>
            <div class="form-group"><label class="form-label">Notiz</label><input class="form-control" type="text" name="notiz" maxlength="500" value="<?= e((string)$form['notiz']) ?>"></div>
        </div>
        <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        <?php if ($form['id']): ?><a href="<?= $self ?>" class="btn btn-ghost-light btn-sm">Abbrechen</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
