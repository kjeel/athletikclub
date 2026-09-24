<?php
/**
 * Athletikclub Steiermark – Basisförderung SPORTUNION Steiermark
 *
 * Förderkatalog, Richtwert-Berechnung, Fristen und Plausibilitätsprüfung für zwei Programme:
 *  A) Landesverbandsförderung nach den Förderrichtlinien (Infrastruktur, Allgemein-, Leistungssport)
 *  B) SPORTUNION Vereinsbonus (5 Fördersäulen mit Höchstbeträgen)
 * Quellen:
 *  - Förderrichtlinien der SPORTUNION Steiermark 2023 (Foerderrichtlinien-2023.pdf)
 *  - Finanzielle Zuschüsse der SPORTUNION Steiermark
 *  - Abrechnungsrichtlinien für Subventionen (2023_ABRECHNUNGSRICHTLINIEN_Vereine.pdf)
 *  - SPORTUNION Vereinsbonus, Fördersäulen 2026 (sportunion.at/stmk/projekte/sportunion-vereinsbonus/)
 * Ändern sich Sätze oder Fristen, nur hier anpassen.
 */

const SU_QUELLEN = [
    'Vereinsförderung (Übersicht)' => 'https://sportunion.at/stmk/service/vereinsdatenbank/vereinsfoerderung/',
    'Förderrichtlinien 2023'       => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/Foerderrichtlinien-2023.pdf',
    'Finanzielle Zuschüsse'        => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/Finanzielle-Zuschüsse-der-SPORTUNION-Steiermark.pdf',
    'Abrechnungsrichtlinien'       => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2023_ABRECHNUNGSRICHTLINIEN_Vereine.pdf',
    'SPORTUNION Vereinsbonus'      => 'https://sportunion.at/stmk/projekte/sportunion-vereinsbonus/',
    'Vereinsbonus-Fördersäulen 2026 (Grafik)' => 'https://sportunion.at/stmk/wp-content/uploads/SU-Vereinsbonus_Foerdersaeulen-2026.png',
    'Fit Sport Austria – Qualitätssiegel' => 'https://www.fitsportaustria.at/qualitaetssiegel/',
    'Downloads & Abrechnungsformulare' => 'https://sportunion.at/stmk/service/downloads/',
    'Online-Förderansuchen / Vereinsbonus-Antrag (Vereinsdatenbank)' => 'https://suvw.at/vereinsmeldung/index.php',
];

const SU_VEREINSDATENBANK = 'https://suvw.at/vereinsmeldung/index.php';

/** Vorlagen der SPORTUNION für die Abrechnung. */
const SU_ABRECHNUNGSFORMULARE = [
    'PRAE – Pauschale Reiseaufwandsentschädigung' => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2023_PRAE_Pauschale_Reiseaufwandsentschaedigung.xlsx',
    'Honorarnote'                                 => 'https://sportunion.at/wp-content/uploads/2018_Honorarnote_HN_neu-1.xlsx',
    'Letztempfängerliste (Fahrtkosten)'           => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2020_LEL_Letztempfaengerliste.xlsx',
    'Tatsächliche Reisekosten'                    => 'https://sportunion.at/wp-content/uploads/2020_TRK_Tatsaechliche_Reisekosten.xlsx',
    'Teilnehmer:innenliste'                       => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2020_TN_TeilnehmerInnenliste.xlsx',
    'Kostenzusammenstellung'                      => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2020_KostZ_Kostenzusammenstellung.xlsx',
    'Anlageverzeichnis'                           => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2020_AV_Anlageverzeichnis.xls',
    'Kassabuch'                                   => 'https://sportunion.at/wp-content/uploads/20180207_Vorlage_Kassabuch-1.xlsx',
    'PRAE-Jahresmeldung (Formular L19)'           => 'https://sportunion.at/stmk/wp-content/uploads/sites/7/2023_PRAE-BMF-Meldeformular_L19-1.pdf',
];

const SU_KONTAKTE = [
    ['rolle' => 'Förderungen / GF Leistungs- und Wettkampfsport', 'name' => 'Mag. Herwig Reupichler', 'email' => 'herwig.reupichler@sportunion-steiermark.at', 'tel' => '+43 316 3244 30 75'],
    ['rolle' => 'Vereinsbonus, Aus- und Fortbildung', 'name' => 'Mag.a Lydia Mitterhammer', 'email' => 'lydia.mitterhammer@sportunion-steiermark.at', 'tel' => '+43 316 3244 30 74'],
    ['rolle' => 'Abrechnung', 'name' => 'Ina Werni', 'email' => 'ina.werni@sportunion-steiermark.at', 'tel' => '+43 316 32 44 30 71'],
    ['rolle' => 'Landesgeschäftsstelle', 'name' => 'SPORTUNION Steiermark, Gaußgasse 3, 8010 Graz', 'email' => 'office@sportunion-steiermark.at', 'tel' => '+43 316 32 44 30'],
    ['rolle' => 'Land Steiermark – Referat Sport (Abrechnung, Berichte)', 'name' => 'Andreas Wanner, MBA', 'email' => 'andreas.wanner@stmk.gv.at', 'tel' => '+43 316 877-4390'],
    ['rolle' => 'Land Steiermark – Abteilung 9 Kultur, Europa, Sport', 'name' => 'Landhausgasse 7, 8010 Graz (Abrechnungen an sport@stmk.gv.at)', 'email' => 'abteilung9@stmk.gv.at', 'tel' => '+43 316 877-4321'],
];

/** Bereiche; „vereinsbonus“ ist Programm B, alle anderen gehören zur Landesverbandsförderung (Programm A). */
const SU_BEREICHE = [
    'infrastruktur'  => 'Infrastruktur',
    'allgemeinsport' => 'Allgemeinsport',
    'leistungssport' => 'Leistungssport',
    'sonstiges'      => 'Sonstige Zuschüsse',
    'vereinsbonus'   => 'SPORTUNION Vereinsbonus',
    'land'           => 'Sportförderung Land Steiermark',
];

/** Programme; A und B werden bei der SPORTUNION eingereicht, C beim Land Steiermark (eigener Online-Antrag). */
const SU_PROGRAMME = [
    'landesverband' => ['label' => 'Landesverbandsförderung (Förderrichtlinien)', 'kurz' => 'Landesverband', 'class' => 'badge-navy', 'buchstabe' => 'A', 'stelle' => 'sportunion'],
    'vereinsbonus'  => ['label' => 'SPORTUNION Vereinsbonus', 'kurz' => 'Vereinsbonus', 'class' => 'badge-gold', 'buchstabe' => 'B', 'stelle' => 'sportunion'],
    'land'          => ['label' => 'Sportförderung Land Steiermark', 'kurz' => 'Land Stmk', 'class' => 'badge-success', 'buchstabe' => 'C', 'stelle' => 'land'],
];

/** Förderstellen, bei denen eingereicht wird (je Förderstelle ein eigenes PDF). */
const SU_STELLEN = [
    'sportunion' => [
        'name' => 'SPORTUNION Steiermark', 'kopf' => 'FÖRDERANSUCHEN SPORTUNION STEIERMARK',
        'adresse' => "An die\nSPORTUNION Steiermark\nGaußgasse 3, 8010 Graz",
    ],
    'land' => [
        'name' => 'Land Steiermark', 'kopf' => 'FÖRDERANSUCHEN SPORTFÖRDERUNG LAND STEIERMARK',
        'adresse' => "An das\nAmt der Steiermärkischen Landesregierung\nAbteilung 9 Kultur, Europa, Sport – Referat Sport\nJahngasse 1, 8010 Graz",
    ],
];

/** Sportförderung Land Steiermark: Quellen, Vorlagen und Kontakt (Richtlinie gültig ab 15.01.2026). */
const SU_LAND_QUELLEN = [
    'Richtlinie für Sportförderungen des Landes Steiermark (ab 15.01.2026)' => 'https://www.verwaltung.steiermark.at/cms/dokumente/11684766_74836244/d7cdf3c2/Richtlinie%20f%C3%BCr%20Sportf%C3%B6rderungen%20des%20Landes%20Steiermark_14012026.pdf',
    'Online-Antrag Sportförderung (Land)' => 'http://egov.stmk.gv.at/eform/LDF/start.do?generalid=SF-FO-AS',
    'Sportförderung – Allgemeine Informationen' => 'https://www.verwaltung.steiermark.at/cms/ziel/74836846/DE/',
];
const SU_LAND_FORMULARE = [
    'Merkblatt Abrechnung'            => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/7dec2d98/Merkblatt%20zur%20Erstellung%20der%20Abrechnung%20NEU.pdf',
    'Merkblatt Tätigkeitsbericht'     => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/1bde454a/Merkblatt%20zur%20Erstellung%20des%20T%C3%A4tigkeitsberichtes.pdf',
    'Merkblatt Projektbericht'        => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/55a1e381/Merkblatt%20zur%20Erstellung%20Projektbericht%20NEU.pdf',
    'Einnahmen-Ausgaben-Aufstellung'  => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/99db93b9/EinnahmenAusgaben-Aufstellung.xlsx',
    'Belegaufstellung Sachkosten'     => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/f2da3de7/Belegaufstellung%20Sachkosten.xls',
    'Belegaufstellung Personalkosten' => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/56cffc70/Belegaufstellung%20Personalkosten.xls',
    'Leistungsverzeichnis Eigenhonorare' => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/f130870c/Leistungsverzeichnis%20f%C3%BCr%20Eigenhonorare.xlsx',
    'Sammelrechnung'                  => 'https://www.verwaltung.steiermark.at/cms/dokumente/11680471_74836846/82acc9d0/Sammelrechnung.xlsx',
];
const SU_LAND_BAGATELLGRENZE  = 2500; // bis dahin kein Verwendungsnachweis (Stichproben möglich)
const SU_LAND_NACHWEIS_EINFACH = 8000; // bis dahin nur Bericht + Einnahmen-Ausgaben-Aufstellung

/** Erklärungen für den Antrag beim Land Steiermark (laut Richtlinie, vor Unterschrift zu bestätigen). */
const SU_LAND_ERKLAERUNGEN = [
    'Der Verein hat eine gültige ZVR-Zahl und ist Mitglied eines steirischen, bei Sport Austria anerkannten Landesfachverbands.',
    'Gegen den Verein ist kein Zwangsvollstreckungs- oder Insolvenzverfahren bewilligt bzw. eröffnet.',
    'Die geförderten Leistungen dienen nicht überwiegend Erwerbszwecken und werden nicht zur Gänze aus Förderungsmitteln finanziert.',
    'Alle Förderungen zum selben Förderungsgegenstand (auch nachträglich beantragte) sind vollständig angegeben.',
    'Die Richtlinie für Sportförderungen des Landes Steiermark wird anerkannt; die Angaben sind richtig und vollständig; einer Kontrolle durch den Landesrechnungshof wird zugestimmt.',
    'Wesentliche Änderungen werden unverzüglich schriftlich gemeldet; der Förderungsvertrag wird binnen eines Monats unterschrieben retourniert.',
    'Förderungen über € 1.500,– im Kalenderjahr können im Transparenzportal veröffentlicht werden.',
];

/** Kosten, die das Land Steiermark laut Richtlinie/Merkblatt nicht anerkennt (Stichwortsuche in der Kostenaufstellung). */
const SU_LAND_NICHT_FOERDERBAR = [
    'miete' => 'Miet-/Pachtzinszahlungen', 'pacht' => 'Miet-/Pachtzinszahlungen', 'versicherung' => 'Versicherungskosten',
    'bekleidung' => 'Bekleidung und Ausrüstung (außer Spitzensport)', 'dress' => 'Bekleidung und Ausrüstung (außer Spitzensport)', 'trikot' => 'Bekleidung und Ausrüstung (außer Spitzensport)',
    'verpflegung' => 'Verköstigung und Proviant', 'verköstigung' => 'Verköstigung und Proviant', 'proviant' => 'Verköstigung und Proviant', 'catering' => 'Verköstigung und Proviant',
    'bankspesen' => 'Bankspesen', 'bankgebühr' => 'Bankspesen', 'verbandsgebühr' => 'Verbandsgebühren', 'broschüre' => 'Broschüren und Publikationen', 'publikation' => 'Broschüren und Publikationen',
    'beschriftung' => 'Beschriftungskosten', 'ehrengeschenk' => 'Ehrengeschenke und Dekoration', 'dekoration' => 'Ehrengeschenke und Dekoration', 'preisgeld' => 'Preisgelder',
    'betriebskosten' => 'Betriebskosten', 'strom' => 'Betriebskosten', 'kredit' => 'Kreditraten', 'rechtsanwalt' => 'Rechts- und Beratungskosten', 'beratung' => 'Rechts- und Beratungskosten',
    'nahrungsergänzung' => 'Nahrungsergänzungsmittel', 'vip' => 'VIP-Bereiche und Empfänge',
];

/** Kategorien der sozialen Maßnahme (Vereinsbonus). */
const SU_SOZIAL_KATEGORIEN = [
    'inklusion'             => 'Inklusion (Menschen mit und ohne Beeinträchtigung)',
    'integration'           => 'Integration (Menschen mit Migrationshintergrund)',
    'gender'                => 'Gendergerechtigkeit (Chancengleichheit, Prävention sexueller Übergriffe)',
    'soziale_verantwortung' => 'Soziale Verantwortung (Betrugs-/Dopingprävention, benachteiligte Gruppen)',
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
 *              pro_person (Satz je Teilnehmer:in), fix (fester Betrag), ermessen (Finanzierungslücke),
 *              deckel (Vereinsbonus: Höchstbetrag max_je, bei menge_zaehlt × Anzahl)
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

    // ---------------- Programm B: SPORTUNION Vereinsbonus ----------------
    'vb_kurs' => [
        'bereich' => 'vereinsbonus', 'label' => 'Vereinsbonus: Start eines neuen Semesterkurses',
        'kurz' => 'Neuer Kurs, der zusätzlich zum bestehenden Vereinsprogramm gegründet wird und bei der Förderzusage noch nicht gestartet ist.',
        'berechnung' => 'deckel', 'max_je' => 450, 'je' => 'Kurs', 'frist' => '30.09.', 'nachtraeglich' => false,
        'felder' => ['wettkampf'], 'feld_labels' => ['wettkampf' => 'Kurs (Name, Tag/Uhrzeit, Ort)'],
        'regeln' => [
            'Höchstens € 450,– pro genehmigtem Kurs.',
            'Kurs in der SPORTUNION-Vereinsdatenbank eintragen, Fit-Sport-Austria-Qualitätssiegel beantragen und den Tag „Vereinsbonus“ zuordnen.',
            'Abrechenbar: Belege mit direktem Bezug zum Kurs (PRAE der Übungsleiter:in, Material, Hallenkosten).',
        ],
        'nachweise' => ['Kurseintrag mit Qualitätssiegel und Tag „Vereinsbonus“', 'PRAE/Honorarnoten der Übungsleiter:innen', 'Material- und Hallenrechnungen', 'Zahlungsnachweise'],
        'beispiel' => 'z.B. neuer Calisthenics-Anfängerkurs, Padel für Einsteiger:innen, Skate-Kurs für Mädchen',
    ],
    'vb_sozial' => [
        'bereich' => 'vereinsbonus', 'label' => 'Vereinsbonus: Soziale Maßnahme',
        'kurz' => 'Vereinsangebote oder neue Projekte in den Bereichen Inklusion, Integration, Gendergerechtigkeit oder soziale Verantwortung.',
        'berechnung' => 'deckel', 'max_je' => 450, 'je' => 'Projekt', 'frist' => '30.09.', 'nachtraeglich' => false,
        'felder' => ['kategorie', 'anzahl_personen'], 'feld_labels' => ['anzahl_personen' => 'Erwartete Teilnehmer:innen'], 'menge_zaehlt' => false,
        'regeln' => [
            'Höchstens € 450,– pro genehmigtem Projekt.',
            'Zusätzlich zum Antrag das Formular „Soziale Maßnahme“ ausfüllen; Dokumentation nach Absprache mit dem Landesverband.',
            'Menschen mit Migrationshintergrund sind ausschließlich Zielgruppe der Kategorie „Integration“.',
        ],
        'nachweise' => ['Formular „Soziale Maßnahme“', 'Dokumentation der Maßnahme', 'Belege (PRAE, Material, Hallenkosten)', 'Zahlungsnachweise'],
        'beispiel' => 'z.B. inklusive Bewegungsstunde, Mädchen-Skate-Nachmittag, kostenloser Kurs für einkommensschwache Familien',
    ],
    'vb_partner' => [
        'bereich' => 'vereinsbonus', 'label' => 'Vereinsbonus: Sporteinheiten mit Partnereinrichtungen',
        'kurz' => 'Sport- und Bewegungseinheiten in Partnereinrichtungen: Schulen Sek I und II, Altersheime, Firmen, Jugendzentren, Betreuungseinrichtungen u.a.',
        'berechnung' => 'deckel', 'max_je' => 30, 'je' => 'Einheit', 'frist' => '30.09.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'], 'feld_labels' => ['anzahl_personen' => 'Anzahl Einheiten', 'wettkampf' => 'Partnereinrichtung'], 'menge_zaehlt' => true,
        'regeln' => [
            'Höchstens € 30,– pro abgehaltener Einheit.',
            'Kooperation vorab schriftlich mit der Partnereinrichtung festhalten.',
            'Stundenliste mit Stempel und Unterschrift der Partnereinrichtung führen und der Abrechnung beilegen.',
            'Volksschulen laufen über die Tägliche Bewegungseinheit (eigener Menüpunkt „TBE-Gesamtkonzept“).',
        ],
        'nachweise' => ['Kooperationsvereinbarung mit der Partnereinrichtung', 'Stundenliste mit Stempel und Unterschrift', 'Belege (PRAE, Material, Hallenkosten)'],
        'beispiel' => 'z.B. Calisthenics an der Mittelschule, Bewegungseinheit im Seniorenheim, Firmensport',
    ],
    'vb_ausbildung' => [
        'bereich' => 'vereinsbonus', 'label' => 'Vereinsbonus: Übungsleiter:innen-Ausbildung',
        'kurz' => 'Übungsleiter:innen-Ausbildungen der SPORTUNION Akademie oder vergleichbare; Erstausbildungen werden bevorzugt gefördert.',
        'berechnung' => 'deckel', 'max_je' => 314, 'je' => 'Ausbildung', 'frist' => '30.09.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'], 'feld_labels' => ['anzahl_personen' => 'Anzahl Personen', 'wettkampf' => 'Ausbildung (Bezeichnung, Anbieter)'], 'menge_zaehlt' => true,
        'regeln' => [
            'Höchstens € 314,– pro Ausbildung, abgerechnet mit der Rechnung.',
            'Bekanntgabe VOR Ausbildungsbeginn; Nachweis über den Abschluss (z.B. Zertifikat).',
            'Der Verein übernimmt die Kosten, die Rechnung lautet auf den Verein.',
            'Die Übungsleiter:in ist danach in einem qualitätsgesiegelten Kurs im Verein tätig.',
        ],
        'nachweise' => ['Rechnung des Ausbildungsanbieters auf den Verein', 'Zahlungsnachweis', 'Abschlusszertifikat'],
        'beispiel' => 'z.B. Übungsleiter:in Allgemeine Körperausbildung, Übungsleiter:in Kinder & Jugend',
    ],
    'vb_fortbildung' => [
        'bereich' => 'vereinsbonus', 'label' => 'Vereinsbonus: Fortbildung',
        'kurz' => 'Fortbildungen der SPORTUNION Akademie oder vergleichbare für bereits ausgebildete Übungsleiter:innen.',
        'berechnung' => 'deckel', 'max_je' => 99, 'je' => 'Fortbildung', 'frist' => '30.09.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'], 'feld_labels' => ['anzahl_personen' => 'Anzahl Personen', 'wettkampf' => 'Fortbildung (Bezeichnung, Anbieter)'], 'menge_zaehlt' => true,
        'regeln' => [
            'Höchstens € 99,– pro Fortbildung, abgerechnet mit der Rechnung.',
            'Eine Übungsleiter:innen-Ausbildung ist Voraussetzung.',
            'Bekanntgabe VOR Beginn; Nachweis über die Teilnahme (z.B. Teilnahmebestätigung).',
            'Der Verein übernimmt die Kosten, die Rechnung lautet auf den Verein.',
        ],
        'nachweise' => ['Rechnung auf den Verein', 'Zahlungsnachweis', 'Teilnahmebestätigung'],
        'beispiel' => 'z.B. Kinderschutz-Fortbildung, Mobility-Workshop, Erste Hilfe im Sport',
    ],

    // ---------------- Programm C: Sportförderung Land Steiermark ----------------
    // berechnung „land“: Standardförderung mit Bandbreite (Zu-/Abschläge je nach Vereinsgröße, Kostenintensität, Nachwuchsqualität)
    'land_betrieb' => [
        'bereich' => 'land', 'label' => 'Land: Vereinsförderung – Allg. Trainings- und Wettkampfbetrieb',
        'kurz' => 'Jährliche Vereinsförderung für den allgemeinen Trainings- und Wettkampfbetrieb steirischer Vereine, die Mitglied eines Landesfachverbands sind.',
        'berechnung' => 'land', 'standard' => 500, 'min' => 300, 'max_prozent_kosten' => 10, 'frist' => '31.10.', 'nachtraeglich' => true,
        'felder' => [], 'kosten_hinweis' => 'Jahresbudget des Trainings- und Wettkampfbetriebs eintragen (Förderung max. 10 % davon)',
        'regeln' => [
            'Standardförderung € 500,–, Bandbreite € 300,– bis max. 10 % des Gesamtbudgets.',
            'Antrag von 1. Jänner bis 31. Oktober, vor Meisterschaftsbeginn; pro Kalenderjahr nur EIN Antrag (gemeinsam mit der Nachwuchsförderung).',
            'Voraussetzung: Mitgliedschaft in einem steirischen, bei Sport Austria anerkannten Landesfachverband. Reine Breitensportvereine werden grundsätzlich über die Dachverbände gefördert.',
            'Nicht förderbar u.a.: Miete/Pacht, Betriebskosten, Versicherungen, Bekleidung/Ausrüstung, Verköstigung, Bankspesen.',
        ],
        'nachweise' => ['Tätigkeitsbericht', 'Einnahmen-Ausgaben-Aufstellung (Plan/Ist)', 'ab € 8.000,–: Belegaufstellungen mit Originalrechnungen und Zahlungsnachweisen'],
        'beispiel' => 'z.B. Trainer:innen-Honorare, Wettkampfgebühren, Fahrten zu Meisterschaften',
    ],
    'land_nachwuchs' => [
        'bereich' => 'land', 'label' => 'Land: Vereinsförderung – Nachwuchsarbeit',
        'kurz' => 'Jährliche Förderung der Kinder- und Jugendarbeit; bemessen an Nachwuchsmannschaften, aktiven Nachwuchssportler:innen und inhaltlichen Schwerpunkten.',
        'berechnung' => 'land', 'standard' => 500, 'min' => 300, 'max' => 5000, 'frist' => '31.10.', 'nachtraeglich' => true,
        'felder' => ['anzahl_personen'], 'feld_labels' => ['anzahl_personen' => 'Aktive Nachwuchssportler:innen'],
        'regeln' => [
            'Standardförderung € 500,–, Bandbreite € 300,– bis € 5.000,–.',
            'Zuschläge für Vereinsgröße, Kostenintensität der Sportart und Qualität der Nachwuchsarbeit (Mannschaften, Aktive, Schwerpunkte).',
            'Antrag von 1. Jänner bis 31. Oktober; gemeinsam mit der Förderung des Trainings- und Wettkampfbetriebs in EINEM Antrag.',
        ],
        'nachweise' => ['Tätigkeitsbericht (Nachwuchsgruppen, Trainingsumfang, Erfolge, Fotos)', 'Einnahmen-Ausgaben-Aufstellung', 'ab € 8.000,–: Belegaufstellungen'],
        'beispiel' => 'z.B. Nachwuchstraining Calisthenics/Tischtennis, Nachwuchs-Trainer:innen, Trainingslager',
    ],
    'land_veranst_nachwuchs' => [
        'bereich' => 'land', 'label' => 'Land: Nachwuchsleistungssport-Veranstaltung',
        'kurz' => 'Veranstaltungen im Nachwuchsleistungssport mit klarem Steiermark-Bezug.',
        'berechnung' => 'land', 'standard' => 500, 'min' => 300, 'max' => 8000, 'frist' => '31.12.', 'vorlauf_monate' => 3, 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'], 'feld_labels' => ['anzahl_personen' => 'Erwartete Teilnehmer:innen', 'wettkampf' => 'Veranstaltung (Name, Ort)'],
        'regeln' => [
            'Standardförderung € 500,–, Bandbreite € 300,– bis € 8.000,–.',
            'Antrag spätestens drei Monate vor Beginn der Veranstaltung.',
            'Zu-/Abschläge nach Dauer, infrastrukturellem und personellem Aufwand, sportlicher Bedeutung, Teilnehmerzahl und Eigendeckungsgrad (Startgelder, Sponsoren).',
        ],
        'nachweise' => ['Projektbericht (Ergebnislisten, Fotos, Presse)', 'Einnahmen-Ausgaben-Aufstellung', 'ab € 8.000,–: Belegaufstellungen'],
        'beispiel' => 'z.B. Nachwuchs-Landesmeisterschaft Tischtennis',
    ],
    'land_veranst_oem' => [
        'bereich' => 'land', 'label' => 'Land: Österreichische Meisterschaft',
        'kurz' => 'Durchführung einer Österreichischen Meisterschaft in der Steiermark.',
        'berechnung' => 'land', 'standard' => 2000, 'min' => 1000, 'max' => 10000, 'frist' => '31.12.', 'vorlauf_monate' => 3, 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'], 'feld_labels' => ['anzahl_personen' => 'Erwartete Teilnehmer:innen', 'wettkampf' => 'Meisterschaft (Name, Ort)'],
        'regeln' => ['Standardförderung € 2.000,–, Bandbreite € 1.000,– bis € 10.000,–.', 'Antrag spätestens drei Monate vor Beginn.'],
        'nachweise' => ['Projektbericht mit Ergebnislisten', 'Einnahmen-Ausgaben-Aufstellung', 'ab € 8.000,–: Belegaufstellungen'],
        'beispiel' => '',
    ],
    'land_veranst_leistung' => [
        'bereich' => 'land', 'label' => 'Land: Leistungs- und Spitzensportveranstaltung',
        'kurz' => 'Internationale Bewerbe, Staatsmeisterschaften, Steirische Meisterschaften und Veranstaltungen mit besonderer sportlicher Bedeutung.',
        'berechnung' => 'land', 'standard' => 3000, 'min' => 2000, 'max' => 20000, 'frist' => '31.12.', 'vorlauf_monate' => 3, 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'], 'feld_labels' => ['anzahl_personen' => 'Erwartete Teilnehmer:innen', 'wettkampf' => 'Veranstaltung (Name, Ort)'],
        'regeln' => ['Standardförderung € 3.000,–, Bandbreite € 2.000,– bis € 20.000,–.', 'Antrag spätestens drei Monate vor Beginn.'],
        'nachweise' => ['Projektbericht mit Ergebnislisten', 'Einnahmen-Ausgaben-Aufstellung', 'ab € 8.000,–: Belegaufstellungen'],
        'beispiel' => '',
    ],
    'land_veranst_sonstige' => [
        'bereich' => 'land', 'label' => 'Land: Sonstige Sportveranstaltung',
        'kurz' => 'Sonstige Sportveranstaltungen mit Steiermark-Bezug. Reine Breitensportveranstaltungen fördern grundsätzlich die Dachverbände.',
        'berechnung' => 'land', 'standard' => 500, 'min' => 100, 'max' => 5000, 'frist' => '31.12.', 'vorlauf_monate' => 3, 'nachtraeglich' => false,
        'felder' => ['anzahl_personen', 'wettkampf'], 'feld_labels' => ['anzahl_personen' => 'Erwartete Teilnehmer:innen', 'wettkampf' => 'Veranstaltung (Name, Ort)'],
        'regeln' => ['Standardförderung € 500,–, Bandbreite € 100,– bis € 5.000,–.', 'Antrag spätestens drei Monate vor Beginn.', 'Breitensport-Events primär über die SPORTUNION (Programm A „Veranstaltungen“).'],
        'nachweise' => ['Projektbericht (Programm, Fotos, Teilnehmerzahlen)', 'Einnahmen-Ausgaben-Aufstellung'],
        'beispiel' => 'z.B. Calisthenics- oder Skate-Contest mit überregionaler Beteiligung',
    ],
    'land_inklusion' => [
        'bereich' => 'land', 'label' => 'Land: Behindertensport / Special Olympics',
        'kurz' => 'Inklusiver Sport und behindertenspezifische Sportangebote von Vereinen; Miteinander von Menschen mit und ohne Behinderung.',
        'berechnung' => 'ermessen', 'frist' => '31.10.', 'nachtraeglich' => false,
        'felder' => ['anzahl_personen'], 'feld_labels' => ['anzahl_personen' => 'Teilnehmer:innen'],
        'regeln' => ['Höhe nach Ermessen des Referats Sport.', 'Kombinierbar mit der SPORTUNION-Förderung „Soziale Maßnahme“ – andere Förderungen im Antrag angeben.'],
        'nachweise' => ['Tätigkeitsbericht', 'Einnahmen-Ausgaben-Aufstellung'],
        'beispiel' => 'z.B. inklusive Calisthenics-Gruppe',
    ],
    'land_einzelspitzensport' => [
        'bereich' => 'land', 'label' => 'Land: Einzelspitzensportförderung',
        'kurz' => 'Personenbezogene Leistungsförderung für steirische Spitzenaktive ab 17 Jahren mit Teilnahme an EM, WM, EYOF oder Olympischen Spielen.',
        'berechnung' => 'ermessen', 'frist' => '21.10.', 'nachtraeglich' => true,
        'felder' => ['wettkampf', 'platzierung'], 'feld_labels' => ['wettkampf' => 'Sportler:in und Wettkampf (EM/WM …)'],
        'regeln' => [
            'Wettkämpfe vom 15. Oktober des Vorjahres bis 14. Oktober; Antrag bis 21. Oktober, 23:59 Uhr.',
            'Vollendetes 17. Lebensjahr; Ergebnislisten; positive Stellungnahme des Fachverbandspräsidenten.',
        ],
        'nachweise' => ['Ergebnislisten', 'Stellungnahme des Fachverbands'],
        'beispiel' => '',
    ],
];

/**
 * Voraussetzungen je Förderart, die im Formular abgehakt werden.
 * Nicht erfüllte Punkte erscheinen im Antrags-Check.
 */
const SU_CHECKS = [
    'geraete'            => ['angebote' => 'Angebote/Kostenvoranschläge liegen vor', 'nicht_gekauft' => 'Geräte sind noch nicht angeschafft', 'sportbezug' => 'Geräte dienen unmittelbar der Sportausübung'],
    'bau'                => ['oeiss' => 'Maßnahme entspricht den ÖISS-Bestimmungen', 'plaene' => 'Pläne und Kostenvoranschläge liegen vor', 'keine_kantine' => 'Kein Buffet-/Kantinenbereich enthalten', 'nicht_begonnen' => 'Bau hat noch nicht begonnen'],
    'ausbildung'         => ['zeugnis' => 'Zeugnis/Abschlusszertifikat liegt vor bzw. wird nachgereicht', 'amateursport' => 'Einsatz im Amateursport des Vereins'],
    'jugend'             => ['ueber_ueblich' => 'Maßnahme geht über die übliche Kinder- und Jugendbetreuung hinaus', 'kooperation' => 'Kooperationspartner (z.B. Schule) steht fest'],
    'veranstaltung'      => ['kein_fachverband' => 'Keine Meisterschaft/kein Cup eines Fachverbands', 'kalkulation' => 'Budget/Kalkulation der Veranstaltung liegt vor'],
    'jugendmannschaft'   => ['teilnehmerliste' => 'Teilnehmerliste liegt vor', 'bundesfinale' => 'Teilnahme an Nachwuchs-Bundesfinalspielen bestätigt'],
    'fahrt_allgemein'    => ['ergebnisliste' => 'Ergebnisliste liegt vor'],
    'fahrt_nachwuchs'    => ['ergebnisliste' => 'Ergebnisliste liegt vor'],
    'su_meisterschaften' => ['nominierung' => 'Nominierung durch Landesspartenreferent:in', 'abgestimmt' => 'Mit dem GF Leistungs- und Wettkampfsport abgestimmt'],
    'entsendung'         => ['einladung' => 'Einladung/Nominierung zum internationalen Wettkampf liegt vor'],
    'lehrgang'           => ['kva_referent' => 'Kostenvoranschlag über Landesspartenreferent:in eingereicht'],
    'personifiziert'     => ['plan' => 'Trainings- und Wettkampfplan liegt vor'],
    'bundesliga'         => ['liga' => 'Nachweis der Ligazugehörigkeit (Vorjahr) liegt vor', 'amateur' => 'Keine Profimannschaft'],
    'gruendung'          => ['neu' => 'Verein ist neu gegründetes SPORTUNION-Mitglied'],
    'vb_kurs'            => ['zusaetzlich' => 'Kurs ist zusätzlich zum bestehenden Vereinsprogramm', 'nicht_gestartet' => 'Kurs ist noch nicht gestartet', 'qs_tag' => 'Kurs in der Vereinsdatenbank eingetragen, Qualitätssiegel beantragt, Tag „Vereinsbonus“ zugeordnet'],
    'vb_sozial'          => ['formular' => 'Formular „Soziale Maßnahme“ ausgefüllt', 'doku' => 'Dokumentation mit dem Landesverband abgesprochen'],
    'vb_partner'         => ['kooperation' => 'Kooperationsvereinbarung mit der Partnereinrichtung unterschrieben', 'stundenliste' => 'Stundenliste mit Stempel und Unterschrift wird geführt', 'keine_vs' => 'Partner ist keine Volksschule (diese laufen über die TBE)'],
    'vb_ausbildung'      => ['vorab' => 'Vor Ausbildungsbeginn bekanntgegeben', 'rechnung_verein' => 'Verein übernimmt die Kosten, Rechnung lautet auf den Verein', 'qs_kurs' => 'Übungsleiter:in wird in einem qualitätsgesiegelten Kurs tätig', 'erstausbildung' => 'Es handelt sich um eine Erstausbildung'],
    'vb_fortbildung'     => ['ul_vorhanden' => 'Übungsleiter:innen-Ausbildung ist vorhanden', 'vorab' => 'Vor Beginn bekanntgegeben', 'rechnung_verein' => 'Verein übernimmt die Kosten, Rechnung lautet auf den Verein', 'qs_kurs' => 'Übungsleiter:in ist in einem qualitätsgesiegelten Kurs tätig'],
    'land_betrieb'       => ['fachverband' => 'Verein ist Mitglied eines steirischen, bei Sport Austria anerkannten Landesfachverbands', 'budget' => 'Jahresbudget (Einnahmen/Ausgaben) liegt vor', 'vor_meisterschaft' => 'Antrag vor Meisterschaftsbeginn'],
    'land_nachwuchs'     => ['fachverband' => 'Verein ist Mitglied eines steirischen, bei Sport Austria anerkannten Landesfachverbands', 'nachwuchs_doku' => 'Nachwuchsgruppen, Aktive und Schwerpunkte sind dokumentiert'],
    'land_veranst_nachwuchs' => ['drei_monate' => 'Antrag mind. drei Monate vor Beginn', 'steiermark' => 'Veranstaltung findet in der Steiermark statt', 'budget' => 'Veranstaltungsbudget mit Einnahmen (Startgelder, Sponsoren) liegt vor'],
    'land_veranst_oem'   => ['drei_monate' => 'Antrag mind. drei Monate vor Beginn', 'vergabe' => 'Vergabe durch den Fachverband liegt vor', 'budget' => 'Veranstaltungsbudget mit Einnahmen liegt vor'],
    'land_veranst_leistung' => ['drei_monate' => 'Antrag mind. drei Monate vor Beginn', 'steiermark' => 'Veranstaltung findet in der Steiermark statt', 'budget' => 'Veranstaltungsbudget mit Einnahmen liegt vor'],
    'land_veranst_sonstige' => ['drei_monate' => 'Antrag mind. drei Monate vor Beginn', 'steiermark' => 'Veranstaltung findet in der Steiermark statt', 'budget' => 'Veranstaltungsbudget mit Einnahmen liegt vor'],
    'land_inklusion'     => ['konzept' => 'Konzept des inklusiven Angebots liegt vor'],
    'land_einzelspitzensport' => ['alter' => 'Sportler:in hat das 17. Lebensjahr vollendet', 'ergebnis' => 'Ergebnisliste (EM/WM/EYOF/Olympia) liegt vor', 'stellungnahme' => 'Positive Stellungnahme des Fachverbandspräsidenten liegt vor'],
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

/** Programm einer Förderart: „landesverband“ (A), „vereinsbonus“ (B) oder „land“ (C). */
function suProgramm(string $foerderart): string
{
    $bereich = suFoerderart($foerderart)['bereich'];
    return in_array($bereich, ['vereinsbonus', 'land'], true) ? $bereich : 'landesverband';
}

/** Förderstelle, bei der eine Förderart eingereicht wird: „sportunion“ oder „land“. */
function suStelle(string $foerderart): string
{
    return SU_PROGRAMME[suProgramm($foerderart)]['stelle'];
}

/** Höchstbetrag einer Land-Förderart: fester Maximalbetrag oder Prozentsatz des Budgets (Kostenaufstellung). */
function suLandMax(array $art, string $kostenSumme): ?string
{
    if (isset($art['max'])) return moneyRound($art['max']);
    if (!empty($art['max_prozent_kosten']) && bccomp($kostenSumme, '0', 2) > 0) {
        return bcdiv(bcmul($kostenSumme, (string)$art['max_prozent_kosten'], 2), '100', 2);
    }
    return null;
}

/** Abgehakte Voraussetzungen einer Position (JSON-Spalte „checks“). */
function suChecks(array $pos): array
{
    $liste = json_decode((string)($pos['checks'] ?? ''), true);
    return is_array($liste) ? $liste : [];
}

/** Höchstbetrag einer „deckel“-Förderart (Höchstsatz × Menge, falls die Menge zählt). */
function suDeckel(array $pos): string
{
    $art   = suFoerderart($pos['foerderart']);
    $menge = !empty($art['menge_zaehlt']) ? max(1, (int)($pos['anzahl_personen'] ?? 1)) : 1;
    return moneyRound($menge * $art['max_je']);
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

        case 'land':
            $max    = suLandMax($art, $kostenSumme);
            $betrag = moneyRound($art['standard']);
            if ($max !== null && bccomp($betrag, $max, 2) > 0) $betrag = $max;
            if (bccomp($kostenSumme, '0', 2) > 0 && bccomp($betrag, $luecke, 2) > 0) $betrag = $luecke;
            $rahmen = moneyFormat($art['min']) . ' bis ' . (isset($art['max']) ? moneyFormat($art['max']) : 'max. ' . $art['max_prozent_kosten'] . ' % des Budgets' . ($max !== null ? ' (' . moneyFormat($max) . ')' : ''));
            return ['betrag' => $betrag, 'erklaerung' => 'Standardförderung ' . moneyFormat($art['standard']) . ', Bandbreite ' . $rahmen . '; Zu-/Abschläge legt das Land fest.'];

        case 'deckel':
            $deckel = suDeckel($pos);
            $menge  = !empty($art['menge_zaehlt']) ? max(1, (int)($pos['anzahl_personen'] ?? 1)) : 1;
            $text   = ($menge > 1 ? $menge . ' × ' : '') . 'max. ' . moneyFormat($art['max_je']) . ' = ' . moneyFormat($deckel);
            if (bccomp($kostenSumme, '0', 2) <= 0) return ['betrag' => $deckel, 'erklaerung' => $text . ' (Höchstbetrag; abgerechnet werden nur tatsächliche Belege).'];
            $betrag = bccomp($luecke, $deckel, 2) > 0 ? $deckel : $luecke;
            return ['betrag' => $betrag, 'erklaerung' => 'Finanzierungslücke ' . moneyFormat($luecke) . ', gedeckelt auf ' . $text . '.'];

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
    $land = suProgramm($pos['foerderart']) === 'land';
    if (in_array($art['berechnung'], ['rahmen', 'ermessen', 'land'], true) && empty($kosten)) {
        $m[] = ['typ' => 'fehler', 'text' => $land
            ? 'Kosten-/Budgetaufstellung fehlt – das Land verlangt eine Einnahmen-Ausgaben-Aufstellung' . (!empty($art['kosten_hinweis']) ? ' (' . $art['kosten_hinweis'] . ').' : '.')
            : 'Kostenaufstellung fehlt – die kostenmäßige Darstellung ist Pflicht.'];
    }
    if ($art['berechnung'] === 'land') {
        $max = suLandMax($art, $kostenSumme);
        if ($max !== null && bccomp($beantragt, $max, 2) > 0) {
            $m[] = ['typ' => 'fehler', 'text' => 'Beantragter Betrag über dem Höchstbetrag von ' . moneyFormat($max) . (isset($art['max']) ? '.' : ' (' . $art['max_prozent_kosten'] . ' % des Budgets).')];
        }
        if (bccomp($beantragt, '0', 2) > 0 && bccomp($beantragt, (string)$art['min'], 2) < 0) {
            $m[] = ['typ' => 'info', 'text' => 'Betrag unter der Mindestförderung von ' . moneyFormat($art['min']) . ' – prüfen, ob sich der Antrag lohnt.'];
        }
    }
    if ($land && bccomp($kostenSumme, '0', 2) > 0 && bccomp(moneySum([$beantragt, $pos['andere_foerderungen']]), $kostenSumme, 2) >= 0) {
        $m[] = ['typ' => 'warnung', 'text' => 'Das Land fördert keine Leistungen, die zur Gänze aus Förderungen finanziert werden – Eigenmittel (Mitgliedsbeiträge, Startgelder, Sponsoren) ausweisen.'];
    }
    if (!empty($art['vorlauf_monate']) && $entwurf && !empty($pos['massnahme_von'])
        && $pos['massnahme_von'] < date('Y-m-d', strtotime('+' . $art['vorlauf_monate'] . ' months'))) {
        $m[] = ['typ' => 'warnung', 'text' => 'Veranstaltungsanträge müssen spätestens ' . $art['vorlauf_monate'] . ' Monate vor Beginn gestellt werden (spätestens ' . date('d.m.Y', strtotime('-' . $art['vorlauf_monate'] . ' months', strtotime($pos['massnahme_von']))) . ').'];
    }
    if ($art['berechnung'] === 'ausbildung' && empty($pos['ausbildungsstufe'])) {
        $m[] = ['typ' => 'fehler', 'text' => 'Bitte die Ausbildungsstufe wählen (bestimmt den Fixbetrag).'];
    }
    if ($art['berechnung'] === 'pro_person' && (int)$pos['anzahl_personen'] < 1) {
        $m[] = ['typ' => 'fehler', 'text' => 'Bitte die Anzahl der Teilnehmer:innen angeben und die Teilnehmerliste beilegen.'];
    }
    if ($art['berechnung'] === 'deckel' && empty($kosten)) {
        $m[] = ['typ' => 'info', 'text' => 'Ohne Kostenaufstellung wird der Höchstbetrag angesetzt – abgerechnet werden nur tatsächliche Belege (PRAE, Material, Hallenkosten bzw. Rechnung).'];
    }
    if ($art['berechnung'] === 'deckel' && bccomp($beantragt, suDeckel($pos), 2) > 0) {
        $m[] = ['typ' => 'fehler', 'text' => 'Beantragter Betrag über dem Höchstbetrag von ' . moneyFormat(suDeckel($pos)) . '.'];
    }
    if ($pos['foerderart'] === 'vb_sozial' && empty($pos['kategorie'])) {
        $m[] = ['typ' => 'fehler', 'text' => 'Bitte die Kategorie der sozialen Maßnahme wählen (Inklusion, Integration, Gendergerechtigkeit, soziale Verantwortung).'];
    }
    if ($pos['foerderart'] === 'vb_partner' && (int)$pos['anzahl_personen'] < 1) {
        $m[] = ['typ' => 'fehler', 'text' => 'Bitte die geplante Anzahl der Einheiten angeben (max. € 30,– je Einheit).'];
    }
    // Abgehakte Voraussetzungen je Förderart
    $erfuellt = suChecks($pos);
    foreach (SU_CHECKS[$pos['foerderart']] ?? [] as $schluessel => $label) {
        if (!in_array($schluessel, $erfuellt, true)) $m[] = ['typ' => 'warnung', 'text' => 'Voraussetzung offen: ' . $label . '.'];
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
    if (bccomp($kostenSumme, '0', 2) > 0 && in_array($art['berechnung'], ['rahmen', 'ermessen', 'deckel', 'land'], true)) {
        $finanziert = moneySum([$pos['eigenmittel'], $pos['andere_foerderungen'], $beantragt]);
        $diff = bcsub($kostenSumme, $finanziert, 2);
        if (bccomp($diff, '0', 2) !== 0) {
            $m[] = ['typ' => 'warnung', 'text' => 'Finanzierungsplan nicht ausgeglichen: ' . (bccomp($diff, '0', 2) > 0 ? moneyFormat($diff) . ' ungedeckt' : moneyFormat(bcmul($diff, '-1', 2)) . ' überfinanziert') . ' – Eigenmittel anpassen.'];
        }
    }
    if (!$land && bccomp($beantragt, (string)SU_FINANZIERUNGSPLAN_AB, 2) >= 0) {
        $m[] = ['typ' => 'info', 'text' => 'Ab ' . moneyFormat(SU_FINANZIERUNGSPLAN_AB) . ' ist ein Finanzierungsplan verpflichtend – er ist im PDF enthalten.'];
    }

    // Kostenpositionen (das Land hat eine eigene, strengere Liste nicht förderbarer Kosten)
    $ausschluss = $land ? SU_LAND_NICHT_FOERDERBAR + SU_NICHT_FOERDERBAR : SU_NICHT_FOERDERBAR;
    foreach ($kosten as $k) {
        $bez = mb_strtolower($k['bezeichnung']);
        foreach ($ausschluss as $begriff => $grund) {
            if (str_contains($bez, $begriff)) {
                $m[] = ['typ' => 'warnung', 'text' => '„' . $k['bezeichnung'] . '“: ' . $grund . ' werden ' . ($land ? 'vom Land Steiermark' : 'laut Abrechnungsrichtlinien') . ' nicht anerkannt.'];
                break;
            }
        }
        if (!$land && (float)$k['einzelpreis'] > SU_ANLAGENVERZEICHNIS_AB) {
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
        'vb_ausbildung' => 'Ausbildungen können auch über die Landesverbandsförderung (Fixbetrag nach Abschluss) unterstützt werden – dieselbe Rechnung nicht doppelt abrechnen.',
        'ausbildung'    => 'Übungsleiter:innen-Ausbildungen sind auch über den Vereinsbonus (bis € 314,–, Antrag VOR Beginn) förderbar – dieselbe Rechnung nicht doppelt abrechnen.',
        'land_betrieb'  => 'Reine Hobby-/Breitensportvereine fördert das Land nur ausnahmsweise – ohne Mitgliedschaft in einem Landesfachverband (z.B. Tischtennis) vorab mit dem Referat Sport klären.',
        'land_nachwuchs' => 'Bei der SPORTUNION geförderte Nachwuchsprojekte (z.B. Jugendarbeit) im Land-Antrag als „andere Förderung“ angeben.',
    ];
    if (isset($hinweise[$pos['foerderart']])) $m[] = ['typ' => 'info', 'text' => $hinweise[$pos['foerderart']]];

    return $m;
}

/** Prüfung auf Ebene des Ansuchens (Vertreter, Erklärungen, Gesamtbetrag, Vereinsbonus-Voraussetzungen). */
function suPruefeAntrag(array $antrag, string $beantragtGesamt, int $anzahlPositionen, bool $mitVereinsbonus = false, array $landPositionen = []): array
{
    $m = [];
    if ($landPositionen) {
        $arten = array_column($landPositionen, 'foerderart');
        if (count(array_intersect($arten, ['land_betrieb', 'land_nachwuchs'])) > 1 || count(array_keys($arten, 'land_betrieb')) > 1 || count(array_keys($arten, 'land_nachwuchs')) > 1) {
            $m[] = ['typ' => 'info', 'text' => 'Land: Trainings-/Wettkampfbetrieb und Nachwuchsarbeit in EINEM Online-Antrag einbringen – pro Kalenderjahr ist nur ein Vereinsförderungsantrag möglich.'];
        }
        $landSumme = moneySum(array_column($landPositionen, 'beantragt'));
        $stufe = bccomp($landSumme, (string)SU_LAND_BAGATELLGRENZE, 2) <= 0
            ? 'bis ' . moneyFormat(SU_LAND_BAGATELLGRENZE) . ' gilt die Bagatellgrenze (kein Verwendungsnachweis, nur Stichproben)'
            : (bccomp($landSumme, (string)SU_LAND_NACHWEIS_EINFACH, 2) <= 0
                ? 'bis ' . moneyFormat(SU_LAND_NACHWEIS_EINFACH) . ' reichen Tätigkeits-/Projektbericht und Einnahmen-Ausgaben-Aufstellung'
                : 'über ' . moneyFormat(SU_LAND_NACHWEIS_EINFACH) . ' zusätzlich Belegaufstellungen mit Originalrechnungen und Zahlungsnachweisen');
        $m[] = ['typ' => 'info', 'text' => 'Land Steiermark: Antrag ausschließlich online (egov.stmk.gv.at), Förderungsvertrag binnen 1 Monat retournieren, Verwendungsnachweis grundsätzlich 2 Monate nach Ende; ' . $stufe . '.'];
    }
    if ($anzahlPositionen === 0) $m[] = ['typ' => 'fehler', 'text' => 'Noch keine Fördergegenstände erfasst.'];
    if ($mitVereinsbonus) {
        if (empty($antrag['vb_fit_siegel'])) $m[] = ['typ' => 'fehler', 'text' => 'Vereinsbonus: Der Verein braucht mindestens ein aktives Fit-Sport-Austria-Qualitätssiegel.'];
        if (empty($antrag['vb_beratung']))   $m[] = ['typ' => 'warnung', 'text' => 'Vereinsbonus: Vor der ersten Förderung ist ein Beratungsgespräch mit dem Landesverband verpflichtend.'];
        $m[] = ['typ' => 'info', 'text' => 'Vereinsbonus: Andere Landesverbände nennen für 2026 die Fristen 31.03. (Sommersemester) und 30.09. (Herbst); die Frist für die Steiermark beim Landesverband bestätigen. Anträge immer vor Beginn der Maßnahme.'];
    }
    if (trim((string)$antrag['obmann_name']) === '' || trim((string)$antrag['vertreter2_name']) === '') {
        $m[] = ['typ' => 'fehler', 'text' => 'Statutarische Vertreter fehlen – Ansuchen stellen Obmann/Obfrau gemeinsam mit Kassier:in oder Schriftführer:in.'];
    }
    // SPORTUNION-Erklärungen und Berichtspflicht betreffen nur die bei der SPORTUNION eingereichten Positionen
    $mitSportunion = $anzahlPositionen > count($landPositionen);
    $offen = array_filter(array_keys(SU_ERKLAERUNGEN), fn($k) => empty($antrag[$k]));
    if ($mitSportunion && $offen) $m[] = ['typ' => 'warnung', 'text' => count($offen) . ' von ' . count(SU_ERKLAERUNGEN) . ' SPORTUNION-Erklärungen noch nicht bestätigt (Voraussetzung für die Förderwürdigkeit).'];
    if (empty($antrag['iban'])) $m[] = ['typ' => 'warnung', 'text' => 'Bankverbindung für die Auszahlung fehlt (Auszahlung nur auf ein Vereinskonto).'];
    $beantragtSportunion = bcsub($beantragtGesamt, moneySum(array_column($landPositionen, 'beantragt')), 2);
    if (bccomp($beantragtSportunion, (string)SU_BERICHT_AB, 2) >= 0) {
        $m[] = ['typ' => 'info', 'text' => 'Ab ' . moneyFormat(SU_BERICHT_AB) . ' Förderung ist bei der Abrechnung zusätzlich ein Bericht über die geförderte Maßnahme beizulegen.'];
    }
    return $m;
}
