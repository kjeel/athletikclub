<?php
/**
 * Athletikclub Steiermark – Login
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(APP_URL . '/dashboard/index.php');
}

$errors = [];
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $email    = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);
    $email_value = $email;

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    }
    if (empty($password)) {
        $errors['password'] = 'Bitte gib dein Passwort ein.';
    }

    if (empty($errors)) {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !verifyPassword($password, $user['passwort_hash'])) {
                $errors['general'] = 'E-Mail oder Passwort ist falsch.';
            } elseif (!$user['aktiv']) {
                $errors['general'] = 'Dein Konto wurde deaktiviert. Bitte kontaktiere uns.';
            } elseif (!$user['email_verified']) {
                $errors['general'] = 'Bitte bestätige zunächst deine E-Mail-Adresse. Sieh in deinem Posteingang nach.';
            } else {
                loginUser($user);

                // Remember-me Cookie (30 Tage)
                if ($remember) {
                    $token = generateToken();
                    // In Produktion: Token in DB speichern für sicheres Remember-me
                    setcookie('remember_token', $token, time() + 60 * 60 * 24 * 30, '/', '', true, true);
                }

                logActivity('login', "Login: {$email}");

                $redirect = $_SESSION['redirect_after_login'] ?? (APP_URL . '/dashboard/index.php');
                unset($_SESSION['redirect_after_login']);
                redirect($redirect);
            }
        } catch (Exception $e) {
            $errors['general'] = 'Anmeldefehler. Bitte versuche es später erneut.';
        }
    }
}

$page_title = 'Anmelden | ' . APP_NAME;
$registered = isset($_GET['registered']);
$verified   = isset($_GET['verified']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>

<main class="auth-page">
    <div class="auth-card">

        <!-- Logo -->
        <div class="auth-card-header">
            <a href="<?= APP_URL ?>/" class="logo" style="justify-content: center; margin-bottom: 1.5rem;">
                <div class="logo-mark">
                    <svg viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="25" cy="25" r="23" stroke="#1F3556" stroke-width="2.5"/>
                        <path d="M14 34L25 14L36 34" stroke="#1F3556" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M18 28H32" stroke="#1F3556" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="25" cy="14" r="2.5" fill="#1F3556"/>
                    </svg>
                </div>
                <div class="logo-text">
                    <span class="logo-name" style="color: var(--text-primary);">ATHLETIKCLUB</span>
                    <span class="logo-sub">STEIERMARK</span>
                </div>
            </a>
            <h1 style="font-size: 1.75rem; margin-bottom: 0.375rem;">Willkommen zurück</h1>
            <p>Melde dich mit deinen Zugangsdaten an</p>
        </div>

        <!-- Success Messages -->
        <?php if ($registered): ?>
            <div class="flash-message flash-success" style="margin-bottom: 1.5rem; border-radius: 0.5rem;">
                <span>✅ Registrierung erfolgreich! Bitte bestätige deine E-Mail-Adresse.</span>
            </div>
        <?php endif; ?>
        <?php if ($verified): ?>
            <div class="flash-message flash-success" style="margin-bottom: 1.5rem; border-radius: 0.5rem;">
                <span>✅ E-Mail bestätigt! Du kannst dich jetzt anmelden.</span>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-error" style="margin-bottom: 1.5rem; border-radius: 0.5rem;">
                <span><?= e($errors['general']) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" data-validate id="login-form" novalidate>
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="email">E-Mail-Adresse</label>
                <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>"
                       type="email" id="email" name="email"
                       value="<?= e($email_value) ?>"
                       required autocomplete="email" placeholder="deine@email.at" autofocus>
                <?php if (isset($errors['email'])): ?>
                    <span class="form-error"><?= e($errors['email']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <label class="form-label" for="password" style="margin-bottom: 0;">Passwort</label>
                    <a href="<?= APP_URL ?>/auth/passwort-vergessen.php" style="font-size: 0.8rem; color: var(--text-primary);">Vergessen?</a>
                </div>
                <div class="input-group">
                    <input class="form-control <?= isset($errors['password']) ? 'error' : '' ?>"
                           type="password" id="password" name="password"
                           required autocomplete="current-password" placeholder="Dein Passwort">
                    <button type="button" class="input-group-btn" data-password-toggle="password" aria-label="Passwort anzeigen">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <span class="form-error"><?= e($errors['password']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="remember" id="remember" value="1">
                    <span class="form-check-label">Angemeldet bleiben</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary w-full btn-lg" id="login-submit">
                Anmelden
            </button>
        </form>

        <div class="auth-divider"><span>Noch kein Konto?</span></div>

        <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-ghost-light w-full">
            Jetzt Mitglied werden
        </a>
    </div>
</main>

<script src="<?= asset_url('/assets/js/main.js') ?>"></script>
</body>
</html>
