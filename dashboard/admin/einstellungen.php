<?php
/**
 * Athletikclub Steiermark – Zentrale Einstellungen
 * Vereinsstammdaten (gemeinsam mit PRAE in prae_einstellungen), Kontakt, Logo, Bankverbindung,
 * Rechnungen/Mahnwesen, Kursfristen, E-Mail-Absender und Jahresbudget.
 * Zugangsdaten (Datenbank, Mailserver, FTP) stehen nur in der Serverkonfiguration und werden
 * hier bewusst weder angezeigt noch gespeichert.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/prae.php';
require_once ROOT_PATH . '/includes/einstellungen.php';
require_once ROOT_PATH . '/includes/kommunikation.php';
require_once ROOT_PATH . '/includes/upload.php';

requireDarf('einstellungen.bearbeiten');

$db     = getDB();
$org_id = currentOrgId();
$self   = APP_URL . '/dashboard/admin/einstellungen.php';
$errors = [];
$bereich = '';

$stmt = $db->prepare('SELECT * FROM prae_einstellungen WHERE organization_id = ?');
$stmt->execute([$org_id]);
$stamm = $stmt->fetch() ?: ['vereinsname' => APP_NAME, 'zvr' => '', 'steuernummer' => '', 'strasse' => '', 'plz' => '', 'ort' => '', 'land' => 'AT', 'iban' => '', 'bic' => '', 'verantwortlich' => ''];

/** Stammdatenfelder in prae_einstellungen speichern (tagessatz bleibt unberührt). */
$stammSpeichern = function (array $neu) use ($db, $org_id, $stamm): void {
    $stmt = $db->prepare('SELECT COUNT(*) FROM prae_einstellungen WHERE organization_id = ?');
    $stmt->execute([$org_id]);
    if ($stmt->fetchColumn()) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($neu)));
        $db->prepare("UPDATE prae_einstellungen SET $sets WHERE organization_id = ?")->execute(array_merge(array_values($neu), [$org_id]));
    } else {
        $neu += ['vereinsname' => APP_NAME];
        $spalten = implode(', ', array_keys($neu));
        $db->prepare("INSERT INTO prae_einstellungen ($spalten, organization_id) VALUES (" . implode(', ', array_fill(0, count($neu), '?')) . ', ?)')
           ->execute(array_merge(array_values($neu), [$org_id]));
    }
    $alt = array_intersect_key($stamm, $neu);
    if ($alt != $neu) auditLog('geaendert', 'prae_einstellungen', null, $alt, $neu, 'Vereinsstammdaten');
};

$ganzzahl = function (string $feld, int $min, int $max, string $label) use (&$errors): ?string {
    $v = trim((string)($_POST[$feld] ?? ''));
    if ($v === '' || !ctype_digit($v) || (int)$v < $min || (int)$v > $max) { $errors[$feld] = "$label: bitte eine Zahl von $min bis $max."; return null; }
    return (string)(int)$v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $bereich = $_POST['bereich'] ?? '';

    if ($bereich === 'verein') {
        $zvr = preg_replace('/\D+/', '', $_POST['zvr'] ?? '');
        $stn = preg_replace('/\D+/', '', $_POST['steuernummer'] ?? '');
        $name = trim($_POST['vereinsname'] ?? '');
        if ($name === '') $errors['vereinsname'] = 'Bitte den Vereinsnamen angeben.';
        if ($zvr !== '' && ($f = praeZvrFehler($zvr))) $errors['zvr'] = $f;
        if ($stn !== '' && ($f = praeStnrFehler($stn))) $errors['steuernummer'] = $f;
        $land = strtoupper(trim($_POST['land'] ?? 'AT'));
        if (!preg_match('/^[A-Z]{2}$/', $land)) $errors['land'] = 'Ländercode mit zwei Buchstaben, z.B. AT.';
        if (!$errors) {
            $stammSpeichern([
                'vereinsname' => mb_substr($name, 0, 200), 'zvr' => $zvr !== '' ? str_pad($zvr, 10, '0', STR_PAD_LEFT) : null, 'steuernummer' => $stn ?: null,
                'strasse' => mb_substr(trim($_POST['strasse'] ?? ''), 0, 200) ?: null, 'plz' => mb_substr(trim($_POST['plz'] ?? ''), 0, 10) ?: null,
                'ort' => mb_substr(trim($_POST['ort'] ?? ''), 0, 100) ?: null, 'land' => $land, 'verantwortlich' => mb_substr(trim($_POST['verantwortlich'] ?? ''), 0, 150) ?: null,
            ]);
            flashMessage('success', 'Vereinsdaten gespeichert.');
            redirect($self . '#verein');
        }
    }

    if ($bereich === 'kontakt') {
        $mail = trim($_POST['verein_email'] ?? '');
        $web = trim($_POST['verein_website'] ?? '');
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $errors['verein_email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        if ($web !== '' && !preg_match('#^https?://[^\s<>"]+$#i', $web)) $errors['verein_website'] = 'Die Website muss mit http:// oder https:// beginnen.';
        $logo = null;
        if (!empty($_FILES['logo']['name'])) {
            $up = bildUpload($_FILES['logo'], 'verein');
            if (isset($up['fehler'])) $errors['logo'] = $up['fehler']; else $logo = $up['datei'];
        }
        if (!$errors) {
            einstellungSetzen($db, 'verein_email', $mail);
            einstellungSetzen($db, 'verein_telefon', mb_substr(trim($_POST['verein_telefon'] ?? ''), 0, 40));
            einstellungSetzen($db, 'verein_website', $web);
            if ($logo) einstellungSetzen($db, 'verein_logo', $logo);
            elseif (!empty($_POST['logo_entfernen'])) einstellungSetzen($db, 'verein_logo', '');
            flashMessage('success', 'Kontaktdaten gespeichert.');
            redirect($self . '#kontakt');
        }
    }

    if ($bereich === 'bank') {
        $iban = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $bic = strtoupper(preg_replace('/\s+/', '', $_POST['bic'] ?? ''));
        if ($iban !== '' && !praeIbanGueltig($iban)) $errors['iban'] = 'Die IBAN ist ungültig (Prüfziffer).';
        if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) $errors['bic'] = 'Der BIC hat 8 oder 11 Zeichen.';
        if (!$errors) {
            $stammSpeichern(['iban' => $iban ?: null, 'bic' => $bic ?: null]);
            flashMessage('success', 'Bankverbindung gespeichert.');
            redirect($self . '#bank');
        }
    }

    if ($bereich === 'rechnung') {
        $prefix = strtoupper(trim($_POST['rechnung_prefix'] ?? ''));
        if (!preg_match('/^[A-Z0-9]{1,6}$/', $prefix)) $errors['rechnung_prefix'] = 'Präfix: 1–6 Buchstaben/Ziffern.';
        $ziel = $ganzzahl('rechnung_zahlungsziel', 0, 90, 'Zahlungsziel');
        $m1 = $ganzzahl('mahnung_tage_1', 1, 180, 'Zahlungserinnerung');
        $m2 = $ganzzahl('mahnung_tage_2', 1, 365, '1. Mahnung');
        $m3 = $ganzzahl('mahnung_tage_3', 1, 365, '2. Mahnung');
        if ($m1 && $m2 && $m3 && !((int)$m1 < (int)$m2 && (int)$m2 < (int)$m3)) $errors['mahnung_tage_2'] = 'Die Mahnstufen müssen zeitlich aufeinander folgen (Erinnerung < 1. Mahnung < 2. Mahnung).';
        if (!$errors) {
            foreach (['rechnung_prefix' => $prefix, 'rechnung_zahlungsziel' => $ziel, 'mahnung_tage_1' => $m1, 'mahnung_tage_2' => $m2, 'mahnung_tage_3' => $m3,
                      'rechnung_steuerhinweis' => mb_substr(trim($_POST['rechnung_steuerhinweis'] ?? ''), 0, 500),
                      'rechnung_fusszeile' => mb_substr(trim($_POST['rechnung_fusszeile'] ?? ''), 0, 1000),
                      'mahnung_auto_versand' => !empty($_POST['mahnung_auto_versand']) ? '1' : '0'] as $k => $v) einstellungSetzen($db, $k, $v);
            flashMessage('success', 'Rechnungseinstellungen gespeichert. Bereits ausgestellte Rechnungen bleiben unverändert.');
            redirect($self . '#rechnung');
        }
    }

    if ($bereich === 'kurse') {
        $e = $ganzzahl('erinnerung_stunden', 1, 168, 'Erinnerung');
        $s = $ganzzahl('storno_frist_std', 0, 720, 'Storno-Frist');
        $a = $ganzzahl('anfrage_gueltig_std', 1, 336, 'Bestätigungsfrist');
        if (!$errors) {
            einstellungSetzen($db, 'erinnerung_stunden', $e);
            einstellungSetzen($db, 'storno_frist_std', $s);
            einstellungSetzen($db, 'anfrage_gueltig_std', $a);
            flashMessage('success', 'Kurseinstellungen gespeichert.');
            redirect($self . '#kurse');
        }
    }

    if ($bereich === 'mail') {
        $abs = trim($_POST['mail_absender'] ?? '');
        $name = trim(preg_replace('/[\r\n]+/', ' ', $_POST['mail_absender_name'] ?? ''));
        if (!filter_var($abs, FILTER_VALIDATE_EMAIL)) $errors['mail_absender'] = 'Bitte eine gültige Absenderadresse angeben.';
        if ($name === '') $errors['mail_absender_name'] = 'Bitte einen Absendernamen angeben.';
        if (!$errors) {
            einstellungSetzen($db, 'mail_aktiv', !empty($_POST['mail_aktiv']) ? '1' : '0');
            einstellungSetzen($db, 'mail_absender', $abs);
            einstellungSetzen($db, 'mail_absender_name', mb_substr($name, 0, 100));
            flashMessage('success', 'E-Mail-Einstellungen gespeichert.');
            redirect($self . '#mail');
        }
    }

    if ($bereich === 'testmail') {
        $ich = getCurrentUser();
        $ok = mailSenden($db, $ich['email'], 'Testnachricht ' . APP_NAME, "Diese Testnachricht bestätigt, dass der E-Mail-Versand der Vereinsplattform funktioniert.\n\nGesendet am " . date('d.m.Y H:i') . '.', (int)$ich['id'], 'testmail');
        flashMessage($ok ? 'success' : 'error', $ok ? 'Testnachricht an ' . $ich['email'] . ' übergeben. Bitte Posteingang (und Spam) prüfen.' : 'Die Testnachricht konnte nicht versendet werden – siehe E-Mail-Protokoll.');
        redirect($self . '#mail');
    }

    if ($bereich === 'budget') {
        $werte = [];
        foreach (['budget_einnahmen', 'budget_ausgaben'] as $k) {
            $v = str_replace([' ', '.'], '', trim($_POST[$k] ?? ''));
            $v = str_replace(',', '.', $v);
            if ($v !== '' && (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000000)) $errors[$k] = 'Bitte einen Betrag ≥ 0 angeben.';
            $werte[$k] = $v === '' ? '' : moneyRound($v);
        }
        if (!$errors) {
            foreach ($werte as $k => $v) einstellungSetzen($db, $k, $v);
            flashMessage('success', 'Jahresbudget gespeichert.');
            redirect($self . '#budget');
        }
    }
}

$e = einstellungenAlle(true);
$alt = fn(string $k, $standard = '') => $_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($k, $_POST) ? (string)$_POST[$k] : (string)($e[$k] ?? $stamm[$k] ?? $standard);
$fehler = fn(string $k) => isset($errors[$k]) ? '<div class="form-error">' . e($errors[$k]) . '</div>' : '';
$eingabe = function (string $k, string $label, string $typ = 'text', string $extra = '', string $hilfe = '') use ($alt, $fehler) {
    return '<div class="form-group"><label class="form-label" for="f-' . $k . '">' . e($label) . '</label>'
        . '<input class="form-control' . ($fehler($k) ? ' is-invalid' : '') . '" type="' . $typ . '" id="f-' . $k . '" name="' . $k . '" value="' . e($alt($k)) . '" ' . $extra . '>'
        . ($hilfe ? '<small class="st-hilfe">' . e($hilfe) . '</small>' : '') . $fehler($k) . '</div>';
};
$mail_ok = mailKonfiguriert();

$page_title = 'Einstellungen';
$breadcrumb = 'Einstellungen';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.st-layout { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
.st-menue { position: sticky; top: 90px; display: flex; flex-direction: column; gap: 2px; }
.st-menue a { padding: 0.55rem 0.8rem; border-radius: 8px; color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; }
.st-menue a:hover { background: var(--bg-muted); color: var(--navy-primary); }
.st-karte { scroll-margin-top: 90px; margin-bottom: 1.5rem; }
.st-karte form { padding: 1.25rem; }
.st-raster { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 0 1rem; }
.st-hilfe { display: block; font-size: 0.78rem; color: var(--text-muted); margin-top: 0.25rem; }
.st-info { font-size: 0.85rem; color: var(--text-secondary); padding: 0 1.25rem; margin: 1rem 0 0; }
.st-logo { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
.st-logo img { max-height: 64px; max-width: 180px; background: #fff; border: 1px solid var(--border-light); border-radius: 8px; padding: 6px; }
@media (max-width: 900px) { .st-layout { grid-template-columns: minmax(0, 1fr); } .st-menue { position: static; flex-direction: row; flex-wrap: wrap; } }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Einstellungen</h1>
    <p class="dashboard-subtitle">Zentrale Vereinsdaten und Voreinstellungen für Rechnungen, Mahnwesen, Kurse und E-Mails.</p>
</div>

<?php if ($errors): ?><div class="alert alert-error">Bitte die markierten Felder prüfen.</div><?php endif; ?>

<div class="st-layout">
<nav class="st-menue" aria-label="Bereiche">
    <a href="#verein">Vereinsdaten</a><a href="#kontakt">Kontakt &amp; Logo</a><a href="#bank">Bankverbindung</a>
    <a href="#rechnung">Rechnungen &amp; Mahnwesen</a><a href="#kurse">Kurse &amp; Fristen</a><a href="#mail">E-Mail</a><a href="#budget">Jahresbudget</a>
    <?php if (darf('automatisierungen.bearbeiten')): ?><a href="<?= APP_URL ?>/dashboard/admin/automatisierungen.php">Automatisierungen →</a><?php endif; ?>
</nav>
<div>
    <section class="table-card st-karte" id="verein">
        <div class="table-card-header"><h2 class="table-card-title">Vereinsdaten</h2></div>
        <p class="st-info">Diese Stammdaten erscheinen auf Rechnungen, Berichten und PRAE-Meldungen (eine gemeinsame Quelle).</p>
        <form method="post"><?= csrfField() ?><input type="hidden" name="bereich" value="verein">
            <?= $eingabe('vereinsname', 'Vereinsname', 'text', 'required maxlength="200"') ?>
            <div class="st-raster">
                <?= $eingabe('zvr', 'ZVR-Zahl', 'text', 'inputmode="numeric" maxlength="12"') ?>
                <?= $eingabe('steuernummer', 'Steuernummer', 'text', 'maxlength="15"') ?>
            </div>
            <?= $eingabe('strasse', 'Straße und Hausnummer', 'text', 'maxlength="200"') ?>
            <div class="st-raster">
                <?= $eingabe('plz', 'PLZ', 'text', 'maxlength="10"') ?>
                <?= $eingabe('ort', 'Ort', 'text', 'maxlength="100"') ?>
                <?= $eingabe('land', 'Land (ISO)', 'text', 'maxlength="2"') ?>
            </div>
            <?= $eingabe('verantwortlich', 'Verantwortliche Person (Obfrau/Obmann)', 'text', 'maxlength="150"') ?>
            <button class="btn btn-primary btn-sm">Speichern</button>
        </form>
    </section>

    <section class="table-card st-karte" id="kontakt">
        <div class="table-card-header"><h2 class="table-card-title">Kontakt &amp; Logo</h2></div>
        <form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="bereich" value="kontakt">
            <div class="st-raster">
                <?= $eingabe('verein_email', 'Kontakt-E-Mail', 'email', 'maxlength="150"') ?>
                <?= $eingabe('verein_telefon', 'Telefon', 'tel', 'maxlength="40"') ?>
            </div>
            <?= $eingabe('verein_website', 'Website', 'url', 'maxlength="200" placeholder="https://"') ?>
            <div class="st-logo">
                <?php if ($e['verein_logo']): ?><img src="<?= APP_URL ?>/api/bild.php?logo=1&amp;v=<?= e(md5($e['verein_logo'])) ?>" alt="Aktuelles Logo"><?php else: ?><span class="st-hilfe">Kein eigenes Logo – verwendet wird die Bildmarke der Website.</span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="f-logo">Logo hochladen (PNG oder JPG, für PDFs)</label>
                <input class="form-control" type="file" id="f-logo" name="logo" accept="image/png,image/jpeg">
                <?= $fehler('logo') ?>
            </div>
            <?php if ($e['verein_logo']): ?><label class="form-check" style="margin-bottom: 1rem;"><input type="checkbox" name="logo_entfernen" value="1"><span class="form-check-label">Eigenes Logo entfernen</span></label><?php endif; ?>
            <button class="btn btn-primary btn-sm">Speichern</button>
        </form>
    </section>

    <section class="table-card st-karte" id="bank">
        <div class="table-card-header"><h2 class="table-card-title">Bankverbindung</h2></div>
        <form method="post"><?= csrfField() ?><input type="hidden" name="bereich" value="bank">
            <div class="st-raster">
                <?= $eingabe('iban', 'IBAN', 'text', 'maxlength="42" autocomplete="off"', 'Erscheint auf Rechnungen und Zahlungserinnerungen.') ?>
                <?= $eingabe('bic', 'BIC', 'text', 'maxlength="11" autocomplete="off"') ?>
            </div>
            <button class="btn btn-primary btn-sm">Speichern</button>
        </form>
    </section>

    <section class="table-card st-karte" id="rechnung">
        <div class="table-card-header"><h2 class="table-card-title">Rechnungen &amp; Mahnwesen</h2></div>
        <form method="post"><?= csrfField() ?><input type="hidden" name="bereich" value="rechnung">
            <div class="st-raster">
                <?= $eingabe('rechnung_prefix', 'Nummernpräfix', 'text', 'maxlength="6"', 'Nummer: ' . ($e['rechnung_prefix'] ?: 'RE') . '-' . date('Y') . '-0001 (fortlaufend je Jahr)') ?>
                <?= $eingabe('rechnung_zahlungsziel', 'Zahlungsziel (Tage)', 'number', 'min="0" max="90"') ?>
            </div>
            <div class="form-group"><label class="form-label" for="f-rechnung_steuerhinweis">Steuerhinweis</label>
                <textarea class="form-control" id="f-rechnung_steuerhinweis" name="rechnung_steuerhinweis" rows="2" maxlength="500"><?= e($alt('rechnung_steuerhinweis')) ?></textarea>
                <small class="st-hilfe">Z.B. Hinweis auf Umsatzsteuerbefreiung – Formulierung bitte mit der Steuerberatung abstimmen.</small></div>
            <div class="form-group"><label class="form-label" for="f-rechnung_fusszeile">Zusatztext unten auf Rechnungen</label>
                <textarea class="form-control" id="f-rechnung_fusszeile" name="rechnung_fusszeile" rows="2" maxlength="1000"><?= e($alt('rechnung_fusszeile')) ?></textarea></div>
            <div class="st-raster">
                <?= $eingabe('mahnung_tage_1', 'Zahlungserinnerung nach … Tagen', 'number', 'min="1" max="180"') ?>
                <?= $eingabe('mahnung_tage_2', '1. Mahnung nach … Tagen', 'number', 'min="1" max="365"') ?>
                <?= $eingabe('mahnung_tage_3', '2. Mahnung nach … Tagen', 'number', 'min="1" max="365"') ?>
            </div>
            <label class="form-check" style="margin-bottom: 0.4rem;"><input type="checkbox" name="mahnung_auto_versand" value="1" <?= $alt('mahnung_auto_versand') === '1' ? 'checked' : '' ?>><span class="form-check-label">Mahnungen automatisch versenden</span></label>
            <small class="st-hilfe" style="margin-bottom: 1rem;">Ohne Häkchen werden Mahnungen nur vorbereitet und im Handlungsbedarf zur Freigabe angezeigt. Jede Stufe wird höchstens einmal versendet.</small>
            <button class="btn btn-primary btn-sm">Speichern</button>
        </form>
    </section>

    <section class="table-card st-karte" id="kurse">
        <div class="table-card-header"><h2 class="table-card-title">Kurse &amp; Fristen</h2></div>
        <form method="post"><?= csrfField() ?><input type="hidden" name="bereich" value="kurse">
            <div class="st-raster">
                <?= $eingabe('erinnerung_stunden', 'Erinnerung vor Kursbeginn (Std.)', 'number', 'min="1" max="168"') ?>
                <?= $eingabe('storno_frist_std', 'Selbst-Storno bis (Std. vorher)', 'number', 'min="0" max="720"', 'Standard, je Kurs überschreibbar.') ?>
                <?= $eingabe('anfrage_gueltig_std', 'Bestätigungsfrist öffentl. Anmeldung (Std.)', 'number', 'min="1" max="336"') ?>
            </div>
            <button class="btn btn-primary btn-sm">Speichern</button>
        </form>
    </section>

    <section class="table-card st-karte" id="mail">
        <div class="table-card-header"><h2 class="table-card-title">E-Mail</h2><span class="badge <?= $mail_ok ? 'badge-success' : 'badge-warning' ?>"><?= $mail_ok ? 'Versand bereit' : 'Versand inaktiv' ?></span></div>
        <p class="st-info">Der Mailserver-Zugang wird in der Serverkonfiguration verwaltet und hier aus Sicherheitsgründen nicht angezeigt.</p>
        <form method="post"><?= csrfField() ?><input type="hidden" name="bereich" value="mail">
            <label class="form-check" style="margin-bottom: 1rem;"><input type="checkbox" name="mail_aktiv" value="1" <?= $alt('mail_aktiv') === '1' ? 'checked' : '' ?>><span class="form-check-label">E-Mail-Versand aktiv</span></label>
            <div class="st-raster">
                <?= $eingabe('mail_absender', 'Absenderadresse', 'email', 'required maxlength="150"') ?>
                <?= $eingabe('mail_absender_name', 'Absendername', 'text', 'required maxlength="100"') ?>
            </div>
            <button class="btn btn-primary btn-sm">Speichern</button>
        </form>
        <form method="post" style="padding-top: 0;"><?= csrfField() ?><input type="hidden" name="bereich" value="testmail">
            <button class="btn btn-ghost-light btn-sm" <?= $mail_ok ? '' : 'disabled' ?>>Testnachricht an mich senden</button>
        </form>
    </section>

    <section class="table-card st-karte" id="budget">
        <div class="table-card-header"><h2 class="table-card-title">Jahresbudget (Soll)</h2></div>
        <p class="st-info">Grundlage für den Soll-Ist-Vergleich im Management-Cockpit. Leer lassen, wenn kein Budget beschlossen ist.</p>
        <form method="post"><?= csrfField() ?><input type="hidden" name="bereich" value="budget">
            <div class="st-raster">
                <?= $eingabe('budget_einnahmen', 'Geplante Einnahmen (€)', 'text', 'inputmode="decimal"') ?>
                <?= $eingabe('budget_ausgaben', 'Geplante Ausgaben (€)', 'text', 'inputmode="decimal"') ?>
            </div>
            <button class="btn btn-primary btn-sm">Speichern</button>
        </form>
    </section>
</div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
