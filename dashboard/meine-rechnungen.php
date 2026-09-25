<?php
/**
 * Athletikclub Steiermark – Meine Rechnungen (eigene, ausgestellte Rechnungen)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/rechnungen.php';

requireLogin();

$db = getDB();
$me = (int)getCurrentUserId();

if (!empty($_GET['pdf'])) {
    $stmt = $db->prepare("SELECT * FROM rechnungen WHERE id = ? AND user_id = ? AND organization_id = ? AND status <> 'entwurf'");
    $stmt->execute([(int)$_GET['pdf'], $me, currentOrgId()]);
    if ($r = $stmt->fetch()) {
        require_once ROOT_PATH . '/includes/rechnung-pdf.php';
        $bytes = rechnungPdfErzeugen($db, $r);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rechnungPdfDateiname($r) . '"');
        echo $bytes;
        exit;
    }
    http_response_code(404);
    exit('Rechnung nicht gefunden.');
}

$liste = [];
try {
    $stmt = $db->prepare("SELECT r.*, (SELECT COALESCE(SUM(z.betrag), 0) FROM zahlungen z WHERE z.rechnung_id = r.id) AS bezahlt
                          FROM rechnungen r WHERE r.user_id = ? AND r.organization_id = ? AND r.status <> 'entwurf' ORDER BY r.rechnungsdatum DESC, r.id DESC LIMIT 200");
    $stmt->execute([$me, currentOrgId()]);
    $liste = $stmt->fetchAll();
} catch (Exception $e) {}
$v = verein();

$page_title = 'Meine Rechnungen';
$breadcrumb = 'Meine Rechnungen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<div class="dashboard-header">
    <h1 class="dashboard-title">Meine Rechnungen</h1>
    <p class="dashboard-subtitle">Kursbeiträge und weitere Rechnungen<?= $v['iban'] ? ' · Bankverbindung: ' . e(ibanFormat($v['iban'])) : '' ?></p>
</div>
<div class="table-card">
    <?php if (!$liste): ?><div class="empty-state" style="padding: 2.5rem 1rem;"><h3>Keine Rechnungen</h3><p>Sobald eine Rechnung für dich ausgestellt wird, findest du sie hier.</p></div>
    <?php else: ?><div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Nummer</th><th>Datum</th><th>Betrag</th><th>Offen</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($liste as $r): $st = RECHNUNG_STATUS[rechnungAnzeigeStatus($r)] ?? ['label' => $r['status'], 'class' => 'badge-gray'];
            $of = $r['status'] === 'offen' ? bcsub(moneyRound($r['betrag_brutto']), moneyRound($r['bezahlt']), 2) : '0.00'; ?>
            <tr><td class="text-primary"><?= e($r['nummer']) ?></td><td><?= date('d.m.Y', strtotime($r['rechnungsdatum'])) ?></td><td><?= moneyFormat($r['betrag_brutto']) ?></td>
                <td><?= bccomp($of, '0', 2) > 0 ? moneyFormat($of) . '<div style="font-size: 0.75rem; color: var(--text-muted);">bis ' . date('d.m.Y', strtotime($r['faellig_am'])) . '</div>' : '–' ?></td>
                <td><span class="badge <?= $st['class'] ?>"><?= e($st['label']) ?></span></td>
                <td><a class="btn btn-ghost-light btn-sm" href="?pdf=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">PDF</a></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
