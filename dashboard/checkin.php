<?php
/**
 * Athletikclub Steiermark – Check-in (Handy-optimiert)
 *   /checkin/{code}      QR-Code gescannt → Anmeldung prüfen, heutige Einheit einchecken
 *   ?einheit=ID          Teilnehmerliste zum Antippen (manueller Check-in)
 *   ohne Parameter       heutige Einheiten der Person
 * Der Code ist ein 128-Bit-Zufallswert (Besitz = Berechtigung zum Einlösen durch Berechtigte).
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/checkin.php';
require_once ROOT_PATH . '/includes/kursanmeldung.php';

requireLogin();

$db = getDB();
$me = (int)getCurrentUserId();
$ergebnis = null;

// ---------------- QR-Code ----------------
$code = (string)($_GET['c'] ?? '');
if ($code !== '') {
    $ergebnis = ['typ' => 'fehler', 'text' => 'Ungültiger Check-in-Code.'];
    if (preg_match('/^[a-f0-9]{32}$/', $code)) {
        $stmt = $db->prepare('SELECT ka.*, u.vorname, u.nachname, ki.vorname AS kind_vorname, ki.nachname AS kind_nachname, ki.hinweise, ki.notfall_name, ki.notfall_telefon
                              FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id LEFT JOIN kinder ki ON ki.id = ka.kind_id
                              WHERE ka.checkin_code = ? AND ka.organization_id = ?');
        $stmt->execute([$code, currentOrgId()]);
        if ($a = $stmt->fetch()) {
            $kurs = kursLaden($db, (int)$a['kurs_id']);
            $einheit = checkinEinheit($db, (int)$a['kurs_id']);
            $name = $a['kind_vorname'] ? $a['kind_vorname'] . ' ' . $a['kind_nachname'] : $a['vorname'] . ' ' . $a['nachname'];
            if (!checkinDarf($db, $kurs, $einheit)) {
                $ergebnis = ['typ' => 'fehler', 'text' => 'Du bist für „' . $kurs['titel'] . '“ nicht als Kursleitung eingeteilt.'];
            } elseif (!in_array($a['status'], ['angemeldet', 'teilgenommen'], true)) {
                $ergebnis = ['typ' => 'warnung', 'name' => $name, 'kurs' => $kurs, 'text' => 'Anmeldung ist nicht bestätigt (Status: ' . (ANMELDUNG_STATUS[$a['status']]['label'] ?? $a['status']) . ').'];
            } elseif (!$einheit) {
                $ergebnis = ['typ' => 'warnung', 'name' => $name, 'kurs' => $kurs, 'text' => 'Für heute ist keine Einheit dieses Kurses geplant.'];
            } else {
                $r = checkinErfassen($db, $a, $einheit);
                $ergebnis = ['typ' => $r['neu'] ? 'ok' : 'schon', 'name' => $name, 'kurs' => $kurs, 'einheit' => $einheit, 'zeit' => $r['zeit'], 'anmeldung' => $a,
                             'text' => $r['neu'] ? 'Eingecheckt um ' . $r['zeit'] . ' Uhr.' : 'Bereits um ' . $r['zeit'] . ' Uhr eingecheckt.'];
            }
        }
    }
}

// ---------------- Manuelle Liste ----------------
$einheit = null;
if (!empty($_GET['einheit'])) {
    $einheit = einheitLaden($db, (int)$_GET['einheit']);
    $kurs = $einheit && $einheit['kurs_id'] ? kursLaden($db, (int)$einheit['kurs_id']) : null;
    if (!$einheit || !$kurs || !checkinDarf($db, $kurs, $einheit)) {
        flashMessage('error', 'Kein Zugriff auf diesen Check-in.');
        redirect(APP_URL . '/dashboard/checkin.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $stmt = $db->prepare("SELECT * FROM kurs_anmeldungen WHERE id = ? AND kurs_id = ? AND status IN ('angemeldet','teilgenommen')");
        $stmt->execute([(int)($_POST['anmeldung_id'] ?? 0), $kurs['id']]);
        if ($a = $stmt->fetch()) {
            if (($_POST['action'] ?? '') === 'rueckgaengig') {
                $db->prepare('DELETE FROM anwesenheiten WHERE einheit_id = ? AND teilnehmer_key = ?')->execute([$einheit['id'], checkinSchluessel($a)]);
                $db->prepare('UPDATE kurs_anmeldungen SET eingecheckt_am = NULL WHERE id = ?')->execute([$a['id']]);
            } else {
                checkinErfassen($db, $a, $einheit);
            }
        }
        redirect(APP_URL . '/dashboard/checkin.php?einheit=' . (int)$einheit['id'] . '#a' . (int)($_POST['anmeldung_id'] ?? 0));
    }
    $stmt = $db->prepare("SELECT ka.id, ka.user_id, ka.kind_id, u.vorname, u.nachname, ki.vorname AS kind_vorname, ki.nachname AS kind_nachname, ki.hinweise, an.status AS anw, an.erfasst_am
                          FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id LEFT JOIN kinder ki ON ki.id = ka.kind_id
                          LEFT JOIN anwesenheiten an ON an.einheit_id = ? AND an.teilnehmer_key = CASE WHEN ka.kind_id > 0 THEN 'k' || ka.kind_id ELSE 'u' || ka.user_id END
                          WHERE ka.kurs_id = ? AND ka.status IN ('angemeldet','teilgenommen') ORDER BY COALESCE(ki.vorname, u.vorname)");
    $sql_concat = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($sql_concat) {
        $stmt = $db->prepare("SELECT ka.id, ka.user_id, ka.kind_id, u.vorname, u.nachname, ki.vorname AS kind_vorname, ki.nachname AS kind_nachname, ki.hinweise, an.status AS anw, an.erfasst_am
                              FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id LEFT JOIN kinder ki ON ki.id = ka.kind_id
                              LEFT JOIN anwesenheiten an ON an.einheit_id = ? AND an.teilnehmer_key = CASE WHEN ka.kind_id > 0 THEN CONCAT('k', ka.kind_id) ELSE CONCAT('u', ka.user_id) END
                              WHERE ka.kurs_id = ? AND ka.status IN ('angemeldet','teilgenommen') ORDER BY COALESCE(ki.vorname, u.vorname)");
    }
    $stmt->execute([$einheit['id'], $kurs['id']]);
    $liste = $stmt->fetchAll();
}

// ---------------- Heutige Einheiten ----------------
$heute = [];
if (!$einheit && !$ergebnis) {
    $alle = darfEines('anwesenheit.bearbeiten', 'kalender.bearbeiten');
    $sql = "SELECT e.*, k.titel AS kurs_titel, (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = e.kurs_id AND ka.status IN ('angemeldet','teilgenommen')) AS anzahl,
                   (SELECT COUNT(*) FROM anwesenheiten a WHERE a.einheit_id = e.id AND a.status = 'anwesend') AS da
            FROM einheiten e JOIN kurse k ON k.id = e.kurs_id WHERE e.organization_id = ? AND e.status <> 'storniert' AND e.start BETWEEN ? AND ?";
    $p = [currentOrgId(), date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];
    if (!$alle) { $sql .= ' AND (k.trainer_id = ? OR EXISTS (SELECT 1 FROM einheit_trainer et WHERE et.einheit_id = e.id AND et.user_id = ?))'; array_push($p, $me, $me); }
    $stmt = $db->prepare($sql . ' ORDER BY e.start');
    $stmt->execute($p);
    $heute = $stmt->fetchAll();
}

$page_title = 'Check-in';
$breadcrumb = 'Check-in';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.ci-ergebnis { text-align: center; padding: 2rem 1.25rem; border-radius: 1.25rem; margin-bottom: 1.5rem; }
.ci-ergebnis.ok, .ci-ergebnis.schon { background: rgba(34, 197, 94, 0.12); }
.ci-ergebnis.warnung { background: rgba(245, 158, 11, 0.14); }
.ci-ergebnis.fehler { background: rgba(239, 68, 68, 0.12); }
.ci-icon { font-size: 3rem; line-height: 1; margin-bottom: 0.5rem; }
.ci-name { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.5rem; margin: 0.25rem 0; }
.ci-liste { display: flex; flex-direction: column; }
.ci-person { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--border-light); }
.ci-person.da { background: rgba(34, 197, 94, 0.07); }
.ci-person button { min-width: 7.5rem; min-height: 2.75rem; font-size: 0.95rem; }
.ci-such { margin-bottom: 1rem; font-size: 1rem; padding: 0.8rem 1rem; }
.ci-mini { font-size: 0.78rem; color: var(--text-muted); }
</style>

<?php if ($ergebnis): $icon = ['ok' => '✅', 'schon' => '☑️', 'warnung' => '⚠️', 'fehler' => '⛔'][$ergebnis['typ']]; ?>
    <div class="ci-ergebnis <?= e($ergebnis['typ']) ?>" role="status">
        <div class="ci-icon" aria-hidden="true"><?= $icon ?></div>
        <?php if (!empty($ergebnis['name'])): ?><div class="ci-name"><?= e($ergebnis['name']) ?></div><?php endif; ?>
        <?php if (!empty($ergebnis['kurs'])): ?><div><?= e($ergebnis['kurs']['titel']) ?></div><?php endif; ?>
        <p style="margin: 0.5rem 0 0; font-weight: 600;"><?= e($ergebnis['text']) ?></p>
        <?php if (!empty($ergebnis['anmeldung']['hinweise'])): ?><p style="margin: 0.75rem auto 0; max-width: 420px; background: #fff; border-radius: 0.6rem; padding: 0.6rem; color: #B45309;"><strong>Hinweis:</strong> <?= e($ergebnis['anmeldung']['hinweise']) ?></p><?php endif; ?>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center;">
        <?php if (!empty($ergebnis['einheit'])): ?><a class="btn btn-navy" href="?einheit=<?= (int)$ergebnis['einheit']['id'] ?>">Teilnehmerliste</a><?php endif; ?>
        <a class="btn btn-ghost-light" href="<?= APP_URL ?>/dashboard/checkin.php">Heutige Einheiten</a>
    </div>
    <p class="ci-mini" style="text-align: center; margin-top: 1rem;">Nächsten Code einfach mit der Handykamera scannen.</p>

<?php elseif ($einheit): $da = count(array_filter($liste, fn($x) => $x['anw'] === 'anwesend')); ?>
    <div class="dashboard-header">
        <a href="<?= APP_URL ?>/dashboard/checkin.php" class="ci-mini">← Heute</a>
        <h1 class="dashboard-title"><?= e($einheit['titel']) ?></h1>
        <p class="dashboard-subtitle"><?= date('d.m.Y, H:i', strtotime($einheit['start'])) ?> · <strong><?= $da ?> / <?= count($liste) ?></strong> eingecheckt · <a href="<?= APP_URL ?>/dashboard/einheit.php?id=<?= (int)$einheit['id'] ?>">Einheit bestätigen</a></p>
    </div>
    <input class="form-control ci-such" type="search" placeholder="Name suchen …" oninput="var s=this.value.toLowerCase();document.querySelectorAll('.ci-person').forEach(function(el){el.style.display=el.dataset.n.indexOf(s)>=0?'':'none'})" aria-label="Teilnehmende suchen">
    <div class="table-card"><div class="ci-liste">
        <?php if (!$liste): ?><p class="ci-mini" style="padding: 1rem;">Keine bestätigten Anmeldungen.</p><?php endif; ?>
        <?php foreach ($liste as $t): $n = $t['kind_vorname'] ? $t['kind_vorname'] . ' ' . $t['kind_nachname'] : $t['vorname'] . ' ' . $t['nachname']; $ist = $t['anw'] === 'anwesend'; ?>
        <div class="ci-person <?= $ist ? 'da' : '' ?>" id="a<?= (int)$t['id'] ?>" data-n="<?= e(mb_strtolower($n)) ?>">
            <div><strong><?= e($n) ?></strong><?php if ($t['hinweise']): ?> <span class="badge badge-warning" title="<?= e($t['hinweise']) ?>">Hinweis</span><?php endif; ?>
                <div class="ci-mini"><?= $t['kind_vorname'] ? 'Kind von ' . e($t['vorname'] . ' ' . $t['nachname']) : '' ?><?= $ist ? ' · ' . date('H:i', strtotime($t['erfasst_am'])) . ' Uhr' : '' ?></div></div>
            <form method="POST"><?= csrfField() ?><input type="hidden" name="anmeldung_id" value="<?= (int)$t['id'] ?>">
                <?php if ($ist): ?><button class="btn btn-ghost-light" name="action" value="rueckgaengig">✓ Da</button>
                <?php else: ?><button class="btn btn-primary" name="action" value="checkin">Einchecken</button><?php endif; ?>
            </form>
        </div>
        <?php endforeach; ?>
    </div></div>

<?php else: ?>
    <div class="dashboard-header"><h1 class="dashboard-title">Check-in</h1><p class="dashboard-subtitle">Teilnehmende zeigen ihren QR-Code – einfach mit der Handykamera scannen. Oder die Liste öffnen und antippen.</p></div>
    <div class="table-card">
        <?php if (!$heute): ?><div class="empty-state" style="padding: 2rem 1rem;"><p>Heute sind keine Einheiten mit Anmeldeliste geplant.</p></div>
        <?php else: foreach ($heute as $e): ?>
        <a class="ci-person" href="?einheit=<?= (int)$e['id'] ?>" style="color: inherit;">
            <div><strong><?= date('H:i', strtotime($e['start'])) ?> · <?= e($e['titel']) ?></strong><div class="ci-mini"><?= e($e['ort'] ?? '') ?></div></div>
            <span class="badge <?= (int)$e['da'] >= (int)$e['anzahl'] && $e['anzahl'] ? 'badge-success' : 'badge-gray' ?>"><?= (int)$e['da'] ?> / <?= (int)$e['anzahl'] ?></span>
        </a>
        <?php endforeach; endif; ?>
    </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
