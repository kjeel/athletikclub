<?php
/**
 * Athletikclub Steiermark – Dokumente / PDF-Verwaltung (Dashboard)
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title = 'Dokumente';
$breadcrumb = 'Dokumente';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$db   = getDB();
$user = getCurrentUser();
$errors = [];
$success = '';

// Upload-Verzeichnis erstellen falls nicht vorhanden
if (!is_dir(PDF_PATH)) {
    mkdir(PDF_PATH, 0755, true);
}

// ----------------------------------------------------------------
// PDF Upload (nur Trainer / Admin)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    requireCsrf();
    requireTrainer();

    $titel       = trim($_POST['titel'] ?? '');
    $beschreibung= trim($_POST['beschreibung'] ?? '');
    $kategorie   = $_POST['kategorie'] ?? 'sonstiges';
    $sichtbar    = $_POST['sichtbar_fuer'] ?? 'mitglieder';

    $allowed_kategorien  = ['vereinsdokument', 'trainingsplan', 'kursinformation', 'protokoll', 'sonstiges'];
    $allowed_sichtbar    = ['alle', 'mitglieder', 'trainer', 'admin'];

    if (empty($titel))                                    $errors[] = 'Titel ist Pflichtfeld.';
    if (!in_array($kategorie, $allowed_kategorien, true)) $errors[] = 'Ungültige Kategorie.';
    if (!in_array($sichtbar, $allowed_sichtbar, true))    $errors[] = 'Ungültige Sichtbarkeit.';

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
            $stmt = $db->prepare(
                'INSERT INTO dokumente (titel, beschreibung, datei_name, datei_pfad, datei_groesse, mime_type, kategorie, sichtbar_fuer, hochgeladen_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $titel, $beschreibung, $original_name, 'pdfs/' . $unique_name,
                $_FILES['pdf_file']['size'], 'application/pdf',
                $kategorie, $sichtbar, $user['id']
            ]);
            logActivity('dokument_upload', "Datei: {$original_name}");
            $success = 'Dokument erfolgreich hochgeladen!';
        } else {
            $errors[] = 'Datei konnte nicht gespeichert werden.';
        }
    }
}

// Dokument löschen (Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireCsrf();
    requireAdmin();

    $dok_id = (int)($_POST['dok_id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM dokumente WHERE id = ?');
    $stmt->execute([$dok_id]);
    $dok = $stmt->fetch();

    if ($dok) {
        $file = UPLOAD_PATH . '/' . $dok['datei_pfad'];
        if (file_exists($file)) unlink($file);
        $db->prepare('DELETE FROM dokumente WHERE id = ?')->execute([$dok_id]);
        logActivity('dokument_loeschen', "Dok-ID: {$dok_id}");
        $success = 'Dokument gelöscht.';
    }
}

// ----------------------------------------------------------------
// Dokumente laden (nach Rolle filtern)
// ----------------------------------------------------------------
$filter_kategorie = $_GET['kategorie'] ?? '';
$filter_suche     = trim($_GET['suche'] ?? '');

if (isAdmin()) {
    $where = 'd.mitglied_id IS NULL';
} elseif (isTrainer()) {
    $where = "d.mitglied_id IS NULL AND sichtbar_fuer IN ('alle','mitglieder','trainer')";
} else {
    $where = "d.mitglied_id IS NULL AND sichtbar_fuer IN ('alle','mitglieder')";
}
$params = [];

if ($filter_kategorie) {
    $where .= ' AND d.kategorie = ?';
    $params[] = $filter_kategorie;
}
if ($filter_suche) {
    $where .= ' AND (d.titel LIKE ? OR d.beschreibung LIKE ?)';
    $params[] = '%' . $filter_suche . '%';
    $params[] = '%' . $filter_suche . '%';
}

$stmt = $db->prepare(
    "SELECT d.*, u.vorname, u.nachname
     FROM dokumente d
     LEFT JOIN users u ON d.hochgeladen_von = u.id
     WHERE {$where}
     ORDER BY d.created_at DESC"
);
$stmt->execute($params);
$dokumente = $stmt->fetchAll();
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Dokumente</h1>
        <p class="dashboard-subtitle">PDF-Bibliothek des Athletikclubs</p>
    </div>
    <?php if (isTrainer()): ?>
        <button class="btn btn-primary btn-sm" data-modal-open="upload-modal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            PDF hochladen
        </button>
    <?php endif; ?>
</div>

<!-- Alerts -->
<?php if (!empty($errors)): ?>
    <div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
        <span><?= implode(' | ', array_map('e', $errors)) ?></span>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="flash-message flash-success" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
        <span><?= e($success) ?></span>
    </div>
<?php endif; ?>

<!-- Filter / Suche -->
<div style="background: white; border-radius: 1rem; padding: 1rem 1.25rem; border: 1px solid var(--border-light); margin-bottom: 1.5rem;">
    <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
            <label class="form-label">Suche</label>
            <input class="form-control" type="text" name="suche" value="<?= e($filter_suche) ?>" placeholder="Titel oder Beschreibung…">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Kategorie</label>
            <select class="form-control" name="kategorie">
                <option value="">Alle Kategorien</option>
                <?php foreach (['vereinsdokument' => 'Vereinsdokument', 'trainingsplan' => 'Trainingsplan', 'kursinformation' => 'Kursinformation', 'protokoll' => 'Protokoll', 'sonstiges' => 'Sonstiges'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $filter_kategorie === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-navy btn-sm" style="margin-bottom: 0;">Filtern</button>
        <?php if ($filter_suche || $filter_kategorie): ?>
            <a href="<?= APP_URL ?>/dashboard/dokumente.php" class="btn btn-ghost-light btn-sm" style="margin-bottom: 0;">Zurücksetzen</a>
        <?php endif; ?>
    </form>
</div>

<!-- Dokument-Liste -->
<?php if (empty($dokumente)): ?>
    <div class="table-card">
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <h3>Keine Dokumente</h3>
            <p><?= ($filter_suche || $filter_kategorie) ? 'Keine Dokumente entsprechen deiner Suche.' : 'Es wurden noch keine Dokumente hochgeladen.' ?></p>
            <?php if (isTrainer()): ?>
                <button class="btn btn-primary btn-sm" data-modal-open="upload-modal">PDF hochladen</button>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
        <?php foreach ($dokumente as $dok): ?>
            <div class="card" style="border-radius: 1rem;">
                <div style="display: flex; align-items: flex-start; gap: 1rem; padding: 1.25rem;">
                    <!-- PDF Icon -->
                    <div style="
                        width: 48px; height: 48px; flex-shrink: 0;
                        background: rgba(239,68,68,0.1);
                        border-radius: 0.75rem;
                        display: flex; align-items: center; justify-content: center;
                    ">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.9rem; font-weight: 700; margin-bottom: 0.25rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= e($dok['titel']) ?>
                        </h3>
                        <?php if ($dok['beschreibung']): ?>
                            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0 0 0.5rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                <?= e($dok['beschreibung']) ?>
                            </p>
                        <?php endif; ?>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem;">
                            <?php
                            $kat_labels = ['vereinsdokument' => 'Vereinsdok.', 'trainingsplan' => 'Trainingsplan', 'kursinformation' => 'Kursinfo', 'protokoll' => 'Protokoll', 'sonstiges' => 'Sonstiges'];
                            $kat_label  = $kat_labels[$dok['kategorie']] ?? $dok['kategorie'];
                            ?>
                            <span class="badge badge-navy"><?= e($kat_label) ?></span>
                            <span class="badge badge-gray"><?= number_format($dok['datei_groesse'] / 1024, 0) ?> KB</span>
                        </div>
                        <p style="font-size: 0.7rem; color: var(--text-muted); margin: 0.5rem 0 0;">
                            <?= e($dok['vorname'] . ' ' . $dok['nachname']) ?> ·
                            <?= date('d.m.Y', strtotime($dok['created_at'])) ?> ·
                            <?= $dok['downloads'] ?> Downloads
                        </p>
                    </div>
                </div>
                <div style="padding: 0.75rem 1.25rem; border-top: 1px solid var(--border-light); display: flex; gap: 0.5rem; justify-content: flex-end;">
                    <a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $dok['id'] ?>"
                       class="btn btn-primary btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download
                    </a>
                    <?php if (isAdmin()): ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Dokument wirklich löschen?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="dok_id" value="<?= $dok['id'] ?>">
                            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                Löschen
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============================================================
     UPLOAD MODAL (Trainer/Admin)
============================================================ -->
<?php if (isTrainer()): ?>
<div class="modal" id="upload-modal" style="
    position: fixed; inset: 0; z-index: 2000;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
    opacity: 0; visibility: hidden; transition: all 0.25s;
" id="upload-modal">
    <div class="modal-overlay" style="position: absolute; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);"></div>
    <div style="
        background: white;
        border-radius: 1.5rem;
        width: 100%;
        max-width: 520px;
        position: relative;
        z-index: 1;
        box-shadow: 0 25px 50px rgba(0,0,0,0.2);
        animation: authCardIn 0.3s ease;
    ">
        <div style="padding: 1.5rem 1.75rem; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center;">
            <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.1rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin: 0;">PDF hochladen</h2>
            <button data-modal-close style="font-size: 1.5rem; opacity: 0.5; line-height: 1;">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data" style="padding: 1.75rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload">

            <div class="form-group">
                <label class="form-label">Titel <span class="required">*</span></label>
                <input class="form-control" type="text" name="titel" required placeholder="z.B. Trainingsplan Winter 2025">
            </div>

            <div class="form-group">
                <label class="form-label">Beschreibung</label>
                <textarea class="form-control" name="beschreibung" rows="2" placeholder="Kurze Beschreibung (optional)"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Kategorie</label>
                    <select class="form-control" name="kategorie">
                        <option value="sonstiges">Sonstiges</option>
                        <option value="vereinsdokument">Vereinsdokument</option>
                        <option value="trainingsplan">Trainingsplan</option>
                        <option value="kursinformation">Kursinformation</option>
                        <option value="protokoll">Protokoll</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Sichtbar für</label>
                    <select class="form-control" name="sichtbar_fuer">
                        <option value="mitglieder">Mitglieder</option>
                        <option value="trainer">Trainer+</option>
                        <?php if (isAdmin()): ?>
                        <option value="admin">Nur Admin</option>
                        <?php endif; ?>
                        <option value="alle">Alle (öffentlich)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">PDF-Datei <span class="required">*</span></label>
                <label class="upload-zone" for="pdf_file_input">
                    <div class="upload-zone-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    </div>
                    <h3>PDF hierher ziehen oder klicken</h3>
                    <p>Maximale Dateigröße: 10 MB</p>
                    <input type="file" name="pdf_file" id="pdf_file_input" accept=".pdf" required>
                </label>
                <p class="form-hint" id="pdf-filename"></p>
            </div>

            <button type="submit" class="btn btn-primary w-full">Hochladen</button>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.modal.active { opacity: 1 !important; visibility: visible !important; }
</style>

<script>
// File name preview
const pdfInput = document.getElementById('pdf_file_input');
if (pdfInput) {
    pdfInput.addEventListener('change', function() {
        const preview = document.getElementById('pdf-filename');
        if (preview && this.files[0]) {
            preview.textContent = '📄 ' + this.files[0].name;
        }
    });
}
</script>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
