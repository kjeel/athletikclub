<?php
/**
 * Athletikclub Steiermark – Events: Laden und Kennzahlen (genutzt von Events-Seite und Eventbericht)
 */

require_once ROOT_PATH . '/includes/money.php';

/** Event (Kurs mit art = event) inkl. Projekt und erster Einheit laden. */
function eventLaden(PDO $db, int $kurs_id): ?array
{
    $stmt = $db->prepare("SELECT k.*, p.name AS projekt_name, p.leitung_id, p.budget, p.status AS projekt_status, u.vorname AS leitung_vorname, u.nachname AS leitung_nachname
                          FROM kurse k LEFT JOIN projekte p ON p.id = k.projekt_id LEFT JOIN users u ON u.id = p.leitung_id
                          WHERE k.id = ? AND k.organization_id = ? AND k.art = 'event'");
    $stmt->execute([$kurs_id, currentOrgId()]);
    $e = $stmt->fetch();
    if (!$e) return null;
    $stmt = $db->prepare("SELECT * FROM einheiten WHERE kurs_id = ? ORDER BY start LIMIT 1");
    $stmt->execute([$kurs_id]);
    $e['einheit'] = $stmt->fetch() ?: null;
    return $e;
}

/** Finanz- und Teilnahmekennzahlen eines Events (für Übersicht und Bericht). */
function eventKennzahlen(PDO $db, array $e): array
{
    $k = ['angemeldet' => 0, 'warteliste' => 0, 'angefragt' => 0, 'eingecheckt' => 0, 'einnahmen_teilnahme' => '0.00', 'einnahmen' => '0.00', 'ausgaben' => '0.00', 'helfer' => 0, 'aufgaben_offen' => 0];
    $stmt = $db->prepare('SELECT status, bezahlt, eingecheckt_am FROM kurs_anmeldungen WHERE kurs_id = ?');
    $stmt->execute([$e['id']]);
    foreach ($stmt->fetchAll() as $a) {
        if (in_array($a['status'], ['angemeldet', 'teilgenommen'], true)) $k['angemeldet']++;
        if (isset($k[$a['status']]) && in_array($a['status'], ['warteliste', 'angefragt'], true)) $k[$a['status']]++;
        if ($a['eingecheckt_am']) $k['eingecheckt']++;
        if ((int)$a['bezahlt'] && (float)$e['preis'] > 0) $k['einnahmen_teilnahme'] = bcadd($k['einnahmen_teilnahme'], moneyRound($e['preis']), 2);
    }
    if ($e['einheit']) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM anwesenheiten WHERE einheit_id = ? AND status IN ('anwesend','probetraining')");
        $stmt->execute([$e['einheit']['id']]);
        $k['eingecheckt'] = max($k['eingecheckt'], (int)$stmt->fetchColumn());
    }
    if ($e['projekt_id']) {
        $stmt = $db->prepare('SELECT art, betrag FROM buchungen WHERE projekt_id = ?');
        $stmt->execute([$e['projekt_id']]);
        foreach ($stmt->fetchAll() as $b) $k[$b['art'] === 'einnahme' ? 'einnahmen' : 'ausgaben'] = bcadd($k[$b['art'] === 'einnahme' ? 'einnahmen' : 'ausgaben'], moneyRound($b['betrag']), 2);
        $stmt = $db->prepare('SELECT COUNT(*) FROM projekt_team WHERE projekt_id = ?');
        $stmt->execute([$e['projekt_id']]);
        $k['helfer'] = (int)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM aufgaben WHERE projekt_id = ? AND status <> 'erledigt'");
        $stmt->execute([$e['projekt_id']]);
        $k['aufgaben_offen'] = (int)$stmt->fetchColumn();
    }
    $k['einnahmen_gesamt'] = bcadd($k['einnahmen'], $k['einnahmen_teilnahme'], 2);
    $k['ergebnis'] = bcsub($k['einnahmen_gesamt'], $k['ausgaben'], 2);
    return $k;
}
