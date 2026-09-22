<?php
/**
 * Athletikclub Steiermark – Kontakt
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Kontakt';
$meta_description = 'Kontaktiere den Athletikclub Steiermark, wir sind für dich da.';
require_once ROOT_PATH . '/includes/header.php';

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $betreff   = trim($_POST['betreff'] ?? '');
    $nachricht = trim($_POST['nachricht'] ?? '');

    if (empty($name) || mb_strlen($name) < 2) $errors['name'] = 'Bitte gib deinen Namen ein.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    if (empty($nachricht) || mb_strlen($nachricht) < 10) $errors['nachricht'] = 'Bitte schreibe mindestens 10 Zeichen.';

    if (empty($errors)) {
        try {
            $db = getDB();
            $db->prepare('INSERT INTO kontakt_anfragen (organization_id, name, email, betreff, nachricht) VALUES (?, ?, ?, ?, ?)')
               ->execute([currentOrgId(), $name, $email, $betreff, $nachricht]);

            $mail_body  = "Neue Kontaktanfrage über die Website:\n\n";
            $mail_body .= "Name: {$name}\nE-Mail: {$email}\nBetreff: {$betreff}\n\nNachricht:\n{$nachricht}";
            @mail(MAIL_ADMIN, "Neue Kontaktanfrage: {$betreff}", $mail_body, 'From: ' . MAIL_FROM);
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
            <span class="breadcrumb-current">Kontakt</span>
        </nav>
        <h1>Kontakt</h1>
        <p>Wir freuen uns auf deine Nachricht!</p>
    </div>
</section>

<section class="section bg-light">
    <div class="container">
        <div class="feature-block" style="gap: 4rem;">

            <!-- Kontaktinfos -->
            <div class="reveal">
                <span class="section-label">So erreichst du uns</span>
                <h2 class="section-title">Nimm Kontakt auf</h2>
                <p style="margin-bottom: 2rem; line-height: 1.8;">
                    Du hast Fragen zu unserem Trainingsangebot, möchtest Mitglied werden
                    oder hast andere Anliegen? Wir sind für dich da!
                </p>

                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                    <?php
                    $infos = [
                        ['icon' => 'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3', 'label' => 'Adresse', 'value' => 'St. Georgen an der Stiefing 14, 8413 Sankt Georgen an der Stiefing', 'href' => null],
                        ['icon' => 'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.07 6.07l1.79-1.79a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92', 'label' => 'Telefon', 'value' => '+43 664 882 895 00', 'href' => 'tel:+436648828950'],
                        ['icon' => 'M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6', 'label' => 'E-Mail', 'value' => 'office@athletikclub-steiermark.at', 'href' => 'mailto:office@athletikclub-steiermark.at'],
                    ];
                    foreach ($infos as $info): ?>
                        <div style="display: flex; gap: 1rem; align-items: flex-start;">
                            <div style="width: 44px; height: 44px; background: var(--gold-dim); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2"><path d="<?= $info['icon'] ?>"/></svg>
                            </div>
                            <div>
                                <div style="font-family: 'Montserrat', sans-serif; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.2rem;"><?= $info['label'] ?></div>
                                <?php if ($info['href']): ?>
                                    <a href="<?= $info['href'] ?>" style="font-weight: 500; color: var(--navy-primary);"><?= e($info['value']) ?></a>
                                <?php else: ?>
                                    <span style="font-size: 0.9rem; color: var(--text-secondary);"><?= e($info['value']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div style="display: flex; gap: 1rem; align-items: flex-start; margin-top: 0.5rem;">
                        <div style="width: 44px; height: 44px; background: var(--gold-dim); border-radius: 0.75rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#C6A135" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div>
                            <div style="font-family: 'Montserrat', sans-serif; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.2rem;">Öffnungszeiten</div>
                            <span style="font-size: 0.9rem; color: var(--text-secondary);">Ausschließlich mit Terminvereinbarung</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formular -->
            <div class="reveal reveal-delay-1">
                <?php if ($success): ?>
                    <div style="text-align: center; padding: 3rem;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                        <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Danke für deine Nachricht!</h2>
                        <p>Wir melden uns so schnell wie möglich bei dir.</p>
                        <a href="/" class="btn btn-navy" style="margin-top: 1.5rem;">Zurück zur Startseite</a>
                    </div>
                <?php else: ?>
                <div class="form-card">
                    <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.25rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.5rem;">Nachricht senden</h2>

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
                            <label class="form-label" for="betreff">Betreff</label>
                            <input class="form-control" type="text" id="betreff" name="betreff" value="<?= e($_POST['betreff'] ?? '') ?>" placeholder="Worum geht es?">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="nachricht">Nachricht <span class="required">*</span></label>
                            <textarea class="form-control <?= isset($errors['nachricht']) ? 'error' : '' ?>" id="nachricht" name="nachricht" required rows="5" placeholder="Deine Nachricht…"><?= e($_POST['nachricht'] ?? '') ?></textarea>
                            <?php if (isset($errors['nachricht'])): ?><span class="form-error"><?= e($errors['nachricht']) ?></span><?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-full btn-lg">Nachricht absenden</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
