<?php
/**
 * Athletikclub Steiermark – Einheit (Detail)
 * Informationen, Trainer:innen, Anwesenheit und Bestätigung „durchgeführt“
 * (Dauer, Teilnehmerzahl, Honorar → Trainer-Monatsabrechnung).
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/einheiten.php';

requireLogin();

$db = getDB();
$me = (int)getCurrentUserId();
$id = (int)($_GET['id'] ?? 0);
$einheit = einheitLaden($db, $id);
if (!$einheit || !einheitDarfSehen($db, $einheit)) {
    flashMessage('error', 'Einheit nicht gefunden.');
    redirect(APP_URL . '/dashboard/kalender.php');
}
$self = APP_URL . '/dashboard/einheit.php?id=' . $id;
$trainer = einheitTrainer($db, $id);
$mein = null;
foreach ($trainer as $t) if ((int)$t['user_id'] === $me) $mein = $t;
$bearbeiten = einheitDarfBearbeiten($db, $einheit) || (int)$einheit['erstellt_von'] === $me;
$darf_anwesenheit = isTrainer() && ($mein || $bearbeiten || darf('anwesenheit.bearbeiten'));
$darf_betraege = darf('abrechnung.anzeigen');
$begonnen = strtotime($einheit['start']) <= time();

/** Ist die Abrechnung, in der ein Einsatz steckt, noch änderbar? */
$abrechnung_offen = function (?array $et) use ($db): bool {
    if (!$et || !$et['abrechnung_id']) return true;
    $stmt = $db->prepare('SELECT status FROM trainer_abrechnungen WHERE id = ?');
    $stmt->execute([$et['abrechnung_id']]);
    return $stmt->fetchColumn() === 'entwurf';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'anwesenheit' && $darf_anwesenheit) {
        $teilnehmer = einheitTeilnehmer($db, $einheit);
        $n = anwesenheitSpeichern($db, $id, $teilnehmer, (array)($_POST['status'] ?? []), $_POST['gast'] ?? null, $_POST['gast_status'] ?? null);
        logActivity('anwesenheit_erfasst', "Einheit-ID: {$id}, {$n} Einträge");
        flashMessage('success', "Anwesenheit gespeichert ({$n} Einträge).");
        redirect($self . '#anwesenheit');
    }

    if ($action === 'bestaetigen') {
        // Eigene Einheit – oder mit Abrechnungsrecht auch für andere
        $uid = darf('abrechnung.bearbeiten') && !empty($_POST['user_id']) ? (int)$_POST['user_id'] : $me;
        $et = null;
        foreach ($trainer as $t) if ((int)$t['user_id'] === $uid) $et = $t;
        if (!$et || !$abrechnung_offen($et)) {
            flashMessage('error', 'Diese Einheit kann nicht (mehr) bestätigt werden.');
            redirect($self);
        }
        $tag = substr($einheit['start'], 0, 10);
        $von = preg_match('/^\d{2}:\d{2}$/', $_POST['ist_von'] ?? '') ? $_POST['ist_von'] : substr($einheit['start'], 11, 5);
        $bis = preg_match('/^\d{2}:\d{2}$/', $_POST['ist_bis'] ?? '') ? $_POST['ist_bis'] : substr($einheit['ende'], 11, 5);
        $tn = ($_POST['teilnehmer'] ?? '') !== '' ? max(0, min(999, (int)$_POST['teilnehmer'])) : null;
        $r = einheitBestaetigen($db, $einheit, $uid, "{$tag} {$von}:00", "{$tag} {$bis}:00", $tn, mb_substr(trim($_POST['notiz'] ?? ''), 0, 500) ?: null);
        if (isset($r['fehler'])) {
            flashMessage('error', $r['fehler']);
        } else {
            trainerAbrechnungAktualisieren($db, $uid, (int)substr($tag, 0, 4), (int)substr($tag, 5, 2));
            flashMessage('success', 'Einheit als durchgeführt bestätigt (' . dauerText($r['minuten']) . ($r['betrag'] !== null ? ', Honorar ' . moneyFormat($r['betrag']) : '') . ').'
                . ($r['ohne_satz'] ? ' Hinweis: Es ist noch kein Honorarsatz hinterlegt – die Vereinsleitung ergänzt ihn.' : ''));
        }
        redirect($self);
    }

    if ($action === 'zuruecknehmen' && $mein && $mein['status'] === 'durchgefuehrt' && $abrechnung_offen($mein)) {
        $db->prepare("UPDATE einheit_trainer SET status = 'geplant', ist_start = NULL, ist_ende = NULL, dauer_min = NULL, betrag = NULL, bestaetigt_am = NULL, abrechnung_id = NULL WHERE id = ?")->execute([$mein['id']]);
        $tag = substr($mein['ist_start'] ?? $einheit['start'], 0, 10);
        trainerAbrechnungAktualisieren($db, $me, (int)substr($tag, 0, 4), (int)substr($tag, 5, 2));
        auditLog('status', 'einheit_trainer', (int)$mein['id'], ['status' => 'durchgefuehrt'], ['status' => 'geplant'], 'Bestätigung zurückgenommen: ' . $einheit['titel']);
        flashMessage('success', 'Bestätigung zurückgenommen.');
        redirect($self);
    }
}

$teilnehmer = einheitTeilnehmer($db, $einheit);
$zaehler = array_fill_keys(array_keys(ANWESENHEIT_STATUS), 0);
foreach ($teilnehmer as $t) if (!empty($t['status'])) $zaehler[$t['status']]++;
$anwesend = $zaehler['anwesend'] + $zaehler['probetraining'];
$stmt = $db->prepare('SELECT b.*, r.name, r.kategorie FROM ressourcen_buchungen b JOIN ressourcen r ON r.id = b.ressource_id WHERE b.einheit_id = ?');
try { $stmt->execute([$id]); $ressourcen = $stmt->fetchAll(); } catch (Exception $e) { $ressourcen = []; }
$typ = EINHEIT_TYPEN[$einheit['typ']] ?? EINHEIT_TYPEN['sonstiges'];
$wt = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][(int)date('N', strtotime($einheit['start'])) - 1];

$page_title = $einheit['titel'];
$breadcrumb = 'Kalender';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<style>
.eh-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; }
.eh-info div { background: var(--bg-muted); border-radius: 0.75rem; padding: 0.7rem 0.9rem; font-size: 0.88rem; }
.eh-info span { display: block; font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.15rem; }
.eh-status { display: inline-flex; gap: 0.25rem; }
.eh-status label { cursor: pointer; }
.eh-status input { position: absolute; opacity: 0; pointer-events: none; }
.eh-status span { display: inline-block; min-width: 2.1rem; text-align: center; padding: 0.3rem 0.45rem; border-radius: 0.5rem; border: 1px solid var(--border-color); font-size: 0.78rem; font-weight: 700; color: var(--text-muted); }
.eh-status input:checked + span.anwesend { background: var(--success); border-color: var(--success); color: #fff; }
.eh-status input:checked + span.abwesend { background: var(--danger); border-color: var(--danger); color: #fff; }
.eh-status input:checked + span.entschuldigt { background: var(--warning); border-color: var(--warning); color: #1a1a1a; }
.eh-status input:checked + span.probetraining { background: var(--info); border-color: var(--info); color: #fff; }
.eh-status input:focus-visible + span { outline: 2px solid var(--gold-accent); outline-offset: 1px; }
.eh-mini { font-size: 0.75rem; color: var(--text-muted); }
.eh-hinweis { font-size: 0.75rem; color: var(--danger); }
</style>

<div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="<?= APP_URL ?>/dashboard/kalender.php?ansicht=woche&datum=<?= substr($einheit['start'], 0, 10) ?>" style="font-size: 0.8rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Kalender
        </a>
        <h1 class="dashboard-title"><?= e($einheit['titel']) ?></h1>
        <p class="dashboard-subtitle">
            <span class="badge" style="background: color-mix(in srgb, <?= $typ['farbe'] ?> 18%, transparent); color: var(--text-primary);"><?= e($typ['label']) ?></span>
            <span class="badge <?= EINHEIT_STATUS[$einheit['status']]['class'] ?>"><?= EINHEIT_STATUS[$einheit['status']]['label'] ?></span>
            <?= $einheit['serie_id'] ? '<span class="eh-mini">Teil einer Serie</span>' : '' ?>
        </p>
    </div>
    <?php if ($bearbeiten): ?><a href="<?= APP_URL ?>/dashboard/einheit-planen.php?id=<?= $id ?>" class="btn btn-ghost-light btn-sm">Bearbeiten</a><?php endif; ?>
</div>

<div class="eh-info" style="margin-bottom: 1.5rem;">
    <div><span>Wann</span><?= $wt ?>, <?= date('d.m.Y', strtotime($einheit['start'])) ?><br><strong><?= date('H:i', strtotime($einheit['start'])) ?>–<?= date('H:i', strtotime($einheit['ende'])) ?> Uhr</strong></div>
    <div><span>Ort</span><?= e($einheit['ort'] ?: '–') ?></div>
    <?php if ($einheit['projekt_name']): ?><div><span>Projekt</span><a class="text-primary" href="<?= APP_URL ?>/dashboard/projekt.php?id=<?= (int)$einheit['projekt_id'] ?>"><?= e($einheit['projekt_name']) ?></a></div><?php endif; ?>
    <?php if ($einheit['kurs_titel']): ?><div><span>Kurs</span><a class="text-primary" href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= (int)$einheit['kurs_id'] ?>"><?= e($einheit['kurs_titel']) ?></a></div><?php endif; ?>
    <div><span>Teilnehmende</span><?= $anwesend ?> anwesend<?= $einheit['erwartete_teilnehmer'] ? ' · erwartet ' . (int)$einheit['erwartete_teilnehmer'] : '' ?><?= count($teilnehmer) ? ' · ' . count($teilnehmer) . ' auf der Liste' : '' ?></div>
</div>
<?php if ($einheit['notiz']): ?><div class="table-card" style="margin-bottom: 1.5rem;"><div style="padding: 1rem 1.25rem; white-space: pre-line; font-size: 0.9rem;"><?= e($einheit['notiz']) ?></div></div><?php endif; ?>

<div class="grid-2" style="align-items: start;">
    <!-- Trainer:innen & Bestätigung -->
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Trainer:innen</h2></div>
        <?php if (!$trainer): ?>
            <div style="padding: 1.25rem;" class="eh-mini">Noch niemand eingeteilt.</div>
        <?php else: ?>
        <div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Name</th><th>Status</th><th>Durchführung</th><?php if ($darf_betraege): ?><th>Honorar</th><?php endif; ?></tr></thead>
            <tbody><?php foreach ($trainer as $t): $eigen = (int)$t['user_id'] === $me; ?>
                <tr>
                    <td><?= e($t['vorname'] . ' ' . $t['nachname']) ?><div class="eh-mini"><?= $t['rolle'] === 'leitung' ? 'Leitung' : 'Assistenz' ?></div></td>
                    <td><span class="badge <?= ET_STATUS[$t['status']]['class'] ?>"><?= ET_STATUS[$t['status']]['label'] ?></span></td>
                    <td><?= $t['ist_start'] ? date('H:i', strtotime($t['ist_start'])) . '–' . date('H:i', strtotime($t['ist_ende'])) . '<div class="eh-mini">' . dauerText((int)$t['dauer_min']) . ($t['teilnehmer_anzahl'] !== null ? ' · ' . (int)$t['teilnehmer_anzahl'] . ' TN' : '') . '</div>' : '–' ?></td>
                    <?php if ($darf_betraege): ?><td><?= $t['betrag'] !== null ? moneyFormat($t['betrag']) : ($t['ist_start'] ? '<span class="eh-hinweis">kein Satz</span>' : '–') ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>

        <?php if ($mein && $einheit['status'] !== 'storniert'): ?>
        <div id="bestaetigen" style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
            <?php if (!$begonnen): ?>
                <p class="eh-mini" style="margin: 0;">Nach der Einheit kannst du sie hier als durchgeführt bestätigen.</p>
            <?php elseif (in_array($mein['status'], ['geplant', 'durchgefuehrt'], true) && $abrechnung_offen($mein)): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="bestaetigen">
                    <strong style="font-size: 0.9rem;"><?= $mein['status'] === 'geplant' ? 'Einheit als durchgeführt markieren' : 'Bestätigung ändern' ?></strong>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-top: 0.6rem;">
                        <div class="form-group" style="margin: 0;"><label class="form-label">Beginn</label><input class="form-control" type="time" name="ist_von" value="<?= e(substr($mein['ist_start'] ?? $einheit['start'], 11, 5)) ?>"></div>
                        <div class="form-group" style="margin: 0;"><label class="form-label">Ende</label><input class="form-control" type="time" name="ist_bis" value="<?= e(substr($mein['ist_ende'] ?? $einheit['ende'], 11, 5)) ?>"></div>
                        <div class="form-group" style="margin: 0;"><label class="form-label">Teilnehmer:innen</label><input class="form-control" type="number" min="0" max="999" name="teilnehmer" value="<?= e((string)($mein['teilnehmer_anzahl'] ?? ($anwesend ?: ''))) ?>" style="max-width: 110px;"></div>
                    </div>
                    <div class="form-group" style="margin: 0.6rem 0;"><input class="form-control" type="text" name="notiz" maxlength="500" value="<?= e($mein['notiz'] ?? '') ?>" placeholder="Notiz (optional), z.B. Inhalte, Besonderheiten"></div>
                    <button type="submit" class="btn btn-navy btn-sm"><?= $mein['status'] === 'geplant' ? 'Durchgeführt ✓' : 'Aktualisieren' ?></button>
                </form>
                <?php if ($mein['status'] === 'durchgefuehrt'): ?>
                <form method="POST" style="margin-top: 0.5rem;"><?= csrfField() ?><input type="hidden" name="action" value="zuruecknehmen"><button type="submit" class="btn btn-ghost-light btn-sm">Bestätigung zurücknehmen</button></form>
                <?php endif; ?>
            <?php else: ?>
                <p class="eh-mini" style="margin: 0;">Diese Einheit ist in deiner Abrechnung (<?= ET_STATUS[$mein['status']]['label'] ?>) und kann nicht mehr geändert werden.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Material -->
    <div class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Material &amp; Orte</h2></div>
        <div style="padding: 1.25rem; font-size: 0.88rem;">
            <?php if (!$ressourcen): ?><span class="eh-mini">Keine Ressourcen gebucht.</span><?php endif; ?>
            <?php foreach ($ressourcen as $r): ?><div>• <?= (int)$r['menge'] ?> × <?= e($r['name']) ?> <span class="eh-mini">(<?= e(RESSOURCE_KATEGORIEN[$r['kategorie']] ?? $r['kategorie']) ?>)</span></div><?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Anwesenheit -->
<?php if ($darf_anwesenheit): ?>
<form method="POST" class="table-card" id="anwesenheit" style="margin-top: 1.5rem;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="anwesenheit">
    <div class="table-card-header">
        <h2 class="table-card-title">Anwesenheit</h2>
        <span class="eh-mini"><?php foreach (ANWESENHEIT_STATUS as $k => $s): ?><?= $s['kurz'] ?> = <?= e($s['label']) ?> · <?php endforeach; ?><?= $zaehler['anwesend'] ?> anwesend, <?= $zaehler['probetraining'] ?> Probetraining, <?= $zaehler['entschuldigt'] ?> entschuldigt, <?= $zaehler['abwesend'] ?> abwesend</span>
    </div>
    <?php if ($teilnehmer): ?>
    <div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Teilnehmer:in</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($teilnehmer as $t): ?>
            <tr>
                <td><?= e($t['name']) ?><?php if ($t['info']): ?><div class="eh-mini"><?= e($t['info']) ?></div><?php endif; ?>
                    <?php if (!empty($t['hinweise'])): ?><div class="eh-hinweis">⚠ <?= e($t['hinweise']) ?></div><?php endif; ?>
                    <?php if (!empty($t['notfall'])): ?><div class="eh-mini">Notfall: <?= e($t['notfall']) ?></div><?php endif; ?></td>
                <td><div class="eh-status" role="radiogroup" aria-label="Anwesenheit <?= e($t['name']) ?>">
                    <?php foreach (ANWESENHEIT_STATUS as $k => $s): ?>
                    <label title="<?= e($s['label']) ?>"><input type="radio" name="status[<?= e($t['key']) ?>]" value="<?= $k ?>" <?= ($t['status'] ?? '') === $k ? 'checked' : '' ?>><span class="<?= $k ?>"><?= $s['kurz'] ?></span></label>
                    <?php endforeach; ?>
                </div></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?>
        <div style="padding: 1rem 1.25rem;" class="eh-mini"><?= $einheit['kurs_id'] ? 'Für den Kurs ist noch niemand angemeldet.' : 'Diese Einheit hat keine Kursliste – Teilnehmende unten einzeln erfassen.' ?></div>
    <?php endif; ?>
    <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--border-light); display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;"><label class="form-label">Weitere Person / Probetraining</label><input class="form-control" type="text" name="gast" maxlength="150" placeholder="Vor- und Nachname"></div>
        <div class="form-group" style="margin: 0;"><select class="form-control" name="gast_status" aria-label="Status"><option value="probetraining">Probetraining</option><option value="anwesend">anwesend</option></select></div>
        <button type="submit" class="btn btn-navy btn-sm">Anwesenheit speichern</button>
    </div>
</form>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
