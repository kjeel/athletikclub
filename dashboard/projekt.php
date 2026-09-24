<?php
/**
 * Athletikclub Steiermark – Projekt (Detail mit Tabs)
 * Übersicht · Aufgaben · Termine · Kurse · Team · Finanzen · Förderungen · Dokumente · Partner
 * Kosten entstehen automatisch aus freigegebenen Trainerabrechnungen (Einheiten des Projekts)
 * und werden der Standard-Förderung des Projekts zugerechnet.
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
$id     = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT p.*, u.vorname, u.nachname, f.titel AS foerderung_titel FROM projekte p LEFT JOIN users u ON u.id = p.leitung_id
                      LEFT JOIN foerderungen f ON f.id = p.foerderung_id WHERE p.id = ? AND p.organization_id = ?');
$stmt->execute([$id, $org_id]);
$p = $stmt->fetch();
$stmt = $db->prepare('SELECT 1 FROM projekt_team WHERE projekt_id = ? AND user_id = ?');
$stmt->execute([$id, $me]);
$im_team = (bool)$stmt->fetchColumn();
$ist_leitung = $p && (int)$p['leitung_id'] === $me;
if (!$p || !(darf('projekte.anzeigen') || $ist_leitung || $im_team)) {
    flashMessage('error', 'Projekt nicht gefunden.');
    redirect(APP_URL . '/dashboard/projekte.php');
}
$bearbeiten = darf('projekte.bearbeiten') || $ist_leitung;
$finanzen   = darf('finanzen.bearbeiten') || $bearbeiten;
$tabs = ['uebersicht' => 'Übersicht', 'aufgaben' => 'Aufgaben', 'termine' => 'Termine', 'kurse' => 'Kurse', 'team' => 'Team',
         'finanzen' => 'Finanzen', 'foerderungen' => 'Förderungen', 'dokumente' => 'Dokumente', 'partner' => 'Partner'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'uebersicht';
$self = APP_URL . "/dashboard/projekt.php?id={$id}";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $zurueck = $self . '&tab=' . ($_POST['tab'] ?? 'uebersicht');
    $braucht = ['stammdaten' => $bearbeiten, 'team_add' => $bearbeiten, 'team_remove' => $bearbeiten, 'kurs_zuordnen' => $bearbeiten, 'kurs_entfernen' => $bearbeiten,
                'partner_add' => $bearbeiten, 'partner_remove' => $bearbeiten, 'foerderung' => $bearbeiten, 'buchung_neu' => $finanzen, 'buchung_loeschen' => $finanzen,
                'dokument' => $bearbeiten || $im_team, 'archivieren' => darf('projekte.loeschen')];
    if (empty($braucht[$action])) { flashMessage('error', 'Keine Berechtigung für diese Aktion.'); redirect($zurueck); }

    switch ($action) {
        case 'stammdaten':
            $datum = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$f] ?? '') ? $_POST[$f] : null;
            $budget = trim(str_replace(',', '.', $_POST['budget'] ?? ''));
            $neu = ['name' => mb_substr(trim($_POST['name'] ?? ''), 0, 200) ?: $p['name'], 'beschreibung' => trim($_POST['beschreibung'] ?? '') ?: null,
                    'kategorie' => isset(PROJEKT_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : $p['kategorie'],
                    'status' => isset(PROJEKT_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : $p['status'],
                    'leitung_id' => (int)($_POST['leitung_id'] ?? 0) ?: null, 'start_datum' => $datum('start_datum'), 'end_datum' => $datum('end_datum'),
                    'gemeinde' => mb_substr(trim($_POST['gemeinde'] ?? ''), 0, 150) ?: null, 'budget' => is_numeric($budget) ? moneyRound($budget) : null,
                    'farbe' => preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['farbe'] ?? '') ? $_POST['farbe'] : null];
            $db->prepare('UPDATE projekte SET name = ?, beschreibung = ?, kategorie = ?, status = ?, leitung_id = ?, start_datum = ?, end_datum = ?, gemeinde = ?, budget = ?, farbe = ? WHERE id = ?')
               ->execute(array_merge(array_values($neu), [$id]));
            auditLog('geaendert', 'projekte', $id, $p, $neu, $neu['name']);
            if ($neu['leitung_id'] && (int)$neu['leitung_id'] !== (int)$p['leitung_id']) benachrichtigen((int)$neu['leitung_id'], 'projekt', "Du leitest jetzt das Projekt „{$neu['name']}“", null, '/dashboard/projekt.php?id=' . $id);
            flashMessage('success', 'Projekt gespeichert.');
            break;
        case 'team_add':
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid) {
                $db->prepare('DELETE FROM projekt_team WHERE projekt_id = ? AND user_id = ?')->execute([$id, $uid]);
                $db->prepare('INSERT INTO projekt_team (projekt_id, user_id, rolle) VALUES (?, ?, ?)')->execute([$id, $uid, mb_substr(trim($_POST['rolle'] ?? ''), 0, 80) ?: null]);
                benachrichtigen($uid, 'projekt', "Du bist jetzt im Team „{$p['name']}“", null, '/dashboard/projekt.php?id=' . $id);
                auditLog('geaendert', 'projekt_team', $id, null, ['user_id' => $uid, 'aktion' => 'hinzugefügt']);
            }
            break;
        case 'team_remove':
            $db->prepare('DELETE FROM projekt_team WHERE projekt_id = ? AND user_id = ?')->execute([$id, (int)($_POST['user_id'] ?? 0)]);
            auditLog('geaendert', 'projekt_team', $id, ['user_id' => (int)($_POST['user_id'] ?? 0)], ['aktion' => 'entfernt']);
            break;
        case 'kurs_zuordnen':
            $db->prepare('UPDATE kurse SET projekt_id = ? WHERE id = ? AND organization_id = ?')->execute([$id, (int)($_POST['kurs_id'] ?? 0), $org_id]);
            break;
        case 'kurs_entfernen':
            $db->prepare('UPDATE kurse SET projekt_id = NULL WHERE id = ? AND projekt_id = ?')->execute([(int)($_POST['kurs_id'] ?? 0), $id]);
            break;
        case 'partner_add':
            $pid = (int)($_POST['partner_id'] ?? 0);
            if ($pid) {
                $db->prepare('DELETE FROM projekt_partner WHERE projekt_id = ? AND partner_id = ?')->execute([$id, $pid]);
                $db->prepare('INSERT INTO projekt_partner (projekt_id, partner_id, rolle) VALUES (?, ?, ?)')->execute([$id, $pid, mb_substr(trim($_POST['rolle'] ?? ''), 0, 80) ?: null]);
            }
            break;
        case 'partner_remove':
            $db->prepare('DELETE FROM projekt_partner WHERE projekt_id = ? AND partner_id = ?')->execute([$id, (int)($_POST['partner_id'] ?? 0)]);
            break;
        case 'foerderung':
            $fid = (int)($_POST['foerderung_id'] ?? 0) ?: null;
            if ($fid) $db->prepare('UPDATE foerderungen SET projekt_id = ? WHERE id = ? AND organization_id = ?')->execute([$id, $fid, $org_id]);
            if (!empty($_POST['standard']) || !$p['foerderung_id']) {
                $db->prepare('UPDATE projekte SET foerderung_id = ? WHERE id = ?')->execute([$fid, $id]);
                auditLog('geaendert', 'projekte', $id, ['foerderung_id' => $p['foerderung_id']], ['foerderung_id' => $fid], 'Standard-Förderung');
            }
            flashMessage('success', 'Förderung verknüpft. Neue Trainerkosten dieses Projekts werden ihr zugerechnet.');
            break;
        case 'buchung_neu':
            $betrag = trim(str_replace(',', '.', $_POST['betrag'] ?? ''));
            if (!is_numeric($betrag) || (float)$betrag <= 0 || trim($_POST['beschreibung'] ?? '') === '') { flashMessage('error', 'Bitte Betrag und Beschreibung angeben.'); break; }
            $werte = ['datum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['datum'] ?? '') ? $_POST['datum'] : date('Y-m-d'), 'art' => ($_POST['art'] ?? '') === 'einnahme' ? 'einnahme' : 'ausgabe',
                      'betrag' => moneyRound($betrag), 'kategorie' => isset(BUCHUNG_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : 'sonstiges',
                      'beschreibung' => mb_substr(trim($_POST['beschreibung']), 0, 255), 'foerderung_id' => (int)($_POST['foerderung_id'] ?? 0) ?: null,
                      'belegnummer' => mb_substr(trim($_POST['belegnummer'] ?? ''), 0, 60) ?: null];
            $db->prepare('INSERT INTO buchungen (organization_id, datum, art, betrag, kategorie, beschreibung, projekt_id, foerderung_id, belegnummer, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$org_id, $werte['datum'], $werte['art'], $werte['betrag'], $werte['kategorie'], $werte['beschreibung'], $id, $werte['foerderung_id'], $werte['belegnummer'], $me]);
            $bid = (int)$db->lastInsertId();
            auditLog('erstellt', 'buchungen', $bid, null, $werte, $werte['beschreibung']);
            if (!empty($_FILES['beleg']['name'])) {
                $r = plattformPdfUpload($db, $_FILES['beleg'], 'Beleg ' . ($werte['belegnummer'] ?? $bid) . ': ' . $werte['beschreibung'], 'beleg', ['buchung_id' => $bid, 'projekt_id' => $id, 'foerderung_id' => $werte['foerderung_id']]);
                if (isset($r['fehler'])) flashMessage('error', 'Buchung gespeichert, Beleg nicht: ' . $r['fehler']);
            }
            break;
        case 'buchung_loeschen':
            $stmt = $db->prepare('SELECT * FROM buchungen WHERE id = ? AND projekt_id = ? AND trainer_abrechnung_id IS NULL');
            $stmt->execute([(int)($_POST['buchung_id'] ?? 0), $id]);
            if ($b = $stmt->fetch()) {
                $db->prepare('DELETE FROM buchungen WHERE id = ?')->execute([$b['id']]);
                auditLog('geloescht', 'buchungen', (int)$b['id'], $b, null, $b['beschreibung']);
            }
            break;
        case 'dokument':
            $r = plattformPdfUpload($db, $_FILES['datei'] ?? [], trim($_POST['titel'] ?? ''), 'projekt', ['projekt_id' => $id]);
            flashMessage(isset($r['fehler']) ? 'error' : 'success', $r['fehler'] ?? 'Dokument hochgeladen.');
            break;
        case 'archivieren':
            $db->prepare("UPDATE projekte SET status = 'archiviert' WHERE id = ?")->execute([$id]);
            auditLog('status', 'projekte', $id, ['status' => $p['status']], ['status' => 'archiviert'], $p['name']);
            flashMessage('success', 'Projekt archiviert.');
            break;
    }
    redirect($zurueck);
}

// ----------------------------------------------------------------
// Daten
// ----------------------------------------------------------------
$heute = date('Y-m-d');
$stmt = $db->prepare("SELECT status, COUNT(*) AS n FROM einheiten WHERE projekt_id = ? GROUP BY status");
$stmt->execute([$id]);
$einheit_status = array_column($stmt->fetchAll(), 'n', 'status');
$stmt = $db->prepare("SELECT COUNT(*) FROM anwesenheiten a JOIN einheiten e ON e.id = a.einheit_id WHERE e.projekt_id = ? AND a.status IN ('anwesend','probetraining')");
$stmt->execute([$id]);
$teilnahmen = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN art = 'einnahme' THEN betrag ELSE 0 END), 0) AS einnahmen, COALESCE(SUM(CASE WHEN art = 'ausgabe' THEN betrag ELSE 0 END), 0) AS ausgaben,
                             COALESCE(SUM(CASE WHEN art = 'ausgabe' AND kategorie = 'trainerhonorar' THEN betrag ELSE 0 END), 0) AS trainer FROM buchungen WHERE projekt_id = ?");
$stmt->execute([$id]);
$fin = $stmt->fetch();
// Noch nicht freigegebene Honorare (bestätigt, aber noch nicht als Kosten gebucht)
$stmt = $db->prepare("SELECT COALESCE(SUM(et.betrag), 0) FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id LEFT JOIN trainer_abrechnungen ta ON ta.id = et.abrechnung_id
                      WHERE e.projekt_id = ? AND et.status IN ('durchgefuehrt','zur_abrechnung') AND (ta.id IS NULL OR ta.status IN ('entwurf','eingereicht','geprueft'))");
$stmt->execute([$id]);
$honorar_offen = moneyRound($stmt->fetchColumn());
$stmt = $db->prepare('SELECT a.*, u.vorname, u.nachname FROM aufgaben a LEFT JOIN users u ON u.id = a.verantwortlich_id WHERE a.projekt_id = ?
                      ORDER BY CASE a.status WHEN \'erledigt\' THEN 1 ELSE 0 END, a.deadline IS NULL, a.deadline');
$stmt->execute([$id]);
$aufgaben = $stmt->fetchAll();
$offen_aufgaben = array_filter($aufgaben, fn($a) => $a['status'] !== 'erledigt');
$ueberfaellig = array_filter($offen_aufgaben, fn($a) => $a['deadline'] && $a['deadline'] < $heute);
$team = plattformTeam($db);

$page_title = $p['name'];
$breadcrumb = 'Projekte';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.pj-tabs { display: flex; gap: 0.25rem; overflow-x: auto; border-bottom: 1px solid var(--border-light); margin-bottom: 1.5rem; scrollbar-width: thin; }
.pj-tabs a { padding: 0.65rem 0.95rem; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); border-bottom: 3px solid transparent; white-space: nowrap; }
.pj-tabs a.aktiv { color: var(--navy-primary); border-bottom-color: var(--gold-accent); }
:root[data-theme="dark"] .pj-tabs a.aktiv { color: #E8EEF7; }
.pj-mini { font-size: 0.75rem; color: var(--text-muted); }
.zahl { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pj-inline { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.pj-inline .form-group { margin: 0; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/projekte.php" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Projekte
        </a>
        <h1 class="dashboard-title"><?= e($p['name']) ?> <span class="badge <?= PROJEKT_STATUS[$p['status']]['class'] ?>" style="vertical-align: middle; font-size: 0.7rem;"><?= PROJEKT_STATUS[$p['status']]['label'] ?></span></h1>
        <p class="dashboard-subtitle"><?= e(PROJEKT_KATEGORIEN[$p['kategorie']] ?? '') ?><?= $p['gemeinde'] ? ' · ' . e($p['gemeinde']) : '' ?><?= $p['vorname'] ? ' · Leitung ' . e($p['vorname'] . ' ' . $p['nachname']) : '' ?><?= $p['start_datum'] ? ' · ' . plattformDatum($p['start_datum']) . ($p['end_datum'] ? '–' . plattformDatum($p['end_datum']) : '') : '' ?></p>
    </div>
    <?php if (darfEines('kalender.erstellen') || $bearbeiten): ?><a href="<?= APP_URL ?>/dashboard/einheit-planen.php?projekt=<?= $id ?>" class="btn btn-navy btn-sm">+ Einheit planen</a><?php endif; ?>
</div>

<nav class="pj-tabs" aria-label="Projektbereiche">
    <?php foreach ($tabs as $k => $label): ?><a href="<?= $self ?>&tab=<?= $k ?>" class="<?= $tab === $k ? 'aktiv' : '' ?>"><?= e($label) ?><?= $k === 'aufgaben' && $offen_aufgaben ? ' (' . count($offen_aufgaben) . ')' : '' ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'uebersicht'): $budget = (float)$p['budget']; ?>
<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value"><?= (int)($einheit_status['durchgefuehrt'] ?? 0) ?> / <?= (int)(($einheit_status['durchgefuehrt'] ?? 0) + ($einheit_status['geplant'] ?? 0)) ?></div><div class="kpi-label">Einheiten durchgeführt</div></div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;"><div class="kpi-value"><?= $teilnahmen ?></div><div class="kpi-label">Teilnahmen<?= ($einheit_status['durchgefuehrt'] ?? 0) ? ' · Ø ' . number_format($teilnahmen / $einheit_status['durchgefuehrt'], 1, ',', '.') : '' ?></div></div>
    <div class="kpi-card" style="--kpi-color: #EF4444;"><div class="kpi-value"><?= count($offen_aufgaben) ?></div><div class="kpi-label">Offene Aufgaben<?= $ueberfaellig ? ' · ' . count($ueberfaellig) . ' überfällig' : '' ?></div></div>
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value"><?= moneyFormat($fin['ausgaben']) ?></div><div class="kpi-label">Kosten<?= $budget > 0 ? ' von ' . moneyFormat($budget) . ' Budget' : '' ?><?= bccomp($honorar_offen, '0', 2) > 0 ? ' · + ' . moneyFormat($honorar_offen) . ' Honorar offen' : '' ?></div></div>
</div>
<?php if ($p['beschreibung']): ?><div class="table-card" style="margin-bottom: 1.5rem;"><div style="padding: 1.1rem 1.25rem; white-space: pre-line; font-size: 0.9rem;"><?= e($p['beschreibung']) ?></div></div><?php endif; ?>
<?php if ($bearbeiten): ?>
<form method="POST" class="table-card">
    <?= csrfField() ?><input type="hidden" name="action" value="stammdaten"><input type="hidden" name="tab" value="uebersicht">
    <div class="table-card-header"><h2 class="table-card-title">Projektdaten</h2></div>
    <div style="padding: 1.25rem;">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Name</label><input class="form-control" type="text" name="name" maxlength="200" value="<?= e($p['name']) ?>"></div>
            <div class="form-group"><label class="form-label">Kategorie</label><select class="form-control" name="kategorie"><?php foreach (PROJEKT_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= $p['kategorie'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status"><?php foreach (PROJEKT_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Projektleitung</label><select class="form-control" name="leitung_id"><option value="">–</option><?php foreach ($team as $t): ?><option value="<?= $t['id'] ?>" <?= (int)$p['leitung_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Zeitraum</label><div style="display: flex; gap: 0.5rem;"><input class="form-control" type="date" name="start_datum" value="<?= e((string)$p['start_datum']) ?>"><input class="form-control" type="date" name="end_datum" value="<?= e((string)$p['end_datum']) ?>"></div></div>
            <div class="form-group"><label class="form-label">Gemeinde</label><input class="form-control" type="text" name="gemeinde" maxlength="150" value="<?= e((string)$p['gemeinde']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Budget (€)</label><input class="form-control" type="text" inputmode="decimal" name="budget" value="<?= $p['budget'] !== null ? number_format((float)$p['budget'], 2, ',', '') : '' ?>"></div>
            <div class="form-group"><label class="form-label">Farbe</label><input class="form-control" type="color" name="farbe" value="<?= e($p['farbe'] ?: '#C6A135') ?>" style="max-width: 90px; padding: 0.2rem;"></div>
        </div>
        <div class="form-group"><label class="form-label">Beschreibung</label><textarea class="form-control" name="beschreibung" rows="3"><?= e((string)$p['beschreibung']) ?></textarea></div>
        <button type="submit" class="btn btn-navy btn-sm">Speichern</button>
    </div>
</form>
<?php if (darf('projekte.loeschen') && $p['status'] !== 'archiviert'): ?>
<form method="POST" style="margin-top: 1rem;" onsubmit="return confirm('Projekt archivieren? Daten bleiben erhalten.')"><?= csrfField() ?><input type="hidden" name="action" value="archivieren"><button type="submit" class="btn btn-ghost-light btn-sm">Projekt archivieren</button></form>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($tab === 'aufgaben'): ?>
<div class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Aufgaben</h2><a href="<?= APP_URL ?>/dashboard/aufgaben.php?projekt=<?= $id ?>#neu" class="btn btn-navy btn-sm">+ Aufgabe</a></div>
    <?php if (!$aufgaben): ?><div style="padding: 1.25rem;" class="pj-mini">Noch keine Aufgaben.</div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Aufgabe</th><th>Verantwortlich</th><th>Priorität</th><th>Deadline</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($aufgaben as $a): $ueber = $a['status'] !== 'erledigt' && $a['deadline'] && $a['deadline'] < $heute; ?>
            <tr style="<?= $a['status'] === 'erledigt' ? 'opacity: 0.55;' : '' ?>"><td><a class="text-primary" href="<?= APP_URL ?>/dashboard/aufgaben.php?id=<?= $a['id'] ?>"><?= e($a['titel']) ?></a></td>
                <td><?= e($a['vorname'] ? $a['vorname'] . ' ' . $a['nachname'] : '–') ?></td>
                <td><span class="badge <?= AUFGABE_PRIO[$a['prioritaet']]['class'] ?>"><?= AUFGABE_PRIO[$a['prioritaet']]['label'] ?></span></td>
                <td style="<?= $ueber ? 'color: var(--danger); font-weight: 600;' : '' ?>"><?= plattformDatum($a['deadline']) ?><?= $ueber ? '<div class="pj-mini">überfällig</div>' : '' ?></td>
                <td><span class="badge <?= AUFGABE_STATUS[$a['status']]['class'] ?>"><?= AUFGABE_STATUS[$a['status']]['label'] ?></span></td></tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</div>

<?php elseif ($tab === 'termine'):
    $stmt = $db->prepare("SELECT e.*, (SELECT GROUP_CONCAT(u.vorname) FROM einheit_trainer et JOIN users u ON u.id = et.user_id WHERE et.einheit_id = e.id) AS trainer,
                                 (SELECT COUNT(*) FROM anwesenheiten a WHERE a.einheit_id = e.id AND a.status IN ('anwesend','probetraining')) AS tn
                          FROM einheiten e WHERE e.projekt_id = ? ORDER BY e.start");
    $stmt->execute([$id]);
    $einheiten = $stmt->fetchAll();
    $kommend = array_filter($einheiten, fn($e) => $e['start'] >= date('Y-m-d H:i:s'));
    $vergangen = array_reverse(array_filter($einheiten, fn($e) => $e['start'] < date('Y-m-d H:i:s'))); ?>
<?php foreach (['Kommende Einheiten' => $kommend, 'Vergangene Einheiten' => $vergangen] as $titel => $liste): ?>
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header"><h2 class="table-card-title"><?= $titel ?> (<?= count($liste) ?>)</h2></div>
    <?php if (!$liste): ?><div style="padding: 1.25rem;" class="pj-mini">Keine.</div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Datum</th><th>Einheit</th><th>Trainer:innen</th><th class="zahl">TN</th><th>Status</th></tr></thead>
        <tbody><?php foreach (array_slice($liste, 0, 60) as $e): ?>
            <tr data-row-href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $e['id'] ?>"><td><?= date('d.m.Y H:i', strtotime($e['start'])) ?></td><td><a class="text-primary" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= $e['id'] ?>"><?= e($e['titel']) ?></a></td>
                <td class="pj-mini"><?= e(str_replace(',', ', ', (string)$e['trainer']) ?: '–') ?></td><td class="zahl"><?= (int)$e['tn'] ?: '–' ?></td>
                <td><span class="badge <?= EINHEIT_STATUS[$e['status']]['class'] ?>"><?= EINHEIT_STATUS[$e['status']]['label'] ?></span></td></tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</div>
<?php endforeach; ?>

<?php elseif ($tab === 'kurse'):
    $stmt = $db->prepare("SELECT k.*, u.vorname, u.nachname, (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status IN ('angemeldet','teilgenommen')) AS tn FROM kurse k LEFT JOIN users u ON u.id = k.trainer_id WHERE k.projekt_id = ? ORDER BY k.start_datum DESC");
    $stmt->execute([$id]);
    $kurse = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT id, titel FROM kurse WHERE organization_id = ? AND (projekt_id IS NULL OR projekt_id <> ?) AND status IN ('geplant','aktiv') ORDER BY titel");
    $stmt->execute([$org_id, $id]);
    $frei = $stmt->fetchAll(); ?>
<div class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Kurse</h2></div>
    <?php if (!$kurse): ?><div style="padding: 1.25rem;" class="pj-mini">Noch keine Kurse zugeordnet.</div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Kurs</th><th>Trainer:in</th><th>Zeitraum</th><th class="zahl">Teilnehmende</th><th></th></tr></thead>
        <tbody><?php foreach ($kurse as $k): ?>
            <tr><td><a class="text-primary" href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $k['id'] ?>"><?= e($k['titel']) ?></a></td><td><?= e($k['vorname'] ? $k['vorname'] . ' ' . $k['nachname'] : '–') ?></td>
                <td class="pj-mini"><?= plattformDatum($k['start_datum']) ?> – <?= plattformDatum($k['end_datum']) ?></td><td class="zahl"><?= (int)$k['tn'] ?><?= $k['max_teilnehmer'] ? ' / ' . (int)$k['max_teilnehmer'] : '' ?></td>
                <td><?php if ($bearbeiten): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="kurs_entfernen"><input type="hidden" name="tab" value="kurse"><input type="hidden" name="kurs_id" value="<?= $k['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" aria-label="Zuordnung entfernen">✕</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
    <?php if ($bearbeiten && $frei): ?>
    <form method="POST" class="pj-inline"><?= csrfField() ?><input type="hidden" name="action" value="kurs_zuordnen"><input type="hidden" name="tab" value="kurse">
        <div class="form-group" style="flex: 1; min-width: 200px;"><label class="form-label">Bestehenden Kurs zuordnen</label><select class="form-control" name="kurs_id"><?php foreach ($frei as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['titel']) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn btn-ghost-light btn-sm">Zuordnen</button></form>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'team'):
    $stmt = $db->prepare('SELECT t.*, u.vorname, u.nachname, u.email FROM projekt_team t JOIN users u ON u.id = t.user_id WHERE t.projekt_id = ? ORDER BY u.nachname');
    $stmt->execute([$id]);
    $mitglieder = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT u.vorname, u.nachname, COUNT(*) AS n, COALESCE(SUM(et.dauer_min), 0) AS minuten FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id JOIN users u ON u.id = et.user_id
                          WHERE e.projekt_id = ? AND et.status NOT IN ('geplant','storniert') GROUP BY u.id, u.vorname, u.nachname ORDER BY n DESC");
    $stmt->execute([$id]);
    $einsaetze = $stmt->fetchAll(); ?>
<div class="grid-2" style="align-items: start;">
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Team</h2></div>
        <div style="padding: 0.5rem 0;">
            <?php if ($p['vorname']): ?><div style="padding: 0.6rem 1.25rem;"><strong><?= e($p['vorname'] . ' ' . $p['nachname']) ?></strong> <span class="pj-mini">· Projektleitung</span></div><?php endif; ?>
            <?php foreach ($mitglieder as $m): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 1.25rem; border-top: 1px solid var(--border-light);">
                <span><?= e($m['vorname'] . ' ' . $m['nachname']) ?> <span class="pj-mini"><?= $m['rolle'] ? '· ' . e($m['rolle']) : '' ?></span></span>
                <?php if ($bearbeiten): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="team_remove"><input type="hidden" name="tab" value="team"><input type="hidden" name="user_id" value="<?= $m['user_id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" aria-label="Entfernen">✕</button></form><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($bearbeiten): ?>
        <form method="POST" class="pj-inline"><?= csrfField() ?><input type="hidden" name="action" value="team_add"><input type="hidden" name="tab" value="team">
            <div class="form-group"><label class="form-label">Person</label><select class="form-control" name="user_id"><?php foreach ($team as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Rolle</label><input class="form-control" type="text" name="rolle" maxlength="80" placeholder="z.B. Trainer:in"></div>
            <button type="submit" class="btn btn-ghost-light btn-sm">Hinzufügen</button></form>
        <?php endif; ?>
    </div>
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Einsätze im Projekt</h2></div>
        <?php if (!$einsaetze): ?><div style="padding: 1.25rem;" class="pj-mini">Noch keine bestätigten Einheiten.</div><?php else: ?>
        <div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Trainer:in</th><th class="zahl">Einheiten</th><th class="zahl">Stunden</th></tr></thead>
            <tbody><?php foreach ($einsaetze as $e): ?><tr><td><?= e($e['vorname'] . ' ' . $e['nachname']) ?></td><td class="zahl"><?= (int)$e['n'] ?></td><td class="zahl"><?= dauerText((int)$e['minuten']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'finanzen'):
    $stmt = $db->prepare('SELECT b.*, f.titel AS foerderung_titel, (SELECT d.id FROM dokumente d WHERE d.buchung_id = b.id LIMIT 1) AS beleg_id FROM buchungen b LEFT JOIN foerderungen f ON f.id = b.foerderung_id WHERE b.projekt_id = ? ORDER BY b.datum DESC, b.id DESC');
    $stmt->execute([$id]);
    $buchungen = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT id, titel FROM foerderungen WHERE organization_id = ? AND (projekt_id = ? OR id = ?) ORDER BY titel");
    $stmt->execute([$org_id, $id, (int)$p['foerderung_id']]);
    $f_liste = $stmt->fetchAll();
    $budget = (float)$p['budget']; ?>
<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;"><div class="kpi-value"><?= $budget > 0 ? moneyFormat($p['budget']) : '–' ?></div><div class="kpi-label">Budget</div></div>
    <div class="kpi-card" style="--kpi-color: #EF4444;"><div class="kpi-value"><?= moneyFormat($fin['ausgaben']) ?></div><div class="kpi-label">Ausgaben (davon Trainer <?= moneyFormat($fin['trainer']) ?>)</div></div>
    <div class="kpi-card" style="--kpi-color: #22C55E;"><div class="kpi-value"><?= moneyFormat($fin['einnahmen']) ?></div><div class="kpi-label">Einnahmen</div></div>
    <div class="kpi-card" style="--kpi-color: #C6A135;"><div class="kpi-value"><?= $budget > 0 ? moneyFormat(bcsub(moneyRound($p['budget']), moneyRound($fin['ausgaben']), 2)) : moneyFormat(bcsub(moneyRound($fin['einnahmen']), moneyRound($fin['ausgaben']), 2)) ?></div><div class="kpi-label"><?= $budget > 0 ? 'Restbudget' : 'Saldo' ?><?= bccomp($honorar_offen, '0', 2) > 0 ? ' · ' . moneyFormat($honorar_offen) . ' Honorar noch nicht gebucht' : '' ?></div></div>
</div>
<div class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Einnahmen &amp; Ausgaben</h2><span class="pj-mini">Trainerhonorare werden bei Freigabe der Trainerabrechnung automatisch gebucht</span></div>
    <?php if (!$buchungen): ?><div style="padding: 1.25rem;" class="pj-mini">Noch keine Buchungen.</div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Datum</th><th>Beschreibung</th><th>Kategorie</th><th>Förderung</th><th class="zahl">Betrag</th><th></th></tr></thead>
        <tbody><?php foreach ($buchungen as $b): ?>
            <tr><td><?= plattformDatum($b['datum']) ?></td>
                <td><?= e($b['beschreibung']) ?><?= $b['belegnummer'] ? '<div class="pj-mini">Beleg ' . e($b['belegnummer']) . '</div>' : '' ?><?= $b['beleg_id'] ? ' <a class="pj-mini" href="' . APP_URL . '/api/dokument-download.php?id=' . (int)$b['beleg_id'] . '">📎 PDF</a>' : '' ?></td>
                <td class="pj-mini"><?= e(BUCHUNG_KATEGORIEN[$b['kategorie']] ?? $b['kategorie']) ?><?= $b['status'] === 'offen' ? ' · offen' : '' ?></td>
                <td class="pj-mini"><?= e($b['foerderung_titel'] ?? '–') ?></td>
                <td class="zahl" style="color: <?= $b['art'] === 'einnahme' ? 'var(--success)' : 'inherit' ?>;"><?= $b['art'] === 'einnahme' ? '+' : '−' ?> <?= moneyFormat($b['betrag']) ?></td>
                <td><?php if ($finanzen && !$b['trainer_abrechnung_id']): ?><form method="POST" onsubmit="return confirm('Buchung löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="buchung_loeschen"><input type="hidden" name="tab" value="finanzen"><input type="hidden" name="buchung_id" value="<?= $b['id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" aria-label="Löschen">✕</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
    <?php if ($finanzen): ?>
    <form method="POST" enctype="multipart/form-data" class="pj-inline"><?= csrfField() ?><input type="hidden" name="action" value="buchung_neu"><input type="hidden" name="tab" value="finanzen">
        <div class="form-group"><label class="form-label">Datum</label><input class="form-control" type="date" name="datum" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label class="form-label">Art</label><select class="form-control" name="art"><option value="ausgabe">Ausgabe</option><option value="einnahme">Einnahme</option></select></div>
        <div class="form-group"><label class="form-label">Kategorie</label><select class="form-control" name="kategorie"><?php foreach (BUCHUNG_KATEGORIEN as $k => $l): if ($k === 'trainerhonorar') continue; ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="flex: 1; min-width: 180px;"><label class="form-label">Beschreibung</label><input class="form-control" type="text" name="beschreibung" maxlength="255" required></div>
        <div class="form-group"><label class="form-label">Betrag (€)</label><input class="form-control" type="text" inputmode="decimal" name="betrag" required style="max-width: 110px;"></div>
        <div class="form-group"><label class="form-label">Förderung</label><select class="form-control" name="foerderung_id"><option value="">–</option><?php foreach ($f_liste as $f): ?><option value="<?= $f['id'] ?>" <?= (int)$p['foerderung_id'] === (int)$f['id'] ? 'selected' : '' ?>><?= e($f['titel']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Beleg-Nr.</label><input class="form-control" type="text" name="belegnummer" maxlength="60" style="max-width: 110px;"></div>
        <div class="form-group"><label class="form-label">Beleg (PDF)</label><input class="form-control" type="file" name="beleg" accept="application/pdf"></div>
        <button type="submit" class="btn btn-navy btn-sm">Buchen</button></form>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'foerderungen'):
    $stmt = $db->prepare("SELECT f.*, (SELECT COALESCE(SUM(b.betrag), 0) FROM buchungen b WHERE b.foerderung_id = f.id AND b.art = 'ausgabe') AS verbraucht
                          FROM foerderungen f WHERE f.organization_id = ? AND (f.projekt_id = ? OR f.id = ?) ORDER BY f.titel");
    $stmt->execute([$org_id, $id, (int)$p['foerderung_id']]);
    $foerderungen = $stmt->fetchAll();
    $stmt = $db->prepare("SELECT id, titel, foerderstelle FROM foerderungen WHERE organization_id = ? AND status NOT IN ('abgelehnt','abgeschlossen') ORDER BY titel");
    $stmt->execute([$org_id]);
    $alle_f = $stmt->fetchAll(); ?>
<div class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Förderungen</h2><span class="pj-mini">Standard-Förderung: <?= e($p['foerderung_titel'] ?? 'keine') ?> – Trainerkosten dieses Projekts werden ihr zugerechnet</span></div>
    <?php if (!$foerderungen): ?><div style="padding: 1.25rem;" class="pj-mini">Noch keine Förderung verknüpft.</div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Förderung</th><th class="zahl">Bewilligt</th><th class="zahl">Verbraucht</th><th class="zahl">Rest</th><th>Fristen</th></tr></thead>
        <tbody><?php foreach ($foerderungen as $f): $bew = $f['betrag_bewilligt'] ?? $f['betrag_beantragt']; ?>
            <tr><td><a class="text-primary" href="<?= APP_URL ?>/dashboard/admin/foerderung-detail.php?id=<?= $f['id'] ?>"><?= e($f['titel']) ?></a><div class="pj-mini"><?= e($f['foerderstelle']) ?><?= (int)$f['id'] === (int)$p['foerderung_id'] ? ' · Standard' : '' ?></div></td>
                <td class="zahl"><?= $bew !== null ? moneyFormat($bew) : '–' ?></td><td class="zahl"><?= moneyFormat($f['verbraucht']) ?></td>
                <td class="zahl"><?= $bew !== null ? moneyFormat(bcsub(moneyRound($bew), moneyRound($f['verbraucht']), 2)) : '–' ?></td>
                <td class="pj-mini"><?= $f['nachweisfrist'] ? 'Nachweis ' . plattformDatum($f['nachweisfrist']) : '' ?><?= !empty($f['abrechnungsfrist']) ? '<br>Abrechnung ' . plattformDatum($f['abrechnungsfrist']) : '' ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
    <?php if ($bearbeiten && $alle_f): ?>
    <form method="POST" class="pj-inline"><?= csrfField() ?><input type="hidden" name="action" value="foerderung"><input type="hidden" name="tab" value="foerderungen">
        <div class="form-group" style="flex: 1; min-width: 220px;"><label class="form-label">Förderung verknüpfen</label><select class="form-control" name="foerderung_id"><?php foreach ($alle_f as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['titel'] . ' – ' . $f['foerderstelle']) ?></option><?php endforeach; ?></select></div>
        <label style="display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem;"><input type="checkbox" name="standard" value="1" checked> als Standard für Projektkosten</label>
        <button type="submit" class="btn btn-ghost-light btn-sm">Verknüpfen</button></form>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'dokumente'):
    $stmt = $db->prepare('SELECT d.*, u.vorname, u.nachname FROM dokumente d LEFT JOIN users u ON u.id = d.hochgeladen_von WHERE d.projekt_id = ? ORDER BY d.created_at DESC');
    $stmt->execute([$id]);
    $dokumente = $stmt->fetchAll(); ?>
<div class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Dokumente</h2></div>
    <?php if (!$dokumente): ?><div style="padding: 1.25rem;" class="pj-mini">Noch keine Dokumente.</div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Titel</th><th>Kategorie</th><th>Hochgeladen</th><th></th></tr></thead>
        <tbody><?php foreach ($dokumente as $d): ?><tr><td><?= e($d['titel']) ?></td><td class="pj-mini"><?= e($d['kategorie']) ?></td><td class="pj-mini"><?= plattformDatum($d['created_at']) ?> · <?= e(($d['vorname'] ?? '') . ' ' . ($d['nachname'] ?? '')) ?></td>
            <td><a href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $d['id'] ?>" class="btn btn-ghost-light btn-sm">Download</a></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($bearbeiten || $im_team): ?>
    <form method="POST" enctype="multipart/form-data" class="pj-inline"><?= csrfField() ?><input type="hidden" name="action" value="dokument"><input type="hidden" name="tab" value="dokumente">
        <div class="form-group" style="flex: 1; min-width: 200px;"><label class="form-label">Titel</label><input class="form-control" type="text" name="titel" maxlength="200" required></div>
        <div class="form-group"><label class="form-label">PDF-Datei</label><input class="form-control" type="file" name="datei" accept="application/pdf" required></div>
        <button type="submit" class="btn btn-navy btn-sm">Hochladen</button></form>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'partner'):
    $stmt = $db->prepare('SELECT pp.*, o.name, o.kategorie, o.ansprechpartner, o.telefon, o.email FROM projekt_partner pp JOIN partner_organisationen o ON o.id = pp.partner_id WHERE pp.projekt_id = ? ORDER BY o.name');
    $stmt->execute([$id]);
    $partner = $stmt->fetchAll();
    $stmt = $db->prepare('SELECT id, name, kategorie FROM partner_organisationen WHERE organization_id = ? ORDER BY name');
    $stmt->execute([$org_id]);
    $alle_p = $stmt->fetchAll(); ?>
<div class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Partner</h2><?php if (darf('partner.anzeigen')): ?><a href="<?= APP_URL ?>/dashboard/admin/partner.php" class="btn btn-ghost-light btn-sm">Partner-Verwaltung</a><?php endif; ?></div>
    <?php if (!$partner): ?><div style="padding: 1.25rem;" class="pj-mini">Noch keine Partner zugeordnet.</div><?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table"><thead><tr><th>Organisation</th><th>Rolle</th><th>Kontakt</th><th></th></tr></thead>
        <tbody><?php foreach ($partner as $pa): ?><tr><td><?= darf('partner.anzeigen') ? '<a class="text-primary" href="' . APP_URL . '/dashboard/admin/partner.php?id=' . $pa['partner_id'] . '">' . e($pa['name']) . '</a>' : e($pa['name']) ?><div class="pj-mini"><?= e(PARTNER_KATEGORIEN[$pa['kategorie']] ?? '') ?></div></td>
            <td><?= e($pa['rolle'] ?? '–') ?></td><td class="pj-mini"><?= e(implode(' · ', array_filter([$pa['ansprechpartner'], $pa['telefon'], $pa['email']]))) ?></td>
            <td><?php if ($bearbeiten): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="partner_remove"><input type="hidden" name="tab" value="partner"><input type="hidden" name="partner_id" value="<?= $pa['partner_id'] ?>"><button type="submit" class="btn btn-ghost-light btn-sm" aria-label="Entfernen">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($bearbeiten && $alle_p): ?>
    <form method="POST" class="pj-inline"><?= csrfField() ?><input type="hidden" name="action" value="partner_add"><input type="hidden" name="tab" value="partner">
        <div class="form-group" style="flex: 1; min-width: 200px;"><label class="form-label">Partner</label><select class="form-control" name="partner_id"><?php foreach ($alle_p as $o): ?><option value="<?= $o['id'] ?>"><?= e($o['name']) ?> (<?= e(PARTNER_KATEGORIEN[$o['kategorie']] ?? '') ?>)</option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Rolle</label><input class="form-control" type="text" name="rolle" maxlength="80" placeholder="z.B. Auftraggeber, Sponsor"></div>
        <button type="submit" class="btn btn-ghost-light btn-sm">Zuordnen</button></form>
    <?php elseif ($bearbeiten): ?><p class="pj-mini" style="padding: 0 1.25rem 1.25rem;">Lege zuerst Partner in der Partner-Verwaltung an.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
