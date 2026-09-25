<?php
/**
 * Athletikclub Steiermark – Admin: Fördermanagement (Übersicht)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';

requireDarf('foerderungen.anzeigen');

$db = getDB();

$status_map = FOERDER_STATUS;

// ----------------------------------------------------------------
// Filter
// ----------------------------------------------------------------
$filter_status = $_GET['status'] ?? '';
$filter_suche  = trim($_GET['suche'] ?? '');

$where  = 'organization_id = ?';
$params = [currentOrgId()];

if ($filter_status && isset($status_map[$filter_status])) {
    $where .= ' AND status = ?';
    $params[] = $filter_status;
}
if ($filter_suche) {
    $where .= ' AND (titel LIKE ? OR foerderstelle LIKE ? OR foerderprogramm LIKE ?)';
    $params[] = '%' . $filter_suche . '%';
    $params[] = '%' . $filter_suche . '%';
    $params[] = '%' . $filter_suche . '%';
}

$stmt = $db->prepare("SELECT * FROM foerderungen WHERE {$where} ORDER BY
    CASE WHEN einreichfrist IS NULL THEN 1 ELSE 0 END, einreichfrist ASC,
    created_at DESC");
$stmt->execute($params);
$foerderungen = $stmt->fetchAll();
foreach ($foerderungen as &$f) $f['budget'] = foerderBudget($db, $f);
unset($f);

// ----------------------------------------------------------------
// KPIs (über alle Förderungen der Organisation, ungefiltert)
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT status, betrag_beantragt, betrag_bewilligt, betrag_ausbezahlt, einreichfrist, nachweisfrist, abrechnungsfrist FROM foerderungen WHERE organization_id = ?');
$stmt->execute([currentOrgId()]);
$alle = $stmt->fetchAll();

$offene_frist_bald = 0;
$heute = new DateTime();
foreach ($alle as $f) {
    foreach ([$f['einreichfrist'], $f['nachweisfrist'], $f['abrechnungsfrist']] as $frist) {
        if ($frist) {
            $diff = $heute->diff(new DateTime($frist))->days;
            $ist_zukunft = new DateTime($frist) >= $heute;
            if ($ist_zukunft && $diff <= 30) {
                $offene_frist_bald++;
                break;
            }
        }
    }
}

$summe_bewilligt  = array_sum(array_column(array_filter($alle, fn($f) => in_array($f['status'], FOERDER_ZUGESAGT, true)), 'betrag_bewilligt'));
// Ausbezahlt: erfasster Auszahlungsbetrag, sonst (Altbestand) die bewilligte Summe bei Status ausbezahlt/abgeschlossen
$summe_ausbezahlt = array_sum(array_map(fn($f) => $f['betrag_ausbezahlt'] !== null ? (float)$f['betrag_ausbezahlt'] : (in_array($f['status'], ['ausbezahlt', 'abgeschlossen'], true) ? (float)$f['betrag_bewilligt'] : 0), $alle));
$anzahl_laufend   = count(array_filter($alle, fn($f) => in_array($f['status'], FOERDER_OFFEN, true)));

$page_title = 'Fördermanagement';
$breadcrumb = 'Fördermanagement';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header" style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="dashboard-title">Fördermanagement</h1>
        <p class="dashboard-subtitle">Förderansuchen, Bewilligungen und Verwendungsnachweise im Überblick.</p>
    </div>
    <?php if (darf('foerderungen.bearbeiten')): ?>
    <a href="<?= APP_URL ?>/dashboard/admin/foerderung-erstellen.php" class="btn btn-primary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Neue Förderung
    </a>
    <?php endif; ?>
</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="kpi-value"><?= number_format((float)$summe_bewilligt, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Bewilligt (gesamt)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg></div>
        <div class="kpi-value"><?= number_format((float)$summe_ausbezahlt, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Bereits ausbezahlt</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #3B82F6;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div class="kpi-value"><?= $anzahl_laufend ?></div>
        <div class="kpi-label">Laufende Ansuchen</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #EF4444;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        <div class="kpi-value"><?= $offene_frist_bald ?></div>
        <div class="kpi-label">Fristen in 30 Tagen</div>
    </div>
</div>

<!-- Filter -->
<div style="background: var(--surface); border-radius: 1rem; padding: 1rem 1.25rem; border: 1px solid var(--border-light); margin-bottom: 1.5rem;">
    <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
            <label class="form-label">Suche</label>
            <input class="form-control" type="text" name="suche" value="<?= e($filter_suche) ?>" placeholder="Titel oder Förderstelle…">
        </div>
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="">Alle Status</option>
                <?php foreach ($status_map as $val => $info): ?>
                    <option value="<?= $val ?>" <?= $filter_status === $val ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-navy btn-sm" style="margin-bottom: 0;">Filtern</button>
        <?php if ($filter_suche || $filter_status): ?>
            <a href="<?= APP_URL ?>/dashboard/admin/foerderungen.php" class="btn btn-ghost-light btn-sm" style="margin-bottom: 0;">Zurücksetzen</a>
        <?php endif; ?>
    </form>
</div>

<!-- Liste -->
<div class="table-card">
    <div class="table-card-header">
        <h2 class="table-card-title">Förderungen (<?= count($foerderungen) ?>)</h2>
    </div>
    <?php if (empty($foerderungen)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg>
            </div>
            <h3><?= ($filter_suche || $filter_status) ? 'Keine Treffer' : 'Noch keine Förderungen erfasst' ?></h3>
            <p><?= ($filter_suche || $filter_status) ? 'Keine Förderungen entsprechen deiner Suche.' : 'Lege die erste Förderung an, um den Überblick zu behalten.' ?></p>
            <?php if (!$filter_suche && !$filter_status): ?>
                <a href="<?= APP_URL ?>/dashboard/admin/foerderung-erstellen.php" class="btn btn-primary btn-sm">Neue Förderung anlegen</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Förderstelle</th>
                        <th>Beantragt</th>
                        <th>Bewilligt</th>
                        <th>Verbraucht / Rest</th>
                        <th>Status</th>
                        <th>Frist</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($foerderungen as $f): $s = $status_map[$f['status']] ?? ['label' => $f['status'], 'class' => 'badge-gray']; ?>
                    <tr>
                        <td class="text-primary"><?= e($f['titel']) ?></td>
                        <td><?= e($f['foerderstelle']) ?><?php if (!empty($f['foerderprogramm'])): ?><br><span style="font-size: 0.75rem; color: var(--text-muted);"><?= e($f['foerderprogramm']) ?></span><?php endif; ?></td>
                        <td><?= $f['betrag_beantragt'] !== null ? number_format((float)$f['betrag_beantragt'], 2, ',', '.') . ' €' : '–' ?></td>
                        <td><?= $f['betrag_bewilligt'] !== null ? number_format((float)$f['betrag_bewilligt'], 2, ',', '.') . ' €' : '–' ?></td>
                        <td><?php if ($f['budget']['anzahl'] || bccomp($f['budget']['bewilligt'], '0', 2) > 0): ?><?= moneyFormat($f['budget']['verbraucht']) ?><br><span style="font-size: 0.75rem; color: <?= bccomp($f['budget']['rest'], '0', 2) < 0 ? '#B91C1C' : 'var(--text-muted)' ?>;">Rest <?= moneyFormat($f['budget']['rest']) ?></span><?php else: ?>–<?php endif; ?></td>
                        <td><span class="badge <?= $s['class'] ?>"><?= e($s['label']) ?></span></td>
                        <td>
                            <?php if ($f['status'] === 'abgeschlossen' && $f['nachweisfrist']): ?>
                                Nachweis: <?= date('d.m.Y', strtotime($f['nachweisfrist'])) ?>
                            <?php elseif (in_array($f['status'], ['bewilligt', 'in_umsetzung', 'ausbezahlt'], true) && !empty($f['abrechnungsfrist']) && (!$f['nachweisfrist'] || $f['abrechnungsfrist'] < $f['nachweisfrist'])): ?>
                                Abrechnung bis <?= date('d.m.Y', strtotime($f['abrechnungsfrist'])) ?>
                            <?php elseif (in_array($f['status'], ['bewilligt', 'in_umsetzung', 'ausbezahlt', 'abgerechnet'], true) && $f['nachweisfrist']): ?>
                                Nachweis bis <?= date('d.m.Y', strtotime($f['nachweisfrist'])) ?>
                            <?php elseif ($f['einreichfrist']): ?>
                                Einreichung bis <?= date('d.m.Y', strtotime($f['einreichfrist'])) ?>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                        <td><a href="<?= APP_URL ?>/dashboard/admin/foerderung-detail.php?id=<?= $f['id'] ?>" class="btn btn-ghost-light btn-sm">Details</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
