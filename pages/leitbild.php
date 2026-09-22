<?php
/**
 * Athletikclub Steiermark – Leitbild
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Leitbild';
$meta_description = 'Unser Leitbild: sechs Grundwerte, die unser Handeln als Verein, Gemeinschaft und Vorbild prägen.';
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
        <h1>Werte, die uns leiten &amp; verbinden</h1>
        <p>Sechs Grundwerte, die unser Handeln prägen – als Verein, als Gemeinschaft und als Vorbilder.</p>
    </div>
</section>

<!-- ============================================================
     INTRO + STICHWORTE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2rem; max-width: 720px; margin-inline: auto;">
            <span class="section-label reveal">Unser Leitbild</span>
            <p class="section-subtitle reveal reveal-delay-1" style="margin: 0 auto; font-size: 1.1rem;">
                Wir schaffen einen Raum, in dem Bewegung, Begegnung und Entwicklung zusammenwachsen.
            </p>
        </div>

        <div class="reveal reveal-delay-2" style="display: flex; justify-content: center; gap: 0.6rem; flex-wrap: wrap;">
            <?php
            $stichworte = ['🌍 Vielfalt & Offenheit', '🫀 Gesundheit', '🌱 Nachhaltigkeit', '🤝 Gemeinschaft', '📚 Bildung', '⚡ Eigenverantwortung'];
            foreach ($stichworte as $s): ?>
                <span class="badge badge-gold" style="font-size: 0.8rem; padding: 0.5rem 1rem;"><?= e($s) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     UNSER SELBSTVERSTÄNDNIS
============================================================ -->
<section class="section bg-navy" style="padding: 4rem 0;">
    <div class="container">
        <div class="reveal" style="max-width: 780px; margin: 0 auto; text-align: center;">
            <div style="font-family: 'Montserrat', sans-serif; font-size: 3rem; font-weight: 900; color: var(--gold-accent); opacity: 0.5; line-height: 1;">01</div>
            <p style="font-size: 1.4rem; line-height: 1.7; color: white; font-weight: 300; margin-top: 1rem;">
                Unser Leitbild beschreibt, wer wir sind und wie wir handeln. Es ist Grundlage für
                Entscheidungen im Verein – und ein Versprechen an unsere Mitglieder.
            </p>
        </div>
    </div>
</section>

<!-- ============================================================
     SECHS GRUNDWERTE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Sechs Grundwerte</span>
            <h2 class="section-title reveal reveal-delay-1">Was uns ausmacht</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Diese Werte sind kein Anspruch von außen – sie wachsen von innen und werden von
                allen gelebt.
            </p>
        </div>

        <div class="grid-3">
            <?php
            $werte = [
                [
                    'icon' => '🌍', 'title' => 'Vielfalt und Offenheit',
                    'punkte' => ['Unterschiedliche Bewegungsformen und Zugänge zum Sport', 'Platz für unterschiedliche Interessen, Fähigkeiten und Ziele', 'Vielfalt verstehen wir als Stärke'],
                ],
                [
                    'icon' => '🫀', 'title' => 'Gesundheit und Verantwortung',
                    'punkte' => ['Körperliche und mentale Gesundheit stehen im Mittelpunkt', 'Training wird verantwortungsvoll und altersgerecht gestaltet', 'Prävention und Sicherheit sind zentrale Bestandteile'],
                ],
                [
                    'icon' => '🌱', 'title' => 'Nachhaltige Entwicklung',
                    'punkte' => ['Sportliche Entwicklung erfolgt schrittweise und langfristig', 'Kurzfristige Erfolge stehen nie über dem Wohl der Mitglieder', 'Freude an Bewegung als Grundlage nachhaltiger Motivation'],
                ],
                [
                    'icon' => '🤝', 'title' => 'Gemeinschaft und Respekt',
                    'punkte' => ['Ein Raum für Begegnung, Austausch und Zusammenhalt', 'Respekt, Fairness und Wertschätzung prägen unser Miteinander', 'Diskriminierung und Ausgrenzung haben keinen Platz'],
                ],
                [
                    'icon' => '📚', 'title' => 'Bildung und Orientierung',
                    'punkte' => ['Wir vermitteln Werte, Bewegungskompetenz und Verantwortung', 'Trainer*innen handeln reflektiert und mit Vorbildwirkung', 'Lernen und Weiterentwicklung sind Teil unserer Kultur'],
                ],
                [
                    'icon' => '⚡', 'title' => 'Eigenverantwortung und Mitgestaltung',
                    'punkte' => ['Mitglieder übernehmen Verantwortung für sich und den Verein', 'Mitwirkung und Engagement sind willkommen', 'Der Verein lebt von aktiver Beteiligung'],
                ],
            ];
            foreach ($werte as $i => $w): ?>
                <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
                    <div class="card-body">
                        <div style="font-size: 1.75rem; margin-bottom: 0.75rem;"><?= $w['icon'] ?></div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.85rem;"><?= e($w['title']) ?></h3>
                        <?php foreach ($w['punkte'] as $p): ?>
                            <div style="display: flex; gap: 0.5rem; align-items: flex-start; margin-bottom: 0.5rem;">
                                <span style="width: 5px; height: 5px; border-radius: 50%; background: var(--gold-accent); flex-shrink: 0; margin-top: 7px;"></span>
                                <span style="font-size: 0.825rem; line-height: 1.6; color: var(--text-secondary);"><?= e($p) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     UNSER VERSPRECHEN
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div class="reveal" style="max-width: 720px; margin: 0 auto;">
            <span class="section-label" style="display: block; text-align: center;">Unser Versprechen</span>
            <h2 class="section-title" style="text-align: center; margin-bottom: 2rem;">Werte, die wir jeden Tag leben</h2>

            <p style="font-size: 1.05rem; line-height: 1.9; margin-bottom: 1.25rem;">
                Wir schaffen einen Raum, in dem Bewegung, Begegnung und Entwicklung
                zusammenwachsen – für jede*n, der oder die Teil davon sein möchte.
            </p>
            <p style="line-height: 1.9; margin-bottom: 1.25rem;">
                Unser Leitbild ist kein starres Regelwerk, sondern ein lebendiges Versprechen, das
                wir gemeinsam mit unseren Mitgliedern, Trainer*innen und Betreuer*innen immer
                wieder neu einlösen.
            </p>
            <p style="line-height: 1.9; margin: 0;">
                Werte werden gelebt, nicht nur formuliert. Deshalb gehören Reflexion,
                Weiterentwicklung und ein offener Austausch fest zu unserer Vereinskultur.
            </p>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
