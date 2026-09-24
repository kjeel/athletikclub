<?php
/**
 * Athletikclub Steiermark – Basisförderung SPORTUNION Steiermark
 *
 * Förderkatalog, Richtwert-Berechnung, Fristen und Plausibilitätsprüfung.
 * Quellen (Stand Förderrichtlinien 2023):
 *  - Förderrichtlinien der SPORTUNION Steiermark (Foerderrichtlinien-2023.pdf)
 *  - Finanzielle Zuschüsse der SPORTUNION Steiermark
 *  - Abrechnungsrichtlinien für Subventionen (2023_ABRECHNUNGSRICHTLINIEN_Vereine.pdf)
 * Ändern sich Sätze oder Fristen, nur hier anpassen.
 */

const SU_QUELLEN = [
    'Vereinsförderung (Übersicht)' => 'https://sportunion.at/stmk/service/vereinsdatenbank/vereinsfoerderung/',
    'Förderrichtlinien 2023'       => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/Foerderrichtlinien-2023.pdf',
    'Finanzielle Zuschüsse'        => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/Finanzielle-Zuschüsse-der-SPORTUNION-Steiermark.pdf',
    'Abrechnungsrichtlinien'       => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2023_ABRECHNUNGSRICHTLINIEN_Vereine.pdf',
    'Abrechnungsformulare'         => 'https://sportunion.at/stmk/service/downloads/#Abrechnungsformulare',
    'Online-Förderansuchen (Vereinsdatenbank)' => 'https://suvw.at/vereinsmeldung/index.php',
];

const SU_KONTAKTE = [
    ['rolle' => 'Förderungen / GF Leistungs- und Wettkampfsport', 'name' => 'Mag. Herwig Reupichler', 'email' => 'herwig.reupichler@sportunion-steiermark.at', 'tel' => '+43 316 3244 30 75'],
    ['rolle' => 'Abrechnung', 'name' => 'Ina Werni', 'email' => 'ina.werni@sportunion-steiermark.at', 'tel' => '+43 316 32 44 30 71'],
    ['rolle' => 'Landesgeschäftsstelle', 'name' => 'SPORTUNION Steiermark, Gaußgasse 3, 8010 Graz', 'email' => 'office@sportunion-steiermark.at', 'tel' => '+43 316 32 44 30'],
];

const SU_BEREICHE = [
    'infrastruktur'  => 'Infrastruktur',
    'allgemeinsport' => 'Allgemeinsport',
    'leistungssport' => 'Leistungssport',
    'sonstiges'      => 'Sonstige Zuschüsse',
];

/** Fixbeträge für abgeschlossene Fachausbildungen. */
const SU_AUSBILDUNG_SAETZE = [
    'uebungsleiter' => ['label' => 'Übungsleiter:in', 'betrag' => 100],
    'kampfrichter'  => ['label' => 'Kampfrichter:in', 'betrag' => 100],
    'instruktor'    => ['label' => 'Instruktor:in',   'betrag' => 250],
    'trainer'       => ['label' => 'Trainer:in',      'betrag' => 450],
];

/**
 * Förderkatalog.
 *  berechnung: rahmen (Grund-/Höchstbetrag), ausbildung (Fixbetrag je Abschluss),
 *              pro_person (Satz je Teilnehmer:in), fix (fester Betrag), ermessen (Finanzierungslücke)
 *  frist:      Einreichfrist im Förderjahr (TT.MM.)
 *  nachtraeglich: darf nach Durchführung beantragt werden
 *  felder:     zusätzliche Eingabefelder im Formular
 */
const SU_FOERDERARTEN = [
    'geraete' => [
        'bereich' => 'infrastruktur', 'label' => 'Gerätesubvention',
        'kurz' => 'Ankauf von beweglichen Gegenständen (Maschinen, Vorrichtungen, Sportgeräte, Trainingsutensilien), die unmittelbar der Sportausübung dienen.',
        'berechnung' => 'rahmen', 'grund' => 150, 'hoechst' => 1200, 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => [],
        'regeln' => [
            'Grundbetrag € 150,–, Höchstbetrag € 1.200,–.',
            'Ansuchen vor der Anschaffung einbringen.',
            'Geräte über € 800,– Anschaffungswert ins Anlagenverzeichnis des Vereins aufnehmen.',
            'Werden Geräte/Bekleidung an Sportler:innen weitergegeben: Weitergabe-Vermerk auf dem Beleg.',
        ],
        'nachweise' => ['Originalrechnungen auf den Verein', 'Zahlungsnachweise', 'ggf. Anlagenverzeichnis (ab € 800,– je Gerät)'],
        'beispiel' => 'z.B. Calisthenics-Stangen, Parallettes, Tischtennistische, Padel-Schläger, Matten, Skateboard-Obstacles',
    ],
    'bau' => [
        'bereich' => 'infrastruktur', 'label' => 'Bausubvention',
        'kurz' => 'Errichtung, Erhaltung und Verbesserung von Sportstätten und angrenzenden Einrichtungen nach den Bestimmungen des ÖISS.',
        'berechnung' => 'rahmen', 'grund' => 350, 'hoechst' => 8400, 'frist' => '31.03.', 'nachtraeglich' => false,
        'felder' => [],
        'regeln' => [
            'Grundbetrag € 350,–, Höchstbetrag € 8.400,–.',
            'Einreichung bis 31. März, Bearbeitung im Mai, Abrechnung bis 31. Oktober.',
            'Förderbar u.a.: Sport-, Tennis-, Leichtathletik- und Beachvolleyballplätze, Asphaltbahnen; bei Vereinsheimen Umkleiden, Sanitär, WC, Abstellraum, Übungsräume (z.B. Kraftkammer), Aufenthaltsraum bis 50 m²; Großsportanlagen nach individueller Prüfung.',
            'Buffetbereiche/Kantinen sind nicht förderbar.',
            'Rückerstattung anteilig (1/10 je offenem Jahr), wenn der Verein in den folgenden 9 Jahren aus der SPORTUNION ausscheidet.',
        ],
        'nachweise' => ['Originalrechnungen auf den Verein', 'Zahlungsnachweise', 'Anlagenverzeichnis', 'ggf. Pläne/Kostenvoranschläge'],
        'beispiel' => 'z.B. Calisthenics-Anlage, Skate-Fläche, Übungsraum',
    ],
    'ausbildung' => [
        'bereich' => 'allgemeinsport', 'label' => 'Aus- und Fortbildung',
        'kurz' => 'Fachausbildungen (Übungsleiter:in, Instruktor:in, Trainer:in, Kampfrichter:in) aus dem Angebot von Dach-/Fachverbänden oder der öffentlichen Hand (z.B. BSPA) für den Einsatz im Amateursport.',
        'berechnung' => 'ausbildung', 'frist' => '31.10.', 'nachtraeglich' => true,
        'felder' => ['anzahl_personen', 'ausbildungsstufe', 'wettkampf'],
        'regeln' => [
            'Fixbetrag bei erfolgreichem Abschluss: Übungsleiter:in/Kampfrichter:in € 100,–, Instruktor:in € 250,–, Trainer:in € 450,–.',
            'Optional anteilige Fahrtkosten bei besonderem Aufwand.',
            'Darf auch nach dem Abschluss beantragt werden.',
        ],
        'nachweise' => ['Kopie des Zeugnisses / Abschluss- oder Teilnahmezertifikats', 'bei Fahrtkosten: Belege bzw. PRAE'],
        'beispiel' => 'z.B. Übungsleiter:in Allgemeine Körperausbildung, Instruktor:in Calisthenics',
    ],
    'jugend' => [
        'bereich' => 'allgemeinsport', 'label' => 'Jugendarbeit / Schule & Verein',
        'kurz' => 'Maßnahmen, die über das übliche Ausmaß der Kinder- und Jugendbetreuung hinausgehen, sowie Projekte der Zusammenarbeit zwischen Schule und Sportverein.',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen'],
        'regeln' => [
            'Förderung nach freiem Ermessen der Landesleitung.',
            'Besonders gefördert: Vernetzung Schule – Verein, die die sportlichen Betätigungsfelder der Jugend verbreitert.',
        ],
        'nachweise' => ['Originalrechnungen auf den Verein', 'Zahlungsnachweise', 'Teilnehmerliste', 'ggf. PRAE/Honorarnoten für Trainer:innen'],
        'beispiel' => 'z.B. Schulkooperation Calisthenics, Ferien-Sportcamp, Nachwuchs-Schnuppertage',
    ],
    'veranstaltung' => [
        'bereich' => 'allgemeinsport', 'label' => 'Veranstaltungen',
        'kurz' => 'Durchführung größerer (höherwertiger) Veranstaltungen durch den Verein.',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'],
        'regeln' => [
            'Höhe richtet sich nach den Umständen des Einzelfalles.',
            'Für Fachverbandswettkämpfe (Meisterschaften, Cups) werden in der Regel KEINE Zuschüsse gewährt.',
        ],
        'nachweise' => ['Originalrechnungen auf den Verein', 'Zahlungsnachweise', 'Teilnehmerliste / Ergebnisliste', 'ggf. Bericht und Fotos'],
        'beispiel' => 'z.B. Calisthenics-Jam, Skate-Contest, Vereinsturnier Tischtennis/Padel',
    ],
    'jugendmannschaft' => [
        'bereich' => 'leistungssport', 'label' => 'Jugend-Mannschaftsförderung',
        'kurz' => 'Fahrt- und Aufenthaltskosten bei der Teilnahme an Nachwuchs-Bundesfinalspielen.',
        'berechnung' => 'pro_person', 'satz' => 25, 'satz_uebernachtung' => 50, 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'mit_uebernachtung', 'wettkampf'],
        'regeln' => ['€ 25,– pro Teilnehmer:in ohne Übernachtung, € 50,– mit Übernachtung.', 'Teilnehmerliste ist dem Ansuchen beizulegen.'],
        'nachweise' => ['Teilnehmerliste', 'Ergebnisliste'],
        'beispiel' => 'z.B. Nachwuchs-Bundesfinale Tischtennis',
    ],
    'fahrt_allgemein' => [
        'bereich' => 'leistungssport', 'label' => 'Fahrtkostenunterstützung Allgemeine Klasse (Einzel)',
        'kurz' => 'Zuschuss für Aktive der Allgemeinen Klasse bei Platz 1–3 bei Landesmeisterschaften bzw. Platz 1–6 bei Österreichischen Meisterschaften.',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => true,
        'felder' => ['anzahl_personen', 'wettkampf', 'platzierung'],
        'regeln' => ['Abzurechnen mit Ausgaben für Trainer:innen, Trainingslager und Fahrtkosten.', 'Darf nach der Meisterschaft beantragt werden.'],
        'nachweise' => ['Ergebnisliste', 'Belege für Trainer, Trainingslager, Fahrtkosten'],
        'beispiel' => 'z.B. Top-3 bei der Steirischen Meisterschaft',
    ],
    'fahrt_nachwuchs' => [
        'bereich' => 'leistungssport', 'label' => 'Fahrtkostenunterstützung Nachwuchsklasse (Einzel)',
        'kurz' => 'Zuschuss für Aktive der Nachwuchsklassen bei Platz 1 bei Landesmeisterschaften bzw. Platz 1–3 bei Österreichischen Meisterschaften.',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => true,
        'felder' => ['anzahl_personen', 'wettkampf', 'platzierung'],
        'regeln' => ['Abzurechnen mit Ausgaben für Trainer:innen, Trainingslager und Fahrtkosten.', 'Darf nach der Meisterschaft beantragt werden.'],
        'nachweise' => ['Ergebnisliste', 'Belege für Trainer, Trainingslager, Fahrtkosten'],
        'beispiel' => 'z.B. Landesmeistertitel U15',
    ],
    'su_meisterschaften' => [
        'bereich' => 'leistungssport', 'label' => 'SPORTUNION-Meisterschaften',
        'kurz' => 'Beschickung von SPORTUNION-Bundeswettkämpfen (Auswahl durch die Landesspartenreferent:innen).',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'],
        'regeln' => ['Übernommen werden tatsächliche Fahrtkosten (max. Tarif öffentliche Verkehrsmittel) und Nächtigung bis max. € 60,– (DZ mit Frühstück).', 'Bedarf rechtzeitig vorlegen; Modalitäten mit dem GF Leistungs- und Wettkampfsport abstimmen.'],
        'nachweise' => ['Teilnehmerliste', 'Fahrt- und Nächtigungsbelege'],
        'beispiel' => '',
    ],
    'entsendung' => [
        'bereich' => 'leistungssport', 'label' => 'Entsendung zu internationalen Wettkämpfen/Turnieren',
        'kurz' => 'Unterstützung der Entsendung zu internationalen Wettkämpfen bzw. Turnieren.',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'],
        'regeln' => ['Förderung nach freiem Ermessen.', 'Abrechenbar sind Fahrt-, Flug- und Aufenthaltskosten.'],
        'nachweise' => ['Fahrt-/Flug- und Aufenthaltsbelege', 'Teilnehmer- bzw. Ergebnisliste'],
        'beispiel' => '',
    ],
    'lehrgang' => [
        'bereich' => 'leistungssport', 'label' => 'Lehrgänge, Kadertrainings',
        'kurz' => 'Vereinsübergreifende Spartenlehrgänge oder Kadertrainings bei nachgewiesenem Bedarf und übergeordnetem Interesse.',
        'berechnung' => 'ermessen', 'frist' => '31.03.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'],
        'regeln' => ['Kostenvoranschlag durch die Landesspartenreferent:innen bis 31. März.', 'Bei verbandsübergreifenden Lehrgängen den Vorteil für SPORTUNION-Vereine darstellen.'],
        'nachweise' => ['Kostenvoranschlag', 'Teilnehmerliste', 'Belege'],
        'beispiel' => '',
    ],
    'personifiziert' => [
        'bereich' => 'leistungssport', 'label' => 'Personifizierte Sportförderung / Spitzensport',
        'kurz' => 'Maßnahmenorientierte Unterstützung von nach leistungssportlichen Kriterien trainierenden Aktiven, besonders im Nachwuchs.',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf', 'platzierung'],
        'regeln' => ['Augenmerk auf maßnahmenorientierte Förderungen (Trainings- und Wettkampfgeschehen).', 'Belege dürfen auch auf den Namen der Spitzensportlerin / des Spitzensportlers lauten (Aufenthalts- und Fahrtkosten).'],
        'nachweise' => ['Trainings-/Wettkampfplan', 'Ergebnislisten', 'Belege'],
        'beispiel' => '',
    ],
    'bundesliga' => [
        'bereich' => 'leistungssport', 'label' => 'Mannschafts-Bundesligaförderung',
        'kurz' => 'Beitrag zu erhöhten Fahrt- und Materialkosten von Mannschaften in hochrangigem Ligabetrieb (nicht Fußball, nicht Profi-Mannschaften).',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'],
        'regeln' => ['Förderung erst ab dem Kalenderjahr nach dem Aufstieg.', 'Damit sind – außer Aus-/Fortbildung und projektbezogenen Mitteln – alle übrigen Subventionsmöglichkeiten ausgeschöpft.'],
        'nachweise' => ['Nachweis der Ligazugehörigkeit', 'Fahrt- und Materialbelege'],
        'beispiel' => 'z.B. Tischtennis-Bundesliga-Mannschaft',
    ],
    'gruendung' => [
        'bereich' => 'sonstiges', 'label' => 'Gründungssubvention',
        'kurz' => 'Einmaliger Zuschuss für neue SPORTUNION-Vereine, abzurechnen mit Rechnungen für Stempel, Briefpapier, Kuverts u.Ä.',
        'berechnung' => 'fix', 'betrag' => 80, 'frist' => '31.10.', 'nachtraeglich' => true,
        'felder' => [],
        'regeln' => ['Fixbetrag € 80,– für neu gegründete Mitgliedsvereine.'],
        'nachweise' => ['Rechnungen für Stempel, Briefpapier, Kuverts u.Ä.', 'Zahlungsnachweise'],
        'beispiel' => 'z.B. Vereinsstempel, Briefpapier, Kuverts',
    ],
];

const SU_STATUS = [
    'entwurf'     => ['label' => 'Entwurf',       'class' => 'badge-gray'],
    'eingereicht' => ['label' => 'Eingereicht',   'class' => 'badge-warning'],
    'zugesagt'    => ['label' => 'Zugesagt',      'class' => 'badge-success'],
    'teilzusage'  => ['label' => 'Teilzusage',    'class' => 'badge-info'],
    'abgelehnt'   => ['label' => 'Abgelehnt',     'class' => 'badge-danger'],
    'abgerechnet' => ['label' => 'Abgerechnet',   'class' => 'badge-navy'],
];

/** Erklärungen des Vereins (Voraussetzungen laut Förderrichtlinien). */
const SU_ERKLAERUNGEN = [
    'erkl_mitglied'     => 'Der Verein ist Mitglied der SPORTUNION Steiermark und steht mit dem Verband in keinem Rechtsstreit.',
    'erkl_landesumlage' => 'Es bestehen keine offenen Verbindlichkeiten gegenüber dem Landesverband (Landesumlage, Betriebskostenbeiträge etc.).',
    'erkl_fairplay'     => 'Der Verein verpflichtet sich zur Einhaltung der Fair-Play-Regeln, der Anti-Doping-Bestimmungen und der Ehrenkodex-Erklärung der SPORTUNION Steiermark.',
    'erkl_richtlinien'  => 'Die Förderrichtlinien und Abrechnungsrichtlinien der SPORTUNION Steiermark werden anerkannt; die Mittel werden nach den Grundsätzen der Notwendigkeit, Sparsamkeit, Wirtschaftlichkeit und Zweckmäßigkeit verwendet und bei nicht ordnungsgemäßer Verwendung rückerstattet.',
    'erkl_logo'         => 'Der Verein weist in Publikationen und bei gegebenen Anlässen auf die Unterstützung durch die SPORTUNION Steiermark hin und verwendet das SPORTUNION-Logo (Website, Drucksorten, Social Media, Dressen).',
];

/** Belege mit diesen Begriffen werden laut Abrechnungsrichtlinien nicht anerkannt. */
const SU_NICHT_FOERDERBAR = [
    'alkohol' => 'Alkoholische Getränke', 'bier' => 'Alkoholische Getränke', 'wein' => 'Alkoholische Getränke', 'sekt' => 'Alkoholische Getränke',
    'zigarette' => 'Rauchwaren', 'tabak' => 'Rauchwaren', 'trinkgeld' => 'Trinkgelder', 'geschenk' => 'Geschenke (ausgenommen Ehrenpreise)',
    'mahn' => 'Mahnspesen', 'säumnis' => 'Säumniszuschläge', 'strafe' => 'Strafgelder', 'kantine' => 'Kantine / Vereinslokal',
    'buffet' => 'Kantine / Vereinslokal', 'vereinslokal' => 'Kantine / Vereinslokal', 'aufschließung' => 'Aufschließungskosten',
];

const SU_FINANZIERUNGSPLAN_AB = 1500;  // Pflicht ab diesem beantragten Betrag
const SU_BERICHT_AB           = 10000; // Bericht bei der Abrechnung ab diesem Förderbetrag
const SU_ANLAGENVERZEICHNIS_AB = 800;  // Langlebige Wirtschaftsgüter

function suFoerderart(string $schluessel): array
{
    return SU_FOERDERARTEN[$schluessel] ?? ['bereich' => 'sonstiges', 'label' => $schluessel, 'kurz' => '', 'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false, 'felder' => [], 'regeln' => [], 'nachweise' => [], 'beispiel' => ''];
}

/** Summe der Kostenaufstellung (Menge × Einzelpreis). */
function suKostenSumme(array $kosten): string
{
    return moneySum(array_map(fn($k) => bcmul((string)$k['menge'], (string)$k['einzelpreis'], 2), $kosten));
}

/**
 * Richtwert für den Antragsbetrag nach den Regeln der Förderart.
 * @return array{betrag: ?string, erklaerung: string}
 */
function suRichtwert(array $pos, string $kostenSumme): array
{
    $art    = suFoerderart($pos['foerderart']);
    $luecke = bcsub(bcsub($kostenSumme, (string)$pos['eigenmittel'], 2), (string)$pos['andere_foerderungen'], 2);
    if (bccomp($luecke, '0', 2) < 0) $luecke = '0.00';

    switch ($art['berechnung']) {
        case 'rahmen':
            if (bccomp($kostenSumme, '0', 2) <= 0) return ['betrag' => null, 'erklaerung' => 'Kostenaufstellung fehlt.'];
            $betrag = bccomp($luecke, (string)$art['hoechst'], 2) > 0 ? moneyRound($art['hoechst']) : $luecke;
            return ['betrag' => $betrag, 'erklaerung' => 'Finanzierungslücke ' . moneyFormat($luecke) . ', Förderrahmen ' . moneyFormat($art['grund']) . ' bis ' . moneyFormat($art['hoechst']) . '.'];

        case 'ausbildung':
            $stufe = $pos['ausbildungsstufe'] ?? null;
            if (!$stufe || !isset(SU_AUSBILDUNG_SAETZE[$stufe])) return ['betrag' => null, 'erklaerung' => 'Ausbildungsstufe fehlt.'];
            $personen = max(1, (int)($pos['anzahl_personen'] ?? 1));
            $satz = SU_AUSBILDUNG_SAETZE[$stufe];
            return ['betrag' => moneyRound($personen * $satz['betrag']), 'erklaerung' => $personen . ' × ' . $satz['label'] . ' à ' . moneyFormat($satz['betrag']) . ' (Fixbetrag bei Abschluss).'];

        case 'pro_person':
            $personen = (int)($pos['anzahl_personen'] ?? 0);
            if ($personen < 1) return ['betrag' => null, 'erklaerung' => 'Anzahl Teilnehmer:innen fehlt.'];
            $satz = !empty($pos['mit_uebernachtung']) ? $art['satz_uebernachtung'] : $art['satz'];
            return ['betrag' => moneyRound($personen * $satz), 'erklaerung' => $personen . ' Teilnehmer:innen à ' . moneyFormat($satz) . (!empty($pos['mit_uebernachtung']) ? ' (mit Übernachtung).' : ' (ohne Übernachtung).')];

        case 'fix':
            return ['betrag' => moneyRound($art['betrag']), 'erklaerung' => 'Fixbetrag.'];

        default: // ermessen
            if (bccomp($kostenSumme, '0', 2) <= 0) return ['betrag' => null, 'erklaerung' => 'Kostenaufstellung fehlt.'];
            return ['betrag' => $luecke, 'erklaerung' => 'Finanzierungslücke (Kosten abzüglich Eigenmittel und anderer Förderungen); Höhe nach Ermessen der Landesleitung.'];
    }
}

/** Beantragter Betrag: manueller Wert oder Richtwert. */
function suBeantragt(array $pos, string $kostenSumme): string
{
    if ($pos['betrag_beantragt'] !== null && $pos['betrag_beantragt'] !== '') return moneyRound($pos['betrag_beantragt']);
    return suRichtwert($pos, $kostenSumme)['betrag'] ?? '0.00';
}

/** Einreichfrist einer Förderart im Förderjahr. */
function suEinreichfrist(string $foerderart, int $jahr): string
{
    [$tag, $monat] = explode('.', rtrim(suFoerderart($foerderart)['frist'], '.'));
    return sprintf('%04d-%02d-%02d', $jahr, (int)$monat, (int)$tag);
}

/**
 * Abrechnungsfrist nach Zusage: binnen 3 Monaten, spätestens 30.11. des Zusagejahres;
 * Bausubventionen bis 31.10. des Förderjahres.
 */
function suAbrechnungsfrist(array $antrag, bool $mitBau): ?string
{
    if (empty($antrag['zusage_datum'])) return $mitBau ? $antrag['jahr'] . '-10-31' : null;
    $zusage  = strtotime($antrag['zusage_datum']);
    $frist   = min(strtotime('+3 months', $zusage), strtotime(date('Y', $zusage) . '-11-30'));
    if ($mitBau) $frist = min($frist, strtotime($antrag['jahr'] . '-10-31'));
    return date('Y-m-d', $frist);
}

/**
 * Plausibilitätsprüfung einer Position gegen die Förderrichtlinien.
 * @return list<array{typ: string, text: string}> typ: fehler | warnung | info
 */
function suPruefePosition(array $antrag, array $pos, array $kosten, string $kostenSumme, string $beantragt): array
{
    $art    = suFoerderart($pos['foerderart']);
    $heute  = date('Y-m-d');
    $jahr   = (int)$antrag['jahr'];
    $m      = [];
    $entwurf = $antrag['status'] === 'entwurf';

    if (trim((string)$pos['beschreibung']) === '') {
        $m[] = ['typ' => 'fehler', 'text' => 'Beschreibung fehlt – die Richtlinien verlangen eine klare Beschreibung des Fördergegenstandes.'];
    }
    if (in_array($art['berechnung'], ['rahmen', 'ermessen'], true) && empty($kosten)) {
        $m[] = ['typ' => 'fehler', 'text' => 'Kostenaufstellung fehlt – die kostenmäßige Darstellung ist Pflicht.'];
    }
    if ($art['berechnung'] === 'ausbildung' && empty($pos['ausbildungsstufe'])) {
        $m[] = ['typ' => 'fehler', 'text' => 'Bitte die Ausbildungsstufe wählen (bestimmt den Fixbetrag).'];
    }
    if ($art['berechnung'] === 'pro_person' && (int)$pos['anzahl_personen'] < 1) {
        $m[] = ['typ' => 'fehler', 'text' => 'Bitte die Anzahl der Teilnehmer:innen angeben und die Teilnehmerliste beilegen.'];
    }
    if (in_array($pos['foerderart'], ['fahrt_allgemein', 'fahrt_nachwuchs'], true) && trim((string)$pos['platzierung']) === '') {
        $m[] = ['typ' => 'warnung', 'text' => 'Platzierung angeben – gefördert wird nur bei ' . ($pos['foerderart'] === 'fahrt_allgemein' ? 'Platz 1–3 (LM) bzw. 1–6 (ÖM).' : 'Platz 1 (LM) bzw. 1–3 (ÖM).')];
    }

    // Fristen
    $frist = suEinreichfrist($pos['foerderart'], $jahr);
    if ($entwurf && $heute > $frist) {
        $m[] = ['typ' => 'warnung', 'text' => 'Einreichfrist ' . date('d.m.Y', strtotime($frist)) . ' ist abgelaufen – ggf. im nächsten Förderjahr beantragen.'];
    }
    if (!$art['nachtraeglich'] && $entwurf && !empty($pos['massnahme_von']) && $pos['massnahme_von'] <= $heute) {
        $m[] = ['typ' => 'warnung', 'text' => 'Die Maßnahme hat bereits begonnen – Ansuchen müssen VOR der Maßnahme eingebracht werden.'];
    }
    foreach (['massnahme_von', 'massnahme_bis'] as $feld) {
        if (!empty($pos[$feld]) && (int)substr($pos[$feld], 0, 4) !== $jahr) {
            $m[] = ['typ' => 'warnung', 'text' => 'Der Maßnahmenzeitraum liegt außerhalb des Förderjahres ' . $jahr . ' – Rechnungs- und Zahlungsdatum müssen im Kalenderjahr liegen.'];
            break;
        }
    }

    // Beträge
    if ($art['berechnung'] === 'rahmen') {
        if (bccomp($beantragt, (string)$art['hoechst'], 2) > 0) {
            $m[] = ['typ' => 'fehler', 'text' => 'Beantragter Betrag über dem Höchstbetrag von ' . moneyFormat($art['hoechst']) . '.'];
        } elseif (bccomp($beantragt, '0', 2) > 0 && bccomp($beantragt, (string)$art['grund'], 2) < 0) {
            $m[] = ['typ' => 'info', 'text' => 'Betrag unter dem Grundbetrag von ' . moneyFormat($art['grund']) . ' – prüfen, ob sich das Ansuchen lohnt oder mit weiteren Anschaffungen gebündelt werden kann.'];
        }
    }
    if (bccomp($kostenSumme, '0', 2) > 0 && in_array($art['berechnung'], ['rahmen', 'ermessen'], true)) {
        $finanziert = moneySum([$pos['eigenmittel'], $pos['andere_foerderungen'], $beantragt]);
        $diff = bcsub($kostenSumme, $finanziert, 2);
        if (bccomp($diff, '0', 2) !== 0) {
            $m[] = ['typ' => 'warnung', 'text' => 'Finanzierungsplan nicht ausgeglichen: ' . (bccomp($diff, '0', 2) > 0 ? moneyFormat($diff) . ' ungedeckt' : moneyFormat(bcmul($diff, '-1', 2)) . ' überfinanziert') . ' – Eigenmittel anpassen.'];
        }
    }
    if (bccomp($beantragt, (string)SU_FINANZIERUNGSPLAN_AB, 2) >= 0) {
        $m[] = ['typ' => 'info', 'text' => 'Ab ' . moneyFormat(SU_FINANZIERUNGSPLAN_AB) . ' ist ein Finanzierungsplan verpflichtend – er ist im PDF enthalten.'];
    }

    // Kostenpositionen
    foreach ($kosten as $k) {
        $bez = mb_strtolower($k['bezeichnung']);
        foreach (SU_NICHT_FOERDERBAR as $begriff => $grund) {
            if (str_contains($bez, $begriff)) {
                $m[] = ['typ' => 'warnung', 'text' => '„' . $k['bezeichnung'] . '“: ' . $grund . ' werden laut Abrechnungsrichtlinien nicht anerkannt.'];
                break;
            }
        }
        if ((float)$k['einzelpreis'] > SU_ANLAGENVERZEICHNIS_AB) {
            $m[] = ['typ' => 'info', 'text' => '„' . $k['bezeichnung'] . '“ kostet über ' . moneyFormat(SU_ANLAGENVERZEICHNIS_AB) . ' – ins Anlagenverzeichnis des Vereins aufnehmen.'];
        }
        if ($pos['foerderart'] === 'su_meisterschaften' && str_contains($bez, 'nächtigung') && (float)$k['einzelpreis'] > 60) {
            $m[] = ['typ' => 'warnung', 'text' => 'Nächtigung wird nur bis € 60,– (DZ mit Frühstück) übernommen.'];
        }
    }

    // Hinweise je Förderart
    $hinweise = [
        'veranstaltung' => 'Für Fachverbandswettkämpfe (Meisterschaften, Cups) gibt es in der Regel keine Zuschüsse – Charakter als eigene Vereinsveranstaltung herausstreichen.',
        'bau'           => 'Maßnahme muss den ÖISS-Bestimmungen entsprechen; Buffet/Kantine nicht förderbar; Rückerstattungspflicht bei Austritt innerhalb von 9 Jahren.',
        'gruendung'     => 'Nur für neu gegründete SPORTUNION-Vereine.',
        'lehrgang'      => 'Der Kostenvoranschlag ist über die Landesspartenreferent:innen bis 31. März vorzulegen.',
        'bundesliga'    => 'Förderung erst ab dem Kalenderjahr nach dem Aufstieg; nicht für Profi-Mannschaften und Fußball.',
        'jugend'        => 'Kooperation Schule – Verein ausdrücklich erwünscht: Bezug zur Täglichen Bewegungseinheit und zu Partnerschulen herausstreichen.',
    ];
    if (isset($hinweise[$pos['foerderart']])) $m[] = ['typ' => 'info', 'text' => $hinweise[$pos['foerderart']]];

    return $m;
}

/** Prüfung auf Ebene des Ansuchens (Vertreter, Erklärungen, Gesamtbetrag). */
function suPruefeAntrag(array $antrag, string $beantragtGesamt, int $anzahlPositionen): array
{
    $m = [];
    if ($anzahlPositionen === 0) $m[] = ['typ' => 'fehler', 'text' => 'Noch keine Fördergegenstände erfasst.'];
    if (trim((string)$antrag['obmann_name']) === '' || trim((string)$antrag['vertreter2_name']) === '') {
        $m[] = ['typ' => 'fehler', 'text' => 'Statutarische Vertreter fehlen – Ansuchen stellen Obmann/Obfrau gemeinsam mit Kassier:in oder Schriftführer:in.'];
    }
    $offen = array_filter(array_keys(SU_ERKLAERUNGEN), fn($k) => empty($antrag[$k]));
    if ($offen) $m[] = ['typ' => 'warnung', 'text' => count($offen) . ' von ' . count(SU_ERKLAERUNGEN) . ' Erklärungen noch nicht bestätigt (Voraussetzung für die Förderwürdigkeit).'];
    if (empty($antrag['iban'])) $m[] = ['typ' => 'warnung', 'text' => 'Bankverbindung für die Auszahlung fehlt.'];
    if (bccomp($beantragtGesamt, (string)SU_BERICHT_AB, 2) >= 0) {
        $m[] = ['typ' => 'info', 'text' => 'Ab ' . moneyFormat(SU_BERICHT_AB) . ' Förderung ist bei der Abrechnung zusätzlich ein Bericht über die geförderte Maßnahme beizulegen.'];
    }
    return $m;
}
