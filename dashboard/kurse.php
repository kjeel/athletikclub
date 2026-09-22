<?php
/**
 * Athletikclub Steiermark – Kursübersicht (Dashboard)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireLogin();

$db   = getDB();
$user = getCurrentUser();

// ----------------------------------------------------------------
// Schnell-Aktionen: An-/Abmelden direkt aus der Liste
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $kurs_id = (int)($_POST['kurs_id'] ?? 0);
    $action  = $_POST['action'] ?? '';

    if ($kurs_id && $action === 'anmelden') {
        $stmt = $db->prepare('SELECT max_teilnehmer FROM kurse WHERE id = ? AND organization_id = ?');
        $stmt->execute([$kurs_id, currentOrgId()]);
        $kurs = $stmt->fetch();

        if ($kurs) {
            $count_stmt = $db->prepare("SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = ? AND status = 'angemeldet'");
            $count_stmt->execute([$kurs_id]);
            $belegt = (int)$count_stmt->fetchColumn();

            $status = ($kurs['max_teilnehmer'] && $belegt >= $kurs['max_teilnehmer']) ? 'warteliste' : 'angemeldet';

            $db->prepare(
                'INSERT INTO kurs_anmeldungen (organization_id, kurs_id, user_id, status) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status)'
            )->execute([currentOrgId(), $kurs_id, $user['id'], $status]);

            logActivity('kurs_anmeldung', "Kurs-ID: {$kurs_id}");
            flashMessage('success', $status === 'warteliste' ? 'Du stehst auf der Warteliste.' : 'Anmeldung erfolgreich!');
        }
    } elseif ($kurs_id && $action === 'abmelden') {
        $db->prepare("UPDATE kurs_anmeldungen SET status = 'storniert' WHERE kurs_id = ? AND user_id = ?")
           ->execute([$kurs_id, $user['id']]);
        logActivity('kurs_abmeldung', "Kurs-ID: {$kurs_id}");
        flashMessage('success', 'Du wurdest abgemeldet.');
    }

    redirect(APP_URL . '/dashboard/kurse.php');
}

// ----------------------------------------------------------------
// Filter
// ----------------------------------------------------------------
$filter_sportart = trim($_GET['sportart'] ?? '');
$filter_suche    = trim($_GET['suche'] ?? '');
$nur_meine        = isset($_GET['meine']) && isTrainer();

$where  = "k.status != 'abgesagt' AND k.organization_id = ?";
$params = [currentOrgId()];

if (!isAdmin()) {
    // Trainer sehen alle aktiven Kurse (zur Übersicht), aber Filter "meine" grenzt ein
    if ($nur_meine) {
        $where .= ' AND k.trainer_id = ?';
        $params[] = $user['id'];
    }
}
if ($filter_sportart) {
    $where .= ' AND k.sportart = ?';
    $params[] = $filter_sportart;
}
if ($filter_suche) {
    $where .= ' AND k.titel LIKE ?';
    $params[] = '%' . $filter_suche . '%';
}

$stmt = $db->prepare(
    "SELECT k.*, u.vorname, u.nachname,
            (SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = k.id AND status = 'angemeldet') AS belegt,
            (SELECT status FROM kurs_anmeldungen WHERE kurs_id = k.id AND user_id = ?) AS meine_anmeldung
     FROM kurse k
     LEFT JOIN users u ON k.trainer_id = u.id
     WHERE {$where}
     ORDER BY k.start_datum ASC"
);
$stmt->execute(array_merge([$user['id']], $params));
$kurse = $stmt->fetchAll();

$sportarten = ['Calisthenics', 'Skateboarding', 'Tischtennis', 'Padel Tennis', 'Athletiktraining', 'Ausdauer'];

$page_title = 'Kurse';
$breadcrumb = 'Kurse';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Kurse</h1>
        <p class="dashboard-subtitle">Trainingseinheiten des Athletikclub Steiermark</p>
    </div>
    <?php if (isTrainer()): ?>
        <a href="<?= APP_URL ?>/dashboard/kurs-erstellen.php" class="btn btn-primary btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Kurs erstellen
        </a>
    <?php endif; ?>
</div>

<!-- Filter -->
<div style="background: white; border-radius: 1rem; padding: 1rem 1.25rem; border: 1px solid var(--border-light); margin-bottom: 1.5rem;">
    <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
            <label class="form-label">Suche</label>
            <input class="form-control" type="text" name="suche" value="<?= e($filter_suche) ?>" placeholder="Kurstitel…">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Sportart</label>
            <select class="form-control" name="sportart">
                <option value="">Alle</option>
                <?php foreach ($sportarten as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filter_sportart === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (isTrainer()): ?>
        <div class="form-group" style="margin: 0;">
            <label class="form-check" style="margin-bottom: 0.6rem;">
                <input type="checkbox" name="meine" value="1" <?= $nur_meine ? 'checked' : '' ?> onchange="this.form.submit()">
                <span class="form-check-label">Nur meine Kurse</span>
            </label>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-navy btn-sm" style="margin-bottom: 0;">Filtern</button>
        <?php if ($filter_suche || $filter_sportart || $nur_meine): ?>
            <a href="<?= APP_URL ?>/dashboard/kurse.php" class="btn btn-ghost-light btn-sm" style="margin-bottom: 0;">Zurücksetzen</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($kurse)): ?>
    <div class="table-card">
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <h3>Keine Kurse gefunden</h3>
            <p><?= ($filter_suche || $filter_sportart) ? 'Keine Kurse entsprechen deiner Suche.' : 'Es wurden noch keine Kurse angelegt.' ?></p>
            <?php if (isTrainer()): ?>
                <a href="<?= APP_URL ?>/dashboard/kurs-erstellen.php" class="btn btn-primary btn-sm">Kurs erstellen</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="table-card">
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kurs</th>
                        <th>Datum</th>
                        <th>Trainer</th>
                        <th>Teilnehmer</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kurse as $kurs): ?>
                    <tr data-row-href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $kurs['id'] ?>">
                        <td>
                            <span class="text-primary"><?= e($kurs['titel']) ?></span>
                            <?php if ($kurs['sportart']): ?>
                                <br><span class="badge badge-gold" style="margin-top: 4px;"><?= e($kurs['sportart']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($kurs['start_datum'])) ?></td>
                        <td><?= $kurs['vorname'] ? e($kurs['vorname'] . ' ' . $kurs['nachname']) : 'k. A.' ?></td>
                        <td><?= (int)$kurs['belegt'] ?><?= $kurs['max_teilnehmer'] ? ' / ' . (int)$kurs['max_teilnehmer'] : '' ?></td>
                        <td>
                            <?php
                            $status_map = [
                                'geplant'       => ['label' => 'Geplant',       'class' => 'badge-info'],
                                'aktiv'         => ['label' => 'Aktiv',         'class' => 'badge-success'],
                                'abgeschlossen' => ['label' => 'Abgeschlossen', 'class' => 'badge-gray'],
                            ];
                            $s = $status_map[$kurs['status']] ?? ['label' => $kurs['status'], 'class' => 'badge-gray'];
                            echo '<span class="badge ' . $s['class'] . '">' . e($s['label']) . '</span>';
                            ?>
                        </td>
                        <td onclick="event.stopPropagation()">
                            <?php if (!isTrainer()): ?>
                                <?php if ($kurs['meine_anmeldung'] === 'angemeldet'): ?>
                                    <form method="POST" style="display:inline;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="abmelden">
                                        <input type="hidden" name="kurs_id" value="<?= $kurs['id'] ?>">
                                        <button type="submit" class="btn btn-ghost-light btn-sm">Abmelden</button>
                                    </form>
                                <?php elseif ($kurs['meine_anmeldung'] === 'warteliste'): ?>
                                    <span class="badge badge-info">Warteliste</span>
                                <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="anmelden">
                                        <input type="hidden" name="kurs_id" value="<?= $kurs['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Anmelden</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $kurs['id'] ?>" class="btn btn-ghost-light btn-sm">Details</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
