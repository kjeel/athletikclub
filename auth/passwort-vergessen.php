<?php
/**
 * Athletikclub Steiermark – Passwort vergessen
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

if (isLoggedIn()) redirect(APP_URL . '/dashboard/index.php');

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $email = trim(strtolower($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND aktiv = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = generateToken(32);
                $exp   = date('Y-m-d H:i:s', strtotime('+2 hours'));
                $db->prepare('UPDATE users SET reset_token = ?, reset_token_exp = ? WHERE id = ?')
                   ->execute([$token, $exp, $user['id']]);

                $reset_url = APP_URL . '/auth/passwort-reset.php?token=' . $token;
                $subject   = 'Passwort zurücksetzen bei ' . APP_NAME;
                $message   = "Hallo,\n\ndu hast eine Passwortzurücksetzung angefordert.\n\n"
                           . "Klicke auf folgenden Link (gültig für 2 Stunden):\n"
                           . $reset_url . "\n\n"
                           . "Falls du dies nicht angefordert hast, ignoriere diese E-Mail.\n\n"
                           . "Sportliche Grüße,\nDas Athletikclub-Steiermark-Team";
                @mail($email, $subject, $message, 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>');
            }

            // Immer success anzeigen (verhindert E-Mail-Enumeration)
            $success = true;
        } catch (Exception $e) {
            $errors['general'] = 'Fehler. Bitte versuche es später erneut.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort vergessen | <?= APP_NAME ?></title>
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
            <h1 style="font-size: 1.5rem; margin-bottom: 0.375rem;">Passwort zurücksetzen</h1>
            <p>Gib deine E-Mail-Adresse ein und wir senden dir einen Reset-Link.</p>
        </div>

        <?php if ($success): ?>
            <div class="flash-message flash-success" style="border-radius: 0.5rem; margin-bottom: 1.5rem;">
                <span>✅ Falls ein Konto mit dieser E-Mail existiert, erhältst du in Kürze einen Reset-Link.</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-error" style="border-radius: 0.5rem; margin-bottom: 1.5rem;">
                <span><?= e($errors['general']) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST" action="" data-validate novalidate>
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label" for="email">E-Mail-Adresse</label>
                <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>"
                       type="email" id="email" name="email"
                       required autocomplete="email" placeholder="deine@email.at" autofocus>
                <?php if (isset($errors['email'])): ?>
                    <span class="form-error"><?= e($errors['email']) ?></span>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary w-full btn-lg">Reset-Link senden</button>
        </form>
        <?php endif; ?>

        <p class="auth-footer" style="margin-top: 1.5rem;">
            <a href="<?= APP_URL ?>/auth/login.php">← Zurück zum Login</a>
        </p>
    </div>
</main>
<script src="<?= asset_url('/assets/js/main.js') ?>"></script>
</body>
</html>
