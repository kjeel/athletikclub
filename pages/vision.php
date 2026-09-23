<?php
/**
 * Athletikclub Steiermark – Vision
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Vision';
$meta_description = 'Unsere Vision: eine polysportive Bewegungskultur mit Vielfalt, Gesundheit und langfristiger Entwicklung statt früher Spezialisierung.';
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
        <h1>Polysportive Bewegungskultur</h1>
        <p>Vielfalt, Gesundheit und langfristige Entwicklung als Grundlage für alles, was wir tun.</p>
    </div>
</section>

<!-- ============================================================
     INTRO + STICHWORTE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2rem; max-width: 720px; margin-inline: auto;">
            <span class="section-label reveal">Unsere Vision</span>
            <p class="section-subtitle reveal reveal-delay-1" style="margin: 0 auto; font-size: 1.1rem;">
                Ein vielseitig bewegter Mensch ist gesünder, anpassungsfähiger und
                leistungsfähiger. Davon sind wir überzeugt.
            </p>
        </div>

        <div class="reveal reveal-delay-2" style="display: flex; justify-content: center; gap: 0.6rem; flex-wrap: wrap;">
            <?php
            $stichworte = ['🫀 Körperliche Gesundheit', '🧠 Mentale Stärke', '⚡ Nachhaltige Leistung', '🌍 Soziale Vielfalt', '🤸 Bewegungsvielfalt', '🏃 Nachwuchssport'];
            foreach ($stichworte as $s): ?>
                <span class="badge badge-gold" style="font-size: 0.8rem; padding: 0.5rem 1rem;"><?= e($s) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     LEITGEDANKE
============================================================ -->
<section class="section bg-navy" style="padding: 4rem 0;">
    <div class="container">
        <div class="reveal" style="max-width: 780px; margin: 0 auto; text-align: center;">
            <div style="font-family: 'Montserrat', sans-serif; font-size: 3rem; font-weight: 900; color: var(--gold-accent); opacity: 0.5; line-height: 1;">01</div>
            <p style="font-size: 1.4rem; line-height: 1.7; color: white; font-weight: 300; margin-top: 1rem;">
                Wir fördern eine polysportive Bewegungskultur, die Vielfalt, Gesundheit und
                langfristige Entwicklung ermöglicht, damit Menschen mehrere Sportarten erleben,
                erlernen und miteinander verbinden können.
            </p>
        </div>
    </div>
</section>

<!-- ============================================================
     VIER GRUNDPFEILER
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Vier Grundpfeiler</span>
            <h2 class="section-title reveal reveal-delay-1">Was uns antreibt</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Polysportivität ist für uns kein Trend, sondern ein ganzheitliches Verständnis
                von Bewegung und Mensch.
            </p>
        </div>

        <div class="grid-4">
            <?php
            $pfeiler = [
                ['icon' => '🫀', 'title' => 'Körperliche Gesundheit', 'desc' => 'Vielseitige Bewegung schützt den Körper, senkt das Verletzungsrisiko und fördert eine ausgeglichene muskuläre Entwicklung.'],
                ['icon' => '🧠', 'title' => 'Mentale Stärke',         'desc' => 'Neue Bewegungsformen fordern den Kopf, schärfen die Konzentration und stärken die Widerstandsfähigkeit durch ständiges Lernen.'],
                ['icon' => '🌍', 'title' => 'Soziale Vielfalt',       'desc' => 'Sport verbindet. Polysportivität schafft Begegnungen über Grenzen hinweg, zwischen Menschen, Kulturen und Generationen.'],
                ['icon' => '⚡', 'title' => 'Nachhaltige Leistung',   'desc' => 'Wer vielseitig trainiert, bleibt länger leistungsfähig, ganz ohne Überlastung oder frühzeitigen Verschleiß.'],
            ];
            foreach ($pfeiler as $i => $p): ?>
                <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
                    <div class="card-body">
                        <div style="font-size: 1.75rem; margin-bottom: 0.75rem;"><?= $p['icon'] ?></div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.5rem;"><?= e($p['title']) ?></h3>
                        <p style="font-size: 0.85rem; line-height: 1.7; margin: 0;"><?= e($p['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     UNSER STANDPUNKT
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div class="reveal" style="max-width: 720px; margin: 0 auto;">
            <span class="section-label" style="display: block; text-align: center;">Unser Standpunkt</span>
            <h2 class="section-title" style="text-align: center; margin-bottom: 2rem;">Vielfalt statt früher Spezialisierung</h2>

            <p style="font-size: 1.05rem; line-height: 1.9; margin-bottom: 1.25rem;">
                Wir sind überzeugt: Ein vielseitig bewegter Mensch ist gesünder, anpassungsfähiger
                und leistungsfähiger als jemand, der sich zu früh auf eine einzige Sportart
                festlegt.
            </p>
            <p style="line-height: 1.9; margin: 0;">
                Deshalb steht der Athletikclub Steiermark für polysportive Entwicklung statt
                früher Spezialisierung, als Grundlage für körperliche Gesundheit, mentale Stärke,
                soziale Vielfalt und Leistungsfähigkeit, die trägt, auch im Nachwuchssport.
            </p>
        </div>
    </div>
</section>

<!-- ============================================================
     DER UNTERSCHIED
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Der Unterschied</span>
            <h2 class="section-title reveal reveal-delay-1">Unser Ansatz im Vergleich</h2>
        </div>

        <div class="reveal reveal-delay-2" style="display: flex; flex-wrap: wrap; gap: 2rem; align-items: flex-start; justify-content: center; max-width: 900px; margin: 0 auto;">
            <!-- Frühzeitige Spezialisierung -->
            <div class="card" style="opacity: 0.85; flex: 1 1 320px; min-width: 280px;">
                <div class="card-body">
                    <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.9rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 1.25rem;">Frühzeitige Spezialisierung</h3>
                    <?php
                    $nachteile = [
                        'Einseitige muskuläre Belastung',
                        'Erhöhtes Verletzungsrisiko',
                        'Monotonie statt Motivation',
                        'Begrenzte motorische Vielfalt',
                        'Früher Leistungsabfall',
                    ];
                    foreach ($nachteile as $n): ?>
                        <div style="display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.85rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <span style="font-size: 0.85rem; color: var(--text-secondary);"><?= e($n) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- VS -->
            <div style="display: flex; align-items: center; justify-content: center; flex: 0 0 auto; padding-top: 2.5rem;">
                <span style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; color: var(--text-muted); letter-spacing: 0.05em;">VS</span>
            </div>

            <!-- Polysportive Entwicklung -->
            <div class="card" style="border: 2px solid var(--gold-accent); flex: 1 1 320px; min-width: 280px;">
                <div class="card-body">
                    <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.9rem; font-weight: 700; text-transform: uppercase; color: var(--text-primary); margin-bottom: 1.25rem;">Polysportive Entwicklung</h3>
                    <?php
                    $vorteile = [
                        'Ganzheitliche Körperentwicklung',
                        'Niedrigeres Verletzungsrisiko',
                        'Freude & Motivation durch Abwechslung',
                        'Breite motorische Kompetenz',
                        'Langfristige Leistungsfähigkeit',
                    ];
                    foreach ($vorteile as $v): ?>
                        <div style="display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.85rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;"><polyline points="20 6 9 17 4 12"/></svg>
                            <span style="font-size: 0.85rem; font-weight: 500;"><?= e($v) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
