<?php
/**
 * Athletikclub Steiermark – Aufgaben
 * Meine Aufgaben, überfällige Aufgaben, Aufgaben je Projekt.
 * Zuweisen an andere: Recht „aufgaben.erstellen“ oder Projektleitung.
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
$heute  = date('Y-m-d');
$errors = [];

/** Projekte, die ich leite (für Zuweisungsrechte). */
$stmt = $db->prepare('SELECT id FROM projekte WHERE organization_id = ? AND leitung_id = ?');
$stmt->execute([$org_id, $me]);
$meine_leitung = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$aufgabe_laden = function (int $id) use ($db, $org_id): ?array {
    $stmt = $db->prepare('SELECT * FROM aufgaben WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, $org_id]);
    return $stmt->fetch() ?: null;
};
$darf_bearbeiten = fn(array $a) => darf('aufgaben.bearbeiten') || (int)$a['verantwortlich_id'] === $me || (int)$a['erstellt_von'] === $me || in_array((int)$a['projekt_id'], $meine_leitung, true);
$darf_sehen = fn(array $a) => darf('aufgaben.anzeigen') || $darf_bearbeiten($a);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $zurueck = !empty($_POST['zurueck']) && str_starts_with($_POST['zurueck'], '/dashboard/') ? APP_URL . $_POST['zurueck'] : APP_URL . '/dashboard/aufgaben.php';

    if ($action === 'speichern') {
        $id = (int)($_POST['id'] ?? 0);
        $alt = $id ? $aufgabe_laden($id) : null;
        if ($id && (!$alt || !$darf_bearbeiten($alt))) { flashMessage('error', 'Keine Berechtigung.'); redirect($zurueck); }
        $projekt = (int)($_POST['projekt_id'] ?? 0) ?: null;
        $verantwortlich = (int)($_POST['verantwortlich_id'] ?? 0) ?: $me;
        // Anderen zuweisen nur mit Recht oder als Projektleitung
        if ($verantwortlich !== $me && !darf('aufgaben.erstellen') && !in_array((int)$projekt, $meine_leitung, true) && (!$alt || (int)$alt['verantwortlich_id'] !== $verantwortlich)) {
            $verantwortlich = $me;
        }
        $werte = ['titel' => mb_substr(trim($_POST['titel'] ?? ''), 0, 200), 'beschreibung' => trim($_POST['beschreibung'] ?? '') ?: null, 'projekt_id' => $projekt,
                  'verantwortlich_id' => $verantwortlich, 'prioritaet' => isset(AUFGABE_PRIO[$_POST['prioritaet'] ?? '']) ? $_POST['prioritaet'] : 'normal',
                  'deadline' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['deadline'] ?? '') ? $_POST['deadline'] : null,
                  'status' => isset(AUFGABE_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : ($alt['status'] ?? 'offen')];
        if ($werte['titel'] === '') { flashMessage('error', 'Bitte einen Titel angeben.'); redirect($zurueck); }
        $erledigt_am = $werte['status'] === 'erledigt' ? ($alt['erledigt_am'] ?? date('Y-m-d H:i:s')) : null;
        if ($alt) {
            $db->prepare('UPDATE aufgaben SET titel = ?, beschreibung = ?, projekt_id = ?, verantwortlich_id = ?, prioritaet = ?, deadline = ?, status = ?, erledigt_am = ? WHERE id = ?')
               ->execute(array_merge(array_values($werte), [$erledigt_am, $id]));
            auditLog('geaendert', 'aufgaben', $id, $alt, $werte, $werte['titel']);
        } else {
            $db->prepare('INSERT INTO aufgaben (organization_id, titel, beschreibung, projekt_id, verantwortlich_id, prioritaet, deadline, status, erledigt_am, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute(array_merge([$org_id], array_values($werte), [$erledigt_am, $me]));
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'aufgaben', $id, null, $werte, $werte['titel']);
        }
        if ($werte['verantwortlich_id'] !== $me && (!$alt || (int)$alt['verantwortlich_id'] !== $werte['verantwortlich_id'])) {
            benachrichtigen($werte['verantwortlich_id'], 'aufgabe', 'Neue Aufgabe: ' . $werte['titel'], $werte['deadline'] ? 'Deadline ' . plattformDatum($werte['deadline']) : null, '/dashboard/aufgaben.php?id=' . $id);
        }
        flashMessage('success', 'Aufgabe gespeichert.');
        redirect($zurueck);
    }

    if ($action === 'status') {
        $a = $aufgabe_laden((int)($_POST['id'] ?? 0));
        $neu = $_POST['status'] ?? '';
        if ($a && $darf_bearbeiten($a) && isset(AUFGABE_STATUS[$neu])) {
            $db->prepare('UPDATE aufgaben SET status = ?, erledigt_am = ? WHERE id = ?')->execute([$neu, $neu === 'erledigt' ? date('Y-m-d H:i:s') : null, $a['id']]);
            auditLog('status', 'aufgaben', (int)$a['id'], ['status' => $a['status']], ['status' => $neu], $a['titel']);
            if ($neu === 'erledigt' && $a['erstellt_von'] && (int)$a['erstellt_von'] !== $me) benachrichtigen((int)$a['erstellt_von'], 'aufgabe', 'Erledigt: ' . $a['titel'], null, '/dashboard/aufgaben.php?id=' . $a['id']);
        }
        redirect($zurueck);
    }

    if ($action === 'loeschen') {
        $a = $aufgabe_laden((int)($_POST['id'] ?? 0));
        if ($a && (darf('aufgaben.loeschen') || (int)$a['erstellt_von'] === $me)) {
            $db->prepare('DELETE FROM aufgaben WHERE id = ?')->execute([$a['id']]);
            auditLog('geloescht', 'aufgaben', (int)$a['id'], $a, null, $a['titel']);
            flashMessage('success', 'Aufgabe gelöscht.');
        }
        redirect(APP_URL . '/dashboard/aufgaben.php');
    }
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$ansicht = in_array($_GET['ansicht'] ?? '', ['meine', 'alle', 'ueberfaellig', 'erledigt'], true) ? $_GET['ansicht'] : 'meine';
if (!darf('aufgaben.anzeigen') && $ansicht === 'alle') $ansicht = 'meine';
$projekt_filter = (int)($_GET['projekt'] ?? 0);
$where = 'a.organization_id = ?';
$params = [$org_id];
if ($ansicht === 'meine') { $where .= " AND a.verantwortlich_id = ? AND a.status <> 'erledigt'"; $params[] = $me; }
if ($ansicht === 'ueberfaellig') { $where .= " AND a.status <> 'erledigt' AND a.deadline < ?"; $params[] = $heute; }
if ($ansicht === 'alle') $where .= " AND a.status <> 'erledigt'";
if ($ansicht === 'erledigt') $where .= " AND a.status = 'erledigt'";
if ($projekt_filter) { $where .= ' AND a.projekt_id = ?'; $params[] = $projekt_filter; }
if (!darf('aufgaben.anzeigen')) {
    $where .= ' AND (a.verantwortlich_id = ? OR a.erstellt_von = ?' . ($meine_leitung ? ' OR a.projekt_id IN (' . implode(',', $meine_leitung) . ')' : '') . ')';
    array_push($params, $me, $me);
}
$stmt = $db->prepare("SELECT a.*, u.vorname, u.nachname, p.name AS projekt_name FROM aufgaben a LEFT JOIN users u ON u.id = a.verantwortlich_id LEFT JOIN projekte p ON p.id = a.projekt_id
                      WHERE {$where} ORDER BY a.status = 'erledigt', a.deadline IS NULL, a.deadline,
                      CASE a.prioritaet WHEN 'kritisch' THEN 0 WHEN 'hoch' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END LIMIT 300");
$stmt->execute($params);
$aufgaben = $stmt->fetchAll();

$zaehler = [];
foreach (['meine' => "verantwortlich_id = {$me} AND status <> 'erledigt'", 'ueberfaellig' => "status <> 'erledigt' AND deadline < '{$heute}'" . (darf('aufgaben.anzeigen') ? '' : " AND (verantwortlich_id = {$me} OR erstellt_von = {$me})")] as $k => $bed) {
    $zaehler[$k] = (int)$db->query("SELECT COUNT(*) FROM aufgaben WHERE organization_id = {$org_id} AND {$bed}")->fetchColumn();
}

$bearbeiten_aufgabe = !empty($_GET['id']) ? $aufgabe_laden((int)$_GET['id']) : null;
if ($bearbeiten_aufgabe && !$darf_sehen($bearbeiten_aufgabe)) $bearbeiten_aufgabe = null;
$form = $bearbeiten_aufgabe ?? ['id' => 0, 'titel' => '', 'beschreibung' => '', 'projekt_id' => $projekt_filter ?: null, 'verantwortlich_id' => $me, 'prioritaet' => 'normal', 'deadline' => '', 'status' => 'offen'];
$darf_zuweisen = darf('aufgaben.erstellen') || $meine_leitung;
$team = plattformTeam($db);
$projekte = plattformProjekte($db);
$zurueck_url = '/dashboard/aufgaben.php?' . http_build_query(array_filter(['ansicht' => $ansicht, 'projekt' => $projekt_filter ?: null]));

$page_title = 'Aufgaben';
$breadcrumb = 'Aufgaben';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.au-chips { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.au-chips a { font-size: 0.8rem; padding: 0.4rem 0.85rem; border-radius: 99px; border: 1px solid var(--border-color); color: var(--text-secondary); }
.au-chips a.aktiv { background: var(--navy-primary); border-color: var(--navy-primary); color: #fff; }
.au-mini { font-size: 0.75rem; color: var(--text-muted); }
.au-ueber { color: var(--danger); font-weight: 600; }
.au-schnell { display: flex; gap: 0.3rem; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Aufgaben</h1>
        <p class="dashboard-subtitle">To-dos für Projekte und Verein – mit Verantwortlichen, Priorität und Deadline.</p>
    </div>
    <a href="#neu" class="btn btn-navy btn-sm">+ Aufgabe</a>
</div>

<div class="au-chips">
    <a href="?ansicht=meine" class="<?= $ansicht === 'meine' ? 'aktiv' : '' ?>">Meine Aufgaben (<?= $zaehler['meine'] ?>)</a>
    <a href="?ansicht=ueberfaellig" class="<?= $ansicht === 'ueberfaellig' ? 'aktiv' : '' ?>" style="<?= $zaehler['ueberfaellig'] ? 'border-color: var(--danger);' : '' ?>">Überfällig (<?= $zaehler['ueberfaellig'] ?>)</a>
    <?php if (darf('aufgaben.anzeigen')): ?><a href="?ansicht=alle" class="<?= $ansicht === 'alle' ? 'aktiv' : '' ?>">Alle offenen</a><?php endif; ?>
    <a href="?ansicht=erledigt" class="<?= $ansicht === 'erledigt' ? 'aktiv' : '' ?>">Erledigt</a>
</div>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <?php if (!$aufgaben): ?>
        <div class="empty-state" style="padding: 2.5rem 1rem;"><h3><?= $ansicht === 'ueberfaellig' ? 'Nichts überfällig' : 'Keine Aufgaben' ?></h3><p><?= $ansicht === 'meine' ? 'Dir ist gerade nichts zugewiesen.' : '' ?></p></div>
    <?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Aufgabe</th><th>Projekt</th><th>Verantwortlich</th><th>Priorität</th><th>Deadline</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($aufgaben as $a): $ueber = $a['status'] !== 'erledigt' && $a['deadline'] && $a['deadline'] < $heute; ?>
            <tr>
                <td><a class="text-primary" href="?id=<?= $a['id'] ?>&ansicht=<?= $ansicht ?>#neu"><?= e($a['titel']) ?></a><?php if ($a['beschreibung']): ?><div class="au-mini"><?= e(mb_strimwidth($a['beschreibung'], 0, 90, '…')) ?></div><?php endif; ?></td>
                <td class="au-mini"><?= $a['projekt_name'] ? '<a href="' . APP_URL . '/dashboard/projekt.php?id=' . (int)$a['projekt_id'] . '&tab=aufgaben">' . e($a['projekt_name']) . '</a>' : '–' ?></td>
                <td><?= e($a['vorname'] ? $a['vorname'] . ' ' . $a['nachname'] : '–') ?></td>
                <td><span class="badge <?= AUFGABE_PRIO[$a['prioritaet']]['class'] ?>"><?= AUFGABE_PRIO[$a['prioritaet']]['label'] ?></span></td>
                <td class="<?= $ueber ? 'au-ueber' : '' ?>"><?= plattformDatum($a['deadline']) ?><?php if ($a['deadline'] && $a['status'] !== 'erledigt'): ?><div class="au-mini"><?= plattformFrist($a['deadline']) ?></div><?php endif; ?></td>
                <td><span class="badge <?= AUFGABE_STATUS[$a['status']]['class'] ?>"><?= AUFGABE_STATUS[$a['status']]['label'] ?></span></td>
                <td><?php if ($darf_bearbeiten($a) && $a['status'] !== 'erledigt'): ?>
                    <form method="POST" class="au-schnell"><?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="zurueck" value="<?= e($zurueck_url) ?>">
                        <?php if ($a['status'] === 'offen'): ?><button type="submit" name="status" value="in_bearbeitung" class="btn btn-ghost-light btn-sm">Starten</button><?php endif; ?>
                        <button type="submit" name="status" value="erledigt" class="btn btn-ghost-light btn-sm" title="Als erledigt markieren">✓</button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="table-card" id="neu">
    <div class="table-card-header"><h2 class="table-card-title"><?= $form['id'] ? 'Aufgabe bearbeiten' : 'Neue Aufgabe' ?></h2><?php if ($form['id']): ?><a href="?ansicht=<?= $ansicht ?>" class="btn btn-ghost-light btn-sm">Neue Aufgabe</a><?php endif; ?></div>
    <form method="POST" style="padding: 1.25rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="speichern"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>"><input type="hidden" name="zurueck" value="<?= e($zurueck_url) ?>">
        <?php $lesend = $form['id'] && !$darf_bearbeiten($form); ?>
        <fieldset <?= $lesend ? 'disabled' : '' ?> style="border: 0; padding: 0; margin: 0;">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Titel <span class="required">*</span></label><input class="form-control" type="text" name="titel" maxlength="200" value="<?= e($form['titel']) ?>" required></div>
            <div class="form-group"><label class="form-label">Projekt</label><select class="form-control" name="projekt_id"><option value="">– kein Projekt –</option><?php foreach ($projekte as $p): ?><option value="<?= $p['id'] ?>" <?= (int)$form['projekt_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Verantwortlich</label>
                <select class="form-control" name="verantwortlich_id"><?php foreach ($team as $t): if (!$darf_zuweisen && (int)$t['id'] !== $me && (int)$t['id'] !== (int)$form['verantwortlich_id']) continue; ?><option value="<?= $t['id'] ?>" <?= (int)$form['verantwortlich_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Deadline</label><input class="form-control" type="date" name="deadline" value="<?= e((string)$form['deadline']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Priorität</label><select class="form-control" name="prioritaet"><?php foreach (AUFGABE_PRIO as $k => $s): ?><option value="<?= $k ?>" <?= $form['prioritaet'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status"><?php foreach (AUFGABE_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $form['status'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">Beschreibung</label><textarea class="form-control" name="beschreibung" rows="3"><?= e((string)$form['beschreibung']) ?></textarea></div>
        <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        </fieldset>
    </form>
    <?php if ($form['id'] && (darf('aufgaben.loeschen') || (int)$form['erstellt_von'] === $me)): ?>
    <form method="POST" style="padding: 0 1.25rem 1.25rem;" onsubmit="return confirm('Aufgabe löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Löschen</button></form>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
