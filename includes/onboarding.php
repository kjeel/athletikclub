<?php
/**
 * Athletikclub Steiermark – Trainer-Onboarding
 *
 * Bewerbung → Prüfung → Aufnahme → Stammdaten → Qualifikationen → Dokumente →
 * Vereinbarung → Einschulung → Freigabe → Aktiv. Checklistenpunkte mit automatischer
 * Prüfung greifen auf die vorhandenen Module zu (Konto, PRAE-Bankdaten, Qualifikationen,
 * Verträge) – es wird nichts doppelt erfasst.
 */

require_once ROOT_PATH . '/includes/kommunikation.php';

const ONBOARDING_STATUS = [
    'bewerbung'       => ['label' => 'Bewerbung',       'class' => 'badge-info'],
    'pruefung'        => ['label' => 'Prüfung',         'class' => 'badge-info'],
    'aufnahme'        => ['label' => 'Aufnahme',        'class' => 'badge-warning'],
    'stammdaten'      => ['label' => 'Stammdaten',      'class' => 'badge-warning'],
    'qualifikationen' => ['label' => 'Qualifikationen', 'class' => 'badge-warning'],
    'dokumente'       => ['label' => 'Dokumente',       'class' => 'badge-warning'],
    'vereinbarung'    => ['label' => 'Vereinbarung',    'class' => 'badge-warning'],
    'einschulung'     => ['label' => 'Einschulung',     'class' => 'badge-warning'],
    'freigabe'        => ['label' => 'Freigabe',        'class' => 'badge-navy'],
    'aktiv'           => ['label' => 'Aktiv',           'class' => 'badge-success'],
    'abgelehnt'       => ['label' => 'Abgelehnt',       'class' => 'badge-danger'],
    'zurueckgezogen'  => ['label' => 'Zurückgezogen',   'class' => 'badge-gray'],
];
const ONBOARDING_ABLAUF = ['bewerbung', 'pruefung', 'aufnahme', 'stammdaten', 'qualifikationen', 'dokumente', 'vereinbarung', 'einschulung', 'freigabe', 'aktiv'];
const ONBOARDING_AUTO = [
    'konto'         => 'Dashboard-Konto angelegt und aktiv',
    'bankdaten'     => 'IBAN in den PRAE-Empfängerdaten',
    'qualifikation' => 'mind. eine gültige Qualifikation',
    'erste_hilfe'   => 'gültiger Erste-Hilfe-Nachweis',
    'vereinbarung'  => 'aktiver Vertrag „Trainer:innen-Vereinbarung“',
    'datenschutz'   => 'Datenschutz-Einwilligung erteilt',
];

function onboardingLaden(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT o.*, b.vorname AS betreuer_vorname, b.nachname AS betreuer_nachname FROM onboarding o LEFT JOIN users b ON b.id = o.betreuer_id WHERE o.id = ? AND o.organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

/** Neuen Onboarding-Vorgang anlegen (inkl. aller aktiven Checklistenpunkte). */
function onboardingAnlegen(PDO $db, array $d, ?int $kontakt_anfrage_id = null): int
{
    $stmt = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND organization_id = ?');
    $stmt->execute([$d['email'], currentOrgId()]);
    $user_id = $stmt->fetchColumn() ?: null;
    $db->prepare('INSERT INTO onboarding (organization_id, user_id, vorname, nachname, email, telefon, schwerpunkt, nachricht, status, kontakt_anfrage_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([currentOrgId(), $user_id, $d['vorname'], $d['nachname'], strtolower($d['email']), $d['telefon'] ?? null, $d['schwerpunkt'] ?? null, $d['nachricht'] ?? null, $d['status'] ?? 'bewerbung', $kontakt_anfrage_id]);
    $id = (int)$db->lastInsertId();
    onboardingPunkteErgaenzen($db, $id);
    auditLog('erstellt', 'onboarding', $id, null, ['name' => $d['vorname'] . ' ' . $d['nachname'], 'status' => $d['status'] ?? 'bewerbung']);
    return $id;
}

/** Fehlende Checklistenpunkte (z.B. neu definierte) für einen Vorgang anlegen. */
function onboardingPunkteErgaenzen(PDO $db, int $id): void
{
    $stmt = $db->prepare('SELECT p.id FROM onboarding_punkte p WHERE p.organization_id = ? AND p.aktiv = 1 AND NOT EXISTS (SELECT 1 FROM onboarding_status s WHERE s.onboarding_id = ? AND s.punkt_id = p.id)');
    $stmt->execute([currentOrgId(), $id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $db->prepare("INSERT INTO onboarding_status (onboarding_id, punkt_id, status) VALUES (?, ?, 'offen')")->execute([$id, $pid]);
    }
}

/** Ist die automatische Bedingung für diesen Vorgang erfüllt? */
function onboardingAutoErfuellt(PDO $db, array $o, string $art): bool
{
    $uid = (int)$o['user_id'];
    if (!$uid) return false;
    $heute = date('Y-m-d');
    try {
        switch ($art) {
            case 'konto':
                $s = $db->prepare('SELECT aktiv FROM users WHERE id = ?'); $s->execute([$uid]); return (int)$s->fetchColumn() === 1;
            case 'bankdaten':
                $s = $db->prepare("SELECT 1 FROM prae_empfaenger WHERE user_id = ? AND iban IS NOT NULL AND iban <> ''"); $s->execute([$uid]); return (bool)$s->fetchColumn();
            case 'qualifikation':
                $s = $db->prepare("SELECT 1 FROM trainer_qualifikationen WHERE user_id = ? AND typ <> 'erste_hilfe' AND (gueltig_bis IS NULL OR gueltig_bis >= ?)"); $s->execute([$uid, $heute]); return (bool)$s->fetchColumn();
            case 'erste_hilfe':
                $s = $db->prepare("SELECT 1 FROM trainer_qualifikationen WHERE user_id = ? AND typ = 'erste_hilfe' AND (gueltig_bis IS NULL OR gueltig_bis >= ?)"); $s->execute([$uid, $heute]); return (bool)$s->fetchColumn();
            case 'vereinbarung':
                $s = $db->prepare("SELECT 1 FROM vertraege WHERE user_id = ? AND vertragsart = 'trainer' AND status = 'aktiv'"); $s->execute([$uid]); return (bool)$s->fetchColumn();
            case 'datenschutz':
                $s = $db->prepare("SELECT erteilt FROM einwilligungen WHERE user_id = ? AND kind_id IS NULL AND typ = 'datenschutz' ORDER BY created_at DESC, id DESC LIMIT 1"); $s->execute([$uid]); return (int)$s->fetchColumn() === 1;
        }
    } catch (Exception $e) {}
    return false;
}

/** Automatische Punkte prüfen: erfüllt → erledigt, nicht mehr erfüllt → wieder offen. Liefert Anzahl Änderungen. */
function onboardingAutoPruefen(PDO $db, array $o): int
{
    $stmt = $db->prepare('SELECT s.*, p.auto_pruefung FROM onboarding_status s JOIN onboarding_punkte p ON p.id = s.punkt_id WHERE s.onboarding_id = ? AND p.auto_pruefung IS NOT NULL');
    $stmt->execute([$o['id']]);
    $n = 0;
    foreach ($stmt->fetchAll() as $s) {
        if ($s['status'] === 'nicht_erforderlich') continue;
        $ok = onboardingAutoErfuellt($db, $o, $s['auto_pruefung']);
        if ($ok && $s['status'] === 'offen') {
            $db->prepare("UPDATE onboarding_status SET status = 'erledigt', erledigt_am = ?, erledigt_von = NULL, notiz = 'automatisch geprüft' WHERE onboarding_id = ? AND punkt_id = ?")->execute([date('Y-m-d H:i:s'), $o['id'], $s['punkt_id']]);
            $n++;
        } elseif (!$ok && $s['status'] === 'erledigt' && $s['notiz'] === 'automatisch geprüft') {
            $db->prepare("UPDATE onboarding_status SET status = 'offen', erledigt_am = NULL, notiz = 'Nachweis nicht mehr gültig' WHERE onboarding_id = ? AND punkt_id = ?")->execute([$o['id'], $s['punkt_id']]);
            $n++;
        }
    }
    return $n;
}

/** Fortschritt: ['prozent', 'erledigt', 'gesamt', 'fehlend' => [Titel …]] (nur aktive Punkte). */
function onboardingFortschritt(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT s.status, p.titel, p.pflicht FROM onboarding_status s JOIN onboarding_punkte p ON p.id = s.punkt_id WHERE s.onboarding_id = ? AND p.aktiv = 1 ORDER BY p.reihenfolge, p.id');
    $stmt->execute([$id]);
    $gesamt = $fertig = 0;
    $fehlend = [];
    foreach ($stmt->fetchAll() as $s) {
        $gesamt++;
        if ($s['status'] !== 'offen') $fertig++;
        elseif ($s['pflicht']) $fehlend[] = $s['titel'];
    }
    return ['prozent' => $gesamt ? (int)round($fertig / $gesamt * 100) : 0, 'erledigt' => $fertig, 'gesamt' => $gesamt, 'fehlend' => $fehlend];
}

/** Trainer-Konto anlegen (wie Nutzerverwaltung: Platzhalter-Passwort + Einladungslink). */
function onboardingKontoAnlegen(PDO $db, array $o): array
{
    if ($o['user_id']) return ['fehler' => 'Es gibt bereits ein Konto.'];
    $stmt = $db->prepare('SELECT id, rolle FROM users WHERE LOWER(email) = LOWER(?) AND organization_id = ?');
    $stmt->execute([$o['email'], currentOrgId()]);
    if ($u = $stmt->fetch()) {
        // Bestehendes Konto (z.B. Mitglied) übernehmen und zur Trainer:in machen
        if ($u['rolle'] === 'mitglied') $db->prepare("UPDATE users SET rolle = 'trainer' WHERE id = ?")->execute([$u['id']]);
        $uid = (int)$u['id'];
        $link = null;
    } else {
        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO users (organization_id, vorname, nachname, email, passwort_hash, rolle, email_verified, reset_token, reset_token_exp) VALUES (?, ?, ?, ?, ?, 'trainer', 0, ?, ?)")
           ->execute([currentOrgId(), $o['vorname'], $o['nachname'], strtolower($o['email']), password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), tokenHash($token), date('Y-m-d H:i:s', strtotime('+7 days'))]);
        $uid = (int)$db->lastInsertId();
        $link = APP_URL . '/auth/passwort-reset.php?token=' . $token;
    }
    try { $db->prepare('INSERT INTO trainer_profile (organization_id, user_id, erstellt_von) VALUES (?, ?, ?)')->execute([currentOrgId(), $uid, getCurrentUserId()]); } catch (Exception $e) {}
    try { $db->prepare("INSERT INTO user_roles (user_id, role_id, organization_id) SELECT ?, id, ? FROM roles WHERE code = 'TRAINER'")->execute([$uid, currentOrgId()]); } catch (Exception $e) {}
    if ($o['telefon']) {
        try { $db->prepare("INSERT INTO mitglieder_profile (organization_id, user_id, telefon, mitgliedsstatus) VALUES (?, ?, ?, 'aktiv')")->execute([currentOrgId(), $uid, $o['telefon']]); } catch (Exception $e) {}
    }
    $db->prepare('UPDATE onboarding SET user_id = ? WHERE id = ?')->execute([$uid, $o['id']]);
    auditLog('erstellt', 'users', $uid, null, ['rolle' => 'trainer', 'quelle' => 'Onboarding'], 'Trainer-Konto aus Onboarding');
    $gesendet = $link ? nachrichtAnAdresse($db, $o['email'], $o['vorname'], 'konto_einladung', ['link' => $link], 'onboarding:' . $o['id']) : false;
    return ['user_id' => $uid, 'neu' => (bool)$link, 'gesendet' => $gesendet];
}

/** Nächster Schritt im Ablauf. */
function onboardingNaechsterStatus(string $status): ?string
{
    $i = array_search($status, ONBOARDING_ABLAUF, true);
    return $i === false || $i >= count(ONBOARDING_ABLAUF) - 1 ? null : ONBOARDING_ABLAUF[$i + 1];
}
