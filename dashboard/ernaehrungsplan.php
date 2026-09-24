<?php
/**
 * Athletikclub Steiermark – Ernährungsplan (Ernährungstraining für gesunde Personen)
 * Trainer:innen: Gesundheits-Check, Energie-/Makro-Richtwerte, Mahlzeiten.
 * Mitglieder: eigenen freigegebenen Plan ansehen.
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
$plan    = planLaden($db, 'ernaehrungsplaene', $plan_id);

if (!$plan) {
    flashMessage('error', 'Ernährungsplan nicht gefunden.');
    redirect(APP_URL . '/dashboard/plaene.php');
}

$self_url   = APP_URL . '/dashboard/ernaehrungsplan.php?id=' . $plan_id;
$ist_vorlage = !$plan['mitglied_id'];
$errors     = [];

/** Ganzzahl aus einem Formularfeld oder null. */
function epZahl($wert, int $max): ?int
{
    $wert = trim((string)$wert);
    return $wert === '' ? null : max(0, min($max, (int)$wert));
}

// ----------------------------------------------------------------
// Aktionen (nur Trainer:innen)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isTrainer()) {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'plan_speichern') {
        $titel  = trim($_POST['titel'] ?? '');
        $status = $_POST['status'] ?? 'entwurf';
        $ziel   = $_POST['ziel'] ?? 'halten';
        $pal    = $_POST['pal'] ?? '1.55';
        if ($titel === '') $errors['titel'] = 'Bitte einen Titel angeben.';
        if (!isset(PLAN_STATUS[$status]) || $ist_vorlage) $status = 'entwurf'; // Vorlagen werden nicht freigegeben

        $neu = [
            'titel'               => mb_substr($titel, 0, 150),
            'ziel'                => isset(EP_ZIELE[$ziel]) ? $ziel : 'halten',
            'geschlecht'          => in_array($_POST['geschlecht'] ?? '', ['w', 'm'], true) ? $_POST['geschlecht'] : null,
            'alter_jahre'         => epZahl($_POST['alter_jahre'] ?? '', 110),
            'groesse_cm'          => epZahl($_POST['groesse_cm'] ?? '', 250),
            'gewicht_kg'          => ($_POST['gewicht_kg'] ?? '') !== '' ? max(20, min(300, round((float)str_replace(',', '.', $_POST['gewicht_kg']), 1))) : null,
            'pal'                 => isset(EP_PAL[$pal]) ? $pal : '1.55',
            'sport_stunden_woche' => max(0, min(40, round((float)str_replace(',', '.', $_POST['sport_stunden_woche'] ?? '3'), 1))),
            'kalorien_ziel'       => epZahl($_POST['kalorien_ziel'] ?? '', 6000) ?: null,
            'protein_g_kg'        => ($_POST['protein_g_kg'] ?? '') !== '' ? max(0.5, min(3, round((float)str_replace(',', '.', $_POST['protein_g_kg']), 1))) : null,
            'fett_prozent'        => max(10, min(50, (int)($_POST['fett_prozent'] ?? 30))),
            'gesundheit_checks'   => json_encode(array_values(array_intersect(array_keys(EP_GESUNDHEIT), (array)($_POST['gesundheit'] ?? [])))),
        ];
        // Freigabe nur, wenn alle Ausschlusskriterien bestätigt sind und die Berechnung keine Sperre ergibt
        if ($status === 'aktiv' && !epFreigegeben(array_merge($plan, $neu))) {
            $errors['freigabe'] = 'Der Plan kann nicht freigegeben werden: Gesundheits-Check unvollständig oder ein Ausschlussgrund (BMI, Alter, Energiezufuhr unter Grundumsatz) liegt vor. In diesem Fall bitte an Diätolog:innen bzw. Ärzt:innen verweisen.';
            $status = 'entwurf';
        }
        if (empty($errors['titel'])) {
            $db->prepare(
                'UPDATE ernaehrungsplaene SET titel = ?, ziel = ?, geschlecht = ?, alter_jahre = ?, groesse_cm = ?, gewicht_kg = ?, pal = ?,
                    sport_stunden_woche = ?, kalorien_ziel = ?, protein_g_kg = ?, fett_prozent = ?, gesundheit_checks = ?,
                    start_datum = ?, status = ?, hinweise = ? WHERE id = ?'
            )->execute([
                $neu['titel'], $neu['ziel'], $neu['geschlecht'], $neu['alter_jahre'], $neu['groesse_cm'], $neu['gewicht_kg'], $neu['pal'],
                $neu['sport_stunden_woche'], $neu['kalorien_ziel'], $neu['protein_g_kg'], $neu['fett_prozent'], $neu['gesundheit_checks'],
                strtotime($_POST['start_datum'] ?? '') ? date('Y-m-d', strtotime($_POST['start_datum'])) : null,
                $status, trim($_POST['hinweise'] ?? '') ?: null, $plan_id,
            ]);
            logActivity('ernaehrungsplan_gespeichert', "Plan-ID: {$plan_id}, Status: {$status}");
            if (empty($errors)) {
                flashMessage('success', 'Plan gespeichert.' . ($status === 'aktiv' && $plan['status'] !== 'aktiv' ? ' Das Mitglied sieht ihn jetzt unter „Meine Pläne“.' : ''));
                redirect($self_url);
            }
            // Gespeichert, aber nicht freigegeben – Fehlermeldung auf der Seite anzeigen
            $plan = planLaden($db, 'ernaehrungsplaene', $plan_id);
        }
    }

    if ($action === 'mahlzeit_speichern') {
        if (!$ist_vorlage && !epFreigegeben($plan)) {
            flashMessage('error', 'Mahlzeiten können erst nach vollständigem Gesundheits-Check geplant werden.');
            redirect($self_url);
        }
        $mahlzeit_id = (int)($_POST['mahlzeit_id'] ?? 0);
        $inhalt      = trim($_POST['inhalt'] ?? '');
        $name        = trim($_POST['mahlzeit'] ?? '');
        if ($name === '' || $inhalt === '') {
            $errors['mahlzeit'] = 'Bitte Mahlzeit und Inhalt angeben.';
        } else {
            $werte = [
                array_key_exists($_POST['tagtyp'] ?? '', EP_TAGTYPEN) ? $_POST['tagtyp'] : 'alle',
                mb_substr($name, 0, 60), mb_substr(trim($_POST['uhrzeit'] ?? ''), 0, 10) ?: null, $inhalt,
                epZahl($_POST['kcal'] ?? '', 5000), epZahl($_POST['protein_g'] ?? '', 500), epZahl($_POST['kh_g'] ?? '', 1000), epZahl($_POST['fett_g'] ?? '', 500),
            ];
            if ($mahlzeit_id) {
                $db->prepare('UPDATE ernaehrungsplan_mahlzeiten SET tagtyp = ?, mahlzeit = ?, uhrzeit = ?, inhalt = ?, kcal = ?, protein_g = ?, kh_g = ?, fett_g = ? WHERE id = ? AND plan_id = ?')
                   ->execute(array_merge($werte, [$mahlzeit_id, $plan_id]));
            } else {
                $stmt = $db->prepare('SELECT COALESCE(MAX(sortierung), 0) + 1 FROM ernaehrungsplan_mahlzeiten WHERE plan_id = ?');
                $stmt->execute([$plan_id]);
                $db->prepare('INSERT INTO ernaehrungsplan_mahlzeiten (tagtyp, mahlzeit, uhrzeit, inhalt, kcal, protein_g, kh_g, fett_g, plan_id, sortierung) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute(array_merge($werte, [$plan_id, (int)$stmt->fetchColumn()]));
            }
            flashMessage('success', 'Mahlzeit gespeichert.');
            redirect($self_url . '#mahlzeiten');
        }
    }

    if ($action === 'mahlzeit_loeschen') {
        $db->prepare('DELETE FROM ernaehrungsplan_mahlzeiten WHERE id = ? AND plan_id = ?')->execute([(int)($_POST['mahlzeit_id'] ?? 0), $plan_id]);
        redirect($self_url . '#mahlzeiten');
    }

    if ($action === 'kopieren') {
        $ziel_mitglied = (int)($_POST['ziel_mitglied'] ?? 0) ?: null;
        $alter = null;
        if ($ziel_mitglied) {
            $stmt = $db->prepare("SELECT mp.geburtsdatum FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id WHERE u.id = ? AND u.organization_id = ? AND u.rolle = 'mitglied'");
            $stmt->execute([$ziel_mitglied, currentOrgId()]);
            $m = $stmt->fetch();
            if (!$m) redirect($self_url);
            $alter = alterAus($m['geburtsdatum']);
        }
        $db->beginTransaction();
        // Personenbezogene Werte und Gesundheits-Check werden bewusst NICHT übernommen
        $db->prepare(
            'INSERT INTO ernaehrungsplaene (organization_id, mitglied_id, trainer_id, titel, ziel, alter_jahre, pal, sport_stunden_woche, protein_g_kg, fett_prozent, hinweise)
             SELECT organization_id, ?, ?, titel, ziel, ?, pal, sport_stunden_woche, protein_g_kg, fett_prozent, hinweise FROM ernaehrungsplaene WHERE id = ?'
        )->execute([$ziel_mitglied, $user['id'], $alter, $plan_id]);
        $neu_id = (int)$db->lastInsertId();
        ernaehrungsplanKopieren($db, $plan_id, $neu_id);
        $db->commit();
        flashMessage('success', $ziel_mitglied ? 'Plan übernommen – bitte Körperdaten und Gesundheits-Check ergänzen.' : 'Als Vorlage gespeichert.');
        redirect(APP_URL . '/dashboard/ernaehrungsplan.php?id=' . $neu_id);
    }

    if ($action === 'plan_loeschen') {
        $db->prepare('DELETE FROM ernaehrungsplaene WHERE id = ? AND organization_id = ?')->execute([$plan_id, currentOrgId()]);
        logActivity('ernaehrungsplan_geloescht', "Plan-ID: {$plan_id}");
        flashMessage('success', 'Ernährungsplan gelöscht.');
        redirect(APP_URL . '/dashboard/plaene.php');
    }
}

// ----------------------------------------------------------------
// Daten laden und auswerten
// ----------------------------------------------------------------
$stmt = $db->prepare("SELECT * FROM ernaehrungsplan_mahlzeiten WHERE plan_id = ? ORDER BY CASE tagtyp WHEN 'alle' THEN 0 WHEN 'training' THEN 1 ELSE 2 END, sortierung, id");
$stmt->execute([$plan_id]);
$mahlzeiten = $stmt->fetchAll();

$berechnung = epBerechnung($plan);
$freigabe   = $ist_vorlage || epFreigegeben($plan, $berechnung);
$checks     = planChecks($plan['gesundheit_checks']);

// Summen je Tagestyp: „Jeder Tag“ zählt zu Trainings- und Ruhetagen dazu
$summe = fn(array $liste, string $feld) => array_sum(array_map(fn($m) => (int)$m[$feld], $liste));
$je_typ = ['alle' => [], 'training' => [], 'ruhe' => []];
foreach ($mahlzeiten as $m) $je_typ[$m['tagtyp']][] = $m;
$tage = [];
if ($je_typ['training'] || $je_typ['ruhe']) {
    foreach (['training' => 'Trainingstag', 'ruhe' => 'Ruhetag'] as $typ => $label) $tage[$label] = array_merge($je_typ['alle'], $je_typ[$typ]);
} elseif ($je_typ['alle']) {
    $tage['Jeder Tag'] = $je_typ['alle'];
}

$bearbeiten = null;
foreach ($mahlzeiten as $m) if ((int)$m['id'] === (int)($_GET['mahlzeit'] ?? 0)) $bearbeiten = $m;
$mform = $bearbeiten ?? ['id' => 0, 'tagtyp' => 'alle', 'mahlzeit' => '', 'uhrzeit' => '', 'inhalt' => '', 'kcal' => '', 'protein_g' => '', 'kh_g' => '', 'fett_g' => ''];
if (($_POST['action'] ?? '') === 'mahlzeit_speichern') $mform = array_merge($mform, $_POST);

$s = PLAN_STATUS[$plan['status']] ?? PLAN_STATUS['entwurf'];
$v = fn($wert) => e((string)($wert ?? ''));
$fmt = fn($zahl) => number_format((float)$zahl, 0, ',', '.');

$page_title = $plan['titel'];
$breadcrumb = isTrainer() ? 'Trainings- & Ernährungspläne' : 'Meine Pläne';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ep-hinweis { border-radius: 0.5rem; padding: 0.75rem 1rem; font-size: 0.875rem; margin-bottom: 1rem; background: var(--gold-dim); }
.ep-warnung { background: rgba(239, 68, 68, 0.10); }
.ep-meldung { padding: 0.45rem 0.75rem; border-radius: 0.5rem; margin-bottom: 0.4rem; font-size: 0.875rem; }
.ep-meldung-fehler { background: rgba(239, 68, 68, 0.12); }
.ep-meldung-warnung { background: rgba(245, 158, 11, 0.14); }
.ep-meldung-info { background: rgba(59, 130, 246, 0.10); }
.ep-abschnitt { font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin: 1.25rem 0 0.75rem; }
</style>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/plaene.php<?= isTrainer() && $plan['mitglied_id'] ? '?mitglied=' . (int)$plan['mitglied_id'] : '' ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <?= isTrainer() ? 'Alle Pläne' : 'Meine Pläne' ?>
        </a>
        <h1 class="dashboard-title"><?= e($plan['titel']) ?> <span class="badge <?= $s['class'] ?>" style="vertical-align: middle;"><?= e($s['label']) ?></span></h1>
        <p class="dashboard-subtitle">Ernährungsplan für <strong><?= e(planFuer($plan)) ?></strong> · <?= e(EP_ZIELE[$plan['ziel']]['label']) ?> · Trainer:in <?= e($plan['t_vorname'] . ' ' . $plan['t_nachname']) ?></p>
    </div>
    <?php if ($freigabe): ?><a href="<?= APP_URL ?>/dashboard/plan-pdf.php?typ=ernaehrung&amp;id=<?= $plan_id ?>" target="_blank" class="btn btn-primary btn-sm">Als PDF</a><?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<div class="ep-hinweis ep-warnung"><?= e(EP_HINWEIS) ?></div>

<?php if ($berechnung['vollstaendig'] && !$ist_vorlage): ?>
<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #F59E0B;">
        <div class="kpi-value"><?= $fmt($berechnung['kcal']) ?> kcal</div>
        <div class="kpi-label">Richtwert pro Tag<?= isTrainer() ? ' · Grundumsatz ' . $fmt($berechnung['grundumsatz']) . ' / Gesamtumsatz ' . $fmt($berechnung['gesamtumsatz']) : '' ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-value"><?= $berechnung['protein_g'] ?> g</div>
        <div class="kpi-label">Eiweiß (<?= e(rtrim(rtrim(number_format($berechnung['protein_g_kg'], 1, ',', ''), '0'), ',')) ?> g/kg)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-value"><?= $berechnung['kh_g'] ?> g · <?= $berechnung['fett_g'] ?> g</div>
        <div class="kpi-label">Kohlenhydrate · Fett (<?= (int)$plan['fett_prozent'] ?> %)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;">
        <div class="kpi-value"><?= e(number_format($berechnung['wasser_l'], 1, ',', '')) ?> l</div>
        <div class="kpi-label">Trinkmenge (Richtwert)</div>
    </div>
</div>
<?php endif; ?>

<?php if (isTrainer()): ?>
<!-- ================= Trainer:innen-Ansicht ================= -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title"><?= $ist_vorlage ? 'Vorlage' : 'Gesundheits-Check, Körperdaten &amp; Ziel' ?></h2></div>
    <div style="padding: 1.25rem;">
        <?php if ($berechnung['hinweise'] && !$ist_vorlage): ?>
            <?php foreach ($berechnung['hinweise'] as $h): ?><div class="ep-meldung ep-meldung-<?= $h['typ'] ?>"><?= e($h['text']) ?></div><?php endforeach; ?>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="plan_speichern">

            <?php if (!$ist_vorlage): ?>
            <h4 class="ep-abschnitt" style="margin-top: 0;">1. Gesundheits-Check (Pflicht – alle Punkte müssen zutreffen)</h4>
            <p class="form-hint" style="margin-bottom: 0.75rem;">Ernährungstraining ist nur für gesunde Personen ohne Krankheit oder Krankheitsgefährdung zulässig. Trifft ein Punkt nicht zu, bitte an eine Diätologin bzw. einen Diätologen oder die Hausärztin bzw. den Hausarzt verweisen – das Training kann trotzdem weitergehen.</p>
            <?php foreach (EP_GESUNDHEIT as $schluessel => $label): ?>
                <label style="display: flex; gap: 0.6rem; align-items: flex-start; margin-bottom: 0.45rem; font-size: 0.9rem;">
                    <input type="checkbox" name="gesundheit[]" value="<?= $schluessel ?>" <?= in_array($schluessel, $checks, true) ? 'checked' : '' ?> style="margin-top: 0.2rem;">
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>

            <h4 class="ep-abschnitt">2. Körperdaten</h4>
            <div class="form-row">
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Geschlecht (für die Formel)</label>
                        <select class="form-control" name="geschlecht">
                            <option value="">–</option>
                            <option value="w" <?= $plan['geschlecht'] === 'w' ? 'selected' : '' ?>>weiblich</option>
                            <option value="m" <?= $plan['geschlecht'] === 'm' ? 'selected' : '' ?>>männlich</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Alter</label>
                        <input class="form-control" type="number" min="10" max="110" name="alter_jahre" value="<?= $v($plan['alter_jahre']) ?>">
                    </div>
                </div>
                <div class="form-group" style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label class="form-label">Größe (cm)</label>
                        <input class="form-control" type="number" min="100" max="250" name="groesse_cm" value="<?= $v($plan['groesse_cm']) ?>">
                    </div>
                    <div style="flex: 1;">
                        <label class="form-label">Gewicht (kg)</label>
                        <input class="form-control" type="number" min="20" max="300" step="0.1" name="gewicht_kg" value="<?= $v($plan['gewicht_kg']) ?>">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <h4 class="ep-abschnitt"><?= $ist_vorlage ? '' : '3. ' ?>Ziel &amp; Aktivität</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Titel <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['titel']) ? 'error' : '' ?>" type="text" name="titel" maxlength="150" value="<?= $v($plan['titel']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Ziel</label>
                    <select class="form-control" name="ziel">
                        <?php foreach (EP_ZIELE as $val => $z): ?>
                            <option value="<?= $val ?>" <?= $plan['ziel'] === $val ? 'selected' : '' ?>><?= e($z['label']) ?> – <?= e($z['text']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Aktivitätsfaktor (PAL)</label>
                    <select class="form-control" name="pal">
                        <?php foreach (EP_PAL as $val => $label): ?>
                            <option value="<?= $val ?>" <?= abs((float)$plan['pal'] - (float)$val) < 0.001 ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Sport pro Woche (Stunden)</label>
                    <input class="form-control" type="number" min="0" max="40" step="0.5" name="sport_stunden_woche" value="<?= $v((float)$plan['sport_stunden_woche']) ?>">
                    <p class="form-hint">Bestimmt die Eiweißempfehlung: bis 5 h 0,8 g/kg, darüber 1,2–2,0 g/kg (DGE).</p>
                </div>
            </div>
            <details style="margin-bottom: 1rem;">
                <summary style="cursor: pointer; font-weight: 600;">Richtwerte manuell anpassen</summary>
                <div class="form-row" style="margin-top: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label">Energie (kcal/Tag, leer = berechnet)</label>
                        <input class="form-control" type="number" min="800" max="6000" name="kalorien_ziel" value="<?= $v($plan['kalorien_ziel']) ?>">
                    </div>
                    <div class="form-group" style="display: flex; gap: 1rem;">
                        <div style="flex: 1;">
                            <label class="form-label">Eiweiß (g/kg, leer = Empfehlung)</label>
                            <input class="form-control" type="number" min="0.5" max="3" step="0.1" name="protein_g_kg" value="<?= $v($plan['protein_g_kg']) ?>">
                        </div>
                        <div style="flex: 1;">
                            <label class="form-label">Fettanteil (%)</label>
                            <input class="form-control" type="number" min="10" max="50" name="fett_prozent" value="<?= (int)$plan['fett_prozent'] ?>">
                        </div>
                    </div>
                </div>
            </details>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status" <?= $ist_vorlage ? 'disabled' : '' ?>>
                        <?php foreach (PLAN_STATUS as $val => $st): ?>
                            <option value="<?= $val ?>" <?= $plan['status'] === $val ? 'selected' : '' ?>><?= e($st['label']) ?><?= $val === 'aktiv' ? ' (für das Mitglied sichtbar)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Start</label>
                    <input class="form-control" type="date" name="start_datum" value="<?= $v($plan['start_datum']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Hinweise für das Mitglied</label>
                <textarea class="form-control" name="hinweise" rows="3" placeholder="z.B. Tellermodell: ½ Gemüse, ¼ Eiweißquelle, ¼ Vollkorn; 2 Portionen Obst täglich; vor dem Training leicht Verdauliches"><?= $v($plan['hinweise']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        </form>
    </div>
</div>
<?php elseif ($plan['hinweise']): ?>
<div class="ep-hinweis"><strong>Hinweise deiner Trainerin/deines Trainers:</strong><br><?= nl2br(e($plan['hinweise'])) ?></div>
<?php endif; ?>

<!-- Mahlzeiten -->
<div class="table-card" id="mahlzeiten" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Mahlzeiten</h2></div>
    <?php if (!$freigabe): ?>
        <div style="padding: 1.25rem;"><div class="ep-meldung ep-meldung-fehler">Mahlzeiten können erst geplant werden, wenn der Gesundheits-Check vollständig ist und kein Ausschlussgrund vorliegt.</div></div>
    <?php else: ?>
        <?php if (!$mahlzeiten): ?>
            <div class="empty-state"><p><?= isTrainer() ? 'Noch keine Mahlzeiten – unten die erste anlegen.' : 'Noch keine Mahlzeiten eingetragen.' ?></p></div>
        <?php endif; ?>
        <?php foreach (EP_TAGTYPEN as $typ => $typ_label): if (!$je_typ[$typ]) continue; ?>
            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.9rem; font-weight: 800; padding: 1rem 1.25rem 0;"><?= e($typ_label) ?></h3>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead><tr><th>Mahlzeit</th><th>Inhalt</th><th>kcal</th><th>E</th><th>KH</th><th>F</th><?php if (isTrainer()): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($je_typ[$typ] as $m): ?>
                        <tr>
                            <td><div class="text-primary"><?= e($m['mahlzeit']) ?></div><?php if ($m['uhrzeit']): ?><div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($m['uhrzeit']) ?></div><?php endif; ?></td>
                            <td style="white-space: pre-line; min-width: 220px;"><?= e($m['inhalt']) ?></td>
                            <td><?= $m['kcal'] !== null ? $fmt($m['kcal']) : '–' ?></td>
                            <td><?= $m['protein_g'] !== null ? (int)$m['protein_g'] . ' g' : '–' ?></td>
                            <td><?= $m['kh_g'] !== null ? (int)$m['kh_g'] . ' g' : '–' ?></td>
                            <td><?= $m['fett_g'] !== null ? (int)$m['fett_g'] . ' g' : '–' ?></td>
                            <?php if (isTrainer()): ?>
                            <td style="white-space: nowrap;">
                                <a href="<?= $self_url ?>&amp;mahlzeit=<?= $m['id'] ?>#mahlzeit-formular" class="btn btn-ghost-light btn-sm">Bearbeiten</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Mahlzeit löschen?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mahlzeit_loeschen">
                                    <input type="hidden" name="mahlzeit_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">✕</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <?php if ($tage): ?>
        <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--border-light);">
            <h4 class="ep-abschnitt" style="margin-top: 0;">Tagessumme <?= $berechnung['vollstaendig'] && !$ist_vorlage ? 'im Vergleich zum Richtwert' : '' ?></h4>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead><tr><th></th><th>kcal</th><th>Eiweiß</th><th>Kohlenhydrate</th><th>Fett</th></tr></thead>
                    <tbody>
                        <?php foreach ($tage as $label => $liste): ?>
                        <tr>
                            <td><strong><?= e($label) ?></strong></td>
                            <?php foreach (['kcal', 'protein_g', 'kh_g', 'fett_g'] as $feld):
                                $ist = $summe($liste, $feld);
                                $soll = $berechnung['vollstaendig'] && !$ist_vorlage ? $berechnung[$feld] : null;
                                $abw = $soll ? round(($ist - $soll) / $soll * 100) : null; ?>
                                <td>
                                    <?= $fmt($ist) ?><?= $feld === 'kcal' ? '' : ' g' ?>
                                    <?php if ($soll): ?>
                                        <span style="font-size: 0.75rem; color: <?= abs($abw) <= 10 ? '#16A34A' : 'var(--danger)' ?>;">/ <?= $fmt($soll) ?> (<?= $abw > 0 ? '+' : '' ?><?= $abw ?> %)</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($berechnung['vollstaendig'] && !$ist_vorlage): ?><p class="form-hint">Grün: innerhalb ± 10 % des Richtwerts. Richtwerte sind Orientierungsgrößen – Hunger, Sättigung und Wohlbefinden haben Vorrang.</p><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (isTrainer()): ?>
        <div id="mahlzeit-formular" style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
            <h4 class="ep-abschnitt" style="margin-top: 0;"><?= $mform['id'] ? 'Mahlzeit bearbeiten' : 'Mahlzeit hinzufügen' ?></h4>
            <form method="POST" action="<?= $self_url ?>#mahlzeit-formular">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mahlzeit_speichern">
                <input type="hidden" name="mahlzeit_id" value="<?= (int)$mform['id'] ?>">
                <div class="form-row">
                    <div class="form-group" style="display: flex; gap: 1rem;">
                        <div style="flex: 1;">
                            <label class="form-label">Gilt für</label>
                            <select class="form-control" name="tagtyp">
                                <?php foreach (EP_TAGTYPEN as $val => $label): ?><option value="<?= $val ?>" <?= $mform['tagtyp'] === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex: 2;">
                            <label class="form-label">Mahlzeit <span class="required">*</span></label>
                            <input class="form-control" type="text" name="mahlzeit" maxlength="60" list="mahlzeiten-liste" value="<?= $v($mform['mahlzeit']) ?>" placeholder="z.B. Frühstück">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Uhrzeit (optional)</label>
                        <input class="form-control" type="text" name="uhrzeit" maxlength="10" value="<?= $v($mform['uhrzeit']) ?>" placeholder="z.B. 07:00">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Inhalt: Lebensmittel, Mengen, Rezeptidee <span class="required">*</span></label>
                    <textarea class="form-control <?= isset($errors['mahlzeit']) ? 'error' : '' ?>" name="inhalt" rows="3" placeholder="z.B. 60 g Haferflocken, 200 g Naturjoghurt, 1 Banane, 1 EL Nüsse"><?= $v($mform['inhalt']) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group" style="display: flex; gap: 1rem;">
                        <div style="flex: 1;"><label class="form-label">kcal</label><input class="form-control" type="number" min="0" name="kcal" value="<?= $v($mform['kcal']) ?>"></div>
                        <div style="flex: 1;"><label class="form-label">Eiweiß (g)</label><input class="form-control" type="number" min="0" name="protein_g" value="<?= $v($mform['protein_g']) ?>"></div>
                    </div>
                    <div class="form-group" style="display: flex; gap: 1rem;">
                        <div style="flex: 1;"><label class="form-label">Kohlenhydrate (g)</label><input class="form-control" type="number" min="0" name="kh_g" value="<?= $v($mform['kh_g']) ?>"></div>
                        <div style="flex: 1;"><label class="form-label">Fett (g)</label><input class="form-control" type="number" min="0" name="fett_g" value="<?= $v($mform['fett_g']) ?>"></div>
                    </div>
                </div>
                <p class="form-hint" style="margin-bottom: 1rem;">Nährwerte optional – z.B. aus der Österreichischen Nährwerttabelle oder den Angaben auf der Verpackung.</p>
                <button type="submit" class="btn btn-navy btn-sm"><?= $mform['id'] ? 'Änderungen speichern' : 'Mahlzeit hinzufügen' ?></button>
                <?php if ($mform['id']): ?><a href="<?= $self_url ?>#mahlzeiten" class="btn btn-ghost-light btn-sm">Abbrechen</a><?php endif; ?>
            </form>
            <datalist id="mahlzeiten-liste">
                <?php foreach (['Frühstück', 'Vormittagssnack', 'Mittagessen', 'Nachmittagssnack', 'Snack vor dem Training', 'Nach dem Training', 'Abendessen', 'Spätmahlzeit'] as $name): ?><option value="<?= $name ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (isTrainer()): ?>
<?php
    $stmt = $db->prepare("SELECT id, vorname, nachname FROM users WHERE organization_id = ? AND rolle = 'mitglied' ORDER BY nachname, vorname");
    $stmt->execute([currentOrgId()]);
    $mitglieder = $stmt->fetchAll();
?>
<div class="table-card">
    <div style="padding: 1.25rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <form method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="kopieren">
            <div>
                <label class="form-label">Mahlzeiten kopieren für</label>
                <select class="form-control" name="ziel_mitglied">
                    <option value="">– als Vorlage speichern –</option>
                    <?php foreach ($mitglieder as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-ghost-light btn-sm">Kopieren</button>
        </form>
        <form method="POST" onsubmit="return confirm('Ernährungsplan unwiderruflich löschen?')" style="margin-left: auto;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="plan_loeschen">
            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Plan löschen</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
