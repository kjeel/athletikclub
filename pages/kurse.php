<?php
/**
 * Athletikclub Steiermark – öffentliches Kurs- und Eventportal (/kurse)
 * Zeigt nur freigegebene (kurse.oeffentlich = 1), laufende Angebote. Keine internen Daten.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/buchung.php';

$db = getDB();
$art = in_array($_GET['art'] ?? '', ['kurs', 'event'], true) ? $_GET['art'] : '';
$f = [
    'sportart' => mb_substr(trim($_GET['sportart'] ?? ''), 0, 60),
    'ort'      => mb_substr(trim($_GET['ort'] ?? ''), 0, 100),
    'alter'    => ctype_digit($_GET['alter'] ?? '') ? (int)$_GET['alter'] : null,
    'ab'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ab'] ?? '') ? $_GET['ab'] : '',
    'frei'     => !empty($_GET['frei']),
];
$seite = max(1, (int)($_GET['seite'] ?? 1));
$pro_seite = 12;

$where = "k.organization_id = ? AND k.oeffentlich = 1 AND k.status IN ('geplant','aktiv') AND k.end_datum >= ?";
$p = [currentOrgId(), date('Y-m-d H:i:s')];
if ($art) { $where .= ' AND k.art = ?'; $p[] = $art; }
if ($f['sportart'] !== '') { $where .= ' AND k.sportart = ?'; $p[] = $f['sportart']; }
if ($f['ort'] !== '') { $where .= ' AND k.ort = ?'; $p[] = $f['ort']; }
if ($f['alter'] !== null) { $where .= ' AND (k.min_alter IS NULL OR k.min_alter <= ?) AND (k.max_alter IS NULL OR k.max_alter >= ?)'; array_push($p, $f['alter'], $f['alter']); }
if ($f['ab']) { $where .= ' AND k.end_datum >= ?'; $p[] = $f['ab'] . ' 00:00:00'; }
$belegt_sql = "(SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status IN ('angemeldet','angefragt'))";
if ($f['frei']) $where .= " AND (k.max_teilnehmer IS NULL OR k.max_teilnehmer > $belegt_sql)";

$angebote = [];
$gesamt = 0;
$sportarten = $orte = [];
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM kurse k WHERE $where");
    $stmt->execute($p);
    $gesamt = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT k.id, k.titel, k.art, k.sportart, k.ort, k.kurzbeschreibung, k.beschreibung, k.bild, k.start_datum, k.end_datum, k.max_teilnehmer,
                                 k.preis, k.min_alter, k.max_alter, k.anmeldeschluss, u.vorname AS trainer_vorname, u.nachname AS trainer_nachname, $belegt_sql AS belegt
                          FROM kurse k LEFT JOIN users u ON u.id = k.trainer_id WHERE $where ORDER BY k.start_datum LIMIT $pro_seite OFFSET " . (($seite - 1) * $pro_seite));
    $stmt->execute($p);
    $angebote = $stmt->fetchAll();
    $basis = "FROM kurse WHERE organization_id = ? AND oeffentlich = 1 AND status IN ('geplant','aktiv') AND end_datum >= ?";
    $stmt = $db->prepare("SELECT DISTINCT sportart $basis AND sportart IS NOT NULL ORDER BY sportart");
    $stmt->execute([currentOrgId(), date('Y-m-d H:i:s')]);
    $sportarten = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $db->prepare("SELECT DISTINCT ort $basis AND ort IS NOT NULL AND ort <> '' ORDER BY ort");
    $stmt->execute([currentOrgId(), date('Y-m-d H:i:s')]);
    $orte = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $angebote = [];
}
$seiten = max(1, (int)ceil($gesamt / $pro_seite));
$qs = fn(array $mehr) => '?' . http_build_query(array_filter(array_merge(['art' => $art] + $f, $mehr), fn($v) => $v !== '' && $v !== null && $v !== false));

$page_title       = $art === 'event' ? 'Events' : 'Kurse & Events';
$meta_description = 'Kurse, Trainings und Events des Athletikclub Steiermark – freie Plätze sehen und direkt online anmelden.';
require_once ROOT_PATH . '/includes/header.php';
$alter_text = function (array $k): string {
    if ($k['min_alter'] !== null && $k['max_alter'] !== null) return (int)$k['min_alter'] . '–' . (int)$k['max_alter'] . ' Jahre';
    if ($k['min_alter'] !== null) return 'ab ' . (int)$k['min_alter'] . ' Jahren';
    if ($k['max_alter'] !== null) return 'bis ' . (int)$k['max_alter'] . ' Jahre';
    return 'alle Altersgruppen';
};
?>

<style>
.kp-filter { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; background: var(--surface, #fff); border: 1px solid var(--border-light); border-radius: 1rem; padding: 1rem 1.25rem; margin-bottom: 2rem; }
.kp-filter .form-group { margin: 0; min-width: 150px; flex: 1; }
.kp-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.kp-karte { display: flex; flex-direction: column; height: 100%; overflow: hidden; }
.kp-bild { aspect-ratio: 16 / 9; background: linear-gradient(135deg, var(--navy-primary, #1F3556), #2c4a73); display: flex; align-items: center; justify-content: center; color: var(--gold-accent, #C6A135); font-family: 'Montserrat', sans-serif; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; font-size: 0.85rem; }
.kp-bild img { width: 100%; height: 100%; object-fit: cover; display: block; }
.kp-body { padding: 1.25rem 1.35rem 1.4rem; display: flex; flex-direction: column; gap: 0.6rem; flex: 1; }
.kp-titel { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.1rem; margin: 0; line-height: 1.3; }
.kp-titel a { color: inherit; }
.kp-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem 0.75rem; font-size: 0.83rem; color: var(--text-secondary); }
.kp-meta span::before { content: attr(data-l); display: block; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); }
.kp-fuss { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-top: auto; padding-top: 0.75rem; border-top: 1px solid var(--border-light); }
.kp-preis { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.15rem; }
.kp-plaetze { font-size: 0.8rem; font-weight: 600; }
.kp-plaetze.voll { color: #B45309; }
.kp-plaetze.frei { color: #15803D; }
.kp-leer { text-align: center; padding: 3rem 1rem; color: var(--text-secondary); }
.kp-seiten { display: flex; justify-content: center; gap: 0.5rem; margin-top: 2rem; align-items: center; }
</style>

<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a><span class="breadcrumb-sep">›</span><span class="breadcrumb-current"><?= $art === 'event' ? 'Events' : 'Kurse & Events' ?></span>
        </nav>
        <h1><?= $art === 'event' ? 'Events & Veranstaltungen' : 'Kurse & Events' ?></h1>
        <p>Finde dein Training, sieh freie Plätze und melde dich direkt online an – für dich oder dein Kind.</p>
    </div>
</section>

<section class="section bg-white">
    <div class="container">
        <div class="kp-tabs">
            <a href="<?= e($qs(['art' => '', 'seite' => null])) ?>" class="btn <?= $art === '' ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm">Alle</a>
            <a href="<?= e($qs(['art' => 'kurs', 'seite' => null])) ?>" class="btn <?= $art === 'kurs' ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm">Kurse</a>
            <a href="<?= e($qs(['art' => 'event', 'seite' => null])) ?>" class="btn <?= $art === 'event' ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm">Events</a>
        </div>

        <form method="GET" class="kp-filter" role="search" aria-label="Angebote filtern">
            <?php if ($art): ?><input type="hidden" name="art" value="<?= e($art) ?>"><?php endif; ?>
            <div class="form-group"><label class="form-label" for="f-sport">Sportart</label>
                <select class="form-control" id="f-sport" name="sportart"><option value="">Alle</option><?php foreach ($sportarten as $s): ?><option value="<?= e($s) ?>" <?= $f['sportart'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label" for="f-ort">Standort</label>
                <select class="form-control" id="f-ort" name="ort"><option value="">Alle</option><?php foreach ($orte as $o): ?><option value="<?= e($o) ?>" <?= $f['ort'] === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="max-width: 130px;"><label class="form-label" for="f-alter">Alter</label>
                <input class="form-control" id="f-alter" type="number" min="1" max="99" name="alter" value="<?= $f['alter'] ?? '' ?>" placeholder="z.B. 8"></div>
            <div class="form-group"><label class="form-label" for="f-ab">Ab Datum</label>
                <input class="form-control" id="f-ab" type="date" name="ab" value="<?= e($f['ab']) ?>"></div>
            <label class="form-check" style="margin-bottom: 0.6rem;"><input type="checkbox" name="frei" value="1" <?= $f['frei'] ? 'checked' : '' ?>><span class="form-check-label">nur freie Plätze</span></label>
            <button class="btn btn-navy btn-sm" type="submit">Filtern</button>
        </form>

        <?php if (!$angebote): ?>
            <div class="card kp-leer"><div class="card-body">
                <h2 style="font-family: 'Montserrat', sans-serif; font-size: 1.2rem; font-weight: 800;">Keine passenden Angebote</h2>
                <p>Ändere die Filter oder schau bald wieder vorbei. Fragen beantworten wir gerne persönlich.</p>
                <a href="<?= APP_URL ?>/pages/kontakt.php" class="btn btn-navy btn-sm">Kontakt</a>
            </div></div>
        <?php else: ?>
        <div class="grid-3">
            <?php foreach ($angebote as $k):
                $frei = $k['max_teilnehmer'] ? max(0, (int)$k['max_teilnehmer'] - (int)$k['belegt']) : null;
                $url = kursOeffentlichLink((int)$k['id']); ?>
            <article class="card kp-karte reveal">
                <a class="kp-bild" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
                    <?php if ($k['bild']): ?><img src="<?= APP_URL ?>/api/bild.php?k=<?= (int)$k['id'] ?>" alt="" loading="lazy"><?php else: ?><?= e($k['sportart'] ?: ($k['art'] === 'event' ? 'Event' : 'Training')) ?><?php endif; ?>
                </a>
                <div class="kp-body">
                    <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                        <?php if ($k['art'] === 'event'): ?><span class="badge badge-navy">Event</span><?php endif; ?>
                        <?php if ($k['sportart']): ?><span class="badge badge-gold"><?= e($k['sportart']) ?></span><?php endif; ?>
                    </div>
                    <h2 class="kp-titel"><a href="<?= e($url) ?>"><?= e($k['titel']) ?></a></h2>
                    <?php $kurz = $k['kurzbeschreibung'] ?: mb_strimwidth(strip_tags((string)$k['beschreibung']), 0, 140, '…'); ?>
                    <?php if ($kurz): ?><p style="margin: 0; font-size: 0.9rem; color: var(--text-secondary);"><?= e($kurz) ?></p><?php endif; ?>
                    <div class="kp-meta">
                        <span data-l="Beginn"><?= date('d.m.Y, H:i', strtotime($k['start_datum'])) ?></span>
                        <span data-l="Ort"><?= e($k['ort'] ?: '–') ?></span>
                        <span data-l="Alter"><?= e($alter_text($k)) ?></span>
                        <span data-l="Trainer:in"><?= $k['trainer_vorname'] ? e($k['trainer_vorname'] . ' ' . mb_substr($k['trainer_nachname'], 0, 1) . '.') : '–' ?></span>
                    </div>
                    <div class="kp-fuss">
                        <span class="kp-preis"><?= (float)$k['preis'] > 0 ? moneyFormat($k['preis']) : 'kostenlos' ?></span>
                        <?php if ($frei === null): ?><span class="kp-plaetze frei">Plätze frei</span>
                        <?php elseif ($frei > 0): ?><span class="kp-plaetze frei"><?= $frei ?> von <?= (int)$k['max_teilnehmer'] ?> frei</span>
                        <?php else: ?><span class="kp-plaetze voll">ausgebucht · Warteliste</span><?php endif; ?>
                    </div>
                    <a href="<?= e($url) ?>#anmelden" class="btn <?= $frei === 0 ? 'btn-ghost-light' : 'btn-primary' ?> btn-sm w-full"><?= $frei === 0 ? 'Auf Warteliste setzen' : 'Jetzt anmelden' ?></a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php if ($seiten > 1): ?>
        <nav class="kp-seiten" aria-label="Seiten">
            <?php if ($seite > 1): ?><a class="btn btn-ghost-light btn-sm" href="<?= e($qs(['seite' => $seite - 1])) ?>">‹ Zurück</a><?php endif; ?>
            <span class="text-muted">Seite <?= $seite ?> von <?= $seiten ?></span>
            <?php if ($seite < $seiten): ?><a class="btn btn-ghost-light btn-sm" href="<?= e($qs(['seite' => $seite + 1])) ?>">Weiter ›</a><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
