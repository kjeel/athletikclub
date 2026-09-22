<?php
/**
 * Athletikclub Steiermark – Admin: Nutzerverwaltung
 * Neue Trainer*innen/Mitglieder/Admins anlegen und verwalten.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db      = getDB();
$errors  = [];
$invite_link = null;

// ----------------------------------------------------------------
// Neuen Nutzer anlegen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    requireCsrf();

    $vorname  = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');
    $email    = trim(strtolower($_POST['email'] ?? ''));
    $rolle    = $_POST['rolle'] ?? 'trainer';

    if (empty($vorname) || mb_strlen($vorname) < 2) $errors['vorname'] = 'Vorname muss mindestens 2 Zeichen haben.';
    if (empty($nachname) || mb_strlen($nachname) < 2) $errors['nachname'] = 'Nachname muss mindestens 2 Zeichen haben.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    if (!in_array($rolle, ['admin', 'trainer', 'mitglied'], true)) $rolle = 'trainer';

    if (empty($errors)) {
        try {
            // E-Mail ist aktuell global eindeutig (siehe users.email UNIQUE) –
            // Org-Scoping hier vorbereitet für den Tag, an dem das aufgeweicht wird.
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors['email'] = 'Diese E-Mail-Adresse ist bereits registriert.';
            }
        } catch (Exception $e) {
            $errors['general'] = 'Datenbankfehler. Bitte versuche es später erneut.';
        }
    }

    if (empty($errors)) {
        try {
            $token = generateToken(32);
            $exp   = date('Y-m-d H:i:s', strtotime('+7 days'));
            // Platzhalter-Hash: Konto ist erst nach Passwort-Setzen per Link nutzbar.
            $placeholder_hash = hashPassword(bin2hex(random_bytes(16)));
            $org_id = currentOrgId();

            $stmt = $db->prepare(
                'INSERT INTO users (organization_id, vorname, nachname, email, passwort_hash, rolle, email_verified, reset_token, reset_token_exp)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)'
            );
            $stmt->execute([$org_id, $vorname, $nachname, $email, $placeholder_hash, $rolle, $token, $exp]);
            $new_user_id = (int)$db->lastInsertId();

            if ($rolle === 'mitglied') {
                $db->prepare('INSERT INTO mitglieder_profile (organization_id, user_id, mitglied_seit) VALUES (?, ?, NOW())')
                   ->execute([$org_id, $new_user_id]);
            } else {
                $db->prepare('INSERT INTO trainer_profile (organization_id, user_id, erstellt_von) VALUES (?, ?, ?)')
                   ->execute([$org_id, $new_user_id, getCurrentUserId()]);
            }

            $rbac_code = ['admin' => 'ORGANIZATION_ADMIN', 'trainer' => 'TRAINER', 'mitglied' => 'CUSTOMER'][$rolle];
            $db->prepare(
                'INSERT INTO user_roles (user_id, role_id, organization_id)
                 SELECT ?, id, ? FROM roles WHERE code = ?'
            )->execute([$new_user_id, $org_id, $rbac_code]);

            $invite_link = APP_URL . '/auth/passwort-reset.php?token=' . $token;

            $subject = 'Dein Konto beim ' . APP_NAME;
            $message = "Hallo {$vorname},\n\n"
                     . "für dich wurde ein Konto beim Athletikclub Steiermark als " . ucfirst($rolle) . " angelegt.\n\n"
                     . "Lege dein Passwort über folgenden Link fest (gültig für 7 Tage):\n"
                     . $invite_link . "\n\n"
                     . "Sportliche Grüße,\nDas Athletikclub-Steiermark-Team";
            @mail($email, $subject, $message, 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>');

            logActivity('nutzer_angelegt', "Neuer {$rolle}: {$email}");
        } catch (Exception $e) {
            $errors['general'] = 'Nutzer konnte nicht angelegt werden. Bitte versuche es später erneut.';
        }
    }
}

// ----------------------------------------------------------------
// Aktiv/Inaktiv umschalten
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_aktiv') {
    requireCsrf();
    $toggle_id = (int)($_POST['user_id'] ?? 0);
    if ($toggle_id && $toggle_id !== getCurrentUserId()) {
        $db->prepare('UPDATE users SET aktiv = NOT aktiv WHERE id = ? AND organization_id = ?')
           ->execute([$toggle_id, currentOrgId()]);
        logActivity('nutzer_status_geaendert', "User-ID: {$toggle_id}");
    }
    redirect(APP_URL . '/dashboard/admin/nutzerverwaltung.php');
}

// ----------------------------------------------------------------
// Liste laden
// ----------------------------------------------------------------
$stmt = $db->prepare(
    'SELECT id, vorname, nachname, email, rolle, email_verified, aktiv, created_at
     FROM users WHERE organization_id = ? ORDER BY created_at DESC'
);
$stmt->execute([currentOrgId()]);
$users = $stmt->fetchAll();

$page_title = 'Nutzerverwaltung';
$breadcrumb = 'Nutzerverwaltung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Nutzerverwaltung</h1>
    <p class="dashboard-subtitle">Trainer*innen, Mitglieder und Admin-Konten verwalten.</p>
</div>

<?php if ($invite_link): ?>
<div class="flash-message flash-success" style="border-radius: 0.5rem; margin-bottom: 1.5rem; align-items: flex-start;">
    <span>
        ✅ Konto wurde angelegt und Einladung per E-Mail verschickt.<br>
        Falls die Mail nicht ankommt (z. B. weil eure Domain-Mails über einen anderen Anbieter laufen), hier der direkte Link zum Passwort-Setzen:<br>
        <code style="word-break: break-all;"><?= e($invite_link) ?></code>
    </span>
</div>
<?php endif; ?>

<?php if (!empty($errors['general'])): ?>
<div class="flash-message flash-error" style="border-radius: 0.5rem; margin-bottom: 1.5rem;">
    <span><?= e($errors['general']) ?></span>
</div>
<?php endif; ?>

<!-- Neuen Nutzer anlegen -->
<div class="form-card" style="margin-bottom: 2rem;">
    <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1.25rem;">
        Neuen Nutzer anlegen
    </h2>

    <form method="POST" action="" data-validate novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

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
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="email">E-Mail <span class="required">*</span></label>
                <input class="form-control <?= isset($errors['email']) ? 'error' : '' ?>" type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="name@beispiel.at">
                <?php if (isset($errors['email'])): ?><span class="form-error"><?= e($errors['email']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="rolle">Rolle</label>
                <select class="form-control" id="rolle" name="rolle">
                    <option value="trainer" <?= (($_POST['rolle'] ?? 'trainer') === 'trainer') ? 'selected' : '' ?>>Trainer*in</option>
                    <option value="mitglied" <?= (($_POST['rolle'] ?? '') === 'mitglied') ? 'selected' : '' ?>>Mitglied</option>
                    <option value="admin" <?= (($_POST['rolle'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">Nutzer anlegen &amp; einladen</button>
    </form>
</div>

<!-- Nutzerliste -->
<div class="table-card">
    <div class="table-card-header">
        <h2 class="table-card-title">Alle Nutzer (<?= count($users) ?>)</h2>
    </div>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Rolle</th>
                    <th>Status</th>
                    <th>Registriert</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="text-primary"><?= e($u['vorname'] . ' ' . $u['nachname']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="role-badge role-<?= e($u['rolle']) ?>"><?= ucfirst(e($u['rolle'])) ?></span></td>
                    <td>
                        <?php if (!$u['aktiv']): ?>
                            <span class="badge badge-danger">Inaktiv</span>
                        <?php elseif (!$u['email_verified']): ?>
                            <span class="badge badge-info">Ausstehend</span>
                        <?php else: ?>
                            <span class="badge badge-success">Aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td><?= date('d.m.Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <?php if ((int)$u['id'] !== getCurrentUserId()): ?>
                        <form method="POST" action="" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_aktiv">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-ghost-light btn-sm">
                                <?= $u['aktiv'] ? 'Deaktivieren' : 'Aktivieren' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
