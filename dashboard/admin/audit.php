<?php
/**
 * Athletikclub Steiermark – Audit-Log
 * Wer hat wann was geändert (alte → neue Werte). Durchsuchbar nach Text, Bereich,
 * Aktion, Person und Zeitraum. Nur mit Recht „audit.anzeigen“.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireDarf('audit.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$pro_seite = 50;
$tabellen_labels = [
    'kurse' => 'Kurse', 'kurs_anmeldungen' => 'Kursanmeldungen', 'einheiten' => 'Einheiten', 'einheit_trainer' => 'Einsätze', 'anwesenheiten' => 'Anwesenheit',
    'trainer_abrechnungen' => 'Trainerabrechnungen', 'honorar_saetze' => 'Honorarsätze', 'buchungen' => 'Buchungen', 'projekte' => 'Projekte', 'projekt_team' => 'Projektteam',
    'aufgaben' => 'Aufgaben', 'trainer_qualifikationen' => 'Qualifikationen', 'kinder' => 'Kinder', 'einwilligungen' => 'Einwilligungen',
    'partner_organisationen' => 'Partner', 'partner_kontakte' => 'Partnerkontakte', 'ressourcen' => 'Ressourcen', 'ressourcen_buchungen' => 'Ressourcenbuchungen',
    'vertraege' => 'Verträge', 'foerderungen' => 'Förderungen', 'dokumente' => 'Dokumente', 'user_roles' => 'Rollenzuweisung', 'role_permissions' => 'Rechte-Matrix',
];
$aktionen = ['erstellt' => ['erstellt', 'badge-success'], 'geaendert' => ['geändert', 'badge-info'], 'status' => ['Status', 'badge-warning'], 'geloescht' => ['gelöscht', 'badge-danger']];

// Filter
$f = [
    'q'       => mb_substr(trim($_GET['q'] ?? ''), 0, 100),
    'tabelle' => preg_match('/^[a-z_]{1,60}$/', $_GET['tabelle'] ?? '') ? $_GET['tabelle'] : '',
    'aktion'  => isset($aktionen[$_GET['aktion'] ?? '']) ? $_GET['aktion'] : '',
    'user'    => (int)($_GET['user'] ?? 0),
    'von'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['von'] ?? '') ? $_GET['von'] : date('Y-m-d', strtotime('-30 days')),
    'bis'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bis'] ?? '') ? $_GET['bis'] : date('Y-m-d'),
    'id'      => (int)($_GET['id'] ?? 0),
];
$seite = max(1, (int)($_GET['seite'] ?? 1));

$where = 'a.organization_id = ? AND a.created_at BETWEEN ? AND ?';
$p = [$org_id, $f['von'] . ' 00:00:00', $f['bis'] . ' 23:59:59'];
if ($f['q'] !== '') { $where .= ' AND (a.beschreibung LIKE ? OR a.alt LIKE ? OR a.neu LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['tabelle'] !== '') { $where .= ' AND a.tabelle = ?'; $p[] = $f['tabelle']; }
if ($f['aktion'] !== '') { $where .= ' AND a.aktion = ?'; $p[] = $f['aktion']; }
if ($f['user']) { $where .= ' AND a.user_id = ?'; $p[] = $f['user']; }
if ($f['id']) { $where .= ' AND a.datensatz_id = ?'; $p[] = $f['id']; }

$stmt = $db->prepare("SELECT COUNT(*) FROM audit_log a WHERE $where");
$stmt->execute($p);
$gesamt = (int)$stmt->fetchColumn();
$seiten = max(1, (int)ceil($gesamt / $pro_seite));
$seite = min($seite, $seiten);
$stmt = $db->prepare("SELECT a.*, u.vorname, u.nachname FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.created_at DESC, a.id DESC LIMIT $pro_seite OFFSET " . (($seite - 1) * $pro_seite));
$stmt->execute($p);
$eintraege = $stmt->fetchAll();

$stmt = $db->prepare('SELECT DISTINCT a.user_id, u.vorname, u.nachname FROM audit_log a JOIN users u ON u.id = a.user_id WHERE a.organization_id = ? ORDER BY u.nachname');
$stmt->execute([$org_id]);
$personen = $stmt->fetchAll();
$stmt = $db->prepare('SELECT DISTINCT tabelle FROM audit_log WHERE organization_id = ? ORDER BY tabelle');
$stmt->execute([$org_id]);
$tabellen = $stmt->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Audit-Log';
$breadcrumb = 'Audit-Log';
require_once ROOT_PATH . '/includes/dashboard-header.php';

/** Wert kompakt darstellen (lange Texte kürzen). */
$wert = function ($v): string {
    if ($v === null || $v === '') return '–';
    if (is_bool($v)) return $v ? 'ja' : 'nein';
    if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
    $v = (string)$v;
    return mb_strlen($v) > 120 ? mb_substr($v, 0, 117) . '…' : $v;
};
$link = fn(array $mehr) => '?' . http_build_query(array_filter(array_merge($f, $mehr), fn($x) => $x !== '' && $x !== 0 && $x !== null));
?>

<style>
.au-filter { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem; }
.au-filter .form-group { margin: 0; }
.au-mini { font-size: 0.78rem; color: var(--text-muted); }
.au-diff { font-size: 0.8rem; margin: 0; padding: 0; list-style: none; }
.au-diff li { padding: 0.1rem 0; word-break: break-word; }
.au-diff .feld { font-weight: 600; }
.au-diff .alt { color: #B91C1C; text-decoration: line-through; opacity: 0.8; }
.au-diff .neu { color: #15803D; }
.au-seiten { display: flex; gap: 0.5rem; justify-content: center; align-items: center; padding: 1rem; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Audit-Log</h1>
    <p class="dashboard-subtitle">Nachvollziehbare Änderungen mit alten und neuen Werten – <?= number_format($gesamt, 0, ',', '.') ?> Einträge im gewählten Zeitraum.</p>
</div>

<form method="GET" class="au-filter">
    <div class="form-group" style="flex: 1; min-width: 200px;"><label class="form-label">Suche</label><input class="form-control" name="q" value="<?= e($f['q']) ?>" placeholder="Name, Titel, Wert …"></div>
    <div class="form-group"><label class="form-label">Bereich</label><select class="form-control" name="tabelle"><option value="">Alle</option><?php foreach ($tabellen as $t): ?><option value="<?= e($t) ?>" <?= $f['tabelle'] === $t ? 'selected' : '' ?>><?= e($tabellen_labels[$t] ?? $t) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Aktion</label><select class="form-control" name="aktion"><option value="">Alle</option><?php foreach ($aktionen as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['aktion'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Person</label><select class="form-control" name="user"><option value="">Alle</option><?php foreach ($personen as $u): ?><option value="<?= (int)$u['user_id'] ?>" <?= $f['user'] === (int)$u['user_id'] ? 'selected' : '' ?>><?= e($u['vorname'] . ' ' . $u['nachname']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Von</label><input class="form-control" type="date" name="von" value="<?= e($f['von']) ?>"></div>
    <div class="form-group"><label class="form-label">Bis</label><input class="form-control" type="date" name="bis" value="<?= e($f['bis']) ?>"></div>
    <?php if ($f['id']): ?><input type="hidden" name="id" value="<?= $f['id'] ?>"><?php endif; ?>
    <button class="btn btn-navy btn-sm">Filtern</button>
    <a href="?" class="btn btn-ghost-light btn-sm">Zurücksetzen</a>
</form>
<?php if ($f['id']): ?><p class="au-mini" style="margin: -0.5rem 0 1rem;">Gefiltert auf Datensatz #<?= $f['id'] ?> · <a href="<?= e($link(['id' => 0])) ?>">Filter entfernen</a></p><?php endif; ?>

<div class="table-card">
    <?php if (!$eintraege): ?>
        <div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Einträge</h3><p>Für diese Auswahl wurden keine Änderungen protokolliert.</p></div>
    <?php else: ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Zeitpunkt</th><th>Person</th><th>Aktion</th><th>Bereich</th><th>Änderung</th></tr></thead>
        <tbody>
        <?php foreach ($eintraege as $a):
            $alt = json_decode((string)$a['alt'], true) ?: [];
            $neu = json_decode((string)$a['neu'], true) ?: [];
            $felder = array_unique(array_merge(array_keys($alt), array_keys($neu)));
            $ak = $aktionen[$a['aktion']] ?? [$a['aktion'], 'badge-gray']; ?>
            <tr>
                <td style="white-space: nowrap;"><?= date('d.m.Y', strtotime($a['created_at'])) ?><div class="au-mini"><?= date('H:i:s', strtotime($a['created_at'])) ?></div></td>
                <td><?= $a['vorname'] ? '<a href="' . e($link(['user' => (int)$a['user_id'], 'seite' => 1])) . '">' . e($a['vorname'] . ' ' . $a['nachname']) . '</a>' : '<span class="au-mini">System</span>' ?><?= $a['ip_adresse'] ? '<div class="au-mini">' . e($a['ip_adresse']) . '</div>' : '' ?></td>
                <td><span class="badge <?= $ak[1] ?>"><?= e($ak[0]) ?></span></td>
                <td><a href="<?= e($link(['tabelle' => $a['tabelle'], 'id' => (int)$a['datensatz_id'], 'seite' => 1])) ?>" title="Verlauf dieses Datensatzes"><?= e($tabellen_labels[$a['tabelle']] ?? $a['tabelle']) ?><?= $a['datensatz_id'] ? ' #' . (int)$a['datensatz_id'] : '' ?></a></td>
                <td>
                    <?php if ($a['beschreibung']): ?><div style="font-size: 0.85rem; margin-bottom: 0.2rem;"><?= e($a['beschreibung']) ?></div><?php endif; ?>
                    <?php if ($felder): ?>
                    <ul class="au-diff">
                        <?php foreach (array_slice($felder, 0, 12) as $feld): ?>
                        <li><span class="feld"><?= e((string)$feld) ?>:</span>
                            <?php if (array_key_exists($feld, $alt) && $a['aktion'] !== 'erstellt'): ?><span class="alt"><?= e($wert($alt[$feld])) ?></span> →<?php endif; ?>
                            <span class="neu"><?= e($wert($neu[$feld] ?? null)) ?></span></li>
                        <?php endforeach; ?>
                        <?php if (count($felder) > 12): ?><li class="au-mini">… <?= count($felder) - 12 ?> weitere Felder</li><?php endif; ?>
                    </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($seiten > 1): ?>
    <div class="au-seiten">
        <?php if ($seite > 1): ?><a class="btn btn-ghost-light btn-sm" href="<?= e($link(['seite' => $seite - 1])) ?>">‹ Neuere</a><?php endif; ?>
        <span class="au-mini">Seite <?= $seite ?> von <?= $seiten ?></span>
        <?php if ($seite < $seiten): ?><a class="btn btn-ghost-light btn-sm" href="<?= e($link(['seite' => $seite + 1])) ?>">Ältere ›</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
