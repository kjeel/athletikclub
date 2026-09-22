<?php
/**
 * Athletikclub Steiermark – Mein Profil (Dashboard)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireLogin();

$db     = getDB();
$user   = getCurrentUser();
$errors = [];
$success = '';

// ----------------------------------------------------------------
// Stammdaten speichern
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stammdaten') {
    requireCsrf();

    $vorname  = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');

    if (empty($vorname) || mb_strlen($vorname) < 2) $errors['vorname'] = 'Vorname muss mindestens 2 Zeichen haben.';
    if (empty($nachname) || mb_strlen($nachname) < 2) $errors['nachname'] = 'Nachname muss mindestens 2 Zeichen haben.';

    if (empty($errors)) {
        $db->prepare('UPDATE users SET vorname = ?, nachname = ? WHERE id = ?')
           ->execute([$vorname, $nachname, $user['id']]);
        $_SESSION['user_vorname']  = $vorname;
        $_SESSION['user_nachname'] = $nachname;
        logActivity('profil_aktualisiert');
        flashMessage('success', 'Deine Daten wurden gespeichert.');
        redirect(APP_URL . '/dashboard/profil.php');
    }
}

// ----------------------------------------------------------------
// Mitglieder-/Trainer-Profil speichern
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profil') {
    requireCsrf();

    if (isTrainer()) {
        $bio            = trim($_POST['bio'] ?? '');
        $qualifikationen = trim($_POST['qualifikationen'] ?? '');
        $sportarten     = trim($_POST['sportarten'] ?? '');
        $lizenz_nr      = trim($_POST['lizenz_nr'] ?? '');

        $db->prepare(
            'INSERT INTO trainer_profile (user_id, bio, qualifikationen, sportarten, lizenz_nr)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE bio = VALUES(bio), qualifikationen = VALUES(qualifikationen), sportarten = VALUES(sportarten), lizenz_nr = VALUES(lizenz_nr)'
        )->execute([$user['id'], $bio ?: null, $qualifikationen ?: null, $sportarten ?: null, $lizenz_nr ?: null]);
    } else {
        $geburtsdatum = trim($_POST['geburtsdatum'] ?? '');
        $telefon      = trim($_POST['telefon'] ?? '');
        $strasse      = trim($_POST['strasse'] ?? '');
        $plz          = trim($_POST['plz'] ?? '');
        $ort          = trim($_POST['ort'] ?? '');
        $sportarten   = trim($_POST['sportarten'] ?? '');

        $db->prepare(
            'INSERT INTO mitglieder_profile (user_id, geburtsdatum, telefon, strasse, plz, ort, sportarten, mitglied_seit)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE geburtsdatum = VALUES(geburtsdatum), telefon = VALUES(telefon), strasse = VALUES(strasse), plz = VALUES(plz), ort = VALUES(ort), sportarten = VALUES(sportarten)'
        )->execute([$user['id'], $geburtsdatum ?: null, $telefon ?: null, $strasse ?: null, $plz ?: null, $ort ?: null, $sportarten ?: null]);
    }

    logActivity('profil_aktualisiert');
    flashMessage('success', 'Dein Profil wurde gespeichert.');
    redirect(APP_URL . '/dashboard/profil.php');
}

// ----------------------------------------------------------------
// Passwort ändern
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'passwort') {
    requireCsrf();

    $aktuell  = $_POST['aktuelles_passwort'] ?? '';
    $neu      = $_POST['neues_passwort'] ?? '';
    $bestaetigung = $_POST['neues_passwort_confirm'] ?? '';

    $stmt = $db->prepare('SELECT passwort_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();

    if (!verifyPassword($aktuell, $hash)) {
        $errors['aktuelles_passwort'] = 'Aktuelles Passwort ist falsch.';
    }
    if (mb_strlen($neu) < 8) {
        $errors['neues_passwort'] = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
    }
    if ($neu !== $bestaetigung) {
        $errors['neues_passwort_confirm'] = 'Die Passwörter stimmen nicht überein.';
    }

    if (empty($errors)) {
        $db->prepare('UPDATE users SET passwort_hash = ? WHERE id = ?')
           ->execute([hashPassword($neu), $user['id']]);
        logActivity('passwort_geaendert');
        flashMessage('success', 'Passwort erfolgreich geändert.');
        redirect(APP_URL . '/dashboard/profil.php');
    }
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT vorname, nachname, email, rolle, created_at FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$stammdaten = $stmt->fetch();

$profil = [];
if (isTrainer()) {
    $stmt = $db->prepare('SELECT * FROM trainer_profile WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $profil = $stmt->fetch() ?: [];
} else {
    $stmt = $db->prepare('SELECT * FROM mitglieder_profile WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $profil = $stmt->fetch() ?: [];
}

$page_title = 'Mein Profil';
$breadcrumb = 'Mein Profil';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Mein Profil</h1>
    <p class="dashboard-subtitle">Persönliche Daten und Kontoeinstellungen verwalten</p>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start;">

    <!-- Stammdaten -->
    <div class="form-card">
        <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.25rem;">Stammdaten</h2>
        <form method="POST" action="" data-validate novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="stammdaten">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="vorname">Vorname <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['vorname']) ? 'error' : '' ?>" type="text" id="vorname" name="vorname" value="<?= e($stammdaten['vorname']) ?>" required>
                    <?php if (isset($errors['vorname'])): ?><span class="form-error"><?= e($errors['vorname']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="nachname">Nachname <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['nachname']) ? 'error' : '' ?>" type="text" id="nachname" name="nachname" value="<?= e($stammdaten['nachname']) ?>" required>
                    <?php if (isset($errors['nachname'])): ?><span class="form-error"><?= e($errors['nachname']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">E-Mail</label>
                <input class="form-control" type="email" value="<?= e($stammdaten['email']) ?>" disabled>
                <span class="form-hint">Für eine Änderung der E-Mail-Adresse wende dich an einen Admin.</span>
            </div>

            <div class="form-group">
                <label class="form-label">Rolle</label>
                <div><span class="role-badge role-<?= e($stammdaten['rolle']) ?>"><?= ucfirst(e($stammdaten['rolle'])) ?></span></div>
            </div>

            <button type="submit" class="btn btn-primary">Speichern</button>
        </form>
    </div>

    <!-- Passwort ändern -->
    <div class="form-card">
        <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.25rem;">Passwort ändern</h2>
        <form method="POST" action="" data-validate novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="passwort">

            <div class="form-group">
                <label class="form-label" for="aktuelles_passwort">Aktuelles Passwort <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['aktuelles_passwort']) ? 'error' : '' ?>" type="password" id="aktuelles_passwort" name="aktuelles_passwort" required autocomplete="current-password">
                <?php if (isset($errors['aktuelles_passwort'])): ?><span class="form-error"><?= e($errors['aktuelles_passwort']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="neues_passwort">Neues Passwort <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['neues_passwort']) ? 'error' : '' ?>" type="password" id="neues_passwort" name="neues_passwort" required autocomplete="new-password">
                <span class="form-hint">Mind. 8 Zeichen</span>
                <?php if (isset($errors['neues_passwort'])): ?><span class="form-error"><?= e($errors['neues_passwort']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="neues_passwort_confirm">Neues Passwort bestätigen <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['neues_passwort_confirm']) ? 'error' : '' ?>" type="password" id="neues_passwort_confirm" name="neues_passwort_confirm" required autocomplete="new-password">
                <?php if (isset($errors['neues_passwort_confirm'])): ?><span class="form-error"><?= e($errors['neues_passwort_confirm']) ?></span><?php endif; ?>
            </div>

            <button type="submit" class="btn btn-navy">Passwort ändern</button>
        </form>
    </div>
</div>

<!-- Rollenspezifisches Profil -->
<div class="form-card" style="margin-top: 1.5rem;">
    <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.25rem;">
        <?= isTrainer() ? 'Trainer-Profil' : 'Mitglieder-Profil' ?>
    </h2>
    <form method="POST" action="" data-validate novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="profil">

        <?php if (isTrainer()): ?>
            <div class="form-group">
                <label class="form-label" for="bio">Über mich</label>
                <textarea class="form-control" id="bio" name="bio" rows="3" placeholder="Kurze Vorstellung, wird auf der Team-Seite angezeigt"><?= e($profil['bio'] ?? '') ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="sportarten">Sportarten</label>
                    <input class="form-control" type="text" id="sportarten" name="sportarten" value="<?= e($profil['sportarten'] ?? '') ?>" placeholder="z.B. Calisthenics, Athletiktraining">
                </div>
                <div class="form-group">
                    <label class="form-label" for="lizenz_nr">Lizenznummer</label>
                    <input class="form-control" type="text" id="lizenz_nr" name="lizenz_nr" value="<?= e($profil['lizenz_nr'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="qualifikationen">Qualifikationen</label>
                <textarea class="form-control" id="qualifikationen" name="qualifikationen" rows="2" placeholder="Ausbildungen, Zertifikate…"><?= e($profil['qualifikationen'] ?? '') ?></textarea>
            </div>
        <?php else: ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="geburtsdatum">Geburtsdatum</label>
                    <input class="form-control" type="date" id="geburtsdatum" name="geburtsdatum" value="<?= e($profil['geburtsdatum'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="telefon">Telefon</label>
                    <input class="form-control" type="tel" id="telefon" name="telefon" value="<?= e($profil['telefon'] ?? '') ?>" placeholder="+43 …">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="strasse">Straße</label>
                    <input class="form-control" type="text" id="strasse" name="strasse" value="<?= e($profil['strasse'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="plz">PLZ</label>
                    <input class="form-control" type="text" id="plz" name="plz" value="<?= e($profil['plz'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="ort">Ort</label>
                    <input class="form-control" type="text" id="ort" name="ort" value="<?= e($profil['ort'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="sportarten">Sportarten</label>
                <input class="form-control" type="text" id="sportarten" name="sportarten" value="<?= e($profil['sportarten'] ?? '') ?>" placeholder="z.B. Calisthenics, Padel Tennis">
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Profil speichern</button>
    </form>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
