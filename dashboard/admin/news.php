<?php
/**
 * Athletikclub Steiermark – Admin: News verwalten
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db     = getDB();
$user   = getCurrentUser();
$errors = [];

// ----------------------------------------------------------------
// News anlegen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anlegen') {
    requireCsrf();

    $titel      = trim($_POST['titel'] ?? '');
    $inhalt     = trim($_POST['inhalt'] ?? '');
    $zielgruppe = $_POST['zielgruppe'] ?? 'trainer';

    if (mb_strlen($titel) < 3) $errors['titel'] = 'Titel muss mindestens 3 Zeichen haben.';
    if (mb_strlen($inhalt) < 3) $errors['inhalt'] = 'Bitte einen Inhalt angeben.';
    if (!in_array($zielgruppe, ['trainer', 'alle'], true)) $zielgruppe = 'trainer';

    if (empty($errors)) {
        $db->prepare('INSERT INTO news (titel, inhalt, zielgruppe, erstellt_von) VALUES (?, ?, ?, ?)')
           ->execute([$titel, $inhalt, $zielgruppe, $user['id']]);
        logActivity('news_erstellt', "Titel: {$titel}");
        flashMessage('success', 'News veröffentlicht – erscheint beim nächsten Login als Popup.');
        redirect(APP_URL . '/dashboard/admin/news.php');
    }
}

// News löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'loeschen') {
    requireCsrf();
    $news_id = (int)($_POST['news_id'] ?? 0);
    $db->prepare('DELETE FROM news WHERE id = ?')->execute([$news_id]);
    logActivity('news_geloescht', "News-ID: {$news_id}");
    redirect(APP_URL . '/dashboard/admin/news.php');
}

// ----------------------------------------------------------------
// Liste laden
// ----------------------------------------------------------------
$news_liste = $db->query(
    "SELECT n.*, u.vorname, u.nachname,
            (SELECT COUNT(*) FROM news_gelesen ng WHERE ng.news_id = n.id) AS gelesen_anzahl
     FROM news n LEFT JOIN users u ON n.erstellt_von = u.id
     ORDER BY n.created_at DESC"
)->fetchAll();

$page_title = 'News';
$breadcrumb = 'News';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">News</h1>
    <p class="dashboard-subtitle">Erscheint als Popup beim nächsten Dashboard-Aufruf der Zielgruppe</p>
</div>

<div class="form-card" style="margin-bottom: 2rem; max-width: 720px;">
    <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.25rem;">Neue News</h2>
    <form method="POST" action="" data-validate novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="anlegen">

        <div class="form-group">
            <label class="form-label" for="titel">Titel <span class="required">*</span></label>
            <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" id="titel" name="titel" value="<?= e($_POST['titel'] ?? '') ?>" required placeholder="z.B. Neue Trainingszeiten ab Oktober">
            <?php if (isset($errors['titel'])): ?><span class="form-error"><?= e($errors['titel']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="inhalt">Inhalt <span class="required">*</span></label>
            <textarea class="form-control <?= isset($errors['inhalt']) ? 'error' : '' ?>" id="inhalt" name="inhalt" rows="4" required><?= e($_POST['inhalt'] ?? '') ?></textarea>
            <?php if (isset($errors['inhalt'])): ?><span class="form-error"><?= e($errors['inhalt']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="zielgruppe">Zielgruppe</label>
            <select class="form-control" id="zielgruppe" name="zielgruppe">
                <option value="trainer" <?= (($_POST['zielgruppe'] ?? 'trainer') === 'trainer') ? 'selected' : '' ?>>Nur Trainer*innen</option>
                <option value="alle" <?= (($_POST['zielgruppe'] ?? '') === 'alle') ? 'selected' : '' ?>>Alle (Trainer + Mitglieder)</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Veröffentlichen</button>
    </form>
</div>

<div class="table-card">
    <div class="table-card-header">
        <h2 class="table-card-title">Alle News (<?= count($news_liste) ?>)</h2>
    </div>
    <?php if (empty($news_liste)): ?>
        <div class="empty-state"><h3>Noch keine News</h3></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Zielgruppe</th>
                        <th>Gelesen von</th>
                        <th>Erstellt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($news_liste as $n): ?>
                    <tr>
                        <td class="text-primary"><?= e($n['titel']) ?></td>
                        <td><span class="badge badge-gray"><?= $n['zielgruppe'] === 'alle' ? 'Alle' : 'Trainer' ?></span></td>
                        <td><?= (int)$n['gelesen_anzahl'] ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('News wirklich löschen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="loeschen">
                                <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                                <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Löschen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
