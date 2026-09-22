<?php
/**
 * Athletikclub Steiermark – Leitbild
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Leitbild';
$meta_description = 'Das Leitbild des Athletikclub Steiermark – unsere Werte im Training und im Verein.';
require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Leitbild</span>
        </nav>
        <h1>Unser Leitbild</h1>
        <p>Die Werte, nach denen wir trainieren und zusammenarbeiten.</p>
    </div>
</section>

<section class="section bg-white">
    <div class="container">
        <div class="grid-4">
            <?php
            $werte = [
                ['icon' => 'shield',   'title' => 'Respekt',       'desc' => 'Wertschätzender Umgang – unter Mitgliedern, Trainer*innen und im Wettkampf.'],
                ['icon' => 'trending', 'title' => 'Leistung',      'desc' => 'Individuelle Weiterentwicklung, in dem Tempo, das zu dir passt.'],
                ['icon' => 'users',    'title' => 'Gemeinschaft',  'desc' => 'Zusammenhalt als Grundlage für Motivation und Erfolg.'],
                ['icon' => 'award',    'title' => 'Qualität',      'desc' => 'Professionelles Training durch qualifizierte, engagierte Trainer*innen.'],
            ];
            foreach ($werte as $i => $w): ?>
                <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>" style="text-align: center;">
                    <div class="card-body">
                        <div style="width: 56px; height: 56px; border-radius: 1rem; background: var(--gold-dim); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2">
                                <?php
                                $icons = [
                                    'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                                    'trending' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
                                    'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                                    'award'    => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
                                ];
                                echo $icons[$w['icon']];
                                ?>
                            </svg>
                        </div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.5rem;"><?= htmlspecialchars($w['title']) ?></h3>
                        <p style="font-size: 0.85rem; margin: 0;"><?= htmlspecialchars($w['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<div class="container" style="padding-bottom: 2rem;">
    <div class="card" style="border-style: dashed; text-align: center;">
        <div class="card-body">
            <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">
                Entwurfstext – bitte durch euer offizielles Leitbild ersetzen.
            </p>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
