<?php
/**
 * Athletikclub Steiermark – Rollen & Berechtigungen
 * Personen Rollen zuweisen (Präsidium, Administration, Projektleitung, Trainer:in,
 * Mitarbeiter:in, Mitglied) und – nur für Admins – die Rechte-Matrix je Rolle pflegen.
 * Schutz vor Rechteausweitung: Ohne Admin-Status dürfen nur Rollen vergeben werden,
 * deren Rechte man selbst besitzt; Admin-Rollen und die Matrix bleiben Admins vorbehalten.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireDarf('rollen.bearbeiten');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$self   = APP_URL . '/dashboard/admin/rollen.php';
$tab    = ($_GET['tab'] ?? '') === 'matrix' ? 'matrix' : 'personen';
const ADMIN_ROLLEN = ['SUPER_ADMIN', 'ORGANIZATION_ADMIN'];
$rollen_info = [
    'MANAGEMENT'         => ['Präsidium / Management', 'Vorstand: sieht alles, gibt Abrechnungen frei, Finanzen und Förderungen.'],
    'ORGANIZATION_ADMIN' => ['Administrator:in', 'Vollzugriff inkl. Rollen und Einstellungen.'],
    'PROJECT_MANAGER'    => ['Projektleitung', 'Projekte, Einsatzplanung, Aufgaben, Partner.'],
    'ADMINISTRATION'     => ['Mitarbeiter:in / Verwaltung', 'Verwaltung: Kinder, Abrechnungen prüfen, Verträge, Ressourcen.'],
    'TRAINER'            => ['Trainer:in', 'Eigene Einsätze, Zeiterfassung, Kurse, Anwesenheit.'],
    'CUSTOMER'           => ['Mitglied', 'Kurse buchen, Kinder anmelden, eigene Daten.'],
    'SUPER_ADMIN'        => ['Super-Admin', 'Technischer Vollzugriff (alle Organisationen).'],
];
$modul_labels = ['kalender' => 'Kalender & Einsatzplanung', 'anwesenheit' => 'Anwesenheit', 'abrechnung' => 'Trainerabrechnung', 'projekte' => 'Projekte',
                 'aufgaben' => 'Aufgaben', 'qualifikationen' => 'Qualifikationen', 'kinder' => 'Kinder & Einwilligungen', 'partner' => 'Partner/CRM',
                 'ressourcen' => 'Ressourcen', 'vertraege' => 'Verträge', 'foerderungen' => 'Förderungen', 'finanzen' => 'Finanzen', 'rollen' => 'Rollen', 'audit' => 'Audit-Log'];

$rollen = $db->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$rollen_by_id = array_column($rollen, null, 'id');
$permissions = $db->query('SELECT * FROM permissions ORDER BY code')->fetchAll();
$rp = [];
foreach ($db->query('SELECT role_id, permission_id FROM role_permissions')->fetchAll() as $r) $rp[(int)$r['role_id']][(int)$r['permission_id']] = true;
$perm_code = array_column($permissions, 'code', 'id');

/** Darf die aktuelle Person diese Rolle vergeben/entziehen? */
$darf_rolle = function (array $rolle) use ($rp, $perm_code): bool {
    if (isAdmin()) return true;
    if (in_array($rolle['code'], ADMIN_ROLLEN, true)) return false;
    foreach (array_keys($rp[(int)$rolle['id']] ?? []) as $pid) {
        if (!darf($perm_code[$pid] ?? '')) return false;
    }
    return true;
};

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'zuweisen' || $action === 'entziehen') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $rolle = $rollen_by_id[(int)($_POST['role_id'] ?? 0)] ?? null;
        $stmt = $db->prepare('SELECT id, vorname, nachname FROM users WHERE id = ? AND organization_id = ?');
        $stmt->execute([$uid, $org_id]);
        $person = $stmt->fetch();
        if (!$person || !$rolle || !$darf_rolle($rolle)) {
            flashMessage('error', 'Diese Rolle darfst du nicht vergeben oder entziehen.');
            redirect($self . '?suche=' . urlencode($_POST['suche'] ?? ''));
        }
        if ($action === 'entziehen' && $uid === $me && in_array($rolle['code'], ADMIN_ROLLEN, true)) {
            flashMessage('error', 'Die eigene Admin-Rolle kann nicht entzogen werden (Schutz vor Aussperren).');
            redirect($self);
        }
        $name = $person['vorname'] . ' ' . $person['nachname'];
        if ($action === 'zuweisen') {
            $stmt = $db->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ? AND organization_id = ?');
            $stmt->execute([$uid, $rolle['id'], $org_id]);
            if (!$stmt->fetchColumn()) {
                $db->prepare('INSERT INTO user_roles (user_id, role_id, organization_id) VALUES (?, ?, ?)')->execute([$uid, $rolle['id'], $org_id]);
                auditLog('erstellt', 'user_roles', $uid, null, ['rolle' => $rolle['code']], "Rolle {$rolle['code']} an {$name}");
                benachrichtigen($uid, 'projekt', 'Neue Rolle: ' . ($rollen_info[$rolle['code']][0] ?? $rolle['name']), 'Dein Zugriff im Dashboard wurde erweitert.', '/dashboard/index.php');
            }
            flashMessage('success', "{$name}: Rolle „" . ($rollen_info[$rolle['code']][0] ?? $rolle['name']) . '“ zugewiesen.');
        } else {
            $db->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ? AND organization_id = ?')->execute([$uid, $rolle['id'], $org_id]);
            auditLog('geloescht', 'user_roles', $uid, ['rolle' => $rolle['code']], null, "Rolle {$rolle['code']} von {$name}");
            flashMessage('success', "{$name}: Rolle entzogen.");
        }
        redirect($self . '?suche=' . urlencode($_POST['suche'] ?? ''));
    }

    if ($action === 'matrix' && isAdmin()) {
        $rolle = $rollen_by_id[(int)($_POST['role_id'] ?? 0)] ?? null;
        if ($rolle && !in_array($rolle['code'], ADMIN_ROLLEN, true)) {
            $neu = array_values(array_intersect(array_map('intval', (array)($_POST['perm'] ?? [])), array_keys($perm_code)));
            $alt = array_keys($rp[(int)$rolle['id']] ?? []);
            $db->beginTransaction();
            $db->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$rolle['id']]);
            $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($neu as $pid) $ins->execute([$rolle['id'], $pid]);
            $db->commit();
            $weg = array_map(fn($p) => $perm_code[$p], array_diff($alt, $neu));
            $dazu = array_map(fn($p) => $perm_code[$p], array_diff($neu, $alt));
            if ($weg || $dazu) auditLog('geaendert', 'role_permissions', (int)$rolle['id'], null, ['erteilt' => implode(', ', $dazu) ?: null, 'entzogen' => implode(', ', $weg) ?: null], 'Rechte der Rolle ' . $rolle['code']);
            flashMessage('success', 'Rechte für „' . ($rollen_info[$rolle['code']][0] ?? $rolle['name']) . '“ gespeichert.');
        }
        redirect($self . '?tab=matrix#rolle-' . (int)($_POST['role_id'] ?? 0));
    }
    redirect($self);
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$suche = trim($_GET['suche'] ?? '');
$nur_team = !isset($_GET['alle']);
$sql = 'SELECT id, vorname, nachname, email, rolle, aktiv FROM users WHERE organization_id = ?';
$p = [$org_id];
if ($suche !== '') { $sql .= ' AND (vorname LIKE ? OR nachname LIKE ? OR email LIKE ?)'; array_push($p, "%$suche%", "%$suche%", "%$suche%"); }
elseif ($nur_team) { $sql .= " AND (rolle IN ('admin','trainer') OR id IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code <> 'CUSTOMER'))"; }
$stmt = $db->prepare($sql . ' ORDER BY aktiv DESC, nachname, vorname LIMIT 300');
$stmt->execute($p);
$personen = $stmt->fetchAll();
$zuordnung = [];
$stmt = $db->prepare('SELECT user_id, role_id FROM user_roles WHERE organization_id = ?');
$stmt->execute([$org_id]);
foreach ($stmt->fetchAll() as $r) $zuordnung[(int)$r['user_id']][(int)$r['role_id']] = true;

$page_title = 'Rollen & Berechtigungen';
$breadcrumb = 'Rollen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$rname = fn($r) => $rollen_info[$r['code']][0] ?? $r['name'];
?>

<style>
.ro-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.ro-chips { display: flex; gap: 0.35rem; flex-wrap: wrap; align-items: center; }
.ro-chip { display: inline-flex; align-items: center; gap: 0.25rem; }
.ro-chip button { border: none; background: none; cursor: pointer; color: inherit; opacity: 0.6; padding: 0 0.1rem; font-size: 0.8rem; }
.ro-chip button:hover { opacity: 1; }
.ro-mini { font-size: 0.78rem; color: var(--text-muted); }
.ro-add { display: flex; gap: 0.35rem; align-items: center; }
.ro-add select { font-size: 0.8rem; padding: 0.3rem 0.5rem; max-width: 190px; }
.ro-matrix { padding: 1rem 1.25rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 1rem 1.5rem; }
.ro-modul h4 { margin: 0 0 0.35rem; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
.ro-modul label { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; padding: 0.1rem 0; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Rollen &amp; Berechtigungen</h1>
    <p class="dashboard-subtitle">Wer darf was – geprüft wird jede Aktion serverseitig. Mehrere Rollen pro Person sind möglich.</p>
</div>

<div class="ro-tabs">
    <a href="?" class="btn <?= $tab === 'personen' ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm">Personen &amp; Rollen</a>
    <a href="?tab=matrix" class="btn <?= $tab === 'matrix' ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm">Rechte je Rolle</a>
</div>

<?php if ($tab === 'personen'): ?>
    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; align-items: center;">
        <input class="form-control" style="max-width: 280px;" name="suche" value="<?= e($suche) ?>" placeholder="Name oder E-Mail (auch Mitglieder) …">
        <button class="btn btn-navy btn-sm">Suchen</button>
        <?php if ($suche === ''): ?><a href="?<?= $nur_team ? 'alle=1' : '' ?>" class="btn btn-ghost-light btn-sm"><?= $nur_team ? 'Alle Personen zeigen' : 'Nur Team zeigen' ?></a><?php endif; ?>
    </form>
    <div class="table-card">
        <div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Person</th><th>Konto-Typ</th><th>Rollen</th><th>Rolle hinzufügen</th></tr></thead>
            <tbody>
            <?php if (!$personen): ?><tr><td colspan="4" style="text-align: center; color: var(--text-muted);">Keine Personen gefunden.</td></tr><?php endif; ?>
            <?php foreach ($personen as $u): $hat = $zuordnung[(int)$u['id']] ?? []; ?>
                <tr<?= !(int)$u['aktiv'] ? ' style="opacity: 0.55;"' : '' ?>>
                    <td class="text-primary"><?= e($u['vorname'] . ' ' . $u['nachname']) ?><div class="ro-mini"><?= e($u['email']) ?></div></td>
                    <td><span class="role-badge role-<?= e($u['rolle']) ?>"><?= e(ucfirst($u['rolle'])) ?></span></td>
                    <td><div class="ro-chips">
                        <?php foreach ($hat as $rid => $_): $r = $rollen_by_id[$rid] ?? null; if (!$r) continue; ?>
                            <span class="badge <?= in_array($r['code'], ADMIN_ROLLEN, true) ? 'badge-gold' : ($r['code'] === 'CUSTOMER' ? 'badge-gray' : 'badge-navy') ?> ro-chip"><?= e($rname($r)) ?>
                            <?php if ($darf_rolle($r) && !((int)$u['id'] === $me && in_array($r['code'], ADMIN_ROLLEN, true))): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Rolle entziehen?');"><?= csrfField() ?><input type="hidden" name="action" value="entziehen"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="role_id" value="<?= $rid ?>"><input type="hidden" name="suche" value="<?= e($suche) ?>"><button type="submit" aria-label="Rolle entziehen" title="Entziehen">✕</button></form>
                            <?php endif; ?></span>
                        <?php endforeach; ?>
                        <?php if (!$hat): ?><span class="ro-mini">keine</span><?php endif; ?>
                    </div></td>
                    <td>
                        <?php $moeglich = array_filter($rollen, fn($r) => !isset($hat[(int)$r['id']]) && $darf_rolle($r) && $r['code'] !== 'SUPER_ADMIN'); ?>
                        <?php if ($moeglich): ?>
                        <form method="POST" class="ro-add"><?= csrfField() ?><input type="hidden" name="action" value="zuweisen"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="suche" value="<?= e($suche) ?>">
                            <select class="form-control" name="role_id" aria-label="Rolle"><?php foreach ($moeglich as $r): ?><option value="<?= $r['id'] ?>"><?= e($rname($r)) ?></option><?php endforeach; ?></select>
                            <button class="btn btn-ghost-light btn-sm" type="submit">+</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <p class="ro-mini" style="margin-top: 1rem;">Hinweis: Der Konto-Typ (Admin/Trainer/Mitglied) steuert die Grundnavigation und wird in der Nutzerverwaltung festgelegt. Die Rollen hier ergänzen gezielt Rechte, z.B. Projektleitung oder Präsidium.</p>

<?php else: ?>
    <?php if (!isAdmin()): ?><div class="flash-message flash-info" style="border-radius: 0.6rem; margin-bottom: 1rem;"><span>Die Rechte-Matrix kann nur von Administrator:innen geändert werden.</span></div><?php endif; ?>
    <?php
    $gruppen = [];
    foreach ($permissions as $p) { $modul = explode('.', $p['code'])[0]; $gruppen[$modul][] = $p; }
    $reihenfolge = ['MANAGEMENT', 'PROJECT_MANAGER', 'ADMINISTRATION', 'TRAINER', 'CUSTOMER', 'ORGANIZATION_ADMIN', 'SUPER_ADMIN'];
    $pos = fn($code) => ($i = array_search($code, $reihenfolge, true)) === false ? 99 : $i;
    usort($rollen, fn($a, $b) => $pos($a['code']) <=> $pos($b['code']));
    ?>
    <?php foreach ($rollen as $r): $fix = in_array($r['code'], ADMIN_ROLLEN, true); $bearbeitbar = isAdmin() && !$fix; ?>
    <div class="table-card" id="rolle-<?= $r['id'] ?>" style="margin-bottom: 1.25rem;">
        <div class="table-card-header">
            <div><h2 class="table-card-title"><?= e($rname($r)) ?></h2><div class="ro-mini"><?= e($rollen_info[$r['code']][1] ?? '') ?><?= $fix ? ' Hat automatisch alle Rechte.' : '' ?></div></div>
            <span class="badge badge-gray"><?= $fix ? 'alle' : count($rp[(int)$r['id']] ?? []) ?> Rechte</span>
        </div>
        <?php if (!$fix): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="matrix"><input type="hidden" name="role_id" value="<?= $r['id'] ?>">
            <div class="ro-matrix">
                <?php foreach ($gruppen as $modul => $perms): ?>
                <div class="ro-modul"><h4><?= e($modul_labels[$modul] ?? ucfirst($modul)) ?></h4>
                    <?php foreach ($perms as $p): ?>
                    <label title="<?= e($p['beschreibung'] ?? '') ?>"><input type="checkbox" name="perm[]" value="<?= $p['id'] ?>" <?= isset($rp[(int)$r['id']][(int)$p['id']]) ? 'checked' : '' ?> <?= $bearbeitbar ? '' : 'disabled' ?>> <?= e(substr($p['code'], strlen($modul) + 1)) ?></label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($bearbeitbar): ?><div style="padding: 0 1.25rem 1.25rem;"><button type="submit" class="btn btn-navy btn-sm">Rechte speichern</button></div><?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
