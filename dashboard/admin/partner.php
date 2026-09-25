<?php
/**
 * Athletikclub Steiermark – Partner & CRM
 * Gemeinden, Schulen, Kindergärten, Unternehmen, Sponsoren, Verbände: Stammdaten,
 * Kontaktverlauf mit Wiedervorlage, verknüpfte Projekte, Verträge und Dokumente.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireDarf('partner.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$self   = APP_URL . '/dashboard/admin/partner.php';
$kontakt_arten = ['telefon' => 'Telefonat', 'email' => 'E-Mail', 'treffen' => 'Treffen', 'sonstiges' => 'Sonstiges'];
$status_liste  = ['aktiv' => ['Aktiv', 'badge-success'], 'potenziell' => ['Potenziell', 'badge-info'], 'inaktiv' => ['Inaktiv', 'badge-gray']];

function partnerLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT o.*, u.vorname AS verantw_vorname, u.nachname AS verantw_nachname FROM partner_organisationen o
                          LEFT JOIN users u ON u.id = o.verantwortlich_id WHERE o.id = ? AND o.organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $partner = $id ? partnerLaden($db, $id) : null;
    if ($id && !$partner) redirect($self);
    $ziel = $id ? "$self?id=$id" : $self;
    $recht = ['speichern' => $id ? 'partner.bearbeiten' : 'partner.erstellen', 'kontakt' => 'partner.bearbeiten', 'kontakt_loeschen' => 'partner.bearbeiten',
              'dokument' => 'partner.bearbeiten', 'loeschen' => 'partner.loeschen'][$action] ?? null;
    if (!$recht || !darf($recht)) { flashMessage('error', 'Keine Berechtigung für diese Aktion.'); redirect($ziel); }

    $datum = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$f] ?? '') ? $_POST[$f] : null;
    $txt   = fn($f, $max) => mb_substr(trim($_POST[$f] ?? ''), 0, $max) ?: null;

    if ($action === 'speichern') {
        $d = [
            'name' => $txt('name', 200), 'kategorie' => isset(PARTNER_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : 'sonstiges',
            'status' => isset($status_liste[$_POST['status'] ?? '']) ? $_POST['status'] : 'aktiv',
            'ansprechpartner' => $txt('ansprechpartner', 150), 'funktion' => $txt('funktion', 100), 'telefon' => $txt('telefon', 40),
            'email' => $txt('email', 180), 'strasse' => $txt('strasse', 200), 'plz' => $txt('plz', 10), 'ort' => $txt('ort', 100),
            'website' => $txt('website', 255), 'notizen' => trim($_POST['notizen'] ?? '') ?: null, 'naechster_kontakt' => $datum('naechster_kontakt'),
            'kooperation_id' => (int)($_POST['kooperation_id'] ?? 0) ?: null, 'verantwortlich_id' => (int)($_POST['verantwortlich_id'] ?? 0) ?: null,
        ];
        if (!$d['name']) { flashMessage('error', 'Bitte einen Namen eingeben.'); redirect($id ? "$self?id=$id&bearbeiten=1" : "$self?neu=1"); }
        if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { flashMessage('error', 'E-Mail-Adresse ungültig.'); redirect($id ? "$self?id=$id&bearbeiten=1" : "$self?neu=1"); }
        if ($d['website'] && !preg_match('#^https?://#i', $d['website'])) $d['website'] = 'https://' . $d['website'];
        if ($d['website'] && !filter_var($d['website'], FILTER_VALIDATE_URL)) $d['website'] = null;
        if ($partner) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $db->prepare("UPDATE partner_organisationen SET $sets WHERE id = ?")->execute([...array_values($d), $id]);
            auditLog('geaendert', 'partner_organisationen', $id, $partner, $d, $d['name']);
        } else {
            $d += ['organization_id' => $org_id, 'erstellt_von' => $me];
            $db->prepare('INSERT INTO partner_organisationen (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'partner_organisationen', $id, null, $d, $d['name']);
        }
        if ($d['verantwortlich_id'] && $d['verantwortlich_id'] !== $me && (!$partner || (int)$partner['verantwortlich_id'] !== $d['verantwortlich_id'])) {
            benachrichtigen($d['verantwortlich_id'], 'projekt', 'Du betreust jetzt den Partner „' . $d['name'] . '“', null, '/dashboard/admin/partner.php?id=' . $id);
        }
        flashMessage('success', 'Partner gespeichert.');
        redirect("$self?id=$id");
    }

    if ($action === 'kontakt') {
        $k_datum = $datum('datum') ?? date('Y-m-d');
        $art = isset($kontakt_arten[$_POST['art'] ?? '']) ? $_POST['art'] : 'sonstiges';
        $db->prepare('INSERT INTO partner_kontakte (partner_id, datum, art, notiz, user_id) VALUES (?, ?, ?, ?, ?)')->execute([$id, $k_datum, $art, trim($_POST['notiz'] ?? '') ?: null, $me]);
        $neu = ['letzter_kontakt' => max((string)$partner['letzter_kontakt'], $k_datum), 'naechster_kontakt' => $datum('naechster_kontakt')];
        $db->prepare('UPDATE partner_organisationen SET letzter_kontakt = ?, naechster_kontakt = ? WHERE id = ?')->execute([$neu['letzter_kontakt'], $neu['naechster_kontakt'], $id]);
        auditLog('erstellt', 'partner_kontakte', $id, null, ['datum' => $k_datum, 'art' => $art] + $neu, $partner['name']);
        flashMessage('success', 'Kontakt eingetragen.');
        redirect($ziel);
    }

    if ($action === 'kontakt_loeschen') {
        $db->prepare('DELETE FROM partner_kontakte WHERE id = ? AND partner_id = ?')->execute([(int)($_POST['kontakt_id'] ?? 0), $id]);
        auditLog('geloescht', 'partner_kontakte', (int)($_POST['kontakt_id'] ?? 0), null, null, $partner['name']);
        redirect($ziel);
    }

    if ($action === 'dokument') {
        $r = plattformPdfUpload($db, $_FILES['datei'] ?? [], trim($_POST['titel'] ?? ''), 'sonstiges', ['partner_id' => $id], 'admin');
        flashMessage(isset($r['fehler']) ? 'error' : 'success', $r['fehler'] ?? 'Dokument hochgeladen.');
        redirect($ziel);
    }

    if ($action === 'loeschen') {
        // Mit Verträgen oder Projekten nur archivieren, damit keine Verknüpfungen verloren gehen
        $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM vertraege WHERE partner_id = ?) + (SELECT COUNT(*) FROM projekt_partner WHERE partner_id = ?) + (SELECT COUNT(*) FROM dokumente WHERE partner_id = ?)');
        $stmt->execute([$id, $id, $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $db->prepare("UPDATE partner_organisationen SET status = 'inaktiv' WHERE id = ?")->execute([$id]);
            auditLog('geaendert', 'partner_organisationen', $id, ['status' => $partner['status']], ['status' => 'inaktiv'], $partner['name']);
            flashMessage('info', 'Der Partner hat Verträge, Projekte oder Dokumente und wurde daher auf „inaktiv“ gesetzt statt gelöscht.');
            redirect($ziel);
        }
        $db->prepare('DELETE FROM partner_organisationen WHERE id = ?')->execute([$id]);
        auditLog('geloescht', 'partner_organisationen', $id, $partner, null, $partner['name']);
        flashMessage('success', 'Partner gelöscht.');
        redirect($self);
    }
    redirect($ziel);
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$detail = !empty($_GET['id']) ? partnerLaden($db, (int)$_GET['id']) : null;
$formular = (!empty($_GET['neu']) && darf('partner.erstellen')) || ($detail && !empty($_GET['bearbeiten']) && darf('partner.bearbeiten'));

$kooperationen = [];
try {
    $stmt = $db->prepare('SELECT id, gemeinde_name FROM kooperationen WHERE organization_id = ? ORDER BY gemeinde_name');
    $stmt->execute([$org_id]);
    $kooperationen = $stmt->fetchAll();
} catch (Exception $e) {}

if ($detail) {
    $stmt = $db->prepare('SELECT k.*, u.vorname, u.nachname FROM partner_kontakte k LEFT JOIN users u ON u.id = k.user_id WHERE k.partner_id = ? ORDER BY k.datum DESC, k.id DESC');
    $stmt->execute([$detail['id']]);
    $kontakte = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT pp.rolle, p.id, p.name, p.status FROM projekt_partner pp JOIN projekte p ON p.id = pp.projekt_id WHERE pp.partner_id = ? ORDER BY p.name');
    $stmt->execute([$detail['id']]);
    $projekte = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT * FROM vertraege WHERE partner_id = ? AND organization_id = ? ORDER BY beginn DESC');
    $stmt->execute([$detail['id'], $org_id]);
    $vertraege = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT id, titel, datei_name, created_at FROM dokumente WHERE partner_id = ? AND organization_id = ? ORDER BY created_at DESC');
    $stmt->execute([$detail['id'], $org_id]);
    $dokumente = $stmt->fetchAll();
} else {
    $f_kat = isset(PARTNER_KATEGORIEN[$_GET['kategorie'] ?? '']) ? $_GET['kategorie'] : '';
    $f_status = isset($status_liste[$_GET['status'] ?? '']) ? $_GET['status'] : '';
    $f_suche = trim($_GET['suche'] ?? '');
    $sql = 'SELECT o.*, (SELECT COUNT(*) FROM projekt_partner pp WHERE pp.partner_id = o.id) AS n_projekte,
                   (SELECT COUNT(*) FROM vertraege v WHERE v.partner_id = o.id AND v.status = \'aktiv\') AS n_vertraege
            FROM partner_organisationen o WHERE o.organization_id = ?';
    $p = [$org_id];
    if ($f_kat) { $sql .= ' AND o.kategorie = ?'; $p[] = $f_kat; }
    if ($f_status) { $sql .= ' AND o.status = ?'; $p[] = $f_status; } else { $sql .= " AND o.status <> 'inaktiv'"; }
    if ($f_suche !== '') { $sql .= ' AND (o.name LIKE ? OR o.ansprechpartner LIKE ? OR o.ort LIKE ?)'; array_push($p, "%$f_suche%", "%$f_suche%", "%$f_suche%"); }
    $stmt = $db->prepare($sql . ' ORDER BY o.name');
    $stmt->execute($p);
    $liste = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT COUNT(*) FROM partner_organisationen WHERE organization_id = ? AND status <> 'inaktiv' AND naechster_kontakt IS NOT NULL AND naechster_kontakt <= ?");
    $stmt->execute([$org_id, date('Y-m-d')]);
    $faellig = (int)$stmt->fetchColumn();
}

$page_title = $detail ? $detail['name'] : 'Partner';
$breadcrumb = 'Partner';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$heute = date('Y-m-d');
?>

<style>
.pa-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; padding: 1.25rem; font-size: 0.875rem; }
.pa-mini { font-size: 0.78rem; color: var(--text-muted); }
.pa-faellig { color: #B91C1C; font-weight: 600; }
.pa-verlauf { padding: 0 1.25rem; }
.pa-kontakt { display: grid; grid-template-columns: 6.5rem 1fr auto; gap: 0.75rem; padding: 0.75rem 0; border-bottom: 1px solid var(--border-light); font-size: 0.875rem; }
.pa-kontakt:last-child { border-bottom: none; }
.pa-inline { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.pa-inline .form-group { margin: 0; }
@media (max-width: 600px) { .pa-kontakt { grid-template-columns: 1fr auto; } .pa-kontakt > :first-child { grid-column: 1 / -1; } }
</style>

<?php if ($formular): $f = $detail ?? ['kategorie' => 'gemeinde', 'status' => 'aktiv']; ?>
    <div class="dashboard-header">
        <a href="<?= $self . ($detail ? '?id=' . $detail['id'] : '') ?>" class="pa-mini">← zurück</a>
        <h1 class="dashboard-title"><?= $detail ? 'Partner bearbeiten' : 'Neuer Partner' ?></h1>
    </div>
    <form method="POST" class="form-card" style="max-width: 820px;">
        <?= csrfField() ?><input type="hidden" name="action" value="speichern"><input type="hidden" name="id" value="<?= (int)($detail['id'] ?? 0) ?>">
        <div class="form-row">
            <div class="form-group" style="flex: 2;"><label class="form-label">Name <span class="required">*</span></label><input class="form-control" name="name" maxlength="200" required value="<?= e($f['name'] ?? '') ?>" placeholder="z.B. Marktgemeinde Tillmitsch"></div>
            <div class="form-group"><label class="form-label">Kategorie</label><select class="form-control" name="kategorie"><?php foreach (PARTNER_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= ($f['kategorie'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status"><?php foreach ($status_liste as $k => [$l]): ?><option value="<?= $k ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Ansprechpartner:in</label><input class="form-control" name="ansprechpartner" maxlength="150" value="<?= e($f['ansprechpartner'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Funktion</label><input class="form-control" name="funktion" maxlength="100" value="<?= e($f['funktion'] ?? '') ?>" placeholder="z.B. Bürgermeister:in, Direktion"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Telefon</label><input class="form-control" name="telefon" maxlength="40" value="<?= e($f['telefon'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">E-Mail</label><input class="form-control" type="email" name="email" maxlength="180" value="<?= e($f['email'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Website</label><input class="form-control" name="website" maxlength="255" value="<?= e($f['website'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex: 2;"><label class="form-label">Straße</label><input class="form-control" name="strasse" maxlength="200" value="<?= e($f['strasse'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">PLZ</label><input class="form-control" name="plz" maxlength="10" value="<?= e($f['plz'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Ort</label><input class="form-control" name="ort" maxlength="100" value="<?= e($f['ort'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Betreut von</label><select class="form-control" name="verantwortlich_id"><option value="">–</option><?php foreach (plattformTeam($db) as $t): ?><option value="<?= $t['id'] ?>" <?= (int)($f['verantwortlich_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Nächster Kontakt (Wiedervorlage)</label><input class="form-control" type="date" name="naechster_kontakt" value="<?= e($f['naechster_kontakt'] ?? '') ?>"></div>
            <?php if ($kooperationen): ?>
            <div class="form-group"><label class="form-label">Gemeinde-Kooperation</label><select class="form-control" name="kooperation_id"><option value="">–</option><?php foreach ($kooperationen as $k): ?><option value="<?= $k['id'] ?>" <?= (int)($f['kooperation_id'] ?? 0) === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['gemeinde_name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
        </div>
        <div class="form-group"><label class="form-label">Notizen</label><textarea class="form-control" name="notizen" rows="4"><?= e($f['notizen'] ?? '') ?></textarea></div>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>

<?php elseif ($detail): $st = $status_liste[$detail['status']] ?? [$detail['status'], 'badge-gray']; ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <a href="<?= $self ?>" class="pa-mini">← Partner</a>
            <h1 class="dashboard-title"><?= e($detail['name']) ?></h1>
            <p class="dashboard-subtitle"><?= e(PARTNER_KATEGORIEN[$detail['kategorie']] ?? $detail['kategorie']) ?> · <span class="badge <?= $st[1] ?>"><?= e($st[0]) ?></span></p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if (darf('partner.bearbeiten')): ?><a href="?id=<?= $detail['id'] ?>&bearbeiten=1" class="btn btn-navy btn-sm">Bearbeiten</a><?php endif; ?>
            <?php if (darf('vertraege.erstellen')): ?><a href="<?= APP_URL ?>/dashboard/admin/vertraege.php?neu=1&partner=<?= $detail['id'] ?>" class="btn btn-ghost-light btn-sm">+ Vertrag</a><?php endif; ?>
            <?php if (darf('partner.loeschen')): ?><form method="POST" onsubmit="return confirm('Partner löschen? Mit Verträgen/Projekten wird er nur auf inaktiv gesetzt.');"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><button class="btn btn-ghost-light btn-sm">Löschen</button></form><?php endif; ?>
        </div>
    </div>

    <div class="table-card">
        <div class="pa-info">
            <div><strong>Ansprechpartner:in</strong><br><?= e($detail['ansprechpartner'] ?: '–') ?><?= $detail['funktion'] ? '<div class="pa-mini">' . e($detail['funktion']) . '</div>' : '' ?></div>
            <div><strong>Kontakt</strong><br><?= $detail['telefon'] ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $detail['telefon'])) . '">' . e($detail['telefon']) . '</a><br>' : '' ?><?= $detail['email'] ? '<a href="mailto:' . e($detail['email']) . '">' . e($detail['email']) . '</a>' : '' ?><?= !$detail['telefon'] && !$detail['email'] ? '–' : '' ?></div>
            <div><strong>Adresse</strong><br><?= e(trim(($detail['strasse'] ?? '') . ', ' . ($detail['plz'] ?? '') . ' ' . ($detail['ort'] ?? ''), ' ,')) ?: '–' ?><?= $detail['website'] ? '<br><a href="' . e($detail['website']) . '" target="_blank" rel="noopener noreferrer">Website</a>' : '' ?></div>
            <div><strong>Betreut von</strong><br><?= $detail['verantw_vorname'] ? e($detail['verantw_vorname'] . ' ' . $detail['verantw_nachname']) : '–' ?></div>
            <div><strong>Letzter Kontakt</strong><br><?= plattformDatum($detail['letzter_kontakt']) ?></div>
            <div><strong>Nächster Kontakt</strong><br><span class="<?= $detail['naechster_kontakt'] && $detail['naechster_kontakt'] <= $heute ? 'pa-faellig' : '' ?>"><?= plattformDatum($detail['naechster_kontakt']) ?></span><?= $detail['naechster_kontakt'] ? '<div class="pa-mini">' . e(plattformFrist($detail['naechster_kontakt'])) . '</div>' : '' ?></div>
            <?php if ($detail['kooperation_id']): ?><div><strong>Kooperation</strong><br><a href="<?= APP_URL ?>/dashboard/admin/kooperation-detail.php?id=<?= (int)$detail['kooperation_id'] ?>">Gemeinde-Kooperation öffnen</a></div><?php endif; ?>
        </div>
        <?php if ($detail['notizen']): ?><div style="padding: 0 1.25rem 1.25rem; font-size: 0.875rem; line-height: 1.6;"><?= nl2br(e($detail['notizen'])) ?></div><?php endif; ?>
    </div>

    <div class="grid-2" style="display: grid; gap: 1.5rem; align-items: start; margin-top: 1.5rem;">
        <div class="table-card">
            <div class="table-card-header"><h2 class="table-card-title">Kontaktverlauf</h2></div>
            <div class="pa-verlauf">
                <?php if (!$kontakte): ?><p class="pa-mini" style="padding: 1rem 0;">Noch keine Kontakte dokumentiert.</p><?php endif; ?>
                <?php foreach ($kontakte as $k): ?>
                <div class="pa-kontakt">
                    <div><strong><?= date('d.m.Y', strtotime($k['datum'])) ?></strong><div class="pa-mini"><?= e($kontakt_arten[$k['art']] ?? $k['art']) ?></div></div>
                    <div><?= nl2br(e($k['notiz'] ?? '')) ?><div class="pa-mini"><?= $k['vorname'] ? e($k['vorname'] . ' ' . $k['nachname']) : '' ?></div></div>
                    <?php if (darf('partner.bearbeiten')): ?><form method="POST" onsubmit="return confirm('Eintrag löschen?');"><?= csrfField() ?><input type="hidden" name="action" value="kontakt_loeschen"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><input type="hidden" name="kontakt_id" value="<?= $k['id'] ?>"><button class="btn btn-ghost-light btn-sm" aria-label="Löschen">✕</button></form><?php else: ?><span></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (darf('partner.bearbeiten')): ?>
            <form method="POST" class="pa-inline"><?= csrfField() ?><input type="hidden" name="action" value="kontakt"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
                <div class="form-group"><label class="form-label">Datum</label><input class="form-control" type="date" name="datum" value="<?= $heute ?>" max="<?= $heute ?>"></div>
                <div class="form-group"><label class="form-label">Art</label><select class="form-control" name="art"><?php foreach ($kontakt_arten as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="form-group" style="flex: 1 1 100%;"><label class="form-label">Notiz</label><textarea class="form-control" name="notiz" rows="2" placeholder="Was wurde besprochen?"></textarea></div>
                <div class="form-group"><label class="form-label">Wiedervorlage</label><input class="form-control" type="date" name="naechster_kontakt" min="<?= $heute ?>"></div>
                <button type="submit" class="btn btn-navy btn-sm">Eintragen</button>
            </form>
            <?php endif; ?>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <div class="table-card">
                <div class="table-card-header"><h2 class="table-card-title">Projekte</h2></div>
                <?php if (!$projekte): ?><p class="pa-mini" style="padding: 1rem 1.25rem;">Noch keinem Projekt zugeordnet (Zuordnung im Projekt unter „Partner“).</p>
                <?php else: ?><table class="data-table"><tbody><?php foreach ($projekte as $p): ?><tr data-row-href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= $p['id'] ?>&tab=partner"><td class="text-primary"><?= e($p['name']) ?><div class="pa-mini"><?= e($p['rolle'] ?? '') ?></div></td><td><span class="badge <?= PROJEKT_STATUS[$p['status']]['class'] ?? 'badge-gray' ?>"><?= e(PROJEKT_STATUS[$p['status']]['label'] ?? $p['status']) ?></span></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            </div>
            <div class="table-card">
                <div class="table-card-header"><h2 class="table-card-title">Verträge</h2></div>
                <?php if (!$vertraege): ?><p class="pa-mini" style="padding: 1rem 1.25rem;">Keine Verträge.</p>
                <?php else: ?><table class="data-table"><tbody><?php foreach ($vertraege as $v): $vs = VERTRAG_STATUS[$v['status']] ?? ['label' => $v['status'], 'class' => 'badge-gray']; ?><tr data-row-href="<?= APP_URL ?>/dashboard/admin/vertraege.php?id=<?= $v['id'] ?>"><td class="text-primary"><?= e($v['titel']) ?><div class="pa-mini"><?= plattformDatum($v['beginn']) ?> – <?= $v['ende'] ? plattformDatum($v['ende']) : 'unbefristet' ?></div></td><td><span class="badge <?= $vs['class'] ?>"><?= e($vs['label']) ?></span></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            </div>
            <div class="table-card">
                <div class="table-card-header"><h2 class="table-card-title">Dokumente</h2></div>
                <?php if ($dokumente): ?><table class="data-table"><tbody><?php foreach ($dokumente as $d): ?><tr><td><a class="text-primary" href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $d['id'] ?>"><?= e($d['titel']) ?></a><div class="pa-mini"><?= date('d.m.Y', strtotime($d['created_at'])) ?></div></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
                <?php if (darf('partner.bearbeiten')): ?>
                <form method="POST" enctype="multipart/form-data" class="pa-inline"><?= csrfField() ?><input type="hidden" name="action" value="dokument"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
                    <div class="form-group" style="flex: 1; min-width: 160px;"><label class="form-label">Titel</label><input class="form-control" name="titel" maxlength="200" placeholder="z.B. Angebot 2027"></div>
                    <div class="form-group"><label class="form-label">PDF</label><input class="form-control" type="file" name="datei" accept="application/pdf,.pdf" required></div>
                    <button class="btn btn-navy btn-sm">Hochladen</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 class="dashboard-title">Partner &amp; CRM</h1>
            <p class="dashboard-subtitle">Gemeinden, Schulen, Kindergärten, Unternehmen, Sponsoren und Verbände.<?= $faellig ? ' <span class="pa-faellig">' . $faellig . ' Wiedervorlage(n) fällig.</span>' : '' ?></p>
        </div>
        <?php if (darf('partner.erstellen')): ?><a href="?neu=1" class="btn btn-primary btn-sm">+ Partner anlegen</a><?php endif; ?>
    </div>
    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
        <input class="form-control" style="max-width: 240px;" name="suche" value="<?= e($f_suche) ?>" placeholder="Name, Ansprechpartner, Ort …">
        <select class="form-control" style="max-width: 190px;" name="kategorie"><option value="">Alle Kategorien</option><?php foreach (PARTNER_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= $f_kat === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-control" style="max-width: 190px;" name="status"><option value="">Aktiv + potenziell</option><?php foreach ($status_liste as $k => [$l]): ?><option value="<?= $k ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-navy btn-sm">Filtern</button>
    </form>
    <div class="table-card">
        <?php if (!$liste): ?>
            <div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Partner gefunden</h3><p>Lege Gemeinden, Schulen oder Sponsoren an, um Kontakte, Verträge und Projekte zu verknüpfen.</p></div>
        <?php else: ?>
        <div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Name</th><th>Kategorie</th><th>Ansprechpartner:in</th><th>Letzter Kontakt</th><th>Wiedervorlage</th><th>Projekte</th><th>Verträge</th></tr></thead>
            <tbody>
            <?php foreach ($liste as $o): $st = $status_liste[$o['status']] ?? [$o['status'], 'badge-gray']; ?>
                <tr data-row-href="<?= $self ?>?id=<?= $o['id'] ?>">
                    <td class="text-primary"><?= e($o['name']) ?><?= $o['status'] !== 'aktiv' ? ' <span class="badge ' . $st[1] . '">' . e($st[0]) . '</span>' : '' ?><div class="pa-mini"><?= e($o['ort'] ?? '') ?></div></td>
                    <td><?= e(PARTNER_KATEGORIEN[$o['kategorie']] ?? $o['kategorie']) ?></td>
                    <td><?= e($o['ansprechpartner'] ?? '–') ?><div class="pa-mini"><?= e($o['telefon'] ?? '') ?></div></td>
                    <td><?= plattformDatum($o['letzter_kontakt']) ?></td>
                    <td class="<?= $o['naechster_kontakt'] && $o['naechster_kontakt'] <= $heute ? 'pa-faellig' : '' ?>"><?= plattformDatum($o['naechster_kontakt']) ?></td>
                    <td><?= (int)$o['n_projekte'] ?></td>
                    <td><?= (int)$o['n_vertraege'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
