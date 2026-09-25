<?php
/**
 * Athletikclub Steiermark – Dashboard 2.0: „Handlungsbedarf“
 *
 * Sammelt rollen- und rechteabhängig alles, was jetzt Aufmerksamkeit braucht
 * (offene Bestätigungen, Freigaben, Fristen, abgelaufene Nachweise …).
 * Jede Prüfung ist gekapselt: fehlt eine Tabelle (Migration noch nicht
 * eingespielt), wird sie still übersprungen.
 *
 * Eintrag: ['stufe' => kritisch|warnung|info, 'text' => …, 'anzahl' => n, 'link' => '/dashboard/…', 'bereich' => …]
 */

require_once ROOT_PATH . '/includes/plattform.php';

const HB_STUFEN = ['kritisch' => 0, 'warnung' => 1, 'info' => 2];

function handlungsbedarf(PDO $db): array
{
    $me    = (int)getCurrentUserId();
    $org   = currentOrgId();
    $heute = date('Y-m-d');
    $jetzt = date('Y-m-d H:i:s');
    $liste = [];
    $add = function (string $stufe, string $text, int $anzahl, string $link, string $bereich) use (&$liste) {
        if ($anzahl > 0) $liste[] = compact('stufe', 'text', 'anzahl', 'link', 'bereich');
    };
    $zahl = function (string $sql, array $p) use ($db): int {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($p);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    };

    // ---------------- Persönlich (alle) ----------------
    $n = $zahl("SELECT COUNT(*) FROM aufgaben WHERE verantwortlich_id = ? AND status <> 'erledigt' AND deadline IS NOT NULL AND deadline < ?", [$me, $heute]);
    $add('kritisch', 'Überfällige Aufgaben', $n, '/dashboard/aufgaben.php?ansicht=ueberfaellig', 'Aufgaben');
    $n = $zahl("SELECT COUNT(*) FROM aufgaben WHERE verantwortlich_id = ? AND status <> 'erledigt' AND deadline BETWEEN ? AND ?", [$me, $heute, date('Y-m-d', strtotime('+3 days'))]);
    $add('warnung', 'Aufgaben in den nächsten 3 Tagen fällig', $n, '/dashboard/aufgaben.php', 'Aufgaben');

    // Eltern: Kinder ohne Teilnahme-Einwilligung bzw. ohne Notfallkontakt
    try {
        $stmt = $db->prepare('SELECT id, notfall_telefon FROM kinder WHERE elternteil_id = ? AND aktiv = 1');
        $stmt->execute([$me]);
        $ohne_einw = $ohne_notfall = 0;
        foreach ($stmt->fetchAll() as $k) {
            $e = $db->prepare("SELECT erteilt FROM einwilligungen WHERE kind_id = ? AND typ = 'teilnahme' ORDER BY created_at DESC, id DESC LIMIT 1");
            $e->execute([$k['id']]);
            if ((int)$e->fetchColumn() !== 1) $ohne_einw++;
            if (!$k['notfall_telefon']) $ohne_notfall++;
        }
        $add('warnung', 'Kinder ohne Teilnahme-Einwilligung', $ohne_einw, '/dashboard/kinder.php', 'Kinder');
        $add('info', 'Kinder ohne Notfallkontakt', $ohne_notfall, '/dashboard/kinder.php', 'Kinder');
    } catch (Exception $e) {}

    // Eigene Kursanmeldungen: Zahlung offen
    $n = $zahl("SELECT COUNT(*) FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                WHERE ka.user_id = ? AND ka.status = 'angemeldet' AND ka.bezahlt = 0 AND k.preis > 0 AND k.status <> 'abgesagt' AND k.end_datum >= ?", [$me, $jetzt]);
    $add('info', 'Kursbeiträge noch offen', $n, '/dashboard/kurse.php', 'Kurse');

    // ---------------- Trainer:innen ----------------
    if (isTrainer()) {
        $n = $zahl("SELECT COUNT(*) FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                    WHERE et.user_id = ? AND et.status = 'geplant' AND e.status <> 'storniert' AND e.ende < ?", [$me, $jetzt]);
        $add($n > 3 ? 'kritisch' : 'warnung', 'Einheiten noch nicht bestätigt (Durchführung/Dauer)', $n, '/dashboard/zeiterfassung.php', 'Zeiterfassung');

        $n = $zahl("SELECT COUNT(*) FROM einheit_trainer et JOIN einheiten e ON e.id = et.einheit_id
                    WHERE et.user_id = ? AND et.status <> 'geplant' AND et.status <> 'storniert' AND e.kurs_id IS NOT NULL AND e.start >= ?
                      AND NOT EXISTS (SELECT 1 FROM anwesenheiten a WHERE a.einheit_id = e.id)", [$me, date('Y-m-d', strtotime('-60 days'))]);
        $add('warnung', 'Anwesenheit fehlt bei durchgeführten Kurseinheiten', $n, '/dashboard/zeiterfassung.php', 'Anwesenheit');

        // Vormonat bestätigt, aber noch nicht eingereicht
        $vm = strtotime('first day of last month');
        $n = $zahl("SELECT COUNT(*) FROM trainer_abrechnungen WHERE user_id = ? AND jahr = ? AND monat = ? AND status = 'entwurf' AND anzahl_einheiten > 0",
                   [$me, (int)date('Y', $vm), (int)date('n', $vm)]);
        $add('warnung', 'Monatsabrechnung ' . date('m/Y', $vm) . ' noch nicht eingereicht', $n, '/dashboard/zeiterfassung.php?jahr=' . date('Y', $vm) . '&monat=' . date('n', $vm), 'Abrechnung');

        try {
            $stmt = $db->prepare('SELECT gueltig_bis FROM trainer_qualifikationen WHERE user_id = ? AND gueltig_bis IS NOT NULL');
            $stmt->execute([$me]);
            $ab = $bald = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $g) {
                $q = qualStatus($g);
                if ($q['code'] === 'abgelaufen') $ab++; elseif ($q['code'] === 'bald') $bald++;
            }
            $add('kritisch', 'Eigene Qualifikation abgelaufen', $ab, '/dashboard/qualifikationen.php', 'Qualifikationen');
            $add('warnung', 'Eigene Qualifikation läuft bald ab', $bald, '/dashboard/qualifikationen.php', 'Qualifikationen');
        } catch (Exception $e) {}

        // Eigene Kurse: Warteliste bei freien Plätzen (manuelle Nachbearbeitung nötig) und offene Zahlungen
        $n = $zahl("SELECT COUNT(*) FROM kurse k WHERE k.trainer_id = ? AND k.status IN ('geplant','aktiv') AND k.max_teilnehmer IS NOT NULL
                    AND (SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = k.id AND status = 'warteliste') > 0
                    AND (SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = k.id AND status = 'angemeldet') < k.max_teilnehmer", [$me]);
        $add('warnung', 'Eigene Kurse mit freien Plätzen und Warteliste', $n, '/dashboard/kurse.php?meine=1', 'Kurse');
        $n = $zahl("SELECT COUNT(*) FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id
                    WHERE k.trainer_id = ? AND ka.status = 'angemeldet' AND ka.bezahlt = 0 AND k.preis > 0 AND k.status IN ('geplant','aktiv')", [$me]);
        $add('info', 'Offene Zahlungen in eigenen Kursen', $n, '/dashboard/kurse.php?meine=1', 'Kurse');
    }

    // ---------------- Rechtebasiert ----------------
    if (darf('abrechnung.freigeben')) {
        $n = $zahl("SELECT COUNT(*) FROM trainer_abrechnungen WHERE organization_id = ? AND status = 'geprueft' AND user_id <> ?", [$org, $me]);
        $add('warnung', 'Trainerabrechnungen warten auf Freigabe', $n, '/dashboard/admin/trainerabrechnungen.php', 'Abrechnung');
    }
    if (darf('abrechnung.bearbeiten')) {
        $n = $zahl("SELECT COUNT(*) FROM trainer_abrechnungen WHERE organization_id = ? AND status = 'eingereicht'", [$org]);
        $add('warnung', 'Eingereichte Trainerabrechnungen prüfen', $n, '/dashboard/admin/trainerabrechnungen.php', 'Abrechnung');
    }
    if (darf('abrechnung.abrechnen')) {
        $n = $zahl("SELECT COUNT(*) FROM trainer_abrechnungen WHERE organization_id = ? AND status = 'freigegeben'", [$org]);
        $add('info', 'Freigegebene Abrechnungen auszahlen', $n, '/dashboard/admin/trainerabrechnungen.php', 'Abrechnung');
    }
    if (darf('rechnungen.anzeigen')) {
        $n = $zahl("SELECT COUNT(*) FROM rechnungen WHERE organization_id = ? AND status = 'offen' AND typ = 'rechnung' AND faellig_am < ?", [$org, $heute]);
        $add('warnung', 'Überfällige Rechnungen', $n, '/dashboard/admin/rechnungen.php?status=ueberfaellig', 'Rechnungen');
        $n = $zahl("SELECT COUNT(*) FROM mahnungen m JOIN rechnungen r ON r.id = m.rechnung_id WHERE r.organization_id = ? AND m.status = 'vorbereitet'", [$org]);
        $add('warnung', 'Vorbereitete Mahnungen prüfen und senden', $n, '/dashboard/admin/rechnungen.php?status=ueberfaellig', 'Rechnungen');
        $n = $zahl("SELECT COUNT(*) FROM rechnungen WHERE organization_id = ? AND status = 'entwurf'", [$org]);
        $add('info', 'Rechnungsentwürfe zur Prüfung', $n, '/dashboard/admin/rechnungen.php?status=entwurf', 'Rechnungen');
    }
    if (darf('onboarding.anzeigen')) {
        $n = $zahl("SELECT COUNT(*) FROM onboarding WHERE organization_id = ? AND status = 'bewerbung'", [$org]);
        $add('info', 'Neue Trainer-Bewerbungen', $n, '/dashboard/admin/onboarding.php', 'Onboarding');
    }
    if (darf('automatisierungen.bearbeiten')) {
        $n = $zahl("SELECT COUNT(*) FROM automationen WHERE organization_id = ? AND aktiv = 1 AND letzter_status = 'fehler'", [$org]);
        $add('kritisch', 'Automatisierungen mit Fehler', $n, '/dashboard/admin/automatisierungen.php', 'System');
    }
    if (darf('kalender.bearbeiten')) {
        $n = $zahl("SELECT COUNT(*) FROM einheiten e WHERE e.organization_id = ? AND e.status = 'geplant' AND e.start BETWEEN ? AND ?
                    AND NOT EXISTS (SELECT 1 FROM einheit_trainer et WHERE et.einheit_id = e.id AND et.status <> 'storniert')",
                   [$org, $jetzt, date('Y-m-d H:i:s', strtotime('+14 days'))]);
        $add('kritisch', 'Einheiten in den nächsten 14 Tagen ohne Trainer:in', $n, '/dashboard/kalender.php?ansicht=liste', 'Einsatzplanung');
    }
    if (darf('qualifikationen.anzeigen')) {
        try {
            $stmt = $db->prepare('SELECT gueltig_bis FROM trainer_qualifikationen WHERE organization_id = ? AND user_id <> ? AND gueltig_bis IS NOT NULL AND gueltig_bis <= ?');
            $stmt->execute([$org, $me, date('Y-m-d', strtotime('+' . QUAL_WARNTAGE . ' days'))]);
            $ab = $bald = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $g) { if ($g < $heute) $ab++; else $bald++; }
            $add('kritisch', 'Qualifikationen im Team abgelaufen', $ab, '/dashboard/qualifikationen.php', 'Qualifikationen');
            $add('info', 'Qualifikationen im Team laufen bald ab', $bald, '/dashboard/qualifikationen.php', 'Qualifikationen');
        } catch (Exception $e) {}
    }
    if (darf('foerderungen.anzeigen')) {
        try {
            $stmt = $db->prepare("SELECT * FROM foerderungen WHERE organization_id = ? AND status NOT IN ('abgelehnt','abgeschlossen')");
            $stmt->execute([$org]);
            $fristen = $ueber = 0;
            $grenze = date('Y-m-d', strtotime('+14 days'));
            foreach ($stmt->fetchAll() as $f) {
                foreach (['einreichfrist', 'nachweisfrist', 'abrechnungsfrist'] as $feld) {
                    if (empty($f[$feld]) || $f[$feld] < $heute || $f[$feld] > $grenze) continue;
                    if ($feld === 'einreichfrist' && !in_array($f['status'], FOERDER_OFFEN, true)) continue;
                    if ($feld !== 'einreichfrist' && $f['status'] === 'abgerechnet') continue;
                    $fristen++;
                }
                if (in_array($f['status'], FOERDER_ZUGESAGT, true)) {
                    $b = foerderBudget($db, $f);
                    if (bccomp($b['bewilligt'], '0', 2) > 0 && bccomp($b['rest'], '0', 2) < 0) $ueber++;
                }
            }
            $add('kritisch', 'Förderfristen in den nächsten 14 Tagen', $fristen, '/dashboard/admin/foerderungen.php', 'Förderungen');
            $add('warnung', 'Förderbudget überschritten', $ueber, '/dashboard/admin/foerderungen.php', 'Förderungen');
        } catch (Exception $e) {}
    }
    if (darf('vertraege.anzeigen')) {
        $n = $zahl("SELECT COUNT(*) FROM vertraege WHERE organization_id = ? AND status = 'aktiv' AND
                    ((kuendigung_bis IS NOT NULL AND kuendigung_bis BETWEEN ? AND ?) OR (ende IS NOT NULL AND ende <= ?))",
                   [$org, $heute, date('Y-m-d', strtotime('+45 days')), date('Y-m-d', strtotime('+45 days'))]);
        $add('warnung', 'Verträge: Kündigungsfrist oder Ende in 45 Tagen', $n, '/dashboard/admin/vertraege.php', 'Verträge');
    }
    if (darf('partner.anzeigen')) {
        $n = $zahl("SELECT COUNT(*) FROM partner_organisationen WHERE organization_id = ? AND status <> 'inaktiv' AND naechster_kontakt IS NOT NULL AND naechster_kontakt <= ?", [$org, $heute]);
        $add('info', 'Partner-Wiedervorlagen fällig', $n, '/dashboard/admin/partner.php', 'Partner');
    }
    if (darf('ressourcen.bearbeiten')) {
        $n = $zahl("SELECT COUNT(*) FROM ressourcen WHERE organization_id = ? AND zustand IN ('reparatur','defekt')", [$org]);
        $add('info', 'Material in Reparatur oder defekt', $n, '/dashboard/ressourcen.php', 'Ressourcen');
    }
    if (darf('projekte.anzeigen')) {
        $n = $zahl("SELECT COUNT(*) FROM projekte WHERE organization_id = ? AND status = 'aktiv' AND end_datum IS NOT NULL AND end_datum < ?", [$org, $heute]);
        $add('info', 'Aktive Projekte mit überschrittenem Enddatum', $n, '/dashboard/projekte.php', 'Projekte');
    }
    if (isAdmin()) {
        $n = $zahl('SELECT COUNT(*) FROM kontakt_anfragen WHERE gelesen = 0', []);
        $add('info', 'Neue Kontaktanfragen', $n, '/dashboard/admin/inhalte.php', 'Website');
    }

    usort($liste, fn($a, $b) => HB_STUFEN[$a['stufe']] <=> HB_STUFEN[$b['stufe']] ?: $b['anzahl'] <=> $a['anzahl']);
    return $liste;
}

/** Eigene kommende Einheiten (Trainer:in) bzw. Kurstermine (Mitglied/Kinder) der nächsten Tage. */
function meineNaechstenTermine(PDO $db, int $tage = 7, int $limit = 6): array
{
    $me = (int)getCurrentUserId();
    $von = date('Y-m-d H:i:s');
    $bis = date('Y-m-d H:i:s', strtotime("+{$tage} days"));
    try {
        $stmt = $db->prepare("SELECT e.id, e.titel, e.start, e.ende, e.ort, 'einsatz' AS art FROM einheiten e JOIN einheit_trainer et ON et.einheit_id = e.id
                              WHERE et.user_id = ? AND et.status <> 'storniert' AND e.status <> 'storniert' AND e.start BETWEEN ? AND ?
                              UNION
                              SELECT e.id, e.titel, e.start, e.ende, e.ort, 'kurs' AS art FROM einheiten e JOIN kurs_anmeldungen ka ON ka.kurs_id = e.kurs_id
                              WHERE ka.user_id = ? AND ka.status = 'angemeldet' AND e.status <> 'storniert' AND e.start BETWEEN ? AND ?
                              ORDER BY start LIMIT " . (int)$limit);
        $stmt->execute([$me, $von, $bis, $me, $von, $bis]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}
