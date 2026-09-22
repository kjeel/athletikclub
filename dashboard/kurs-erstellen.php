<?php
/**
 * Athletikclub Steiermark – Kurs erstellen (Dashboard)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireTrainer();

$db     = getDB();
$user   = getCurrentUser();
$errors = [];

$trainer_liste = [];
if (isAdmin()) {
    $stmt = $db->prepare(
        "SELECT id, vorname, nachname FROM users WHERE rolle IN ('trainer','admin') AND aktiv = 1 AND organization_id = ? ORDER BY vorname"
    );
    $stmt->execute([currentOrgId()]);
    $trainer_liste = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $titel          = trim($_POST['titel'] ?? '');
    $beschreibung   = trim($_POST['beschreibung'] ?? '');
    $sportart       = trim($_POST['sportart'] ?? '');
    $ort            = trim($_POST['ort'] ?? '');
    $start_datum    = trim($_POST['start_datum'] ?? '');
    $end_datum      = trim($_POST['end_datum'] ?? '');
    $max_teilnehmer = trim($_POST['max_teilnehmer'] ?? '');
    $preis          = trim($_POST['preis'] ?? '0');
    $trainer_id     = isAdmin() && !empty($_POST['trainer_id']) ? (int)$_POST['trainer_id'] : (int)$user['id'];

    if (mb_strlen($titel) < 3) $errors['titel'] = 'Titel muss mindestens 3 Zeichen haben.';
    if (empty($start_datum))   $errors['start_datum'] = 'Startdatum ist Pflichtfeld.';
    if (empty($end_datum))     $errors['end_datum'] = 'Enddatum ist Pflichtfeld.';
    if (!empty($start_datum) && !empty($end_datum) && strtotime($end_datum) <= strtotime($start_datum)) {
        $errors['end_datum'] = 'Enddatum muss nach dem Startdatum liegen.';
    }
    if ($max_teilnehmer !== '' && (!ctype_digit($max_teilnehmer) || (int)$max_teilnehmer < 1)) {
        $errors['max_teilnehmer'] = 'Bitte eine gültige Zahl eingeben.';
    }
    if (!is_numeric($preis) || (float)$preis < 0) {
        $errors['preis'] = 'Bitte einen gültigen Preis eingeben.';
    }

    if (empty($errors)) {
        try {
            $stmt = $db->prepare(
                'INSERT INTO kurse (organization_id, titel, beschreibung, trainer_id, sportart, ort, start_datum, end_datum, max_teilnehmer, preis, status, erstellt_von)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                currentOrgId(),
                $titel,
                $beschreibung ?: null,
                $trainer_id ?: null,
                $sportart ?: null,
                $ort ?: null,
                date('Y-m-d H:i:s', strtotime($start_datum)),
                date('Y-m-d H:i:s', strtotime($end_datum)),
                $max_teilnehmer !== '' ? (int)$max_teilnehmer : null,
                (float)$preis,
                'geplant',
                $user['id'],
            ]);
            $neue_kurs_id = (int)$db->lastInsertId();

            logActivity('kurs_erstellt', "Kurs-ID: {$neue_kurs_id}, Titel: {$titel}");
            flashMessage('success', 'Kurs erfolgreich erstellt!');
            redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $neue_kurs_id);
        } catch (Exception $e) {
            $errors['general'] = 'Kurs konnte nicht angelegt werden. Bitte versuche es später erneut.';
        }
    }
}

$sportarten = ['Calisthenics', 'Skateboarding', 'Tischtennis', 'Padel Tennis', 'Athletiktraining', 'Ausdauer', 'Sonstiges'];

$page_title = 'Kurs erstellen';
$breadcrumb = 'Kurs erstellen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Kurs erstellen</h1>
    <p class="dashboard-subtitle">Neue Trainingseinheit anlegen</p>
</div>

<?php if (!empty($errors['general'])): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= e($errors['general']) ?></span>
</div>
<?php endif; ?>

<div class="form-card" style="max-width: 720px;">
    <form method="POST" action="" data-validate novalidate>
        <?= csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="titel">Titel <span class="required">*</span></label>
            <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" id="titel" name="titel" value="<?= e($_POST['titel'] ?? '') ?>" required placeholder="z.B. Calisthenics Grundkurs">
            <?php if (isset($errors['titel'])): ?><span class="form-error"><?= e($errors['titel']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="beschreibung">Beschreibung</label>
            <textarea class="form-control" id="beschreibung" name="beschreibung" rows="4" placeholder="Worum geht es in diesem Kurs?"><?= e($_POST['beschreibung'] ?? '') ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="sportart">Sportart</label>
                <select class="form-control" id="sportart" name="sportart">
                    <option value="">Bitte wählen…</option>
                    <?php foreach ($sportarten as $s): ?>
                        <option value="<?= e($s) ?>" <?= (($_POST['sportart'] ?? '') === $s) ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="ort">Ort</label>
                <input class="form-control" type="text" id="ort" name="ort" value="<?= e($_POST['ort'] ?? '') ?>" placeholder="z.B. Trainingshalle St. Georgen">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="start_datum">Start <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['start_datum']) ? 'error' : '' ?>" type="datetime-local" id="start_datum" name="start_datum" value="<?= e($_POST['start_datum'] ?? '') ?>" required>
                <?php if (isset($errors['start_datum'])): ?><span class="form-error"><?= e($errors['start_datum']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="end_datum">Ende <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['end_datum']) ? 'error' : '' ?>" type="datetime-local" id="end_datum" name="end_datum" value="<?= e($_POST['end_datum'] ?? '') ?>" required>
                <?php if (isset($errors['end_datum'])): ?><span class="form-error"><?= e($errors['end_datum']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="max_teilnehmer">Max. Teilnehmer</label>
                <input class="form-control <?= isset($errors['max_teilnehmer']) ? 'error' : '' ?>" type="number" min="1" id="max_teilnehmer" name="max_teilnehmer" value="<?= e($_POST['max_teilnehmer'] ?? '') ?>" placeholder="Leer = unlimitiert">
                <?php if (isset($errors['max_teilnehmer'])): ?><span class="form-error"><?= e($errors['max_teilnehmer']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="preis">Preis (€)</label>
                <input class="form-control <?= isset($errors['preis']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="preis" name="preis" value="<?= e($_POST['preis'] ?? '0') ?>">
                <?php if (isset($errors['preis'])): ?><span class="form-error"><?= e($errors['preis']) ?></span><?php endif; ?>
            </div>
        </div>

        <?php if (isAdmin() && !empty($trainer_liste)): ?>
        <div class="form-group">
            <label class="form-label" for="trainer_id">Trainer*in</label>
            <select class="form-control" id="trainer_id" name="trainer_id">
                <?php foreach ($trainer_liste as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ((int)($_POST['trainer_id'] ?? $user['id']) === (int)$t['id']) ? 'selected' : '' ?>>
                        <?= e($t['vorname'] . ' ' . $t['nachname']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-lg">Kurs erstellen</button>
    </form>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
