<?php
/**
 * Athletikclub Steiermark – Trainer*innen-Anbindung
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Trainer*in werden';
$meta_description = 'Trainiere selbstständig beim Athletikclub Steiermark. Infrastruktur, Kundenbasis und rechtlicher Rahmen sind inklusive, du bringst deine Expertise mit.';
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/kommunikation.php';

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $spam = formularSpamPruefen('trainer_bewerbung');

    $vorname       = trim($_POST['vorname'] ?? '');
    $nachname      = trim($_POST['nachname'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $qualifikation = trim($_POST['qualifikation'] ?? '');
    $nachricht     = trim($_POST['nachricht'] ?? '');

    if (empty($vorname) || mb_strlen($vorname) < 2) $errors['vorname'] = 'Bitte gib deinen Vornamen ein.';
    if (empty($nachname) || mb_strlen($nachname) < 2) $errors['nachname'] = 'Bitte gib deinen Nachnamen ein.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    if (empty($nachricht) || mb_strlen($nachricht) < 10) $errors['nachricht'] = 'Bitte schreib uns kurz von dir und deinen Vorstellungen.';

    if ($spam === 'limit') $errors['general'] = 'Zu viele Anfragen in kurzer Zeit. Bitte versuche es in einer Stunde erneut oder schreib uns direkt per E-Mail.';
    if ($spam === 'honeypot') {
        $success = true;
    } elseif (empty($errors)) {
        try {
            $betreff = 'Trainer-Anbindung' . ($qualifikation !== '' ? ': ' . $qualifikation : '');
            $name    = $vorname . ' ' . $nachname;
            $db = getDB();
            $db->prepare('INSERT INTO kontakt_anfragen (organization_id, name, email, betreff, nachricht) VALUES (?, ?, ?, ?, ?)')
               ->execute([currentOrgId(), $name, $email, $betreff, $nachricht]);
            $anfrage_id = (int)$db->lastInsertId();

            // Onboarding-Vorgang anlegen und Eingang bestätigen (Migration 012 evtl. noch nicht eingespielt)
            try {
                require_once ROOT_PATH . '/includes/onboarding.php';
                onboardingAnlegen($db, ['vorname' => $vorname, 'nachname' => $nachname, 'email' => $email, 'schwerpunkt' => $qualifikation ?: null, 'nachricht' => $nachricht], $anfrage_id);
                nachrichtAnAdresse($db, $email, $vorname, 'bewerbung_eingang', [], 'bewerbung:' . $anfrage_id);
                benachrichtigeAdmins($db, 'projekt', 'Neue Trainer-Bewerbung: ' . $name, $qualifikation ?: null, '/dashboard/admin/onboarding.php');
            } catch (Throwable $e) {}

            $mail_body  = "Neue Trainer-Anbindungsanfrage über die Website:\n\n";
            $mail_body .= "Name: {$name}\nE-Mail: {$email}\nQualifikation/Schwerpunkt: {$qualifikation}\n\nNachricht:\n{$nachricht}";
            mailSenden(getDB(), (verein()['email'] ?: MAIL_ADMIN), "Neue Trainer-Anfrage: {$name}", $mail_body, null, 'trainer_bewerbung', $email);
            $success = true;
        } catch (Exception $e) {
            $errors['general'] = 'Fehler beim Senden. Bitte versuche es später erneut.';
        }
    }
}
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
        <h1>Trainiere selbstständig. Mit unserem Rücken.</h1>
        <p>Du willst als Trainer*in durchstarten, ohne Vereinsgründung, ohne Bürokratie und ohne das Risiko allein zu tragen?</p>
    </div>
</section>

<!-- ============================================================
     INTRO + STICHWORTE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2.5rem; max-width: 720px; margin-inline: auto;">
            <span class="section-label reveal">Trainer*innen-Anbindung</span>
            <p class="section-subtitle reveal reveal-delay-1" style="margin: 0 auto;">
                Wir bieten dir die Infrastruktur, die Kunden und den rechtlichen Rahmen.
                Du bringst deine Expertise mit.
            </p>
        </div>

        <div class="reveal reveal-delay-2" style="display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 3.5rem;">
            <?php foreach (['Sofort einsatzbereit', 'Kein Gründungsaufwand', 'Faire Vergütung'] as $badge): ?>
                <span class="badge badge-gold" style="font-size: 0.8rem; padding: 0.5rem 1rem;"><?= e($badge) ?></span>
            <?php endforeach; ?>
        </div>

        <div class="grid-4">
            <?php
            $intro_cards = [
                ['icon' => '🚀', 'title' => 'Sofort loslegen',          'desc' => 'Kein Gründungsaufwand, keine Bürokratie, direkt starten.'],
                ['icon' => '📋', 'title' => 'Rechtlicher Rahmen',       'desc' => 'Versicherung, Verträge und Abrechnung über uns geregelt.'],
                ['icon' => '👥', 'title' => 'Bestehende Kundenbasis',   'desc' => 'Zugang zu unserem Mitglieder- und Interessentennetzwerk.'],
                ['icon' => '💶', 'title' => 'Faire Vergütung',          'desc' => 'Ein transparentes Provisionsmodell: du verdienst, was du leistest.'],
            ];
            foreach ($intro_cards as $i => $c): ?>
                <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
                    <div class="card-body">
                        <div style="font-size: 1.75rem; margin-bottom: 0.75rem;"><?= $c['icon'] ?></div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.4rem;"><?= e($c['title']) ?></h3>
                        <p style="font-size: 0.85rem; margin: 0;"><?= e($c['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     SO FUNKTIONIERT ES
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">So funktioniert es</span>
            <h2 class="section-title reveal reveal-delay-1">Von der Idee zum ersten Training in vier Schritten</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Der Einstieg ist unkompliziert. Wir begleiten dich durch den gesamten Prozess,
                damit du dich auf das Wesentliche konzentrieren kannst: dein Training.
            </p>
        </div>

        <div class="grid-4">
            <?php
            $schritte = [
                ['nr' => '01', 'title' => 'Erstes Gespräch',    'desc' => 'Du meldest dich bei uns, wir lernen uns kennen und besprechen deine Qualifikationen, Schwerpunkte und Vorstellungen. Völlig unverbindlich.'],
                ['nr' => '02', 'title' => 'Anbindungsvertrag',  'desc' => 'Wir schließen einen klaren Kooperationsvertrag. Darin sind Vergütung, Rahmenbedingungen und gegenseitige Rechte und Pflichten transparent geregelt.'],
                ['nr' => '03', 'title' => 'Onboarding',         'desc' => 'Du wirst in unsere Strukturen eingeführt: Buchungssystem, Kommunikation mit Kunden, Nutzung unserer Räumlichkeiten und Materialien.'],
                ['nr' => '04', 'title' => 'Loslegen',           'desc' => 'Du trainierst unter deinem Namen, mit deiner Methode und zu deinen Zeiten. Wir kümmern uns im Hintergrund um Abrechnung, Verwaltung und Support.'],
            ];
            foreach ($schritte as $i => $s): ?>
                <div class="reveal reveal-delay-<?= ($i % 4) + 1 ?>">
                    <div style="font-family: 'Montserrat', sans-serif; font-size: 2rem; font-weight: 900; color: var(--gold-accent); margin-bottom: 0.5rem;"><?= $s['nr'] ?></div>
                    <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem;"><?= e($s['title']) ?></h3>
                    <p style="font-size: 0.875rem; line-height: 1.7; margin: 0;"><?= e($s['desc']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     DEINE VORTEILE
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="text-center" style="margin-bottom: 3.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Deine Vorteile</span>
            <h2 class="section-title reveal reveal-delay-1">Alles, was du brauchst, ohne den Aufwand dahinter</h2>
            <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                Selbstständig arbeiten bedeutet nicht, alles alleine stemmen zu müssen. Mit uns im
                Rücken konzentrierst du dich auf Leistung, wir übernehmen den Rest.
            </p>
        </div>

        <div class="grid-3">
            <?php
            $vorteile = [
                ['icon' => '🏛️', 'title' => 'Kein Gründungsaufwand',     'desc' => 'Du arbeitest unter dem rechtlichen Dach unserer Organisation: sofort und unkompliziert, ohne eigenen Gründungsaufwand.'],
                ['icon' => '🔒', 'title' => 'Versicherungsschutz',       'desc' => 'Unsere Haftpflicht- und Unfallversicherung deckt deine Trainingstätigkeit ab. Du bist von Anfang an auf der sicheren Seite.'],
                ['icon' => '📊', 'title' => 'Buchhaltung & Abrechnung',  'desc' => 'Rechnungen, Honorarabrechnungen und die steuerliche Abwicklung laufen über uns. Du erhältst monatlich eine transparente Abrechnung.'],
                ['icon' => '📍', 'title' => 'Infrastruktur & Räume',     'desc' => 'Zugang zu unseren Trainingsräumen, Equipment und Buchungssystemen, ohne eigene Investitionen in Ausstattung oder Mietverträge.'],
                ['icon' => '📣', 'title' => 'Marketing & Sichtbarkeit',  'desc' => 'Du wirst auf unserer Website, in Social Media und in unserem Newsletter als Trainer*in vorgestellt. Wir bringen dir Kunden, du musst nicht selbst akquirieren.'],
                ['icon' => '🤝', 'title' => 'Kollegiales Netzwerk',      'desc' => 'Du bist Teil eines Teams aus gleichgesinnten Trainer*innen. Austausch, gegenseitige Unterstützung und gemeinsame Weiterentwicklung inklusive.'],
            ];
            foreach ($vorteile as $i => $v): ?>
                <div class="card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
                    <div class="card-body">
                        <div style="
                            width: 56px; height: 56px;
                            border-radius: 1rem;
                            background: var(--gold-dim);
                            display: flex; align-items: center; justify-content: center;
                            margin-bottom: 1.25rem;
                            font-size: 1.5rem;
                        "><?= $v['icon'] ?></div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem;"><?= e($v['title']) ?></h3>
                        <p style="font-size: 0.875rem; margin: 0;"><?= e($v['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     WAS WIR UNS WÜNSCHEN
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div class="text-center" style="margin-bottom: 2.5rem; max-width: 680px; margin-inline: auto;">
            <span class="section-label reveal">Was wir uns wünschen</span>
            <h2 class="section-title reveal reveal-delay-1">Deine Qualifikation ist unsere Grundlage</h2>
        </div>

        <div style="max-width: 680px; margin: 0 auto;">
            <?php
            $anforderungen = [
                'Abgeschlossene Trainer*innenausbildung: eine anerkannte Lizenz im Bereich Sport, Fitness, Gesundheit oder verwandten Feldern',
                'Verlässlichkeit und Professionalität: pünktlich, vorbereitet und im Umgang mit Kunden stets freundlich und kompetent',
                'Eigenverantwortung: du organisierst deinen Alltag selbst und bringst den Antrieb mit, als Selbstständige*r zu arbeiten',
                'Identifikation mit unseren Werten: Ehrlichkeit, Qualität im Training und das Wohl der Kund*innen stehen bei uns an erster Stelle',
                'Bereitschaft zur Zusammenarbeit: wir sind kein anonymes Netzwerk, sondern ein echtes Team mit regelmäßigem Austausch',
            ];
            foreach ($anforderungen as $i => $a): ?>
                <div class="reveal reveal-delay-<?= ($i % 4) + 1 ?>" style="display: flex; gap: 0.85rem; align-items: flex-start; margin-bottom: 1.25rem;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;"><polyline points="20 6 9 17 4 12"/></svg>
                    <span style="font-size: 0.95rem; line-height: 1.7;"><?= e($a) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     TESTIMONIAL
============================================================ -->
<section class="section bg-white">
    <div class="container">
        <div class="reveal" style="max-width: 680px; margin: 0 auto; text-align: center;">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="#C6A135" style="margin: 0 auto 1.5rem;"><path d="M9.983 3v7.391c0 5.704-3.731 9.57-8.983 10.609l-.995-2.151c2.432-.917 3.995-3.638 3.995-5.849h-4v-10h9.983zm14.017 0v7.391c0 5.704-3.748 9.571-9 10.609l-.996-2.151c2.433-.917 3.996-3.638 3.996-5.849h-4v-10h10z"/></svg>
            <p style="font-size: 1.15rem; line-height: 1.8; font-style: italic; color: var(--text-secondary); margin-bottom: 1.5rem;">
                Ich wollte schon lange selbstständig trainieren, aber der Aufwand mit Versicherung,
                Verträgen und Buchhaltung hat mich zurückgehalten. Die Anbindung war der perfekte
                Einstieg.
            </p>
            <span style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">
                Trainer*in im Athletikclub Steiermark
            </span>
        </div>
    </div>
</section>

<!-- ============================================================
     QUEREINSTIEG
============================================================ -->
<div class="container" style="padding-bottom: 1rem;">
    <div class="card" style="max-width: 680px; margin: 0 auto; border-left: 3px solid var(--gold-accent);">
        <div class="card-body">
            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.5rem;">Quereinstieg möglich?</h3>
            <p style="font-size: 0.9rem; margin: 0; line-height: 1.7;">
                Wenn du gerade eine Ausbildung absolvierst oder kurz vor dem Abschluss stehst, melde
                dich trotzdem gerne. Wir besprechen gemeinsam, ob und wie eine frühe Zusammenarbeit
                möglich ist.
            </p>
        </div>
    </div>
</div>

<!-- ============================================================
     KONTAKTFORMULAR
============================================================ -->
<section class="section bg-light">
    <div class="container">
        <div style="max-width: 640px; margin: 0 auto;">
            <div class="text-center" style="margin-bottom: 2.5rem;">
                <span class="section-label reveal">Bereit für den nächsten Schritt?</span>
                <h2 class="section-title reveal reveal-delay-1">Wir freuen uns auf dich.</h2>
                <p class="section-subtitle reveal reveal-delay-2" style="margin: 0 auto;">
                    Füll das Formular aus. Wir melden uns innerhalb von 48 Stunden bei dir.
                    Kein Druck, keine Verpflichtung, nur ein ehrlicher Austausch.
                </p>
            </div>

            <div class="reveal reveal-delay-3">
                <?php if ($success): ?>
                    <div style="text-align: center; padding: 3rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Danke für deine Anfrage!</h3>
                        <p>Wir melden uns innerhalb von 48 Stunden bei dir.</p>
                        <a href="<?= APP_URL ?>/" class="btn btn-navy" style="margin-top: 1.5rem;">Zurück zur Startseite</a>
                    </div>
                <?php else: ?>
                <div class="form-card">
                    <?php if (!empty($errors['general'])): ?>
                        <div class="flash-message flash-error" style="border-radius: 0.5rem; margin-bottom: 1rem;"><span><?= e($errors['general']) ?></span></div>
                    <?php endif; ?>

                    <form method="POST" action="" data-validate novalidate>
                        <?= csrfField() ?>
                        <?= formularHoneypot() ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="vorname">Vorname <span class="required">*</span></label>
                                <input class="form-control <?= isset($errors['vorname']) ? 'error' : '' ?>" type="text" id="vorname" name="vorname" value="<?= e($_POST['vorname'] ?? '') ?>" required placeholder="Max">
                                <?php if (isset($errors['vorname'])): ?><span class="form-error"><?= e($errors['vorname']) ?></span><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="nachname">Nachname <span class="required">*</span></label>
                                <input class="form-control <?= isset($errors['nachname']) ? 'error' : '' ?>" type="text" id="nachname" name="nachname" value="<?= e($_POST['nachname'] ?? '') ?>" required placeholder="Mustermann">
                                <?php if (isset($errors['nachname'])): ?><span class="form-error"><?= e($errors['nachname']) ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">E-Mail <span class="required">*</span></label>
                            <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>" type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="deine@email.at">
                            <?php if (isset($errors['email'])): ?><span class="form-error"><?= e($errors['email']) ?></span><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="qualifikation">Deine Qualifikation / Schwerpunkt</label>
                            <input class="form-control" type="text" id="qualifikation" name="qualifikation" value="<?= e($_POST['qualifikation'] ?? '') ?>" placeholder="z. B. Personal Trainer*in, Kraftsport, Yoga …">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="nachricht">Nachricht <span class="required">*</span></label>
                            <textarea class="form-control <?= isset($errors['nachricht']) ? 'error' : '' ?>" id="nachricht" name="nachricht" required rows="5" placeholder="Erzähl uns kurz von dir und deinen Vorstellungen …"><?= e($_POST['nachricht'] ?? '') ?></textarea>
                            <?php if (isset($errors['nachricht'])): ?><span class="form-error"><?= e($errors['nachricht']) ?></span><?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-full btn-lg">Anfrage absenden</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
