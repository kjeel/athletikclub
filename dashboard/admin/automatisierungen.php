<?php
/**
 * Athletikclub Steiermark – Automatisierungen (Übersicht, Steuerung, Protokoll)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/automation.php';

requireDarf('automatisierungen.bearbeiten');

$db     = getDB();
$org_id = currentOrgId();
$self   = APP_URL . '/dashboard/admin/automatisierungen.php';
automationenSync($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $code = (string)($_POST['code'] ?? '');
    if ($action === 'speichern' && isset(AUTOMATION_REGISTER[$code])) {
        $stmt = $db->prepare('SELECT * FROM automationen WHERE organization_id = ? AND code = ?');
        $stmt->execute([$org_id, $code]);
        $alt = $stmt->fetch();
        $param = [];
        foreach (AUTOMATION_REGISTER[$code]['parameter'] as $k => $std) if (is_int($std)) $param[$k] = max(1, min(365, (int)($_POST['p_' . $k] ?? $std)));
        $neu = ['aktiv' => !empty($_POST['aktiv']) ? 1 : 0, 'intervall_min' => max(5, min(10080, (int)($_POST['intervall_min'] ?? 60))), 'parameter' => $param ? json_encode($param) : null];
        $db->prepare('UPDATE automationen SET aktiv = ?, intervall_min = ?, parameter = ?, naechste_ausfuehrung = NULL WHERE organization_id = ? AND code = ?')
           ->execute([...array_values($neu), $org_id, $code]);
        auditLog('geaendert', 'automationen', (int)$alt['id'], $alt, $neu, AUTOMATION_REGISTER[$code]['name']);
        flashMessage('success', AUTOMATION_REGISTER[$code]['name'] . ' gespeichert.');
    } elseif ($action === 'ausfuehren') {
        $erg = automationLauf($db, true, isset(AUTOMATION_REGISTER[$code]) ? $code : null);
        if ($erg === null) flashMessage('error', 'Gerade läuft bereits eine Ausführung – bitte kurz warten.');
        elseif ($erg['fehler']) flashMessage('error', 'Fehler: ' . implode(' | ', $erg['fehler']));
        else flashMessage('success', 'Ausgeführt – ' . array_sum($erg['ausgefuehrt']) . ' Aktion(en).');
    } elseif ($action === 'cron_schluessel') {
        einstellungSetzen($db, 'cron_schluessel', bin2hex(random_bytes(24)));
        auditLog('geaendert', 'einstellungen', null, null, ['cron_schluessel' => 'neu erzeugt'], 'Cron-Schlüssel');
        flashMessage('success', 'Neuer Cron-Schlüssel erzeugt – bitte die Cron-Adresse im Hosting aktualisieren.');
    }
    redirect($self);
}

$stmt = $db->prepare('SELECT * FROM automationen WHERE organization_id = ?');
$stmt->execute([$org_id]);
$zeilen = array_column($stmt->fetchAll(), null, 'code');
$f_auto = isset(AUTOMATION_REGISTER[$_GET['automation'] ?? '']) ? $_GET['automation'] : '';
$f_erg = in_array($_GET['ergebnis'] ?? '', ['ok', 'fehler', 'uebersprungen'], true) ? $_GET['ergebnis'] : '';
$seite = max(1, (int)($_GET['seite'] ?? 1));
$w = 'organization_id = ?';
$p = [$org_id];
if ($f_auto) { $w .= ' AND automation = ?'; $p[] = $f_auto; }
if ($f_erg) { $w .= ' AND ergebnis = ?'; $p[] = $f_erg; }
$stmt = $db->prepare("SELECT * FROM automation_log WHERE $w ORDER BY id DESC LIMIT 50 OFFSET " . (($seite - 1) * 50));
$stmt->execute($p);
$log = $stmt->fetchAll();
$letzter = systemStatus($db, 'automation_letzter_lauf_' . $org_id);
$schluessel = einstellung('cron_schluessel', '');

$page_title = 'Automatisierungen';
$breadcrumb = 'Automatisierungen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$intervall_text = fn($m) => $m >= 1440 ? round($m / 1440, 1) . ' Tag(e)' : ($m >= 60 ? round($m / 60, 1) . ' Std.' : $m . ' Min.');
?>
<style>
.au-liste { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1rem; }
.au-karte { background: var(--surface, #fff); border: 1px solid var(--border-light); border-radius: 1rem; padding: 1rem 1.2rem; display: flex; flex-direction: column; gap: 0.5rem; }
.au-kette { display: grid; grid-template-columns: auto 1fr; gap: 0.15rem 0.5rem; font-size: 0.8rem; }
.au-kette span:nth-child(odd) { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.05em; padding-top: 0.1rem; }
.au-mini { font-size: 0.75rem; color: var(--text-muted); }
.au-form { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; border-top: 1px solid var(--border-light); padding-top: 0.6rem; }
.au-form .form-group { margin: 0; }
.au-form input[type=number] { width: 90px; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div><h1 class="dashboard-title">Automatisierungen</h1>
        <p class="dashboard-subtitle">Auslöser → Bedingung → Aktion. Letzter Lauf: <?= $letzter ? date('d.m.Y H:i', strtotime($letzter)) : 'noch nie' ?>. Finanzielle Schritte werden nur vorbereitet.</p></div>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="ausfuehren"><button class="btn btn-primary btn-sm">Alle jetzt ausführen</button></form>
</div>

<div class="au-liste">
    <?php foreach (AUTOMATION_REGISTER as $code => $def): $z = $zeilen[$code] ?? ['aktiv' => 1, 'intervall_min' => $def['intervall'], 'parameter' => null, 'letzte_ausfuehrung' => null, 'naechste_ausfuehrung' => null, 'letzter_status' => null, 'letzter_fehler' => null]; ?>
    <div class="au-karte">
        <div style="display: flex; justify-content: space-between; gap: 0.5rem; align-items: center;">
            <strong><?= e($def['name']) ?></strong>
            <span class="badge <?= !$z['aktiv'] ? 'badge-gray' : ($z['letzter_status'] === 'fehler' ? 'badge-danger' : 'badge-success') ?>"><?= !$z['aktiv'] ? 'inaktiv' : ($z['letzter_status'] === 'fehler' ? 'Fehler' : 'aktiv') ?></span>
        </div>
        <div class="au-kette"><span>Auslöser</span><span><?= e($def['ausloeser']) ?></span><span>Bedingung</span><span><?= e($def['bedingung']) ?></span><span>Aktion</span><span><?= e($def['aktion']) ?></span></div>
        <div class="au-mini">Letzte Ausführung: <?= $z['letzte_ausfuehrung'] ? date('d.m.Y H:i', strtotime($z['letzte_ausfuehrung'])) : '–' ?> · nächste Prüfung: <?= $z['aktiv'] ? ($z['naechste_ausfuehrung'] ? date('d.m.Y H:i', strtotime($z['naechste_ausfuehrung'])) : 'beim nächsten Lauf') : '–' ?></div>
        <?php if ($z['letzter_fehler']): ?><div class="au-mini" style="color: #B91C1C;"><?= e(mb_strimwidth($z['letzter_fehler'], 0, 180, '…')) ?></div><?php endif; ?>
        <form method="POST" class="au-form"><?= csrfField() ?><input type="hidden" name="code" value="<?= e($code) ?>">
            <label class="form-check" style="margin-bottom: 0.4rem;"><input type="checkbox" name="aktiv" value="1" <?= $z['aktiv'] ? 'checked' : '' ?>><span class="form-check-label">aktiv</span></label>
            <div class="form-group"><label class="form-label" style="font-size: 0.7rem;">Prüfen alle (Min.)</label><input class="form-control" type="number" name="intervall_min" min="5" value="<?= (int)$z['intervall_min'] ?>" title="<?= e($intervall_text((int)$z['intervall_min'])) ?>"></div>
            <?php $pw = json_decode((string)$z['parameter'], true) ?: []; foreach ($def['parameter'] as $k => $std): if (is_int($std)): ?>
            <div class="form-group"><label class="form-label" style="font-size: 0.7rem;"><?= e(ucfirst($k)) ?></label><input class="form-control" type="number" name="p_<?= e($k) ?>" min="1" value="<?= (int)($pw[$k] ?? $std) ?>"></div>
            <?php elseif (is_string($std)): ?><span class="au-mini" style="margin-bottom: 0.5rem;"><?= e(ucfirst($k)) ?>: <?= e(einstellung($std, '')) ?> (<a href="<?= APP_URL ?>/dashboard/admin/einstellungen.php">Einstellungen</a>)</span><?php endif; endforeach; ?>
            <button class="btn btn-ghost-light btn-sm" name="action" value="speichern">Speichern</button>
            <button class="btn btn-ghost-light btn-sm" name="action" value="ausfuehren" title="Jetzt ausführen">▶</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Zeitsteuerung (Cron)</h2></div>
    <div style="padding: 1rem 1.25rem; font-size: 0.88rem; line-height: 1.6;">
        <p>Ohne Cron laufen die Automatisierungen höchstens alle 10 Minuten, sobald jemand das Dashboard öffnet. Für pünktliche Erinnerungen im Hosting einen Cron-Job (z.B. alle 15 Minuten) auf folgende Adresse einrichten:</p>
        <?php if ($schluessel): ?>
            <code style="display: block; background: var(--bg-muted); padding: 0.6rem 0.8rem; border-radius: 0.5rem; word-break: break-all;"><?= e(APP_URL . '/cron.php?key=' . substr($schluessel, 0, 6) . '…') ?></code>
            <details style="margin-top: 0.5rem;"><summary class="au-mini" style="cursor: pointer;">Vollständige Adresse anzeigen (geheim halten)</summary><code style="display: block; background: var(--bg-muted); padding: 0.6rem 0.8rem; border-radius: 0.5rem; word-break: break-all; margin-top: 0.4rem;"><?= e(APP_URL . '/cron.php?key=' . $schluessel) ?></code></details>
        <?php else: ?><p class="au-mini">Noch kein Schlüssel vorhanden.</p><?php endif; ?>
        <form method="POST" style="margin-top: 0.75rem;" onsubmit="return confirm('<?= $schluessel ? 'Neuen Schlüssel erzeugen? Die alte Cron-Adresse funktioniert dann nicht mehr.' : 'Schlüssel erzeugen?' ?>');"><?= csrfField() ?><input type="hidden" name="action" value="cron_schluessel"><button class="btn btn-ghost-light btn-sm"><?= $schluessel ? 'Neuen Schlüssel erzeugen' : 'Schlüssel erzeugen' ?></button></form>
    </div>
</div>

<div class="table-card" style="margin-top: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Protokoll</h2></div>
    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; padding: 1rem 1.25rem 0;">
        <select class="form-control" name="automation" style="max-width: 240px;"><option value="">Alle Automatisierungen</option><?php foreach (AUTOMATION_REGISTER as $k => $d): ?><option value="<?= e($k) ?>" <?= $f_auto === $k ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
        <select class="form-control" name="ergebnis" style="max-width: 160px;"><option value="">Alle Ergebnisse</option><option value="ok" <?= $f_erg === 'ok' ? 'selected' : '' ?>>ok</option><option value="fehler" <?= $f_erg === 'fehler' ? 'selected' : '' ?>>Fehler</option></select>
        <button class="btn btn-navy btn-sm">Filtern</button>
    </form>
    <?php if (!$log): ?><p class="au-mini" style="padding: 1rem 1.25rem;">Noch keine Einträge.</p>
    <?php else: ?><div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Zeit</th><th>Automatisierung</th><th>Auslöser</th><th>Datensatz</th><th>Aktion / Fehler</th><th>Ergebnis</th></tr></thead>
        <tbody><?php foreach ($log as $l): ?><tr>
            <td style="white-space: nowrap;"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
            <td><?= e(AUTOMATION_REGISTER[$l['automation']]['name'] ?? $l['automation']) ?></td>
            <td class="au-mini"><?= e($l['ausloeser'] ?? '') ?></td>
            <td class="au-mini"><?= e($l['datensatz'] ?? '') ?></td>
            <td><?= e($l['aktion'] ?? '') ?><?= $l['fehler'] ? '<div class="au-mini" style="color: #B91C1C;">' . e(mb_strimwidth($l['fehler'], 0, 200, '…')) . '</div>' : '' ?></td>
            <td><span class="badge <?= $l['ergebnis'] === 'ok' ? 'badge-success' : ($l['ergebnis'] === 'fehler' ? 'badge-danger' : 'badge-gray') ?>"><?= e($l['ergebnis']) ?></span></td>
        </tr><?php endforeach; ?></tbody></table></div>
        <div style="display: flex; justify-content: center; gap: 0.5rem; padding: 1rem;"><?php if ($seite > 1): ?><a class="btn btn-ghost-light btn-sm" href="?<?= e(http_build_query(['automation' => $f_auto, 'ergebnis' => $f_erg, 'seite' => $seite - 1])) ?>">‹ Neuere</a><?php endif; ?><?php if (count($log) === 50): ?><a class="btn btn-ghost-light btn-sm" href="?<?= e(http_build_query(['automation' => $f_auto, 'ergebnis' => $f_erg, 'seite' => $seite + 1])) ?>">Ältere ›</a><?php endif; ?></div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
