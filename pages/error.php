<?php
/**
 * Athletikclub Steiermark – Generische Fehlerseite
 */
$code = (int)($_GET['code'] ?? 404);
$messages = [
    403 => ['title' => 'Zugriff verweigert', 'desc' => 'Du hast keine Berechtigung, diese Seite aufzurufen.'],
    404 => ['title' => 'Seite nicht gefunden', 'desc' => 'Die gesuchte Seite existiert nicht oder wurde verschoben.'],
    500 => ['title' => 'Serverfehler', 'desc' => 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuche es später erneut.'],
];
$msg = $messages[$code] ?? $messages[404];
http_response_code($code);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
if (file_exists(ROOT_PATH . '/config/config.php')) require_once ROOT_PATH . '/config/config.php';
$app_url = defined('APP_URL') ? APP_URL : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $code ?> – <?= $msg['title'] ?> | Athletikclub Steiermark</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800;900&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $app_url ?>/assets/css/style.css">
</head>
<body>
<div style="
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem;
    background: linear-gradient(135deg, #0D1F35, #1F3556);
    color: white;
">
    <div style="font-family: 'Montserrat', sans-serif; font-size: 8rem; font-weight: 900; line-height: 1; color: #C6A135; opacity: 0.4;"><?= $code ?></div>
    <h1 style="font-family: 'Montserrat', sans-serif; font-size: 2rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: white; margin-bottom: 0.75rem;"><?= $msg['title'] ?></h1>
    <p style="color: rgba(255,255,255,0.65); max-width: 400px; margin-bottom: 2rem; line-height: 1.7;"><?= $msg['desc'] ?></p>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center;">
        <a href="<?= $app_url ?>/" class="btn btn-primary">Zur Startseite</a>
        <a href="javascript:history.back()" class="btn btn-ghost">Zurück</a>
    </div>
</div>
</body>
</html>
