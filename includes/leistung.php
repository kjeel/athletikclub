<?php
/**
 * Athletikclub Steiermark – Leistungsdiagnostik
 * Testkatalog, Testungen, Verlauf/Leistungssteigerung und JASP-Export.
 */

const LD_KATEGORIEN = [
    'koerper'       => 'Körper',
    'kraft'         => 'Kraft & Kraftausdauer',
    'schnellkraft'  => 'Schnellkraft',
    'schnelligkeit' => 'Schnelligkeit & Agilität',
    'ausdauer'      => 'Ausdauer',
    'beweglichkeit' => 'Beweglichkeit',
    'koordination'  => 'Koordination & Gleichgewicht',
    'skills'        => 'Calisthenics-Skills',
];

const LD_RICHTUNGEN = [
    'hoeher'    => 'Höherer Wert ist besser',
    'niedriger' => 'Niedrigerer Wert ist besser',
    'neutral'   => 'Keine Wertung (z.B. Körpergewicht)',
];

/** Vorlagen für Testbatterien (Testnamen aus dem Startbestand). */
const LD_BATTERIEN = [
    'calisthenics' => ['label' => 'Calisthenics-Basis',     'tests' => ['Klimmzüge max.', 'Liegestütz max.', 'Dips max.', 'Unterarmstütz (Plank)', 'Hollow Body Hold', 'L-Sit']],
    'athletik'     => ['label' => 'Athletik',               'tests' => ['Counter Movement Jump', 'Standweitsprung', 'Sprint 10 m', 'Sprint 20 m', 'T-Test (Agilität)', 'Sit-and-Reach']],
    'ausdauer'     => ['label' => 'Ausdauer',               'tests' => ['Ruhepuls', 'Cooper-Test (12 min)', 'Shuttle-Run (Beep-Test)']],
    'koerper'      => ['label' => 'Körperdaten',            'tests' => ['Körpergewicht', 'Körperfettanteil', 'Taillenumfang', 'Ruhepuls']],
    'kinder'       => ['label' => 'Kinder & Jugend',        'tests' => ['Standweitsprung', 'Sprint 20 m', 'Seitliches Hin- und Herspringen', 'Einbeinstand Augen zu', 'Rumpfbeuge stehend', 'Liegestütz in 60 s', '6-Minuten-Lauf']],
];

const LD_HINWEIS = 'Vergleichbar sind nur Messungen unter gleichen Bedingungen: gleiche Tageszeit, gleiches Aufwärmen, gleiche Messmethode und Ausrüstung. Testungen nur bei Beschwerdefreiheit durchführen.';

/** Aktive Tests der Organisation, nach Kategorie und Sortierung. */
function ldTests(PDO $db, bool $nur_aktive = true): array
{
    $stmt = $db->prepare('SELECT * FROM ld_tests WHERE organization_id = ?' . ($nur_aktive ? ' AND aktiv = 1' : '') . ' ORDER BY sortierung, name');
    $stmt->execute([currentOrgId()]);
    $tests = [];
    foreach ($stmt->fetchAll() as $t) $tests[(int)$t['id']] = $t;
    return $tests;
}

/** Wert mit der Nachkommastellenzahl des Tests (österreichisches Format). */
function ldWert($wert, array $test, bool $mit_einheit = true): string
{
    if ($wert === null || $wert === '') return '–';
    $text = number_format((float)$wert, (int)$test['dezimalen'], ',', '.');
    return $mit_einheit ? $text . ' ' . $test['einheit'] : $text;
}

/** Eingabe „12,5“ oder „12.5“ → float|null. */
function ldZahl($eingabe): ?float
{
    $eingabe = trim(str_replace([' ', ','], ['', '.'], (string)$eingabe));
    return $eingabe !== '' && is_numeric($eingabe) ? (float)$eingabe : null;
}

/**
 * Veränderung in Prozent, so dass positiv immer „besser“ bedeutet
 * (bei „niedriger ist besser“ wird das Vorzeichen gedreht). Neutral: roh.
 */
function ldVeraenderung(?float $von, ?float $bis, string $richtung): ?float
{
    if ($von === null || $bis === null || $von == 0.0) return null;
    $prozent = ($bis - $von) / abs($von) * 100;
    return round($richtung === 'niedriger' ? -$prozent : $prozent, 1);
}

/** Darf der/die aktuelle Nutzer:in die Daten dieses Mitglieds sehen? */
function ldZugriff(int $mitglied_id): bool
{
    return isTrainer() || $mitglied_id === (int)getCurrentUserId();
}

/** Mitglied (nur eigene Organisation). */
function ldMitglied(PDO $db, int $mitglied_id): ?array
{
    $stmt = $db->prepare("SELECT u.id, u.vorname, u.nachname, u.email, mp.geburtsdatum
                          FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
                          WHERE u.id = ? AND u.organization_id = ? AND u.rolle = 'mitglied' LIMIT 1");
    $stmt->execute([$mitglied_id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/** Testung inkl. Mitglied/Trainer:in laden (mit Zugriffsprüfung). */
function ldSitzung(PDO $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT s.*, m.vorname AS m_vorname, m.nachname AS m_nachname, t.vorname AS t_vorname, t.nachname AS t_nachname
                          FROM ld_sitzungen s JOIN users m ON m.id = s.mitglied_id JOIN users t ON t.id = s.trainer_id
                          WHERE s.id = ? AND s.organization_id = ? LIMIT 1");
    $stmt->execute([$id, currentOrgId()]);
    $s = $stmt->fetch();
    return $s && ldZugriff((int)$s['mitglied_id']) ? $s : null;
}

/**
 * Alle Messwerte eines Mitglieds je Test, chronologisch:
 * [test_id => [['datum' => …, 'wert' => float, 'sitzung_id' => …], …]]
 */
function ldVerlauf(PDO $db, int $mitglied_id): array
{
    $stmt = $db->prepare("SELECT e.test_id, e.wert, s.datum, s.id AS sitzung_id
                          FROM ld_ergebnisse e JOIN ld_sitzungen s ON s.id = e.sitzung_id
                          WHERE s.mitglied_id = ? AND s.organization_id = ? AND e.wert IS NOT NULL
                          ORDER BY s.datum, s.id");
    $stmt->execute([$mitglied_id, currentOrgId()]);
    $verlauf = [];
    foreach ($stmt->fetchAll() as $r) {
        $verlauf[(int)$r['test_id']][] = ['datum' => $r['datum'], 'wert' => (float)$r['wert'], 'sitzung_id' => (int)$r['sitzung_id']];
    }
    return $verlauf;
}

/** Kennzahlen einer Messreihe: erster, letzter, bester Wert, Veränderung. */
function ldReihe(array $reihe, array $test): array
{
    $werte = array_column($reihe, 'wert');
    $bester = match ($test['richtung']) { 'niedriger' => min($werte), 'hoeher' => max($werte), default => end($werte) };
    $erster = $reihe[0]['wert'];
    $letzter = end($reihe)['wert'];
    $vorletzter = count($reihe) > 1 ? $reihe[count($reihe) - 2]['wert'] : null;
    return [
        'anzahl'        => count($reihe),
        'erster'        => $erster,
        'letzter'       => $letzter,
        'bester'        => $bester,
        'gesamt'        => count($reihe) > 1 ? ldVeraenderung($erster, $letzter, $test['richtung']) : null,
        'zuletzt'       => $vorletzter !== null ? ldVeraenderung($vorletzter, $letzter, $test['richtung']) : null,
        'differenz'     => $letzter - $erster,
        'ist_bestwert'  => count($reihe) > 1 && $letzter == $bester && $test['richtung'] !== 'neutral',
    ];
}

/**
 * Gruppenfortschritt je Test: Personen mit mindestens zwei Messungen,
 * Ø erster/letzter Wert, Ø Veränderung und Anteil verbessert.
 */
function ldGruppenfortschritt(PDO $db, array $tests, ?int $trainer_id = null): array
{
    $sql = "SELECT s.mitglied_id, e.test_id, e.wert, s.datum
            FROM ld_ergebnisse e JOIN ld_sitzungen s ON s.id = e.sitzung_id
            WHERE s.organization_id = ? AND e.wert IS NOT NULL" . ($trainer_id ? ' AND s.trainer_id = ?' : '') . '
            ORDER BY s.datum, s.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($trainer_id ? [currentOrgId(), $trainer_id] : [currentOrgId()]);
    $reihen = [];
    foreach ($stmt->fetchAll() as $r) $reihen[(int)$r['test_id']][(int)$r['mitglied_id']][] = (float)$r['wert'];

    $ergebnis = [];
    foreach ($reihen as $tid => $personen) {
        if (!isset($tests[$tid])) continue;
        $t = $tests[$tid];
        $erste = $letzte = $veraenderungen = [];
        $verbessert = 0;
        foreach ($personen as $werte) {
            if (count($werte) < 2) continue;
            $erste[] = $werte[0];
            $letzte[] = end($werte);
            $v = ldVeraenderung($werte[0], end($werte), $t['richtung']);
            if ($v !== null) $veraenderungen[] = $v;
            if ($t['richtung'] !== 'neutral' && $v !== null && $v > 0) $verbessert++;
        }
        $ergebnis[$tid] = [
            'test'        => $t,
            'personen'    => count($personen),
            'mit_verlauf' => count($erste),
            'erster_avg'  => $erste ? array_sum($erste) / count($erste) : null,
            'letzter_avg' => $letzte ? array_sum($letzte) / count($letzte) : null,
            'veraenderung'=> $veraenderungen ? round(array_sum($veraenderungen) / count($veraenderungen), 1) : null,
            'verbessert'  => $t['richtung'] !== 'neutral' && $erste ? round($verbessert / count($erste) * 100) : null,
        ];
    }
    uasort($ergebnis, fn($a, $b) => [$a['test']['sortierung'], $a['test']['name']] <=> [$b['test']['sortierung'], $b['test']['name']]);
    return $ergebnis;
}

/** Variablenname für JASP/Statistikprogramme: ASCII, ohne Leerzeichen, stabil. */
function ldVariable(string $name): string
{
    $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', '×' => 'x']);
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
    $name = trim($name, '_');
    return preg_match('/^[0-9]/', $name) ? 'T_' . $name : $name;
}

/** Eindeutige Variablennamen für eine Testliste (bei Namensgleichheit mit ID). */
function ldVariablen(array $tests): array
{
    $namen = [];
    $gezaehlt = array_count_values(array_map(fn($t) => ldVariable($t['name']), $tests));
    foreach ($tests as $id => $t) {
        $v = ldVariable($t['name']);
        $namen[$id] = $gezaehlt[$v] > 1 ? $v . '_' . $id : $v;
    }
    return $namen;
}

function ldAltersgruppe(?int $alter): string
{
    if ($alter === null) return '';
    return match (true) {
        $alter < 10 => 'bis 9', $alter < 15 => '10-14', $alter < 19 => '15-18', $alter < 30 => '19-29',
        $alter < 45 => '30-44', $alter < 60 => '45-59', default => '60+',
    };
}
