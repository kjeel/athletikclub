<?php
/**
 * Athletikclub Steiermark – Globale Suche (Seite und JSON für die Schnellsuche im Kopf)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/suche.php';

requireLogin();

$db = getDB();
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$ergebnis = globaleSuche($db, $q);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode(['q' => $q, 'gruppen' => array_values(array_map(fn($g) => ['label' => $g['label'], 'treffer' => array_slice($g['treffer'], 0, 4)], $ergebnis))],
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

$anzahl = array_sum(array_map(fn($g) => count($g['treffer']), $ergebnis));
$page_title = $q !== '' ? 'Suche: ' . $q : 'Suche';
$breadcrumb = 'Suche';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.su-form { display: flex; gap: 0.5rem; max-width: 640px; margin-bottom: 1.5rem; }
.su-form input { flex: 1; }
.su-sprung { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1.25rem; }
.su-sprung a { font-size: 0.8rem; padding: 0.25rem 0.7rem; border-radius: 999px; background: var(--bg-muted); color: var(--text-secondary); text-decoration: none; }
.su-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 1.25rem; align-items: start; }
.su-liste { list-style: none; margin: 0; padding: 0.25rem 0; }
.su-liste a { display: block; padding: 0.6rem 1.25rem; text-decoration: none; color: inherit; border-bottom: 1px solid var(--border-light); }
.su-liste li:last-child a { border-bottom: 0; }
.su-liste a:hover, .su-liste a:focus { background: var(--bg-muted); }
.su-titel { font-weight: 600; color: var(--navy, #1F3556); }
.su-info { display: block; font-size: 0.8rem; color: var(--text-muted); margin-top: 0.1rem; }
.su-mehr { display: block; padding: 0.6rem 1.25rem; font-size: 0.82rem; }
mark { background: rgba(198, 161, 53, 0.25); color: inherit; padding: 0 1px; border-radius: 2px; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Suche</h1>
    <p class="dashboard-subtitle">Personen, Kurse, Events, Projekte, Aufgaben, Partner, Rechnungen, Förderungen, Dokumente – je nach Berechtigung.</p>
</div>

<form method="get" class="su-form" role="search">
    <label for="su-q" class="sr-only">Suchbegriff</label>
    <input type="search" id="su-q" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Suchbegriff (mind. <?= SUCHE_MIN ?> Zeichen)" minlength="<?= SUCHE_MIN ?>" autofocus>
    <button class="btn btn-primary">Suchen</button>
</form>

<?php if ($q !== '' && mb_strlen($q) < SUCHE_MIN): ?>
    <div class="alert alert-info">Bitte mindestens <?= SUCHE_MIN ?> Zeichen eingeben.</div>
<?php elseif ($q !== '' && !$ergebnis): ?>
    <div class="empty-state"><p>Keine Treffer für „<?= e($q) ?>“.</p></div>
<?php elseif ($ergebnis): ?>
    <p class="text-muted" style="font-size: 0.85rem;"><?= $anzahl ?> Treffer in <?= count($ergebnis) ?> Kategorien</p>
    <?php if (count($ergebnis) > 2): ?>
    <nav class="su-sprung" aria-label="Kategorien"><?php foreach ($ergebnis as $kat => $g): ?><a href="#su-<?= $kat ?>"><?= e($g['label']) ?> (<?= count($g['treffer']) ?><?= $g['mehr'] ? '+' : '' ?>)</a><?php endforeach; ?></nav>
    <?php endif; ?>
    <?php
    // Treffer hervorheben: erst am Rohtext teilen, dann jeden Teil einzeln escapen
    $markiere = function (string $s) use ($q): string {
        $teile = preg_split('/(' . preg_quote($q, '/') . ')/iu', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
        $out = '';
        foreach ($teile as $i => $t) $out .= $i % 2 ? '<mark>' . e($t) . '</mark>' : e($t);
        return $out;
    };
    ?>
    <div class="su-grid">
    <?php foreach ($ergebnis as $kat => $g): ?>
        <div class="table-card" id="su-<?= $kat ?>">
            <div class="table-card-header"><h2 class="table-card-title"><?= e($g['label']) ?></h2></div>
            <ul class="su-liste">
                <?php foreach ($g['treffer'] as $t): ?>
                    <li><a href="<?= e($t['link']) ?>"><span class="su-titel"><?= $markiere($t['titel']) ?></span><?php if ($t['info'] !== ''): ?><span class="su-info"><?= $markiere($t['info']) ?></span><?php endif; ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($g['mehr']): ?><a class="su-mehr" href="<?= e($g['mehr']) ?>">Weitere Treffer anzeigen →</a><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
