<?php
/**
 * Athletikclub Steiermark – Kommunikation (Kanäle, Vorlagen, Versandprotokoll)
 *
 * Kanäle: „intern“ (Glocke im Dashboard, benachrichtigungen) und „email“ (PHP mail(),
 * protokolliert in mail_log). Weitere Kanäle (z.B. SMS) werden in kanalSenden() ergänzt.
 * Vorlagen verwenden {{variable}} – reine Textersetzung, keine Code-Ausführung.
 */

require_once ROOT_PATH . '/includes/plattform.php';
require_once ROOT_PATH . '/includes/einstellungen.php';

/** Variablen, die in Vorlagen zur Verfügung stehen (für die Oberfläche). */
const VORLAGEN_VARIABLEN = [
    'vorname' => 'Vorname der empfangenden Person', 'nachname' => 'Nachname', 'person' => 'Teilnehmende Person (z.B. Kind)',
    'kurs' => 'Kurs / Event / Einheit', 'datum' => 'Datum', 'uhrzeit' => 'Uhrzeit', 'ort' => 'Ort', 'trainer' => 'Trainer:in',
    'hinweise' => 'Hinweise / Voraussetzungen', 'position' => 'Wartelistenplatz', 'link' => 'Link (Buchung, Rechnung …)',
    'rechnung' => 'Rechnungsnummer', 'rechnungsdatum' => 'Rechnungsdatum', 'betrag' => 'Betrag', 'faellig' => 'Fälligkeit',
    'zeitraum' => 'Abrechnungszeitraum', 'iban' => 'IBAN des Vereins', 'verein' => 'Vereinsname',
];
const KOMM_KANAELE = ['intern' => 'Benachrichtigung im Dashboard', 'email' => 'E-Mail'];

/** {{variable}} ersetzen; unbekannte Platzhalter werden entfernt. */
function textErsetzen(string $text, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars) {
        $v = $vars[strtolower($m[1])] ?? '';
        return is_scalar($v) ? (string)$v : '';
    }, $text);
}

function vorlageLaden(PDO $db, string $code): ?array
{
    try {
        $stmt = $db->prepare('SELECT * FROM nachricht_vorlagen WHERE organization_id = ? AND code = ? AND aktiv = 1');
        $stmt->execute([currentOrgId(), $code]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** Standard-Variablen (Verein, Bankverbindung). */
function standardVariablen(): array
{
    $v = verein();
    return ['verein' => $v['vereinsname'], 'iban' => ibanFormat($v['iban'] ?? '')];
}

function mailKonfiguriert(): bool
{
    return einstellung('mail_aktiv', '1') === '1' && filter_var(einstellung('mail_absender', MAIL_FROM), FILTER_VALIDATE_EMAIL) && function_exists('mail');
}

/**
 * E-Mail (Klartext, UTF-8) senden und protokollieren. Kopfzeilen werden gegen
 * Header-Injection bereinigt. Liefert true bei Übergabe an den Mailserver.
 */
function mailSenden(PDO $db, string $email, string $betreff, string $text, ?int $user_id = null, ?string $bezug = null): bool
{
    $email = trim($email);
    $betreff = trim(preg_replace('/[\r\n]+/', ' ', $betreff));
    $status = 'gesendet';
    $fehler = null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $status = 'fehler';
        $fehler = 'Ungültige E-Mail-Adresse';
    } elseif (einstellung('mail_aktiv', '1') !== '1') {
        $status = 'deaktiviert';
    } elseif (defined('MAIL_TESTMODUS')) {
        $fehler = 'Testmodus – nicht versendet';
    } else {
        $absender = einstellung('mail_absender', MAIL_FROM);
        $name = preg_replace('/[\r\n"<>]+/', '', einstellung('mail_absender_name', MAIL_FROM_NAME));
        $kopf = 'From: ' . mb_encode_mimeheader($name, 'UTF-8') . ' <' . $absender . ">\r\n"
              . 'Reply-To: ' . (verein()['email'] ?: $absender) . "\r\n"
              . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
        $ok = @mail($email, mb_encode_mimeheader($betreff, 'UTF-8'), str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text)), $kopf);
        if (!$ok) {
            $status = 'fehler';
            $fehler = 'mail() hat den Versand abgelehnt';
        }
    }
    try {
        $db->prepare('INSERT INTO mail_log (organization_id, user_id, email, betreff, status, fehler, bezug) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([currentOrgId(), $user_id, mb_substr($email, 0, 180), mb_substr($betreff, 0, 200), $status, $fehler, $bezug]);
    } catch (Exception $e) {}
    return $status === 'gesendet';
}

/**
 * Nachricht an ein Konto über die Kanäle der Vorlage (oder $kanal).
 * $link: interner Pfad (/dashboard/…) oder absolute URL.
 * Liefert ['intern' => bool, 'email' => bool].
 */
function nachrichtAnPerson(PDO $db, int $user_id, string $code, array $vars = [], ?string $link = null, ?string $bezug = null, ?string $schluessel = null, ?string $kanal = null): array
{
    $ergebnis = ['intern' => false, 'email' => false];
    $vorlage = vorlageLaden($db, $code);
    if (!$vorlage) return $ergebnis;
    $stmt = $db->prepare('SELECT id, vorname, nachname, email, aktiv FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();
    if (!$u) return $ergebnis;
    $abs = $link && str_starts_with($link, '/') ? APP_URL . $link : $link;
    $vars = array_merge(standardVariablen(), ['vorname' => $u['vorname'], 'nachname' => $u['nachname'], 'link' => $abs ?? ''], $vars);
    $betreff = textErsetzen($vorlage['betreff'], $vars);
    $text = textErsetzen($vorlage['text'], $vars);
    $kanal = $kanal ?? $vorlage['kanal'];
    if (in_array($kanal, ['intern', 'beide'], true)) {
        benachrichtigen($user_id, 'nachricht', mb_substr($betreff, 0, 200), mb_substr(preg_replace('/\s+/', ' ', $text), 0, 480),
                        $link && str_starts_with($link, '/dashboard/') ? $link : null, $schluessel);
        $ergebnis['intern'] = true;
    }
    if (in_array($kanal, ['email', 'beide'], true) && (int)$u['aktiv']) {
        $ergebnis['email'] = mailSenden($db, $u['email'], $betreff, $text, $user_id, $bezug);
    }
    return $ergebnis;
}

/** E-Mail an eine Adresse ohne Konto (z.B. Bewerbung), Vorlage wie oben. */
function nachrichtAnAdresse(PDO $db, string $email, string $vorname, string $code, array $vars = [], ?string $bezug = null): bool
{
    $vorlage = vorlageLaden($db, $code);
    if (!$vorlage) return false;
    $vars = array_merge(standardVariablen(), ['vorname' => $vorname], $vars);
    return mailSenden($db, $email, textErsetzen($vorlage['betreff'], $vars), textErsetzen($vorlage['text'], $vars), null, $bezug);
}

/** Variablen zu einem Kurs (Datum, Uhrzeit, Ort, Trainer:in, Hinweise). */
function kursVariablen(PDO $db, array $kurs): array
{
    $trainer = '';
    if (!empty($kurs['trainer_id'])) {
        $stmt = $db->prepare('SELECT vorname, nachname FROM users WHERE id = ?');
        $stmt->execute([$kurs['trainer_id']]);
        if ($t = $stmt->fetch()) $trainer = $t['vorname'] . ' ' . $t['nachname'];
    }
    return ['kurs' => $kurs['titel'], 'datum' => date('d.m.Y', strtotime($kurs['start_datum'])), 'uhrzeit' => date('H:i', strtotime($kurs['start_datum'])),
            'ort' => $kurs['ort'] ?? '', 'trainer' => $trainer, 'hinweise' => trim((string)($kurs['voraussetzungen'] ?? ''))];
}
