<?php
/**
 * Athletikclub Steiermark – Rechnungen, Zahlungen & Mahnwesen
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/rechnungen.php';

requireDarf('rechnungen.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$self   = APP_URL . '/dashboard/admin/rechnungen.php';
$d_bearbeiten = darf('rechnungen.bearbeiten');
$d_ausstellen = darf('rechnungen.ausstellen');
$d_zahlung    = darf('zahlungen.erfassen');

// PDF-Ausgabe
if (!empty($_GET['pdf']) && ($r = rechnungLaden($db, (int)($_GET['id'] ?? 0)))) {
    require_once ROOT_PATH . '/includes/rechnung-pdf.php';
    $bytes = rechnungPdfErzeugen($db, $r);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rechnungPdfDateiname($r) . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $r = $id ? rechnungLaden($db, $id) : null;
    $ziel = $id ? "$self?id=$id" : $self;
    $recht = ['neu' => $d_bearbeiten, 'kopf' => $d_bearbeiten, 'position' => $d_bearbeiten, 'position_loeschen' => $d_bearbeiten, 'entwurf_loeschen' => $d_bearbeiten,
              'ausstellen' => $d_ausstellen, 'stornieren' => $d_ausstellen, 'senden' => $d_ausstellen, 'mahnung_vorbereiten' => $d_ausstellen, 'mahnung_senden' => $d_ausstellen,
              'mahnung_verwerfen' => $d_ausstellen, 'zahlung' => $d_zahlung, 'zahlung_loeschen' => $d_zahlung, 'aus_kurs' => $d_bearbeiten][$action] ?? false;
    if (!$recht || ($id && !$r)) { flashMessage('error', 'Keine Berechtigung für diese Aktion.'); redirect($ziel); }
    $entwurf = $r && $r['status'] === 'entwurf';
    $datum = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$f] ?? '') ? $_POST[$f] : null;
    $txt = fn($f, $max) => mb_substr(trim($_POST[$f] ?? ''), 0, $max) ?: null;

    if ($action === 'neu' || ($action === 'kopf' && $entwurf)) {
        $user_id = (int)($_POST['user_id'] ?? 0) ?: null;
        $partner_id = (int)($_POST['partner_id'] ?? 0) ?: null;
        if ($user_id) { $s = $db->prepare('SELECT 1 FROM users WHERE id = ? AND organization_id = ?'); $s->execute([$user_id, $org_id]); if (!$s->fetchColumn()) $user_id = null; }
        if ($partner_id) { $s = $db->prepare('SELECT 1 FROM partner_organisationen WHERE id = ? AND organization_id = ?'); $s->execute([$partner_id, $org_id]); if (!$s->fetchColumn()) $partner_id = null; }
        $werte = ['empf_name' => $txt('empf_name', 200), 'empf_zusatz' => $txt('empf_zusatz', 200), 'empf_strasse' => $txt('empf_strasse', 200), 'empf_plz' => $txt('empf_plz', 10),
                  'empf_ort' => $txt('empf_ort', 100), 'empf_land' => $txt('empf_land', 60), 'empf_email' => $txt('empf_email', 180), 'empf_uid' => $txt('empf_uid', 30),
                  'user_id' => $user_id, 'partner_id' => $partner_id, 'kurs_id' => (int)($_POST['kurs_id'] ?? 0) ?: null, 'projekt_id' => (int)($_POST['projekt_id'] ?? 0) ?: null,
                  'leistung_von' => $datum('leistung_von'), 'leistung_bis' => $datum('leistung_bis'), 'faellig_am' => $datum('faellig_am'),
                  'text_oben' => trim($_POST['text_oben'] ?? '') ?: null, 'text_unten' => trim($_POST['text_unten'] ?? '') ?: null, 'steuerhinweis' => $txt('steuerhinweis', 300)];
        // Leere Empfängerfelder aus Konto/Partner übernehmen (keine doppelte Stammdatenpflege)
        foreach (empfaengerDaten($db, $user_id, $partner_id) as $k => $v) if (empty($werte[$k])) $werte[$k] = $v;
        if ($werte['empf_email'] && !filter_var($werte['empf_email'], FILTER_VALIDATE_EMAIL)) { flashMessage('error', 'E-Mail-Adresse ungültig.'); redirect($id ? $ziel : "$self?neu=1"); }
        if (!$werte['empf_name']) { flashMessage('error', 'Bitte einen Empfänger wählen oder eingeben.'); redirect($id ? $ziel : "$self?neu=1"); }
        if ($r) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($werte)));
            $db->prepare("UPDATE rechnungen SET $sets WHERE id = ?")->execute([...array_values($werte), $id]);
            auditLog('geaendert', 'rechnungen', $id, $r, $werte, 'Rechnungsentwurf');
        } else {
            $werte += ['organization_id' => $org_id, 'typ' => 'rechnung', 'status' => 'entwurf', 'erstellt_von' => $me];
            $db->prepare('INSERT INTO rechnungen (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')->execute(array_values($werte));
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'rechnungen', $id, null, $werte, 'Rechnungsentwurf');
        }
        flashMessage('success', 'Entwurf gespeichert.');
        redirect("$self?id=$id");
    }

    if ($action === 'position' && $entwurf) {
        $menge = str_replace(',', '.', trim($_POST['menge'] ?? '1'));
        $preis = str_replace(',', '.', trim($_POST['einzelpreis'] ?? ''));
        $ust = str_replace(',', '.', trim($_POST['ust_satz'] ?? '0'));
        $beschr = $txt('beschreibung', 300);
        if (!$beschr || !is_numeric($menge) || !is_numeric($preis) || !is_numeric($ust) || (float)$ust < 0 || (float)$ust > 30) { flashMessage('error', 'Bitte Beschreibung, Menge und Einzelpreis prüfen.'); redirect($ziel); }
        $pid = (int)($_POST['pos_id'] ?? 0);
        $werte = [$beschr, moneyRound($menge), $txt('einheit', 20), moneyRound($preis), moneyRound($ust)];
        if ($pid) {
            $db->prepare('UPDATE rechnung_positionen SET beschreibung = ?, menge = ?, einheit = ?, einzelpreis = ?, ust_satz = ? WHERE id = ? AND rechnung_id = ?')->execute([...$werte, $pid, $id]);
        } else {
            $s = $db->prepare('SELECT COALESCE(MAX(pos), 0) + 1 FROM rechnung_positionen WHERE rechnung_id = ?');
            $s->execute([$id]);
            $db->prepare('INSERT INTO rechnung_positionen (rechnung_id, pos, beschreibung, menge, einheit, einzelpreis, ust_satz) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, (int)$s->fetchColumn(), ...$werte]);
        }
        rechnungSummenNeu($db, $id);
        redirect($ziel . '#positionen');
    }

    if ($action === 'position_loeschen' && $entwurf) {
        $db->prepare('DELETE FROM rechnung_positionen WHERE id = ? AND rechnung_id = ?')->execute([(int)($_POST['pos_id'] ?? 0), $id]);
        rechnungSummenNeu($db, $id);
        redirect($ziel . '#positionen');
    }

    if ($action === 'entwurf_loeschen' && $entwurf) {
        $db->prepare('DELETE FROM rechnungen WHERE id = ? AND status = ?')->execute([$id, 'entwurf']);
        auditLog('geloescht', 'rechnungen', $id, $r, null, 'Rechnungsentwurf');
        flashMessage('success', 'Entwurf gelöscht.');
        redirect($self);
    }

    if ($action === 'ausstellen') {
        $f = rechnungAusstellen($db, $id, $datum('rechnungsdatum'));
        if ($f) flashMessage('error', $f);
        else {
            $r = rechnungLaden($db, $id);
            $msg = 'Rechnung ' . $r['nummer'] . ' ausgestellt.';
            if (!empty($_POST['gleich_senden'])) $msg .= rechnungVersenden($db, $r) ? ' Versand an die Empfänger:in ausgelöst.' : ' Versand nicht möglich (keine E-Mail-Adresse).';
            flashMessage('success', $msg);
        }
        redirect($ziel);
    }

    if ($action === 'senden') {
        $ok = rechnungVersenden($db, $r);
        flashMessage($ok ? 'success' : 'error', $ok ? 'Rechnung ' . $r['nummer'] . ' wurde versendet.' : 'Versand nicht möglich – keine E-Mail-Adresse hinterlegt oder E-Mail deaktiviert.');
        redirect($ziel);
    }

    if ($action === 'stornieren') {
        $erg = rechnungStornieren($db, $id, $txt('grund', 200) ?? '');
        if (isset($erg['fehler'])) flashMessage('error', $erg['fehler']);
        else flashMessage('success', 'Rechnung storniert, Stornorechnung erstellt.' . (bccomp($erg['bezahlt'], '0', 2) > 0 ? ' Achtung: Es wurden bereits ' . moneyFormat($erg['bezahlt']) . ' bezahlt – bitte Rückzahlung veranlassen.' : ''));
        redirect(isset($erg['id']) ? "$self?id={$erg['id']}" : $ziel);
    }

    if ($action === 'zahlung') {
        $erg = zahlungErfassen($db, $r, str_replace(',', '.', trim($_POST['betrag'] ?? '')), $datum('datum') ?? date('Y-m-d'), $_POST['zahlungsart'] ?? 'ueberweisung', $txt('referenz', 120));
        flashMessage(isset($erg['fehler']) ? 'error' : 'success', $erg['fehler'] ?? (bccomp($erg['offen'], '0', 2) <= 0 ? 'Zahlung erfasst – Rechnung ist vollständig bezahlt.' : 'Teilzahlung erfasst, offen: ' . moneyFormat($erg['offen']) . '.'));
        redirect($ziel . '#zahlungen');
    }

    if ($action === 'zahlung_loeschen') {
        flashMessage(zahlungLoeschen($db, $r, (int)($_POST['zahlung_id'] ?? 0)) ? 'success' : 'error', 'Zahlung zurückgenommen.');
        redirect($ziel . '#zahlungen');
    }

    if ($action === 'mahnung_vorbereiten') {
        $stufe = min(3, (int)$r['mahnstufe'] + 1);
        $s = $db->prepare('SELECT COALESCE(MAX(stufe), 0) FROM mahnungen WHERE rechnung_id = ?');
        $s->execute([$id]);
        $stufe = min(3, max($stufe, (int)$s->fetchColumn() + 1));
        $ok = mahnungVorbereiten($db, $r, $stufe, $me);
        flashMessage($ok ? 'success' : 'error', MAHNSTUFEN[$stufe][0] . ($ok ? ' vorbereitet – bitte prüfen und senden.' : ' besteht bereits.'));
        redirect($ziel . '#mahnungen');
    }

    if ($action === 'mahnung_senden') {
        $s = $db->prepare('SELECT id FROM mahnungen WHERE id = ? AND rechnung_id = ?');
        $s->execute([(int)($_POST['mahnung_id'] ?? 0), $id]);
        $ok = ($mid = $s->fetchColumn()) && mahnungVersenden($db, (int)$mid);
        flashMessage($ok ? 'success' : 'error', $ok ? 'Mahnung versendet.' : 'Diese Mahnung wurde bereits versendet.');
        redirect($ziel . '#mahnungen');
    }

    if ($action === 'mahnung_verwerfen') {
        $db->prepare("UPDATE mahnungen SET status = 'verworfen' WHERE id = ? AND rechnung_id = ? AND status = 'vorbereitet'")->execute([(int)($_POST['mahnung_id'] ?? 0), $id]);
        redirect($ziel . '#mahnungen');
    }

    if ($action === 'aus_kurs') {
        $kurs_id = (int)($_POST['kurs_id'] ?? 0);
        $s = $db->prepare("SELECT ka.id FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id WHERE ka.kurs_id = ? AND k.organization_id = ? AND ka.status IN ('angemeldet','teilgenommen') AND ka.bezahlt = 0
                           AND NOT EXISTS (SELECT 1 FROM rechnungen r WHERE r.anmeldung_id = ka.id AND r.status <> 'storniert' AND r.typ = 'rechnung')");
        $s->execute([$kurs_id, $org_id]);
        $n = $ausg = 0;
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            if ($rid = rechnungAusAnmeldung($db, (int)$aid)) {
                $n++;
                if (!empty($_POST['ausstellen']) && $d_ausstellen && !rechnungAusstellen($db, $rid)) {
                    $ausg++;
                    if (!empty($_POST['senden'])) rechnungVersenden($db, rechnungLaden($db, $rid));
                }
            }
        }
        flashMessage($n ? 'success' : 'info', $n ? "$n Rechnung(en) erstellt" . ($ausg ? ", $ausg ausgestellt" : ' (Entwürfe)') . '.' : 'Keine offenen Anmeldungen ohne Rechnung.');
        redirect($self . '?status=' . ($ausg ? 'offen' : 'entwurf'));
    }
    redirect($ziel);
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$detail = !empty($_GET['id']) ? rechnungLaden($db, (int)$_GET['id']) : null;
$neu = !empty($_GET['neu']) && $d_bearbeiten;
$page_title = $detail ? ($detail['nummer'] ?: 'Rechnungsentwurf') : 'Rechnungen';
$breadcrumb = 'Rechnungen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$personen = $db->query("SELECT id, vorname, nachname, email FROM users WHERE organization_id = " . (int)$org_id . " AND aktiv = 1 ORDER BY nachname, vorname LIMIT 3000")->fetchAll();
$partner = [];
try { $partner = $db->query("SELECT id, name FROM partner_organisationen WHERE organization_id = " . (int)$org_id . " AND status <> 'inaktiv' ORDER BY name")->fetchAll(); } catch (Exception $e) {}
$st_anzeige = fn($r) => RECHNUNG_STATUS[rechnungAnzeigeStatus($r)] ?? ['label' => $r['status'], 'class' => 'badge-gray'];
?>

<style>
.re-mini { font-size: 0.78rem; color: var(--text-muted); }
.re-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
.re-inline { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-end; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.re-inline .form-group { margin: 0; }
.re-zahl { text-align: right; white-space: nowrap; }
.re-summe td { font-weight: 700; }
.re-aktionen { display: flex; gap: 0.5rem; flex-wrap: wrap; }
@media (max-width: 900px) { .re-grid { grid-template-columns: minmax(0, 1fr); } }
</style>

<?php if ($neu || ($detail && $detail['status'] === 'entwurf' && !empty($_GET['bearbeiten']))): $f = $detail ?? ['user_id' => (int)($_GET['user'] ?? 0), 'partner_id' => (int)($_GET['partner'] ?? 0), 'projekt_id' => (int)($_GET['projekt'] ?? 0), 'kurs_id' => 0]; ?>
    <div class="dashboard-header">
        <a href="<?= $self . ($detail ? '?id=' . $detail['id'] : '') ?>" class="re-mini">← zurück</a>
        <h1 class="dashboard-title"><?= $detail ? 'Entwurf bearbeiten' : 'Neue Rechnung' ?></h1>
        <p class="dashboard-subtitle">Empfänger wählen – leere Adressfelder werden aus Konto bzw. Partner übernommen.</p>
    </div>
    <form method="POST" class="form-card" style="max-width: 860px;"><?= csrfField() ?><input type="hidden" name="action" value="<?= $detail ? 'kopf' : 'neu' ?>"><input type="hidden" name="id" value="<?= (int)($detail['id'] ?? 0) ?>">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Person (Konto)</label><select class="form-control" name="user_id"><option value="">–</option><?php foreach ($personen as $u): ?><option value="<?= $u['id'] ?>" <?= (int)($f['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nachname'] . ' ' . $u['vorname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">oder Partner / Organisation</label><select class="form-control" name="partner_id"><option value="">–</option><?php foreach ($partner as $p): ?><option value="<?= $p['id'] ?>" <?= (int)($f['partner_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Empfänger (Name/Firma)</label><input class="form-control" name="empf_name" maxlength="200" value="<?= e($f['empf_name'] ?? '') ?>" placeholder="leer = aus Auswahl"></div>
            <div class="form-group"><label class="form-label">Zusatz</label><input class="form-control" name="empf_zusatz" maxlength="200" value="<?= e($f['empf_zusatz'] ?? '') ?>" placeholder="z. H. …"></div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex: 2;"><label class="form-label">Straße</label><input class="form-control" name="empf_strasse" maxlength="200" value="<?= e($f['empf_strasse'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">PLZ</label><input class="form-control" name="empf_plz" maxlength="10" value="<?= e($f['empf_plz'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Ort</label><input class="form-control" name="empf_ort" maxlength="100" value="<?= e($f['empf_ort'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">E-Mail für den Versand</label><input class="form-control" type="email" name="empf_email" maxlength="180" value="<?= e($f['empf_email'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">UID-Nummer (Unternehmen)</label><input class="form-control" name="empf_uid" maxlength="30" value="<?= e($f['empf_uid'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Land</label><input class="form-control" name="empf_land" maxlength="60" value="<?= e($f['empf_land'] ?? '') ?>" placeholder="leer = Österreich"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Leistung von</label><input class="form-control" type="date" name="leistung_von" value="<?= e($f['leistung_von'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Leistung bis</label><input class="form-control" type="date" name="leistung_bis" value="<?= e($f['leistung_bis'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Zahlbar bis</label><input class="form-control" type="date" name="faellig_am" value="<?= e($f['faellig_am'] ?? '') ?>"><span class="form-hint">leer = <?= (int)einstellung('rechnung_zahlungsziel', '14') ?> Tage ab Ausstellung</span></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Projekt</label><select class="form-control" name="projekt_id"><option value="">–</option><?php foreach (plattformProjekte($db, false) as $p): ?><option value="<?= $p['id'] ?>" <?= (int)($f['projekt_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Kurs / Event</label><select class="form-control" name="kurs_id"><option value="">–</option><?php foreach ($db->query("SELECT id, titel, start_datum FROM kurse WHERE organization_id = " . (int)$org_id . " AND end_datum >= '" . date('Y-m-d', strtotime('-1 year')) . "' ORDER BY start_datum DESC LIMIT 300") as $k): ?><option value="<?= $k['id'] ?>" <?= (int)($f['kurs_id'] ?? 0) === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['titel']) ?> (<?= date('d.m.Y', strtotime($k['start_datum'])) ?>)</option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">Einleitungstext</label><textarea class="form-control" name="text_oben" rows="2"><?= e($f['text_oben'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Schlusstext</label><textarea class="form-control" name="text_unten" rows="2"><?= e($f['text_unten'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Steuerhinweis</label><input class="form-control" name="steuerhinweis" maxlength="300" value="<?= e($f['steuerhinweis'] ?? '') ?>" placeholder="<?= e(einstellung('rechnung_steuerhinweis', '') ?: 'Standard aus den Einstellungen') ?>"></div>
        <button type="submit" class="btn btn-primary"><?= $detail ? 'Speichern' : 'Entwurf anlegen' ?></button>
    </form>

<?php elseif ($detail): $r = $detail; $st = $st_anzeige($r); $pos = rechnungPositionen($db, (int)$r['id']); $bezahlt = rechnungBezahlt($db, (int)$r['id']); $offen = bcsub(moneyRound($r['betrag_brutto']), $bezahlt, 2);
    $zahlungen = $db->prepare('SELECT z.*, u.vorname, u.nachname FROM zahlungen z LEFT JOIN users u ON u.id = z.erfasst_von WHERE z.rechnung_id = ? ORDER BY z.datum, z.id'); $zahlungen->execute([$r['id']]); $zahlungen = $zahlungen->fetchAll();
    $mahnungen = $db->prepare('SELECT * FROM mahnungen WHERE rechnung_id = ? ORDER BY stufe'); $mahnungen->execute([$r['id']]); $mahnungen = $mahnungen->fetchAll();
    $bezug = null;
    if ($r['storno_zu_id']) { $s = $db->prepare('SELECT id, nummer FROM rechnungen WHERE id = ?'); $s->execute([$r['storno_zu_id']]); $bezug = ['Storno zu', $s->fetch()]; }
    else { $s = $db->prepare("SELECT id, nummer FROM rechnungen WHERE storno_zu_id = ?"); $s->execute([$r['id']]); if ($x = $s->fetch()) $bezug = ['Storniert durch', $x]; }
    $entwurf = $r['status'] === 'entwurf'; ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <a href="<?= $self ?>" class="re-mini">← Rechnungen</a>
            <h1 class="dashboard-title"><?= $r['typ'] === 'storno' ? 'Stornorechnung ' : 'Rechnung ' ?><?= e($r['nummer'] ?: '(Entwurf)') ?></h1>
            <p class="dashboard-subtitle"><?= e($r['empf_name']) ?> · <span class="badge <?= $st['class'] ?>"><?= e($st['label']) ?></span><?= $r['mahnstufe'] ? ' · ' . e(MAHNSTUFEN[(int)$r['mahnstufe']][0]) . ' versendet' : '' ?>
                <?php if ($bezug && $bezug[1]): ?> · <?= e($bezug[0]) ?> <a href="?id=<?= (int)$bezug[1]['id'] ?>"><?= e($bezug[1]['nummer'] ?? '') ?></a><?php endif; ?></p>
        </div>
        <div class="re-aktionen">
            <a href="?id=<?= $r['id'] ?>&pdf=1" target="_blank" rel="noopener" class="btn btn-navy btn-sm"><?= $entwurf ? 'Vorschau (PDF)' : 'PDF' ?></a>
            <?php if ($entwurf && $d_bearbeiten): ?><a href="?id=<?= $r['id'] ?>&bearbeiten=1" class="btn btn-ghost-light btn-sm">Kopfdaten</a><?php endif; ?>
            <?php if (!$entwurf && $r['status'] !== 'storniert' && $d_ausstellen): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="senden"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-ghost-light btn-sm" <?= $r['empf_email'] || $r['user_id'] ? '' : 'disabled title="Keine E-Mail-Adresse"' ?>>Per E-Mail senden</button></form><?php endif; ?>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value" style="font-size: 1.5rem;"><?= moneyFormat($r['betrag_brutto']) ?></div><div class="kpi-label">Rechnungsbetrag<?= (float)$r['betrag_ust'] ? ' (inkl. ' . moneyFormat($r['betrag_ust']) . ' USt)' : '' ?></div></div>
        <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value" style="font-size: 1.5rem;"><?= moneyFormat($bezahlt) ?></div><div class="kpi-label">Bezahlt (<?= count($zahlungen) ?> Zahlung<?= count($zahlungen) === 1 ? '' : 'en' ?>)</div></div>
        <div class="kpi-card" style="--kpi-color: <?= bccomp($offen, '0', 2) > 0 && rechnungAnzeigeStatus($r) === 'ueberfaellig' ? '#EF4444' : '#C6A135' ?>;"><div class="kpi-value" style="font-size: 1.5rem;"><?= moneyFormat(in_array($r['status'], ['offen'], true) ? $offen : '0') ?></div><div class="kpi-label">Offen<?= $r['faellig_am'] ? ' · fällig ' . date('d.m.Y', strtotime($r['faellig_am'])) : '' ?></div></div>
    </div>

    <div class="re-grid">
        <div>
            <div class="table-card" id="positionen">
                <div class="table-card-header"><h2 class="table-card-title">Positionen</h2></div>
                <div style="overflow-x: auto;"><table class="data-table">
                    <thead><tr><th>Pos.</th><th>Beschreibung</th><th class="re-zahl">Menge</th><th class="re-zahl">Einzelpreis</th><th class="re-zahl">USt</th><th class="re-zahl">Betrag</th><?php if ($entwurf && $d_bearbeiten): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($pos as $p): ?>
                        <tr><td><?= (int)$p['pos'] ?></td><td><?= e($p['beschreibung']) ?></td><td class="re-zahl"><?= e(rtrim(rtrim(number_format((float)$p['menge'], 2, ',', '.'), '0'), ',')) ?> <?= e($p['einheit'] ?? '') ?></td>
                            <td class="re-zahl"><?= moneyFormat($p['einzelpreis']) ?></td><td class="re-zahl"><?= (float)$p['ust_satz'] ? e(rtrim(rtrim((string)$p['ust_satz'], '0'), '.')) . ' %' : '–' ?></td><td class="re-zahl"><?= moneyFormat($p['betrag']) ?></td>
                            <?php if ($entwurf && $d_bearbeiten): ?><td><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="position_loeschen"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="pos_id" value="<?= $p['id'] ?>"><button class="btn btn-ghost-light btn-sm" aria-label="Position löschen">✕</button></form></td><?php endif; ?></tr>
                    <?php endforeach; ?>
                    <?php if (!$pos): ?><tr><td colspan="7" class="re-mini">Noch keine Positionen.</td></tr><?php endif; ?>
                    <tr class="re-summe"><td colspan="5" class="re-zahl">Gesamt</td><td class="re-zahl"><?= moneyFormat($r['betrag_brutto']) ?></td><?php if ($entwurf && $d_bearbeiten): ?><td></td><?php endif; ?></tr>
                    </tbody>
                </table></div>
                <?php if ($entwurf && $d_bearbeiten): ?>
                <form method="POST" class="re-inline"><?= csrfField() ?><input type="hidden" name="action" value="position"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <div class="form-group" style="flex: 1; min-width: 200px;"><label class="form-label">Beschreibung</label><input class="form-control" name="beschreibung" maxlength="300" required></div>
                    <div class="form-group"><label class="form-label">Menge</label><input class="form-control" name="menge" value="1" inputmode="decimal" style="width: 80px;"></div>
                    <div class="form-group"><label class="form-label">Einheit</label><input class="form-control" name="einheit" maxlength="20" placeholder="Std." style="width: 80px;"></div>
                    <div class="form-group"><label class="form-label">Einzelpreis €</label><input class="form-control" name="einzelpreis" inputmode="decimal" required style="width: 110px;"></div>
                    <div class="form-group"><label class="form-label">USt %</label><input class="form-control" name="ust_satz" value="0" inputmode="decimal" style="width: 70px;"></div>
                    <button class="btn btn-navy btn-sm">Hinzufügen</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (!$entwurf && $r['typ'] === 'rechnung'): ?>
            <div class="table-card" id="zahlungen" style="margin-top: 1.5rem;">
                <div class="table-card-header"><h2 class="table-card-title">Zahlungen</h2></div>
                <?php if ($zahlungen): ?><div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Datum</th><th>Art</th><th>Referenz</th><th class="re-zahl">Betrag</th><th></th></tr></thead><tbody>
                    <?php foreach ($zahlungen as $z): ?><tr><td><?= date('d.m.Y', strtotime($z['datum'])) ?></td><td><?= e(ZAHLUNGSARTEN[$z['zahlungsart']] ?? $z['zahlungsart']) ?></td><td><?= e($z['referenz'] ?? '') ?><div class="re-mini"><?= e(trim(($z['vorname'] ?? '') . ' ' . ($z['nachname'] ?? ''))) ?></div></td><td class="re-zahl"><?= moneyFormat($z['betrag']) ?></td>
                        <td><?php if ($d_zahlung && $r['status'] !== 'storniert'): ?><form method="POST" onsubmit="return confirm('Zahlung zurücknehmen?');"><?= csrfField() ?><input type="hidden" name="action" value="zahlung_loeschen"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="zahlung_id" value="<?= $z['id'] ?>"><button class="btn btn-ghost-light btn-sm" aria-label="Zurücknehmen">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
                </tbody></table></div><?php else: ?><p class="re-mini" style="padding: 1rem 1.25rem;">Noch keine Zahlung.</p><?php endif; ?>
                <?php if ($d_zahlung && $r['status'] === 'offen'): ?>
                <form method="POST" class="re-inline"><?= csrfField() ?><input type="hidden" name="action" value="zahlung"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <div class="form-group"><label class="form-label">Datum</label><input class="form-control" type="date" name="datum" value="<?= date('Y-m-d') ?>"></div>
                    <div class="form-group"><label class="form-label">Betrag €</label><input class="form-control" name="betrag" inputmode="decimal" value="<?= e(number_format((float)$offen, 2, ',', '')) ?>" style="width: 110px;"></div>
                    <div class="form-group"><label class="form-label">Art</label><select class="form-control" name="zahlungsart"><?php foreach (ZAHLUNGSARTEN as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group" style="flex: 1; min-width: 140px;"><label class="form-label">Referenz</label><input class="form-control" name="referenz" maxlength="120" placeholder="Kontoauszug, Beleg …"></div>
                    <button class="btn btn-navy btn-sm">Zahlung erfassen</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="table-card">
                <div class="table-card-header"><h2 class="table-card-title">Empfänger</h2></div>
                <div style="padding: 1rem 1.25rem; font-size: 0.9rem; line-height: 1.6;">
                    <?= nl2br(e(implode("\n", array_filter([$r['empf_name'], $r['empf_zusatz'], $r['empf_strasse'], trim(($r['empf_plz'] ?? '') . ' ' . ($r['empf_ort'] ?? '')), $r['empf_land']])))) ?>
                    <?php if ($r['empf_email']): ?><div class="re-mini"><?= e($r['empf_email']) ?></div><?php endif; ?>
                    <?php if ($r['leistung_von']): ?><div class="re-mini" style="margin-top: 0.5rem;">Leistung: <?= date('d.m.Y', strtotime($r['leistung_von'])) ?><?= $r['leistung_bis'] ? ' – ' . date('d.m.Y', strtotime($r['leistung_bis'])) : '' ?></div><?php endif; ?>
                    <?php if (!$entwurf && $r['zugriff_token']): ?><div class="re-mini" style="margin-top: 0.5rem;">Online-Link: <a href="<?= e(rechnungLink($r)) ?>" target="_blank" rel="noopener">öffnen</a></div><?php endif; ?>
                </div>
            </div>

            <?php if ($entwurf && $d_ausstellen): ?>
            <form method="POST" class="table-card" style="margin-top: 1.5rem; padding: 1.25rem;" onsubmit="return confirm('Rechnung ausstellen? Danach sind keine Änderungen mehr möglich.');"><?= csrfField() ?><input type="hidden" name="action" value="ausstellen"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <h2 class="table-card-title" style="margin-bottom: 0.75rem;">Ausstellen</h2>
                <div class="form-group"><label class="form-label">Rechnungsdatum</label><input class="form-control" type="date" name="rechnungsdatum" value="<?= date('Y-m-d') ?>"></div>
                <label class="form-check" style="margin-bottom: 0.75rem;"><input type="checkbox" name="gleich_senden" value="1" <?= $r['empf_email'] || $r['user_id'] ? 'checked' : 'disabled' ?>><span class="form-check-label">gleich per E-Mail senden</span></label>
                <button class="btn btn-primary w-full" <?= $pos ? '' : 'disabled' ?>>Prüfen &amp; ausstellen</button>
                <p class="re-mini" style="margin-top: 0.5rem;">Vergibt die nächste Rechnungsnummer. Danach nur noch Storno möglich.</p>
            </form>
            <?php endif; ?>
            <?php if ($entwurf && $d_bearbeiten): ?>
            <form method="POST" style="margin-top: 0.75rem;" onsubmit="return confirm('Entwurf löschen?');"><?= csrfField() ?><input type="hidden" name="action" value="entwurf_loeschen"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-ghost-light btn-sm w-full">Entwurf löschen</button></form>
            <?php endif; ?>

            <?php if ($r['typ'] === 'rechnung' && in_array($r['status'], ['offen', 'bezahlt'], true) && $d_ausstellen): ?>
            <div class="table-card" id="mahnungen" style="margin-top: 1.5rem;">
                <div class="table-card-header"><h2 class="table-card-title">Mahnwesen</h2></div>
                <?php foreach ($mahnungen as $m): ?>
                <div style="padding: 0.7rem 1.25rem; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    <div><strong><?= e(MAHNSTUFEN[(int)$m['stufe']][0]) ?></strong> <span class="badge <?= ['vorbereitet' => 'badge-warning', 'versendet' => 'badge-success', 'verworfen' => 'badge-gray'][$m['status']] ?>"><?= e($m['status']) ?></span>
                        <div class="re-mini"><?= moneyFormat($m['offener_betrag']) ?> offen · <?= $m['versendet_am'] ? 'versendet ' . date('d.m.Y H:i', strtotime($m['versendet_am'])) : 'vorbereitet ' . date('d.m.Y', strtotime($m['created_at'])) . ($m['erstellt_von'] ? '' : ' (automatisch)') ?></div></div>
                    <?php if ($m['status'] === 'vorbereitet' && $r['status'] === 'offen'): ?><div class="re-aktionen">
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="mahnung_senden"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="mahnung_id" value="<?= $m['id'] ?>"><button class="btn btn-navy btn-sm">Senden</button></form>
                        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="mahnung_verwerfen"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="mahnung_id" value="<?= $m['id'] ?>"><button class="btn btn-ghost-light btn-sm">Verwerfen</button></form>
                    </div><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if ($r['status'] === 'offen' && count($mahnungen) < 3): ?>
                <form method="POST" style="padding: 0.9rem 1.25rem;"><?= csrfField() ?><input type="hidden" name="action" value="mahnung_vorbereiten"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-ghost-light btn-sm">Nächste Mahnstufe vorbereiten</button></form>
                <?php elseif (!$mahnungen): ?><p class="re-mini" style="padding: 1rem 1.25rem;">Keine Mahnungen.</p><?php endif; ?>
            </div>
            <form method="POST" class="table-card" style="margin-top: 1.5rem; padding: 1.25rem;" onsubmit="return confirm('Rechnung stornieren? Es wird eine Stornorechnung erstellt.');"><?= csrfField() ?><input type="hidden" name="action" value="stornieren"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <h2 class="table-card-title" style="margin-bottom: 0.75rem;">Stornieren</h2>
                <div class="form-group"><label class="form-label">Grund</label><input class="form-control" name="grund" maxlength="200" required placeholder="z.B. falscher Betrag, Kursabsage"></div>
                <button class="btn btn-ghost-light btn-sm">Stornorechnung erstellen</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

<?php else:
    $f_status = isset(RECHNUNG_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
    $f_suche = mb_substr(trim($_GET['suche'] ?? ''), 0, 60);
    $jahr = (int)($_GET['jahr'] ?? date('Y'));
    $seite = max(1, (int)($_GET['seite'] ?? 1));
    $where = 'r.organization_id = ?';
    $p = [$org_id];
    if ($f_status === 'ueberfaellig') { $where .= " AND r.status = 'offen' AND r.faellig_am < ?"; $p[] = date('Y-m-d'); }
    elseif ($f_status === 'faellig') { $where .= " AND r.status = 'offen' AND r.faellig_am = ?"; $p[] = date('Y-m-d'); }
    elseif ($f_status) { $where .= ' AND r.status = ?'; $p[] = $f_status; }
    if ($f_suche !== '') { $where .= ' AND (r.nummer LIKE ? OR r.empf_name LIKE ?)'; array_push($p, "%$f_suche%", "%$f_suche%"); }
    if (!$f_status || !in_array($f_status, ['entwurf', 'offen', 'ueberfaellig', 'faellig'], true)) { $where .= ' AND (r.rechnungsdatum IS NULL OR r.rechnungsdatum BETWEEN ? AND ?)'; array_push($p, "$jahr-01-01", "$jahr-12-31"); }
    $s = $db->prepare("SELECT COUNT(*) FROM rechnungen r WHERE $where");
    $s->execute($p);
    $gesamt = (int)$s->fetchColumn();
    $s = $db->prepare("SELECT r.*, (SELECT COALESCE(SUM(z.betrag), 0) FROM zahlungen z WHERE z.rechnung_id = r.id) AS bezahlt FROM rechnungen r WHERE $where ORDER BY (r.nummer IS NULL) DESC, r.rechnungsdatum DESC, r.id DESC LIMIT 50 OFFSET " . (($seite - 1) * 50));
    $s->execute($p);
    $liste = $s->fetchAll();
    $kpi = rechnungenOffenSumme($db);
    $s = $db->prepare("SELECT COALESCE(SUM(z.betrag), 0) FROM zahlungen z WHERE z.organization_id = ? AND z.datum BETWEEN ? AND ?");
    $s->execute([$org_id, "$jahr-01-01", "$jahr-12-31"]);
    $eingang = moneyRound($s->fetchColumn());
    $s = $db->prepare("SELECT COUNT(*) FROM rechnungen WHERE organization_id = ? AND status = 'entwurf'");
    $s->execute([$org_id]);
    $entwuerfe = (int)$s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(*) FROM mahnungen m JOIN rechnungen r ON r.id = m.rechnung_id WHERE r.organization_id = ? AND m.status = 'vorbereitet'");
    $s->execute([$org_id]);
    $mahn_offen = (int)$s->fetchColumn();
    $qs = fn($mehr) => '?' . http_build_query(array_filter(array_merge(['status' => $f_status, 'suche' => $f_suche, 'jahr' => $jahr], $mehr), fn($v) => $v !== '' && $v !== null)); ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div><h1 class="dashboard-title">Rechnungen</h1><p class="dashboard-subtitle">Entwürfe prüfen, ausstellen, Zahlungen verbuchen und mahnen.</p></div>
        <?php if ($d_bearbeiten): ?><a href="?neu=1" class="btn btn-primary btn-sm">+ Neue Rechnung</a><?php endif; ?>
    </div>
    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value" style="font-size: 1.5rem;"><?= moneyFormat($kpi['offen']) ?></div><div class="kpi-label">Offen (<?= $kpi['anzahl'] ?> Rechnungen)</div></div>
        <div class="kpi-card" style="--kpi-color: #EF4444;"><div class="kpi-value" style="font-size: 1.5rem;"><?= moneyFormat($kpi['ueberfaellig']) ?></div><div class="kpi-label">Überfällig (<?= $kpi['anzahl_ueberfaellig'] ?>)</div></div>
        <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value" style="font-size: 1.5rem;"><?= moneyFormat($eingang) ?></div><div class="kpi-label">Zahlungseingang <?= $jahr ?></div></div>
        <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value" style="font-size: 1.5rem;"><?= $entwuerfe ?> / <?= $mahn_offen ?></div><div class="kpi-label">Entwürfe / Mahnungen zu versenden</div></div>
    </div>
    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
        <input class="form-control" style="max-width: 240px;" name="suche" value="<?= e($f_suche) ?>" placeholder="Nummer oder Empfänger …">
        <select class="form-control" style="max-width: 180px;" name="status"><option value="">Alle Status</option><?php foreach (RECHNUNG_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select>
        <select class="form-control" style="max-width: 110px;" name="jahr"><?php for ($j = (int)date('Y') + 1; $j >= (int)date('Y') - 5; $j--): ?><option <?= $j === $jahr ? 'selected' : '' ?>><?= $j ?></option><?php endfor; ?></select>
        <button class="btn btn-navy btn-sm">Filtern</button>
    </form>
    <div class="table-card">
        <?php if (!$liste): ?><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Rechnungen</h3><p>Neue Rechnung anlegen oder aus Kursanmeldungen erzeugen (Kursseite → Rechnungen).</p></div>
        <?php else: ?><div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Nummer</th><th>Empfänger</th><th>Datum</th><th>Fällig</th><th class="re-zahl">Betrag</th><th class="re-zahl">Offen</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($liste as $r): $st = $st_anzeige($r); $of = $r['status'] === 'offen' ? bcsub(moneyRound($r['betrag_brutto']), moneyRound($r['bezahlt']), 2) : '0.00'; ?>
                <tr data-row-href="?id=<?= $r['id'] ?>">
                    <td class="text-primary"><?= e($r['nummer'] ?: 'Entwurf #' . $r['id']) ?><?= $r['typ'] === 'storno' ? ' <span class="badge badge-navy">Storno</span>' : '' ?></td>
                    <td><?= e($r['empf_name']) ?></td>
                    <td><?= $r['rechnungsdatum'] ? date('d.m.Y', strtotime($r['rechnungsdatum'])) : '–' ?></td>
                    <td><?= $r['faellig_am'] ? date('d.m.Y', strtotime($r['faellig_am'])) : '–' ?></td>
                    <td class="re-zahl"><?= moneyFormat($r['betrag_brutto']) ?></td>
                    <td class="re-zahl"><?= bccomp($of, '0', 2) > 0 ? moneyFormat($of) : '–' ?></td>
                    <td><span class="badge <?= $st['class'] ?>"><?= e($st['label']) ?></span><?= $r['mahnstufe'] ? '<div class="re-mini">' . e(MAHNSTUFEN[(int)$r['mahnstufe']][0]) . '</div>' : '' ?></td>
                </tr><?php endforeach; ?></tbody></table></div>
            <?php if ($gesamt > 50): ?><div style="display: flex; justify-content: center; gap: 0.5rem; padding: 1rem;"><?php if ($seite > 1): ?><a class="btn btn-ghost-light btn-sm" href="<?= e($qs(['seite' => $seite - 1])) ?>">‹</a><?php endif; ?><span class="re-mini">Seite <?= $seite ?> / <?= (int)ceil($gesamt / 50) ?></span><?php if ($seite * 50 < $gesamt): ?><a class="btn btn-ghost-light btn-sm" href="<?= e($qs(['seite' => $seite + 1])) ?>">›</a><?php endif; ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
