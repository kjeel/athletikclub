<?php
/**
 * Athletikclub Steiermark – Kursanmeldung & Warteliste
 *
 * Eine Anmeldung gilt für eine Person: Mitglied selbst (kind_id = 0) oder ein Kind
 * des Mitglieds (kind_id = kinder.id). Ist der Kurs voll, landet die Anmeldung auf
 * der Warteliste; wird ein Platz frei, rückt automatisch die älteste Warteliste-
 * Anmeldung nach (mit Benachrichtigung).
 */

require_once ROOT_PATH . '/includes/plattform.php';

/** Anzahl fixer Plätze (angemeldet). */
function kursBelegt(PDO $db, int $kurs_id): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = ? AND status = 'angemeldet'");
    $stmt->execute([$kurs_id]);
    return (int)$stmt->fetchColumn();
}

/** Anzahl auf der Warteliste. */
function kursWarteliste(PDO $db, int $kurs_id): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = ? AND status = 'warteliste'");
    $stmt->execute([$kurs_id]);
    return (int)$stmt->fetchColumn();
}

/** Position auf der Warteliste (1 = als Nächste:r dran), 0 wenn nicht auf der Warteliste. */
function kursWartelistePosition(PDO $db, int $kurs_id, int $anmeldung_id): int
{
    $stmt = $db->prepare("SELECT id FROM kurs_anmeldungen WHERE kurs_id = ? AND status = 'warteliste' ORDER BY angemeldet_am, id");
    $stmt->execute([$kurs_id]);
    $pos = array_search($anmeldung_id, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    return $pos === false ? 0 : $pos + 1;
}

/** Alter in vollen Jahren zu einem Stichtag. */
function alterAm(?string $geburtsdatum, string $stichtag): ?int
{
    if (!$geburtsdatum) return null;
    try {
        return (new DateTime(substr($geburtsdatum, 0, 10)))->diff(new DateTime(substr($stichtag, 0, 10)))->y;
    } catch (Exception $e) {
        return null;
    }
}

/** Ist ein Kurs für neue Anmeldungen offen? Liefert Grund oder null. */
function kursAnmeldungGeschlossen(array $kurs): ?string
{
    if (in_array($kurs['status'], ['abgesagt', 'abgeschlossen'], true)) return 'Für diesen Kurs sind keine Anmeldungen mehr möglich.';
    if (!empty($kurs['anmeldeschluss']) && date('Y-m-d H:i:s') > $kurs['anmeldeschluss']) {
        return 'Der Anmeldeschluss (' . date('d.m.Y, H:i', strtotime($kurs['anmeldeschluss'])) . ') ist bereits vorbei.';
    }
    if (strtotime($kurs['end_datum']) < time()) return 'Der Kurs ist bereits vorbei.';
    return null;
}

/** Kinder eines Mitglieds (aktiv). */
function meineKinder(PDO $db, int $user_id): array
{
    try {
        $stmt = $db->prepare('SELECT * FROM kinder WHERE elternteil_id = ? AND aktiv = 1 ORDER BY vorname');
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/** Aktueller Stand einer Einwilligung (letzter Eintrag zählt). */
function einwilligungAktuell(PDO $db, string $typ, int $user_id, ?int $kind_id = null): ?array
{
    try {
        $stmt = $db->prepare('SELECT * FROM einwilligungen WHERE user_id = ? AND typ = ? AND ' . ($kind_id ? 'kind_id = ?' : 'kind_id IS NULL') . ' ORDER BY created_at DESC, id DESC LIMIT 1');
        $stmt->execute($kind_id ? [$user_id, $typ, $kind_id] : [$user_id, $typ]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Prüft, ob die Person (Mitglied oder Kind) angemeldet werden darf.
 * Liefert eine Fehlermeldung oder null.
 */
function kursAnmeldungFehler(PDO $db, array $kurs, array $user, ?array $kind, bool $voraussetzungen_bestaetigt): ?string
{
    if ($grund = kursAnmeldungGeschlossen($kurs)) return $grund;

    $name = $kind ? $kind['vorname'] : 'Du';
    if (!$kind && !array_key_exists('geburtsdatum', $user)) {
        $stmt = $db->prepare('SELECT geburtsdatum FROM mitglieder_profile WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $user['geburtsdatum'] = $stmt->fetchColumn() ?: null;
    }
    $geb  = $kind ? ($kind['geburtsdatum'] ?? null) : ($user['geburtsdatum'] ?? null);
    if ($kurs['min_alter'] !== null && $kurs['min_alter'] !== '' || $kurs['max_alter'] !== null && $kurs['max_alter'] !== '') {
        $alter = alterAm($geb, $kurs['start_datum']);
        if ($alter === null) {
            return $kind ? 'Für ' . $kind['vorname'] . ' ist kein Geburtsdatum hinterlegt (Altersgrenze des Kurses).'
                         : 'Dieser Kurs hat eine Altersgrenze – bitte ergänze dein Geburtsdatum im Profil.';
        }
        if ($kurs['min_alter'] !== null && $kurs['min_alter'] !== '' && $alter < (int)$kurs['min_alter']) {
            return $name . ($kind ? ' ist' : ' bist') . ' zu Kursbeginn ' . $alter . ' Jahre alt – Mindestalter ' . (int)$kurs['min_alter'] . '.';
        }
        if ($kurs['max_alter'] !== null && $kurs['max_alter'] !== '' && $alter > (int)$kurs['max_alter']) {
            return $name . ($kind ? ' ist' : ' bist') . ' zu Kursbeginn ' . $alter . ' Jahre alt – Höchstalter ' . (int)$kurs['max_alter'] . '.';
        }
    }
    if (trim((string)($kurs['voraussetzungen'] ?? '')) !== '' && !$voraussetzungen_bestaetigt) {
        return 'Bitte bestätige, dass die Teilnahmevoraussetzungen erfüllt sind.';
    }
    if ($kind) {
        $einw = einwilligungAktuell($db, 'teilnahme', (int)$user['id'], (int)$kind['id']);
        if (!$einw || !(int)$einw['erteilt']) {
            return 'Für ' . $kind['vorname'] . ' fehlt die Teilnahme-Einwilligung (unter „Kinder & Einwilligungen“ erteilen).';
        }
    }
    return null;
}

/** Transaktion + Sperre auf den Kurs (MySQL), damit Plätze nicht doppelt vergeben werden. */
function kursSperren(PDO $db, int $kurs_id): void
{
    if (!$db->inTransaction()) $db->beginTransaction();
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $db->prepare('SELECT id FROM kurse WHERE id = ? FOR UPDATE')->execute([$kurs_id]);
    }
}

/**
 * Anmeldung durchführen. Liefert ['status' => angemeldet|warteliste, 'id' => anmeldung_id, 'position' => n].
 * Bestehende (stornierte) Anmeldung wird wiederverwendet; Wartelisten-Reihenfolge ab jetzt.
 */
function kursAnmelden(PDO $db, array $kurs, int $user_id, int $kind_id = 0): array
{
    $kurs_id = (int)$kurs['id'];
    kursSperren($db, $kurs_id);
    try {
        $stmt = $db->prepare('SELECT * FROM kurs_anmeldungen WHERE kurs_id = ? AND user_id = ? AND kind_id = ?');
        $stmt->execute([$kurs_id, $user_id, $kind_id]);
        $bestehend = $stmt->fetch();
        if ($bestehend && in_array($bestehend['status'], ['angemeldet', 'warteliste', 'teilgenommen'], true)) {
            $db->commit();
            return ['status' => $bestehend['status'], 'id' => (int)$bestehend['id'], 'position' => kursWartelistePosition($db, $kurs_id, (int)$bestehend['id']), 'schon' => true];
        }

        // Nur wenn niemand wartet, darf direkt ein freier Platz vergeben werden
        $belegt = kursBelegt($db, $kurs_id);
        $voll   = $kurs['max_teilnehmer'] && $belegt >= (int)$kurs['max_teilnehmer'];
        $status = ($voll || kursWarteliste($db, $kurs_id) > 0) ? 'warteliste' : 'angemeldet';
        $jetzt  = date('Y-m-d H:i:s');

        if ($bestehend) {
            $db->prepare('UPDATE kurs_anmeldungen SET status = ?, angemeldet_am = ? WHERE id = ?')->execute([$status, $jetzt, $bestehend['id']]);
            $id = (int)$bestehend['id'];
        } else {
            $db->prepare('INSERT INTO kurs_anmeldungen (organization_id, kurs_id, user_id, kind_id, status, angemeldet_am) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([currentOrgId(), $kurs_id, $user_id, $kind_id, $status, $jetzt]);
            $id = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    auditLog('erstellt', 'kurs_anmeldungen', $id, null, ['kurs_id' => $kurs_id, 'user_id' => $user_id, 'kind_id' => $kind_id, 'status' => $status]);

    // Kurs gerade voll geworden → Kursleitung + Admins informieren (einmalig)
    if ($status === 'angemeldet' && $kurs['max_teilnehmer'] && $belegt + 1 >= (int)$kurs['max_teilnehmer']) {
        kursVollMelden($db, $kurs);
    }
    return ['status' => $status, 'id' => $id, 'position' => $status === 'warteliste' ? kursWartelistePosition($db, $kurs_id, $id) : 0, 'schon' => false];
}

function kursVollMelden(PDO $db, array $kurs): void
{
    $titel = 'Kurs voll: ' . $kurs['titel'];
    $text  = (int)$kurs['max_teilnehmer'] . ' von ' . (int)$kurs['max_teilnehmer'] . ' Plätzen belegt – weitere Anmeldungen kommen auf die Warteliste.';
    $link  = '/dashboard/kurs-detail.php?id=' . (int)$kurs['id'];
    $key   = 'kurs_voll_' . (int)$kurs['id'] . '_' . date('Ymd');
    if ($kurs['trainer_id']) benachrichtigen((int)$kurs['trainer_id'], 'kurs', $titel, $text, $link, $key);
    foreach (plattformAdminIds($db) as $id) {
        if ($id !== (int)$kurs['trainer_id']) benachrichtigen($id, 'kurs', $titel, $text, $link, $key);
    }
}

/**
 * Freie Plätze mit der Warteliste auffüllen (älteste zuerst). Liefert die nachgerückten Anmeldungs-IDs.
 * Wird nach Abmeldung, Storno durch die Kursleitung oder Erhöhung der Maximalzahl aufgerufen.
 */
function kursNachruecken(PDO $db, array $kurs): array
{
    if (in_array($kurs['status'], ['abgesagt', 'abgeschlossen'], true)) return [];
    $kurs_id = (int)$kurs['id'];
    $nachgerueckt = [];
    kursSperren($db, $kurs_id);
    try {
        $frei = $kurs['max_teilnehmer'] ? (int)$kurs['max_teilnehmer'] - kursBelegt($db, $kurs_id) : PHP_INT_MAX;
        if ($frei > 0) {
            $stmt = $db->prepare("SELECT ka.id, ka.user_id, ka.kind_id, ki.vorname AS kind_vorname FROM kurs_anmeldungen ka
                                  LEFT JOIN kinder ki ON ki.id = ka.kind_id
                                  WHERE ka.kurs_id = ? AND ka.status = 'warteliste' ORDER BY ka.angemeldet_am, ka.id LIMIT " . (int)min($frei, 500));
            $stmt->execute([$kurs_id]);
            foreach ($stmt->fetchAll() as $w) {
                $db->prepare("UPDATE kurs_anmeldungen SET status = 'angemeldet' WHERE id = ? AND status = 'warteliste'")->execute([$w['id']]);
                $nachgerueckt[] = $w;
            }
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    foreach ($nachgerueckt as $w) {
        auditLog('status', 'kurs_anmeldungen', (int)$w['id'], ['status' => 'warteliste'], ['status' => 'angemeldet'], 'Automatisch von der Warteliste nachgerückt');
        $wer = $w['kind_vorname'] ? $w['kind_vorname'] . ' hat' : 'Du hast';
        benachrichtigen((int)$w['user_id'], 'kurs', 'Platz frei: ' . $kurs['titel'],
            $wer . ' jetzt einen fixen Platz (von der Warteliste nachgerückt). Beginn: ' . date('d.m.Y, H:i', strtotime($kurs['start_datum'])) . '.',
            '/dashboard/kurs-detail.php?id=' . $kurs_id);
    }
    return array_map(fn($w) => (int)$w['id'], $nachgerueckt);
}

/** Anmeldung stornieren (Mitglied selbst oder Kursleitung) und Warteliste nachziehen. */
function kursStornieren(PDO $db, array $kurs, array $anmeldung): array
{
    $db->prepare("UPDATE kurs_anmeldungen SET status = 'storniert' WHERE id = ?")->execute([$anmeldung['id']]);
    auditLog('status', 'kurs_anmeldungen', (int)$anmeldung['id'], ['status' => $anmeldung['status']], ['status' => 'storniert']);
    return $anmeldung['status'] === 'angemeldet' ? kursNachruecken($db, $kurs) : [];
}

/** Anmeldungen der aktuellen Person (selbst + Kinder) für einen Kurs, Schlüssel = kind_id. */
function meineKursAnmeldungen(PDO $db, int $kurs_id, int $user_id): array
{
    $stmt = $db->prepare('SELECT * FROM kurs_anmeldungen WHERE kurs_id = ? AND user_id = ?');
    $stmt->execute([$kurs_id, $user_id]);
    $r = [];
    foreach ($stmt->fetchAll() as $a) $r[(int)$a['kind_id']] = $a;
    return $r;
}
