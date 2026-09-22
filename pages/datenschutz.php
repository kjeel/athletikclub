<?php
/**
 * Athletikclub Steiermark – Datenschutzerklärung
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title       = 'Datenschutz';
$meta_description = 'Datenschutzerklärung des Athletikclub Steiermark gemäß DSGVO.';
require_once ROOT_PATH . '/includes/header.php';

$sec_style = "font-family: 'Montserrat', sans-serif; font-size: 1.1rem; font-weight: 800; text-transform: uppercase; margin-bottom: 1rem;";
$sub_style = "font-family: 'Montserrat', sans-serif; font-size: 0.95rem; font-weight: 700; margin: 1.25rem 0 0.5rem;";
?>

<!-- Page Header -->
<section class="page-header">
    <div class="page-header-pattern"></div>
    <div class="container page-header-content">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= APP_URL ?>/">Start</a>
            <span class="breadcrumb-sep">›</span>
            <span class="breadcrumb-current">Datenschutz</span>
        </nav>
        <h1>Datenschutzerklärung</h1>
        <p>Stand: <?= date('d.m.Y') ?></p>
    </div>
</section>

<section class="section bg-white">
    <div class="container" style="max-width: 760px;">

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">1. Verantwortlicher</h2>
                <p style="line-height: 1.8; margin: 0;">
                    Verantwortlicher im Sinne der Datenschutz-Grundverordnung (DSGVO) ist:<br><br>
                    <strong>Athletikclub Steiermark</strong><br>
                    Sankt Georgen an der Stiefing 14<br>
                    8413 Sankt Georgen an der Stiefing<br>
                    ZVR-Zahl: 1545056798<br>
                    E-Mail: <a href="mailto:office@athletikclub-steiermark.at">office@athletikclub-steiermark.at</a><br>
                    Telefon: <a href="tel:+436648828950">+43 664 882 895 00</a>
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">2. Hosting &amp; Server-Logfiles</h2>
                <p style="line-height: 1.8;">
                    Diese Website wird bei Hetzner Online GmbH gehostet. Beim Aufruf der Website
                    erhebt unser Hosting-Anbieter automatisch technische Zugriffsdaten
                    (sogenannte Server-Logfiles), die Ihr Browser automatisch übermittelt: IP-Adresse,
                    Datum und Uhrzeit der Anfrage, aufgerufene Seite, verwendeter Browser und
                    Betriebssystem, Referrer-URL.
                </p>
                <p style="line-height: 1.8; margin: 0;">
                    Diese Daten dienen ausschließlich der technischen Bereitstellung und Absicherung
                    der Website (Art. 6 Abs. 1 lit. f DSGVO) und werden nicht mit anderen Datenquellen
                    zusammengeführt.
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">3. Cookies &amp; Session</h2>
                <p style="line-height: 1.8; margin: 0;">
                    Beim Login in den Mitgliederbereich setzen wir ein technisch notwendiges
                    Session-Cookie (PHPSESSID), um Sie während Ihres Besuchs eingeloggt zu halten.
                    Dieses Cookie wird beim Schließen des Browsers bzw. Logout gelöscht und dient
                    ausschließlich der Funktionsfähigkeit der Website (Art. 6 Abs. 1 lit. f DSGVO,
                    berechtigtes Interesse an einer funktionierenden Website). Es findet keine
                    Auswertung zu Marketingzwecken statt.
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">4. Registrierung &amp; Mitgliederbereich</h2>
                <p style="line-height: 1.8;">
                    Wenn Sie sich als Mitglied registrieren, verarbeiten wir folgende Daten zur
                    Verwaltung Ihrer Mitgliedschaft und zur Abwicklung des Trainingsbetriebs
                    (Art. 6 Abs. 1 lit. b DSGVO, Vertragserfüllung):
                </p>
                <ul style="line-height: 1.9; padding-left: 1.25rem;">
                    <li>Vor- und Nachname, E-Mail-Adresse, Passwort (verschlüsselt gespeichert)</li>
                    <li>Optional: Geburtsdatum, Telefonnummer, Adresse, ausgeübte Sportarten</li>
                    <li>Kursanmeldungen, Anwesenheits- und Zahlungsstatus</li>
                    <li>Von Trainer*innen erfasste Fortschrittsnotizen zu Ihrem Training</li>
                    <li>Von Ihnen bzw. für Sie hochgeladene Dokumente (z. B. Trainingspläne, Befunde)</li>
                </ul>
                <p style="line-height: 1.8; margin: 0;">
                    Für Trainer*innen speichern wir zusätzlich Qualifikationen, Bio-Text und
                    Sportarten zur Darstellung auf der öffentlichen Team-Seite sowie
                    Abrechnungsdaten zur Provisionsauszahlung.
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">5. Kontaktformular &amp; Bewerbungen</h2>
                <p style="line-height: 1.8; margin: 0;">
                    Wenn Sie uns über das Kontaktformular oder die Trainer-Bewerbung kontaktieren,
                    speichern wir die von Ihnen angegebenen Daten (Name, E-Mail-Adresse, Nachricht)
                    zur Bearbeitung Ihrer Anfrage (Art. 6 Abs. 1 lit. b bzw. f DSGVO). Diese Daten
                    werden gelöscht, sobald die Anfrage abschließend bearbeitet ist und keine
                    gesetzlichen Aufbewahrungspflichten entgegenstehen.
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">6. Aktivitätsprotokoll</h2>
                <p style="line-height: 1.8; margin: 0;">
                    Zu Sicherheits- und Nachvollziehbarkeitszwecken (z. B. bei Änderungen an
                    Finanz- oder Nutzerdaten) protokollieren wir bestimmte Aktionen eingeloggter
                    Nutzer*innen inklusive IP-Adresse und Zeitstempel (Art. 6 Abs. 1 lit. f DSGVO,
                    berechtigtes Interesse an Missbrauchsprävention und Rechenschaftspflicht).
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">7. Analyse-Tools (Google Analytics)</h2>
                <p style="line-height: 1.8; margin: 0;">
                    <strong>Aktueller Stand: Auf dieser Website ist derzeit kein Tracking- oder
                    Analyse-Tool aktiv.</strong> Sollten wir zukünftig Google Analytics oder ein
                    vergleichbares Tool einsetzen, um die Nutzung unserer Website statistisch
                    auszuwerten, werden wir dies ausschließlich auf Basis Ihrer ausdrücklichen
                    Einwilligung (Art. 6 Abs. 1 lit. a DSGVO) über einen Cookie-Consent-Banner tun
                    und diesen Abschnitt vor der Aktivierung um die dann geltenden Details
                    (eingesetzter Anbieter, Zweck, Speicherdauer, Widerrufsmöglichkeit) ergänzen.
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">8. Weitergabe von Daten</h2>
                <p style="line-height: 1.8; margin: 0;">
                    Eine Übermittlung Ihrer Daten an Dritte erfolgt nur, soweit dies zur
                    Vertragsabwicklung notwendig ist (z. B. an unseren Hosting-Anbieter Hetzner
                    Online GmbH als Auftragsverarbeiter) oder wir gesetzlich dazu verpflichtet sind.
                    Eine Weitergabe zu Werbezwecken findet nicht statt.
                </p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">9. Speicherdauer</h2>
                <p style="line-height: 1.8; margin: 0;">
                    Wir speichern personenbezogene Daten nur so lange, wie dies für die genannten
                    Zwecke erforderlich ist oder gesetzliche Aufbewahrungsfristen dies verlangen.
                    Mitgliedsdaten werden nach Beendigung der Mitgliedschaft gelöscht, sofern keine
                    steuer- oder vereinsrechtlichen Aufbewahrungspflichten entgegenstehen.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 style="<?= $sec_style ?>">10. Ihre Rechte</h2>
                <p style="line-height: 1.8;">Sie haben das Recht auf:</p>
                <ul style="line-height: 1.9; padding-left: 1.25rem; margin-bottom: 1.25rem;">
                    <li>Auskunft über die zu Ihrer Person gespeicherten Daten (Art. 15 DSGVO)</li>
                    <li>Berichtigung unrichtiger Daten (Art. 16 DSGVO)</li>
                    <li>Löschung Ihrer Daten (Art. 17 DSGVO)</li>
                    <li>Einschränkung der Verarbeitung (Art. 18 DSGVO)</li>
                    <li>Datenübertragbarkeit (Art. 20 DSGVO)</li>
                    <li>Widerspruch gegen die Verarbeitung (Art. 21 DSGVO)</li>
                </ul>
                <p style="line-height: 1.8; margin: 0;">
                    Wenden Sie sich dazu formlos an
                    <a href="mailto:office@athletikclub-steiermark.at">office@athletikclub-steiermark.at</a>.
                    Zudem haben Sie das Recht, sich bei der österreichischen Datenschutzbehörde
                    (<a href="https://www.dsb.gv.at" target="_blank" rel="noopener">www.dsb.gv.at</a>)
                    zu beschweren.
                </p>
            </div>
        </div>

    </div>
</section>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
