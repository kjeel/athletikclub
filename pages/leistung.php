<?php
/**
 * Athletikclub Steiermark – Leistungsangebot (Platzhalter, bis das Angebot online ist)
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Leistung';
$meta_description = 'Das Leistungsangebot des Athletikclub Steiermark wird in Kürze verfügbar sein.';
// Platzhalter nicht in Suchmaschinen aufnehmen
$noindex = true;
require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Leistung</span>
        </nav>
        <h1>Unser Leistungsangebot</h1>
        <p>Wir arbeiten gerade daran.</p>
    </div>
</section>

<section class="section bg-white">
    <div class="container">
        <div class="card reveal" style="max-width: 640px; margin: 0 auto; text-align: center;">
            <div class="card-body">
                <div style="width: 56px; height: 56px; border-radius: 1rem; background: var(--gold-dim); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                    </svg>
                </div>
                <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.35rem; font-weight: 800; margin: 0 0 0.75rem;">Wir arbeiten gerade daran</h2>
                <p style="margin: 0 0 1.5rem;">
                    Unser Leistungsangebot wird in Kürze verfügbar sein.
                    Bis dahin beantworten wir deine Fragen zu Kursen und Trainings gerne persönlich.
                </p>
                <a href="<?= APP_URL ?>/pages/kontakt.php" class="btn btn-navy">Kontakt aufnehmen</a>
            </div>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
