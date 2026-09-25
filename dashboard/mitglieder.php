<?php
/**
 * Athletikclub Steiermark – Mitgliederverwaltung (Dashboard, Trainer/Admin)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kommunikation.php';

requireTrainer();

$db = getDB();
$errors = [];
$invite_link = null;

// ----------------------------------------------------------------
// Neues Mitglied anlegen (Trainer/Admin) – immer Rolle "mitglied"
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_mitglied') {
    requireCsrf();

    $vorname  = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');
    $email    = trim(strtolower($_POST['email'] ?? ''));

    if (empty($vorname) || mb_strlen($vorname) < 2) $errors['vorname'] = 'Vorname muss mindestens 2 Zeichen haben.';
    if (empty($nachname) || mb_strlen($nachname) < 2) $errors['nachname'] = 'Nachname muss mindestens 2 Zeichen haben.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';

    if (empty($errors)) {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Diese E-Mail-Adresse ist bereits registriert.';
        }
    }

    if (empty($errors)) {
        try {
            $token = generateToken(32);
            $exp   = date('Y-m-d H:i:s', strtotime('+7 days'));
            $placeholder_hash = hashPassword(bin2hex(random_bytes(16)));
            $org_id = currentOrgId();

            $stmt = $db->prepare(
                'INSERT INTO users (organization_id, vorname, nachname, email, passwort_hash, rolle, email_verified, reset_token, reset_token_exp)
                 VALUES (?, ?, ?, ?, ?, \'mitglied\', 0, ?, ?)'
            );
            $stmt->execute([$org_id, $vorname, $nachname, $email, $placeholder_hash, tokenHash($token), $exp]);
            $new_user_id = (int)$db->lastInsertId();

            $db->prepare('INSERT INTO mitglieder_profile (organization_id, user_id, mitglied_seit, mitgliedsstatus) VALUES (?, ?, NOW(), \'aktiv\')')
               ->execute([$org_id, $new_user_id]);

            $db->prepare(
                "INSERT INTO user_roles (user_id, role_id, organization_id)
                 SELECT ?, id, ? FROM roles WHERE code = 'CUSTOMER'"
            )->execute([$new_user_id, $org_id]);

            $invite_link = APP_URL . '/auth/passwort-reset.php?token=' . $token;

            $subject = 'Dein Konto beim ' . APP_NAME;
            $message = "Hallo {$vorname},\n\n"
                     . "für dich wurde ein Mitgliedskonto beim Athletikclub Steiermark angelegt.\n\n"
                     . "Lege dein Passwort über folgenden Link fest (gültig für 7 Tage):\n"
                     . $invite_link . "\n\n"
                     . "Sportliche Grüße,\nDas Athletikclub-Steiermark-Team";
            mailSenden(getDB(), $email, $subject, $message, null, 'konto');

            logActivity('mitglied_angelegt', "Von Trainer/Admin, neues Mitglied: {$email}");
        } catch (Exception $e) {
            $errors['general'] = 'Mitglied konnte nicht angelegt werden. Bitte versuche es später erneut.';
        }
    }
}

// ----------------------------------------------------------------
// Mitgliedsstatus / Notizen aktualisieren
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    requireCsrf();

    $mitglied_user_id = (int)($_POST['user_id'] ?? 0);
    $status = $_POST['mitgliedsstatus'] ?? '';
    $notizen = trim($_POST['notizen'] ?? '');

    if ($mitglied_user_id && in_array($status, ['aktiv', 'inaktiv', 'ausstehend'], true)) {
        $db->prepare(
            'INSERT INTO mitglieder_profile (organization_id, user_id, mitgliedsstatus, notizen, mitglied_seit)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE mitgliedsstatus = VALUES(mitgliedsstatus), notizen = VALUES(notizen)'
        )->execute([currentOrgId(), $mitglied_user_id, $status, $notizen ?: null]);
        logActivity('mitglied_aktualisiert', "User-ID: {$mitglied_user_id}");
        flashMessage('success', 'Mitgliedsdaten aktualisiert.');
    }
    redirect(APP_URL . '/dashboard/mitglieder.php');
}

// ----------------------------------------------------------------
// Selbst registriertes Mitglied freigeben (nur Admin) + Mitglied informieren
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'freigeben') {
    requireCsrf();
    requireAdmin();

    $mitglied_user_id = (int)($_POST['user_id'] ?? 0);
    $stmt = $db->prepare(
        "SELECT u.vorname, u.email FROM users u
         JOIN mitglieder_profile mp ON mp.user_id = u.id
         WHERE u.id = ? AND u.organization_id = ? AND u.rolle = 'mitglied' AND mp.mitgliedsstatus = 'ausstehend'"
    );
    $stmt->execute([$mitglied_user_id, currentOrgId()]);
    $freizugeben = $stmt->fetch();

    if ($freizugeben) {
        $db->prepare("UPDATE mitglieder_profile SET mitgliedsstatus = 'aktiv' WHERE user_id = ?")
           ->execute([$mitglied_user_id]);

        mailSenden(getDB(), $freizugeben['email'], 'Dein Konto ist freigeschaltet: ' . APP_NAME, "Hallo {$freizugeben['vorname']},\n\n"
            . "dein Konto beim Athletikclub Steiermark wurde freigeschaltet. Du kannst dich jetzt anmelden:\n"
            . APP_URL . "/auth/login.php\n\n"
            . "Sportliche Grüße,\nDas Athletikclub-Steiermark-Team", null, 'konto');

        logActivity('mitglied_freigegeben', "User-ID: {$mitglied_user_id}");
        flashMessage('success', $freizugeben['vorname'] . ' wurde freigegeben und per E-Mail informiert.');
    }
    redirect(APP_URL . '/dashboard/mitglieder.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
}

// ----------------------------------------------------------------
// Filter & Liste
// ----------------------------------------------------------------
$filter_suche  = trim($_GET['suche'] ?? '');
$filter_status = $_GET['status'] ?? '';

$where  = "u.rolle = 'mitglied' AND u.organization_id = ?";
$params = [currentOrgId()];

if ($filter_suche) {
    $where .= ' AND (u.vorname LIKE ? OR u.nachname LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $filter_suche . '%';
    $params[] = '%' . $filter_suche . '%';
    $params[] = '%' . $filter_suche . '%';
}
if (in_array($filter_status, ['aktiv', 'inaktiv', 'ausstehend'], true)) {
    $where .= ' AND mp.mitgliedsstatus = ?';
    $params[] = $filter_status;
}

$stmt = $db->prepare(
    "SELECT u.id, u.vorname, u.nachname, u.email, u.aktiv AS konto_aktiv, u.created_at,
            mp.telefon, mp.ort, mp.sportarten, mp.mitgliedsstatus, mp.mitglied_seit, mp.notizen,
            (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.user_id = u.id AND ka.status = 'angemeldet') AS aktive_kurse
     FROM users u
     LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
     WHERE {$where}
     ORDER BY u.vorname ASC"
);
$stmt->execute($params);
$mitglieder = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM users u JOIN mitglieder_profile mp ON mp.user_id = u.id
     WHERE u.rolle = 'mitglied' AND u.organization_id = ? AND mp.mitgliedsstatus = 'ausstehend'"
);
$stmt->execute([currentOrgId()]);
$anzahl_ausstehend = (int)$stmt->fetchColumn();

$page_title = 'Mitglieder';
$breadcrumb = 'Mitglieder';
require_once ROOT_PATH . '/includes/dashboard-header.php';

$status_labels = [
    'aktiv'      => ['label' => 'Aktiv',      'class' => 'badge-success'],
    'inaktiv'    => ['label' => 'Inaktiv',    'class' => 'badge-danger'],
    'ausstehend' => ['label' => 'Ausstehend', 'class' => 'badge-info'],
];
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Mitglieder</h1>
        <p class="dashboard-subtitle">Alle Mitglieder des Athletikclub Steiermark (<?= count($mitglieder) ?>)</p>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-mitglied-form').classList.toggle('open-row')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Mitglied anlegen
    </button>
</div>

<?php if (isAdmin() && $anzahl_ausstehend > 0 && $filter_status !== 'ausstehend'): ?>
<div class="flash-message flash-warning" style="border-radius: 0.5rem; margin-bottom: 1.5rem;">
    <span>
        <?= $anzahl_ausstehend === 1 ? '1 neue Registrierung wartet' : $anzahl_ausstehend . ' neue Registrierungen warten' ?> auf deine Freigabe.
        <a href="<?= APP_URL ?>/dashboard/mitglieder.php?status=ausstehend" style="font-weight: 700; text-decoration: underline;">Jetzt ansehen</a>
    </span>
</div>
<?php endif; ?>

<?php if ($invite_link): ?>
<div class="flash-message flash-success" style="border-radius: 0.5rem; margin-bottom: 1.5rem; align-items: flex-start;">
    <span>
        ✅ Mitglied wurde angelegt und Einladung per E-Mail verschickt.<br>
        Falls die Mail nicht ankommt, hier der direkte Link zum Passwort-Setzen:<br>
        <code style="word-break: break-all;"><?= e($invite_link) ?></code>
    </span>
</div>
<?php endif; ?>

<?php if (!empty($errors['general'])): ?>
<div class="flash-message flash-error" style="border-radius: 0.5rem; margin-bottom: 1.5rem;">
    <span><?= e($errors['general']) ?></span>
</div>
<?php endif; ?>

<div id="create-mitglied-form" class="form-card edit-row" style="display: none; margin-bottom: 1.5rem;">
    <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.25rem;">Neues Mitglied anlegen</h2>
    <form method="POST" action="" data-validate novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_mitglied">

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="vorname">Vorname <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['vorname']) ? 'error' : '' ?>" type="text" id="vorname" name="vorname" value="<?= e($_POST['vorname'] ?? '') ?>" required placeholder="Vorname">
                <?php if (isset($errors['vorname'])): ?><span class="form-error"><?= e($errors['vorname']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="nachname">Nachname <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['nachname']) ? 'error' : '' ?>" type="text" id="nachname" name="nachname" value="<?= e($_POST['nachname'] ?? '') ?>" required placeholder="Nachname">
                <?php if (isset($errors['nachname'])): ?><span class="form-error"><?= e($errors['nachname']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="email">E-Mail <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>" type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="name@beispiel.at">
                <?php if (isset($errors['email'])): ?><span class="form-error"><?= e($errors['email']) ?></span><?php endif; ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Mitglied anlegen &amp; einladen</button>
    </form>
</div>

<!-- Filter -->
<div style="background: white; border-radius: 1rem; padding: 1rem 1.25rem; border: 1px solid var(--border-light); margin-bottom: 1.5rem;">
    <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
            <label class="form-label">Suche</label>
            <input class="form-control" type="text" name="suche" value="<?= e($filter_suche) ?>" placeholder="Name oder E-Mail…">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="">Alle</option>
                <?php foreach ($status_labels as $val => $info): ?>
                    <option value="<?= $val ?>" <?= $filter_status === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-navy btn-sm" style="margin-bottom: 0;">Filtern</button>
        <?php if ($filter_suche || $filter_status): ?>
            <a href="<?= APP_URL ?>/dashboard/mitglieder.php" class="btn btn-ghost-light btn-sm" style="margin-bottom: 0;">Zurücksetzen</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($mitglieder)): ?>
    <div class="table-card">
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </div>
            <h3>Keine Mitglieder gefunden</h3>
        </div>
    </div>
<?php else: ?>
    <div class="table-card">
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Kontakt</th>
                        <th>Sportarten</th>
                        <th>Kurse</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mitglieder as $m):
                        $status = $m['mitgliedsstatus'] ?? 'ausstehend';
                        $sl = $status_labels[$status] ?? ['label' => $status, 'class' => 'badge-gray'];
                    ?>
                    <tr>
                        <td>
                            <span class="text-primary"><?= e($m['vorname'] . ' ' . $m['nachname']) ?></span>
                            <?php if (!$m['konto_aktiv']): ?><br><span class="badge badge-danger" style="margin-top: 4px;">Konto deaktiviert</span><?php endif; ?>
                        </td>
                        <td>
                            <?= e($m['email']) ?>
                            <?php if ($m['telefon']): ?><br><span style="color: var(--text-muted); font-size: 0.8rem;"><?= e($m['telefon']) ?></span><?php endif; ?>
                        </td>
                        <td><?= $m['sportarten'] ? e($m['sportarten']) : 'k. A.' ?></td>
                        <td><?= (int)$m['aktive_kurse'] ?></td>
                        <td><span class="badge <?= $sl['class'] ?>"><?= e($sl['label']) ?></span></td>
                        <td style="white-space: nowrap;">
                            <?php if (isAdmin() && $m['mitgliedsstatus'] === 'ausstehend'): ?>
                            <form method="POST" style="display: inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="freigeben">
                                <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm">Freigeben</button>
                            </form>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/dashboard/mitglied-detail.php?id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">Fortschritt &amp; Dokumente</a>
                            <?php if (isAdmin()): ?>
                            <button type="button" class="btn btn-ghost-light btn-sm" onclick="document.getElementById('edit-<?= $m['id'] ?>').classList.toggle('open-row')">Bearbeiten</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (isAdmin()): ?>
                    <tr id="edit-<?= $m['id'] ?>" class="edit-row" style="display: none;">
                        <td colspan="6" style="background: var(--bg-muted);">
                            <form method="POST" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; padding: 1rem;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                                <div class="form-group" style="margin: 0;">
                                    <label class="form-label">Status</label>
                                    <select class="form-control" name="mitgliedsstatus">
                                        <?php foreach ($status_labels as $val => $info): ?>
                                            <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0; flex: 1; min-width: 220px;">
                                    <label class="form-label">Interne Notizen</label>
                                    <input class="form-control" type="text" name="notizen" value="<?= e($m['notizen'] ?? '') ?>">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<style>
.edit-row.open-row { display: table-row !important; }
</style>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
