<?php
/**
 * Athletikclub Steiermark – Kursdetail (Dashboard)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireLogin();

$db      = getDB();
$user    = getCurrentUser();
$kurs_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    "SELECT k.*, u.vorname AS trainer_vorname, u.nachname AS trainer_nachname
     FROM kurse k LEFT JOIN users u ON k.trainer_id = u.id
     WHERE k.id = ? LIMIT 1"
);
$stmt->execute([$kurs_id]);
$kurs = $stmt->fetch();

if (!$kurs) {
    flashMessage('error', 'Kurs nicht gefunden.');
    redirect(APP_URL . '/dashboard/kurse.php');
}

$ist_eigentuemer = isAdmin() || (isTrainer() && (int)$kurs['trainer_id'] === (int)$user['id']);

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'anmelden') {
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = ? AND status = 'angemeldet'");
        $count_stmt->execute([$kurs_id]);
        $belegt = (int)$count_stmt->fetchColumn();
        $status = ($kurs['max_teilnehmer'] && $belegt >= $kurs['max_teilnehmer']) ? 'warteliste' : 'angemeldet';

        $db->prepare(
            'INSERT INTO kurs_anmeldungen (kurs_id, user_id, status) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)'
        )->execute([$kurs_id, $user['id'], $status]);
        logActivity('kurs_anmeldung', "Kurs-ID: {$kurs_id}");
        flashMessage('success', $status === 'warteliste' ? 'Du stehst auf der Warteliste.' : 'Anmeldung erfolgreich!');
        redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id);
    }

    if ($action === 'abmelden') {
        $db->prepare("UPDATE kurs_anmeldungen SET status = 'storniert' WHERE kurs_id = ? AND user_id = ?")
           ->execute([$kurs_id, $user['id']]);
        logActivity('kurs_abmeldung', "Kurs-ID: {$kurs_id}");
        flashMessage('success', 'Du wurdest abgemeldet.');
        redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id);
    }

    // Ab hier nur Trainer (Eigentümer) / Admin
    if (!$ist_eigentuemer) {
        flashMessage('error', 'Keine Berechtigung für diese Aktion.');
        redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id);
    }

    if ($action === 'status_aendern') {
        $neuer_status = $_POST['status'] ?? '';
        if (in_array($neuer_status, ['geplant', 'aktiv', 'abgesagt', 'abgeschlossen'], true)) {
            $db->prepare('UPDATE kurse SET status = ? WHERE id = ?')->execute([$neuer_status, $kurs_id]);
            logActivity('kurs_status_geaendert', "Kurs-ID: {$kurs_id} -> {$neuer_status}");
            flashMessage('success', 'Status aktualisiert.');
        }
        redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id);
    }

    if ($action === 'teilnehmer_status') {
        $anmeldung_id  = (int)($_POST['anmeldung_id'] ?? 0);
        $neuer_status  = $_POST['teilnehmer_neuer_status'] ?? '';
        if ($anmeldung_id && in_array($neuer_status, ['angemeldet', 'warteliste', 'storniert', 'teilgenommen'], true)) {
            $db->prepare('UPDATE kurs_anmeldungen SET status = ? WHERE id = ? AND kurs_id = ?')
               ->execute([$neuer_status, $anmeldung_id, $kurs_id]);
            logActivity('teilnehmer_status_geaendert', "Anmeldung-ID: {$anmeldung_id} -> {$neuer_status}");
        }
        redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id);
    }

    if ($action === 'bezahlt_umschalten') {
        $anmeldung_id = (int)($_POST['anmeldung_id'] ?? 0);
        $db->prepare(
            "UPDATE kurs_anmeldungen
             SET bezahlt = NOT bezahlt, bezahlt_am = IF(bezahlt = 1, NOW(), NULL)
             WHERE id = ? AND kurs_id = ?"
        )->execute([$anmeldung_id, $kurs_id]);
        logActivity('teilnehmer_bezahlt_umgeschaltet', "Anmeldung-ID: {$anmeldung_id}");
        redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id);
    }
}

// ----------------------------------------------------------------
// Daten für Anzeige laden
// ----------------------------------------------------------------
$anmeldungen = [];
if ($ist_eigentuemer) {
    $stmt = $db->prepare(
        "SELECT ka.*, u.vorname, u.nachname, u.email
         FROM kurs_anmeldungen ka JOIN users u ON ka.user_id = u.id
         WHERE ka.kurs_id = ? ORDER BY ka.angemeldet_am ASC"
    );
    $stmt->execute([$kurs_id]);
    $anmeldungen = $stmt->fetchAll();
}

$stmt = $db->prepare("SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = ? AND status = 'angemeldet'");
$stmt->execute([$kurs_id]);
$belegt = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT status FROM kurs_anmeldungen WHERE kurs_id = ? AND user_id = ?");
$stmt->execute([$kurs_id, $user['id']]);
$meine_anmeldung = $stmt->fetchColumn();

$page_title = $kurs['titel'];
$breadcrumb = 'Kurse';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$status_map = [
    'geplant'       => ['label' => 'Geplant',       'class' => 'badge-info'],
    'aktiv'         => ['label' => 'Aktiv',         'class' => 'badge-success'],
    'abgesagt'      => ['label' => 'Abgesagt',      'class' => 'badge-danger'],
    'abgeschlossen' => ['label' => 'Abgeschlossen', 'class' => 'badge-gray'],
];
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/kurse.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Zurück zu Kurse
        </a>
        <h1 class="dashboard-title"><?= e($kurs['titel']) ?></h1>
        <p class="dashboard-subtitle">
            <?= date('d.m.Y H:i', strtotime($kurs['start_datum'])) ?> – <?= date('d.m.Y H:i', strtotime($kurs['end_datum'])) ?>
            <?php if ($kurs['ort']): ?> · <?= e($kurs['ort']) ?><?php endif; ?>
        </p>
    </div>
    <?php $s = $status_map[$kurs['status']] ?? ['label' => $kurs['status'], 'class' => 'badge-gray']; ?>
    <span class="badge <?= $s['class'] ?>" style="font-size: 0.8rem;"><?= e($s['label']) ?></span>
</div>

<div style="display: grid; grid-template-columns: <?= $ist_eigentuemer ? '1fr 1fr' : '1fr' ?>; gap: 1.5rem; align-items: start;">

    <!-- Kursinfo -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Details</h2>
        </div>
        <div style="padding: 1.25rem;">
            <?php if ($kurs['beschreibung']): ?>
                <p style="line-height: 1.7; margin-bottom: 1.5rem;"><?= nl2br(e($kurs['beschreibung'])) ?></p>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.875rem;">
                <?php if ($kurs['sportart']): ?>
                <div><strong>Sportart</strong><br><span class="badge badge-gold" style="margin-top: 4px;"><?= e($kurs['sportart']) ?></span></div>
                <?php endif; ?>
                <div><strong>Trainer*in</strong><br><?= $kurs['trainer_vorname'] ? e($kurs['trainer_vorname'] . ' ' . $kurs['trainer_nachname']) : '–' ?></div>
                <div><strong>Teilnehmer</strong><br><?= $belegt ?><?= $kurs['max_teilnehmer'] ? ' / ' . (int)$kurs['max_teilnehmer'] : ' (unlimitiert)' ?></div>
                <div><strong>Preis</strong><br><?= $kurs['preis'] > 0 ? number_format((float)$kurs['preis'], 2, ',', '.') . ' €' : 'Kostenlos' ?></div>
            </div>

            <?php if (!isTrainer()): ?>
                <div style="margin-top: 1.5rem;">
                    <?php if ($meine_anmeldung === 'angemeldet'): ?>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="abmelden">
                            <button type="submit" class="btn btn-ghost-light w-full">Vom Kurs abmelden</button>
                        </form>
                    <?php elseif ($meine_anmeldung === 'warteliste'): ?>
                        <div class="flash-message flash-info" style="border-radius: 0.5rem;"><span>Du stehst auf der Warteliste.</span></div>
                    <?php elseif ($kurs['status'] !== 'abgesagt' && $kurs['status'] !== 'abgeschlossen'): ?>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="anmelden">
                            <button type="submit" class="btn btn-primary w-full btn-lg">Jetzt anmelden</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ist_eigentuemer): ?>
    <!-- Verwaltung (Trainer/Admin) -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Verwaltung</h2>
        </div>
        <div style="padding: 1.25rem;">
            <label class="form-label">Kurs-Status ändern</label>
            <form method="POST" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status_aendern">
                <select name="status" class="form-control" style="flex: 1; min-width: 160px;">
                    <?php foreach ($status_map as $val => $info): ?>
                        <option value="<?= $val ?>" <?= $kurs['status'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($ist_eigentuemer): ?>
<!-- Teilnehmerliste -->
<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Teilnehmer (<?= count($anmeldungen) ?>)</h2>
    </div>
    <?php if (empty($anmeldungen)): ?>
        <div class="empty-state">
            <h3>Noch keine Anmeldungen</h3>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Angemeldet am</th>
                        <th>Status</th>
                        <th>Bezahlt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $teilnehmer_status_labels = [
                        'angemeldet'   => ['label' => 'Angemeldet',  'class' => 'badge-success'],
                        'warteliste'   => ['label' => 'Warteliste',  'class' => 'badge-info'],
                        'storniert'    => ['label' => 'Storniert',   'class' => 'badge-danger'],
                        'teilgenommen' => ['label' => 'Teilgenommen','class' => 'badge-gray'],
                    ];
                    foreach ($anmeldungen as $a):
                        $ts = $teilnehmer_status_labels[$a['status']] ?? ['label' => $a['status'], 'class' => 'badge-gray'];
                    ?>
                    <tr>
                        <td class="text-primary"><?= e($a['vorname'] . ' ' . $a['nachname']) ?></td>
                        <td><?= e($a['email']) ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($a['angemeldet_am'])) ?></td>
                        <td><span class="badge <?= $ts['class'] ?>"><?= e($ts['label']) ?></span></td>
                        <td>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="bezahlt_umschalten">
                                <input type="hidden" name="anmeldung_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="badge <?= $a['bezahlt'] ? 'badge-success' : 'badge-gray' ?>" style="border: none; cursor: pointer;">
                                    <?= $a['bezahlt'] ? '✓ Bezahlt' : 'Offen' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" style="display: flex; gap: 0.4rem;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="teilnehmer_status">
                                <input type="hidden" name="anmeldung_id" value="<?= $a['id'] ?>">
                                <select name="teilnehmer_neuer_status" class="form-control" style="font-size: 0.8rem; padding: 0.35rem 0.5rem;" onchange="this.form.submit()">
                                    <?php foreach ($teilnehmer_status_labels as $val => $info): ?>
                                        <option value="<?= $val ?>" <?= $a['status'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
