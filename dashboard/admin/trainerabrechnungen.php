<?php
/**
 * Athletikclub Steiermark – Trainerabrechnungen (Verwaltung)
 * Monatsabrechnungen aus bestätigten Einheiten: prüfen → freigeben → bezahlt.
 * Freigabe bucht die Kosten auf Projekt/Kurs/Förderung; optional Übernahme als PRAE.
 * Honorarsätze je Stunde oder Einheit, je Trainer:in, Projekt oder Kurs.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireDarf('abrechnung.anzeigen', 'abrechnung.bearbeiten', 'abrechnung.freigeben', 'abrechnung.abrechnen');

$db     = getDB();
$org_id = currentOrgId();
$jahr   = max(2024, min(2100, (int)($_GET['jahr'] ?? date('Y'))));
$monat  = max(1, min(12, (int)($_GET['monat'] ?? date('n'))));
$self   = APP_URL . "/dashboard/admin/trainerabrechnungen.php?jahr={$jahr}&monat={$monat}";
$detail = !empty($_GET['id']) ? trainerAbrechnungLaden($db, (int)$_GET['id']) : null;
if ($detail) { $jahr = (int)$detail['jahr']; $monat = (int)$detail['monat']; }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $ta = !empty($_POST['id']) ? trainerAbrechnungLaden($db, (int)$_POST['id']) : null;
    $zurueck = $ta ? APP_URL . '/dashboard/admin/trainerabrechnungen.php?id=' . $ta['id'] : $self;

    if ($action === 'erstellen' && darf('abrechnung.bearbeiten')) {
        $n = 0;
        foreach (array_map('intval', (array)($_POST['user_ids'] ?? [])) as $uid) if (trainerAbrechnungAktualisieren($db, $uid, $jahr, $monat)) $n++;
        flashMessage('success', "{$n} Abrechnung(en) erstellt bzw. aktualisiert.");
        redirect($self);
    }

    if ($ta && $action === 'status') {
        $ziel = $_POST['ziel'] ?? '';
        $recht = ['geprueft' => 'abrechnung.bearbeiten', 'entwurf' => 'abrechnung.bearbeiten', 'freigegeben' => 'abrechnung.freigeben', 'bezahlt' => 'abrechnung.abrechnen', 'eingereicht' => 'abrechnung.bearbeiten'][$ziel] ?? null;
        if (!$recht || !darf($recht)) { flashMessage('error', 'Dafür fehlt die Berechtigung.'); redirect($zurueck); }
        if ($ziel === 'freigegeben' && (int)$ta['user_id'] === (int)getCurrentUserId() && !isAdmin()) { flashMessage('error', 'Die eigene Abrechnung kann nicht selbst freigegeben werden.'); redirect($zurueck); }
        $fehler = trainerAbrechnungStatus($db, $ta, $ziel);
        flashMessage($fehler ? 'error' : 'success', $fehler ?? 'Status geändert: ' . TA_STATUS[$ziel]['label'] . '.');
        redirect($zurueck);
    }

    if ($ta && $action === 'auszahlungsart' && darf('abrechnung.bearbeiten') && in_array($ta['status'], ['entwurf', 'eingereicht', 'geprueft'], true)) {
        $art = ($_POST['auszahlungsart'] ?? '') === 'prae' ? 'prae' : 'honorar';
        $db->prepare('UPDATE trainer_abrechnungen SET auszahlungsart = ? WHERE id = ?')->execute([$art, $ta['id']]);
        auditLog('geaendert', 'trainer_abrechnungen', (int)$ta['id'], ['auszahlungsart' => $ta['auszahlungsart']], ['auszahlungsart' => $art]);
        redirect($zurueck);
    }

    if ($ta && $action === 'korrektur' && darf('abrechnung.bearbeiten') && in_array($ta['status'], ['entwurf', 'eingereicht', 'geprueft'], true)) {
        foreach ((array)($_POST['betrag'] ?? []) as $et_id => $wert) {
            $wert = trim(str_replace(',', '.', (string)$wert));
            if ($wert === '' || !is_numeric($wert) || (float)$wert < 0) continue;
            $stmt = $db->prepare('SELECT * FROM einheit_trainer WHERE id = ? AND abrechnung_id = ?');
            $stmt->execute([(int)$et_id, $ta['id']]);
            if (!$et = $stmt->fetch()) continue;
            if ($et['betrag'] !== null && bccomp(moneyRound($et['betrag']), moneyRound($wert), 2) === 0) continue;
            $db->prepare('UPDATE einheit_trainer SET betrag = ? WHERE id = ?')->execute([moneyRound($wert), $et['id']]);
            auditLog('geaendert', 'einheit_trainer', (int)$et['id'], ['betrag' => $et['betrag']], ['betrag' => moneyRound($wert)], 'Honorar korrigiert (Abrechnung ' . $ta['id'] . ')');
        }
        $stmt = $db->prepare('SELECT COALESCE(SUM(betrag), 0) FROM einheit_trainer WHERE abrechnung_id = ?');
        $stmt->execute([$ta['id']]);
        $neu = moneyRound($stmt->fetchColumn());
        $db->prepare('UPDATE trainer_abrechnungen SET betrag = ? WHERE id = ?')->execute([$neu, $ta['id']]);
        if (bccomp(moneyRound($ta['betrag']), $neu, 2) !== 0) auditLog('geaendert', 'trainer_abrechnungen', (int)$ta['id'], ['betrag' => $ta['betrag']], ['betrag' => $neu], 'Summe nach Korrektur');
        flashMessage('success', 'Honorare gespeichert. Summe: ' . moneyFormat($neu));
        redirect($zurueck);
    }

    if ($ta && $action === 'prae' && darf('abrechnung.abrechnen') && in_array($ta['status'], ['freigegeben', 'bezahlt'], true)) {
        $r = trainerAbrechnungAlsPrae($db, $ta);
        flashMessage(isset($r['fehler']) ? 'error' : 'success', $r['fehler'] ?? "{$r['neu']} Einsatztag(e) in die PRAE-Abrechnung übernommen" . ($r['uebersprungen'] ? " ({$r['uebersprungen']} bereits vorhanden bzw. Monat abgeschlossen)" : '') . '.');
        redirect($zurueck);
    }

    if ($action === 'satz_neu' && darf('abrechnung.bearbeiten')) {
        $betrag = trim(str_replace(',', '.', $_POST['betrag'] ?? ''));
        if (!is_numeric($betrag) || (float)$betrag <= 0) {
            flashMessage('error', 'Bitte einen Betrag angeben.');
        } else {
            $datum = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$f] ?? '') ? $_POST[$f] : null;
            $db->prepare('INSERT INTO honorar_saetze (organization_id, user_id, projekt_id, kurs_id, modell, betrag, gueltig_ab, gueltig_bis, notiz) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$org_id, (int)($_POST['user_id'] ?? 0) ?: null, (int)($_POST['projekt_id'] ?? 0) ?: null, (int)($_POST['kurs_id'] ?? 0) ?: null,
                          ($_POST['modell'] ?? '') === 'stunde' ? 'stunde' : 'einheit', moneyRound($betrag), $datum('gueltig_ab'), $datum('gueltig_bis'),
                          mb_substr(trim($_POST['notiz'] ?? ''), 0, 255) ?: null]);
            auditLog('erstellt', 'honorar_saetze', (int)$db->lastInsertId(), null, ['betrag' => moneyRound($betrag), 'modell' => $_POST['modell'] ?? 'einheit']);
            flashMessage('success', 'Honorarsatz gespeichert.');
        }
        redirect($self . '#saetze');
    }

    if ($action === 'satz_loeschen' && darf('abrechnung.bearbeiten')) {
        $db->prepare('DELETE FROM honorar_saetze WHERE id = ? AND organization_id = ?')->execute([(int)($_POST['satz_id'] ?? 0), $org_id]);
        auditLog('geloescht', 'honorar_saetze', (int)($_POST['satz_id'] ?? 0));
        redirect($self . '#saetze');
    }

    if ($action === 'nachberechnen' && darf('abrechnung.bearbeiten')) {
        // Bestätigte Einsätze ohne Betrag (z.B. vor Anlage des Satzes) neu berechnen
        $stmt = $db->prepare("SELECT et.*, e.projekt_id, e.kurs_id FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                              LEFT JOIN trainer_abrechnungen ta ON ta.id = et.abrechnung_id
                              WHERE e.organization_id = ? AND et.betrag IS NULL AND et.dauer_min IS NOT NULL AND (ta.id IS NULL OR ta.status IN ('entwurf','eingereicht','geprueft'))");
        $stmt->execute([$org_id]);
        $n = 0;
        foreach ($stmt->fetchAll() as $et) {
            $satz = honorarSatz($db, (int)$et['user_id'], $et['projekt_id'] ? (int)$et['projekt_id'] : null, $et['kurs_id'] ? (int)$et['kurs_id'] : null, substr($et['ist_start'], 0, 10));
            if (!$satz) continue;
            $db->prepare('UPDATE einheit_trainer SET honorar_modell = ?, honorar_satz = ?, betrag = ? WHERE id = ?')->execute([$satz['modell'], $satz['betrag'], honorarBetrag($satz, (int)$et['dauer_min']), $et['id']]);
            if ($et['abrechnung_id']) {
                $db->prepare('UPDATE trainer_abrechnungen SET betrag = (SELECT COALESCE(SUM(betrag), 0) FROM einheit_trainer WHERE abrechnung_id = ?) WHERE id = ?')->execute([$et['abrechnung_id'], $et['abrechnung_id']]);
            }
            $n++;
        }
        flashMessage('success', "{$n} Einsatz/Einsätze nachberechnet.");
        redirect($self . '#saetze');
    }
}

// ----------------------------------------------------------------
// Daten
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT ta.*, u.vorname, u.nachname FROM trainer_abrechnungen ta JOIN users u ON u.id = ta.user_id WHERE ta.organization_id = ? AND ta.jahr = ? AND ta.monat = ? ORDER BY u.nachname');
$stmt->execute([$org_id, $jahr, $monat]);
$abrechnungen = $stmt->fetchAll();
$von = sprintf('%04d-%02d-01', $jahr, $monat);
$stmt = $db->prepare("SELECT et.user_id, u.vorname, u.nachname, COUNT(*) AS n, COALESCE(SUM(et.betrag), 0) AS summe, SUM(CASE WHEN et.betrag IS NULL THEN 1 ELSE 0 END) AS ohne_satz
                      FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id JOIN users u ON u.id = et.user_id
                      WHERE e.organization_id = ? AND et.status = 'durchgefuehrt' AND et.abrechnung_id IS NULL AND COALESCE(et.ist_start, e.start) BETWEEN ? AND ?
                      GROUP BY et.user_id, u.vorname, u.nachname");
$stmt->execute([$org_id, $von . ' 00:00:00', date('Y-m-t', strtotime($von)) . ' 23:59:59']);
$ohne_abrechnung = $stmt->fetchAll();
$stmt = $db->prepare("SELECT COUNT(*) FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id WHERE e.organization_id = ? AND et.status = 'geplant' AND e.status <> 'storniert' AND e.ende < ? AND e.start >= ?");
$stmt->execute([$org_id, date('Y-m-d H:i:s'), $von . ' 00:00:00']);
$unbestaetigt = (int)$stmt->fetchColumn();

$zeilen = [];
if ($detail) {
    $stmt = $db->prepare('SELECT et.*, e.titel, e.start, e.typ, p.name AS projekt_name, k.titel AS kurs_titel, f.titel AS foerderung_titel FROM einheit_trainer et
                          JOIN einheiten e ON e.id = et.einheit_id LEFT JOIN projekte p ON p.id = e.projekt_id LEFT JOIN kurse k ON k.id = e.kurs_id LEFT JOIN foerderungen f ON f.id = p.foerderung_id
                          WHERE et.abrechnung_id = ? ORDER BY COALESCE(et.ist_start, e.start)');
    $stmt->execute([$detail['id']]);
    $zeilen = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT b.*, p.name AS projekt_name, f.titel AS foerderung_titel FROM buchungen b LEFT JOIN projekte p ON p.id = b.projekt_id LEFT JOIN foerderungen f ON f.id = b.foerderung_id WHERE b.trainer_abrechnung_id = ?');
    $stmt->execute([$detail['id']]);
    $buchungen = $stmt->fetchAll();
}

$stmt = $db->prepare('SELECT h.*, u.vorname, u.nachname, p.name AS projekt_name, k.titel AS kurs_titel FROM honorar_saetze h LEFT JOIN users u ON u.id = h.user_id
                      LEFT JOIN projekte p ON p.id = h.projekt_id LEFT JOIN kurse k ON k.id = h.kurs_id WHERE h.organization_id = ? ORDER BY h.user_id IS NULL, u.nachname, h.created_at DESC');
$stmt->execute([$org_id]);
$saetze = $stmt->fetchAll();
$trainer_liste = plattformTrainer($db);
$projekte = plattformProjekte($db, false);
$stmt = $db->prepare("SELECT id, titel FROM kurse WHERE organization_id = ? AND status IN ('geplant','aktiv') ORDER BY titel");
$stmt->execute([$org_id]);
$kurse = $stmt->fetchAll();

$monate = [1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$vor  = $monat === 1 ? [$jahr - 1, 12] : [$jahr, $monat - 1];
$nach = $monat === 12 ? [$jahr + 1, 1] : [$jahr, $monat + 1];
$summe = moneySum(array_column($abrechnungen, 'betrag'));

$page_title = 'Trainerabrechnungen';
$breadcrumb = 'Trainerabrechnungen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.ta-mini { font-size: 0.75rem; color: var(--text-muted); }
.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ta-aktionen { display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Trainerabrechnungen</h1>
    <p class="dashboard-subtitle">Bestätigte Einheiten × Honorar = Auszahlung. Ablauf: Trainer:in reicht ein → Verwaltung prüft → Freigabe (Kosten werden Projekt, Kurs und Förderung zugeordnet) → bezahlt.</p>
</div>

<?php if ($detail): ?>
<!-- Detail einer Abrechnung -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <div><h2 class="table-card-title"><?= e($detail['vorname'] . ' ' . $detail['nachname']) ?> · <?= $monate[(int)$detail['monat']] ?> <?= $detail['jahr'] ?></h2>
            <span class="ta-mini"><?= (int)$detail['anzahl_einheiten'] ?> Einheiten · <?= dauerText((int)$detail['minuten']) ?> · <?= $detail['auszahlungsart'] === 'prae' ? 'Auszahlung als PRAE' : 'Honorar' ?><?= $detail['prae_uebernommen_am'] ? ' · in PRAE übernommen ' . plattformDatum($detail['prae_uebernommen_am']) : '' ?></span></div>
        <div style="text-align: right;"><span class="badge <?= TA_STATUS[$detail['status']]['class'] ?>"><?= TA_STATUS[$detail['status']]['label'] ?></span><div style="font-family: var(--font-heading); font-weight: 800; font-size: 1.3rem;"><?= moneyFormat($detail['betrag']) ?></div></div>
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="korrektur"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
        <div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Datum</th><th>Einheit</th><th>Zuordnung</th><th class="zahl">Dauer</th><th class="zahl">Satz</th><th class="zahl">Honorar</th></tr></thead>
            <tbody><?php $korrigierbar = darf('abrechnung.bearbeiten') && in_array($detail['status'], ['entwurf', 'eingereicht', 'geprueft'], true); foreach ($zeilen as $z): ?>
                <tr>
                    <td><?= date('d.m.', strtotime($z['ist_start'] ?? $z['start'])) ?> <span class="ta-mini"><?= $z['ist_start'] ? date('H:i', strtotime($z['ist_start'])) . '–' . date('H:i', strtotime($z['ist_ende'])) : '' ?></span></td>
                    <td><a class="text-primary" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $z['einheit_id'] ?>"><?= e($z['titel']) ?></a><?php if ($z['notiz']): ?><div class="ta-mini"><?= e($z['notiz']) ?></div><?php endif; ?></td>
                    <td class="ta-mini"><?= e($z['projekt_name'] ?: ($z['kurs_titel'] ?: '–')) ?><?= $z['foerderung_titel'] ? '<br>Förderung: ' . e($z['foerderung_titel']) : '' ?></td>
                    <td class="zahl"><?= dauerText((int)$z['dauer_min']) ?></td>
                    <td class="zahl ta-mini"><?= $z['honorar_satz'] !== null ? moneyFormat($z['honorar_satz']) . ' / ' . ($z['honorar_modell'] === 'stunde' ? 'h' : 'Einheit') : '–' ?></td>
                    <td class="zahl"><?php if ($korrigierbar): ?><input class="form-control" type="text" inputmode="decimal" name="betrag[<?= $z['id'] ?>]" value="<?= $z['betrag'] !== null ? number_format((float)$z['betrag'], 2, ',', '') : '' ?>" style="max-width: 100px; text-align: right; <?= $z['betrag'] === null ? 'border-color: var(--danger);' : '' ?>" aria-label="Honorar"><?php else: ?><?= $z['betrag'] !== null ? moneyFormat($z['betrag']) : '–' ?><?php endif; ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($korrigierbar): ?><div style="padding: 0.75rem 1.25rem;"><button type="submit" class="btn btn-ghost-light btn-sm">Honorare speichern</button></div><?php endif; ?>
    </form>
    <div class="ta-aktionen" style="padding: 1rem 1.25rem; border-top: 1px solid var(--border-light);">
        <?php $knopf = fn($ziel, $text, $klasse = 'btn-navy') => '<form method="POST">' . csrfField() . '<input type="hidden" name="action" value="status"><input type="hidden" name="id" value="' . $detail['id'] . '"><input type="hidden" name="ziel" value="' . $ziel . '"><button type="submit" class="btn ' . $klasse . ' btn-sm">' . $text . '</button></form>'; ?>
        <?php if ($detail['status'] === 'entwurf' && darf('abrechnung.bearbeiten')): ?><?= $knopf('eingereicht', 'Im Namen einreichen', 'btn-ghost-light') ?><?php endif; ?>
        <?php if ($detail['status'] === 'eingereicht' && darf('abrechnung.bearbeiten')): ?><?= $knopf('geprueft', 'Als geprüft markieren') ?><?php endif; ?>
        <?php if (in_array($detail['status'], ['eingereicht', 'geprueft'], true) && darf('abrechnung.freigeben')): ?><?= $knopf('freigegeben', 'Freigeben') ?><?php endif; ?>
        <?php if (in_array($detail['status'], ['eingereicht', 'geprueft'], true) && darf('abrechnung.bearbeiten')): ?><?= $knopf('entwurf', 'Zurück an Trainer:in', 'btn-ghost-light') ?><?php endif; ?>
        <?php if ($detail['status'] === 'freigegeben' && darf('abrechnung.abrechnen')): ?><?= $knopf('bezahlt', 'Als bezahlt markieren') ?><?php endif; ?>
        <?php if (in_array($detail['status'], ['freigegeben', 'bezahlt'], true) && $detail['auszahlungsart'] === 'prae' && !$detail['prae_uebernommen_am'] && darf('abrechnung.abrechnen')): ?>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="prae"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm">In PRAE-Abrechnung übernehmen</button></form>
        <?php endif; ?>
        <?php if (in_array($detail['status'], ['entwurf', 'eingereicht', 'geprueft'], true) && darf('abrechnung.bearbeiten')): ?>
            <form method="POST" style="margin-left: auto; display: flex; gap: 0.4rem; align-items: center;"><?= csrfField() ?><input type="hidden" name="action" value="auszahlungsart"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
                <select class="form-control" name="auszahlungsart" onchange="this.form.submit()" aria-label="Auszahlungsart"><option value="honorar">Auszahlung als Honorar</option><option value="prae" <?= $detail['auszahlungsart'] === 'prae' ? 'selected' : '' ?>>Auszahlung als PRAE (steuerfrei, max. 120 €/Tag)</option></select></form>
        <?php endif; ?>
    </div>
    <?php if (!empty($buchungen)): ?>
    <div style="padding: 0 1.25rem 1.25rem;" class="ta-mini">
        <strong>Kostenbuchungen:</strong>
        <?php foreach ($buchungen as $b): ?><div>• <?= moneyFormat($b['betrag']) ?> → <?= e($b['projekt_name'] ?: 'ohne Projekt') ?><?= $b['foerderung_titel'] ? ' · Förderung „' . e($b['foerderung_titel']) . '“' : '' ?> (<?= $b['status'] === 'bezahlt' ? 'bezahlt' : 'offen' ?>)</div><?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<a href="<?= $self ?>" class="btn btn-ghost-light btn-sm" style="margin-bottom: 1.5rem;">← Alle Abrechnungen <?= $monate[$monat] ?> <?= $jahr ?></a>
<?php endif; ?>

<!-- Monatsübersicht -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <a href="?jahr=<?= $vor[0] ?>&monat=<?= $vor[1] ?>" class="btn btn-ghost-light btn-sm" aria-label="Vormonat">‹</a>
            <h2 class="table-card-title" style="min-width: 9rem; text-align: center;"><?= $monate[$monat] ?> <?= $jahr ?></h2>
            <a href="?jahr=<?= $nach[0] ?>&monat=<?= $nach[1] ?>" class="btn btn-ghost-light btn-sm" aria-label="Folgemonat">›</a>
        </div>
        <span class="ta-mini">Summe <?= moneyFormat($summe) ?><?= $unbestaetigt ? " · {$unbestaetigt} vergangene Einheit(en) noch unbestätigt" : '' ?></span>
    </div>
    <?php if (!$abrechnungen && !$ohne_abrechnung): ?>
        <div style="padding: 1.25rem;" class="ta-mini">Keine bestätigten Einheiten in diesem Monat.</div>
    <?php endif; ?>
    <?php if ($abrechnungen): ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Trainer:in</th><th class="zahl">Einheiten</th><th class="zahl">Stunden</th><th class="zahl">Betrag</th><th>Art</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($abrechnungen as $a): ?>
            <tr data-row-href="?id=<?= $a['id'] ?>">
                <td><a class="text-primary" href="?id=<?= $a['id'] ?>"><?= e($a['vorname'] . ' ' . $a['nachname']) ?></a></td>
                <td class="zahl"><?= (int)$a['anzahl_einheiten'] ?></td>
                <td class="zahl"><?= dauerText((int)$a['minuten']) ?></td>
                <td class="zahl"><strong><?= moneyFormat($a['betrag']) ?></strong></td>
                <td><?= $a['auszahlungsart'] === 'prae' ? 'PRAE' : 'Honorar' ?></td>
                <td><span class="badge <?= TA_STATUS[$a['status']]['class'] ?>"><?= TA_STATUS[$a['status']]['label'] ?></span></td>
                <td><a href="?id=<?= $a['id'] ?>" class="btn btn-ghost-light btn-sm">Öffnen</a></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($ohne_abrechnung && darf('abrechnung.bearbeiten')): ?>
    <form method="POST" style="padding: 1rem 1.25rem; border-top: 1px solid var(--border-light);">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="erstellen">
        <strong style="font-size: 0.88rem;">Bestätigte Einheiten ohne Abrechnung</strong>
        <?php foreach ($ohne_abrechnung as $o): ?>
        <label style="display: flex; gap: 0.5rem; align-items: center; font-size: 0.87rem; margin-top: 0.4rem;"><input type="checkbox" name="user_ids[]" value="<?= $o['user_id'] ?>" checked>
            <?= e($o['vorname'] . ' ' . $o['nachname']) ?> – <?= (int)$o['n'] ?> Einheit(en), <?= moneyFormat($o['summe']) ?><?= $o['ohne_satz'] ? ' <span style="color: var(--danger);">(' . (int)$o['ohne_satz'] . ' ohne Honorarsatz)</span>' : '' ?></label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-ghost-light btn-sm" style="margin-top: 0.6rem;">Abrechnungen erstellen</button>
    </form>
    <?php endif; ?>
</div>

<!-- Honorarsätze -->
<div class="table-card" id="saetze">
    <div class="table-card-header"><h2 class="table-card-title">Honorarsätze</h2><span class="ta-mini">Der spezifischste passende Satz gilt: Trainer:in + Kurs › Trainer:in + Projekt › Trainer:in › Kurs › Projekt › Standard</span></div>
    <?php if ($saetze): ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Gilt für</th><th class="zahl">Betrag</th><th>Gültig</th><th></th></tr></thead>
        <tbody><?php foreach ($saetze as $s): ?>
            <tr><td><?= e(implode(' · ', array_filter([$s['vorname'] ? $s['vorname'] . ' ' . $s['nachname'] : 'alle Trainer:innen', $s['projekt_name'] ? 'Projekt ' . $s['projekt_name'] : null, $s['kurs_titel'] ? 'Kurs ' . $s['kurs_titel'] : null]))) ?><?= $s['notiz'] ? '<div class="ta-mini">' . e($s['notiz']) . '</div>' : '' ?></td>
                <td class="zahl"><strong><?= moneyFormat($s['betrag']) ?></strong> / <?= $s['modell'] === 'stunde' ? 'Stunde' : 'Einheit' ?></td>
                <td class="ta-mini"><?= $s['gueltig_ab'] ? 'ab ' . plattformDatum($s['gueltig_ab']) : 'unbegrenzt' ?><?= $s['gueltig_bis'] ? ' bis ' . plattformDatum($s['gueltig_bis']) : '' ?></td>
                <td><?php if (darf('abrechnung.bearbeiten')): ?><form method="POST" onsubmit="return confirm('Honorarsatz löschen? Bereits bestätigte Einheiten behalten ihren Betrag.')"><?= csrfField() ?><input type="hidden" name="action" value="satz_loeschen"><input type="hidden" name="satz_id" value="<?= $s['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);" aria-label="Löschen">✕</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php if (darf('abrechnung.bearbeiten')): ?>
    <form method="POST" style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="satz_neu">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin: 0;"><label class="form-label">Trainer:in</label><select class="form-control" name="user_id"><option value="">alle</option><?php foreach ($trainer_liste as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">Projekt</label><select class="form-control" name="projekt_id"><option value="">alle</option><?php foreach ($projekte as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">Kurs</label><select class="form-control" name="kurs_id"><option value="">alle</option><?php foreach ($kurse as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['titel']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">Betrag (€)</label><input class="form-control" type="text" inputmode="decimal" name="betrag" required style="max-width: 100px;"></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">je</label><select class="form-control" name="modell"><option value="einheit">Einheit</option><option value="stunde">Stunde</option></select></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">Gültig ab</label><input class="form-control" type="date" name="gueltig_ab"></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">bis</label><input class="form-control" type="date" name="gueltig_bis"></div>
            <button type="submit" class="btn btn-navy btn-sm">Satz hinzufügen</button>
        </div>
    </form>
    <form method="POST" style="padding: 0 1.25rem 1.25rem;"><?= csrfField() ?><input type="hidden" name="action" value="nachberechnen"><button type="submit" class="btn btn-ghost-light btn-sm">Einsätze ohne Honorar nachberechnen</button> <span class="ta-mini">nach Anlage eines neuen Satzes</span></form>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
