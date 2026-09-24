<?php
/**
 * Athletikclub Steiermark – Trainings- und Ernährungspläne
 * Gemeinsame Labels, Gesundheits-Checks, Zugriffsprüfung und Ernährungsberechnung.
 *
 * Fachliche Grundlagen:
 *  - Belastungsnormative je Trainingsziel nach gängigen Empfehlungen der Trainingslehre (ACSM/NSCA)
 *  - Gesundheitsfragen angelehnt an den PAR-Q (Physical Activity Readiness Questionnaire)
 *  - Grundumsatz nach Mifflin-St Jeor, Gesamtumsatz über PAL-Faktor
 *  - Proteinzufuhr nach DGE-Positionspapier „Proteinzufuhr im Sport“ (2020):
 *    0,8 g/kg bei bis zu 5 h Sport/Woche, 1,2–2,0 g/kg darüber
 *  - Rechtlicher Rahmen Österreich: Ernährungstraining nur für gesunde Personen ohne
 *    Krankheit/Krankheitsgefährdung; Ernährungstherapie ist Diätolog:innen/Ärzt:innen vorbehalten.
 */

const TP_ZIELE = [
    'allgemeine_fitness' => ['label' => 'Allgemeine Fitness', 'empfehlung' => '2–3 Sätze à 10–15 Wdh., RPE 6–7, 60–90 s Pause; Ganzkörper 2–3× pro Woche.'],
    'kraftausdauer'      => ['label' => 'Kraftausdauer',      'empfehlung' => '2–3 Sätze à 15–25 Wdh., < 65 % 1RM bzw. RPE 6–7, 30–60 s Pause.'],
    'hypertrophie'       => ['label' => 'Muskelaufbau',       'empfehlung' => '3–4 Sätze à 6–12 Wdh., 65–80 % 1RM bzw. RPE 7–9, 60–120 s Pause; 10–20 Sätze je Muskelgruppe und Woche.'],
    'kraft'              => ['label' => 'Maximalkraft',       'empfehlung' => '3–5 Sätze à 3–6 Wdh., 80–90 % 1RM bzw. RPE 8–9, 2–5 min Pause; saubere Technik vor Last.'],
    'skill'              => ['label' => 'Calisthenics-Skills', 'empfehlung' => 'Skill-Arbeit zu Beginn der Einheit, kurze Sätze (3–8 Wdh. bzw. 5–20 s Halten), 2–3 min Pause, Progressionen statt Last.'],
    'ausdauer'           => ['label' => 'Ausdauer',           'empfehlung' => 'Überwiegend Grundlagenausdauer (Sprechtempo), 1–2 Intervalleinheiten pro Woche; Umfang wöchentlich max. ca. 10 % steigern.'],
    'abnehmen'           => ['label' => 'Gewichtsreduktion',  'empfehlung' => 'Krafttraining zum Muskelerhalt (2–3×/Woche, 8–15 Wdh.) plus Ausdauer/Alltagsbewegung; Ernährung als Hauptfaktor.'],
    'mobilitaet'         => ['label' => 'Beweglichkeit',      'empfehlung' => 'Dynamisch vor, statisch nach dem Training; Dehnpositionen 30–60 s halten, 2–3 Wiederholungen, möglichst täglich.'],
];

const TP_NIVEAUS = [
    'einsteiger'      => 'Einsteiger:in',
    'fortgeschritten' => 'Fortgeschritten',
    'profi'           => 'Leistungsorientiert',
];

const PLAN_STATUS = [
    'entwurf'       => ['label' => 'Entwurf',       'class' => 'badge-gray'],
    'aktiv'         => ['label' => 'Aktiv',         'class' => 'badge-success'],
    'abgeschlossen' => ['label' => 'Abgeschlossen', 'class' => 'badge-navy'],
];

const WOCHENTAGE = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

const UEBUNG_KATEGORIEN = [
    'aufwaermen'   => 'Aufwärmen',
    'kraft'        => 'Kraft',
    'calisthenics' => 'Calisthenics',
    'mobilitaet'   => 'Mobilität',
    'ausdauer'     => 'Ausdauer',
    'koordination' => 'Koordination',
    'cooldown'     => 'Cool-down',
    'sonstiges'    => 'Sonstiges',
];

/**
 * Gesundheitsfragen vor dem Trainingsplan (angelehnt an den PAR-Q).
 * Abgehakt = mit „Nein“ beantwortet. Offene Fragen → ärztliche Abklärung empfehlen.
 */
const TP_GESUNDHEIT = [
    'herz'          => 'Keine Herzerkrankung bzw. kein ärztlicher Rat, Sport nur unter Aufsicht zu betreiben',
    'brust_belast'  => 'Keine Brustschmerzen bei körperlicher Belastung',
    'brust_ruhe'    => 'Keine Brustschmerzen in Ruhe im letzten Monat',
    'schwindel'     => 'Kein Gleichgewichtsverlust durch Schwindel und keine Bewusstlosigkeit',
    'gelenke'       => 'Keine Knochen- oder Gelenkprobleme, die sich durch Bewegung verschlechtern',
    'medikamente'   => 'Keine Medikamente gegen Bluthochdruck oder Herzbeschwerden',
    'sonstiges'     => 'Kein sonstiger Grund gegen körperliche Aktivität (z.B. Operation, Schwangerschaft, akute Verletzung)',
];

/**
 * Ausschlusskriterien für Ernährungspläne. ALLE müssen bestätigt sein – sonst gehört die
 * Person in diätologische bzw. ärztliche Betreuung (Ernährungstherapie).
 */
const EP_GESUNDHEIT = [
    'keine_erkrankung'  => 'Keine Erkrankung mit Ernährungsbezug (z.B. Diabetes, Stoffwechsel-, Nieren-, Leber-, Herz-Kreislauf-, Magen-Darm-Erkrankung, Bluthochdruck)',
    'keine_allergie'    => 'Keine Nahrungsmittelallergie oder -unverträglichkeit (z.B. Laktose, Gluten, Fruktose, Histamin)',
    'keine_essstoerung' => 'Keine bestehende oder frühere Essstörung',
    'nicht_schwanger'   => 'Nicht schwanger und nicht stillend',
    'keine_medikamente' => 'Keine Medikamente, die eine besondere Ernährung erfordern',
    'volljaehrig'       => 'Volljährig (für Kinder und Jugendliche nur allgemeine Empfehlungen, keine Energiereduktion)',
];

const EP_ZIELE = [
    'abnehmen' => ['label' => 'Gewicht reduzieren', 'text' => 'moderates Energiedefizit (max. 500 kcal bzw. 20 %), nicht unter den Grundumsatz'],
    'halten'   => ['label' => 'Gewicht halten',     'text' => 'Energiezufuhr entspricht dem Gesamtumsatz'],
    'aufbauen' => ['label' => 'Muskelaufbau',       'text' => 'leichter Energieüberschuss von ca. 300 kcal'],
    'leistung' => ['label' => 'Leistung / Training', 'text' => 'Energiebedarf decken, Kohlenhydrate für die Trainingsleistung betonen'],
];

const EP_PAL = [
    '1.2'   => '1,2 – überwiegend sitzend, kaum Bewegung',
    '1.375' => '1,375 – leicht aktiv (1–2× Sport pro Woche)',
    '1.55'  => '1,55 – mäßig aktiv (3–4× Sport pro Woche)',
    '1.725' => '1,725 – sehr aktiv (5–6× Sport pro Woche)',
    '1.9'   => '1,9 – extrem aktiv (täglich intensiv oder körperliche Arbeit)',
];

const EP_TAGTYPEN = [
    'alle'     => 'Jeder Tag',
    'training' => 'Trainingstag',
    'ruhe'     => 'Ruhetag',
];

const EP_HINWEIS = 'Diese Ernährungsempfehlungen richten sich ausschließlich an gesunde Erwachsene ohne Krankheit oder Krankheitsgefährdung und dienen als Ernährungstraining im Rahmen des Vereinssports. Sie ersetzen keine Ernährungsberatung oder -therapie durch Diätolog:innen oder Ärzt:innen. Bei Erkrankungen, Allergien, Unverträglichkeiten, Schwangerschaft oder Essstörungen wende dich bitte an eine Diätologin bzw. einen Diätologen oder deine Ärztin bzw. deinen Arzt.';
const TP_HINWEIS = 'Trainiere nur beschwerdefrei. Bei Schmerzen, Schwindel oder Atemnot das Training sofort abbrechen und ärztlich abklären lassen.';

/** JSON-Liste abgehakter Punkte lesen. */
function planChecks(?string $json): array
{
    $liste = json_decode((string)$json, true);
    return is_array($liste) ? $liste : [];
}

/** Sind alle Punkte einer Checkliste bestätigt? */
function planChecksVollstaendig(?string $json, array $katalog): bool
{
    return count(array_intersect(array_keys($katalog), planChecks($json))) === count($katalog);
}

/**
 * Lädt einen Plan (Tabelle trainingsplaene oder ernaehrungsplaene) und prüft den Zugriff:
 * Trainer:innen/Admins sehen alle Pläne des Vereins, Mitglieder nur eigene, nicht im Entwurf.
 */
function planLaden(PDO $db, string $tabelle, int $id): ?array
{
    if (!in_array($tabelle, ['trainingsplaene', 'ernaehrungsplaene'], true)) return null;
    $stmt = $db->prepare(
        "SELECT p.*, m.vorname AS m_vorname, m.nachname AS m_nachname, t.vorname AS t_vorname, t.nachname AS t_nachname
         FROM {$tabelle} p
         LEFT JOIN users m ON m.id = p.mitglied_id
         JOIN users t ON t.id = p.trainer_id
         WHERE p.id = ? AND p.organization_id = ? LIMIT 1"
    );
    $stmt->execute([$id, currentOrgId()]);
    $plan = $stmt->fetch();
    if (!$plan) return null;
    if (isTrainer()) return $plan;
    if ((int)$plan['mitglied_id'] === (int)getCurrentUserId() && $plan['status'] !== 'entwurf') return $plan;
    return null;
}

/** Kopiert Einheiten und Übungen eines Trainingsplans in einen anderen Plan. */
function trainingsplanKopieren(PDO $db, int $von_id, int $nach_id): void
{
    $stmt = $db->prepare('SELECT * FROM trainingsplan_einheiten WHERE plan_id = ? ORDER BY sortierung, id');
    $stmt->execute([$von_id]);
    foreach ($stmt->fetchAll() as $e) {
        $db->prepare('INSERT INTO trainingsplan_einheiten (plan_id, name, wochentag, aufwaermen, notiz, sortierung) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$nach_id, $e['name'], $e['wochentag'], $e['aufwaermen'], $e['notiz'], $e['sortierung']]);
        $db->prepare(
            'INSERT INTO trainingsplan_uebungen (einheit_id, uebung_id, uebung_name, saetze, wiederholungen, last, rpe, tempo, pause_sek, notiz, sortierung)
             SELECT ?, uebung_id, uebung_name, saetze, wiederholungen, last, rpe, tempo, pause_sek, notiz, sortierung FROM trainingsplan_uebungen WHERE einheit_id = ?'
        )->execute([(int)$db->lastInsertId(), $e['id']]);
    }
}

/** Kopiert die Mahlzeiten eines Ernährungsplans in einen anderen Plan. */
function ernaehrungsplanKopieren(PDO $db, int $von_id, int $nach_id): void
{
    $db->prepare(
        'INSERT INTO ernaehrungsplan_mahlzeiten (plan_id, tagtyp, mahlzeit, uhrzeit, inhalt, kcal, protein_g, kh_g, fett_g, sortierung)
         SELECT ?, tagtyp, mahlzeit, uhrzeit, inhalt, kcal, protein_g, kh_g, fett_g, sortierung FROM ernaehrungsplan_mahlzeiten WHERE plan_id = ?'
    )->execute([$nach_id, $von_id]);
}

/** Name des Mitglieds oder „Vorlage“. */
function planFuer(array $plan): string
{
    return $plan['mitglied_id'] ? trim($plan['m_vorname'] . ' ' . $plan['m_nachname']) : 'Vorlage';
}

/** Alter aus dem Geburtsdatum. */
function alterAus(?string $geburtsdatum): ?int
{
    if (!$geburtsdatum || !strtotime($geburtsdatum)) return null;
    return (int)(new DateTime($geburtsdatum))->diff(new DateTime())->y;
}

/**
 * Energie- und Nährstoff-Richtwerte eines Ernährungsplans.
 * @return array{vollstaendig: bool, grundumsatz?: int, gesamtumsatz?: int, kcal?: int, protein_g_kg?: float,
 *               protein_g?: int, fett_g?: int, kh_g?: int, wasser_l?: float, bmi?: float, hinweise: list<array{typ: string, text: string}>}
 */
function epBerechnung(array $p): array
{
    $h = [];
    if (!$p['geschlecht'] || !$p['alter_jahre'] || !$p['groesse_cm'] || !$p['gewicht_kg']) {
        return ['vollstaendig' => false, 'hinweise' => [['typ' => 'info', 'text' => 'Für die Berechnung Geschlecht, Alter, Größe und Gewicht eintragen.']]];
    }
    $gewicht = (float)$p['gewicht_kg'];
    $groesse = (int)$p['groesse_cm'];
    $alter   = (int)$p['alter_jahre'];

    // Grundumsatz nach Mifflin-St Jeor, Gesamtumsatz über PAL
    $gu  = 10 * $gewicht + 6.25 * $groesse - 5 * $alter + ($p['geschlecht'] === 'm' ? 5 : -161);
    $gsu = $gu * (float)$p['pal'];
    $bmi = $gewicht / (($groesse / 100) ** 2);

    $kcal = match ($p['ziel']) {
        'abnehmen' => $gsu - min(500, 0.2 * $gsu),
        'aufbauen' => $gsu + 300,
        default    => $gsu,
    };
    if ($p['ziel'] === 'abnehmen' && $kcal < $gu) {
        $kcal = $gu;
        $h[] = ['typ' => 'info', 'text' => 'Das Energiedefizit wurde auf den Grundumsatz begrenzt – darunter wird nicht geplant.'];
    }
    if ($p['kalorien_ziel']) {
        $kcal = (int)$p['kalorien_ziel'];
        if ($kcal < $gu) $h[] = ['typ' => 'fehler', 'text' => 'Die gewählte Energiezufuhr liegt unter dem Grundumsatz (' . round($gu) . ' kcal) – so niedrige Zufuhren gehören in ärztliche/diätologische Begleitung.'];
    }

    // Protein nach DGE: bis 5 h Sport/Woche 0,8 g/kg, darüber 1,2–2,0 g/kg je nach Ziel
    $empf_protein = (float)$p['sport_stunden_woche'] > 5
        ? (in_array($p['ziel'], ['aufbauen', 'abnehmen'], true) ? 1.8 : 1.4)
        : 0.8;
    $protein_g_kg = $p['protein_g_kg'] !== null && $p['protein_g_kg'] !== '' ? (float)$p['protein_g_kg'] : $empf_protein;
    if ($protein_g_kg > 2.0) $h[] = ['typ' => 'warnung', 'text' => 'Mehr als 2,0 g Protein je kg Körpergewicht bringt laut DGE keinen zusätzlichen Nutzen.'];
    if ((float)$p['sport_stunden_woche'] <= 5 && $protein_g_kg > 1.2) {
        $h[] = ['typ' => 'info', 'text' => 'Bei bis zu 5 Stunden Sport pro Woche empfiehlt die DGE 0,8 g Protein/kg – ein höherer Wert ist nicht nötig.'];
    }

    $protein_g = $protein_g_kg * $gewicht;
    $fett_g    = $kcal * (int)$p['fett_prozent'] / 100 / 9;
    $kh_g      = max(0, ($kcal - $protein_g * 4 - $fett_g * 9) / 4);
    if ((int)$p['fett_prozent'] < 20) $h[] = ['typ' => 'warnung', 'text' => 'Unter 20 % Fettanteil ist die Versorgung mit essenziellen Fettsäuren und fettlöslichen Vitaminen gefährdet.'];
    if ($p['ziel'] === 'leistung' && $kh_g / $gewicht < 3) $h[] = ['typ' => 'info', 'text' => 'Für trainingsintensive Phasen sind meist mindestens 3–5 g Kohlenhydrate/kg sinnvoll.'];

    // BMI-Grenzen: Untergewicht und Adipositas nicht im Ernährungstraining
    if ($bmi < 18.5) {
        $h[] = ['typ' => 'fehler', 'text' => 'BMI ' . number_format($bmi, 1, ',', '') . ' (Untergewicht) – keine Energiereduktion; bitte ärztlich bzw. diätologisch abklären lassen.'];
    } elseif ($bmi >= 30) {
        $h[] = ['typ' => 'fehler', 'text' => 'BMI ' . number_format($bmi, 1, ',', '') . ' – Adipositas gilt als Erkrankung; die Ernährungsbetreuung gehört zu Diätolog:innen bzw. Ärzt:innen. Das Training kann der Verein weiter begleiten.'];
    }
    if ($alter < 18) $h[] = ['typ' => 'fehler', 'text' => 'Für Kinder und Jugendliche keine individuellen Energie-/Makrovorgaben – nur allgemeine Empfehlungen (z.B. nach der Österreichischen Ernährungspyramide).'];

    return [
        'vollstaendig' => true,
        'grundumsatz'  => (int)round($gu),
        'gesamtumsatz' => (int)round($gsu),
        'kcal'         => (int)round($kcal),
        'protein_g_kg' => $protein_g_kg,
        'protein_g'    => (int)round($protein_g),
        'fett_g'       => (int)round($fett_g),
        'kh_g'         => (int)round($kh_g),
        'wasser_l'     => round($gewicht * 0.035 + (float)$p['sport_stunden_woche'] / 7 * 0.75, 1),
        'bmi'          => round($bmi, 1),
        'hinweise'     => $h,
    ];
}

/** Darf der Ernährungsplan aktiv geschaltet bzw. mit Mahlzeiten befüllt werden? */
function epFreigegeben(array $plan, ?array $berechnung = null): bool
{
    if (!planChecksVollstaendig($plan['gesundheit_checks'] ?? null, EP_GESUNDHEIT)) return false;
    $berechnung ??= epBerechnung($plan);
    foreach ($berechnung['hinweise'] as $h) if ($h['typ'] === 'fehler') return false;
    return true;
}
