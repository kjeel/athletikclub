<?php
/**
 * Athletikclub Steiermark – Admin: Förderung erstellen
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

    $titel             = trim($_POST['titel'] ?? '');
    $foerderstelle     = trim($_POST['foerderstelle'] ?? '');
    $beschreibung      = trim($_POST['beschreibung'] ?? '');
    $betrag_beantragt  = trim($_POST['betrag_beantragt'] ?? '');
    $einreichfrist     = trim($_POST['einreichfrist'] ?? '');
    $nachweisfrist     = trim($_POST['nachweisfrist'] ?? '');
    $ansprechpartner_name    = trim($_POST['ansprechpartner_name'] ?? '');
    $ansprechpartner_email   = trim($_POST['ansprechpartner_email'] ?? '');
    $ansprechpartner_telefon = trim($_POST['ansprechpartner_telefon'] ?? '');

    if (mb_strlen($titel) < 3) $errors['titel'] = 'Titel muss mindestens 3 Zeichen haben.';
    if (mb_strlen($foerderstelle) < 2) $errors['foerderstelle'] = 'Förderstelle ist Pflichtfeld.';
    if ($betrag_beantragt !== '' && (!is_numeric($betrag_beantragt) || (float)$betrag_beantragt < 0)) {
        $errors['betrag_beantragt'] = 'Bitte einen gültigen Betrag eingeben.';
    }
    if ($ansprechpartner_email !== '' && !filter_var($ansprechpartner_email, FILTER_VALIDATE_EMAIL)) {
        $errors['ansprechpartner_email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare(
                'INSERT INTO foerderungen
                    (organization_id, titel, foerderstelle, beschreibung, betrag_beantragt, status,
                     einreichfrist, nachweisfrist, ansprechpartner_name, ansprechpartner_email, ansprechpartner_telefon, erstellt_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                currentOrgId(),
                $titel,
                $foerderstelle,
                $beschreibung ?: null,
                $betrag_beantragt !== '' ? (float)$betrag_beantragt : null,
                'geplant',
                $einreichfrist ?: null,
                $nachweisfrist ?: null,
                $ansprechpartner_name ?: null,
                $ansprechpartner_email ?: null,
                $ansprechpartner_telefon ?: null,
                $user['id'],
            ]);
            $neue_id = (int)$db->lastInsertId();

            logActivity('foerderung_erstellt', "Förderung-ID: {$neue_id}, Titel: {$titel}");
            flashMessage('success', 'Förderung erfolgreich angelegt!');
            redirect(APP_URL . '/dashboard/admin/foerderung-detail.php?id=' . $neue_id);
        } catch (Exception $e) {
            $errors['general'] = 'Förderung konnte nicht angelegt werden. Bitte versuche es später erneut.';
        }
    }
}

$page_title = 'Förderung erstellen';
$breadcrumb = 'Fördermanagement';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/admin/foerderungen.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Zurück zum Fördermanagement
    </a>
    <h1 class="dashboard-title">Förderung erstellen</h1>
    <p class="dashboard-subtitle">Neues Förderansuchen anlegen und im Verlauf verfolgen.</p>
</div>

<?php if (!empty($errors['general'])): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= e($errors['general']) ?></span>
</div>
<?php endif; ?>

<div class="form-card" style="max-width: 720px;">
    <form method="POST" action="" data-validate novalidate>
        <?= csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="titel">Titel <span class="required">*</span></label>
            <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" id="titel" name="titel" value="<?= e($_POST['titel'] ?? '') ?>" required placeholder="z.B. Nachwuchsförderung Land Steiermark 2026">
            <?php if (isset($errors['titel'])): ?><span class="form-error"><?= e($errors['titel']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="foerderstelle">Förderstelle <span class="required">*</span></label>
            <input class="form-control <?= isset($errors['foerderstelle']) ? 'error' : '' ?>" type="text" id="foerderstelle" name="foerderstelle" value="<?= e($_POST['foerderstelle'] ?? '') ?>" required placeholder="z.B. Land Steiermark, Sportunion, Gemeinde …">
            <?php if (isset($errors['foerderstelle'])): ?><span class="form-error"><?= e($errors['foerderstelle']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="beschreibung">Beschreibung</label>
            <textarea class="form-control" id="beschreibung" name="beschreibung" rows="3" placeholder="Wofür wird die Förderung beantragt?"><?= e($_POST['beschreibung'] ?? '') ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="betrag_beantragt">Beantragter Betrag (€)</label>
                <input class="form-control <?= isset($errors['betrag_beantragt']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="betrag_beantragt" name="betrag_beantragt" value="<?= e($_POST['betrag_beantragt'] ?? '') ?>">
                <?php if (isset($errors['betrag_beantragt'])): ?><span class="form-error"><?= e($errors['betrag_beantragt']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="einreichfrist">Einreichfrist</label>
                <input class="form-control" type="date" id="einreichfrist" name="einreichfrist" value="<?= e($_POST['einreichfrist'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="nachweisfrist">Frist für Verwendungsnachweis</label>
            <input class="form-control" type="date" id="nachweisfrist" name="nachweisfrist" value="<?= e($_POST['nachweisfrist'] ?? '') ?>">
            <p class="form-hint">Kann auch später ergänzt werden, sobald der Bescheid vorliegt.</p>
        </div>

        <h2 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 800; text-transform: uppercase; margin: 1.75rem 0 1rem;">Ansprechpartner bei der Förderstelle</h2>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="ansprechpartner_name">Name</label>
                <input class="form-control" type="text" id="ansprechpartner_name" name="ansprechpartner_name" value="<?= e($_POST['ansprechpartner_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="ansprechpartner_telefon">Telefon</label>
                <input class="form-control" type="text" id="ansprechpartner_telefon" name="ansprechpartner_telefon" value="<?= e($_POST['ansprechpartner_telefon'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="ansprechpartner_email">E-Mail</label>
            <input class="form-control <?= isset($errors['ansprechpartner_email']) ? 'error' : '' ?>" type="email" id="ansprechpartner_email" name="ansprechpartner_email" value="<?= e($_POST['ansprechpartner_email'] ?? '') ?>">
            <?php if (isset($errors['ansprechpartner_email'])): ?><span class="form-error"><?= e($errors['ansprechpartner_email']) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">Förderung anlegen</button>
    </form>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
