<?php
/**
 * Athletikclub Steiermark – Dokumentgenerator
 * Jede Berichtsart liefert eine Datenstruktur (siehe includes/export.php), die als PDF, CSV oder
 * XLSX ausgegeben wird. Zugriff wird je Bericht und Datensatz geprüft (berichtErzeugen).
 */

require_once ROOT_PATH . '/includes/plattform.php';
require_once ROOT_PATH . '/includes/einheiten.php';
require_once ROOT_PATH . '/includes/kursanmeldung.php';
require_once ROOT_PATH . '/includes/events.php';
require_once ROOT_PATH . '/includes/export.php';

/**
 * param: kurs | event | projekt | abrechnung | zeitraum | keiner
 * personen: enthält personenbezogene Daten (Tabellen-Export nur mit export.personen oder als Kursleitung)
 */
const BERICHT_TYPEN = [
    'teilnehmerliste'   => ['label' => 'Teilnehmerliste',         'param' => 'kurs',       'personen' => true,  'text' => 'Angemeldete Personen eines Kurses mit Kontakt- und Notfalldaten'],
    'anwesenheitsliste' => ['label' => 'Anwesenheitsliste',       'param' => 'kurs',       'personen' => true,  'text' => 'Alle Termine eines Kurses mit erfasster Anwesenheit'],
    'trainerabrechnung' => ['label' => 'Trainerabrechnung',       'param' => 'abrechnung', 'personen' => false, 'text' => 'Monatsabrechnung mit allen Einsätzen'],
    'trainerstunden'    => ['label' => 'Trainerstunden',          'param' => 'zeitraum',   'personen' => false, 'text' => 'Einsätze, Stunden und Honorare je Trainer:in im Zeitraum'],
    'projektuebersicht' => ['label' => 'Projektübersicht',        'param' => 'projekt',    'personen' => false, 'text' => 'Stammdaten, Budget, Buchungen, Aufgaben und Einheiten eines Projekts'],
    'eventbericht'      => ['label' => 'Veranstaltungsbericht',   'param' => 'event',      'personen' => false, 'text' => 'Teilnahme, Check-in, Finanzen und Aufgaben eines Events'],
    'kursuebersicht'    => ['label' => 'Kursübersicht',           'param' => 'zeitraum',   'personen' => false, 'text' => 'Alle Kurse im Zeitraum mit Belegung, Umsatz und Trainerkosten'],
    'foerderuebersicht' => ['label' => 'Förderübersicht',         'param' => 'keiner',     'personen' => false, 'text' => 'Alle Förderungen mit Beträgen, Verbrauch und Fristen'],
    'finanzjournal'     => ['label' => 'Buchungsjournal',         'param' => 'zeitraum',   'personen' => false, 'text' => 'Alle Einnahmen und Ausgaben im Zeitraum'],
];

/** Darf die angemeldete Person die Berichtsart grundsätzlich nutzen? (für die Auswahl) */
function berichtVerfuegbar(string $typ): bool
{
    switch ($typ) {
        case 'teilnehmerliste':
        case 'anwesenheitsliste': return isTrainer() || darf('kinder.anzeigen');
        case 'trainerabrechnung': return isTrainer() || darfEines('abrechnung.anzeigen', 'abrechnung.bearbeiten');
        case 'trainerstunden':    return isTrainer() || darf('abrechnung.anzeigen');
        case 'projektuebersicht': return isTrainer() || darf('projekte.anzeigen');
        case 'eventbericht':      return darf('events.anzeigen');
        case 'kursuebersicht':    return isTrainer() || darfEines('management.anzeigen', 'finanzen.anzeigen');
        case 'foerderuebersicht': return darf('foerderungen.anzeigen');
        case 'finanzjournal':     return darf('finanzen.anzeigen');
    }
    return false;
}

/** Kurse, deren Personenlisten die angemeldete Person sehen darf. */
function berichtKursDarf(PDO $db, array $kurs): bool
{
    if (darf('kinder.anzeigen') || (int)$kurs['trainer_id'] === (int)getCurrentUserId()) return true;
    return einheitDarfBearbeiten($db, ['kurs_id' => (int)$kurs['id'], 'projekt_id' => $kurs['projekt_id'] ?? null]);
}

function berichtProjektDarf(PDO $db, array $p): bool
{
    if (darf('projekte.anzeigen') || (int)$p['leitung_id'] === (int)getCurrentUserId()) return true;
    $stmt = $db->prepare('SELECT 1 FROM projekt_team WHERE projekt_id = ? AND user_id = ?');
    $stmt->execute([$p['id'], getCurrentUserId()]);
    return (bool)$stmt->fetchColumn();
}

/** Tabellenexport (CSV/XLSX) personenbezogener Listen: nur mit Recht oder als verantwortliche Kursleitung. */
function berichtPersonenExportDarf(PDO $db, array $kurs): bool
{
    return darf('export.personen') || (int)$kurs['trainer_id'] === (int)getCurrentUserId();
}

/** Auswahllisten für die Parameter (nur Datensätze mit Zugriff). */
function berichtAuswahl(PDO $db, string $param): array
{
    $org = currentOrgId();
    $me = (int)getCurrentUserId();
    $liste = [];
    switch ($param) {
        case 'kurs':
            $stmt = $db->prepare("SELECT id, titel, start_datum, trainer_id, projekt_id FROM kurse WHERE organization_id = ? AND status <> 'abgesagt' ORDER BY start_datum DESC LIMIT 400");
            $stmt->execute([$org]);
            foreach ($stmt->fetchAll() as $k) if (berichtKursDarf($db, $k)) $liste[$k['id']] = $k['titel'] . ' (' . date('d.m.Y', strtotime($k['start_datum'])) . ')';
            break;
        case 'event':
            $stmt = $db->prepare("SELECT id, titel, start_datum FROM kurse WHERE organization_id = ? AND art = 'event' ORDER BY start_datum DESC LIMIT 200");
            $stmt->execute([$org]);
            foreach ($stmt->fetchAll() as $k) $liste[$k['id']] = $k['titel'] . ' (' . date('d.m.Y', strtotime($k['start_datum'])) . ')';
            break;
        case 'projekt':
            $stmt = $db->prepare("SELECT id, name, leitung_id FROM projekte WHERE organization_id = ? AND status <> 'archiviert' ORDER BY name");
            $stmt->execute([$org]);
            foreach ($stmt->fetchAll() as $p) if (berichtProjektDarf($db, $p)) $liste[$p['id']] = $p['name'];
            break;
        case 'abrechnung':
            $alle = darfEines('abrechnung.anzeigen', 'abrechnung.bearbeiten');
            $stmt = $db->prepare('SELECT ta.id, ta.jahr, ta.monat, u.vorname, u.nachname FROM trainer_abrechnungen ta JOIN users u ON u.id = ta.user_id
                                  WHERE ta.organization_id = ?' . ($alle ? '' : ' AND ta.user_id = ' . $me) . ' ORDER BY ta.jahr DESC, ta.monat DESC, u.nachname LIMIT 300');
            $stmt->execute([$org]);
            foreach ($stmt->fetchAll() as $a) $liste[$a['id']] = sprintf('%02d/%d – %s %s', $a['monat'], $a['jahr'], $a['vorname'], $a['nachname']);
            break;
    }
    return $liste;
}

/**
 * Bericht erzeugen. Liefert ['fehler' => '…'] oder die Berichtsstruktur.
 * $format wird für die Prüfung personenbezogener Tabellenexporte benötigt.
 */
function berichtErzeugen(PDO $db, string $typ, array $q, string $format = 'pdf'): array
{
    if (!isset(BERICHT_TYPEN[$typ]) || !berichtVerfuegbar($typ)) return ['fehler' => 'Für diesen Bericht fehlt die Berechtigung.'];
    $id = (int)($q['id'] ?? 0);
    $von = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['von'] ?? '') ? $q['von'] : date('Y-01-01');
    $bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['bis'] ?? '') ? $q['bis'] : date('Y-12-31');
    if ($bis < $von) [$von, $bis] = [$bis, $von];
    $zeitraum = date('d.m.Y', strtotime($von)) . ' – ' . date('d.m.Y', strtotime($bis));

    switch ($typ) {
        case 'teilnehmerliste':
        case 'anwesenheitsliste':
            $kurs = kursLaden($db, $id);
            if (!$kurs || !berichtKursDarf($db, $kurs)) return ['fehler' => 'Kurs nicht gefunden oder kein Zugriff.'];
            if ($format !== 'pdf' && !berichtPersonenExportDarf($db, $kurs)) return ['fehler' => 'Tabellenexporte mit Personendaten erfordern die Berechtigung „Personenbezogene Listen exportieren“.'];
            return $typ === 'teilnehmerliste' ? berichtTeilnehmerliste($db, $kurs) : berichtAnwesenheitsliste($db, $kurs);
        case 'trainerabrechnung':
            $ta = trainerAbrechnungLaden($db, $id);
            if (!$ta || (!darfEines('abrechnung.anzeigen', 'abrechnung.bearbeiten') && (int)$ta['user_id'] !== (int)getCurrentUserId())) return ['fehler' => 'Abrechnung nicht gefunden oder kein Zugriff.'];
            return berichtTrainerabrechnung($db, $ta);
        case 'trainerstunden':
            return berichtTrainerstunden($db, $von, $bis, $zeitraum, darf('abrechnung.anzeigen') ? null : (int)getCurrentUserId());
        case 'projektuebersicht':
            $stmt = $db->prepare('SELECT p.*, u.vorname, u.nachname, f.titel AS foerderung_titel FROM projekte p LEFT JOIN users u ON u.id = p.leitung_id
                                  LEFT JOIN foerderungen f ON f.id = p.foerderung_id WHERE p.id = ? AND p.organization_id = ?');
            $stmt->execute([$id, currentOrgId()]);
            $p = $stmt->fetch();
            if (!$p || !berichtProjektDarf($db, $p)) return ['fehler' => 'Projekt nicht gefunden oder kein Zugriff.'];
            $finanzen = darfEines('finanzen.anzeigen', 'projekte.bearbeiten') || (int)$p['leitung_id'] === (int)getCurrentUserId();
            return berichtProjekt($db, $p, $finanzen);
        case 'eventbericht':
            $e = eventLaden($db, $id);
            if (!$e) return ['fehler' => 'Event nicht gefunden.'];
            return berichtEvent($db, $e);
        case 'kursuebersicht':
            return berichtKursuebersicht($db, $von, $bis, $zeitraum, darfEines('management.anzeigen', 'finanzen.anzeigen') ? null : (int)getCurrentUserId());
        case 'foerderuebersicht':
            return berichtFoerderungen($db);
        case 'finanzjournal':
            return berichtJournal($db, $von, $bis, $zeitraum);
    }
    return ['fehler' => 'Unbekannter Bericht.'];
}

function berichtName(?string $v, ?string $n): string { return trim(($v ?? '') . ' ' . ($n ?? '')); }

function berichtKursInfo(array $kurs): array
{
    return [
        'Kurs' => $kurs['titel'],
        'Zeitraum' => date('d.m.Y', strtotime($kurs['start_datum'])) . ' – ' . date('d.m.Y', strtotime($kurs['end_datum'])),
        'Ort' => $kurs['ort'] ?: '–',
        'Plätze' => $kurs['max_teilnehmer'] ? (string)$kurs['max_teilnehmer'] : 'unbegrenzt',
    ];
}

function berichtTeilnehmerliste(PDO $db, array $kurs): array
{
    $kontakt = berichtKursDarf($db, $kurs);
    $stmt = $db->prepare("SELECT ka.*, u.vorname, u.nachname, u.email, mp.telefon, mp.geburtsdatum, ki.vorname AS k_vorname, ki.nachname AS k_nachname,
                                 ki.geburtsdatum AS k_geburtsdatum, ki.notfall_name, ki.notfall_telefon, ki.hinweise
                          FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
                          LEFT JOIN kinder ki ON ki.id = ka.kind_id AND ka.kind_id > 0
                          WHERE ka.kurs_id = ? AND ka.status IN ('angemeldet','teilgenommen','angefragt','warteliste')
                          ORDER BY CASE ka.status WHEN 'angemeldet' THEN 1 WHEN 'teilgenommen' THEN 1 WHEN 'angefragt' THEN 2 ELSE 3 END,
                                   CASE WHEN ka.status = 'warteliste' THEN ka.angemeldet_am END, COALESCE(ki.nachname, u.nachname), COALESCE(ki.vorname, u.vorname)");
    $stmt->execute([$kurs['id']]);
    $zeilen = [];
    $nr = 0;
    foreach ($stmt->fetchAll() as $a) {
        $kind = (int)$a['kind_id'] > 0 && $a['k_vorname'] !== null;
        $foto = einwilligungAktuell($db, 'foto_video', (int)$a['user_id'], $kind ? (int)$a['kind_id'] : null);
        $geb = $kind ? $a['k_geburtsdatum'] : $a['geburtsdatum'];
        $zeile = [
            ++$nr,
            $kind ? berichtName($a['k_vorname'], $a['k_nachname']) : berichtName($a['vorname'], $a['nachname']),
            $geb ? alterAm($geb, date('Y-m-d')) : null,
            ANMELDUNG_STATUS[$a['status']]['label'] ?? $a['status'],
            (float)$kurs['preis'] > 0 ? ((int)$a['bezahlt'] ? 'ja' : 'offen') : '–',
            $foto ? ((int)$foto['erteilt'] ? 'ja' : 'nein') : '?',
        ];
        if ($kontakt) {
            $zeile[] = ($kind ? 'Eltern: ' . berichtName($a['vorname'], $a['nachname']) . "\n" : '') . $a['email'] . ($a['telefon'] ? "\n" . $a['telefon'] : '');
            $zeile[] = $kind ? trim(($a['notfall_name'] ?? '') . ' ' . ($a['notfall_telefon'] ?? '')) . ($a['hinweise'] ? "\nHinweis: " . $a['hinweise'] : '') : '';
        }
        $zeilen[] = $zeile;
    }
    $spalten = [['titel' => 'Nr.', 'typ' => 'zahl', 'breite' => 5], ['titel' => 'Name', 'breite' => 22], ['titel' => 'Alter', 'typ' => 'zahl', 'breite' => 6],
                ['titel' => 'Status', 'breite' => 11], ['titel' => 'Bezahlt', 'breite' => 7], ['titel' => 'Foto', 'breite' => 6]];
    if ($kontakt) { $spalten[] = ['titel' => 'Kontakt', 'breite' => 28]; $spalten[] = ['titel' => 'Notfall / Hinweise', 'breite' => 24]; }
    $bestaetigt = count(array_filter($zeilen, fn($z) => in_array($z[3], [ANMELDUNG_STATUS['angemeldet']['label'] ?? 'Angemeldet', ANMELDUNG_STATUS['teilgenommen']['label'] ?? 'Teilgenommen'], true)));
    return [
        'titel' => 'Teilnehmerliste', 'untertitel' => $kurs['titel'], 'dateiname' => 'teilnehmerliste-' . $kurs['titel'], 'quer' => $kontakt,
        'info' => berichtKursInfo($kurs) + ['Bestätigt' => (string)$bestaetigt, 'Einträge gesamt' => (string)count($zeilen)],
        'abschnitte' => [['titel' => '', 'spalten' => $spalten, 'zeilen' => $zeilen,
                          'hinweis' => 'Foto: Einwilligung zu Foto-/Videoaufnahmen (ja / nein / ? = nicht erfasst). Enthält personenbezogene Daten – nur für den Kursbetrieb verwenden und nach Kursende vernichten.']],
    ];
}

function berichtAnwesenheitsliste(PDO $db, array $kurs): array
{
    $stmt = $db->prepare("SELECT * FROM einheiten WHERE kurs_id = ? AND status <> 'storniert' ORDER BY start");
    $stmt->execute([$kurs['id']]);
    $einheiten = $stmt->fetchAll();
    $personen = [];
    $werte = [];
    foreach ($einheiten as $i => $e) {
        foreach (einheitTeilnehmer($db, $e) as $key => $t) {
            $personen[$key] = $personen[$key] ?? $t['name'];
            if (!empty($t['status'])) $werte[$key][$i] = $t['status'];
        }
    }
    // Personen ohne Einheiten (Kurs noch nicht terminiert) trotzdem aufführen
    if (!$einheiten) foreach (einheitTeilnehmer($db, ['id' => 0, 'kurs_id' => $kurs['id']]) as $key => $t) $personen[$key] = $t['name'];
    asort($personen, SORT_LOCALE_STRING);

    // Bei vielen Terminen: Blöcke zu je 12 Spalten (lesbar im Querformat)
    $abschnitte = [];
    $bloecke = $einheiten ? array_chunk($einheiten, 12, true) : [[]];
    foreach ($bloecke as $b => $block) {
        $spalten = [['titel' => 'Name', 'breite' => 30]];
        foreach ($block as $e) $spalten[] = ['titel' => date('d.m.', strtotime($e['start'])), 'breite' => 7];
        if ($b === count($bloecke) - 1) $spalten[] = ['titel' => 'Quote', 'breite' => 8];
        $zeilen = [];
        foreach ($personen as $key => $name) {
            $z = [$name];
            foreach ($block as $i => $e) {
                $s = $werte[$key][$i] ?? null;
                $z[] = $s ? ANWESENHEIT_STATUS[$s]['kurz'] : (strtotime($e['start']) > time() ? '' : '–');
            }
            if ($b === count($bloecke) - 1) {
                $vergangen = count(array_filter($einheiten, fn($e) => strtotime($e['start']) <= time()));
                $da = count(array_filter($werte[$key] ?? [], fn($s) => in_array($s, ['anwesend', 'probetraining'], true)));
                $z[] = $vergangen ? round($da / $vergangen * 100) . ' %' : '–';
            }
            $zeilen[] = $z;
        }
        $abschnitte[] = ['titel' => count($bloecke) > 1 ? 'Termine ' . ($b * 12 + 1) . '–' . ($b * 12 + count($block)) : '', 'spalten' => $spalten, 'zeilen' => $zeilen];
    }
    $abschnitte[count($abschnitte) - 1]['hinweis'] = 'A = anwesend · F = gefehlt · E = entschuldigt · P = Probetraining · – = nicht erfasst · leer = Termin in der Zukunft';
    return [
        'titel' => 'Anwesenheitsliste', 'untertitel' => $kurs['titel'], 'dateiname' => 'anwesenheit-' . $kurs['titel'], 'quer' => true,
        'info' => berichtKursInfo($kurs) + ['Termine' => (string)count($einheiten), 'Teilnehmende' => (string)count($personen)],
        'abschnitte' => $abschnitte,
        'unterschrift' => ['Datum, Unterschrift Trainer:in'],
    ];
}

function berichtTrainerabrechnung(PDO $db, array $ta): array
{
    $stmt = $db->prepare('SELECT et.*, e.titel, e.start, e.typ, p.name AS projekt_name, k.titel AS kurs_titel FROM einheit_trainer et
                          JOIN einheiten e ON e.id = et.einheit_id LEFT JOIN projekte p ON p.id = e.projekt_id LEFT JOIN kurse k ON k.id = e.kurs_id
                          WHERE et.abrechnung_id = ? ORDER BY COALESCE(et.ist_start, e.start)');
    $stmt->execute([$ta['id']]);
    $zeilen = [];
    $summe = '0.00';
    $min = 0;
    foreach ($stmt->fetchAll() as $r) {
        $zeilen[] = [$r['ist_start'] ?: $r['start'], $r['titel'], $r['kurs_titel'] ?: ($r['projekt_name'] ?: (EINHEIT_TYPEN[$r['typ']]['label'] ?? '')),
                     $r['dauer_min'] ? round($r['dauer_min'] / 60, 2) : null, $r['teilnehmer_anzahl'], $r['betrag']];
        $summe = bcadd($summe, moneyRound($r['betrag'] ?? 0), 2);
        $min += (int)$r['dauer_min'];
    }
    $monate = ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return [
        'titel' => 'Trainerabrechnung', 'untertitel' => berichtName($ta['vorname'], $ta['nachname']) . ' · ' . $monate[(int)$ta['monat']] . ' ' . $ta['jahr'],
        'dateiname' => sprintf('trainerabrechnung-%s-%d-%02d', $ta['nachname'], $ta['jahr'], $ta['monat']),
        'info' => ['Trainer:in' => berichtName($ta['vorname'], $ta['nachname']), 'Monat' => $monate[(int)$ta['monat']] . ' ' . $ta['jahr'],
                   'Status' => TA_STATUS[$ta['status']]['label'] ?? $ta['status'], 'Auszahlung' => $ta['auszahlungsart'] ? ucfirst(str_replace('_', ' ', $ta['auszahlungsart'])) : '–',
                   'Einsätze' => (string)count($zeilen), 'Stunden' => number_format($min / 60, 2, ',', '.')],
        'abschnitte' => [['titel' => 'Einsätze', 'spalten' => [
            ['titel' => 'Datum', 'typ' => 'datumzeit', 'breite' => 14], ['titel' => 'Einheit', 'breite' => 30], ['titel' => 'Kurs / Projekt', 'breite' => 24],
            ['titel' => 'Stunden', 'typ' => 'zahl', 'breite' => 8], ['titel' => 'TN', 'typ' => 'zahl', 'breite' => 6], ['titel' => 'Betrag', 'typ' => 'geld', 'breite' => 11]],
            'zeilen' => $zeilen, 'summe' => ['Summe', '', '', round($min / 60, 2), null, $summe]]],
        'fusszeile' => $ta['notiz'] ? 'Notiz: ' . $ta['notiz'] : '',
        'unterschrift' => ['Datum, Unterschrift Trainer:in', 'Freigabe Verein'],
    ];
}

function berichtTrainerstunden(PDO $db, string $von, string $bis, string $zeitraum, ?int $nur_user): array
{
    $stmt = $db->prepare("SELECT et.user_id, u.vorname, u.nachname, COUNT(*) AS einsaetze, COALESCE(SUM(et.dauer_min), 0) AS minuten, COALESCE(SUM(et.betrag), 0) AS betrag,
                                 SUM(CASE WHEN et.abrechnung_id IS NULL THEN 1 ELSE 0 END) AS offen
                          FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id JOIN users u ON u.id = et.user_id
                          WHERE e.organization_id = ? AND COALESCE(et.ist_start, e.start) BETWEEN ? AND ? AND et.status NOT IN ('geplant','storniert')"
                          . ($nur_user ? ' AND et.user_id = ' . $nur_user : '') . '
                          GROUP BY et.user_id, u.vorname, u.nachname ORDER BY u.nachname, u.vorname');
    $stmt->execute([currentOrgId(), "$von 00:00:00", "$bis 23:59:59"]);
    $zeilen = [];
    $s = ['e' => 0, 'm' => 0, 'b' => '0.00'];
    foreach ($stmt->fetchAll() as $r) {
        $zeilen[] = [berichtName($r['vorname'], $r['nachname']), (int)$r['einsaetze'], round($r['minuten'] / 60, 2), $r['betrag'], (int)$r['offen']];
        $s['e'] += (int)$r['einsaetze']; $s['m'] += (int)$r['minuten']; $s['b'] = bcadd($s['b'], moneyRound($r['betrag']), 2);
    }
    return [
        'titel' => 'Trainerstunden', 'untertitel' => $zeitraum, 'dateiname' => 'trainerstunden',
        'info' => ['Zeitraum' => $zeitraum, 'Grundlage' => 'bestätigte Einsätze'],
        'abschnitte' => [['titel' => '', 'spalten' => [['titel' => 'Trainer:in', 'breite' => 30], ['titel' => 'Einsätze', 'typ' => 'zahl', 'breite' => 10],
            ['titel' => 'Stunden', 'typ' => 'zahl', 'breite' => 10], ['titel' => 'Honorar', 'typ' => 'geld', 'breite' => 12], ['titel' => 'noch nicht abgerechnet', 'typ' => 'zahl', 'breite' => 14]],
            'zeilen' => $zeilen, 'summe' => $zeilen ? ['Summe', $s['e'], round($s['m'] / 60, 2), $s['b'], null] : null]],
    ];
}

function berichtProjekt(PDO $db, array $p, bool $finanzen = true): array
{
    $b = $db->prepare('SELECT datum, art, kategorie, beschreibung, belegnummer, betrag FROM buchungen WHERE projekt_id = ? ORDER BY datum');
    $b->execute([$p['id']]);
    $buchungen = [];
    $ein = $aus = '0.00';
    foreach ($b->fetchAll() as $r) {
        $buchungen[] = [$r['datum'], $r['art'] === 'einnahme' ? 'Einnahme' : 'Ausgabe', BUCHUNG_KATEGORIEN[$r['kategorie']] ?? $r['kategorie'], $r['beschreibung'], $r['belegnummer'], $r['art'] === 'einnahme' ? $r['betrag'] : -1 * (float)$r['betrag']];
        if ($r['art'] === 'einnahme') $ein = bcadd($ein, moneyRound($r['betrag']), 2); else $aus = bcadd($aus, moneyRound($r['betrag']), 2);
    }
    $a = $db->prepare('SELECT a.titel, a.status, a.prioritaet, a.deadline, u.vorname, u.nachname FROM aufgaben a LEFT JOIN users u ON u.id = a.verantwortlich_id WHERE a.projekt_id = ? ORDER BY a.status = \'erledigt\', a.deadline');
    $a->execute([$p['id']]);
    $aufgaben = array_map(fn($r) => [$r['titel'], AUFGABE_STATUS[$r['status']]['label'] ?? $r['status'], AUFGABE_PRIO[$r['prioritaet']]['label'] ?? $r['prioritaet'], $r['deadline'], berichtName($r['vorname'], $r['nachname'])], $a->fetchAll());
    $e = $db->prepare("SELECT e.start, e.titel, e.status, e.ort, (SELECT COUNT(*) FROM anwesenheiten an WHERE an.einheit_id = e.id AND an.status IN ('anwesend','probetraining')) AS anwesend
                       FROM einheiten e WHERE e.projekt_id = ? ORDER BY e.start");
    $e->execute([$p['id']]);
    $einheiten = array_map(fn($r) => [$r['start'], $r['titel'], $r['ort'], EINHEIT_STATUS[$r['status']]['label'] ?? $r['status'], (int)$r['anwesend']], $e->fetchAll());
    $t = $db->prepare('SELECT u.vorname, u.nachname, pt.rolle FROM projekt_team pt JOIN users u ON u.id = pt.user_id WHERE pt.projekt_id = ? ORDER BY u.nachname');
    $t->execute([$p['id']]);
    $team = implode(', ', array_map(fn($r) => berichtName($r['vorname'], $r['nachname']) . ($r['rolle'] ? ' (' . $r['rolle'] . ')' : ''), $t->fetchAll()));
    $info = [
        'Kategorie' => PROJEKT_KATEGORIEN[$p['kategorie']] ?? ($p['kategorie'] ?: '–'), 'Status' => PROJEKT_STATUS[$p['status']]['label'] ?? $p['status'],
        'Leitung' => berichtName($p['vorname'], $p['nachname']) ?: '–', 'Laufzeit' => ($p['start_datum'] ? date('d.m.Y', strtotime($p['start_datum'])) : '?') . ' – ' . ($p['end_datum'] ? date('d.m.Y', strtotime($p['end_datum'])) : 'offen'),
        'Budget' => $p['budget'] !== null ? exportFormat($p['budget'], 'geld') : '–', 'Kosten' => exportFormat($aus, 'geld'),
        'Einnahmen' => exportFormat($ein, 'geld'), 'Rest' => $p['budget'] !== null ? exportFormat(bcsub(moneyRound($p['budget']), $aus, 2), 'geld') : '–',
    ];
    if (!$finanzen) unset($info['Budget'], $info['Kosten'], $info['Einnahmen'], $info['Rest']);
    if ($p['gemeinde']) $info['Gemeinde'] = $p['gemeinde'];
    if ($p['foerderung_titel']) $info['Förderung'] = $p['foerderung_titel'];
    if ($team) $info['Team'] = $team;
    $bericht = [
        'titel' => 'Projektübersicht', 'untertitel' => $p['name'], 'dateiname' => 'projekt-' . $p['name'], 'info' => $info,
        'abschnitte' => [
            ['titel' => 'Buchungen', 'spalten' => [['titel' => 'Datum', 'typ' => 'datum', 'breite' => 10], ['titel' => 'Art', 'breite' => 9], ['titel' => 'Kategorie', 'breite' => 14],
                ['titel' => 'Beschreibung', 'breite' => 30], ['titel' => 'Beleg', 'breite' => 9], ['titel' => 'Betrag', 'typ' => 'geld', 'breite' => 11]],
                'zeilen' => $buchungen, 'summe' => $buchungen ? ['Saldo', '', '', '', '', bcsub($ein, $aus, 2)] : null],
            ['titel' => 'Aufgaben', 'spalten' => [['titel' => 'Aufgabe', 'breite' => 34], ['titel' => 'Status', 'breite' => 12], ['titel' => 'Priorität', 'breite' => 10],
                ['titel' => 'Deadline', 'typ' => 'datum', 'breite' => 10], ['titel' => 'Verantwortlich', 'breite' => 18]], 'zeilen' => $aufgaben],
            ['titel' => 'Einheiten', 'spalten' => [['titel' => 'Beginn', 'typ' => 'datumzeit', 'breite' => 13], ['titel' => 'Titel', 'breite' => 30], ['titel' => 'Ort', 'breite' => 20],
                ['titel' => 'Status', 'breite' => 12], ['titel' => 'Anwesend', 'typ' => 'zahl', 'breite' => 9]], 'zeilen' => $einheiten],
        ],
        'fusszeile' => $p['beschreibung'] ? 'Beschreibung: ' . $p['beschreibung'] : '',
    ];
    if (!$finanzen) array_shift($bericht['abschnitte']);
    return $bericht;
}

function berichtEvent(PDO $db, array $e): array
{
    $k = eventKennzahlen($db, $e);
    $info = [
        'Datum' => date('d.m.Y H:i', strtotime($e['start_datum'])) . ' – ' . date(substr($e['start_datum'], 0, 10) === substr($e['end_datum'], 0, 10) ? 'H:i' : 'd.m.Y H:i', strtotime($e['end_datum'])),
        'Ort' => $e['ort'] ?: '–', 'Verantwortlich' => berichtName($e['leitung_vorname'] ?? '', $e['leitung_nachname'] ?? '') ?: '–',
        'Plätze' => $e['max_teilnehmer'] ? (string)$e['max_teilnehmer'] : 'unbegrenzt',
    ];
    $kennzahlen = [
        ['Anmeldungen (bestätigt)', $k['angemeldet']], ['Offene Anfragen', $k['angefragt']], ['Warteliste', $k['warteliste']],
        ['Eingecheckt / anwesend', $k['eingecheckt']], ['Check-in-Quote', $k['angemeldet'] ? round($k['eingecheckt'] / $k['angemeldet'] * 100) . ' %' : '–'],
        ['Helfer:innen im Team', $k['helfer']], ['Offene Aufgaben', $k['aufgaben_offen']],
    ];
    $finanzen = [['Teilnahmebeiträge (bezahlt)', $k['einnahmen_teilnahme']], ['Weitere Einnahmen', $k['einnahmen']], ['Ausgaben', -1 * (float)$k['ausgaben']]];
    if ($e['budget'] !== null) $info['Budget'] = exportFormat($e['budget'], 'geld');
    $abschnitte = [
        ['titel' => 'Kennzahlen', 'spalten' => [['titel' => 'Kennzahl', 'breite' => 40], ['titel' => 'Wert', 'breite' => 15]], 'zeilen' => $kennzahlen],
        ['titel' => 'Finanzen', 'spalten' => [['titel' => 'Position', 'breite' => 40], ['titel' => 'Betrag', 'typ' => 'geld', 'breite' => 15]], 'zeilen' => $finanzen, 'summe' => ['Ergebnis', $k['ergebnis']]],
    ];
    if ($e['projekt_id']) {
        $p = berichtProjekt($db, ['id' => $e['projekt_id'], 'kategorie' => '', 'status' => '', 'vorname' => '', 'nachname' => '', 'start_datum' => null, 'end_datum' => null,
                                  'budget' => null, 'gemeinde' => null, 'foerderung_titel' => null, 'name' => '', 'beschreibung' => null]);
        $abschnitte[] = $p['abschnitte'][0];
        $abschnitte[] = $p['abschnitte'][1];
    }
    return [
        'titel' => 'Veranstaltungsbericht', 'untertitel' => $e['titel'], 'dateiname' => 'eventbericht-' . $e['titel'], 'info' => $info, 'abschnitte' => $abschnitte,
        'fusszeile' => 'Alle Zahlen aus Anmeldungen, Check-in, Anwesenheit und den Buchungen des Event-Projekts. Stand: ' . date('d.m.Y H:i') . '.',
    ];
}

function berichtKursuebersicht(PDO $db, string $von, string $bis, string $zeitraum, ?int $nur_trainer): array
{
    $stmt = $db->prepare("SELECT k.*, u.vorname, u.nachname,
                                 (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status IN ('angemeldet','teilgenommen')) AS belegt,
                                 (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status = 'warteliste') AS warteliste,
                                 (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.bezahlt = 1) AS bezahlt,
                                 (SELECT COALESCE(SUM(et.betrag), 0) FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id WHERE e.kurs_id = k.id AND et.status NOT IN ('geplant','storniert')) AS trainerkosten
                          FROM kurse k LEFT JOIN users u ON u.id = k.trainer_id
                          WHERE k.organization_id = ? AND k.start_datum <= ? AND k.end_datum >= ?" . ($nur_trainer ? ' AND k.trainer_id = ' . $nur_trainer : '') . '
                          ORDER BY k.start_datum, k.titel');
    $stmt->execute([currentOrgId(), "$bis 23:59:59", "$von 00:00:00"]);
    $zeilen = [];
    $s = ['b' => 0, 'u' => '0.00', 't' => '0.00'];
    foreach ($stmt->fetchAll() as $k) {
        $umsatz = bcmul(moneyRound($k['preis'] ?? 0), (string)(int)$k['bezahlt'], 2);
        $zeilen[] = [$k['titel'], berichtName($k['vorname'], $k['nachname']), $k['start_datum'], $k['end_datum'],
                     (int)$k['belegt'] . ($k['max_teilnehmer'] ? ' / ' . (int)$k['max_teilnehmer'] : ''), $k['max_teilnehmer'] ? round($k['belegt'] / $k['max_teilnehmer'] * 100) . ' %' : '–',
                     (int)$k['warteliste'], $umsatz, $k['trainerkosten'], ucfirst($k['status'])];
        $s['b'] += (int)$k['belegt']; $s['u'] = bcadd($s['u'], $umsatz, 2); $s['t'] = bcadd($s['t'], moneyRound($k['trainerkosten']), 2);
    }
    return [
        'titel' => 'Kursübersicht', 'untertitel' => $zeitraum, 'dateiname' => 'kursuebersicht', 'quer' => true,
        'info' => ['Zeitraum' => $zeitraum, 'Kurse' => (string)count($zeilen)],
        'abschnitte' => [['titel' => '', 'spalten' => [
            ['titel' => 'Kurs', 'breite' => 26], ['titel' => 'Trainer:in', 'breite' => 16], ['titel' => 'Start', 'typ' => 'datum', 'breite' => 10], ['titel' => 'Ende', 'typ' => 'datum', 'breite' => 10],
            ['titel' => 'Belegt', 'breite' => 8], ['titel' => 'Auslastung', 'breite' => 9], ['titel' => 'Warteliste', 'typ' => 'zahl', 'breite' => 8],
            ['titel' => 'Umsatz (bezahlt)', 'typ' => 'geld', 'breite' => 12], ['titel' => 'Trainerkosten', 'typ' => 'geld', 'breite' => 12], ['titel' => 'Status', 'breite' => 10]],
            'zeilen' => $zeilen, 'summe' => $zeilen ? ['Summe', '', null, null, (string)$s['b'], '', null, $s['u'], $s['t'], ''] : null,
            'hinweis' => 'Umsatz = Kursbeitrag × bezahlte Anmeldungen (gesamte Kurslaufzeit). Trainerkosten = bestätigte Einsätze mit Honorar.']],
    ];
}

function berichtFoerderungen(PDO $db): array
{
    $stmt = $db->prepare("SELECT * FROM foerderungen WHERE organization_id = ? ORDER BY CASE WHEN status IN ('abgeschlossen','abgelehnt') THEN 1 ELSE 0 END, COALESCE(einreichfrist, nachweisfrist, created_at)");
    $stmt->execute([currentOrgId()]);
    $zeilen = [];
    $s = ['a' => '0.00', 'b' => '0.00', 'v' => '0.00', 'r' => '0.00'];
    foreach ($stmt->fetchAll() as $f) {
        $b = foerderBudget($db, $f);
        $zugesagt = in_array($f['status'], FOERDER_ZUGESAGT, true);
        $fristen = [];
        foreach (['einreichfrist' => 'Einreichung', 'nachweisfrist' => 'Nachweis', 'abrechnungsfrist' => 'Abrechnung'] as $feld => $l) {
            if (!empty($f[$feld])) $fristen[] = $l . ' ' . date('d.m.Y', strtotime($f[$feld]));
        }
        $zeilen[] = [$f['titel'], $f['foerderstelle'], FOERDER_STATUS[$f['status']]['label'] ?? $f['status'], $f['betrag_beantragt'], $zugesagt ? $b['bewilligt'] : null,
                     $zugesagt ? $b['verbraucht'] : null, $zugesagt ? $b['rest'] : null, $f['betrag_ausbezahlt'], implode("\n", $fristen)];
        $s['a'] = bcadd($s['a'], moneyRound($f['betrag_beantragt'] ?? 0), 2);
        if ($zugesagt) { $s['b'] = bcadd($s['b'], $b['bewilligt'], 2); $s['v'] = bcadd($s['v'], $b['verbraucht'], 2); $s['r'] = bcadd($s['r'], $b['rest'], 2); }
    }
    return [
        'titel' => 'Förderübersicht', 'untertitel' => 'Stand ' . date('d.m.Y'), 'dateiname' => 'foerderuebersicht', 'quer' => true,
        'info' => ['Förderungen' => (string)count($zeilen), 'Bewilligt gesamt' => exportFormat($s['b'], 'geld'), 'Verbraucht' => exportFormat($s['v'], 'geld'), 'Verfügbar' => exportFormat($s['r'], 'geld')],
        'abschnitte' => [['titel' => '', 'spalten' => [
            ['titel' => 'Förderung', 'breite' => 24], ['titel' => 'Förderstelle', 'breite' => 16], ['titel' => 'Status', 'breite' => 10], ['titel' => 'Beantragt', 'typ' => 'geld', 'breite' => 11],
            ['titel' => 'Bewilligt', 'typ' => 'geld', 'breite' => 11], ['titel' => 'Verbraucht', 'typ' => 'geld', 'breite' => 11], ['titel' => 'Rest', 'typ' => 'geld', 'breite' => 11],
            ['titel' => 'Ausbezahlt', 'typ' => 'geld', 'breite' => 11], ['titel' => 'Fristen', 'breite' => 18]],
            'zeilen' => $zeilen, 'summe' => $zeilen ? ['Summe', '', '', $s['a'], $s['b'], $s['v'], $s['r'], null, ''] : null,
            'hinweis' => 'Verbraucht = Ausgaben-Buchungen, die der Förderung zugeordnet sind.']],
    ];
}

function berichtJournal(PDO $db, string $von, string $bis, string $zeitraum): array
{
    $stmt = $db->prepare('SELECT b.*, p.name AS projekt_name, f.titel AS foerderung_titel FROM buchungen b LEFT JOIN projekte p ON p.id = b.projekt_id
                          LEFT JOIN foerderungen f ON f.id = b.foerderung_id WHERE b.organization_id = ? AND b.datum BETWEEN ? AND ? ORDER BY b.datum, b.id');
    $stmt->execute([currentOrgId(), $von, $bis]);
    $zeilen = [];
    $ein = $aus = '0.00';
    foreach ($stmt->fetchAll() as $b) {
        $zeilen[] = [$b['datum'], $b['belegnummer'], $b['art'] === 'einnahme' ? 'Einnahme' : 'Ausgabe', BUCHUNG_KATEGORIEN[$b['kategorie']] ?? $b['kategorie'], $b['beschreibung'],
                     $b['projekt_name'] ?: ($b['foerderung_titel'] ?: ''), $b['status'] === 'offen' ? 'offen' : 'bezahlt', $b['art'] === 'einnahme' ? $b['betrag'] : -1 * (float)$b['betrag']];
        if ($b['art'] === 'einnahme') $ein = bcadd($ein, moneyRound($b['betrag']), 2); else $aus = bcadd($aus, moneyRound($b['betrag']), 2);
    }
    return [
        'titel' => 'Buchungsjournal', 'untertitel' => $zeitraum, 'dateiname' => 'buchungsjournal', 'quer' => true,
        'info' => ['Zeitraum' => $zeitraum, 'Buchungen' => (string)count($zeilen), 'Einnahmen' => exportFormat($ein, 'geld'), 'Ausgaben' => exportFormat($aus, 'geld')],
        'abschnitte' => [['titel' => '', 'spalten' => [
            ['titel' => 'Datum', 'typ' => 'datum', 'breite' => 10], ['titel' => 'Beleg', 'breite' => 9], ['titel' => 'Art', 'breite' => 9], ['titel' => 'Kategorie', 'breite' => 14],
            ['titel' => 'Beschreibung', 'breite' => 30], ['titel' => 'Projekt / Förderung', 'breite' => 18], ['titel' => 'Status', 'breite' => 8], ['titel' => 'Betrag', 'typ' => 'geld', 'breite' => 12]],
            'zeilen' => $zeilen, 'summe' => $zeilen ? ['Saldo', '', '', '', '', '', '', bcsub($ein, $aus, 2)] : null,
            'hinweis' => 'Kursbeiträge aus bezahlten Anmeldungen sind nicht als Einzelbuchungen enthalten (siehe Kursübersicht).']],
    ];
}
