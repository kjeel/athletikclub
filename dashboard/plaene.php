<?php
/**
 * Athletikclub Steiermark – Trainings- und Ernährungspläne (Übersicht)
 * Trainer:innen/Admins: alle Pläne, Vorlagen, neuen Plan anlegen.
 * Mitglieder: eigene aktive und abgeschlossene Pläne.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plaene.php';

requireLogin();

$db     = getDB();
$user   = getCurrentUser();
$org_id = currentOrgId();
$errors = [];

// ----------------------------------------------------------------
// Neuen Plan anlegen (nur Trainer:innen), optional als Kopie einer Vorlage
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'plan_erstellen') {
    requireTrainer();
    requireCsrf();

    $typ         = ($_POST['typ'] ?? '') === 'ernaehrung' ? 'ernaehrung' : 'training';
    $mitglied_id = (int)($_POST['mitglied_id'] ?? 0) ?: null;
    $titel       = trim($_POST['titel'] ?? '');
    $vorlage_id  = (int)($_POST['vorlage_id'] ?? 0);

    if ($mitglied_id) {
        $stmt = $db->prepare("SELECT u.id, mp.geburtsdatum FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id WHERE u.id = ? AND u.organization_id = ? AND u.rolle = 'mitglied'");
        $stmt->execute([$mitglied_id, $org_id]);
        $mitglied = $stmt->fetch();
        if (!$mitglied) $errors['mitglied_id'] = 'Mitglied nicht gefunden.';
    }
    if ($titel === '' && !$vorlage_id) $errors['titel'] = 'Bitte einen Titel angeben.';

    if (empty($errors)) {
        $db->beginTransaction();
        if ($typ === 'training') {
            $vorlage = null;
            if ($vorlage_id) {
                $stmt = $db->prepare('SELECT * FROM trainingsplaene WHERE id = ? AND organization_id = ?');
                $stmt->execute([$vorlage_id, $org_id]);
                $vorlage = $stmt->fetch() ?: null;
            }
            $ziel = $_POST['ziel'] ?? ($vorlage['ziel'] ?? 'allgemeine_fitness');
            $db->prepare(
                'INSERT INTO trainingsplaene (organization_id, mitglied_id, trainer_id, titel, ziel, niveau, start_datum, dauer_wochen, einheiten_pro_woche, hinweise)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $org_id, $mitglied_id, $user['id'],
                $titel !== '' ? $titel : $vorlage['titel'],
                isset(TP_ZIELE[$ziel]) ? $ziel : 'allgemeine_fitness',
                $vorlage['niveau'] ?? (isset(TP_NIVEAUS[$_POST['niveau'] ?? '']) ? $_POST['niveau'] : 'einsteiger'),
                $mitglied_id ? date('Y-m-d') : null,
                $vorlage['dauer_wochen'] ?? max(1, min(52, (int)($_POST['dauer_wochen'] ?? 6))),
                $vorlage['einheiten_pro_woche'] ?? max(1, min(14, (int)($_POST['einheiten_pro_woche'] ?? 3))),
                $vorlage['hinweise'] ?? null,
            ]);
            $neu_id = (int)$db->lastInsertId();
            if ($vorlage) trainingsplanKopieren($db, (int)$vorlage['id'], $neu_id);
            $ziel_url = 'trainingsplan.php';
        } else {
            $vorlage = null;
            if ($vorlage_id) {
                $stmt = $db->prepare('SELECT * FROM ernaehrungsplaene WHERE id = ? AND organization_id = ?');
                $stmt->execute([$vorlage_id, $org_id]);
                $vorlage = $stmt->fetch() ?: null;
            }
            $db->prepare(
                'INSERT INTO ernaehrungsplaene (organization_id, mitglied_id, trainer_id, titel, ziel, alter_jahre, fett_prozent, hinweise)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $org_id, $mitglied_id, $user['id'],
                $titel !== '' ? $titel : $vorlage['titel'],
                $vorlage['ziel'] ?? 'halten',
                $mitglied_id ? alterAus($mitglied['geburtsdatum'] ?? null) : null,
                $vorlage['fett_prozent'] ?? 30,
                $vorlage['hinweise'] ?? null,
            ]);
            $neu_id = (int)$db->lastInsertId();
            if ($vorlage) ernaehrungsplanKopieren($db, (int)$vorlage['id'], $neu_id);
            $ziel_url = 'ernaehrungsplan.php';
        }
        $db->commit();
        logActivity($typ === 'training' ? 'trainingsplan_erstellt' : 'ernaehrungsplan_erstellt', "Plan-ID: {$neu_id}" . ($mitglied_id ? ", Mitglied-ID: {$mitglied_id}" : ', Vorlage'));
        flashMessage('success', ($typ === 'training' ? 'Trainingsplan' : 'Ernährungsplan') . ' angelegt.');
        redirect(APP_URL . '/dashboard/' . $ziel_url . '?id=' . $neu_id);
    }
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$filter_mitglied = (int)($_GET['mitglied'] ?? 0);
$filter_ansicht  = $_GET['ansicht'] ?? 'mitglieder'; // mitglieder | vorlagen | meine

if (isTrainer()) {
    $where  = 'p.organization_id = ?';
    $params = [$org_id];
    if ($filter_ansicht === 'vorlagen') {
        $where .= ' AND p.mitglied_id IS NULL';
    } else {
        $where .= ' AND p.mitglied_id IS NOT NULL';
        if ($filter_ansicht === 'meine') { $where .= ' AND p.trainer_id = ?'; $params[] = $user['id']; }
        if ($filter_mitglied) { $where .= ' AND p.mitglied_id = ?'; $params[] = $filter_mitglied; }
    }
} else {
    $where  = "p.organization_id = ? AND p.mitglied_id = ? AND p.status <> 'entwurf'";
    $params = [$org_id, $user['id']];
}

$sql = "SELECT '%s' AS typ, p.id, p.titel, p.status, p.start_datum, p.updated_at, p.mitglied_id,
               m.vorname AS m_vorname, m.nachname AS m_nachname, t.vorname AS t_vorname, t.nachname AS t_nachname, %s AS detail
        FROM %s p LEFT JOIN users m ON m.id = p.mitglied_id JOIN users t ON t.id = p.trainer_id
        WHERE {$where}";
$stmt = $db->prepare(sprintf($sql, 'training', 'p.ziel', 'trainingsplaene') . ' UNION ALL ' . sprintf($sql, 'ernaehrung', 'p.ziel', 'ernaehrungsplaene') . ' ORDER BY updated_at DESC');
$stmt->execute(array_merge($params, $params));
$plaene = $stmt->fetchAll();

$mitglieder = [];
$vorlagen   = ['training' => [], 'ernaehrung' => []];
if (isTrainer()) {
    $stmt = $db->prepare("SELECT u.id, u.vorname, u.nachname FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
                          WHERE u.organization_id = ? AND u.rolle = 'mitglied' AND COALESCE(mp.mitgliedsstatus, 'aktiv') <> 'inaktiv'
                          ORDER BY u.nachname, u.vorname");
    $stmt->execute([$org_id]);
    $mitglieder = $stmt->fetchAll();
    foreach (['training' => 'trainingsplaene', 'ernaehrung' => 'ernaehrungsplaene'] as $typ => $tabelle) {
        $stmt = $db->prepare("SELECT id, titel FROM {$tabelle} WHERE organization_id = ? AND mitglied_id IS NULL ORDER BY titel");
        $stmt->execute([$org_id]);
        $vorlagen[$typ] = $stmt->fetchAll();
    }
}

$form = array_merge(['typ' => $_GET['typ'] ?? 'training', 'mitglied_id' => $filter_mitglied ?: '', 'titel' => '', 'ziel' => 'allgemeine_fitness',
                     'niveau' => 'einsteiger', 'dauer_wochen' => 6, 'einheiten_pro_woche' => 3, 'vorlage_id' => ''], $_POST);

$page_title = isTrainer() ? 'Trainings- & Ernährungspläne' : 'Meine Pläne';
$breadcrumb = $page_title;
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title"><?= e($page_title) ?></h1>
    <p class="dashboard-subtitle">
        <?= isTrainer()
            ? 'Individuelle Trainingspläne und Ernährungspläne für Mitglieder erstellen, Vorlagen verwalten und Trainingsprotokolle einsehen.'
            : 'Deine Trainings- und Ernährungspläne von deinen Trainer:innen. Hake absolvierte Einheiten ab, damit dein Fortschritt sichtbar wird.' ?>
    </p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<?php if (isTrainer()): ?>
<div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
    <?php foreach (['mitglieder' => 'Alle Mitglieder-Pläne', 'meine' => 'Von mir erstellt', 'vorlagen' => 'Vorlagen'] as $val => $label): ?>
        <a href="<?= APP_URL ?>/dashboard/plaene.php?ansicht=<?= $val ?>" class="btn btn-sm <?= $filter_ansicht === $val ? 'btn-navy' : 'btn-ghost-light' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <a href="<?= APP_URL ?>/dashboard/uebungen.php" class="btn btn-sm btn-ghost-light" style="margin-left: auto;">Übungsbibliothek</a>
</div>
<?php endif; ?>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title"><?= isTrainer() ? ($filter_ansicht === 'vorlagen' ? 'Vorlagen' : 'Pläne') : 'Meine Pläne' ?> (<?= count($plaene) ?>)</h2>
    </div>
    <?php if (empty($plaene)): ?>
        <div class="empty-state">
            <h3><?= isTrainer() ? 'Noch keine Pläne' : 'Noch keine Pläne für dich' ?></h3>
            <p><?= isTrainer() ? 'Lege unten den ersten Trainings- oder Ernährungsplan an.' : 'Sobald deine Trainerin bzw. dein Trainer einen Plan freigibt, erscheint er hier.' ?></p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Art</th>
                        <?php if (isTrainer() && $filter_ansicht !== 'vorlagen'): ?><th>Mitglied</th><?php endif; ?>
                        <th>Trainer:in</th>
                        <th>Status</th>
                        <th>Aktualisiert</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plaene as $p): $s = PLAN_STATUS[$p['status']];
                        $url = APP_URL . '/dashboard/' . ($p['typ'] === 'training' ? 'trainingsplan.php' : 'ernaehrungsplan.php') . '?id=' . $p['id']; ?>
                    <tr>
                        <td>
                            <div class="text-primary"><?= e($p['titel']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                <?= e($p['typ'] === 'training' ? (TP_ZIELE[$p['detail']]['label'] ?? '') : (EP_ZIELE[$p['detail']]['label'] ?? '')) ?>
                            </div>
                        </td>
                        <td><span class="badge <?= $p['typ'] === 'training' ? 'badge-navy' : 'badge-gold' ?>"><?= $p['typ'] === 'training' ? 'Training' : 'Ernährung' ?></span></td>
                        <?php if (isTrainer() && $filter_ansicht !== 'vorlagen'): ?>
                            <td><a href="<?= APP_URL ?>/dashboard/plaene.php?mitglied=<?= (int)$p['mitglied_id'] ?>"><?= e($p['m_vorname'] . ' ' . $p['m_nachname']) ?></a></td>
                        <?php endif; ?>
                        <td><?= e($p['t_vorname'] . ' ' . $p['t_nachname']) ?></td>
                        <td><span class="badge <?= $s['class'] ?>"><?= e($s['label']) ?></span></td>
                        <td><?= date('d.m.Y', strtotime($p['updated_at'])) ?></td>
                        <td><a href="<?= $url ?>" class="btn btn-ghost-light btn-sm">Öffnen</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (isTrainer()): ?>
<div class="table-card" id="neu">
    <div class="table-card-header">
        <h2 class="table-card-title">Neuen Plan anlegen</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= APP_URL ?>/dashboard/plaene.php#neu" id="plan-neu">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="plan_erstellen">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Art des Plans</label>
                    <select class="form-control" name="typ" id="plan-typ">
                        <option value="training" <?= $form['typ'] === 'training' ? 'selected' : '' ?>>Trainingsplan</option>
                        <option value="ernaehrung" <?= $form['typ'] === 'ernaehrung' ? 'selected' : '' ?>>Ernährungsplan</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Für</label>
                    <select class="form-control <?= isset($errors['mitglied_id']) ? 'error' : '' ?>" name="mitglied_id">
                        <option value="">– Vorlage (für mehrere Mitglieder wiederverwendbar) –</option>
                        <?php foreach ($mitglieder as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= (string)$form['mitglied_id'] === (string)$m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Titel</label>
                    <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" name="titel" maxlength="150" value="<?= e((string)$form['titel']) ?>" placeholder="z.B. Calisthenics Grundlagen – Block 1">
                </div>
                <div class="form-group">
                    <label class="form-label">Aus Vorlage übernehmen (optional)</label>
                    <select class="form-control" name="vorlage_id" id="plan-vorlage">
                        <option value="">– leer beginnen –</option>
                        <?php foreach ($vorlagen as $typ => $liste): foreach ($liste as $v): ?>
                            <option value="<?= $v['id'] ?>" data-typ="<?= $typ ?>" <?= (string)$form['vorlage_id'] === (string)$v['id'] ? 'selected' : '' ?>><?= e($v['titel']) ?></option>
                        <?php endforeach; endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row nur-training">
                <div class="form-group">
                    <label class="form-label">Trainingsziel</label>
                    <select class="form-control" name="ziel">
                        <?php foreach (TP_ZIELE as $val => $z): ?>
                            <option value="<?= $val ?>" <?= $form['ziel'] === $val ? 'selected' : '' ?>><?= e($z['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Niveau</label>
                        <select class="form-control" name="niveau">
                            <?php foreach (TP_NIVEAUS as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $form['niveau'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Wochen</label>
                        <input class="form-control" type="number" min="1" max="52" name="dauer_wochen" value="<?= (int)$form['dauer_wochen'] ?>">
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Einheiten/Woche</label>
                        <input class="form-control" type="number" min="1" max="14" name="einheiten_pro_woche" value="<?= (int)$form['einheiten_pro_woche'] ?>">
                    </div>
                </div>
            </div>
            <p class="form-hint nur-ernaehrung">Ernährungspläne sind nur für gesunde Erwachsene zulässig – im nächsten Schritt wird der Gesundheits-Check abgefragt.</p>
            <button type="submit" class="btn btn-primary btn-sm">Plan anlegen</button>
        </form>
    </div>
</div>

<script>
(() => {
    const typ = document.getElementById('plan-typ');
    const vorlage = document.getElementById('plan-vorlage');
    function umschalten() {
        const t = typ.value;
        document.querySelectorAll('.nur-training').forEach(el => el.style.display = t === 'training' ? '' : 'none');
        document.querySelectorAll('.nur-ernaehrung').forEach(el => el.style.display = t === 'ernaehrung' ? '' : 'none');
        // Nur Vorlagen der passenden Art anbieten
        [...vorlage.options].forEach(o => { if (o.dataset.typ) o.hidden = o.dataset.typ !== t; });
        if (vorlage.selectedOptions[0]?.hidden) vorlage.value = '';
    }
    typ.addEventListener('change', umschalten);
    umschalten();
})();
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
