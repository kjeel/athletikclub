<?php
/**
 * Athletikclub Steiermark – Terminkalender
 * Führt eigene Termine (auch wiederkehrend), Kurse und Leistungsdiagnostik-
 * Testungen zu einer Terminliste zusammen und erzeugt iCalendar-Dateien (.ics)
 * für das Abo im Handy-/Outlook-Kalender.
 */

const KAL_TYPEN = [
    'termin'        => ['label' => 'Termin',        'farbe' => '#64748B'],
    'training'      => ['label' => 'Training',      'farbe' => '#22C55E'],
    'besprechung'   => ['label' => 'Besprechung',   'farbe' => '#8B5CF6'],
    'veranstaltung' => ['label' => 'Veranstaltung', 'farbe' => '#EF4444'],
    'wettkampf'     => ['label' => 'Wettkampf',     'farbe' => '#F59E0B'],
    'sonstiges'     => ['label' => 'Sonstiges',     'farbe' => '#14B8A6'],
];
const KAL_QUELLEN = [
    'kurse'      => ['label' => 'Kurse',                'farbe' => '#3B82F6'],
    'diagnostik' => ['label' => 'Leistungsdiagnostik',  'farbe' => '#C6A135'],
    'termine'    => ['label' => 'Termine',              'farbe' => '#64748B'],
];
const KAL_SICHTBAR = [
    'privat'  => 'Nur für mich',
    'trainer' => 'Alle Trainer:innen',
    'alle'    => 'Alle (auch Mitglieder)',
];
const KAL_WIEDERHOLUNG = [
    'keine'        => 'Einmalig',
    'woechentlich' => 'Jede Woche',
    '14taegig'     => 'Alle 2 Wochen',
    'monatlich'    => 'Jeden Monat',
];
const KAL_ZEITZONE = 'Europe/Vienna';

/**
 * Alle sichtbaren Einträge im Zeitraum [von, bis] (Y-m-d), sortiert.
 * Parameter explizit, damit auch der Abo-Feed ohne Login funktioniert.
 */
function kalEintraege(PDO $db, int $org_id, int $user_id, bool $trainer, string $von, string $bis, array $quellen = ['kurse', 'diagnostik', 'termine']): array
{
    $von_dt = $von . ' 00:00:00';
    $bis_dt = $bis . ' 23:59:59';
    $liste = [];

    if (in_array('termine', $quellen, true)) {
        try {
            $sicht = $trainer ? "(t.sichtbar IN ('trainer','alle') OR t.erstellt_von = ? OR t.mitglied_id = ?)" : "(t.sichtbar = 'alle' OR t.mitglied_id = ?)";
            $stmt = $db->prepare("SELECT t.*, m.vorname AS m_vorname, m.nachname AS m_nachname
                                  FROM termine t LEFT JOIN users m ON m.id = t.mitglied_id
                                  WHERE t.organization_id = ? AND {$sicht} AND t.start <= ?
                                    AND (t.ende >= ? OR (t.wiederholung <> 'keine' AND (t.wiederholung_bis IS NULL OR t.wiederholung_bis >= ?)))");
            $stmt->execute(array_merge([$org_id], $trainer ? [$user_id, $user_id] : [$user_id], [$bis_dt, $von_dt, $von]));
            foreach ($stmt->fetchAll() as $t) {
                foreach (kalWiederholungen($t, $von_dt, $bis_dt) as [$start, $ende]) {
                    $typ = KAL_TYPEN[$t['typ']] ?? KAL_TYPEN['termin'];
                    $liste[] = [
                        'uid'       => 'termin-' . $t['id'] . '-' . substr($start, 0, 10),
                        'quelle'    => 'termine',
                        'titel'     => $t['titel'] . ($t['m_vorname'] && $trainer ? ' (' . $t['m_vorname'] . ' ' . $t['m_nachname'] . ')' : ''),
                        'start'     => $start,
                        'ende'      => $ende,
                        'ganztags'  => (bool)$t['ganztags'],
                        'ort'       => $t['ort'],
                        'beschreibung' => $t['beschreibung'],
                        'typ'       => $typ['label'],
                        'farbe'     => $typ['farbe'],
                        'url'       => '/dashboard/kalender.php?termin=' . $t['id'],
                        'abgesagt'  => false,
                        'wiederkehrend' => $t['wiederholung'] !== 'keine',
                    ];
                }
            }
        } catch (PDOException $e) { /* Migration 009 fehlt */ }
    }

    if (in_array('kurse', $quellen, true)) {
        if ($trainer) {
            $stmt = $db->prepare("SELECT k.*, u.vorname AS t_vorname, u.nachname AS t_nachname,
                                         (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status IN ('angemeldet','teilgenommen')) AS teilnehmer
                                  FROM kurse k LEFT JOIN users u ON u.id = k.trainer_id
                                  WHERE k.organization_id = ? AND k.start_datum <= ? AND k.end_datum >= ?");
            $stmt->execute([$org_id, $bis_dt, $von_dt]);
        } else {
            $stmt = $db->prepare("SELECT k.*, u.vorname AS t_vorname, u.nachname AS t_nachname, NULL AS teilnehmer
                                  FROM kurse k JOIN kurs_anmeldungen ka ON ka.kurs_id = k.id AND ka.user_id = ? AND ka.status IN ('angemeldet','teilgenommen','warteliste')
                                  LEFT JOIN users u ON u.id = k.trainer_id
                                  WHERE k.organization_id = ? AND k.start_datum <= ? AND k.end_datum >= ?");
            $stmt->execute([$user_id, $org_id, $bis_dt, $von_dt]);
        }
        foreach ($stmt->fetchAll() as $k) {
            $info = array_filter([
                $k['t_vorname'] ? 'Trainer:in: ' . $k['t_vorname'] . ' ' . $k['t_nachname'] : null,
                $k['teilnehmer'] !== null ? 'Anmeldungen: ' . (int)$k['teilnehmer'] . ($k['max_teilnehmer'] ? ' / ' . (int)$k['max_teilnehmer'] : '') : null,
                $k['beschreibung'] ?? null,
            ]);
            $liste[] = [
                'uid' => 'kurs-' . $k['id'], 'quelle' => 'kurse', 'titel' => $k['titel'],
                'start' => $k['start_datum'], 'ende' => $k['end_datum'], 'ganztags' => false,
                'ort' => $k['ort'] ?? null, 'beschreibung' => implode("\n", $info), 'typ' => 'Kurs',
                'farbe' => KAL_QUELLEN['kurse']['farbe'], 'url' => '/dashboard/kurs-detail.php?id=' . $k['id'],
                'abgesagt' => $k['status'] === 'abgesagt', 'wiederkehrend' => false,
                'eigen' => (int)$k['trainer_id'] === $user_id,
            ];
        }
    }

    if (in_array('diagnostik', $quellen, true)) {
        try {
            $stmt = $db->prepare("SELECT s.*, m.vorname, m.nachname FROM ld_sitzungen s JOIN users m ON m.id = s.mitglied_id
                                  WHERE s.organization_id = ? AND s.datum BETWEEN ? AND ?" . ($trainer ? '' : ' AND s.mitglied_id = ?'));
            $stmt->execute($trainer ? [$org_id, $von, $bis] : [$org_id, $von, $bis, $user_id]);
            foreach ($stmt->fetchAll() as $s) {
                $mit_zeit = !empty($s['uhrzeit']);
                $start = $s['datum'] . ' ' . ($mit_zeit ? substr($s['uhrzeit'], 0, 8) : '00:00:00');
                $liste[] = [
                    'uid' => 'diagnostik-' . $s['id'], 'quelle' => 'diagnostik',
                    'titel' => 'Leistungsdiagnostik' . ($trainer ? ': ' . $s['vorname'] . ' ' . $s['nachname'] : ''),
                    'start' => $start, 'ende' => $mit_zeit ? date('Y-m-d H:i:s', strtotime($start . ' +90 minutes')) : $s['datum'] . ' 23:59:59',
                    'ganztags' => !$mit_zeit, 'ort' => $s['ort'], 'beschreibung' => $s['bedingungen'],
                    'typ' => $s['status'] === 'durchgefuehrt' ? 'Testung (durchgeführt)' : 'Testung (geplant)',
                    'farbe' => KAL_QUELLEN['diagnostik']['farbe'], 'url' => '/dashboard/leistungstest.php?id=' . $s['id'],
                    'abgesagt' => false, 'wiederkehrend' => false,
                ];
            }
        } catch (PDOException $e) { /* Migration 009 fehlt */ }
    }

    usort($liste, fn($a, $b) => [$a['start'], $a['titel']] <=> [$b['start'], $b['titel']]);
    return $liste;
}

/** Vorkommen eines (wiederkehrenden) Termins im Zeitraum als [start, ende]. */
function kalWiederholungen(array $t, string $von_dt, string $bis_dt): array
{
    $start = new DateTime($t['start']);
    $dauer = strtotime($t['ende']) - strtotime($t['start']);
    if ($t['wiederholung'] === 'keine') return [[$t['start'], $t['ende']]];

    $grenze = $t['wiederholung_bis'] ? min($t['wiederholung_bis'] . ' 23:59:59', $bis_dt) : $bis_dt;
    $vorkommen = [];
    for ($i = 0; $i < 600; $i++) {
        $s = clone $start;
        match ($t['wiederholung']) {
            'woechentlich' => $s->modify('+' . $i . ' week'),
            '14taegig'     => $s->modify('+' . ($i * 2) . ' week'),
            'monatlich'    => $s->modify('+' . $i . ' month'),
            default        => null,
        };
        $s_str = $s->format('Y-m-d H:i:s');
        if ($s_str > $grenze) break;
        // Monatlich am 29.–31.: Monate ohne diesen Tag auslassen statt in den Folgemonat zu rutschen
        if ($t['wiederholung'] === 'monatlich' && $s->format('d') !== $start->format('d')) continue;
        $e_str = date('Y-m-d H:i:s', $s->getTimestamp() + $dauer);
        if ($e_str >= $von_dt) $vorkommen[] = [$s_str, $e_str];
    }
    return $vorkommen;
}

/** Einträge nach Tag gruppiert (mehrtägige erscheinen an jedem Tag). */
function kalJeTag(array $eintraege, string $von, string $bis): array
{
    $tage = [];
    foreach ($eintraege as $e) {
        $tag = max(substr($e['start'], 0, 10), $von);
        $letzter = min(substr($e['ende'], 0, 10), $bis);
        if ($letzter < $tag) $letzter = $tag;
        for ($d = $tag; $d <= $letzter; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $tage[$d][] = $e;
    }
    return $tage;
}

/** Zeitangabe für die Anzeige. */
function kalZeit(array $e): string
{
    if ($e['ganztags']) return 'ganztägig';
    $von = date('H:i', strtotime($e['start']));
    $bis = date('H:i', strtotime($e['ende']));
    if (substr($e['start'], 0, 10) !== substr($e['ende'], 0, 10)) return $von . ' – ' . date('d.m. H:i', strtotime($e['ende']));
    return $von === $bis ? $von : $von . '–' . $bis;
}

// ----------------------------------------------------------------
// iCalendar (RFC 5545)
// ----------------------------------------------------------------

function kalIcsText(?string $text): string
{
    return str_replace(["\\", "\r\n", "\n", ',', ';'], ["\\\\", '\\n', '\\n', '\\,', '\\;'], (string)$text);
}

/** Zeilen länger als 75 Byte falten (Folgezeilen beginnen mit Leerzeichen). */
function kalIcsZeile(string $zeile): string
{
    $out = '';
    while (strlen($zeile) > 75) {
        $teil = mb_strcut($zeile, 0, 75, 'UTF-8');
        $out .= $teil . "\r\n ";
        $zeile = substr($zeile, strlen($teil));
    }
    return $out . $zeile . "\r\n";
}

function kalIcsUtc(string $lokal): string
{
    $dt = new DateTime($lokal, new DateTimeZone(KAL_ZEITZONE));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\THis\Z');
}

function kalIcs(array $eintraege, string $name, string $basis_url): string
{
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Athletikclub Steiermark//Kalender//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
    $ics .= kalIcsZeile('X-WR-CALNAME:' . kalIcsText($name));
    $ics .= 'X-WR-TIMEZONE:' . KAL_ZEITZONE . "\r\nREFRESH-INTERVAL;VALUE=DURATION:PT1H\r\nX-PUBLISHED-TTL:PT1H\r\n";
    $jetzt = gmdate('Ymd\THis\Z');
    foreach ($eintraege as $e) {
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= kalIcsZeile('UID:' . $e['uid'] . '@aci-stmk.at');
        $ics .= "DTSTAMP:{$jetzt}\r\n";
        if ($e['ganztags']) {
            $ics .= 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($e['start'])) . "\r\n";
            $ics .= 'DTEND;VALUE=DATE:' . date('Ymd', strtotime(substr($e['ende'], 0, 10) . ' +1 day')) . "\r\n";
        } else {
            $ics .= 'DTSTART:' . kalIcsUtc($e['start']) . "\r\n";
            $ics .= 'DTEND:' . kalIcsUtc($e['ende']) . "\r\n";
        }
        $ics .= kalIcsZeile('SUMMARY:' . kalIcsText(($e['abgesagt'] ? 'ABGESAGT: ' : '') . $e['titel']));
        if ($e['ort']) $ics .= kalIcsZeile('LOCATION:' . kalIcsText($e['ort']));
        $beschreibung = trim($e['typ'] . "\n" . ($e['beschreibung'] ?? ''));
        $ics .= kalIcsZeile('DESCRIPTION:' . kalIcsText($beschreibung));
        $ics .= kalIcsZeile('URL:' . $basis_url . $e['url']);
        $ics .= kalIcsZeile('CATEGORIES:' . kalIcsText($e['typ']));
        if ($e['abgesagt']) $ics .= "STATUS:CANCELLED\r\n";
        $ics .= "END:VEVENT\r\n";
    }
    return $ics . "END:VCALENDAR\r\n";
}
