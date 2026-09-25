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
require_once ROOT_PATH . '/includes/einstellungen.php';

/** Belegte Plätze: bestätigt + angefragt (angefragte Plätze sind bis zum Ablauf reserviert). */
function kursBelegt(PDO $db, int $kurs_id): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = ? AND status IN ('angemeldet','angefragt')");
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
function kursAnmeldungFehler(PDO $db, array $kurs, array $user, ?array $kind, bool $voraussetzungen_bestaetigt, bool $einwilligung_pruefen = true): ?string
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
    if ($kind && $einwilligung_pruefen) {
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

/** Zufälliger 128-Bit-Token (Bestätigung, Selbst-Storno, QR-Check-in). */
function anmeldungToken(): string
{
    return bin2hex(random_bytes(16));
}

/** Öffentliche Links (bei fehlendem mod_rewrite funktionieren die pages/-Pfade weiterhin). */
function buchungLink(string $token): string { return APP_URL . '/buchung/' . $token; }
function kursOeffentlichLink(int $kurs_id): string { return APP_URL . '/kurse/' . $kurs_id; }
function checkinLink(string $token): string { return APP_URL . '/checkin/' . $token; }

/**
 * Anmeldung durchführen – transaktional mit Kurssperre (keine Überbuchung bei
 * gleichzeitigen Anmeldungen). Liefert ['status', 'id', 'position', 'schon', 'token'].
 *
 * $opt: quelle      'dashboard' (Standard) | 'oeffentlich'
 *       bestaetigt  true (Standard) = fixer Platz; false = „angefragt“, muss per E-Mail-Link bestätigt werden
 *       benachrichtigen  true (Standard) = Bestätigung/Warteliste per Vorlage senden
 */
function kursAnmelden(PDO $db, array $kurs, int $user_id, int $kind_id = 0, array $opt = []): array
{
    $quelle     = ($opt['quelle'] ?? 'dashboard') === 'oeffentlich' ? 'oeffentlich' : 'dashboard';
    $bestaetigt = $opt['bestaetigt'] ?? true;
    $kurs_id = (int)$kurs['id'];
    kursSperren($db, $kurs_id);
    try {
        $stmt = $db->prepare('SELECT * FROM kurs_anmeldungen WHERE kurs_id = ? AND user_id = ? AND kind_id = ?');
        $stmt->execute([$kurs_id, $user_id, $kind_id]);
        $bestehend = $stmt->fetch();
        if ($bestehend && in_array($bestehend['status'], ['angemeldet', 'angefragt', 'warteliste', 'teilgenommen'], true)) {
            $db->commit();
            return ['status' => $bestehend['status'], 'id' => (int)$bestehend['id'], 'position' => kursWartelistePosition($db, $kurs_id, (int)$bestehend['id']),
                    'schon' => true, 'token' => $bestehend['token'] ?? null];
        }

        // Nur wenn niemand wartet, darf direkt ein freier Platz vergeben werden
        $belegt = kursBelegt($db, $kurs_id);
        $voll   = $kurs['max_teilnehmer'] && $belegt >= (int)$kurs['max_teilnehmer'];
        $status = ($voll || kursWarteliste($db, $kurs_id) > 0) ? 'warteliste' : ($bestaetigt ? 'angemeldet' : 'angefragt');
        $jetzt  = date('Y-m-d H:i:s');
        $token  = ($bestehend['token'] ?? null) ?: anmeldungToken();
        $checkin = ($bestehend['checkin_code'] ?? null) ?: anmeldungToken();
        $werte  = [
            'status' => $status, 'angemeldet_am' => $jetzt, 'token' => $token, 'checkin_code' => $checkin, 'quelle' => $quelle,
            'anfrage_bis' => $status === 'angefragt' ? date('Y-m-d H:i:s', time() + 3600 * (int)einstellungAnfrageStunden()) : null,
            'bestaetigt_am' => $bestaetigt ? $jetzt : null, 'storniert_am' => null,
        ];
        if ($bestehend) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($werte)));
            $db->prepare("UPDATE kurs_anmeldungen SET $sets WHERE id = ?")->execute([...array_values($werte), $bestehend['id']]);
            $id = (int)$bestehend['id'];
        } else {
            $werte += ['organization_id' => currentOrgId(), 'kurs_id' => $kurs_id, 'user_id' => $user_id, 'kind_id' => $kind_id];
            $db->prepare('INSERT INTO kurs_anmeldungen (' . implode(', ', array_keys($werte)) . ') VALUES (' . implode(', ', array_fill(0, count($werte), '?')) . ')')
               ->execute(array_values($werte));
            $id = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    auditLog('erstellt', 'kurs_anmeldungen', $id, null, ['kurs_id' => $kurs_id, 'user_id' => $user_id, 'kind_id' => $kind_id, 'status' => $status, 'quelle' => $quelle]);

    // Kurs gerade voll geworden → Kursleitung + Admins informieren (einmalig pro Tag)
    if (in_array($status, ['angemeldet', 'angefragt'], true) && $kurs['max_teilnehmer'] && $belegt + 1 >= (int)$kurs['max_teilnehmer']) {
        kursVollMelden($db, $kurs);
    }
    $position = $status === 'warteliste' ? kursWartelistePosition($db, $kurs_id, $id) : 0;
    if ($opt['benachrichtigen'] ?? true) {
        $code = match ($status) {
            'angefragt'  => 'anmeldung_anfrage',
            'warteliste' => 'warteliste',
            default      => ($kurs['art'] ?? 'kurs') === 'event' ? 'event_anmeldung' : 'anmeldung_bestaetigt',
        };
        kursAnmeldungBenachrichtigen($db, $kurs, $id, $code, ['position' => $position]);
    }
    return ['status' => $status, 'id' => $id, 'position' => $position, 'schon' => false, 'token' => $token];
}

function einstellungAnfrageStunden(): int
{
    return function_exists('einstellung') ? max(1, (int)einstellung('anfrage_gueltig_std', '48')) : 48;
}

/** Vorlage an die buchende Person senden (Name der teilnehmenden Person, Buchungslink). */
function kursAnmeldungBenachrichtigen(PDO $db, array $kurs, int $anmeldung_id, string $code, array $extra = []): void
{
    require_once ROOT_PATH . '/includes/kommunikation.php';
    try {
        $stmt = $db->prepare('SELECT ka.*, u.vorname, u.nachname, ki.vorname AS kind_vorname, ki.nachname AS kind_nachname
                              FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id LEFT JOIN kinder ki ON ki.id = ka.kind_id WHERE ka.id = ?');
        $stmt->execute([$anmeldung_id]);
        $a = $stmt->fetch();
        if (!$a) return;
        $person = $a['kind_vorname'] ? $a['kind_vorname'] . ' ' . $a['kind_nachname'] : $a['vorname'] . ' ' . $a['nachname'];
        $vars = kursVariablen($db, $kurs) + ['person' => $person] + $extra;
        // Die Anfrage-Mail (Bestätigungslink) nur per E-Mail – intern wäre der Link sinnlos
        $link = !empty($a['token']) ? buchungLink($a['token']) : '/dashboard/kurs-detail.php?id=' . (int)$kurs['id'];
        nachrichtAnPerson($db, (int)$a['user_id'], $code, $vars, $link, 'anmeldung:' . $anmeldung_id, $code . '-' . $anmeldung_id . '-' . date('YmdHi'));
    } catch (Exception $e) {
        // Versand darf die Buchung nie verhindern
    }
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
 * Bereits bestätigte Wartende bekommen einen fixen Platz; unbestätigte öffentliche Anfragen
 * rücken als „angefragt“ nach (neue Bestätigungsfrist).
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
            $stmt = $db->prepare("SELECT ka.id, ka.user_id, ka.kind_id, ka.bestaetigt_am, ka.quelle FROM kurs_anmeldungen ka
                                  WHERE ka.kurs_id = ? AND ka.status = 'warteliste' ORDER BY ka.angemeldet_am, ka.id LIMIT " . (int)min($frei, 500));
            $stmt->execute([$kurs_id]);
            foreach ($stmt->fetchAll() as $w) {
                $fix = $w['bestaetigt_am'] !== null || $w['quelle'] !== 'oeffentlich';
                $neu = $fix ? 'angemeldet' : 'angefragt';
                $bis = $fix ? null : date('Y-m-d H:i:s', time() + 3600 * einstellungAnfrageStunden());
                $db->prepare("UPDATE kurs_anmeldungen SET status = ?, anfrage_bis = ? WHERE id = ? AND status = 'warteliste'")->execute([$neu, $bis, $w['id']]);
                $nachgerueckt[] = $w + ['neu' => $neu];
            }
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    foreach ($nachgerueckt as $w) {
        auditLog('status', 'kurs_anmeldungen', (int)$w['id'], ['status' => 'warteliste'], ['status' => $w['neu']], 'Automatisch von der Warteliste nachgerückt');
        kursAnmeldungBenachrichtigen($db, $kurs, (int)$w['id'], $w['neu'] === 'angemeldet' ? 'warteliste_platz_frei' : 'anmeldung_anfrage');
    }
    return array_map(fn($w) => (int)$w['id'], $nachgerueckt);
}

/** Öffentliche Anfrage bestätigen (Link aus der E-Mail). Liefert den neuen Status. */
function kursAnmeldungBestaetigen(PDO $db, array $kurs, array $anmeldung): string
{
    if ($anmeldung['bestaetigt_am'] !== null && $anmeldung['status'] !== 'angefragt') return $anmeldung['status'];
    $neu = $anmeldung['status'] === 'angefragt' ? 'angemeldet' : $anmeldung['status'];
    $db->prepare('UPDATE kurs_anmeldungen SET bestaetigt_am = ?, status = ?, anfrage_bis = NULL WHERE id = ?')->execute([date('Y-m-d H:i:s'), $neu, $anmeldung['id']]);
    // Mit bestätigter E-Mail-Adresse ist auch das Konto verifiziert
    $db->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$anmeldung['user_id']]);
    auditLog('status', 'kurs_anmeldungen', (int)$anmeldung['id'], ['status' => $anmeldung['status']], ['status' => $neu], 'Per E-Mail-Link bestätigt');
    if ($neu === 'angemeldet') {
        kursAnmeldungBenachrichtigen($db, $kurs, (int)$anmeldung['id'], ($kurs['art'] ?? 'kurs') === 'event' ? 'event_anmeldung' : 'anmeldung_bestaetigt');
    }
    return $neu;
}

/** Anmeldung stornieren (Mitglied, Kursleitung, Selbst-Storno, Ablauf) und Warteliste nachziehen. */
function kursStornieren(PDO $db, array $kurs, array $anmeldung, bool $benachrichtigen = false): array
{
    $db->prepare("UPDATE kurs_anmeldungen SET status = 'storniert', storniert_am = ?, anfrage_bis = NULL WHERE id = ?")->execute([date('Y-m-d H:i:s'), $anmeldung['id']]);
    auditLog('status', 'kurs_anmeldungen', (int)$anmeldung['id'], ['status' => $anmeldung['status']], ['status' => 'storniert']);
    if ($benachrichtigen) kursAnmeldungBenachrichtigen($db, $kurs, (int)$anmeldung['id'], 'storno_bestaetigt');
    return in_array($anmeldung['status'], ['angemeldet', 'angefragt'], true) ? kursNachruecken($db, $kurs) : [];
}

/** Unbestätigte öffentliche Anfragen nach Ablauf der Frist stornieren (Automatisierung). */
function kursAnfragenVerfallen(PDO $db): int
{
    $stmt = $db->prepare("SELECT ka.*, k.id AS k_id FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                          WHERE ka.organization_id = ? AND ka.status = 'angefragt' AND ka.anfrage_bis IS NOT NULL AND ka.anfrage_bis < ?");
    $stmt->execute([currentOrgId(), date('Y-m-d H:i:s')]);
    $n = 0;
    foreach ($stmt->fetchAll() as $a) {
        $kurs = kursLaden($db, (int)$a['k_id']);
        if ($kurs) { kursStornieren($db, $kurs, $a); $n++; }
    }
    return $n;
}

/** Selbst-Storno erlaubt? (bis X Stunden vor Beginn, Kurs nicht vorbei) – liefert Grund oder null. */
function kursStornoGesperrt(array $kurs, array $anmeldung): ?string
{
    if (!in_array($anmeldung['status'], ['angemeldet', 'angefragt', 'warteliste'], true)) return 'Diese Anmeldung ist nicht mehr aktiv.';
    if ($anmeldung['status'] === 'warteliste' || $anmeldung['status'] === 'angefragt') return null;
    $std = $kurs['storno_frist_std'] ?? null;
    if ($std === null || $std === '') $std = function_exists('einstellung') ? einstellung('storno_frist_std', '24') : 24;
    if (strtotime($kurs['start_datum']) - time() < (int)$std * 3600) {
        return 'Eine Stornierung ist nur bis ' . (int)$std . ' Stunden vor Kursbeginn online möglich – bitte kontaktiere uns direkt.';
    }
    return null;
}

function kursLaden(PDO $db, int $kurs_id): ?array
{
    $stmt = $db->prepare('SELECT * FROM kurse WHERE id = ? AND organization_id = ?');
    $stmt->execute([$kurs_id, currentOrgId()]);
    return $stmt->fetch() ?: null;
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

/** Status-Beschriftungen für Anmeldungen (Dashboard und öffentliche Seiten). */
const ANMELDUNG_STATUS = [
    'angefragt'        => ['label' => 'Angefragt',        'class' => 'badge-warning'],
    'angemeldet'       => ['label' => 'Bestätigt',        'class' => 'badge-success'],
    'warteliste'       => ['label' => 'Warteliste',       'class' => 'badge-info'],
    'storniert'        => ['label' => 'Storniert',        'class' => 'badge-danger'],
    'teilgenommen'     => ['label' => 'Teilgenommen',     'class' => 'badge-gray'],
    'nicht_erschienen' => ['label' => 'Nicht erschienen', 'class' => 'badge-danger'],
];
