<?php
/**
 * Athletikclub Steiermark – Mitglied-Detail (Fortschritt & Dokumentenarchiv)
 * Nur für Trainer/Admin.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireTrainer();

$db      = getDB();
$user    = getCurrentUser();
$mitglied_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    "SELECT u.*, mp.telefon, mp.ort, mp.sportarten, mp.mitgliedsstatus, mp.mitglied_seit, mp.notizen
     FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
     WHERE u.id = ? AND u.rolle = 'mitglied' AND u.organization_id = ? LIMIT 1"
);
$stmt->execute([$mitglied_id, currentOrgId()]);
$mitglied = $stmt->fetch();

if (!$mitglied) {
    flashMessage('error', 'Mitglied nicht gefunden.');
    redirect(APP_URL . '/dashboard/mitglieder.php');
}

if (!is_dir(PDF_PATH)) {
    mkdir(PDF_PATH, 0755, true);
}

$errors = [];

// ----------------------------------------------------------------
// Fortschrittseintrag hinzufügen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fortschritt_hinzufuegen') {
    requireCsrf();
    $eintrag = trim($_POST['eintrag'] ?? '');

    if (mb_strlen($eintrag) < 3) {
        $errors['eintrag'] = 'Bitte einen aussagekräftigen Eintrag verfassen.';
    } else {
        $db->prepare('INSERT INTO fortschritt_eintraege (organization_id, user_id, trainer_id, eintrag) VALUES (?, ?, ?, ?)')
           ->execute([currentOrgId(), $mitglied_id, $user['id'], $eintrag]);
        logActivity('fortschritt_eintrag', "Mitglied-ID: {$mitglied_id}");
        flashMessage('success', 'Eintrag gespeichert.');
        redirect(APP_URL . '/dashboard/mitglied-detail.php?id=' . $mitglied_id);
    }
}

// ----------------------------------------------------------------
// Fortschrittseintrag löschen (Admin oder Autor)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fortschritt_loeschen') {
    requireCsrf();
    $eintrag_id = (int)($_POST['eintrag_id'] ?? 0);

    $stmt = $db->prepare('SELECT trainer_id FROM fortschritt_eintraege WHERE id = ? AND user_id = ?');
    $stmt->execute([$eintrag_id, $mitglied_id]);
    $autor_id = $stmt->fetchColumn();

    if ($autor_id && (isAdmin() || (int)$autor_id === (int)$user['id'])) {
        $db->prepare('DELETE FROM fortschritt_eintraege WHERE id = ?')->execute([$eintrag_id]);
        logActivity('fortschritt_eintrag_geloescht', "Eintrag-ID: {$eintrag_id}");
    }
    redirect(APP_URL . '/dashboard/mitglied-detail.php?id=' . $mitglied_id);
}

// ----------------------------------------------------------------
// Dokument-Upload (Archiv dieses Mitglieds)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dokument_upload') {
    requireCsrf();

    $titel = trim($_POST['titel'] ?? '');
    if (empty($titel)) $errors[] = 'Titel ist Pflichtfeld.';

    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Bitte wähle eine PDF-Datei aus.';
    } elseif ($_FILES['pdf_file']['size'] > MAX_PDF_SIZE) {
        $errors[] = 'Datei zu groß (max. 10 MB).';
    } elseif (!in_array($_FILES['pdf_file']['type'], ALLOWED_PDF_TYPES, true)) {
        $errors[] = 'Nur PDF-Dateien sind erlaubt.';
    }

    if (empty($errors)) {
        $original_name = basename($_FILES['pdf_file']['name']);
        $safe_name     = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $original_name);
        $unique_name   = date('Ymd_His') . '_' . $safe_name;
        $dest          = PDF_PATH . '/' . $unique_name;

        if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $dest)) {
            $db->prepare(
                'INSERT INTO dokumente (organization_id, titel, beschreibung, datei_name, datei_pfad, datei_groesse, mime_type, kategorie, sichtbar_fuer, hochgeladen_von, mitglied_id)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, \'sonstiges\', \'mitglieder\', ?, ?)'
            )->execute([
                currentOrgId(), $titel, $original_name, 'pdfs/' . $unique_name,
                $_FILES['pdf_file']['size'], 'application/pdf',
                $user['id'], $mitglied_id,
            ]);
            logActivity('mitglied_dokument_upload', "Mitglied-ID: {$mitglied_id}, Datei: {$original_name}");
            flashMessage('success', 'Dokument hochgeladen.');
            redirect(APP_URL . '/dashboard/mitglied-detail.php?id=' . $mitglied_id);
        } else {
            $errors[] = 'Datei konnte nicht gespeichert werden.';
        }
    }
}

// Dokument löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dokument_loeschen') {
    requireCsrf();
    $dok_id = (int)($_POST['dok_id'] ?? 0);

    $stmt = $db->prepare('SELECT * FROM dokumente WHERE id = ? AND mitglied_id = ?');
    $stmt->execute([$dok_id, $mitglied_id]);
    $dok = $stmt->fetch();

    if ($dok && (isAdmin() || (int)$dok['hochgeladen_von'] === (int)$user['id'])) {
        $file = UPLOAD_PATH . '/' . $dok['datei_pfad'];
        if (file_exists($file)) unlink($file);
        $db->prepare('DELETE FROM dokumente WHERE id = ?')->execute([$dok_id]);
        logActivity('mitglied_dokument_geloescht', "Dok-ID: {$dok_id}");
    }
    redirect(APP_URL . '/dashboard/mitglied-detail.php?id=' . $mitglied_id);
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$stmt = $db->prepare(
    "SELECT f.*, u.vorname, u.nachname
     FROM fortschritt_eintraege f JOIN users u ON f.trainer_id = u.id
     WHERE f.user_id = ? ORDER BY f.created_at DESC"
);
$stmt->execute([$mitglied_id]);
$fortschritt = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM dokumente WHERE mitglied_id = ? ORDER BY created_at DESC');
$stmt->execute([$mitglied_id]);
$dokumente = $stmt->fetchAll();

$page_title = $mitglied['vorname'] . ' ' . $mitglied['nachname'];
$breadcrumb = 'Mitglieder';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$status_labels = [
    'aktiv'      => ['label' => 'Aktiv',      'class' => 'badge-success'],
    'inaktiv'    => ['label' => 'Inaktiv',    'class' => 'badge-danger'],
    'ausstehend' => ['label' => 'Ausstehend', 'class' => 'badge-info'],
];
$s = $status_labels[$mitglied['mitgliedsstatus'] ?? 'ausstehend'] ?? ['label' => 'k. A.', 'class' => 'badge-gray'];
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/mitglieder.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Zurück zu Mitglieder
        </a>
        <h1 class="dashboard-title"><?= e($mitglied['vorname'] . ' ' . $mitglied['nachname']) ?></h1>
        <p class="dashboard-subtitle">
            <?= e($mitglied['email']) ?>
            <?php if ($mitglied['telefon']): ?> · <?= e($mitglied['telefon']) ?><?php endif; ?>
            <?php if ($mitglied['sportarten']): ?> · <?= e($mitglied['sportarten']) ?><?php endif; ?>
        </p>
    </div>
    <span class="badge <?= $s['class'] ?>" style="font-size: 0.8rem;"><?= e($s['label']) ?></span>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', array_values($errors))) ?></span>
</div>
<?php endif; ?>

<div class="grid-2" style="align-items: start;">

    <!-- Fortschritt -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Fortschritt</h2>
        </div>
        <div style="padding: 1.25rem;">
            <form method="POST" action="" style="margin-bottom: 1.5rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="fortschritt_hinzufuegen">
                <div class="form-group">
                    <textarea class="form-control" name="eintrag" rows="3" placeholder="Neuer Fortschrittseintrag, z.B. Beobachtungen, Ziele, Testergebnisse…" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Eintrag speichern</button>
            </form>

            <?php if (empty($fortschritt)): ?>
                <p style="color: var(--text-muted); font-size: 0.875rem;">Noch keine Einträge vorhanden.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 1rem; max-height: 480px; overflow-y: auto;">
                    <?php foreach ($fortschritt as $f): ?>
                        <div style="border-left: 3px solid var(--gold-accent); padding-left: 1rem;">
                            <p style="margin: 0 0 0.25rem; font-size: 0.9rem; line-height: 1.6;"><?= nl2br(e($f['eintrag'])) ?></p>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">
                                <?= e($f['vorname'] . ' ' . $f['nachname']) ?> · <?= date('d.m.Y H:i', strtotime($f['created_at'])) ?>
                            </span>
                            <?php if (isAdmin() || (int)$f['trainer_id'] === (int)$user['id']): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Eintrag wirklich löschen?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="fortschritt_loeschen">
                                    <input type="hidden" name="eintrag_id" value="<?= $f['id'] ?>">
                                    <button type="submit" style="background: none; border: none; color: var(--danger); font-size: 0.75rem; cursor: pointer; padding: 0; margin-left: 0.5rem;">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Dokumentenarchiv -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Dokumentenarchiv</h2>
        </div>
        <div style="padding: 1.25rem;">
            <form method="POST" action="" enctype="multipart/form-data" style="margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-light);">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="dokument_upload">
                <div class="form-group">
                    <label class="form-label">Titel</label>
                    <input class="form-control" type="text" name="titel" required placeholder="z.B. Leistungstest März 2026">
                </div>
                <div class="form-group">
                    <label class="form-label">PDF-Datei</label>
                    <input class="form-control" type="file" name="pdf_file" accept=".pdf" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Hochladen</button>
            </form>

            <?php if (empty($dokumente)): ?>
                <p style="color: var(--text-muted); font-size: 0.875rem;">Noch keine Dokumente im Archiv.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($dokumente as $dok): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem; background: var(--bg-muted); border-radius: 0.5rem;">
                            <div style="min-width: 0;">
                                <div style="font-weight: 600; font-size: 0.875rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= e($dok['titel']) ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= date('d.m.Y', strtotime($dok['created_at'])) ?> · <?= number_format($dok['datei_groesse'] / 1024, 0) ?> KB</div>
                            </div>
                            <div style="display: flex; gap: 0.4rem; flex-shrink: 0;">
                                <a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $dok['id'] ?>" class="btn btn-ghost-light btn-sm">Download</a>
                                <?php if (isAdmin() || (int)$dok['hochgeladen_von'] === (int)$user['id']): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Dokument wirklich löschen?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="dokument_loeschen">
                                    <input type="hidden" name="dok_id" value="<?= $dok['id'] ?>">
                                    <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Löschen</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isAdmin()): ?>
<div class="form-card" style="margin-top: 1.5rem;">
    <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.25rem;">Interne Notizen</h2>
    <form method="POST" action="<?= APP_URL ?>/dashboard/mitglieder.php" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?= $mitglied['id'] ?>">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Status</label>
            <select class="form-control" name="mitgliedsstatus">
                <?php foreach ($status_labels as $val => $info): ?>
                    <option value="<?= $val ?>" <?= ($mitglied['mitgliedsstatus'] ?? '') === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin: 0; flex: 1; min-width: 220px;">
            <label class="form-label">Notizen</label>
            <input class="form-control" type="text" name="notizen" value="<?= e($mitglied['notizen'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
    </form>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
