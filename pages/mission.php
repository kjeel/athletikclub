<?php
/**
 * Athletikclub Steiermark – Mission
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Mission';
$meta_description = 'Die Mission des Athletikclub Steiermark – ganzheitliches Athletik- und polysportives Training in St. Georgen an der Stiefing.';
require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Mission</span>
        </nav>
        <h1>Unsere Mission</h1>
        <p>Was wir jeden Tag für unsere Mitglieder tun.</p>
    </div>
</section>

<section class="section bg-light">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 720px; margin-inline: auto;">
            <span class="section-label reveal">Unser Auftrag</span>
            <h2 class="section-title reveal reveal-delay-1">Training, das wirklich weiterbringt</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Wir bieten polysportives, ganzheitliches Training – professionell begleitet
                und für jedes Leistungsniveau zugänglich.
            </p>
        </div>

        <div class="grid-3">
            <?php
            $punkte = [
                ['icon' => 'target',   'title' => 'Qualität', 'desc' => 'Professionell geplantes Training durch qualifizierte Trainer*innen in allen Disziplinen.'],
                ['icon' => 'users',    'title' => 'Zugänglichkeit', 'desc' => 'Ein Angebot für Einsteiger*innen genauso wie für ambitionierte Leistungssportler*innen.'],
                ['icon' => 'heart',    'title' => 'Gemeinschaft', 'desc' => 'Ein Verein, der Zusammenhalt und gegenseitige Motivation in den Mittelpunkt stellt.'],
            ];
            foreach ($punkte as $i => $p): ?>
                <div class="card reveal reveal-delay-<?= $i + 1 ?>" style="text-align: center;">
                    <div class="card-body">
                        <div style="width: 56px; height: 56px; border-radius: 1rem; background: var(--gold-dim); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2">
                                <?php
                                $icons = [
                                    'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
                                    'users'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                                    'heart'  => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
                                ];
                                echo $icons[$p['icon']];
                                ?>
                            </svg>
                        </div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem;"><?= htmlspecialchars($p['title']) ?></h3>
                        <p style="font-size: 0.875rem; margin: 0;"><?= htmlspecialchars($p['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<div class="container" style="padding-block: 2rem;">
    <div class="card" style="border-style: dashed; text-align: center;">
        <div class="card-body">
            <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">
                Entwurfstext – bitte durch euren offiziellen Mission-Text ersetzen.
            </p>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
