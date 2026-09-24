<?php
/**
 * Athletikclub Steiermark – PRAE-Abrechnung (Admin)
 * Monatsübersicht aller Empfänger:innen, Freigabe und Auszahlung (inkl. SEPA-Datei),
 * Vereinsdaten für die L19-Meldung.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/prae.php';

requireAdmin();

$db     = getDB();
$org_id = currentOrgId();
$errors = [];

$jahr  = max(2023, min(2100, (int)($_GET['jahr'] ?? date('Y'))));
$monat = max(1, min(12, (int)($_GET['monat'] ?? date('n'))));
$self  = APP_URL . "/dashboard/admin/prae.php?jahr={$jahr}&monat={$monat}";
$einst = praeEinstellungen($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'einstellungen') {
        $zvr = preg_replace('/\D+/', '', $_POST['zvr'] ?? '');
        $stn = preg_replace('/\D+/', '', $_POST['steuernummer'] ?? '');
        $iban = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        if ($f = praeZvrFehler($zvr)) $errors['zvr'] = $f;
        if ($stn !== '' && ($f = praeStnrFehler($stn))) $errors['steuernummer'] = $f;
        if ($iban !== '' && !praeIbanGueltig($iban)) $errors['iban'] = 'Die IBAN des Vereins ist ungültig.';
        $tagessatz = moneyRound(str_replace(',', '.', $_POST['tagessatz'] ?? '0') ?: '0');
        if (bccomp($tagessatz, '0', 2) <= 0 || bccomp($tagessatz, PRAE_TAG_MAX, 2) > 0) $errors['tagessatz'] = 'Der Standard-Tagessatz muss zwischen 0,01 € und 120 € liegen.';
        if (empty($errors)) {
            $werte = [mb_substr(trim($_POST['vereinsname'] ?? '') ?: APP_NAME, 0, 200), str_pad($zvr, 10, '0', STR_PAD_LEFT), $stn ?: null,
                      mb_substr(trim($_POST['strasse'] ?? ''), 0, 200) ?: null, mb_substr(trim($_POST['plz'] ?? ''), 0, 10) ?: null,
                      mb_substr(trim($_POST['ort'] ?? ''), 0, 100) ?: null, 'AT', $tagessatz, $iban ?: null,
                      strtoupper(trim($_POST['bic'] ?? '')) ?: null, mb_substr(trim($_POST['verantwortlich'] ?? ''), 0, 150) ?: null];
            $stmt = $db->prepare('SELECT COUNT(*) FROM prae_einstellungen WHERE organization_id = ?');
            $stmt->execute([$org_id]);
            if ($stmt->fetchColumn()) {
                $db->prepare('UPDATE prae_einstellungen SET vereinsname = ?, zvr = ?, steuernummer = ?, strasse = ?, plz = ?, ort = ?, land = ?, tagessatz = ?, iban = ?, bic = ?, verantwortlich = ? WHERE organization_id = ?')
                   ->execute(array_merge($werte, [$org_id]));
            } else {
                $db->prepare('INSERT INTO prae_einstellungen (vereinsname, zvr, steuernummer, strasse, plz, ort, land, tagessatz, iban, bic, verantwortlich, organization_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute(array_merge($werte, [$org_id]));
            }
            flashMessage('success', 'Vereinsdaten gespeichert.');
            redirect($self . '#einstellungen');
        }
    }

    if ($action === 'aktualisieren') {
        $stmt = $db->prepare('SELECT DISTINCT empfaenger_id FROM prae_einsaetze WHERE organization_id = ? AND datum BETWEEN ? AND ?');
        $von = sprintf('%04d-%02d-01', $jahr, $monat);
        $stmt->execute([$org_id, $von, date('Y-m-t', strtotime($von))]);
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eid) if (praeAbrechnungAktualisieren($db, (int)$eid, $jahr, $monat)) $n++;
        flashMessage('success', "{$n} Monatsabrechnung(en) für " . PRAE_MONATE[$monat] . " {$jahr} erstellt bzw. aktualisiert.");
        redirect($self);
    }

    if ($action === 'status') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $ziel = $_POST['ziel'] ?? '';
        $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ausgezahlt_am'] ?? '') ? $_POST['ausgezahlt_am'] : date('Y-m-d');
        $zahlungsart = ($_POST['zahlungsart'] ?? '') === 'bar' ? 'bar' : 'ueberweisung';
        $n = 0;
        foreach ($ids as $aid) {
            $stmt = $db->prepare('SELECT status FROM prae_abrechnungen WHERE id = ? AND organization_id = ?');
            $stmt->execute([$aid, $org_id]);
            $status = $stmt->fetchColumn();
            if ($ziel === 'freigegeben' && $status === 'entwurf') {
                $db->prepare("UPDATE prae_abrechnungen SET status = 'freigegeben', freigegeben_von = ? WHERE id = ?")->execute([getCurrentUserId(), $aid]);
                $n++;
            } elseif ($ziel === 'ausbezahlt' && $status === 'freigegeben') {
                $db->prepare("UPDATE prae_abrechnungen SET status = 'ausbezahlt', ausgezahlt_am = ?, zahlungsart = ? WHERE id = ?")->execute([$datum, $zahlungsart, $aid]);
                $n++;
            }
        }
        logActivity('prae_status', "{$ziel}: {$n} Abrechnungen");
        flashMessage($n ? 'success' : 'error', $n ? "{$n} Abrechnung(en) " . ($ziel === 'ausbezahlt' ? 'als ausbezahlt markiert.' : 'freigegeben.') : 'Keine passende Abrechnung ausgewählt (Freigabe nur aus „Entwurf“, Auszahlung nur aus „Freigegeben“).');
        redirect($self);
    }

    if ($action === 'sepa') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        if (!praeIbanGueltig($einst['iban'] ?? '')) {
            flashMessage('error', 'Für die SEPA-Datei bitte zuerst die IBAN des Vereinskontos in den Vereinsdaten eintragen.');
            redirect($self . '#einstellungen');
        }
        $zahlungen = $ohne_iban = [];
        foreach ($ids as $aid) {
            $stmt = $db->prepare("SELECT a.*, e.vorname, e.nachname, e.iban FROM prae_abrechnungen a JOIN prae_empfaenger e ON e.id = a.empfaenger_id
                                  WHERE a.id = ? AND a.organization_id = ? AND a.status = 'freigegeben'");
            $stmt->execute([$aid, $org_id]);
            if (!$a = $stmt->fetch()) continue;
            if (!praeIbanGueltig($a['iban'])) { $ohne_iban[] = praeName($a); continue; }
            $zahlungen[] = ['id' => 'PRAE-' . $a['jahr'] . sprintf('%02d', $a['monat']) . '-' . $a['empfaenger_id'], 'name' => praeName($a), 'iban' => $a['iban'],
                            'betrag' => $a['betrag_gesamt'], 'zweck' => 'PRAE ' . PRAE_MONATE[(int)$a['monat']] . ' ' . $a['jahr'] . ' - ' . $a['einsatztage'] . ' Einsatztage'];
        }
        if (!$zahlungen) {
            flashMessage('error', 'Keine freigegebene Abrechnung mit gültiger IBAN ausgewählt.' . ($ohne_iban ? ' Ohne IBAN: ' . implode(', ', $ohne_iban) : ''));
            redirect($self);
        }
        $ausfuehrung = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ausgezahlt_am'] ?? '') && $_POST['ausgezahlt_am'] >= date('Y-m-d') ? $_POST['ausgezahlt_am'] : date('Y-m-d');
        logActivity('prae_sepa', count($zahlungen) . ' Zahlungen');
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="PRAE_SEPA_' . $jahr . '-' . sprintf('%02d', $monat) . '.xml"');
        echo praeSepaXml($einst, $zahlungen, $ausfuehrung);
        exit;
    }
}

// ----------------------------------------------------------------
// Daten
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM prae_empfaenger WHERE organization_id = ? ORDER BY aktiv DESC, nachname, vorname');
$stmt->execute([$org_id]);
$empfaenger = $stmt->fetchAll();

$von = sprintf('%04d-%02d-01', $jahr, $monat);
$bis = date('Y-m-t', strtotime($von));
$stmt = $db->prepare('SELECT empfaenger_id, COUNT(*) AS tage, SUM(betrag) AS summe FROM prae_einsaetze WHERE organization_id = ? AND datum BETWEEN ? AND ? GROUP BY empfaenger_id');
$stmt->execute([$org_id, $von, $bis]);
$einsatz_summen = [];
foreach ($stmt->fetchAll() as $r) $einsatz_summen[(int)$r['empfaenger_id']] = $r;
$stmt = $db->prepare('SELECT * FROM prae_abrechnungen WHERE organization_id = ? AND jahr = ? AND monat = ?');
$stmt->execute([$org_id, $jahr, $monat]);
$abrechnungen = [];
foreach ($stmt->fetchAll() as $a) $abrechnungen[(int)$a['empfaenger_id']] = $a;

$stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'ausbezahlt' THEN betrag_steuerfrei ELSE 0 END), 0) AS ausbezahlt,
                             COALESCE(SUM(CASE WHEN status <> 'ausbezahlt' THEN betrag_gesamt ELSE 0 END), 0) AS offen_betrag,
                             SUM(CASE WHEN status <> 'ausbezahlt' THEN 1 ELSE 0 END) AS offen,
                             SUM(CASE WHEN betrag_ueberschuss > 0 THEN 1 ELSE 0 END) AS ueber
                      FROM prae_abrechnungen WHERE organization_id = ? AND jahr = ?");
$stmt->execute([$org_id, $jahr]);
$kpi = $stmt->fetch();

$einst_hinweise = array_filter([
    praeZvrFehler($einst['zvr']),
    $einst['steuernummer'] ? praeStnrFehler($einst['steuernummer']) : null,
    !$einst['iban'] ? 'Keine Vereins-IBAN hinterlegt – SEPA-Datei nicht möglich.' : null,
]);
$meldejahr = (int)date('n') <= 2 ? (int)date('Y') - 1 : null;
$form = array_merge($einst, ($_POST['action'] ?? '') === 'einstellungen' ? $_POST : []);
$v = fn($wert) => e((string)($wert ?? ''));
$monat_url = fn(int $j, int $m) => APP_URL . "/dashboard/admin/prae.php?jahr={$j}&monat={$m}";
$vor  = $monat === 1 ? [$jahr - 1, 12] : [$jahr, $monat - 1];
$nach = $monat === 12 ? [$jahr + 1, 1] : [$jahr, $monat + 1];

$page_title = 'PRAE-Abrechnung';
$breadcrumb = 'PRAE-Abrechnung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.prae-hinweis { font-size: 0.75rem; line-height: 1.4; }
.prae-hinweis.fehler { color: var(--danger); } .prae-hinweis.warnung { color: #B7791F; }
:root[data-theme="dark"] .prae-hinweis.warnung { color: #FBBF5A; }
.prae-leiste { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.prae-mini { font-size: 0.75rem; color: var(--text-muted); }
.prae-box { border-radius: 0.75rem; padding: 0.9rem 1.1rem; margin-bottom: 1.25rem; font-size: 0.9rem; background: var(--gold-dim); border-left: 4px solid var(--gold-accent); }
.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">PRAE-Abrechnung</h1>
        <p class="dashboard-subtitle">Pauschale Reiseaufwandsentschädigung nach § 3 Abs. 1 Z 16c EStG: Einsatztage erfassen, monatlich abrechnen, auszahlen und jährlich per L19 an das Finanzamt melden.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="<?= APP_URL ?>/dashboard/admin/prae-meldung.php?jahr=<?= $meldejahr ?? $jahr ?>" class="btn btn-primary btn-sm">Jahresmeldung L19</a>
        <a href="<?= APP_URL ?>/dashboard/admin/prae-empfaenger.php?neu=1" class="btn btn-navy btn-sm">+ Empfänger:in</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<?php if ($meldejahr): ?>
<div class="prae-box">
    <strong>Meldefrist:</strong> Die PRAE <?= $meldejahr ?> müssen bis <strong><?= date('d.m.Y', strtotime(praeMeldefrist($meldejahr))) ?></strong> per L19 über ELDA an das Finanzamt gemeldet werden.
    <a href="<?= APP_URL ?>/dashboard/admin/prae-meldung.php?jahr=<?= $meldejahr ?>">Zur Jahresmeldung <?= $meldejahr ?> →</a>
</div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value"><?= moneyFormat($kpi['ausbezahlt'] ?? 0) ?></div><div class="kpi-label">Ausbezahlt <?= $jahr ?> (steuerfrei)</div></div>
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value"><?= moneyFormat($kpi['offen_betrag'] ?? 0) ?></div><div class="kpi-label"><?= (int)($kpi['offen'] ?? 0) ?> offene Abrechnung(en)</div></div>
    <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value"><?= count(array_filter($empfaenger, fn($e) => (int)$e['aktiv'])) ?></div><div class="kpi-label">Aktive Empfänger:innen</div></div>
    <div class="kpi-card" style="--kpi-color: #EF4444;"><div class="kpi-value"><?= (int)($kpi['ueber'] ?? 0) ?></div><div class="kpi-label">Monate über Höchstbetrag</div></div>
</div>

<!-- Monatsübersicht -->
<form method="POST" class="table-card" style="margin-bottom: 1.5rem;" id="monat">
    <?= csrfField() ?>
    <div class="table-card-header">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <a href="<?= $monat_url(...$vor) ?>" class="btn btn-ghost-light btn-sm" aria-label="Vormonat">‹</a>
            <h2 class="table-card-title" style="min-width: 9rem; text-align: center;"><?= PRAE_MONATE[$monat] ?> <?= $jahr ?></h2>
            <a href="<?= $monat_url(...$nach) ?>" class="btn btn-ghost-light btn-sm" aria-label="Folgemonat">›</a>
        </div>
        <button type="submit" name="action" value="aktualisieren" class="btn btn-ghost-light btn-sm" formnovalidate>Abrechnungen aus Einsätzen erstellen</button>
    </div>
    <?php if (!$empfaenger): ?>
        <div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Noch keine Empfänger:innen</h3><p>Lege Trainer:innen, Übungsleiter:innen oder Sportler:innen an, die PRAE erhalten.</p>
            <a href="<?= APP_URL ?>/dashboard/admin/prae-empfaenger.php?neu=1" class="btn btn-navy btn-sm">+ Empfänger:in anlegen</a></div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th style="width: 2rem;"><input type="checkbox" aria-label="Alle auswählen" onclick="document.querySelectorAll('.prae-auswahl').forEach(c => c.checked = this.checked)"></th>
                <th>Empfänger:in</th><th class="zahl">Einsatztage</th><th class="zahl">Betrag</th><th class="zahl">steuerfrei</th><th>Status</th><th>Hinweise</th></tr></thead>
            <tbody>
                <?php foreach ($empfaenger as $e):
                    $eid = (int)$e['id'];
                    $a = $abrechnungen[$eid] ?? null;
                    $s = $einsatz_summen[$eid] ?? null;
                    if (!(int)$e['aktiv'] && !$a && !$s) continue;
                    $hinweise = praeEmpfaengerHinweise($e);
                    if ($a && bccomp(moneyRound($a['betrag_ueberschuss']), '0', 2) > 0) $hinweise[] = ['typ' => 'fehler', 'text' => moneyFormat($a['betrag_ueberschuss']) . ' über dem Höchstbetrag – steuerpflichtig (Lohnverrechnung).'];
                    if ($s && !$a) $hinweise[] = ['typ' => 'warnung', 'text' => 'Einsätze erfasst, aber noch keine Abrechnung erstellt.'];
                    if ($a && $s && (int)$s['tage'] !== (int)$a['einsatztage']) $hinweise[] = ['typ' => 'warnung', 'text' => 'Einsätze geändert – Abrechnung aktualisieren.'];
                ?>
                <tr>
                    <td><?php if ($a && $a['status'] !== 'ausbezahlt'): ?><input type="checkbox" class="prae-auswahl" name="ids[]" value="<?= (int)$a['id'] ?>" aria-label="<?= e(praeName($e)) ?> auswählen"><?php endif; ?></td>
                    <td><a class="text-primary" href="<?= APP_URL ?>/dashboard/admin/prae-empfaenger.php?id=<?= $eid ?>&jahr=<?= $jahr ?>&monat=<?= $monat ?>"><?= e(praeName($e)) ?></a>
                        <div class="prae-mini"><?= e(PRAE_ROLLEN[$e['rolle']] ?? $e['rolle']) ?> · <?= moneyFormat(praeTagessatz($e, $einst)) ?>/Tag<?= (int)$e['aktiv'] ? '' : ' · inaktiv' ?></div></td>
                    <td class="zahl"><?= $a ? (int)$a['einsatztage'] : (int)($s['tage'] ?? 0) ?></td>
                    <td class="zahl"><?= $a ? moneyFormat($a['betrag_gesamt']) : ($s ? moneyFormat($s['summe']) : '–') ?></td>
                    <td class="zahl"><?= $a ? moneyFormat($a['betrag_steuerfrei']) : '–' ?></td>
                    <td>
                        <?php if ($a): ?><span class="badge <?= PRAE_STATUS[$a['status']]['class'] ?>"><?= PRAE_STATUS[$a['status']]['label'] ?></span>
                            <?php if ($a['bestaetigt_am']): ?><div class="prae-mini">bestätigt <?= date('d.m.', strtotime($a['bestaetigt_am'])) ?></div><?php endif; ?>
                            <?php if ($a['ausgezahlt_am']): ?><div class="prae-mini"><?= date('d.m.Y', strtotime($a['ausgezahlt_am'])) ?> · <?= $a['zahlungsart'] === 'bar' ? 'bar' : 'Überweisung' ?></div><?php endif; ?>
                        <?php else: ?><span class="prae-mini">–</span><?php endif; ?>
                    </td>
                    <td><?php foreach ($hinweise as $h): ?><div class="prae-hinweis <?= $h['typ'] ?>"><?= $h['typ'] === 'fehler' ? '✕' : '!' ?> <?= e($h['text']) ?></div><?php endforeach; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="prae-leiste">
        <span class="prae-mini">Ausgewählte:</span>
        <button type="submit" name="action" value="status" onclick="this.form.ziel.value='freigegeben'" class="btn btn-ghost-light btn-sm">Freigeben</button>
        <input type="hidden" name="ziel" value="">
        <input class="form-control" type="date" name="ausgezahlt_am" value="<?= date('Y-m-d') ?>" style="max-width: 160px;" aria-label="Auszahlungsdatum">
        <select class="form-control" name="zahlungsart" style="max-width: 150px;" aria-label="Zahlungsart"><option value="ueberweisung">Überweisung</option><option value="bar">Bar</option></select>
        <button type="submit" name="action" value="status" onclick="this.form.ziel.value='ausbezahlt'" class="btn btn-ghost-light btn-sm">Als ausbezahlt markieren</button>
        <button type="submit" name="action" value="sepa" class="btn btn-ghost-light btn-sm" title="Sammelüberweisung für freigegebene Abrechnungen (pain.001) – im Online-Banking importieren">SEPA-Datei</button>
        <a href="<?= APP_URL ?>/dashboard/admin/prae-pdf.php?jahr=<?= $jahr ?>&monat=<?= $monat ?>" class="btn btn-ghost-light btn-sm" target="_blank">Alle Abrechnungen als PDF</a>
    </div>
    <?php endif; ?>
</form>

<div class="grid-2" style="align-items: start;">
    <!-- Vereinsdaten -->
    <div class="table-card" id="einstellungen">
        <div class="table-card-header"><h2 class="table-card-title">Vereinsdaten für L19 &amp; SEPA</h2></div>
        <div style="padding: 1.25rem;">
            <?php foreach ($einst_hinweise as $h): ?><div class="prae-hinweis warnung" style="margin-bottom: 0.4rem;">! <?= e($h) ?></div><?php endforeach; ?>
            <form method="POST" action="<?= $self ?>#einstellungen">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="einstellungen">
                <div class="form-group"><label class="form-label">Vereinsname</label><input class="form-control" type="text" name="vereinsname" maxlength="200" value="<?= $v($form['vereinsname']) ?>"></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">ZVR-Zahl <span class="required">*</span></label><input class="form-control <?= isset($errors['zvr']) ? 'error' : '' ?>" type="text" name="zvr" inputmode="numeric" maxlength="10" value="<?= $v($form['zvr']) ?>"></div>
                    <div class="form-group"><label class="form-label">Steuernummer (falls vorhanden)</label><input class="form-control <?= isset($errors['steuernummer']) ? 'error' : '' ?>" type="text" name="steuernummer" maxlength="12" value="<?= $v($form['steuernummer']) ?>" placeholder="z.B. 68 123/4567"></div>
                </div>
                <div class="form-group"><label class="form-label">Straße und Hausnummer</label><input class="form-control" type="text" name="strasse" maxlength="200" value="<?= $v($form['strasse']) ?>"></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">PLZ</label><input class="form-control" type="text" name="plz" maxlength="10" value="<?= $v($form['plz']) ?>"></div>
                    <div class="form-group"><label class="form-label">Ort</label><input class="form-control" type="text" name="ort" maxlength="100" value="<?= $v($form['ort']) ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Standard-Tagessatz (€)</label><input class="form-control <?= isset($errors['tagessatz']) ? 'error' : '' ?>" type="text" inputmode="decimal" name="tagessatz" value="<?= $v(number_format((float)$form['tagessatz'], 2, ',', '')) ?>"><p class="form-hint">Höchstens 120 € je Einsatztag.</p></div>
                    <div class="form-group"><label class="form-label">Unterzeichnet von</label><input class="form-control" type="text" name="verantwortlich" maxlength="150" value="<?= $v($form['verantwortlich']) ?>" placeholder="z.B. Kassier:in"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">IBAN Vereinskonto</label><input class="form-control <?= isset($errors['iban']) ? 'error' : '' ?>" type="text" name="iban" maxlength="42" value="<?= $v($form['iban'] ? praeIbanFormat($form['iban']) : '') ?>"></div>
                    <div class="form-group"><label class="form-label">BIC (optional)</label><input class="form-control" type="text" name="bic" maxlength="11" value="<?= $v($form['bic']) ?>"></div>
                </div>
                <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
            </form>
        </div>
    </div>

    <!-- Regeln -->
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Die wichtigsten Regeln</h2></div>
        <div style="padding: 1.25rem; font-size: 0.87rem; line-height: 1.65;">
            <ul style="margin: 0; padding-left: 1.1rem; list-style: disc;">
                <li><strong>Höchstbeträge:</strong> 120 € je Einsatztag, 720 € je Kalendermonat – steuer- und sozialversicherungsfrei.</li>
                <li><strong>Einsatztag:</strong> Tag mit tatsächlicher sportlicher Aktivität für den Verein (Training, Wettkampf, Fortbildung mit aktiver Betätigung) – unabhängig von der Dauer.</li>
                <li><strong>Wer:</strong> Sportler:innen, Trainer:innen, Übungsleiter:innen, Sportbetreuer:innen, Schieds-/Kampfrichter:innen. <em>Nicht</em> für Funktionär:innen (Obmann, Kassier …).</li>
                <li><strong>Nicht Hauptberuf</strong> und nicht Haupteinnahmequelle (Sozialversicherung). Studium und Haushaltsführung gelten als Hauptberuf, Pension und AMS-Bezug nicht.</li>
                <li><strong>Nicht kombinierbar</strong> im selben Monat mit Kilometergeld, Tages-/Nächtigungsgeldern oder dem Freiwilligenpauschale. Vom Verein bezahlte Bus- oder Bahntickets sind unschädlich.</li>
                <li><strong>Über dem Höchstbetrag:</strong> nur der Überschuss ist steuerpflichtig → Lohnverrechnung, Lohnzettel L16, ggf. ÖGK-Anmeldung vor Arbeitsantritt.</li>
                <li><strong>Meldung:</strong> Jahressumme je Person per L19 über ELDA bis Ende Februar des Folgejahres. Aufzeichnungen 7 Jahre aufbewahren.</li>
            </ul>
            <p class="prae-mini" style="margin: 0.75rem 0 0;">Quellen: BMF-Leitfaden „Sportler/innen-Begünstigung“ (10/2023), <a href="https://sportunion.at/service/vereinsfinanzen/abrechnung/prae/" target="_blank" rel="noopener">SPORTUNION – PRAE</a>, ELDA-Prüfkatalog L19. Keine Steuerberatung – im Zweifel Steuerberater:in fragen.</p>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
