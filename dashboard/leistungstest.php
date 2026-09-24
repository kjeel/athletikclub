<?php
/**
 * Athletikclub Steiermark – Einzelne Testung (Leistungsdiagnostik)
 * Trainer:innen tragen Messwerte ein; Mitglieder sehen ihre Ergebnisse.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/leistung.php';

requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$sitzung = ldSitzung($db, $id);
if (!$sitzung) {
    flashMessage('error', 'Testung nicht gefunden.');
    redirect(APP_URL . (isTrainer() ? '/dashboard/leistungsdiagnostik.php' : '/dashboard/leistungsprofil.php'));
}
$trainer  = isTrainer();
$self_url = APP_URL . '/dashboard/leistungstest.php?id=' . $id;
$tests    = ldTests($db, false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $trainer) {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'speichern') {
        $datum = $_POST['datum'] ?? $sitzung['datum'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || !strtotime($datum)) $datum = $sitzung['datum'];
        $uhrzeit = $_POST['uhrzeit'] ?? '';

        $upd = $db->prepare('UPDATE ld_ergebnisse SET wert = ?, notiz = ? WHERE sitzung_id = ? AND test_id = ?');
        $gemessen = 0;
        foreach ((array)($_POST['wert'] ?? []) as $tid => $eingabe) {
            $wert = ldZahl($eingabe);
            if ($wert !== null) $gemessen++;
            $upd->execute([$wert, mb_substr(trim((string)($_POST['notiz_test'][$tid] ?? '')), 0, 255) ?: null, $id, (int)$tid]);
        }
        $status = $gemessen > 0 ? 'durchgefuehrt' : 'geplant';
        $db->prepare('UPDATE ld_sitzungen SET datum = ?, uhrzeit = ?, ort = ?, bedingungen = ?, notiz = ?, status = ? WHERE id = ?')
           ->execute([$datum, preg_match('/^\d{2}:\d{2}/', $uhrzeit) ? substr($uhrzeit, 0, 5) . ':00' : null,
                      mb_substr(trim($_POST['ort'] ?? ''), 0, 150) ?: null, mb_substr(trim($_POST['bedingungen'] ?? ''), 0, 255) ?: null,
                      trim($_POST['notiz'] ?? '') ?: null, $status, $id]);
        logActivity('leistungstest_gespeichert', "Testung-ID: {$id}");
        flashMessage('success', $gemessen ? "Gespeichert – {$gemessen} Messwert" . ($gemessen === 1 ? '' : 'e') . ' erfasst.' : 'Gespeichert.');
        redirect($self_url);
    }

    if ($action === 'test_hinzufuegen') {
        $tid = (int)($_POST['test_id'] ?? 0);
        $stmt = $db->prepare('SELECT COUNT(*) FROM ld_ergebnisse WHERE sitzung_id = ? AND test_id = ?');
        $stmt->execute([$id, $tid]);
        if (isset($tests[$tid]) && !$stmt->fetchColumn()) {
            $db->prepare('INSERT INTO ld_ergebnisse (sitzung_id, test_id, wert) VALUES (?, ?, NULL)')->execute([$id, $tid]);
        }
        redirect($self_url . '#werte');
    }

    if ($action === 'test_entfernen') {
        $db->prepare('DELETE FROM ld_ergebnisse WHERE sitzung_id = ? AND test_id = ?')->execute([$id, (int)($_POST['test_id'] ?? 0)]);
        redirect($self_url . '#werte');
    }

    if ($action === 'loeschen') {
        $db->prepare('DELETE FROM ld_ergebnisse WHERE sitzung_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM ld_sitzungen WHERE id = ? AND organization_id = ?')->execute([$id, currentOrgId()]);
        logActivity('leistungstest_geloescht', "Testung-ID: {$id}");
        flashMessage('success', 'Testung gelöscht.');
        redirect(APP_URL . '/dashboard/leistungsdiagnostik.php');
    }
}

// Ergebnisse dieser Testung + Vergleich mit vorheriger Messung und Bestwert
$stmt = $db->prepare('SELECT * FROM ld_ergebnisse WHERE sitzung_id = ?');
$stmt->execute([$id]);
$ergebnisse = [];
foreach ($stmt->fetchAll() as $r) if (isset($tests[(int)$r['test_id']])) $ergebnisse[(int)$r['test_id']] = $r;
uksort($ergebnisse, fn($a, $b) => [$tests[$a]['sortierung'], $tests[$a]['name']] <=> [$tests[$b]['sortierung'], $tests[$b]['name']]);

$verlauf = ldVerlauf($db, (int)$sitzung['mitglied_id']);
$vergleich = [];
foreach ($ergebnisse as $tid => $r) {
    $vorher = array_values(array_filter($verlauf[$tid] ?? [], fn($p) => $p['datum'] < $sitzung['datum'] || ($p['datum'] === $sitzung['datum'] && $p['sitzung_id'] < $id)));
    $t = $tests[$tid];
    $werte = array_column($vorher, 'wert');
    $vergleich[$tid] = [
        'vorher'  => $vorher ? end($vorher) : null,
        'bester'  => $werte ? ($t['richtung'] === 'niedriger' ? min($werte) : max($werte)) : null,
    ];
}

$fehlende_tests = array_filter($tests, fn($t) => (int)$t['aktiv'] && !isset($ergebnisse[(int)$t['id']]));
$gemessen = count(array_filter($ergebnisse, fn($r) => $r['wert'] !== null));
$v = fn($wert) => e((string)($wert ?? ''));
$eingabe = fn($wert, $t) => $wert === null ? '' : rtrim(rtrim(number_format((float)$wert, max(0, (int)$t['dezimalen']), ',', ''), '0'), ',');

$page_title = 'Testung ' . date('d.m.Y', strtotime($sitzung['datum']));
$breadcrumb = 'Leistungsdiagnostik';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ld-plus { color: var(--success); font-weight: 600; } .ld-minus { color: var(--danger); font-weight: 600; }
.ld-mini { font-size: 0.75rem; color: var(--text-muted); }
.ld-eingabe { display: flex; align-items: center; gap: 0.4rem; }
.ld-eingabe input { max-width: 120px; text-align: right; }
.ld-best { display: inline-block; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; background: var(--gold-dim); color: #8B6914; border-radius: 99px; padding: 1px 7px; margin-left: 0.35rem; }
:root[data-theme="dark"] .ld-best { color: #E8CE7A; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/<?= $trainer ? 'leistungsdiagnostik.php' : 'leistungsprofil.php' ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <?= $trainer ? 'Leistungsdiagnostik' : 'Meine Leistungswerte' ?>
        </a>
        <h1 class="dashboard-title">Testung vom <?= date('d.m.Y', strtotime($sitzung['datum'])) ?>
            <span class="badge <?= $sitzung['status'] === 'durchgefuehrt' ? 'badge-success' : 'badge-info' ?>" style="vertical-align: middle; font-size: 0.7rem;"><?= $sitzung['status'] === 'durchgefuehrt' ? 'Durchgeführt' : 'Geplant' ?></span></h1>
        <p class="dashboard-subtitle">
            <?= e($sitzung['m_vorname'] . ' ' . $sitzung['m_nachname']) ?>
            <?php if ($sitzung['uhrzeit']): ?> · <?= substr($sitzung['uhrzeit'], 0, 5) ?> Uhr<?php endif; ?>
            <?php if ($sitzung['ort']): ?> · <?= e($sitzung['ort']) ?><?php endif; ?>
            · Trainer:in <?= e($sitzung['t_vorname'] . ' ' . $sitzung['t_nachname']) ?>
            · <?= $gemessen ?> von <?= count($ergebnisse) ?> Tests gemessen
        </p>
    </div>
    <a href="<?= APP_URL ?>/dashboard/leistungsprofil.php<?= $trainer ? '?mitglied=' . (int)$sitzung['mitglied_id'] : '' ?>" class="btn btn-primary btn-sm">Leistungsverlauf ansehen</a>
</div>

<?php if ($trainer): ?>
<form method="POST" id="werte">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="speichern">
<?php endif; ?>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Messwerte</h2><?php if ($trainer): ?><span class="ld-mini">Leeres Feld = nicht gemessen · Komma oder Punkt möglich</span><?php endif; ?></div>
    <?php if (!$ergebnisse): ?>
        <div style="padding: 1.25rem;" class="ld-mini">Noch keine Tests in dieser Testung.</div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Test</th><th>Ergebnis</th><th>Vorher</th><th>Veränderung</th><th>Bisher bester</th><?php if ($trainer): ?><th>Notiz</th><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($ergebnisse as $tid => $r):
                    $t = $tests[$tid];
                    $vorher = $vergleich[$tid]['vorher'];
                    $wert = $r['wert'] !== null ? (float)$r['wert'] : null;
                    $delta = $vorher ? ldVeraenderung($vorher['wert'], $wert, $t['richtung']) : null;
                    $bestwert = $wert !== null && $vergleich[$tid]['bester'] !== null && $t['richtung'] !== 'neutral'
                        && ($t['richtung'] === 'hoeher' ? $wert > $vergleich[$tid]['bester'] : $wert < $vergleich[$tid]['bester']);
                ?>
                <tr>
                    <td>
                        <span class="text-primary"><?= e($t['name']) ?></span><?php if ($bestwert): ?><span class="ld-best">Bestwert</span><?php endif; ?>
                        <div class="ld-mini"><?= e(LD_KATEGORIEN[$t['kategorie']] ?? '') ?> · <?= $t['richtung'] === 'niedriger' ? 'niedriger ist besser' : ($t['richtung'] === 'hoeher' ? 'höher ist besser' : 'ohne Wertung') ?></div>
                        <?php if ($t['beschreibung']): ?><details><summary class="ld-mini" style="cursor: pointer;">Durchführung</summary><div class="ld-mini" style="max-width: 360px; margin-top: 0.25rem;"><?= e($t['beschreibung']) ?></div></details><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($trainer): ?>
                        <div class="ld-eingabe"><input class="form-control" type="text" inputmode="decimal" name="wert[<?= $tid ?>]" value="<?= $v($eingabe($wert, $t)) ?>" aria-label="<?= e($t['name']) ?>"><span class="ld-mini"><?= e($t['einheit']) ?></span></div>
                        <?php else: ?>
                        <strong><?= ldWert($wert, $t) ?></strong><?php if ($r['notiz']): ?><div class="ld-mini"><?= e($r['notiz']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $vorher ? ldWert($vorher['wert'], $t) . '<div class="ld-mini">' . date('d.m.Y', strtotime($vorher['datum'])) . '</div>' : '<span class="ld-mini">erste Messung</span>' ?></td>
                    <td class="<?= $delta !== null && $t['richtung'] !== 'neutral' ? ($delta >= 0 ? 'ld-plus' : 'ld-minus') : '' ?>">
                        <?php if ($delta !== null): ?><?= ($delta > 0 ? '+' : '') . number_format($delta, 1, ',', '.') ?> %<?php elseif ($vorher && $wert !== null): ?><?= ($wert - $vorher['wert'] > 0 ? '+' : '') . ldWert($wert - $vorher['wert'], $t) ?><?php else: ?>–<?php endif; ?>
                    </td>
                    <td><?= ldWert($vergleich[$tid]['bester'], $t) ?></td>
                    <?php if ($trainer): ?>
                    <td><input class="form-control" type="text" name="notiz_test[<?= $tid ?>]" maxlength="255" value="<?= $v($r['notiz']) ?>" placeholder="z.B. Band grün" style="min-width: 140px;"></td>
                    <td><button type="submit" form="entfernen-<?= $tid ?>" class="btn btn-ghost-light btn-sm" style="color: var(--danger);" title="Test aus dieser Testung entfernen">✕</button></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($trainer): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Rahmenbedingungen</h2></div>
    <div style="padding: 1.25rem;">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Datum</label>
                <div style="display: flex; gap: 0.5rem;"><input class="form-control" type="date" name="datum" value="<?= e($sitzung['datum']) ?>" style="flex: 1;"><input class="form-control" type="time" name="uhrzeit" value="<?= e(substr((string)$sitzung['uhrzeit'], 0, 5)) ?>" style="max-width: 130px;"></div></div>
            <div class="form-group"><label class="form-label">Ort</label><input class="form-control" type="text" name="ort" maxlength="150" value="<?= $v($sitzung['ort']) ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Bedingungen</label><input class="form-control" type="text" name="bedingungen" maxlength="255" value="<?= $v($sitzung['bedingungen']) ?>" placeholder="z.B. nach 10 min Aufwärmen, Hallenschuhe, abends"></div>
        <div class="form-group"><label class="form-label">Notiz (für das Mitglied sichtbar)</label><textarea class="form-control" name="notiz" rows="3" placeholder="z.B. Einschätzung, nächste Ziele"><?= $v($sitzung['notiz']) ?></textarea></div>
        <p class="form-hint"><?= e(LD_HINWEIS) ?></p>
        <button type="submit" class="btn btn-navy">Speichern</button>
    </div>
</div>
</form>

<?php foreach ($ergebnisse as $tid => $_): ?>
<form method="POST" id="entfernen-<?= $tid ?>" onsubmit="return confirm('Test aus dieser Testung entfernen? Ein eingetragener Wert geht verloren.')"><?= csrfField() ?><input type="hidden" name="action" value="test_entfernen"><input type="hidden" name="test_id" value="<?= $tid ?>"></form>
<?php endforeach; ?>

<div class="grid-2" style="align-items: start;">
    <?php if ($fehlende_tests): ?>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Weiteren Test hinzufügen</h2></div>
        <form method="POST" style="padding: 1.25rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="test_hinzufuegen">
            <select class="form-control" name="test_id" style="flex: 1; min-width: 200px;">
                <?php foreach (LD_KATEGORIEN as $kat => $label): $liste = array_filter($fehlende_tests, fn($t) => $t['kategorie'] === $kat); if (!$liste) continue; ?>
                <optgroup label="<?= e($label) ?>"><?php foreach ($liste as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (<?= e($t['einheit']) ?>)</option><?php endforeach; ?></optgroup>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-ghost-light btn-sm">Hinzufügen</button>
        </form>
    </div>
    <?php endif; ?>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Testung löschen</h2></div>
        <form method="POST" style="padding: 1.25rem;" onsubmit="return confirm('Diese Testung mit allen Messwerten endgültig löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="loeschen">
            <p class="ld-mini" style="margin-bottom: 0.75rem;">Löscht die Testung samt allen Messwerten.</p>
            <button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Testung löschen</button>
        </form>
    </div>
</div>
<?php else: ?>
    <?php if ($sitzung['notiz']): ?>
    <div class="table-card"><div class="table-card-header"><h2 class="table-card-title">Notiz deiner Trainer:in</h2></div><div style="padding: 1.25rem; white-space: pre-line;"><?= e($sitzung['notiz']) ?></div></div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
