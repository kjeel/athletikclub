<?php
/**
 * Athletikclub Steiermark – Check-in (QR-Code oder manuell)
 * Der QR-Code enthält nur /checkin/{checkin_code} (128-Bit-Zufallswert, keine Personendaten).
 * Eingelöst wird ausschließlich serverseitig durch eingeloggte Kursleitung/Einsatzkräfte;
 * jeder Check-in erzeugt bzw. aktualisiert die Anwesenheit der heutigen Einheit.
 */

require_once ROOT_PATH . '/includes/einheiten.php';

/** Heutige (bzw. gerade laufende) Einheit eines Kurses/Events – nächstgelegene zuerst. */
function checkinEinheit(PDO $db, int $kurs_id): ?array
{
    $stmt = $db->prepare("SELECT * FROM einheiten WHERE kurs_id = ? AND status <> 'storniert' AND start BETWEEN ? AND ? ORDER BY start");
    $stmt->execute([$kurs_id, date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')]);
    $heute = $stmt->fetchAll();
    if (!$heute) return null;
    $jetzt = time();
    usort($heute, fn($a, $b) => abs(strtotime($a['start']) - $jetzt) <=> abs(strtotime($b['start']) - $jetzt));
    return $heute[0];
}

/** Darf die aktuelle Person für diese Einheit einchecken? */
function checkinDarf(PDO $db, array $kurs, ?array $einheit): bool
{
    if (darfEines('anwesenheit.bearbeiten', 'kalender.bearbeiten')) return true;
    if ((int)$kurs['trainer_id'] === (int)getCurrentUserId()) return true;
    return $einheit && einheitIstMeine($db, (int)$einheit['id']);
}

/** Teilnehmer-Schlüssel wie in der Anwesenheitsliste (u<user> bzw. k<kind>). */
function checkinSchluessel(array $anmeldung): string
{
    return (int)$anmeldung['kind_id'] > 0 ? 'k' . (int)$anmeldung['kind_id'] : 'u' . (int)$anmeldung['user_id'];
}

/** Check-in speichern. Liefert ['neu' => bool, 'zeit' => 'H:i'] */
function checkinErfassen(PDO $db, array $anmeldung, array $einheit): array
{
    $key = checkinSchluessel($anmeldung);
    $stmt = $db->prepare('SELECT status, erfasst_am FROM anwesenheiten WHERE einheit_id = ? AND teilnehmer_key = ?');
    $stmt->execute([$einheit['id'], $key]);
    $vorher = $stmt->fetch();
    if ($vorher && $vorher['status'] === 'anwesend') return ['neu' => false, 'zeit' => date('H:i', strtotime($vorher['erfasst_am']))];
    $kind = (int)$anmeldung['kind_id'] > 0;
    $db->prepare('DELETE FROM anwesenheiten WHERE einheit_id = ? AND teilnehmer_key = ?')->execute([$einheit['id'], $key]);
    $db->prepare("INSERT INTO anwesenheiten (einheit_id, teilnehmer_key, user_id, kind_id, status, erfasst_von, erfasst_am) VALUES (?, ?, ?, ?, 'anwesend', ?, ?)")
       ->execute([$einheit['id'], $key, $kind ? null : (int)$anmeldung['user_id'], $kind ? (int)$anmeldung['kind_id'] : null, getCurrentUserId(), date('Y-m-d H:i:s')]);
    $db->prepare('UPDATE kurs_anmeldungen SET eingecheckt_am = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $anmeldung['id']]);
    auditLog('erstellt', 'anwesenheiten', (int)$einheit['id'], null, ['teilnehmer' => $key, 'status' => 'anwesend', 'quelle' => 'Check-in']);
    return ['neu' => true, 'zeit' => date('H:i')];
}
