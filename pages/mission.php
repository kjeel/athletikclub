<?php
/**
 * Athletikclub Steiermark – Mission
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Mission';
$meta_description = 'Unsere Mission: Sport als lebensbegleitender Prozess – vielseitiger, gesunder und verantwortungsvoller Zugang zu Bewegung für alle.';
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
        <h1>Sport als Begleiter fürs ganze Leben</h1>
        <p>Ein vielseitiger, gesunder und verantwortungsvoller Zugang zu Bewegung – für jede Lebensphase.</p>
    </div>
</section>

<!-- ============================================================
     INTRO + STICHWORTE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2rem; max-width: 720px; margin-inline: auto;">
            <span class="section-label reveal">Unsere Mission</span>
            <p class="section-subtitle reveal reveal-delay-1" style="margin: 0 auto; font-size: 1.1rem;">
                Sport ist für uns kein kurzfristiges Leistungsziel, sondern eine Fähigkeit, die
                Menschen ein Leben lang begleitet und stärkt.
            </p>
        </div>

        <div class="reveal reveal-delay-2" style="display: flex; justify-content: center; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 1rem;">
            <?php
            $stichworte = ['🌱 Persönliche Entwicklung', '🫀 Gesundheit', '🤝 Gemeinschaft', '👶 Für jedes Alter', '🎯 Individuell', '⚡ Selbstwirksamkeit'];
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
                Wir setzen uns dafür ein, dass Bewegung Menschen in jeder Lebensphase stärkt –
                körperlich, mental und als Teil einer Gemeinschaft, in der niemand allein trainiert.
            </p>
        </div>
    </div>
</section>

<!-- ============================================================
     UNSERE ANGEBOTE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Was wir schaffen</span>
            <h2 class="section-title reveal reveal-delay-1">Unsere Angebote</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Angebote, die Vielfalt, Freude und persönliche Entwicklung in den Mittelpunkt stellen.
            </p>
        </div>

        <div class="grid-4">
            <?php
            $angebote = [
                ['nr' => '01', 'icon' => '🌍', 'title' => 'Für alle offen',        'desc' => 'Unabhängig von Alter, Erfahrung oder sportlichem Niveau – bei uns findet jede*r seinen Platz.'],
                ['nr' => '02', 'icon' => '🎯', 'title' => 'Individueller Weg',      'desc' => 'Jeder Mensch bringt andere Stärken mit. Wir schaffen Raum, sie zu entdecken und weiterzuentwickeln.'],
                ['nr' => '03', 'icon' => '⚡', 'title' => 'Können & Freude',        'desc' => 'Wir stärken motorische Fähigkeiten und die Freude an Bewegung – als Grundlage für Selbstvertrauen.'],
                ['nr' => '04', 'icon' => '🌱', 'title' => 'Vielfalt statt Routine', 'desc' => 'Breite Bewegungserfahrung statt Einseitigkeit – als Basis für ein aktives Leben, heute und langfristig.'],
            ];
            foreach ($angebote as $i => $a): ?>
                <div class="reveal reveal-delay-<?= ($i % 4) + 1 ?>">
                    <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.75rem;">
                        <span style="font-family: 'Montserrat', sans-serif; font-size: 0.75rem; font-weight: 700; color: var(--text-muted);"><?= $a['nr'] ?></span>
                        <span style="font-size: 1.4rem;"><?= $a['icon'] ?></span>
                    </div>
                    <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem;"><?= e($a['title']) ?></h3>
                    <p style="font-size: 0.875rem; line-height: 1.7; margin: 0;"><?= e($a['desc']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     UNSER VERSTÄNDNIS
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div class="reveal" style="max-width: 720px; margin: 0 auto;">
            <span class="section-label" style="display: block; text-align: center;">Unser Verständnis</span>
            <h2 class="section-title" style="text-align: center; margin-bottom: 2rem;">Mehr als Wettkampf</h2>

            <p style="font-size: 1.05rem; line-height: 1.9; margin-bottom: 1.25rem;">
                Für uns ist Sport kein kurzfristiges Leistungsziel, sondern ein Weg, der Menschen
                ein Leben lang begleitet, stärkt und miteinander verbindet.
            </p>
            <p style="line-height: 1.9; margin-bottom: 1.25rem;">
                Beim Athletikclub Steiermark verstehen wir Training als Mittel zur persönlichen
                Entwicklung, zur Gesundheitsvorsorge und zum Miteinander – weit über klassisches
                Wettkampfdenken hinaus.
            </p>
            <p style="line-height: 1.9; margin: 0;">
                Bewegungskompetenz, Freude an der Sache und das Vertrauen in die eigenen Fähigkeiten
                sind für uns die Grundlage eines Sporterlebnisses, das trägt – für Kinder,
                Jugendliche und Erwachsene gleichermaßen.
            </p>
        </div>
    </div>
</section>

<!-- ============================================================
     VIER GRUNDSÄTZE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Vier Grundsätze</span>
            <h2 class="section-title reveal reveal-delay-1">Was unsere Angebote ausmacht</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Diese Prinzipien gelten für jedes unserer Angebote – konsequent und ohne Ausnahme.
            </p>
        </div>

        <div style="max-width: 680px; margin: 0 auto;">
            <?php
            $grundsaetze = [
                'Offen für alle – unabhängig von Alter, Erfahrung oder Lebensphase',
                'Individuelle Entwicklung ermöglichen – jeder Weg ist einzigartig',
                'Können, Freude und Selbstvertrauen stärken – von innen heraus',
                'Vielfalt statt Einseitigkeit – als Basis für nachhaltige Leistungsfähigkeit',
            ];
            foreach ($grundsaetze as $i => $g): ?>
                <div class="reveal reveal-delay-<?= ($i % 4) + 1 ?>" style="display: flex; gap: 0.85rem; align-items: flex-start; margin-bottom: 1.25rem;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;"><polyline points="20 6 9 17 4 12"/></svg>
                    <span style="font-size: 0.95rem; line-height: 1.7;"><?= e($g) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
