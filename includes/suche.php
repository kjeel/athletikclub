<?php
/**
 * Athletikclub Steiermark – Globale Suche
 * Durchsucht alle Module, gruppiert nach Kategorie. Jede Kategorie wird nur mit passender
 * Berechtigung abgefragt; innerhalb einer Kategorie gelten dieselben Filter wie auf der Zielseite
 * (z.B. nur eigene Aufgaben/Projekte ohne Leserecht, Dokumente nach Sichtbarkeit).
 */

require_once ROOT_PATH . '/includes/plattform.php';

const SUCHE_MIN = 2;
const SUCHE_JE_KATEGORIE = 8;

function sucheLike(string $q): string
{
    return '%' . strtr($q, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
}

/** Liefert [kategorie => ['label' => …, 'treffer' => [['titel','info','link'], …], 'mehr' => link|null]] */
function globaleSuche(PDO $db, string $q): array
{
    $q = trim(preg_replace('/\s+/', ' ', $q));
    if (mb_strlen($q) < SUCHE_MIN) return [];
    $org = currentOrgId();
    $me = (int)getCurrentUserId();
    $like = sucheLike($q);
    $n = SUCHE_JE_KATEGORIE + 1;
    $erg = [];
    $abfrage = function (string $sql, array $p) use ($db): array {
        try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Exception $e) { return []; }
    };
    // MySQL nutzt „\“ bereits als LIKE-Escape (ein ESCAPE '\' wäre dort ein Syntaxfehler), SQLite braucht die Angabe
    $esc = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '' : " ESCAPE '\\'";
    $add = function (string $kat, string $label, array $zeilen, callable $abbild, ?string $mehr = null) use (&$erg) {
        if (!$zeilen) return;
        $erg[$kat] = ['label' => $label, 'treffer' => array_map($abbild, array_slice($zeilen, 0, SUCHE_JE_KATEGORIE)),
                      'mehr' => count($zeilen) > SUCHE_JE_KATEGORIE ? $mehr : null];
    };
    $name = "(u.vorname || ' ' || u.nachname)";
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $name = "CONCAT(u.vorname, ' ', u.nachname)";

    // Personen
    if (isTrainer()) {
        $add('personen', 'Personen', $abfrage("SELECT u.id, u.vorname, u.nachname, u.email, u.rolle FROM users u WHERE u.organization_id = ?
                AND (u.vorname LIKE ?$esc OR u.nachname LIKE ?$esc OR u.email LIKE ?$esc OR $name LIKE ?$esc) ORDER BY u.nachname, u.vorname LIMIT $n", [$org, $like, $like, $like, $like]),
            fn($r) => ['titel' => $r['vorname'] . ' ' . $r['nachname'], 'info' => ucfirst($r['rolle']) . ' · ' . $r['email'], 'link' => APP_URL . '/dashboard/mitglied-detail.php?id=' . $r['id']],
            APP_URL . '/dashboard/mitglieder.php?suche=' . urlencode($q));
    }
    if (darf('kinder.anzeigen')) {
        $add('kinder', 'Kinder', $abfrage("SELECT ki.id, ki.vorname, ki.nachname, ki.geburtsdatum, u.id AS eltern_id, u.vorname AS e_vorname, u.nachname AS e_nachname
                FROM kinder ki JOIN users u ON u.id = ki.elternteil_id WHERE ki.organization_id = ? AND (ki.vorname LIKE ?$esc OR ki.nachname LIKE ?$esc) ORDER BY ki.nachname LIMIT $n", [$org, $like, $like]),
            fn($r) => ['titel' => $r['vorname'] . ' ' . $r['nachname'], 'info' => 'Kind von ' . $r['e_vorname'] . ' ' . $r['e_nachname'], 'link' => APP_URL . '/dashboard/mitglied-detail.php?id=' . $r['eltern_id']]);
    }
    if (darf('onboarding.anzeigen')) {
        $add('onboarding', 'Trainer-Onboarding', $abfrage("SELECT id, vorname, nachname, email, status FROM onboarding WHERE organization_id = ? AND (vorname LIKE ?$esc OR nachname LIKE ?$esc OR email LIKE ?$esc) ORDER BY created_at DESC LIMIT $n", [$org, $like, $like, $like]),
            fn($r) => ['titel' => $r['vorname'] . ' ' . $r['nachname'], 'info' => 'Onboarding · ' . $r['status'], 'link' => APP_URL . '/dashboard/admin/onboarding.php?id=' . $r['id']]);
    }

    // Kurse & Events
    $add('kurse', 'Kurse', $abfrage("SELECT id, titel, start_datum, ort, status FROM kurse WHERE organization_id = ? AND art <> 'event' AND status <> 'abgesagt'
            AND (titel LIKE ?$esc OR ort LIKE ?$esc OR sportart LIKE ?$esc) ORDER BY start_datum DESC LIMIT $n", [$org, $like, $like, $like]),
        fn($r) => ['titel' => $r['titel'], 'info' => date('d.m.Y', strtotime($r['start_datum'])) . ($r['ort'] ? ' · ' . $r['ort'] : ''), 'link' => APP_URL . '/dashboard/kurs-detail.php?id=' . $r['id']],
        APP_URL . '/dashboard/kurse.php?suche=' . urlencode($q));
    if (darf('events.anzeigen')) {
        $add('events', 'Events', $abfrage("SELECT id, titel, start_datum, ort FROM kurse WHERE organization_id = ? AND art = 'event' AND (titel LIKE ?$esc OR ort LIKE ?$esc) ORDER BY start_datum DESC LIMIT $n", [$org, $like, $like]),
            fn($r) => ['titel' => $r['titel'], 'info' => date('d.m.Y', strtotime($r['start_datum'])) . ($r['ort'] ? ' · ' . $r['ort'] : ''), 'link' => APP_URL . '/dashboard/events.php?id=' . $r['id']]);
    }

    // Projekte & Aufgaben
    if (isTrainer() || darf('projekte.anzeigen')) {
        $alle = darf('projekte.anzeigen');
        $add('projekte', 'Projekte', $abfrage("SELECT p.id, p.name, p.status, p.gemeinde FROM projekte p WHERE p.organization_id = ? AND (p.name LIKE ?$esc OR p.gemeinde LIKE ?$esc OR p.beschreibung LIKE ?$esc)"
                . ($alle ? '' : " AND (p.leitung_id = $me OR EXISTS (SELECT 1 FROM projekt_team t WHERE t.projekt_id = p.id AND t.user_id = $me))") . " ORDER BY p.status = 'archiviert', p.name LIMIT $n", [$org, $like, $like, $like]),
            fn($r) => ['titel' => $r['name'], 'info' => (PROJEKT_STATUS[$r['status']]['label'] ?? $r['status']) . ($r['gemeinde'] ? ' · ' . $r['gemeinde'] : ''), 'link' => APP_URL . '/dashboard/projekt.php?id=' . $r['id']]);
        $alle = darf('aufgaben.anzeigen');
        $add('aufgaben', 'Aufgaben', $abfrage("SELECT a.id, a.titel, a.status, a.deadline FROM aufgaben a WHERE a.organization_id = ? AND (a.titel LIKE ?$esc OR a.beschreibung LIKE ?$esc)"
                . ($alle ? '' : " AND (a.verantwortlich_id = $me OR a.erstellt_von = $me)") . " ORDER BY a.status = 'erledigt', a.deadline LIMIT $n", [$org, $like, $like]),
            fn($r) => ['titel' => $r['titel'], 'info' => (AUFGABE_STATUS[$r['status']]['label'] ?? $r['status']) . ($r['deadline'] ? ' · bis ' . date('d.m.Y', strtotime($r['deadline'])) : ''), 'link' => APP_URL . '/dashboard/aufgaben.php?id=' . $r['id']]);
    }

    // Organisation
    if (darf('partner.anzeigen')) {
        $add('partner', 'Partner', $abfrage("SELECT id, name, kategorie, ort, ansprechpartner FROM partner_organisationen WHERE organization_id = ? AND (name LIKE ?$esc OR ansprechpartner LIKE ?$esc OR ort LIKE ?$esc OR email LIKE ?$esc) ORDER BY name LIMIT $n", [$org, $like, $like, $like, $like]),
            fn($r) => ['titel' => $r['name'], 'info' => implode(' · ', array_filter([PARTNER_KATEGORIEN[$r['kategorie']] ?? $r['kategorie'], $r['ansprechpartner'], $r['ort']])), 'link' => APP_URL . '/dashboard/admin/partner.php?id=' . $r['id']]);
    }
    if (isAdmin()) {
        $add('kooperationen', 'Gemeinde-Kooperationen', $abfrage("SELECT id, gemeinde_name, status FROM kooperationen WHERE organization_id = ? AND (gemeinde_name LIKE ?$esc OR buergermeister LIKE ?$esc OR gemeinde_ansprechpartner_name LIKE ?$esc) ORDER BY gemeinde_name LIMIT $n", [$org, $like, $like, $like]),
            fn($r) => ['titel' => $r['gemeinde_name'], 'info' => 'Kooperation · ' . $r['status'], 'link' => APP_URL . '/dashboard/admin/kooperation-detail.php?id=' . $r['id']]);
    }
    if (darf('vertraege.anzeigen')) {
        $add('vertraege', 'Verträge', $abfrage("SELECT id, titel, status, ende FROM vertraege WHERE organization_id = ? AND (titel LIKE ?$esc OR notiz LIKE ?$esc) ORDER BY titel LIMIT $n", [$org, $like, $like]),
            fn($r) => ['titel' => $r['titel'], 'info' => ucfirst($r['status']) . ($r['ende'] ? ' · bis ' . date('d.m.Y', strtotime($r['ende'])) : ''), 'link' => APP_URL . '/dashboard/admin/vertraege.php?id=' . $r['id']]);
    }
    if (darf('ressourcen.anzeigen')) {
        $add('ressourcen', 'Ressourcen', $abfrage("SELECT id, name, standort, anzahl FROM ressourcen WHERE organization_id = ? AND (name LIKE ?$esc OR standort LIKE ?$esc) ORDER BY name LIMIT $n", [$org, $like, $like]),
            fn($r) => ['titel' => $r['name'], 'info' => $r['anzahl'] . ' Stk.' . ($r['standort'] ? ' · ' . $r['standort'] : ''), 'link' => APP_URL . '/dashboard/ressourcen.php?id=' . $r['id']]);
    }

    // Finanzen & Förderungen
    if (darf('rechnungen.anzeigen')) {
        $add('rechnungen', 'Rechnungen', $abfrage("SELECT id, nummer, empf_name, betrag_brutto, status, typ FROM rechnungen WHERE organization_id = ? AND (nummer LIKE ?$esc OR empf_name LIKE ?$esc OR empf_email LIKE ?$esc) ORDER BY id DESC LIMIT $n", [$org, $like, $like, $like]),
            fn($r) => ['titel' => ($r['nummer'] ?: 'Entwurf') . ' · ' . $r['empf_name'], 'info' => ($r['typ'] === 'storno' ? 'Storno · ' : '') . number_format((float)$r['betrag_brutto'], 2, ',', '.') . ' € · ' . $r['status'], 'link' => APP_URL . '/dashboard/admin/rechnungen.php?id=' . $r['id']]);
    }
    if (darf('finanzen.anzeigen')) {
        $add('buchungen', 'Buchungen', $abfrage("SELECT id, datum, art, betrag, beschreibung, belegnummer FROM buchungen WHERE organization_id = ? AND (beschreibung LIKE ?$esc OR belegnummer LIKE ?$esc) ORDER BY datum DESC LIMIT $n", [$org, $like, $like]),
            fn($r) => ['titel' => $r['beschreibung'] ?: ($r['belegnummer'] ?: 'Buchung'), 'info' => date('d.m.Y', strtotime($r['datum'])) . ' · ' . ($r['art'] === 'einnahme' ? '+' : '−') . number_format((float)$r['betrag'], 2, ',', '.') . ' €',
                        'link' => APP_URL . '/dashboard/admin/finanzen.php?jahr=' . substr($r['datum'], 0, 4) . '#journal']);
    }
    if (darf('foerderungen.anzeigen')) {
        $add('foerderungen', 'Förderungen', $abfrage("SELECT id, titel, foerderstelle, status FROM foerderungen WHERE organization_id = ? AND (titel LIKE ?$esc OR foerderstelle LIKE ?$esc OR foerderprogramm LIKE ?$esc) ORDER BY created_at DESC LIMIT $n", [$org, $like, $like, $like]),
            fn($r) => ['titel' => $r['titel'], 'info' => implode(' · ', array_filter([$r['foerderstelle'], FOERDER_STATUS[$r['status']]['label'] ?? $r['status']])), 'link' => APP_URL . '/dashboard/admin/foerderung-detail.php?id=' . $r['id']]);
    }

    // Dokumente (Sichtbarkeit wie auf der Dokumentenseite)
    $sicht = isAdmin() ? '' : (isTrainer() ? " AND sichtbar_fuer IN ('alle','mitglieder','trainer')" : " AND sichtbar_fuer IN ('alle','mitglieder')");
    $add('dokumente', 'Dokumente', $abfrage("SELECT id, titel, kategorie, created_at FROM dokumente WHERE organization_id = ? AND mitglied_id IS NULL$sicht AND (titel LIKE ?$esc OR beschreibung LIKE ?$esc) ORDER BY created_at DESC LIMIT $n", [$org, $like, $like]),
        fn($r) => ['titel' => $r['titel'], 'info' => ucfirst((string)$r['kategorie']) . ' · ' . date('d.m.Y', strtotime($r['created_at'])), 'link' => APP_URL . '/dashboard/dokumente.php?suche=' . urlencode($r['titel'])],
        APP_URL . '/dashboard/dokumente.php?suche=' . urlencode($q));

    return $erg;
}

/** Schnellaktionen für „+ Neu“ (nur mit Berechtigung). */
function schnellaktionen(): array
{
    $a = [];
    if (isTrainer())                      $a[] = ['Kurs', '/dashboard/kurs-erstellen.php'];
    if (darf('events.bearbeiten'))        $a[] = ['Event', '/dashboard/events.php?neu=1'];
    if (darfEines('kalender.erstellen', 'kalender.bearbeiten') || isTrainer()) $a[] = ['Einheit', '/dashboard/einheit-planen.php'];
    if (darf('projekte.erstellen'))       $a[] = ['Projekt', '/dashboard/projekte.php#neu'];
    if (isTrainer() || darf('aufgaben.erstellen')) $a[] = ['Aufgabe', '/dashboard/aufgaben.php#neu'];
    if (darf('rechnungen.bearbeiten'))    $a[] = ['Rechnung', '/dashboard/admin/rechnungen.php?neu=1'];
    if (darf('finanzen.bearbeiten'))      $a[] = ['Buchung', '/dashboard/admin/finanzen.php#buchung-neu'];
    if (darf('partner.erstellen'))        $a[] = ['Partner', '/dashboard/admin/partner.php?neu=1'];
    if (darf('foerderungen.bearbeiten'))  $a[] = ['Förderung', '/dashboard/admin/foerderung-erstellen.php'];
    if (darf('kommunikation.senden'))     $a[] = ['Nachricht', '/dashboard/kommunikation.php'];
    if (darf('onboarding.bearbeiten'))    $a[] = ['Trainer-Onboarding', '/dashboard/admin/onboarding.php?neu=1'];
    return $a;
}
