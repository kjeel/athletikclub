<?php
/**
 * Athletikclub Steiermark – zentrale Automatisierungs-Engine
 *
 * Prinzip: AUSLÖSER → BEDINGUNG → AKTION. Jede Automatisierung ist im Register
 * AUTOMATION_REGISTER beschrieben und hat eine Funktion, die ihre Bedingungen selbst
 * prüft. Ein einziger Runner (automationLauf) führt fällige Automatisierungen aus –
 * aufgerufen über cron.php (echter Cron) oder als Rückfall höchstens alle 10 Minuten
 * beim Seitenaufruf im Dashboard. Jede Aktion wird in automation_log protokolliert;
 * datensatzbezogene Aktionen werden über das Protokoll gegen Doppelausführung geschützt.
 *
 * Finanziell/organisatorisch wichtige Schritte werden nur VORBEREITET (Abrechnungsentwürfe,
 * Mahnungen – Versand nur mit Einstellung), nie endgültig freigegeben.
 */

require_once ROOT_PATH . '/includes/kursanmeldung.php';
require_once ROOT_PATH . '/includes/einheiten.php';
require_once ROOT_PATH . '/includes/rechnungen.php';

const AUTOMATION_REGISTER = [
    'kurs_erinnerung' => [
        'name' => 'Kurs-Erinnerung', 'ausloeser' => 'Einheit/Kurs beginnt in X Stunden', 'bedingung' => 'bestätigte Anmeldung, noch nicht erinnert',
        'aktion' => 'Teilnehmende per Vorlage „Kurs-Erinnerung“ erinnern', 'intervall' => 30, 'parameter' => ['stunden' => 'erinnerung_stunden'],
    ],
    'einheit_bestaetigen' => [
        'name' => 'Einheit bestätigen', 'ausloeser' => 'Einheit ist beendet', 'bedingung' => 'Trainer:in hat noch nicht bestätigt',
        'aktion' => 'Trainer:in um Bestätigung bitten (einmal je Einheit)', 'intervall' => 60, 'parameter' => [],
    ],
    'anfragen_verfall' => [
        'name' => 'Unbestätigte Anmeldungen', 'ausloeser' => 'Bestätigungsfrist einer Online-Anmeldung abgelaufen', 'bedingung' => 'Status „angefragt“',
        'aktion' => 'Anmeldung stornieren, Platz an Warteliste', 'intervall' => 15, 'parameter' => [],
    ],
    'warteliste' => [
        'name' => 'Warteliste bearbeiten', 'ausloeser' => 'Platz frei geworden', 'bedingung' => 'Kurs hat freie Plätze und Wartende',
        'aktion' => 'Nächste Person nachrücken lassen und informieren', 'intervall' => 15, 'parameter' => [],
    ],
    'qualifikation_ablauf' => [
        'name' => 'Qualifikationen', 'ausloeser' => 'Qualifikation läuft in X Tagen ab', 'bedingung' => 'noch nicht erinnert (je Stufe)',
        'aktion' => 'Trainer:in und Administration informieren', 'intervall' => 1440, 'parameter' => ['tage' => 30],
    ],
    'foerderfrist' => [
        'name' => 'Förderfristen', 'ausloeser' => 'Einreich-, Nachweis- oder Abrechnungsfrist in X Tagen', 'bedingung' => 'Förderung nicht abgeschlossen',
        'aktion' => 'Administration informieren', 'intervall' => 1440, 'parameter' => ['tage' => 14],
    ],
    'vertrag_ablauf' => [
        'name' => 'Verträge', 'ausloeser' => 'Kündigungsfrist/Vertragsende in X Tagen', 'bedingung' => 'Vertrag aktiv',
        'aktion' => 'Verantwortliche (Administration) informieren', 'intervall' => 1440, 'parameter' => ['tage' => 30],
    ],
    'aufgabe_ueberfaellig' => [
        'name' => 'Aufgaben', 'ausloeser' => 'Deadline morgen oder überschritten', 'bedingung' => 'Aufgabe nicht erledigt',
        'aktion' => 'Verantwortliche Person informieren', 'intervall' => 720, 'parameter' => [],
    ],
    'partner_wiedervorlage' => [
        'name' => 'Partner-Wiedervorlage', 'ausloeser' => 'Wiedervorlagedatum erreicht', 'bedingung' => 'Partner nicht inaktiv',
        'aktion' => 'Betreuer:in informieren', 'intervall' => 1440, 'parameter' => [],
    ],
    'rechnung_ueberfaellig' => [
        'name' => 'Mahnwesen', 'ausloeser' => 'Rechnung überfällig (Tage laut Einstellungen)', 'bedingung' => 'Stufe noch nicht erzeugt',
        'aktion' => 'Zahlungserinnerung/Mahnung vorbereiten (Versand je nach Einstellung)', 'intervall' => 720, 'parameter' => [],
    ],
    'abrechnung_monatsende' => [
        'name' => 'Trainerabrechnung Monatsende', 'ausloeser' => 'Monatsende', 'bedingung' => 'bestätigte, noch nicht abgerechnete Einheiten im Vormonat',
        'aktion' => 'Abrechnungsentwürfe erzeugen (Freigabe bleibt bei der Administration)', 'intervall' => 720, 'parameter' => [],
    ],
];

/** Protokoll-Eintrag. */
function automationLog(PDO $db, string $code, ?string $ausloeser, ?string $datensatz, ?string $aktion, string $ergebnis = 'ok', ?string $fehler = null): void
{
    try {
        $db->prepare('INSERT INTO automation_log (organization_id, automation, ausloeser, datensatz, aktion, ergebnis, fehler) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([currentOrgId(), $code, $ausloeser ? mb_substr($ausloeser, 0, 120) : null, $datensatz ? mb_substr($datensatz, 0, 80) : null,
                      $aktion ? mb_substr($aktion, 0, 255) : null, $ergebnis, $fehler]);
    } catch (Exception $e) {}
}

/** Wurde diese Aktion für diesen Datensatz bereits erfolgreich ausgeführt? */
function automationErledigt(PDO $db, string $code, string $datensatz): bool
{
    $stmt = $db->prepare("SELECT 1 FROM automation_log WHERE automation = ? AND datensatz = ? AND ergebnis = 'ok' AND organization_id = ? LIMIT 1");
    $stmt->execute([$code, $datensatz, currentOrgId()]);
    return (bool)$stmt->fetchColumn();
}

/** Fehlende Automatisierungen mit Standardwerten anlegen. */
function automationenSync(PDO $db): void
{
    $vorhanden = $db->prepare('SELECT code FROM automationen WHERE organization_id = ?');
    $vorhanden->execute([currentOrgId()]);
    $da = array_flip($vorhanden->fetchAll(PDO::FETCH_COLUMN));
    foreach (AUTOMATION_REGISTER as $code => $def) {
        if (isset($da[$code])) continue;
        $param = array_filter($def['parameter'], fn($v) => is_int($v));
        $db->prepare('INSERT INTO automationen (organization_id, code, aktiv, intervall_min, parameter) VALUES (?, ?, 1, ?, ?)')
           ->execute([currentOrgId(), $code, $def['intervall'], $param ? json_encode($param) : null]);
    }
}

function automationParameter(array $zeile, string $name, int $standard): int
{
    $p = json_decode((string)($zeile['parameter'] ?? ''), true) ?: [];
    return isset($p[$name]) ? (int)$p[$name] : $standard;
}

// ----------------------------------------------------------------
// Aktionen der einzelnen Automatisierungen (liefern Anzahl ausgelöster Aktionen)
// ----------------------------------------------------------------

function autoKursErinnerung(PDO $db, array $a): int
{
    require_once ROOT_PATH . '/includes/kommunikation.php';
    $std = max(1, (int)einstellung('erinnerung_stunden', '24'));
    $jetzt = date('Y-m-d H:i:s');
    $bis = date('Y-m-d H:i:s', time() + $std * 3600);
    $n = 0;
    // Einheiten mit Kursbezug, die im Fenster beginnen
    $stmt = $db->prepare("SELECT e.id AS einheit_id, e.start, e.ende, e.ort AS e_ort, k.* FROM einheiten e JOIN kurse k ON k.id = e.kurs_id
                          WHERE e.organization_id = ? AND e.status = 'geplant' AND e.start > ? AND e.start <= ?");
    $stmt->execute([currentOrgId(), $jetzt, $bis]);
    $termine = $stmt->fetchAll();
    // Kurse ohne Einheiten (Beginn im Fenster)
    $stmt = $db->prepare("SELECT NULL AS einheit_id, k.start_datum AS start, k.end_datum AS ende, NULL AS e_ort, k.* FROM kurse k
                          WHERE k.organization_id = ? AND k.status IN ('geplant','aktiv') AND k.start_datum > ? AND k.start_datum <= ?
                          AND NOT EXISTS (SELECT 1 FROM einheiten e WHERE e.kurs_id = k.id)");
    $stmt->execute([currentOrgId(), $jetzt, $bis]);
    $termine = array_merge($termine, $stmt->fetchAll());
    foreach ($termine as $t) {
        $kurs = $t;
        $kurs['start_datum'] = $t['start'];
        if ($t['e_ort']) $kurs['ort'] = $t['e_ort'];
        $anm = $db->prepare("SELECT ka.id FROM kurs_anmeldungen ka WHERE ka.kurs_id = ? AND ka.status = 'angemeldet'");
        $anm->execute([$t['id']]);
        foreach ($anm->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $ds = ($t['einheit_id'] ? 'einheit:' . $t['einheit_id'] : 'kurs:' . $t['id']) . ':anm:' . $aid;
            if (automationErledigt($db, 'kurs_erinnerung', $ds)) continue;
            kursAnmeldungBenachrichtigen($db, $kurs, (int)$aid, 'kurs_erinnerung');
            automationLog($db, 'kurs_erinnerung', 'Beginn ' . date('d.m.Y H:i', strtotime($t['start'])), $ds, 'Erinnerung an Teilnehmende: ' . $t['titel']);
            $n++;
        }
    }
    return $n;
}

function autoEinheitBestaetigen(PDO $db, array $a): int
{
    require_once ROOT_PATH . '/includes/kommunikation.php';
    $stmt = $db->prepare("SELECT et.user_id, e.* FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                          WHERE e.organization_id = ? AND e.status <> 'storniert' AND et.status = 'geplant' AND e.ende < ? AND e.ende > ?");
    $stmt->execute([currentOrgId(), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('-14 days'))]);
    $n = 0;
    foreach ($stmt->fetchAll() as $e) {
        $ds = 'einheit:' . $e['id'] . ':trainer:' . $e['user_id'];
        if (automationErledigt($db, 'einheit_bestaetigen', $ds)) continue;
        nachrichtAnPerson($db, (int)$e['user_id'], 'einheit_bestaetigen', ['kurs' => $e['titel'], 'datum' => date('d.m.Y', strtotime($e['start']))],
                          '/dashboard/einheit.php?id=' . (int)$e['id'], 'einheit:' . $e['id'], 'bestaetigen-' . $e['id']);
        automationLog($db, 'einheit_bestaetigen', 'Einheit beendet ' . date('d.m.Y H:i', strtotime($e['ende'])), $ds, 'Trainer:in um Bestätigung gebeten: ' . $e['titel']);
        $n++;
    }
    return $n;
}

function autoAnfragenVerfall(PDO $db, array $a): int
{
    $n = kursAnfragenVerfallen($db);
    if ($n) automationLog($db, 'anfragen_verfall', 'Bestätigungsfrist abgelaufen', null, "$n unbestätigte Anmeldung(en) storniert, Warteliste geprüft");
    return $n;
}

function autoWarteliste(PDO $db, array $a): int
{
    $stmt = $db->prepare("SELECT k.* FROM kurse k WHERE k.organization_id = ? AND k.status IN ('geplant','aktiv') AND k.end_datum >= ?
                          AND EXISTS (SELECT 1 FROM kurs_anmeldungen w WHERE w.kurs_id = k.id AND w.status = 'warteliste')
                          AND (k.max_teilnehmer IS NULL OR k.max_teilnehmer > (SELECT COUNT(*) FROM kurs_anmeldungen b WHERE b.kurs_id = k.id AND b.status IN ('angemeldet','angefragt')))");
    $stmt->execute([currentOrgId(), date('Y-m-d H:i:s')]);
    $n = 0;
    foreach ($stmt->fetchAll() as $k) {
        $ids = kursNachruecken($db, $k);
        if ($ids) { automationLog($db, 'warteliste', 'Platz frei', 'kurs:' . $k['id'], count($ids) . ' Person(en) nachgerückt: ' . $k['titel']); $n += count($ids); }
    }
    return $n;
}

function autoQualifikation(PDO $db, array $a): int
{
    $tage = automationParameter($a, 'tage', 30);
    $heute = date('Y-m-d');
    $stmt = $db->prepare('SELECT q.*, u.vorname, u.nachname FROM trainer_qualifikationen q JOIN users u ON u.id = q.user_id
                          WHERE q.organization_id = ? AND u.aktiv = 1 AND q.gueltig_bis IS NOT NULL AND q.gueltig_bis <= ?');
    $stmt->execute([currentOrgId(), date('Y-m-d', strtotime("+$tage days"))]);
    $n = 0;
    foreach ($stmt->fetchAll() as $q) {
        $rest = (int)round((strtotime($q['gueltig_bis']) - strtotime($heute)) / 86400);
        $stufe = $rest < 0 ? 'ab' : ($rest <= 7 ? '7' : $tage);
        $ds = 'qual:' . $q['id'] . ':' . $stufe;
        if (automationErledigt($db, 'qualifikation_ablauf', $ds)) continue;
        $titel = $rest < 0 ? "{$q['bezeichnung']} ist abgelaufen" : "{$q['bezeichnung']} läuft in {$rest} Tagen ab";
        $link = '/dashboard/qualifikationen.php?user=' . $q['user_id'];
        benachrichtigen((int)$q['user_id'], 'qualifikation', $titel, 'Bitte einen neuen Nachweis hochladen.', $link, $ds);
        benachrichtigeAdmins($db, 'qualifikation', "{$q['vorname']} {$q['nachname']}: {$titel}", null, $link, $ds);
        automationLog($db, 'qualifikation_ablauf', 'gültig bis ' . date('d.m.Y', strtotime($q['gueltig_bis'])), $ds, 'Trainer:in + Administration informiert');
        $n++;
    }
    return $n;
}

function autoFoerderfrist(PDO $db, array $a): int
{
    $tage = automationParameter($a, 'tage', 14);
    $heute = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM foerderungen WHERE organization_id = ? AND status NOT IN ('abgelehnt','abgeschlossen')");
    $stmt->execute([currentOrgId()]);
    $n = 0;
    foreach ($stmt->fetchAll() as $f) {
        foreach (['einreichfrist' => 'Einreichfrist', 'nachweisfrist' => 'Nachweisfrist', 'abrechnungsfrist' => 'Abrechnungsfrist'] as $feld => $label) {
            if (empty($f[$feld]) || $f[$feld] < $heute || $f[$feld] > date('Y-m-d', strtotime("+$tage days"))) continue;
            if ($feld === 'einreichfrist' && !in_array($f['status'], FOERDER_OFFEN, true)) continue;
            $rest = (int)round((strtotime($f[$feld]) - strtotime($heute)) / 86400);
            $ds = "foerd:{$f['id']}:$feld:" . ($rest <= 5 ? '5' : $tage);
            if (automationErledigt($db, 'foerderfrist', $ds)) continue;
            // Schlüssel wie im bisherigen Tagescheck, damit niemand doppelt benachrichtigt wird
            benachrichtigeAdmins($db, 'foerderung', "{$label} „{$f['titel']}“ in {$rest} Tagen", 'Frist: ' . date('d.m.Y', strtotime($f[$feld])), '/dashboard/admin/foerderung-detail.php?id=' . $f['id'], "foerd-{$f['id']}-{$feld}-" . ($rest <= 5 ? '5' : '14'));
            automationLog($db, 'foerderfrist', $label . ' ' . date('d.m.Y', strtotime($f[$feld])), $ds, 'Administration informiert: ' . $f['titel']);
            $n++;
        }
    }
    return $n;
}

function autoVertrag(PDO $db, array $a): int
{
    $tage = automationParameter($a, 'tage', 30);
    $heute = date('Y-m-d');
    $grenze = date('Y-m-d', strtotime("+$tage days"));
    $stmt = $db->prepare("SELECT * FROM vertraege WHERE organization_id = ? AND status = 'aktiv' AND ((kuendigung_bis IS NOT NULL AND kuendigung_bis BETWEEN ? AND ?) OR (ende IS NOT NULL AND ende BETWEEN ? AND ?))");
    $stmt->execute([currentOrgId(), $heute, $grenze, $heute, $grenze]);
    $n = 0;
    foreach ($stmt->fetchAll() as $v) {
        $frist = $v['kuendigung_bis'] && $v['kuendigung_bis'] <= $grenze && $v['kuendigung_bis'] >= $heute ? 'Kündigungsfrist bis ' . date('d.m.Y', strtotime($v['kuendigung_bis'])) : 'Vertragsende ' . date('d.m.Y', strtotime($v['ende']));
        $ds = 'vertrag:' . $v['id'] . ':' . ($v['kuendigung_bis'] ?? $v['ende']);
        if (automationErledigt($db, 'vertrag_ablauf', $ds)) continue;
        benachrichtigeAdmins($db, 'vertrag', "Vertrag „{$v['titel']}“: {$frist}", null, '/dashboard/admin/vertraege.php?id=' . $v['id'], "vertrag-{$v['id']}-" . ($v['kuendigung_bis'] ?? $v['ende']));
        automationLog($db, 'vertrag_ablauf', $frist, $ds, 'Administration informiert: ' . $v['titel']);
        $n++;
    }
    return $n;
}

function autoAufgaben(PDO $db, array $a): int
{
    $heute = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM aufgaben WHERE organization_id = ? AND status <> 'erledigt' AND verantwortlich_id IS NOT NULL AND deadline IS NOT NULL AND deadline <= ?");
    $stmt->execute([currentOrgId(), date('Y-m-d', strtotime('+1 day'))]);
    $n = 0;
    foreach ($stmt->fetchAll() as $t) {
        $ueber = $t['deadline'] < $heute;
        $ds = 'aufgabe:' . $t['id'] . ':' . ($ueber ? 'ueber' : 'morgen');
        if (automationErledigt($db, 'aufgabe_ueberfaellig', $ds)) continue;
        benachrichtigen((int)$t['verantwortlich_id'], 'aufgabe', ($ueber ? 'Überfällig: ' : 'Fällig morgen: ') . $t['titel'], 'Deadline ' . date('d.m.Y', strtotime($t['deadline'])), '/dashboard/aufgaben.php?id=' . $t['id'], 'aufg-' . $t['id'] . '-' . ($ueber ? 'ueber' : 'morgen'));
        automationLog($db, 'aufgabe_ueberfaellig', 'Deadline ' . date('d.m.Y', strtotime($t['deadline'])), $ds, 'Verantwortliche Person informiert: ' . $t['titel']);
        $n++;
    }
    return $n;
}

function autoPartner(PDO $db, array $a): int
{
    $stmt = $db->prepare("SELECT id, name, naechster_kontakt, verantwortlich_id FROM partner_organisationen WHERE organization_id = ? AND status <> 'inaktiv' AND naechster_kontakt IS NOT NULL AND naechster_kontakt <= ?");
    $stmt->execute([currentOrgId(), date('Y-m-d')]);
    $n = 0;
    foreach ($stmt->fetchAll() as $p) {
        $ds = "partner:{$p['id']}:{$p['naechster_kontakt']}";
        if (automationErledigt($db, 'partner_wiedervorlage', $ds)) continue;
        $titel = "Wiedervorlage: {$p['name']} kontaktieren";
        $link = '/dashboard/admin/partner.php?id=' . $p['id'];
        $key = "partner-{$p['id']}-{$p['naechster_kontakt']}";
        if ($p['verantwortlich_id']) benachrichtigen((int)$p['verantwortlich_id'], 'projekt', $titel, null, $link, $key);
        else benachrichtigeAdmins($db, 'projekt', $titel, null, $link, $key);
        automationLog($db, 'partner_wiedervorlage', 'Wiedervorlage ' . date('d.m.Y', strtotime($p['naechster_kontakt'])), $ds, 'Betreuung informiert: ' . $p['name']);
        $n++;
    }
    return $n;
}

function autoMahnwesen(PDO $db, array $a): int
{
    $e = rechnungenMahnlauf($db);
    foreach ($e['details'] as $d) automationLog($db, 'rechnung_ueberfaellig', 'Rechnung überfällig', explode(':', $d)[0], $d . (einstellung('mahnung_auto_versand', '0') === '1' ? ' – versendet' : ' – vorbereitet, Versand manuell'));
    if ($e['vorbereitet'] && einstellung('mahnung_auto_versand', '0') !== '1') {
        benachrichtigeAdmins($db, 'abrechnung', $e['vorbereitet'] . ' Mahnung(en) vorbereitet', 'Bitte prüfen und senden.', '/dashboard/admin/rechnungen.php?status=ueberfaellig', 'mahnlauf-' . date('Ymd'));
    }
    return $e['vorbereitet'];
}

function autoAbrechnungMonatsende(PDO $db, array $a): int
{
    $vm = strtotime('first day of last month');
    [$jahr, $monat] = [(int)date('Y', $vm), (int)date('n', $vm)];
    $ds = sprintf('monat:%04d-%02d', $jahr, $monat);
    if (automationErledigt($db, 'abrechnung_monatsende', $ds)) return 0;
    $stmt = $db->prepare("SELECT DISTINCT et.user_id FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                          WHERE e.organization_id = ? AND et.status IN ('durchgefuehrt','zur_abrechnung') AND et.abrechnung_id IS NULL AND e.start BETWEEN ? AND ?");
    $stmt->execute([currentOrgId(), date('Y-m-01 00:00:00', $vm), date('Y-m-t 23:59:59', $vm)]);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if ($ta = trainerAbrechnungAktualisieren($db, (int)$uid, $jahr, $monat)) {
            $n++;
            benachrichtigen((int)$uid, 'abrechnung', sprintf('Abrechnung %02d/%d vorbereitet', $monat, $jahr), 'Bitte prüfen und einreichen.', '/dashboard/zeiterfassung.php?jahr=' . $jahr . '&monat=' . $monat, 'ta-entwurf-' . $ta['id']);
        }
    }
    automationLog($db, 'abrechnung_monatsende', 'Monatsende ' . sprintf('%02d/%d', $monat, $jahr), $ds, "$n Abrechnungsentwurf/-entwürfe erzeugt – Freigabe durch die Administration");
    return $n;
}

const AUTOMATION_FUNKTIONEN = [
    'kurs_erinnerung' => 'autoKursErinnerung', 'einheit_bestaetigen' => 'autoEinheitBestaetigen', 'anfragen_verfall' => 'autoAnfragenVerfall',
    'warteliste' => 'autoWarteliste', 'qualifikation_ablauf' => 'autoQualifikation', 'foerderfrist' => 'autoFoerderfrist', 'vertrag_ablauf' => 'autoVertrag',
    'aufgabe_ueberfaellig' => 'autoAufgaben', 'partner_wiedervorlage' => 'autoPartner', 'rechnung_ueberfaellig' => 'autoMahnwesen',
    'abrechnung_monatsende' => 'autoAbrechnungMonatsende',
];

/**
 * Fällige (oder mit $nur/$erzwingen gewählte) Automatisierungen ausführen.
 * Liefert ['ausgefuehrt' => [code => anzahl], 'fehler' => [code => text]] oder null, wenn gesperrt.
 */
function automationLauf(PDO $db, bool $erzwingen = false, ?string $nur = null): ?array
{
    // Sperre gegen parallele Läufe (verfällt nach 10 Minuten, falls ein Lauf abbricht)
    $sperre = systemStatus($db, 'automation_sperre_' . currentOrgId());
    if ($sperre && strtotime($sperre) > time() - 600) return null;
    systemStatusSetzen($db, 'automation_sperre_' . currentOrgId(), date('Y-m-d H:i:s'));
    $erg = ['ausgefuehrt' => [], 'fehler' => []];
    try {
        automationenSync($db);
        $stmt = $db->prepare('SELECT * FROM automationen WHERE organization_id = ?');
        $stmt->execute([currentOrgId()]);
        foreach ($stmt->fetchAll() as $a) {
            $code = $a['code'];
            if (!isset(AUTOMATION_FUNKTIONEN[$code]) || ($nur && $nur !== $code)) continue;
            if (!$erzwingen && (!(int)$a['aktiv'] || ($a['naechste_ausfuehrung'] && $a['naechste_ausfuehrung'] > date('Y-m-d H:i:s')))) continue;
            $jetzt = date('Y-m-d H:i:s');
            try {
                $n = (AUTOMATION_FUNKTIONEN[$code])($db, $a);
                $erg['ausgefuehrt'][$code] = $n;
                $db->prepare("UPDATE automationen SET letzte_ausfuehrung = ?, naechste_ausfuehrung = ?, letzter_status = 'ok', letzter_fehler = NULL WHERE id = ?")
                   ->execute([$jetzt, date('Y-m-d H:i:s', time() + 60 * max(5, (int)$a['intervall_min'])), $a['id']]);
            } catch (Throwable $e) {
                $text = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 500);
                $erg['fehler'][$code] = $text;
                automationLog($db, $code, 'Lauf', null, 'Abbruch', 'fehler', $text);
                $db->prepare("UPDATE automationen SET letzte_ausfuehrung = ?, naechste_ausfuehrung = ?, letzter_status = 'fehler', letzter_fehler = ? WHERE id = ?")
                   ->execute([$jetzt, date('Y-m-d H:i:s', time() + 60 * max(5, (int)$a['intervall_min'])), $text, $a['id']]);
            }
        }
        systemStatusSetzen($db, 'automation_letzter_lauf_' . currentOrgId(), date('Y-m-d H:i:s'));
    } finally {
        systemStatusSetzen($db, 'automation_sperre_' . currentOrgId(), '');
    }
    return $erg;
}

/** Rückfall ohne Cron: höchstens alle 10 Minuten beim Seitenaufruf. */
function automationTick(PDO $db): void
{
    try {
        $letzt = systemStatus($db, 'automation_tick_' . currentOrgId());
        if ($letzt && strtotime($letzt) > time() - 600) return;
        systemStatusSetzen($db, 'automation_tick_' . currentOrgId(), date('Y-m-d H:i:s'));
        automationLauf($db);
    } catch (Throwable $e) {
        // Automatisierung darf die Seite nie blockieren
    }
}
