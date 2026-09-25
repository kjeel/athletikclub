<?php
/**
 * Athletikclub Steiermark – Dokumente & Exporte
 * Berichte als PDF (Corporate Design), CSV oder Excel (XLSX). Jede Ausgabe prüft die
 * Berechtigung für den konkreten Datensatz; Exporte werden im Aktivitätsprotokoll vermerkt.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/berichte.php';

requireLogin();

$db = getDB();
$verfuegbar = array_filter(BERICHT_TYPEN, fn($t, $code) => berichtVerfuegbar($code), ARRAY_FILTER_USE_BOTH);
if (!$verfuegbar && !darf('rechnungen.anzeigen')) {
    flashMessage('error', 'Für Berichte fehlt die Berechtigung.');
    redirect(APP_URL . '/dashboard/index.php');
}

$typ = (string)($_GET['typ'] ?? '');
$format = in_array($_GET['format'] ?? '', ['pdf', 'csv', 'xlsx'], true) ? $_GET['format'] : null;
$fehler = null;
if ($format && isset(BERICHT_TYPEN[$typ])) {
    $bericht = berichtErzeugen($db, $typ, $_GET, $format);
    if (isset($bericht['fehler'])) {
        $fehler = $bericht['fehler'];
    } else {
        logActivity('export', BERICHT_TYPEN[$typ]['label'] . ' (' . strtoupper($format) . ')' . (!empty($_GET['id']) ? ' #' . (int)$_GET['id'] : ''));
        exportAusliefern($bericht, $format);
    }
}

$auswahl = [];
foreach ($verfuegbar as $t) if (!in_array($t['param'], ['zeitraum', 'keiner'], true) && !isset($auswahl[$t['param']])) $auswahl[$t['param']] = berichtAuswahl($db, $t['param']);
$param_label = ['kurs' => 'Kurs', 'event' => 'Event', 'projekt' => 'Projekt', 'abrechnung' => 'Abrechnung'];

$page_title = 'Dokumente & Exporte';
$breadcrumb = 'Dokumente & Exporte';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.be-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 1.25rem; }
.be-karte { background: var(--bg-card, #fff); border: 1px solid var(--border-light); border-radius: 12px; padding: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem; }
.be-karte h2 { font-size: 1.05rem; margin: 0; color: var(--navy, #1F3556); }
.be-karte p { margin: 0; font-size: 0.85rem; color: var(--text-secondary); }
.be-karte form { display: flex; flex-direction: column; gap: 0.6rem; margin-top: auto; }
.be-karte .form-group { margin: 0; }
.be-zeitraum { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.be-formate { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.be-formate .btn { flex: 1; }
.be-schloss { font-size: 0.75rem; color: var(--text-muted); }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Dokumente &amp; Exporte</h1>
    <p class="dashboard-subtitle">Berichte als PDF im Vereinsdesign oder als Tabelle (CSV, Excel). Es erscheinen nur Daten, für die Sie berechtigt sind.</p>
</div>

<?php if ($fehler): ?><div class="alert alert-error"><?= e($fehler) ?></div><?php endif; ?>

<div class="be-grid">
<?php foreach ($verfuegbar as $code => $t): $liste = $auswahl[$t['param']] ?? null; ?>
    <div class="be-karte" id="bericht-<?= $code ?>">
        <h2><?= e($t['label']) ?></h2>
        <p><?= e($t['text']) ?></p>
        <?php if ($liste !== null && !$liste): ?>
            <p class="be-schloss">Keine <?= e($param_label[$t['param']]) ?>-Datensätze verfügbar.</p>
        <?php else: ?>
        <form method="get" target="_blank">
            <input type="hidden" name="typ" value="<?= $code ?>">
            <?php if ($liste !== null): ?>
                <div class="form-group">
                    <label class="form-label" for="id-<?= $code ?>"><?= e($param_label[$t['param']]) ?></label>
                    <select class="form-control" name="id" id="id-<?= $code ?>" required>
                        <?php foreach ($liste as $id => $name): ?><option value="<?= (int)$id ?>" <?= $typ === $code && (int)($_GET['id'] ?? 0) === (int)$id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($t['param'] === 'zeitraum'): ?>
                <div class="be-zeitraum">
                    <div class="form-group"><label class="form-label" for="von-<?= $code ?>">Von</label><input class="form-control" type="date" name="von" id="von-<?= $code ?>" value="<?= date('Y-01-01') ?>"></div>
                    <div class="form-group"><label class="form-label" for="bis-<?= $code ?>">Bis</label><input class="form-control" type="date" name="bis" id="bis-<?= $code ?>" value="<?= date('Y-12-31') ?>"></div>
                </div>
            <?php endif; ?>
            <div class="be-formate">
                <button class="btn btn-primary btn-sm" name="format" value="pdf">PDF</button>
                <button class="btn btn-ghost-light btn-sm" name="format" value="xlsx">Excel</button>
                <button class="btn btn-ghost-light btn-sm" name="format" value="csv">CSV</button>
            </div>
            <?php if ($t['personen'] && !darf('export.personen')): ?><span class="be-schloss">Excel/CSV nur für eigene Kurse (Personendaten).</span><?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (darf('rechnungen.anzeigen')): ?>
    <div class="be-karte">
        <h2>Rechnungen</h2>
        <p>Rechnungs-PDFs werden im Rechnungsmodul erzeugt – dort auch Zahlungen, Mahnungen und Stornorechnungen.</p>
        <a class="btn btn-ghost-light btn-sm" style="margin-top: auto;" href="<?= APP_URL ?>/dashboard/admin/rechnungen.php">Zu den Rechnungen</a>
    </div>
<?php endif; ?>
<?php if (darf('foerderungen.anzeigen')): ?>
    <div class="be-karte">
        <h2>Verwendungsnachweis</h2>
        <p>Nachweis je Förderung mit Belegliste – erreichbar in der jeweiligen Förderung.</p>
        <a class="btn btn-ghost-light btn-sm" style="margin-top: auto;" href="<?= APP_URL ?>/dashboard/admin/foerderungen.php">Zu den Förderungen</a>
    </div>
<?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
