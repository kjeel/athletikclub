<?php
/**
 * Athletikclub Steiermark – Admin: Förderungsdetail
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db  = getDB();
$user = getCurrentUser();
$foerderung_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM foerderungen WHERE id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([$foerderung_id, currentOrgId()]);
$foerderung = $stmt->fetch();

if (!$foerderung) {
    flashMessage('error', 'Förderung nicht gefunden.');
    redirect(APP_URL . '/dashboard/admin/foerderungen.php');
}

$status_map = [
    'geplant'       => ['label' => 'Geplant',       'class' => 'badge-gray'],
    'beantragt'     => ['label' => 'Beantragt',     'class' => 'badge-info'],
    'bewilligt'     => ['label' => 'Bewilligt',     'class' => 'badge-success'],
    'abgelehnt'     => ['label' => 'Abgelehnt',     'class' => 'badge-danger'],
    'ausbezahlt'    => ['label' => 'Ausbezahlt',    'class' => 'badge-gold'],
    'abgeschlossen' => ['label' => 'Abgeschlossen', 'class' => 'badge-navy'],
];

$kat_labels = [
    'foerderansuchen'     => 'Förderansuchen',
    'foerderbescheid'     => 'Bescheid',
    'verwendungsnachweis' => 'Verwendungsnachweis',
    'sonstiges'           => 'Sonstiges',
];

if (!is_dir(PDF_PATH)) {
    mkdir(PDF_PATH, 0755, true);
}

$errors  = [];
$success = '';

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_details') {
        $titel             = trim($_POST['titel'] ?? '');
        $foerderstelle     = trim($_POST['foerderstelle'] ?? '');
        $beschreibung      = trim($_POST['beschreibung'] ?? '');
        $betrag_beantragt  = trim($_POST['betrag_beantragt'] ?? '');
        $betrag_bewilligt  = trim($_POST['betrag_bewilligt'] ?? '');
        $einreichfrist     = trim($_POST['einreichfrist'] ?? '');
        $bewilligungsdatum = trim($_POST['bewilligungsdatum'] ?? '');
        $nachweisfrist     = trim($_POST['nachweisfrist'] ?? '');
        $ausbezahlt_am     = trim($_POST['ausbezahlt_am'] ?? '');
        $ansprechpartner_name    = trim($_POST['ansprechpartner_name'] ?? '');
        $ansprechpartner_email   = trim($_POST['ansprechpartner_email'] ?? '');
        $ansprechpartner_telefon = trim($_POST['ansprechpartner_telefon'] ?? '');
        $notizen           = trim($_POST['notizen'] ?? '');

        if (mb_strlen($titel) < 3) $errors['titel'] = 'Titel muss mindestens 3 Zeichen haben.';
        if (mb_strlen($foerderstelle) < 2) $errors['foerderstelle'] = 'Förderstelle ist Pflichtfeld.';
        if ($betrag_beantragt !== '' && (!is_numeric($betrag_beantragt) || (float)$betrag_beantragt < 0)) {
            $errors['betrag_beantragt'] = 'Bitte einen gültigen Betrag eingeben.';
        }
        if ($betrag_bewilligt !== '' && (!is_numeric($betrag_bewilligt) || (float)$betrag_bewilligt < 0)) {
            $errors['betrag_bewilligt'] = 'Bitte einen gültigen Betrag eingeben.';
        }
        if ($ansprechpartner_email !== '' && !filter_var($ansprechpartner_email, FILTER_VALIDATE_EMAIL)) {
            $errors['ansprechpartner_email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        if (empty($errors)) {
            $db->prepare(
                'UPDATE foerderungen SET
                    titel = ?, foerderstelle = ?, beschreibung = ?, betrag_beantragt = ?, betrag_bewilligt = ?,
                    einreichfrist = ?, bewilligungsdatum = ?, nachweisfrist = ?, ausbezahlt_am = ?,
                    ansprechpartner_name = ?, ansprechpartner_email = ?, ansprechpartner_telefon = ?, notizen = ?
                 WHERE id = ?'
            )->execute([
                $titel, $foerderstelle, $beschreibung ?: null,
                $betrag_beantragt !== '' ? (float)$betrag_beantragt : null,
                $betrag_bewilligt !== '' ? (float)$betrag_bewilligt : null,
                $einreichfrist ?: null, $bewilligungsdatum ?: null, $nachweisfrist ?: null, $ausbezahlt_am ?: null,
                $ansprechpartner_name ?: null, $ansprechpartner_email ?: null, $ansprechpartner_telefon ?: null,
                $notizen ?: null,
                $foerderung_id,
            ]);
            logActivity('foerderung_aktualisiert', "Förderung-ID: {$foerderung_id}");
            flashMessage('success', 'Förderung aktualisiert.');
            redirect(APP_URL . '/dashboard/admin/foerderung-detail.php?id=' . $foerderung_id);
        }
    }

    if ($action === 'status_aendern') {
        $neuer_status = $_POST['status'] ?? '';
        if (isset($status_map[$neuer_status])) {
            if ($neuer_status === 'ausbezahlt' && empty($foerderung['ausbezahlt_am'])) {
                $db->prepare('UPDATE foerderungen SET status = ?, ausbezahlt_am = CURDATE() WHERE id = ?')
                   ->execute([$neuer_status, $foerderung_id]);
            } else {
                $db->prepare('UPDATE foerderungen SET status = ? WHERE id = ?')
                   ->execute([$neuer_status, $foerderung_id]);
            }
            logActivity('foerderung_status_geaendert', "Förderung-ID: {$foerderung_id} -> {$neuer_status}");
            flashMessage('success', 'Status aktualisiert.');
        }
        redirect(APP_URL . '/dashboard/admin/foerderung-detail.php?id=' . $foerderung_id);
    }

    if ($action === 'upload_dokument') {
        $titel     = trim($_POST['dok_titel'] ?? '');
        $kategorie = $_POST['dok_kategorie'] ?? 'sonstiges';
        if (!isset($kat_labels[$kategorie])) $kategorie = 'sonstiges';
        if (empty($titel)) $errors['dok_titel'] = 'Titel ist Pflichtfeld.';

        if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            $errors['dok_titel'] = 'Bitte wähle eine PDF-Datei aus.';
        } elseif ($_FILES['pdf_file']['size'] > MAX_PDF_SIZE) {
            $errors['dok_titel'] = 'Datei zu groß (max. 10 MB).';
        } elseif (!in_array($_FILES['pdf_file']['type'], ALLOWED_PDF_TYPES, true)) {
            $errors['dok_titel'] = 'Nur PDF-Dateien sind erlaubt.';
        }

        if (empty($errors)) {
            $original_name = basename($_FILES['pdf_file']['name']);
            $safe_name     = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $original_name);
            $unique_name   = date('Ymd_His') . '_' . $safe_name;
            $dest          = PDF_PATH . '/' . $unique_name;

            if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $dest)) {
                $db->prepare(
                    'INSERT INTO dokumente (organization_id, titel, datei_name, datei_pfad, datei_groesse, mime_type, kategorie, sichtbar_fuer, hochgeladen_von, foerderung_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    currentOrgId(), $titel, $original_name, 'pdfs/' . $unique_name,
                    $_FILES['pdf_file']['size'], 'application/pdf', $kategorie, 'admin', $user['id'], $foerderung_id,
                ]);
                logActivity('foerderung_dokument_upload', "Förderung-ID: {$foerderung_id}, Datei: {$original_name}");
                flashMessage('success', 'Dokument hochgeladen.');
                redirect(APP_URL . '/dashboard/admin/foerderung-detail.php?id=' . $foerderung_id);
            } else {
                $errors['dok_titel'] = 'Datei konnte nicht gespeichert werden.';
            }
        }
    }

    if ($action === 'delete_dokument') {
        $dok_id = (int)($_POST['dok_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM dokumente WHERE id = ? AND foerderung_id = ?');
        $stmt->execute([$dok_id, $foerderung_id]);
        $dok = $stmt->fetch();
        if ($dok) {
            $file = UPLOAD_PATH . '/' . $dok['datei_pfad'];
            if (file_exists($file)) unlink($file);
            $db->prepare('DELETE FROM dokumente WHERE id = ?')->execute([$dok_id]);
            logActivity('foerderung_dokument_geloescht', "Förderung-ID: {$foerderung_id}, Dok-ID: {$dok_id}");
            flashMessage('success', 'Dokument gelöscht.');
        }
        redirect(APP_URL . '/dashboard/admin/foerderung-detail.php?id=' . $foerderung_id);
    }

    if ($action === 'delete_foerderung') {
        $db->prepare('DELETE FROM foerderungen WHERE id = ?')->execute([$foerderung_id]);
        logActivity('foerderung_geloescht', "Förderung-ID: {$foerderung_id}, Titel: {$foerderung['titel']}");
        flashMessage('success', 'Förderung gelöscht.');
        redirect(APP_URL . '/dashboard/admin/foerderungen.php');
    }

    // Bei Fehlern: aktuelle Werte für erneute Anzeige übernehmen
    $foerderung = array_merge($foerderung, $_POST);
}

// ----------------------------------------------------------------
// Dokumente laden
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM dokumente WHERE foerderung_id = ? ORDER BY created_at DESC');
$stmt->execute([$foerderung_id]);
$dokumente = $stmt->fetchAll();

$page_title = $foerderung['titel'];
$breadcrumb = 'Fördermanagement';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$s = $status_map[$foerderung['status']] ?? ['label' => $foerderung['status'], 'class' => 'badge-gray'];
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/admin/foerderungen.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Zurück zum Fördermanagement
        </a>
        <h1 class="dashboard-title"><?= e($foerderung['titel']) ?></h1>
        <p class="dashboard-subtitle"><?= e($foerderung['foerderstelle']) ?></p>
    </div>
    <span class="badge <?= $s['class'] ?>" style="font-size: 0.8rem;"><?= e($s['label']) ?></span>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<div class="grid-2" style="display: grid; gap: 1.5rem; align-items: start; margin-bottom: 1.5rem;">

    <!-- Status & Verwaltung -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Status</h2>
        </div>
        <div style="padding: 1.25rem;">
            <form method="POST" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status_aendern">
                <select name="status" class="form-control" style="flex: 1; min-width: 160px;">
                    <?php foreach ($status_map as $val => $info): ?>
                        <option value="<?= $val ?>" <?= $foerderung['status'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
            </form>
            <p class="form-hint">Beim Wechsel auf "Ausbezahlt" wird das Auszahlungsdatum automatisch auf heute gesetzt, falls noch leer.</p>

            <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border-light);">
                <form method="POST" onsubmit="return confirm('Förderung inkl. aller Dokumente wirklich unwiderruflich löschen?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_foerderung">
                    <button type="submit" class="btn btn-ghost-light btn-sm w-full" style="color: var(--danger);">Förderung löschen</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Eckdaten -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Eckdaten</h2>
        </div>
        <div style="padding: 1.25rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.875rem;">
            <div><strong>Beantragt</strong><br><?= $foerderung['betrag_beantragt'] !== null ? number_format((float)$foerderung['betrag_beantragt'], 2, ',', '.') . ' €' : '–' ?></div>
            <div><strong>Bewilligt</strong><br><?= $foerderung['betrag_bewilligt'] !== null ? number_format((float)$foerderung['betrag_bewilligt'], 2, ',', '.') . ' €' : '–' ?></div>
            <div><strong>Einreichfrist</strong><br><?= $foerderung['einreichfrist'] ? date('d.m.Y', strtotime($foerderung['einreichfrist'])) : '–' ?></div>
            <div><strong>Bewilligungsdatum</strong><br><?= $foerderung['bewilligungsdatum'] ? date('d.m.Y', strtotime($foerderung['bewilligungsdatum'])) : '–' ?></div>
            <div><strong>Nachweisfrist</strong><br><?= $foerderung['nachweisfrist'] ? date('d.m.Y', strtotime($foerderung['nachweisfrist'])) : '–' ?></div>
            <div><strong>Ausbezahlt am</strong><br><?= $foerderung['ausbezahlt_am'] ? date('d.m.Y', strtotime($foerderung['ausbezahlt_am'])) : '–' ?></div>
        </div>
    </div>
</div>

<!-- Bearbeiten -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Details bearbeiten</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="" data-validate novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_details">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="titel">Titel <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" id="titel" name="titel" value="<?= e($foerderung['titel']) ?>" required>
                    <?php if (isset($errors['titel'])): ?><span class="form-error"><?= e($errors['titel']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="foerderstelle">Förderstelle <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['foerderstelle']) ? 'error' : '' ?>" type="text" id="foerderstelle" name="foerderstelle" value="<?= e($foerderung['foerderstelle']) ?>" required>
                    <?php if (isset($errors['foerderstelle'])): ?><span class="form-error"><?= e($errors['foerderstelle']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="beschreibung">Beschreibung</label>
                <textarea class="form-control" id="beschreibung" name="beschreibung" rows="3"><?= e($foerderung['beschreibung'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="betrag_beantragt">Beantragter Betrag (€)</label>
                    <input class="form-control <?= isset($errors['betrag_beantragt']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="betrag_beantragt" name="betrag_beantragt" value="<?= e($foerderung['betrag_beantragt'] ?? '') ?>">
                    <?php if (isset($errors['betrag_beantragt'])): ?><span class="form-error"><?= e($errors['betrag_beantragt']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="betrag_bewilligt">Bewilligter Betrag (€)</label>
                    <input class="form-control <?= isset($errors['betrag_bewilligt']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="betrag_bewilligt" name="betrag_bewilligt" value="<?= e($foerderung['betrag_bewilligt'] ?? '') ?>">
                    <?php if (isset($errors['betrag_bewilligt'])): ?><span class="form-error"><?= e($errors['betrag_bewilligt']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="einreichfrist">Einreichfrist</label>
                    <input class="form-control" type="date" id="einreichfrist" name="einreichfrist" value="<?= e($foerderung['einreichfrist'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="bewilligungsdatum">Bewilligungsdatum</label>
                    <input class="form-control" type="date" id="bewilligungsdatum" name="bewilligungsdatum" value="<?= e($foerderung['bewilligungsdatum'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="nachweisfrist">Frist Verwendungsnachweis</label>
                    <input class="form-control" type="date" id="nachweisfrist" name="nachweisfrist" value="<?= e($foerderung['nachweisfrist'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="ausbezahlt_am">Ausbezahlt am</label>
                    <input class="form-control" type="date" id="ausbezahlt_am" name="ausbezahlt_am" value="<?= e($foerderung['ausbezahlt_am'] ?? '') ?>">
                </div>
            </div>

            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin: 1.5rem 0 1rem;">Ansprechpartner</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="ansprechpartner_name">Name</label>
                    <input class="form-control" type="text" id="ansprechpartner_name" name="ansprechpartner_name" value="<?= e($foerderung['ansprechpartner_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="ansprechpartner_telefon">Telefon</label>
                    <input class="form-control" type="text" id="ansprechpartner_telefon" name="ansprechpartner_telefon" value="<?= e($foerderung['ansprechpartner_telefon'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="ansprechpartner_email">E-Mail</label>
                <input class="form-control <?= isset($errors['ansprechpartner_email']) ? 'error' : '' ?>" type="email" id="ansprechpartner_email" name="ansprechpartner_email" value="<?= e($foerderung['ansprechpartner_email'] ?? '') ?>">
                <?php if (isset($errors['ansprechpartner_email'])): ?><span class="form-error"><?= e($errors['ansprechpartner_email']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="notizen">Interne Notizen</label>
                <textarea class="form-control" id="notizen" name="notizen" rows="3" placeholder="Verlauf, Kommunikation, Besonderheiten…"><?= e($foerderung['notizen'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Änderungen speichern</button>
        </form>
    </div>
</div>

<!-- Dokumente -->
<div class="table-card">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="table-card-title">Dokumente (<?= count($dokumente) ?>)</h2>
        <button class="btn btn-primary btn-sm" data-modal-open="upload-modal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            PDF hochladen
        </button>
    </div>
    <?php if (empty($dokumente)): ?>
        <div class="empty-state">
            <h3>Noch keine Dokumente</h3>
            <p>Lade Förderansuchen, Bescheid oder Verwendungsnachweis als PDF hoch.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Kategorie</th>
                        <th>Hochgeladen am</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dokumente as $dok): ?>
                    <tr>
                        <td class="text-primary"><?= e($dok['titel']) ?></td>
                        <td><span class="badge badge-navy"><?= e($kat_labels[$dok['kategorie']] ?? $dok['kategorie']) ?></span></td>
                        <td><?= date('d.m.Y', strtotime($dok['created_at'])) ?></td>
                        <td style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                            <a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $dok['id'] ?>" class="btn btn-ghost-light btn-sm">Download</a>
                            <form method="POST" onsubmit="return confirm('Dokument wirklich löschen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_dokument">
                                <input type="hidden" name="dok_id" value="<?= $dok['id'] ?>">
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

<!-- Upload Modal -->
<div class="modal" id="upload-modal" style="
    position: fixed; inset: 0; z-index: 2000;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
    opacity: 0; visibility: hidden; transition: all 0.25s;
">
    <div class="modal-overlay" style="position: absolute; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);"></div>
    <div style="
        background: var(--surface);
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
            <input type="hidden" name="action" value="upload_dokument">

            <div class="form-group">
                <label class="form-label">Titel <span class="required">*</span></label>
                <input class="form-control" type="text" name="dok_titel" required placeholder="z.B. Förderbescheid 2026">
            </div>

            <div class="form-group">
                <label class="form-label">Kategorie</label>
                <select class="form-control" name="dok_kategorie">
                    <?php foreach ($kat_labels as $val => $label): ?>
                        <option value="<?= $val ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
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

<style>
.modal.active { opacity: 1 !important; visibility: visible !important; }
</style>

<script>
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
