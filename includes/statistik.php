<?php
/**
 * Athletikclub Steiermark – Statistik & Auswertungen
 *
 * Die Rohdaten werden einmal geladen und in PHP ausgewertet (unabhängig vom
 * Datenbank-Dialekt; bei Vereinsgröße sind die Datenmengen klein). So lassen
 * sich derselbe Zeitraum, der Vergleichszeitraum und jede Trainer:in mit
 * identischer Logik auswerten.
 *
 * Umsatz wie in Umsatz- und Abrechnungsübersicht:
 *  - bezahlte Kursanmeldungen × Kurspreis (Datum: bezahlt_am, sonst Kursbeginn)
 *  - manuelle Umsatzeinträge (Leistungsdatum)
 * Kund:innen einer Trainer:in = Mitglieder mit Anmeldung in ihren/seinen Kursen
 * oder mit einem Trainings-/Ernährungsplan von ihr/ihm.
 */

require_once ROOT_PATH . '/includes/money.php';

const STAT_ZEITRAEUME = [
    '30t'     => 'Letzte 30 Tage',
    '90t'     => 'Letzte 3 Monate',
    '12m'     => 'Letzte 12 Monate',
    'jahr'    => 'Dieses Jahr',
    'vorjahr' => 'Letztes Jahr',
    'alles'   => 'Gesamter Zeitraum',
    'frei'    => 'Eigener Zeitraum',
];

const STAT_ALTERSGRUPPEN = [
    'bis 9'  => [0, 9],
    '10–14'  => [10, 14],
    '15–18'  => [15, 18],
    '19–29'  => [19, 29],
    '30–44'  => [30, 44],
    '45–59'  => [45, 59],
    '60+'    => [60, 150],
];

const STAT_TAGESZEITEN = [
    'morgens'     => 'bis 12 Uhr',
    'mittags'     => '12–15 Uhr',
    'nachmittags' => '15–18 Uhr',
    'abends'      => 'ab 18 Uhr',
];

const STAT_WOCHENTAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

const STAT_ANMELDESTATUS = [
    'angemeldet'   => 'Angemeldet',
    'teilgenommen' => 'Teilgenommen',
    'warteliste'   => 'Warteliste',
    'storniert'    => 'Storniert',
];

/** Kund:innen ohne Buchung seit so vielen Tagen gelten als „wieder ansprechen“. */
const STAT_INAKTIV_TAGE = 60;

// ----------------------------------------------------------------
// Zeitraum
// ----------------------------------------------------------------

/**
 * Ermittelt Auswertungs- und Vergleichszeitraum aus den GET-Parametern.
 * Vergleich: gleich langer Zeitraum direkt davor; bei „Dieses Jahr“ derselbe
 * Zeitraum im Vorjahr, bei „Letztes Jahr“ das Jahr davor.
 */
function statZeitraum(array $q, ?string $heute = null): array
{
    $heute  = $heute ?? date('Y-m-d');
    $preset = isset(STAT_ZEITRAEUME[$q['zeitraum'] ?? '']) ? $q['zeitraum'] : '12m';
    $jahr   = (int)substr($heute, 0, 4);
    $datum  = fn($s) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) && strtotime($s) ? $s : null;

    if ($preset === 'frei' && (!$datum($q['von'] ?? '') || !$datum($q['bis'] ?? ''))) $preset = '12m';

    switch ($preset) {
        case '30t':     $von = date('Y-m-d', strtotime("{$heute} -29 days")); $bis = $heute; break;
        case '90t':     $von = date('Y-m-d', strtotime("{$heute} -3 months +1 day")); $bis = $heute; break;
        case 'jahr':    $von = "{$jahr}-01-01"; $bis = $heute; break;
        case 'vorjahr': $von = ($jahr - 1) . '-01-01'; $bis = ($jahr - 1) . '-12-31'; break;
        case 'alles':   $von = '2000-01-01'; $bis = $heute; break;
        case 'frei':
            [$von, $bis] = [$q['von'], $q['bis']];
            if ($von > $bis) [$von, $bis] = [$bis, $von];
            break;
        default:        $von = date('Y-m-01', strtotime(substr($heute, 0, 7) . '-01 -11 months')); $bis = $heute;
    }

    $v_von = $v_bis = null;
    if ($preset === 'jahr' || $preset === 'vorjahr') {
        $v_von = date('Y-m-d', strtotime("{$von} -1 year"));
        $v_bis = date('Y-m-d', strtotime("{$bis} -1 year"));
    } elseif ($preset !== 'alles') {
        $tage  = (int)round((strtotime($bis) - strtotime($von)) / 86400);
        $v_bis = date('Y-m-d', strtotime("{$von} -1 day"));
        $v_von = date('Y-m-d', strtotime("{$v_bis} -{$tage} days"));
    }

    return [
        'preset' => $preset, 'von' => $von, 'bis' => $bis, 'heute' => $heute,
        'vergleich_von' => $v_von, 'vergleich_bis' => $v_bis,
        'label' => $preset === 'frei' ? date('d.m.Y', strtotime($von)) . ' – ' . date('d.m.Y', strtotime($bis)) : STAT_ZEITRAEUME[$preset],
    ];
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------

/**
 * Lädt alle für die Statistik nötigen Rohdaten der Organisation
 * (optional nur einer Trainer:in).
 */
function statLaden(PDO $db, int $org_id, ?int $trainer_id = null): array
{
    $k_filter = $trainer_id ? ' AND k.trainer_id = ?' : '';
    $params   = $trainer_id ? [$org_id, $trainer_id] : [$org_id];

    $stmt = $db->prepare("SELECT k.id, k.titel, k.sportart, k.start_datum, k.end_datum, k.preis, k.max_teilnehmer, k.status, k.trainer_id
                          FROM kurse k WHERE k.organization_id = ?{$k_filter}");
    $stmt->execute($params);
    $kurse = [];
    foreach ($stmt->fetchAll() as $k) $kurse[(int)$k['id']] = $k;

    $stmt = $db->prepare("SELECT ka.kurs_id, ka.user_id, ka.status, ka.bezahlt, ka.bezahlt_am, ka.angemeldet_am
                          FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                          WHERE k.organization_id = ?{$k_filter}");
    $stmt->execute($params);
    $buchungen = [];
    foreach ($stmt->fetchAll() as $b) {
        $k = $kurse[(int)$b['kurs_id']] ?? null;
        if (!$k) continue;
        $b['kurs']       = $k;
        $b['kurs_datum'] = substr((string)$k['start_datum'], 0, 10);
        $b['trainer_id'] = $k['trainer_id'] !== null ? (int)$k['trainer_id'] : 0;
        $buchungen[] = $b;
    }

    $stmt = $db->prepare('SELECT trainer_id, beschreibung, betrag, leistungsdatum FROM umsatz_eintraege WHERE organization_id = ?' . ($trainer_id ? ' AND trainer_id = ?' : ''));
    $stmt->execute($params);
    $manuell = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT u.id, u.vorname, u.nachname, u.aktiv, COALESCE(tp.provisionssatz, 80) AS provisionssatz
                          FROM users u LEFT JOIN trainer_profile tp ON tp.user_id = u.id
                          WHERE u.organization_id = ? AND u.rolle IN ('trainer', 'admin')
                          ORDER BY u.nachname, u.vorname");
    $stmt->execute([$org_id]);
    $trainer = [];
    foreach ($stmt->fetchAll() as $t) $trainer[(int)$t['id']] = $t;

    $stmt = $db->prepare("SELECT u.id, u.vorname, u.nachname, u.aktiv, u.created_at,
                                 mp.geburtsdatum, mp.ort, mp.plz, mp.sportarten, mp.mitgliedsstatus, mp.mitglied_seit
                          FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id
                          WHERE u.organization_id = ? AND u.rolle = 'mitglied'");
    $stmt->execute([$org_id]);
    $mitglieder = [];
    foreach ($stmt->fetchAll() as $m) $mitglieder[(int)$m['id']] = $m;

    // Trainings-/Ernährungspläne (Migration 008 – fehlt sie, bleibt der Bereich leer)
    $plaene = $protokoll = $uebungen = [];
    $plaene_verfuegbar = true;
    try {
        $p_filter = $trainer_id ? ' AND p.trainer_id = ?' : '';
        foreach (['training' => 'trainingsplaene', 'ernaehrung' => 'ernaehrungsplaene'] as $typ => $tabelle) {
            $stmt = $db->prepare("SELECT '{$typ}' AS typ, p.id, p.trainer_id, p.mitglied_id, p.ziel, p.status, p.created_at
                                  FROM {$tabelle} p WHERE p.organization_id = ?{$p_filter}");
            $stmt->execute($params);
            $plaene = array_merge($plaene, $stmt->fetchAll());
        }
        $stmt = $db->prepare("SELECT pr.datum, pr.user_id, pr.rpe, p.trainer_id
                              FROM trainingsplan_protokoll pr JOIN trainingsplaene p ON p.id = pr.plan_id
                              WHERE p.organization_id = ?{$p_filter}");
        $stmt->execute($params);
        $protokoll = $stmt->fetchAll();
        $stmt = $db->prepare("SELECT tu.uebung_name, p.trainer_id
                              FROM trainingsplan_uebungen tu
                              JOIN trainingsplan_einheiten e ON e.id = tu.einheit_id
                              JOIN trainingsplaene p ON p.id = e.plan_id
                              WHERE p.organization_id = ? AND p.mitglied_id IS NOT NULL{$p_filter}");
        $stmt->execute($params);
        $uebungen = $stmt->fetchAll();
    } catch (PDOException $e) {
        $plaene_verfuegbar = false;
    }

    return compact('kurse', 'buchungen', 'manuell', 'trainer', 'mitglieder', 'plaene', 'protokoll', 'uebungen', 'plaene_verfuegbar')
         + ['trainer_filter' => $trainer_id];
}

/** Teilmenge der Daten für eine einzelne Trainer:in (für die Trainer-Übersicht). */
function statFiltern(array $d, int $trainer_id): array
{
    $gleich = fn($row) => (int)($row['trainer_id'] ?? 0) === $trainer_id;
    $d['kurse']          = array_filter($d['kurse'], $gleich);
    $d['buchungen']      = array_values(array_filter($d['buchungen'], $gleich));
    $d['manuell']        = array_values(array_filter($d['manuell'], $gleich));
    $d['plaene']         = array_values(array_filter($d['plaene'], $gleich));
    $d['protokoll']      = array_values(array_filter($d['protokoll'], $gleich));
    $d['uebungen']       = array_values(array_filter($d['uebungen'], $gleich));
    $d['trainer_filter'] = $trainer_id;
    return $d;
}

/** Frühestes Datum in den Daten (für „Gesamter Zeitraum“). */
function statErstesDatum(array $d): ?string
{
    $daten = array_merge(
        array_map(fn($k) => substr((string)$k['start_datum'], 0, 10), $d['kurse']),
        array_map(fn($m) => (string)$m['leistungsdatum'], $d['manuell']),
        array_map(fn($p) => substr((string)$p['created_at'], 0, 10), $d['plaene']),
    );
    $daten = array_filter($daten);
    return $daten ? min($daten) : null;
}

// ----------------------------------------------------------------
// Hilfsfunktionen
// ----------------------------------------------------------------

function statImZeitraum(?string $datum, string $von, string $bis): bool
{
    if (!$datum) return false;
    $datum = substr($datum, 0, 10);
    return $datum >= $von && $datum <= $bis;
}

/** Anteil in Prozent (null, wenn keine Basis vorhanden). */
function statQuote(float|int $teil, float|int $basis): ?float
{
    return $basis > 0 ? round($teil / $basis * 100, 1) : null;
}

/** Veränderung in Prozent gegenüber dem Vergleichswert (null ohne Basis). */
function statDelta(float|int|string|null $neu, float|int|string|null $alt): ?float
{
    if ($neu === null || $alt === null || (float)$alt == 0.0) return null;
    return round(((float)$neu - (float)$alt) / abs((float)$alt) * 100, 1);
}

function statAlter(?string $geburtsdatum, string $stichtag): ?int
{
    if (!$geburtsdatum || !strtotime($geburtsdatum)) return null;
    $alter = (int)date_diff(date_create($geburtsdatum), date_create($stichtag))->y;
    return $alter >= 0 && $alter < 120 ? $alter : null;
}

function statTageszeit(string $datetime): string
{
    $stunde = (int)date('G', strtotime($datetime));
    return $stunde < 12 ? 'morgens' : ($stunde < 15 ? 'mittags' : ($stunde < 18 ? 'nachmittags' : 'abends'));
}

/** Aktive (nicht stornierte, nicht wartende) Buchung? */
function statBuchungAktiv(array $b): bool
{
    return in_array($b['status'], ['angemeldet', 'teilgenommen'], true);
}

/** Alle Umsatzereignisse (Kurs + manuell) mit Datum, Betrag, Trainer:in, Kund:in, Sportart. */
function statUmsaetze(array $d): array
{
    $liste = [];
    foreach ($d['buchungen'] as $b) {
        if (!(int)$b['bezahlt'] || (float)$b['kurs']['preis'] <= 0) continue;
        $liste[] = [
            'datum'      => substr((string)($b['bezahlt_am'] ?: $b['kurs']['start_datum']), 0, 10),
            'betrag'     => moneyRound((string)$b['kurs']['preis']),
            'art'        => 'kurs',
            'trainer_id' => $b['trainer_id'],
            'user_id'    => (int)$b['user_id'],
            'sportart'   => trim((string)$b['kurs']['sportart']) ?: 'Ohne Angabe',
            'kurs_id'    => (int)$b['kurs_id'],
        ];
    }
    foreach ($d['manuell'] as $m) {
        $liste[] = [
            'datum' => (string)$m['leistungsdatum'], 'betrag' => moneyRound((string)$m['betrag']), 'art' => 'manuell',
            'trainer_id' => (int)$m['trainer_id'], 'user_id' => null, 'sportart' => null, 'kurs_id' => null,
        ];
    }
    return $liste;
}

/** Erster Kontakt jeder Kund:in im Datenbestand (erste Anmeldung oder erster Plan). */
function statErsterKontakt(array $d): array
{
    $erst = [];
    $merke = function (int $uid, ?string $datum) use (&$erst) {
        if (!$uid || !$datum) return;
        $datum = substr($datum, 0, 10);
        if (!isset($erst[$uid]) || $datum < $erst[$uid]) $erst[$uid] = $datum;
    };
    foreach ($d['buchungen'] as $b) if ($b['status'] !== 'storniert') $merke((int)$b['user_id'], $b['angemeldet_am'] ?: $b['kurs']['start_datum']);
    foreach ($d['plaene'] as $p) if ($p['mitglied_id']) $merke((int)$p['mitglied_id'], $p['created_at']);
    return $erst;
}

// ----------------------------------------------------------------
// Kennzahlen eines Zeitraums
// ----------------------------------------------------------------

function statKennzahlen(array $d, string $von, string $bis): array
{
    $jetzt = date('Y-m-d H:i:s');

    // Umsatz & Provision
    $kurs = $manuell = [];
    $pro_trainer = [];
    foreach (statUmsaetze($d) as $u) {
        if (!statImZeitraum($u['datum'], $von, $bis)) continue;
        if ($u['art'] === 'kurs') $kurs[] = $u['betrag']; else $manuell[] = $u['betrag'];
        $pro_trainer[$u['trainer_id']][] = $u['betrag'];
    }
    $provision = [];
    foreach ($pro_trainer as $tid => $betraege) {
        $satz = $d['trainer'][$tid]['provisionssatz'] ?? 0; // Kurse ohne Trainer:in: kein Provisionsanteil
        $provision[] = moneyPercent(moneySum($betraege), $satz);
    }
    $umsatz_kurs    = moneySum($kurs);
    $umsatz_manuell = moneySum($manuell);
    $umsatz         = moneySum([$umsatz_kurs, $umsatz_manuell]);
    $provision      = moneySum($provision);

    // Kurse & Buchungen (Zuordnung über den Kursbeginn)
    $kurse_p = array_filter($d['kurse'], fn($k) => statImZeitraum($k['start_datum'], $von, $bis));
    $kurse_durchgefuehrt = array_filter($kurse_p, fn($k) => $k['status'] !== 'abgesagt');
    $buchungen_p = array_values(array_filter($d['buchungen'], fn($b) => statImZeitraum($b['kurs_datum'], $von, $bis)));

    $status = array_fill_keys(array_keys(STAT_ANMELDESTATUS), 0);
    $belegt = [];
    $kunden_buchungen = [];
    $offen = [];
    $vergangen_aktiv = $vergangen_teilgenommen = 0;
    foreach ($buchungen_p as $b) {
        $status[$b['status']] = ($status[$b['status']] ?? 0) + 1;
        if (!statBuchungAktiv($b)) continue;
        $belegt[(int)$b['kurs_id']] = ($belegt[(int)$b['kurs_id']] ?? 0) + 1;
        $kunden_buchungen[(int)$b['user_id']] = ($kunden_buchungen[(int)$b['user_id']] ?? 0) + 1;
        if (!(int)$b['bezahlt'] && (float)$b['kurs']['preis'] > 0) $offen[] = moneyRound((string)$b['kurs']['preis']);
        if ((string)$b['kurs']['end_datum'] < $jetzt) {
            $vergangen_aktiv++;
            if ($b['status'] === 'teilgenommen') $vergangen_teilgenommen++;
        }
    }
    $plaetze = $plaetze_belegt = 0;
    foreach ($kurse_durchgefuehrt as $k) {
        if ((int)$k['max_teilnehmer'] <= 0) continue;
        $plaetze        += (int)$k['max_teilnehmer'];
        $plaetze_belegt += min((int)$k['max_teilnehmer'], $belegt[(int)$k['id']] ?? 0);
    }
    $anmeldungen = $status['angemeldet'] + $status['teilgenommen'];
    $alle_buchungen = array_sum($status);

    // Kund:innen: Buchung, Plan oder protokollierte Einheit im Zeitraum
    $kunden = array_keys($kunden_buchungen);
    foreach ($d['plaene'] as $p) if ($p['mitglied_id'] && statImZeitraum($p['created_at'], $von, $bis)) $kunden[] = (int)$p['mitglied_id'];
    foreach ($d['protokoll'] as $pr) if (statImZeitraum($pr['datum'], $von, $bis)) $kunden[] = (int)$pr['user_id'];
    $kunden = array_values(array_unique($kunden));

    $neue_kunden = count(array_filter(statErsterKontakt($d), fn($datum) => statImZeitraum($datum, $von, $bis)));
    $wiederkehrer = count(array_filter($kunden_buchungen, fn($n) => $n >= 2));

    // Pläne
    $plaene_neu = array_filter($d['plaene'], fn($p) => statImZeitraum($p['created_at'], $von, $bis));
    $einheiten  = array_filter($d['protokoll'], fn($pr) => statImZeitraum($pr['datum'], $von, $bis));

    // Mitglieder (nur Vereinssicht)
    $registrierungen = count(array_filter($d['mitglieder'], fn($m) => statImZeitraum($m['created_at'], $von, $bis)));

    return [
        'umsatz'              => $umsatz,
        'umsatz_kurs'         => $umsatz_kurs,
        'umsatz_manuell'      => $umsatz_manuell,
        'provision'           => $provision,
        'vereinsanteil'       => bcsub($umsatz, $provision, MONEY_SCALE),
        'offen_betrag'        => moneySum($offen),
        'offen_anzahl'        => count($offen),
        'kurse'               => count($kurse_durchgefuehrt),
        'kurse_abgesagt'      => count($kurse_p) - count($kurse_durchgefuehrt),
        'anmeldungen'         => $anmeldungen,
        'status'              => $status,
        'stornoquote'         => statQuote($status['storniert'], $alle_buchungen),
        'teilnahmequote'      => $vergangen_teilgenommen > 0 ? statQuote($vergangen_teilgenommen, $vergangen_aktiv) : null,
        'auslastung'          => statQuote($plaetze_belegt, $plaetze),
        'plaetze'             => $plaetze,
        'kunden'              => count($kunden),
        'kunden_ids'          => $kunden,
        'neue_kunden'         => $neue_kunden,
        'wiederkehrquote'     => statQuote($wiederkehrer, count($kunden_buchungen)),
        'umsatz_pro_kunde'    => count($kunden) ? bcdiv($umsatz, (string)count($kunden), MONEY_SCALE) : null,
        'buchungen_pro_kurs'  => count($kurse_durchgefuehrt) ? round($anmeldungen / count($kurse_durchgefuehrt), 1) : null,
        'plaene_neu'          => count($plaene_neu),
        'trainingsplaene_aktiv' => count(array_filter($d['plaene'], fn($p) => $p['typ'] === 'training' && $p['status'] === 'aktiv' && $p['mitglied_id'])),
        'ernaehrungsplaene_aktiv' => count(array_filter($d['plaene'], fn($p) => $p['typ'] === 'ernaehrung' && $p['status'] === 'aktiv' && $p['mitglied_id'])),
        'einheiten_protokolliert' => count($einheiten),
        'registrierungen'     => $registrierungen,
    ];
}

// ----------------------------------------------------------------
// Verläufe & Verteilungen
// ----------------------------------------------------------------

/** Zeitachse: Wochen (bis 62 Tage), Monate (bis 3 Jahre) oder Jahre. */
function statZeitachse(string $von, string $bis): array
{
    $tage = (strtotime($bis) - strtotime($von)) / 86400;
    $einheit = $tage <= 62 ? 'woche' : ($tage <= 1100 ? 'monat' : 'jahr');
    $schluessel = fn(string $datum) => match ($einheit) {
        'woche' => date('o-\WW', strtotime($datum)),
        'monat' => substr($datum, 0, 7),
        default => substr($datum, 0, 4),
    };
    $monate = ['01' => 'Jän', '02' => 'Feb', '03' => 'Mär', '04' => 'Apr', '05' => 'Mai', '06' => 'Jun',
               '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Dez'];
    $labels = [];
    $schritt = match ($einheit) { 'woche' => '+1 week', 'monat' => '+1 month', default => '+1 year' };
    $start = match ($einheit) { 'woche' => date('Y-m-d', strtotime('monday this week', strtotime($von))), 'monat' => substr($von, 0, 7) . '-01', default => substr($von, 0, 4) . '-01-01' };
    for ($t = strtotime($start); $t <= strtotime($bis); $t = strtotime($schritt, $t)) {
        $s = $schluessel(date('Y-m-d', $t));
        $labels[$s] = match ($einheit) {
            'woche' => 'KW ' . (int)date('W', $t),
            'monat' => $monate[date('m', $t)] . ' ' . date('y', $t),
            default => date('Y', $t),
        };
    }
    return ['einheit' => $einheit, 'labels' => $labels, 'schluessel' => $schluessel];
}

function statVerlauf(array $d, string $von, string $bis): array
{
    $achse = statZeitachse($von, $bis);
    $leer  = array_fill_keys(array_keys($achse['labels']), 0);
    $reihen = ['umsatz_kurs' => $leer, 'umsatz_manuell' => $leer, 'anmeldungen' => $leer, 'neue_kunden' => $leer, 'einheiten' => $leer];
    $plus = function (string $reihe, ?string $datum, float $wert = 1) use (&$reihen, $achse, $von, $bis) {
        if (!statImZeitraum($datum, $von, $bis)) return;
        $s = ($achse['schluessel'])(substr($datum, 0, 10));
        if (isset($reihen[$reihe][$s])) $reihen[$reihe][$s] += $wert;
    };
    foreach (statUmsaetze($d) as $u) $plus($u['art'] === 'kurs' ? 'umsatz_kurs' : 'umsatz_manuell', $u['datum'], (float)$u['betrag']);
    foreach ($d['buchungen'] as $b) if (statBuchungAktiv($b)) $plus('anmeldungen', $b['kurs_datum']);
    foreach (statErsterKontakt($d) as $datum) $plus('neue_kunden', $datum);
    foreach ($d['protokoll'] as $pr) $plus('einheiten', $pr['datum']);

    return ['einheit' => $achse['einheit'], 'labels' => array_values($achse['labels'])]
         + array_map(fn($r) => array_map(fn($v) => round($v, 2), array_values($r)), $reihen);
}

/** Sportarten/Trainingsangebote: Anmeldungen, Kund:innen, Kurse, Umsatz, Auslastung. */
function statSportarten(array $d, string $von, string $bis): array
{
    $sp = [];
    $name = fn($k) => trim((string)$k['sportart']) ?: 'Ohne Angabe';
    $init = fn() => ['anmeldungen' => 0, 'kunden' => [], 'kurse' => [], 'umsatz' => [], 'plaetze' => 0, 'belegt' => 0, 'storniert' => 0];
    $belegt = [];
    foreach ($d['buchungen'] as $b) {
        if (!statImZeitraum($b['kurs_datum'], $von, $bis)) continue;
        $s = $name($b['kurs']);
        $sp[$s] ??= $init();
        if ($b['status'] === 'storniert') { $sp[$s]['storniert']++; continue; }
        if (!statBuchungAktiv($b)) continue;
        $sp[$s]['anmeldungen']++;
        $sp[$s]['kunden'][(int)$b['user_id']] = true;
        $belegt[(int)$b['kurs_id']] = ($belegt[(int)$b['kurs_id']] ?? 0) + 1;
    }
    foreach ($d['kurse'] as $k) {
        if (!statImZeitraum($k['start_datum'], $von, $bis) || $k['status'] === 'abgesagt') continue;
        $s = $name($k);
        $sp[$s] ??= $init();
        $sp[$s]['kurse'][(int)$k['id']] = true;
        if ((int)$k['max_teilnehmer'] > 0) {
            $sp[$s]['plaetze'] += (int)$k['max_teilnehmer'];
            $sp[$s]['belegt']  += min((int)$k['max_teilnehmer'], $belegt[(int)$k['id']] ?? 0);
        }
    }
    foreach (statUmsaetze($d) as $u) {
        if ($u['art'] !== 'kurs' || !statImZeitraum($u['datum'], $von, $bis)) continue;
        $sp[$u['sportart']] ??= $init();
        $sp[$u['sportart']]['umsatz'][] = $u['betrag'];
    }
    $liste = [];
    foreach ($sp as $s => $w) {
        $liste[] = [
            'sportart'    => $s,
            'anmeldungen' => $w['anmeldungen'],
            'kunden'      => count($w['kunden']),
            'kurse'       => count($w['kurse']),
            'umsatz'      => moneySum($w['umsatz']),
            'auslastung'  => statQuote($w['belegt'], $w['plaetze']),
            'stornoquote' => statQuote($w['storniert'], $w['anmeldungen'] + $w['storniert']),
        ];
    }
    usort($liste, fn($a, $b) => [$b['anmeldungen'], (float)$b['umsatz']] <=> [$a['anmeldungen'], (float)$a['umsatz']]);
    return $liste;
}

/** Interessen laut Mitgliederprofil (Komma-Liste „sportarten“). */
function statInteressen(array $mitglieder): array
{
    $zaehler = [];
    foreach ($mitglieder as $m) {
        foreach (preg_split('/\s*[,;]\s*/', (string)$m['sportarten'], -1, PREG_SPLIT_NO_EMPTY) as $s) {
            $s = mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
            $zaehler[$s] = ($zaehler[$s] ?? 0) + 1;
        }
    }
    arsort($zaehler);
    return $zaehler;
}

/** Einzelne Kurse im Zeitraum mit Belegung und Umsatz. */
function statKurse(array $d, string $von, string $bis): array
{
    $liste = [];
    foreach ($d['kurse'] as $k) {
        if (!statImZeitraum($k['start_datum'], $von, $bis)) continue;
        $liste[(int)$k['id']] = ['kurs' => $k, 'anmeldungen' => 0, 'warteliste' => 0, 'storniert' => 0, 'umsatz' => []];
    }
    foreach ($d['buchungen'] as $b) {
        $id = (int)$b['kurs_id'];
        if (!isset($liste[$id])) continue;
        if (statBuchungAktiv($b)) $liste[$id]['anmeldungen']++;
        elseif (isset($liste[$id][$b['status']])) $liste[$id][$b['status']]++;
        if ((int)$b['bezahlt'] && (float)$b['kurs']['preis'] > 0) $liste[$id]['umsatz'][] = moneyRound((string)$b['kurs']['preis']);
    }
    foreach ($liste as &$z) {
        $max = (int)$z['kurs']['max_teilnehmer'];
        $z['umsatz']     = moneySum($z['umsatz']);
        $z['auslastung'] = $max > 0 ? statQuote(min($max, $z['anmeldungen']), $max) : null;
    }
    unset($z);
    usort($liste, fn($a, $b) => [$b['anmeldungen'], (float)$b['umsatz']] <=> [$a['anmeldungen'], (float)$a['umsatz']]);
    return $liste;
}

/** Anmeldungen nach Wochentag × Tageszeit (Kursbeginn). */
function statHeatmap(array $d, string $von, string $bis): array
{
    $matrix = [];
    foreach (STAT_WOCHENTAGE as $tag => $_) $matrix[$tag] = array_fill_keys(array_keys(STAT_TAGESZEITEN), 0);
    foreach ($d['buchungen'] as $b) {
        if (!statBuchungAktiv($b) || !statImZeitraum($b['kurs_datum'], $von, $bis)) continue;
        $start = (string)$b['kurs']['start_datum'];
        $matrix[(int)date('N', strtotime($start))][statTageszeit($start)]++;
    }
    return $matrix;
}

/** Altersgruppen und Wohnorte einer Personengruppe. */
function statDemografie(array $personen, string $stichtag): array
{
    $alter = array_fill_keys(array_keys(STAT_ALTERSGRUPPEN), 0) + ['unbekannt' => 0];
    $orte = [];
    $summe_alter = $anzahl_alter = 0;
    foreach ($personen as $p) {
        $a = statAlter($p['geburtsdatum'] ?? null, $stichtag);
        if ($a === null) { $alter['unbekannt']++; }
        else {
            $summe_alter += $a; $anzahl_alter++;
            foreach (STAT_ALTERSGRUPPEN as $gruppe => [$min, $max]) if ($a >= $min && $a <= $max) { $alter[$gruppe]++; break; }
        }
        $ort = trim((string)($p['ort'] ?? ''));
        $ort = $ort !== '' ? mb_convert_case($ort, MB_CASE_TITLE, 'UTF-8') : 'Ohne Angabe';
        $orte[$ort] = ($orte[$ort] ?? 0) + 1;
    }
    arsort($orte);
    return ['alter' => $alter, 'orte' => $orte, 'durchschnittsalter' => $anzahl_alter ? round($summe_alter / $anzahl_alter, 1) : null];
}

/** Top-Kund:innen im Zeitraum nach Umsatz und Buchungen. */
function statTopKunden(array $d, string $von, string $bis, int $limit = 10): array
{
    $k = [];
    foreach ($d['buchungen'] as $b) {
        if (!statBuchungAktiv($b) || !statImZeitraum($b['kurs_datum'], $von, $bis)) continue;
        $uid = (int)$b['user_id'];
        $k[$uid] ??= ['buchungen' => 0, 'umsatz' => [], 'letzte' => null, 'sportarten' => []];
        $k[$uid]['buchungen']++;
        $k[$uid]['letzte'] = max((string)$k[$uid]['letzte'], $b['kurs_datum']);
        if ($s = trim((string)$b['kurs']['sportart'])) $k[$uid]['sportarten'][$s] = true;
    }
    foreach (statUmsaetze($d) as $u) {
        if ($u['user_id'] && isset($k[$u['user_id']]) && statImZeitraum($u['datum'], $von, $bis)) $k[$u['user_id']]['umsatz'][] = $u['betrag'];
    }
    $liste = [];
    foreach ($k as $uid => $w) {
        $m = $d['mitglieder'][$uid] ?? null;
        $liste[] = ['id' => $uid, 'name' => $m ? $m['vorname'] . ' ' . $m['nachname'] : 'Unbekannt', 'mitglied' => (bool)$m,
                    'buchungen' => $w['buchungen'], 'umsatz' => moneySum($w['umsatz']), 'letzte' => $w['letzte'],
                    'sportarten' => implode(', ', array_keys($w['sportarten']))];
    }
    usort($liste, fn($a, $b) => [(float)$b['umsatz'], $b['buchungen']] <=> [(float)$a['umsatz'], $a['buchungen']]);
    return array_slice($liste, 0, $limit);
}

/** Kund:innen, deren letzte Buchung länger als STAT_INAKTIV_TAGE zurückliegt. */
function statReaktivierung(array $d, string $stichtag, int $limit = 15): array
{
    $grenze = date('Y-m-d', strtotime("{$stichtag} -" . STAT_INAKTIV_TAGE . ' days'));
    $letzte = $anzahl = [];
    foreach ($d['buchungen'] as $b) {
        if (!statBuchungAktiv($b) || $b['kurs_datum'] > $stichtag) continue;
        $uid = (int)$b['user_id'];
        $letzte[$uid] = max($letzte[$uid] ?? '', $b['kurs_datum']);
        $anzahl[$uid] = ($anzahl[$uid] ?? 0) + 1;
    }
    // Wer bereits wieder für einen kommenden Kurs angemeldet ist, braucht keine Erinnerung
    foreach ($d['buchungen'] as $b) if (statBuchungAktiv($b) && $b['kurs_datum'] > $stichtag) unset($letzte[(int)$b['user_id']]);

    $liste = [];
    foreach ($letzte as $uid => $datum) {
        $m = $d['mitglieder'][$uid] ?? null;
        if ($datum >= $grenze || !$m || !(int)$m['aktiv'] || ($m['mitgliedsstatus'] ?? '') === 'inaktiv') continue;
        $liste[] = ['id' => $uid, 'name' => $m['vorname'] . ' ' . $m['nachname'], 'letzte' => $datum, 'buchungen' => $anzahl[$uid],
                    'tage' => (int)round((strtotime($stichtag) - strtotime($datum)) / 86400)];
    }
    usort($liste, fn($a, $b) => [$b['buchungen'], $a['tage']] <=> [$a['buchungen'], $b['tage']]);
    return array_slice($liste, 0, $limit);
}

/** Kennzahlen je Trainer:in (Vereinssicht). */
function statTrainerUebersicht(array $d, string $von, string $bis): array
{
    $ids = array_keys($d['trainer']);
    foreach ($d['kurse'] as $k) if ($k['trainer_id'] === null) { $ids[] = 0; break; }
    $zeilen = [];
    foreach (array_unique($ids) as $tid) {
        $t   = $d['trainer'][$tid] ?? null;
        $sub = statFiltern($d, (int)$tid);
        $kz  = statKennzahlen($sub, $von, $bis);
        $aktiv_im_zeitraum = $kz['kurse'] || $kz['anmeldungen'] || (float)$kz['umsatz'] || $kz['plaene_neu'] || $kz['einheiten_protokolliert'];
        if (!$aktiv_im_zeitraum && (!$t || !(int)$t['aktiv'])) continue;
        $alle_kunden = array_unique(array_merge(
            array_map(fn($b) => (int)$b['user_id'], array_filter($sub['buchungen'], fn($b) => $b['status'] !== 'storniert')),
            array_map(fn($p) => (int)$p['mitglied_id'], array_filter($sub['plaene'], fn($p) => $p['mitglied_id'])),
        ));
        $zeilen[] = [
            'id'            => (int)$tid,
            'name'          => $t ? $t['vorname'] . ' ' . $t['nachname'] : 'Ohne Trainer:in',
            'aktiv'         => $t ? (bool)$t['aktiv'] : true,
            'provisionssatz'=> $t['provisionssatz'] ?? null,
            'kunden_gesamt' => count($alle_kunden),
        ] + $kz;
    }
    usort($zeilen, fn($a, $b) => [(float)$b['umsatz'], $b['anmeldungen']] <=> [(float)$a['umsatz'], $a['anmeldungen']]);
    return $zeilen;
}

/** Trainingsziele der Pläne und meistverwendete Übungen. */
function statPlaene(array $d, array $ziel_labels): array
{
    $ziele = [];
    foreach ($d['plaene'] as $p) {
        if ($p['typ'] !== 'training' || !$p['mitglied_id']) continue;
        $label = $ziel_labels[$p['ziel']]['label'] ?? $p['ziel'];
        $ziele[$label] = ($ziele[$label] ?? 0) + 1;
    }
    arsort($ziele);
    $uebungen = [];
    foreach ($d['uebungen'] as $u) $uebungen[$u['uebung_name']] = ($uebungen[$u['uebung_name']] ?? 0) + 1;
    arsort($uebungen);
    $rpe = array_filter(array_map(fn($pr) => $pr['rpe'] !== null ? (float)$pr['rpe'] : null, $d['protokoll']), fn($v) => $v !== null);
    return ['ziele' => $ziele, 'uebungen' => array_slice($uebungen, 0, 10, true), 'rpe_schnitt' => $rpe ? round(array_sum($rpe) / count($rpe), 1) : null];
}

/** Mitgliederbestand kumuliert je Monat (Vereinssicht). */
function statMitgliederwachstum(array $mitglieder, string $von, string $bis): array
{
    $achse = statZeitachse($von, $bis);
    $stand = [];
    foreach (array_keys($achse['labels']) as $s) $stand[$s] = 0;
    $vorher = 0;
    foreach ($mitglieder as $m) {
        $datum = substr((string)$m['created_at'], 0, 10);
        if ($datum < $von) { $vorher++; continue; }
        if ($datum > $bis) continue;
        $s = ($achse['schluessel'])($datum);
        if (isset($stand[$s])) $stand[$s]++;
    }
    $kumuliert = [];
    $summe = $vorher;
    foreach ($stand as $n) { $summe += $n; $kumuliert[] = $summe; }
    return ['labels' => array_values($achse['labels']), 'neu' => array_values($stand), 'gesamt' => $kumuliert];
}
