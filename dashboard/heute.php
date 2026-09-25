<?php
/**
 * Athletikclub Steiermark – „Heute“: mobile Trainer-Ansicht
 * Heutige und nächste Einheit, Weg dorthin, Teilnehmerliste mit Notfallhinweisen, Check-in,
 * Anwesenheit, Bestätigung, offene Aufgaben und der laufende Abrechnungsmonat – alles mit
 * großen Schaltflächen für die Nutzung am Handy in der Halle.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireTrainer();

$db  = getDB();
$org = currentOrgId();
$me  = (int)getCurrentUserId();
$jetzt = date('Y-m-d H:i:s');
$heute = date('Y-m-d');

// Einheiten, bei denen ich eingeteilt bin oder die zu meinen Kursen gehören
$sql = "SELECT e.*, et.status AS mein_status, et.id AS et_id, k.titel AS kurs_titel, k.art AS kurs_art, p.name AS projekt_name
        FROM einheiten e
        LEFT JOIN einheit_trainer et ON et.einheit_id = e.id AND et.user_id = ?
        LEFT JOIN kurse k ON k.id = e.kurs_id
        LEFT JOIN projekte p ON p.id = e.projekt_id
        WHERE e.organization_id = ? AND e.status <> 'storniert' AND (et.id IS NOT NULL OR k.trainer_id = ?)";
$stmt = $db->prepare($sql . ' AND e.start BETWEEN ? AND ? ORDER BY e.start');
$stmt->execute([$me, $org, $me, "$heute 00:00:00", "$heute 23:59:59"]);
$einheiten = $stmt->fetchAll();

$stmt = $db->prepare($sql . ' AND e.start > ? ORDER BY e.start LIMIT 1');
$stmt->execute([$me, $org, $me, "$heute 23:59:59"]);
$naechste_spaeter = $stmt->fetch() ?: null;

// Hervorgehobene Einheit: läuft gerade oder beginnt als nächstes (heute)
$fokus = null;
foreach ($einheiten as $e) if ($e['ende'] >= $jetzt) { $fokus = $e; break; }

$stmt = $db->prepare("SELECT COUNT(*) FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id WHERE et.user_id = ? AND et.status = 'geplant' AND e.status <> 'storniert' AND e.ende < ?");
$stmt->execute([$me, $jetzt]);
$offen_bestaetigen = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT a.id, a.titel, a.deadline, a.prioritaet, p.name AS projekt_name FROM aufgaben a LEFT JOIN projekte p ON p.id = a.projekt_id
                      WHERE a.verantwortlich_id = ? AND a.status <> 'erledigt' ORDER BY a.deadline IS NULL, a.deadline, a.id LIMIT 6");
$stmt->execute([$me]);
$aufgaben = $stmt->fetchAll();

// Abrechnungsmonat
$monat = trainerEinheitenMonat($db, $me, (int)date('Y'), (int)date('n'));
$m_min = array_sum(array_map(fn($x) => (int)$x['dauer_min'], $monat));
$m_betrag = '0.00';
foreach ($monat as $x) $m_betrag = bcadd($m_betrag, moneyRound($x['betrag'] ?? 0), 2);
$stmt = $db->prepare('SELECT id, status, betrag FROM trainer_abrechnungen WHERE user_id = ? AND jahr = ? AND monat = ?');
$stmt->execute([$me, (int)date('Y'), (int)date('n')]);
$abrechnung = $stmt->fetch() ?: null;

$teilnehmer = [];
foreach ($einheiten as $e) if ($e['kurs_id']) $teilnehmer[$e['id']] = einheitTeilnehmer($db, $e);

$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
$route = fn(?string $ort) => $ort ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($ort) : null;
$titel = fn(array $e) => $e['titel'] ?: ($e['kurs_titel'] ?: ($e['projekt_name'] ?: (EINHEIT_TYPEN[$e['typ']]['label'] ?? 'Einheit')));

$page_title = 'Heute';
$breadcrumb = 'Heute';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.ht-wrap { max-width: 760px; }
.ht-fokus { background: linear-gradient(135deg, var(--navy-primary, #1F3556), #2a4670); color: #fff; border-radius: 16px; padding: 1.25rem; margin-bottom: 1.25rem; }
.ht-fokus .ht-label { font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--gold-accent, #C6A135); font-weight: 700; }
.ht-fokus h2 { color: #fff; font-size: 1.35rem; margin: 0.35rem 0 0.25rem; }
.ht-fokus .ht-meta { color: rgba(255,255,255,0.8); font-size: 0.9rem; }
.ht-aktionen { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.5rem; margin-top: 1rem; }
.ht-btn { display: flex; align-items: center; justify-content: center; gap: 0.4rem; min-height: 48px; padding: 0.6rem 0.8rem; border-radius: 12px; font-weight: 600; font-size: 0.92rem; text-decoration: none; text-align: center; border: 1px solid transparent; }
.ht-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
.ht-btn-gold { background: var(--gold-accent, #C6A135); color: #0D1F35; }
.ht-btn-hell { background: rgba(255,255,255,0.12); color: #fff; border-color: rgba(255,255,255,0.2); }
.ht-btn-rand { background: var(--surface); color: var(--navy-primary); border-color: var(--border-light); }
.ht-abschnitt { font-size: 0.75rem; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); font-weight: 700; margin: 1.5rem 0 0.6rem; }
.ht-karte { background: var(--surface); border: 1px solid var(--border-light); border-radius: 14px; padding: 1rem; margin-bottom: 0.75rem; }
.ht-karte.vorbei { opacity: 0.75; }
.ht-zeit { font-family: var(--font-heading); font-weight: 800; font-size: 1.05rem; color: var(--navy-primary); }
.ht-kopf { display: flex; justify-content: space-between; gap: 0.75rem; align-items: flex-start; }
.ht-kopf h3 { margin: 0.1rem 0 0; font-size: 1rem; }
.ht-mini { font-size: 0.8rem; color: var(--text-muted); }
.ht-tn { margin-top: 0.75rem; border-top: 1px solid var(--border-light); padding-top: 0.6rem; }
.ht-tn summary { cursor: pointer; font-weight: 600; font-size: 0.9rem; min-height: 40px; display: flex; align-items: center; }
.ht-tn ul { list-style: none; margin: 0.25rem 0 0; padding: 0; }
.ht-tn li { padding: 0.5rem 0; border-bottom: 1px solid var(--border-light); font-size: 0.9rem; display: flex; justify-content: space-between; gap: 0.5rem; }
.ht-tn li:last-child { border-bottom: 0; }
.ht-hinweis { display: block; font-size: 0.78rem; color: #B45309; }
.ht-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem; }
.ht-kpi { background: var(--surface); border: 1px solid var(--border-light); border-radius: 12px; padding: 0.75rem; text-align: center; }
.ht-kpi strong { display: block; font-family: var(--font-heading); font-size: 1.15rem; color: var(--navy-primary); }
.ht-kpi span { font-size: 0.72rem; color: var(--text-muted); }
.ht-liste a { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 0; border-bottom: 1px solid var(--border-light); text-decoration: none; color: inherit; min-height: 44px; }
.ht-liste a:last-child { border-bottom: 0; }
.ht-warn { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.35); border-radius: 12px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.9rem; }
</style>

<div class="ht-wrap">
<div class="dashboard-header" style="margin-bottom: 1rem;">
    <h1 class="dashboard-title">Heute, <?= $wochentage[(int)date('w')] ?> <?= date('d.m.') ?></h1>
    <p class="dashboard-subtitle"><?= count($einheiten) ? count($einheiten) . ' Einheit' . (count($einheiten) > 1 ? 'en' : '') . ' heute' : 'Heute keine Einheiten' ?></p>
</div>

<?php if ($offen_bestaetigen): ?>
    <div class="ht-warn"><span><strong><?= $offen_bestaetigen ?></strong> vergangene Einheit<?= $offen_bestaetigen > 1 ? 'en' : '' ?> noch nicht bestätigt</span><a class="btn btn-navy btn-sm" href="<?= APP_URL ?>/dashboard/zeiterfassung.php">Bestätigen</a></div>
<?php endif; ?>

<?php
$f = $fokus ?? $naechste_spaeter;
if ($f):
    $laeuft = $f['start'] <= $jetzt && $f['ende'] >= $jetzt;
    $ist_heute = substr($f['start'], 0, 10) === $heute;
?>
<section class="ht-fokus" aria-label="Nächste Einheit">
    <div class="ht-label"><?= $laeuft ? 'Läuft gerade' : ($ist_heute ? 'Als Nächstes' : 'Nächste Einheit') ?></div>
    <h2><?= e($titel($f)) ?></h2>
    <div class="ht-meta">
        <?= $ist_heute ? '' : $wochentage[(int)date('w', strtotime($f['start']))] . ' ' . date('d.m.', strtotime($f['start'])) . ' · ' ?><?= date('H:i', strtotime($f['start'])) ?>–<?= date('H:i', strtotime($f['ende'])) ?> Uhr<?= $f['ort'] ? ' · ' . e($f['ort']) : '' ?>
        <?php if (!$laeuft && $ist_heute): $min = (int)round((strtotime($f['start']) - time()) / 60); ?> · in <?= $min >= 60 ? floor($min / 60) . ' h ' . ($min % 60) . ' min' : $min . ' min' ?><?php endif; ?>
    </div>
    <div class="ht-aktionen">
        <?php if ($r = $route($f['ort'])): ?><a class="ht-btn ht-btn-gold" href="<?= e($r) ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>Route</a><?php endif; ?>
        <?php if ($f['kurs_id'] && $ist_heute): ?><a class="ht-btn ht-btn-hell" href="<?= APP_URL ?>/dashboard/checkin.php?einheit=<?= (int)$f['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v7"/></svg>Check-in</a><?php endif; ?>
        <a class="ht-btn ht-btn-hell" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= (int)$f['id'] ?>#anwesenheit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Anwesenheit</a>
        <a class="ht-btn ht-btn-hell" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= (int)$f['id'] ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>Details</a>
    </div>
    <?php if ($f['notiz']): ?><p style="margin: 0.9rem 0 0; font-size: 0.85rem; color: rgba(255,255,255,0.85);"><strong>Notiz:</strong> <?= e($f['notiz']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($einheiten): ?>
<h2 class="ht-abschnitt">Heutige Einheiten</h2>
<?php foreach ($einheiten as $e): $vorbei = $e['ende'] < $jetzt; $tn = $teilnehmer[$e['id']] ?? []; ?>
<article class="ht-karte<?= $vorbei ? ' vorbei' : '' ?>">
    <div class="ht-kopf">
        <div>
            <div class="ht-zeit"><?= date('H:i', strtotime($e['start'])) ?>–<?= date('H:i', strtotime($e['ende'])) ?></div>
            <h3><?= e($titel($e)) ?></h3>
            <div class="ht-mini"><?= e(EINHEIT_TYPEN[$e['typ']]['label'] ?? '') ?><?= $e['ort'] ? ' · ' . e($e['ort']) : '' ?><?= $e['erwartete_teilnehmer'] ? ' · ' . (int)$e['erwartete_teilnehmer'] . ' erwartet' : '' ?></div>
        </div>
        <?php if ($e['mein_status']): ?><span class="badge <?= ET_STATUS[$e['mein_status']]['class'] ?? 'badge-gray' ?>"><?= ET_STATUS[$e['mein_status']]['label'] ?? e($e['mein_status']) ?></span><?php endif; ?>
    </div>
    <div class="ht-aktionen">
        <?php if ($r = $route($e['ort'])): ?><a class="ht-btn ht-btn-rand" href="<?= e($r) ?>" target="_blank" rel="noopener">Route</a><?php endif; ?>
        <?php if ($e['kurs_id'] && !$vorbei): ?><a class="ht-btn ht-btn-rand" href="<?= APP_URL ?>/dashboard/checkin.php?einheit=<?= (int)$e['id'] ?>">Check-in</a><?php endif; ?>
        <a class="ht-btn ht-btn-rand" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= (int)$e['id'] ?>#anwesenheit">Anwesenheit</a>
        <?php if ($e['mein_status'] === 'geplant' && $e['start'] <= $jetzt): ?><a class="ht-btn ht-btn-gold" href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= (int)$e['id'] ?>#bestaetigen">Bestätigen</a><?php endif; ?>
    </div>
    <?php if ($e['notiz']): ?><p class="ht-mini" style="margin: 0.6rem 0 0;"><strong>Notiz:</strong> <?= e($e['notiz']) ?></p><?php endif; ?>
    <?php if ($tn): $da = count(array_filter($tn, fn($t) => in_array($t['status'] ?? '', ['anwesend', 'probetraining'], true))); ?>
    <details class="ht-tn">
        <summary>Teilnehmer:innen (<?= count($tn) ?><?= $da ? ', ' . $da . ' anwesend' : '' ?>)</summary>
        <ul>
            <?php foreach ($tn as $t): ?>
            <li>
                <span><?= e($t['name']) ?><?php if (!empty($t['hinweise'])): ?><span class="ht-hinweis">⚠ <?= e($t['hinweise']) ?></span><?php endif; ?><?php if (!empty($t['notfall'])): ?><span class="ht-mini">Notfall: <?= e($t['notfall']) ?></span><?php endif; ?></span>
                <?php if (!empty($t['status'])): ?><span class="badge <?= ANWESENHEIT_STATUS[$t['status']]['class'] ?>"><?= ANWESENHEIT_STATUS[$t['status']]['label'] ?></span><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</article>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($fokus && $naechste_spaeter): ?>
    <p class="ht-mini">Danach: <?= e($titel($naechste_spaeter)) ?>, <?= $wochentage[(int)date('w', strtotime($naechste_spaeter['start']))] ?> <?= date('d.m., H:i', strtotime($naechste_spaeter['start'])) ?> Uhr</p>
<?php endif; ?>

<h2 class="ht-abschnitt">Abrechnung <?= date('m/Y') ?></h2>
<div class="ht-kpis">
    <div class="ht-kpi"><strong><?= count($monat) ?></strong><span>Einsätze</span></div>
    <div class="ht-kpi"><strong><?= number_format($m_min / 60, 1, ',', '.') ?> h</strong><span>Stunden</span></div>
    <div class="ht-kpi"><strong><?= moneyFormat($m_betrag) ?></strong><span><?= $abrechnung ? e(TA_STATUS[$abrechnung['status']]['label'] ?? $abrechnung['status']) : 'noch offen' ?></span></div>
</div>
<p style="margin-top: 0.6rem;"><a href="<?= APP_URL ?>/dashboard/zeiterfassung.php" class="ht-mini">Zur Zeiterfassung und Monatsabrechnung →</a></p>

<h2 class="ht-abschnitt">Meine Aufgaben</h2>
<div class="ht-karte ht-liste" style="padding-top: 0.25rem; padding-bottom: 0.25rem;">
    <?php if (!$aufgaben): ?><p class="ht-mini" style="margin: 0.6rem 0;">Keine offenen Aufgaben.</p><?php endif; ?>
    <?php foreach ($aufgaben as $a): $ueber = $a['deadline'] && $a['deadline'] < $heute; ?>
        <a href="<?= APP_URL ?>/dashboard/aufgaben.php?id=<?= (int)$a['id'] ?>">
            <span><?= e($a['titel']) ?><?php if ($a['projekt_name']): ?><span class="ht-mini" style="display: block;"><?= e($a['projekt_name']) ?></span><?php endif; ?></span>
            <span class="ht-mini" style="<?= $ueber ? 'color: #B91C1C; font-weight: 600;' : '' ?> white-space: nowrap;"><?= $a['deadline'] ? date('d.m.', strtotime($a['deadline'])) : '' ?></span>
        </a>
    <?php endforeach; ?>
</div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
