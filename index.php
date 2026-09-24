<?php
/**
 * Athletikclub Steiermark – Startseite
 */
define('ROOT_PATH', __DIR__);
$page_title       = 'Sport & Training in St. Georgen an der Stiefing';
$meta_description = 'Willkommen beim Athletikclub Steiermark. Ganzheitliches Athletik- und polysportives Training in St. Georgen an der Stiefing, Steiermark.';

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
                #1F3556 45%,
                #24406A 100%);
        "></div>
    </div>

    <!-- Aurora-Blobs (Sportfarben-Mesh) -->
    <div class="aurora-blob" style="width: 620px; height: 620px; background: #0055D4; top: -12%; left: -8%; animation-delay: 0s;"></div>
    <div class="aurora-blob" style="width: 520px; height: 520px; background: #C6A135; bottom: -14%; right: -6%; animation-delay: -4s;"></div>
    <div class="aurora-blob" style="width: 420px; height: 420px; background: #7C3AED; top: 30%; right: 18%; animation-delay: -8s;"></div>
    <div class="aurora-blob" style="width: 380px; height: 380px; background: #00A896; bottom: 8%; left: 30%; animation-delay: -12s;"></div>

    <div class="container" style="position: relative; z-index: 2; width: 100%;">
        <div class="hero-grid">

            <!-- Hero Content -->
            <div class="hero-content" style="max-width: 620px;">
                <div class="hero-eyebrow reveal">
                    <div class="hero-eyebrow-line"></div>
                    <span>Athletikclub Steiermark</span>
                    <div class="hero-eyebrow-line"></div>
                </div>

                <h1 class="hero-title reveal reveal-delay-1">
                    Beweg dich.
                    <em>Auf deine Art.</em>
                </h1>

                <p class="hero-subtitle reveal reveal-delay-2" style="max-width: 520px;">
                    Ganzheitliches Athletik- und polysportives Training in St. Georgen an der Stiefing.
                    Vier Disziplinen, ein Verein, unendlich viele Wege, dich zu bewegen.
                </p>

                <div class="hero-actions reveal reveal-delay-3">
                    <a href="/pages/mitglied-werden.php" class="btn btn-primary btn-xl">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                        Mitglied werden
                    </a>
                    <a href="/pages/vision.php" class="btn btn-ghost btn-xl">
                        Unsere Vision
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>
            </div>

            <!-- 3D-Karten-Cluster -->
            <div class="hero-art reveal reveal-delay-4" aria-hidden="true">
                <?php
                $art_cards = [
                    ['icon' => '🤸', 'name' => 'Calisthenics',    'color' => '#0055D4'],
                    ['icon' => '🛹', 'name' => 'Skateboarding',   'color' => '#FF7B37'],
                    ['icon' => '🏓', 'name' => 'Tischtennis',     'color' => '#00A896'],
                    ['icon' => '🎾', 'name' => 'Padel Tennis',    'color' => '#4EBA6F'],
                ];
                foreach ($art_cards as $i => $c): ?>
                    <div class="hero-art-card hero-art-card-<?= $i + 1 ?>">
                        <div class="hero-art-icon" style="background: <?= $c['color'] ?>1a; box-shadow: inset 0 0 0 1px <?= $c['color'] ?>4d;"><?= $c['icon'] ?></div>
                        <span><?= htmlspecialchars($c['name']) ?></span>
                    </div>
                <?php endforeach; ?>
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
     DISZIPLIN-MARQUEE
============================================================ -->
<div class="marquee-strip">
    <div class="marquee-track">
        <?php
        $marquee_items = ['Calisthenics', 'Skateboarding', 'Tischtennis', 'Padel Tennis'];
        // Gerade Anzahl Wiederholungen, damit die -50%-Animation nahtlos loopt
        for ($r = 0; $r < 6; $r++):
            foreach ($marquee_items as $mi): ?>
                <span class="marquee-item"><?= htmlspecialchars($mi) ?></span>
                <span class="marquee-dot">✦</span>
            <?php endforeach;
        endfor; ?>
    </div>
</div>

<style>
/* ---------- Hero: Aurora-Blobs + 3D-Karten-Cluster ---------- */
.hero-grid {
    display: grid;
    grid-template-columns: 1.05fr 1fr;
    gap: 2rem;
    align-items: center;
}
.aurora-blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.35;
    z-index: 1;
    animation: auroraFloat 16s ease-in-out infinite;
    pointer-events: none;
}
@keyframes auroraFloat {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33%      { transform: translate(3%, -4%) scale(1.08); }
    66%      { transform: translate(-3%, 3%) scale(0.96); }
}
.hero-art {
    position: relative;
    height: 480px;
    perspective: 1400px;
}
.hero-art-card {
    position: absolute;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.9rem 1.25rem;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 1.1rem;
    backdrop-filter: blur(14px);
    box-shadow: 0 20px 45px rgba(0,0,0,0.28);
    font-family: 'Montserrat', sans-serif;
    font-size: 0.85rem;
    font-weight: 700;
    color: rgba(255,255,255,0.92);
    white-space: nowrap;
    transform-style: preserve-3d;
    animation: cardFloat 7s ease-in-out infinite;
}
.hero-art-icon {
    width: 34px; height: 34px;
    border-radius: 0.65rem;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem;
    flex-shrink: 0;
}
.hero-art-card-1 { top: 6%;  left: 6%;  transform: perspective(1400px) rotateY(-12deg) rotateX(6deg) rotateZ(-4deg); animation-delay: 0s; }
.hero-art-card-2 { top: 28%; right: 0%; transform: perspective(1400px) rotateY(14deg) rotateX(-4deg) rotateZ(3deg); animation-delay: -1.75s; }
.hero-art-card-3 { top: 52%; left: 0%;  transform: perspective(1400px) rotateY(-10deg) rotateX(-6deg) rotateZ(2deg); animation-delay: -3.5s; }
.hero-art-card-4 { top: 76%; right: 8%; transform: perspective(1400px) rotateY(10deg) rotateX(6deg) rotateZ(-3deg); animation-delay: -5.25s; }
@keyframes cardFloat {
    0%, 100% { margin-top: 0; }
    50%      { margin-top: -14px; }
}
@media (max-width: 900px) {
    .hero-grid { grid-template-columns: 1fr; }
    .hero-art { display: none; }
}
.marquee-strip {
    background: var(--navy-deeper, #0D1F35);
    overflow: hidden;
    white-space: nowrap;
    padding: 1.1rem 0;
    border-bottom: 1px solid rgba(198,161,53,0.15);
}
.marquee-track {
    display: inline-flex;
    align-items: center;
    animation: marqueeScroll 72s linear infinite;
}
.marquee-item {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(255,255,255,0.55);
    padding: 0 1.25rem;
}
.marquee-dot {
    color: var(--gold-accent, #C6A135);
    font-size: 0.7rem;
}
@keyframes marqueeScroll {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
}
@media (prefers-reduced-motion: reduce) {
    .marquee-track { animation: none; }
    .aurora-blob, .hero-art-card { animation: none !important; }
}
/* ---------- Sportarten: "Mehr erfahren" → Coming-soon-Hinweis ---------- */
.sport-more-btn {
    display: inline-flex; align-items: center; gap: 0.35rem;
    margin-top: 1.25rem; padding: 0;
    background: none; border: 0; cursor: pointer;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    transition: gap 0.2s;
}
.sport-more-btn:hover { gap: 0.6rem; }
.sport-more-btn svg { transition: transform 0.2s; }
.sport-more-btn[aria-expanded="true"] svg { transform: rotate(90deg); }
.sport-soon {
    margin: 0.85rem 0 0;
    padding: 0.7rem 0.9rem;
    border-left: 3px solid;
    border-radius: 0.5rem;
    background: var(--bg-muted);
    color: var(--text-secondary);
    font-size: 0.85rem;
    line-height: 1.5;
    animation: sportSoonIn 0.25s ease-out;
}
.sport-soon strong {
    display: block;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 0.15rem;
}
@keyframes sportSoonIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: none; }
}
</style>

<!-- ============================================================
     SPORTARTEN / DISZIPLINEN
============================================================ -->
<section class="section bg-white" id="sportarten">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem;">
            <span class="section-label reveal">Unsere Disziplinen</span>
            <h2 class="section-title reveal reveal-delay-1">Trainiere, was dich bewegt</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Polysportives Training für Kraft, Ausdauer, Koordination und Beweglichkeit,
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
                    'desc'        => 'Reaktionsstärke und taktisches Gespür, Training für alle Altersgruppen.',
                    'color'       => '#00A896',
                    'color_pale'  => 'rgba(0,168,150,0.08)',
                ],
                [
                    'icon'        => '🎾',
                    'name'        => 'Padel Tennis',
                    'desc'        => 'Der Trendsport schlechthin: strategisch, dynamisch, ideal für Team-Spirit.',
                    'color'       => '#4EBA6F',
                    'color_pale'  => 'rgba(78,186,111,0.08)',
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
                    <!-- Detailseiten sind in Konzeption: Klick zeigt "Coming soon"-Hinweis -->
                    <button type="button" class="sport-more-btn" style="color: <?= $d['color'] ?>;"
                            aria-expanded="false" aria-controls="sport-soon-<?= $i ?>"
                            onclick="var n=document.getElementById('sport-soon-<?= $i ?>'), open=n.hidden; n.hidden=!open; this.setAttribute('aria-expanded', open);">
                        Mehr erfahren
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                    <p class="sport-soon" id="sport-soon-<?= $i ?>" role="status" hidden
                       style="border-color: <?= $d['color'] ?>;">
                        <strong style="color: <?= $d['color'] ?>;">Coming soon</strong>
                        Unser <?= htmlspecialchars($d['name']) ?>-Angebot ist gerade in Konzeption. Details folgen in Kürze!
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     PHILOSOPHIE-TEASER
============================================================ -->
<section class="bg-navy" style="padding: 6rem 0; position: relative; overflow: hidden;">
    <div style="
        position: absolute; width: 500px; height: 500px;
        border-radius: 50%; border: 1px solid rgba(198,161,53,0.1);
        top: -150px; left: -150px;
    "></div>
    <div class="container" style="position: relative;">
        <div class="reveal" style="max-width: 780px; margin: 0 auto; text-align: center;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="var(--gold-accent)" style="margin: 0 auto 1.75rem;"><path d="M9.983 3v7.391c0 5.704-3.731 9.57-8.983 10.609l-.995-2.151c2.432-.917 3.995-3.638 3.995-5.849h-4v-10h9.983zm14.017 0v7.391c0 5.704-3.748 9.571-9 10.609l-.996-2.151c2.433-.917 3.996-3.638 3.996-5.849h-4v-10h10z"/></svg>
            <p style="font-size: clamp(1.3rem, 2.4vw, 1.75rem); line-height: 1.6; color: white; font-weight: 300;">
                Ein vielseitig bewegter Mensch ist gesünder, anpassungsfähiger und
                leistungsfähiger. Deshalb glauben wir an polysportive Entwicklung statt
                früher Spezialisierung.
            </p>
        </div>

        <div class="reveal reveal-delay-1" style="display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap; margin-top: 3rem;">
            <a href="/pages/vision.php" class="btn btn-ghost">Unsere Vision</a>
            <a href="/pages/mission.php" class="btn btn-ghost">Unsere Mission</a>
            <a href="/pages/leitbild.php" class="btn btn-ghost">Unser Leitbild</a>
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
                    position: relative;
                    overflow: hidden;
                ">
                    <div style="position: absolute; inset: 0; opacity: 0.06; background-image: linear-gradient(rgba(255,255,255,0.5) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.5) 1px, transparent 1px); background-size: 32px 32px;"></div>

                    <!-- Präsidium-Portraits -->
                    <img src="<?= APP_URL ?>/assets/images/team/jacob-kysela.jpg" alt="Jacob Kysela"
                         style="
                            position: absolute; width: 44%; aspect-ratio: 3/4; object-fit: cover; object-position: center 30%;
                            border-radius: 1rem; border: 4px solid rgba(255,255,255,0.9);
                            box-shadow: 0 20px 40px rgba(0,0,0,0.35);
                            left: 8%; top: 12%; z-index: 2;
                         ">
                    <img src="<?= APP_URL ?>/assets/images/team/christof-oster.jpeg" alt="Christof Oster"
                         style="
                            position: absolute; width: 44%; aspect-ratio: 3/4; object-fit: cover; object-position: center 30%;
                            border-radius: 1rem; border: 4px solid rgba(255,255,255,0.9);
                            box-shadow: 0 20px 40px rgba(0,0,0,0.35);
                            right: 8%; bottom: 10%; z-index: 1;
                         ">
                </div>
            </div>

            <div class="reveal reveal-delay-1">
                <span class="section-label">Über uns</span>
                <h2 class="section-title" style="margin-bottom: 1.25rem;">
                    Dein Verein für ganzheitliche Athletik
                </h2>
                <p style="margin-bottom: 1.25rem; font-size: 1.05rem; line-height: 1.85;">
                    Der <strong>Athletikclub Steiermark</strong> ist dein Zuhause für
                    polysportives Training in der Steiermark. Wir fördern Kraft, Ausdauer,
                    Beweglichkeit und Koordination für alle Leistungsniveaus.
                </p>
                <p style="margin-bottom: 2rem; line-height: 1.85;">
                    Geführt von Jacob Kysela und Christof Oster begleitet dich unser Team auf
                    deinem persönlichen Weg, egal ob du gerade erst anfängst oder als
                    ambitionierter Sportler deine Leistung optimieren möchtest.
                </p>

                <div class="feature-list">
                    <?php
                    $features = [
                        ['icon' => 'users',      'title' => 'Starke Gemeinschaft',      'desc' => 'Ein Verein, der Bewegung, Begegnung und Entwicklung zusammenbringt.'],
                        ['icon' => 'award',      'title' => 'Qualifizierte Trainer',    'desc' => 'Zertifizierte Übungsleiter mit Leidenschaft für ihren Sport.'],
                        ['icon' => 'activity',   'title' => 'Ganzheitliches Training',  'desc' => 'Vier Disziplinen für ein breites, ausgewogenes Sportprogramm.'],
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
        <div class="grid-3">
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
                            <a href="<?= $item['link'] ?>" style="color: var(--text-primary); font-size: 0.9rem; font-weight: 500;">
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
