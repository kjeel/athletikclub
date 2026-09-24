<?php
/**
 * Athletikclub Steiermark – E-Mail-Verifizierung
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

$token  = trim($_GET['token'] ?? '');
$status = 'error';
$msg    = 'Ungültiger oder abgelaufener Bestätigungslink.';

if (!empty($token) && mb_strlen($token) === 64) {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, vorname, email_verified FROM users WHERE verify_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['email_verified']) {
                $status = 'already';
                $msg    = 'Deine E-Mail-Adresse wurde bereits bestätigt.';
            } else {
                $db->prepare(
                    'UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?'
                )->execute([$user['id']]);

                logActivity('email_verifiziert', "User-ID: {$user['id']}");

                $status = 'success';
                $msg    = 'Deine E-Mail-Adresse wurde erfolgreich bestätigt!';
            }
        }
    } catch (Exception $e) {
        $msg = 'Technischer Fehler. Bitte versuche es später erneut.';
    }
}

if ($status === 'success') {
    flashMessage('success', 'E-Mail bestätigt! Sobald wir dein Konto freigegeben haben, kannst du dich anmelden.');
    redirect(APP_URL . '/auth/login.php?verified=1');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Mail-Bestätigung | <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800;900&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<main class="auth-page">
    <div class="auth-card" style="text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 1rem;"><?= $status === 'already' ? '✅' : '❌' ?></div>
        <h1 style="font-size: 1.5rem; margin-bottom: 0.75rem;">E-Mail-Bestätigung</h1>
        <p style="margin-bottom: 1.5rem;"><?= e($msg) ?></p>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary">Zum Login</a>
    </div>
</main>
</body>
</html>
