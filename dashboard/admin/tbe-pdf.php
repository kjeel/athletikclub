<?php
/**
 * Athletikclub Steiermark – Admin: TBE-Gesamtkonzept als Bericht (PDF) zum Einreichen
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

$stmt = $db->prepare('SELECT * FROM tbe_projekte WHERE konzept_id = ? ORDER BY sortierung ASC, einrichtung ASC, id ASC');
$stmt->execute([$konzept_id]);
$projekte = $stmt->fetchAll();

$mit_kosten = $k['stundensatz'] !== null;

class TbePDF extends TCPDF
{
    public string $headerTitel = '';

    public function Header()
    {
        $this->SetFont('dejavusans', 'B', 13);
        $this->SetTextColor(31, 53, 86);
        $this->Cell(0, 8, $this->headerTitel, 0, 1, 'L');
        $this->SetDrawColor(198, 161, 53);
        $this->SetLineWidth(0.6);
        $this->Line(15, 22, 195, 22);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 10, APP_NAME . ' · ZVR ' . VEREIN_ZVR . ' · Seite ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }

    public function feldZeile(string $label, ?string $wert, float $labelWidth = 60): void
    {
        $this->SetFont('dejavusans', 'B', 10);
        $this->Cell($labelWidth, 7, $label, 0, 0, 'L');
        $this->SetFont('dejavusans', '', 10);
        $this->Cell(0, 7, $wert !== null && $wert !== '' ? $wert : '–', 0, 1, 'L');
    }

    public function abschnitt(string $titel): void
    {
        $this->Ln(3);
        $this->SetFont('dejavusans', 'B', 11);
        $this->SetFillColor(31, 53, 86);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 8, '  ' . $titel, 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$pdf = new TbePDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->headerTitel = 'TÄGLICHE BEWEGUNGSEINHEIT – GESAMTKONZEPT ' . $k['bezeichnung'];
$pdf->SetCreator(APP_NAME);
$pdf->SetAuthor(APP_NAME);
$pdf->SetTitle('Gesamtkonzept Tägliche Bewegungseinheit ' . $k['bezeichnung']);
$pdf->SetMargins(15, 26, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// ----------------------------------------------------------------
// Antragsteller
// ----------------------------------------------------------------
$pdf->abschnitt('Verein');
$pdf->feldZeile('Name:', APP_NAME);
$pdf->feldZeile('ZVR-Zahl:', VEREIN_ZVR);
$pdf->feldZeile('Adresse:', VEREIN_ADRESSE);
$pdf->feldZeile('Förderjahr:', $k['bezeichnung']);
$pdf->feldZeile('Umsetzungszeitraum:', date('d.m.Y', strtotime($k['zeitraum_von'])) . ' – ' . date('d.m.Y', strtotime($k['zeitraum_bis'])));

// ----------------------------------------------------------------
// Konzept
// ----------------------------------------------------------------
if (!empty($k['konzeptbeschreibung'])) {
    $pdf->abschnitt('Konzept');
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(0, 5.5, $k['konzeptbeschreibung'], 0, 'L');
}

// ----------------------------------------------------------------
// Überblick
// ----------------------------------------------------------------
$summe_einheiten = array_sum(array_map('tbeEinheiten', $projekte));
$summe_stunden   = array_sum(array_map('tbeStunden', $projekte));
$kosten_gesamt   = $mit_kosten ? moneySum(array_map(fn($p) => tbeKosten($p, $k['stundensatz']), $projekte)) : null;

$typen = [];
foreach ($projekte as $p) {
    $typ = TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']] ?? 'Sonstige';
    $typen[$typ][mb_strtolower($p['einrichtung'])] = true;
}
$einrichtungen_text = implode(', ', array_map(fn($typ, $liste) => count($liste) . ' × ' . $typ, array_keys($typen), $typen));

$pdf->abschnitt('Überblick');
$pdf->feldZeile('Anzahl Projekte:', (string)count($projekte));
$pdf->feldZeile('Einrichtungen:', $einrichtungen_text);
$pdf->feldZeile('Bewegungseinheiten gesamt:', tbeZahl($summe_einheiten));
$pdf->feldZeile('Trainer:innen-Stunden gesamt:', tbeZahl($summe_stunden));
if ($mit_kosten) {
    $pdf->feldZeile('Stundensatz:', moneyFormat($k['stundensatz']));
    $pdf->feldZeile('Geplante Kosten gesamt:', moneyFormat($kosten_gesamt));
}

// ----------------------------------------------------------------
// Projektübersicht (Tabelle)
// ----------------------------------------------------------------
$pdf->abschnitt('Projekte');

if (empty($projekte)) {
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->Cell(0, 7, 'Noch keine Projekte erfasst.', 0, 1, 'L');
} else {
    $th = 'style="background-color:#EEF1F5; font-weight:bold;"';
    $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;">'
        . '<thead><tr>'
        . '<th ' . $th . ' width="' . ($mit_kosten ? 28 : 33) . '%">Einrichtung</th>'
        . '<th ' . $th . ' width="' . ($mit_kosten ? 24 : 29) . '%">Angebot / Zielgruppe</th>'
        . '<th ' . $th . ' width="8%" align="right">Gruppen</th>'
        . '<th ' . $th . ' width="8%" align="right">Einh./ Woche</th>'
        . '<th ' . $th . ' width="8%" align="right">Dauer (Min.)</th>'
        . '<th ' . $th . ' width="7%" align="right">Wochen</th>'
        . '<th ' . $th . ' width="7%" align="right">Stunden</th>'
        . ($mit_kosten ? '<th ' . $th . ' width="10%" align="right">Kosten</th>' : '')
        . '</tr></thead><tbody>';

    foreach ($projekte as $p) {
        $html .= '<tr nobr="true">'
            . '<td width="' . ($mit_kosten ? 28 : 33) . '%"><b>' . $h($p['einrichtung']) . '</b><br>' . $h(TBE_EINRICHTUNGSTYPEN[$p['einrichtungstyp']] ?? '') . ($p['ort'] ? ', ' . $h($p['ort']) : '') . '</td>'
            . '<td width="' . ($mit_kosten ? 24 : 29) . '%">' . $h($p['bewegungsangebot']) . ($p['zielgruppe'] ? '<br>' . $h($p['zielgruppe']) : '') . '</td>'
            . '<td width="8%" align="right">' . (int)$p['anzahl_gruppen'] . '</td>'
            . '<td width="8%" align="right">' . tbeZahl((float)$p['einheiten_pro_woche']) . '</td>'
            . '<td width="8%" align="right">' . (int)$p['dauer_minuten'] . '</td>'
            . '<td width="7%" align="right">' . (int)$p['anzahl_wochen'] . '</td>'
            . '<td width="7%" align="right">' . tbeZahl(tbeStunden($p)) . '</td>'
            . ($mit_kosten ? '<td width="10%" align="right">' . $h(moneyFormat(tbeKosten($p, $k['stundensatz']))) . '</td>' : '')
            . '</tr>';
    }

    $html .= '<tr style="font-weight:bold;">'
        . '<td width="' . ($mit_kosten ? 83 : 93) . '%" colspan="6">Gesamt</td>'
        . '<td width="7%" align="right">' . tbeZahl($summe_stunden) . '</td>'
        . ($mit_kosten ? '<td width="10%" align="right">' . $h(moneyFormat($kosten_gesamt)) . '</td>' : '')
        . '</tr></tbody></table>';

    $pdf->writeHTML($html, true, false, false, false, '');
}

// ----------------------------------------------------------------
// Projektbeschreibungen
// ----------------------------------------------------------------
$mit_beschreibung = array_filter($projekte, fn($p) => !empty($p['beschreibung']) || !empty($p['trainer']) || !empty($p['ansprechperson']));
if (!empty($mit_beschreibung)) {
    $pdf->abschnitt('Projektbeschreibungen');
    foreach ($mit_beschreibung as $p) {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->MultiCell(0, 6, $p['einrichtung'] . ' – ' . $p['bewegungsangebot'], 0, 'L');
        $pdf->SetFont('dejavusans', '', 9);
        $zeilen = [];
        if (!empty($p['ansprechperson'])) $zeilen[] = 'Ansprechperson: ' . $p['ansprechperson'];
        if (!empty($p['trainer']))        $zeilen[] = 'Trainer:in: ' . $p['trainer'];
        if ($zeilen) $pdf->MultiCell(0, 5, implode(' · ', $zeilen), 0, 'L');
        if (!empty($p['beschreibung'])) {
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->MultiCell(0, 5.5, $p['beschreibung'], 0, 'L');
        }
        $pdf->Ln(3);
    }
}

// ----------------------------------------------------------------
// Unterschrift
// ----------------------------------------------------------------
$pdf->Ln(12);
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
