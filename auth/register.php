<?php
/**
 * Athletikclub Steiermark – Registrierung
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

// Bereits eingeloggt → Dashboard
if (isLoggedIn()) {
    redirect(APP_URL . '/dashboard/index.php');
}

$errors = [];
$values = ['vorname' => '', 'nachname' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $vorname  = trim($_POST['vorname']  ?? '');
    $nachname = trim($_POST['nachname'] ?? '');
    $email    = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $agb = $_POST['agb'] ?? '';

    $values = compact('vorname', 'nachname', 'email');

    // Validierung
    if (empty($vorname) || mb_strlen($vorname) < 2)
        $errors['vorname'] = 'Vorname muss mindestens 2 Zeichen haben.';

    if (empty($nachname) || mb_strlen($nachname) < 2)
        $errors['nachname'] = 'Nachname muss mindestens 2 Zeichen haben.';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';

    if (mb_strlen($password) < 8)
        $errors['password'] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';

    if ($password !== $password_confirm)
        $errors['password_confirm'] = 'Die Passwörter stimmen nicht überein.';

    if (empty($agb))
        $errors['agb'] = 'Bitte akzeptiere die Datenschutzerklärung.';

    // E-Mail bereits vergeben?
    if (empty($errors['email'])) {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors['email'] = 'Diese E-Mail-Adresse ist bereits registriert.';
            }
        } catch (Exception $e) {
            $errors['general'] = 'Datenbankfehler. Bitte versuche es später erneut.';
        }
    }

    // Benutzer anlegen
    if (empty($errors)) {
        try {
            $db            = getDB();
            $verify_token  = generateToken(32);
            $password_hash = hashPassword($password);

            $stmt = $db->prepare(
                'INSERT INTO users (organization_id, vorname, nachname, email, passwort_hash, rolle, email_verified, verify_token)
                 VALUES (1, ?, ?, ?, ?, ?, 0, ?)'
            );
            $stmt->execute([$vorname, $nachname, $email, $password_hash, 'mitglied', $verify_token]);
            $user_id = (int)$db->lastInsertId();

            // Mitglieder-Profil automatisch anlegen
            $db->prepare('INSERT INTO mitglieder_profile (organization_id, user_id, mitglied_seit) VALUES (1, ?, NOW())')
               ->execute([$user_id]);

            // RBAC-Rolle zuordnen (CUSTOMER)
            $db->prepare(
                "INSERT INTO user_roles (user_id, role_id, organization_id)
                 SELECT ?, id, 1 FROM roles WHERE code = 'CUSTOMER'"
            )->execute([$user_id]);

            // Verifizierungs-E-Mail senden
            $verify_url = APP_URL . '/auth/verify.php?token=' . $verify_token;
            $subject    = 'E-Mail-Adresse bestätigen bei ' . APP_NAME;
            $message    = "Hallo {$vorname},\n\n"
                        . "vielen Dank für deine Registrierung beim Athletikclub Steiermark!\n\n"
                        . "Bitte bestätige deine E-Mail-Adresse durch Klick auf folgenden Link:\n"
                        . $verify_url . "\n\n"
                        . "Der Link ist 48 Stunden gültig.\n\n"
                        . "Sportliche Grüße,\nDas Athletikclub-Steiermark-Team";
            $headers = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
            @mail($email, $subject, $message, $headers);

            logActivity('registrierung', "Neues Mitglied: {$email}");

            flashMessage('success', 'Registrierung erfolgreich! Bitte bestätige deine E-Mail-Adresse.');
            redirect(APP_URL . '/auth/login.php?registered=1');
        } catch (Exception $e) {
            $errors['general'] = 'Registrierung fehlgeschlagen. Bitte versuche es später erneut.';
        }
    }
}

$page_title = 'Registrierung | ' . APP_NAME;
$meta_description = 'Werde Mitglied beim Athletikclub Steiermark, jetzt registrieren.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($meta_description) ?>">
    <title><?= e($page_title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>

<main class="auth-page">
    <div class="auth-card" style="max-width: 540px;">

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
                    <span class="logo-name" style="color: var(--navy-primary);">ATHLETIKCLUB</span>
                    <span class="logo-sub">STEIERMARK</span>
                </div>
            </a>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.375rem;">Jetzt registrieren</h1>
            <p>Werde Mitglied und starte dein Training</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="flash-message flash-error" style="margin-bottom: 1.5rem; border-radius: 0.5rem;">
                <span><?= e($errors['general']) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" data-validate id="register-form" novalidate>
            <?= csrfField() ?>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="vorname">Vorname <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['vorname']) ? 'error' : '' ?>"
                           type="text" id="vorname" name="vorname"
                           value="<?= e($values['vorname']) ?>"
                           required autocomplete="given-name" placeholder="Max">
                    <?php if (isset($errors['vorname'])): ?>
                        <span class="form-error"><?= e($errors['vorname']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="nachname">Nachname <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['nachname']) ? 'error' : '' ?>"
                           type="text" id="nachname" name="nachname"
                           value="<?= e($values['nachname']) ?>"
                           required autocomplete="family-name" placeholder="Mustermann">
                    <?php if (isset($errors['nachname'])): ?>
                        <span class="form-error"><?= e($errors['nachname']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">E-Mail-Adresse <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>"
                       type="email" id="email" name="email"
                       value="<?= e($values['email']) ?>"
                       required autocomplete="email" placeholder="max@beispiel.at">
                <?php if (isset($errors['email'])): ?>
                    <span class="form-error"><?= e($errors['email']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Passwort <span class="required">*</span></label>
                <div class="input-group">
                    <input class="form-control <?= isset($errors['password']) ? 'error' : '' ?>"
                           type="password" id="password" name="password"
                           required autocomplete="new-password" placeholder="Mindestens 8 Zeichen">
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
                <div class="input-group">
                    <input class="form-control <?= isset($errors['password_confirm']) ? 'error' : '' ?>"
                           type="password" id="password_confirm" name="password_confirm"
                           required autocomplete="new-password" placeholder="Passwort wiederholen">
                    <button type="button" class="input-group-btn" data-password-toggle="password_confirm" aria-label="Passwort anzeigen">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" data-feather="eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <?php if (isset($errors['password_confirm'])): ?>
                    <span class="form-error"><?= e($errors['password_confirm']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" name="agb" id="agb" value="1" <?= !empty($_POST['agb']) ? 'checked' : '' ?>>
                    <span class="form-check-label">
                        Ich akzeptiere die
                        <a href="/pages/datenschutz.php" target="_blank">Datenschutzerklärung</a>
                        und stimme der Verarbeitung meiner Daten zu.
                        <span class="required">*</span>
                    </span>
                </label>
                <?php if (isset($errors['agb'])): ?>
                    <span class="form-error"><?= e($errors['agb']) ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary w-full btn-lg" id="register-submit">
                Konto erstellen
            </button>
        </form>

        <div class="auth-divider">
            <span>oder</span>
        </div>

        <p class="auth-footer">
            Bereits Mitglied? <a href="<?= APP_URL ?>/auth/login.php">Jetzt anmelden</a>
        </p>
    </div>
</main>

<script src="<?= asset_url('/assets/js/main.js') ?>"></script>
</body>
</html>
