<?php
/**
 * Athletikclub Steiermark – Startseite
 */
define('ROOT_PATH', __DIR__);
$page_title       = 'Athletikclub Steiermark – Ganzheitliches Training & Sport';
$meta_description = 'Willkommen beim Athletikclub für Individualsportarten. Ganzheitliches Athletik- und polysportives Training in St. Georgen an der Stiefing, Steiermark.';

require_once ROOT_PATH . '/includes/header.php';

// Kurse für die Homepage laden (nächste 3 Kurse)
$upcoming_kurse = [];
try {
    $db = getDB();
    $stmt = $db->query(
        "SELECT k.*, u.vorname, u.nachname
         FROM kurse k
         LEFT JOIN users u ON k.trainer_id = u.id
         WHERE k.status IN ('geplant','aktiv') AND k.start_datum >= NOW()
         ORDER BY k.start_datum ASC
         LIMIT 3"
    );
    $upcoming_kurse = $stmt->fetchAll();
} catch (Exception $e) {
    // DB noch nicht eingerichtet – ignorieren
}
?>

<!-- ============================================================
     HERO SECTION
============================================================ -->
<section class="hero" id="hero">
    <div class="hero-bg">
        <div style="
            position: absolute; inset: 0;
            background: linear-gradient(135deg,
                #0D1F35 0%,
                #1F3556 40%,
                #2A4570 70%,
                #1F3556 100%);
        "></div>
    </div>

    <!-- Geometrisches Muster -->
    <div class="hero-pattern"></div>

    <!-- Dekorative Kreise -->
    <div style="
        position: absolute; width: 600px; height: 600px;
        border-radius: 50%;
        border: 1px solid rgba(198,161,53,0.12);
        top: -200px; right: -200px; z-index: 1;
    "></div>
    <div style="
        position: absolute; width: 400px; height: 400px;
        border-radius: 50%;
        border: 1px solid rgba(198,161,53,0.08);
        bottom: -100px; left: -100px; z-index: 1;
    "></div>
    <div style="
        position: absolute; width: 200px; height: 200px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(198,161,53,0.1) 0%, transparent 70%);
        top: 20%; right: 15%; z-index: 1;
    "></div>

    <div class="container" style="position: relative; z-index: 2; width: 100%;">
        <div style="display: grid; grid-template-columns: 1fr auto; gap: 4rem; align-items: center;">

            <!-- Hero Content -->
            <div class="hero-content">
                <div class="hero-eyebrow reveal">
                    <div class="hero-eyebrow-line"></div>
                    <span>Athletikclub Steiermark</span>
                    <div class="hero-eyebrow-line"></div>
                </div>

                <h1 class="hero-title reveal reveal-delay-1">
                    Stärker.
                    <em>Schneller.</em>
                    Athletischer.
                </h1>

                <p class="hero-subtitle reveal reveal-delay-2">
                    Ganzheitliches Athletik- und polysportives Training in St. Georgen an der Stiefing.
                    Für Anfänger bis ambitionierte Leistungssportler.
                </p>

                <div class="hero-actions reveal reveal-delay-3">
                    <a href="/pages/mitglied-werden.php" class="btn btn-primary btn-xl">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                        Mitglied werden
                    </a>
                    <a href="/pages/leistung.php" class="btn btn-ghost btn-xl">
                        Unsere Angebote
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>

                <!-- Sport-Badges -->
                <div class="reveal reveal-delay-4" style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 2.5rem;">
                    <?php
                    $sportarten = [
                        ['name' => 'Calisthenics',   'color' => '#0055D4'],
                        ['name' => 'Skateboarding',   'color' => '#FF7B37'],
                        ['name' => 'Tischtennis',     'color' => '#00A896'],
                        ['name' => 'Padel Tennis',    'color' => '#4EBA6F'],
                        ['name' => 'Athletiktraining','color' => '#C6A135'],
                        ['name' => 'Ausdauer',        'color' => '#7C3AED'],
                    ];
                    foreach ($sportarten as $s): ?>
                        <span style="
                            display: inline-flex; align-items: center; gap: 0.4rem;
                            padding: 0.3rem 0.85rem;
                            background: rgba(255,255,255,0.07);
                            border: 1px solid rgba(255,255,255,0.12);
                            border-radius: 999px;
                            font-family: 'Montserrat', sans-serif;
                            font-size: 0.7rem;
                            font-weight: 600;
                            letter-spacing: 0.06em;
                            color: rgba(255,255,255,0.8);
                            backdrop-filter: blur(4px);
                        ">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: <?= $s['color'] ?>; flex-shrink: 0;"></span>
                            <?= htmlspecialchars($s['name']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Floating Stats -->
            <div class="hero-deco">
                <div class="hero-stat">
                    <div class="hero-stat-number" data-count="150">0</div>
                    <div class="hero-stat-label">Mitglieder</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-number" data-count="6">0</div>
                    <div class="hero-stat-label">Sportarten</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-number" data-count="10">0</div>
                    <div class="hero-stat-label">Trainer</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-number" data-count="2019">0</div>
                    <div class="hero-stat-label">Gegründet</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scroll-Indicator -->
    <div class="hero-scroll">
        <span>Entdecken</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
</section>

<!-- ============================================================
     SPORTARTEN / DISZIPLINEN
============================================================ -->
<section class="section bg-white" id="sportarten">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem;">
            <span class="section-label reveal">Unsere Disziplinen</span>
            <h2 class="section-title reveal reveal-delay-1">Trainiere, was dich bewegt</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Polysportives Training für Kraft, Ausdauer, Koordination und Beweglichkeit –
                in einer starken Gemeinschaft.
            </p>
        </div>

        <div class="sport-grid">
            <?php
            $disziplinen = [
                [
                    'icon'        => '🤸',
                    'name'        => 'Calisthenics',
                    'desc'        => 'Körpergewichtstraining auf höchstem Niveau. Von Basics bis zu Freestyle-Elementen.',
                    'color'       => '#0055D4',
                    'color_pale'  => 'rgba(0,85,212,0.08)',
                ],
                [
                    'icon'        => '🛹',
                    'name'        => 'Skateboarding',
                    'desc'        => 'Gleichgewicht, Koordination und Mut. Skateboarding als vollwertiger Vereinssport.',
                    'color'       => '#FF7B37',
                    'color_pale'  => 'rgba(255,123,55,0.08)',
                ],
                [
                    'icon'        => '🏓',
                    'name'        => 'Tischtennis',
                    'desc'        => 'Reaktionsstärke und taktisches Gespür – Training für alle Altersgruppen.',
                    'color'       => '#00A896',
                    'color_pale'  => 'rgba(0,168,150,0.08)',
                ],
                [
                    'icon'        => '🎾',
                    'name'        => 'Padel Tennis',
                    'desc'        => 'Der Trendsport schlechthin – strategisch, dynamisch, ideal für Team-Spirit.',
                    'color'       => '#4EBA6F',
                    'color_pale'  => 'rgba(78,186,111,0.08)',
                ],
                [
                    'icon'        => '💪',
                    'name'        => 'Athletiktraining',
                    'desc'        => 'Funktionelles Training für Kraft, Explosivität und ganzheitliche Athletik.',
                    'color'       => '#C6A135',
                    'color_pale'  => 'rgba(198,161,53,0.08)',
                ],
                [
                    'icon'        => '🏃',
                    'name'        => 'Ausdauer',
                    'desc'        => 'Lauf- und Cardio-Programme für nachhaltige Fitness und Gesundheit.',
                    'color'       => '#7C3AED',
                    'color_pale'  => 'rgba(124,58,237,0.08)',
                ],
            ];
            foreach ($disziplinen as $i => $d): ?>
                <div class="sport-card reveal reveal-delay-<?= ($i % 4) + 1 ?>"
                     style="--card-color: <?= $d['color'] ?>; --card-color-pale: <?= $d['color_pale'] ?>;">
                    <div class="sport-card-icon"><?= $d['icon'] ?></div>
                    <h3 class="sport-card-title">
                        <span class="sport-dot"></span>
                        <?= htmlspecialchars($d['name']) ?>
                    </h3>
                    <p class="sport-card-desc"><?= htmlspecialchars($d['desc']) ?></p>
                    <a href="/pages/leistung.php" style="
                        display: inline-flex; align-items: center; gap: 0.35rem;
                        margin-top: 1.25rem;
                        font-family: 'Montserrat', sans-serif;
                        font-size: 0.75rem; font-weight: 700;
                        text-transform: uppercase; letter-spacing: 0.08em;
                        color: <?= $d['color'] ?>;
                        transition: gap 0.2s;
                    " onmouseover="this.style.gap='0.6rem'" onmouseout="this.style.gap='0.35rem'">
                        Mehr erfahren
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     STATS / KENNZAHLEN
============================================================ -->
<section class="bg-navy" style="padding: 5rem 0;">
    <div class="container">
        <div class="stats-grid">
            <?php
            $stats = [
                ['number' => 150,   'suffix' => '+', 'label' => 'Aktive Mitglieder'],
                ['number' => 6,     'suffix' => '',  'label' => 'Sportdisziplinen'],
                ['number' => 10,    'suffix' => '+', 'label' => 'Qualifizierte Trainer'],
                ['number' => 5,     'suffix' => '+', 'label' => 'Jahre Erfahrung'],
            ];
            foreach ($stats as $i => $s): ?>
                <div class="stat-item reveal reveal-delay-<?= $i + 1 ?>">
                    <div class="stat-number">
                        <span data-count="<?= $s['number'] ?>">0</span><?= $s['suffix'] ?>
                    </div>
                    <div class="stat-label"><?= htmlspecialchars($s['label']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     ÜBER UNS / VISION TEASER
============================================================ -->
<section class="section bg-light">
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
                    <!-- Placeholder Graphic -->
                    <div style="
                        width: 200px; height: 200px;
                        border-radius: 50%;
                        border: 3px solid rgba(198,161,53,0.3);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        position: relative;
                    ">
                        <div style="
                            position: absolute; inset: -20px;
                            border-radius: 50%;
                            border: 1px solid rgba(198,161,53,0.1);
                        "></div>
                        <svg width="80" height="80" viewBox="0 0 50 50" fill="none">
                            <circle cx="25" cy="25" r="23" stroke="#C6A135" stroke-width="2"/>
                            <path d="M14 34L25 14L36 34" stroke="#C6A135" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M18 28H32" stroke="#C6A135" stroke-width="2" stroke-linecap="round"/>
                            <circle cx="25" cy="14" r="2.5" fill="#C6A135"/>
                        </svg>
                    </div>
                    <div style="
                        position: absolute;
                        bottom: 2rem; right: 2rem;
                        background: rgba(198,161,53,0.9);
                        border-radius: 1rem;
                        padding: 1rem 1.25rem;
                        backdrop-filter: blur(8px);
                    ">
                        <div style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 900; color: #0D1F35; line-height: 1;">2019</div>
                        <div style="font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(13,31,53,0.7);">Gegründet</div>
                    </div>
                </div>
            </div>

            <div class="reveal reveal-delay-1">
                <span class="section-label">Über uns</span>
                <h2 class="section-title" style="margin-bottom: 1.25rem;">
                    Dein Verein für ganzheitliche Athletik
                </h2>
                <p style="margin-bottom: 1.25rem; font-size: 1.05rem; line-height: 1.85;">
                    Der <strong>Athletikclub für Individualsportarten (ACI)</strong> ist dein Zuhause für
                    polysportives Training in der Steiermark. Wir fördern Kraft, Ausdauer,
                    Beweglichkeit und Koordination – für alle Leistungsniveaus.
                </p>
                <p style="margin-bottom: 2rem; line-height: 1.85;">
                    Unser Team aus qualifizierten Trainern begleitet dich auf deinem
                    persönlichen Weg – egal ob du gerade erst anfängst oder als
                    ambitionierter Sportler deine Leistung optimieren möchtest.
                </p>

                <div class="feature-list">
                    <?php
                    $features = [
                        ['icon' => 'users',      'title' => 'Starke Gemeinschaft',      'desc' => 'Über 150 Mitglieder, die sich gegenseitig motivieren und unterstützen.'],
                        ['icon' => 'award',      'title' => 'Qualifizierte Trainer',    'desc' => 'Zertifizierte Übungsleiter mit Leidenschaft für ihren Sport.'],
                        ['icon' => 'activity',   'title' => 'Ganzheitliches Training',  'desc' => 'Sechs Disziplinen für ein breites, ausgewogenes Sportprogramm.'],
                    ];
                    foreach ($features as $f): ?>
                        <div class="feature-item">
                            <div class="feature-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <?php
                                    $icons = [
                                        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                                        'award'    => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
                                        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
                                    ];
                                    echo $icons[$f['icon']];
                                    ?>
                                </svg>
                            </div>
                            <div>
                                <h4 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.25rem;">
                                    <?= htmlspecialchars($f['title']) ?>
                                </h4>
                                <p style="font-size: 0.875rem; margin: 0; line-height: 1.6;">
                                    <?= htmlspecialchars($f['desc']) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <a href="/pages/vision.php" class="btn btn-navy">Unsere Vision</a>
                    <a href="/pages/team.php" class="btn btn-ghost-light">Das Team</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     AKTUELLE KURSE
============================================================ -->
<?php if (!empty($upcoming_kurse)): ?>
<section class="section bg-white" id="kurse">
    <div class="container">
        <div class="flex-between" style="margin-bottom: 3rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span class="section-label reveal">Trainingsangebot</span>
                <h2 class="section-title reveal reveal-delay-1">Nächste Kurse</h2>
            </div>
            <a href="/pages/leistung.php" class="btn btn-ghost-light reveal">Alle Kurse ansehen</a>
        </div>

        <div class="grid-3">
            <?php foreach ($upcoming_kurse as $i => $kurs): ?>
                <div class="card reveal reveal-delay-<?= $i + 1 ?>">
                    <div style="
                        height: 6px;
                        background: linear-gradient(90deg, var(--navy-primary), var(--navy-light));
                    "></div>
                    <div class="card-body">
                        <div class="card-meta">
                            <span style="display: flex; align-items: center; gap: 0.25rem;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <?= date('d.m.Y', strtotime($kurs['start_datum'])) ?>
                            </span>
                            <?php if ($kurs['ort']): ?>
                            <span style="display: flex; align-items: center; gap: 0.25rem;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <?= htmlspecialchars($kurs['ort']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <h3 class="card-title"><?= htmlspecialchars($kurs['titel']) ?></h3>
                        <?php if ($kurs['sportart']): ?>
                            <span class="badge badge-gold" style="margin-bottom: 0.75rem;"><?= htmlspecialchars($kurs['sportart']) ?></span>
                        <?php endif; ?>
                        <?php if ($kurs['beschreibung']): ?>
                            <p style="font-size: 0.875rem; margin-bottom: 1rem;">
                                <?= htmlspecialchars(mb_strimwidth($kurs['beschreibung'], 0, 100, '…')) ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($kurs['vorname']): ?>
                            <p style="font-size: 0.8rem; color: var(--text-muted);">
                                Trainer: <?= htmlspecialchars($kurs['vorname'] . ' ' . $kurs['nachname']) ?>
                            </p>
                        <?php endif; ?>
                        <div style="margin-top: 1.25rem;">
                            <?php if (isLoggedIn()): ?>
                                <a href="/dashboard/kurs-detail.php?id=<?= $kurs['id'] ?>" class="btn btn-primary btn-sm w-full">Jetzt anmelden</a>
                            <?php else: ?>
                                <a href="/auth/login.php" class="btn btn-ghost-light btn-sm w-full">Anmelden zum Kurs</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     CTA – MITGLIED WERDEN
============================================================ -->
<section class="cta-section">
    <div class="container" style="position: relative; z-index: 1;">
        <span class="section-label reveal" style="color: var(--gold-accent);">Werde Teil des Teams</span>
        <h2 class="section-title section-title--white reveal reveal-delay-1" style="margin-bottom: 1.25rem;">
            Bereit für den nächsten Level?
        </h2>
        <p class="section-subtitle section-subtitle--white reveal reveal-delay-2" style="margin: 0 auto 2.5rem;">
            Werde Mitglied beim Athletikclub Steiermark und trainiere in einer starken
            Gemeinschaft mit qualifizierten Trainern an deiner Seite.
        </p>
        <div class="reveal reveal-delay-3" style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
            <a href="/auth/register.php" class="btn btn-primary btn-xl">
                Jetzt registrieren
            </a>
            <a href="/pages/kontakt.php" class="btn btn-ghost btn-xl">
                Kontakt aufnehmen
            </a>
        </div>

        <div class="reveal reveal-delay-4" style="
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin-top: 3.5rem;
            flex-wrap: wrap;
        ">
            <?php
            $cta_benefits = ['✓ Kein Startgeld', '✓ Flexibles Training', '✓ Alle Sportarten inklusive', '✓ Persönliche Betreuung'];
            foreach ($cta_benefits as $b): ?>
                <span style="
                    font-family: 'Montserrat', sans-serif;
                    font-size: 0.8rem;
                    font-weight: 600;
                    letter-spacing: 0.06em;
                    color: rgba(255,255,255,0.75);
                "><?= htmlspecialchars($b) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     KONTAKT-TEASER
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div style="
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        ">
            <?php
            $kontakt_items = [
                [
                    'icon' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
                    'title' => 'Adresse',
                    'content' => 'St. Georgen an der Stiefing 14<br>8413 Sankt Georgen an der Stiefing',
                    'link' => null,
                ],
                [
                    'icon' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.07 6.07l1.79-1.79a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
                    'title' => 'Telefon',
                    'content' => '+43 664 882 895 00',
                    'link' => 'tel:+436648828950',
                ],
                [
                    'icon' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
                    'title' => 'E-Mail',
                    'content' => 'office@athletikclub-steiermark.at',
                    'link' => 'mailto:office@athletikclub-steiermark.at',
                ],
            ];
            foreach ($kontakt_items as $i => $item): ?>
                <div class="card reveal reveal-delay-<?= $i + 1 ?>" style="text-align: center;">
                    <div class="card-body">
                        <div style="
                            width: 56px; height: 56px;
                            border-radius: 1rem;
                            background: var(--gold-dim);
                            display: flex; align-items: center; justify-content: center;
                            margin: 0 auto 1.25rem;
                        ">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2">
                                <?= $item['icon'] ?>
                            </svg>
                        </div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.9rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">
                            <?= htmlspecialchars($item['title']) ?>
                        </h3>
                        <?php if ($item['link']): ?>
                            <a href="<?= $item['link'] ?>" style="color: var(--navy-primary); font-size: 0.9rem; font-weight: 500;">
                                <?= $item['content'] ?>
                            </a>
                        <?php else: ?>
                            <p style="font-size: 0.875rem; margin: 0;"><?= $item['content'] ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center reveal" style="margin-top: 2.5rem;">
            <a href="/pages/kontakt.php" class="btn btn-navy">Kontaktformular öffnen</a>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
