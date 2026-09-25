<?php
/**
 * Athletikclub Steiermark – Rechnung online ansehen (/rechnung/{token})
 * Zugang über den 128-Bit-Token aus der E-Mail; nur ausgestellte Rechnungen.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/rechnungen.php';

$db = getDB();
$r = null;
$t = (string)($_GET['t'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $t)) {
    $stmt = $db->prepare("SELECT * FROM rechnungen WHERE zugriff_token = ? AND organization_id = ? AND status <> 'entwurf'");
    $stmt->execute([$t, currentOrgId()]);
    $r = $stmt->fetch() ?: null;
}
if ($r && !empty($_GET['pdf'])) {
    require_once ROOT_PATH . '/includes/rechnung-pdf.php';
    $bytes = rechnungPdfErzeugen($db, $r);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rechnungPdfDateiname($r) . '"');
    header('X-Robots-Tag: noindex');
    echo $bytes;
    exit;
}
$page_title = 'Rechnung';
$noindex = true;
require_once ROOT_PATH . '/includes/header.php';
if (!$r): ?>
<section class="section bg-white"><div class="container container--narrow text-center">
    <h1 style="font-family: 'Montserrat', sans-serif; font-weight: 800;">Rechnung nicht gefunden</h1>
    <p>Der Link ist ungültig. Bitte verwende den Link aus deiner E-Mail oder kontaktiere uns.</p>
</div></section>
<?php require_once ROOT_PATH . '/includes/footer.php'; exit; endif;
$v = verein();
$offen = $r['status'] === 'offen' ? rechnungOffenBetrag($db, $r) : '0.00';
$st = RECHNUNG_STATUS[rechnungAnzeigeStatus($r)] ?? ['label' => $r['status'], 'class' => 'badge-gray'];
?>
<section class="page-header"><div class="page-header-pattern"></div><div class="container page-header-content">
    <h1><?= $r['typ'] === 'storno' ? 'Stornorechnung' : 'Rechnung' ?> <?= e($r['nummer']) ?></h1>
    <p><?= e($v['vereinsname']) ?></p>
</div></section>
<section class="section bg-white"><div class="container">
    <div class="card" style="max-width: 640px; margin: 0 auto;"><div class="card-body">
        <p><span class="badge <?= $st['class'] ?>"><?= e($st['label']) ?></span></p>
        <table style="width: 100%; font-size: 0.95rem; border-collapse: collapse;">
            <tr><td style="padding: 0.4rem 0; color: var(--text-muted);">Empfänger</td><td><?= e($r['empf_name']) ?></td></tr>
            <tr><td style="padding: 0.4rem 0; color: var(--text-muted);">Rechnungsdatum</td><td><?= date('d.m.Y', strtotime($r['rechnungsdatum'])) ?></td></tr>
            <tr><td style="padding: 0.4rem 0; color: var(--text-muted);">Betrag</td><td><strong><?= moneyFormat($r['betrag_brutto']) ?></strong></td></tr>
            <?php if ($r['status'] === 'offen'): ?>
            <tr><td style="padding: 0.4rem 0; color: var(--text-muted);">Offen</td><td><strong><?= moneyFormat($offen) ?></strong> · zahlbar bis <?= date('d.m.Y', strtotime($r['faellig_am'])) ?></td></tr>
            <?php endif; ?>
        </table>
        <?php if ($r['status'] === 'offen' && $v['iban']): ?>
        <div style="background: var(--bg-muted); border-radius: 0.75rem; padding: 1rem; margin-top: 1rem; font-size: 0.9rem;">
            <strong>Überweisung</strong><br>Empfänger: <?= e($v['vereinsname']) ?><br>IBAN: <strong><?= e(ibanFormat($v['iban'])) ?></strong><?= $v['bic'] ? ' · BIC: ' . e($v['bic']) : '' ?><br>Verwendungszweck: <strong><?= e($r['nummer']) ?></strong>
        </div>
        <?php elseif ($r['status'] === 'bezahlt'): ?><p style="color: #15803D; margin-top: 1rem;">Vielen Dank – diese Rechnung ist bezahlt.</p><?php endif; ?>
        <a class="btn btn-navy" style="margin-top: 1.25rem;" href="?t=<?= e($t) ?>&pdf=1" target="_blank" rel="noopener">Rechnung als PDF</a>
    </div></div>
</div></section>
<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
