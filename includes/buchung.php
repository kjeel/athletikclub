<?php
/**
 * Athletikclub Steiermark – öffentliche Buchung (Kurse & Events)
 *
 * Personen werden NICHT doppelt gespeichert: Die E-Mail-Adresse führt zum bestehenden
 * Konto (users + mitglieder_profile); gibt es keines, wird ein Konto ohne Passwort
 * angelegt (später über „Passwort vergessen“ aktivierbar). Kinder hängen am Elternkonto.
 * Gäste müssen die Anmeldung per E-Mail-Link bestätigen – so kann niemand fremde
 * Adressen verwenden. Die Antwort an Gäste verrät nie, ob ein Konto existiert.
 */

require_once ROOT_PATH . '/includes/kursanmeldung.php';
require_once ROOT_PATH . '/includes/kommunikation.php';

const BUCHUNG_MAX_PRO_STUNDE = 10;

/** Öffentlich buchbarer Kurs/Event (nur freigegebene, nicht beendete). */
function oeffentlicherKurs(PDO $db, int $id): ?array
{
    $stmt = $db->prepare("SELECT k.*, u.vorname AS trainer_vorname, u.nachname AS trainer_nachname
                          FROM kurse k LEFT JOIN users u ON u.id = k.trainer_id
                          WHERE k.id = ? AND k.organization_id = ? AND k.oeffentlich = 1 AND k.status IN ('geplant','aktiv')");
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/** Kommende Termine (Einheiten) eines Kurses. */
function kursTermine(PDO $db, int $kurs_id, int $limit = 20): array
{
    try {
        $stmt = $db->prepare("SELECT id, start, ende, ort, status FROM einheiten WHERE kurs_id = ? AND status <> 'storniert' AND ende >= ? ORDER BY start LIMIT " . (int)$limit);
        $stmt->execute([$kurs_id, date('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function freiePlaetze(PDO $db, array $kurs): ?int
{
    return $kurs['max_teilnehmer'] ? max(0, (int)$kurs['max_teilnehmer'] - kursBelegt($db, (int)$kurs['id'])) : null;
}

/** Zu viele Anfragen von dieser IP in der letzten Stunde? */
function buchungRateLimit(PDO $db): bool
{
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM aktivitaets_log WHERE aktion = 'oeffentliche_anmeldung' AND ip_adresse = ? AND created_at > ?");
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', date('Y-m-d H:i:s', time() - 3600)]);
        return (int)$stmt->fetchColumn() >= BUCHUNG_MAX_PRO_STUNDE;
    } catch (Exception $e) {
        return false;
    }
}

/** Konto zur E-Mail finden oder (ohne Passwort) anlegen. Liefert user_id. */
function kontoFuerBuchung(PDO $db, string $email, string $vorname, string $nachname, ?string $telefon, ?string $geburtsdatum): int
{
    $stmt = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    if ($id = $stmt->fetchColumn()) {
        // Bestehende Stammdaten nicht überschreiben – nur fehlendes Profil ergänzen
        $stmt = $db->prepare('SELECT 1 FROM mitglieder_profile WHERE user_id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            $db->prepare("INSERT INTO mitglieder_profile (organization_id, user_id, geburtsdatum, telefon, mitgliedsstatus, notizen) VALUES (?, ?, ?, ?, 'aktiv', ?)")
               ->execute([currentOrgId(), $id, $geburtsdatum, $telefon, 'Profil über Online-Buchung ergänzt']);
        }
        return (int)$id;
    }
    $db->prepare("INSERT INTO users (organization_id, vorname, nachname, email, passwort_hash, rolle, email_verified, aktiv) VALUES (?, ?, ?, ?, ?, 'mitglied', 0, 1)")
       ->execute([currentOrgId(), $vorname, $nachname, strtolower($email), password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT)]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO mitglieder_profile (organization_id, user_id, geburtsdatum, telefon, mitgliedsstatus, notizen) VALUES (?, ?, ?, ?, 'aktiv', ?)")
       ->execute([currentOrgId(), $id, $geburtsdatum, $telefon, 'Konto über Online-Buchung angelegt (Kund:in)']);
    auditLog('erstellt', 'users', $id, null, ['quelle' => 'Online-Buchung'], 'Konto über Online-Buchung');
    return $id;
}

/** Kind zum Elternkonto finden (Vorname + Geburtsdatum) oder anlegen. */
function kindFuerBuchung(PDO $db, int $eltern_id, array $d): int
{
    $stmt = $db->prepare('SELECT id FROM kinder WHERE elternteil_id = ? AND LOWER(vorname) = LOWER(?) AND geburtsdatum = ? AND aktiv = 1 LIMIT 1');
    $stmt->execute([$eltern_id, $d['vorname'], $d['geburtsdatum']]);
    if ($id = $stmt->fetchColumn()) return (int)$id;
    $db->prepare('INSERT INTO kinder (organization_id, elternteil_id, vorname, nachname, geburtsdatum, notfall_name, notfall_telefon, notfall_beziehung, hinweise) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([currentOrgId(), $eltern_id, $d['vorname'], $d['nachname'], $d['geburtsdatum'], $d['notfall_name'] ?: null, $d['notfall_telefon'] ?: null, $d['notfall_name'] ? 'Notfallkontakt' : null, $d['hinweise'] ?: null]);
    $id = (int)$db->lastInsertId();
    auditLog('erstellt', 'kinder', $id, null, ['quelle' => 'Online-Buchung']);
    return $id;
}

function einwilligungErfassen(PDO $db, int $user_id, ?int $kind_id, string $typ, bool $erteilt): void
{
    $db->prepare('INSERT INTO einwilligungen (organization_id, kind_id, user_id, typ, erteilt, erfasst_von, ip_adresse) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([currentOrgId(), $kind_id, $user_id, $typ, $erteilt ? 1 : 0, getCurrentUserId(), $_SERVER['REMOTE_ADDR'] ?? null]);
}

/**
 * Anmeldung aus dem öffentlichen Formular verarbeiten.
 * Liefert ['ok' => bool, 'fehler' => [feld => text], 'status' => …, 'token' => …, 'gast' => bool, 'name' => …].
 */
function oeffentlicheAnmeldung(PDO $db, array $kurs, array $p): array
{
    $f = [];
    $t = fn($k, $max = 100) => mb_substr(trim((string)($p[$k] ?? '')), 0, $max);
    $datum = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $p[$k] ?? '') && strtotime($p[$k]) && $p[$k] <= date('Y-m-d') && $p[$k] >= '1920-01-01' ? $p[$k] : null;

    // Spam-Schutz: verstecktes Feld und Mengenbegrenzung
    if (trim((string)($p['firma_web'] ?? '')) !== '') return ['ok' => true, 'gast' => true, 'status' => 'angefragt', 'token' => null, 'name' => ''];
    if (buchungRateLimit($db)) return ['ok' => false, 'fehler' => ['allgemein' => 'Zu viele Anmeldungen in kurzer Zeit. Bitte versuche es später erneut.']];
    if ($grund = kursAnmeldungGeschlossen($kurs)) return ['ok' => false, 'fehler' => ['allgemein' => $grund]];

    $eingeloggt = isLoggedIn();
    $fuer_kind = ($p['teilnahme'] ?? 'selbst') === 'kind';
    $kind_id_vorhanden = (int)($p['kind_id'] ?? 0);

    // Kontakt / erwachsene Person
    if ($eingeloggt) {
        $u = getCurrentUser();
        $stmt = $db->prepare('SELECT geburtsdatum, telefon FROM mitglieder_profile WHERE user_id = ?');
        $stmt->execute([$u['id']]);
        $prof = $stmt->fetch() ?: [];
        $kontakt = ['vorname' => $u['vorname'], 'nachname' => $u['nachname'], 'email' => $u['email'], 'telefon' => $prof['telefon'] ?? null,
                    'geburtsdatum' => $prof['geburtsdatum'] ?? $datum('geburtsdatum')];
    } else {
        $kontakt = ['vorname' => $t('vorname', 80), 'nachname' => $t('nachname', 80), 'email' => strtolower($t('email', 180)),
                    'telefon' => $t('telefon', 30) ?: null, 'geburtsdatum' => $fuer_kind ? null : $datum('geburtsdatum')];
        if (mb_strlen($kontakt['vorname']) < 2) $f['vorname'] = 'Bitte Vornamen angeben.';
        if (mb_strlen($kontakt['nachname']) < 2) $f['nachname'] = 'Bitte Nachnamen angeben.';
        if (!filter_var($kontakt['email'], FILTER_VALIDATE_EMAIL)) $f['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        if ($kontakt['telefon'] && !preg_match('/^[0-9 +\/()-]{4,30}$/', $kontakt['telefon'])) $f['telefon'] = 'Telefonnummer ungültig.';
        if ($fuer_kind && !$kontakt['telefon']) $f['telefon'] = 'Für Kinderanmeldungen brauchen wir eine Telefonnummer für Notfälle.';
    }
    if (empty($p['datenschutz'])) $f['datenschutz'] = 'Bitte stimme der Datenverarbeitung zu.';

    // Teilnehmende Person
    $kind = null;
    if ($fuer_kind) {
        if ($eingeloggt && $kind_id_vorhanden) {
            foreach (meineKinder($db, (int)getCurrentUserId()) as $k) if ((int)$k['id'] === $kind_id_vorhanden) $kind = $k;
            if (!$kind) $f['kind_id'] = 'Kind nicht gefunden.';
        } else {
            $kind = ['id' => 0, 'vorname' => $t('kind_vorname', 100), 'nachname' => $t('kind_nachname', 100) ?: $kontakt['nachname'], 'geburtsdatum' => $datum('kind_geburtsdatum'),
                     'notfall_name' => $t('notfall_name', 150), 'notfall_telefon' => $t('notfall_telefon', 40), 'hinweise' => $t('hinweise', 1000)];
            if (mb_strlen($kind['vorname']) < 2) $f['kind_vorname'] = 'Bitte den Vornamen des Kindes angeben.';
            if (!$kind['geburtsdatum']) $f['kind_geburtsdatum'] = 'Bitte ein gültiges Geburtsdatum angeben.';
            if ($kind['notfall_telefon'] && !preg_match('/^[0-9 +\/()-]{4,40}$/', $kind['notfall_telefon'])) $f['notfall_telefon'] = 'Telefonnummer ungültig.';
        }
        if (empty($p['einw_teilnahme'])) $f['einw_teilnahme'] = 'Die Einwilligung zur Teilnahme ist erforderlich.';
    }
    if ($f) return ['ok' => false, 'fehler' => $f];

    // Teilnahmebedingungen (Alter, Voraussetzungen) vor dem Anlegen von Daten prüfen
    $pruef_user = ['id' => (int)getCurrentUserId(), 'geburtsdatum' => $kontakt['geburtsdatum']];
    if ($fehler = kursAnmeldungFehler($db, $kurs, $pruef_user, $kind, !empty($p['voraussetzungen_ok']), false)) {
        return ['ok' => false, 'fehler' => ['allgemein' => $fehler]];
    }

    $db->beginTransaction();
    try {
        $user_id = $eingeloggt ? (int)getCurrentUserId() : kontoFuerBuchung($db, $kontakt['email'], $kontakt['vorname'], $kontakt['nachname'], $kontakt['telefon'], $kontakt['geburtsdatum']);
        $kind_id = 0;
        if ($fuer_kind) {
            $kind_id = $kind['id'] ? (int)$kind['id'] : kindFuerBuchung($db, $user_id, $kind);
            einwilligungErfassen($db, $user_id, $kind_id, 'teilnahme', true);
            einwilligungErfassen($db, $user_id, $kind_id, 'datenschutz', true);
            einwilligungErfassen($db, $user_id, $kind_id, 'foto_video', !empty($p['einw_foto']));
            if (!empty($kind['notfall_telefon']) || $kontakt['telefon']) einwilligungErfassen($db, $user_id, $kind_id, 'notfallkontakt', true);
        } else {
            einwilligungErfassen($db, $user_id, null, 'datenschutz', true);
            einwilligungErfassen($db, $user_id, null, 'foto_video', !empty($p['einw_foto']));
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    // Eingeloggte (verifizierte) Konten buchen fix, Gäste bestätigen per E-Mail-Link
    $r = kursAnmelden($db, $kurs, $user_id, $kind_id, ['quelle' => 'oeffentlich', 'bestaetigt' => $eingeloggt]);
    logActivity('oeffentliche_anmeldung', 'Kurs ' . (int)$kurs['id']);
    return ['ok' => true, 'gast' => !$eingeloggt, 'status' => $r['status'], 'token' => $r['token'], 'position' => $r['position'], 'schon' => $r['schon'],
            'name' => $kind ? $kind['vorname'] : $kontakt['vorname']];
}

/** QR-Code als SVG (TCPDF-Barcode-Klasse, keine externen Dienste). */
function qrSvg(string $text, int $groesse = 5): string
{
    if (!class_exists('TCPDF2DBarcode')) {
        if (is_file(ROOT_PATH . '/vendor/autoload.php')) require_once ROOT_PATH . '/vendor/autoload.php';
        if (!class_exists('TCPDF2DBarcode') && class_exists('TCPDF')) require_once dirname((new ReflectionClass('TCPDF'))->getFileName()) . '/tcpdf_barcodes_2d.php';
    }
    if (!class_exists('TCPDF2DBarcode')) return '';
    $bc = new TCPDF2DBarcode($text, 'QRCODE,M');
    return (string)$bc->getBarcodeSVGcode($groesse, $groesse, '#1F3556');
}
