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
        $stmt = $db->prepare('SELECT COUNT(*) FROM kontakt_anfragen WHERE gelesen = 0 AND organization_id = ?');
        $stmt->execute([currentOrgId()]);
        $unread_kontakt = (int)$stmt->fetchColumn();
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
             WHERE k.trainer_id = ? AND ka.status = "angemeldet" AND k.organization_id = ?'
        );
        $stmt->execute([$user['id'], currentOrgId()]);
        $pending_anmeldungen = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
}

// Ungelesene News (Popup)
$ungelesene_news = [];
try {
    $db = getDB();
    $zielgruppen = isTrainer() ? ['trainer', 'alle'] : ['alle'];
    $platzhalter = implode(',', array_fill(0, count($zielgruppen), '?'));
    $stmt = $db->prepare(
        "SELECT n.* FROM news n
         WHERE n.organization_id = ? AND n.zielgruppe IN ({$platzhalter})
           AND NOT EXISTS (SELECT 1 FROM news_gelesen ng WHERE ng.news_id = n.id AND ng.user_id = ?)
         ORDER BY n.created_at ASC"
    );
    $stmt->execute([currentOrgId(), ...$zielgruppen, $user['id']]);
    $ungelesene_news = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <script>
    (function () {
        try {
            var t = localStorage.getItem('aci-theme');
            if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $page_title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
    <?php require ROOT_PATH . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/dashboard.css') ?>">
    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<body class="is-logged-in">

<!-- News-Popup -->
<?php if (!empty($ungelesene_news)): ?>
<div style="
    position: fixed; inset: 0; z-index: 3000;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
    background: rgba(13,31,53,0.6); backdrop-filter: blur(4px);
">
    <div style="
        background: var(--surface); border-radius: 1.25rem; width: 100%; max-width: 520px;
        max-height: 85vh; overflow-y: auto;
        box-shadow: 0 25px 50px rgba(0,0,0,0.25);
    ">
        <div style="padding: 1.5rem 1.75rem; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 0.75rem; background: var(--gold-dim); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
            <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.1rem; font-weight: 800; text-transform: uppercase; margin: 0;">
                <?= count($ungelesene_news) > 1 ? count($ungelesene_news) . ' neue Neuigkeiten' : 'Neuigkeit' ?>
            </h2>
        </div>
        <div style="padding: 1.5rem 1.75rem; display: flex; flex-direction: column; gap: 1.5rem;">
            <?php foreach ($ungelesene_news as $n): ?>
                <div>
                    <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.4rem;"><?= e($n['titel']) ?></h3>
                    <p style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.6rem;"><?= date('d.m.Y', strtotime($n['created_at'])) ?></p>
                    <div style="font-size: 0.9rem; line-height: 1.7;"><?= nl2br(e($n['inhalt'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="padding: 1.25rem 1.75rem; border-top: 1px solid var(--border-light);">
            <form method="POST" action="<?= APP_URL ?>/dashboard/news-gelesen.php">
                <?= csrfField() ?>
                <input type="hidden" name="redirect" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="btn btn-primary w-full">Verstanden</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Flash Message -->
<?php if ($flash): ?>
<div class="flash-message flash-<?= e($flash['type']) ?>" id="flash-msg" role="alert">
    <span><?= e($flash['message']) ?></span>
    <button class="flash-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- Dashboard Header -->
<header class="site-header" id="site-header" style="position: sticky; top: 0; z-index: 1000;">
    <div class="header-inner container dash-header-inner" style="max-width: 100%;">
        <div class="dash-header-left">
            <!-- Mobile Sidebar Toggle -->
            <button type="button" class="nav-toggle" id="sidebar-toggle" aria-label="Menü öffnen" aria-controls="sidebar" aria-expanded="false">
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
            <span class="dash-breadcrumb-sep" style="color: rgba(255,255,255,0.3); font-size: 1.2rem; margin-left: 0.5rem;">/</span>
            <span class="dash-breadcrumb" style="font-family: 'Montserrat', sans-serif; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255,255,255,0.7);">
                <?= isset($breadcrumb) ? e($breadcrumb) : 'Dashboard' ?>
            </span>
        </div>

        <div class="dash-header-actions">
            <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Farbschema wechseln" title="Hell/Dunkel umschalten">
                <svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                <svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <a href="<?= APP_URL ?>/" class="btn btn-ghost btn-sm dash-website-btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Website
            </a>
            <div class="user-chip has-dropdown" tabindex="0" role="button" aria-haspopup="true" aria-expanded="false" aria-label="Benutzermenü">
                <div class="user-avatar"><?= $initials ?></div>
                <span class="user-name"><?= e($user['vorname']) ?></span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                <ul class="dropdown-menu dropdown-right">
                    <li class="dropdown-nur-mobil"><a href="<?= APP_URL ?>/">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        Zur Website
                    </a></li>
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
            <?php $plaene_aktiv = preg_match('#/dashboard/(plaene|trainingsplan|ernaehrungsplan)#', $current_path); ?>
            <?php if (!isTrainer()): ?>
            <a href="<?= APP_URL ?>/dashboard/plaene.php" class="sidebar-link <?= $plaene_aktiv ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6.5 6.5h11v11h-11z"/><path d="M2 12h4.5M17.5 12H22M4 8v8M20 8v8"/></svg>
                Meine Pläne
            </a>
            <?php endif; ?>

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
            <a href="<?= APP_URL ?>/dashboard/plaene.php" class="sidebar-link <?= $plaene_aktiv ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6.5 6.5h11v11h-11z"/><path d="M2 12h4.5M17.5 12H22M4 8v8M20 8v8"/></svg>
                Trainings- &amp; Ernährungspläne
            </a>
            <a href="<?= APP_URL ?>/dashboard/uebungen.php" class="sidebar-link <?= strpos($current_path, '/dashboard/uebungen') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                Übungsbibliothek
            </a>
            <a href="<?= APP_URL ?>/dashboard/umsatz.php" class="sidebar-link <?= strpos($current_path, '/dashboard/umsatz') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Mein Umsatz
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
            <a href="<?= APP_URL ?>/dashboard/admin/umsatz.php" class="sidebar-link <?= strpos($current_path, '/admin/umsatz') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Umsatzübersicht
            </a>
            <a href="<?= APP_URL ?>/dashboard/admin/abrechnungen.php" class="sidebar-link <?= strpos($current_path, '/admin/abrechnungen') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Abrechnungen
            </a>
            <a href="<?= APP_URL ?>/dashboard/admin/foerderungen.php" class="sidebar-link <?= strpos($current_path, '/admin/foerderung') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg>
                Fördermanagement
            </a>
            <a href="<?= APP_URL ?>/dashboard/admin/basisfoerderung.php" class="sidebar-link <?= strpos($current_path, '/admin/basisfoerderung') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg>
                Basisförderung SPORTUNION &amp; Land
            </a>
            <a href="<?= APP_URL ?>/dashboard/admin/kooperationen.php" class="sidebar-link <?= strpos($current_path, '/admin/kooperation') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Gemeinde-Kooperationen
            </a>
            <a href="<?= APP_URL ?>/dashboard/admin/tbe.php" class="sidebar-link <?= strpos($current_path, '/admin/tbe') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                TBE-Gesamtkonzept
            </a>
            <a href="<?= APP_URL ?>/dashboard/admin/news.php" class="sidebar-link <?= strpos($current_path, '/admin/news') !== false ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                News
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

    <div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>

    <!-- MAIN CONTENT STARTS HERE -->
    <main class="dashboard-main" id="main-content">
