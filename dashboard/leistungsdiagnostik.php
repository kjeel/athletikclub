<?php
/**
 * Athletikclub Steiermark – Leistungsdiagnostik (Übersicht für Trainer:innen)
 * Testungen planen, Gruppenfortschritt je Test, Zugang zu Testkatalog und JASP-Export.
 * Mitglieder werden auf ihr eigenes Leistungsprofil weitergeleitet.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/leistung.php';

requireLogin();
if (!isTrainer()) redirect(APP_URL . '/dashboard/leistungsprofil.php');

$db     = getDB();
$org_id = currentOrgId();
$errors = [];
$tests  = ldTests($db);

// ----------------------------------------------------------------
// Neue Testung anlegen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sitzung_neu') {
    requireCsrf();
    $mitglied_id = (int)($_POST['mitglied_id'] ?? 0);
    $datum       = $_POST['datum'] ?? '';
    $uhrzeit     = $_POST['uhrzeit'] ?? '';
    $test_ids    = array_values(array_intersect(array_map('intval', (array)($_POST['tests'] ?? [])), array_keys($tests)));

    if (!ldMitglied($db, $mitglied_id)) $errors['mitglied_id'] = 'Bitte ein Mitglied auswählen.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || !strtotime($datum)) $errors['datum'] = 'Bitte ein gültiges Datum angeben.';
    if (!empty($_POST['wie_zuletzt']) && !isset($errors['mitglied_id'])) {
        $stmt = $db->prepare('SELECT e.test_id FROM ld_ergebnisse e WHERE e.sitzung_id = (SELECT id FROM ld_sitzungen WHERE mitglied_id = ? AND organization_id = ? ORDER BY datum DESC, id DESC LIMIT 1)');
        $stmt->execute([$mitglied_id, $org_id]);
        $test_ids = array_values(array_unique(array_merge($test_ids, array_intersect(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), array_keys($tests)))));
    }
    if (!$test_ids) $errors['tests'] = 'Bitte mindestens einen Test auswählen (oder eine Testbatterie).';

    if (empty($errors)) {
        $db->beginTransaction();
        $db->prepare('INSERT INTO ld_sitzungen (organization_id, mitglied_id, trainer_id, datum, uhrzeit, ort, bedingungen, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$org_id, $mitglied_id, getCurrentUserId(), $datum, preg_match('/^\d{2}:\d{2}$/', $uhrzeit) ? $uhrzeit . ':00' : null,
                      mb_substr(trim($_POST['ort'] ?? ''), 0, 150) ?: null, mb_substr(trim($_POST['bedingungen'] ?? ''), 0, 255) ?: null, 'geplant']);
        $sitzung_id = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO ld_ergebnisse (sitzung_id, test_id, wert) VALUES (?, ?, NULL)');
        foreach ($test_ids as $tid) $ins->execute([$sitzung_id, $tid]);
        $db->commit();
        logActivity('leistungstest_angelegt', "Testung-ID: {$sitzung_id}, Mitglied-ID: {$mitglied_id}");
        flashMessage('success', 'Testung angelegt – jetzt können die Messwerte eingetragen werden.');
        redirect(APP_URL . '/dashboard/leistungstest.php?id=' . $sitzung_id);
    }
}

// ----------------------------------------------------------------
// Daten
// ----------------------------------------------------------------
$filter_mitglied = (int)($_GET['mitglied'] ?? 0);
$nur_meine       = !empty($_GET['meine']);

$where  = 's.organization_id = ?';
$params = [$org_id];
if ($filter_mitglied) { $where .= ' AND s.mitglied_id = ?'; $params[] = $filter_mitglied; }
if ($nur_meine)       { $where .= ' AND s.trainer_id = ?';  $params[] = getCurrentUserId(); }
$stmt = $db->prepare(
    "SELECT s.*, m.vorname AS m_vorname, m.nachname AS m_nachname, t.vorname AS t_vorname, t.nachname AS t_nachname,
            (SELECT COUNT(*) FROM ld_ergebnisse e WHERE e.sitzung_id = s.id) AS tests_gesamt,
            (SELECT COUNT(*) FROM ld_ergebnisse e WHERE e.sitzung_id = s.id AND e.wert IS NOT NULL) AS tests_gemessen
     FROM ld_sitzungen s JOIN users m ON m.id = s.mitglied_id JOIN users t ON t.id = s.trainer_id
     WHERE {$where} ORDER BY s.datum DESC, s.id DESC LIMIT 150"
);
$stmt->execute($params);
$sitzungen = $stmt->fetchAll();

$heute = date('Y-m-d');
$stmt = $db->prepare("SELECT COUNT(*) AS n, COUNT(DISTINCT mitglied_id) AS personen FROM ld_sitzungen WHERE organization_id = ? AND status = 'durchgefuehrt' AND datum >= ?");
$stmt->execute([$org_id, date('Y-m-d', strtotime('-12 months'))]);
$kpi_jahr = $stmt->fetch();
$stmt = $db->prepare("SELECT COUNT(*) FROM ld_sitzungen WHERE organization_id = ? AND status = 'geplant' AND datum >= ?");
$stmt->execute([$org_id, $heute]);
$kpi_geplant = (int)$stmt->fetchColumn();

$fortschritt = ldGruppenfortschritt($db, $tests, $nur_meine ? (int)getCurrentUserId() : null);
$bewertbar = array_filter($fortschritt, fn($f) => $f['veraenderung'] !== null && $f['test']['richtung'] !== 'neutral');
$kpi_verbesserung = $bewertbar ? round(array_sum(array_column($bewertbar, 'veraenderung')) / count($bewertbar), 1) : null;

$stmt = $db->prepare("SELECT u.id, u.vorname, u.nachname FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
                      WHERE u.organization_id = ? AND u.rolle = 'mitglied' AND u.aktiv = 1 AND COALESCE(mp.mitgliedsstatus, 'aktiv') <> 'inaktiv'
                      ORDER BY u.nachname, u.vorname");
$stmt->execute([$org_id]);
$mitglieder = $stmt->fetchAll();

$tests_je_kategorie = [];
foreach ($tests as $t) $tests_je_kategorie[$t['kategorie']][] = $t;
$batterien = [];
foreach (LD_BATTERIEN as $key => $b) {
    $ids = array_keys(array_filter($tests, fn($t) => in_array($t['name'], $b['tests'], true)));
    if ($ids) $batterien[$key] = ['label' => $b['label'], 'ids' => $ids];
}

$form = array_merge(['mitglied_id' => $filter_mitglied ?: '', 'datum' => $heute, 'uhrzeit' => '', 'ort' => '', 'bedingungen' => ''], $_POST);
$gewaehlt = array_map('intval', (array)($_POST['tests'] ?? []));
$prozent = fn(?float $p) => $p === null ? '–' : ($p > 0 ? '+' : '') . number_format($p, 1, ',', '.') . ' %';

$page_title = 'Leistungsdiagnostik';
$breadcrumb = 'Leistungsdiagnostik';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ld-tests { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 0.25rem 1rem; margin-top: 0.5rem; }
.ld-tests label { display: flex; gap: 0.5rem; align-items: flex-start; font-size: 0.85rem; padding: 0.2rem 0; cursor: pointer; }
.ld-tests label input { margin-top: 0.2rem; flex-shrink: 0; }
.ld-kat { border: 1px solid var(--border-light); border-radius: 0.75rem; padding: 0.6rem 0.9rem; margin-bottom: 0.6rem; }
.ld-kat summary { cursor: pointer; font-weight: 600; font-size: 0.9rem; }
.ld-plus { color: var(--success); font-weight: 600; } .ld-minus { color: var(--danger); font-weight: 600; }
.ld-mini { font-size: 0.75rem; color: var(--text-muted); }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Leistungsdiagnostik</h1>
        <p class="dashboard-subtitle">Tests planen und auswerten, Leistungssteigerung sichtbar machen, Daten für JASP exportieren.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="<?= APP_URL ?>/dashboard/ld-tests.php" class="btn btn-ghost-light btn-sm">Testkatalog</a>
        <a href="<?= APP_URL ?>/dashboard/ld-export.php" class="btn btn-ghost-light btn-sm">Export für JASP</a>
        <a href="#neu" class="btn btn-navy btn-sm">+ Neue Testung</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value"><?= (int)$kpi_jahr['n'] ?></div><div class="kpi-label">Testungen (12 Monate)</div></div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;"><div class="kpi-value"><?= (int)$kpi_jahr['personen'] ?></div><div class="kpi-label">Getestete Personen</div></div>
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value"><?= $kpi_geplant ?></div><div class="kpi-label">Geplante Testungen</div></div>
    <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value"><?= $prozent($kpi_verbesserung) ?></div><div class="kpi-label">Ø Leistungssteigerung</div></div>
</div>

<!-- Gruppenfortschritt -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Leistungssteigerung je Test</h2>
        <span class="ld-mini">erste vs. letzte Messung aller Personen mit mind. 2 Messungen<?= $nur_meine ? ' · nur meine Testungen' : '' ?></span>
    </div>
    <?php if (!$fortschritt): ?>
        <div style="padding: 1.25rem;" class="ld-mini">Noch keine Messwerte vorhanden.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Test</th><th>Personen</th><th>mit Verlauf</th><th>Ø erste Messung</th><th>Ø letzte Messung</th><th>Ø Veränderung</th><th>verbessert</th></tr></thead>
            <tbody>
                <?php foreach ($fortschritt as $f): $t = $f['test']; ?>
                <tr>
                    <td><span class="text-primary"><?= e($t['name']) ?></span><div class="ld-mini"><?= e(LD_KATEGORIEN[$t['kategorie']] ?? '') ?> · <?= $t['richtung'] === 'niedriger' ? 'niedriger ist besser' : ($t['richtung'] === 'hoeher' ? 'höher ist besser' : 'ohne Wertung') ?></div></td>
                    <td><?= $f['personen'] ?></td>
                    <td><?= $f['mit_verlauf'] ?></td>
                    <td><?= ldWert($f['erster_avg'], $t) ?></td>
                    <td><?= ldWert($f['letzter_avg'], $t) ?></td>
                    <td class="<?= $t['richtung'] !== 'neutral' && $f['veraenderung'] !== null ? ($f['veraenderung'] >= 0 ? 'ld-plus' : 'ld-minus') : '' ?>"><?= $prozent($f['veraenderung']) ?></td>
                    <td><?= $f['verbessert'] !== null ? $f['verbessert'] . ' %' : '–' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Testungen -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Testungen</h2>
        <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
            <select class="form-control" name="mitglied" style="max-width: 220px;" onchange="this.form.submit()">
                <option value="">Alle Mitglieder</option>
                <?php foreach ($mitglieder as $m): ?><option value="<?= $m['id'] ?>" <?= $filter_mitglied === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option><?php endforeach; ?>
            </select>
            <label style="display: flex; gap: 0.35rem; align-items: center; font-size: 0.85rem;"><input type="checkbox" name="meine" value="1" <?= $nur_meine ? 'checked' : '' ?> onchange="this.form.submit()"> nur meine</label>
        </form>
    </div>
    <?php if (!$sitzungen): ?>
        <div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Noch keine Testungen</h3><p>Lege unten die erste Testung an.</p></div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Datum</th><th>Mitglied</th><th>Tests</th><th>Status</th><th>Trainer:in</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($sitzungen as $s): ?>
                <tr data-row-href="<?= APP_URL ?>/dashboard/leistungstest.php?id=<?= $s['id'] ?>">
                    <td><?= date('d.m.Y', strtotime($s['datum'])) ?><?php if ($s['uhrzeit']): ?><div class="ld-mini"><?= substr($s['uhrzeit'], 0, 5) ?> Uhr</div><?php endif; ?></td>
                    <td><a class="text-primary" href="<?= APP_URL ?>/dashboard/leistungsprofil.php?mitglied=<?= $s['mitglied_id'] ?>"><?= e($s['m_vorname'] . ' ' . $s['m_nachname']) ?></a></td>
                    <td><?= (int)$s['tests_gemessen'] ?> / <?= (int)$s['tests_gesamt'] ?> gemessen</td>
                    <td><span class="badge <?= $s['status'] === 'durchgefuehrt' ? 'badge-success' : 'badge-info' ?>"><?= $s['status'] === 'durchgefuehrt' ? 'Durchgeführt' : 'Geplant' ?></span></td>
                    <td><?= e($s['t_vorname'] . ' ' . $s['t_nachname']) ?></td>
                    <td><a href="<?= APP_URL ?>/dashboard/leistungstest.php?id=<?= $s['id'] ?>" class="btn btn-ghost-light btn-sm"><?= $s['status'] === 'geplant' ? 'Werte eintragen' : 'Öffnen' ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Neue Testung -->
<div class="table-card" id="neu">
    <div class="table-card-header"><h2 class="table-card-title">Neue Testung</h2></div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= APP_URL ?>/dashboard/leistungsdiagnostik.php#neu">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sitzung_neu">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Mitglied <span class="required">*</span></label>
                    <select class="form-control <?= isset($errors['mitglied_id']) ? 'error' : '' ?>" name="mitglied_id" required>
                        <option value="">– auswählen –</option>
                        <?php foreach ($mitglieder as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$form['mitglied_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nachname'] . ' ' . $m['vorname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Datum <span class="required">*</span></label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input class="form-control" type="date" name="datum" value="<?= e($form['datum']) ?>" required style="flex: 1;">
                        <input class="form-control" type="time" name="uhrzeit" value="<?= e($form['uhrzeit']) ?>" style="max-width: 130px;" title="Uhrzeit (optional)">
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Ort</label><input class="form-control" type="text" name="ort" maxlength="150" value="<?= e($form['ort']) ?>" placeholder="z.B. Turnhalle St. Georgen"></div>
                <div class="form-group"><label class="form-label">Bedingungen</label><input class="form-control" type="text" name="bedingungen" maxlength="255" value="<?= e($form['bedingungen']) ?>" placeholder="z.B. nach 10 min Aufwärmen, Hallenschuhe"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Testbatterie</label>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <?php foreach ($batterien as $key => $b): ?>
                    <button type="button" class="btn btn-ghost-light btn-sm" data-batterie="<?= e(implode(',', $b['ids'])) ?>"><?= e($b['label']) ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-ghost-light btn-sm" data-batterie="">Auswahl leeren</button>
                </div>
                <label style="display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem; margin-top: 0.75rem;">
                    <input type="checkbox" name="wie_zuletzt" value="1" <?= !empty($_POST['wie_zuletzt']) ? 'checked' : '' ?>> Tests der letzten Testung dieses Mitglieds übernehmen (Retest)
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">Tests <?php if (isset($errors['tests'])): ?><span class="required"><?= e($errors['tests']) ?></span><?php endif; ?></label>
                <?php foreach (LD_KATEGORIEN as $kat => $kat_label): if (empty($tests_je_kategorie[$kat])) continue; $offen = array_intersect(array_column($tests_je_kategorie[$kat], 'id'), $gewaehlt); ?>
                <details class="ld-kat" <?= $offen ? 'open' : '' ?>>
                    <summary><?= e($kat_label) ?> <span class="ld-mini">(<?= count($tests_je_kategorie[$kat]) ?>)</span></summary>
                    <div class="ld-tests">
                        <?php foreach ($tests_je_kategorie[$kat] as $t): ?>
                        <label title="<?= e((string)$t['beschreibung']) ?>"><input type="checkbox" name="tests[]" value="<?= $t['id'] ?>" <?= in_array((int)$t['id'], $gewaehlt, true) ? 'checked' : '' ?>> <span><?= e($t['name']) ?> <span class="ld-mini">(<?= e($t['einheit']) ?>)</span></span></label>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endforeach; ?>
                <p class="form-hint"><?= e(LD_HINWEIS) ?></p>
            </div>
            <button type="submit" class="btn btn-navy">Testung anlegen</button>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('[data-batterie]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var ids = btn.getAttribute('data-batterie') ? btn.getAttribute('data-batterie').split(',') : [];
        document.querySelectorAll('input[name="tests[]"]').forEach(function (cb) {
            cb.checked = ids.indexOf(cb.value) !== -1;
            if (cb.checked) cb.closest('details').open = true;
        });
    });
});
</script>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
