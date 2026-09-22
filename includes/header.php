<?php
/**
 * Athletikclub Steiermark – Globaler Header / Navigation
 *
 * Verwendung: require_once ROOT_PATH . '/includes/header.php';
 * Vorher muss $page_title gesetzt sein.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

$current_user = getCurrentUser();
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$flash        = getFlashMessage();

$page_title = isset($page_title) ? e($page_title) . ' | ' . APP_NAME : APP_NAME;
$meta_desc  = isset($meta_description) ? e($meta_description) : 'Athletikclub Steiermark – ganzheitliches Athletik- und polysportives Training in St. Georgen an der Stiefing.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $meta_desc ?>">
    <meta name="theme-color" content="#1F3556">

    <!-- Open Graph -->
    <meta property="og:title"       content="<?= $page_title ?>">
    <meta property="og:description" content="<?= $meta_desc ?>">
    <meta property="og:type"        content="website">
    <meta property="og:url"         content="<?= APP_URL . $current_path ?>">

    <title><?= $page_title ?></title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600&family=Playfair+Display:ital,wght@1,400;1,600&display=swap" rel="stylesheet">

    <!-- Icons (Feather Icons via CDN) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css">

    <!-- Haupt-CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<body class="<?= isLoggedIn() ? 'is-logged-in' : '' ?>">

<!-- ============================================================
     Skip-to-Content (Accessibility)
============================================================ -->
<a href="#main-content" class="skip-link">Zum Inhalt springen</a>

<!-- ============================================================
     Flash-Nachrichten
============================================================ -->
<?php if ($flash): ?>
<div class="flash-message flash-<?= e($flash['type']) ?>" role="alert" id="flash-msg">
    <span><?= e($flash['message']) ?></span>
    <button class="flash-close" onclick="this.parentElement.remove()" aria-label="Schließen">×</button>
</div>
<?php endif; ?>

<!-- ============================================================
     HEADER / NAVIGATION
============================================================ -->
<header class="site-header" id="site-header">
    <div class="header-inner container">

        <!-- Logo -->
        <a href="<?= APP_URL ?>/" class="logo" aria-label="Athletikclub Steiermark – Startseite">
            <div class="logo-mark">
                <svg viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="25" cy="25" r="23" stroke="#C6A135" stroke-width="2.5"/>
                    <path d="M14 34L25 14L36 34" stroke="#C6A135" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M18 28H32" stroke="#C6A135" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="25" cy="14" r="2.5" fill="#C6A135"/>
                </svg>
            </div>
            <div class="logo-text">
                <span class="logo-name">ATHLETIKCLUB</span>
                <span class="logo-sub">STEIERMARK</span>
            </div>
        </a>

        <!-- Desktop Nav -->
        <nav class="main-nav" id="main-nav" aria-label="Hauptnavigation">
            <ul class="nav-list">
                <li><a href="<?= APP_URL ?>/" class="nav-link <?= ($current_path === '/' || $current_path === '/index.php') ? 'active' : '' ?>">Start</a></li>
                <li class="has-dropdown">
                    <a href="#" class="nav-link nav-dropdown-trigger">Verein <i data-feather="chevron-down"></i></a>
                    <ul class="dropdown-menu">
                        <li><a href="<?= APP_URL ?>/pages/vision.php">Vision</a></li>
                        <li><a href="<?= APP_URL ?>/pages/mission.php">Mission</a></li>
                        <li><a href="<?= APP_URL ?>/pages/leitbild.php">Leitbild</a></li>
                        <li><a href="<?= APP_URL ?>/pages/team.php">Team &amp; Präsidium</a></li>
                        <li><a href="<?= APP_URL ?>/pages/partner.php">Partner*innen</a></li>
                    </ul>
                </li>
                <li><a href="<?= APP_URL ?>/pages/leistung.php" class="nav-link">Leistung</a></li>
                <li><a href="<?= APP_URL ?>/pages/trainer-werden.php" class="nav-link">Trainer*in werden</a></li>
                <li><a href="<?= APP_URL ?>/pages/mitglied-werden.php" class="nav-link">Mitglied werden</a></li>
                <li><a href="<?= APP_URL ?>/pages/kontakt.php" class="nav-link">Kontakt</a></li>
            </ul>
        </nav>

        <!-- Auth-Bereich -->
        <div class="header-actions">
            <?php if (isLoggedIn() && $current_user): ?>
                <a href="<?= APP_URL ?>/dashboard/index.php" class="btn btn-ghost btn-sm">
                    <i data-feather="grid"></i>
                    Dashboard
                </a>
                <div class="user-chip has-dropdown">
                    <div class="user-avatar"><?= strtoupper(substr($current_user['vorname'], 0, 1) . substr($current_user['nachname'], 0, 1)) ?></div>
                    <span class="user-name"><?= e($current_user['vorname']) ?></span>
                    <i data-feather="chevron-down"></i>
                    <ul class="dropdown-menu dropdown-right">
                        <li><a href="<?= APP_URL ?>/dashboard/profil.php"><i data-feather="user"></i> Mein Profil</a></li>
                        <?php if (isTrainer()): ?>
                        <li><a href="<?= APP_URL ?>/dashboard/mitglieder.php"><i data-feather="users"></i> Mitglieder</a></li>
                        <li><a href="<?= APP_URL ?>/dashboard/kurse.php"><i data-feather="calendar"></i> Kurse</a></li>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                        <li class="dropdown-divider"></li>
                        <li><a href="<?= APP_URL ?>/dashboard/admin/nutzerverwaltung.php"><i data-feather="settings"></i> Administration</a></li>
                        <?php endif; ?>
                        <li class="dropdown-divider"></li>
                        <li><a href="<?= APP_URL ?>/auth/logout.php" class="text-danger"><i data-feather="log-out"></i> Abmelden</a></li>
                    </ul>
                </div>
            <?php else: ?>
                <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-ghost btn-sm">Anmelden</a>
                <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-primary btn-sm">Mitglied werden</a>
            <?php endif; ?>

            <!-- Mobile Hamburger -->
            <button class="nav-toggle" id="nav-toggle" aria-label="Navigation öffnen" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>

<!-- Mobile Nav Overlay -->
<div class="mobile-nav-overlay" id="mobile-nav-overlay"></div>
<nav class="mobile-nav" id="mobile-nav" aria-label="Mobile Navigation">
    <div class="mobile-nav-header">
        <span class="logo-name">ATHLETIKCLUB</span>
        <button class="mobile-nav-close" id="mobile-nav-close" aria-label="Menü schließen">×</button>
    </div>
    <ul class="mobile-nav-list">
        <li><a href="<?= APP_URL ?>/">Start</a></li>
        <li class="mobile-nav-group">
            <span class="mobile-nav-label">Verein</span>
            <ul>
                <li><a href="<?= APP_URL ?>/pages/vision.php">Vision</a></li>
                <li><a href="<?= APP_URL ?>/pages/mission.php">Mission</a></li>
                <li><a href="<?= APP_URL ?>/pages/leitbild.php">Leitbild</a></li>
                <li><a href="<?= APP_URL ?>/pages/team.php">Team</a></li>
                <li><a href="<?= APP_URL ?>/pages/partner.php">Partner*innen</a></li>
            </ul>
        </li>
        <li><a href="<?= APP_URL ?>/pages/leistung.php">Leistung</a></li>
        <li><a href="<?= APP_URL ?>/pages/trainer-werden.php">Trainer*in werden</a></li>
        <li><a href="<?= APP_URL ?>/pages/mitglied-werden.php">Mitglied werden</a></li>
        <li><a href="<?= APP_URL ?>/pages/kontakt.php">Kontakt</a></li>
        <li class="mobile-nav-divider"></li>
        <?php if (isLoggedIn()): ?>
            <li><a href="<?= APP_URL ?>/dashboard/index.php">Dashboard</a></li>
            <li><a href="<?= APP_URL ?>/auth/logout.php">Abmelden</a></li>
        <?php else: ?>
            <li><a href="<?= APP_URL ?>/auth/login.php" class="btn btn-ghost w-full">Anmelden</a></li>
            <li><a href="<?= APP_URL ?>/auth/register.php" class="btn btn-primary w-full">Mitglied werden</a></li>
        <?php endif; ?>
    </ul>
</nav>

<!-- Main Content -->
<main id="main-content">
