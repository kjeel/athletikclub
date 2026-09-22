<?php
/**
 * Athletikclub Steiermark – Team & Präsidium
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Team & Präsidium';
$meta_description = 'Das Präsidium und Trainer-Team des Athletikclub Steiermark.';
require_once ROOT_PATH . '/includes/header.php';

$db = getDB();
$trainer = [];
try {
    $trainer = $db->query(
        "SELECT u.vorname, u.nachname, tp.bio, tp.sportarten
         FROM users u
         JOIN trainer_profile tp ON tp.user_id = u.id
         WHERE u.rolle IN ('trainer','admin') AND u.aktiv = 1
         ORDER BY u.vorname"
    )->fetchAll();
} catch (Exception $e) {
    // DB evtl. nicht bereit – ignorieren
}
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Team &amp; Präsidium</span>
        </nav>
        <h1>Team &amp; Präsidium</h1>
        <p>Die Menschen hinter dem Athletikclub Steiermark.</p>
    </div>
</section>

<!-- Präsidium -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2.5rem;">
            <span class="section-label reveal">Vorstand</span>
            <h2 class="section-title reveal reveal-delay-1">Präsidium</h2>
        </div>

        <div class="grid-2" style="max-width: 640px; margin-inline: auto;">
            <?php
            $praesidium = [
                ['name' => 'Jacob Kysela',  'funktion' => 'Präsident / Geschäftsführer', 'foto' => 'jacob-kysela.jpg'],
                ['name' => 'Christof Oster', 'funktion' => 'Präsident / Geschäftsführer', 'foto' => 'christof-oster.jpeg'],
            ];
            foreach ($praesidium as $i => $p): ?>
                <div class="card reveal reveal-delay-<?= $i + 1 ?>" style="text-align: center;">
                    <img
                        src="<?= APP_URL ?>/assets/images/team/<?= e($p['foto']) ?>"
                        alt="<?= e($p['name']) ?>"
                        style="width: 100%; aspect-ratio: 4/5; object-fit: cover; object-position: center 30%; display: block;"
                    >
                    <div class="card-body">
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1.05rem; font-weight: 700; margin-bottom: 0.35rem;"><?= e($p['name']) ?></h3>
                        <p style="font-size: 0.85rem; color: var(--gold-accent); font-weight: 600; margin: 0;"><?= e($p['funktion']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Trainer-Team (aus DB) -->
<section class="section bg-light">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2.5rem;">
            <span class="section-label reveal">Unsere Trainer*innen</span>
            <h2 class="section-title reveal reveal-delay-1">Das Trainer-Team</h2>
        </div>

        <?php if (empty($trainer)): ?>
            <div class="card" style="border-style: dashed; text-align: center; max-width: 640px; margin-inline: auto;">
                <div class="card-body">
                    <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">
                        Sobald Trainer*innen-Konten angelegt sind, erscheinen sie hier automatisch.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="grid-3">
                <?php foreach ($trainer as $i => $t): ?>
                    <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>" style="text-align: center;">
                        <div class="card-body">
                            <div style="width: 64px; height: 64px; border-radius: 50%; background: var(--navy-primary); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 1.1rem;">
                                <?= htmlspecialchars(mb_substr($t['vorname'], 0, 1) . mb_substr($t['nachname'], 0, 1)) ?>
                            </div>
                            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.25rem;">
                                <?= htmlspecialchars($t['vorname'] . ' ' . $t['nachname']) ?>
                            </h3>
                            <?php if ($t['sportarten']): ?>
                                <p style="font-size: 0.8rem; color: var(--gold-accent); font-weight: 600; margin-bottom: 0.5rem;"><?= htmlspecialchars($t['sportarten']) ?></p>
                            <?php endif; ?>
                            <?php if ($t['bio']): ?>
                                <p style="font-size: 0.85rem; margin: 0;"><?= htmlspecialchars($t['bio']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
