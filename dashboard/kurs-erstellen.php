<?php
/**
 * Athletikclub Steiermark – Kurs erstellen / bearbeiten (Dashboard)
 * Inkl. Anmeldeschluss, Altersgrenzen, Voraussetzungen und Projektzuordnung.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kursanmeldung.php';
require_once ROOT_PATH . '/includes/upload.php';

requireTrainer();

$db     = getDB();
$user   = getCurrentUser();
$errors = [];

// Bearbeiten: nur Kursleitung oder Admin
$kurs_id = (int)($_GET['id'] ?? 0);
$kurs = null;
if ($kurs_id) {
    $stmt = $db->prepare('SELECT * FROM kurse WHERE id = ? AND organization_id = ?');
    $stmt->execute([$kurs_id, currentOrgId()]);
    $kurs = $stmt->fetch();
    if (!$kurs || !(isAdmin() || (int)$kurs['trainer_id'] === (int)$user['id'])) {
        flashMessage('error', 'Diesen Kurs kannst du nicht bearbeiten.');
        redirect(APP_URL . '/dashboard/kurse.php');
    }
}

$trainer_liste = [];
if (isAdmin()) {
    $stmt = $db->prepare(
        "SELECT id, vorname, nachname FROM users WHERE rolle IN ('trainer','admin') AND aktiv = 1 AND organization_id = ? ORDER BY vorname"
    );
    $stmt->execute([currentOrgId()]);
    $trainer_liste = $stmt->fetchAll();
}

// Projekte: alle mit Projektrechten, sonst eigene (Leitung/Team)
$projekte = plattformProjekte($db);
if (!darfEines('projekte.bearbeiten', 'projekte.erstellen')) {
    try {
        $stmt = $db->prepare('SELECT p.id FROM projekte p LEFT JOIN projekt_team t ON t.projekt_id = p.id AND t.user_id = ? WHERE p.organization_id = ? AND (p.leitung_id = ? OR t.user_id IS NOT NULL)');
        $stmt->execute([$user['id'], currentOrgId(), $user['id']]);
        $eigene = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        $eigene = [];
    }
    $projekte = array_values(array_filter($projekte, fn($p) => in_array((int)$p['id'], $eigene, true) || ($kurs && (int)$p['id'] === (int)$kurs['projekt_id'])));
}
$projekt_ids = array_map(fn($p) => (int)$p['id'], $projekte);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $titel           = trim($_POST['titel'] ?? '');
    $beschreibung    = trim($_POST['beschreibung'] ?? '');
    $sportart        = trim($_POST['sportart'] ?? '');
    $ort             = trim($_POST['ort'] ?? '');
    $start_datum     = trim($_POST['start_datum'] ?? '');
    $end_datum       = trim($_POST['end_datum'] ?? '');
    $max_teilnehmer  = trim($_POST['max_teilnehmer'] ?? '');
    $preis           = trim($_POST['preis'] ?? '0');
    $anmeldeschluss  = trim($_POST['anmeldeschluss'] ?? '');
    $min_alter       = trim($_POST['min_alter'] ?? '');
    $max_alter       = trim($_POST['max_alter'] ?? '');
    $voraussetzungen = trim($_POST['voraussetzungen'] ?? '');
    $projekt_id      = (int)($_POST['projekt_id'] ?? 0);
    $kurzbeschreibung = mb_substr(trim($_POST['kurzbeschreibung'] ?? ''), 0, 300);
    $storno_frist    = trim($_POST['storno_frist_std'] ?? '');
    if ($storno_frist !== '' && (!ctype_digit($storno_frist) || (int)$storno_frist > 720)) $errors['storno_frist_std'] = 'Bitte 0–720 Stunden angeben.';
    $bild_neu = null;
    if (!empty($_FILES['bild']['name'])) {
        $r = bildUpload($_FILES['bild'], 'kurse');
        if (isset($r['fehler'])) $errors['bild'] = $r['fehler']; else $bild_neu = $r['datei'];
    }
    if ($kurs) {
        $trainer_id = isAdmin() && !empty($_POST['trainer_id']) ? (int)$_POST['trainer_id'] : (int)$kurs['trainer_id'];
    } else {
        $trainer_id = isAdmin() && !empty($_POST['trainer_id']) ? (int)$_POST['trainer_id'] : (int)$user['id'];
    }

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
    if ($anmeldeschluss !== '' && strtotime($anmeldeschluss) === false) {
        $errors['anmeldeschluss'] = 'Ungültiges Datum.';
    }
    foreach (['min_alter' => $min_alter, 'max_alter' => $max_alter] as $feld => $wert) {
        if ($wert !== '' && (!ctype_digit($wert) || (int)$wert > 120)) $errors[$feld] = 'Bitte ein Alter zwischen 0 und 120 eingeben.';
    }
    if ($min_alter !== '' && $max_alter !== '' && !isset($errors['min_alter']) && !isset($errors['max_alter']) && (int)$min_alter > (int)$max_alter) {
        $errors['max_alter'] = 'Höchstalter muss größer oder gleich dem Mindestalter sein.';
    }
    if ($projekt_id && !in_array($projekt_id, $projekt_ids, true)) $projekt_id = $kurs['projekt_id'] ?? 0;
    if ($trainer_liste && $trainer_id && !in_array($trainer_id, array_map(fn($t) => (int)$t['id'], $trainer_liste), true)) {
        $errors['trainer_id'] = 'Ungültige Auswahl.';
    }

    if (empty($errors)) {
        $werte = [
            'titel'           => $titel,
            'beschreibung'    => $beschreibung ?: null,
            'trainer_id'      => $trainer_id ?: null,
            'sportart'        => $sportart ?: null,
            'ort'             => $ort ?: null,
            'start_datum'     => date('Y-m-d H:i:s', strtotime($start_datum)),
            'end_datum'       => date('Y-m-d H:i:s', strtotime($end_datum)),
            'max_teilnehmer'  => $max_teilnehmer !== '' ? (int)$max_teilnehmer : null,
            'preis'           => (float)$preis,
            'anmeldeschluss'  => $anmeldeschluss !== '' ? date('Y-m-d H:i:s', strtotime($anmeldeschluss)) : null,
            'min_alter'       => $min_alter !== '' ? (int)$min_alter : null,
            'max_alter'       => $max_alter !== '' ? (int)$max_alter : null,
            'voraussetzungen' => $voraussetzungen ?: null,
            'projekt_id'      => $projekt_id ?: null,
            'oeffentlich'     => !empty($_POST['oeffentlich']) ? 1 : 0,
            'art'             => ($_POST['art'] ?? '') === 'event' ? 'event' : 'kurs',
            'kurzbeschreibung' => $kurzbeschreibung ?: null,
            'storno_frist_std' => $storno_frist !== '' ? (int)$storno_frist : null,
        ];
        if ($bild_neu) $werte['bild'] = $bild_neu;
        elseif (!empty($_POST['bild_entfernen'])) $werte['bild'] = null;
        try {
            if ($kurs) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($werte)));
                $db->prepare("UPDATE kurse SET $sets WHERE id = ?")->execute([...array_values($werte), $kurs_id]);
                auditLog('geaendert', 'kurse', $kurs_id, $kurs, $werte);
                logActivity('kurs_bearbeitet', "Kurs-ID: {$kurs_id}");

                // Neue Kursleitung informieren
                if ($werte['trainer_id'] && (int)$werte['trainer_id'] !== (int)$kurs['trainer_id'] && (int)$werte['trainer_id'] !== (int)$user['id']) {
                    benachrichtigen((int)$werte['trainer_id'], 'kurs', 'Kurs zugewiesen: ' . $titel, 'Du leitest ab ' . date('d.m.Y', strtotime($werte['start_datum'])) . ' diesen Kurs.', '/dashboard/kurs-detail.php?id=' . $kurs_id);
                }
                // Mehr Plätze → Warteliste nachziehen
                $neu = array_merge($kurs, $werte);
                $n = kursNachruecken($db, $neu);
                flashMessage('success', 'Kurs gespeichert.' . ($n ? ' ' . count($n) . ' Person(en) von der Warteliste nachgerückt.' : ''));
                redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $kurs_id);
            }

            $werte = ['organization_id' => currentOrgId()] + $werte + ['status' => 'geplant', 'erstellt_von' => $user['id']];
            $db->prepare('INSERT INTO kurse (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')
               ->execute(array_values($werte));
            $neue_kurs_id = (int)$db->lastInsertId();

            logActivity('kurs_erstellt', "Kurs-ID: {$neue_kurs_id}, Titel: {$titel}");
            auditLog('erstellt', 'kurse', $neue_kurs_id, null, $werte);
            if ($werte['trainer_id'] && (int)$werte['trainer_id'] !== (int)$user['id']) {
                benachrichtigen((int)$werte['trainer_id'], 'kurs', 'Kurs zugewiesen: ' . $titel, 'Du leitest ab ' . date('d.m.Y', strtotime($werte['start_datum'])) . ' diesen Kurs.', '/dashboard/kurs-detail.php?id=' . $neue_kurs_id);
            }
            flashMessage('success', 'Kurs erfolgreich erstellt!');
            redirect(APP_URL . '/dashboard/kurs-detail.php?id=' . $neue_kurs_id);
        } catch (Exception $e) {
            $errors['general'] = 'Kurs konnte nicht gespeichert werden. Bitte versuche es später erneut.';
        }
    }
}

// Formularwerte: POST > bestehender Kurs > leer
$dtl = fn($v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($kurs ? [
    'titel' => $kurs['titel'], 'beschreibung' => $kurs['beschreibung'], 'sportart' => $kurs['sportart'], 'ort' => $kurs['ort'],
    'start_datum' => $dtl($kurs['start_datum']), 'end_datum' => $dtl($kurs['end_datum']), 'max_teilnehmer' => $kurs['max_teilnehmer'],
    'preis' => $kurs['preis'], 'trainer_id' => $kurs['trainer_id'], 'anmeldeschluss' => $dtl($kurs['anmeldeschluss'] ?? null),
    'min_alter' => $kurs['min_alter'] ?? '', 'max_alter' => $kurs['max_alter'] ?? '', 'voraussetzungen' => $kurs['voraussetzungen'] ?? '',
    'projekt_id' => $kurs['projekt_id'] ?? '', 'oeffentlich' => $kurs['oeffentlich'] ?? 0, 'art' => $kurs['art'] ?? 'kurs',
    'kurzbeschreibung' => $kurs['kurzbeschreibung'] ?? '', 'storno_frist_std' => $kurs['storno_frist_std'] ?? '',
] : ['preis' => '0', 'projekt_id' => (int)($_GET['projekt'] ?? 0), 'oeffentlich' => 1, 'art' => ($_GET['art'] ?? '') === 'event' ? 'event' : 'kurs']);
$fw = fn($k, $d = '') => (string)($f[$k] ?? $d);

$sportarten = ['Calisthenics', 'Skateboarding', 'Tischtennis', 'Padel Tennis', 'Athletiktraining', 'Ausdauer', 'Sonstiges'];

$titel_seite = $kurs ? 'Kurs bearbeiten' : 'Kurs erstellen';
$page_title = $titel_seite;
$breadcrumb = $titel_seite;
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <?php if ($kurs): ?>
    <a href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $kurs_id ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Zurück zum Kurs
    </a>
    <?php endif; ?>
    <h1 class="dashboard-title"><?= e($titel_seite) ?></h1>
    <p class="dashboard-subtitle"><?= $kurs ? e($kurs['titel']) : 'Neue Trainingseinheit anlegen' ?></p>
</div>

<?php if (!empty($errors['general'])): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= e($errors['general']) ?></span>
</div>
<?php endif; ?>

<div class="form-card" style="max-width: 720px;">
    <form method="POST" action="" enctype="multipart/form-data" data-validate novalidate>
        <?= csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="titel">Titel <span class="required">*</span></label>
            <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" id="titel" name="titel" value="<?= e($fw('titel')) ?>" required placeholder="z.B. Calisthenics Grundkurs">
            <?php if (isset($errors['titel'])): ?><span class="form-error"><?= e($errors['titel']) ?></span><?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="art">Art</label>
                <select class="form-control" id="art" name="art">
                    <option value="kurs" <?= $fw('art', 'kurs') === 'kurs' ? 'selected' : '' ?>>Kurs / Training</option>
                    <option value="event" <?= $fw('art') === 'event' ? 'selected' : '' ?>>Event / Veranstaltung</option>
                </select>
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
                <label class="form-check"><input type="checkbox" name="oeffentlich" value="1" <?= $fw('oeffentlich') ? 'checked' : '' ?>>
                    <span class="form-check-label">Im öffentlichen Kursportal anzeigen und online buchbar</span></label>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="kurzbeschreibung">Kurzbeschreibung (für die Übersicht)</label>
            <input class="form-control" type="text" id="kurzbeschreibung" name="kurzbeschreibung" maxlength="300" value="<?= e($fw('kurzbeschreibung')) ?>" placeholder="Ein Satz, der neugierig macht">
        </div>

        <div class="form-group">
            <label class="form-label" for="beschreibung">Beschreibung</label>
            <textarea class="form-control" id="beschreibung" name="beschreibung" rows="4" placeholder="Worum geht es in diesem Kurs?"><?= e($fw('beschreibung')) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="sportart">Sportart</label>
                <select class="form-control" id="sportart" name="sportart">
                    <option value="">Bitte wählen…</option>
                    <?php foreach ($sportarten as $s): ?>
                        <option value="<?= e($s) ?>" <?= $fw('sportart') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="ort">Ort</label>
                <input class="form-control" type="text" id="ort" name="ort" value="<?= e($fw('ort')) ?>" placeholder="z.B. Trainingshalle St. Georgen">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="start_datum">Start <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['start_datum']) ? 'error' : '' ?>" type="datetime-local" id="start_datum" name="start_datum" value="<?= e($fw('start_datum')) ?>" required>
                <?php if (isset($errors['start_datum'])): ?><span class="form-error"><?= e($errors['start_datum']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="end_datum">Ende <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['end_datum']) ? 'error' : '' ?>" type="datetime-local" id="end_datum" name="end_datum" value="<?= e($fw('end_datum')) ?>" required>
                <?php if (isset($errors['end_datum'])): ?><span class="form-error"><?= e($errors['end_datum']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="max_teilnehmer">Max. Teilnehmer</label>
                <input class="form-control <?= isset($errors['max_teilnehmer']) ? 'error' : '' ?>" type="number" min="1" id="max_teilnehmer" name="max_teilnehmer" value="<?= e($fw('max_teilnehmer')) ?>" placeholder="Leer = unlimitiert">
                <?php if (isset($errors['max_teilnehmer'])): ?><span class="form-error"><?= e($errors['max_teilnehmer']) ?></span><?php endif; ?>
                <span class="form-hint">Ist der Kurs voll, kommen weitere Anmeldungen auf die Warteliste.</span>
            </div>
            <div class="form-group">
                <label class="form-label" for="preis">Preis (€)</label>
                <input class="form-control <?= isset($errors['preis']) ? 'error' : '' ?>" type="number" min="0" step="0.01" id="preis" name="preis" value="<?= e($fw('preis', '0')) ?>">
                <?php if (isset($errors['preis'])): ?><span class="form-error"><?= e($errors['preis']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="anmeldeschluss">Anmeldeschluss</label>
                <input class="form-control <?= isset($errors['anmeldeschluss']) ? 'error' : '' ?>" type="datetime-local" id="anmeldeschluss" name="anmeldeschluss" value="<?= e($fw('anmeldeschluss')) ?>">
                <?php if (isset($errors['anmeldeschluss'])): ?><span class="form-error"><?= e($errors['anmeldeschluss']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Alter (zu Kursbeginn)</label>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <input class="form-control <?= isset($errors['min_alter']) ? 'error' : '' ?>" type="number" min="0" max="120" name="min_alter" value="<?= e($fw('min_alter')) ?>" placeholder="von" aria-label="Mindestalter">
                    <span>–</span>
                    <input class="form-control <?= isset($errors['max_alter']) ? 'error' : '' ?>" type="number" min="0" max="120" name="max_alter" value="<?= e($fw('max_alter')) ?>" placeholder="bis" aria-label="Höchstalter">
                </div>
                <?php foreach (['min_alter', 'max_alter'] as $k): if (isset($errors[$k])): ?><span class="form-error"><?= e($errors[$k]) ?></span><?php endif; endforeach; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="bild">Bild</label>
                <input class="form-control <?= isset($errors['bild']) ? 'error' : '' ?>" type="file" id="bild" name="bild" accept="image/jpeg,image/png,image/webp">
                <?php if (isset($errors['bild'])): ?><span class="form-error"><?= e($errors['bild']) ?></span><?php endif; ?>
                <?php if (!empty($kurs['bild'])): ?><label class="form-check" style="margin-top: 0.4rem;"><input type="checkbox" name="bild_entfernen" value="1"><span class="form-check-label">Aktuelles Bild entfernen</span></label><?php endif; ?>
                <span class="form-hint">JPG, PNG oder WebP, max. 5 MB – wird automatisch verkleinert.</span>
            </div>
            <div class="form-group">
                <label class="form-label" for="storno_frist_std">Online-Storno bis (Stunden vor Beginn)</label>
                <input class="form-control <?= isset($errors['storno_frist_std']) ? 'error' : '' ?>" type="number" min="0" max="720" id="storno_frist_std" name="storno_frist_std" value="<?= e($fw('storno_frist_std')) ?>" placeholder="Standard: <?= e(einstellung('storno_frist_std', '24')) ?>">
                <?php if (isset($errors['storno_frist_std'])): ?><span class="form-error"><?= e($errors['storno_frist_std']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="voraussetzungen">Voraussetzungen</label>
            <textarea class="form-control" id="voraussetzungen" name="voraussetzungen" rows="2" placeholder="z.B. 5 saubere Klimmzüge, eigene Ausrüstung, Schwimmabzeichen …"><?= e($fw('voraussetzungen')) ?></textarea>
            <span class="form-hint">Bei der Anmeldung muss bestätigt werden, dass die Voraussetzungen erfüllt sind.</span>
        </div>

        <div class="form-row">
            <?php if ($projekte): ?>
            <div class="form-group">
                <label class="form-label" for="projekt_id">Projekt</label>
                <select class="form-control" id="projekt_id" name="projekt_id">
                    <option value="">– kein Projekt –</option>
                    <?php foreach ($projekte as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= (int)$fw('projekt_id') === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Kosten (Trainerhonorare) werden dem Projekt zugeordnet.</span>
            </div>
            <?php endif; ?>

            <?php if (isAdmin() && !empty($trainer_liste)): ?>
            <div class="form-group">
                <label class="form-label" for="trainer_id">Trainer*in</label>
                <select class="form-control <?= isset($errors['trainer_id']) ? 'error' : '' ?>" id="trainer_id" name="trainer_id">
                    <?php foreach ($trainer_liste as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ((int)$fw('trainer_id', (string)$user['id']) === (int)$t['id']) ? 'selected' : '' ?>>
                            <?= e($t['vorname'] . ' ' . $t['nachname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-lg"><?= $kurs ? 'Änderungen speichern' : 'Kurs erstellen' ?></button>
    </form>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
