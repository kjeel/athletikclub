<?php
/**
 * Athletikclub Steiermark – Dashboard Hauptseite
 * Rollenbasierte Übersicht für Mitglied / Trainer / Admin
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title = 'Übersicht';
$breadcrumb = 'Dashboard';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$db   = getDB();
$user = getCurrentUser();

// ----------------------------------------------------------------
// Daten je nach Rolle laden
// ----------------------------------------------------------------
$stats = [];

try {
    if (isAdmin()) {
        // Admin: Gesamtstatistiken
        $stats['mitglieder']   = (int)$db->query('SELECT COUNT(*) FROM users WHERE rolle = "mitglied"')->fetchColumn();
        $stats['trainer']      = (int)$db->query('SELECT COUNT(*) FROM users WHERE rolle IN ("trainer", "admin")')->fetchColumn();
        $stats['kurse']        = (int)$db->query('SELECT COUNT(*) FROM kurse WHERE status IN ("geplant","aktiv")')->fetchColumn();
        $stats['dokumente']    = (int)$db->query('SELECT COUNT(*) FROM dokumente')->fetchColumn();
        $stats['kontakt']      = (int)$db->query('SELECT COUNT(*) FROM kontakt_anfragen WHERE gelesen = 0')->fetchColumn();

        // Letzte Nutzer
        $latest_users = $db->query(
            'SELECT id, vorname, nachname, email, rolle, created_at FROM users ORDER BY created_at DESC LIMIT 5'
        )->fetchAll();

        // Nächste Kurse
        $next_kurse = $db->query(
            'SELECT k.*, u.vorname, u.nachname FROM kurse k
             LEFT JOIN users u ON k.trainer_id = u.id
             WHERE k.start_datum >= NOW() ORDER BY k.start_datum ASC LIMIT 5'
        )->fetchAll();

    } elseif (isTrainer()) {
        // Trainer: Eigene Kurse + Anmeldungen
        $stmt = $db->prepare('SELECT COUNT(*) FROM kurse WHERE trainer_id = ? AND status IN ("geplant","aktiv")');
        $stmt->execute([$user['id']]);
        $stats['meine_kurse'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM kurs_anmeldungen ka
             JOIN kurse k ON ka.kurs_id = k.id
             WHERE k.trainer_id = ? AND ka.status = "angemeldet"'
        );
        $stmt->execute([$user['id']]);
        $stats['anmeldungen'] = (int)$stmt->fetchColumn();

        $stats['mitglieder'] = (int)$db->query('SELECT COUNT(*) FROM users WHERE rolle = "mitglied" AND aktiv = 1')->fetchColumn();
        $stats['dokumente']  = (int)$db->query('SELECT COUNT(*) FROM dokumente WHERE sichtbar_fuer IN ("trainer","alle","mitglieder")')->fetchColumn();

        $stmt = $db->prepare(
            'SELECT k.*, (SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = k.id AND status = "angemeldet") AS anmeldungen_count
             FROM kurse k WHERE k.trainer_id = ? AND k.start_datum >= NOW()
             ORDER BY k.start_datum ASC LIMIT 5'
        );
        $stmt->execute([$user['id']]);
        $next_kurse = $stmt->fetchAll();

    } else {
        // Mitglied: Eigene Anmeldungen
        $stmt = $db->prepare('SELECT COUNT(*) FROM kurs_anmeldungen WHERE user_id = ? AND status = "angemeldet"');
        $stmt->execute([$user['id']]);
        $stats['meine_kurse'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM kurs_anmeldungen WHERE user_id = ? AND status = "teilgenommen"');
        $stmt->execute([$user['id']]);
        $stats['absolviert'] = (int)$stmt->fetchColumn();

        $stats['dokumente'] = (int)$db->query('SELECT COUNT(*) FROM dokumente WHERE sichtbar_fuer IN ("alle","mitglieder")')->fetchColumn();

        $stmt = $db->prepare(
            'SELECT k.*, u.vorname, u.nachname FROM kurse k
             JOIN kurs_anmeldungen ka ON ka.kurs_id = k.id
             LEFT JOIN users u ON k.trainer_id = u.id
             WHERE ka.user_id = ? AND ka.status = "angemeldet" AND k.start_datum >= NOW()
             ORDER BY k.start_datum ASC LIMIT 5'
        );
        $stmt->execute([$user['id']]);
        $next_kurse = $stmt->fetchAll();
    }
} catch (Exception $e) {
    // DB noch nicht vorhanden
    $latest_users = [];
    $next_kurse   = [];
}
?>

<!-- Dashboard Header -->
<div class="dashboard-header">
    <h1 class="dashboard-title">
        Guten <?= date('H') < 12 ? 'Morgen' : (date('H') < 18 ? 'Tag' : 'Abend') ?>,
        <?= e($user['vorname']) ?> 👋
    </h1>
    <p class="dashboard-subtitle"><?= date('l, d. F Y', strtotime('now')) ?></p>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <?php if (isAdmin()): ?>
        <div class="kpi-card" style="--kpi-color: #1F3556;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="kpi-value"><?= $stats['mitglieder'] ?? 0 ?></div>
            <div class="kpi-label">Mitglieder</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #C6A135;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg></div>
            <div class="kpi-value"><?= $stats['trainer'] ?? 0 ?></div>
            <div class="kpi-label">Trainer / Admins</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #00A896;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="kpi-value"><?= $stats['kurse'] ?? 0 ?></div>
            <div class="kpi-label">Aktive Kurse</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #7C3AED;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="kpi-value"><?= $stats['dokumente'] ?? 0 ?></div>
            <div class="kpi-label">Dokumente</div>
        </div>
        <?php if ($stats['kontakt'] > 0): ?>
        <div class="kpi-card" style="--kpi-color: #EF4444;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
            <div class="kpi-value"><?= $stats['kontakt'] ?></div>
            <div class="kpi-label">Neue Anfragen</div>
        </div>
        <?php endif; ?>

    <?php elseif (isTrainer()): ?>
        <div class="kpi-card" style="--kpi-color: #1F3556;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="kpi-value"><?= $stats['meine_kurse'] ?? 0 ?></div>
            <div class="kpi-label">Meine Kurse</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #C6A135;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="kpi-value"><?= $stats['anmeldungen'] ?? 0 ?></div>
            <div class="kpi-label">Anmeldungen</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #4EBA6F;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="kpi-value"><?= $stats['mitglieder'] ?? 0 ?></div>
            <div class="kpi-label">Gesamtmitglieder</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #7C3AED;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="kpi-value"><?= $stats['dokumente'] ?? 0 ?></div>
            <div class="kpi-label">Dokumente</div>
        </div>

    <?php else: ?>
        <div class="kpi-card" style="--kpi-color: #1F3556;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="kpi-value"><?= $stats['meine_kurse'] ?? 0 ?></div>
            <div class="kpi-label">Meine Kurse</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #C6A135;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
            <div class="kpi-value"><?= $stats['absolviert'] ?? 0 ?></div>
            <div class="kpi-label">Absolviert</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #7C3AED;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="kpi-value"><?= $stats['dokumente'] ?? 0 ?></div>
            <div class="kpi-label">Dokumente</div>
        </div>
    <?php endif; ?>
</div>

<!-- Nächste Kurse + (Admin: Letzte User) -->
<div style="display: grid; grid-template-columns: <?= isAdmin() ? '1fr 1fr' : '1fr' ?>; gap: 1.5rem;">

    <!-- Nächste Kurse -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">
                <?= isTrainer() && !isAdmin() ? 'Meine nächsten Kurse' : 'Nächste Kurse' ?>
            </h2>
            <a href="<?= APP_URL ?>/dashboard/kurse.php" class="btn btn-ghost-light btn-sm">Alle ansehen</a>
        </div>
        <?php if (empty($next_kurse)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <h3>Keine Kurse</h3>
                <p><?= isTrainer() ? 'Du hast noch keine Kurse erstellt.' : 'Du bist noch für keinen Kurs angemeldet.' ?></p>
                <a href="<?= isTrainer() ? APP_URL . '/dashboard/kurs-erstellen.php' : APP_URL . '/dashboard/kurse.php' ?>" class="btn btn-navy btn-sm">
                    <?= isTrainer() ? 'Kurs erstellen' : 'Kurse entdecken' ?>
                </a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kurs</th>
                            <th>Datum</th>
                            <?php if (isTrainer()): ?><th>Anmeldungen</th><?php endif; ?>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($next_kurse as $kurs): ?>
                        <tr>
                            <td>
                                <span class="text-primary">
                                    <a href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $kurs['id'] ?>">
                                        <?= e($kurs['titel']) ?>
                                    </a>
                                </span>
                                <?php if ($kurs['sportart']): ?>
                                    <br><span class="badge badge-gold" style="margin-top: 4px;"><?= e($kurs['sportart']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('d.m.Y H:i', strtotime($kurs['start_datum'])) ?></td>
                            <?php if (isTrainer()): ?>
                                <td><?= $kurs['anmeldungen_count'] ?? 'k. A.' ?><?= $kurs['max_teilnehmer'] ? ' / ' . $kurs['max_teilnehmer'] : '' ?></td>
                            <?php endif; ?>
                            <td>
                                <?php
                                $status_map = [
                                    'geplant'      => ['label' => 'Geplant',      'class' => 'badge-info'],
                                    'aktiv'        => ['label' => 'Aktiv',        'class' => 'badge-success'],
                                    'abgesagt'     => ['label' => 'Abgesagt',     'class' => 'badge-danger'],
                                    'abgeschlossen'=> ['label' => 'Abgeschlossen','class' => 'badge-gray'],
                                ];
                                $s = $status_map[$kurs['status']] ?? ['label' => $kurs['status'], 'class' => 'badge-gray'];
                                echo '<span class="badge ' . $s['class'] . '">' . e($s['label']) . '</span>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if (isAdmin() && !empty($latest_users)): ?>
    <!-- Neueste Nutzer (Admin) -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Neue Mitglieder</h2>
            <a href="<?= APP_URL ?>/dashboard/admin/nutzerverwaltung.php" class="btn btn-ghost-light btn-sm">Alle ansehen</a>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Rolle</th>
                        <th>Registriert</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($latest_users as $u): ?>
                    <tr>
                        <td class="text-primary"><?= e($u['vorname'] . ' ' . $u['nachname']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><span class="role-badge role-<?= e($u['rolle']) ?>"><?= ucfirst(e($u['rolle'])) ?></span></td>
                        <td><?= date('d.m.Y', strtotime($u['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Schnellzugriff Buttons -->
<div style="margin-top: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
    <a href="<?= APP_URL ?>/dashboard/kurse.php" class="btn btn-navy btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Kurse ansehen
    </a>
    <a href="<?= APP_URL ?>/dashboard/dokumente.php" class="btn btn-ghost-light btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Dokumente
    </a>
    <a href="<?= APP_URL ?>/dashboard/profil.php" class="btn btn-ghost-light btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profil bearbeiten
    </a>
    <?php if (isTrainer()): ?>
    <a href="<?= APP_URL ?>/dashboard/kurs-erstellen.php" class="btn btn-primary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Kurs erstellen
    </a>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
