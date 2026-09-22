<?php
/**
 * Athletikclub Steiermark – Trainer*in werden
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Trainer*in werden';
$meta_description = 'Werde Trainer*in beim Athletikclub Steiermark und gib deine Leidenschaft für Sport an unsere Mitglieder weiter.';
require_once ROOT_PATH . '/includes/header.php';

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $sportart  = trim($_POST['sportart'] ?? '');
    $nachricht = trim($_POST['nachricht'] ?? '');

    if (empty($name) || mb_strlen($name) < 2) $errors['name'] = 'Bitte gib deinen Namen ein.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    if (empty($nachricht) || mb_strlen($nachricht) < 10) $errors['nachricht'] = 'Bitte schreibe mindestens 10 Zeichen über dich und deine Erfahrung.';

    if (empty($errors)) {
        try {
            $betreff = 'Trainer-Bewerbung' . ($sportart !== '' ? ' – ' . $sportart : '');
            $db = getDB();
            $db->prepare('INSERT INTO kontakt_anfragen (name, email, betreff, nachricht) VALUES (?, ?, ?, ?)')
               ->execute([$name, $email, $betreff, $nachricht]);

            $mail_body  = "Neue Trainer-Bewerbung über die Website:\n\n";
            $mail_body .= "Name: {$name}\nE-Mail: {$email}\nSportart/Bereich: {$sportart}\n\nNachricht:\n{$nachricht}";
            @mail(MAIL_ADMIN, "Neue Trainer-Bewerbung: {$name}", $mail_body, 'From: ' . MAIL_FROM);
            $success = true;
        } catch (Exception $e) {
            $errors['general'] = 'Fehler beim Senden. Bitte versuche es später erneut.';
        }
    }
}

$sportarten = ['Calisthenics', 'Skateboarding', 'Tischtennis', 'Padel Tennis', 'Athletiktraining', 'Ausdauer', 'Sonstiges'];
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Trainer*in werden</span>
        </nav>
        <h1>Trainer*in werden</h1>
        <p>Gib deine Leidenschaft für Sport weiter und werde Teil unseres Trainer-Teams.</p>
    </div>
</section>

<!-- ============================================================
     WARUM TRAINER*IN BEIM ATHLETIKCLUB STEIERMARK
============================================================ -->
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
                    <div style="
                        width: 200px; height: 200px;
                        border-radius: 50%;
                        border: 3px solid rgba(198,161,53,0.3);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    ">
                        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="1.5">
                            <circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>
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
                        <div style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 900; color: #0D1F35; line-height: 1;">10+</div>
                        <div style="font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(13,31,53,0.7);">Trainer im Team</div>
                    </div>
                </div>
            </div>

            <div class="reveal reveal-delay-1">
                <span class="section-label">Werde Teil des Teams</span>
                <h2 class="section-title" style="margin-bottom: 1.25rem;">
                    Trainer*in beim Athletikclub Steiermark
                </h2>
                <p style="margin-bottom: 1.25rem; font-size: 1.05rem; line-height: 1.85;">
                    Du brennst für deinen Sport und möchtest diese Begeisterung weitergeben?
                    Beim Athletikclub Steiermark unterrichtest du in einem von sechs Disziplinen und begleitest
                    Mitglieder vom Einstieg bis zum Leistungssport.
                </p>
                <p style="margin-bottom: 2rem; line-height: 1.85;">
                    Wir legen Wert auf ein familiäres Trainer-Team, flexible Trainingszeiten
                    und die Möglichkeit, dein eigenes Angebot mitzugestalten.
                </p>

                <div class="feature-list">
                    <?php
                    $vorteile = [
                        ['icon' => 'clock',    'title' => 'Flexible Zeiten',        'desc' => 'Trainingszeiten, die sich mit Job, Studium oder anderen Verpflichtungen vereinbaren lassen.'],
                        ['icon' => 'users',    'title' => 'Starkes Team',           'desc' => 'Austausch und Zusammenarbeit mit erfahrenen Trainer*innen aus allen Disziplinen.'],
                        ['icon' => 'trending', 'title' => 'Weiterentwicklung',      'desc' => 'Unterstützung bei Fort- und Weiterbildungen im Trainerbereich.'],
                    ];
                    foreach ($vorteile as $v): ?>
                        <div class="feature-item">
                            <div class="feature-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <?php
                                    $icons = [
                                        'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                                        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                                        'trending' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
                                    ];
                                    echo $icons[$v['icon']];
                                    ?>
                                </svg>
                            </div>
                            <div>
                                <h4 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.25rem;">
                                    <?= htmlspecialchars($v['title']) ?>
                                </h4>
                                <p style="font-size: 0.875rem; margin: 0; line-height: 1.6;">
                                    <?= htmlspecialchars($v['desc']) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     ANFORDERUNGEN
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem;">
            <span class="section-label reveal">Das bringst du mit</span>
            <h2 class="section-title reveal reveal-delay-1">Anforderungen</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Fachliche Qualifikation ist wichtig – genauso wichtig ist uns Motivation
                und Freude am Umgang mit Menschen.
            </p>
        </div>

        <div class="grid-3">
            <?php
            $anforderungen = [
                ['icon' => 'award',   'title' => 'Qualifikation', 'desc' => 'Trainerausbildung, Übungsleiterschein oder vergleichbare Qualifikation in deiner Sportart.'],
                ['icon' => 'heart',   'title' => 'Leidenschaft',  'desc' => 'Begeisterung für deinen Sport und die Motivation, diese an andere weiterzugeben.'],
                ['icon' => 'users',   'title' => 'Teamfähigkeit', 'desc' => 'Freude an der Arbeit mit Menschen jeden Alters und Leistungsniveaus.'],
            ];
            foreach ($anforderungen as $i => $a): ?>
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
                                <?php
                                $icons = [
                                    'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
                                    'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
                                    'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                                ];
                                echo $icons[$a['icon']];
                                ?>
                            </svg>
                        </div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem;">
                            <?= htmlspecialchars($a['title']) ?>
                        </h3>
                        <p style="font-size: 0.875rem; margin: 0;"><?= htmlspecialchars($a['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     BEWERBUNGSFORMULAR
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div style="max-width: 640px; margin: 0 auto;">
            <div class="text-center" style="margin-bottom: 2.5rem;">
                <span class="section-label reveal">Interesse geweckt?</span>
                <h2 class="section-title reveal reveal-delay-1">Jetzt bewerben</h2>
                <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                    Schreib uns kurz, welche Sportart du trainieren möchtest und welche
                    Erfahrung du mitbringst.
                </p>
            </div>

            <div class="reveal reveal-delay-3">
                <?php if ($success): ?>
                    <div style="text-align: center; padding: 3rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Danke für deine Bewerbung!</h3>
                        <p>Wir melden uns so schnell wie möglich bei dir.</p>
                        <a href="<?= APP_URL ?>/" class="btn btn-navy" style="margin-top: 1.5rem;">Zurück zur Startseite</a>
                    </div>
                <?php else: ?>
                <div class="form-card">
                    <?php if (!empty($errors['general'])): ?>
                        <div class="flash-message flash-error" style="border-radius: 0.5rem; margin-bottom: 1rem;"><span><?= e($errors['general']) ?></span></div>
                    <?php endif; ?>

                    <form method="POST" action="" data-validate novalidate>
                        <?= csrfField() ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="name">Name <span class="required">*</span></label>
                                <input class="form-control <?= isset($errors['name']) ? 'error' : '' ?>" type="text" id="name" name="name" value="<?= e($_POST['name'] ?? '') ?>" required placeholder="Dein Name">
                                <?php if (isset($errors['name'])): ?><span class="form-error"><?= e($errors['name']) ?></span><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="email">E-Mail <span class="required">*</span></label>
                                <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>" type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="deine@email.at">
                                <?php if (isset($errors['email'])): ?><span class="form-error"><?= e($errors['email']) ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="sportart">Sportart / Bereich</label>
                            <select class="form-control" id="sportart" name="sportart">
                                <option value="">Bitte wählen…</option>
                                <?php foreach ($sportarten as $s): ?>
                                    <option value="<?= e($s) ?>" <?= (($_POST['sportart'] ?? '') === $s) ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="nachricht">Über dich <span class="required">*</span></label>
                            <textarea class="form-control <?= isset($errors['nachricht']) ? 'error' : '' ?>" id="nachricht" name="nachricht" required rows="5" placeholder="Erzähl uns von deiner Erfahrung, Qualifikation und warum du Trainer*in beim Athletikclub Steiermark werden möchtest…"><?= e($_POST['nachricht'] ?? '') ?></textarea>
                            <?php if (isset($errors['nachricht'])): ?><span class="form-error"><?= e($errors['nachricht']) ?></span><?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-full btn-lg">Bewerbung absenden</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
