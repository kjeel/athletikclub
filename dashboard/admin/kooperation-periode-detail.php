<?php
/**
 * Athletikclub Steiermark – Admin: Kooperationsperiode Detail
 * (Rückmeldeblatt: geplante Angebote + Zusage / Rechnung)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

$db  = getDB();
$periode_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    'SELECT p.*, k.gemeinde_name, k.dachverband
     FROM kooperations_perioden p
     JOIN kooperationen k ON k.id = p.kooperation_id
     WHERE p.id = ? AND p.organization_id = ? LIMIT 1'
);
$stmt->execute([$periode_id, currentOrgId()]);
$periode = $stmt->fetch();

if (!$periode) {
    flashMessage('error', 'Periode nicht gefunden.');
    redirect(APP_URL . '/dashboard/admin/kooperationen.php');
}

$kooperation_id = (int)$periode['kooperation_id'];

$rm_labels = ['offen' => 'Offen', 'eingereicht' => 'Eingereicht', 'freigegeben' => 'Freigegeben'];
$re_labels = ['offen' => 'Offen', 'erstellt' => 'Erstellt', 'eingereicht' => 'Eingereicht', 'bezahlt' => 'Bezahlt'];

$errors = [];

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_zusage') {
        $zugesagte_angebote  = trim($_POST['zugesagte_angebote'] ?? '');
        $zugesagte_einheiten = trim($_POST['zugesagte_einheiten'] ?? '');
        $zugesagtes_budget   = trim($_POST['zugesagtes_budget'] ?? '');
        $rueckmeldeblatt_status = $_POST['rueckmeldeblatt_status'] ?? 'offen';
        if (!isset($rm_labels[$rueckmeldeblatt_status])) $rueckmeldeblatt_status = 'offen';

        $db->prepare(
            'UPDATE kooperations_perioden SET zugesagte_angebote = ?, zugesagte_einheiten = ?, zugesagtes_budget = ?, rueckmeldeblatt_status = ? WHERE id = ?'
        )->execute([
            $zugesagte_angebote ?: null,
            $zugesagte_einheiten ?: null,
            $zugesagtes_budget !== '' ? (float)$zugesagtes_budget : null,
            $rueckmeldeblatt_status,
            $periode_id,
        ]);
        logActivity('kooperationsperiode_zusage_aktualisiert', "Periode-ID: {$periode_id}");
        flashMessage('success', 'Rückmeldeblatt aktualisiert.');
        redirect(APP_URL . '/dashboard/admin/kooperation-periode-detail.php?id=' . $periode_id);
    }

    if ($action === 'angebot_hinzufuegen') {
        $angebotsname = trim($_POST['angebotsname'] ?? '');
        if (empty($angebotsname)) {
            $errors['angebotsname'] = 'Angebotsname ist Pflichtfeld.';
        } else {
            $db->prepare(
                'INSERT INTO kooperations_angebote (periode_id, angebotsname, zielgruppe, zeitraum_von, zeitraum_bis, gruppenanzahl, einheiten_pro_gruppe)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $periode_id,
                $angebotsname,
                trim($_POST['zielgruppe'] ?? '') ?: null,
                trim($_POST['ang_zeitraum_von'] ?? '') ?: null,
                trim($_POST['ang_zeitraum_bis'] ?? '') ?: null,
                $_POST['gruppenanzahl'] !== '' ? (int)$_POST['gruppenanzahl'] : null,
                $_POST['einheiten_pro_gruppe'] !== '' ? (int)$_POST['einheiten_pro_gruppe'] : null,
            ]);
            logActivity('kooperationsangebot_hinzugefuegt', "Periode-ID: {$periode_id}, Angebot: {$angebotsname}");
            flashMessage('success', 'Angebot hinzugefügt.');
            redirect(APP_URL . '/dashboard/admin/kooperation-periode-detail.php?id=' . $periode_id);
        }
    }

    if ($action === 'angebot_loeschen') {
        $angebot_id = (int)($_POST['angebot_id'] ?? 0);
        $db->prepare('DELETE FROM kooperations_angebote WHERE id = ? AND periode_id = ?')->execute([$angebot_id, $periode_id]);
        logActivity('kooperationsangebot_geloescht', "Periode-ID: {$periode_id}, Angebot-ID: {$angebot_id}");
        flashMessage('success', 'Angebot entfernt.');
        redirect(APP_URL . '/dashboard/admin/kooperation-periode-detail.php?id=' . $periode_id);
    }

    if ($action === 'update_rechnung') {
        $rechnung_nr     = trim($_POST['rechnung_nr'] ?? '');
        $rechnung_datum  = trim($_POST['rechnung_datum'] ?? '');
        $trainer_4h      = max(0, (int)($_POST['rechnung_trainer_4h'] ?? 0));
        $trainer_56h     = max(0, (int)($_POST['rechnung_trainer_56h'] ?? 0));
        $betrag          = ($trainer_4h * 90) + ($trainer_56h * 120);
        $kontoname       = trim($_POST['rechnung_kontoname'] ?? '');
        $iban            = trim($_POST['rechnung_iban'] ?? '');
        $bic             = trim($_POST['rechnung_bic'] ?? '');
        $bank            = trim($_POST['rechnung_bank'] ?? '');
        $rechnung_status = $_POST['rechnung_status'] ?? 'offen';
        if (!isset($re_labels[$rechnung_status])) $rechnung_status = 'offen';

        $db->prepare(
            'UPDATE kooperations_perioden SET
                rechnung_nr = ?, rechnung_datum = ?, rechnung_trainer_4h = ?, rechnung_trainer_56h = ?, rechnung_betrag = ?,
                rechnung_kontoname = ?, rechnung_iban = ?, rechnung_bic = ?, rechnung_bank = ?, rechnung_status = ?
             WHERE id = ?'
        )->execute([
            $rechnung_nr ?: null, $rechnung_datum ?: null, $trainer_4h, $trainer_56h, $betrag,
            $kontoname ?: null, $iban ?: null, $bic ?: null, $bank ?: null, $rechnung_status,
            $periode_id,
        ]);
        logActivity('kooperationsperiode_rechnung_aktualisiert', "Periode-ID: {$periode_id}");
        flashMessage('success', 'Rechnung aktualisiert.');
        redirect(APP_URL . '/dashboard/admin/kooperation-periode-detail.php?id=' . $periode_id);
    }

    if ($action === 'periode_loeschen') {
        $db->prepare('DELETE FROM kooperations_perioden WHERE id = ?')->execute([$periode_id]);
        logActivity('kooperationsperiode_geloescht', "Periode-ID: {$periode_id}");
        flashMessage('success', 'Periode gelöscht.');
        redirect(APP_URL . '/dashboard/admin/kooperation-detail.php?id=' . $kooperation_id);
    }

    $periode = array_merge($periode, $_POST);
}

$stmt = $db->prepare('SELECT * FROM kooperations_angebote WHERE periode_id = ? ORDER BY sortierung ASC, id ASC');
$stmt->execute([$periode_id]);
$angebote = $stmt->fetchAll();

$page_title = 'Periode ' . $periode['bezeichnung'];
$breadcrumb = 'Gemeinde-Kooperationen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <a href="<?= APP_URL ?>/dashboard/admin/kooperation-detail.php?id=<?= $kooperation_id ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Zurück zu <?= e($periode['gemeinde_name']) ?>
    </a>
    <h1 class="dashboard-title">Periode <?= e($periode['bezeichnung']) ?></h1>
    <p class="dashboard-subtitle"><?= date('d.m.Y', strtotime($periode['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($periode['zeitraum_bis'])) ?></p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<!-- Rückmeldeblatt -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="table-card-title">Rückmeldeblatt – Geplante Angebote</h2>
        <a href="<?= APP_URL ?>/dashboard/admin/kooperation-pdf.php?type=rueckmeldeblatt&periode_id=<?= $periode_id ?>" target="_blank" class="btn btn-ghost-light btn-sm">PDF erzeugen</a>
    </div>

    <?php if (empty($angebote)): ?>
        <div class="empty-state"><h3>Noch keine Angebote erfasst</h3></div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Angebot</th>
                        <th>Zielgruppe</th>
                        <th>Zeitraum</th>
                        <th>Gruppen</th>
                        <th>Einheiten/Gruppe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($angebote as $a): ?>
                    <tr>
                        <td class="text-primary"><?= e($a['angebotsname']) ?></td>
                        <td><?= e($a['zielgruppe'] ?? '–') ?></td>
                        <td><?= e(($a['zeitraum_von'] ?: '–') . ' – ' . ($a['zeitraum_bis'] ?: '–')) ?></td>
                        <td><?= $a['gruppenanzahl'] !== null ? (int)$a['gruppenanzahl'] : '–' ?></td>
                        <td><?= $a['einheiten_pro_gruppe'] !== null ? (int)$a['einheiten_pro_gruppe'] : '–' ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Angebot wirklich entfernen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="angebot_loeschen">
                                <input type="hidden" name="angebot_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Angebot hinzufügen</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="angebot_hinzufuegen">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Angebotsname <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['angebotsname']) ? 'error' : '' ?>" type="text" name="angebotsname" placeholder="z.B. Calisthenics für Senior:innen">
                </div>
                <div class="form-group">
                    <label class="form-label">Zielgruppe</label>
                    <input class="form-control" type="text" name="zielgruppe" placeholder="z.B. Senior:innen">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Umsetzungszeitraum von</label>
                    <input class="form-control" type="text" name="ang_zeitraum_von" placeholder="z.B. 09/2026">
                </div>
                <div class="form-group">
                    <label class="form-label">bis</label>
                    <input class="form-control" type="text" name="ang_zeitraum_bis" placeholder="z.B. 06/2027">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Geplante Gruppenanzahl</label>
                    <input class="form-control" type="number" min="0" name="gruppenanzahl">
                </div>
                <div class="form-group">
                    <label class="form-label">Einheiten pro Gruppe</label>
                    <input class="form-control" type="number" min="0" name="einheiten_pro_gruppe">
                </div>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Angebot hinzufügen</button>
        </form>
    </div>

    <div style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Zusage des Dachverbands</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_zusage">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Zugesagte Angebote</label>
                    <input class="form-control" type="text" name="zugesagte_angebote" value="<?= e($periode['zugesagte_angebote'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Zugesagte Einheiten</label>
                    <input class="form-control" type="text" name="zugesagte_einheiten" value="<?= e($periode['zugesagte_einheiten'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Zugesagtes Budget (€)</label>
                    <input class="form-control" type="number" min="0" step="0.01" name="zugesagtes_budget" value="<?= e($periode['zugesagtes_budget'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Rückmeldeblatt-Status</label>
                    <select class="form-control" name="rueckmeldeblatt_status">
                        <?php foreach ($rm_labels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $periode['rueckmeldeblatt_status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
        </form>
    </div>
</div>

<!-- Rechnung -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="table-card-title">Rechnung an <?= e($periode['dachverband']) ?></h2>
        <a href="<?= APP_URL ?>/dashboard/admin/kooperation-pdf.php?type=rechnung&periode_id=<?= $periode_id ?>" target="_blank" class="btn btn-ghost-light btn-sm">PDF erzeugen</a>
    </div>
    <div style="padding: 1.25rem;">
        <p class="form-hint" style="margin-bottom: 1rem;">Deadline: Ende August des jeweiligen Jahres. Der Rechnung müssen Dokumentation und Anwesenheitsliste beigelegt werden (im Ordner unten hochladen).</p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_rechnung">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Rechnungsnummer</label>
                    <input class="form-control" type="text" name="rechnung_nr" value="<?= e($periode['rechnung_nr'] ?? '') ?>" placeholder="z.B. AC-STMK-2027-01">
                </div>
                <div class="form-group">
                    <label class="form-label">Rechnungsdatum</label>
                    <input class="form-control" type="date" name="rechnung_datum" value="<?= e($periode['rechnung_datum'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Trainer:innen-Einsätze à 4 Std. (90 €)</label>
                    <input class="form-control" type="number" min="0" id="rechnung_trainer_4h" name="rechnung_trainer_4h" value="<?= (int)($periode['rechnung_trainer_4h'] ?? 0) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Trainer:innen-Einsätze à 5/6 Std. (120 €)</label>
                    <input class="form-control" type="number" min="0" id="rechnung_trainer_56h" name="rechnung_trainer_56h" value="<?= (int)($periode['rechnung_trainer_56h'] ?? 0) ?>">
                </div>
            </div>
            <p style="font-weight: 700; margin-bottom: 1.25rem;">Gesamtbetrag: <span id="rechnung_betrag_anzeige"><?= number_format((float)($periode['rechnung_betrag'] ?? 0), 2, ',', '.') ?></span> €</p>

            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Kontodaten für die Überweisung</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Kontoname</label>
                    <input class="form-control" type="text" name="rechnung_kontoname" value="<?= e($periode['rechnung_kontoname'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Bank</label>
                    <input class="form-control" type="text" name="rechnung_bank" value="<?= e($periode['rechnung_bank'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">IBAN</label>
                    <input class="form-control" type="text" name="rechnung_iban" value="<?= e($periode['rechnung_iban'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">BIC</label>
                    <input class="form-control" type="text" name="rechnung_bic" value="<?= e($periode['rechnung_bic'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Rechnungs-Status</label>
                <select class="form-control" name="rechnung_status">
                    <?php foreach ($re_labels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $periode['rechnung_status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Rechnung speichern</button>
        </form>
    </div>
</div>

<div class="table-card">
    <div style="padding: 1.25rem;">
        <form method="POST" onsubmit="return confirm('Periode wirklich unwiderruflich löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="periode_loeschen">
            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Periode löschen</button>
        </form>
    </div>
</div>

<script>
function berechneRechnungsbetrag() {
    const h4  = parseInt(document.getElementById('rechnung_trainer_4h').value, 10) || 0;
    const h56 = parseInt(document.getElementById('rechnung_trainer_56h').value, 10) || 0;
    const betrag = (h4 * 90) + (h56 * 120);
    document.getElementById('rechnung_betrag_anzeige').textContent = betrag.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
document.getElementById('rechnung_trainer_4h').addEventListener('input', berechneRechnungsbetrag);
document.getElementById('rechnung_trainer_56h').addEventListener('input', berechneRechnungsbetrag);
</script>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
