<?php
/**
 * Athletikclub Steiermark – PRAE-Jahresmeldung L19 (Admin)
 *
 * Erzeugt je Empfänger:in eine L19-Mitteilung als XML nach dem BMF-Schema
 * (urn:bmf.gv.at:efsz:l19:202302), prüft sie gegen das offizielle XSD und
 * stellt sie für den Upload in ELDA bereit (einzeln oder als ZIP).
 * Korrekturen und Stornos verwenden dieselbe Referenznummer (ZVR + REFNR + Jahr).
 *
 * Die direkte Übermittlung an ELDA ist vorbereitet (gespeicherte XML, Status
 * „übermittelt“) – der Versand selbst erfolgt derzeit über ELDA Online.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/prae.php';

requireAdmin();

$db     = getDB();
$org_id = currentOrgId();
$jahr   = max(2023, min(2100, (int)($_GET['jahr'] ?? ((int)date('n') <= 2 ? (int)date('Y') - 1 : (int)date('Y')))));
$self   = APP_URL . '/dashboard/admin/prae-meldung.php?jahr=' . $jahr;
$einst  = praeEinstellungen($db);
$daten  = praeJahresdaten($db, $jahr);

/** Letzte Meldung je Empfänger:in in diesem Jahr. */
$letzte_meldungen = function () use ($db, $org_id, $jahr): array {
    $stmt = $db->prepare('SELECT * FROM prae_meldungen WHERE organization_id = ? AND jahr = ? ORDER BY id');
    $stmt->execute([$org_id, $jahr]);
    $letzte = [];
    foreach ($stmt->fetchAll() as $m) $letzte[(int)$m['empfaenger_id']] = $m;
    return $letzte;
};

// Einzelne gespeicherte XML herunterladen
if (isset($_GET['xml'])) {
    $stmt = $db->prepare('SELECT * FROM prae_meldungen WHERE id = ? AND organization_id = ?');
    $stmt->execute([(int)$_GET['xml'], $org_id]);
    if ($m = $stmt->fetch()) {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . praeL19Dateiname($m['refnr'], $m['typ']) . '"');
        echo $m['xml'];
        exit;
    }
}

// Jahresübersicht als CSV (Aufzeichnungen / Kassaprüfung)
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PRAE_Jahresuebersicht_' . $jahr . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Referenznummer', 'Nachname', 'Vorname', 'SV-Nummer', 'Geburtsdatum', 'Straße', 'PLZ', 'Ort', 'Zeitraum von', 'Zeitraum bis', 'PRAE steuerfrei (L19)', 'Überschuss steuerpflichtig', 'Meldeweg'], ';');
    foreach ($daten as $d) {
        $e = $d['empfaenger'];
        fputcsv($out, [$d['refnr'], $e['nachname'], $e['vorname'], $e['svnr'], $e['geburtsdatum'] ? date('d.m.Y', strtotime($e['geburtsdatum'])) : '', $e['strasse'], $e['plz'], $e['ort'],
                       $d['blz'] ? date('d.m.Y', strtotime($d['blz'])) : '', $d['elz'] ? date('d.m.Y', strtotime($d['elz'])) : '',
                       number_format((float)$d['betrag'], 2, ',', ''), number_format((float)$d['ueberschuss'], 2, ',', ''), $d['l16'] ? 'L16 (Lohnverrechnung)' : 'L19'], ';');
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));

    if ($action === 'erzeugen') {
        if ($f = praeZvrFehler($einst['zvr'])) { flashMessage('error', 'Vereinsdaten unvollständig: ' . $f); redirect(APP_URL . '/dashboard/admin/prae.php#einstellungen'); }
        $letzte = $letzte_meldungen();
        $dateien = $fehler = [];
        $unveraendert = 0;
        foreach ($ids as $eid) {
            $d = $daten[$eid] ?? null;
            if (!$d || !$d['meldbar']) { $fehler[] = ($d ? praeName($d['empfaenger']) : "#{$eid}") . ': nicht meldbar (siehe Hinweise)'; continue; }
            if ($d['elz'] > date('Y-m-t', strtotime('+2 months'))) { $fehler[] = praeName($d['empfaenger']) . ': Zeitraum endet zu weit in der Zukunft'; continue; }
            $vorher = $letzte[$eid] ?? null;
            // Unveränderte, bereits erzeugte Meldung nicht doppelt anlegen
            if ($vorher && $vorher['typ'] !== 'storno' && $vorher['blz'] === $d['blz'] && $vorher['elz'] === $d['elz'] && bccomp(moneyRound($vorher['betrag']), $d['betrag'], 2) === 0) {
                $dateien[praeL19Dateiname($vorher['refnr'], $vorher['typ'])] = $vorher['xml'];
                $unveraendert++;
                continue;
            }
            $xml = praeL19Xml($einst, $d['empfaenger'], $d['refnr'], $d['blz'], $d['elz'], $d['betrag']);
            if ($xf = praeXmlFehler($xml)) { $fehler[] = praeName($d['empfaenger']) . ': ' . $xf[0]; continue; }
            $typ = $vorher && $vorher['status'] === 'uebermittelt' ? 'korrektur' : 'meldung';
            $db->prepare('INSERT INTO prae_meldungen (organization_id, empfaenger_id, jahr, refnr, typ, blz, elz, betrag, xml, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$org_id, $eid, $jahr, $d['refnr'], $typ, $d['blz'], $d['elz'], $d['betrag'], $xml, getCurrentUserId()]);
            $dateien[praeL19Dateiname($d['refnr'], $typ)] = $xml;
        }
        if (!$dateien) {
            flashMessage('error', 'Keine L19 erzeugt. ' . implode(' · ', $fehler));
            redirect($self);
        }
        logActivity('prae_l19_erzeugt', "Jahr {$jahr}: " . count($dateien) . ' Dateien' . ($fehler ? ', ' . count($fehler) . ' übersprungen' : ''));
        if ($fehler) $_SESSION['prae_hinweis'] = 'Übersprungen: ' . implode(' · ', $fehler);
        if (count($dateien) === 1) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . array_key_first($dateien) . '"');
            echo reset($dateien);
        } else {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="L19_PRAE_' . $jahr . '.zip"');
            echo praeZip($dateien);
        }
        exit;
    }

    if ($action === 'storno') {
        $eid = (int)($_POST['empfaenger_id'] ?? 0);
        $vorher = $letzte_meldungen()[$eid] ?? null;
        $empf = praeEmpfaenger($db, $eid);
        if (!$vorher || $vorher['typ'] === 'storno' || !$empf) {
            flashMessage('error', 'Für diese Person gibt es keine aktive Meldung, die storniert werden kann.');
            redirect($self);
        }
        $xml = praeL19Xml($einst, $empf, $vorher['refnr'], $vorher['blz'], $vorher['elz'], '0', true);
        if ($xf = praeXmlFehler($xml)) { flashMessage('error', 'Storno konnte nicht erzeugt werden: ' . $xf[0]); redirect($self); }
        $db->prepare("INSERT INTO prae_meldungen (organization_id, empfaenger_id, jahr, refnr, typ, blz, elz, betrag, xml, erstellt_von) VALUES (?, ?, ?, ?, 'storno', ?, ?, 0, ?, ?)")
           ->execute([$org_id, $eid, $jahr, $vorher['refnr'], $vorher['blz'], $vorher['elz'], $xml, getCurrentUserId()]);
        logActivity('prae_l19_storno', "Empfänger-ID: {$eid}, REFNR: {$vorher['refnr']}");
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . praeL19Dateiname($vorher['refnr'], 'storno') . '"');
        echo $xml;
        exit;
    }

    if ($action === 'uebermittelt') {
        $meldung_ids = array_map('intval', (array)($_POST['meldungen'] ?? []));
        $n = 0;
        foreach ($meldung_ids as $mid) {
            $stmt = $db->prepare("UPDATE prae_meldungen SET status = 'uebermittelt', uebermittelt_am = ? WHERE id = ? AND organization_id = ? AND status = 'erstellt'");
            $stmt->execute([date('Y-m-d H:i:s'), $mid, $org_id]);
            $n += $stmt->rowCount();
        }
        logActivity('prae_l19_uebermittelt', "{$n} Meldungen");
        flashMessage('success', "{$n} Meldung(en) als an ELDA übermittelt markiert.");
        redirect($self . '#verlauf');
    }
}

$letzte = $letzte_meldungen();
$stmt = $db->prepare('SELECT m.*, e.vorname, e.nachname FROM prae_meldungen m JOIN prae_empfaenger e ON e.id = m.empfaenger_id WHERE m.organization_id = ? AND m.jahr = ? ORDER BY m.id DESC');
$stmt->execute([$org_id, $jahr]);
$verlauf = $stmt->fetchAll();

$frist = praeMeldefrist($jahr);
$tage_bis_frist = (int)floor((strtotime($frist) - strtotime(date('Y-m-d'))) / 86400);
$l19 = array_filter($daten, fn($d) => !$d['l16'] && $d['monate']);
$l16 = array_filter($daten, fn($d) => $d['l16']);
$summe_l19 = moneySum(array_column($l19, 'betrag'));
$offen = array_filter($l19, function ($d) use ($letzte) {
    $m = $letzte[$d['empfaenger_id']] ?? null;
    return !$m || $m['status'] !== 'uebermittelt' || $m['typ'] === 'storno' || bccomp(moneyRound($m['betrag']), $d['betrag'], 2) !== 0 || $m['blz'] !== $d['blz'] || $m['elz'] !== $d['elz'];
});
$einst_fehler = array_filter([praeZvrFehler($einst['zvr']), $einst['steuernummer'] ? praeStnrFehler($einst['steuernummer']) : null]);
$hinweis = $_SESSION['prae_hinweis'] ?? null;
unset($_SESSION['prae_hinweis']);
$maske = fn(?string $svnr) => $svnr ? substr($svnr, 0, 2) . '•• ' . substr($svnr, 4, 6) : '–';

$page_title = 'PRAE-Meldung L19 ' . $jahr;
$breadcrumb = 'PRAE-Abrechnung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.prae-hinweis { font-size: 0.75rem; line-height: 1.4; }
.prae-hinweis.fehler { color: var(--danger); } .prae-hinweis.warnung { color: #B7791F; }
:root[data-theme="dark"] .prae-hinweis.warnung { color: #FBBF5A; }
.prae-mini { font-size: 0.75rem; color: var(--text-muted); }
.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.prae-box { border-radius: 0.75rem; padding: 0.9rem 1.1rem; margin-bottom: 1.25rem; font-size: 0.9rem; background: var(--gold-dim); border-left: 4px solid var(--gold-accent); }
.prae-box.rot { background: rgba(239, 68, 68, 0.1); border-left-color: var(--danger); }
.prae-leiste { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.prae-schritte { counter-reset: s; list-style: none; padding: 0; margin: 0; }
.prae-schritte li { counter-increment: s; position: relative; padding: 0 0 0.85rem 2.3rem; font-size: 0.87rem; line-height: 1.6; }
.prae-schritte li::before { content: counter(s); position: absolute; left: 0; top: 0; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: var(--gold-dim); color: var(--gold-accent); font-weight: 800; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/admin/prae.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            PRAE-Abrechnung
        </a>
        <h1 class="dashboard-title">Jahresmeldung L19 · <?= $jahr ?></h1>
        <p class="dashboard-subtitle">Mitteilung über pauschale Reiseaufwandsentschädigungen an das Finanzamt – elektronisch über ELDA, eine Mitteilung je Person.</p>
    </div>
    <form method="GET" style="display: flex; gap: 0.5rem;">
        <select class="form-control" name="jahr" onchange="this.form.submit()"><?php for ($j = (int)date('Y'); $j >= 2023; $j--): ?><option value="<?= $j ?>" <?= $j === $jahr ? 'selected' : '' ?>><?= $j ?></option><?php endfor; ?></select>
    </form>
</div>

<?php if ($hinweis): ?><div class="flash-message flash-warning" style="border-radius: 0.75rem; margin-bottom: 1.25rem;"><span><?= e($hinweis) ?></span></div><?php endif; ?>

<div class="prae-box <?= $tage_bis_frist < 0 && $offen ? 'rot' : '' ?>">
    <?php if ($tage_bis_frist >= 0): ?>
        <strong>Meldefrist: <?= date('d.m.Y', strtotime($frist)) ?></strong> (noch <?= $tage_bis_frist ?> Tage) ·
    <?php else: ?>
        <strong>Meldefrist <?= date('d.m.Y', strtotime($frist)) ?> ist abgelaufen</strong> · <?= $offen ? 'Offene Meldungen bitte umgehend nachholen.' : '' ?>
    <?php endif; ?>
    <?= count($l19) ?> Person(en) per L19 · <?= count($offen) ?> noch nicht (aktuell) übermittelt · Summe <?= moneyFormat($summe_l19) ?>
    <?php if ((int)date('Y') === $jahr): ?><br><span class="prae-mini">Tipp: Erst nach Jahresende melden (Jänner/Februar) – dann reicht eine Meldung je Person, Korrekturen entfallen.</span><?php endif; ?>
</div>

<?php if ($einst_fehler): ?>
<div class="prae-box rot">✕ Vereinsdaten unvollständig: <?= e(implode(' ', $einst_fehler)) ?> <a href="<?= APP_URL ?>/dashboard/admin/prae.php#einstellungen">Vereinsdaten bearbeiten</a></div>
<?php endif; ?>

<form method="POST" class="table-card" style="margin-bottom: 1.5rem;">
    <?= csrfField() ?>
    <div class="table-card-header">
        <h2 class="table-card-title">Mitteilungen L19</h2>
        <span class="prae-mini">Auszahler: <?= e($einst['vereinsname']) ?> · ZVR <?= e($einst['zvr']) ?><?= $einst['steuernummer'] ? ' · St.Nr. ' . e($einst['steuernummer']) : '' ?></span>
    </div>
    <?php if (!$l19): ?>
        <div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine ausbezahlten PRAE in <?= $jahr ?></h3><p>Gemeldet werden nur Monatsabrechnungen mit Status „ausbezahlt“.</p></div>
    <?php else: ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th style="width: 2rem;"><input type="checkbox" aria-label="Alle auswählen" onclick="document.querySelectorAll('.l19-auswahl').forEach(c => c.checked = this.checked)" checked></th>
                <th>Empfänger:in</th><th>Zeitraum</th><th class="zahl">PRAE (Kz 243)</th><th>Status</th><th>Hinweise</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($l19 as $eid => $d): $e = $d['empfaenger']; $m = $letzte[$eid] ?? null;
                    $aktuell = $m && $m['typ'] !== 'storno' && $m['status'] === 'uebermittelt' && bccomp(moneyRound($m['betrag']), $d['betrag'], 2) === 0 && $m['blz'] === $d['blz'] && $m['elz'] === $d['elz']; ?>
                <tr>
                    <td><?php if ($d['meldbar']): ?><input type="checkbox" class="l19-auswahl" name="ids[]" value="<?= $eid ?>" <?= $aktuell || ($m && $m['typ'] === 'storno') ? '' : 'checked' ?> aria-label="<?= e(praeName($e)) ?>"><?php endif; ?></td>
                    <td><a class="text-primary" href="<?= APP_URL ?>/dashboard/admin/prae-empfaenger.php?id=<?= $eid ?>&jahr=<?= $jahr ?>"><?= e(praeName($e)) ?></a>
                        <div class="prae-mini">SV-Nr. <?= e($maske($e['svnr'])) ?> · <?= e($d['refnr']) ?></div></td>
                    <td><?= date('d.m.', strtotime($d['blz'])) ?> – <?= date('d.m.Y', strtotime($d['elz'])) ?><div class="prae-mini"><?= count($d['monate']) ?> Monat(e)</div></td>
                    <td class="zahl"><strong><?= moneyFormat($d['betrag']) ?></strong></td>
                    <td>
                        <?php if (!$m): ?><span class="badge badge-gray">noch nicht erstellt</span>
                        <?php elseif ($m['typ'] === 'storno'): ?><span class="badge badge-danger">storniert</span>
                        <?php elseif ($aktuell): ?><span class="badge badge-success">übermittelt</span><div class="prae-mini"><?= date('d.m.Y', strtotime($m['uebermittelt_am'])) ?></div>
                        <?php elseif ($m['status'] === 'erstellt'): ?><span class="badge badge-info">erstellt, nicht übermittelt</span>
                        <?php else: ?><span class="badge badge-warning">Korrektur nötig</span><div class="prae-mini">gemeldet <?= moneyFormat($m['betrag']) ?></div><?php endif; ?>
                    </td>
                    <td><?php foreach ($d['hinweise'] as $h): ?><div class="prae-hinweis <?= $h['typ'] ?>"><?= $h['typ'] === 'fehler' ? '✕' : '!' ?> <?= e($h['text']) ?></div><?php endforeach; ?></td>
                    <td style="white-space: nowrap;">
                        <?php if ($m && $m['typ'] !== 'storno'): ?>
                        <button type="submit" name="action" value="storno" formaction="<?= $self ?>" onclick="this.form.empfaenger_id.value='<?= $eid ?>'; return confirm('L19 von <?= e(praeName($e)) ?> stornieren? Es wird eine Storno-Datei für ELDA erzeugt.')" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Storno</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <input type="hidden" name="empfaenger_id" value="">
    <div class="prae-leiste">
        <button type="submit" name="action" value="erzeugen" class="btn btn-navy btn-sm">Ausgewählte L19 erzeugen &amp; herunterladen</button>
        <span class="prae-mini">Jede Datei wird gegen das offizielle BMF-Schema geprüft. Mehrere Personen → ZIP mit einer XML je Person.</span>
        <a href="<?= $self ?>&export=csv" class="btn btn-ghost-light btn-sm" style="margin-left: auto;">Jahresübersicht CSV</a>
        <a href="<?= APP_URL ?>/dashboard/admin/prae-pdf.php?jahresuebersicht=<?= $jahr ?>" class="btn btn-ghost-light btn-sm" target="_blank">Jahresübersicht PDF</a>
    </div>
    <?php endif; ?>
</form>

<?php if ($l16): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Über Lohnverrechnung (L16 statt L19)</h2></div>
    <div style="padding: 1rem 1.25rem; font-size: 0.87rem;">
        <p style="margin-top: 0;">Bei diesen Personen wurden Höchstbeträge überschritten oder es gibt zusätzlich Lohn/Gehalt. Die PRAE gehören dann auf den <strong>Lohnzettel L16</strong> (über eure Lohnverrechnung bzw. Steuerberatung); ggf. ist eine ÖGK-Anmeldung vor Arbeitsantritt nötig.</p>
        <?php foreach ($l16 as $d): ?><div>• <?= e(praeName($d['empfaenger'])) ?>: steuerfrei <?= moneyFormat($d['betrag']) ?>, steuerpflichtig <?= moneyFormat($d['ueberschuss']) ?></div><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid-2" style="align-items: start;">
    <div class="table-card" id="verlauf">
        <div class="table-card-header"><h2 class="table-card-title">Erzeugte Dateien</h2></div>
        <?php if (!$verlauf): ?>
            <div style="padding: 1.25rem;" class="prae-mini">Noch keine L19 erzeugt.</div>
        <?php else: ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="uebermittelt">
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead><tr><th></th><th>Person</th><th>Art</th><th class="zahl">Betrag</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($verlauf as $m): ?>
                        <tr>
                            <td><?php if ($m['status'] === 'erstellt'): ?><input type="checkbox" name="meldungen[]" value="<?= $m['id'] ?>" aria-label="Als übermittelt markieren"><?php endif; ?></td>
                            <td><?= e($m['vorname'] . ' ' . $m['nachname']) ?><div class="prae-mini"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></div></td>
                            <td><?= ['meldung' => 'Meldung', 'korrektur' => 'Korrektur', 'storno' => 'Storno'][$m['typ']] ?></td>
                            <td class="zahl"><?= $m['typ'] === 'storno' ? '–' : moneyFormat($m['betrag']) ?></td>
                            <td><?= $m['status'] === 'uebermittelt' ? '<span class="badge badge-success">übermittelt</span>' : '<span class="badge badge-info">erstellt</span>' ?></td>
                            <td><a href="<?= $self ?>&xml=<?= $m['id'] ?>" class="btn btn-ghost-light btn-sm">XML</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="prae-leiste"><button type="submit" class="btn btn-ghost-light btn-sm">Ausgewählte als „an ELDA übermittelt“ markieren</button><span class="prae-mini">nach erfolgreichem Upload laut ELDA-Protokoll</span></div>
        </form>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">So meldest du über ELDA</h2></div>
        <div style="padding: 1.25rem;">
            <ol class="prae-schritte">
                <li><strong>Einmalig:</strong> Mit deiner persönlichen ID Austria bei <a href="https://www.elda.at" target="_blank" rel="noopener">ELDA</a> für den Verein registrieren (ZVR <?= e($einst['zvr']) ?>).</li>
                <li><strong>Hier:</strong> Personen auswählen → „L19 erzeugen“. Du erhältst je Person eine XML-Datei (bei mehreren als ZIP – vor dem Upload entpacken).</li>
                <li><strong>In ELDA Online</strong> anmelden und die L19-Dateien über den Datei-Upload senden. Alternativ ist die manuelle Erfassung des L19 direkt in ELDA möglich.</li>
                <li><strong>Protokoll prüfen:</strong> ELDA zeigt je Mitteilung, ob sie übernommen wurde. Danach hier „als übermittelt markieren“.</li>
                <li><strong>Korrektur/Storno:</strong> Ändert sich ein Betrag, einfach neu erzeugen – das System verwendet dieselbe Referenznummer (Korrektur). Für ein Storno die Schaltfläche „Storno“ nutzen.</li>
            </ol>
            <p class="prae-mini" style="margin: 0;">Nur wenn die elektronische Übermittlung mangels technischer Voraussetzungen unzumutbar ist, darf das Formular L 19 auf Papier beim Finanzamt abgegeben werden. Anleitung: <a href="https://sportunion.at/service/vereinsfinanzen/abrechnung/prae/" target="_blank" rel="noopener">SPORTUNION – PRAE</a>.</p>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
