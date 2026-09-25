<?php
/**
 * Athletikclub Steiermark – Datenschutz
 * Datenauskunft je Person (JSON/Excel), Anonymisierung auf Wunsch (unumkehrbar, mit Bestätigung),
 * Aufbewahrungsfristen, Einwilligungsprotokoll und Bereinigung alter Protokolldaten.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einstellungen.php';
require_once ROOT_PATH . '/includes/datenschutz.php';
require_once ROOT_PATH . '/includes/export.php';

requireDarf('datenschutz.bearbeiten');

$db   = getDB();
$org  = currentOrgId();
$self = APP_URL . '/dashboard/admin/datenschutz.php';
$person = !empty($_GET['person']) ? datenschutzPerson($db, (int)$_GET['person']) : null;

// Auskunft herunterladen
if ($person && in_array($_GET['export'] ?? '', ['json', 'xlsx'], true)) {
    $daten = datenAuskunft($db, (int)$person['id']);
    auditLog('status', 'users', (int)$person['id'], null, null, 'Datenauskunft erstellt (' . strtoupper($_GET['export']) . ')');
    if ($_GET['export'] === 'json') {
        $json = datenAuskunftJson($person, $daten);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . exportDateiname('datenauskunft-' . $person['id'], 'json') . '"');
        header('Cache-Control: private, no-store');
        echo $json;
        exit;
    }
    exportAusliefern(datenAuskunftBericht($person, $daten), 'xlsx');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'anonymisieren') {
        $p = datenschutzPerson($db, (int)($_POST['person_id'] ?? 0));
        if (!$p) { flashMessage('error', 'Person nicht gefunden.'); redirect($self); }
        $hindernisse = anonymisierungHindernisse($db, $p);
        if ($hindernisse) { flashMessage('error', implode(' ', $hindernisse)); redirect($self . '?person=' . $p['id']); }
        if (trim($_POST['bestaetigung'] ?? '') !== 'ANONYMISIEREN' || empty($_POST['verstanden'])) {
            flashMessage('error', 'Bitte „ANONYMISIEREN“ eintippen und den Hinweis bestätigen.');
            redirect($self . '?person=' . $p['id'] . '#anonymisieren');
        }
        $erg = personAnonymisieren($db, (int)$p['id']);
        auditLog('geaendert', 'users', (int)$p['id'], null, null, 'Person anonymisiert (Art. 17 DSGVO): ' . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($erg), $erg)));
        logActivity('anonymisiert', 'User-ID ' . $p['id']);
        flashMessage('success', 'Die Person wurde anonymisiert. Rechnungen und Abrechnungen bleiben wegen der Aufbewahrungspflicht erhalten.');
        redirect($self . '?person=' . $p['id']);
    }
    if ($action === 'bereinigen') {
        $erg = datenschutzBereinigung($db, true);
        auditLog('status', 'datenschutz', null, null, $erg, 'Bereinigung nach Aufbewahrungsfristen');
        flashMessage('success', 'Bereinigung ausgeführt: ' . implode(', ', array_map(fn($k, $v) => "$k: " . (int)$v, array_keys($erg), $erg)) . '.');
        redirect($self . '#bereinigung');
    }
}

// Personensuche
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$treffer = [];
if (mb_strlen($q) >= 2) {
    $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
    $stmt = $db->prepare('SELECT id, vorname, nachname, email, rolle, aktiv FROM users WHERE organization_id = ? AND (vorname LIKE ? OR nachname LIKE ? OR email LIKE ?) ORDER BY nachname, vorname LIMIT 20');
    $stmt->execute([$org, $like, $like, $like]);
    $treffer = $stmt->fetchAll();
}
$uebersicht = $person ? array_map('count', datenAuskunft($db, (int)$person['id'])) : [];
$hindernisse = $person ? anonymisierungHindernisse($db, $person) : [];
$umfang = $person ? anonymisierungUmfang($db, (int)$person['id']) : [];
$bereinigung = datenschutzBereinigung($db, false);
$einwilligungen = [];
try {
    $stmt = $db->prepare('SELECT e.*, u.vorname, u.nachname, k.vorname AS k_vorname, k.nachname AS k_nachname FROM einwilligungen e JOIN users u ON u.id = e.user_id
                          LEFT JOIN kinder k ON k.id = e.kind_id WHERE e.organization_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 15');
    $stmt->execute([$org]);
    $einwilligungen = $stmt->fetchAll();
} catch (Exception $e) {}

$page_title = 'Datenschutz';
$breadcrumb = 'Datenschutz';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.ds-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); gap: 1.25rem; align-items: start; }
.ds-pad { padding: 1.25rem; }
.ds-mini { font-size: 0.8rem; color: var(--text-muted); }
.ds-liste { list-style: none; margin: 0; padding: 0; }
.ds-liste li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.4rem 0; border-bottom: 1px solid var(--border-light); font-size: 0.88rem; }
.ds-liste li:last-child { border-bottom: 0; }
.ds-gefahr { border: 1px solid rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.05); border-radius: 12px; padding: 1rem; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Datenschutz</h1>
    <p class="dashboard-subtitle">Auskunft, Löschung (Anonymisierung), Aufbewahrungsfristen und Einwilligungen nach DSGVO.</p>
</div>

<div class="ds-grid">
<section class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Person suchen</h2></div>
    <form method="get" class="ds-pad" style="display: flex; gap: 0.5rem;">
        <label for="ds-q" class="sr-only">Name oder E-Mail</label>
        <input class="form-control" type="search" id="ds-q" name="q" value="<?= e($q) ?>" placeholder="Name oder E-Mail" minlength="2" style="flex: 1;">
        <button class="btn btn-primary btn-sm">Suchen</button>
    </form>
    <?php if ($q !== '' && !$treffer): ?><p class="ds-mini" style="padding: 0 1.25rem 1rem;">Keine Person gefunden.</p><?php endif; ?>
    <?php if ($treffer): ?>
    <ul class="ds-liste" style="padding: 0 1.25rem 1rem;">
        <?php foreach ($treffer as $t): ?>
            <li><a href="?person=<?= (int)$t['id'] ?>"><?= e($t['vorname'] . ' ' . $t['nachname']) ?></a><span class="ds-mini"><?= e($t['email']) ?> · <?= e($t['rolle']) ?><?= $t['aktiv'] ? '' : ' · inaktiv' ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php if ($person): ?>
<section class="table-card">
    <div class="table-card-header"><h2 class="table-card-title"><?= e($person['vorname'] . ' ' . $person['nachname']) ?></h2><span class="ds-mini"><?= e($person['email']) ?></span></div>
    <div class="ds-pad">
        <h3 style="font-size: 0.95rem; margin: 0 0 0.5rem;">Gespeicherte Daten</h3>
        <ul class="ds-liste">
            <?php foreach ($uebersicht as $bereich => $n): ?><li><span><?= e($bereich) ?></span><strong><?= $n ?></strong></li><?php endforeach; ?>
        </ul>
        <div style="display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap;">
            <a class="btn btn-navy btn-sm" href="?person=<?= (int)$person['id'] ?>&amp;export=json">Auskunft als JSON</a>
            <a class="btn btn-ghost-light btn-sm" href="?person=<?= (int)$person['id'] ?>&amp;export=xlsx">Auskunft als Excel</a>
        </div>
        <p class="ds-mini" style="margin-top: 0.5rem;">Ohne Passwort-Hash und Zugangs-Tokens. Jede Auskunft wird im Änderungsprotokoll vermerkt.</p>

        <div class="ds-gefahr" id="anonymisieren" style="margin-top: 1.5rem;">
            <h3 style="font-size: 0.95rem; margin: 0 0 0.5rem; color: #B91C1C;">Löschen durch Anonymisierung</h3>
            <?php if ($hindernisse): ?>
                <?php foreach ($hindernisse as $h): ?><p class="ds-mini" style="margin: 0.2rem 0;">• <?= e($h) ?></p><?php endforeach; ?>
            <?php else: ?>
                <p style="font-size: 0.85rem; margin: 0 0 0.6rem;">Name, Kontaktdaten, Profil, Kinderdaten, Notfallkontakte, Benachrichtigungen und IP-Adressen werden unwiderruflich entfernt, das Konto wird deaktiviert.
                Erhalten bleiben ohne Namen: <?= (int)$umfang['anmeldungen'] ?> Kursanmeldung(en) und Anwesenheiten für die Statistik.
                <?php if ($umfang['rechnungen'] || $umfang['abrechnungen']): ?><strong>Unverändert bleiben <?= (int)$umfang['rechnungen'] ?> Rechnung(en) und <?= (int)$umfang['abrechnungen'] ?> Trainerabrechnung(en)</strong> (Aufbewahrungspflicht 7 Jahre).<?php endif; ?></p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="anonymisieren">
                    <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
                    <label class="form-check" style="margin-bottom: 0.6rem;"><input type="checkbox" name="verstanden" value="1" required><span class="form-check-label">Ich habe verstanden, dass dieser Schritt nicht rückgängig gemacht werden kann.</span></label>
                    <div class="form-group"><label class="form-label" for="ds-best">Zur Bestätigung <strong>ANONYMISIEREN</strong> eintippen</label>
                        <input class="form-control" id="ds-best" name="bestaetigung" autocomplete="off" required pattern="ANONYMISIEREN"></div>
                    <button class="btn btn-sm" style="background: #B91C1C; color: #fff;">Person anonymisieren</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="table-card" id="bereinigung">
    <div class="table-card-header"><h2 class="table-card-title">Aufbewahrungsfristen</h2></div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Daten</th><th>Frist</th><th>Grundlage / Umsetzung</th></tr></thead>
        <tbody><?php foreach (DATENSCHUTZ_FRISTEN as [$d, $f, $g]): ?><tr><td><?= e($d) ?></td><td><?= e($f) ?></td><td class="ds-mini"><?= e($g) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="ds-pad">
        <h3 style="font-size: 0.95rem; margin: 0 0 0.5rem;">Bereinigung nach Fristen</h3>
        <ul class="ds-liste">
            <?php foreach ($bereinigung as $label => $n): ?><li><span><?= e($label) ?></span><strong><?= $n === null ? '–' : (int)$n ?></strong></li><?php endforeach; ?>
        </ul>
        <?php if (array_sum(array_map('intval', $bereinigung)) > 0): ?>
        <form method="post" style="margin-top: 0.75rem;" onsubmit="return confirm('Alte IP-Adressen und E-Mail-Empfänger in den Protokollen jetzt entfernen?');">
            <?= csrfField() ?><input type="hidden" name="action" value="bereinigen">
            <button class="btn btn-navy btn-sm">Jetzt bereinigen</button>
        </form>
        <?php else: ?><p class="ds-mini" style="margin-top: 0.5rem;">Derzeit ist nichts zu bereinigen.</p><?php endif; ?>
        <p class="ds-mini" style="margin-top: 0.5rem;">Es werden nur Protokollfelder geleert – keine Mitglieds-, Kurs- oder Finanzdaten.</p>
    </div>
</section>

<section class="table-card">
    <div class="table-card-header"><h2 class="table-card-title">Einwilligungsprotokoll (neueste)</h2></div>
    <?php if (!$einwilligungen): ?><p class="ds-mini ds-pad">Noch keine Einwilligungen erfasst.</p><?php else: ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Datum</th><th>Person</th><th>Art</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($einwilligungen as $w): ?>
            <tr>
                <td style="white-space: nowrap;"><?= date('d.m.Y H:i', strtotime($w['created_at'])) ?></td>
                <td><?= e($w['k_vorname'] ? $w['k_vorname'] . ' ' . $w['k_nachname'] . ' (Kind)' : $w['vorname'] . ' ' . $w['nachname']) ?></td>
                <td><?= e(EINWILLIGUNG_TYPEN[$w['typ']] ?? $w['typ']) ?><?= $w['text_version'] ? '<div class="ds-mini">Version ' . e($w['text_version']) . '</div>' : '' ?></td>
                <td><span class="badge <?= (int)$w['erteilt'] ? 'badge-success' : 'badge-danger' ?>"><?= (int)$w['erteilt'] ? 'erteilt' : 'widerrufen' ?></span></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
