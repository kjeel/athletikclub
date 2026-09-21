<?php
/**
 * Athletikclub Steiermark – Dashboard Include (Sidebar)
 * Wird am Anfang aller Dashboard-Seiten eingebunden.
 */

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireLogin();

$user         = getCurrentUser();
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$initials     = strtoupper(substr($user['vorname'], 0, 1) . substr($user['nachname'], 0, 1));
$flash        = getFlashMessage();

$page_title = isset($page_title) ? e($page_title) . ' | Dashboard | ' . APP_NAME : 'Dashboard | ' . APP_NAME;

// Ungelesene Kontaktanfragen (nur Admin)
$unread_kontakt = 0;
if (isAdmin()) {
    try {
        $db = getDB();
        $unread_kontakt = (int)$db->query('SELECT COUNT(*) FROM kontakt_anfragen WHERE gelesen = 0')->fetchColumn();
    } catch (Exception $e) {}
}

// Offene Kursanmeldungen dieses Trainers
$pending_anmeldungen = 0;
if (isTrainer()) {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM kurs_anmeldungen ka
             JOIN kurse k ON ka.kurs_id = k.id
             WHERE k.trainer_id = ? AND ka.status = "angemeldet"'
        );
        $stmt->execute([$user['id']]);
        $pending_anmeldungen = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<body class="is-logged-in">

<!-- Flash Message -->
<?php if ($flash): ?>
<div class="flash-message flash-<?= e($flash['type']) ?>" id="flash-msg" role="alert">
    <span><?= e($flash['message']) ?></span>
    <button class="flash-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- Dashboard Header -->
<header class="site-header" id="site-header" style="position: sticky; top: 0; z-index: 1000;">
    <div class="header-inner container" style="max-width: 100%; padding-inline: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <!-- Mobile Sidebar Toggle -->
            <button class="nav-toggle" id="sidebar-toggle" aria-label="Sidebar öffnen" style="display: none;">
                <span></span><span></span><span></span>
            </button>
            <a href="<?= APP_URL ?>/" class="logo">
                <div class="logo-mark" style="width: 36px; height: 36px;">
                    <svg viewBox="0 0 50 50" fill="none"><circle cx="25" cy="25" r="23" stroke="#C6A135" stroke-width="2.5"/><path d="M14 34L25 14L36 34" stroke="#C6A135" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 28H32" stroke="#C6A135" stroke-width="2" stroke-linecap="round"/><circle cx="25" cy="14" r="2.5" fill="#C6A135"/></svg>
                </div>
                <div class="logo-text">
                    <span class="logo-name" style="font-size: 0.75rem;">ATHLETIKCLUB</span>
                    <span class="logo-sub">STEIERMARK</span>
                </div>
            </a>
            <!-- Breadcrumb -->
            <span style="color: rgba(255,255,255,0.3); font-size: 1.2rem; margin-left: 0.5rem;">/</span>
            <span style="font-family: 'Montserrat', sans-serif; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255,255,255,0.7);">
                <?= isset($breadcrumb) ? e($breadcrumb) : 'Dashboard' ?>
            </span>
        </div>

        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="<?= APP_URL ?>/" class="btn btn-ghost btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Website
            </a>
            <div class="user-chip has-dropdown">
                <div class="user-avatar"><?= $initials ?></div>
                <span class="user-name"><?= e($user['vorname']) ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                <ul class="dropdown-menu dropdown-right">
                    <li><a href="<?= APP_URL ?>/dashboard/profil.php">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Mein Profil
                    </a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="<?= APP_URL ?>/auth/logout.php" class="text-danger">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Abmelden
                    </a></li>
                </ul>
            </div>
        </div>
    </div>
</header>

<div class="dashboard-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <!-- User Info -->
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= $initials ?></div>
            <div class="sidebar-name"><?= e($user['vorname'] . ' ' . $user['nachname']) ?></div>
            <div class="sidebar-role">
                <span class="role-badge role-<?= e($user['rolle']) ?>"><?= ucfirst(e($user['rolle'])) ?></span>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="sidebar-nav" aria-label="Dashboard Navigation">

            <!-- Allgemein -->
            <span class="sidebar-section-label">Allgemein</span>
            <a href="<?= APP_URL ?>/dashboard/index.php" class="sidebar-link <?= strpos($current_path, '/dashboard/index') !== false || $current_path === '/dashboard/' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Übersicht
            </a>
            <a href="<?= APP_URL ?>/dashboard/profil.php" class="sidebar-link <?= strpos($current_path, '/dashboard/profil') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Mein Profil
            </a>
            <a href="<?= APP_URL ?>/dashboard/kurse.php" class="sidebar-link <?= strpos($current_path, '/dashboard/kurse') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Kurse
                <?php if ($pending_anmeldungen > 0): ?>
                    <span class="sidebar-link-badge"><?= $pending_anmeldungen ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= APP_URL ?>/dashboard/dokumente.php" class="sidebar-link <?= strpos($current_path, '/dashboard/dokumente') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Dokumente
            </a>

            <?php if (isTrainer()): ?>
            <!-- Trainer-Bereich -->
            <span class="sidebar-section-label" style="margin-top: 0.75rem;">Trainer</span>
            <a href="<?= APP_URL ?>/dashboard/mitglieder.php" class="sidebar-link <?= strpos($current_path, '/dashboard/mitglieder') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Mitglieder
            </a>
            <a href="<?= APP_URL ?>/dashboard/kurs-erstellen.php" class="sidebar-link <?= strpos($current_path, '/dashboard/kurs-erstellen') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Kurs erstellen
            </a>
            <?php endif; ?>

            <?php if (isAdmin()): ?>
            <!-- Admin-Bereich -->
            <span class="sidebar-section-label" style="margin-top: 0.75rem;">Administration</span>
            <a href="<?= APP_URL ?>/dashboard/admin/nutzerverwaltung.php" class="sidebar-link <?= strpos($current_path, '/admin/nutzerverwaltung') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Nutzerverwaltung
            </a>
            <a href="<?= APP_URL ?>/dashboard/admin/inhalte.php" class="sidebar-link <?= strpos($current_path, '/admin/inhalte') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Seiteninhalte
                <?php if ($unread_kontakt > 0): ?>
                    <span class="sidebar-link-badge"><?= $unread_kontakt ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </nav>

        <!-- Bottom: Logout -->
        <div class="sidebar-bottom">
            <a href="<?= APP_URL ?>/auth/logout.php" class="sidebar-link" style="color: var(--danger);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Abmelden
            </a>
        </div>
    </aside>

    <!-- MAIN CONTENT STARTS HERE -->
    <main class="dashboard-main" id="main-content">
