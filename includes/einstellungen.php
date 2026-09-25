<?php
/**
 * Athletikclub Steiermark – zentrale Einstellungen & Vereinsstammdaten
 *
 * Vereinsstammdaten (Name, ZVR, Steuernummer, Adresse, IBAN/BIC) bleiben in
 * `prae_einstellungen` – dort werden sie bereits für PRAE/L19 verwendet.
 * Alle weiteren Einstellungen liegen als Schlüssel/Wert in `einstellungen`.
 * Zugriff ausschließlich über verein() / einstellung(), damit nichts doppelt gepflegt wird.
 */

/** Standardwerte aller Einstellungen (Schlüssel => [Standard, Beschreibung]). */
const EINSTELLUNGEN_STANDARD = [
    'verein_email'             => [MAIL_ADMIN, 'Kontakt-E-Mail des Vereins'],
    'verein_telefon'           => ['', 'Telefonnummer'],
    'verein_website'           => ['', 'Website'],
    'verein_logo'              => ['', 'Logo-Datei (uploads/images)'],
    'rechnung_prefix'          => ['RE', 'Präfix der Rechnungsnummer'],
    'rechnung_zahlungsziel'    => ['14', 'Zahlungsziel in Tagen'],
    'rechnung_steuerhinweis'   => ['', 'Steuerhinweis auf Rechnungen (z.B. Befreiung) – bitte mit Steuerberatung abstimmen'],
    'rechnung_fusszeile'       => ['', 'Zusatztext unten auf Rechnungen'],
    'mahnung_tage_1'           => ['7', 'Zahlungserinnerung X Tage nach Fälligkeit'],
    'mahnung_tage_2'           => ['21', '1. Mahnung X Tage nach Fälligkeit'],
    'mahnung_tage_3'           => ['35', '2. Mahnung X Tage nach Fälligkeit'],
    'mahnung_auto_versand'     => ['0', 'Mahnungen automatisch versenden (sonst nur vorbereiten)'],
    'erinnerung_stunden'       => ['24', 'Kurs-Erinnerung X Stunden vor Beginn'],
    'storno_frist_std'         => ['24', 'Selbst-Storno bis X Stunden vor Kursbeginn'],
    'anfrage_gueltig_std'      => ['48', 'Öffentliche Anmeldung muss innerhalb X Stunden bestätigt werden'],
    'mail_aktiv'               => ['1', 'E-Mail-Versand aktiv'],
    'mail_absender'            => [MAIL_FROM, 'Absenderadresse'],
    'mail_absender_name'       => [MAIL_FROM_NAME, 'Absendername'],
    'cron_schluessel'          => ['', 'Geheimer Schlüssel für cron.php'],
    'budget_einnahmen'         => ['', 'Jahresbudget Einnahmen (Soll)'],
    'budget_ausgaben'          => ['', 'Jahresbudget Ausgaben (Soll)'],
];

/** Alle Einstellungen der Organisation (gecacht je Anfrage). */
function einstellungenAlle(bool $neu_laden = false): array
{
    static $cache = null;
    if ($cache !== null && !$neu_laden) return $cache;
    $cache = array_map(fn($x) => $x[0], EINSTELLUNGEN_STANDARD);
    try {
        $stmt = getDB()->prepare('SELECT schluessel, wert FROM einstellungen WHERE organization_id = ?');
        $stmt->execute([currentOrgId()]);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $cache[$k] = $v;
    } catch (Exception $e) {
        // Migration 012 noch nicht eingespielt → Standardwerte
    }
    return $cache;
}

function einstellung(string $schluessel, ?string $standard = null): ?string
{
    $alle = einstellungenAlle();
    return array_key_exists($schluessel, $alle) && $alle[$schluessel] !== null ? (string)$alle[$schluessel] : $standard;
}

function einstellungSetzen(PDO $db, string $schluessel, ?string $wert): void
{
    $alt = einstellung($schluessel);
    $stmt = $db->prepare('SELECT 1 FROM einstellungen WHERE organization_id = ? AND schluessel = ?');
    $stmt->execute([currentOrgId(), $schluessel]);
    if ($stmt->fetchColumn()) $db->prepare('UPDATE einstellungen SET wert = ? WHERE organization_id = ? AND schluessel = ?')->execute([$wert, currentOrgId(), $schluessel]);
    else $db->prepare('INSERT INTO einstellungen (organization_id, schluessel, wert) VALUES (?, ?, ?)')->execute([currentOrgId(), $schluessel, $wert]);
    einstellungenAlle(true);
    if ($alt !== $wert && function_exists('auditLog') && $schluessel !== 'cron_schluessel') {
        auditLog('geaendert', 'einstellungen', null, [$schluessel => $alt], [$schluessel => $wert], 'Einstellung ' . $schluessel);
    }
}

/** Vereinsstammdaten für Rechnungen, PDFs, E-Mails, öffentliche Seiten. */
function verein(): array
{
    static $v = null;
    if ($v !== null) return $v;
    $basis = ['vereinsname' => APP_NAME, 'zvr' => defined('VEREIN_ZVR') ? VEREIN_ZVR : '', 'steuernummer' => null,
              'strasse' => null, 'plz' => null, 'ort' => null, 'land' => 'AT', 'iban' => null, 'bic' => null, 'verantwortlich' => null];
    try {
        $stmt = getDB()->prepare('SELECT * FROM prae_einstellungen WHERE organization_id = ?');
        $stmt->execute([currentOrgId()]);
        if ($row = $stmt->fetch()) $basis = array_merge($basis, array_filter($row, fn($x) => $x !== null && $x !== ''));
    } catch (Exception $e) {}
    $e = einstellungenAlle();
    $v = $basis + [
        'email'    => $e['verein_email'] ?: MAIL_ADMIN,
        'telefon'  => $e['verein_telefon'],
        'website'  => $e['verein_website'] ?: (defined('SITE_URL') ? SITE_URL : APP_URL),
        'logo'     => $e['verein_logo'],
    ];
    $v['adresse_zeile'] = trim(($v['strasse'] ?? '') . (($v['plz'] ?? '') || ($v['ort'] ?? '') ? ', ' . trim(($v['plz'] ?? '') . ' ' . ($v['ort'] ?? '')) : ''), ' ,');
    return $v;
}

/** IBAN lesbar in Vierergruppen. */
function ibanFormat(?string $iban): string
{
    $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
    return trim(chunk_split($iban, 4, ' '));
}
