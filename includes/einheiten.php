<?php
/**
 * Athletikclub Steiermark – Einsatzplanung
 *
 * Einheit (einheiten) = eine konkrete Durchführung mit Projekt/Kurs, Trainer:innen
 * (einheit_trainer), Teilnehmenden und Anwesenheit (anwesenheiten).
 * Kette: Einheit → Bestätigung (Dauer, Teilnehmerzahl, Honorar) → Trainer-
 * Monatsabrechnung → Freigabe erzeugt Kostenbuchungen je Projekt/Kurs/Förderung
 * → optional Übernahme als PRAE-Einsatztage.
 */

require_once ROOT_PATH . '/includes/plattform.php';

/** Einheit laden inkl. Projekt/Kurs-Namen (ohne Zugriffsprüfung). */
function einheitLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT e.*, p.name AS projekt_name, k.titel AS kurs_titel FROM einheiten e
                          LEFT JOIN projekte p ON p.id = e.projekt_id LEFT JOIN kurse k ON k.id = e.kurs_id
                          WHERE e.id = ? AND e.organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/** Trainer:innen einer Einheit. */
function einheitTrainer(PDO $db, int $einheit_id): array
{
    $stmt = $db->prepare("SELECT et.*, u.vorname, u.nachname FROM einheit_trainer et JOIN users u ON u.id = et.user_id
                          WHERE et.einheit_id = ? ORDER BY CASE et.rolle WHEN 'leitung' THEN 0 ELSE 1 END, u.nachname");
    $stmt->execute([$einheit_id]);
    return $stmt->fetchAll();
}

/** Ist die aktuelle Person dieser Einheit als Trainer:in zugeteilt? */
function einheitIstMeine(PDO $db, int $einheit_id): bool
{
    $stmt = $db->prepare('SELECT 1 FROM einheit_trainer WHERE einheit_id = ? AND user_id = ?');
    $stmt->execute([$einheit_id, getCurrentUserId()]);
    return (bool)$stmt->fetchColumn();
}

/** Sichtrecht: Planungsrecht, zugeteilt, Projektteam oder angemeldete Teilnehmer:in. */
function einheitDarfSehen(PDO $db, array $e): bool
{
    if (darf('kalender.anzeigen') || einheitIstMeine($db, (int)$e['id'])) return true;
    if ($e['projekt_id']) {
        $stmt = $db->prepare('SELECT 1 FROM projekt_team WHERE projekt_id = ? AND user_id = ? UNION SELECT 1 FROM projekte WHERE id = ? AND leitung_id = ?');
        $stmt->execute([$e['projekt_id'], getCurrentUserId(), $e['projekt_id'], getCurrentUserId()]);
        if ($stmt->fetchColumn()) return true;
    }
    if ($e['kurs_id']) {
        $stmt = $db->prepare("SELECT 1 FROM kurs_anmeldungen WHERE kurs_id = ? AND user_id = ? AND status IN ('angemeldet','teilgenommen')");
        $stmt->execute([$e['kurs_id'], getCurrentUserId()]);
        if ($stmt->fetchColumn()) return true;
        $stmt = $db->prepare('SELECT 1 FROM kurse WHERE id = ? AND trainer_id = ?');
        $stmt->execute([$e['kurs_id'], getCurrentUserId()]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

/** Planen/Ändern: Planungsrecht oder Kursleitung bzw. Projektleitung. */
function einheitDarfBearbeiten(PDO $db, ?array $e = null): bool
{
    if (darf('kalender.bearbeiten')) return true;
    if (!$e) return darf('kalender.erstellen');
    if ($e['kurs_id']) {
        $stmt = $db->prepare('SELECT 1 FROM kurse WHERE id = ? AND trainer_id = ?');
        $stmt->execute([$e['kurs_id'], getCurrentUserId()]);
        if ($stmt->fetchColumn()) return true;
    }
    if ($e['projekt_id']) {
        $stmt = $db->prepare('SELECT 1 FROM projekte WHERE id = ? AND leitung_id = ?');
        $stmt->execute([$e['projekt_id'], getCurrentUserId()]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

// ----------------------------------------------------------------
// Überschneidungen
// ----------------------------------------------------------------

/**
 * Termine, bei denen die Person im Zeitraum bereits eingeplant ist:
 * andere Einheiten (nicht storniert) sowie eintägige Kurse als Kursleitung.
 */
function trainerKonflikte(PDO $db, int $user_id, string $start, string $ende, ?int $ausser_einheit = null): array
{
    $stmt = $db->prepare("SELECT e.id, e.titel, e.start, e.ende FROM einheiten e JOIN einheit_trainer et ON et.einheit_id = e.id
                          WHERE et.user_id = ? AND et.status <> 'storniert' AND e.status <> 'storniert' AND e.organization_id = ?
                            AND e.start < ? AND e.ende > ?" . ($ausser_einheit ? ' AND e.id <> ?' : ''));
    $stmt->execute(array_merge([$user_id, currentOrgId(), $ende, $start], $ausser_einheit ? [$ausser_einheit] : []));
    $konflikte = $stmt->fetchAll();
    // Kurse ohne eigene Einheiten, die an einem Tag stattfinden
    $stmt = $db->prepare("SELECT k.id, k.titel, k.start_datum AS start, k.end_datum AS ende FROM kurse k
                          WHERE k.trainer_id = ? AND k.organization_id = ? AND k.status <> 'abgesagt' AND k.start_datum < ? AND k.end_datum > ?
                            AND NOT EXISTS (SELECT 1 FROM einheiten e WHERE e.kurs_id = k.id)");
    $stmt->execute([$user_id, currentOrgId(), $ende, $start]);
    foreach ($stmt->fetchAll() as $k) {
        if (substr($k['start'], 0, 10) === substr($k['ende'], 0, 10)) $konflikte[] = $k + ['kurs' => true];
    }
    return $konflikte;
}

/** Verfügbare Menge einer Ressource im Zeitraum (Anzahl minus überschneidende Buchungen). */
function ressourceFrei(PDO $db, int $ressource_id, string $start, string $ende, ?int $ausser_buchung = null, ?int $ausser_einheit = null): ?int
{
    $stmt = $db->prepare('SELECT anzahl, verfuegbar FROM ressourcen WHERE id = ? AND organization_id = ?');
    $stmt->execute([$ressource_id, currentOrgId()]);
    $r = $stmt->fetch();
    if (!$r) return null;
    if (!(int)$r['verfuegbar']) return 0;
    $sql = 'SELECT COALESCE(SUM(b.menge), 0) FROM ressourcen_buchungen b LEFT JOIN einheiten e ON e.id = b.einheit_id
            WHERE b.ressource_id = ? AND b.start < ? AND b.ende > ? AND (e.id IS NULL OR e.status <> \'storniert\')';
    $p = [$ressource_id, $ende, $start];
    if ($ausser_buchung) { $sql .= ' AND b.id <> ?'; $p[] = $ausser_buchung; }
    if ($ausser_einheit) { $sql .= ' AND (b.einheit_id IS NULL OR b.einheit_id <> ?)'; $p[] = $ausser_einheit; }
    $stmt = $db->prepare($sql);
    $stmt->execute($p);
    return max(0, (int)$r['anzahl'] - (int)$stmt->fetchColumn());
}

// ----------------------------------------------------------------
// Serien
// ----------------------------------------------------------------

/** Termine einer Serie ab $von (inkl.) bis $bis im Rhythmus (max. 2 Jahre). */
function serienDaten(string $von, string $bis, string $rhythmus): array
{
    $schritt = $rhythmus === '14taegig' ? 14 : 7;
    $daten = [];
    $grenze = min(strtotime($bis), strtotime($von . ' +2 years'));
    for ($t = strtotime($von); $t <= $grenze; $t = strtotime('+' . $schritt . ' days', $t)) $daten[] = date('Y-m-d', $t);
    return $daten;
}

// ----------------------------------------------------------------
// Honorar
// ----------------------------------------------------------------

/**
 * Passender Honorarsatz: spezifischster gültiger Eintrag gewinnt
 * (Trainer+Kurs > Trainer+Projekt > Trainer > Kurs > Projekt > Standard).
 */
function honorarSatz(PDO $db, int $user_id, ?int $projekt_id, ?int $kurs_id, string $datum): ?array
{
    try {
        $stmt = $db->prepare('SELECT * FROM honorar_saetze WHERE organization_id = ? AND (gueltig_ab IS NULL OR gueltig_ab <= ?) AND (gueltig_bis IS NULL OR gueltig_bis >= ?)');
        $stmt->execute([currentOrgId(), $datum, $datum]);
        $saetze = $stmt->fetchAll();
    } catch (Exception $e) {
        return null;
    }
    $bester = null;
    $bester_rang = -1;
    foreach ($saetze as $s) {
        $u = $s['user_id'] !== null ? (int)$s['user_id'] : null;
        $p = $s['projekt_id'] !== null ? (int)$s['projekt_id'] : null;
        $k = $s['kurs_id'] !== null ? (int)$s['kurs_id'] : null;
        if (($u !== null && $u !== $user_id) || ($p !== null && $p !== $projekt_id) || ($k !== null && $k !== $kurs_id)) continue;
        $rang = ($u !== null ? 4 : 0) + ($k !== null ? 2 : 0) + ($p !== null ? 1 : 0);
        if ($rang > $bester_rang) { $bester = $s; $bester_rang = $rang; }
    }
    return $bester;
}

/** Betrag aus Honorarmodell und Dauer (Stunde: anteilig auf Minuten genau). */
function honorarBetrag(?array $satz, int $minuten): ?string
{
    if (!$satz) return null;
    if ($satz['modell'] === 'stunde') return bcdiv(bcmul((string)$satz['betrag'], (string)$minuten, 4), '60', 2);
    return moneyRound($satz['betrag']);
}

// ----------------------------------------------------------------
// Bestätigung „durchgeführt“
// ----------------------------------------------------------------

/**
 * Trainer:in bestätigt die Einheit: Ist-Zeiten, Dauer, Teilnehmerzahl, Notiz.
 * Honorar wird aus dem passenden Satz berechnet und festgeschrieben.
 */
function einheitBestaetigen(PDO $db, array $einheit, int $user_id, string $ist_start, string $ist_ende, ?int $teilnehmer, ?string $notiz): array
{
    if (strtotime($ist_ende) <= strtotime($ist_start)) return ['fehler' => 'Das Ende muss nach dem Beginn liegen.'];
    $minuten = (int)round((strtotime($ist_ende) - strtotime($ist_start)) / 60);
    if ($minuten > 16 * 60) return ['fehler' => 'Eine Einheit kann nicht länger als 16 Stunden dauern.'];
    $satz = honorarSatz($db, $user_id, $einheit['projekt_id'] ? (int)$einheit['projekt_id'] : null, $einheit['kurs_id'] ? (int)$einheit['kurs_id'] : null, substr($ist_start, 0, 10));
    $betrag = honorarBetrag($satz, $minuten);
    $stmt = $db->prepare('SELECT * FROM einheit_trainer WHERE einheit_id = ? AND user_id = ?');
    $stmt->execute([$einheit['id'], $user_id]);
    $alt = $stmt->fetch();
    if (!$alt) return ['fehler' => 'Diese Person ist der Einheit nicht zugeteilt.'];
    if (!in_array($alt['status'], ['geplant', 'durchgefuehrt'], true)) return ['fehler' => 'Die Einheit ist bereits in einer Abrechnung und kann nicht mehr geändert werden.'];
    $neu = ['status' => 'durchgefuehrt', 'ist_start' => $ist_start, 'ist_ende' => $ist_ende, 'dauer_min' => $minuten, 'teilnehmer_anzahl' => $teilnehmer,
            'notiz' => $notiz, 'honorar_modell' => $satz['modell'] ?? null, 'honorar_satz' => $satz['betrag'] ?? null, 'betrag' => $betrag, 'bestaetigt_am' => date('Y-m-d H:i:s')];
    $db->prepare('UPDATE einheit_trainer SET status = ?, ist_start = ?, ist_ende = ?, dauer_min = ?, teilnehmer_anzahl = ?, notiz = ?, honorar_modell = ?, honorar_satz = ?, betrag = ?, bestaetigt_am = ? WHERE id = ?')
       ->execute(array_merge(array_values($neu), [$alt['id']]));
    if ($einheit['status'] === 'geplant') $db->prepare("UPDATE einheiten SET status = 'durchgefuehrt' WHERE id = ?")->execute([$einheit['id']]);
    auditLog('geaendert', 'einheit_trainer', (int)$alt['id'], $alt, $neu, 'Einheit bestätigt: ' . $einheit['titel']);
    return ['minuten' => $minuten, 'betrag' => $betrag, 'ohne_satz' => $satz === null];
}

// ----------------------------------------------------------------
// Teilnehmende & Anwesenheit
// ----------------------------------------------------------------

/**
 * Teilnehmende einer Einheit: Anmeldungen zum Kurs (Personen und Kinder)
 * sowie bereits erfasste Gäste/Probetrainings. Schlüssel: u<ID>, k<ID>, g<Name>.
 */
function einheitTeilnehmer(PDO $db, array $einheit): array
{
    $liste = [];
    if ($einheit['kurs_id']) {
        $stmt = $db->prepare("SELECT ka.user_id, ka.kind_id, ka.status, u.vorname, u.nachname, ki.vorname AS k_vorname, ki.nachname AS k_nachname,
                                     ki.hinweise AS k_hinweise, ki.notfall_name, ki.notfall_telefon
                              FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id LEFT JOIN kinder ki ON ki.id = ka.kind_id AND ka.kind_id > 0
                              WHERE ka.kurs_id = ? AND ka.status IN ('angemeldet','teilgenommen') ORDER BY COALESCE(ki.nachname, u.nachname), COALESCE(ki.vorname, u.vorname)");
        $stmt->execute([$einheit['kurs_id']]);
        foreach ($stmt->fetchAll() as $a) {
            if ((int)$a['kind_id'] > 0 && $a['k_vorname'] !== null) {
                $liste['k' . $a['kind_id']] = ['key' => 'k' . $a['kind_id'], 'name' => $a['k_vorname'] . ' ' . $a['k_nachname'], 'typ' => 'kind', 'user_id' => null,
                    'kind_id' => (int)$a['kind_id'], 'info' => 'Kind von ' . $a['vorname'] . ' ' . $a['nachname'], 'hinweise' => $a['k_hinweise'],
                    'notfall' => trim(($a['notfall_name'] ?? '') . ' ' . ($a['notfall_telefon'] ?? ''))];
            } else {
                $liste['u' . $a['user_id']] = ['key' => 'u' . $a['user_id'], 'name' => $a['vorname'] . ' ' . $a['nachname'], 'typ' => 'mitglied', 'user_id' => (int)$a['user_id'],
                    'kind_id' => null, 'info' => '', 'hinweise' => null, 'notfall' => ''];
            }
        }
    }
    $stmt = $db->prepare('SELECT a.*, u.vorname, u.nachname, ki.vorname AS k_vorname, ki.nachname AS k_nachname FROM anwesenheiten a
                          LEFT JOIN users u ON u.id = a.user_id LEFT JOIN kinder ki ON ki.id = a.kind_id WHERE a.einheit_id = ?');
    $stmt->execute([$einheit['id']]);
    foreach ($stmt->fetchAll() as $a) {
        if (isset($liste[$a['teilnehmer_key']])) { $liste[$a['teilnehmer_key']]['status'] = $a['status']; continue; }
        $name = $a['gast_name'] ?: ($a['k_vorname'] ? $a['k_vorname'] . ' ' . $a['k_nachname'] : trim(($a['vorname'] ?? '') . ' ' . ($a['nachname'] ?? '')));
        $liste[$a['teilnehmer_key']] = ['key' => $a['teilnehmer_key'], 'name' => $name ?: 'Gast', 'typ' => $a['gast_name'] ? 'gast' : ($a['kind_id'] ? 'kind' : 'mitglied'),
            'user_id' => $a['user_id'] ? (int)$a['user_id'] : null, 'kind_id' => $a['kind_id'] ? (int)$a['kind_id'] : null, 'info' => $a['gast_name'] ? 'Gast' : '',
            'hinweise' => null, 'notfall' => '', 'status' => $a['status']];
    }
    return $liste;
}

/** Anwesenheit speichern (ein Datensatz je Teilnehmer:in und Einheit). */
function anwesenheitSpeichern(PDO $db, int $einheit_id, array $teilnehmer, array $status, ?string $gast = null, ?string $gast_status = null): int
{
    $n = 0;
    foreach ($status as $key => $s) {
        if (!isset(ANWESENHEIT_STATUS[$s]) || !isset($teilnehmer[$key])) continue;
        $t = $teilnehmer[$key];
        $db->prepare('DELETE FROM anwesenheiten WHERE einheit_id = ? AND teilnehmer_key = ?')->execute([$einheit_id, $key]);
        $db->prepare('INSERT INTO anwesenheiten (einheit_id, teilnehmer_key, user_id, kind_id, gast_name, status, erfasst_von) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$einheit_id, $key, $t['user_id'], $t['kind_id'], $t['typ'] === 'gast' ? $t['name'] : null, $s, getCurrentUserId()]);
        $n++;
    }
    if ($gast !== null && trim($gast) !== '') {
        $gast = mb_substr(trim($gast), 0, 150);
        $key = 'g' . substr(sha1(mb_strtolower($gast)), 0, 16);
        $db->prepare('DELETE FROM anwesenheiten WHERE einheit_id = ? AND teilnehmer_key = ?')->execute([$einheit_id, $key]);
        $db->prepare('INSERT INTO anwesenheiten (einheit_id, teilnehmer_key, gast_name, status, erfasst_von) VALUES (?, ?, ?, ?, ?)')
           ->execute([$einheit_id, $key, $gast, isset(ANWESENHEIT_STATUS[$gast_status ?? '']) ? $gast_status : 'probetraining', getCurrentUserId()]);
        $n++;
    }
    return $n;
}

// ----------------------------------------------------------------
// Trainer-Monatsabrechnung
// ----------------------------------------------------------------

/** Bestätigte Einheiten einer Person in einem Monat (nach Ist-Beginn). */
function trainerEinheitenMonat(PDO $db, int $user_id, int $jahr, int $monat, bool $nur_offen = false): array
{
    $von = sprintf('%04d-%02d-01 00:00:00', $jahr, $monat);
    $bis = date('Y-m-t 23:59:59', strtotime($von));
    $stmt = $db->prepare("SELECT et.*, e.titel, e.typ, e.start, e.ende, e.projekt_id, e.kurs_id, e.ort, p.name AS projekt_name, k.titel AS kurs_titel
                          FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                          LEFT JOIN projekte p ON p.id = e.projekt_id LEFT JOIN kurse k ON k.id = e.kurs_id
                          WHERE et.user_id = ? AND e.organization_id = ? AND COALESCE(et.ist_start, e.start) BETWEEN ? AND ?
                            AND et.status NOT IN ('geplant','storniert')" . ($nur_offen ? ' AND et.abrechnung_id IS NULL' : '') . '
                          ORDER BY COALESCE(et.ist_start, e.start)');
    $stmt->execute([$user_id, currentOrgId(), $von, $bis]);
    return $stmt->fetchAll();
}

function trainerAbrechnungLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT ta.*, u.vorname, u.nachname FROM trainer_abrechnungen ta JOIN users u ON u.id = ta.user_id WHERE ta.id = ? AND ta.organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/**
 * Monatsabrechnung aus den bestätigten Einheiten anlegen oder aktualisieren
 * (nur im Status „Entwurf“). Liefert die Abrechnung oder null (keine Einheiten).
 */
function trainerAbrechnungAktualisieren(PDO $db, int $user_id, int $jahr, int $monat): ?array
{
    $stmt = $db->prepare('SELECT * FROM trainer_abrechnungen WHERE user_id = ? AND jahr = ? AND monat = ?');
    $stmt->execute([$user_id, $jahr, $monat]);
    $ta = $stmt->fetch() ?: null;
    if ($ta && $ta['status'] !== 'entwurf') return $ta;

    $zeilen = array_filter(trainerEinheitenMonat($db, $user_id, $jahr, $monat), fn($z) => $z['abrechnung_id'] === null || ($ta && (int)$z['abrechnung_id'] === (int)$ta['id']));
    // Nachträge: Einheiten früherer Monate, die erst nach Einreichung jener Monatsabrechnung bestätigt wurden
    // (je Monat gibt es nur eine Abrechnung) – sie werden in die nächste offene Abrechnung übernommen.
    $stmt = $db->prepare("SELECT et.* FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                          JOIN trainer_abrechnungen ta ON ta.user_id = et.user_id AND ta.organization_id = e.organization_id AND ta.status <> 'entwurf'
                           AND ta.jahr = SUBSTR(COALESCE(et.ist_start, e.start), 1, 4) AND ta.monat = SUBSTR(COALESCE(et.ist_start, e.start), 6, 2)
                          WHERE et.user_id = ? AND e.organization_id = ? AND et.status = 'durchgefuehrt' AND et.abrechnung_id IS NULL AND COALESCE(et.ist_start, e.start) < ?");
    $stmt->execute([$user_id, currentOrgId(), sprintf('%04d-%02d-01 00:00:00', $jahr, $monat)]);
    foreach ($stmt->fetchAll() as $n) $zeilen[] = $n;
    if (!$zeilen) {
        if ($ta) $db->prepare('DELETE FROM trainer_abrechnungen WHERE id = ?')->execute([$ta['id']]);
        return null;
    }
    $betrag = moneySum(array_map(fn($z) => $z['betrag'] ?? '0', $zeilen));
    $minuten = array_sum(array_map(fn($z) => (int)$z['dauer_min'], $zeilen));
    if ($ta) {
        $db->prepare('UPDATE trainer_abrechnungen SET anzahl_einheiten = ?, minuten = ?, betrag = ? WHERE id = ?')->execute([count($zeilen), $minuten, $betrag, $ta['id']]);
        $id = (int)$ta['id'];
    } else {
        $art = 'honorar';
        try {
            $stmt = $db->prepare('SELECT 1 FROM prae_empfaenger WHERE user_id = ? AND aktiv = 1');
            $stmt->execute([$user_id]);
            if ($stmt->fetchColumn()) $art = 'prae';
        } catch (Exception $e) {}
        $db->prepare('INSERT INTO trainer_abrechnungen (organization_id, user_id, jahr, monat, anzahl_einheiten, minuten, betrag, auszahlungsart) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([currentOrgId(), $user_id, $jahr, $monat, count($zeilen), $minuten, $betrag, $art]);
        $id = (int)$db->lastInsertId();
    }
    $upd = $db->prepare('UPDATE einheit_trainer SET abrechnung_id = ? WHERE id = ?');
    foreach ($zeilen as $z) $upd->execute([$id, $z['id']]);
    return trainerAbrechnungLaden($db, $id);
}

/**
 * Status der Monatsabrechnung weiterschalten.
 * eingereicht → Einheiten „zur Abrechnung“; freigegeben → „abgerechnet“ + Kostenbuchungen;
 * bezahlt → „bezahlt“ + Buchungen bezahlt. Liefert Fehlertext oder null.
 */
function trainerAbrechnungStatus(PDO $db, array $ta, string $ziel): ?string
{
    $erlaubt = ['eingereicht' => ['entwurf'], 'geprueft' => ['eingereicht'], 'freigegeben' => ['geprueft', 'eingereicht'], 'bezahlt' => ['freigegeben'], 'entwurf' => ['eingereicht', 'geprueft']];
    if (!in_array($ta['status'], $erlaubt[$ziel] ?? [], true)) return 'Dieser Statuswechsel ist nicht möglich.';
    $jetzt = date('Y-m-d H:i:s');
    $uid = getCurrentUserId();
    $db->beginTransaction();
    try {
        switch ($ziel) {
            case 'eingereicht':
                $db->prepare("UPDATE trainer_abrechnungen SET status = 'eingereicht', eingereicht_am = ? WHERE id = ?")->execute([$jetzt, $ta['id']]);
                $db->prepare("UPDATE einheit_trainer SET status = 'zur_abrechnung' WHERE abrechnung_id = ?")->execute([$ta['id']]);
                break;
            case 'entwurf':
                $db->prepare("UPDATE trainer_abrechnungen SET status = 'entwurf', eingereicht_am = NULL, geprueft_am = NULL, geprueft_von = NULL WHERE id = ?")->execute([$ta['id']]);
                $db->prepare("UPDATE einheit_trainer SET status = 'durchgefuehrt' WHERE abrechnung_id = ?")->execute([$ta['id']]);
                break;
            case 'geprueft':
                $db->prepare("UPDATE trainer_abrechnungen SET status = 'geprueft', geprueft_von = ?, geprueft_am = ? WHERE id = ?")->execute([$uid, $jetzt, $ta['id']]);
                break;
            case 'freigegeben':
                $db->prepare("UPDATE trainer_abrechnungen SET status = 'freigegeben', freigegeben_von = ?, freigegeben_am = ? WHERE id = ?")->execute([$uid, $jetzt, $ta['id']]);
                $db->prepare("UPDATE einheit_trainer SET status = 'abgerechnet' WHERE abrechnung_id = ?")->execute([$ta['id']]);
                trainerKostenBuchen($db, $ta);
                break;
            case 'bezahlt':
                $db->prepare("UPDATE trainer_abrechnungen SET status = 'bezahlt', bezahlt_am = ? WHERE id = ?")->execute([date('Y-m-d'), $ta['id']]);
                $db->prepare("UPDATE einheit_trainer SET status = 'bezahlt' WHERE abrechnung_id = ?")->execute([$ta['id']]);
                $db->prepare("UPDATE buchungen SET status = 'bezahlt' WHERE trainer_abrechnung_id = ?")->execute([$ta['id']]);
                break;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        return 'Fehler beim Speichern: ' . $e->getMessage();
    }
    auditLog('status', 'trainer_abrechnungen', (int)$ta['id'], ['status' => $ta['status']], ['status' => $ziel],
             'Trainerabrechnung ' . $ta['monat'] . '/' . $ta['jahr'] . ' ' . ($ta['vorname'] ?? '') . ' ' . ($ta['nachname'] ?? ''));
    $monat = sprintf('%02d/%d', $ta['monat'], $ta['jahr']);
    if ($ziel === 'eingereicht') benachrichtigeAdmins($db, 'abrechnung', "Trainerabrechnung {$monat} eingereicht", trim(($ta['vorname'] ?? '') . ' ' . ($ta['nachname'] ?? '')), '/dashboard/admin/trainerabrechnungen.php?id=' . $ta['id']);
    if ($ziel === 'freigegeben') benachrichtigen((int)$ta['user_id'], 'abrechnung', "Deine Abrechnung {$monat} wurde freigegeben", moneyFormat($ta['betrag']), '/dashboard/zeiterfassung.php?jahr=' . $ta['jahr'] . '&monat=' . $ta['monat']);
    if ($ziel === 'bezahlt') benachrichtigen((int)$ta['user_id'], 'abrechnung', "Deine Abrechnung {$monat} wurde ausbezahlt", moneyFormat($ta['betrag']), '/dashboard/zeiterfassung.php?jahr=' . $ta['jahr'] . '&monat=' . $ta['monat']);
    return null;
}

/**
 * Kosten der freigegebenen Abrechnung als Ausgaben buchen – gruppiert nach
 * Projekt und Kurs; die Förderung kommt aus dem Projekt (Standard-Förderung).
 */
function trainerKostenBuchen(PDO $db, array $ta): void
{
    $db->prepare('DELETE FROM buchungen WHERE trainer_abrechnung_id = ?')->execute([$ta['id']]);
    $stmt = $db->prepare('SELECT e.projekt_id, e.kurs_id, p.foerderung_id, p.name AS projekt_name, k.titel AS kurs_titel, SUM(et.betrag) AS summe, COUNT(*) AS anzahl
                          FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id LEFT JOIN projekte p ON p.id = e.projekt_id LEFT JOIN kurse k ON k.id = e.kurs_id
                          WHERE et.abrechnung_id = ? AND et.betrag IS NOT NULL GROUP BY e.projekt_id, e.kurs_id, p.foerderung_id, p.name, k.titel');
    $stmt->execute([$ta['id']]);
    $name = trim(($ta['vorname'] ?? '') . ' ' . ($ta['nachname'] ?? ''));
    foreach ($stmt->fetchAll() as $g) {
        if (bccomp(moneyRound($g['summe']), '0', 2) <= 0) continue;
        $bezug = $g['projekt_name'] ?: ($g['kurs_titel'] ?: 'ohne Projekt');
        $db->prepare("INSERT INTO buchungen (organization_id, datum, art, betrag, kategorie, beschreibung, projekt_id, kurs_id, foerderung_id, trainer_abrechnung_id, status, erstellt_von)
                      VALUES (?, ?, 'ausgabe', ?, 'trainerhonorar', ?, ?, ?, ?, ?, 'offen', ?)")
           ->execute([currentOrgId(), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $ta['jahr'], $ta['monat']))), moneyRound($g['summe']),
                      mb_substr("Trainerhonorar {$name} " . sprintf('%02d/%d', $ta['monat'], $ta['jahr']) . " – {$bezug} ({$g['anzahl']} Einheiten)", 0, 255),
                      $g['projekt_id'], $g['kurs_id'], $g['foerderung_id'], $ta['id'], getCurrentUserId()]);
    }
}

/**
 * Freigegebene Abrechnung als PRAE-Einsatztage übernehmen (Summe je Tag).
 * Voraussetzung: Person ist als PRAE-Empfänger:in hinterlegt.
 */
function trainerAbrechnungAlsPrae(PDO $db, array $ta): array
{
    require_once ROOT_PATH . '/includes/prae.php';
    $stmt = $db->prepare('SELECT * FROM prae_empfaenger WHERE user_id = ? AND organization_id = ?');
    $stmt->execute([$ta['user_id'], currentOrgId()]);
    $empf = $stmt->fetch();
    if (!$empf) return ['fehler' => 'Die Person ist nicht als PRAE-Empfänger:in hinterlegt (Administration → PRAE-Abrechnung).'];
    // Summe je Tag in PHP bilden (datenbankunabhängig)
    $stmt = $db->prepare('SELECT COALESCE(et.ist_start, e.start) AS zeit, et.betrag, e.titel FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id WHERE et.abrechnung_id = ? ORDER BY zeit');
    $stmt->execute([$ta['id']]);
    $je_tag = [];
    foreach ($stmt->fetchAll() as $r) {
        $tag = substr($r['zeit'], 0, 10);
        $je_tag[$tag]['summe'][] = $r['betrag'] ?? '0';
        $je_tag[$tag]['titel'][$r['titel']] = true;
    }
    $tage = [];
    foreach ($je_tag as $tag => $w) $tage[] = ['tag' => $tag, 'summe' => moneySum($w['summe']), 'titel' => implode(', ', array_keys($w['titel']))];
    $neu = $uebersprungen = 0;
    foreach ($tage as $t) {
        if (!praeMonatOffen($db, (int)$empf['id'], $t['tag'])) { $uebersprungen++; continue; }
        $stmt = $db->prepare('SELECT COUNT(*) FROM prae_einsaetze WHERE empfaenger_id = ? AND datum = ?');
        $stmt->execute([$empf['id'], $t['tag']]);
        if ($stmt->fetchColumn()) { $uebersprungen++; continue; }
        $db->prepare("INSERT INTO prae_einsaetze (organization_id, empfaenger_id, datum, art, beschreibung, betrag, erfasst_von) VALUES (?, ?, ?, 'training', ?, ?, ?)")
           ->execute([currentOrgId(), $empf['id'], $t['tag'], mb_substr((string)$t['titel'], 0, 255), moneyRound($t['summe']), getCurrentUserId()]);
        $neu++;
    }
    praeAbrechnungAktualisieren($db, (int)$empf['id'], (int)$ta['jahr'], (int)$ta['monat']);
    $db->prepare('UPDATE trainer_abrechnungen SET prae_uebernommen_am = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $ta['id']]);
    return ['neu' => $neu, 'uebersprungen' => $uebersprungen, 'empfaenger_id' => (int)$empf['id']];
}

/** Kurzform Dauer „1:30 h“. */
function dauerText(?int $minuten): string
{
    if ($minuten === null) return '–';
    return intdiv($minuten, 60) . ':' . str_pad((string)($minuten % 60), 2, '0', STR_PAD_LEFT) . ' h';
}
