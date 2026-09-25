<?php
/**
 * Athletikclub Steiermark – Ressourcen & Material
 * Inventar (Skateboards, Helme, Hallen, Plätze …) mit Anzahl, Zustand und Standort,
 * Buchungen mit Doppelbuchungsschutz (verfügbare Menge im Zeitraum). Buchungen aus
 * der Einsatzplanung (Einheiten) erscheinen hier ebenfalls.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireDarf('ressourcen.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$self   = APP_URL . '/dashboard/ressourcen.php';
$verwalten = darf('ressourcen.bearbeiten');

function ressourceLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT r.*, u.vorname, u.nachname FROM ressourcen r LEFT JOIN users u ON u.id = r.verantwortlich_id WHERE r.id = ? AND r.organization_id = ?');
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
    $r = $id ? ressourceLaden($db, $id) : null;
    if ($id && !$r) redirect($self);
    $ziel = $id ? "$self?id=$id" : $self;

    if ($action === 'speichern') {
        if (!darf($id ? 'ressourcen.bearbeiten' : 'ressourcen.erstellen')) { flashMessage('error', 'Keine Berechtigung.'); redirect($ziel); }
        $d = [
            'name' => mb_substr(trim($_POST['name'] ?? ''), 0, 150), 'kategorie' => isset(RESSOURCE_KATEGORIEN[$_POST['kategorie'] ?? '']) ? $_POST['kategorie'] : 'sonstiges',
            'anzahl' => max(1, min(9999, (int)($_POST['anzahl'] ?? 1))), 'zustand' => isset(RESSOURCE_ZUSTAND[$_POST['zustand'] ?? '']) ? $_POST['zustand'] : 'gut',
            'standort' => mb_substr(trim($_POST['standort'] ?? ''), 0, 150) ?: null, 'verantwortlich_id' => (int)($_POST['verantwortlich_id'] ?? 0) ?: null,
            'verfuegbar' => !empty($_POST['verfuegbar']) ? 1 : 0, 'notiz' => trim($_POST['notiz'] ?? '') ?: null,
        ];
        if ($d['name'] === '') { flashMessage('error', 'Bitte einen Namen eingeben.'); redirect($id ? "$self?id=$id&bearbeiten=1" : "$self?neu=1"); }
        if (in_array($d['zustand'], ['defekt'], true)) $d['verfuegbar'] = 0;
        if ($r) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $db->prepare("UPDATE ressourcen SET $sets WHERE id = ?")->execute([...array_values($d), $id]);
            auditLog('geaendert', 'ressourcen', $id, $r, $d, $d['name']);
            // Weniger Stück als bereits gebucht? → Hinweis
            $stmt = $db->prepare('SELECT MIN(start) FROM ressourcen_buchungen WHERE ressource_id = ? AND ende > ? AND menge > ?');
            $stmt->execute([$id, date('Y-m-d H:i:s'), $d['anzahl']]);
            if ($erste = $stmt->fetchColumn()) {
                flashMessage('error', 'Gespeichert. Achtung: Ab ' . date('d.m.Y', strtotime($erste)) . ' sind mehr Stück gebucht als jetzt vorhanden.');
                redirect("$self?id=$id");
            }
        } else {
            $d['organization_id'] = $org_id;
            $db->prepare('INSERT INTO ressourcen (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'ressourcen', $id, null, $d, $d['name']);
        }
        flashMessage('success', 'Ressource gespeichert.');
        redirect("$self?id=$id");
    }

    if ($action === 'buchen' && $r) {
        $datum = $_POST['datum'] ?? '';
        $bis_datum = ($_POST['bis_datum'] ?? '') ?: $datum;
        $von = $_POST['von'] ?? '';
        $bis = $_POST['bis'] ?? '';
        $menge = max(1, (int)($_POST['menge'] ?? 1));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis_datum) || !preg_match('/^\d{2}:\d{2}$/', $von) || !preg_match('/^\d{2}:\d{2}$/', $bis)) {
            flashMessage('error', 'Bitte Datum und Uhrzeit angeben.');
            redirect($ziel);
        }
        $start = "$datum $von:00";
        $ende  = "$bis_datum $bis:00";
        if ($ende <= $start) { flashMessage('error', 'Das Ende muss nach dem Beginn liegen.'); redirect($ziel); }
        if ($start < date('Y-m-d H:i:s', strtotime('-1 day'))) { flashMessage('error', 'Buchungen in der Vergangenheit sind nicht möglich.'); redirect($ziel); }
        if ($menge > (int)$r['anzahl']) { flashMessage('error', 'Es sind nur ' . (int)$r['anzahl'] . ' Stück vorhanden.'); redirect($ziel); }

        // Doppelbuchungsschutz unter Sperre (MySQL), damit parallele Buchungen nicht beide durchgehen
        $db->beginTransaction();
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $db->prepare('SELECT id FROM ressourcen WHERE id = ? FOR UPDATE')->execute([$id]);
            $frei = ressourceFrei($db, $id, $start, $ende);
            if ($frei < $menge) {
                $db->rollBack();
                flashMessage('error', $frei === 0 ? '„' . $r['name'] . '“ ist in diesem Zeitraum bereits vollständig gebucht.' : 'Im Zeitraum sind nur noch ' . $frei . ' von ' . (int)$r['anzahl'] . ' Stück frei.');
                redirect($ziel);
            }
            $db->prepare('INSERT INTO ressourcen_buchungen (ressource_id, start, ende, menge, notiz, erstellt_von) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$id, $start, $ende, $menge, mb_substr(trim($_POST['notiz'] ?? ''), 0, 255) ?: null, $me]);
            $bid = (int)$db->lastInsertId();
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        auditLog('erstellt', 'ressourcen_buchungen', $bid, null, ['ressource_id' => $id, 'start' => $start, 'ende' => $ende, 'menge' => $menge], $r['name']);
        if ($r['verantwortlich_id'] && (int)$r['verantwortlich_id'] !== $me) {
            benachrichtigen((int)$r['verantwortlich_id'], 'termin', 'Neue Buchung: ' . $r['name'], $menge . ' Stück, ' . date('d.m.Y H:i', strtotime($start)) . ' – ' . date('d.m.Y H:i', strtotime($ende)), '/dashboard/ressourcen.php?id=' . $id);
        }
        flashMessage('success', 'Gebucht: ' . $menge . ' × ' . $r['name'] . '.');
        redirect($ziel);
    }

    if ($action === 'buchung_loeschen' && $r) {
        $stmt = $db->prepare('SELECT * FROM ressourcen_buchungen WHERE id = ? AND ressource_id = ?');
        $stmt->execute([(int)($_POST['buchung_id'] ?? 0), $id]);
        $b = $stmt->fetch();
        if ($b && !$b['einheit_id'] && ((int)$b['erstellt_von'] === $me || $verwalten)) {
            $db->prepare('DELETE FROM ressourcen_buchungen WHERE id = ?')->execute([$b['id']]);
            auditLog('geloescht', 'ressourcen_buchungen', (int)$b['id'], $b, null, $r['name']);
            flashMessage('success', 'Buchung storniert.');
        } elseif ($b && $b['einheit_id']) {
            flashMessage('error', 'Diese Buchung gehört zu einer Einheit – bitte dort ändern.');
        } elseif ($b) {
            flashMessage('error', 'Nur die buchende Person oder die Materialverwaltung kann diese Buchung stornieren.');
        }
        redirect($ziel);
    }

    if ($action === 'loeschen' && $r) {
        if (!darf('ressourcen.loeschen')) { flashMessage('error', 'Keine Berechtigung.'); redirect($ziel); }
        $stmt = $db->prepare('SELECT COUNT(*) FROM ressourcen_buchungen WHERE ressource_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $db->prepare('UPDATE ressourcen SET verfuegbar = 0 WHERE id = ?')->execute([$id]);
            auditLog('geaendert', 'ressourcen', $id, ['verfuegbar' => $r['verfuegbar']], ['verfuegbar' => 0], $r['name']);
            flashMessage('info', 'Die Ressource hat Buchungen und wurde daher nur als „nicht verfügbar“ markiert.');
            redirect($ziel);
        }
        $db->prepare('DELETE FROM ressourcen WHERE id = ?')->execute([$id]);
        auditLog('geloescht', 'ressourcen', $id, $r, null, $r['name']);
        flashMessage('success', 'Ressource gelöscht.');
        redirect($self);
    }
    redirect($ziel);
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$detail = !empty($_GET['id']) ? ressourceLaden($db, (int)$_GET['id']) : null;
$formular = (!empty($_GET['neu']) && darf('ressourcen.erstellen')) || ($detail && !empty($_GET['bearbeiten']) && $verwalten);
$jetzt = date('Y-m-d H:i:s');

if ($detail) {
    $stmt = $db->prepare("SELECT b.*, e.titel AS einheit_titel, e.status AS einheit_status, u.vorname, u.nachname FROM ressourcen_buchungen b
                          LEFT JOIN einheiten e ON e.id = b.einheit_id LEFT JOIN users u ON u.id = b.erstellt_von
                          WHERE b.ressource_id = ? AND b.ende >= ? ORDER BY b.start LIMIT 200");
    $stmt->execute([$detail['id'], date('Y-m-d H:i:s', strtotime('-30 days'))]);
    $buchungen = $stmt->fetchAll();
    $frei_jetzt = ressourceFrei($db, (int)$detail['id'], $jetzt, date('Y-m-d H:i:s', strtotime('+1 minute')));
} elseif (!$formular) {
    $f_kat = isset(RESSOURCE_KATEGORIEN[$_GET['kategorie'] ?? '']) ? $_GET['kategorie'] : '';
    $sql = "SELECT r.*, (SELECT COALESCE(SUM(b.menge), 0) FROM ressourcen_buchungen b LEFT JOIN einheiten e ON e.id = b.einheit_id
                         WHERE b.ressource_id = r.id AND b.start <= ? AND b.ende > ? AND (e.id IS NULL OR e.status <> 'storniert')) AS gebucht_jetzt,
                   (SELECT MIN(b.start) FROM ressourcen_buchungen b WHERE b.ressource_id = r.id AND b.start > ?) AS naechste
            FROM ressourcen r WHERE r.organization_id = ?";
    $p = [$jetzt, $jetzt, $jetzt, $org_id];
    if ($f_kat) { $sql .= ' AND r.kategorie = ?'; $p[] = $f_kat; }
    $stmt = $db->prepare($sql . ' ORDER BY r.kategorie, r.name');
    $stmt->execute($p);
    $liste = $stmt->fetchAll();
    $gesamt_stueck = array_sum(array_map(fn($x) => (int)$x['anzahl'], $liste));
    $defekt = count(array_filter($liste, fn($x) => in_array($x['zustand'], ['reparatur', 'defekt'], true)));
}

$page_title = $detail ? $detail['name'] : 'Ressourcen';
$breadcrumb = 'Ressourcen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$zustand_badge = ['neu' => 'badge-success', 'gut' => 'badge-success', 'gebraucht' => 'badge-gray', 'reparatur' => 'badge-warning', 'defekt' => 'badge-danger'];
?>

<style>
.rs-mini { font-size: 0.78rem; color: var(--text-muted); }
.rs-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; padding: 1.25rem; font-size: 0.875rem; }
.rs-inline { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); }
.rs-inline .form-group { margin: 0; }
</style>

<?php if ($formular): $f = $detail ?? ['kategorie' => 'material', 'zustand' => 'gut', 'anzahl' => 1, 'verfuegbar' => 1]; ?>
    <div class="dashboard-header">
        <a href="<?= $self . ($detail ? '?id=' . $detail['id'] : '') ?>" class="rs-mini">← zurück</a>
        <h1 class="dashboard-title"><?= $detail ? 'Ressource bearbeiten' : 'Neue Ressource' ?></h1>
    </div>
    <form method="POST" class="form-card" style="max-width: 760px;">
        <?= csrfField() ?><input type="hidden" name="action" value="speichern"><input type="hidden" name="id" value="<?= (int)($detail['id'] ?? 0) ?>">
        <div class="form-row">
            <div class="form-group" style="flex: 2;"><label class="form-label">Bezeichnung <span class="required">*</span></label><input class="form-control" name="name" maxlength="150" required value="<?= e($f['name'] ?? '') ?>" placeholder="z.B. Skateboards Kinder 28&quot;"></div>
            <div class="form-group"><label class="form-label">Kategorie</label><select class="form-control" name="kategorie"><?php foreach (RESSOURCE_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= ($f['kategorie'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Anzahl</label><input class="form-control" type="number" min="1" max="9999" name="anzahl" value="<?= (int)($f['anzahl'] ?? 1) ?>"><span class="form-hint">Für Räume/Plätze: 1</span></div>
            <div class="form-group"><label class="form-label">Zustand</label><select class="form-control" name="zustand"><?php foreach (RESSOURCE_ZUSTAND as $k => $l): ?><option value="<?= $k ?>" <?= ($f['zustand'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Standort</label><input class="form-control" name="standort" maxlength="150" value="<?= e($f['standort'] ?? '') ?>" placeholder="z.B. Lager Leibnitz"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Verantwortlich</label><select class="form-control" name="verantwortlich_id"><option value="">–</option><?php foreach (plattformTeam($db) as $t): ?><option value="<?= $t['id'] ?>" <?= (int)($f['verantwortlich_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="display: flex; align-items: flex-end;"><label class="form-check"><input type="checkbox" name="verfuegbar" value="1" <?= !empty($f['verfuegbar']) ? 'checked' : '' ?>><span class="form-check-label">Buchbar</span></label></div>
        </div>
        <div class="form-group"><label class="form-label">Notiz</label><textarea class="form-control" name="notiz" rows="3"><?= e($f['notiz'] ?? '') ?></textarea></div>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>

<?php elseif ($detail): ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <a href="<?= $self ?>" class="rs-mini">← Ressourcen</a>
            <h1 class="dashboard-title"><?= e($detail['name']) ?></h1>
            <p class="dashboard-subtitle"><?= e(RESSOURCE_KATEGORIEN[$detail['kategorie']] ?? $detail['kategorie']) ?> · <span class="badge <?= $zustand_badge[$detail['zustand']] ?? 'badge-gray' ?>"><?= e(RESSOURCE_ZUSTAND[$detail['zustand']] ?? $detail['zustand']) ?></span><?= !(int)$detail['verfuegbar'] ? ' <span class="badge badge-danger">nicht buchbar</span>' : '' ?></p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if ($verwalten): ?><a href="?id=<?= $detail['id'] ?>&bearbeiten=1" class="btn btn-navy btn-sm">Bearbeiten</a><?php endif; ?>
            <?php if (darf('ressourcen.loeschen')): ?><form method="POST" onsubmit="return confirm('Ressource löschen?');"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><button class="btn btn-ghost-light btn-sm">Löschen</button></form><?php endif; ?>
        </div>
    </div>
    <div class="table-card">
        <div class="rs-info">
            <div><strong>Bestand</strong><br><?= (int)$detail['anzahl'] ?> Stück</div>
            <div><strong>Jetzt frei</strong><br><?= (int)$frei_jetzt ?> Stück</div>
            <div><strong>Standort</strong><br><?= e($detail['standort'] ?: '–') ?></div>
            <div><strong>Verantwortlich</strong><br><?= $detail['vorname'] ? e($detail['vorname'] . ' ' . $detail['nachname']) : '–' ?></div>
        </div>
        <?php if ($detail['notiz']): ?><div style="padding: 0 1.25rem 1.25rem; font-size: 0.875rem;"><?= nl2br(e($detail['notiz'])) ?></div><?php endif; ?>
    </div>

    <div class="table-card" style="margin-top: 1.5rem;">
        <div class="table-card-header"><h2 class="table-card-title">Buchungen</h2></div>
        <?php if (!$buchungen): ?><p class="rs-mini" style="padding: 1rem 1.25rem;">Keine aktuellen Buchungen.</p>
        <?php else: ?>
        <div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Zeitraum</th><th>Menge</th><th>Zweck</th><th>Gebucht von</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($buchungen as $b): $vorbei = $b['ende'] < $jetzt; $storno = $b['einheit_status'] === 'storniert'; ?>
                <tr<?= $vorbei || $storno ? ' style="opacity: 0.55;"' : '' ?>>
                    <td><?= date('d.m.Y H:i', strtotime($b['start'])) ?> – <?= substr($b['start'], 0, 10) === substr($b['ende'], 0, 10) ? date('H:i', strtotime($b['ende'])) : date('d.m.Y H:i', strtotime($b['ende'])) ?></td>
                    <td><?= (int)$b['menge'] ?></td>
                    <td><?php if ($b['einheit_id']): ?><a href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= (int)$b['einheit_id'] ?>"><?= e($b['einheit_titel'] ?? 'Einheit') ?></a><?= $storno ? ' <span class="badge badge-danger">storniert</span>' : '' ?><?php else: ?><?= e($b['notiz'] ?? '–') ?><?php endif; ?></td>
                    <td><?= $b['vorname'] ? e($b['vorname'] . ' ' . $b['nachname']) : '–' ?></td>
                    <td><?php if (!$b['einheit_id'] && !$vorbei && ((int)$b['erstellt_von'] === $me || $verwalten)): ?><form method="POST" onsubmit="return confirm('Buchung stornieren?');"><?= csrfField() ?><input type="hidden" name="action" value="buchung_loeschen"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><input type="hidden" name="buchung_id" value="<?= $b['id'] ?>"><button class="btn btn-ghost-light btn-sm" aria-label="Stornieren">✕</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <?php if ((int)$detail['verfuegbar']): ?>
        <form method="POST" class="rs-inline"><?= csrfField() ?><input type="hidden" name="action" value="buchen"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
            <div class="form-group"><label class="form-label">Von</label><input class="form-control" type="date" name="datum" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label class="form-label">Uhrzeit</label><input class="form-control" type="time" name="von" required value="09:00"></div>
            <div class="form-group"><label class="form-label">Bis</label><input class="form-control" type="date" name="bis_datum" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label class="form-label">Uhrzeit</label><input class="form-control" type="time" name="bis" required value="12:00"></div>
            <div class="form-group"><label class="form-label">Menge</label><input class="form-control" type="number" name="menge" min="1" max="<?= (int)$detail['anzahl'] ?>" value="1" style="width: 90px;"></div>
            <div class="form-group" style="flex: 1; min-width: 180px;"><label class="form-label">Zweck</label><input class="form-control" name="notiz" maxlength="255" placeholder="z.B. Schulprojekt VS Leibnitz"></div>
            <button type="submit" class="btn btn-navy btn-sm">Buchen</button>
        </form>
        <p class="form-hint" style="padding: 0 1.25rem 1rem;">Für Trainingseinheiten Ressourcen direkt bei „Einheit planen“ auswählen – dann sind sie mit dem Termin verknüpft.</p>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 class="dashboard-title">Ressourcen &amp; Material</h1>
            <p class="dashboard-subtitle"><?= count($liste) ?> Positionen · <?= $gesamt_stueck ?> Stück<?= $defekt ? ' · <span style="color:#B45309;">' . $defekt . ' in Reparatur/defekt</span>' : '' ?></p>
        </div>
        <?php if (darf('ressourcen.erstellen')): ?><a href="?neu=1" class="btn btn-primary btn-sm">+ Ressource anlegen</a><?php endif; ?>
    </div>
    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
        <select class="form-control" style="max-width: 240px;" name="kategorie" onchange="this.form.submit()"><option value="">Alle Kategorien</option><?php foreach (RESSOURCE_KATEGORIEN as $k => $l): ?><option value="<?= $k ?>" <?= $f_kat === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <noscript><button class="btn btn-navy btn-sm">Filtern</button></noscript>
    </form>
    <div class="table-card">
        <?php if (!$liste): ?><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Noch keine Ressourcen</h3><p>Skateboards, Helme, Hallen oder Plätze anlegen – dann können sie bei Einheiten gebucht werden, ohne doppelt vergeben zu werden.</p></div>
        <?php else: ?>
        <div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Ressource</th><th>Kategorie</th><th>Bestand</th><th>Jetzt frei</th><th>Zustand</th><th>Nächste Buchung</th></tr></thead>
            <tbody>
            <?php foreach ($liste as $x): $frei = (int)$x['verfuegbar'] ? max(0, (int)$x['anzahl'] - (int)$x['gebucht_jetzt']) : 0; ?>
                <tr data-row-href="?id=<?= $x['id'] ?>"<?= !(int)$x['verfuegbar'] ? ' style="opacity: 0.6;"' : '' ?>>
                    <td class="text-primary"><?= e($x['name']) ?><div class="rs-mini"><?= e($x['standort'] ?? '') ?></div></td>
                    <td><?= e(RESSOURCE_KATEGORIEN[$x['kategorie']] ?? $x['kategorie']) ?></td>
                    <td><?= (int)$x['anzahl'] ?></td>
                    <td><?= (int)$x['verfuegbar'] ? $frei : '<span class="badge badge-danger">gesperrt</span>' ?></td>
                    <td><span class="badge <?= $zustand_badge[$x['zustand']] ?? 'badge-gray' ?>"><?= e(RESSOURCE_ZUSTAND[$x['zustand']] ?? $x['zustand']) ?></span></td>
                    <td><?= $x['naechste'] ? date('d.m.Y H:i', strtotime($x['naechste'])) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
