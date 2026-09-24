<?php
/**
 * Athletikclub Steiermark – Zeiterfassung & Monatsabrechnung (Trainer:innen)
 * Offene Bestätigungen/Anwesenheiten, Monatsübersicht mit Honorar und
 * Einreichen der Monatsabrechnung. Mit Abrechnungsrecht auch für andere Personen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$alle   = darf('abrechnung.anzeigen');
$uid    = $alle && !empty($_GET['user']) ? (int)$_GET['user'] : $me;
$jahr   = max(2024, min(2100, (int)($_GET['jahr'] ?? date('Y'))));
$monat  = max(1, min(12, (int)($_GET['monat'] ?? date('n'))));
$self   = APP_URL . "/dashboard/zeiterfassung.php?jahr={$jahr}&monat={$monat}" . ($uid !== $me ? "&user={$uid}" : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'schnell') {
        // Einheit wie geplant bestätigen (nur eigene)
        $stmt = $db->prepare("SELECT et.*, e.start, e.ende FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                              WHERE et.id = ? AND et.user_id = ? AND et.status = 'geplant' AND e.status <> 'storniert' AND e.start <= ?");
        $stmt->execute([(int)($_POST['et_id'] ?? 0), $me, date('Y-m-d H:i:s')]);
        if ($et = $stmt->fetch()) {
            $e = einheitLaden($db, (int)$et['einheit_id']);
            $anw = $db->prepare("SELECT COUNT(*) FROM anwesenheiten WHERE einheit_id = ? AND status IN ('anwesend','probetraining')");
            $anw->execute([$e['id']]);
            $n = (int)$anw->fetchColumn();
            $r = einheitBestaetigen($db, $e, $me, $e['start'], $e['ende'], $n ?: null, null);
            if (!isset($r['fehler'])) trainerAbrechnungAktualisieren($db, $me, (int)substr($e['start'], 0, 4), (int)substr($e['start'], 5, 2));
            flashMessage(isset($r['fehler']) ? 'error' : 'success', $r['fehler'] ?? 'Einheit „' . $e['titel'] . '“ bestätigt.');
        }
        redirect($self);
    }

    if ($action === 'einreichen' && $uid === $me) {
        $ta = trainerAbrechnungAktualisieren($db, $me, $jahr, $monat);
        if (!$ta) {
            flashMessage('error', 'In diesem Monat gibt es keine bestätigten Einheiten.');
        } else {
            $fehler = trainerAbrechnungStatus($db, $ta, 'eingereicht');
            flashMessage($fehler ? 'error' : 'success', $fehler ?? 'Abrechnung ' . sprintf('%02d/%d', $monat, $jahr) . ' eingereicht – die Vereinsleitung prüft sie.');
        }
        redirect($self);
    }
}

// ----------------------------------------------------------------
// Daten
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT id, vorname, nachname FROM users WHERE id = ? AND organization_id = ?');
$stmt->execute([$uid, $org_id]);
$person = $stmt->fetch();
if (!$person) redirect(APP_URL . '/dashboard/zeiterfassung.php');

// Offene Bestätigungen (vergangen, noch „geplant“)
$stmt = $db->prepare("SELECT et.id AS et_id, e.* FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                      WHERE et.user_id = ? AND et.status = 'geplant' AND e.status <> 'storniert' AND e.organization_id = ? AND e.start <= ? ORDER BY e.start");
$stmt->execute([$uid, $org_id, date('Y-m-d H:i:s')]);
$offen = $stmt->fetchAll();

// Offene Anwesenheiten (vergangene Kurseinheiten ohne Erfassung)
$stmt = $db->prepare("SELECT e.* FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                      WHERE et.user_id = ? AND e.kurs_id IS NOT NULL AND e.status <> 'storniert' AND e.organization_id = ? AND e.start <= ? AND e.start >= ?
                        AND NOT EXISTS (SELECT 1 FROM anwesenheiten a WHERE a.einheit_id = e.id) ORDER BY e.start DESC");
$stmt->execute([$uid, $org_id, date('Y-m-d H:i:s'), date('Y-m-d', strtotime('-60 days'))]);
$ohne_anwesenheit = $stmt->fetchAll();

// Monat
$von = sprintf('%04d-%02d-01', $jahr, $monat);
$stmt = $db->prepare("SELECT et.*, e.titel, e.typ, e.start, e.ende, e.status AS e_status, p.name AS projekt_name, k.titel AS kurs_titel
                      FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id LEFT JOIN projekte p ON p.id = e.projekt_id LEFT JOIN kurse k ON k.id = e.kurs_id
                      WHERE et.user_id = ? AND e.organization_id = ? AND e.start BETWEEN ? AND ? ORDER BY e.start");
$stmt->execute([$uid, $org_id, $von . ' 00:00:00', date('Y-m-t', strtotime($von)) . ' 23:59:59']);
$monat_liste = $stmt->fetchAll();
$bestaetigt = array_filter($monat_liste, fn($z) => !in_array($z['status'], ['geplant', 'storniert'], true));
$verdienst = moneySum(array_map(fn($z) => $z['betrag'] ?? '0', $bestaetigt));
$minuten = array_sum(array_map(fn($z) => (int)$z['dauer_min'], $bestaetigt));
$stmt = $db->prepare('SELECT * FROM trainer_abrechnungen WHERE user_id = ? AND jahr = ? AND monat = ?');
$stmt->execute([$uid, $jahr, $monat]);
$ta = $stmt->fetch() ?: null;
$offen_im_monat = array_filter($offen, fn($o) => substr($o['start'], 0, 7) === substr($von, 0, 7));

// Nächste Einheiten
$stmt = $db->prepare("SELECT e.* FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                      WHERE et.user_id = ? AND e.status = 'geplant' AND e.organization_id = ? AND e.start > ? ORDER BY e.start LIMIT 8");
$stmt->execute([$uid, $org_id, date('Y-m-d H:i:s')]);
$naechste = $stmt->fetchAll();

$monate = [1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$vor  = $monat === 1 ? [$jahr - 1, 12] : [$jahr, $monat - 1];
$nach = $monat === 12 ? [$jahr + 1, 1] : [$jahr, $monat + 1];
$url_monat = fn($j, $m) => APP_URL . "/dashboard/zeiterfassung.php?jahr={$j}&monat={$m}" . ($uid !== $me ? "&user={$uid}" : '');
$trainer_liste = $alle ? plattformTrainer($db) : [];

$page_title = 'Zeiterfassung';
$breadcrumb = 'Zeiterfassung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ze-mini { font-size: 0.75rem; color: var(--text-muted); }
.ze-zeile { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; padding: 0.7rem 1.25rem; border-bottom: 1px solid var(--border-light); flex-wrap: wrap; }
.ze-zeile:last-child { border-bottom: none; }
.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Zeiterfassung<?= $uid !== $me ? ': ' . e($person['vorname'] . ' ' . $person['nachname']) : '' ?></h1>
        <p class="dashboard-subtitle">Einheiten bestätigen, Anwesenheit erfassen und die Monatsabrechnung einreichen.</p>
    </div>
    <?php if ($alle): ?>
    <form method="GET" style="display: flex; gap: 0.5rem;">
        <input type="hidden" name="jahr" value="<?= $jahr ?>"><input type="hidden" name="monat" value="<?= $monat ?>">
        <select class="form-control" name="user" onchange="this.form.submit()"><?php foreach ($trainer_liste as $t): ?><option value="<?= $t['id'] ?>" <?= (int)$t['id'] === $uid ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select>
        <a href="<?= APP_URL ?>/dashboard/admin/trainerabrechnungen.php?jahr=<?= $jahr ?>&monat=<?= $monat ?>" class="btn btn-ghost-light btn-sm">Alle Abrechnungen</a>
    </form>
    <?php endif; ?>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value"><?= moneyFormat($verdienst) ?></div><div class="kpi-label">Honorar <?= $monate[$monat] ?></div></div>
    <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value"><?= count($bestaetigt) ?></div><div class="kpi-label">Bestätigte Einheiten · <?= dauerText($minuten) ?></div></div>
    <div class="kpi-card" style="--kpi-color: #EF4444;"><div class="kpi-value"><?= count($offen) ?></div><div class="kpi-label">Offene Bestätigungen</div></div>
    <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value" style="font-size: 1.2rem;"><?= $ta ? TA_STATUS[$ta['status']]['label'] : 'noch keine' ?></div><div class="kpi-label">Abrechnung <?= sprintf('%02d/%d', $monat, $jahr) ?></div></div>
</div>

<?php if ($offen): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Offene Bestätigungen</h2><span class="ze-mini">vergangene Einheiten, die noch nicht als durchgeführt markiert sind</span></div>
    <?php foreach (array_slice($offen, 0, 20) as $o): ?>
    <div class="ze-zeile">
        <div><a class="text-primary" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $o['id'] ?>"><?= e($o['titel']) ?></a>
            <div class="ze-mini"><?= date('d.m.Y, H:i', strtotime($o['start'])) ?>–<?= date('H:i', strtotime($o['ende'])) ?><?= $o['ort'] ? ' · ' . e($o['ort']) : '' ?></div></div>
        <?php if ($uid === $me): ?>
        <div style="display: flex; gap: 0.4rem;">
            <a href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $o['id'] ?>#anwesenheit" class="btn btn-ghost-light btn-sm">Anwesenheit &amp; Details</a>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="schnell"><input type="hidden" name="et_id" value="<?= $o['et_id'] ?>"><button type="submit" class="btn btn-navy btn-sm">Wie geplant bestätigen</button></form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($ohne_anwesenheit): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Anwesenheit fehlt</h2><span class="ze-mini">Kurseinheiten der letzten 60 Tage ohne Anwesenheitsliste</span></div>
    <?php foreach (array_slice($ohne_anwesenheit, 0, 10) as $o): ?>
    <div class="ze-zeile"><div><?= e($o['titel']) ?><div class="ze-mini"><?= date('d.m.Y, H:i', strtotime($o['start'])) ?></div></div>
        <a href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $o['id'] ?>#anwesenheit" class="btn btn-ghost-light btn-sm">Erfassen</a></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <a href="<?= $url_monat(...$vor) ?>" class="btn btn-ghost-light btn-sm" aria-label="Vormonat">‹</a>
            <h2 class="table-card-title" style="min-width: 9rem; text-align: center;"><?= $monate[$monat] ?> <?= $jahr ?></h2>
            <a href="<?= $url_monat(...$nach) ?>" class="btn btn-ghost-light btn-sm" aria-label="Folgemonat">›</a>
        </div>
        <?php if ($ta): ?><span class="badge <?= TA_STATUS[$ta['status']]['class'] ?>">Abrechnung: <?= TA_STATUS[$ta['status']]['label'] ?></span><?php endif; ?>
    </div>
    <?php if (!$monat_liste): ?>
        <div style="padding: 1.25rem;" class="ze-mini">Keine Einheiten in diesem Monat.</div>
    <?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Datum</th><th>Einheit</th><th>Status</th><th class="zahl">Dauer</th><th class="zahl">TN</th><th class="zahl">Honorar</th></tr></thead>
        <tbody><?php foreach ($monat_liste as $z): ?>
            <tr style="<?= $z['status'] === 'storniert' || $z['e_status'] === 'storniert' ? 'opacity: 0.5;' : '' ?>">
                <td><?= date('d.m.', strtotime($z['start'])) ?> <span class="ze-mini"><?= date('H:i', strtotime($z['start'])) ?></span></td>
                <td><a class="text-primary" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $z['einheit_id'] ?>"><?= e($z['titel']) ?></a><div class="ze-mini"><?= e($z['projekt_name'] ?: ($z['kurs_titel'] ?: (EINHEIT_TYPEN[$z['typ']]['label'] ?? ''))) ?></div></td>
                <td><span class="badge <?= ET_STATUS[$z['status']]['class'] ?>"><?= ET_STATUS[$z['status']]['label'] ?></span></td>
                <td class="zahl"><?= dauerText($z['dauer_min'] !== null ? (int)$z['dauer_min'] : null) ?></td>
                <td class="zahl"><?= $z['teilnehmer_anzahl'] ?? '–' ?></td>
                <td class="zahl"><?= $z['betrag'] !== null ? moneyFormat($z['betrag']) : ($z['ist_start'] ? '<span style="color: var(--danger);">kein Satz</span>' : '–') ?></td>
            </tr>
        <?php endforeach; ?>
            <tr><td colspan="3"><strong>Summe bestätigt</strong></td><td class="zahl"><strong><?= dauerText($minuten) ?></strong></td><td></td><td class="zahl"><strong><?= moneyFormat($verdienst) ?></strong></td></tr>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($uid === $me && $bestaetigt && (!$ta || $ta['status'] === 'entwurf')): ?>
    <form method="POST" style="padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="einreichen">
        <button type="submit" class="btn btn-navy btn-sm" <?= $offen_im_monat ? 'onclick="return confirm(\'Es gibt noch ' . count($offen_im_monat) . ' unbestätigte Einheit(en) in diesem Monat. Trotzdem einreichen?\')"' : '' ?>>Monat zur Abrechnung einreichen</button>
        <span class="ze-mini"><?= count($bestaetigt) ?> Einheiten · <?= moneyFormat($verdienst) ?> · danach prüft und gibt die Vereinsleitung frei<?= $ta && $ta['auszahlungsart'] === 'prae' ? ' (Auszahlung als PRAE)' : '' ?>.</span>
    </form>
    <?php elseif ($ta && $ta['status'] !== 'entwurf'): ?>
    <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--border-light);" class="ze-mini">
        Eingereicht <?= plattformDatum($ta['eingereicht_am'], 'd.m.Y H:i') ?><?= $ta['geprueft_am'] ? ' · geprüft ' . plattformDatum($ta['geprueft_am']) : '' ?><?= $ta['freigegeben_am'] ? ' · freigegeben ' . plattformDatum($ta['freigegeben_am']) : '' ?><?= $ta['bezahlt_am'] ? ' · bezahlt ' . plattformDatum($ta['bezahlt_am']) : '' ?>
        · <?= moneyFormat($ta['betrag']) ?><?= $ta['auszahlungsart'] === 'prae' ? ' (als PRAE)' : '' ?>
    </div>
    <?php endif; ?>
</div>

<div class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Nächste Einheiten</h2><a href="<?= APP_URL ?>/dashboard/kalender.php?ansicht=woche&t=meine" class="btn btn-ghost-light btn-sm">Kalender</a></div>
    <?php if (!$naechste): ?><div style="padding: 1.25rem;" class="ze-mini">Keine geplanten Einheiten.</div><?php endif; ?>
    <?php foreach ($naechste as $n): ?>
    <div class="ze-zeile"><div><a class="text-primary" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $n['id'] ?>"><?= e($n['titel']) ?></a><div class="ze-mini"><?= date('d.m.Y, H:i', strtotime($n['start'])) ?>–<?= date('H:i', strtotime($n['ende'])) ?><?= $n['ort'] ? ' · ' . e($n['ort']) : '' ?></div></div>
        <span class="badge" style="background: color-mix(in srgb, <?= (EINHEIT_TYPEN[$n['typ']] ?? EINHEIT_TYPEN['sonstiges'])['farbe'] ?> 18%, transparent); color: var(--text-primary);"><?= e((EINHEIT_TYPEN[$n['typ']] ?? EINHEIT_TYPEN['sonstiges'])['label']) ?></span></div>
    <?php endforeach; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
