<?php
/**
 * Athletikclub Steiermark – Benachrichtigungen
 * Interne Hinweise (Einsätze, Terminänderungen, Aufgaben, Fristen, Abrechnungen …),
 * gelesen/ungelesen, Öffnen markiert automatisch als gelesen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireLogin();

$db = getDB();
$me = (int)getCurrentUserId();

// Öffnen: als gelesen markieren und weiterleiten (nur interne Ziele)
if (!empty($_GET['oeffnen'])) {
    $stmt = $db->prepare('SELECT * FROM benachrichtigungen WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$_GET['oeffnen'], $me]);
    if ($b = $stmt->fetch()) {
        $db->prepare('UPDATE benachrichtigungen SET gelesen_am = COALESCE(gelesen_am, ?) WHERE id = ?')->execute([date('Y-m-d H:i:s'), $b['id']]);
        if ($b['link'] && str_starts_with($b['link'], '/dashboard/')) redirect(APP_URL . $b['link']);
    }
    redirect(APP_URL . '/dashboard/benachrichtigungen.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'alle_gelesen') {
        $db->prepare('UPDATE benachrichtigungen SET gelesen_am = ? WHERE user_id = ? AND gelesen_am IS NULL')->execute([date('Y-m-d H:i:s'), $me]);
    } elseif ($action === 'gelesen' || $action === 'ungelesen') {
        $db->prepare('UPDATE benachrichtigungen SET gelesen_am = ? WHERE id = ? AND user_id = ?')->execute([$action === 'gelesen' ? date('Y-m-d H:i:s') : null, (int)($_POST['id'] ?? 0), $me]);
    } elseif ($action === 'aufraeumen') {
        $db->prepare('DELETE FROM benachrichtigungen WHERE user_id = ? AND gelesen_am IS NOT NULL AND created_at < ?')->execute([$me, date('Y-m-d', strtotime('-30 days'))]);
    }
    redirect(APP_URL . '/dashboard/benachrichtigungen.php' . (!empty($_GET['nur']) ? '?nur=ungelesen' : ''));
}

$nur_ungelesen = ($_GET['nur'] ?? '') === 'ungelesen';
try {
    $stmt = $db->prepare('SELECT * FROM benachrichtigungen WHERE user_id = ?' . ($nur_ungelesen ? ' AND gelesen_am IS NULL' : '') . ' ORDER BY created_at DESC, id DESC LIMIT 200');
    $stmt->execute([$me]);
    $liste = $stmt->fetchAll();
} catch (Exception $e) {
    $liste = [];
}
$typ_icons = ['einsatz' => '📅', 'termin' => '🕒', 'aufgabe' => '✔', 'foerderung' => '💶', 'qualifikation' => '🎓', 'vertrag' => '📄', 'abrechnung' => '🧾', 'projekt' => '📁', 'kurs' => '👥', 'einheit' => '⏱'];

$page_title = 'Benachrichtigungen';
$breadcrumb = 'Benachrichtigungen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.bn-liste { display: flex; flex-direction: column; }
.bn-eintrag { display: grid; grid-template-columns: 2.2rem 1fr auto; gap: 0.75rem; align-items: start; padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--border-light); }
.bn-eintrag:last-child { border-bottom: none; }
.bn-eintrag.neu { background: color-mix(in srgb, var(--gold-accent) 7%, transparent); }
.bn-eintrag.neu .bn-titel::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--gold-accent); margin-right: 0.4rem; vertical-align: middle; }
.bn-icon { width: 2.2rem; height: 2.2rem; border-radius: 50%; background: var(--bg-muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; }
.bn-titel { font-weight: 600; font-size: 0.92rem; color: var(--text-primary); }
.bn-text { font-size: 0.82rem; color: var(--text-secondary); margin-top: 0.1rem; }
.bn-zeit { font-size: 0.72rem; color: var(--text-muted); white-space: nowrap; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Benachrichtigungen</h1>
        <p class="dashboard-subtitle">Einsätze, Terminänderungen, Aufgaben, Fristen und Abrechnungen.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="?<?= $nur_ungelesen ? '' : 'nur=ungelesen' ?>" class="btn btn-ghost-light btn-sm"><?= $nur_ungelesen ? 'Alle anzeigen' : 'Nur ungelesene' ?></a>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="alle_gelesen"><button type="submit" class="btn btn-navy btn-sm">Alle als gelesen markieren</button></form>
    </div>
</div>

<div class="table-card">
    <?php if (!$liste): ?>
        <div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Benachrichtigungen</h3><p><?= $nur_ungelesen ? 'Alles gelesen.' : 'Hier erscheinen neue Einsätze, Aufgaben und Erinnerungen.' ?></p></div>
    <?php else: ?>
    <div class="bn-liste">
        <?php foreach ($liste as $b): ?>
        <div class="bn-eintrag <?= $b['gelesen_am'] ? '' : 'neu' ?>">
            <div class="bn-icon" aria-hidden="true"><?= $typ_icons[$b['typ']] ?? '•' ?></div>
            <div>
                <?php if ($b['link']): ?><a class="bn-titel" href="?oeffnen=<?= $b['id'] ?>"><?= e($b['titel']) ?></a><?php else: ?><span class="bn-titel"><?= e($b['titel']) ?></span><?php endif; ?>
                <?php if ($b['text']): ?><div class="bn-text"><?= e($b['text']) ?></div><?php endif; ?>
                <div class="bn-zeit"><?= date('d.m.Y, H:i', strtotime($b['created_at'])) ?></div>
            </div>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="id" value="<?= $b['id'] ?>">
                <button type="submit" name="action" value="<?= $b['gelesen_am'] ? 'ungelesen' : 'gelesen' ?>" class="btn btn-ghost-light btn-sm" title="<?= $b['gelesen_am'] ? 'Als ungelesen markieren' : 'Als gelesen markieren' ?>"><?= $b['gelesen_am'] ? '○' : '✓' ?></button></form>
        </div>
        <?php endforeach; ?>
    </div>
    <form method="POST" style="padding: 0.75rem 1.25rem; border-top: 1px solid var(--border-light);"><?= csrfField() ?><input type="hidden" name="action" value="aufraeumen"><button type="submit" class="btn btn-ghost-light btn-sm">Gelesene älter als 30 Tage entfernen</button></form>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
