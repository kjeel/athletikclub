<?php
/**
 * Athletikclub Steiermark – Vision
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Vision';
$meta_description = 'Die Vision des Athletikclub Steiermark – ganzheitliches Athletik- und polysportives Training in St. Georgen an der Stiefing.';
require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Vision</span>
        </nav>
        <h1>Unsere Vision</h1>
        <p>Wohin wir als Verein wollen – und warum.</p>
    </div>
</section>

<section class="section bg-white">
    <div class="container">
        <div class="feature-block">
            <div class="feature-visual reveal">
                <div style="
                    aspect-ratio: 4/3;
                    background: linear-gradient(135deg, var(--navy-primary), var(--navy-light));
                    border-radius: 1.5rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    position: relative;
                    overflow: hidden;
                ">
                    <div style="width: 200px; height: 200px; border-radius: 50%; border: 3px solid rgba(198,161,53,0.3); display: flex; align-items: center; justify-content: center;">
                        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="1.5">
                            <path d="M8 21h8M12 3l9 15H3l9-15z"/>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="reveal reveal-delay-1">
                <span class="section-label">Unser Antrieb</span>
                <h2 class="section-title" style="margin-bottom: 1.25rem;">
                    Athletik für alle – ein Leben lang
                </h2>
                <p style="margin-bottom: 1.25rem; font-size: 1.05rem; line-height: 1.85;">
                    Wir wollen der Verein in der Steiermark sein, in dem Menschen jeden Alters
                    und jeder Leistungsstufe eine sportliche Heimat finden – vom ersten
                    Trainingstag bis zum Leistungssport.
                </p>
                <p style="line-height: 1.85;">
                    Athletik, Beweglichkeit und Ausdauer sind für uns die Basis für ein
                    aktives, gesundes Leben. Unsere Vision ist eine starke, wachsende
                    Sportgemeinschaft, die genau dort ansetzt.
                </p>
            </div>
        </div>
    </div>
</section>

<div class="container" style="padding-bottom: 2rem;">
    <div class="card" style="border-style: dashed; text-align: center;">
        <div class="card-body">
            <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">
                Entwurfstext – bitte durch euren offiziellen Vision-Text ersetzen.
            </p>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
