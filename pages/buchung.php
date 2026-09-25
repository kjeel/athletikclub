<?php
/**
 * Athletikclub Steiermark – eigene Buchung verwalten (/buchung/{token})
 * Der 128-Bit-Token aus der E-Mail ist der Zugang: bestätigen, Details sehen,
 * QR-Code für den Check-in anzeigen, im Rahmen der Stornoregeln stornieren.
 * Bestätigung/Storno nur per POST (E-Mail-Scanner lösen nichts aus).
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/buchung.php';

$db = getDB();
$token = (string)($_GET['t'] ?? '');
$a = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $stmt = $db->prepare('SELECT ka.*, u.vorname, u.nachname, ki.vorname AS kind_vorname, ki.nachname AS kind_nachname
                          FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id LEFT JOIN kinder ki ON ki.id = ka.kind_id
                          WHERE ka.token = ? AND ka.organization_id = ?');
    $stmt->execute([$token, currentOrgId()]);
    $a = $stmt->fetch() ?: null;
}
$kurs = $a ? kursLaden($db, (int)$a['kurs_id']) : null;
$meldung = null;

if ($a && $kurs && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'bestaetigen') {
        if ($a['status'] === 'storniert') {
            $meldung = ['error', 'Diese Anmeldung ist bereits verfallen oder storniert. Bitte melde dich neu an.'];
        } else {
            $neu = kursAnmeldungBestaetigen($db, $kurs, $a);
            $meldung = ['success', $neu === 'angemeldet' ? 'Danke! Die Anmeldung ist bestätigt.' : 'Danke! Deine E-Mail-Adresse ist bestätigt.'];
        }
    } elseif ($aktion === 'stornieren') {
        if ($grund = kursStornoGesperrt($kurs, $a)) {
            $meldung = ['error', $grund];
        } else {
            kursStornieren($db, $kurs, $a, true);
            $meldung = ['success', 'Die Anmeldung wurde storniert. Der Platz geht an die Warteliste.'];
        }
    }
    $stmt = $db->prepare('SELECT ka.*, u.vorname, u.nachname, ki.vorname AS kind_vorname, ki.nachname AS kind_nachname FROM kurs_anmeldungen ka
                          JOIN users u ON u.id = ka.user_id LEFT JOIN kinder ki ON ki.id = ka.kind_id WHERE ka.id = ?');
    $stmt->execute([$a['id']]);
    $a = $stmt->fetch();
}

$page_title = 'Meine Buchung';
$noindex = true;
require_once ROOT_PATH . '/includes/header.php';
if (!$a || !$kurs): ?>
<section class="section bg-white"><div class="container container--narrow text-center">
    <h1 style="font-family: 'Montserrat', sans-serif; font-weight: 800;">Buchung nicht gefunden</h1>
    <p>Der Link ist ungültig oder abgelaufen. Bitte verwende den Link aus deiner E-Mail oder kontaktiere uns.</p>
    <a class="btn btn-navy" href="<?= APP_URL ?>/kurse">Zu den Kursen</a>
</div></section>
<?php require_once ROOT_PATH . '/includes/footer.php'; exit; endif;

$person = $a['kind_vorname'] ? $a['kind_vorname'] . ' ' . $a['kind_nachname'] : $a['vorname'] . ' ' . $a['nachname'];
$st = ANMELDUNG_STATUS[$a['status']] ?? ['label' => $a['status'], 'class' => 'badge-gray'];
$unbestaetigt = $a['bestaetigt_am'] === null && in_array($a['status'], ['angefragt', 'warteliste'], true);
$storno_grund = kursStornoGesperrt($kurs, $a);
$zeige_qr = in_array($a['status'], ['angemeldet', 'teilgenommen'], true) && !empty($a['checkin_code']) && strtotime($kurs['end_datum']) >= time() - 86400;
$termine = kursTermine($db, (int)$kurs['id'], 10);
?>
<style>
.bu-karte { max-width: 640px; margin: 0 auto; }
.bu-zeile { display: grid; grid-template-columns: 9rem 1fr; gap: 0.75rem; padding: 0.55rem 0; border-bottom: 1px solid var(--border-light); font-size: 0.95rem; }
.bu-zeile:last-child { border-bottom: none; }
.bu-zeile span:first-child { color: var(--text-muted); font-size: 0.85rem; }
.bu-qr { text-align: center; padding: 1rem; background: #fff; border-radius: 1rem; border: 1px solid var(--border-light); margin: 1.25rem 0; }
.bu-qr svg { width: 220px; height: 220px; display: block; margin: 0 auto; }
@media (max-width: 520px) { .bu-zeile { grid-template-columns: 1fr; gap: 0.1rem; } }
</style>

<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <h1>Meine Buchung</h1>
        <p><?= e($kurs['titel']) ?></p>
    </div>
</section>

<section class="section bg-white">
    <div class="container">
        <div class="card bu-karte"><div class="card-body">
            <?php if ($meldung): ?><div class="flash-message flash-<?= $meldung[0] ?>" style="border-radius: 0.6rem; margin-bottom: 1rem;"><span><?= e($meldung[1]) ?></span></div><?php endif; ?>

            <?php if ($unbestaetigt && $a['status'] !== 'storniert'): ?>
                <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.35); border-radius: 0.75rem; padding: 1rem; margin-bottom: 1.25rem;">
                    <strong>Bitte bestätige deine Anmeldung.</strong>
                    <p style="margin: 0.35rem 0 0.75rem; font-size: 0.9rem;"><?= $a['anfrage_bis'] ? 'Der Platz ist bis ' . date('d.m.Y, H:i', strtotime($a['anfrage_bis'])) . ' Uhr reserviert.' : 'Mit der Bestätigung bleibt dein Wartelistenplatz aktiv.' ?></p>
                    <form method="POST"><?= csrfField() ?><input type="hidden" name="aktion" value="bestaetigen"><button class="btn btn-primary" type="submit">Anmeldung bestätigen</button></form>
                </div>
            <?php endif; ?>

            <div class="bu-zeile"><span>Status</span><span><span class="badge <?= $st['class'] ?>"><?= e($st['label']) ?></span><?= $a['status'] === 'warteliste' ? ' · Platz ' . kursWartelistePosition($db, (int)$kurs['id'], (int)$a['id']) : '' ?></span></div>
            <div class="bu-zeile"><span>Teilnehmer:in</span><span><?= e($person) ?></span></div>
            <div class="bu-zeile"><span>Angebot</span><span><a href="<?= e(kursOeffentlichLink((int)$kurs['id'])) ?>"><?= e($kurs['titel']) ?></a></span></div>
            <div class="bu-zeile"><span>Beginn</span><span><?= date('d.m.Y, H:i', strtotime($kurs['start_datum'])) ?> Uhr</span></div>
            <div class="bu-zeile"><span>Ort</span><span><?= e($kurs['ort'] ?: '–') ?><?php if ($kurs['ort']): ?> · <a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($kurs['ort']) ?>" target="_blank" rel="noopener noreferrer">Route</a><?php endif; ?></span></div>
            <div class="bu-zeile"><span>Preis</span><span><?= (float)$kurs['preis'] > 0 ? moneyFormat($kurs['preis']) . ($a['bezahlt'] ? ' · <span class="badge badge-success">bezahlt</span>' : ' · Zahlung offen') : 'kostenlos' ?></span></div>
            <?php if ($termine): ?><div class="bu-zeile"><span>Nächste Termine</span><span><?php foreach (array_slice($termine, 0, 4) as $t): ?><?= date('d.m.', strtotime($t['start'])) ?> <?= date('H:i', strtotime($t['start'])) ?> · <?php endforeach; ?><?= count($termine) > 4 ? '…' : '' ?></span></div><?php endif; ?>

            <?php if ($zeige_qr && ($svg = qrSvg(checkinLink($a['checkin_code'])))): ?>
                <div class="bu-qr">
                    <?= $svg ?>
                    <p style="margin: 0.5rem 0 0; font-size: 0.85rem;">Check-in-Code – beim Training einfach vorzeigen.</p>
                </div>
            <?php endif; ?>

            <?php if (in_array($a['status'], ['angemeldet', 'angefragt', 'warteliste'], true)): ?>
                <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border-light);">
                    <?php if ($storno_grund): ?>
                        <p class="form-hint"><?= e($storno_grund) ?></p>
                    <?php else: ?>
                        <form method="POST" onsubmit="<?= bestaetigen('Anmeldung zu „' . $kurs['titel'] . '“ wirklich stornieren?') ?>"><?= csrfField() ?><input type="hidden" name="aktion" value="stornieren">
                            <button class="btn btn-ghost-light btn-sm" type="submit"><?= $a['status'] === 'warteliste' ? 'Von der Warteliste nehmen' : 'Anmeldung stornieren' ?></button></form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <p class="form-hint" style="margin-top: 1rem;">Mit einem Konto siehst du alle Buchungen im Dashboard: <a href="<?= APP_URL ?>/auth/passwort-vergessen.php">Passwort festlegen</a>.</p>
        </div></div>
    </div>
</section>
<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
