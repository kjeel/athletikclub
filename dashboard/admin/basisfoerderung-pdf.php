<?php
/**
 * Athletikclub Steiermark – Admin: Förderansuchen an die SPORTUNION Steiermark als PDF
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/money.php';
require_once ROOT_PATH . '/includes/basisfoerderung.php';

requireAdmin();

require_once ROOT_PATH . '/vendor/autoload.php';

$db        = getDB();
$antrag_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM su_antraege WHERE id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([$antrag_id, currentOrgId()]);
$a = $stmt->fetch();
if (!$a) { http_response_code(404); die('Förderansuchen nicht gefunden.'); }

$stmt = $db->prepare('SELECT * FROM su_antrag_positionen WHERE antrag_id = ? ORDER BY sortierung ASC, id ASC');
$stmt->execute([$antrag_id]);
$positionen = $stmt->fetchAll();

$kosten_je_position = [];
if ($positionen) {
    $ids = array_column($positionen, 'id');
    $stmt = $db->prepare('SELECT * FROM su_antrag_kosten WHERE position_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sortierung ASC, id ASC');
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $k) $kosten_je_position[$k['position_id']][] = $k;
}
foreach ($positionen as &$p) {
    $p['kosten']      = $kosten_je_position[$p['id']] ?? [];
    $p['kostensumme'] = suKostenSumme($p['kosten']);
    $p['beantragt']   = suBeantragt($p, $p['kostensumme']);
}
unset($p);
// Landesverbandsförderung (A) vor Vereinsbonus (B), innerhalb stabil in Erfassungsreihenfolge
usort($positionen, fn($a, $b) => [suProgramm($a['foerderart']) === 'vereinsbonus', $a['sortierung'], $a['id']] <=> [suProgramm($b['foerderart']) === 'vereinsbonus', $b['sortierung'], $b['id']]);
$mit_vereinsbonus = in_array('vereinsbonus', array_map(fn($p) => suProgramm($p['foerderart']), $positionen), true);

$kosten_gesamt    = moneySum(array_column($positionen, 'kostensumme'));
$beantragt_gesamt = moneySum(array_column($positionen, 'beantragt'));
$eigen_gesamt     = moneySum(array_column($positionen, 'eigenmittel'));
$andere_gesamt    = moneySum(array_column($positionen, 'andere_foerderungen'));
$vertreter2_label = $a['vertreter2_funktion'] === 'schriftfuehrer' ? 'Schriftführer:in' : 'Kassier:in';

class SuPDF extends TCPDF
{
    public string $headerTitel = '';
    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->tcpdflink = false; // kein "Powered by TCPDF"-Vermerk
    }

    public function Header()
    {
        $this->SetFont('dejavusans', 'B', 12);
        $this->SetTextColor(31, 53, 86);
        $this->Cell(0, 8, $this->headerTitel, 0, 1, 'L');
        $this->SetDrawColor(198, 161, 53);
        $this->SetLineWidth(0.6);
        $this->Line(15, 17, 195, 17);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 10, APP_NAME . ' · ZVR ' . VEREIN_ZVR . ' · Förderansuchen an die SPORTUNION Steiermark · Seite ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }

    public function feldZeile(string $label, ?string $wert, float $labelWidth = 55): void
    {
        $this->SetFont('dejavusans', 'B', 9.5);
        $this->Cell($labelWidth, 6, $label, 0, 0, 'L');
        $this->SetFont('dejavusans', '', 9.5);
        $this->MultiCell(0, 6, $wert !== null && $wert !== '' ? $wert : '–', 0, 'L');
    }

    public function abschnitt(string $titel): void
    {
        if ($this->GetY() > 250) $this->AddPage();
        $this->Ln(3);
        $this->SetFont('dejavusans', 'B', 11);
        $this->SetFillColor(31, 53, 86);
        $this->SetTextColor(255, 255, 255);
        // MultiCell, damit lange Überschriften umbrechen statt abgeschnitten zu werden
        $this->MultiCell(0, 8, '  ' . $titel, 0, 'L', true, 1, '', '', true, 0, false, true, 0, 'M');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    public function unterTitel(string $titel): void
    {
        $this->Ln(1);
        $this->SetFont('dejavusans', 'B', 9.5);
        $this->SetTextColor(31, 53, 86);
        $this->Cell(0, 6, $titel, 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    public function absatz(string $text, float $size = 9.5): void
    {
        $this->SetFont('dejavusans', '', $size);
        $this->MultiCell(0, 5, $text, 0, 'L');
    }
}

$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$th = 'style="background-color:#EEF1F5; font-weight:bold;"';

$pdf = new SuPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->headerTitel = 'FÖRDERANSUCHEN SPORTUNION STEIERMARK ' . $a['jahr'];
$pdf->SetCreator(APP_NAME);
$pdf->SetAuthor(APP_NAME);
$pdf->SetTitle($a['titel']);
$pdf->SetMargins(15, 22, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ----------------------------------------------------------------
// Empfänger / Antragsteller
// ----------------------------------------------------------------
$pdf->SetFont('dejavusans', '', 9.5);
$pdf->MultiCell(0, 5, "An die\nSPORTUNION Steiermark\nGaußgasse 3, 8010 Graz", 0, 'L');
$pdf->Ln(2);
$pdf->SetFont('dejavusans', 'B', 14);
$pdf->MultiCell(0, 7, $a['titel'], 0, 'L');
$pdf->SetFont('dejavusans', '', 9.5);
$pdf->MultiCell(0, 5, 'Förderzeitraum 01.01.–31.12.' . $a['jahr'] . ' · erstellt am ' . date('d.m.Y'), 0, 'L');

$nr = 1; // Abschnittsnummer, zählt nur tatsächlich gedruckte Abschnitte
$pdf->abschnitt($nr++ . '. Antragsteller');
$pdf->feldZeile('Verein:', APP_NAME);
$pdf->feldZeile('ZVR-Zahl:', VEREIN_ZVR);
$pdf->feldZeile('Vereinsadresse:', VEREIN_ADRESSE);
$pdf->feldZeile('Dachverband:', 'SPORTUNION Steiermark');
$pdf->feldZeile('Sportarten / Sparten:', $a['sportarten']);
$mitglieder = $a['mitglieder_gesamt'] !== null ? $a['mitglieder_gesamt'] . ($a['mitglieder_jugend'] !== null ? ', davon ' . $a['mitglieder_jugend'] . ' unter 19 Jahren' : '') : null;
$pdf->feldZeile('Mitglieder:', $mitglieder);
$pdf->feldZeile('Obmann / Obfrau:', $a['obmann_name']);
$pdf->feldZeile($vertreter2_label . ':', $a['vertreter2_name']);
$pdf->feldZeile('Ansprechperson:', implode(' · ', array_filter([$a['kontakt_name'], $a['kontakt_email'], $a['kontakt_telefon']])));
$pdf->feldZeile('Bankverbindung:', implode(' · ', array_filter([$a['kontoinhaber'], $a['iban'], $a['bic'], $a['bank']])));

if (!empty($a['vereinsbeschreibung'])) {
    $pdf->abschnitt($nr++ . '. Vorstellung des Vereins');
    $pdf->absatz($a['vereinsbeschreibung']);
}

// ----------------------------------------------------------------
// Übersicht
// ----------------------------------------------------------------
$pdf->abschnitt($nr++ . '. Übersicht der beantragten Förderungen');
if (empty($positionen)) {
    $pdf->absatz('Keine Fördergegenstände erfasst.');
} else {
    $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;"><thead><tr>'
        . '<td ' . $th . ' width="6%">Nr.</td>'
        . '<td ' . $th . ' width="24%">Förderart</td>'
        . '<td ' . $th . ' width="38%">Vorhaben</td>'
        . '<td ' . $th . ' width="16%" align="right">Kosten</td>'
        . '<td ' . $th . ' width="16%" align="right">Beantragt</td>'
        . '</tr></thead><tbody>';
    // Zeilen nach Programm gruppiert, mit Zwischensumme je Programm
    foreach (['landesverband' => 'A', 'vereinsbonus' => 'B'] as $programm => $buchstabe) {
        $gruppe = array_filter($positionen, fn($p) => suProgramm($p['foerderart']) === $programm);
        if (!$gruppe) continue;
        $html .= '<tr style="background-color:#F7F3E6;"><td width="100%" colspan="5"><b>' . $buchstabe . ' · ' . $h(SU_PROGRAMME[$programm]['label']) . '</b></td></tr>';
        foreach ($gruppe as $i => $p) {
            $art = suFoerderart($p['foerderart']);
            $unter = $p['kategorie'] ? (SU_SOZIAL_KATEGORIEN[$p['kategorie']] ?? '')
                : ($art['berechnung'] === 'deckel' ? 'max. ' . moneyFormat($art['max_je']) . ' je ' . $art['je'] : (SU_BEREICHE[$art['bereich']] ?? ''));
            $html .= '<tr nobr="true">'
                . '<td width="6%">' . ($i + 1) . '</td>'
                . '<td width="24%">' . $h(preg_replace('/^Vereinsbonus: /', '', $art['label'])) . '<br><span style="color:#777777;">' . $h($unter) . '</span></td>'
                . '<td width="38%">' . $h($p['titel']) . '</td>'
                . '<td width="16%" align="right">' . (bccomp($p['kostensumme'], '0', 2) > 0 ? $h(moneyFormat($p['kostensumme'])) : '–') . '</td>'
                . '<td width="16%" align="right"><b>' . $h(moneyFormat($p['beantragt'])) . '</b></td>'
                . '</tr>';
        }
        if (count($gruppe) < count($positionen)) {
            $html .= '<tr><td width="68%" colspan="3" align="right"><i>Zwischensumme ' . $buchstabe . '</i></td>'
                . '<td width="16%" align="right">' . $h(moneyFormat(moneySum(array_column($gruppe, 'kostensumme')))) . '</td>'
                . '<td width="16%" align="right">' . $h(moneyFormat(moneySum(array_column($gruppe, 'beantragt')))) . '</td></tr>';
        }
    }
    $html .= '<tr style="font-weight:bold;"><td width="68%" colspan="3">Summe</td>'
        . '<td width="16%" align="right">' . $h(moneyFormat($kosten_gesamt)) . '</td>'
        . '<td width="16%" align="right">' . $h(moneyFormat($beantragt_gesamt)) . '</td></tr></tbody></table>';
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->writeHTML($html, true, false, false, false, '');
}

// ----------------------------------------------------------------
// Fördergegenstände im Detail
// ----------------------------------------------------------------
foreach ($positionen as $i => $p) {
    $art = suFoerderart($p['foerderart']);
    $pdf->abschnitt($nr . '.' . ($i + 1) . ' ' . preg_replace('/^Vereinsbonus: /', 'Vereinsbonus – ', $art['label']) . ': ' . $p['titel']);

    $zeitraum = null;
    if ($p['massnahme_von'] || $p['massnahme_bis']) {
        $zeitraum = ($p['massnahme_von'] ? date('d.m.Y', strtotime($p['massnahme_von'])) : '…') . ' – ' . ($p['massnahme_bis'] ? date('d.m.Y', strtotime($p['massnahme_bis'])) : '…');
    }
    $labels = $art['feld_labels'] ?? [];
    $pdf->feldZeile('Programm:', SU_PROGRAMME[suProgramm($p['foerderart'])]['label']);
    if ($art['berechnung'] === 'deckel') {
        $pdf->feldZeile('Förderhöchstbetrag:', 'max. ' . moneyFormat($art['max_je']) . ' je ' . $art['je']);
    } else {
        $pdf->feldZeile('Förderbereich:', SU_BEREICHE[$art['bereich']] ?? '');
    }
    if ($p['kategorie']) $pdf->feldZeile('Kategorie:', SU_SOZIAL_KATEGORIEN[$p['kategorie']] ?? $p['kategorie']);
    if ($zeitraum) $pdf->feldZeile('Zeitraum der Maßnahme:', $zeitraum);
    if ($p['ausbildungsstufe']) $pdf->feldZeile('Ausbildungsstufe:', SU_AUSBILDUNG_SAETZE[$p['ausbildungsstufe']]['label'] ?? $p['ausbildungsstufe']);
    if ($p['anzahl_personen'] !== null) $pdf->feldZeile(isset($labels['anzahl_personen']) ? $labels['anzahl_personen'] . ':' : ($art['berechnung'] === 'ausbildung' ? 'Absolvent:innen:' : 'Teilnehmer:innen:'), $p['anzahl_personen'] . (!empty($p['mit_uebernachtung']) ? ' (mit Übernachtung)' : ''));
    if ($p['wettkampf']) $pdf->feldZeile(isset($labels['wettkampf']) ? preg_replace('/ \(.*\)$/', '', $labels['wettkampf']) . ':' : ($art['berechnung'] === 'ausbildung' ? 'Ausbildung:' : 'Wettkampf / Veranstaltung:'), $p['wettkampf']);
    if ($p['platzierung']) $pdf->feldZeile('Platzierung:', $p['platzierung']);

    if (!empty($p['beschreibung'])) { $pdf->unterTitel('Beschreibung'); $pdf->absatz($p['beschreibung']); }
    if (!empty($p['nutzen']))       { $pdf->unterTitel('Ziel und Nutzen'); $pdf->absatz($p['nutzen']); }

    if ($p['kosten']) {
        $pdf->unterTitel('Kostenaufstellung');
        $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;"><thead><tr>'
            . '<td ' . $th . ' width="40%">Bezeichnung</td>'
            . '<td ' . $th . ' width="10%" align="right">Menge</td>'
            . '<td ' . $th . ' width="15%" align="right">Einzelpreis</td>'
            . '<td ' . $th . ' width="20%">Anbieter / Angebot</td>'
            . '<td ' . $th . ' width="15%" align="right">Summe</td>'
            . '</tr></thead><tbody>';
        foreach ($p['kosten'] as $k) {
            $html .= '<tr nobr="true">'
                . '<td width="40%">' . $h($k['bezeichnung']) . '</td>'
                . '<td width="10%" align="right">' . $h(rtrim(rtrim(number_format((float)$k['menge'], 2, ',', '.'), '0'), ',')) . '</td>'
                . '<td width="15%" align="right">' . $h(moneyFormat($k['einzelpreis'])) . '</td>'
                . '<td width="20%">' . $h($k['anbieter'] ?? '') . '</td>'
                . '<td width="15%" align="right">' . $h(moneyFormat(bcmul((string)$k['menge'], (string)$k['einzelpreis'], 2))) . '</td>'
                . '</tr>';
        }
        $html .= '<tr style="font-weight:bold;"><td width="85%" colspan="4">Gesamtkosten</td><td width="15%" align="right">' . $h(moneyFormat($p['kostensumme'])) . '</td></tr></tbody></table>';
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($html, true, false, false, false, '');
    }

    // Finanzierungsplan (ab € 1.500,– Pflicht, bei Kostenaufstellung immer mitgedruckt)
    if ($p['kosten'] || bccomp($p['beantragt'], (string)SU_FINANZIERUNGSPLAN_AB, 2) >= 0) {
        $pdf->unterTitel('Finanzierungsplan' . (bccomp($p['beantragt'], (string)SU_FINANZIERUNGSPLAN_AB, 2) >= 0 ? ' (verpflichtend ab ' . moneyFormat(SU_FINANZIERUNGSPLAN_AB) . ')' : ''));
        $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;">'
            . '<tr><td width="70%">Eigenmittel des Vereins</td><td width="30%" align="right">' . $h(moneyFormat($p['eigenmittel'])) . '</td></tr>'
            . '<tr><td width="70%">Andere Förderungen' . ($p['andere_foerderungen_text'] ? ' (' . $h($p['andere_foerderungen_text']) . ')' : '') . '</td><td width="30%" align="right">' . $h(moneyFormat($p['andere_foerderungen'])) . '</td></tr>'
            . '<tr><td width="70%"><b>Beantragte Förderung SPORTUNION Steiermark</b></td><td width="30%" align="right"><b>' . $h(moneyFormat($p['beantragt'])) . '</b></td></tr>'
            . '<tr style="background-color:#EEF1F5;"><td width="70%"><b>Gesamtfinanzierung</b></td><td width="30%" align="right"><b>' . $h(moneyFormat(moneySum([$p['eigenmittel'], $p['andere_foerderungen'], $p['beantragt']]))) . '</b></td></tr>'
            . '</table>';
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($html, true, false, false, false, '');
    } else {
        $pdf->feldZeile('Beantragte Förderung:', moneyFormat($p['beantragt']));
    }

    // Voraussetzungen der Förderart mit Erfüllungsstand
    $checks = SU_CHECKS[$p['foerderart']] ?? [];
    if ($checks) {
        $pdf->unterTitel('Voraussetzungen');
        $erfuellt = suChecks($p);
        $pdf->SetFont('dejavusans', '', 8.5);
        foreach ($checks as $schluessel => $label) {
            $pdf->MultiCell(8, 4.5, in_array($schluessel, $erfuellt, true) ? '☒' : '☐', 0, 'L', false, 0);
            $pdf->MultiCell(0, 4.5, $label, 0, 'L');
        }
    }
}

if ($positionen) $nr++; // die Detailabschnitte teilen sich eine Nummer (z.B. 3.1, 3.2 …)

// ----------------------------------------------------------------
// Gesamtfinanzierung
// ----------------------------------------------------------------
if (count($positionen) > 1) {
    $pdf->abschnitt($nr++ . '. Gesamtfinanzierung');
    $html = '<table border="0.3" cellpadding="3" style="font-size:9pt;">'
        . '<tr><td width="70%">Gesamtkosten aller Vorhaben</td><td width="30%" align="right">' . $h(moneyFormat($kosten_gesamt)) . '</td></tr>'
        . '<tr><td width="70%">Eigenmittel des Vereins</td><td width="30%" align="right">' . $h(moneyFormat($eigen_gesamt)) . '</td></tr>'
        . '<tr><td width="70%">Andere Förderungen</td><td width="30%" align="right">' . $h(moneyFormat($andere_gesamt)) . '</td></tr>'
        . ($mit_vereinsbonus
            ? '<tr><td width="70%">davon Landesverbandsförderung (A)</td><td width="30%" align="right">' . $h(moneyFormat(moneySum(array_column(array_filter($positionen, fn($p) => suProgramm($p['foerderart']) === 'landesverband'), 'beantragt')))) . '</td></tr>'
            . '<tr><td width="70%">davon SPORTUNION Vereinsbonus (B)</td><td width="30%" align="right">' . $h(moneyFormat(moneySum(array_column(array_filter($positionen, fn($p) => suProgramm($p['foerderart']) === 'vereinsbonus'), 'beantragt')))) . '</td></tr>'
            : '')
        . '<tr style="background-color:#EEF1F5;"><td width="70%"><b>Beantragte Förderung SPORTUNION Steiermark gesamt</b></td><td width="30%" align="right"><b>' . $h(moneyFormat($beantragt_gesamt)) . '</b></td></tr>'
        . '</table>';
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->writeHTML($html, true, false, false, false, '');
}

// ----------------------------------------------------------------
// Erklärungen
// ----------------------------------------------------------------
$pdf->abschnitt($nr++ . '. Erklärungen des Vereins');
$pdf->SetFont('dejavusans', '', 9);
foreach (SU_ERKLAERUNGEN as $feld => $text) {
    $pdf->MultiCell(8, 5, !empty($a[$feld]) ? '☒' : '☐', 0, 'L', false, 0);
    $pdf->MultiCell(0, 5, $text, 0, 'L');
    $pdf->Ln(1);
}
$pdf->MultiCell(8, 5, '☒', 0, 'L', false, 0);
$pdf->MultiCell(0, 5, 'Die eingereichten Belege werden bei keinem anderen Förderungsgeber zur Abrechnung vorgelegt und nicht durch Dritte übernommen.', 0, 'L');
if (in_array('bau', array_column($positionen, 'foerderart'), true)) {
    $pdf->Ln(1);
    $pdf->MultiCell(8, 5, '☒', 0, 'L', false, 0);
    $pdf->MultiCell(0, 5, 'Bausubvention: Scheidet der Verein innerhalb der dem Förderjahr folgenden neun Jahre aus der SPORTUNION Steiermark aus, wird die Subvention anteilig (1/10 je offenem Jahr) rückerstattet.', 0, 'L');
}
if ($mit_vereinsbonus) {
    foreach ([
        'vb_fit_siegel' => 'Vereinsbonus: Der Verein hat mindestens ein aktives Fit-Sport-Austria-Qualitätssiegel.',
        'vb_beratung'   => 'Vereinsbonus: Das Beratungsgespräch mit dem Landesverband wurde geführt; der Verein nimmt am Evaluationsgespräch teil.',
    ] as $feld => $text) {
        $pdf->Ln(1);
        $pdf->MultiCell(8, 5, !empty($a[$feld]) ? '☒' : '☐', 0, 'L', false, 0);
        $pdf->MultiCell(0, 5, $text, 0, 'L');
    }
}

// ----------------------------------------------------------------
// Beilagen
// ----------------------------------------------------------------
$beilagen = [];
foreach ($positionen as $p) {
    $art = $p['foerderart'];
    if (in_array($art, ['geraete', 'bau', 'lehrgang'], true)) $beilagen[] = 'Kostenvoranschläge / Angebote';
    if (in_array($art, ['jugendmannschaft', 'su_meisterschaften'], true)) $beilagen[] = 'Teilnehmerliste';
    if (in_array($art, ['fahrt_allgemein', 'fahrt_nachwuchs'], true)) $beilagen[] = 'Ergebnisliste der Meisterschaft';
    if ($art === 'ausbildung') $beilagen[] = 'Kopie des Zeugnisses / Abschlusszertifikats (sofern bereits abgeschlossen)';
    if ($art === 'bundesliga') $beilagen[] = 'Nachweis der Ligazugehörigkeit';
    if ($art === 'vb_kurs') $beilagen[] = 'Kurseintrag in der Vereinsdatenbank mit Qualitätssiegel-Antrag (Tag „Vereinsbonus“)';
    if ($art === 'vb_sozial') $beilagen[] = 'Formular „Soziale Maßnahme“';
    if ($art === 'vb_partner') $beilagen[] = 'Kooperationsvereinbarung mit der Partnereinrichtung';
    if (in_array($art, ['vb_ausbildung', 'vb_fortbildung'], true)) $beilagen[] = 'Anmeldung/Ausschreibung der Aus- bzw. Fortbildung (Nachweis folgt nach Abschluss)';
}
if (bccomp($beantragt_gesamt, (string)SU_FINANZIERUNGSPLAN_AB, 2) >= 0) $beilagen[] = 'Finanzierungsplan (in diesem Ansuchen enthalten)';
$beilagen = array_values(array_unique($beilagen));
if ($beilagen) {
    $pdf->abschnitt($nr++ . '. Beilagen');
    $pdf->SetFont('dejavusans', '', 9);
    foreach ($beilagen as $b) {
        $pdf->MultiCell(8, 5, '☐', 0, 'L', false, 0);
        $pdf->MultiCell(0, 5, $b, 0, 'L');
    }
}

// ----------------------------------------------------------------
// Unterschriften der statutarischen Vertreter
// ----------------------------------------------------------------
if ($pdf->GetY() > 235) $pdf->AddPage();
$pdf->Ln(16);
$pdf->SetFont('dejavusans', '', 9);
$y = $pdf->GetY();
$pdf->Line(15, $y, 70, $y);
$pdf->Line(80, $y, 135, $y);
$pdf->Line(145, $y, 195, $y);
$pdf->SetXY(15, $y + 1);
$pdf->MultiCell(55, 5, "Ort, Datum", 0, 'L', false, 0);
$pdf->SetXY(80, $y + 1);
$pdf->MultiCell(55, 5, "Obmann / Obfrau\n" . ($a['obmann_name'] ?? ''), 0, 'L', false, 0);
$pdf->SetXY(145, $y + 1);
$pdf->MultiCell(50, 5, $vertreter2_label . "\n" . ($a['vertreter2_name'] ?? ''), 0, 'L', false, 1);

$pdf->Ln(8);
$pdf->SetFont('dejavusans', '', 7.5);
$pdf->SetTextColor(110, 110, 110);
$pdf->MultiCell(0, 4, 'Hinweis: Förderansuchen' . ($mit_vereinsbonus ? ' und Vereinsbonus-Anträge' : '') . ' sind digital über die Vereinsdatenbank der SPORTUNION Steiermark (suvw.at) einzubringen, Vereinsbonus-Anträge vor Beginn der jeweiligen Maßnahme; dieses Dokument dient als Beilage. Grundlage sind die Förderrichtlinien und Abrechnungsrichtlinien der SPORTUNION Steiermark in der jeweils gültigen Fassung. Es besteht kein Rechtsanspruch auf Förderung.', 0, 'L');

$pdf->Output('Foerderansuchen_SPORTUNION_' . (int)$a['jahr'] . '.pdf', 'I');
exit;
