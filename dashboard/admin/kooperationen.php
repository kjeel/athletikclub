<?php
/**
 * Athletikclub Steiermark – Admin: Gemeinde-Kooperationen (Bewegungsland Steiermark)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db = getDB();

$status_map = [
    'entwurf'                 => ['label' => 'Entwurf',                'class' => 'badge-gray'],
    'vereinbarung_erstellt'   => ['label' => 'Vereinbarung erstellt',  'class' => 'badge-info'],
    'unterschrift_ausstehend' => ['label' => 'Unterschrift ausstehend','class' => 'badge-warning'],
    'aktiv'                   => ['label' => 'Aktiv',                  'class' => 'badge-success'],
    'beendet'                 => ['label' => 'Beendet',                'class' => 'badge-navy'],
];

$stmt = $db->prepare(
    "SELECT k.*,
        (SELECT COUNT(*) FROM kooperations_perioden p WHERE p.kooperation_id = k.id) AS anzahl_perioden
     FROM kooperationen k
     WHERE k.organization_id = ?
     ORDER BY FIELD(k.status, 'unterschrift_ausstehend','entwurf','vereinbarung_erstellt','aktiv','beendet'), k.gemeinde_name ASC"
);
$stmt->execute([currentOrgId()]);
$kooperationen = $stmt->fetchAll();

$anzahl_aktiv = count(array_filter($kooperationen, fn($k) => $k['status'] === 'aktiv'));
$anzahl_offen = count(array_filter($kooperationen, fn($k) => in_array($k['status'], ['entwurf', 'vereinbarung_erstellt', 'unterschrift_ausstehend'], true)));

$page_title = 'Gemeinde-Kooperationen';
$breadcrumb = 'Gemeinde-Kooperationen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Gemeinde-Kooperationen</h1>
        <p class="dashboard-subtitle">Bewegungsland Steiermark – Kooperationsvereinbarungen, Rückmeldeblätter und Abrechnungen je Gemeinde.</p>
    </div>
    <a href="<?= APP_URL ?>/dashboard/admin/kooperation-erstellen.php" class="btn btn-primary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Neue Kooperation
    </a>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #22C55E;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg></div>
        <div class="kpi-value"><?= $anzahl_aktiv ?></div>
        <div class="kpi-label">Aktive Kooperationen</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #F59E0B;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="kpi-value"><?= $anzahl_offen ?></div>
        <div class="kpi-label">Im Aufbau / offen</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <div class="kpi-value"><?= count($kooperationen) ?></div>
        <div class="kpi-label">Gemeinden gesamt</div>
    </div>
</div>

<div class="table-card">
    <div class="table-card-header">
        <h2 class="table-card-title">Kooperationen (<?= count($kooperationen) ?>)</h2>
    </div>
    <?php if (empty($kooperationen)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <h3>Noch keine Gemeinde-Kooperation angelegt</h3>
            <p>Lege die erste Gemeinde an, um Vereinbarung, Rückmeldeblatt und Abrechnung zu verwalten.</p>
            <a href="<?= APP_URL ?>/dashboard/admin/kooperation-erstellen.php" class="btn btn-primary btn-sm">Neue Kooperation anlegen</a>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Gemeinde</th>
                        <th>Dachverband</th>
                        <th>Kooperationsbeginn</th>
                        <th>Perioden</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kooperationen as $k): $s = $status_map[$k['status']] ?? ['label' => $k['status'], 'class' => 'badge-gray']; ?>
                    <tr>
                        <td class="text-primary"><?= e($k['gemeinde_name']) ?></td>
                        <td><?= e($k['dachverband']) ?></td>
                        <td><?= $k['kooperationsbeginn'] ? date('m/Y', strtotime($k['kooperationsbeginn'])) : '–' ?></td>
                        <td><?= (int)$k['anzahl_perioden'] ?></td>
                        <td><span class="badge <?= $s['class'] ?>"><?= e($s['label']) ?></span></td>
                        <td><a href="<?= APP_URL ?>/dashboard/admin/kooperation-detail.php?id=<?= $k['id'] ?>" class="btn btn-ghost-light btn-sm">Öffnen</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
