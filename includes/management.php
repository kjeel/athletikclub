<?php
/**
 * Athletikclub Steiermark – Management-Kennzahlen
 * Alle Werte werden aus den bestehenden Modulen berechnet (Buchungen, Kursanmeldungen,
 * Einheiten, Trainerabrechnungen, Förderungen, Projekte). Für Vergleichszeiträume ohne
 * Daten wird „keine Daten“ geliefert – es wird nichts geschätzt.
 */

require_once ROOT_PATH . '/includes/plattform.php';
require_once ROOT_PATH . '/includes/rechnungen.php';

/** Zeitraum aus GET: monat | quartal | jahr | frei (von/bis). Liefert von, bis, Vorperiode, Titel. */
function managementZeitraum(array $q): array
{
    $art = in_array($q['zeitraum'] ?? '', ['monat', 'quartal', 'jahr', 'frei'], true) ? $q['zeitraum'] : 'monat';
    $ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['datum'] ?? '') ? $q['datum'] : date('Y-m-d');
    $t = strtotime($ref);
    switch ($art) {
        case 'quartal':
            $qn = (int)ceil((int)date('n', $t) / 3);
            $von = date('Y-', $t) . sprintf('%02d', ($qn - 1) * 3 + 1) . '-01';
            $bis = date('Y-m-t', strtotime($von . ' +2 months'));
            $titel = "Q$qn " . date('Y', $t);
            $v_von = date('Y-m-d', strtotime($von . ' -3 months'));
            $v_bis = date('Y-m-t', strtotime($v_von . ' +2 months'));
            break;
        case 'jahr':
            $von = date('Y-01-01', $t); $bis = date('Y-12-31', $t); $titel = date('Y', $t);
            $v_von = (date('Y', $t) - 1) . '-01-01'; $v_bis = (date('Y', $t) - 1) . '-12-31';
            break;
        case 'frei':
            $von = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['von'] ?? '') ? $q['von'] : date('Y-m-01');
            $bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['bis'] ?? '') && $q['bis'] >= $von ? $q['bis'] : date('Y-m-d');
            $tage = (int)round((strtotime($bis) - strtotime($von)) / 86400) + 1;
            $v_bis = date('Y-m-d', strtotime($von . ' -1 day'));
            $v_von = date('Y-m-d', strtotime($v_bis . ' -' . ($tage - 1) . ' days'));
            $titel = date('d.m.Y', strtotime($von)) . ' – ' . date('d.m.Y', strtotime($bis));
            break;
        default:
            $von = date('Y-m-01', $t); $bis = date('Y-m-t', $t);
            $monate = ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
            $titel = $monate[(int)date('n', $t)] . ' ' . date('Y', $t);
            $v_von = date('Y-m-01', strtotime($von . ' -1 month')); $v_bis = date('Y-m-t', strtotime($v_von));
    }
    return compact('art', 'ref', 'von', 'bis', 'titel', 'v_von', 'v_bis');
}

/** Kennzahlen eines Zeitraums. $daten = Anzahl zugrundeliegender Datensätze (0 = keine Daten). */
function managementKennzahlen(PDO $db, string $von, string $bis): array
{
    $org = currentOrgId();
    $k = ['kursbeitraege' => '0.00', 'einnahmen_buchungen' => '0.00', 'ausgaben' => '0.00', 'trainerkosten' => '0.00', 'trainerkosten_kurse' => '0.00',
          'foerdermittel' => '0.00', 'projektkosten' => '0.00', 'einheiten' => 0, 'minuten' => 0, 'teilnehmende' => 0, 'plaetze' => 0, 'belegt' => 0, 'daten' => 0];
    $v1 = "$von 00:00:00"; $b1 = "$bis 23:59:59";
    $sum = fn(string $a, $b) => bcadd($a, moneyRound($b ?? 0), 2);

    $s = $db->prepare('SELECT k.preis FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id WHERE k.organization_id = ? AND ka.bezahlt = 1 AND ka.bezahlt_am BETWEEN ? AND ? AND k.preis > 0');
    $s->execute([$org, $v1, $b1]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $p) { $k['kursbeitraege'] = $sum($k['kursbeitraege'], $p); $k['daten']++; }

    $s = $db->prepare('SELECT art, betrag, kategorie, projekt_id, foerderung_id FROM buchungen WHERE organization_id = ? AND datum BETWEEN ? AND ?');
    $s->execute([$org, $von, $bis]);
    foreach ($s->fetchAll() as $b) {
        $k['daten']++;
        if ($b['art'] === 'einnahme') {
            $k['einnahmen_buchungen'] = $sum($k['einnahmen_buchungen'], $b['betrag']);
            if ($b['kategorie'] === 'foerderung' || $b['foerderung_id']) $k['foerdermittel'] = $sum($k['foerdermittel'], $b['betrag']);
        } else {
            $k['ausgaben'] = $sum($k['ausgaben'], $b['betrag']);
            if ($b['projekt_id']) $k['projektkosten'] = $sum($k['projektkosten'], $b['betrag']);
        }
    }

    // Trainerkosten und -stunden nach Leistungsdatum (bestätigte Einsätze)
    $s = $db->prepare("SELECT et.dauer_min, et.betrag, e.kurs_id, e.id FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                       WHERE e.organization_id = ? AND e.start BETWEEN ? AND ? AND et.status NOT IN ('geplant','storniert')");
    $s->execute([$org, $v1, $b1]);
    $einheiten = [];
    foreach ($s->fetchAll() as $t) {
        $k['daten']++;
        $k['minuten'] += (int)$t['dauer_min'];
        $k['trainerkosten'] = $sum($k['trainerkosten'], $t['betrag']);
        if ($t['kurs_id']) $k['trainerkosten_kurse'] = $sum($k['trainerkosten_kurse'], $t['betrag']);
        $einheiten[(int)$t['id']] = true;
    }
    $k['einheiten'] = count($einheiten);

    // Teilnehmende und Auslastung: Kurse, die im Zeitraum laufen
    $s = $db->prepare("SELECT k.id, k.max_teilnehmer, (SELECT COUNT(*) FROM kurs_anmeldungen ka WHERE ka.kurs_id = k.id AND ka.status IN ('angemeldet','teilgenommen')) AS belegt
                       FROM kurse k WHERE k.organization_id = ? AND k.status <> 'abgesagt' AND k.start_datum <= ? AND k.end_datum >= ?");
    $s->execute([$org, $b1, $v1]);
    $kurse = $s->fetchAll();
    $k['kurse'] = count($kurse);
    foreach ($kurse as $x) {
        if ($x['max_teilnehmer']) { $k['plaetze'] += (int)$x['max_teilnehmer']; $k['belegt'] += min((int)$x['belegt'], (int)$x['max_teilnehmer']); }
    }
    $s = $db->prepare("SELECT DISTINCT ka.user_id, ka.kind_id FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                       WHERE k.organization_id = ? AND ka.status IN ('angemeldet','teilgenommen') AND k.status <> 'abgesagt' AND k.start_datum <= ? AND k.end_datum >= ?");
    $s->execute([$org, $b1, $v1]);
    $personen = [];
    foreach ($s->fetchAll() as $x) $personen[$x['kind_id'] > 0 ? 'k' . $x['kind_id'] : 'u' . $x['user_id']] = true;
    $k['teilnehmende'] = count($personen);
    $k['daten'] += $k['kurse'];

    $k['umsatz'] = bcadd($k['kursbeitraege'], $k['einnahmen_buchungen'], 2);
    $k['kosten'] = $k['ausgaben'];
    $k['saldo'] = bcsub($k['umsatz'], $k['kosten'], 2);
    $k['auslastung'] = $k['plaetze'] ? round($k['belegt'] / $k['plaetze'] * 100, 1) : null;
    $k['stunden'] = round($k['minuten'] / 60, 1);
    return $k;
}

/** Veränderung in % (null, wenn Vorperiode ohne Daten oder Basis 0). */
function managementDelta($neu, $alt, bool $vor_hat_daten): ?float
{
    if (!$vor_hat_daten || $alt === null || (float)$alt == 0.0) return null;
    return round(((float)$neu - (float)$alt) / abs((float)$alt) * 100, 1);
}

/** Momentaufnahmen (unabhängig vom Zeitraum). */
function managementStand(PDO $db): array
{
    $org = currentOrgId();
    $heute = date('Y-m-d');
    $z = function (string $sql, array $p = []) use ($db) {
        try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Exception $e) { return 0; }
    };
    $st = [
        'projekte_aktiv' => (int)$z("SELECT COUNT(*) FROM projekte WHERE organization_id = ? AND status = 'aktiv'", [$org]),
        'aufgaben_offen' => (int)$z("SELECT COUNT(*) FROM aufgaben WHERE organization_id = ? AND status <> 'erledigt'", [$org]),
        'aufgaben_ueber' => (int)$z("SELECT COUNT(*) FROM aufgaben WHERE organization_id = ? AND status <> 'erledigt' AND deadline < ?", [$org, $heute]),
        'trainer_aktiv'  => (int)$z("SELECT COUNT(*) FROM users WHERE organization_id = ? AND rolle = 'trainer' AND aktiv = 1", [$org]),
        'abrechnungen_offen' => (int)$z("SELECT COUNT(*) FROM trainer_abrechnungen WHERE organization_id = ? AND status IN ('eingereicht','geprueft','freigegeben')", [$org]),
        'abrechnungen_betrag' => moneyRound($z("SELECT COALESCE(SUM(betrag), 0) FROM trainer_abrechnungen WHERE organization_id = ? AND status IN ('eingereicht','geprueft','freigegeben')", [$org]) ?: 0),
        'qual_abgelaufen' => (int)$z("SELECT COUNT(*) FROM trainer_qualifikationen q JOIN users u ON u.id = q.user_id WHERE q.organization_id = ? AND u.aktiv = 1 AND q.gueltig_bis < ?", [$org, $heute]),
        'qual_bald' => (int)$z("SELECT COUNT(*) FROM trainer_qualifikationen q JOIN users u ON u.id = q.user_id WHERE q.organization_id = ? AND u.aktiv = 1 AND q.gueltig_bis BETWEEN ? AND ?", [$org, $heute, date('Y-m-d', strtotime('+60 days'))]),
        'rechnungen' => rechnungenOffenSumme($db),
        'partner' => [], 'kooperationen' => (int)$z("SELECT COUNT(*) FROM kooperationen WHERE organization_id = ?", [$org]),
        'foerder' => ['beantragt' => '0.00', 'bewilligt' => '0.00', 'verbraucht' => '0.00', 'verfuegbar' => '0.00', 'fristen' => 0],
    ];
    try {
        $s = $db->prepare("SELECT kategorie, COUNT(*) FROM partner_organisationen WHERE organization_id = ? AND status = 'aktiv' GROUP BY kategorie");
        $s->execute([$org]);
        $st['partner'] = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}
    try {
        $s = $db->prepare("SELECT * FROM foerderungen WHERE organization_id = ? AND status NOT IN ('abgelehnt')");
        $s->execute([$org]);
        foreach ($s->fetchAll() as $f) {
            if (in_array($f['status'], FOERDER_OFFEN, true)) $st['foerder']['beantragt'] = bcadd($st['foerder']['beantragt'], moneyRound($f['betrag_beantragt'] ?? 0), 2);
            if (in_array($f['status'], FOERDER_ZUGESAGT, true) && $f['status'] !== 'abgeschlossen') {
                $b = foerderBudget($db, $f);
                $st['foerder']['bewilligt'] = bcadd($st['foerder']['bewilligt'], $b['bewilligt'], 2);
                $st['foerder']['verbraucht'] = bcadd($st['foerder']['verbraucht'], $b['verbraucht'], 2);
            }
            foreach (['einreichfrist', 'nachweisfrist', 'abrechnungsfrist'] as $feld) {
                if (!empty($f[$feld]) && $f[$feld] >= $heute && $f[$feld] <= date('Y-m-d', strtotime('+30 days')) && $f['status'] !== 'abgeschlossen') $st['foerder']['fristen']++;
            }
        }
        $st['foerder']['verfuegbar'] = bcsub($st['foerder']['bewilligt'], $st['foerder']['verbraucht'], 2);
    } catch (Exception $e) {}
    return $st;
}

/** Soll-Ist je Projekt: Budget, Kosten (Buchungen), Rest, Auslastung. */
function managementProjekte(PDO $db): array
{
    $s = $db->prepare("SELECT p.id, p.name, p.status, p.budget, u.vorname, u.nachname,
                              (SELECT COALESCE(SUM(b.betrag), 0) FROM buchungen b WHERE b.projekt_id = p.id AND b.art = 'ausgabe') AS kosten,
                              (SELECT COALESCE(SUM(b.betrag), 0) FROM buchungen b WHERE b.projekt_id = p.id AND b.art = 'einnahme') AS einnahmen,
                              (SELECT COUNT(*) FROM aufgaben a WHERE a.projekt_id = p.id AND a.status <> 'erledigt') AS aufgaben
                       FROM projekte p LEFT JOIN users u ON u.id = p.leitung_id WHERE p.organization_id = ? AND p.status IN ('planung','aktiv','pausiert') ORDER BY p.name");
    $s->execute([currentOrgId()]);
    $r = [];
    foreach ($s->fetchAll() as $p) {
        $p['rest'] = $p['budget'] !== null ? bcsub(moneyRound($p['budget']), moneyRound($p['kosten']), 2) : null;
        $p['quote'] = $p['budget'] > 0 ? round((float)$p['kosten'] / (float)$p['budget'] * 100, 1) : null;
        $r[] = $p;
    }
    return $r;
}
