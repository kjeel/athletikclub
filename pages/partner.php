<?php
/**
 * Athletikclub Steiermark – Partner*innen
 * Derzeit noch keine Partner*innen – die Seite lädt zur Partnerschaft ein.
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Partner*innen';
$meta_description = 'Partner*in des Athletikclub Steiermark werden: Unternehmen und Institutionen, die Bewegung und Sport in der Region unterstützen möchten.';
require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Partner*innen</span>
        </nav>
        <h1>Unsere Partner*innen</h1>
        <p>Gemeinsam mehr Bewegung in die Region bringen.</p>
    </div>
</section>

<section class="section bg-white">
    <div class="container">
        <div class="card reveal" style="max-width: 680px; margin: 0 auto; text-align: center;">
            <div class="card-body" style="padding: 2.5rem 2rem;">
                <div style="width: 64px; height: 64px; border-radius: 1rem; background: var(--gold-dim); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
                </div>
                <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.25rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Werde unsere erste Partner*in</h2>
                <p style="margin-bottom: 1.75rem;">
                    Wir sind ein junger Verein und bauen unser Partnernetzwerk gerade auf.
                    Ihr möchtet den Athletikclub Steiermark unterstützen – als Unternehmen, Gemeinde oder Institution?
                    Wir freuen uns auf eure Nachricht.
                </p>
                <a href="<?= APP_URL ?>/pages/kontakt.php" class="btn btn-navy">Kontakt aufnehmen</a>
            </div>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
