<?php
/**
 * Athletikclub Steiermark – News als gelesen markieren
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $db   = getDB();
    $user = getCurrentUser();

    $zielgruppen = isTrainer() ? ['trainer', 'alle'] : ['alle'];
    $platzhalter = implode(',', array_fill(0, count($zielgruppen), '?'));

    $stmt = $db->prepare("SELECT id FROM news WHERE organization_id = ? AND zielgruppe IN ({$platzhalter})");
    $stmt->execute([currentOrgId(), ...$zielgruppen]);
    $news_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($news_ids) {
        $insert = $db->prepare('INSERT IGNORE INTO news_gelesen (news_id, user_id) VALUES (?, ?)');
        foreach ($news_ids as $news_id) {
            $insert->execute([$news_id, $user['id']]);
        }
    }
}

// Nur relative Pfade innerhalb der eigenen App zulassen (Open-Redirect vermeiden)
$redirect_path = $_POST['redirect'] ?? '/dashboard/index.php';
if (!str_starts_with($redirect_path, '/') || str_starts_with($redirect_path, '//') || str_contains($redirect_path, '://')) {
    $redirect_path = '/dashboard/index.php';
}
redirect(APP_URL . $redirect_path);
