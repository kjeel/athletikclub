<?php
/**
 * Athletikclub Steiermark – TBE-Gesamtkonzept (Tägliche Bewegungseinheit)
 * Gemeinsame Labels, Fördersätze und Berechnungen für Übersicht, Detailseite und Bericht.
 *
 * Quelle (Stand Schuljahr 2026/27): https://sportunion.at/stmk/taegliche-bewegungseinheit/
 *  - TBE Fix (Säule 2): bis € 1.800,– pro fixer wöchentlicher Bewegungscoach-Stunde (Sep.–Juni)
 *  - TBE Flex (Säule 3): bis € 200,– pro Paket à 5 Einheiten
 * Ändern sich die Sätze, nur hier anpassen.
 */

const TBE_STATUS = [
    'entwurf'       => ['label' => 'Entwurf',          'class' => 'badge-gray'],
    'eingereicht'   => ['label' => 'Eingereicht',      'class' => 'badge-warning'],
    'bewilligt'     => ['label' => 'Budget bewilligt', 'class' => 'badge-success'],
    'abgeschlossen' => ['label' => 'Abgeschlossen',    'class' => 'badge-navy'],
];

const TBE_EINRICHTUNGSTYPEN = [
    'kindergarten' => 'Kindergarten',
    'volksschule'  => 'Volksschule',
    'mittelschule' => 'Mittelschule / Sek I',
    'sonstige'     => 'Sonstige (z.B. ASO)',
];

const TBE_FIX_SATZ         = 1800; // € je fixer wöchentlicher Bewegungscoach-Stunde und Schuljahr
const TBE_FLEX_SATZ        = 200;  // € je Paket
const TBE_FLEX_PAKET       = 5;    // Einheiten je Paket (Mindestpaket)
const TBE_MINDESTBETEILIGUNG = 0.5; // mind. 50 % der Klassen/Gruppen

const TBE_MODELLE = [
    'fix' => [
        'label' => 'TBE Fix – Bewegungscoach-Stunden', 'kurz' => 'Fix', 'saeule' => 'Säule 2', 'class' => 'badge-navy',
        'text'  => 'Fix im Stundenplan verankerte, wöchentliche Ganzjahresstunde (September–Juni). Der Bewegungscoach hält die Einheit allein und trägt die Aufsicht. Förderung bis € 1.800,– pro wöchentlicher Stunde.',
    ],
    'flex' => [
        'label' => 'TBE Flex – flexible Bewegungseinheiten', 'kurz' => 'Flex', 'saeule' => 'Säule 3', 'class' => 'badge-gold',
        'text'  => 'Pakete von mind. 5 Einheiten, frei auf Klassen/Gruppen aufteilbar, in der Unterrichtszeit; die Pädagog:in ist anwesend und trägt die Aufsicht. Auch Bewegungsfest, Ferienbetreuung und je eine Info-Einheit für Pädagog:innen/Eltern. Förderung bis € 200,– pro 5er-Paket.',
    ],
    'flex_s' => [
        'label' => 'TBE Flex-S – Schwimmeinheiten', 'kurz' => 'Flex-S', 'saeule' => 'Säule 3', 'class' => 'badge-info',
        'text'  => 'Flexible Schwimmeinheiten zur Förderung der Wasserkompetenz, in Paketen von mind. 5 Einheiten. Übungsleiter:innen brauchen zusätzlich den Helferschein Schwimmen. Förderung wie Flex (€ 200,– pro 5er-Paket).',
    ],
];

const TBE_LINKS = [
    'TBE-Seite der SPORTUNION Steiermark' => 'https://sportunion.at/stmk/taegliche-bewegungseinheit/',
    'TBE-Datenbank 2026/27 (Maßnahmen erfassen)' => 'https://verwaltung.bewegungseinheit.gv.at/2627/',
    'Kooperationsvereinbarung Kindergarten 2026/27' => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/Kooperationsvereinbarung_TBE_26-27_KiGa_final_ausfuellbar.pdf',
    'Kooperationsvereinbarung Volksschule 2026/27' => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/Kooperationsvereinbarung_TBE_26-27_VS_final_ausfuellbar.pdf',
    'Kooperationsvereinbarung Sek I 2026/27' => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/Kooperationsvereinbarung_TBE_2026-27_Sek-I.pdf',
    'Tutorial für Vereine / Bewegungscoaches' => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/TBE_Tutorial_BC-UeL-Vereine_2024-26.pdf',
    'Info-Portal Tägliche Bewegungseinheit' => 'https://www.bewegungseinheit.gv.at/',
];

const TBE_KONTAKT = ['name' => 'Jürgen Mayrhofer (Landeskoordinator TBE)', 'email' => 'juergen.mayrhofer@sportunion-steiermark.at', 'tel' => '+43 316 3244 30 74'];

function tbeIstFlex(array $projekt): bool
{
    return in_array($projekt['modell'] ?? 'fix', ['flex', 'flex_s'], true);
}

/** Wöchentliche Bewegungscoach-Stunden (nur Fix): Klassen × Stunden pro Woche. */
function tbeFixStundenWoche(array $projekt): float
{
    return tbeIstFlex($projekt) ? 0.0 : (int)$projekt['anzahl_gruppen'] * (float)$projekt['einheiten_pro_woche'];
}

/** Geplante Einheiten gesamt: Fix = Klassen × Stunden/Woche × Wochen, Flex = geplante Einheiten. */
function tbeEinheiten(array $projekt): float
{
    if (tbeIstFlex($projekt)) return (float)($projekt['flex_einheiten'] ?? 0);
    return tbeFixStundenWoche($projekt) * (int)$projekt['anzahl_wochen'];
}

/** Geförderte Flex-Pakete: nur volle 5er-Pakete (vorsichtige Auslegung, Rest wird im Check gemeldet). */
function tbeFlexPakete(array $projekt): int
{
    return tbeIstFlex($projekt) ? intdiv((int)tbeEinheiten($projekt), TBE_FLEX_PAKET) : 0;
}

/** Geplante Trainer:innen-Stunden gesamt (Einheiten × Dauer). */
function tbeStunden(array $projekt): float
{
    return tbeEinheiten($projekt) * (int)$projekt['dauer_minuten'] / 60;
}

/** Förderrahmen nach den offiziellen Sätzen (Fix: € 1.800,– je Wochenstunde, Flex: € 200,– je Paket). */
function tbeFoerderung(array $projekt): string
{
    $betrag = tbeIstFlex($projekt) ? tbeFlexPakete($projekt) * TBE_FLEX_SATZ : tbeFixStundenWoche($projekt) * TBE_FIX_SATZ;
    return moneyRound(round($betrag, 2));
}

/** Geschätzte Kosten eines Projekts (Stunden × Stundensatz), null ohne Stundensatz. */
function tbeKosten(array $projekt, $stundensatz): ?string
{
    if ($stundensatz === null || $stundensatz === '') return null;
    return moneyRound(round(tbeStunden($projekt) * (float)$stundensatz, 2));
}

/**
 * Prüft die Teilnahmevoraussetzungen eines Projekts.
 * @return list<array{typ: string, text: string}> typ: fehler | warnung | info
 */
function tbePruefeProjekt(array $konzept, array $p): array
{
    $m    = [];
    $flex = tbeIstFlex($p);

    if (!$flex && (float)$p['einheiten_pro_woche'] != floor((float)$p['einheiten_pro_woche'])) {
        $m[] = ['typ' => 'warnung', 'text' => 'Bewegungscoach-Stunden sind ganze Wochenstunden – bitte ganze Zahl eintragen.'];
    }
    if (!$flex && $p['einrichtungstyp'] === 'volksschule' && (float)$p['einheiten_pro_woche'] > 2) {
        $m[] = ['typ' => 'warnung', 'text' => 'In der Volksschule sind 1–2 zusätzliche Bewegungscoach-Stunden pro Klasse vorgesehen.'];
    }
    if (!$flex && $p['einrichtungstyp'] === 'kindergarten' && (float)$p['einheiten_pro_woche'] > 1) {
        $m[] = ['typ' => 'warnung', 'text' => 'Im Kindergarten ist 1 Bewegungscoach-Einheit pro teilnehmender Gruppe vorgesehen.'];
    }
    if ($flex && tbeEinheiten($p) < TBE_FLEX_PAKET) {
        $m[] = ['typ' => 'fehler', 'text' => 'Flex-Einheiten werden in Paketen von mindestens ' . TBE_FLEX_PAKET . ' Einheiten umgesetzt.'];
    } elseif ($flex && ($rest = (int)tbeEinheiten($p) % TBE_FLEX_PAKET) !== 0) {
        $m[] = ['typ' => 'warnung', 'text' => (int)tbeEinheiten($p) . ' Einheiten = ' . tbeFlexPakete($p) . ' volle 5er-Pakete; ' . $rest . ' Einheit' . ($rest === 1 ? '' : 'en') . ' ohne Förderung – auf volle Pakete planen oder mit der Landeskoordination abklären.'];
    }
    // Mindestbeteiligung 50 % gilt für die Bewegungscoach-Stunden (Säule 2)
    if (!$flex && !empty($p['klassen_gesamt']) && (int)$p['anzahl_gruppen'] / (int)$p['klassen_gesamt'] < TBE_MINDESTBETEILIGUNG) {
        $m[] = ['typ' => 'warnung', 'text' => 'Nur ' . (int)$p['anzahl_gruppen'] . ' von ' . (int)$p['klassen_gesamt'] . ' Klassen/Gruppen – Mindestbeteiligung 50 % (sonst Maßnahmen in Säule 1 und 3 nötig).'];
    }
    if (empty($p['chk_kooperation'])) $m[] = ['typ' => 'fehler', 'text' => 'Kooperationsvereinbarung mit der Einrichtung noch nicht unterschrieben.'];
    if (!$flex && $p['einrichtungstyp'] === 'volksschule' && empty($p['chk_schulforum'])) {
        $m[] = ['typ' => 'fehler', 'text' => 'Für stundenplanerweiternde Bewegungscoach-Stunden ist ein Beschluss im Schulforum nötig.'];
    }
    if (empty($p['chk_qualifikation'])) {
        $m[] = ['typ' => 'warnung', 'text' => $p['modell'] === 'flex_s'
            ? 'Qualifikation prüfen: ÜL-Ausbildung Kinder/Jugend (o. gleichwertig) plus Helferschein Schwimmen.'
            : ($flex ? 'Qualifikation prüfen: ÜL-Ausbildung Kinder/Jugend oder allgemeine ÜL-Ausbildung + Kinder-Fortbildung (mind. 8 EH) o. höherwertig.'
                     : 'Qualifikation des Bewegungscoachs bestätigen.')];
    }
    if (empty($p['chk_haftpflicht'])) $m[] = ['typ' => 'warnung', 'text' => 'Aufrechte Haftpflichtversicherung für den Coach bestätigen.'];
    if ($flex && empty($konzept['chk_fit_siegel'])) {
        $m[] = ['typ' => 'fehler', 'text' => 'Für Flex braucht der Verein mind. ein Kinder-/Jugendangebot mit Fit-Sport-Austria-Qualitätssiegel.'];
    }
    return $m;
}

/** Zahl im deutschen Format ohne überflüssige Nachkommastellen (z.B. 12 / 12,5 / 0,75). */
function tbeZahl(float $wert): string
{
    return rtrim(rtrim(number_format($wert, 2, ',', '.'), '0'), ',');
}
