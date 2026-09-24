<?php
/**
 * Athletikclub Steiermark – Projekte (Übersicht)
 * Alle Projekte mit Status, Leitung, Einheiten, Aufgaben und Kosten gegenüber Budget.
 * Trainer:innen sehen Projekte, die sie leiten oder in deren Team sie sind.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$alle   = darf('projekte.anzeigen');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anlegen') {
    requireCsrf();
    if (!darf('projekte.erstellen')) { flashMessage('error', 'Keine Berechtigung, Projekte anzulegen.'); redirect(APP_URL . '/dashboard/projekte.php'); }
    $name = mb_substr(trim($_POST['name'] ?? ''), 0, 200);
    if ($name === '') $errors['name'] = 'Bitte einen Projektnamen angeben.';
    $datum = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$f] ?? '') ? $_POST[$f] : null;
    $budget = trim(str_replace(',', '.', $_POST['budget'] ?? ''));
    if (empty($errors)) {
        $werte = ['name' => $name, 'beschreibung' => trim($_POST['beschreibung'] ?? '') ?: null,
                  'kategorie' => isset(PROJEKT_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : 'sonstiges',
                  'status' => isset(PROJEKT_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'planung',
                  'leitung_id' => (int)($_POST['leitung_id'] ?? 0) ?: null, 'start_datum' => $datum('start_datum'), 'end_datum' => $datum('end_datum'),
                  'gemeinde' => mb_substr(trim($_POST['gemeinde'] ?? ''), 0, 150) ?: null, 'budget' => is_numeric($budget) ? moneyRound($budget) : null];
        $db->prepare('INSERT INTO projekte (organization_id, name, beschreibung, kategorie, status, leitung_id, start_datum, end_datum, gemeinde, budget, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute(array_merge([$org_id], array_values($werte), [$me]));
        $id = (int)$db->lastInsertId();
        auditLog('erstellt', 'projekte', $id, null, $werte, $name);
        if ($werte['leitung_id'] && $werte['leitung_id'] !== $me) benachrichtigen($werte['leitung_id'], 'projekt', "Du leitest das Projekt „{$name}“", null, '/dashboard/projekt.php?id=' . $id);
        flashMessage('success', 'Projekt angelegt.');
        redirect(APP_URL . '/dashboard/projekt.php?id=' . $id);
    }
}

$filter_status = $_GET['status'] ?? 'offen';
$where = 'p.organization_id = ?';
$params = [$org_id];
if ($filter_status === 'offen') $where .= " AND p.status IN ('planung','aktiv','pausiert')";
elseif (isset(PROJEKT_STATUS[$filter_status])) { $where .= ' AND p.status = ?'; $params[] = $filter_status; }
if (!$alle) { $where .= ' AND (p.leitung_id = ? OR EXISTS (SELECT 1 FROM projekt_team t WHERE t.projekt_id = p.id AND t.user_id = ?))'; array_push($params, $me, $me); }
$stmt = $db->prepare("SELECT p.*, u.vorname, u.nachname,
        (SELECT COUNT(*) FROM einheiten e WHERE e.projekt_id = p.id AND e.status <> 'storniert') AS einheiten,
        (SELECT COUNT(*) FROM einheiten e WHERE e.projekt_id = p.id AND e.status = 'durchgefuehrt') AS einheiten_erledigt,
        (SELECT COUNT(*) FROM aufgaben a WHERE a.projekt_id = p.id AND a.status <> 'erledigt') AS aufgaben_offen,
        (SELECT COUNT(*) FROM aufgaben a WHERE a.projekt_id = p.id AND a.status <> 'erledigt' AND a.deadline < ?) AS aufgaben_ueberfaellig,
        (SELECT COALESCE(SUM(b.betrag), 0) FROM buchungen b WHERE b.projekt_id = p.id AND b.art = 'ausgabe') AS ausgaben
    FROM projekte p LEFT JOIN users u ON u.id = p.leitung_id WHERE {$where}
    ORDER BY CASE p.status WHEN 'aktiv' THEN 0 WHEN 'planung' THEN 1 WHEN 'pausiert' THEN 2 ELSE 3 END, p.name");
$stmt->execute(array_merge([date('Y-m-d')], $params));
$projekte = $stmt->fetchAll();
$team = plattformTeam($db);
$form = array_merge(['name' => '', 'kategorie' => 'sonstiges', 'status' => 'planung', 'leitung_id' => $me, 'start_datum' => '', 'end_datum' => '', 'gemeinde' => '', 'budget' => '', 'beschreibung' => ''], $_POST);
$v = fn($w) => e((string)($w ?? ''));

$page_title = 'Projekte';
$breadcrumb = 'Projekte';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.pj-karten { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
.pj-karte { background: var(--surface); border: 1px solid var(--border-light); border-radius: 1rem; padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.6rem; color: var(--text-primary); border-top: 4px solid var(--pj, var(--gold-accent)); }
.pj-karte:hover { box-shadow: var(--shadow-md); }
.pj-karte h3 { font-family: var(--font-heading); font-size: 1rem; font-weight: 800; margin: 0; }
.pj-zahlen { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; font-size: 0.75rem; color: var(--text-muted); }
.pj-zahlen strong { display: block; font-size: 1rem; color: var(--text-primary); font-family: var(--font-heading); }
.pj-balken { height: 7px; border-radius: 99px; background: var(--bg-muted); overflow: hidden; }
.pj-balken span { display: block; height: 100%; background: var(--gold-accent); }
.pj-mini { font-size: 0.75rem; color: var(--text-muted); }
.pj-chips { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.pj-chips a { font-size: 0.78rem; padding: 0.35rem 0.8rem; border-radius: 99px; border: 1px solid var(--border-color); color: var(--text-secondary); }
.pj-chips a.aktiv { background: var(--navy-primary); border-color: var(--navy-primary); color: #fff; }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Projekte</h1>
        <p class="dashboard-subtitle">Kindergärten, Schulen, Gemeindeprojekte, Skateparks, Veranstaltungen – mit Terminen, Team, Aufgaben, Finanzen und Förderungen.</p>
    </div>
    <?php if (darf('projekte.erstellen')): ?><a href="#neu" class="btn btn-navy btn-sm">+ Neues Projekt</a><?php endif; ?>
</div>

<?php if (!empty($errors)): ?><div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.25rem;"><span><?= implode(' | ', array_map('e', $errors)) ?></span></div><?php endif; ?>

<div class="pj-chips">
    <?php foreach (['offen' => 'Laufend'] + array_map(fn($s) => $s['label'], PROJEKT_STATUS) + ['alle' => 'Alle'] as $k => $label): ?>
    <a href="?status=<?= $k ?>" class="<?= $filter_status === $k ? 'aktiv' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$projekte): ?>
<div class="table-card"><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Projekte</h3><p><?= $alle ? 'Lege das erste Projekt an.' : 'Du bist noch keinem Projekt zugeordnet.' ?></p></div></div>
<?php else: ?>
<div class="pj-karten">
    <?php foreach ($projekte as $p): $budget = (float)$p['budget']; $anteil = $budget > 0 ? min(100, round((float)$p['ausgaben'] / $budget * 100)) : 0; ?>
    <a class="pj-karte" href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= $p['id'] ?>" style="--pj: <?= e($p['farbe'] ?: '#C6A135') ?>;">
        <div style="display: flex; justify-content: space-between; gap: 0.5rem; align-items: flex-start;">
            <h3><?= e($p['name']) ?></h3>
            <span class="badge <?= PROJEKT_STATUS[$p['status']]['class'] ?>"><?= PROJEKT_STATUS[$p['status']]['label'] ?></span>
        </div>
        <div class="pj-mini"><?= e(PROJEKT_KATEGORIEN[$p['kategorie']] ?? $p['kategorie']) ?><?= $p['gemeinde'] ? ' · ' . e($p['gemeinde']) : '' ?><?= $p['vorname'] ? ' · Leitung ' . e($p['vorname'] . ' ' . $p['nachname']) : '' ?>
            <?= $p['start_datum'] ? '<br>' . plattformDatum($p['start_datum']) . ($p['end_datum'] ? ' – ' . plattformDatum($p['end_datum']) : '') : '' ?></div>
        <div class="pj-zahlen">
            <div><strong><?= (int)$p['einheiten_erledigt'] ?>/<?= (int)$p['einheiten'] ?></strong>Einheiten</div>
            <div><strong style="<?= $p['aufgaben_ueberfaellig'] ? 'color: var(--danger);' : '' ?>"><?= (int)$p['aufgaben_offen'] ?></strong>offene Aufgaben<?= $p['aufgaben_ueberfaellig'] ? ' (' . (int)$p['aufgaben_ueberfaellig'] . ' überfällig)' : '' ?></div>
            <div><strong><?= moneyFormat($p['ausgaben']) ?></strong>Kosten</div>
        </div>
        <?php if ($budget > 0): ?><div><div class="pj-balken" title="<?= $anteil ?> % des Budgets"><span style="width: <?= $anteil ?>%; <?= $anteil >= 90 ? 'background: var(--danger);' : '' ?>"></span></div><div class="pj-mini" style="margin-top: 0.25rem;"><?= $anteil ?> % von <?= moneyFormat($budget) ?> Budget</div></div><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (darf('projekte.erstellen')): ?>
<div class="table-card" id="neu">
    <div class="table-card-header"><h2 class="table-card-title">Neues Projekt</h2></div>
    <form method="POST" style="padding: 1.25rem;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="anlegen">
        <div class="form-row">
            <div class="form-group"><label class="form-label">Projektname <span class="required">*</span></label><input class="form-control" type="text" name="name" maxlength="200" value="<?= $v($form['name']) ?>" required placeholder="z.B. Kindergarten Tillmitsch"></div>
            <div class="form-group"><label class="form-label">Kategorie</label><select class="form-control" name="kategorie"><?php foreach (PROJEKT_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= $form['kategorie'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Projektleitung</label><select class="form-control" name="leitung_id"><option value="">–</option><?php foreach ($team as $t): ?><option value="<?= $t['id'] ?>" <?= (int)$form['leitung_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status"><?php foreach (PROJEKT_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $form['status'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Zeitraum</label><div style="display: flex; gap: 0.5rem;"><input class="form-control" type="date" name="start_datum" value="<?= $v($form['start_datum']) ?>"><input class="form-control" type="date" name="end_datum" value="<?= $v($form['end_datum']) ?>"></div></div>
            <div class="form-group"><label class="form-label">Gemeinde</label><input class="form-control" type="text" name="gemeinde" maxlength="150" value="<?= $v($form['gemeinde']) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Budget (€)</label><input class="form-control" type="text" inputmode="decimal" name="budget" value="<?= $v($form['budget']) ?>"></div>
            <div class="form-group"></div>
        </div>
        <div class="form-group"><label class="form-label">Beschreibung</label><textarea class="form-control" name="beschreibung" rows="3"><?= $v($form['beschreibung']) ?></textarea></div>
        <button type="submit" class="btn btn-navy">Projekt anlegen</button>
    </form>
</div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
