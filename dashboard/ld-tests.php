<?php
/**
 * Athletikclub Steiermark – Testkatalog der Leistungsdiagnostik (Trainer:innen)
 * Tests mit Protokoll, Einheit und Wertungsrichtung. Tests mit Messwerten
 * werden nicht gelöscht, sondern deaktiviert (Verläufe bleiben erhalten).
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/leistung.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'speichern') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
        $einheit = mb_substr(trim($_POST['einheit'] ?? ''), 0, 20);
        if ($name === '') $errors['name'] = 'Bitte einen Namen angeben.';
        if ($einheit === '') $errors['einheit'] = 'Bitte eine Einheit angeben (z.B. Wdh., s, cm, kg).';
        $stmt = $db->prepare('SELECT id FROM ld_tests WHERE organization_id = ? AND name = ? AND id <> ?');
        $stmt->execute([$org_id, $name, $id]);
        if ($stmt->fetch()) $errors['name'] = 'Einen Test mit diesem Namen gibt es bereits.';

        if (empty($errors)) {
            $werte = [$name, isset(LD_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : 'kraft', $einheit,
                      isset(LD_RICHTUNGEN[$_POST['richtung'] ?? '']) ? $_POST['richtung'] : 'hoeher',
                      max(0, min(3, (int)($_POST['dezimalen'] ?? 0))), trim($_POST['beschreibung'] ?? '') ?: null];
            if ($id) {
                $db->prepare('UPDATE ld_tests SET name = ?, kategorie = ?, einheit = ?, richtung = ?, dezimalen = ?, beschreibung = ? WHERE id = ? AND organization_id = ?')
                   ->execute(array_merge($werte, [$id, $org_id]));
            } else {
                $stmt = $db->prepare('SELECT COALESCE(MAX(sortierung), 0) + 10 FROM ld_tests WHERE organization_id = ?');
                $stmt->execute([$org_id]);
                $db->prepare('INSERT INTO ld_tests (name, kategorie, einheit, richtung, dezimalen, beschreibung, organization_id, sortierung, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute(array_merge($werte, [$org_id, (int)$stmt->fetchColumn(), getCurrentUserId()]));
            }
            flashMessage('success', 'Test „' . $name . '“ gespeichert.');
            redirect(APP_URL . '/dashboard/ld-tests.php');
        }
    }

    if ($action === 'aktiv') {
        $db->prepare('UPDATE ld_tests SET aktiv = ? WHERE id = ? AND organization_id = ?')->execute([(int)!empty($_POST['aktiv']), $id, $org_id]);
        redirect(APP_URL . '/dashboard/ld-tests.php');
    }

    if ($action === 'loeschen') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM ld_ergebnisse WHERE test_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            flashMessage('error', 'Der Test wird in Testungen verwendet und kann nur deaktiviert werden.');
        } else {
            $db->prepare('DELETE FROM ld_tests WHERE id = ? AND organization_id = ?')->execute([$id, $org_id]);
            flashMessage('success', 'Test gelöscht.');
        }
        redirect(APP_URL . '/dashboard/ld-tests.php');
    }
}

$tests = ldTests($db, false);
$stmt = $db->prepare('SELECT e.test_id, COUNT(*) FROM ld_ergebnisse e JOIN ld_sitzungen s ON s.id = e.sitzung_id WHERE s.organization_id = ? AND e.wert IS NOT NULL GROUP BY e.test_id');
$stmt->execute([$org_id]);
$nutzung = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

$form = ['id' => 0, 'name' => '', 'kategorie' => 'kraft', 'einheit' => '', 'richtung' => 'hoeher', 'dezimalen' => 0, 'beschreibung' => ''];
if (($bearbeiten = (int)($_GET['id'] ?? 0)) && isset($tests[$bearbeiten])) $form = $tests[$bearbeiten];
if (($_POST['action'] ?? '') === 'speichern') $form = array_merge($form, $_POST);
$v = fn($wert) => e((string)($wert ?? ''));

$page_title = 'Testkatalog';
$breadcrumb = 'Leistungsdiagnostik';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/leistungsdiagnostik.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Leistungsdiagnostik
    </a>
    <h1 class="dashboard-title">Testkatalog</h1>
    <p class="dashboard-subtitle">Standardisierte Tests mit Durchführungsprotokoll. Die Wertungsrichtung bestimmt, ob ein höherer oder niedrigerer Wert als Verbesserung zählt.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<?php foreach (LD_KATEGORIEN as $kat => $label): $liste = array_filter($tests, fn($t) => $t['kategorie'] === $kat); if (!$liste) continue; ?>
<div class="table-card" style="margin-bottom: 1.25rem;">
    <div class="table-card-header"><h2 class="table-card-title"><?= e($label) ?></h2></div>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Test</th><th>Einheit</th><th>Wertung</th><th>Messwerte</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($liste as $t): ?>
                <tr style="<?= (int)$t['aktiv'] ? '' : 'opacity: 0.55;' ?>">
                    <td><span class="text-primary"><?= e($t['name']) ?></span><?= (int)$t['aktiv'] ? '' : ' <span class="badge badge-gray">inaktiv</span>' ?>
                        <?php if ($t['beschreibung']): ?><div style="font-size: 0.75rem; color: var(--text-muted); max-width: 460px;"><?= e($t['beschreibung']) ?></div><?php endif; ?></td>
                    <td><?= e($t['einheit']) ?></td>
                    <td><?= $t['richtung'] === 'hoeher' ? '↑ höher besser' : ($t['richtung'] === 'niedriger' ? '↓ niedriger besser' : '– neutral') ?></td>
                    <td><?= $nutzung[(int)$t['id']] ?? 0 ?></td>
                    <td style="white-space: nowrap;">
                        <a href="<?= APP_URL ?>/dashboard/ld-tests.php?id=<?= $t['id'] ?>#formular" class="btn btn-ghost-light btn-sm">Bearbeiten</a>
                        <form method="POST" style="display: inline;"><?= csrfField() ?><input type="hidden" name="action" value="aktiv"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="aktiv" value="<?= (int)$t['aktiv'] ? '' : '1' ?>">
                            <button type="submit" class="btn btn-ghost-light btn-sm"><?= (int)$t['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
                        <?php if (empty($nutzung[(int)$t['id']])): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Test löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">✕</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<div class="table-card" id="formular">
    <div class="table-card-header"><h2 class="table-card-title"><?= $form['id'] ? 'Test bearbeiten' : 'Neuer Test' ?></h2></div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= APP_URL ?>/dashboard/ld-tests.php#formular">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="speichern">
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Name <span class="required">*</span></label><input class="form-control <?= isset($errors['name']) ? 'error' : '' ?>" type="text" name="name" maxlength="120" value="<?= $v($form['name']) ?>" required></div>
                <div class="form-group"><label class="form-label">Kategorie</label>
                    <select class="form-control" name="kategorie"><?php foreach (LD_KATEGORIEN as $val => $label): ?><option value="<?= $val ?>" <?= $form['kategorie'] === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Einheit <span class="required">*</span></label><input class="form-control <?= isset($errors['einheit']) ? 'error' : '' ?>" type="text" name="einheit" maxlength="20" value="<?= $v($form['einheit']) ?>" placeholder="Wdh., s, cm, kg, m …" required></div>
                <div class="form-group"><label class="form-label">Wertung</label>
                    <select class="form-control" name="richtung"><?php foreach (LD_RICHTUNGEN as $val => $label): ?><option value="<?= $val ?>" <?= $form['richtung'] === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Nachkommastellen</label><select class="form-control" name="dezimalen"><?php for ($i = 0; $i <= 3; $i++): ?><option value="<?= $i ?>" <?= (int)$form['dezimalen'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
                <div class="form-group"></div>
            </div>
            <div class="form-group"><label class="form-label">Durchführung / Protokoll</label><textarea class="form-control" name="beschreibung" rows="3" placeholder="Ausgangsposition, Ausführung, Anzahl Versuche, was gewertet wird"><?= $v($form['beschreibung']) ?></textarea></div>
            <button type="submit" class="btn btn-navy btn-sm"><?= $form['id'] ? 'Änderungen speichern' : 'Test anlegen' ?></button>
            <?php if ($form['id']): ?><a href="<?= APP_URL ?>/dashboard/ld-tests.php" class="btn btn-ghost-light btn-sm">Abbrechen</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
