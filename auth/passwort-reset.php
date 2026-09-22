<?php
/**
 * Athletikclub Steiermark – Neues Passwort setzen
 * Wird sowohl für "Passwort vergessen" als auch für Admin-Einladungen genutzt.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

if (isLoggedIn()) redirect(APP_URL . '/dashboard/index.php');

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors  = [];
$user    = null;

if (empty($token) || mb_strlen($token) !== 64) {
    $errors['general'] = 'Ungültiger oder abgelaufener Link.';
} else {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, vorname, nachname, email, rolle, organization_id FROM users WHERE reset_token = ? AND reset_token_exp > NOW() LIMIT 1');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors['general'] = 'Ungültiger oder abgelaufener Link. Fordere ggf. einen neuen an.';
        }
    } catch (Exception $e) {
        $errors['general'] = 'Technischer Fehler. Bitte versuche es später erneut.';
    }
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (mb_strlen($password) < 8)
        $errors['password'] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';

    if ($password !== $password_confirm)
        $errors['password_confirm'] = 'Die Passwörter stimmen nicht überein.';

    if (empty($errors)) {
        try {
            $db->prepare(
                'UPDATE users SET passwort_hash = ?, reset_token = NULL, reset_token_exp = NULL, email_verified = 1 WHERE id = ?'
            )->execute([hashPassword($password), $user['id']]);

            logActivity('passwort_gesetzt', "User-ID: {$user['id']}");

            loginUser($user);
            flashMessage('success', 'Dein Passwort wurde gesetzt. Willkommen, ' . $user['vorname'] . '!');
            redirect(APP_URL . '/dashboard/index.php');
        } catch (Exception $e) {
            $errors['general'] = 'Fehler beim Speichern. Bitte versuche es später erneut.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort setzen | <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800;900&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<main class="auth-page">
    <div class="auth-card">
        <div class="auth-card-header">
            <a href="<?= APP_URL ?>/" class="logo" style="justify-content: center; margin-bottom: 1.5rem;">
                <div class="logo-mark">
                    <svg viewBox="0 0 50 50" fill="none"><circle cx="25" cy="25" r="23" stroke="#1F3556" stroke-width="2.5"/><path d="M14 34L25 14L36 34" stroke="#1F3556" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 28H32" stroke="#1F3556" stroke-width="2" stroke-linecap="round"/><circle cx="25" cy="14" r="2.5" fill="#1F3556"/></svg>
                </div>
                <div class="logo-text">
                    <span class="logo-name" style="color: var(--navy-primary);">ATHLETIKCLUB</span>
                    <span class="logo-sub">STEIERMARK</span>
                </div>
            </a>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.375rem;">
                <?= $user ? 'Willkommen, ' . e($user['vorname']) . '!' : 'Passwort setzen' ?>
            </h1>
            <p><?= $user ? 'Lege ein Passwort für dein neues Konto fest.' : 'Bitte fordere einen neuen Link an.' ?></p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-error" style="border-radius: 0.5rem; margin-bottom: 1.5rem;">
                <span><?= e($errors['general']) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($user): ?>
        <form method="POST" action="" data-validate novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="form-group">
                <label class="form-label" for="password">Neues Passwort <span class="required">*</span></label>
                <div class="input-group">
                    <input class="form-control <?= isset($errors['password']) ? 'error' : '' ?>"
                           type="password" id="password" name="password"
                           required autocomplete="new-password" placeholder="Mindestens 8 Zeichen" autofocus>
                    <button type="button" class="input-group-btn" data-password-toggle="password" aria-label="Passwort anzeigen">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" data-feather="eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <span class="form-hint">Mind. 8 Zeichen</span>
                <?php if (isset($errors['password'])): ?>
                    <span class="form-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirm">Passwort bestätigen <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                       type="password" id="password_confirm" name="password_confirm"
                       required autocomplete="new-password" placeholder="Passwort wiederholen">
                <?php if (isset($errors['password_confirm'])): ?>
                    <span class="form-error"><?= e($errors['password_confirm']) ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary w-full btn-lg">Passwort speichern</button>
        </form>
        <?php else: ?>
            <a href="<?= APP_URL ?>/auth/passwort-vergessen.php" class="btn btn-primary w-full btn-lg">Neuen Link anfordern</a>
        <?php endif; ?>

        <p class="auth-footer" style="margin-top: 1.5rem;">
            <a href="<?= APP_URL ?>/auth/login.php">← Zurück zum Login</a>
        </p>
    </div>
</main>
<script src="<?= asset_url('/assets/js/main.js') ?>"></script>
</body>
</html>
