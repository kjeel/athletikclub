<?php
/**
 * Athletikclub Steiermark – Admin: Gemeinde-Kooperation erstellen
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db     = getDB();
$user   = getCurrentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

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

    if (mb_strlen($gemeinde_name) < 2) $errors['gemeinde_name'] = 'Gemeindename ist Pflichtfeld.';
    if (!in_array($dachverband, ['ASKÖ', 'ASVÖ', 'SPORTUNION'], true)) $dachverband = 'SPORTUNION';
    if ($gem_ap_email !== '' && !filter_var($gem_ap_email, FILTER_VALIDATE_EMAIL)) {
        $errors['gemeinde_ansprechpartner_email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    }
    if ($verein_ap_email !== '' && !filter_var($verein_ap_email, FILTER_VALIDATE_EMAIL)) {
        $errors['verein_ansprechpartner_email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare(
                'INSERT INTO kooperationen
                    (organization_id, gemeinde_name, gemeinde_adresse, buergermeister,
                     gemeinde_ansprechpartner_name, gemeinde_ansprechpartner_email, gemeinde_ansprechpartner_tel,
                     verein_ansprechpartner_name, verein_ansprechpartner_email, verein_ansprechpartner_tel,
                     dachverband, kooperationsbeginn, status, erstellt_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                currentOrgId(),
                $gemeinde_name,
                $gemeinde_adresse ?: null,
                $buergermeister ?: null,
                $gem_ap_name ?: null, $gem_ap_email ?: null, $gem_ap_tel ?: null,
                $verein_ap_name ?: null, $verein_ap_email ?: null, $verein_ap_tel ?: null,
                $dachverband,
                $kooperationsbeginn !== '' ? date('Y-m-01', strtotime($kooperationsbeginn . '-01')) : null,
                'entwurf',
                $user['id'],
            ]);
            $neue_id = (int)$db->lastInsertId();

            logActivity('kooperation_erstellt', "Kooperation-ID: {$neue_id}, Gemeinde: {$gemeinde_name}");
            flashMessage('success', 'Kooperation erfolgreich angelegt!');
            redirect(APP_URL . '/dashboard/admin/kooperation-detail.php?id=' . $neue_id);
        } catch (Exception $e) {
            $errors['general'] = 'Kooperation konnte nicht angelegt werden. Bitte versuche es später erneut.';
        }
    }
}

$page_title = 'Kooperation erstellen';
$breadcrumb = 'Gemeinde-Kooperationen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/admin/kooperationen.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Zurück zu den Kooperationen
    </a>
    <h1 class="dashboard-title">Gemeinde-Kooperation anlegen</h1>
    <p class="dashboard-subtitle">Stammdaten für die Kooperationsvereinbarung mit einer neuen Gemeinde.</p>
</div>

<?php if (!empty($errors['general'])): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= e($errors['general']) ?></span>
</div>
<?php endif; ?>

<div class="form-card" style="max-width: 720px;">
    <form method="POST" action="" data-validate novalidate>
        <?= csrfField() ?>

        <h2 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1rem;">Gemeinde</h2>

        <div class="form-group">
            <label class="form-label" for="gemeinde_name">Gemeindename <span class="required">*</span></label>
            <input class="form-control <?= isset($errors['gemeinde_name']) ? 'error' : '' ?>" type="text" id="gemeinde_name" name="gemeinde_name" value="<?= e($_POST['gemeinde_name'] ?? '') ?>" required>
            <?php if (isset($errors['gemeinde_name'])): ?><span class="form-error"><?= e($errors['gemeinde_name']) ?></span><?php endif; ?>
        </div>
        <div class="form-group">
            <label class="form-label" for="gemeinde_adresse">Adresse</label>
            <input class="form-control" type="text" id="gemeinde_adresse" name="gemeinde_adresse" value="<?= e($_POST['gemeinde_adresse'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="buergermeister">Bürgermeister*in</label>
            <input class="form-control" type="text" id="buergermeister" name="buergermeister" value="<?= e($_POST['buergermeister'] ?? '') ?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="gemeinde_ansprechpartner_name">Ansprechpartner*in Gemeinde</label>
                <input class="form-control" type="text" id="gemeinde_ansprechpartner_name" name="gemeinde_ansprechpartner_name" value="<?= e($_POST['gemeinde_ansprechpartner_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="gemeinde_ansprechpartner_tel">Telefon</label>
                <input class="form-control" type="text" id="gemeinde_ansprechpartner_tel" name="gemeinde_ansprechpartner_tel" value="<?= e($_POST['gemeinde_ansprechpartner_tel'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="gemeinde_ansprechpartner_email">E-Mail Ansprechpartner*in Gemeinde</label>
            <input class="form-control <?= isset($errors['gemeinde_ansprechpartner_email']) ? 'error' : '' ?>" type="email" id="gemeinde_ansprechpartner_email" name="gemeinde_ansprechpartner_email" value="<?= e($_POST['gemeinde_ansprechpartner_email'] ?? '') ?>">
            <?php if (isset($errors['gemeinde_ansprechpartner_email'])): ?><span class="form-error"><?= e($errors['gemeinde_ansprechpartner_email']) ?></span><?php endif; ?>
        </div>

        <h2 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 800; text-transform: uppercase; margin: 1.75rem 0 1rem;">Verein – Ansprechpartner*in für diese Kooperation</h2>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="verein_ansprechpartner_name">Name</label>
                <input class="form-control" type="text" id="verein_ansprechpartner_name" name="verein_ansprechpartner_name" value="<?= e($_POST['verein_ansprechpartner_name'] ?? $user['vorname'] . ' ' . $user['nachname']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="verein_ansprechpartner_tel">Telefon</label>
                <input class="form-control" type="text" id="verein_ansprechpartner_tel" name="verein_ansprechpartner_tel" value="<?= e($_POST['verein_ansprechpartner_tel'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="verein_ansprechpartner_email">E-Mail</label>
            <input class="form-control <?= isset($errors['verein_ansprechpartner_email']) ? 'error' : '' ?>" type="email" id="verein_ansprechpartner_email" name="verein_ansprechpartner_email" value="<?= e($_POST['verein_ansprechpartner_email'] ?? $user['email']) ?>">
            <?php if (isset($errors['verein_ansprechpartner_email'])): ?><span class="form-error"><?= e($errors['verein_ansprechpartner_email']) ?></span><?php endif; ?>
        </div>

        <h2 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 800; text-transform: uppercase; margin: 1.75rem 0 1rem;">Kooperation</h2>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="dachverband">Dachverband</label>
                <select class="form-control" id="dachverband" name="dachverband">
                    <option value="SPORTUNION" <?= (($_POST['dachverband'] ?? 'SPORTUNION') === 'SPORTUNION') ? 'selected' : '' ?>>SPORTUNION</option>
                    <option value="ASKÖ" <?= (($_POST['dachverband'] ?? '') === 'ASKÖ') ? 'selected' : '' ?>>ASKÖ</option>
                    <option value="ASVÖ" <?= (($_POST['dachverband'] ?? '') === 'ASVÖ') ? 'selected' : '' ?>>ASVÖ</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="kooperationsbeginn">Kooperationsbeginn</label>
                <input class="form-control" type="month" id="kooperationsbeginn" name="kooperationsbeginn" value="<?= e($_POST['kooperationsbeginn'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">Kooperation anlegen</button>
    </form>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
