<?php
/**
 * Athletikclub Steiermark – Admin: TBE-Gesamtkonzept als Bericht (PDF) zum Einreichen
 * (TBE Fix = Bewegungscoach-Stunden, TBE Flex/Flex-S = flexible Bewegungseinheiten)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/money.php';
require_once ROOT_PATH . '/includes/tbe.php';

requireAdmin();

require_once ROOT_PATH . '/vendor/autoload.php';

$db         = getDB();
$konzept_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM tbe_konzepte WHERE id = ? AND organization_id = ? LIMIT 1');
$stmt->execute([$konzept_id, currentOrgId()]);
$k = $stmt->fetch();
if (!$k) { http_response_code(404); die('Gesamtkonzept nicht gefunden.'); }

$stmt = $db->prepare('SELECT * FROM tbe_projekte WHERE konzept_id = ? ORDER BY modell ASC, sortierung ASC, einrichtung ASC, id ASC');
$stmt->execute([$konzept_id]);
$projekte = $stmt->fetchAll();

$mit_kosten = $k['stundensatz'] !== null;

class TbePDF extends TCPDF
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
        $this->Cell(0, 10, APP_NAME . ' · ZVR ' . VEREIN_ZVR . ' · Seite ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }

    public function feldZeile(string $label, ?string $wert, float $labelWidth = 70): void
    {
        $this->SetFont('dejavusans', 'B', 9.5);
        $this->Cell($labelWidth, 6.5, $label, 0, 0, 'L');
        $this->SetFont('dejavusans', '', 9.5);
        $this->MultiCell(0, 6.5, $wert !== null && $wert !== '' ? $wert : '–', 0, 'L');
    }

    public function abschnitt(string $titel): void
    {
        if ($this->GetY() > 250) $this->AddPage();
        $this->Ln(3);
        $this->SetFont('dejavusans', 'B', 11);
        $this->SetFillColor(31, 53, 86);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 8, '  ' . $titel, 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    public function haken(bool $ja, string $text): void
    {
        $this->SetFont('dejavusans', '', 9);
        $this->MultiCell(8, 5, $ja ? '☒' : '☐', 0, 'L', false, 0);
        $this->MultiCell(0, 5, $text, 0, 'L');
    }
}

$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$th = 'style="background-color:#EEF1F5; font-weight:bold;"';

// Summen nach Modell
$fix  = array_values(array_filter($projekte, fn($p) => !tbeIstFlex($p)));
$flex = array_values(array_filter($projekte, 'tbeIstFlex'));
$foerderung_fix  = moneySum(array_map('tbeFoerderung', $fix));
$foerderung_flex = moneySum(array_map('tbeFoerderung', $flex));
$foerderung_ges  = moneySum([$foerderung_fix, $foerderung_flex]);
$summe_stunden   = array_sum(array_map('tbeStunden', $projekte));
$kosten_gesamt   = $mit_kosten ? moneySum(array_map(fn($p) => tbeKosten($p, $k['stundensatz']), $projekte)) : null;

$pdf = new TbePDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->headerTitel = 'TÄGLICHE BEWEGUNGSEINHEIT – GESAMTKONZEPT ' . $k['bezeichnung'];
$pdf->SetCreator(APP_NAME);
$pdf->SetAuthor(APP_NAME);
$pdf->SetTitle('Gesamtkonzept Tägliche Bewegungseinheit ' . $k['bezeichnung']);
$pdf->SetMargins(15, 22, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ----------------------------------------------------------------
// Verein
// ----------------------------------------------------------------
$abschnitt_nr = 1;
$pdf->abschnitt($abschnitt_nr++ . '. Verein');
$pdf->feldZeile('Name:', APP_NAME);
$pdf->feldZeile('ZVR-Zahl:', VEREIN_ZVR);
$pdf->feldZeile('Adresse:', VEREIN_ADRESSE);
$pdf->feldZeile('Dachverband:', 'SPORTUNION Steiermark');
$pdf->feldZeile('Schuljahr:', $k['bezeichnung']);
$pdf->feldZeile('Umsetzungszeitraum:', date('d.m.Y', strtotime($k['zeitraum_von'])) . ' – ' . date('d.m.Y', strtotime($k['zeitraum_bis'])));

if (!empty($k['konzeptbeschreibung'])) {
    $pdf->abschnitt($abschnitt_nr++ . '. Konzept');
    $pdf->SetFont('dejavusans', '', 9.5);
    $pdf->MultiCell(0, 5, $k['konzeptbeschreibung'], 0, 'L');
}

// ----------------------------------------------------------------
// Überblick
// ----------------------------------------------------------------
$typen = [];
foreach ($projekte as $p) $typen[TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']] ?? 'Sonstige'][mb_strtolower($p['einrichtung'])] = true;
$einrichtungen_text = implode(', ', array_map(fn($typ, $liste) => count($liste) . ' × ' . $typ, array_keys($typen), $typen));
$fix_woche   = array_sum(array_map('tbeFixStundenWoche', $fix));
$flex_eh     = array_sum(array_map('tbeEinheiten', $flex));
$flex_pakete = array_sum(array_map('tbeFlexPakete', $flex));
$kinder      = array_sum(array_map(fn($p) => (int)$p['anzahl_kinder'], $projekte));

$pdf->abschnitt($abschnitt_nr++ . '. Überblick');
$pdf->feldZeile('Projekte / Einrichtungen:', count($projekte) . ' Projekte · ' . ($einrichtungen_text ?: '–'));
if ($kinder > 0) $pdf->feldZeile('Erreichte Kinder (geplant):', (string)$kinder);
$pdf->feldZeile('TBE Fix (Säule 2):', tbeZahl($fix_woche) . ' wöchentliche Bewegungscoach-Stunden → ' . moneyFormat($foerderung_fix));
$pdf->feldZeile('TBE Flex (Säule 3):', tbeZahl($flex_eh) . ' Einheiten = ' . $flex_pakete . ' Pakete à ' . TBE_FLEX_PAKET . ' → ' . moneyFormat($foerderung_flex));
$pdf->feldZeile('Trainer:innen-Stunden gesamt:', tbeZahl($summe_stunden));
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->feldZeile('Beantragter Förderrahmen:', moneyFormat($foerderung_ges));
if ($mit_kosten) $pdf->feldZeile('Geschätzte Kosten:', moneyFormat($kosten_gesamt) . ' (Stundensatz ' . moneyFormat($k['stundensatz']) . ')');
$pdf->SetFont('dejavusans', '', 7.5);
$pdf->SetTextColor(110, 110, 110);
$pdf->MultiCell(0, 4, 'Förderrahmen nach den Sätzen der Täglichen Bewegungseinheit: bis € ' . number_format(TBE_FIX_SATZ, 0, ',', '.') . ',– pro fixer wöchentlicher Bewegungscoach-Stunde (Ganzjahresstunde September–Juni), bis € ' . TBE_FLEX_SATZ . ',– pro Paket à ' . TBE_FLEX_PAKET . ' flexiblen Einheiten.', 0, 'L');
$pdf->SetTextColor(0, 0, 0);

// ----------------------------------------------------------------
// Projekttabellen je Modell
// ----------------------------------------------------------------
foreach ([['TBE Fix – Bewegungscoach-Stunden (Säule 2)', $fix, false], ['TBE Flex – flexible Bewegungseinheiten (Säule 3)', $flex, true]] as [$titel, $liste, $ist_flex]) {
    if (empty($liste)) continue;
    $pdf->abschnitt($abschnitt_nr++ . '. ' . $titel);

    $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;"><thead><tr>'
        . '<td ' . $th . ' width="28%">Einrichtung</td>'
        . '<td ' . $th . ' width="26%">Angebot / Zielgruppe</td>'
        . '<td ' . $th . ' width="' . ($ist_flex ? 12 : 10) . '%" align="right">' . ($ist_flex ? 'Klassen/Gr.' : 'Klassen') . '</td>'
        . '<td ' . $th . ' width="' . ($ist_flex ? 12 : 14) . '%" align="right">' . ($ist_flex ? 'Einheiten' : 'BC-Std./Woche') . '</td>'
        . '<td ' . $th . ' width="8%" align="right">Std.</td>'
        . '<td ' . $th . ' width="14%" align="right">Förderung</td>'
        . '</tr></thead><tbody>';
    foreach ($liste as $p) {
        $zusatz = array_filter([TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']] ?? '', $p['ort'], $p['kennzahl'] ? 'Kennzahl ' . $p['kennzahl'] : null]);
        $umfang = $ist_flex
            ? (int)$p['flex_einheiten'] . ' (' . tbeFlexPakete($p) . ' Pak.)'
            : (int)$p['anzahl_gruppen'] . ' × ' . tbeZahl((float)$p['einheiten_pro_woche']) . ' = ' . tbeZahl(tbeFixStundenWoche($p));
        $html .= '<tr nobr="true">'
            . '<td width="28%"><b>' . $h($p['einrichtung']) . '</b><br>' . $h(implode(', ', $zusatz)) . '</td>'
            . '<td width="26%">' . $h($p['bewegungsangebot']) . ($p['zielgruppe'] ? '<br>' . $h($p['zielgruppe']) : '') . ($p['modell'] === 'flex_s' ? '<br><i>Schwimmen (Flex-S)</i>' : '') . '</td>'
            . '<td width="' . ($ist_flex ? 12 : 10) . '%" align="right">' . (int)$p['anzahl_gruppen'] . ($p['klassen_gesamt'] ? ' / ' . (int)$p['klassen_gesamt'] : '') . '</td>'
            . '<td width="' . ($ist_flex ? 12 : 14) . '%" align="right">' . $h($umfang) . '</td>'
            . '<td width="8%" align="right">' . tbeZahl(tbeStunden($p)) . '</td>'
            . '<td width="14%" align="right">' . $h(moneyFormat(tbeFoerderung($p))) . '</td>'
            . '</tr>';
    }
    $html .= '<tr style="font-weight:bold;"><td width="78%" colspan="4">Summe</td>'
        . '<td width="8%" align="right">' . tbeZahl(array_sum(array_map('tbeStunden', $liste))) . '</td>'
        . '<td width="14%" align="right">' . $h(moneyFormat($ist_flex ? $foerderung_flex : $foerderung_fix)) . '</td></tr></tbody></table>';
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->writeHTML($html, true, false, false, false, '');
}

// ----------------------------------------------------------------
// Projektbeschreibungen
// ----------------------------------------------------------------
$mit_beschreibung = array_filter($projekte, fn($p) => !empty($p['beschreibung']) || !empty($p['trainer']) || !empty($p['ansprechperson']));
if (!empty($mit_beschreibung)) {
    $pdf->abschnitt($abschnitt_nr++ . '. Projektbeschreibungen');
    foreach ($mit_beschreibung as $p) {
        $pdf->SetFont('dejavusans', 'B', 9.5);
        $pdf->MultiCell(0, 5.5, $p['einrichtung'] . ' – ' . $p['bewegungsangebot'] . ' (' . (TBE_MODELLE[$p['modell']]['kurz'] ?? '') . ')', 0, 'L');
        $pdf->SetFont('dejavusans', '', 8.5);
        $zeilen = [];
        if (!empty($p['ansprechperson'])) $zeilen[] = 'Ansprechperson: ' . $p['ansprechperson'];
        if (!empty($p['trainer']))        $zeilen[] = (tbeIstFlex($p) ? 'Übungsleiter:in: ' : 'Bewegungscoach: ') . $p['trainer'];
        if ($zeilen) $pdf->MultiCell(0, 4.5, implode(' · ', $zeilen), 0, 'L');
        if (!empty($p['beschreibung'])) {
            $pdf->SetFont('dejavusans', '', 9.5);
            $pdf->MultiCell(0, 5, $p['beschreibung'], 0, 'L');
        }
        $pdf->Ln(2);
    }
}

// ----------------------------------------------------------------
// Teilnahmevoraussetzungen
// ----------------------------------------------------------------
$pdf->abschnitt($abschnitt_nr++ . '. Teilnahmevoraussetzungen');
$pdf->haken(!empty($k['chk_kinderangebot']), 'Der Verein bietet ein eigenes Bewegungsangebot für Kinder an.');
if ($flex) $pdf->haken(!empty($k['chk_fit_siegel']), 'Mindestens ein Kinder-/Jugendangebot des Vereins ist mit dem Fit-Sport-Austria-Qualitätssiegel zertifiziert.');
$alle = fn(string $feld, array $liste) => $liste && count(array_filter($liste, fn($p) => !empty($p[$feld]))) === count($liste);
$pdf->haken($alle('chk_kooperation', $projekte), 'Mit allen Einrichtungen sind Kooperationsvereinbarungen für das Schuljahr ' . $k['bezeichnung'] . ' abgeschlossen.');
$vs_fix = array_filter($fix, fn($p) => $p['einrichtungstyp'] === 'volksschule');
if ($vs_fix) $pdf->haken($alle('chk_schulforum', array_values($vs_fix)), 'Für die Bewegungscoach-Stunden an Volksschulen liegen die Beschlüsse im Schulforum vor.');
$pdf->haken($alle('chk_qualifikation', $projekte), 'Alle Bewegungscoaches und Übungsleiter:innen erfüllen die geforderte Qualifikation' . ($flex ? ' (Flex: ÜL-Ausbildung Kinder/Jugend o. gleichwertig; Flex-S zusätzlich Helferschein Schwimmen)' : '') . '.');
$pdf->haken($alle('chk_haftpflicht', $projekte), 'Für alle eingesetzten Bewegungscoaches besteht eine aufrechte Haftpflichtversicherung.');
$pdf->haken(true, 'Die Angebote sind für alle Kinder frei zugänglich und kostenlos; der Verein bekennt sich zum Verhaltenskodex und zum Kinderschutzkonzept der Einrichtungen.');
$pdf->haken(true, 'Die Einheiten werden laufend in der Datenbank der Täglichen Bewegungseinheit dokumentiert; bei Ausfall eines Bewegungscoachs wird binnen fünf Werktagen Ersatz organisiert.');

// ----------------------------------------------------------------
// Unterschrift
// ----------------------------------------------------------------
if ($pdf->GetY() > 245) $pdf->AddPage();
$pdf->Ln(14);
$pdf->SetFont('dejavusans', '', 9);
$y = $pdf->GetY();
$pdf->Line(15, $y, 85, $y);
$pdf->Line(115, $y, 195, $y);
$pdf->SetXY(15, $y + 1);
$pdf->Cell(70, 5, 'Ort, Datum', 0, 0, 'L');
$pdf->SetXY(115, $y + 1);
$pdf->Cell(80, 5, 'Unterschrift Obmann/Obfrau ' . APP_NAME, 0, 0, 'L');

$pdf->Output('TBE_Gesamtkonzept_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $k['bezeichnung']) . '.pdf', 'I');
exit;
