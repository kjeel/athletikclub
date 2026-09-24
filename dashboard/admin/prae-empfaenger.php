<?php
/**
 * Athletikclub Steiermark – PRAE-Empfänger:in (Admin)
 * Stammdaten (für L19), Einsatztage je Monat (auch aus eigenen Kursen),
 * Monatsabrechnung mit Freigabe und Auszahlung, Jahresüberblick.
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
$einst  = praeEinstellungen($db);

$id    = (int)($_GET['id'] ?? 0);
$empf  = $id ? praeEmpfaenger($db, $id) : null;
if ($id && !$empf) { flashMessage('error', 'Empfänger:in nicht gefunden.'); redirect(APP_URL . '/dashboard/admin/prae.php'); }
$jahr  = max(2023, min(2100, (int)($_GET['jahr'] ?? date('Y'))));
$monat = max(1, min(12, (int)($_GET['monat'] ?? date('n'))));
$self  = $empf ? APP_URL . "/dashboard/admin/prae-empfaenger.php?id={$id}&jahr={$jahr}&monat={$monat}" : APP_URL . '/dashboard/admin/prae-empfaenger.php?neu=1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'stammdaten') {
        $d = [
            'user_id'   => (int)($_POST['user_id'] ?? 0) ?: null,
            'rolle'     => isset(PRAE_ROLLEN[$_POST['rolle'] ?? '']) ? $_POST['rolle'] : 'trainer',
            'vorname'   => mb_substr(trim($_POST['vorname'] ?? ''), 0, 100),
            'nachname'  => mb_substr(trim($_POST['nachname'] ?? ''), 0, 100),
            'svnr'      => preg_replace('/\s+/', '', $_POST['svnr'] ?? '') ?: null,
            'geburtsdatum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['geburtsdatum'] ?? '') ? $_POST['geburtsdatum'] : null,
            'strasse'   => mb_substr(trim($_POST['strasse'] ?? ''), 0, 200) ?: null,
            'plz'       => mb_substr(trim($_POST['plz'] ?? ''), 0, 10) ?: null,
            'ort'       => mb_substr(trim($_POST['ort'] ?? ''), 0, 100) ?: null,
            'land'      => preg_match('/^[A-Za-z]{2}$/', $_POST['land'] ?? '') ? strtoupper($_POST['land']) : 'AT',
            'email'     => filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
            'iban'      => strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? '')) ?: null,
            'tagessatz' => trim($_POST['tagessatz'] ?? '') !== '' ? moneyRound(str_replace(',', '.', $_POST['tagessatz'])) : null,
            'hauptberuf'=> mb_substr(trim($_POST['hauptberuf'] ?? ''), 0, 150) ?: null,
            'kein_hauptberuf' => (int)!empty($_POST['kein_hauptberuf']),
            'andere_bezuege'  => (int)!empty($_POST['andere_bezuege']),
            'aktiv'     => (int)!empty($_POST['aktiv']),
            'notiz'     => trim($_POST['notiz'] ?? '') ?: null,
        ];
        if ($d['vorname'] === '' || $d['nachname'] === '') $errors['name'] = 'Bitte Vor- und Nachnamen angeben.';
        if ($d['svnr'] && ($f = praeSvnrFehler($d['svnr']))) $errors['svnr'] = $f;
        if (!$d['svnr'] && !$d['geburtsdatum']) $errors['geburtsdatum'] = 'Ohne SV-Nummer ist das Geburtsdatum Pflicht (für die L19-Meldung).';
        if ($d['iban'] && !praeIbanGueltig($d['iban'])) $errors['iban'] = 'Die IBAN ist ungültig.';
        if ($d['tagessatz'] !== null && (bccomp($d['tagessatz'], '0', 2) <= 0 || bccomp($d['tagessatz'], PRAE_TAG_MAX, 2) > 0)) $errors['tagessatz'] = 'Der Tagessatz muss zwischen 0,01 € und 120 € liegen.';
        if ($d['user_id']) {
            $stmt = $db->prepare('SELECT id FROM prae_empfaenger WHERE organization_id = ? AND user_id = ? AND id <> ?');
            $stmt->execute([$org_id, $d['user_id'], $id]);
            if ($stmt->fetch()) $errors['user_id'] = 'Dieses Benutzerkonto ist bereits einer anderen Empfänger:in zugeordnet.';
        }
        if (empty($errors)) {
            $spalten = array_keys($d);
            if ($empf) {
                $db->prepare('UPDATE prae_empfaenger SET ' . implode(', ', array_map(fn($s) => "{$s} = ?", $spalten)) . ' WHERE id = ? AND organization_id = ?')
                   ->execute(array_merge(array_values($d), [$id, $org_id]));
            } else {
                $db->prepare('INSERT INTO prae_empfaenger (' . implode(', ', $spalten) . ', organization_id) VALUES (' . implode(', ', array_fill(0, count($spalten) + 1, '?')) . ')')
                   ->execute(array_merge(array_values($d), [$org_id]));
                $id = (int)$db->lastInsertId();
            }
            logActivity('prae_empfaenger_gespeichert', "Empfänger-ID: {$id}");
            flashMessage('success', 'Stammdaten gespeichert.');
            redirect(APP_URL . "/dashboard/admin/prae-empfaenger.php?id={$id}&jahr={$jahr}&monat={$monat}");
        }
    }

    if ($empf && $action === 'einsatz_neu') {
        $datum  = $_POST['datum'] ?? '';
        $betrag = moneyRound(str_replace(',', '.', $_POST['betrag'] ?? '0') ?: '0');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || !strtotime($datum)) $errors['datum'] = 'Bitte ein gültiges Datum angeben.';
        elseif ($datum > date('Y-m-d', strtotime('+31 days'))) $errors['datum'] = 'Einsatztage können nicht weit in der Zukunft erfasst werden.';
        elseif (!praeMonatOffen($db, $id, $datum)) $errors['datum'] = 'Dieser Monat ist bereits ausbezahlt und abgeschlossen.';
        if (bccomp($betrag, '0', 2) <= 0) $errors['betrag'] = 'Bitte einen Betrag angeben.';
        if (empty($errors)) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM prae_einsaetze WHERE empfaenger_id = ? AND datum = ?');
            $stmt->execute([$id, $datum]);
            if ($stmt->fetchColumn()) $errors['datum'] = 'An diesem Tag ist bereits ein Einsatztag erfasst (je Tag zählt ein Einsatztag – mehrere Einheiten in der Beschreibung festhalten).';
        }
        if (empty($errors)) {
            $db->prepare('INSERT INTO prae_einsaetze (organization_id, empfaenger_id, datum, art, beschreibung, betrag, erfasst_von) VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([$org_id, $id, $datum, isset(PRAE_ARTEN[$_POST['art'] ?? '']) ? $_POST['art'] : 'training',
                          mb_substr(trim($_POST['beschreibung'] ?? ''), 0, 255) ?: null, $betrag, getCurrentUserId()]);
            praeAbrechnungAktualisieren($db, $id, (int)substr($datum, 0, 4), (int)substr($datum, 5, 2));
            flashMessage(bccomp($betrag, PRAE_TAG_MAX, 2) > 0 ? 'error' : 'success', bccomp($betrag, PRAE_TAG_MAX, 2) > 0
                ? 'Einsatztag gespeichert – Achtung: über 120 € ist der Mehrbetrag steuerpflichtig.' : 'Einsatztag gespeichert.');
            redirect(APP_URL . "/dashboard/admin/prae-empfaenger.php?id={$id}&jahr=" . substr($datum, 0, 4) . '&monat=' . (int)substr($datum, 5, 2) . '#einsaetze');
        }
    }

    if ($empf && $action === 'einsatz_loeschen') {
        $stmt = $db->prepare('SELECT datum FROM prae_einsaetze WHERE id = ? AND empfaenger_id = ?');
        $stmt->execute([(int)($_POST['einsatz_id'] ?? 0), $id]);
        $datum = $stmt->fetchColumn();
        if ($datum && praeMonatOffen($db, $id, $datum)) {
            $db->prepare('DELETE FROM prae_einsaetze WHERE id = ?')->execute([(int)$_POST['einsatz_id']]);
            praeAbrechnungAktualisieren($db, $id, (int)substr($datum, 0, 4), (int)substr($datum, 5, 2));
        }
        redirect($self . '#einsaetze');
    }

    if ($empf && $action === 'aus_kursen') {
        if (!$empf['user_id']) {
            flashMessage('error', 'Zuerst ein Benutzerkonto verknüpfen – dann können die Kurse dieser Person übernommen werden.');
            redirect($self);
        }
        $von = sprintf('%04d-%02d-01', $jahr, $monat);
        $bis = min(date('Y-m-t', strtotime($von)), date('Y-m-d'));
        $stmt = $db->prepare("SELECT id, titel, start_datum FROM kurse WHERE organization_id = ? AND trainer_id = ? AND status <> 'abgesagt'
                              AND start_datum >= ? AND start_datum <= ? ORDER BY start_datum");
        $stmt->execute([$org_id, $empf['user_id'], $von . ' 00:00:00', $bis . ' 23:59:59']);
        $je_tag = [];
        foreach ($stmt->fetchAll() as $k) $je_tag[substr($k['start_datum'], 0, 10)][] = $k;
        $neu = 0;
        if (praeMonatOffen($db, $id, $von)) {
            foreach ($je_tag as $tag => $kurse) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM prae_einsaetze WHERE empfaenger_id = ? AND datum = ?');
                $stmt->execute([$id, $tag]);
                if ($stmt->fetchColumn()) continue;
                $db->prepare("INSERT INTO prae_einsaetze (organization_id, empfaenger_id, datum, art, beschreibung, kurs_id, betrag, erfasst_von) VALUES (?, ?, ?, 'training', ?, ?, ?, ?)")
                   ->execute([$org_id, $id, $tag, mb_substr(implode(', ', array_unique(array_column($kurse, 'titel'))), 0, 255), $kurse[0]['id'], praeTagessatz($empf, $einst), getCurrentUserId()]);
                $neu++;
            }
            praeAbrechnungAktualisieren($db, $id, $jahr, $monat);
        }
        flashMessage('success', $neu ? "{$neu} Einsatztag(e) aus Kursen übernommen." : 'Keine neuen Kurstage in diesem Monat gefunden.');
        redirect($self . '#einsaetze');
    }

    if ($empf && $action === 'status') {
        $stmt = $db->prepare('SELECT * FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? AND monat = ?');
        $stmt->execute([$id, $jahr, $monat]);
        $abr = $stmt->fetch();
        $ziel = $_POST['ziel'] ?? '';
        if ($abr) {
            if ($ziel === 'freigegeben' && $abr['status'] === 'entwurf') {
                $db->prepare("UPDATE prae_abrechnungen SET status = 'freigegeben', freigegeben_von = ?, notiz = ? WHERE id = ?")->execute([getCurrentUserId(), mb_substr(trim($_POST['notiz'] ?? ''), 0, 500) ?: null, $abr['id']]);
            } elseif ($ziel === 'entwurf' && $abr['status'] === 'freigegeben') {
                $db->prepare("UPDATE prae_abrechnungen SET status = 'entwurf' WHERE id = ?")->execute([$abr['id']]);
            } elseif ($ziel === 'ausbezahlt' && $abr['status'] === 'freigegeben') {
                $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ausgezahlt_am'] ?? '') ? $_POST['ausgezahlt_am'] : date('Y-m-d');
                $db->prepare("UPDATE prae_abrechnungen SET status = 'ausbezahlt', ausgezahlt_am = ?, zahlungsart = ? WHERE id = ?")
                   ->execute([$datum, ($_POST['zahlungsart'] ?? '') === 'bar' ? 'bar' : 'ueberweisung', $abr['id']]);
            } elseif ($ziel === 'storniert' && $abr['status'] === 'ausbezahlt') {
                // Auszahlung zurücknehmen (z.B. Fehlbuchung) – Monat wird wieder bearbeitbar
                $db->prepare("UPDATE prae_abrechnungen SET status = 'freigegeben', ausgezahlt_am = NULL WHERE id = ?")->execute([$abr['id']]);
            }
            logActivity('prae_abrechnung_status', "Abrechnung-ID: {$abr['id']}, Ziel: {$ziel}");
        }
        redirect($self . '#abrechnung');
    }

    if ($empf && $action === 'loeschen') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM prae_abrechnungen WHERE empfaenger_id = ? AND status = 'ausbezahlt'");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) {
            flashMessage('error', 'Es gibt bereits ausbezahlte Abrechnungen (Aufbewahrungspflicht 7 Jahre) – bitte stattdessen auf „inaktiv“ setzen.');
            redirect($self);
        }
        $db->prepare('DELETE FROM prae_einsaetze WHERE empfaenger_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM prae_abrechnungen WHERE empfaenger_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM prae_empfaenger WHERE id = ? AND organization_id = ?')->execute([$id, $org_id]);
        flashMessage('success', 'Empfänger:in gelöscht.');
        redirect(APP_URL . '/dashboard/admin/prae.php');
    }
}

// ----------------------------------------------------------------
// Formular und Daten
// ----------------------------------------------------------------
$form = $empf ?? ['user_id' => null, 'rolle' => 'trainer', 'vorname' => '', 'nachname' => '', 'svnr' => '', 'geburtsdatum' => '', 'strasse' => '', 'plz' => '',
                  'ort' => '', 'land' => 'AT', 'email' => '', 'iban' => '', 'tagessatz' => null, 'hauptberuf' => '', 'kein_hauptberuf' => 0, 'andere_bezuege' => 0, 'aktiv' => 1, 'notiz' => ''];
if (!$empf && ($uid = (int)($_GET['user'] ?? 0))) {
    // Vorbelegung aus Benutzerkonto und Mitgliederprofil
    $stmt = $db->prepare('SELECT u.id, u.vorname, u.nachname, u.email, u.rolle, mp.geburtsdatum, mp.strasse, mp.plz, mp.ort FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id WHERE u.id = ? AND u.organization_id = ?');
    $stmt->execute([$uid, $org_id]);
    if ($u = $stmt->fetch()) {
        $form = array_merge($form, array_filter(['user_id' => $u['id'], 'vorname' => $u['vorname'], 'nachname' => $u['nachname'], 'email' => $u['email'],
            'geburtsdatum' => $u['geburtsdatum'], 'strasse' => $u['strasse'], 'plz' => $u['plz'], 'ort' => $u['ort'], 'rolle' => $u['rolle'] === 'mitglied' ? 'sportler' : 'trainer']));
    }
}
if (($_POST['action'] ?? '') === 'stammdaten') $form = array_merge($form, $_POST, ['kein_hauptberuf' => !empty($_POST['kein_hauptberuf']), 'andere_bezuege' => !empty($_POST['andere_bezuege']), 'aktiv' => !empty($_POST['aktiv'])]);

$stmt = $db->prepare("SELECT u.id, u.vorname, u.nachname, u.rolle FROM users u
                      WHERE u.organization_id = ? AND u.aktiv = 1 AND NOT EXISTS (SELECT 1 FROM prae_empfaenger p WHERE p.user_id = u.id AND p.id <> ?)
                      ORDER BY CASE u.rolle WHEN 'mitglied' THEN 1 ELSE 0 END, u.nachname, u.vorname");
$stmt->execute([$org_id, $id]);
$konten = $stmt->fetchAll();

$einsaetze = $abr = $jahresuebersicht = [];
$berechnung = null;
if ($empf) {
    $einsaetze = praeEinsaetze($db, $id, $jahr, $monat);
    $berechnung = praeMonatBerechnen($einsaetze);
    $stmt = $db->prepare('SELECT * FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? AND monat = ?');
    $stmt->execute([$id, $jahr, $monat]);
    $abr = $stmt->fetch() ?: null;
    $stmt = $db->prepare('SELECT * FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? ORDER BY monat');
    $stmt->execute([$id, $jahr]);
    foreach ($stmt->fetchAll() as $a) $jahresuebersicht[(int)$a['monat']] = $a;
}
$monat_offen = !$abr || $abr['status'] !== 'ausbezahlt';
$hinweise = $empf ? praeEmpfaengerHinweise($empf) : [];
$v = fn($wert) => e((string)($wert ?? ''));
$monat_url = fn(int $j, int $m) => APP_URL . "/dashboard/admin/prae-empfaenger.php?id={$id}&jahr={$j}&monat={$m}";
$vor  = $monat === 1 ? [$jahr - 1, 12] : [$jahr, $monat - 1];
$nach = $monat === 12 ? [$jahr + 1, 1] : [$jahr, $monat + 1];
$standard_datum = ($jahr === (int)date('Y') && $monat === (int)date('n')) ? date('Y-m-d') : sprintf('%04d-%02d-01', $jahr, $monat);

$page_title = $empf ? 'PRAE: ' . praeName($empf) : 'Neue PRAE-Empfänger:in';
$breadcrumb = 'PRAE-Abrechnung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.prae-hinweis { font-size: 0.8rem; line-height: 1.45; margin-bottom: 0.3rem; }
.prae-hinweis.fehler { color: var(--danger); } .prae-hinweis.warnung { color: #B7791F; }
:root[data-theme="dark"] .prae-hinweis.warnung { color: #FBBF5A; }
.prae-mini { font-size: 0.75rem; color: var(--text-muted); }
.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.prae-summe { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.prae-summe div { background: var(--bg-muted); border-radius: 0.75rem; padding: 0.7rem 0.9rem; }
.prae-summe strong { display: block; font-family: var(--font-heading); font-size: 1.15rem; }
.prae-check { display: flex; gap: 0.5rem; align-items: flex-start; font-size: 0.87rem; margin-bottom: 0.5rem; }
.prae-check input { margin-top: 0.25rem; flex-shrink: 0; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/admin/prae.php?jahr=<?= $jahr ?>&monat=<?= $monat ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            PRAE-Abrechnung
        </a>
        <h1 class="dashboard-title"><?= $empf ? e(praeName($empf)) : 'Neue Empfänger:in' ?></h1>
        <p class="dashboard-subtitle"><?= $empf ? e(PRAE_ROLLEN[$empf['rolle']] ?? '') . ' · ' . moneyFormat(praeTagessatz($empf, $einst)) . ' je Einsatztag' . ((int)$empf['aktiv'] ? '' : ' · inaktiv') : 'Stammdaten für Abrechnung und L19-Meldung' ?></p>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div>
<?php endif; ?>

<?php if ($empf): ?>
<?php foreach ($hinweise as $h): ?><div class="prae-hinweis <?= $h['typ'] ?>"><?= $h['typ'] === 'fehler' ? '✕' : '!' ?> <?= e($h['text']) ?></div><?php endforeach; ?>

<!-- Monat: Einsätze & Abrechnung -->
<div class="table-card" style="margin: 1rem 0 1.5rem;" id="einsaetze">
    <div class="table-card-header">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <a href="<?= $monat_url(...$vor) ?>#einsaetze" class="btn btn-ghost-light btn-sm" aria-label="Vormonat">‹</a>
            <h2 class="table-card-title" style="min-width: 9rem; text-align: center;"><?= PRAE_MONATE[$monat] ?> <?= $jahr ?></h2>
            <a href="<?= $monat_url(...$nach) ?>#einsaetze" class="btn btn-ghost-light btn-sm" aria-label="Folgemonat">›</a>
        </div>
        <?php if ($abr): ?><span class="badge <?= PRAE_STATUS[$abr['status']]['class'] ?>"><?= PRAE_STATUS[$abr['status']]['label'] ?></span><?php endif; ?>
    </div>
    <div style="padding: 1.25rem 1.25rem 0;">
        <div class="prae-summe">
            <div><span class="prae-mini">Einsatztage</span><strong><?= $berechnung['tage'] ?></strong></div>
            <div><span class="prae-mini">Betrag</span><strong><?= moneyFormat($berechnung['gesamt']) ?></strong></div>
            <div><span class="prae-mini">davon steuerfrei</span><strong><?= moneyFormat($berechnung['steuerfrei']) ?></strong></div>
            <div><span class="prae-mini">steuerpflichtig</span><strong style="color: <?= bccomp($berechnung['ueberschuss'], '0', 2) > 0 ? 'var(--danger)' : 'inherit' ?>"><?= moneyFormat($berechnung['ueberschuss']) ?></strong></div>
        </div>
        <?php if ($berechnung['tage_ueber']): ?><div class="prae-hinweis fehler">✕ An <?= $berechnung['tage_ueber'] ?> Tag(en) mehr als 120 € – der Mehrbetrag ist steuerpflichtig.</div><?php endif; ?>
        <?php if ($berechnung['monat_ueber']): ?><div class="prae-hinweis fehler">✕ Monatshöchstbetrag von 720 € überschritten – der Überschuss ist steuerpflichtig (Lohnverrechnung, L16).</div><?php endif; ?>
    </div>
    <?php if ($einsaetze): ?>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Datum</th><th>Art</th><th>Beschreibung</th><th class="zahl">Betrag</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($einsaetze as $es): ?>
                <tr>
                    <td><?= ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][(int)date('N', strtotime($es['datum'])) - 1] ?>, <?= date('d.m.Y', strtotime($es['datum'])) ?></td>
                    <td><?= e(PRAE_ARTEN[$es['art']] ?? $es['art']) ?></td>
                    <td><?= e($es['beschreibung'] ?? '–') ?></td>
                    <td class="zahl" style="<?= bccomp(moneyRound($es['betrag']), PRAE_TAG_MAX, 2) > 0 ? 'color: var(--danger);' : '' ?>"><?= moneyFormat($es['betrag']) ?></td>
                    <td><?php if ($monat_offen): ?><form method="POST" style="display: inline;"><?= csrfField() ?><input type="hidden" name="action" value="einsatz_loeschen"><input type="hidden" name="einsatz_id" value="<?= $es['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);" aria-label="Einsatztag löschen">✕</button></form><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($monat_offen): ?>
    <form method="POST" action="<?= $self ?>#einsaetze" style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="einsatz_neu">
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin: 0;"><label class="form-label">Datum</label><input class="form-control" type="date" name="datum" value="<?= e($_POST['datum'] ?? $standard_datum) ?>" required></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">Art</label><select class="form-control" name="art"><?php foreach (PRAE_ARTEN as $val => $label): ?><option value="<?= $val ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="margin: 0; flex: 1; min-width: 180px;"><label class="form-label">Beschreibung</label><input class="form-control" type="text" name="beschreibung" maxlength="255" placeholder="z.B. Calisthenics Basics, Halle St. Georgen" value="<?= e($_POST['beschreibung'] ?? '') ?>"></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">Betrag (€)</label><input class="form-control" type="text" inputmode="decimal" name="betrag" value="<?= e($_POST['betrag'] ?? number_format((float)praeTagessatz($empf, $einst), 2, ',', '')) ?>" style="max-width: 110px;"></div>
            <button type="submit" class="btn btn-navy btn-sm">Einsatztag hinzufügen</button>
        </div>
    </form>
    <div style="padding: 0 1.25rem 1.25rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <form method="POST" action="<?= $self ?>"><?= csrfField() ?><input type="hidden" name="action" value="aus_kursen"><button type="submit" class="btn btn-ghost-light btn-sm" <?= $empf['user_id'] ? '' : 'disabled title="Kein Benutzerkonto verknüpft"' ?>>Kurstage dieses Monats übernehmen</button></form>
    </div>
    <?php endif; ?>
</div>

<?php if ($abr): ?>
<div class="table-card" style="margin-bottom: 1.5rem;" id="abrechnung">
    <div class="table-card-header"><h2 class="table-card-title">Monatsabrechnung <?= PRAE_MONATE[$monat] ?> <?= $jahr ?></h2>
        <a href="<?= APP_URL ?>/dashboard/admin/prae-pdf.php?abrechnung=<?= (int)$abr['id'] ?>" class="btn btn-ghost-light btn-sm" target="_blank">PDF zum Unterschreiben</a></div>
    <div style="padding: 1.25rem;">
        <p style="margin: 0 0 1rem; font-size: 0.9rem;">
            <?= (int)$abr['einsatztage'] ?> Einsatztage · <strong><?= moneyFormat($abr['betrag_gesamt']) ?></strong> (steuerfrei <?= moneyFormat($abr['betrag_steuerfrei']) ?>)
            <?php if ($abr['bestaetigt_am']): ?> · von <?= e($empf['vorname']) ?> im System bestätigt am <?= date('d.m.Y H:i', strtotime($abr['bestaetigt_am'])) ?><?php endif; ?>
            <?php if ($abr['ausgezahlt_am']): ?> · ausbezahlt am <?= date('d.m.Y', strtotime($abr['ausgezahlt_am'])) ?> (<?= $abr['zahlungsart'] === 'bar' ? 'bar' : 'Überweisung' ?>)<?php endif; ?>
        </p>
        <form method="POST" action="<?= $self ?>" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="status">
            <?php if ($abr['status'] === 'entwurf'): ?>
                <button type="submit" name="ziel" value="freigegeben" class="btn btn-navy btn-sm">Freigeben</button>
                <span class="prae-mini">Nach der Freigabe kann <?= e($empf['vorname']) ?> die Abrechnung unter „Meine PRAE“ bestätigen.</span>
            <?php elseif ($abr['status'] === 'freigegeben'): ?>
                <input class="form-control" type="date" name="ausgezahlt_am" value="<?= date('Y-m-d') ?>" style="max-width: 160px;" aria-label="Auszahlungsdatum">
                <select class="form-control" name="zahlungsart" style="max-width: 150px;" aria-label="Zahlungsart"><option value="ueberweisung">Überweisung</option><option value="bar">Bar</option></select>
                <button type="submit" name="ziel" value="ausbezahlt" class="btn btn-navy btn-sm">Als ausbezahlt markieren</button>
                <button type="submit" name="ziel" value="entwurf" class="btn btn-ghost-light btn-sm">Freigabe zurücknehmen</button>
            <?php else: ?>
                <button type="submit" name="ziel" value="storniert" class="btn btn-ghost-light btn-sm" onclick="return confirm('Auszahlung zurücknehmen? Die Abrechnung wird wieder auf „Freigegeben“ gesetzt.')">Auszahlung zurücknehmen</button>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Jahresüberblick -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title">Jahr <?= $jahr ?></h2><a href="<?= APP_URL ?>/dashboard/admin/prae-meldung.php?jahr=<?= $jahr ?>" class="btn btn-ghost-light btn-sm">L19-Meldung <?= $jahr ?></a></div>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>Monat</th><th class="zahl">Tage</th><th class="zahl">Betrag</th><th class="zahl">steuerfrei</th><th>Status</th></tr></thead>
            <tbody>
                <?php $summe = []; foreach (PRAE_MONATE as $m => $label): $a = $jahresuebersicht[$m] ?? null; if ($a && $a['status'] === 'ausbezahlt') $summe[] = $a['betrag_steuerfrei']; ?>
                <tr style="<?= $a ? '' : 'opacity: 0.45;' ?>">
                    <td><a href="<?= $monat_url($jahr, $m) ?>#einsaetze" class="text-primary"><?= $label ?></a></td>
                    <td class="zahl"><?= $a ? (int)$a['einsatztage'] : '–' ?></td>
                    <td class="zahl"><?= $a ? moneyFormat($a['betrag_gesamt']) : '–' ?></td>
                    <td class="zahl"><?= $a ? moneyFormat($a['betrag_steuerfrei']) : '–' ?></td>
                    <td><?= $a ? '<span class="badge ' . PRAE_STATUS[$a['status']]['class'] . '">' . PRAE_STATUS[$a['status']]['label'] . '</span>' : '' ?></td>
                </tr>
                <?php endforeach; ?>
                <tr><td><strong>Ausbezahlt (für L19)</strong></td><td></td><td></td><td class="zahl"><strong><?= moneyFormat(moneySum($summe)) ?></strong></td><td></td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Stammdaten -->
<div class="table-card" id="stammdaten">
    <div class="table-card-header"><h2 class="table-card-title">Stammdaten</h2></div>
    <div style="padding: 1.25rem;">
        <form method="POST" action="<?= $self ?>#stammdaten">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="stammdaten">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Benutzerkonto verknüpfen</label>
                    <select class="form-control <?= isset($errors['user_id']) ? 'error' : '' ?>" name="user_id" <?= $empf ? '' : 'onchange="if (this.value) location.href=\'' . APP_URL . '/dashboard/admin/prae-empfaenger.php?neu=1&user=\' + this.value"' ?>>
                        <option value="">– ohne Konto –</option>
                        <?php foreach ($konten as $k): ?><option value="<?= $k['id'] ?>" <?= (int)$form['user_id'] === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['nachname'] . ' ' . $k['vorname']) ?> (<?= e($k['rolle']) ?>)</option><?php endforeach; ?>
                    </select>
                    <p class="form-hint">Mit Konto sieht die Person ihre Abrechnungen unter „Meine PRAE“, und Kurstage können übernommen werden.</p></div>
                <div class="form-group"><label class="form-label">Funktion</label>
                    <select class="form-control" name="rolle"><?php foreach (PRAE_ROLLEN as $val => $label): ?><option value="<?= $val ?>" <?= $form['rolle'] === $val ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Vorname <span class="required">*</span></label><input class="form-control" type="text" name="vorname" maxlength="100" value="<?= $v($form['vorname']) ?>" required></div>
                <div class="form-group"><label class="form-label">Nachname <span class="required">*</span></label><input class="form-control" type="text" name="nachname" maxlength="100" value="<?= $v($form['nachname']) ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">SV-Nummer (laut e-card)</label><input class="form-control <?= isset($errors['svnr']) ? 'error' : '' ?>" type="text" name="svnr" inputmode="numeric" maxlength="12" value="<?= $v($form['svnr']) ?>" placeholder="10-stellig, z.B. 1234 150890" autocomplete="off"></div>
                <div class="form-group"><label class="form-label">Geburtsdatum</label><input class="form-control <?= isset($errors['geburtsdatum']) ? 'error' : '' ?>" type="date" name="geburtsdatum" value="<?= $v($form['geburtsdatum']) ?>"><p class="form-hint">Pflicht, wenn keine SV-Nummer vorhanden ist.</p></div>
            </div>
            <div class="form-group"><label class="form-label">Straße und Hausnummer</label><input class="form-control" type="text" name="strasse" maxlength="200" value="<?= $v($form['strasse']) ?>"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">PLZ / Ort</label><div style="display: flex; gap: 0.5rem;"><input class="form-control" type="text" name="plz" maxlength="10" value="<?= $v($form['plz']) ?>" style="max-width: 100px;"><input class="form-control" type="text" name="ort" maxlength="100" value="<?= $v($form['ort']) ?>"></div></div>
                <div class="form-group"><label class="form-label">Land (ISO)</label><input class="form-control" type="text" name="land" maxlength="2" value="<?= $v($form['land']) ?>" style="max-width: 80px;"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">E-Mail</label><input class="form-control" type="email" name="email" maxlength="180" value="<?= $v($form['email']) ?>"></div>
                <div class="form-group"><label class="form-label">IBAN (für Überweisung)</label><input class="form-control <?= isset($errors['iban']) ? 'error' : '' ?>" type="text" name="iban" maxlength="42" value="<?= $v($form['iban'] ? praeIbanFormat($form['iban']) : '') ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Tagessatz (€)</label><input class="form-control <?= isset($errors['tagessatz']) ? 'error' : '' ?>" type="text" inputmode="decimal" name="tagessatz" value="<?= $v($form['tagessatz'] !== null && $form['tagessatz'] !== '' ? number_format((float)$form['tagessatz'], 2, ',', '') : '') ?>" placeholder="Standard: <?= number_format((float)$einst['tagessatz'], 2, ',', '') ?>"></div>
                <div class="form-group"><label class="form-label">Hauptberuf / Haupteinnahmequelle</label><input class="form-control" type="text" name="hauptberuf" maxlength="150" value="<?= $v($form['hauptberuf']) ?>" placeholder="z.B. Angestellte:r, Pension, Schüler:in"></div>
            </div>
            <label class="prae-check"><input type="checkbox" name="kein_hauptberuf" value="1" <?= $form['kein_hauptberuf'] ? 'checked' : '' ?>> <span>Bestätigt: Die Tätigkeit für den Verein ist <strong>nicht Hauptberuf</strong> und <strong>nicht Haupteinnahmequelle</strong> (Voraussetzung für die Sozialversicherungsfreiheit).</span></label>
            <label class="prae-check"><input type="checkbox" name="andere_bezuege" value="1" <?= $form['andere_bezuege'] ? 'checked' : '' ?>> <span>Erhält vom Verein <strong>zusätzlich Lohn/Gehalt</strong> (z.B. Anstellung) → PRAE werden am Lohnzettel L16 gemeldet, nicht per L19.</span></label>
            <label class="prae-check"><input type="checkbox" name="aktiv" value="1" <?= $form['aktiv'] ? 'checked' : '' ?>> <span>Aktiv</span></label>
            <div class="form-group"><label class="form-label">Notiz</label><textarea class="form-control" name="notiz" rows="2"><?= $v($form['notiz']) ?></textarea></div>
            <button type="submit" class="btn btn-navy"><?= $empf ? 'Stammdaten speichern' : 'Empfänger:in anlegen' ?></button>
        </form>
        <?php if ($empf): ?>
        <form method="POST" style="margin-top: 1rem;" onsubmit="return confirm('Empfänger:in mit allen nicht ausbezahlten Einsätzen löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><button type="submit" class="btn btn-ghost-light btn-sm" style="color: var(--danger);">Löschen</button></form>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
