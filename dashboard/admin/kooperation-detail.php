<?php
/**
 * Athletikclub Steiermark – Admin: Gemeinde-Kooperation Detail
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db  = getDB();
$user = getCurrentUser();
$kooperation_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM kooperationen WHERE id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([$kooperation_id, currentOrgId()]);
$kooperation = $stmt->fetch();

if (!$kooperation) {
    flashMessage('error', 'Kooperation nicht gefunden.');
    redirect(APP_URL . '/dashboard/admin/kooperationen.php');
}

$status_map = [
    'entwurf'                 => ['label' => 'Entwurf',                'class' => 'badge-gray'],
    'vereinbarung_erstellt'   => ['label' => 'Vereinbarung erstellt',  'class' => 'badge-info'],
    'unterschrift_ausstehend' => ['label' => 'Unterschrift ausstehend','class' => 'badge-warning'],
    'aktiv'                   => ['label' => 'Aktiv',                  'class' => 'badge-success'],
    'beendet'                 => ['label' => 'Beendet',                'class' => 'badge-navy'],
];

$kat_labels = [
    'kooperationsvereinbarung' => 'Kooperationsvereinbarung',
    'rueckmeldeblatt'          => 'Rückmeldeblatt',
    'rechnung'                 => 'Rechnung',
    'anwesenheitsliste'        => 'Anwesenheitsliste',
    'dokumentation'            => 'Dokumentation',
    'sonstiges'                => 'Sonstiges',
];

if (!is_dir(PDF_PATH)) {
    mkdir(PDF_PATH, 0755, true);
}

$errors = [];

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_details') {
        $gemeinde_name       = trim($_POST['gemeinde_name'] ?? '');
        $gemeinde_adresse    = trim($_POST['gemeinde_adresse'] ?? '');
        $buergermeister      = trim($_POST['buergermeister'] ?? '');
        $gem_ap_name         = trim($_POST['gemeinde_ansprechpartner_name'] ?? '');
        $gem_ap_email        = trim($_POST['gemeinde_ansprechpartner_email'] ?? '');
        $gem_ap_tel          = trim($_POST['gemeinde_ansprechpartner_tel'] ?? '');
        $verein_ap_name      = trim($_POST['verein_ansprechpartner_name'] ?? '');
        $verein_ap_email     = trim($_POST['verein_ansprechpartner_email'] ?? '');
        $verein_ap_tel       = trim($_POST['verein_ansprechpartner_tel'] ?? '');
        $dachverband         = $_POST['dachverband'] ?? 'SPORTUNION';
        $kooperationsbeginn  = trim($_POST['kooperationsbeginn'] ?? '');
        $notizen             = trim($_POST['notizen'] ?? '');

        if (mb_strlen($gemeinde_name) < 2) $errors['gemeinde_name'] = 'Gemeindename ist Pflichtfeld.';
        if (!in_array($dachverband, ['ASKÖ', 'ASVÖ', 'SPORTUNION'], true)) $dachverband = 'SPORTUNION';
        if ($gem_ap_email !== '' && !filter_var($gem_ap_email, FILTER_VALIDATE_EMAIL)) {
            $errors['gemeinde_ansprechpartner_email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }
        if ($verein_ap_email !== '' && !filter_var($verein_ap_email, FILTER_VALIDATE_EMAIL)) {
            $errors['verein_ansprechpartner_email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        if (empty($errors)) {
            $db->prepare(
                'UPDATE kooperationen SET
                    gemeinde_name = ?, gemeinde_adresse = ?, buergermeister = ?,
                    gemeinde_ansprechpartner_name = ?, gemeinde_ansprechpartner_email = ?, gemeinde_ansprechpartner_tel = ?,
                    verein_ansprechpartner_name = ?, verein_ansprechpartner_email = ?, verein_ansprechpartner_tel = ?,
                    dachverband = ?, kooperationsbeginn = ?, notizen = ?
                 WHERE id = ?'
            )->execute([
                $gemeinde_name, $gemeinde_adresse ?: null, $buergermeister ?: null,
                $gem_ap_name ?: null, $gem_ap_email ?: null, $gem_ap_tel ?: null,
                $verein_ap_name ?: null, $verein_ap_email ?: null, $verein_ap_tel ?: null,
                $dachverband,
                $kooperationsbeginn !== '' ? date('Y-m-01', strtotime($kooperationsbeginn . '-01')) : null,
                $notizen ?: null,
                $kooperation_id,
            ]);
            logActivity('kooperation_aktualisiert', "Kooperation-ID: {$kooperation_id}");
            flashMessage('success', 'Kooperation aktualisiert.');
            redirect(APP_URL . '/dashboard/admin/kooperation-detail.php?id=' . $kooperation_id);
        }
    }

    if ($action === 'status_aendern') {
        $neuer_status = $_POST['status'] ?? '';
        if (isset($status_map[$neuer_status])) {
            $db->prepare('UPDATE kooperationen SET status = ? WHERE id = ?')->execute([$neuer_status, $kooperation_id]);
            logActivity('kooperation_status_geaendert', "Kooperation-ID: {$kooperation_id} -> {$neuer_status}");
            flashMessage('success', 'Status aktualisiert.');
        }
        redirect(APP_URL . '/dashboard/admin/kooperation-detail.php?id=' . $kooperation_id);
    }

    if ($action === 'periode_anlegen') {
        $bezeichnung  = trim($_POST['bezeichnung'] ?? '');
        $zeitraum_von = trim($_POST['zeitraum_von'] ?? '');
        $zeitraum_bis = trim($_POST['zeitraum_bis'] ?? '');

        if (empty($bezeichnung))  $errors['bezeichnung'] = 'Bezeichnung ist Pflichtfeld (z.B. 2026/27).';
        if (empty($zeitraum_von)) $errors['zeitraum_von'] = 'Startdatum ist Pflichtfeld.';
        if (empty($zeitraum_bis)) $errors['zeitraum_bis'] = 'Enddatum ist Pflichtfeld.';

        if (empty($errors)) {
            $db->prepare(
                'INSERT INTO kooperations_perioden (kooperation_id, organization_id, bezeichnung, zeitraum_von, zeitraum_bis)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$kooperation_id, currentOrgId(), $bezeichnung, $zeitraum_von, $zeitraum_bis]);
            $neue_periode_id = (int)$db->lastInsertId();
            logActivity('kooperationsperiode_erstellt', "Kooperation-ID: {$kooperation_id}, Periode: {$bezeichnung}");
            flashMessage('success', 'Periode angelegt.');
            redirect(APP_URL . '/dashboard/admin/kooperation-periode-detail.php?id=' . $neue_periode_id);
        }
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
                    'INSERT INTO dokumente (organization_id, titel, datei_name, datei_pfad, datei_groesse, mime_type, kategorie, sichtbar_fuer, hochgeladen_von, kooperation_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    currentOrgId(), $titel, $original_name, 'pdfs/' . $unique_name,
                    $_FILES['pdf_file']['size'], 'application/pdf', $kategorie, 'admin', $user['id'], $kooperation_id,
                ]);
                logActivity('kooperation_dokument_upload', "Kooperation-ID: {$kooperation_id}, Datei: {$original_name}");
                flashMessage('success', 'Dokument hochgeladen.');
                redirect(APP_URL . '/dashboard/admin/kooperation-detail.php?id=' . $kooperation_id);
            } else {
                $errors['dok_titel'] = 'Datei konnte nicht gespeichert werden.';
            }
        }
    }

    if ($action === 'delete_dokument') {
        $dok_id = (int)($_POST['dok_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM dokumente WHERE id = ? AND kooperation_id = ?');
        $stmt->execute([$dok_id, $kooperation_id]);
        $dok = $stmt->fetch();
        if ($dok) {
            $file = UPLOAD_PATH . '/' . $dok['datei_pfad'];
            if (file_exists($file)) unlink($file);
            $db->prepare('DELETE FROM dokumente WHERE id = ?')->execute([$dok_id]);
            logActivity('kooperation_dokument_geloescht', "Kooperation-ID: {$kooperation_id}, Dok-ID: {$dok_id}");
            flashMessage('success', 'Dokument gelöscht.');
        }
        redirect(APP_URL . '/dashboard/admin/kooperation-detail.php?id=' . $kooperation_id);
    }

    if ($action === 'delete_kooperation') {
        $db->prepare('DELETE FROM kooperationen WHERE id = ?')->execute([$kooperation_id]);
        logActivity('kooperation_geloescht', "Kooperation-ID: {$kooperation_id}, Gemeinde: {$kooperation['gemeinde_name']}");
        flashMessage('success', 'Kooperation gelöscht.');
        redirect(APP_URL . '/dashboard/admin/kooperationen.php');
    }

    $kooperation = array_merge($kooperation, $_POST);
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM dokumente WHERE kooperation_id = ? ORDER BY created_at DESC');
$stmt->execute([$kooperation_id]);
$dokumente = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM kooperations_perioden WHERE kooperation_id = ? ORDER BY zeitraum_von DESC');
$stmt->execute([$kooperation_id]);
$perioden = $stmt->fetchAll();

$page_title = $kooperation['gemeinde_name'];
$breadcrumb = 'Gemeinde-Kooperationen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$s = $status_map[$kooperation['status']] ?? ['label' => $kooperation['status'], 'class' => 'badge-gray'];
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/admin/kooperationen.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Zurück zu den Kooperationen
        </a>
        <h1 class="dashboard-title"><?= e($kooperation['gemeinde_name']) ?></h1>
        <p class="dashboard-subtitle">Dachverband: <?= e($kooperation['dachverband']) ?></p>
    </div>
    <span class="badge <?= $s['class'] ?>" style="font-size: 0.8rem;"><?= e($s['label']) ?></span>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<div class="grid-2" style="display: grid; gap: 1.5rem; align-items: start; margin-bottom: 1.5rem;">

    <!-- Status & Vereinbarung -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Status &amp; Vereinbarung</h2>
        </div>
        <div style="padding: 1.25rem;">
            <form method="POST" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="status_aendern">
                <select name="status" class="form-control" style="flex: 1; min-width: 160px;">
                    <?php foreach ($status_map as $val => $info): ?>
                        <option value="<?= $val ?>" <?= $kooperation['status'] === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
            </form>

            <a href="<?= APP_URL ?>/dashboard/admin/kooperation-pdf.php?type=vereinbarung&id=<?= $kooperation_id ?>" target="_blank" class="btn btn-primary w-full" style="margin-bottom: 0.75rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Vereinbarung als PDF erzeugen
            </a>
            <p class="form-hint">Ausdrucken, von Verein, Dachverband und Gemeinde unterschreiben/stempeln lassen, dann als Scan unten im Ordner hochladen.</p>

            <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border-light);">
                <form method="POST" onsubmit="return confirm('Kooperation inkl. aller Perioden und Dokumente wirklich unwiderruflich löschen?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_kooperation">
                    <button type="submit" class="btn btn-ghost-light btn-sm w-full" style="color: var(--danger);">Kooperation löschen</button>
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
            <div><strong>Bürgermeister*in</strong><br><?= $kooperation['buergermeister'] ? e($kooperation['buergermeister']) : '–' ?></div>
            <div><strong>Kooperationsbeginn</strong><br><?= $kooperation['kooperationsbeginn'] ? date('m/Y', strtotime($kooperation['kooperationsbeginn'])) : '–' ?></div>
            <div><strong>Ansprechpartner*in Gemeinde</strong><br><?= $kooperation['gemeinde_ansprechpartner_name'] ? e($kooperation['gemeinde_ansprechpartner_name']) : '–' ?></div>
            <div><strong>Ansprechpartner*in Verein</strong><br><?= $kooperation['verein_ansprechpartner_name'] ? e($kooperation['verein_ansprechpartner_name']) : '–' ?></div>
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

            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1rem;">Gemeinde</h3>
            <div class="form-group">
                <label class="form-label" for="gemeinde_name">Gemeindename <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['gemeinde_name']) ? 'error' : '' ?>" type="text" id="gemeinde_name" name="gemeinde_name" value="<?= e($kooperation['gemeinde_name']) ?>" required>
                <?php if (isset($errors['gemeinde_name'])): ?><span class="form-error"><?= e($errors['gemeinde_name']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="gemeinde_adresse">Adresse</label>
                <input class="form-control" type="text" id="gemeinde_adresse" name="gemeinde_adresse" value="<?= e($kooperation['gemeinde_adresse'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="buergermeister">Bürgermeister*in</label>
                <input class="form-control" type="text" id="buergermeister" name="buergermeister" value="<?= e($kooperation['buergermeister'] ?? '') ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="gemeinde_ansprechpartner_name">Ansprechpartner*in Gemeinde</label>
                    <input class="form-control" type="text" id="gemeinde_ansprechpartner_name" name="gemeinde_ansprechpartner_name" value="<?= e($kooperation['gemeinde_ansprechpartner_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="gemeinde_ansprechpartner_tel">Telefon</label>
                    <input class="form-control" type="text" id="gemeinde_ansprechpartner_tel" name="gemeinde_ansprechpartner_tel" value="<?= e($kooperation['gemeinde_ansprechpartner_tel'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="gemeinde_ansprechpartner_email">E-Mail Ansprechpartner*in Gemeinde</label>
                <input class="form-control <?= isset($errors['gemeinde_ansprechpartner_email']) ? 'error' : '' ?>" type="email" id="gemeinde_ansprechpartner_email" name="gemeinde_ansprechpartner_email" value="<?= e($kooperation['gemeinde_ansprechpartner_email'] ?? '') ?>">
                <?php if (isset($errors['gemeinde_ansprechpartner_email'])): ?><span class="form-error"><?= e($errors['gemeinde_ansprechpartner_email']) ?></span><?php endif; ?>
            </div>

            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin: 1.5rem 0 1rem;">Verein – Ansprechpartner*in für diese Kooperation</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="verein_ansprechpartner_name">Name</label>
                    <input class="form-control" type="text" id="verein_ansprechpartner_name" name="verein_ansprechpartner_name" value="<?= e($kooperation['verein_ansprechpartner_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="verein_ansprechpartner_tel">Telefon</label>
                    <input class="form-control" type="text" id="verein_ansprechpartner_tel" name="verein_ansprechpartner_tel" value="<?= e($kooperation['verein_ansprechpartner_tel'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="verein_ansprechpartner_email">E-Mail</label>
                <input class="form-control <?= isset($errors['verein_ansprechpartner_email']) ? 'error' : '' ?>" type="email" id="verein_ansprechpartner_email" name="verein_ansprechpartner_email" value="<?= e($kooperation['verein_ansprechpartner_email'] ?? '') ?>">
                <?php if (isset($errors['verein_ansprechpartner_email'])): ?><span class="form-error"><?= e($errors['verein_ansprechpartner_email']) ?></span><?php endif; ?>
            </div>

            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin: 1.5rem 0 1rem;">Kooperation</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="dachverband">Dachverband</label>
                    <select class="form-control" id="dachverband" name="dachverband">
                        <option value="SPORTUNION" <?= $kooperation['dachverband'] === 'SPORTUNION' ? 'selected' : '' ?>>SPORTUNION</option>
                        <option value="ASKÖ" <?= $kooperation['dachverband'] === 'ASKÖ' ? 'selected' : '' ?>>ASKÖ</option>
                        <option value="ASVÖ" <?= $kooperation['dachverband'] === 'ASVÖ' ? 'selected' : '' ?>>ASVÖ</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="kooperationsbeginn">Kooperationsbeginn</label>
                    <input class="form-control" type="month" id="kooperationsbeginn" name="kooperationsbeginn" value="<?= $kooperation['kooperationsbeginn'] ? date('Y-m', strtotime($kooperation['kooperationsbeginn'])) : '' ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notizen">Interne Notizen</label>
                <textarea class="form-control" id="notizen" name="notizen" rows="3"><?= e($kooperation['notizen'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Änderungen speichern</button>
        </form>
    </div>
</div>

<!-- Perioden -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Kooperationsperioden (<?= count($perioden) ?>)</h2>
    </div>
    <div style="padding: 1.25rem; border-bottom: 1px solid var(--border-light);">
        <form method="POST" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="periode_anlegen">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Bezeichnung</label>
                <input class="form-control" type="text" name="bezeichnung" placeholder="z.B. 2026/27" style="width: 140px;">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Von</label>
                <input class="form-control" type="date" name="zeitraum_von">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Bis</label>
                <input class="form-control" type="date" name="zeitraum_bis">
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Periode anlegen</button>
        </form>
    </div>
    <?php if (empty($perioden)): ?>
        <div class="empty-state"><h3>Noch keine Periode angelegt</h3><p>Lege ein Kooperationsjahr an, um Rückmeldeblatt und Rechnung zu erfassen.</p></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Zeitraum</th>
                        <th>Rückmeldeblatt</th>
                        <th>Rechnung</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rm_labels = ['offen' => ['Offen', 'badge-gray'], 'eingereicht' => ['Eingereicht', 'badge-info'], 'freigegeben' => ['Freigegeben', 'badge-success']];
                    $re_labels = ['offen' => ['Offen', 'badge-gray'], 'erstellt' => ['Erstellt', 'badge-info'], 'eingereicht' => ['Eingereicht', 'badge-warning'], 'bezahlt' => ['Bezahlt', 'badge-success']];
                    foreach ($perioden as $p):
                        $rm = $rm_labels[$p['rueckmeldeblatt_status']] ?? ['?', 'badge-gray'];
                        $re = $re_labels[$p['rechnung_status']] ?? ['?', 'badge-gray'];
                    ?>
                    <tr>
                        <td class="text-primary"><?= e($p['bezeichnung']) ?></td>
                        <td><?= date('d.m.Y', strtotime($p['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($p['zeitraum_bis'])) ?></td>
                        <td><span class="badge <?= $rm[1] ?>"><?= $rm[0] ?></span></td>
                        <td><span class="badge <?= $re[1] ?>"><?= $re[0] ?></span></td>
                        <td><a href="<?= APP_URL ?>/dashboard/admin/kooperation-periode-detail.php?id=<?= $p['id'] ?>" class="btn btn-ghost-light btn-sm">Öffnen</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Dokumente -->
<div class="table-card">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="table-card-title">Ordner: Dokumente (<?= count($dokumente) ?>)</h2>
        <button class="btn btn-primary btn-sm" data-modal-open="upload-modal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            PDF hochladen
        </button>
    </div>
    <?php if (empty($dokumente)): ?>
        <div class="empty-state">
            <h3>Noch keine Dokumente</h3>
            <p>Lade hier unterschriebene Vereinbarungen, Rückmeldeblätter, Rechnungen und Anwesenheitslisten hoch.</p>
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
                <input class="form-control" type="text" name="dok_titel" required placeholder="z.B. Kooperationsvereinbarung unterschrieben">
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
