<?php
/**
 * Athletikclub Steiermark – Partner*innen
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Partner*innen';
$meta_description = 'Unsere Partner*innen – Unternehmen und Institutionen, die den Athletikclub Steiermark unterstützen.';
require_once ROOT_PATH . '/includes/header.php';

$partner = [
    'SPORTUNION Steiermark',
    'Marktgemeinde Sankt Georgen an der Stiefing',
    'Alpenverein Leibnitz',
    'Marktgemeinde Kirchbach-Zerlach',
    'MK TEC Elektrotechnik GmbH',
    'Leo Kysela',
    'MFC Service GmbH',
];
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
        <p>Danke an alle, die den Athletikclub Steiermark möglich machen.</p>
    </div>
</section>

<section class="section bg-white">
    <div class="container">
        <div class="grid-3">
            <?php foreach ($partner as $i => $name): ?>
                <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>" style="text-align: center;">
                    <div class="card-body">
                        <div style="width: 56px; height: 56px; border-radius: 1rem; background: var(--gold-dim); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                            </svg>
                        </div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin: 0;"><?= htmlspecialchars($name) ?></h3>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center reveal" style="margin-top: 3rem;">
            <p style="margin-bottom: 1.5rem;">Ihr wollt den Athletikclub Steiermark als Partner*in unterstützen?</p>
            <a href="<?= APP_URL ?>/pages/kontakt.php" class="btn btn-navy">Kontakt aufnehmen</a>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
