<?php
/**
 * Athletikclub Steiermark – Verträge
 * Kooperations-, Miet-, Sponsoring-, Trainer- und sonstige Verträge mit Laufzeit,
 * Kündigungsfrist und PDF. Warnungen bei nahender Kündigungsfrist / Vertragsende
 * (Liste + tägliche Benachrichtigung über plattformFaelligkeiten).
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireDarf('vertraege.anzeigen');

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$self   = APP_URL . '/dashboard/admin/vertraege.php';
$heute  = date('Y-m-d');
$WARN_TAGE = 45;

function vertragLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT v.*, o.name AS partner_name, u.vorname, u.nachname, p.name AS projekt_name FROM vertraege v
                          LEFT JOIN partner_organisationen o ON o.id = v.partner_id LEFT JOIN users u ON u.id = v.user_id LEFT JOIN projekte p ON p.id = v.projekt_id
                          WHERE v.id = ? AND v.organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/** Warnstufe eines Vertrags: ['text', 'badge-…'] oder null. */
function vertragWarnung(array $v, int $warn_tage): ?array
{
    if ($v['status'] !== 'aktiv') return null;
    $heute = date('Y-m-d');
    $grenze = date('Y-m-d', strtotime("+{$warn_tage} days"));
    if ($v['ende'] && $v['ende'] < $heute) return ['Ende überschritten – Status prüfen', 'badge-danger'];
    if ($v['kuendigung_bis'] && $v['kuendigung_bis'] >= $heute && $v['kuendigung_bis'] <= $grenze) return ['Kündigung bis ' . date('d.m.Y', strtotime($v['kuendigung_bis'])) . ' (' . plattformFrist($v['kuendigung_bis']) . ')', 'badge-warning'];
    if ($v['ende'] && $v['ende'] <= $grenze) return ['Endet ' . plattformFrist($v['ende']), 'badge-warning'];
    return null;
}

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $v = $id ? vertragLaden($db, $id) : null;
    if ($id && !$v) redirect($self);
    $ziel = $id ? "$self?id=$id" : $self;
    $recht = ['speichern' => $id ? 'vertraege.bearbeiten' : 'vertraege.erstellen', 'dokument' => 'vertraege.bearbeiten', 'loeschen' => 'vertraege.loeschen'][$action] ?? null;
    if (!$recht || !darf($recht)) { flashMessage('error', 'Keine Berechtigung für diese Aktion.'); redirect($ziel); }

    if ($action === 'speichern') {
        $datum = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$f] ?? '') ? $_POST[$f] : null;
        $wert = trim(str_replace(',', '.', $_POST['wert'] ?? ''));
        $d = [
            'titel' => mb_substr(trim($_POST['titel'] ?? ''), 0, 200), 'vertragsart' => isset(VERTRAG_ARTEN[$_POST['vertragsart'] ?? '']) ? $_POST['vertragsart'] : 'sonstiges',
            'partner_id' => (int)($_POST['partner_id'] ?? 0) ?: null, 'user_id' => (int)($_POST['user_id'] ?? 0) ?: null, 'projekt_id' => (int)($_POST['projekt_id'] ?? 0) ?: null,
            'beginn' => $datum('beginn'), 'ende' => $datum('ende'), 'kuendigungsfrist' => mb_substr(trim($_POST['kuendigungsfrist'] ?? ''), 0, 100) ?: null,
            'kuendigung_bis' => $datum('kuendigung_bis'), 'wert' => is_numeric($wert) ? moneyRound($wert) : null,
            'status' => isset(VERTRAG_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'aktiv', 'notiz' => trim($_POST['notiz'] ?? '') ?: null,
        ];
        $form = $id ? "$self?id=$id&bearbeiten=1" : "$self?neu=1";
        if ($d['titel'] === '') { flashMessage('error', 'Bitte einen Titel eingeben.'); redirect($form); }
        if ($d['beginn'] && $d['ende'] && $d['ende'] < $d['beginn']) { flashMessage('error', 'Das Vertragsende liegt vor dem Beginn.'); redirect($form); }
        // Kündigungsstichtag aus „3 Monate“ + Vertragsende ableiten, wenn nicht angegeben
        if (!$d['kuendigung_bis'] && $d['ende'] && $d['kuendigungsfrist'] && preg_match('/(\d+)\s*(Monat|Woche)/iu', $d['kuendigungsfrist'], $m)) {
            // Zugang spätestens N Monate/Wochen vor Ablauf: (Ende + 1 Tag) − N − 1 Tag, z.B. Ende 30.06. / 3 Monate → 31.03.
            $ab = strtotime('+1 day', strtotime($d['ende']));
            $d['kuendigung_bis'] = date('Y-m-d', strtotime('-1 day', strtotime("-{$m[1]} " . (stripos($m[2], 'Monat') === 0 ? 'months' : 'weeks'), $ab)));
        }
        if ($v) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $db->prepare("UPDATE vertraege SET $sets WHERE id = ?")->execute([...array_values($d), $id]);
            auditLog('geaendert', 'vertraege', $id, $v, $d, $d['titel']);
        } else {
            $d += ['organization_id' => $org_id, 'erstellt_von' => $me];
            $db->prepare('INSERT INTO vertraege (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')')->execute(array_values($d));
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'vertraege', $id, null, $d, $d['titel']);
        }
        if (!empty($_FILES['datei']['name'])) {
            $r = plattformPdfUpload($db, $_FILES['datei'], 'Vertrag: ' . $d['titel'], 'vertrag', ['vertrag_id' => $id, 'partner_id' => $d['partner_id'], 'projekt_id' => $d['projekt_id']], 'admin');
            if (isset($r['fehler'])) { flashMessage('error', 'Gespeichert, aber das PDF wurde nicht hochgeladen: ' . $r['fehler']); redirect("$self?id=$id"); }
        }
        flashMessage('success', 'Vertrag gespeichert.' . ($d['kuendigung_bis'] && empty($_POST['kuendigung_bis']) ? ' Kündigungsstichtag automatisch berechnet: ' . date('d.m.Y', strtotime($d['kuendigung_bis'])) . '.' : ''));
        redirect("$self?id=$id");
    }

    if ($action === 'dokument') {
        $r = plattformPdfUpload($db, $_FILES['datei'] ?? [], trim($_POST['titel'] ?? '') ?: 'Vertrag: ' . $v['titel'], 'vertrag', ['vertrag_id' => $id, 'partner_id' => $v['partner_id'], 'projekt_id' => $v['projekt_id']], 'admin');
        flashMessage(isset($r['fehler']) ? 'error' : 'success', $r['fehler'] ?? 'Dokument hochgeladen.');
        redirect($ziel);
    }

    if ($action === 'loeschen') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM dokumente WHERE vertrag_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $db->prepare("UPDATE vertraege SET status = 'beendet' WHERE id = ?")->execute([$id]);
            auditLog('status', 'vertraege', $id, ['status' => $v['status']], ['status' => 'beendet'], $v['titel']);
            flashMessage('info', 'Der Vertrag hat Dokumente und wurde daher auf „beendet“ gesetzt statt gelöscht.');
            redirect($ziel);
        }
        $db->prepare('DELETE FROM vertraege WHERE id = ?')->execute([$id]);
        auditLog('geloescht', 'vertraege', $id, $v, null, $v['titel']);
        flashMessage('success', 'Vertrag gelöscht.');
        redirect($self);
    }
    redirect($ziel);
}

// ----------------------------------------------------------------
// Anzeige
// ----------------------------------------------------------------
$detail = !empty($_GET['id']) ? vertragLaden($db, (int)$_GET['id']) : null;
$formular = (!empty($_GET['neu']) && darf('vertraege.erstellen')) || ($detail && !empty($_GET['bearbeiten']) && darf('vertraege.bearbeiten'));

$partner_liste = [];
try {
    $stmt = $db->prepare('SELECT id, name FROM partner_organisationen WHERE organization_id = ? ORDER BY name');
    $stmt->execute([$org_id]);
    $partner_liste = $stmt->fetchAll();
} catch (Exception $e) {}

if ($detail) {
    $stmt = $db->prepare('SELECT id, titel, created_at FROM dokumente WHERE vertrag_id = ? AND organization_id = ? ORDER BY created_at DESC');
    $stmt->execute([$detail['id'], $org_id]);
    $dokumente = $stmt->fetchAll();
} elseif (!$formular) {
    $f_status = isset(VERTRAG_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
    $f_art = isset(VERTRAG_ARTEN[$_GET['art'] ?? '']) ? $_GET['art'] : '';
    $sql = 'SELECT v.*, o.name AS partner_name, u.vorname, u.nachname, p.name AS projekt_name FROM vertraege v
            LEFT JOIN partner_organisationen o ON o.id = v.partner_id LEFT JOIN users u ON u.id = v.user_id LEFT JOIN projekte p ON p.id = v.projekt_id
            WHERE v.organization_id = ?';
    $p = [$org_id];
    if ($f_status) { $sql .= ' AND v.status = ?'; $p[] = $f_status; } else { $sql .= " AND v.status IN ('entwurf','aktiv','gekuendigt')"; }
    if ($f_art) { $sql .= ' AND v.vertragsart = ?'; $p[] = $f_art; }
    $stmt = $db->prepare($sql . ' ORDER BY COALESCE(v.kuendigung_bis, v.ende, \'9999-12-31\'), v.titel');
    $stmt->execute($p);
    $liste = $stmt->fetchAll();
    $warnungen = array_filter($liste, fn($v) => vertragWarnung($v, $WARN_TAGE) !== null);
    $summe_jahr = '0.00';
    foreach ($liste as $v) if ($v['status'] === 'aktiv' && $v['wert'] !== null) $summe_jahr = bcadd($summe_jahr, moneyRound($v['wert']), 2);
}

$page_title = $detail ? $detail['titel'] : 'Verträge';
$breadcrumb = 'Verträge';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.vt-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; padding: 1.25rem; font-size: 0.875rem; }
.vt-mini { font-size: 0.78rem; color: var(--text-muted); }
.vt-warn { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.25rem; }
.vt-warn a { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 0.7rem 1rem; border-radius: 0.6rem; border: 1px solid rgba(245, 158, 11, 0.35); background: rgba(245, 158, 11, 0.08); color: var(--text-primary); font-size: 0.875rem; }
</style>

<?php if ($formular): $f = $detail ?? ['status' => 'aktiv', 'vertragsart' => 'kooperation', 'partner_id' => (int)($_GET['partner'] ?? 0), 'projekt_id' => (int)($_GET['projekt'] ?? 0)]; ?>
    <div class="dashboard-header">
        <a href="<?= $self . ($detail ? '?id=' . $detail['id'] : '') ?>" class="vt-mini">← zurück</a>
        <h1 class="dashboard-title"><?= $detail ? 'Vertrag bearbeiten' : 'Neuer Vertrag' ?></h1>
    </div>
    <form method="POST" enctype="multipart/form-data" class="form-card" style="max-width: 820px;">
        <?= csrfField() ?><input type="hidden" name="action" value="speichern"><input type="hidden" name="id" value="<?= (int)($detail['id'] ?? 0) ?>">
        <div class="form-row">
            <div class="form-group" style="flex: 2;"><label class="form-label">Titel <span class="required">*</span></label><input class="form-control" name="titel" maxlength="200" required value="<?= e($f['titel'] ?? '') ?>" placeholder="z.B. Hallennutzung Volksschule 2026/27"></div>
            <div class="form-group"><label class="form-label">Vertragsart</label><select class="form-control" name="vertragsart"><?php foreach (VERTRAG_ARTEN as $k => $l): ?><option value="<?= $k ?>" <?= ($f['vertragsart'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Status</label><select class="form-control" name="status"><?php foreach (VERTRAG_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Vertragspartner (Organisation)</label><select class="form-control" name="partner_id"><option value="">–</option><?php foreach ($partner_liste as $o): ?><option value="<?= $o['id'] ?>" <?= (int)($f['partner_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">oder Person (Trainer:in)</label><select class="form-control" name="user_id"><option value="">–</option><?php foreach (plattformTrainer($db) as $t): ?><option value="<?= $t['id'] ?>" <?= (int)($f['user_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['vorname'] . ' ' . $t['nachname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Projekt</label><select class="form-control" name="projekt_id"><option value="">–</option><?php foreach (plattformProjekte($db, false) as $p): ?><option value="<?= $p['id'] ?>" <?= (int)($f['projekt_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Beginn</label><input class="form-control" type="date" name="beginn" value="<?= e($f['beginn'] ?? '') ?>"></div>
            <div class="form-group"><label class="form-label">Ende</label><input class="form-control" type="date" name="ende" value="<?= e($f['ende'] ?? '') ?>"><span class="form-hint">Leer = unbefristet</span></div>
            <div class="form-group"><label class="form-label">Wert pro Jahr (€)</label><input class="form-control" name="wert" inputmode="decimal" value="<?= e($f['wert'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Kündigungsfrist</label><input class="form-control" name="kuendigungsfrist" maxlength="100" value="<?= e($f['kuendigungsfrist'] ?? '') ?>" placeholder="z.B. 3 Monate zum Jahresende"></div>
            <div class="form-group"><label class="form-label">Kündigen spätestens am</label><input class="form-control" type="date" name="kuendigung_bis" value="<?= e($f['kuendigung_bis'] ?? '') ?>"><span class="form-hint">Leer lassen: wird aus Frist („3 Monate“) und Ende berechnet.</span></div>
        </div>
        <div class="form-group"><label class="form-label">Notiz</label><textarea class="form-control" name="notiz" rows="3"><?= e($f['notiz'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Vertrag als PDF</label><input class="form-control" type="file" name="datei" accept="application/pdf,.pdf"></div>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>

<?php elseif ($detail): $vs = VERTRAG_STATUS[$detail['status']] ?? ['label' => $detail['status'], 'class' => 'badge-gray']; $w = vertragWarnung($detail, $WARN_TAGE); ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <a href="<?= $self ?>" class="vt-mini">← Verträge</a>
            <h1 class="dashboard-title"><?= e($detail['titel']) ?></h1>
            <p class="dashboard-subtitle"><?= e(VERTRAG_ARTEN[$detail['vertragsart']] ?? $detail['vertragsart']) ?> · <span class="badge <?= $vs['class'] ?>"><?= e($vs['label']) ?></span><?= $w ? ' <span class="badge ' . $w[1] . '">' . e($w[0]) . '</span>' : '' ?></p>
        </div>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <?php if (darf('vertraege.bearbeiten')): ?><a href="?id=<?= $detail['id'] ?>&bearbeiten=1" class="btn btn-navy btn-sm">Bearbeiten</a><?php endif; ?>
            <?php if (darf('vertraege.loeschen')): ?><form method="POST" onsubmit="return confirm('Vertrag löschen? Mit Dokumenten wird er nur auf beendet gesetzt.');"><?= csrfField() ?><input type="hidden" name="action" value="loeschen"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><button class="btn btn-ghost-light btn-sm">Löschen</button></form><?php endif; ?>
        </div>
    </div>
    <div class="table-card">
        <div class="vt-info">
            <div><strong>Vertragspartner</strong><br><?php if ($detail['partner_name']): ?><?= darf('partner.anzeigen') ? '<a href="' . APP_URL . '/dashboard/admin/partner.php?id=' . (int)$detail['partner_id'] . '">' . e($detail['partner_name']) . '</a>' : e($detail['partner_name']) ?><?php elseif ($detail['vorname']): ?><?= e($detail['vorname'] . ' ' . $detail['nachname']) ?><?php else: ?>–<?php endif; ?></div>
            <div><strong>Laufzeit</strong><br><?= plattformDatum($detail['beginn']) ?> – <?= $detail['ende'] ? plattformDatum($detail['ende']) : 'unbefristet' ?></div>
            <div><strong>Kündigungsfrist</strong><br><?= e($detail['kuendigungsfrist'] ?: '–') ?><?= $detail['kuendigung_bis'] ? '<div class="vt-mini">spätestens ' . plattformDatum($detail['kuendigung_bis']) . '</div>' : '' ?></div>
            <div><strong>Wert / Jahr</strong><br><?= $detail['wert'] !== null ? moneyFormat($detail['wert']) : '–' ?></div>
            <?php if ($detail['projekt_name']): ?><div><strong>Projekt</strong><br><a href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= (int)$detail['projekt_id'] ?>"><?= e($detail['projekt_name']) ?></a></div><?php endif; ?>
        </div>
        <?php if ($detail['notiz']): ?><div style="padding: 0 1.25rem 1.25rem; font-size: 0.875rem; line-height: 1.6;"><?= nl2br(e($detail['notiz'])) ?></div><?php endif; ?>
    </div>
    <div class="table-card" style="margin-top: 1.5rem;">
        <div class="table-card-header"><h2 class="table-card-title">Dokumente</h2></div>
        <?php if ($dokumente): ?><table class="data-table"><tbody><?php foreach ($dokumente as $d): ?><tr><td><a class="text-primary" href="<?= APP_URL ?>/api/dokument-download.php?id=<?= $d['id'] ?>"><?= e($d['titel']) ?></a></td><td class="vt-mini"><?= date('d.m.Y', strtotime($d['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table>
        <?php else: ?><p class="vt-mini" style="padding: 1rem 1.25rem;">Noch kein PDF hinterlegt.</p><?php endif; ?>
        <?php if (darf('vertraege.bearbeiten')): ?>
        <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; padding: 1rem 1.25rem; border-top: 1px solid var(--border-light);"><?= csrfField() ?><input type="hidden" name="action" value="dokument"><input type="hidden" name="id" value="<?= $detail['id'] ?>">
            <div class="form-group" style="margin: 0; flex: 1; min-width: 180px;"><label class="form-label">Titel</label><input class="form-control" name="titel" maxlength="200" placeholder="z.B. Nachtrag 1"></div>
            <div class="form-group" style="margin: 0;"><label class="form-label">PDF</label><input class="form-control" type="file" name="datei" accept="application/pdf,.pdf" required></div>
            <button class="btn btn-navy btn-sm">Hochladen</button>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 class="dashboard-title">Verträge</h1>
            <p class="dashboard-subtitle"><?= count($liste) ?> Verträge<?= bccomp($summe_jahr, '0', 2) > 0 ? ' · aktive Vertragswerte ' . moneyFormat($summe_jahr) . ' / Jahr' : '' ?></p>
        </div>
        <?php if (darf('vertraege.erstellen')): ?><a href="?neu=1" class="btn btn-primary btn-sm">+ Vertrag anlegen</a><?php endif; ?>
    </div>

    <?php if ($warnungen): ?>
    <div class="vt-warn">
        <?php foreach ($warnungen as $v): $w = vertragWarnung($v, $WARN_TAGE); ?>
            <a href="?id=<?= $v['id'] ?>"><strong><?= e($v['titel']) ?></strong><span class="badge <?= $w[1] ?>"><?= e($w[0]) ?></span></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="GET" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
        <select class="form-control" style="max-width: 220px;" name="art"><option value="">Alle Vertragsarten</option><?php foreach (VERTRAG_ARTEN as $k => $l): ?><option value="<?= $k ?>" <?= $f_art === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-control" style="max-width: 200px;" name="status"><option value="">Laufende</option><?php foreach (VERTRAG_STATUS as $k => $s): ?><option value="<?= $k ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-navy btn-sm">Filtern</button>
    </form>
    <div class="table-card">
        <?php if (!$liste): ?><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Verträge</h3><p>Lege Kooperations-, Miet- oder Sponsoringverträge an – das System erinnert rechtzeitig an Kündigungsfristen.</p></div>
        <?php else: ?>
        <div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Vertrag</th><th>Partner</th><th>Laufzeit</th><th>Kündigen bis</th><th>Wert/Jahr</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($liste as $v): $vs = VERTRAG_STATUS[$v['status']] ?? ['label' => $v['status'], 'class' => 'badge-gray']; $w = vertragWarnung($v, $WARN_TAGE); ?>
                <tr data-row-href="?id=<?= $v['id'] ?>">
                    <td class="text-primary"><?= e($v['titel']) ?><div class="vt-mini"><?= e(VERTRAG_ARTEN[$v['vertragsart']] ?? $v['vertragsart']) ?><?= $v['projekt_name'] ? ' · ' . e($v['projekt_name']) : '' ?></div></td>
                    <td><?= e($v['partner_name'] ?? ($v['vorname'] ? $v['vorname'] . ' ' . $v['nachname'] : '–')) ?></td>
                    <td><?= plattformDatum($v['beginn']) ?> – <?= $v['ende'] ? plattformDatum($v['ende']) : 'unbefristet' ?></td>
                    <td><?= plattformDatum($v['kuendigung_bis']) ?><?= $w ? '<br><span class="badge ' . $w[1] . '">' . e($w[0]) . '</span>' : '' ?></td>
                    <td><?= $v['wert'] !== null ? moneyFormat($v['wert']) : '–' ?></td>
                    <td><span class="badge <?= $vs['class'] ?>"><?= e($vs['label']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
