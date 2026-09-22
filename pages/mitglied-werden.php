<?php
/**
 * Athletikclub Steiermark – Mitglied werden
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Mitglied werden';
$meta_description = 'Werde Mitglied beim Athletikclub Steiermark. Zugang zu unserem Outdoor-Athletikpark und einer aktiven Sportgemeinschaft, schon ab 26 € pro Jahr.';
require_once ROOT_PATH . '/includes/header.php';

$success = false;
$errors  = [];

$mitgliedschaftsarten = [
    'regulaer'   => 'Regulär: 75 € pro Jahr',
    'ermaessigt' => 'Ermäßigt (bis 27J / Pension): 48 € pro Jahr',
    'sonder'     => 'Kinder & Menschen mit Behinderung: 26 € pro Jahr',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $vorname        = trim($_POST['vorname'] ?? '');
    $nachname       = trim($_POST['nachname'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $telefon        = trim($_POST['telefon'] ?? '');
    $geburtsjahr    = trim($_POST['geburtsjahr'] ?? '');
    $mitgliedschaft = $_POST['mitgliedschaftsart'] ?? '';
    $sportinteressen = trim($_POST['sportinteressen'] ?? '');

    if (empty($vorname) || mb_strlen($vorname) < 2) $errors['vorname'] = 'Bitte gib deinen Vornamen ein.';
    if (empty($nachname) || mb_strlen($nachname) < 2) $errors['nachname'] = 'Bitte gib deinen Nachnamen ein.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    if (empty($geburtsjahr) || !ctype_digit($geburtsjahr) || (int)$geburtsjahr < 1900 || (int)$geburtsjahr > (int)date('Y')) {
        $errors['geburtsjahr'] = 'Bitte gib ein gültiges Geburtsjahr ein.';
    }
    if (!isset($mitgliedschaftsarten[$mitgliedschaft])) $errors['mitgliedschaftsart'] = 'Bitte wähle eine Mitgliedschaftsart.';

    if (empty($errors)) {
        try {
            $betreff = 'Mitgliedschaftsantrag: ' . ($mitgliedschaftsarten[$mitgliedschaft] ?? '');
            $nachricht = "Geburtsjahr: {$geburtsjahr}\n"
                       . "Telefon: " . ($telefon ?: 'keine Angabe') . "\n"
                       . "Mitgliedschaftsart: {$mitgliedschaftsarten[$mitgliedschaft]}\n"
                       . "Sportinteressen: " . ($sportinteressen ?: 'keine Angabe');

            $db = getDB();
            $db->prepare('INSERT INTO kontakt_anfragen (organization_id, name, email, betreff, nachricht) VALUES (?, ?, ?, ?, ?)')
               ->execute([currentOrgId(), $vorname . ' ' . $nachname, $email, $betreff, $nachricht]);

            $mail_body  = "Neuer Mitgliedschaftsantrag über die Website:\n\n";
            $mail_body .= "Name: {$vorname} {$nachname}\nE-Mail: {$email}\n\n{$nachricht}";
            @mail(MAIL_ADMIN, "Neuer Mitgliedschaftsantrag: {$vorname} {$nachname}", $mail_body, 'From: ' . MAIL_FROM);

            $success = true;
        } catch (Exception $e) {
            $errors['general'] = 'Antrag konnte nicht gesendet werden. Bitte versuche es später erneut.';
        }
    }
}
?>

<!-- ============================================================
     HERO
============================================================ -->
<section class="hero" id="hero" style="min-height: 60vh;">
    <div class="hero-bg">
        <div style="position: absolute; inset: 0; background: linear-gradient(135deg, #0D1F35 0%, #1F3556 40%, #2A4570 70%, #1F3556 100%);"></div>
    </div>
    <div class="hero-pattern"></div>

    <div class="container" style="position: relative; z-index: 2; width: 100%;">
        <div class="hero-content" style="max-width: 720px;">
            <div class="hero-eyebrow reveal">
                <div class="hero-eyebrow-line"></div>
                <span>Mitglied werden</span>
                <div class="hero-eyebrow-line"></div>
            </div>
            <h1 class="hero-title reveal reveal-delay-1">
                Teil einer starken <em>Community</em> werden
            </h1>
            <p class="hero-subtitle reveal reveal-delay-2">
                Zugang zu unserem Outdoor-Athletikpark, vielseitigem Trainingsangebot und einer
                aktiven Sportgemeinschaft. Offen für alle Levels.
            </p>
            <div class="hero-actions reveal reveal-delay-3">
                <a href="#antrag" class="btn btn-primary btn-xl">Mitglied werden</a>
            </div>

            <div class="reveal reveal-delay-4" style="display: flex; gap: 2.5rem; margin-top: 3rem; flex-wrap: wrap;">
                <div>
                    <div style="font-family: 'Montserrat', sans-serif; font-size: 2rem; font-weight: 900; color: var(--gold-accent);">ab 26 €</div>
                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Mitgliedschaft pro Jahr</div>
                </div>
                <div>
                    <div style="font-family: 'Montserrat', sans-serif; font-size: 2rem; font-weight: 900; color: var(--gold-accent);">3+</div>
                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Outdoor-Athletik, Kraft, Calisthenics</div>
                </div>
                <div>
                    <div style="font-family: 'Montserrat', sans-serif; font-size: 2rem; font-weight: 900; color: var(--gold-accent);">Alle Levels</div>
                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Einsteiger bis Fortgeschrittene</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     DER ATHLETIKPARK
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Der Athletikpark</span>
            <h2 class="section-title reveal reveal-delay-1">Optimale Bedingungen für dein Training</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Eine private Trainingsanlage, die vom Präsidenten des Vereins betreut und der
                Community kostenfrei zur Verfügung gestellt wird.
            </p>
        </div>

        <div class="grid-3">
            <?php
            $merkmale = [
                ['nr' => '01', 'icon' => '🏋️', 'title' => 'Krafttraining', 'desc' => 'Funktionelles Krafttraining im Freien, vielseitig und effektiv für alle Levels.'],
                ['nr' => '02', 'icon' => '🤸', 'title' => 'Calisthenics',  'desc' => 'Stangen, Barren und Kletterstrukturen für Training mit dem eigenen Körpergewicht.'],
                ['nr' => '03', 'icon' => '⚡', 'title' => 'Athletik',      'desc' => 'Sprung-, Schnelligkeits- und Koordinationsübungen für umfassende Entwicklung.'],
                ['nr' => '04', 'icon' => '🌳', 'title' => 'Outdoor',      'desc' => 'Trainieren in frischer Luft, offen und einladend für alle.'],
                ['nr' => '05', 'icon' => '🤝', 'title' => 'Unverbindlich','desc' => 'Interessierte können den Verein jederzeit unverbindlich kennenlernen.'],
                ['nr' => '06', 'icon' => '🛡️', 'title' => 'Betreut',      'desc' => 'Persönlich betreut vom Vereinspräsidenten, sicher und gepflegt.'],
            ];
            foreach ($merkmale as $i => $m): ?>
                <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
                    <div class="card-body">
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                            <span style="font-family: 'Montserrat', sans-serif; font-size: 0.75rem; font-weight: 700; color: var(--text-muted);"><?= $m['nr'] ?></span>
                            <span style="font-size: 1.5rem;"><?= $m['icon'] ?></span>
                        </div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem;"><?= e($m['title']) ?></h3>
                        <p style="font-size: 0.875rem; margin: 0;"><?= e($m['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     MITGLIEDSCHAFTSBEITRÄGE
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Mitgliedschaftsbeiträge</span>
            <h2 class="section-title reveal reveal-delay-1">Fair und transparent</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Jährliche Beiträge, erschwinglich für jeden, unabhängig von Alter und
                Lebenssituation. Alle Beiträge berechtigen zur Nutzung des Athletikparks sowie
                zur Teilnahme am gesamten Vereinsleben.
            </p>
        </div>

        <div class="grid-3">
            <?php
            $beitraege = [
                ['label' => 'Regulär',    'sub' => 'Alle Mitglieder',                    'preis' => '75 €', 'highlight' => true],
                ['label' => 'Ermäßigt',   'sub' => 'Bis 27 Jahre & Pensionist*innen',     'preis' => '48 €', 'highlight' => false],
                ['label' => 'Sonderbeitrag', 'sub' => 'Kinder & Menschen mit Behinderung', 'preis' => '26 €', 'highlight' => false],
            ];
            foreach ($beitraege as $i => $b): ?>
                <div class="card reveal reveal-delay-<?= $i + 1 ?>" style="text-align: center; <?= $b['highlight'] ? 'border: 2px solid var(--gold-accent);' : '' ?>">
                    <div class="card-body">
                        <?php if ($b['highlight']): ?>
                            <span class="badge badge-gold" style="margin-bottom: 1rem;">Beliebt</span>
                        <?php endif; ?>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.25rem;"><?= e($b['label']) ?></h3>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.25rem;"><?= e($b['sub']) ?></p>
                        <div style="font-family: 'Montserrat', sans-serif; font-size: 2.25rem; font-weight: 900; color: var(--navy-primary);"><?= $b['preis'] ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.5rem;">pro Jahr</div>
                        <a href="#antrag" class="btn <?= $b['highlight'] ? 'btn-primary' : 'btn-navy' ?> w-full">Jetzt Mitglied werden</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     CTA – JETZT DABEI SEIN
============================================================ -->
<section class="cta-section">
    <div class="container" style="position: relative; z-index: 1;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; align-items: center; text-align: left;">
            <div class="reveal">
                <span class="section-label" style="color: var(--gold-accent);">Jetzt dabei sein</span>
                <h2 class="section-title section-title--white" style="margin-bottom: 1.25rem;">
                    Werde Teil einer aktiven Gemeinschaft
                </h2>
                <p class="section-subtitle section-subtitle--white" style="margin: 0 0 2rem;">
                    Erlebe, was polysportive Bewegungskultur wirklich bedeutet: gemeinsam, offen
                    und aktiv.
                </p>
                <a href="#antrag" class="btn btn-primary btn-xl">Mitglied werden</a>
            </div>
            <div class="reveal reveal-delay-1">
                <?php
                $vorteile = [
                    'Sofortiger Zugang zum Outdoor-Athletikpark und allen Vereinsangeboten',
                    'Individuelle Kursangebote für alle Altersgruppen und Fitnesslevel',
                    'Aktive Community aus Menschen, die Bewegung, Vielfalt und Respekt teilen',
                    'Netzwerktreffen jeden 2. Samstag im Monat, ab Mai 2026',
                ];
                foreach ($vorteile as $v): ?>
                    <div style="display: flex; gap: 0.75rem; align-items: flex-start; margin-bottom: 1.25rem;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold-accent)" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;"><polyline points="20 6 9 17 4 12"/></svg>
                        <span style="color: rgba(255,255,255,0.85); font-size: 0.95rem; line-height: 1.6;"><?= e($v) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     ANTRAGSFORMULAR
============================================================ -->
<section class="section bg-white" id="antrag">
    <div class="container">
        <div style="max-width: 640px; margin: 0 auto;">
            <div class="text-center" style="margin-bottom: 2.5rem;">
                <span class="section-label reveal">Jetzt dabei sein</span>
                <h2 class="section-title reveal reveal-delay-1">Mitgliedschaft beantragen</h2>
                <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                    Füll das Formular aus. Wir melden uns so bald wie möglich bei dir und
                    begrüßen dich in unserer Community.
                </p>
            </div>

            <div class="reveal reveal-delay-3">
                <?php if ($success): ?>
                    <div style="text-align: center; padding: 3rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Danke für deinen Antrag!</h3>
                        <p>Wir melden uns so schnell wie möglich bei dir.</p>
                        <a href="<?= APP_URL ?>/" class="btn btn-navy" style="margin-top: 1.5rem;">Zurück zur Startseite</a>
                    </div>
                <?php else: ?>
                <div class="form-card">
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                        Alle Felder außer Telefon und Sportinteressen sind Pflichtfelder.
                    </p>

                    <?php if (!empty($errors['general'])): ?>
                        <div class="flash-message flash-error" style="border-radius: 0.5rem; margin-bottom: 1rem;"><span><?= e($errors['general']) ?></span></div>
                    <?php endif; ?>

                    <form method="POST" action="#antrag" data-validate novalidate>
                        <?= csrfField() ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="vorname">Vorname <span class="required">*</span></label>
                                <input class="form-control <?= isset($errors['vorname']) ? 'error' : '' ?>" type="text" id="vorname" name="vorname" value="<?= e($_POST['vorname'] ?? '') ?>" required placeholder="Maria">
                                <?php if (isset($errors['vorname'])): ?><span class="form-error"><?= e($errors['vorname']) ?></span><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="nachname">Nachname <span class="required">*</span></label>
                                <input class="form-control <?= isset($errors['nachname']) ? 'error' : '' ?>" type="text" id="nachname" name="nachname" value="<?= e($_POST['nachname'] ?? '') ?>" required placeholder="Muster">
                                <?php if (isset($errors['nachname'])): ?><span class="form-error"><?= e($errors['nachname']) ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">E-Mail <span class="required">*</span></label>
                            <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>" type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="maria@beispiel.at">
                            <?php if (isset($errors['email'])): ?><span class="form-error"><?= e($errors['email']) ?></span><?php endif; ?>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="telefon">Telefon (optional)</label>
                                <input class="form-control" type="tel" id="telefon" name="telefon" value="<?= e($_POST['telefon'] ?? '') ?>" placeholder="+43 660 …">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="geburtsjahr">Geburtsjahr <span class="required">*</span></label>
                                <input class="form-control <?= isset($errors['geburtsjahr']) ? 'error' : '' ?>" type="number" id="geburtsjahr" name="geburtsjahr" value="<?= e($_POST['geburtsjahr'] ?? '') ?>" min="1900" max="<?= date('Y') ?>" required placeholder="1995">
                                <?php if (isset($errors['geburtsjahr'])): ?><span class="form-error"><?= e($errors['geburtsjahr']) ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="mitgliedschaftsart">Mitgliedschaftsart <span class="required">*</span></label>
                            <select class="form-control <?= isset($errors['mitgliedschaftsart']) ? 'error' : '' ?>" id="mitgliedschaftsart" name="mitgliedschaftsart" required>
                                <option value="">Bitte wählen …</option>
                                <?php foreach ($mitgliedschaftsarten as $val => $label): ?>
                                    <option value="<?= e($val) ?>" <?= (($_POST['mitgliedschaftsart'] ?? '') === $val) ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['mitgliedschaftsart'])): ?><span class="form-error"><?= e($errors['mitgliedschaftsart']) ?></span><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="sportinteressen">Sportinteressen <span style="color: var(--text-muted); font-weight: 400;">(optional)</span></label>
                            <input class="form-control" type="text" id="sportinteressen" name="sportinteressen" value="<?= e($_POST['sportinteressen'] ?? '') ?>" placeholder="z.B. Calisthenics, Athletiktraining">
                        </div>

                        <button type="submit" class="btn btn-primary w-full btn-lg">Mitgliedschaft beantragen</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
