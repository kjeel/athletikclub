<?php
/**
 * Athletikclub Steiermark – Rechnungen, Zahlungen, Mahnwesen
 *
 * Ablauf: Entwurf (frei bearbeitbar, ohne Nummer) → Ausstellen (fortlaufende Nummer je
 * Jahr, danach unveränderlich) → Zahlungen (auch Teilzahlungen) → bezahlt.
 * Korrekturen nur über Stornorechnung; ausgestellte Rechnungen werden nie gelöscht.
 * Mahnwesen: Stufen werden je Rechnung höchstens einmal angelegt (UNIQUE rechnung_id+stufe),
 * automatisch vorbereitet und je nach Einstellung automatisch oder manuell versendet.
 * Beträge ausschließlich mit bcmath.
 */

require_once ROOT_PATH . '/includes/kommunikation.php';

const RECHNUNG_STATUS = [
    'entwurf'      => ['label' => 'Entwurf',     'class' => 'badge-gray'],
    'offen'        => ['label' => 'Offen',       'class' => 'badge-info'],
    'faellig'      => ['label' => 'Fällig',      'class' => 'badge-warning'],
    'ueberfaellig' => ['label' => 'Überfällig',  'class' => 'badge-danger'],
    'bezahlt'      => ['label' => 'Bezahlt',     'class' => 'badge-success'],
    'storniert'    => ['label' => 'Storniert',   'class' => 'badge-navy'],
];
const ZAHLUNGSARTEN = ['ueberweisung' => 'Überweisung', 'bar' => 'Bar', 'karte' => 'Karte', 'sonstiges' => 'Sonstiges'];
const MAHNSTUFEN = [1 => ['Zahlungserinnerung', 'zahlungserinnerung'], 2 => ['1. Mahnung', 'mahnung_1'], 3 => ['2. Mahnung', 'mahnung_2']];

/** Anzeigestatus: offen → fällig (heute) / überfällig (Datum überschritten). */
function rechnungAnzeigeStatus(array $r): string
{
    if ($r['status'] !== 'offen' || !$r['faellig_am']) return $r['status'];
    $heute = date('Y-m-d');
    if ($r['faellig_am'] < $heute) return 'ueberfaellig';
    if ($r['faellig_am'] === $heute) return 'faellig';
    return 'offen';
}

function rechnungLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM rechnungen WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

function rechnungPositionen(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM rechnung_positionen WHERE rechnung_id = ? ORDER BY pos, id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function rechnungBezahlt(PDO $db, int $id): string
{
    $stmt = $db->prepare('SELECT betrag FROM zahlungen WHERE rechnung_id = ?');
    $stmt->execute([$id]);
    return moneySum(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function rechnungOffenBetrag(PDO $db, array $r): string
{
    return bcsub(moneyRound($r['betrag_brutto']), rechnungBezahlt($db, (int)$r['id']), 2);
}

/** Summen aus den Positionen neu berechnen (netto, USt je Satz, brutto). */
function rechnungSummenNeu(PDO $db, int $id): array
{
    $netto = $ust = '0.00';
    foreach (rechnungPositionen($db, $id) as $p) {
        $betrag = moneyRound(bcmul((string)$p['menge'], (string)$p['einzelpreis'], 4));
        if ($betrag !== moneyRound($p['betrag'])) $db->prepare('UPDATE rechnung_positionen SET betrag = ? WHERE id = ?')->execute([$betrag, $p['id']]);
        $netto = bcadd($netto, $betrag, 2);
        if ((float)$p['ust_satz'] > 0) $ust = bcadd($ust, moneyRound(bcdiv(bcmul($betrag, (string)$p['ust_satz'], 4), '100', 4)), 2);
    }
    $brutto = bcadd($netto, $ust, 2);
    $db->prepare('UPDATE rechnungen SET betrag_netto = ?, betrag_ust = ?, betrag_brutto = ? WHERE id = ?')->execute([$netto, $ust, $brutto, $id]);
    return compact('netto', 'ust', 'brutto');
}

/** Nächste Nummer aus dem Nummernkreis (innerhalb einer laufenden Transaktion, gesperrt). */
function naechsteNummer(PDO $db, string $kreis, int $jahr): int
{
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $stmt = $db->prepare('SELECT letzte_nr FROM nummernkreise WHERE organization_id = ? AND kreis = ? AND jahr = ?' . ($mysql ? ' FOR UPDATE' : ''));
    $stmt->execute([currentOrgId(), $kreis, $jahr]);
    $letzte = $stmt->fetchColumn();
    if ($letzte === false) {
        $db->prepare('INSERT INTO nummernkreise (organization_id, kreis, jahr, letzte_nr) VALUES (?, ?, ?, 1)')->execute([currentOrgId(), $kreis, $jahr]);
        return 1;
    }
    $neu = (int)$letzte + 1;
    $db->prepare('UPDATE nummernkreise SET letzte_nr = ? WHERE organization_id = ? AND kreis = ? AND jahr = ?')->execute([$neu, currentOrgId(), $kreis, $jahr]);
    return $neu;
}

/** Entwurf prüfen und ausstellen. Liefert Fehlermeldung oder null. */
function rechnungAusstellen(PDO $db, int $id, ?string $rechnungsdatum = null): ?string
{
    $r = rechnungLaden($db, $id);
    if (!$r || $r['status'] !== 'entwurf') return 'Nur Entwürfe können ausgestellt werden.';
    if (trim($r['empf_name']) === '') return 'Bitte einen Empfänger angeben.';
    if (!rechnungPositionen($db, $id)) return 'Die Rechnung hat keine Positionen.';
    $summen = rechnungSummenNeu($db, $id);
    if ($r['typ'] === 'rechnung' && bccomp($summen['brutto'], '0', 2) <= 0) return 'Der Rechnungsbetrag muss größer als 0 sein.';
    $datum = $rechnungsdatum && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rechnungsdatum) ? $rechnungsdatum : date('Y-m-d');
    $ziel = $r['faellig_am'] ?: date('Y-m-d', strtotime($datum . ' +' . max(0, (int)einstellung('rechnung_zahlungsziel', '14')) . ' days'));
    $db->beginTransaction();
    try {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $stmt = $db->prepare('SELECT status FROM rechnungen WHERE id = ?' . ($mysql ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() !== 'entwurf') {
            $db->rollBack();
            return 'Die Rechnung wurde inzwischen bereits ausgestellt.';
        }
        $jahr = (int)substr($datum, 0, 4);
        $nr = naechsteNummer($db, 'rechnung', $jahr);
        $nummer = preg_replace('/[^A-Za-z0-9-]/', '', einstellung('rechnung_prefix', 'RE')) . '-' . $jahr . '-' . str_pad((string)$nr, 4, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE rechnungen SET nummer = ?, status = ?, rechnungsdatum = ?, faellig_am = ?, ausgestellt_am = ?, ausgestellt_von = ?, zugriff_token = ?,
                      steuerhinweis = COALESCE(steuerhinweis, ?) WHERE id = ? AND status = 'entwurf'")
           ->execute([$nummer, $r['typ'] === 'storno' ? 'storniert' : 'offen', $datum, $r['typ'] === 'storno' ? null : $ziel, date('Y-m-d H:i:s'), getCurrentUserId(),
                      bin2hex(random_bytes(16)), einstellung('rechnung_steuerhinweis', '') ?: null, $id]);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    auditLog('status', 'rechnungen', $id, ['status' => 'entwurf'], ['status' => 'offen', 'nummer' => $nummer, 'betrag' => $summen['brutto']], 'Rechnung ' . $nummer . ' ausgestellt');
    return null;
}

/** Ausgestellte Rechnung stornieren: Stornorechnung mit negativen Positionen, Original → storniert. */
function rechnungStornieren(PDO $db, int $id, string $grund): array
{
    $r = rechnungLaden($db, $id);
    if (!$r || !in_array($r['status'], ['offen', 'bezahlt'], true) || $r['typ'] !== 'rechnung') return ['fehler' => 'Nur ausgestellte Rechnungen können storniert werden.'];
    $felder = ['organization_id', 'user_id', 'partner_id', 'kurs_id', 'projekt_id', 'anmeldung_id', 'empf_name', 'empf_zusatz', 'empf_strasse', 'empf_plz', 'empf_ort', 'empf_land', 'empf_email', 'empf_uid', 'leistung_von', 'leistung_bis', 'steuerhinweis'];
    $werte = array_intersect_key($r, array_flip($felder)) + ['typ' => 'storno', 'status' => 'entwurf', 'storno_zu_id' => $id, 'erstellt_von' => getCurrentUserId(),
             'text_oben' => 'Stornorechnung zu Rechnung ' . $r['nummer'] . ' vom ' . date('d.m.Y', strtotime($r['rechnungsdatum'])) . ($grund !== '' ? '. Grund: ' . $grund : '.')];
    $db->prepare('INSERT INTO rechnungen (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')->execute(array_values($werte));
    $sid = (int)$db->lastInsertId();
    foreach (rechnungPositionen($db, $id) as $p) {
        $db->prepare('INSERT INTO rechnung_positionen (rechnung_id, pos, beschreibung, menge, einheit, einzelpreis, ust_satz, betrag, anmeldung_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$sid, $p['pos'], $p['beschreibung'], bcmul((string)$p['menge'], '-1', 2), $p['einheit'], $p['einzelpreis'], $p['ust_satz'], bcmul((string)$p['betrag'], '-1', 2), $p['anmeldung_id']]);
    }
    rechnungSummenNeu($db, $sid);
    if ($f = rechnungAusstellen($db, $sid)) return ['fehler' => $f];
    $db->prepare("UPDATE rechnungen SET status = 'storniert' WHERE id = ?")->execute([$id]);
    auditLog('status', 'rechnungen', $id, ['status' => $r['status']], ['status' => 'storniert', 'storno_id' => $sid], 'Storniert: ' . $grund);
    return ['id' => $sid, 'bezahlt' => rechnungBezahlt($db, $id)];
}

/** Rechnungspositionen, die zu Kursanmeldungen gehören. */
function rechnungAnmeldungen(PDO $db, array $r): array
{
    $ids = array_filter(array_map('intval', array_column(rechnungPositionen($db, (int)$r['id']), 'anmeldung_id')));
    if ($r['anmeldung_id']) $ids[] = (int)$r['anmeldung_id'];
    return array_values(array_unique($ids));
}

/**
 * Zahlung erfassen. Kursbeiträge werden in der Finanzübersicht aus den bezahlten Anmeldungen
 * gerechnet – dort wird nur die Anmeldung als bezahlt markiert. Alle anderen Rechnungen
 * erzeugen eine Einnahme-Buchung (keine Doppelzählung).
 */
function zahlungErfassen(PDO $db, array $r, string $betrag, string $datum, string $art, ?string $referenz): array
{
    if (!in_array($r['status'], ['offen', 'bezahlt'], true) || $r['typ'] !== 'rechnung') return ['fehler' => 'Zahlungen nur für ausgestellte Rechnungen.'];
    $betrag = moneyRound($betrag);
    if (bccomp($betrag, '0', 2) <= 0) return ['fehler' => 'Der Betrag muss größer als 0 sein.'];
    $noch_offen = rechnungOffenBetrag($db, $r);
    if (bccomp($betrag, $noch_offen, 2) > 0) {
        return ['fehler' => 'Der Betrag übersteigt den offenen Betrag von ' . moneyFormat($noch_offen) . '. Eine Überzahlung bitte als Rückzahlung bzw. Gutschrift gesondert behandeln.'];
    }
    $anmeldungen = rechnungAnmeldungen($db, $r);
    $buchung_id = null;
    $db->beginTransaction();
    try {
        if (!$anmeldungen) {
            $kat = $r['kurs_id'] ? 'kursbeitrag' : ($r['partner_id'] ? 'sponsoring' : 'sonstiges');
            $db->prepare("INSERT INTO buchungen (organization_id, datum, art, betrag, kategorie, beschreibung, projekt_id, kurs_id, belegnummer, status, erstellt_von) VALUES (?, ?, 'einnahme', ?, ?, ?, ?, ?, ?, 'bezahlt', ?)")
               ->execute([currentOrgId(), $datum, $betrag, $kat, mb_substr('Zahlung Rechnung ' . $r['nummer'] . ' – ' . $r['empf_name'], 0, 255), $r['projekt_id'], $r['kurs_id'], $r['nummer'], getCurrentUserId()]);
            $buchung_id = (int)$db->lastInsertId();
        }
        $db->prepare('INSERT INTO zahlungen (organization_id, rechnung_id, betrag, datum, zahlungsart, referenz, buchung_id, erfasst_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([currentOrgId(), $r['id'], $betrag, $datum, isset(ZAHLUNGSARTEN[$art]) ? $art : 'sonstiges', $referenz ? mb_substr($referenz, 0, 120) : null, $buchung_id, getCurrentUserId()]);
        $zid = (int)$db->lastInsertId();
        $offen = rechnungOffenBetrag($db, $r);
        if (bccomp($offen, '0', 2) <= 0) {
            $db->prepare("UPDATE rechnungen SET status = 'bezahlt' WHERE id = ?")->execute([$r['id']]);
            foreach ($anmeldungen as $aid) $db->prepare('UPDATE kurs_anmeldungen SET bezahlt = 1, bezahlt_am = ? WHERE id = ?')->execute([$datum . ' 12:00:00', $aid]);
            // Offene Mahnungen erledigen sich
            $db->prepare("UPDATE mahnungen SET status = 'verworfen' WHERE rechnung_id = ? AND status = 'vorbereitet'")->execute([$r['id']]);
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    auditLog('erstellt', 'zahlungen', $zid, null, ['rechnung' => $r['nummer'], 'betrag' => $betrag, 'datum' => $datum, 'art' => $art], 'Zahlung ' . $r['nummer']);
    return ['id' => $zid, 'offen' => $offen];
}

/** Zahlung zurücknehmen (Fehlerfassung). */
function zahlungLoeschen(PDO $db, array $r, int $zahlung_id): bool
{
    $stmt = $db->prepare('SELECT * FROM zahlungen WHERE id = ? AND rechnung_id = ?');
    $stmt->execute([$zahlung_id, $r['id']]);
    $z = $stmt->fetch();
    if (!$z || $r['status'] === 'storniert') return false;
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM zahlungen WHERE id = ?')->execute([$z['id']]);
        if ($z['buchung_id']) $db->prepare('DELETE FROM buchungen WHERE id = ?')->execute([$z['buchung_id']]);
        if ($r['status'] === 'bezahlt' && bccomp(rechnungOffenBetrag($db, $r), '0', 2) > 0) {
            $db->prepare("UPDATE rechnungen SET status = 'offen' WHERE id = ?")->execute([$r['id']]);
            foreach (rechnungAnmeldungen($db, $r) as $aid) $db->prepare('UPDATE kurs_anmeldungen SET bezahlt = 0, bezahlt_am = NULL WHERE id = ?')->execute([$aid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    auditLog('geloescht', 'zahlungen', (int)$z['id'], $z, null, 'Zahlung zurückgenommen ' . $r['nummer']);
    return true;
}

/** Empfängerdaten aus Konto (users + mitglieder_profile) oder Partner übernehmen. */
function empfaengerDaten(PDO $db, ?int $user_id, ?int $partner_id): array
{
    if ($partner_id) {
        $stmt = $db->prepare('SELECT * FROM partner_organisationen WHERE id = ? AND organization_id = ?');
        $stmt->execute([$partner_id, currentOrgId()]);
        if ($p = $stmt->fetch()) return ['empf_name' => $p['name'], 'empf_zusatz' => $p['ansprechpartner'] ? 'z. H. ' . $p['ansprechpartner'] : null, 'empf_strasse' => $p['strasse'],
                                         'empf_plz' => $p['plz'], 'empf_ort' => $p['ort'], 'empf_land' => null, 'empf_email' => $p['email']];
    }
    if ($user_id) {
        $stmt = $db->prepare('SELECT u.vorname, u.nachname, u.email, mp.strasse, mp.plz, mp.ort, mp.land FROM users u LEFT JOIN mitglieder_profile mp ON mp.user_id = u.id WHERE u.id = ? AND u.organization_id = ?');
        $stmt->execute([$user_id, currentOrgId()]);
        if ($u = $stmt->fetch()) return ['empf_name' => $u['vorname'] . ' ' . $u['nachname'], 'empf_zusatz' => null, 'empf_strasse' => $u['strasse'], 'empf_plz' => $u['plz'],
                                         'empf_ort' => $u['ort'], 'empf_land' => $u['land'] && $u['land'] !== 'Österreich' ? $u['land'] : null, 'empf_email' => $u['email']];
    }
    return [];
}

/** Rechnungsentwurf zu einer Kursanmeldung (einmal je Anmeldung). Liefert Rechnungs-ID. */
function rechnungAusAnmeldung(PDO $db, int $anmeldung_id): ?int
{
    $stmt = $db->prepare("SELECT r.id FROM rechnungen r WHERE r.anmeldung_id = ? AND r.typ = 'rechnung' AND r.status <> 'storniert' LIMIT 1");
    $stmt->execute([$anmeldung_id]);
    if ($id = $stmt->fetchColumn()) return (int)$id;
    $stmt = $db->prepare('SELECT ka.*, k.titel, k.preis, k.start_datum, k.end_datum, k.projekt_id, k.art, ki.vorname AS kind_vorname, ki.nachname AS kind_nachname
                          FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id LEFT JOIN kinder ki ON ki.id = ka.kind_id WHERE ka.id = ? AND ka.organization_id = ?');
    $stmt->execute([$anmeldung_id, currentOrgId()]);
    $a = $stmt->fetch();
    if (!$a || (float)$a['preis'] <= 0) return null;
    $empf = empfaengerDaten($db, (int)$a['user_id'], null);
    $werte = $empf + ['organization_id' => currentOrgId(), 'typ' => 'rechnung', 'status' => 'entwurf', 'user_id' => $a['user_id'], 'kurs_id' => $a['kurs_id'], 'projekt_id' => $a['projekt_id'],
             'anmeldung_id' => $anmeldung_id, 'leistung_von' => substr($a['start_datum'], 0, 10), 'leistung_bis' => substr($a['end_datum'], 0, 10), 'erstellt_von' => getCurrentUserId()];
    $db->prepare('INSERT INTO rechnungen (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')->execute(array_values($werte));
    $rid = (int)$db->lastInsertId();
    $person = $a['kind_vorname'] ? $a['kind_vorname'] . ' ' . $a['kind_nachname'] : ($empf['empf_name'] ?? '');
    $db->prepare('INSERT INTO rechnung_positionen (rechnung_id, pos, beschreibung, menge, einheit, einzelpreis, betrag, anmeldung_id) VALUES (?, 1, ?, 1, ?, ?, ?, ?)')
       ->execute([$rid, mb_substr(($a['art'] === 'event' ? 'Teilnahme ' : 'Kursbeitrag ') . $a['titel'] . ' – ' . $person, 0, 300), 'Teiln.', moneyRound($a['preis']), moneyRound($a['preis']), $anmeldung_id]);
    rechnungSummenNeu($db, $rid);
    auditLog('erstellt', 'rechnungen', $rid, null, ['anmeldung_id' => $anmeldung_id, 'betrag' => $a['preis']], 'Rechnungsentwurf aus Kursanmeldung');
    return $rid;
}

/** Link für Empfänger:innen ohne Login. */
function rechnungLink(array $r): string
{
    return $r['zugriff_token'] ? APP_URL . '/rechnung/' . $r['zugriff_token'] : APP_URL . '/dashboard/admin/rechnungen.php?id=' . (int)$r['id'];
}

/** Rechnung per Vorlage verschicken (Link zur Online-Ansicht mit PDF). */
function rechnungVersenden(PDO $db, array $r, string $vorlage = 'rechnung_versand', array $extra = []): bool
{
    if (!$r['nummer']) return false;
    $vars = ['rechnung' => $r['nummer'], 'rechnungsdatum' => date('d.m.Y', strtotime($r['rechnungsdatum'])), 'faellig' => $r['faellig_am'] ? date('d.m.Y', strtotime($r['faellig_am'])) : '',
             'betrag' => moneyFormat($extra['betrag'] ?? $r['betrag_brutto']), 'link' => rechnungLink($r)] + $extra;
    if ($r['user_id']) {
        $e = nachrichtAnPerson($db, (int)$r['user_id'], $vorlage, $vars, rechnungLink($r), 'rechnung:' . $r['id'], null, $r['empf_email'] ? null : 'intern');
        return $e['email'] || $e['intern'];
    }
    if ($r['empf_email']) return nachrichtAnAdresse($db, $r['empf_email'], $r['empf_zusatz'] ? preg_replace('/^z\. H\. /', '', $r['empf_zusatz']) : $r['empf_name'], $vorlage, $vars, 'rechnung:' . $r['id']);
    return false;
}

/** Fällige Mahnstufe (0 = keine) nach Tagen Verzug laut Einstellungen. */
function mahnstufeFaellig(array $r): int
{
    if ($r['status'] !== 'offen' || !$r['faellig_am']) return 0;
    $tage = (int)floor((strtotime(date('Y-m-d')) - strtotime($r['faellig_am'])) / 86400);
    $stufe = 0;
    foreach ([1, 2, 3] as $s) if ($tage >= (int)einstellung('mahnung_tage_' . $s, (string)[1 => 7, 2 => 21, 3 => 35][$s])) $stufe = $s;
    return $stufe;
}

/** Mahnung einer Stufe vorbereiten (höchstens einmal je Stufe). Liefert Mahnungs-ID oder null. */
function mahnungVorbereiten(PDO $db, array $r, int $stufe, ?int $von = null): ?int
{
    $stmt = $db->prepare('SELECT id FROM mahnungen WHERE rechnung_id = ? AND stufe = ?');
    $stmt->execute([$r['id'], $stufe]);
    if ($stmt->fetchColumn()) return null;
    try {
        $db->prepare('INSERT INTO mahnungen (rechnung_id, stufe, status, offener_betrag, erstellt_von) VALUES (?, ?, ?, ?, ?)')
           ->execute([$r['id'], $stufe, 'vorbereitet', rechnungOffenBetrag($db, $r), $von]);
    } catch (PDOException $e) {
        return null; // parallel bereits angelegt (UNIQUE)
    }
    return (int)$db->lastInsertId();
}

/** Vorbereitete Mahnung versenden – nur einmal (Statuswechsel atomar). */
function mahnungVersenden(PDO $db, int $mahnung_id): bool
{
    $stmt = $db->prepare("UPDATE mahnungen SET status = 'versendet', versendet_am = ?, kanal = 'email' WHERE id = ? AND status = 'vorbereitet'");
    $stmt->execute([date('Y-m-d H:i:s'), $mahnung_id]);
    if ($stmt->rowCount() !== 1) return false;
    $stmt = $db->prepare('SELECT m.*, r.id AS rid FROM mahnungen m JOIN rechnungen r ON r.id = m.rechnung_id WHERE m.id = ?');
    $stmt->execute([$mahnung_id]);
    $m = $stmt->fetch();
    $r = rechnungLaden($db, (int)$m['rid']);
    $ok = rechnungVersenden($db, $r, MAHNSTUFEN[(int)$m['stufe']][1], ['betrag' => $m['offener_betrag']]);
    $db->prepare('UPDATE rechnungen SET mahnstufe = ? WHERE id = ?')->execute([max((int)$r['mahnstufe'], (int)$m['stufe']), $r['id']]);
    auditLog('status', 'mahnungen', $mahnung_id, ['status' => 'vorbereitet'], ['status' => 'versendet', 'zugestellt' => $ok ? 'ja' : 'nein'], MAHNSTUFEN[(int)$m['stufe']][0] . ' ' . $r['nummer']);
    return true;
}

/**
 * Mahnlauf (Automatisierung): überfällige Rechnungen → nächste fällige Stufe vorbereiten,
 * bei aktivem Autoversand versenden. Liefert ['vorbereitet' => n, 'versendet' => n].
 */
function rechnungenMahnlauf(PDO $db): array
{
    $erg = ['vorbereitet' => 0, 'versendet' => 0, 'details' => []];
    $stmt = $db->prepare("SELECT * FROM rechnungen WHERE organization_id = ? AND status = 'offen' AND typ = 'rechnung' AND faellig_am < ?");
    $stmt->execute([currentOrgId(), date('Y-m-d')]);
    foreach ($stmt->fetchAll() as $r) {
        $stufe = mahnstufeFaellig($r);
        if (!$stufe) continue;
        // Nur die höchste fällige Stufe – frühere Stufen werden nicht nachträglich erzeugt
        $mid = mahnungVorbereiten($db, $r, $stufe);
        if (!$mid) continue;
        $erg['vorbereitet']++;
        $erg['details'][] = $r['nummer'] . ': ' . MAHNSTUFEN[$stufe][0];
        if (einstellung('mahnung_auto_versand', '0') === '1' && mahnungVersenden($db, $mid)) $erg['versendet']++;
    }
    return $erg;
}

/** Summe aller offenen Beträge (für KPIs). */
function rechnungenOffenSumme(PDO $db): array
{
    $erg = ['offen' => '0.00', 'ueberfaellig' => '0.00', 'anzahl' => 0, 'anzahl_ueberfaellig' => 0];
    try {
        $stmt = $db->prepare("SELECT r.id, r.betrag_brutto, r.faellig_am, (SELECT COALESCE(SUM(z.betrag), 0) FROM zahlungen z WHERE z.rechnung_id = r.id) AS bezahlt
                              FROM rechnungen r WHERE r.organization_id = ? AND r.status = 'offen' AND r.typ = 'rechnung'");
        $stmt->execute([currentOrgId()]);
        foreach ($stmt->fetchAll() as $r) {
            $offen = bcsub(moneyRound($r['betrag_brutto']), moneyRound($r['bezahlt']), 2);
            $erg['offen'] = bcadd($erg['offen'], $offen, 2);
            $erg['anzahl']++;
            if ($r['faellig_am'] && $r['faellig_am'] < date('Y-m-d')) { $erg['ueberfaellig'] = bcadd($erg['ueberfaellig'], $offen, 2); $erg['anzahl_ueberfaellig']++; }
        }
    } catch (Exception $e) {}
    return $erg;
}
