<?php
/**
 * Athletikclub Steiermark – Trainingsplan
 * Trainer:innen: Plan, Gesundheits-Check, Einheiten und Übungen bearbeiten, Vorlagen.
 * Mitglieder: eigenen Plan ansehen und absolvierte Einheiten protokollieren.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plaene.php';

requireLogin();

$db      = getDB();
$user    = getCurrentUser();
$plan_id = (int)($_GET['id'] ?? 0);
$plan    = planLaden($db, 'trainingsplaene', $plan_id);

if (!$plan) {
    flashMessage('error', 'Trainingsplan nicht gefunden.');
    redirect(APP_URL . '/dashboard/plaene.php');
}

$self_url  = APP_URL . '/dashboard/trainingsplan.php?id=' . $plan_id;
$ist_eigen = (int)$plan['mitglied_id'] === (int)$user['id'];
$errors    = [];

/** Zahl aus einem Formularfeld oder null. */
function tpZahl($wert, int $max): ?int
{
    $wert = trim((string)$wert);
    return $wert === '' ? null : max(0, min($max, (int)$wert));
}

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // ---- Mitglied: Einheit protokollieren ----
    if ($action === 'protokoll_eintragen' && $ist_eigen && $plan['status'] === 'aktiv') {
        $einheit_id = (int)($_POST['einheit_id'] ?? 0);
        $datum      = trim($_POST['datum'] ?? '');
        $stmt = $db->prepare('SELECT id FROM trainingsplan_einheiten WHERE id = ? AND plan_id = ?');
        $stmt->execute([$einheit_id, $plan_id]);
        if ($stmt->fetch() && strtotime($datum) && $datum <= date('Y-m-d')) {
            $rpe = trim(str_replace(',', '.', $_POST['rpe'] ?? ''));
            $db->prepare('INSERT INTO trainingsplan_protokoll (plan_id, einheit_id, user_id, datum, rpe, notiz) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$plan_id, $einheit_id, $user['id'], date('Y-m-d', strtotime($datum)),
                          $rpe !== '' ? max(1, min(10, (float)$rpe)) : null, mb_substr(trim($_POST['notiz'] ?? ''), 0, 500) ?: null]);
            flashMessage('success', 'Stark! Einheit eingetragen.');
        } else {
            flashMessage('error', 'Bitte ein gültiges Datum (nicht in der Zukunft) angeben.');
        }
        redirect($self_url . '#protokoll');
    }

    if ($action === 'protokoll_loeschen' && $ist_eigen) {
        $db->prepare('DELETE FROM trainingsplan_protokoll WHERE id = ? AND plan_id = ? AND user_id = ?')
           ->execute([(int)($_POST['protokoll_id'] ?? 0), $plan_id, $user['id']]);
        redirect($self_url . '#protokoll');
    }

    // ---- Ab hier nur Trainer:innen ----
    if (!isTrainer()) redirect($self_url);

    if ($action === 'plan_speichern') {
        $titel  = trim($_POST['titel'] ?? '');
        $status = $_POST['status'] ?? 'entwurf';
        $checks = array_values(array_intersect(array_keys(TP_GESUNDHEIT), (array)($_POST['gesundheit'] ?? [])));
        $notiz  = trim($_POST['gesundheit_notiz'] ?? '');
        if ($titel === '') $errors['titel'] = 'Bitte einen Titel angeben.';
        if (!isset(PLAN_STATUS[$status])) $status = 'entwurf';
        if (!$plan['mitglied_id']) $status = 'entwurf'; // Vorlagen werden nicht freigegeben
        if ($status === 'aktiv' && count($checks) < count(TP_GESUNDHEIT) && $notiz === '') {
            $errors['gesundheit'] = 'Nicht alle Gesundheitsfragen sind unauffällig – bitte ärztliche Abklärung bzw. Einschränkungen im Notizfeld dokumentieren, bevor der Plan aktiv wird.';
        }
        if (empty($errors)) {
            $ziel = $_POST['ziel'] ?? 'allgemeine_fitness';
            $db->prepare(
                'UPDATE trainingsplaene SET titel = ?, ziel = ?, niveau = ?, start_datum = ?, dauer_wochen = ?, einheiten_pro_woche = ?, status = ?,
                    gesundheit_checks = ?, gesundheit_notiz = ?, hinweise = ? WHERE id = ?'
            )->execute([
                mb_substr($titel, 0, 150),
                isset(TP_ZIELE[$ziel]) ? $ziel : 'allgemeine_fitness',
                isset(TP_NIVEAUS[$_POST['niveau'] ?? '']) ? $_POST['niveau'] : 'einsteiger',
                strtotime($_POST['start_datum'] ?? '') ? date('Y-m-d', strtotime($_POST['start_datum'])) : null,
                max(1, min(52, (int)($_POST['dauer_wochen'] ?? 6))),
                max(1, min(14, (int)($_POST['einheiten_pro_woche'] ?? 3))),
                $status, json_encode($checks), $notiz ?: null,
                trim($_POST['hinweise'] ?? '') ?: null,
                $plan_id,
            ]);
            logActivity('trainingsplan_gespeichert', "Plan-ID: {$plan_id}, Status: {$status}");
            flashMessage('success', 'Plan gespeichert.' . ($status === 'aktiv' && $plan['status'] !== 'aktiv' ? ' Das Mitglied sieht ihn jetzt unter „Meine Pläne“.' : ''));
            redirect($self_url);
        }
    }

    if ($action === 'einheit_neu') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors['einheit'] = 'Bitte die Einheit benennen (z.B. „Tag A – Ganzkörper“).';
        } else {
            $stmt = $db->prepare('SELECT COALESCE(MAX(sortierung), 0) + 1 FROM trainingsplan_einheiten WHERE plan_id = ?');
            $stmt->execute([$plan_id]);
            $db->prepare('INSERT INTO trainingsplan_einheiten (plan_id, name, wochentag, sortierung) VALUES (?, ?, ?, ?)')
               ->execute([$plan_id, mb_substr($name, 0, 120), tpZahl($_POST['wochentag'] ?? '', 7) ?: null, (int)$stmt->fetchColumn()]);
            redirect($self_url . '#einheit-' . $db->lastInsertId());
        }
    }

    if ($action === 'einheit_speichern') {
        $einheit_id = (int)($_POST['einheit_id'] ?? 0);
        $stmt = $db->prepare('SELECT id FROM trainingsplan_einheiten WHERE id = ? AND plan_id = ?');
        $stmt->execute([$einheit_id, $plan_id]);
        if ($stmt->fetch()) {
            // Übungsnamen der Bibliothek zuordnen, damit Beschreibung und Video verlinkt werden
            $stmt = $db->prepare('SELECT LOWER(name) AS schluessel, id FROM uebungen WHERE organization_id = ?');
            $stmt->execute([currentOrgId()]);
            $bibliothek = array_column($stmt->fetchAll(), 'id', 'schluessel');

            $db->beginTransaction();
            $db->prepare('UPDATE trainingsplan_einheiten SET name = ?, wochentag = ?, aufwaermen = ?, notiz = ? WHERE id = ?')
               ->execute([mb_substr(trim($_POST['name'] ?? '') ?: 'Einheit', 0, 120), tpZahl($_POST['wochentag'] ?? '', 7) ?: null,
                          trim($_POST['aufwaermen'] ?? '') ?: null, trim($_POST['notiz'] ?? '') ?: null, $einheit_id]);
            $db->prepare('DELETE FROM trainingsplan_uebungen WHERE einheit_id = ?')->execute([$einheit_id]);
            $ins = $db->prepare(
                'INSERT INTO trainingsplan_uebungen (einheit_id, uebung_id, uebung_name, saetze, wiederholungen, last, rpe, tempo, pause_sek, notiz, sortierung)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $nr = 0;
            foreach ((array)($_POST['u_name'] ?? []) as $i => $name) {
                $name = trim((string)$name);
                if ($name === '') continue;
                $rpe = trim(str_replace(',', '.', (string)($_POST['u_rpe'][$i] ?? '')));
                $ins->execute([
                    $einheit_id,
                    $bibliothek[mb_strtolower($name)] ?? null,
                    mb_substr($name, 0, 120),
                    tpZahl($_POST['u_saetze'][$i] ?? '', 20),
                    mb_substr(trim((string)($_POST['u_wdh'][$i] ?? '')), 0, 30) ?: null,
                    mb_substr(trim((string)($_POST['u_last'][$i] ?? '')), 0, 40) ?: null,
                    $rpe !== '' ? max(1, min(10, (float)$rpe)) : null,
                    mb_substr(trim((string)($_POST['u_tempo'][$i] ?? '')), 0, 12) ?: null,
                    tpZahl($_POST['u_pause'][$i] ?? '', 900),
                    mb_substr(trim((string)($_POST['u_notiz'][$i] ?? '')), 0, 255) ?: null,
                    $nr++,
                ]);
            }
            $db->commit();
            flashMessage('success', 'Einheit gespeichert.');
        }
        redirect($self_url . '#einheit-' . $einheit_id);
    }

    if ($action === 'einheit_duplizieren' || $action === 'einheit_loeschen') {
        $einheit_id = (int)($_POST['einheit_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM trainingsplan_einheiten WHERE id = ? AND plan_id = ?');
        $stmt->execute([$einheit_id, $plan_id]);
        if ($e = $stmt->fetch()) {
            if ($action === 'einheit_loeschen') {
                $db->prepare('DELETE FROM trainingsplan_einheiten WHERE id = ?')->execute([$einheit_id]);
                flashMessage('success', 'Einheit gelöscht.');
            } else {
                $db->prepare('INSERT INTO trainingsplan_einheiten (plan_id, name, wochentag, aufwaermen, notiz, sortierung) VALUES (?, ?, NULL, ?, ?, ?)')
                   ->execute([$plan_id, mb_substr($e['name'] . ' (Kopie)', 0, 120), $e['aufwaermen'], $e['notiz'], (int)$e['sortierung'] + 1]);
                $neu = (int)$db->lastInsertId();
                $db->prepare('INSERT INTO trainingsplan_uebungen (einheit_id, uebung_id, uebung_name, saetze, wiederholungen, last, rpe, tempo, pause_sek, notiz, sortierung)
                              SELECT ?, uebung_id, uebung_name, saetze, wiederholungen, last, rpe, tempo, pause_sek, notiz, sortierung FROM trainingsplan_uebungen WHERE einheit_id = ?')
                   ->execute([$neu, $einheit_id]);
                redirect($self_url . '#einheit-' . $neu);
            }
        }
        redirect($self_url . '#einheiten');
    }

    if ($action === 'kopieren') {
        // Als Vorlage speichern (ziel_mitglied leer) oder für ein anderes Mitglied übernehmen
        $ziel_mitglied = (int)($_POST['ziel_mitglied'] ?? 0) ?: null;
        if ($ziel_mitglied) {
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ? AND rolle = 'mitglied'");
            $stmt->execute([$ziel_mitglied, currentOrgId()]);
            if (!$stmt->fetch()) redirect($self_url);
        }
        $db->beginTransaction();
        $db->prepare(
            'INSERT INTO trainingsplaene (organization_id, mitglied_id, trainer_id, titel, ziel, niveau, start_datum, dauer_wochen, einheiten_pro_woche, hinweise)
             SELECT organization_id, ?, ?, titel, ziel, niveau, ?, dauer_wochen, einheiten_pro_woche, hinweise FROM trainingsplaene WHERE id = ?'
        )->execute([$ziel_mitglied, $user['id'], $ziel_mitglied ? date('Y-m-d') : null, $plan_id]);
        $neu_id = (int)$db->lastInsertId();
        trainingsplanKopieren($db, $plan_id, $neu_id);
        $db->commit();
        logActivity('trainingsplan_kopiert', "Von Plan-ID {$plan_id} nach {$neu_id}");
        flashMessage('success', $ziel_mitglied ? 'Plan für das Mitglied übernommen – bitte Gesundheits-Check ausfüllen und freigeben.' : 'Als Vorlage gespeichert.');
        redirect(APP_URL . '/dashboard/trainingsplan.php?id=' . $neu_id);
    }

    if ($action === 'plan_loeschen') {
        $db->prepare('DELETE FROM trainingsplaene WHERE id = ? AND organization_id = ?')->execute([$plan_id, currentOrgId()]);
        logActivity('trainingsplan_geloescht', "Plan-ID: {$plan_id}");
        flashMessage('success', 'Trainingsplan gelöscht.');
        redirect(APP_URL . '/dashboard/plaene.php');
    }
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM trainingsplan_einheiten WHERE plan_id = ? ORDER BY sortierung, id');
$stmt->execute([$plan_id]);
$einheiten = $stmt->fetchAll();

$uebungen_je_einheit = [];
if ($einheiten) {
    $stmt = $db->prepare(
        'SELECT tu.*, u.beschreibung, u.video_url FROM trainingsplan_uebungen tu LEFT JOIN uebungen u ON u.id = tu.uebung_id
         WHERE tu.einheit_id IN (' . implode(',', array_fill(0, count($einheiten), '?')) . ') ORDER BY tu.sortierung, tu.id'
    );
    $stmt->execute(array_column($einheiten, 'id'));
    foreach ($stmt->fetchAll() as $u) $uebungen_je_einheit[$u['einheit_id']][] = $u;
}

$stmt = $db->prepare('SELECT p.*, e.name AS einheit_name FROM trainingsplan_protokoll p JOIN trainingsplan_einheiten e ON e.id = p.einheit_id WHERE p.plan_id = ? ORDER BY p.datum DESC, p.id DESC');
$stmt->execute([$plan_id]);
$protokoll = $stmt->fetchAll();
$geplant   = (int)$plan['dauer_wochen'] * (int)$plan['einheiten_pro_woche'];
$fortschritt = $geplant ? min(100, round(count($protokoll) / $geplant * 100)) : 0;

$bibliothek = [];
$mitglieder = [];
if (isTrainer()) {
    $stmt = $db->prepare('SELECT name, kategorie FROM uebungen WHERE organization_id = ? ORDER BY name');
    $stmt->execute([currentOrgId()]);
    $bibliothek = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT id, vorname, nachname FROM users WHERE organization_id = ? AND rolle = 'mitglied' ORDER BY nachname, vorname");
    $stmt->execute([currentOrgId()]);
    $mitglieder = $stmt->fetchAll();
}

$checks = planChecks($plan['gesundheit_checks']);
if (($_POST['action'] ?? '') === 'plan_speichern') {
    $plan   = array_merge($plan, $_POST);
    $checks = (array)($_POST['gesundheit'] ?? []);
}
$s = PLAN_STATUS[$plan['status']] ?? PLAN_STATUS['entwurf'];
$v = fn($wert) => e((string)($wert ?? ''));

$page_title = $plan['titel'];
$breadcrumb = isTrainer() ? 'Trainings- & Ernährungspläne' : 'Meine Pläne';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.tp-einheit { border: 1px solid var(--border-light); border-radius: 0.75rem; margin-bottom: 1rem; }
.tp-einheit-kopf { padding: 0.9rem 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; border-bottom: 1px solid var(--border-light); }
.tp-einheit-kopf h3 { font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; margin: 0; }
.tp-tabelle input { min-width: 60px; padding: 0.35rem 0.5rem; font-size: 0.85rem; }
.tp-tabelle td { padding: 0.35rem; vertical-align: top; }
.tp-hinweis { background: var(--gold-dim); border-radius: 0.5rem; padding: 0.75rem 1rem; font-size: 0.875rem; margin-bottom: 1rem; }
.tp-abschnitt { font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin: 1.25rem 0 0.75rem; }
.tp-balken { height: 10px; background: var(--border-light); border-radius: 5px; overflow: hidden; }
.tp-balken > div { height: 100%; background: #22C55E; }
</style>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/plaene.php<?= isTrainer() && $plan['mitglied_id'] ? '?mitglied=' . (int)$plan['mitglied_id'] : '' ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <?= isTrainer() ? 'Alle Pläne' : 'Meine Pläne' ?>
        </a>
        <h1 class="dashboard-title"><?= e($plan['titel']) ?> <span class="badge <?= $s['class'] ?>" style="vertical-align: middle;"><?= e($s['label']) ?></span></h1>
        <p class="dashboard-subtitle">
            Trainingsplan für <strong><?= e(planFuer($plan)) ?></strong> · <?= e(TP_ZIELE[$plan['ziel']]['label'] ?? '') ?> · <?= e(TP_NIVEAUS[$plan['niveau']] ?? '') ?>
            · <?= (int)$plan['dauer_wochen'] ?> Wochen, <?= (int)$plan['einheiten_pro_woche'] ?>× pro Woche · Trainer:in <?= e($plan['t_vorname'] . ' ' . $plan['t_nachname']) ?>
        </p>
    </div>
    <a href="<?= APP_URL ?>/dashboard/plan-pdf.php?typ=training&amp;id=<?= $plan_id ?>" target="_blank" class="btn btn-primary btn-sm">Als PDF</a>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<?php if ($plan['mitglied_id']): ?>
<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #22C55E;">
        <div class="kpi-value"><?= count($protokoll) ?> / <?= $geplant ?></div>
        <div class="kpi-label">Absolvierte Einheiten</div>
        <div class="tp-balken" style="margin-top: 0.5rem;"><div style="width: <?= $fortschritt ?>%;"></div></div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-value"><?= $protokoll ? date('d.m.', strtotime($protokoll[0]['datum'])) : '–' ?></div>
        <div class="kpi-label">Zuletzt trainiert</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <?php $rpes = array_filter(array_column($protokoll, 'rpe'), fn($r) => $r !== null); ?>
        <div class="kpi-value"><?= $rpes ? number_format(array_sum($rpes) / count($rpes), 1, ',', '') : '–' ?></div>
        <div class="kpi-label">Ø Anstrengung (RPE 1–10)</div>
    </div>
</div>
<?php endif; ?>

<?php if (isTrainer()): ?>
<!-- ================= Trainer:innen-Ansicht ================= -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Plandaten &amp; Gesundheits-Check</h2></div>
    <div style="padding: 1.25rem;">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="plan_speichern">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Titel <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" name="titel" maxlength="150" value="<?= $v($plan['titel']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status" <?= $plan['mitglied_id'] ? '' : 'disabled' ?>>
                        <?php foreach (PLAN_STATUS as $val => $st): ?>
                            <option value="<?= $val ?>" <?= $plan['status'] === $val ? 'selected' : '' ?>><?= e($st['label']) ?><?= $val === 'aktiv' ? ' (für das Mitglied sichtbar)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$plan['mitglied_id']): ?><p class="form-hint">Vorlagen sind nur für Trainer:innen sichtbar.</p><?php endif; ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Trainingsziel</label>
                    <select class="form-control" name="ziel" id="tp-ziel">
                        <?php foreach (TP_ZIELE as $val => $z): ?>
                            <option value="<?= $val ?>" data-empfehlung="<?= e($z['empfehlung']) ?>" <?= $plan['ziel'] === $val ? 'selected' : '' ?>><?= e($z['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Niveau</label>
                        <select class="form-control" name="niveau">
                            <?php foreach (TP_NIVEAUS as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $plan['niveau'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Start</label>
                        <input class="form-control" type="date" name="start_datum" value="<?= $v($plan['start_datum']) ?>">
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Dauer (Wochen)</label>
                        <input class="form-control" type="number" min="1" max="52" name="dauer_wochen" value="<?= (int)$plan['dauer_wochen'] ?>">
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Einheiten pro Woche</label>
                        <input class="form-control" type="number" min="1" max="14" name="einheiten_pro_woche" value="<?= (int)$plan['einheiten_pro_woche'] ?>">
                    </div>
                </div>
                <div class="form-group"></div>
            </div>
            <div class="tp-hinweis" id="tp-empfehlung"></div>
            <div class="form-group">
                <label class="form-label">Hinweise für das Mitglied</label>
                <textarea class="form-control" name="hinweise" rows="3" placeholder="z.B. Progression: jede Woche 1 Wiederholung mehr; bei RPE unter 7 Last steigern; jede 4. Woche Deload"><?= $v($plan['hinweise']) ?></textarea>
            </div>

            <?php if ($plan['mitglied_id']): ?>
                <h4 class="tp-abschnitt">Gesundheits-Check (angelehnt an den PAR-Q) – mit dem Mitglied durchgehen</h4>
                <?php foreach (TP_GESUNDHEIT as $schluessel => $label): ?>
                    <label style="display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.45rem; font-size: 0.9rem;">
                        <input type="checkbox" name="gesundheit[]" value="<?= $schluessel ?>" <?= in_array($schluessel, $checks, true) ? 'checked' : '' ?> style="margin-top: 0.2rem;">
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="form-group" style="margin-top: 0.75rem;">
                    <label class="form-label">Einschränkungen, Verletzungen, ärztliche Freigabe</label>
                    <textarea class="form-control <?= isset($errors['gesundheit']) ? 'error' : '' ?>" name="gesundheit_notiz" rows="2" placeholder="Pflicht, wenn ein Punkt oben nicht zutrifft (z.B. „Knie links nach Meniskus-OP, ärztliche Freigabe vom …, keine tiefen Kniebeugen“)"><?= $v($plan['gesundheit_notiz']) ?></textarea>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-navy btn-sm">Plan speichern</button>
        </form>
    </div>
</div>

<div id="einheiten">
    <?php foreach ($einheiten as $e): $zeilen = $uebungen_je_einheit[$e['id']] ?? []; ?>
    <div class="table-card tp-einheit" id="einheit-<?= $e['id'] ?>">
        <form method="POST" action="<?= $self_url ?>#einheit-<?= $e['id'] ?>" class="tp-einheit-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="einheit_speichern">
            <input type="hidden" name="einheit_id" value="<?= $e['id'] ?>">
            <div class="tp-einheit-kopf">
                <div style="display: flex; gap: 0.5rem; flex: 1; flex-wrap: wrap;">
                    <input class="form-control" type="text" name="name" maxlength="120" value="<?= $v($e['name']) ?>" style="flex: 2; min-width: 200px; font-weight: 700;">
                    <select class="form-control" name="wochentag" style="flex: 1; min-width: 130px;">
                        <option value="">Tag frei wählbar</option>
                        <?php foreach (WOCHENTAGE as $nr => $tag): ?>
                            <option value="<?= $nr ?>" <?= (int)$e['wochentag'] === $nr ? 'selected' : '' ?>><?= $tag ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="padding: 1rem 1.25rem;">
                <div class="form-group">
                    <label class="form-label">Aufwärmen</label>
                    <input class="form-control" type="text" name="aufwaermen" value="<?= $v($e['aufwaermen']) ?>" placeholder="z.B. 5 min Seilspringen, Mobilisation Schulter & Hüfte, 2 Aufwärmsätze">
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table tp-tabelle">
                        <thead>
                            <tr><th>Übung</th><th>Sätze</th><th>Wdh./Zeit</th><th>Last</th><th>RPE</th><th>Tempo</th><th>Pause (s)</th><th>Hinweis</th><th></th></tr>
                        </thead>
                        <tbody class="tp-zeilen">
                            <?php foreach ($zeilen ?: [[]] as $u): ?>
                            <tr>
                                <td><input class="form-control" type="text" name="u_name[]" list="uebungsliste" value="<?= $v($u['uebung_name'] ?? '') ?>" style="min-width: 190px;" placeholder="Übung wählen oder eingeben"></td>
                                <td><input class="form-control" type="number" min="0" max="20" name="u_saetze[]" value="<?= $v($u['saetze'] ?? '') ?>" style="width: 65px;"></td>
                                <td><input class="form-control" type="text" name="u_wdh[]" maxlength="30" value="<?= $v($u['wiederholungen'] ?? '') ?>" placeholder="8–12" style="width: 90px;"></td>
                                <td><input class="form-control" type="text" name="u_last[]" maxlength="40" value="<?= $v($u['last'] ?? '') ?>" placeholder="KG / 20 kg" style="width: 110px;"></td>
                                <td><input class="form-control" type="number" min="1" max="10" step="0.5" name="u_rpe[]" value="<?= isset($u['rpe']) && $u['rpe'] !== null ? $v((float)$u['rpe']) : '' ?>" style="width: 70px;"></td>
                                <td><input class="form-control" type="text" name="u_tempo[]" maxlength="12" value="<?= $v($u['tempo'] ?? '') ?>" placeholder="3-1-1-0" style="width: 85px;"></td>
                                <td><input class="form-control" type="number" min="0" max="900" step="15" name="u_pause[]" value="<?= $v($u['pause_sek'] ?? '') ?>" style="width: 80px;"></td>
                                <td><input class="form-control" type="text" name="u_notiz[]" maxlength="255" value="<?= $v($u['notiz'] ?? '') ?>" style="min-width: 150px;"></td>
                                <td><button type="button" class="btn btn-ghost-light btn-sm tp-zeile-weg" title="Zeile entfernen">✕</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-ghost-light btn-sm tp-zeile-neu" style="margin: 0.5rem 0 1rem;">+ Übung</button>
                <div class="form-group">
                    <label class="form-label">Notiz zur Einheit (Cool-down, Progression …)</label>
                    <input class="form-control" type="text" name="notiz" value="<?= $v($e['notiz']) ?>">
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-navy btn-sm">Einheit speichern</button>
                    <button type="submit" name="action" value="einheit_duplizieren" class="btn btn-ghost-light btn-sm" formnovalidate>Duplizieren</button>
                    <button type="submit" name="action" value="einheit_loeschen" class="btn btn-ghost-light btn-sm" style="color: var(--danger);" onclick="return confirm('Einheit mit allen Übungen löschen?')">Löschen</button>
                </div>
            </div>
        </form>
    </div>
    <?php endforeach; ?>

    <div class="table-card" style="margin-bottom: 1.5rem;">
        <div style="padding: 1.25rem;">
            <form method="POST" action="<?= $self_url ?>" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="einheit_neu">
                <div style="flex: 2; min-width: 200px;">
                    <label class="form-label">Neue Trainingseinheit</label>
                    <input class="form-control <?= isset($errors['einheit']) ? 'error' : '' ?>" type="text" name="name" maxlength="120" placeholder="z.B. Tag A – Oberkörper Zug">
                </div>
                <div style="flex: 1; min-width: 140px;">
                    <select class="form-control" name="wochentag">
                        <option value="">Tag frei wählbar</option>
                        <?php foreach (WOCHENTAGE as $nr => $tag): ?><option value="<?= $nr ?>"><?= $tag ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Einheit hinzufügen</button>
            </form>
            <?php if (!$einheiten): ?><p class="form-hint" style="margin-top: 0.75rem;">Tipp: Für <?= (int)$plan['einheiten_pro_woche'] ?> Einheiten pro Woche z.B. „Tag A“, „Tag B“ … anlegen und im Wechsel trainieren lassen.</p><?php endif; ?>
        </div>
    </div>
</div>

<datalist id="uebungsliste">
    <?php foreach ($bibliothek as $b): ?><option value="<?= e($b['name']) ?>"><?= e(UEBUNG_KATEGORIEN[$b['kategorie']] ?? '') ?></option><?php endforeach; ?>
</datalist>
<?php endif; ?>

<?php if (!isTrainer()): ?>
<!-- ================= Mitglieder-Ansicht ================= -->
<?php if ($plan['hinweise']): ?><div class="tp-hinweis"><strong>Hinweise deiner Trainerin/deines Trainers:</strong><br><?= nl2br(e($plan['hinweise'])) ?></div><?php endif; ?>
<div class="tp-hinweis" style="background: rgba(239, 68, 68, 0.08);"><?= e(TP_HINWEIS) ?></div>

<?php foreach ($einheiten as $e): ?>
<div class="table-card tp-einheit">
    <div class="tp-einheit-kopf">
        <h3><?= e($e['name']) ?><?= $e['wochentag'] ? ' <span style="font-weight: 500; color: var(--text-muted);">· ' . WOCHENTAGE[(int)$e['wochentag']] . '</span>' : '' ?></h3>
    </div>
    <div style="padding: 1rem 1.25rem;">
        <?php if ($e['aufwaermen']): ?><p style="margin-bottom: 0.75rem;"><strong>Aufwärmen:</strong> <?= e($e['aufwaermen']) ?></p><?php endif; ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead><tr><th>Übung</th><th>Sätze × Wdh.</th><th>Last</th><th>RPE</th><th>Tempo</th><th>Pause</th></tr></thead>
                <tbody>
                    <?php foreach ($uebungen_je_einheit[$e['id']] ?? [] as $u): ?>
                    <tr>
                        <td>
                            <div class="text-primary">
                                <?= e($u['uebung_name']) ?>
                                <?php if ($u['video_url']): ?> <a href="<?= e($u['video_url']) ?>" target="_blank" rel="noopener" style="font-size: 0.75rem;">▶ Video</a><?php endif; ?>
                            </div>
                            <?php if ($u['notiz']): ?><div style="font-size: 0.75rem;"><?= e($u['notiz']) ?></div><?php endif; ?>
                            <?php if ($u['beschreibung']): ?><details style="font-size: 0.75rem; color: var(--text-muted);"><summary>Ausführung</summary><?= e($u['beschreibung']) ?></details><?php endif; ?>
                        </td>
                        <td><?= $u['saetze'] ? (int)$u['saetze'] . ' × ' : '' ?><?= e($u['wiederholungen'] ?? '') ?></td>
                        <td><?= e($u['last'] ?? '–') ?></td>
                        <td><?= $u['rpe'] !== null ? e(rtrim(rtrim(number_format((float)$u['rpe'], 1, ',', ''), '0'), ',')) : '–' ?></td>
                        <td><?= e($u['tempo'] ?? '–') ?></td>
                        <td><?= $u['pause_sek'] ? (int)$u['pause_sek'] . ' s' : '–' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($e['notiz']): ?><p style="margin-top: 0.75rem;"><strong>Notiz:</strong> <?= e($e['notiz']) ?></p><?php endif; ?>

        <?php if ($ist_eigen && $plan['status'] === 'aktiv'): ?>
        <form method="POST" action="<?= $self_url ?>" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-light);">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="protokoll_eintragen">
            <input type="hidden" name="einheit_id" value="<?= $e['id'] ?>">
            <div><label class="form-label">Trainiert am</label><input class="form-control" type="date" name="datum" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
            <div><label class="form-label">Anstrengung (1–10)</label><input class="form-control" type="number" min="1" max="10" step="0.5" name="rpe" style="width: 110px;"></div>
            <div style="flex: 1; min-width: 180px;"><label class="form-label">Notiz (optional)</label><input class="form-control" type="text" name="notiz" maxlength="500" placeholder="z.B. 3. Satz Klimmzüge nur 5 Wdh."></div>
            <button type="submit" class="btn btn-primary btn-sm">✓ Einheit erledigt</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($plan['mitglied_id']): ?>
<div class="table-card" id="protokoll" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Trainingsprotokoll (<?= count($protokoll) ?>)</h2></div>
    <?php if (!$protokoll): ?>
        <div class="empty-state"><p><?= $ist_eigen ? 'Nach jeder Einheit auf „Einheit erledigt“ klicken – so siehst du deinen Fortschritt.' : 'Das Mitglied hat noch keine Einheit eingetragen.' ?></p></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead><tr><th>Datum</th><th>Einheit</th><th>RPE</th><th>Notiz</th><?php if ($ist_eigen): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($protokoll as $pr): ?>
                    <tr>
                        <td><?= date('d.m.Y', strtotime($pr['datum'])) ?></td>
                        <td><?= e($pr['einheit_name']) ?></td>
                        <td><?= $pr['rpe'] !== null ? e(rtrim(rtrim(number_format((float)$pr['rpe'], 1, ',', ''), '0'), ',')) : '–' ?></td>
                        <td><?= e($pr['notiz'] ?? '') ?></td>
                        <?php if ($ist_eigen): ?>
                        <td>
                            <form method="POST" onsubmit="return confirm('Eintrag löschen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="protokoll_loeschen">
                                <input type="hidden" name="protokoll_id" value="<?= $pr['id'] ?>">
                                <button type="submit" class="btn btn-ghost-light btn-sm">✕</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (isTrainer()): ?>
<div class="table-card">
    <div style="padding: 1.25rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <form method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="kopieren">
            <div>
                <label class="form-label">Plan kopieren für</label>
                <select class="form-control" name="ziel_mitglied">
                    <option value="">– als Vorlage speichern –</option>
                    <?php foreach ($mitglieder as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-ghost-light btn-sm">Kopieren</button>
        </form>
        <form method="POST" onsubmit="return confirm('Trainingsplan mit allen Einheiten und Protokollen unwiderruflich löschen?')" style="margin-left: auto;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="plan_loeschen">
            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Plan löschen</button>
        </form>
    </div>
</div>

<script>
(() => {
    // Empfohlene Belastungsnormative zum gewählten Ziel anzeigen
    const ziel = document.getElementById('tp-ziel');
    const box  = document.getElementById('tp-empfehlung');
    const zeige = () => { box.innerHTML = '<strong>Richtwerte für dieses Ziel:</strong> ' + ziel.selectedOptions[0].dataset.empfehlung; };
    ziel.addEventListener('change', zeige);
    zeige();

    // Übungszeilen je Einheit hinzufügen/entfernen
    document.querySelectorAll('.tp-einheit-form').forEach(form => {
        const tbody = form.querySelector('.tp-zeilen');
        const binden = (tr) => tr.querySelector('.tp-zeile-weg').addEventListener('click', () => {
            if (tbody.rows.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(i => i.value = '');
        });
        [...tbody.rows].forEach(binden);
        form.querySelector('.tp-zeile-neu').addEventListener('click', () => {
            const neu = tbody.rows[tbody.rows.length - 1].cloneNode(true);
            neu.querySelectorAll('input').forEach(i => i.value = '');
            tbody.appendChild(neu);
            binden(neu);
            neu.querySelector('input').focus();
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
