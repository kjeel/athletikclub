<?php
/**
 * Athletikclub Steiermark – Übungsbibliothek (Trainer:innen)
 * Übungen mit Ausführungshinweisen und Video, auswählbar in Trainingsplänen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plaene.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'speichern') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $kategorie = $_POST['kategorie'] ?? 'kraft';
        $video     = trim($_POST['video_url'] ?? '');
        if ($name === '') $errors['name'] = 'Bitte einen Namen angeben.';
        if ($video !== '' && !filter_var($video, FILTER_VALIDATE_URL)) $errors['video_url'] = 'Die Video-Adresse ist ungültig.';
        $stmt = $db->prepare('SELECT id FROM uebungen WHERE organization_id = ? AND name = ? AND id <> ?');
        $stmt->execute([$org_id, $name, $id]);
        if ($stmt->fetch()) $errors['name'] = 'Eine Übung mit diesem Namen gibt es bereits.';

        if (empty($errors)) {
            $werte = [mb_substr($name, 0, 120), isset(UEBUNG_KATEGORIEN[$kategorie]) ? $kategorie : 'sonstiges',
                      mb_substr(trim($_POST['muskelgruppe'] ?? ''), 0, 120) ?: null, mb_substr(trim($_POST['equipment'] ?? ''), 0, 120) ?: null,
                      trim($_POST['beschreibung'] ?? '') ?: null, $video ?: null];
            if ($id) {
                $db->prepare('UPDATE uebungen SET name = ?, kategorie = ?, muskelgruppe = ?, equipment = ?, beschreibung = ?, video_url = ? WHERE id = ? AND organization_id = ?')
                   ->execute(array_merge($werte, [$id, $org_id]));
            } else {
                $db->prepare('INSERT INTO uebungen (name, kategorie, muskelgruppe, equipment, beschreibung, video_url, organization_id, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute(array_merge($werte, [$org_id, getCurrentUserId()]));
            }
            flashMessage('success', 'Übung „' . $name . '“ gespeichert.');
            redirect(APP_URL . '/dashboard/uebungen.php' . (isset($_GET['kategorie']) ? '?kategorie=' . urlencode($_GET['kategorie']) : ''));
        }
    }

    if ($action === 'loeschen') {
        // Bestehende Pläne behalten den Übungsnamen (Verweis wird nur gelöst)
        $db->prepare('DELETE FROM uebungen WHERE id = ? AND organization_id = ?')->execute([(int)($_POST['id'] ?? 0), $org_id]);
        flashMessage('success', 'Übung gelöscht. In bestehenden Plänen bleibt sie mit Namen erhalten.');
        redirect(APP_URL . '/dashboard/uebungen.php');
    }
}

$filter_kat = $_GET['kategorie'] ?? '';
$suche      = trim($_GET['q'] ?? '');
$where  = 'organization_id = ?';
$params = [$org_id];
if (isset(UEBUNG_KATEGORIEN[$filter_kat])) { $where .= ' AND kategorie = ?'; $params[] = $filter_kat; }
if ($suche !== '') { $where .= ' AND (name LIKE ? OR muskelgruppe LIKE ?)'; $params[] = "%{$suche}%"; $params[] = "%{$suche}%"; }
$stmt = $db->prepare("SELECT * FROM uebungen WHERE {$where} ORDER BY kategorie, name");
$stmt->execute($params);
$uebungen = $stmt->fetchAll();

$form = ['id' => 0, 'name' => '', 'kategorie' => $filter_kat ?: 'kraft', 'muskelgruppe' => '', 'equipment' => '', 'beschreibung' => '', 'video_url' => ''];
if ($bearbeiten = (int)($_GET['id'] ?? 0)) {
    foreach ($uebungen as $u) if ((int)$u['id'] === $bearbeiten) $form = $u;
    if (!$form['id']) {
        $stmt = $db->prepare('SELECT * FROM uebungen WHERE id = ? AND organization_id = ?');
        $stmt->execute([$bearbeiten, $org_id]);
        $form = $stmt->fetch() ?: $form;
    }
}
if (($_POST['action'] ?? '') === 'speichern') $form = array_merge($form, $_POST);
$v = fn($wert) => e((string)($wert ?? ''));

$page_title = 'Übungsbibliothek';
$breadcrumb = 'Übungsbibliothek';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/plaene.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Trainings- &amp; Ernährungspläne
    </a>
    <h1 class="dashboard-title">Übungsbibliothek</h1>
    <p class="dashboard-subtitle">Übungen mit Ausführungshinweisen und Video-Link – in Trainingsplänen per Auswahlliste verfügbar. Mitglieder sehen Beschreibung und Video direkt im Plan.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
    <select class="form-control" name="kategorie" style="max-width: 200px;" onchange="this.form.submit()">
        <option value="">Alle Kategorien</option>
        <?php foreach (UEBUNG_KATEGORIEN as $val => $label): ?><option value="<?= $val ?>" <?= $filter_kat === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <input class="form-control" type="search" name="q" value="<?= e($suche) ?>" placeholder="Name oder Muskelgruppe" style="max-width: 260px;">
    <button type="submit" class="btn btn-ghost-light btn-sm">Suchen</button>
</form>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Übungen (<?= count($uebungen) ?>)</h2></div>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Übung</th><th>Kategorie</th><th>Muskelgruppe</th><th>Equipment</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($uebungen as $u): ?>
                <tr>
                    <td>
                        <div class="text-primary"><?= e($u['name']) ?><?php if ($u['video_url']): ?> <a href="<?= e($u['video_url']) ?>" target="_blank" rel="noopener" style="font-size: 0.75rem;">▶ Video</a><?php endif; ?></div>
                        <?php if ($u['beschreibung']): ?><div style="font-size: 0.75rem; color: var(--text-muted); max-width: 420px;"><?= e($u['beschreibung']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge badge-gray"><?= e(UEBUNG_KATEGORIEN[$u['kategorie']] ?? '') ?></span></td>
                    <td><?= e($u['muskelgruppe'] ?? '–') ?></td>
                    <td><?= e($u['equipment'] ?? '–') ?></td>
                    <td style="white-space: nowrap;">
                        <a href="<?= APP_URL ?>/dashboard/uebungen.php?id=<?= $u['id'] ?>#formular" class="btn btn-ghost-light btn-sm">Bearbeiten</a>
                        <?php if (isAdmin() || (int)$u['erstellt_von'] === (int)getCurrentUserId()): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Übung aus der Bibliothek löschen?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="loeschen">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">✕</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card" id="formular">
    <div class="table-card-header"><h2 class="table-card-title"><?= $form['id'] ? 'Übung bearbeiten' : 'Neue Übung' ?></h2></div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= APP_URL ?>/dashboard/uebungen.php#formular">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="speichern">
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Name <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['name']) ? 'error' : '' ?>" type="text" name="name" maxlength="120" value="<?= $v($form['name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Kategorie</label>
                    <select class="form-control" name="kategorie">
                        <?php foreach (UEBUNG_KATEGORIEN as $val => $label): ?><option value="<?= $val ?>" <?= $form['kategorie'] === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Muskelgruppe</label><input class="form-control" type="text" name="muskelgruppe" maxlength="120" value="<?= $v($form['muskelgruppe']) ?>"></div>
                <div class="form-group"><label class="form-label">Equipment</label><input class="form-control" type="text" name="equipment" maxlength="120" value="<?= $v($form['equipment']) ?>" placeholder="z.B. Reckstange, Ringe, Kettlebell"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Ausführung &amp; Coaching-Hinweise</label>
                <textarea class="form-control" name="beschreibung" rows="3"><?= $v($form['beschreibung']) ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Video-Link (optional)</label>
                <input class="form-control <?= isset($errors['video_url']) ? 'error' : '' ?>" type="url" name="video_url" maxlength="255" value="<?= $v($form['video_url']) ?>" placeholder="https://…">
            </div>
            <button type="submit" class="btn btn-navy btn-sm"><?= $form['id'] ? 'Änderungen speichern' : 'Übung anlegen' ?></button>
            <?php if ($form['id']): ?><a href="<?= APP_URL ?>/dashboard/uebungen.php" class="btn btn-ghost-light btn-sm">Abbrechen</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
