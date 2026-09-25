<?php
/**
 * Athletikclub Steiermark – öffentliche Kurs-/Eventdetailseite mit Anmeldung (/kurse/{id})
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/buchung.php';

$db = getDB();
$kurs = oeffentlicherKurs($db, (int)($_GET['id'] ?? 0));
if (!$kurs) {
    http_response_code(404);
    $page_title = 'Angebot nicht gefunden';
    $noindex = true;
    require_once ROOT_PATH . '/includes/header.php';
    echo '<section class="section bg-white"><div class="container container--narrow text-center"><h1 style="font-family: Montserrat, sans-serif; font-weight: 800;">Angebot nicht gefunden</h1>'
       . '<p>Dieses Angebot ist nicht (mehr) online buchbar.</p><a class="btn btn-navy" href="' . APP_URL . '/kurse">Alle Kurse & Events</a></div></section>';
    require_once ROOT_PATH . '/includes/footer.php';
    exit;
}

$ergebnis = null;
$fehler = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $ergebnis = oeffentlicheAnmeldung($db, $kurs, $_POST);
    if (!$ergebnis['ok']) $fehler = $ergebnis['fehler'];
    else $kurs = oeffentlicherKurs($db, (int)$kurs['id']) ?? $kurs; // Plätze neu
}

$termine = kursTermine($db, (int)$kurs['id']);
$frei = freiePlaetze($db, $kurs);
$geschlossen = kursAnmeldungGeschlossen($kurs);
$eingeloggt = isLoggedIn();
$kinder = $eingeloggt ? meineKinder($db, (int)getCurrentUserId()) : [];
$ist_event = $kurs['art'] === 'event';
$v = fn($k, $d = '') => e((string)($_POST[$k] ?? $d));
$fe = fn($k) => isset($fehler[$k]) ? '<span class="form-error">' . e($fehler[$k]) . '</span>' : '';
$alter = $kurs['min_alter'] !== null || $kurs['max_alter'] !== null
    ? ($kurs['min_alter'] !== null && $kurs['max_alter'] !== null ? (int)$kurs['min_alter'] . '–' . (int)$kurs['max_alter'] . ' Jahre' : ($kurs['min_alter'] !== null ? 'ab ' . (int)$kurs['min_alter'] . ' Jahren' : 'bis ' . (int)$kurs['max_alter'] . ' Jahre'))
    : 'alle Altersgruppen';
$nur_kinder = $kurs['max_alter'] !== null && (int)$kurs['max_alter'] < 18;
$teilnahme = $_POST['teilnahme'] ?? ($nur_kinder ? 'kind' : 'selbst');
$wt = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

$page_title       = $kurs['titel'];
$meta_description = mb_strimwidth($kurs['kurzbeschreibung'] ?: strip_tags((string)$kurs['beschreibung']) ?: $kurs['titel'], 0, 155, '…');
require_once ROOT_PATH . '/includes/header.php';
?>

<style>
.kd-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 2rem; align-items: start; }
.kd-bild { border-radius: 1rem; overflow: hidden; margin-bottom: 1.5rem; aspect-ratio: 16 / 8; background: var(--bg-muted); }
.kd-bild img { width: 100%; height: 100%; object-fit: cover; display: block; }
.kd-fakten { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin: 1.5rem 0; }
.kd-fakt { background: var(--bg-muted); border-radius: 0.75rem; padding: 0.8rem 1rem; font-size: 0.9rem; }
.kd-fakt small { display: block; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 0.15rem; }
.kd-h2 { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.05rem; text-transform: uppercase; letter-spacing: 0.04em; margin: 1.75rem 0 0.75rem; }
.kd-termine { list-style: none; padding: 0; margin: 0; display: grid; gap: 0.4rem; }
.kd-termine li { display: flex; gap: 0.75rem; padding: 0.55rem 0.8rem; border: 1px solid var(--border-light); border-radius: 0.6rem; font-size: 0.9rem; }
.kd-termine strong { min-width: 6.5rem; }
.kd-box { position: sticky; top: 6rem; }
.kd-preis { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.8rem; }
.kd-plaetze-balken { height: 8px; border-radius: 4px; background: var(--bg-muted); overflow: hidden; margin: 0.4rem 0 0.3rem; }
.kd-plaetze-balken span { display: block; height: 100%; background: var(--gold-accent); }
.kd-wahl { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem; }
.kd-wahl label { border: 2px solid var(--border-light); border-radius: 0.75rem; padding: 0.7rem; text-align: center; cursor: pointer; font-weight: 600; font-size: 0.9rem; }
.kd-wahl input { position: absolute; opacity: 0; }
.kd-wahl input:checked + span { color: var(--navy-primary); }
.kd-wahl label:has(input:checked) { border-color: var(--gold-accent); background: color-mix(in srgb, var(--gold-accent) 10%, transparent); }
.kd-abschnitt { border-top: 1px solid var(--border-light); padding-top: 1rem; margin-top: 1rem; }
.kd-abschnitt h3 { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin: 0 0 0.75rem; }
.kd-hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
.kd-erfolg { text-align: center; }
.kd-erfolg .kd-icon { width: 56px; height: 56px; border-radius: 50%; background: rgba(34, 197, 94, 0.12); color: #15803D; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.6rem; }
@media (max-width: 900px) { .kd-grid { grid-template-columns: minmax(0, 1fr); } .kd-box { position: static; } }
@media (max-width: 520px) { .kd-box .form-row { grid-template-columns: minmax(0, 1fr); } }
</style>

<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a><span class="breadcrumb-sep">›</span>
            <a href="<?= APP_URL ?>/kurse<?= $ist_event ? '?art=event' : '' ?>"><?= $ist_event ? 'Events' : 'Kurse & Events' ?></a><span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current"><?= e($kurs['titel']) ?></span>
        </nav>
        <h1><?= e($kurs['titel']) ?></h1>
        <p><?= date('d.m.Y', strtotime($kurs['start_datum'])) ?><?= substr($kurs['start_datum'], 0, 10) !== substr($kurs['end_datum'], 0, 10) ? ' – ' . date('d.m.Y', strtotime($kurs['end_datum'])) : ', ' . date('H:i', strtotime($kurs['start_datum'])) . '–' . date('H:i', strtotime($kurs['end_datum'])) . ' Uhr' ?><?= $kurs['ort'] ? ' · ' . e($kurs['ort']) : '' ?></p>
    </div>
</section>

<section class="section bg-white">
    <div class="container kd-grid">
        <div>
            <?php if ($kurs['bild']): ?><div class="kd-bild"><img src="<?= APP_URL ?>/api/bild.php?k=<?= (int)$kurs['id'] ?>" alt="<?= e($kurs['titel']) ?>"></div><?php endif; ?>
            <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                <?php if ($ist_event): ?><span class="badge badge-navy">Event</span><?php endif; ?>
                <?php if ($kurs['sportart']): ?><span class="badge badge-gold"><?= e($kurs['sportart']) ?></span><?php endif; ?>
            </div>
            <?php if ($kurs['beschreibung']): ?><div style="line-height: 1.75; margin-top: 1rem;"><?= nl2br(e($kurs['beschreibung'])) ?></div><?php endif; ?>

            <div class="kd-fakten">
                <div class="kd-fakt"><small>Standort</small><?= e($kurs['ort'] ?: 'wird bekanntgegeben') ?><?php if ($kurs['ort']): ?><br><a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($kurs['ort']) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 0.8rem;">Route planen</a><?php endif; ?></div>
                <div class="kd-fakt"><small>Trainer:in</small><?= $kurs['trainer_vorname'] ? e($kurs['trainer_vorname'] . ' ' . $kurs['trainer_nachname']) : '–' ?></div>
                <div class="kd-fakt"><small>Altersgruppe</small><?= e($alter) ?></div>
                <div class="kd-fakt"><small>Teilnehmende</small><?= $kurs['max_teilnehmer'] ? 'max. ' . (int)$kurs['max_teilnehmer'] : 'unbegrenzt' ?></div>
                <?php if ($kurs['anmeldeschluss']): ?><div class="kd-fakt"><small>Anmeldeschluss</small><?= date('d.m.Y, H:i', strtotime($kurs['anmeldeschluss'])) ?> Uhr</div><?php endif; ?>
            </div>

            <?php if (trim((string)$kurs['voraussetzungen']) !== ''): ?>
                <h2 class="kd-h2">Voraussetzungen</h2>
                <p style="line-height: 1.7;"><?= nl2br(e($kurs['voraussetzungen'])) ?></p>
            <?php endif; ?>

            <h2 class="kd-h2">Termine</h2>
            <?php if ($termine): ?>
                <ul class="kd-termine">
                    <?php foreach ($termine as $t): ?>
                    <li><strong><?= $wt[(int)date('w', strtotime($t['start']))] ?>, <?= date('d.m.Y', strtotime($t['start'])) ?></strong><span><?= date('H:i', strtotime($t['start'])) ?>–<?= date('H:i', strtotime($t['ende'])) ?> Uhr<?= $t['ort'] && $t['ort'] !== $kurs['ort'] ? ' · ' . e($t['ort']) : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p><?= date('d.m.Y, H:i', strtotime($kurs['start_datum'])) ?> Uhr bis <?= date('d.m.Y, H:i', strtotime($kurs['end_datum'])) ?> Uhr</p>
            <?php endif; ?>
        </div>

        <aside class="kd-box" id="anmelden">
            <div class="card"><div class="card-body">
            <?php if ($ergebnis && $ergebnis['ok']): ?>
                <div class="kd-erfolg">
                    <div class="kd-icon" aria-hidden="true">✓</div>
                    <?php if ($ergebnis['gast']): ?>
                        <h2 style="font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.2rem;">Fast geschafft!</h2>
                        <p>Wir haben eine E-Mail an die angegebene Adresse geschickt. Bitte bestätige die Anmeldung über den Link darin innerhalb von <?= (int)einstellung('anfrage_gueltig_std', '48') ?> Stunden – bis dahin ist der Platz für dich reserviert.</p>
                        <p class="form-hint">Keine E-Mail bekommen? Sieh im Spam-Ordner nach oder melde dich bei uns.</p>
                    <?php elseif ($ergebnis['status'] === 'warteliste'): ?>
                        <h2 style="font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.2rem;">Auf der Warteliste</h2>
                        <p><?= e($ergebnis['name']) ?> steht auf Platz <?= (int)$ergebnis['position'] ?> der Warteliste. Wird ein Platz frei, rückt ihr automatisch nach und bekommt eine Nachricht.</p>
                    <?php else: ?>
                        <h2 style="font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 1.2rem;"><?= $ergebnis['schon'] ? 'Bereits angemeldet' : 'Anmeldung bestätigt' ?></h2>
                        <p><?= e($ergebnis['name']) ?> ist für „<?= e($kurs['titel']) ?>“ angemeldet. Die Bestätigung findest du auch im Dashboard.</p>
                    <?php endif; ?>
                    <?php if (!$ergebnis['gast'] && $ergebnis['token']): ?><a class="btn btn-navy btn-sm" href="<?= e(buchungLink($ergebnis['token'])) ?>">Buchung ansehen</a><?php endif; ?>
                    <a class="btn btn-ghost-light btn-sm" href="<?= APP_URL ?>/kurse" style="margin-top: 0.5rem;">Weitere Angebote</a>
                </div>
            <?php else: ?>
                <div class="kd-preis"><?= (float)$kurs['preis'] > 0 ? moneyFormat($kurs['preis']) : 'kostenlos' ?></div>
                <?php if ($frei !== null): ?>
                    <div class="kd-plaetze-balken"><span style="width: <?= round(((int)$kurs['max_teilnehmer'] - $frei) / max(1, (int)$kurs['max_teilnehmer']) * 100) ?>%"></span></div>
                    <p style="font-size: 0.85rem; margin: 0 0 1rem;"><?= $frei > 0 ? '<strong>' . $frei . '</strong> von ' . (int)$kurs['max_teilnehmer'] . ' Plätzen frei' : '<strong>Ausgebucht</strong> – Anmeldung auf die Warteliste möglich' ?></p>
                <?php endif; ?>

                <?php if ($geschlossen): ?>
                    <div class="flash-message flash-info" style="border-radius: 0.6rem;"><span><?= e($geschlossen) ?></span></div>
                <?php else: ?>
                <?php if (!empty($fehler['allgemein'])): ?><div class="flash-message flash-error" style="border-radius: 0.6rem; margin-bottom: 1rem;"><span><?= e($fehler['allgemein']) ?></span></div><?php endif; ?>
                <form method="POST" action="#anmelden" novalidate>
                    <?= csrfField() ?>
                    <div class="kd-hp" aria-hidden="true"><label>Website <input type="text" name="firma_web" tabindex="-1" autocomplete="off"></label></div>

                    <?php if (!$nur_kinder || $eingeloggt): ?>
                    <div class="kd-wahl" role="radiogroup" aria-label="Wer nimmt teil?">
                        <label><input type="radio" name="teilnahme" value="selbst" <?= $teilnahme === 'selbst' ? 'checked' : '' ?> onchange="kdWahl()"><span>Ich selbst</span></label>
                        <label><input type="radio" name="teilnahme" value="kind" <?= $teilnahme === 'kind' ? 'checked' : '' ?> onchange="kdWahl()"><span>Mein Kind</span></label>
                    </div>
                    <?php else: ?><input type="hidden" name="teilnahme" value="kind"><?php endif; ?>

                    <?php if ($eingeloggt): ?>
                        <p class="form-hint" style="margin-bottom: 0.75rem;">Angemeldet als <strong><?= e(getCurrentUser()['vorname'] . ' ' . getCurrentUser()['nachname']) ?></strong> – deine Daten werden aus deinem Profil übernommen.</p>
                        <div data-bereich="selbst" class="form-group"><label class="form-label">Geburtsdatum <span class="form-hint">(falls nicht im Profil)</span></label><input class="form-control" type="date" name="geburtsdatum" max="<?= date('Y-m-d') ?>" value="<?= $v('geburtsdatum') ?>"></div>
                        <?php if ($kinder): ?>
                        <div data-bereich="kind" class="form-group"><label class="form-label">Kind</label>
                            <select class="form-control" name="kind_id" onchange="kdWahl()"><?php foreach ($kinder as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (int)($_POST['kind_id'] ?? 0) === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['vorname'] . ' ' . $k['nachname']) ?></option><?php endforeach; ?><option value="0" <?= ($_POST['kind_id'] ?? '') === '0' ? 'selected' : '' ?>>+ weiteres Kind</option></select><?= $fe('kind_id') ?></div>
                        <?php else: ?><input type="hidden" name="kind_id" value="0"><?php endif; ?>
                    <?php else: ?>
                        <div class="kd-abschnitt" style="border-top: 0; padding-top: 0; margin-top: 0;">
                            <h3 data-bereich="kind">Erziehungsberechtigte Person</h3>
                            <div class="form-row">
                                <div class="form-group"><label class="form-label" for="b-vn">Vorname *</label><input class="form-control" id="b-vn" name="vorname" autocomplete="given-name" required value="<?= $v('vorname') ?>"><?= $fe('vorname') ?></div>
                                <div class="form-group"><label class="form-label" for="b-nn">Nachname *</label><input class="form-control" id="b-nn" name="nachname" autocomplete="family-name" required value="<?= $v('nachname') ?>"><?= $fe('nachname') ?></div>
                            </div>
                            <div class="form-group"><label class="form-label" for="b-em">E-Mail *</label><input class="form-control" id="b-em" type="email" name="email" autocomplete="email" required value="<?= $v('email') ?>"><?= $fe('email') ?></div>
                            <div class="form-row">
                                <div class="form-group"><label class="form-label" for="b-tel">Telefon <span data-bereich="kind">*</span></label><input class="form-control" id="b-tel" type="tel" name="telefon" autocomplete="tel" value="<?= $v('telefon') ?>"><?= $fe('telefon') ?></div>
                                <div class="form-group" data-bereich="selbst"><label class="form-label" for="b-geb">Geburtsdatum<?= $kurs['min_alter'] !== null || $kurs['max_alter'] !== null ? ' *' : '' ?></label><input class="form-control" id="b-geb" type="date" name="geburtsdatum" max="<?= date('Y-m-d') ?>" value="<?= $v('geburtsdatum') ?>"><?= $fe('geburtsdatum') ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="kd-abschnitt" data-bereich="kind-neu">
                        <h3>Kind</h3>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label" for="k-vn">Vorname *</label><input class="form-control" id="k-vn" name="kind_vorname" value="<?= $v('kind_vorname') ?>"><?= $fe('kind_vorname') ?></div>
                            <div class="form-group"><label class="form-label" for="k-nn">Nachname</label><input class="form-control" id="k-nn" name="kind_nachname" value="<?= $v('kind_nachname') ?>" placeholder="wie Elternteil"></div>
                        </div>
                        <div class="form-group"><label class="form-label" for="k-geb">Geburtsdatum *</label><input class="form-control" id="k-geb" type="date" name="kind_geburtsdatum" max="<?= date('Y-m-d') ?>" value="<?= $v('kind_geburtsdatum') ?>"><?= $fe('kind_geburtsdatum') ?></div>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label" for="k-nf">Notfallkontakt</label><input class="form-control" id="k-nf" name="notfall_name" value="<?= $v('notfall_name') ?>" placeholder="falls abweichend"></div>
                            <div class="form-group"><label class="form-label" for="k-nft">Notfall-Telefon</label><input class="form-control" id="k-nft" type="tel" name="notfall_telefon" value="<?= $v('notfall_telefon') ?>"><?= $fe('notfall_telefon') ?></div>
                        </div>
                        <div class="form-group"><label class="form-label" for="k-hw">Hinweise für Trainer:innen</label><textarea class="form-control" id="k-hw" name="hinweise" rows="2" placeholder="Allergien, Medikamente …"><?= $v('hinweise') ?></textarea></div>
                    </div>

                    <div class="kd-abschnitt">
                        <?php if (trim((string)$kurs['voraussetzungen']) !== ''): ?>
                        <label class="form-check" style="margin-bottom: 0.5rem;"><input type="checkbox" name="voraussetzungen_ok" value="1" <?= !empty($_POST['voraussetzungen_ok']) ? 'checked' : '' ?>><span class="form-check-label">Die Voraussetzungen sind erfüllt.</span></label>
                        <?php endif; ?>
                        <label class="form-check" style="margin-bottom: 0.5rem;" data-bereich="kind"><input type="checkbox" name="einw_teilnahme" value="1" <?= !empty($_POST['einw_teilnahme']) ? 'checked' : '' ?>><span class="form-check-label">Ich bin erziehungsberechtigt und stimme der Teilnahme meines Kindes zu. *</span></label>
                        <?= $fe('einw_teilnahme') ?>
                        <label class="form-check" style="margin-bottom: 0.5rem;"><input type="checkbox" name="datenschutz" value="1" <?= !empty($_POST['datenschutz']) ? 'checked' : '' ?>><span class="form-check-label">Ich habe die <a href="<?= APP_URL ?>/pages/datenschutz.php" target="_blank">Datenschutzerklärung</a> gelesen und stimme der Verarbeitung meiner Angaben zur Kursabwicklung zu. *</span></label>
                        <?= $fe('datenschutz') ?>
                        <label class="form-check" style="margin-bottom: 1rem;"><input type="checkbox" name="einw_foto" value="1" <?= !empty($_POST['einw_foto']) ? 'checked' : '' ?>><span class="form-check-label">Foto- und Videoaufnahmen für Vereinszwecke sind erlaubt (freiwillig).</span></label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-full"><?= $frei === 0 ? 'Auf Warteliste setzen' : 'Jetzt anmelden' ?></button>
                    <?php if (!$eingeloggt): ?><p class="form-hint" style="margin-top: 0.75rem;">Schon Mitglied? <a href="<?= APP_URL ?>/auth/login.php">Anmelden</a> und Daten automatisch übernehmen.</p><?php endif; ?>
                    <?php if ((float)$kurs['preis'] > 0): ?><p class="form-hint">Die Rechnung erhältst du nach Bestätigung der Anmeldung.</p><?php endif; ?>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            </div></div>
        </aside>
    </div>
</section>

<script>
function kdWahl() {
    var r = document.querySelector('input[name="teilnahme"]:checked'), kind = r ? r.value === 'kind' : true;
    var sel = document.querySelector('select[name="kind_id"]'), neu = kind && (!sel || sel.value === '0');
    document.querySelectorAll('[data-bereich="kind"]').forEach(function (el) { el.style.display = kind ? '' : 'none'; });
    document.querySelectorAll('[data-bereich="selbst"]').forEach(function (el) { el.style.display = kind ? 'none' : ''; });
    document.querySelectorAll('[data-bereich="kind-neu"]').forEach(function (el) { el.style.display = neu ? '' : 'none'; });
}
kdWahl();
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
