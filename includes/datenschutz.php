<?php
/**
 * Athletikclub Steiermark – Datenschutz (DSGVO)
 *
 * Auskunft (Art. 15/20): alle zu einer Person gespeicherten Daten als JSON oder Excel.
 * Löschung (Art. 17) als Anonymisierung: Personenbezug wird entfernt, Datensätze mit
 * gesetzlicher Aufbewahrungspflicht (Rechnungen, Buchungen, Trainerabrechnungen – BAO § 132:
 * 7 Jahre) und Statistiken (Anmeldungen, Anwesenheiten) bleiben ohne Namen erhalten.
 * Geheimnisse (Passwort-Hashes, Tokens) werden nie ausgegeben.
 */

require_once ROOT_PATH . '/includes/plattform.php';

/** Spalten, die in keiner Auskunft erscheinen (Sicherheitsmerkmale, kein personenbezogener Inhalt). */
const DATENSCHUTZ_GEHEIM = ['passwort_hash', 'verify_token', 'reset_token', 'reset_token_exp', 'token', 'checkin_code', 'zugriff_token', 'feed_token', 'abo_token'];

/** Aufbewahrungsfristen (Konzept, angezeigt auf der Datenschutzseite). */
const DATENSCHUTZ_FRISTEN = [
    ['Rechnungen, Zahlungen, Buchungen, Belege', '7 Jahre ab Ende des Kalenderjahres', 'BAO § 132 – bleiben bei Anonymisierung erhalten'],
    ['Trainerabrechnungen, PRAE-Meldungen', '7 Jahre', 'BAO § 132, Nachweis gegenüber ÖGK/Finanzamt'],
    ['Förderunterlagen und Verwendungsnachweise', 'laut Fördervertrag (meist 7–10 Jahre)', 'Nachweispflicht gegenüber Fördergebern'],
    ['Einwilligungen (inkl. Widerruf)', 'solange die Verarbeitung läuft + 3 Jahre', 'Nachweis nach Art. 7 Abs. 1 DSGVO'],
    ['Mitglieds- und Kontaktdaten', 'bis Austritt/Löschwunsch', 'danach Anonymisierung'],
    ['Kursanmeldungen, Anwesenheiten', 'anonymisiert unbegrenzt (Statistik)', 'Personenbezug wird bei Löschung entfernt'],
    ['IP-Adressen in Protokollen', '90 Tage', 'danach gekürzt (Bereinigung auf dieser Seite)'],
    ['E-Mail-Protokoll', '1 Jahr', 'danach werden Empfängeradressen entfernt'],
];

/** [Bereich, Tabelle, Spalte] – Personenbezug über user_id bzw. Elternteil. */
const DATENSCHUTZ_QUELLEN = [
    ['Konto', 'users', 'id'],
    ['Profil', 'mitglieder_profile', 'user_id'],
    ['Trainerprofil', 'trainer_profile', 'user_id'],
    ['Kinder', 'kinder', 'elternteil_id'],
    ['Einwilligungen', 'einwilligungen', 'user_id'],
    ['Kursanmeldungen', 'kurs_anmeldungen', 'user_id'],
    ['Anwesenheiten', 'anwesenheiten', 'user_id'],
    ['Rechnungen', 'rechnungen', 'user_id'],
    ['Trainingspläne', 'trainingsplaene', 'mitglied_id'],
    ['Ernährungspläne', 'ernaehrungsplaene', 'mitglied_id'],
    ['Trainingsprotokoll', 'trainingsplan_protokoll', 'user_id'],
    ['Leistungsdiagnostik', 'ld_sitzungen', 'mitglied_id'],
    ['Fortschritt', 'fortschritt_eintraege', 'user_id'],
    ['Termine', 'termine', 'mitglied_id'],
    ['Persönliche Dokumente', 'dokumente', 'mitglied_id'],
    ['Qualifikationen', 'trainer_qualifikationen', 'user_id'],
    ['Einsätze', 'einheit_trainer', 'user_id'],
    ['Trainerabrechnungen', 'trainer_abrechnungen', 'user_id'],
    ['PRAE-Empfänger', 'prae_empfaenger', 'user_id'],
    ['Onboarding', 'onboarding', 'user_id'],
    ['Projektteams', 'projekt_team', 'user_id'],
    ['Rollen', 'user_roles', 'user_id'],
    ['Benachrichtigungen', 'benachrichtigungen', 'user_id'],
    ['E-Mail-Protokoll', 'mail_log', 'user_id'],
    ['Aktivitätsprotokoll', 'aktivitaets_log', 'user_id'],
];

function datenschutzPerson(PDO $db, int $user_id): ?array
{
    $stmt = $db->prepare('SELECT id, vorname, nachname, email, rolle, aktiv, created_at FROM users WHERE id = ? AND organization_id = ?');
    $stmt->execute([$user_id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/** Alle gespeicherten Daten einer Person, gruppiert nach Bereich. */
function datenAuskunft(PDO $db, int $user_id): array
{
    $daten = [];
    $kinder = [];
    foreach (DATENSCHUTZ_QUELLEN as [$bereich, $tabelle, $spalte]) {
        try {
            $stmt = $db->prepare("SELECT * FROM $tabelle WHERE $spalte = ?");
            $stmt->execute([$user_id]);
            $zeilen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            continue; // Tabelle in dieser Installation nicht vorhanden
        }
        if (!$zeilen) continue;
        $zeilen = array_map(fn($z) => array_diff_key($z, array_flip(DATENSCHUTZ_GEHEIM)), $zeilen);
        if ($tabelle === 'kinder') $kinder = array_column($zeilen, 'id');
        $daten[$bereich] = $zeilen;
    }
    // Daten der Kinder (Anmeldungen, Anwesenheiten, Einwilligungen laufen über kind_id)
    if ($kinder) {
        $ph = implode(',', array_map('intval', $kinder));
        foreach (['Anwesenheiten der Kinder' => "SELECT * FROM anwesenheiten WHERE kind_id IN ($ph)"] as $bereich => $sql) {
            try { $z = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); if ($z) $daten[$bereich] = $z; } catch (Exception $e) {}
        }
    }
    return $daten;
}

function datenAuskunftJson(array $person, array $daten): string
{
    return json_encode([
        'auskunft' => ['verantwortlich' => verein()['vereinsname'] ?? APP_NAME, 'erstellt_am' => date('c'), 'person' => $person['vorname'] . ' ' . $person['nachname'],
                       'hinweis' => 'Auskunft nach Art. 15 und Art. 20 DSGVO. Sicherheitsmerkmale (Passwort-Hash, Zugangs-Tokens) sind nicht enthalten.'],
        'daten' => $daten,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Auskunft als Berichtsstruktur (für den Excel-Export, ein Blatt je Bereich). */
function datenAuskunftBericht(array $person, array $daten): array
{
    $abschnitte = [];
    foreach ($daten as $bereich => $zeilen) {
        $spalten = array_map(fn($k) => ['titel' => $k, 'breite' => 18], array_keys($zeilen[0]));
        $abschnitte[] = ['titel' => $bereich, 'spalten' => $spalten, 'zeilen' => array_map('array_values', $zeilen)];
    }
    return ['titel' => 'Datenauskunft', 'untertitel' => $person['vorname'] . ' ' . $person['nachname'], 'dateiname' => 'datenauskunft-' . $person['id'], 'abschnitte' => $abschnitte ?: [['titel' => 'Keine Daten', 'spalten' => [['titel' => 'Hinweis']], 'zeilen' => [['Keine Daten gespeichert.']]]]];
}

/** Gründe, die eine Anonymisierung verhindern (leer = möglich). */
function anonymisierungHindernisse(PDO $db, array $person): array
{
    $h = [];
    if ((int)$person['id'] === (int)getCurrentUserId()) $h[] = 'Das eigene Konto kann nicht anonymisiert werden.';
    if ($person['rolle'] === 'admin') $h[] = 'Administrator-Konten müssen zuerst herabgestuft werden.';
    if (str_ends_with((string)$person['email'], '@anonymisiert.invalid')) $h[] = 'Diese Person ist bereits anonymisiert.';
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM trainer_abrechnungen WHERE user_id = ? AND status IN ('eingereicht','geprueft','freigegeben')");
        $stmt->execute([$person['id']]);
        if ($stmt->fetchColumn()) $h[] = 'Es gibt noch nicht ausbezahlte Trainerabrechnungen.';
    } catch (Exception $e) {}
    return $h;
}

/** Was bei der Anonymisierung erhalten bleibt (Hinweis vor der Bestätigung). */
function anonymisierungUmfang(PDO $db, int $user_id): array
{
    $n = function (string $sql) use ($db, $user_id) {
        try { $s = $db->prepare($sql); $s->execute([$user_id]); return (int)$s->fetchColumn(); } catch (Exception $e) { return 0; }
    };
    return [
        'rechnungen' => $n('SELECT COUNT(*) FROM rechnungen WHERE user_id = ?'),
        'abrechnungen' => $n('SELECT COUNT(*) FROM trainer_abrechnungen WHERE user_id = ?'),
        'anmeldungen' => $n('SELECT COUNT(*) FROM kurs_anmeldungen WHERE user_id = ?'),
        'kinder' => $n('SELECT COUNT(*) FROM kinder WHERE elternteil_id = ?'),
    ];
}

/**
 * Person anonymisieren (unumkehrbar). Rechnungen bleiben wegen der Aufbewahrungspflicht
 * unverändert; alle übrigen Personendaten werden entfernt bzw. neutralisiert.
 * Liefert die Anzahl geänderter Datensätze je Bereich.
 */
function personAnonymisieren(PDO $db, int $user_id): array
{
    $erg = [];
    $lauf = function (string $bereich, string $sql, array $p) use ($db, &$erg) {
        try { $s = $db->prepare($sql); $s->execute($p); $erg[$bereich] = ($erg[$bereich] ?? 0) + $s->rowCount(); } catch (Exception $e) {}
    };
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id FROM kinder WHERE elternteil_id = ?');
        $stmt->execute([$user_id]);
        $kinder = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $db->prepare("UPDATE users SET vorname = 'Gelöschte', nachname = ?, email = ?, passwort_hash = ?, verify_token = NULL, reset_token = NULL, reset_token_exp = NULL, aktiv = 0, email_verified = 0 WHERE id = ?")
           ->execute(['Person #' . $user_id, 'geloescht-' . $user_id . '@anonymisiert.invalid', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $user_id]);
        $erg['Konto'] = 1;
        $lauf('Profil', 'UPDATE mitglieder_profile SET geburtsdatum = NULL, telefon = NULL, strasse = NULL, plz = NULL, ort = NULL, sportarten = NULL, notizen = NULL WHERE user_id = ?', [$user_id]);
        $lauf('Trainerprofil', 'UPDATE trainer_profile SET qualifikationen = NULL, bio = NULL, foto_path = NULL, lizenz_nr = NULL WHERE user_id = ?', [$user_id]);
        foreach ($kinder as $k) {
            $lauf('Kinder', "UPDATE kinder SET vorname = 'Kind', nachname = ?, geburtsdatum = NULL, telefon = NULL, notfall_name = NULL, notfall_telefon = NULL, notfall_beziehung = NULL, hinweise = NULL, aktiv = 0 WHERE id = ?", ['#' . $k, $k]);
        }
        $lauf('Einwilligungen (IP entfernt)', 'UPDATE einwilligungen SET ip_adresse = NULL WHERE user_id = ?', [$user_id]);
        $lauf('Kursanmeldungen (Notizen)', 'UPDATE kurs_anmeldungen SET notiz = NULL WHERE user_id = ?', [$user_id]);
        $lauf('Onboarding', "UPDATE onboarding SET vorname = 'Gelöschte', nachname = 'Person', email = ?, telefon = NULL, nachricht = NULL, notiz = NULL WHERE user_id = ?", ['geloescht-' . $user_id . '@anonymisiert.invalid', $user_id]);
        $lauf('PRAE-Empfänger', "UPDATE prae_empfaenger SET email = NULL WHERE user_id = ?", [$user_id]);
        $lauf('E-Mail-Protokoll', "UPDATE mail_log SET email = 'anonymisiert' WHERE user_id = ?", [$user_id]);
        $lauf('Protokolle (IP entfernt)', 'UPDATE aktivitaets_log SET ip_adresse = NULL WHERE user_id = ?', [$user_id]);
        $lauf('Protokolle (IP entfernt)', 'UPDATE audit_log SET ip_adresse = NULL WHERE user_id = ?', [$user_id]);
        // Alte Werte im Änderungsprotokoll können Personendaten enthalten – Eintrag bleibt, Inhalte werden entfernt
        $lauf('Änderungsprotokoll (Inhalte entfernt)', "UPDATE audit_log SET alt = NULL, neu = NULL WHERE tabelle IN ('users','mitglieder_profile') AND datensatz_id = ?", [$user_id]);
        foreach ($kinder as $k) $lauf('Änderungsprotokoll (Inhalte entfernt)', "UPDATE audit_log SET alt = NULL, neu = NULL WHERE tabelle = 'kinder' AND datensatz_id = ?", [$k]);
        $lauf('Benachrichtigungen', 'DELETE FROM benachrichtigungen WHERE user_id = ?', [$user_id]);
        $lauf('Kalender-Abos', 'DELETE FROM kalender_abos WHERE user_id = ?', [$user_id]);
        $lauf('Rollen', 'DELETE FROM user_roles WHERE user_id = ?', [$user_id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return array_filter($erg);
}

/** Bereinigung nach Aufbewahrungsfristen: IP-Adressen > 90 Tage, E-Mail-Adressen im Mail-Protokoll > 1 Jahr. */
function datenschutzBereinigung(PDO $db, bool $ausfuehren): array
{
    $grenzeIp = date('Y-m-d H:i:s', strtotime('-90 days'));
    $grenzeMail = date('Y-m-d H:i:s', strtotime('-1 year'));
    $org = currentOrgId();
    $schritte = [
        'IP-Adressen im Aktivitätsprotokoll' => ['aktivitaets_log', 'ip_adresse = NULL', 'ip_adresse IS NOT NULL AND created_at < ?', [$grenzeIp]],
        'IP-Adressen im Änderungsprotokoll' => ['audit_log', 'ip_adresse = NULL', 'ip_adresse IS NOT NULL AND created_at < ?', [$grenzeIp]],
        'Empfängeradressen im E-Mail-Protokoll' => ['mail_log', "email = 'entfernt'", "email <> 'entfernt' AND email <> 'anonymisiert' AND created_at < ?", [$grenzeMail]],
    ];
    $erg = [];
    foreach ($schritte as $label => [$tabelle, $set, $where, $p]) {
        try {
            $orgFilter = in_array($tabelle, ['aktivitaets_log', 'mail_log', 'audit_log'], true) ? ' AND organization_id = ?' : '';
            $params = $orgFilter ? array_merge($p, [$org]) : $p;
            if ($ausfuehren) {
                $s = $db->prepare("UPDATE $tabelle SET $set WHERE $where$orgFilter");
                $s->execute($params);
                $erg[$label] = $s->rowCount();
            } else {
                $s = $db->prepare("SELECT COUNT(*) FROM $tabelle WHERE $where$orgFilter");
                $s->execute($params);
                $erg[$label] = (int)$s->fetchColumn();
            }
        } catch (Exception $e) {
            $erg[$label] = null;
        }
    }
    return $erg;
}
