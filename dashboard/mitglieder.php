<?php
/**
 * Athletikclub Steiermark – Mitgliederverwaltung (Dashboard, Trainer/Admin)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireTrainer();

$db = getDB();

// ----------------------------------------------------------------
// Mitgliedsstatus / Notizen aktualisieren
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    requireCsrf();

    $mitglied_user_id = (int)($_POST['user_id'] ?? 0);
    $status = $_POST['mitgliedsstatus'] ?? '';
    $notizen = trim($_POST['notizen'] ?? '');

    if ($mitglied_user_id && in_array($status, ['aktiv', 'inaktiv', 'ausstehend'], true)) {
        $db->prepare(
            'INSERT INTO mitglieder_profile (user_id, mitgliedsstatus, notizen, mitglied_seit)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE mitgliedsstatus = VALUES(mitgliedsstatus), notizen = VALUES(notizen)'
        )->execute([$mitglied_user_id, $status, $notizen ?: null]);
        logActivity('mitglied_aktualisiert', "User-ID: {$mitglied_user_id}");
        flashMessage('success', 'Mitgliedsdaten aktualisiert.');
    }
    redirect(APP_URL . '/dashboard/mitglieder.php');
}

// ----------------------------------------------------------------
// Filter & Liste
// ----------------------------------------------------------------
$filter_suche  = trim($_GET['suche'] ?? '');
$filter_status = $_GET['status'] ?? '';

$where  = "u.rolle = 'mitglied'";
$params = [];

if ($filter_suche) {
    $where .= ' AND (u.vorname LIKE ? OR u.nachname LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $filter_suche . '%';
    $params[] = '%' . $filter_suche . '%';
    $params[] = '%' . $filter_suche . '%';
}
if (in_array($filter_status, ['aktiv', 'inaktiv', 'ausstehend'], true)) {
    $where .= ' AND mp.mitgliedsstatus = ?';
    $params[] = $filter_status;
}

$stmt = $db->prepare(
    "SELECT u.id, u.vorname, u.nachname, u.email, u.aktiv AS konto_aktiv, u.created_at,
            mp.telefon, mp.ort, mp.sportarten, mp.mitgliedsstatus, mp.mitglied_seit, mp.notizen,
            (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.user_id = u.id AND ka.status = 'angemeldet') AS aktive_kurse
     FROM users u
     LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
     WHERE {$where}
     ORDER BY u.vorname ASC"
);
$stmt->execute($params);
$mitglieder = $stmt->fetchAll();

$page_title = 'Mitglieder';
$breadcrumb = 'Mitglieder';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$status_labels = [
    'aktiv'      => ['label' => 'Aktiv',      'class' => 'badge-success'],
    'inaktiv'    => ['label' => 'Inaktiv',    'class' => 'badge-danger'],
    'ausstehend' => ['label' => 'Ausstehend', 'class' => 'badge-info'],
];
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Mitglieder</h1>
    <p class="dashboard-subtitle">Alle Mitglieder des Athletikclub Steiermark (<?= count($mitglieder) ?>)</p>
</div>

<!-- Filter -->
<div style="background: white; border-radius: 1rem; padding: 1rem 1.25rem; border: 1px solid var(--border-light); margin-bottom: 1.5rem;">
    <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
            <label class="form-label">Suche</label>
            <input class="form-control" type="text" name="suche" value="<?= e($filter_suche) ?>" placeholder="Name oder E-Mail…">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="">Alle</option>
                <?php foreach ($status_labels as $val => $info): ?>
                    <option value="<?= $val ?>" <?= $filter_status === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-navy btn-sm" style="margin-bottom: 0;">Filtern</button>
        <?php if ($filter_suche || $filter_status): ?>
            <a href="<?= APP_URL ?>/dashboard/mitglieder.php" class="btn btn-ghost-light btn-sm" style="margin-bottom: 0;">Zurücksetzen</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($mitglieder)): ?>
    <div class="table-card">
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </div>
            <h3>Keine Mitglieder gefunden</h3>
        </div>
    </div>
<?php else: ?>
    <div class="table-card">
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Kontakt</th>
                        <th>Sportarten</th>
                        <th>Kurse</th>
                        <th>Status</th>
                        <?php if (isAdmin()): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mitglieder as $m):
                        $status = $m['mitgliedsstatus'] ?? 'ausstehend';
                        $sl = $status_labels[$status] ?? ['label' => $status, 'class' => 'badge-gray'];
                    ?>
                    <tr>
                        <td>
                            <span class="text-primary"><?= e($m['vorname'] . ' ' . $m['nachname']) ?></span>
                            <?php if (!$m['konto_aktiv']): ?><br><span class="badge badge-danger" style="margin-top: 4px;">Konto deaktiviert</span><?php endif; ?>
                        </td>
                        <td>
                            <?= e($m['email']) ?>
                            <?php if ($m['telefon']): ?><br><span style="color: var(--text-muted); font-size: 0.8rem;"><?= e($m['telefon']) ?></span><?php endif; ?>
                        </td>
                        <td><?= $m['sportarten'] ? e($m['sportarten']) : '–' ?></td>
                        <td><?= (int)$m['aktive_kurse'] ?></td>
                        <td><span class="badge <?= $sl['class'] ?>"><?= e($sl['label']) ?></span></td>
                        <?php if (isAdmin()): ?>
                        <td>
                            <button type="button" class="btn btn-ghost-light btn-sm" onclick="document.getElementById('edit-<?= $m['id'] ?>').classList.toggle('open-row')">Bearbeiten</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php if (isAdmin()): ?>
                    <tr id="edit-<?= $m['id'] ?>" class="edit-row" style="display: none;">
                        <td colspan="6" style="background: var(--bg-muted);">
                            <form method="POST" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; padding: 1rem;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label">Status</label>
                                    <select class="form-control" name="mitgliedsstatus">
                                        <?php foreach ($status_labels as $val => $info): ?>
                                            <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0; flex: 1; min-width: 220px;">
                                    <label class="form-label">Interne Notizen</label>
                                    <input class="form-control" type="text" name="notizen" value="<?= e($m['notizen'] ?? '') ?>">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<style>
.edit-row.open-row { display: table-row !important; }
</style>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
