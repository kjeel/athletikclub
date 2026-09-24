<?php
/**
 * Athletikclub Steiermark – PRAE (pauschale Reiseaufwandsentschädigung)
 *
 * Rechtsgrundlagen: § 3 Abs. 1 Z 16c EStG 1988, § 49 Abs. 3 Z 28 ASVG,
 * LStR 2002 Rz 92k, VereinsR 2001 Rz 762 ff., BMF-Leitfaden „Sportler/innen-
 * Begünstigung“ (Okt. 2023). Meldung an das Finanzamt: Formular L 19 über ELDA
 * bis Ende Februar des Folgejahres (XML nach BMF-Schema L19-202302).
 */

require_once ROOT_PATH . '/includes/money.php';

/** Höchstbeträge ab 1.1.2023 (Stand 2026 unverändert). */
const PRAE_TAG_MAX   = '120.00';
const PRAE_MONAT_MAX = '720.00';

const PRAE_ROLLEN = [
    'trainer'        => 'Trainer:in',
    'uebungsleiter'  => 'Übungsleiter:in / Instruktor:in',
    'sportler'       => 'Sportler:in',
    'schiedsrichter' => 'Schieds- / Kampfrichter:in',
    'betreuer'       => 'Sportbetreuer:in (z.B. Masseur:in, Zeugwart:in, Sportarzt/-ärztin)',
];

const PRAE_ARTEN = [
    'training'    => 'Training',
    'wettkampf'   => 'Wettkampf',
    'fortbildung' => 'Fortbildung (aktiv)',
];

const PRAE_MONATE = [1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

const PRAE_STATUS = [
    'entwurf'     => ['label' => 'Entwurf',     'class' => 'badge-gray'],
    'freigegeben' => ['label' => 'Freigegeben', 'class' => 'badge-info'],
    'ausbezahlt'  => ['label' => 'Ausbezahlt',  'class' => 'badge-success'],
];

const PRAE_L19_NS  = 'urn:bmf.gv.at:efsz:l19:202302';
const PRAE_L19_XSD = ROOT_PATH . '/includes/xsd/L19-202302.xsd';

/** Gültige Finanzamtsnummern (erste zwei Ziffern der Steuernummer) laut ELDA-Prüfkatalog L19. */
const PRAE_FA_NUMMERN = ['03', '04', '06', '07', '08', '09', '11', '12', '15', '16', '18', '22', '23', '29', '33', '38', '41', '46', '51', '52',
                         '53', '54', '57', '59', '61', '65', '67', '68', '69', '71', '72', '81', '82', '83', '84', '90', '91', '93', '97', '98'];

/** Pflichterklärungen, die die Monatsabrechnung ausweist. */
const PRAE_ERKLAERUNGEN = [
    'Die Beträge wurden ausschließlich für Einsatztage (Training, Wettkampf oder Fortbildung mit aktiver körperlicher Betätigung) gewährt.',
    'Die Tätigkeit für den Verein ist nicht mein Hauptberuf und nicht die Hauptquelle meiner Einnahmen.',
    'Im selben Monat erhalte ich vom Verein keine sonstigen Reisekostenersätze (z.B. Kilometergeld, Tages- oder Nächtigungsgelder) und kein Freiwilligenpauschale.',
];

// ----------------------------------------------------------------
// Stammdaten
// ----------------------------------------------------------------

function praeEinstellungen(PDO $db): array
{
    $stmt = $db->prepare('SELECT * FROM prae_einstellungen WHERE organization_id = ?');
    $stmt->execute([currentOrgId()]);
    return $stmt->fetch() ?: [
        'organization_id' => currentOrgId(), 'vereinsname' => APP_NAME, 'zvr' => defined('VEREIN_ZVR') ? VEREIN_ZVR : '',
        'steuernummer' => null, 'strasse' => null, 'plz' => null, 'ort' => null, 'land' => 'AT',
        'tagessatz' => '40.00', 'iban' => null, 'bic' => null, 'verantwortlich' => null,
    ];
}

function praeEmpfaenger(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM prae_empfaenger WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

function praeTagessatz(array $empfaenger, array $einstellungen): string
{
    return moneyRound($empfaenger['tagessatz'] !== null && $empfaenger['tagessatz'] !== '' ? $empfaenger['tagessatz'] : $einstellungen['tagessatz']);
}

function praeName(array $e): string
{
    return trim($e['vorname'] . ' ' . $e['nachname']);
}

// ----------------------------------------------------------------
// Prüfungen (laut ELDA-Prüfkatalog L19, Version 202302)
// ----------------------------------------------------------------

/** Sozialversicherungsnummer: 10 Ziffern, Geburtsdatum TTMMJJ, Prüfziffer an Stelle 4. Liefert Fehlertext oder null. */
function praeSvnrFehler(?string $svnr): ?string
{
    $svnr = preg_replace('/\s+/', '', (string)$svnr);
    if (!preg_match('/^[1-9]\d{9}$/', $svnr)) return 'Die SV-Nummer muss 10-stellig sein und darf nicht mit 0 beginnen.';
    $tag = (int)substr($svnr, 4, 2);
    $monat = (int)substr($svnr, 6, 2);
    if ($tag < 1 || $tag > 31 || $monat < 1 || $monat > 16) return 'Das Geburtsdatum in der SV-Nummer (Stellen 5–10, TTMMJJ) ist ungültig.';
    $gewichte = [3, 7, 9, 0, 5, 8, 4, 2, 1, 6];
    $summe = 0;
    foreach ($gewichte as $i => $g) $summe += (int)$svnr[$i] * $g;
    $pruefziffer = $summe % 11;
    if ($pruefziffer === 10 || $pruefziffer !== (int)$svnr[3]) return 'Die Prüfziffer der SV-Nummer stimmt nicht – bitte mit der e-card vergleichen.';
    return null;
}

/** Steuernummer des Vereins: 9 Ziffern (FA-Nr. + 7), Prüfziffer. Liefert Fehlertext oder null. */
function praeStnrFehler(?string $stn): ?string
{
    $stn = preg_replace('/\D+/', '', (string)$stn);
    if (!preg_match('/^\d{9}$/', $stn)) return 'Die Steuernummer muss 9-stellig sein (Finanzamtsnummer + 7 Ziffern, z.B. 68 123/4567 → 681234567).';
    if (!in_array(substr($stn, 0, 2), PRAE_FA_NUMMERN, true)) return 'Die Finanzamtsnummer (erste zwei Ziffern) ist ungültig.';
    $summe = 0;
    for ($i = 0; $i < 8; $i++) {
        $z = (int)$stn[$i];
        if ($i % 2 === 1) { $z *= 2; $z = intdiv($z, 10) + $z % 10; }
        $summe += $z;
    }
    return (10 - $summe % 10) % 10 === (int)$stn[8] ? null : 'Die Prüfziffer der Steuernummer ist ungültig.';
}

function praeZvrFehler(?string $zvr): ?string
{
    return preg_match('/^\d{9,10}$/', preg_replace('/\D+/', '', (string)$zvr)) ? null : 'Die ZVR-Zahl muss 9- oder 10-stellig sein.';
}

/** IBAN-Prüfung (ISO 13616, Modulo 97). */
function praeIbanGueltig(?string $iban): bool
{
    $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) return false;
    if (str_starts_with($iban, 'AT') && strlen($iban) !== 20) return false;
    $umgestellt = substr($iban, 4) . substr($iban, 0, 4);
    $zahl = '';
    foreach (str_split($umgestellt) as $c) $zahl .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
    return bcmod($zahl, '97') === '1';
}

function praeIbanFormat(?string $iban): string
{
    return trim(chunk_split(strtoupper(preg_replace('/\s+/', '', (string)$iban)), 4, ' '));
}

/** Vollständigkeit einer Empfänger:in für die L19-Meldung. */
function praeEmpfaengerHinweise(array $e): array
{
    $h = [];
    if ($e['svnr']) {
        if ($f = praeSvnrFehler($e['svnr'])) $h[] = ['typ' => 'fehler', 'text' => $f];
    } elseif (!$e['geburtsdatum'] || !$e['strasse'] || !$e['plz'] || !$e['ort']) {
        $h[] = ['typ' => 'fehler', 'text' => 'Ohne SV-Nummer sind Geburtsdatum und vollständige Wohnanschrift Pflicht (L19).'];
    }
    if (!(int)$e['kein_hauptberuf']) $h[] = ['typ' => 'warnung', 'text' => 'Nicht bestätigt, dass die Tätigkeit weder Hauptberuf noch Haupteinnahmequelle ist.'];
    if ((int)$e['andere_bezuege']) $h[] = ['typ' => 'warnung', 'text' => 'Erhält auch Lohn/Gehalt vom Verein: PRAE gehören auf den Lohnzettel L16 (Lohnverrechnung), kein L19.'];
    if ($e['iban'] && !praeIbanGueltig($e['iban'])) $h[] = ['typ' => 'warnung', 'text' => 'Die IBAN ist ungültig.'];
    return $h;
}

// ----------------------------------------------------------------
// Berechnung
// ----------------------------------------------------------------

/**
 * Monatsberechnung: je Einsatztag höchstens 120 €, im Monat höchstens 720 € steuerfrei.
 * Nur der übersteigende Betrag ist steuer- (und ggf. sozialversicherungs-)pflichtig.
 */
function praeMonatBerechnen(array $einsaetze): array
{
    $gesamt = $tagesbegrenzt = [];
    $tage_ueber = 0;
    foreach ($einsaetze as $e) {
        $b = moneyRound($e['betrag']);
        $gesamt[] = $b;
        if (bccomp($b, PRAE_TAG_MAX, 2) > 0) { $tage_ueber++; $b = PRAE_TAG_MAX; }
        $tagesbegrenzt[] = $b;
    }
    $gesamt = moneySum($gesamt);
    $tagesbegrenzt = moneySum($tagesbegrenzt);
    $steuerfrei = bccomp($tagesbegrenzt, PRAE_MONAT_MAX, 2) > 0 ? PRAE_MONAT_MAX : $tagesbegrenzt;
    return [
        'tage'        => count($einsaetze),
        'gesamt'      => $gesamt,
        'steuerfrei'  => $steuerfrei,
        'ueberschuss' => bcsub($gesamt, $steuerfrei, 2),
        'tage_ueber'  => $tage_ueber,
        'monat_ueber' => bccomp($tagesbegrenzt, PRAE_MONAT_MAX, 2) > 0,
    ];
}

/** Einsätze einer Person in einem Monat. */
function praeEinsaetze(PDO $db, int $empfaenger_id, int $jahr, int $monat): array
{
    $von = sprintf('%04d-%02d-01', $jahr, $monat);
    $stmt = $db->prepare('SELECT * FROM prae_einsaetze WHERE empfaenger_id = ? AND organization_id = ? AND datum BETWEEN ? AND ? ORDER BY datum');
    $stmt->execute([$empfaenger_id, currentOrgId(), $von, date('Y-m-t', strtotime($von))]);
    return $stmt->fetchAll();
}

/** Monatsabrechnung aus den Einsätzen anlegen bzw. aktualisieren (nur solange nicht ausbezahlt). */
function praeAbrechnungAktualisieren(PDO $db, int $empfaenger_id, int $jahr, int $monat): ?int
{
    $stmt = $db->prepare('SELECT * FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? AND monat = ?');
    $stmt->execute([$empfaenger_id, $jahr, $monat]);
    $abr = $stmt->fetch();
    if ($abr && $abr['status'] === 'ausbezahlt') return (int)$abr['id'];

    $einsaetze = praeEinsaetze($db, $empfaenger_id, $jahr, $monat);
    if (!$einsaetze && !$abr) return null;
    if (!$einsaetze && $abr) {
        $db->prepare('DELETE FROM prae_abrechnungen WHERE id = ?')->execute([$abr['id']]);
        return null;
    }
    $b = praeMonatBerechnen($einsaetze);
    if ($abr) {
        // Geänderte Einsätze setzen Freigabe und Bestätigung zurück
        $geaendert = (int)$abr['einsatztage'] !== $b['tage'] || bccomp(moneyRound($abr['betrag_gesamt']), $b['gesamt'], 2) !== 0;
        $db->prepare('UPDATE prae_abrechnungen SET einsatztage = ?, betrag_gesamt = ?, betrag_steuerfrei = ?, betrag_ueberschuss = ?, status = ?, bestaetigt_am = ? WHERE id = ?')
           ->execute([$b['tage'], $b['gesamt'], $b['steuerfrei'], $b['ueberschuss'],
                      $geaendert ? 'entwurf' : $abr['status'], $geaendert ? null : $abr['bestaetigt_am'], $abr['id']]);
        $id = (int)$abr['id'];
    } else {
        $db->prepare('INSERT INTO prae_abrechnungen (organization_id, empfaenger_id, jahr, monat, einsatztage, betrag_gesamt, betrag_steuerfrei, betrag_ueberschuss, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([currentOrgId(), $empfaenger_id, $jahr, $monat, $b['tage'], $b['gesamt'], $b['steuerfrei'], $b['ueberschuss'], getCurrentUserId()]);
        $id = (int)$db->lastInsertId();
    }
    $db->prepare('UPDATE prae_einsaetze SET abrechnung_id = ? WHERE empfaenger_id = ? AND datum BETWEEN ? AND ?')
       ->execute([$id, $empfaenger_id, sprintf('%04d-%02d-01', $jahr, $monat), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $jahr, $monat)))]);
    return $id;
}

/** Ist der Monat noch offen für Änderungen an Einsätzen? */
function praeMonatOffen(PDO $db, int $empfaenger_id, string $datum): bool
{
    $stmt = $db->prepare("SELECT status FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? AND monat = ?");
    $stmt->execute([$empfaenger_id, (int)substr($datum, 0, 4), (int)substr($datum, 5, 2)]);
    return $stmt->fetchColumn() !== 'ausbezahlt';
}

// ----------------------------------------------------------------
// L19-Jahresmeldung
// ----------------------------------------------------------------

/** Referenznummer: je Empfänger:in und Jahr eindeutig und für Korrektur/Storno stabil. */
function praeRefnr(int $empfaenger_id, int $jahr): string
{
    return sprintf('ACI-PRAE-%d-%05d', $jahr, $empfaenger_id);
}

/**
 * Jahresdaten je Empfänger:in: ausbezahlte steuerfreie PRAE, Zeitraum (erster bis
 * letzter Monat mit Auszahlung) und Prüfergebnis für die L19-Meldung.
 */
function praeJahresdaten(PDO $db, int $jahr): array
{
    $stmt = $db->prepare("SELECT a.*, e.vorname, e.nachname FROM prae_abrechnungen a JOIN prae_empfaenger e ON e.id = a.empfaenger_id
                          WHERE a.organization_id = ? AND a.jahr = ? ORDER BY e.nachname, e.vorname, a.monat");
    $stmt->execute([currentOrgId(), $jahr]);
    $je = [];
    foreach ($stmt->fetchAll() as $a) {
        $id = (int)$a['empfaenger_id'];
        $je[$id] ??= ['empfaenger_id' => $id, 'monate' => [], 'steuerfrei' => [], 'ueberschuss' => [], 'offen' => 0, 'offen_betrag' => []];
        if ($a['status'] === 'ausbezahlt') {
            $je[$id]['monate'][] = (int)$a['monat'];
            $je[$id]['steuerfrei'][] = $a['betrag_steuerfrei'];
            $je[$id]['ueberschuss'][] = $a['betrag_ueberschuss'];
        } else {
            $je[$id]['offen']++;
            $je[$id]['offen_betrag'][] = $a['betrag_gesamt'];
        }
    }
    foreach ($je as $id => &$d) {
        $d['empfaenger'] = praeEmpfaenger($db, $id);
        $d['betrag'] = moneySum($d['steuerfrei']);
        $d['ueberschuss'] = moneySum($d['ueberschuss']);
        $d['offen_betrag'] = moneySum($d['offen_betrag']);
        $d['blz'] = $d['monate'] ? sprintf('%04d-%02d-01', $jahr, min($d['monate'])) : null;
        $d['elz'] = $d['monate'] ? date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $jahr, max($d['monate'])))) : null;
        $d['refnr'] = praeRefnr($id, $jahr);
        $d['hinweise'] = $d['empfaenger'] ? praeEmpfaengerHinweise($d['empfaenger']) : [];
        if ($d['offen']) $d['hinweise'][] = ['typ' => 'warnung', 'text' => $d['offen'] . ' Monatsabrechnung(en) noch nicht ausbezahlt – nicht in der Meldung enthalten.'];
        if (bccomp($d['ueberschuss'], '0', 2) > 0) {
            $d['hinweise'][] = ['typ' => 'fehler', 'text' => 'Höchstbeträge überschritten (' . moneyFormat($d['ueberschuss']) . ' steuerpflichtig): Abrechnung über Lohnverrechnung mit Lohnzettel L16, ggf. ÖGK-Anmeldung.'];
        }
        $d['l16'] = bccomp($d['ueberschuss'], '0', 2) > 0 || ($d['empfaenger'] && (int)$d['empfaenger']['andere_bezuege']);
        $d['meldbar'] = $d['monate'] && !$d['l16'] && !array_filter($d['hinweise'], fn($h) => $h['typ'] === 'fehler');
        $d['monate_anzahl'] = $d['monate'] ? max($d['monate']) - min($d['monate']) + 1 : 0;
        // ELDA-Prüfung L19_PRAE_001: Betrag ≤ 720 × Anzahl Monate im Zeitraum
        if ($d['monate'] && bccomp($d['betrag'], bcmul(PRAE_MONAT_MAX, (string)$d['monate_anzahl'], 2), 2) > 0) {
            $d['hinweise'][] = ['typ' => 'fehler', 'text' => 'Betrag höher als 720 € × Monate im Zeitraum.'];
            $d['meldbar'] = false;
        }
    }
    unset($d);
    return $je;
}

/**
 * L19 bzw. L19Storno als XML nach BMF-Schema urn:bmf.gv.at:efsz:l19:202302.
 * Reihenfolge der Elemente laut XSD: REFNR, TSAUS, BLZ, ELZ, AUSZ, EMPF, KENNZ.
 */
function praeL19Xml(array $einst, array $empf, string $refnr, string $blz, string $elz, string $betrag, bool $storno = false): string
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $root = $doc->createElementNS(PRAE_L19_NS, 'l19:' . ($storno ? 'L19Storno' : 'L19'));
    $doc->appendChild($root);
    $el = function (DOMElement $parent, string $name, string $wert) use ($doc) {
        $e = $doc->createElementNS(PRAE_L19_NS, 'l19:' . $name);
        $e->appendChild($doc->createTextNode($wert));
        $parent->appendChild($e);
        return $e;
    };
    $gruppe = function (DOMElement $parent, string $name) use ($doc) {
        $e = $doc->createElementNS(PRAE_L19_NS, 'l19:' . $name);
        $parent->appendChild($e);
        return $e;
    };
    $adresse = function (DOMElement $parent, array $a) use ($gruppe, $el) {
        $adr = $gruppe($parent, 'ADR');
        $el($adr, 'LND', strtoupper($a['land'] ?: 'AT'));
        $el($adr, 'ORT', mb_substr(trim($a['ort']), 0, 1000));
        $el($adr, 'PLZ', mb_substr(trim($a['plz']), 0, 10));
        $el($adr, 'STR', mb_substr(trim($a['strasse']), 0, 1000));
    };
    $vollstaendig = fn(array $a) => trim((string)($a['strasse'] ?? '')) !== '' && trim((string)($a['plz'] ?? '')) !== '' && trim((string)($a['ort'] ?? '')) !== '';

    $el($root, 'REFNR', mb_substr($refnr, 0, 100));
    $el($root, 'TSAUS', (new DateTime('now', new DateTimeZone('Europe/Vienna')))->format('Y-m-d\TH:i:sP'));
    $el($root, 'BLZ', $blz);
    $el($root, 'ELZ', $elz);

    $ausz = $gruppe($root, 'AUSZ');
    $el($ausz, 'ZVR', str_pad(preg_replace('/\D+/', '', $einst['zvr']), 10, '0', STR_PAD_LEFT));
    if (!empty($einst['steuernummer']) && !praeStnrFehler($einst['steuernummer'])) $el($ausz, 'STN', preg_replace('/\D+/', '', $einst['steuernummer']));
    if ($vollstaendig($einst)) $adresse($ausz, $einst);
    if (!empty($einst['vereinsname'])) $el($ausz, 'NAM', mb_substr($einst['vereinsname'], 0, 1000));

    $empf_el = $gruppe($root, 'EMPF');
    if (!empty($empf['svnr'])) {
        $el($empf_el, 'SVN', preg_replace('/\s+/', '', $empf['svnr']));
        if (!empty($empf['geburtsdatum'])) $el($empf_el, 'GBD', $empf['geburtsdatum']);
        if ($vollstaendig($empf)) $adresse($empf_el, $empf);
    } else {
        $el($empf_el, 'GBD', (string)$empf['geburtsdatum']);
        $adresse($empf_el, $empf);
    }
    $el($empf_el, 'VNAM', mb_substr($empf['vorname'], 0, 1000));
    $el($empf_el, 'FNAM', mb_substr($empf['nachname'], 0, 1000));

    if (!$storno) {
        $kennz = $gruppe($root, 'KENNZ');
        $el($kennz, 'PRAE', number_format((float)$betrag, 2, '.', ''));
    }
    return $doc->saveXML();
}

/** Prüft ein XML gegen das offizielle L19-Schema. Liefert Fehlermeldungen (leer = gültig). */
function praeXmlFehler(string $xml): array
{
    $vorher = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new DOMDocument();
    $fehler = [];
    if (!$doc->loadXML($xml) || !$doc->schemaValidate(PRAE_L19_XSD)) {
        foreach (libxml_get_errors() as $e) $fehler[] = trim($e->message) . ' (Zeile ' . $e->line . ')';
        if (!$fehler) $fehler[] = 'Das XML entspricht nicht dem L19-Schema.';
    }
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    return $fehler;
}

/** Dateiname einer Meldung (ASCII, eindeutig). */
function praeL19Dateiname(string $refnr, string $typ): string
{
    return 'L19_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $refnr) . ($typ === 'storno' ? '_Storno' : ($typ === 'korrektur' ? '_Korrektur' : '')) . '.xml';
}

// ----------------------------------------------------------------
// Dateien: ZIP (ohne ZipArchive-Erweiterung) und SEPA-Überweisung
// ----------------------------------------------------------------

/** Minimaler ZIP-Writer (unkomprimiert, UTF-8-Dateinamen). */
function praeZip(array $dateien): string
{
    $daten = $verzeichnis = '';
    $dos_zeit = ((int)date('H') << 11) | ((int)date('i') << 5) | intdiv((int)date('s'), 2);
    $dos_datum = (((int)date('Y') - 1980) << 9) | ((int)date('n') << 5) | (int)date('j');
    foreach ($dateien as $name => $inhalt) {
        $crc = crc32($inhalt);
        $groesse = strlen($inhalt);
        $offset = strlen($daten);
        $kopf = pack('vvvvvVVVvv', 20, 0x0800, 0, $dos_zeit, $dos_datum, $crc, $groesse, $groesse, strlen($name), 0);
        $daten .= "PK\x03\x04" . $kopf . $name . $inhalt;
        $verzeichnis .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 0, $dos_zeit, $dos_datum, $crc, $groesse, $groesse, strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
    }
    return $daten . $verzeichnis . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($dateien), count($dateien), strlen($verzeichnis), strlen($daten), 0);
}

/** Text für SEPA (lateinischer Grundzeichensatz, Umlaute umschreiben). */
function praeSepaText(string $text, int $max): string
{
    $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', '–' => '-', '„' => '"', '“' => '"']);
    $text = preg_replace("/[^A-Za-z0-9 \/\-?:().,'+]/", '', $text);
    return mb_substr(trim($text), 0, $max);
}

/**
 * SEPA-Sammelüberweisung (pain.001.001.03) für das Online-Banking.
 * $zahlungen: [['name', 'iban', 'betrag', 'zweck', 'id'], …]
 */
function praeSepaXml(array $einst, array $zahlungen, string $ausfuehrung): string
{
    $summe = moneySum(array_column($zahlungen, 'betrag'));
    $msg_id = 'ACI-PRAE-' . date('YmdHis');
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $ns = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';
    $root = $doc->createElementNS($ns, 'Document');
    $doc->appendChild($root);
    $e = function (DOMElement $parent, string $name, ?string $wert = null, array $attr = []) use ($doc, $ns) {
        $el = $doc->createElementNS($ns, $name);
        if ($wert !== null) $el->appendChild($doc->createTextNode($wert));
        foreach ($attr as $k => $v) $el->setAttribute($k, $v);
        $parent->appendChild($el);
        return $el;
    };
    $init = $e($root, 'CstmrCdtTrfInitn');
    $hdr = $e($init, 'GrpHdr');
    $e($hdr, 'MsgId', $msg_id);
    $e($hdr, 'CreDtTm', date('Y-m-d\TH:i:s'));
    $e($hdr, 'NbOfTxs', (string)count($zahlungen));
    $e($hdr, 'CtrlSum', $summe);
    $e($e($hdr, 'InitgPty'), 'Nm', praeSepaText($einst['vereinsname'], 70));

    $pmt = $e($init, 'PmtInf');
    $e($pmt, 'PmtInfId', $msg_id . '-1');
    $e($pmt, 'PmtMtd', 'TRF');
    $e($pmt, 'BtchBookg', 'true');
    $e($pmt, 'NbOfTxs', (string)count($zahlungen));
    $e($pmt, 'CtrlSum', $summe);
    $e($e($e($pmt, 'PmtTpInf'), 'SvcLvl'), 'Cd', 'SEPA');
    $e($pmt, 'ReqdExctnDt', $ausfuehrung);
    $e($e($pmt, 'Dbtr'), 'Nm', praeSepaText($einst['vereinsname'], 70));
    $e($e($e($pmt, 'DbtrAcct'), 'Id'), 'IBAN', strtoupper(preg_replace('/\s+/', '', (string)$einst['iban'])));
    $fin = $e($e($pmt, 'DbtrAgt'), 'FinInstnId');
    if (!empty($einst['bic'])) $e($fin, 'BIC', strtoupper(trim($einst['bic'])));
    else $e($e($fin, 'Othr'), 'Id', 'NOTPROVIDED');
    $e($pmt, 'ChrgBr', 'SLEV');

    foreach ($zahlungen as $z) {
        $tx = $e($pmt, 'CdtTrfTxInf');
        $e($e($tx, 'PmtId'), 'EndToEndId', praeSepaText($z['id'], 35));
        $e($e($tx, 'Amt'), 'InstdAmt', number_format((float)$z['betrag'], 2, '.', ''), ['Ccy' => 'EUR']);
        $e($e($tx, 'Cdtr'), 'Nm', praeSepaText($z['name'], 70));
        $e($e($e($tx, 'CdtrAcct'), 'Id'), 'IBAN', strtoupper(preg_replace('/\s+/', '', $z['iban'])));
        $e($e($tx, 'RmtInf'), 'Ustrd', praeSepaText($z['zweck'], 140));
    }
    return $doc->saveXML();
}

/** Meldefrist für ein Jahr (Ende Februar des Folgejahres). */
function praeMeldefrist(int $jahr): string
{
    return date('Y-m-t', strtotime(($jahr + 1) . '-02-01'));
}
