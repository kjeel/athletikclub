<?php
/**
 * Athletikclub Steiermark – Gemeinsame Grundlagen der Vereinsplattform
 *
 * - darf()/requireDarf(): Berechtigungen je Modul und Aktion (RBAC). Admins
 *   (users.rolle = admin) behalten wie bisher Vollzugriff; alle anderen
 *   erhalten Rechte über ihre Rollen in user_roles. Eigene Daten (z.B. eigene
 *   Einheiten, eigene Aufgaben) regelt die jeweilige Seite zusätzlich.
 * - auditLog(): Änderungsprotokoll mit altem und neuem Wert
 * - benachrichtigen(): interne Benachrichtigungen (Glocke im Header)
 * - plattformFaelligkeiten(): tägliche Erinnerungen (Fristen, Abläufe)
 * - plattformPdfUpload(): sicherer PDF-Upload mit Inhaltsprüfung
 */

require_once ROOT_PATH . '/includes/money.php';

// ----------------------------------------------------------------
// Bezeichnungen
// ----------------------------------------------------------------
const EINHEIT_TYPEN = [
    'kurs'         => ['label' => 'Kurs',            'farbe' => '#3B82F6'],
    'training'     => ['label' => 'Training',        'farbe' => '#22C55E'],
    'kindergarten' => ['label' => 'Kindergarten',    'farbe' => '#F59E0B'],
    'schule'       => ['label' => 'Schule',          'farbe' => '#8B5CF6'],
    'gemeinde'     => ['label' => 'Gemeindeprojekt', 'farbe' => '#14B8A6'],
    'event'        => ['label' => 'Event',           'farbe' => '#EF4444'],
    'meeting'      => ['label' => 'Meeting',         'farbe' => '#64748B'],
    'sonstiges'    => ['label' => 'Sonstiges',       'farbe' => '#A3A3A3'],
];
const EINHEIT_STATUS = [
    'geplant'       => ['label' => 'Geplant',       'class' => 'badge-info'],
    'durchgefuehrt' => ['label' => 'Durchgeführt',  'class' => 'badge-success'],
    'storniert'     => ['label' => 'Storniert',     'class' => 'badge-danger'],
];
const ET_STATUS = [
    'geplant'        => ['label' => 'Geplant',          'class' => 'badge-gray'],
    'durchgefuehrt'  => ['label' => 'Durchgeführt',     'class' => 'badge-info'],
    'storniert'      => ['label' => 'Storniert',        'class' => 'badge-danger'],
    'zur_abrechnung' => ['label' => 'Zur Abrechnung',   'class' => 'badge-warning'],
    'abgerechnet'    => ['label' => 'Abgerechnet',      'class' => 'badge-navy'],
    'bezahlt'        => ['label' => 'Bezahlt',          'class' => 'badge-success'],
];
const TA_STATUS = [
    'entwurf'     => ['label' => 'Entwurf',     'class' => 'badge-gray'],
    'eingereicht' => ['label' => 'Eingereicht', 'class' => 'badge-info'],
    'geprueft'    => ['label' => 'Geprüft',     'class' => 'badge-warning'],
    'freigegeben' => ['label' => 'Freigegeben', 'class' => 'badge-navy'],
    'bezahlt'     => ['label' => 'Bezahlt',     'class' => 'badge-success'],
];
const ANWESENHEIT_STATUS = [
    'anwesend'      => ['label' => 'anwesend',      'kurz' => 'A', 'class' => 'badge-success'],
    'abwesend'      => ['label' => 'abwesend',      'kurz' => 'F', 'class' => 'badge-danger'],
    'entschuldigt'  => ['label' => 'entschuldigt',  'kurz' => 'E', 'class' => 'badge-warning'],
    'probetraining' => ['label' => 'Probetraining', 'kurz' => 'P', 'class' => 'badge-info'],
];
const PROJEKT_STATUS = [
    'planung'       => ['label' => 'Planung',       'class' => 'badge-gray'],
    'aktiv'         => ['label' => 'Aktiv',         'class' => 'badge-success'],
    'pausiert'      => ['label' => 'Pausiert',      'class' => 'badge-warning'],
    'abgeschlossen' => ['label' => 'Abgeschlossen', 'class' => 'badge-navy'],
    'archiviert'    => ['label' => 'Archiviert',    'class' => 'badge-gray'],
];
const PROJEKT_KATEGORIEN = [
    'kindergarten' => 'Kindergarten', 'schule' => 'Schule / TBE', 'gemeinde' => 'Gemeindekooperation', 'kurs' => 'Kursangebot',
    'skate' => 'Skateboard / Skatepark', 'veranstaltung' => 'Veranstaltung', 'infrastruktur' => 'Infrastruktur', 'sonstiges' => 'Sonstiges',
];
const AUFGABE_PRIO = [
    'niedrig'  => ['label' => 'Niedrig',  'class' => 'badge-gray',    'rang' => 1],
    'normal'   => ['label' => 'Normal',   'class' => 'badge-info',    'rang' => 2],
    'hoch'     => ['label' => 'Hoch',     'class' => 'badge-warning', 'rang' => 3],
    'kritisch' => ['label' => 'Kritisch', 'class' => 'badge-danger',  'rang' => 4],
];
const AUFGABE_STATUS = [
    'offen'          => ['label' => 'Offen',          'class' => 'badge-gray'],
    'in_bearbeitung' => ['label' => 'In Bearbeitung', 'class' => 'badge-info'],
    'wartet'         => ['label' => 'Wartet',         'class' => 'badge-warning'],
    'erledigt'       => ['label' => 'Erledigt',       'class' => 'badge-success'],
];
const QUAL_TYPEN = [
    'uebungsleiter' => 'Übungsleiter:in', 'jugendleiter' => 'Jugendleiter:in', 'instruktor' => 'Instruktor:in', 'trainer' => 'Trainer:in-Ausbildung',
    'erste_hilfe' => 'Erste Hilfe', 'skateboard' => 'Skateboard-Ausbildung', 'kinderschutz' => 'Kinderschutz', 'strafregister' => 'Strafregisterbescheinigung (Kinder/Jugend)',
    'sonstiges' => 'Sonstiges Zertifikat',
];
const QUAL_WARNTAGE = 60;
const PARTNER_KATEGORIEN = [
    'gemeinde' => 'Gemeinde', 'schule' => 'Schule', 'kindergarten' => 'Kindergarten', 'unternehmen' => 'Unternehmen',
    'sponsor' => 'Sponsor', 'verband' => 'Verband', 'sonstiges' => 'Sonstiges',
];
const RESSOURCE_KATEGORIEN = [
    'skateboard' => 'Skateboards', 'schutz' => 'Helme & Protektoren', 'material' => 'Sportmaterial', 'halle' => 'Halle',
    'platz' => 'Trainingsplatz', 'skatepark' => 'Skatepark', 'fahrzeug' => 'Fahrzeug', 'sonstiges' => 'Sonstige Ausrüstung',
];
const RESSOURCE_ZUSTAND = ['neu' => 'neu', 'gut' => 'gut', 'gebraucht' => 'gebraucht', 'reparatur' => 'in Reparatur', 'defekt' => 'defekt'];
const VERTRAG_ARTEN = [
    'kooperation' => 'Kooperationsvereinbarung', 'miete' => 'Miete / Hallennutzung', 'sponsoring' => 'Sponsoring', 'trainer' => 'Trainer:innen-Vereinbarung',
    'dienstleistung' => 'Dienstleistung', 'versicherung' => 'Versicherung', 'leasing' => 'Leasing / Kauf', 'sonstiges' => 'Sonstiges',
];
const VERTRAG_STATUS = [
    'entwurf'    => ['label' => 'Entwurf',   'class' => 'badge-gray'],
    'aktiv'      => ['label' => 'Aktiv',     'class' => 'badge-success'],
    'gekuendigt' => ['label' => 'Gekündigt', 'class' => 'badge-warning'],
    'beendet'    => ['label' => 'Beendet',   'class' => 'badge-navy'],
];
const BUCHUNG_KATEGORIEN = [
    'trainerhonorar' => 'Trainerhonorar', 'material' => 'Material / Ausrüstung', 'miete' => 'Miete / Hallen', 'fahrtkosten' => 'Fahrtkosten',
    'verpflegung' => 'Verpflegung', 'werbung' => 'Werbung / Druck', 'versicherung' => 'Versicherung', 'kursbeitrag' => 'Kursbeiträge',
    'foerderung' => 'Fördergelder', 'sponsoring' => 'Sponsoring', 'spende' => 'Spenden', 'mitgliedsbeitrag' => 'Mitgliedsbeiträge', 'sonstiges' => 'Sonstiges',
];
/**
 * Förder-Status in Ablaufreihenfolge. Die neuen Stufen (Vorbereitung … Abgerechnet) ergänzen
 * die bisherigen Werte (geplant, beantragt, ausbezahlt), die für Altbestände erhalten bleiben.
 */
const FOERDER_STATUS = [
    'geplant'       => ['label' => 'Geplant / Idee', 'class' => 'badge-gray'],
    'vorbereitung'  => ['label' => 'Vorbereitung',   'class' => 'badge-gray'],
    'eingereicht'   => ['label' => 'Eingereicht',    'class' => 'badge-info'],
    'beantragt'     => ['label' => 'Beantragt',      'class' => 'badge-info'],
    'in_pruefung'   => ['label' => 'In Prüfung',     'class' => 'badge-info'],
    'bewilligt'     => ['label' => 'Bewilligt',      'class' => 'badge-success'],
    'abgelehnt'     => ['label' => 'Abgelehnt',      'class' => 'badge-danger'],
    'in_umsetzung'  => ['label' => 'In Umsetzung',   'class' => 'badge-success'],
    'ausbezahlt'    => ['label' => 'Ausbezahlt',     'class' => 'badge-gold'],
    'abgerechnet'   => ['label' => 'Abgerechnet',    'class' => 'badge-gold'],
    'abgeschlossen' => ['label' => 'Abgeschlossen',  'class' => 'badge-navy'],
];
/** Status, in denen Fördergeld zugesagt ist (für Budget-/Finanz-KPIs). */
const FOERDER_ZUGESAGT = ['bewilligt', 'in_umsetzung', 'ausbezahlt', 'abgerechnet', 'abgeschlossen'];
/** Status „läuft noch / in Arbeit“ (Antragsphase). */
const FOERDER_OFFEN = ['geplant', 'vorbereitung', 'eingereicht', 'beantragt', 'in_pruefung'];
const EINWILLIGUNG_TYPEN = [
    'teilnahme'      => 'Teilnahme an den Vereinsangeboten',
    'datenschutz'    => 'Verarbeitung der Daten laut Datenschutzerklärung',
    'foto_video'     => 'Foto- und Videoaufnahmen für Vereinszwecke (Website, Social Media)',
    'notfallkontakt' => 'Kontaktaufnahme mit dem Notfallkontakt und Weitergabe der Hinweise an Trainer:innen',
];

// ----------------------------------------------------------------
// Berechtigungen
// ----------------------------------------------------------------

/** Berechtigungscodes der aktuellen Person (aus allen Rollen), gecacht je Anfrage. */
function meineBerechtigungen(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!isLoggedIn()) return $cache;
    try {
        $stmt = getDB()->prepare('SELECT DISTINCT p.code FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id
                                  JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? AND ur.organization_id = ?');
        $stmt->execute([getCurrentUserId(), currentOrgId()]);
        $cache = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}

/** Darf die aktuelle Person diese Aktion (z.B. „projekte.bearbeiten“)? Admins immer. */
function darf(string $recht): bool
{
    if (!isLoggedIn()) return false;
    if (isAdmin()) return true;
    return isset(meineBerechtigungen()[$recht]);
}

/** Mindestens eines der Rechte? */
function darfEines(string ...$rechte): bool
{
    foreach ($rechte as $r) if (darf($r)) return true;
    return false;
}

/** Serverseitige Prüfung – leitet ohne Berechtigung auf das Dashboard um. */
function requireDarf(string ...$rechte): void
{
    requireLogin();
    if (!darfEines(...$rechte)) {
        header('Location: ' . APP_URL . '/dashboard/index.php?error=no_permission');
        exit;
    }
}

// ----------------------------------------------------------------
// Audit-Log
// ----------------------------------------------------------------

/**
 * Protokolliert eine Änderung. Bei „geaendert“ werden nur Felder gespeichert,
 * deren Wert sich tatsächlich unterscheidet.
 */
function auditLog(string $aktion, string $tabelle, ?int $datensatz_id, ?array $alt = null, ?array $neu = null, ?string $beschreibung = null): void
{
    if ($alt !== null && $neu !== null) {
        $a = $n = [];
        foreach ($neu as $k => $v) {
            if (in_array($k, ['updated_at', 'created_at'], true)) continue;
            $vorher = $alt[$k] ?? null;
            if ((string)$vorher !== (string)$v) { $a[$k] = $vorher; $n[$k] = $v; }
        }
        if (!$a && !$n && $aktion === 'geaendert') return;
        [$alt, $neu] = [$a, $n];
    }
    try {
        getDB()->prepare('INSERT INTO audit_log (organization_id, user_id, aktion, tabelle, datensatz_id, beschreibung, alt, neu, ip_adresse) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([currentOrgId(), getCurrentUserId(), $aktion, $tabelle, $datensatz_id, $beschreibung ? mb_substr($beschreibung, 0, 255) : null,
                       $alt !== null ? json_encode($alt, JSON_UNESCAPED_UNICODE) : null, $neu !== null ? json_encode($neu, JSON_UNESCAPED_UNICODE) : null,
                       $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
        // Audit darf den eigentlichen Vorgang nicht verhindern (z.B. Migration noch nicht eingespielt)
    }
}

// ----------------------------------------------------------------
// Benachrichtigungen
// ----------------------------------------------------------------

/** Benachrichtigung anlegen; mit Schlüssel nur einmal je Person. */
function benachrichtigen(int $user_id, string $typ, string $titel, ?string $text = null, ?string $link = null, ?string $schluessel = null): void
{
    if ($user_id <= 0) return;
    try {
        $db = getDB();
        if ($schluessel !== null) {
            $stmt = $db->prepare('SELECT 1 FROM benachrichtigungen WHERE user_id = ? AND schluessel = ?');
            $stmt->execute([$user_id, $schluessel]);
            if ($stmt->fetchColumn()) return;
        }
        $db->prepare('INSERT INTO benachrichtigungen (organization_id, user_id, typ, titel, text, link, schluessel) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([currentOrgId(), $user_id, $typ, mb_substr($titel, 0, 200), $text !== null ? mb_substr($text, 0, 500) : null, $link, $schluessel]);
    } catch (Exception $e) {}
}

/** IDs aller aktiven Admins (Empfänger:innen für Verwaltungs-Hinweise). */
function plattformAdminIds(PDO $db): array
{
    $stmt = $db->prepare("SELECT id FROM users WHERE organization_id = ? AND rolle = 'admin' AND aktiv = 1");
    $stmt->execute([currentOrgId()]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function benachrichtigeAdmins(PDO $db, string $typ, string $titel, ?string $text = null, ?string $link = null, ?string $schluessel = null): void
{
    foreach (plattformAdminIds($db) as $id) benachrichtigen($id, $typ, $titel, $text, $link, $schluessel);
}

function ungeleseneBenachrichtigungen(PDO $db, int $user_id): int
{
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM benachrichtigungen WHERE user_id = ? AND gelesen_am IS NULL');
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ----------------------------------------------------------------
// Systemstatus & tägliche Fälligkeitsprüfung (ohne Cronjob)
// ----------------------------------------------------------------

function systemStatus(PDO $db, string $schluessel): ?string
{
    try {
        $stmt = $db->prepare('SELECT wert FROM system_status WHERE schluessel = ?');
        $stmt->execute([$schluessel]);
        $w = $stmt->fetchColumn();
        return $w === false ? null : $w;
    } catch (Exception $e) {
        return null;
    }
}

function systemStatusSetzen(PDO $db, string $schluessel, string $wert): void
{
    try {
        $db->prepare('DELETE FROM system_status WHERE schluessel = ?')->execute([$schluessel]);
        $db->prepare('INSERT INTO system_status (schluessel, wert) VALUES (?, ?)')->execute([$schluessel, $wert]);
    } catch (Exception $e) {}
}

/**
 * Erzeugt einmal täglich Erinnerungen: ablaufende Qualifikationen, Verträge,
 * Förderfristen, fällige Aufgaben und unbestätigte Einheiten.
 * Schlüssel verhindern Doppelungen.
 */
function plattformFaelligkeiten(PDO $db): void
{
    $heute = date('Y-m-d');
    $key = 'faelligkeiten_org_' . currentOrgId();
    if (systemStatus($db, $key) === $heute) return;
    systemStatusSetzen($db, $key, $heute);
    $org = currentOrgId();
    try {
        // Qualifikationen
        $stmt = $db->prepare("SELECT q.*, u.vorname, u.nachname FROM trainer_qualifikationen q JOIN users u ON u.id = q.user_id
                              WHERE q.organization_id = ? AND q.gueltig_bis IS NOT NULL AND q.gueltig_bis <= ?");
        $stmt->execute([$org, date('Y-m-d', strtotime('+' . QUAL_WARNTAGE . ' days'))]);
        foreach ($stmt->fetchAll() as $q) {
            $tage = (int)round((strtotime($q['gueltig_bis']) - strtotime($heute)) / 86400);
            $titel = $tage < 0 ? "{$q['bezeichnung']} ist abgelaufen" : "{$q['bezeichnung']} läuft in {$tage} Tagen ab";
            $link = '/dashboard/qualifikationen.php' . '?user=' . $q['user_id'];
            benachrichtigen((int)$q['user_id'], 'qualifikation', $titel, 'Bitte einen neuen Nachweis hochladen.', $link, "qual-{$q['id']}-" . ($tage < 0 ? 'ab' : ($tage <= 21 ? '21' : '60')));
            benachrichtigeAdmins($db, 'qualifikation', "{$q['vorname']} {$q['nachname']}: {$titel}", null, $link, "qual-{$q['id']}-" . ($tage < 0 ? 'ab' : ($tage <= 21 ? '21' : '60')));
        }
        // Verträge (Kündigungsfrist bzw. Vertragsende innerhalb von 45 Tagen)
        $stmt = $db->prepare("SELECT * FROM vertraege WHERE organization_id = ? AND status = 'aktiv' AND
                              ((kuendigung_bis IS NOT NULL AND kuendigung_bis BETWEEN ? AND ?) OR (ende IS NOT NULL AND ende BETWEEN ? AND ?))");
        $grenze = date('Y-m-d', strtotime('+45 days'));
        $stmt->execute([$org, $heute, $grenze, $heute, $grenze]);
        foreach ($stmt->fetchAll() as $v) {
            $frist = $v['kuendigung_bis'] && $v['kuendigung_bis'] <= $grenze ? 'Kündigungsfrist bis ' . date('d.m.Y', strtotime($v['kuendigung_bis'])) : 'Vertragsende ' . date('d.m.Y', strtotime($v['ende']));
            benachrichtigeAdmins($db, 'vertrag', "Vertrag „{$v['titel']}“: {$frist}", null, '/dashboard/admin/vertraege.php?id=' . $v['id'], "vertrag-{$v['id']}-" . ($v['kuendigung_bis'] ?? $v['ende']));
        }
        // Partner-Wiedervorlage (heute fällig oder überfällig) → Betreuer:in, sonst Admins
        $stmt = $db->prepare("SELECT id, name, naechster_kontakt, verantwortlich_id FROM partner_organisationen
                              WHERE organization_id = ? AND status <> 'inaktiv' AND naechster_kontakt IS NOT NULL AND naechster_kontakt <= ?");
        $stmt->execute([$org, $heute]);
        foreach ($stmt->fetchAll() as $p) {
            $titel = "Wiedervorlage: {$p['name']} kontaktieren";
            $text  = 'Geplant für ' . date('d.m.Y', strtotime($p['naechster_kontakt']));
            $link  = '/dashboard/admin/partner.php?id=' . $p['id'];
            $key   = "partner-{$p['id']}-{$p['naechster_kontakt']}";
            if ($p['verantwortlich_id']) benachrichtigen((int)$p['verantwortlich_id'], 'projekt', $titel, $text, $link, $key);
            else benachrichtigeAdmins($db, 'projekt', $titel, $text, $link, $key);
        }
        // Förderfristen (14 Tage)
        $stmt = $db->prepare("SELECT * FROM foerderungen WHERE organization_id = ? AND status NOT IN ('abgelehnt','abgeschlossen','abgerechnet')");
        $stmt->execute([$org]);
        foreach ($stmt->fetchAll() as $f) {
            foreach (['einreichfrist' => 'Einreichfrist', 'nachweisfrist' => 'Nachweisfrist', 'abrechnungsfrist' => 'Abrechnungsfrist'] as $feld => $label) {
                if (empty($f[$feld]) || $f[$feld] < $heute || $f[$feld] > date('Y-m-d', strtotime('+14 days'))) continue;
                if ($feld === 'einreichfrist' && !in_array($f['status'], ['geplant', 'vorbereitung'], true)) continue;
                $tage = (int)round((strtotime($f[$feld]) - strtotime($heute)) / 86400);
                benachrichtigeAdmins($db, 'foerderung', "{$label} „{$f['titel']}“ in {$tage} Tagen", 'Frist: ' . date('d.m.Y', strtotime($f[$feld])),
                                     '/dashboard/admin/foerderung-detail.php?id=' . $f['id'], "foerd-{$f['id']}-{$feld}-" . ($tage <= 5 ? '5' : '14'));
            }
        }
        // Aufgaben: Deadline morgen oder überfällig
        $stmt = $db->prepare("SELECT * FROM aufgaben WHERE organization_id = ? AND status <> 'erledigt' AND verantwortlich_id IS NOT NULL AND deadline IS NOT NULL AND deadline <= ?");
        $stmt->execute([$org, date('Y-m-d', strtotime('+1 day'))]);
        foreach ($stmt->fetchAll() as $a) {
            $ueber = $a['deadline'] < $heute;
            benachrichtigen((int)$a['verantwortlich_id'], 'aufgabe', ($ueber ? 'Überfällig: ' : 'Fällig morgen: ') . $a['titel'], 'Deadline ' . date('d.m.Y', strtotime($a['deadline'])),
                            '/dashboard/aufgaben.php?id=' . $a['id'], "aufg-{$a['id']}-" . ($ueber ? 'ueber' : 'morgen'));
        }
        // Einheiten: seit mehr als 2 Tagen vorbei, aber nicht bestätigt
        $stmt = $db->prepare("SELECT et.user_id, COUNT(*) AS n FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                              WHERE e.organization_id = ? AND e.status <> 'storniert' AND et.status = 'geplant' AND e.ende < ? GROUP BY et.user_id");
        $stmt->execute([$org, date('Y-m-d H:i:s', strtotime('-2 days'))]);
        foreach ($stmt->fetchAll() as $r) {
            benachrichtigen((int)$r['user_id'], 'einheit', "{$r['n']} Einheit(en) noch nicht bestätigt", 'Bitte Durchführung und Anwesenheit eintragen.', '/dashboard/zeiterfassung.php', "unbest-{$r['user_id']}-{$heute}");
        }
    } catch (Exception $e) {
        // Tabellen fehlen noch (Migration 011) – still ignorieren
    }
}

// ----------------------------------------------------------------
// Sicherer PDF-Upload in das bestehende Dokumentenarchiv
// ----------------------------------------------------------------

/**
 * Prüft Größe, Dateisignatur (%PDF) und MIME-Typ per finfo, speichert die
 * Datei mit zufälligem Namen und legt den Datensatz in `dokumente` an.
 * $zuordnung: z.B. ['projekt_id' => 3] oder ['qualifikation_id' => 7].
 * Liefert ['id' => …] oder ['fehler' => …].
 */
function plattformPdfUpload(PDO $db, array $datei, string $titel, string $kategorie, array $zuordnung = [], string $sichtbar = 'admin'): array
{
    if (($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['fehler' => 'Bitte eine PDF-Datei auswählen.'];
    if ($datei['size'] > MAX_PDF_SIZE) return ['fehler' => 'Die Datei ist zu groß (max. 10 MB).'];
    $kopf = (string)file_get_contents($datei['tmp_name'], false, null, 0, 5);
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $datei['tmp_name']) : 'application/pdf';
    if ($kopf !== '%PDF-' || !in_array($mime, ['application/pdf', 'application/x-pdf'], true)) return ['fehler' => 'Nur echte PDF-Dateien sind erlaubt.'];

    if (!is_dir(PDF_PATH)) mkdir(PDF_PATH, 0755, true);
    $original = basename((string)$datei['name']);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.pdf';
    if (!move_uploaded_file($datei['tmp_name'], PDF_PATH . '/' . $name)) return ['fehler' => 'Die Datei konnte nicht gespeichert werden.'];

    $spalten = ['organization_id', 'titel', 'datei_name', 'datei_pfad', 'datei_groesse', 'mime_type', 'kategorie', 'sichtbar_fuer', 'hochgeladen_von'];
    $werte = [currentOrgId(), mb_substr($titel ?: $original, 0, 200), $original, 'pdfs/' . $name, (int)$datei['size'], 'application/pdf', $kategorie, $sichtbar, getCurrentUserId()];
    foreach (['projekt_id', 'partner_id', 'kurs_id', 'vertrag_id', 'qualifikation_id', 'buchung_id', 'foerderung_id', 'mitglied_id', 'kooperation_id'] as $feld) {
        if (!empty($zuordnung[$feld])) { $spalten[] = $feld; $werte[] = (int)$zuordnung[$feld]; }
    }
    $db->prepare('INSERT INTO dokumente (' . implode(', ', $spalten) . ') VALUES (' . implode(', ', array_fill(0, count($spalten), '?')) . ')')->execute($werte);
    $id = (int)$db->lastInsertId();
    logActivity('dokument_upload', "Dok-ID: {$id}, Datei: {$original}");
    return ['id' => $id];
}

// ----------------------------------------------------------------
// Kleine Helfer
// ----------------------------------------------------------------

/** Aktive Trainer:innen und Admins der Organisation. */
function plattformTrainer(PDO $db): array
{
    $stmt = $db->prepare("SELECT id, vorname, nachname, rolle FROM users WHERE organization_id = ? AND rolle IN ('trainer','admin') AND aktiv = 1 ORDER BY nachname, vorname");
    $stmt->execute([currentOrgId()]);
    return $stmt->fetchAll();
}

/** Alle aktiven Personen mit Dashboard-Zugang außer Mitgliedern (für Zuweisungen). */
function plattformTeam(PDO $db): array
{
    return plattformTrainer($db);
}

function plattformProjekte(PDO $db, bool $nur_offene = true): array
{
    try {
        $stmt = $db->prepare('SELECT id, name, status FROM projekte WHERE organization_id = ?' . ($nur_offene ? " AND status IN ('planung','aktiv','pausiert')" : '') . ' ORDER BY name');
        $stmt->execute([currentOrgId()]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function plattformDatum(?string $d, string $format = 'd.m.Y'): string
{
    return $d ? date($format, strtotime($d)) : '–';
}

/** „in 5 Tagen“, „heute“, „seit 3 Tagen“ */
function plattformFrist(?string $datum): string
{
    if (!$datum) return '';
    $tage = (int)round((strtotime(substr($datum, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
    return match (true) {
        $tage === 0 => 'heute',
        $tage === 1 => 'morgen',
        $tage > 1   => "in {$tage} Tagen",
        $tage === -1 => 'seit gestern',
        default     => 'seit ' . abs($tage) . ' Tagen',
    };
}

/** Status einer Qualifikation aus dem Ablaufdatum. */
function qualStatus(?string $gueltig_bis): array
{
    if (!$gueltig_bis) return ['code' => 'gueltig', 'label' => 'gültig (unbefristet)', 'class' => 'badge-success'];
    $tage = (int)round((strtotime($gueltig_bis) - strtotime(date('Y-m-d'))) / 86400);
    if ($tage < 0) return ['code' => 'abgelaufen', 'label' => 'abgelaufen', 'class' => 'badge-danger', 'tage' => $tage];
    if ($tage <= QUAL_WARNTAGE) return ['code' => 'bald', 'label' => "läuft in {$tage} Tagen ab", 'class' => 'badge-warning', 'tage' => $tage];
    return ['code' => 'gueltig', 'label' => 'gültig', 'class' => 'badge-success', 'tage' => $tage];
}

/**
 * Budget einer Förderung: bewilligt, verbrauchte Kosten (Ausgaben-Buchungen), zugeordnete
 * Einnahmen, erhaltene Auszahlung und Restbudget – alles mit bcmath.
 */
function foerderBudget(PDO $db, array $f): array
{
    $b = ['bewilligt' => moneyRound($f['betrag_bewilligt'] ?? 0), 'verbraucht' => '0.00', 'einnahmen' => '0.00', 'offen' => '0.00',
          'ausbezahlt' => moneyRound($f['betrag_ausbezahlt'] ?? 0), 'rest' => '0.00', 'quote' => 0.0, 'anzahl' => 0];
    try {
        $stmt = $db->prepare('SELECT art, betrag, status FROM buchungen WHERE foerderung_id = ?');
        $stmt->execute([$f['id']]);
        foreach ($stmt->fetchAll() as $r) {
            if ($r['art'] === 'ausgabe') {
                $b['verbraucht'] = bcadd($b['verbraucht'], moneyRound($r['betrag']), 2);
                if ($r['status'] === 'offen') $b['offen'] = bcadd($b['offen'], moneyRound($r['betrag']), 2);
                $b['anzahl']++;
            } else {
                $b['einnahmen'] = bcadd($b['einnahmen'], moneyRound($r['betrag']), 2);
            }
        }
    } catch (Exception $e) {}
    $b['rest'] = bcsub($b['bewilligt'], $b['verbraucht'], 2);
    $b['quote'] = bccomp($b['bewilligt'], '0', 2) > 0 ? round((float)$b['verbraucht'] / (float)$b['bewilligt'] * 100, 1) : 0.0;
    return $b;
}
