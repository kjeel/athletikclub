<?php
/**
 * Athletikclub Steiermark – Admin: Seiteninhalte & Kontaktanfragen
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db = getDB();

$editierbare_seiten = [
    'vision'   => 'Vision',
    'mission'  => 'Mission',
    'leitbild' => 'Leitbild',
];

// ----------------------------------------------------------------
// Kontaktanfrage: gelesen markieren / löschen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kontakt_gelesen') {
    requireCsrf();
    $anfrage_id = (int)($_POST['anfrage_id'] ?? 0);
    $db->prepare('UPDATE kontakt_anfragen SET gelesen = 1 WHERE id = ?')->execute([$anfrage_id]);
    redirect(APP_URL . '/dashboard/admin/inhalte.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kontakt_loeschen') {
    requireCsrf();
    $anfrage_id = (int)($_POST['anfrage_id'] ?? 0);
    $db->prepare('DELETE FROM kontakt_anfragen WHERE id = ?')->execute([$anfrage_id]);
    logActivity('kontaktanfrage_geloescht', "ID: {$anfrage_id}");
    redirect(APP_URL . '/dashboard/admin/inhalte.php');
}

// ----------------------------------------------------------------
// Seiteninhalt speichern
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seite_speichern') {
    requireCsrf();
    $slug = $_POST['slug'] ?? '';

    if (isset($editierbare_seiten[$slug])) {
        $titel      = trim($_POST['titel'] ?? $editierbare_seiten[$slug]);
        $untertitel = trim($_POST['untertitel'] ?? '');
        $inhalt     = trim($_POST['inhalt'] ?? '');

        $db->prepare(
            'INSERT INTO seiten_inhalte (seiten_slug, titel, untertitel, inhalt, aktualisiert_von)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE titel = VALUES(titel), untertitel = VALUES(untertitel), inhalt = VALUES(inhalt), aktualisiert_von = VALUES(aktualisiert_von)'
        )->execute([$slug, $titel, $untertitel ?: null, $inhalt, getCurrentUserId()]);

        logActivity('seiteninhalt_aktualisiert', "Slug: {$slug}");
        flashMessage('success', 'Seiteninhalt gespeichert.');
    }
    redirect(APP_URL . '/dashboard/admin/inhalte.php');
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$kontakt_anfragen = $db->query('SELECT * FROM kontakt_anfragen ORDER BY created_at DESC LIMIT 50')->fetchAll();

$seiten_daten = [];
$stmt = $db->query('SELECT * FROM seiten_inhalte');
foreach ($stmt->fetchAll() as $row) {
    $seiten_daten[$row['seiten_slug']] = $row;
}

$page_title = 'Seiteninhalte';
$breadcrumb = 'Seiteninhalte';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Seiteninhalte</h1>
    <p class="dashboard-subtitle">Kontaktanfragen bearbeiten und Vereinsseiten pflegen</p>
</div>

<!-- Kontaktanfragen -->
<div class="table-card" style="margin-bottom: 2rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Kontaktanfragen &amp; Bewerbungen</h2>
    </div>
    <?php if (empty($kontakt_anfragen)): ?>
        <div class="empty-state">
            <h3>Keine Anfragen</h3>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Von</th>
                        <th>Betreff</th>
                        <th>Nachricht</th>
                        <th>Datum</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kontakt_anfragen as $a): ?>
                    <tr style="<?= $a['gelesen'] ? '' : 'font-weight: 600;' ?>">
                        <td>
                            <span class="text-primary"><?= e($a['name']) ?></span><br>
                            <a href="mailto:<?= e($a['email']) ?>" style="font-size: 0.8rem; color: var(--text-muted);"><?= e($a['email']) ?></a>
                        </td>
                        <td><?= $a['betreff'] ? e($a['betreff']) : '–' ?></td>
                        <td style="max-width: 320px;">
                            <span style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: 0.85rem;">
                                <?= e($a['nachricht']) ?>
                            </span>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
                        <td style="white-space: nowrap;">
                            <?php if (!$a['gelesen']): ?>
                            <form method="POST" style="display: inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="kontakt_gelesen">
                                <input type="hidden" name="anfrage_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-ghost-light btn-sm">Gelesen</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Anfrage wirklich löschen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="kontakt_loeschen">
                                <input type="hidden" name="anfrage_id" value="<?= $a['id'] ?>">
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

<!-- Seiteninhalte-Editor -->
<div class="table-card">
    <div class="table-card-header">
        <h2 class="table-card-title">Vereinsseiten bearbeiten</h2>
    </div>
    <div style="padding: 1.25rem; display: flex; flex-direction: column; gap: 1.5rem;">
        <?php foreach ($editierbare_seiten as $slug => $label):
            $daten = $seiten_daten[$slug] ?? null;
        ?>
        <details style="border: 1px solid var(--border-light); border-radius: 0.75rem;" <?= $slug === 'vision' ? 'open' : '' ?>>
            <summary style="padding: 1rem 1.25rem; cursor: pointer; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.9rem;">
                <?= e($label) ?>
                <?= $daten ? '' : '<span class="badge badge-info" style="margin-left: 0.5rem;">Noch kein Inhalt gepflegt</span>' ?>
            </summary>
            <div style="padding: 0 1.25rem 1.25rem;">
                <form method="POST" action="" data-validate novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="seite_speichern">
                    <input type="hidden" name="slug" value="<?= e($slug) ?>">

                    <div class="form-group">
                        <label class="form-label">Untertitel</label>
                        <input class="form-control" type="text" name="untertitel" value="<?= e($daten['untertitel'] ?? '') ?>" placeholder="Kurzer Teaser-Satz">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Text</label>
                        <textarea class="form-control" name="inhalt" rows="8" placeholder="Der Text, der auf der öffentlichen Seite angezeigt wird…"><?= e($daten['inhalt'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
                    <?php if ($daten): ?>
                        <span style="font-size: 0.75rem; color: var(--text-muted); margin-left: 0.75rem;">
                            Zuletzt geändert: <?= date('d.m.Y H:i', strtotime($daten['aktualisiert_am'])) ?>
                        </span>
                    <?php endif; ?>
                </form>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
