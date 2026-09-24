<?php
/**
 * Athletikclub Steiermark – Meine PRAE (Selbstservice für Empfänger:innen)
 * Eigene Einsatztage erfassen, Monatsabrechnungen ansehen und bestätigen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/prae.php';

requireLogin();

$db     = getDB();
$errors = [];
$stmt = $db->prepare('SELECT * FROM prae_empfaenger WHERE user_id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([getCurrentUserId(), currentOrgId()]);
$empf = $stmt->fetch() ?: null;
$einst = praeEinstellungen($db);

$jahr  = max(2023, min(2100, (int)($_GET['jahr'] ?? date('Y'))));
$monat = max(1, min(12, (int)($_GET['monat'] ?? date('n'))));
$self  = APP_URL . "/dashboard/prae-meine.php?jahr={$jahr}&monat={$monat}";

$abrechnung = function () use ($db, &$empf, $jahr, $monat) {
    $stmt = $db->prepare('SELECT * FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? AND monat = ?');
    $stmt->execute([$empf['id'], $jahr, $monat]);
    return $stmt->fetch() ?: null;
};

if ($empf && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int)$empf['id'];

    if ($action === 'einsatz_neu' && (int)$empf['aktiv']) {
        $datum = $_POST['datum'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || !strtotime($datum) || $datum > date('Y-m-d')) {
            $errors['datum'] = 'Bitte ein gültiges Datum (heute oder früher) angeben.';
        } else {
            $stmt = $db->prepare('SELECT status FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? AND monat = ?');
            $stmt->execute([$id, (int)substr($datum, 0, 4), (int)substr($datum, 5, 2)]);
            $status = $stmt->fetchColumn();
            if ($status && $status !== 'entwurf') $errors['datum'] = 'Dieser Monat ist bereits freigegeben – Änderungen bitte über die Vereinsleitung.';
            $stmt = $db->prepare('SELECT COUNT(*) FROM prae_einsaetze WHERE empfaenger_id = ? AND datum = ?');
            $stmt->execute([$id, $datum]);
            if ($stmt->fetchColumn()) $errors['datum'] = 'Für diesen Tag ist bereits ein Einsatztag erfasst.';
        }
        if (empty($errors)) {
            $db->prepare('INSERT INTO prae_einsaetze (organization_id, empfaenger_id, datum, art, beschreibung, betrag, erfasst_von) VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([currentOrgId(), $id, $datum, isset(PRAE_ARTEN[$_POST['art'] ?? '']) ? $_POST['art'] : 'training',
                          mb_substr(trim($_POST['beschreibung'] ?? ''), 0, 255) ?: null, praeTagessatz($empf, $einst), getCurrentUserId()]);
            praeAbrechnungAktualisieren($db, $id, (int)substr($datum, 0, 4), (int)substr($datum, 5, 2));
            flashMessage('success', 'Einsatztag eingetragen – die Vereinsleitung gibt die Abrechnung frei.');
            redirect(APP_URL . '/dashboard/prae-meine.php?jahr=' . substr($datum, 0, 4) . '&monat=' . (int)substr($datum, 5, 2));
        }
    }

    if ($action === 'einsatz_loeschen') {
        $stmt = $db->prepare('SELECT e.datum, a.status FROM prae_einsaetze e LEFT JOIN prae_abrechnungen a ON a.id = e.abrechnung_id WHERE e.id = ? AND e.empfaenger_id = ? AND e.erfasst_von = ?');
        $stmt->execute([(int)($_POST['einsatz_id'] ?? 0), $id, getCurrentUserId()]);
        $r = $stmt->fetch();
        if ($r && (!$r['status'] || $r['status'] === 'entwurf')) {
            $db->prepare('DELETE FROM prae_einsaetze WHERE id = ?')->execute([(int)$_POST['einsatz_id']]);
            praeAbrechnungAktualisieren($db, $id, (int)substr($r['datum'], 0, 4), (int)substr($r['datum'], 5, 2));
        }
        redirect($self);
    }

    if ($action === 'bestaetigen') {
        $a = $abrechnung();
        if ($a && in_array($a['status'], ['freigegeben', 'ausbezahlt'], true) && !$a['bestaetigt_am'] && !empty($_POST['erklaerung'])) {
            $db->prepare('UPDATE prae_abrechnungen SET bestaetigt_am = ?, bestaetigt_ip = ? WHERE id = ?')
               ->execute([date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR'] ?? null, $a['id']]);
            logActivity('prae_bestaetigt', "Abrechnung-ID: {$a['id']}");
            flashMessage('success', 'Danke – deine Abrechnung ist bestätigt.');
        } else {
            flashMessage('error', 'Bitte die Erklärung anhaken, um die Abrechnung zu bestätigen.');
        }
        redirect($self);
    }
}

$einsaetze = $empf ? praeEinsaetze($db, (int)$empf['id'], $jahr, $monat) : [];
$b = praeMonatBerechnen($einsaetze);
$abr = $empf ? $abrechnung() : null;
$jahres = [];
if ($empf) {
    $stmt = $db->prepare('SELECT * FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? ORDER BY monat');
    $stmt->execute([$empf['id'], $jahr]);
    foreach ($stmt->fetchAll() as $a) $jahres[(int)$a['monat']] = $a;
}
$bearbeitbar = $empf && (int)$empf['aktiv'] && (!$abr || $abr['status'] === 'entwurf');
$vor  = $monat === 1 ? [$jahr - 1, 12] : [$jahr, $monat - 1];
$nach = $monat === 12 ? [$jahr + 1, 1] : [$jahr, $monat + 1];

$page_title = 'Meine PRAE';
$breadcrumb = 'Meine PRAE';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.prae-mini { font-size: 0.75rem; color: var(--text-muted); }
.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.prae-summe { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem; }
.prae-summe div { background: var(--bg-muted); border-radius: 0.75rem; padding: 0.7rem 0.9rem; }
.prae-summe strong { display: block; font-family: var(--font-heading); font-size: 1.15rem; }
.prae-check { display: flex; gap: 0.6rem; align-items: flex-start; font-size: 0.87rem; }
.prae-check input { margin-top: 0.25rem; flex-shrink: 0; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Meine PRAE</h1>
    <p class="dashboard-subtitle">Pauschale Reiseaufwandsentschädigung: deine Einsatztage und Monatsabrechnungen beim <?= e($einst['vereinsname']) ?>.</p>
</div>

<?php if (!$empf): ?>
<div class="table-card"><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine PRAE hinterlegt</h3><p>Du bist nicht als Empfänger:in einer pauschalen Reiseaufwandsentschädigung eingetragen. Bei Fragen wende dich an die Vereinsleitung.</p></div></div>
<?php else: ?>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.25rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <a href="?jahr=<?= $vor[0] ?>&monat=<?= $vor[1] ?>" class="btn btn-ghost-light btn-sm" aria-label="Vormonat">‹</a>
            <h2 class="table-card-title" style="min-width: 9rem; text-align: center;"><?= PRAE_MONATE[$monat] ?> <?= $jahr ?></h2>
            <a href="?jahr=<?= $nach[0] ?>&monat=<?= $nach[1] ?>" class="btn btn-ghost-light btn-sm" aria-label="Folgemonat">›</a>
        </div>
        <?php if ($abr): ?><span class="badge <?= PRAE_STATUS[$abr['status']]['class'] ?>"><?= PRAE_STATUS[$abr['status']]['label'] ?></span><?php endif; ?>
    </div>
    <div style="padding: 1.25rem 1.25rem 0.5rem;">
        <div class="prae-summe">
            <div><span class="prae-mini">Einsatztage</span><strong><?= $b['tage'] ?></strong></div>
            <div><span class="prae-mini">Betrag</span><strong><?= moneyFormat($b['gesamt']) ?></strong></div>
            <div><span class="prae-mini">Tagessatz</span><strong><?= moneyFormat(praeTagessatz($empf, $einst)) ?></strong></div>
        </div>
    </div>
    <?php if ($einsaetze): ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Datum</th><th>Art</th><th>Beschreibung</th><th class="zahl">Betrag</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($einsaetze as $es): ?>
                <tr>
                    <td><?= date('d.m.Y', strtotime($es['datum'])) ?></td>
                    <td><?= e(PRAE_ARTEN[$es['art']] ?? $es['art']) ?></td>
                    <td><?= e($es['beschreibung'] ?? '–') ?></td>
                    <td class="zahl"><?= moneyFormat($es['betrag']) ?></td>
                    <td><?php if ($bearbeitbar && (int)$es['erfasst_von'] === (int)getCurrentUserId()): ?><form method="POST" style="display: inline;"><?= csrfField() ?><input type="hidden" name="action" value="einsatz_loeschen"><input type="hidden" name="einsatz_id" value="<?= $es['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);" aria-label="Löschen">✕</button></form><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($bearbeitbar): ?>
    <form method="POST" action="<?= $self ?>" style="padding: 1.25rem; border-top: 1px solid var(--border-light); display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="einsatz_neu">
        <div class="form-group" style="margin: 0;"><label class="form-label">Datum</label><input class="form-control" type="date" name="datum" max="<?= date('Y-m-d') ?>" value="<?= e($_POST['datum'] ?? date('Y-m-d')) ?>" required></div>
        <div class="form-group" style="margin: 0;"><label class="form-label">Art</label><select class="form-control" name="art"><?php foreach (PRAE_ARTEN as $val => $label): ?><option value="<?= $val ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="margin: 0; flex: 1; min-width: 180px;"><label class="form-label">Was / wo</label><input class="form-control" type="text" name="beschreibung" maxlength="255" placeholder="z.B. Calisthenics Basics, Turnhalle"></div>
        <button type="submit" class="btn btn-navy btn-sm">Einsatztag eintragen</button>
    </form>
    <?php endif; ?>

    <?php if ($abr && in_array($abr['status'], ['freigegeben', 'ausbezahlt'], true)): ?>
    <div style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <?php if ($abr['bestaetigt_am']): ?>
            <p style="margin: 0 0 0.75rem;">✓ Von dir bestätigt am <?= date('d.m.Y, H:i', strtotime($abr['bestaetigt_am'])) ?> Uhr.<?= $abr['ausgezahlt_am'] ? ' Ausbezahlt am ' . date('d.m.Y', strtotime($abr['ausgezahlt_am'])) . '.' : '' ?></p>
        <?php else: ?>
            <form method="POST" action="<?= $self ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bestaetigen">
                <label class="prae-check" style="margin-bottom: 0.75rem;"><input type="checkbox" name="erklaerung" value="1" required>
                    <span>Ich bestätige die Richtigkeit dieser Abrechnung und erkläre:<br><?php foreach (PRAE_ERKLAERUNGEN as $t): ?>· <?= e($t) ?><br><?php endforeach; ?></span></label>
                <button type="submit" class="btn btn-navy btn-sm">Abrechnung bestätigen</button>
            </form>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/dashboard/admin/prae-pdf.php?abrechnung=<?= (int)$abr['id'] ?>" class="btn btn-ghost-light btn-sm" target="_blank" style="margin-top: 0.5rem;">Abrechnung als PDF</a>
        <p class="prae-mini" style="margin: 0.5rem 0 0;">Die Bestätigung im System ersetzt keine Unterschrift – der Verein kann zusätzlich eine unterschriebene PDF-Abrechnung anfordern.</p>
    </div>
    <?php elseif ($abr): ?>
    <p class="prae-mini" style="padding: 0 1.25rem 1.25rem; margin: 0;">Die Vereinsleitung prüft und gibt die Abrechnung frei – danach kannst du sie hier bestätigen.</p>
    <?php endif; ?>
</div>

<div class="grid-2" style="align-items: start;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Jahr <?= $jahr ?></h2></div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead><tr><th>Monat</th><th class="zahl">Tage</th><th class="zahl">Betrag</th><th>Status</th></tr></thead>
                <tbody>
                    <?php $summe = []; foreach ($jahres as $m => $a): if ($a['status'] === 'ausbezahlt') $summe[] = $a['betrag_steuerfrei']; ?>
                    <tr><td><a class="text-primary" href="?jahr=<?= $jahr ?>&monat=<?= $m ?>"><?= PRAE_MONATE[$m] ?></a></td><td class="zahl"><?= (int)$a['einsatztage'] ?></td><td class="zahl"><?= moneyFormat($a['betrag_gesamt']) ?></td>
                        <td><span class="badge <?= PRAE_STATUS[$a['status']]['class'] ?>"><?= PRAE_STATUS[$a['status']]['label'] ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$jahres): ?><tr><td colspan="4" class="prae-mini">Noch keine Abrechnungen in <?= $jahr ?>.</td></tr><?php endif; ?>
                    <tr><td><strong>Ausbezahlt</strong></td><td></td><td class="zahl"><strong><?= moneyFormat(moneySum($summe)) ?></strong></td><td></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Gut zu wissen</h2></div>
        <div style="padding: 1.25rem; font-size: 0.87rem; line-height: 1.65;">
            <ul style="margin: 0; padding-left: 1.1rem; list-style: disc;">
                <li>Bis 120 € je Einsatztag und 720 € im Monat sind steuer- und sozialversicherungsfrei.</li>
                <li>Ein Einsatztag ist ein Tag mit Training, Wettkampf oder aktiver Fortbildung für den Verein – egal wie lange.</li>
                <li>Der Verein meldet die Jahressumme bis Ende Februar an das Finanzamt (L19). Du musst dafür nichts tun.</li>
                <li>Bitte informiere die Vereinsleitung, wenn sich dein Hauptberuf, deine Adresse oder deine Bankverbindung ändert.</li>
            </ul>
            <p class="prae-mini" style="margin: 0.75rem 0 0;">Hinterlegt: <?= e(praeName($empf)) ?> · <?= e(PRAE_ROLLEN[$empf['rolle']] ?? '') ?><?= $empf['iban'] ? ' · IBAN ••••' . e(substr(preg_replace('/\s+/', '', $empf['iban']), -4)) : ' · keine IBAN' ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
