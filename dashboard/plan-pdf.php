<?php
/**
 * Athletikclub Steiermark – Trainings- bzw. Ernährungsplan als PDF (?typ=training|ernaehrung&id=…)
 * Zugriff wie in der Web-Ansicht: Trainer:innen alle Pläne, Mitglieder nur eigene freigegebene.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plaene.php';

requireLogin();

require_once ROOT_PATH . '/vendor/autoload.php';

$db   = getDB();
$typ  = ($_GET['typ'] ?? '') === 'ernaehrung' ? 'ernaehrung' : 'training';
$plan = planLaden($db, $typ === 'training' ? 'trainingsplaene' : 'ernaehrungsplaene', (int)($_GET['id'] ?? 0));
if (!$plan) { http_response_code(404); die('Plan nicht gefunden.'); }

class PlanPDF extends TCPDF
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
        $this->Cell(0, 10, APP_NAME . ' · erstellt am ' . date('d.m.Y') . ' · Seite ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }

    public function abschnitt(string $titel): void
    {
        if ($this->GetY() > 245) $this->AddPage();
        $this->Ln(3);
        $this->SetFont('dejavusans', 'B', 11);
        $this->SetFillColor(31, 53, 86);
        $this->SetTextColor(255, 255, 255);
        $this->MultiCell(0, 8, '  ' . $titel, 0, 'L', true, 1, '', '', true, 0, false, true, 0, 'M');
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    public function feldZeile(string $label, ?string $wert): void
    {
        $this->SetFont('dejavusans', 'B', 9.5);
        $this->Cell(45, 6, $label, 0, 0, 'L');
        $this->SetFont('dejavusans', '', 9.5);
        $this->MultiCell(0, 6, $wert !== null && $wert !== '' ? $wert : '–', 0, 'L');
    }

    public function hinweis(string $text): void
    {
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(90, 90, 90);
        $this->MultiCell(0, 4.2, $text, 0, 'L');
        $this->SetTextColor(0, 0, 0);
    }
}

$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$th = 'style="background-color:#EEF1F5; font-weight:bold;"';
$zahl = fn($z) => rtrim(rtrim(number_format((float)$z, 1, ',', ''), '0'), ',');

$pdf = new PlanPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->headerTitel = ($typ === 'training' ? 'TRAININGSPLAN' : 'ERNÄHRUNGSPLAN') . ' · ' . mb_strtoupper(APP_NAME);
$pdf->SetCreator(APP_NAME);
$pdf->SetAuthor(APP_NAME);
$pdf->SetTitle($plan['titel']);
$pdf->SetMargins(15, 22, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$pdf->SetFont('dejavusans', 'B', 15);
$pdf->MultiCell(0, 8, $plan['titel'], 0, 'L');
$pdf->Ln(1);
$pdf->feldZeile('Für:', planFuer($plan));
$pdf->feldZeile('Trainer:in:', $plan['t_vorname'] . ' ' . $plan['t_nachname']);
if ($plan['start_datum']) $pdf->feldZeile('Start:', date('d.m.Y', strtotime($plan['start_datum'])));

if ($typ === 'training') {
    // ------------------------------------------------------------
    // Trainingsplan
    // ------------------------------------------------------------
    $pdf->feldZeile('Ziel / Niveau:', (TP_ZIELE[$plan['ziel']]['label'] ?? '') . ' · ' . (TP_NIVEAUS[$plan['niveau']] ?? ''));
    $pdf->feldZeile('Umfang:', (int)$plan['dauer_wochen'] . ' Wochen, ' . (int)$plan['einheiten_pro_woche'] . ' Einheiten pro Woche');
    if ($plan['hinweise']) {
        $pdf->abschnitt('Hinweise');
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->MultiCell(0, 5, $plan['hinweise'], 0, 'L');
    }

    $stmt = $db->prepare('SELECT * FROM trainingsplan_einheiten WHERE plan_id = ? ORDER BY sortierung, id');
    $stmt->execute([$plan['id']]);
    $einheiten = $stmt->fetchAll();
    foreach ($einheiten as $e) {
        $pdf->abschnitt($e['name'] . ($e['wochentag'] ? ' · ' . WOCHENTAGE[(int)$e['wochentag']] : ''));
        if ($e['aufwaermen']) { $pdf->SetFont('dejavusans', '', 9); $pdf->MultiCell(0, 5, 'Aufwärmen: ' . $e['aufwaermen'], 0, 'L'); $pdf->Ln(1); }

        $stmt = $db->prepare('SELECT * FROM trainingsplan_uebungen WHERE einheit_id = ? ORDER BY sortierung, id');
        $stmt->execute([$e['id']]);
        $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;"><thead><tr>'
            . '<td ' . $th . ' width="30%">Übung</td><td ' . $th . ' width="14%" align="center">Sätze × Wdh.</td><td ' . $th . ' width="13%">Last</td>'
            . '<td ' . $th . ' width="7%" align="center">RPE</td><td ' . $th . ' width="10%" align="center">Tempo</td><td ' . $th . ' width="8%" align="center">Pause</td>'
            . '<td ' . $th . ' width="18%">Hinweis</td></tr></thead><tbody>';
        foreach ($stmt->fetchAll() as $u) {
            $html .= '<tr nobr="true"><td width="30%"><b>' . $h($u['uebung_name']) . '</b></td>'
                . '<td width="14%" align="center">' . ($u['saetze'] ? (int)$u['saetze'] . ' × ' : '') . $h($u['wiederholungen'] ?? '') . '</td>'
                . '<td width="13%">' . $h($u['last'] ?? '') . '</td>'
                . '<td width="7%" align="center">' . ($u['rpe'] !== null ? $h($zahl($u['rpe'])) : '') . '</td>'
                . '<td width="10%" align="center">' . $h($u['tempo'] ?? '') . '</td>'
                . '<td width="8%" align="center">' . ($u['pause_sek'] ? (int)$u['pause_sek'] . ' s' : '') . '</td>'
                . '<td width="18%">' . $h($u['notiz'] ?? '') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($html, true, false, false, false, '');
        if ($e['notiz']) { $pdf->SetFont('dejavusans', '', 9); $pdf->MultiCell(0, 5, 'Notiz: ' . $e['notiz'], 0, 'L'); }
    }

    // Wochen-Checkliste zum Abhaken (Wochen × Einheiten)
    if ($einheiten && (int)$plan['dauer_wochen'] <= 16) {
        $pdf->abschnitt('Wochen-Checkliste – absolvierte Einheiten abhaken');
        $html = '<table border="0.3" cellpadding="4" style="font-size:8.5pt;"><tr><td ' . $th . ' width="16%">Woche</td>';
        $breite = 84 / max(1, count($einheiten));
        foreach ($einheiten as $e) $html .= '<td ' . $th . ' width="' . $breite . '%" align="center">' . $h(mb_strimwidth($e['name'], 0, 28, '…')) . '</td>';
        $html .= '</tr>';
        for ($w = 1; $w <= (int)$plan['dauer_wochen']; $w++) {
            $html .= '<tr nobr="true"><td width="16%">Woche ' . $w . '</td>';
            foreach ($einheiten as $e) $html .= '<td width="' . $breite . '%" align="center">☐</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($html, true, false, false, false, '');
    }

    $pdf->Ln(2);
    $pdf->hinweis('RPE (Rate of Perceived Exertion): 10 = maximal, 9 = noch 1 Wiederholung möglich, 8 = noch 2, 7 = noch 3 Wiederholungen im Tank. '
        . 'Tempo z.B. 3-1-1-0 = 3 s absenken, 1 s Pause unten, 1 s hoch, 0 s oben. KG = Körpergewicht.');
    $pdf->Ln(1);
    $pdf->hinweis(TP_HINWEIS);
} else {
    // ------------------------------------------------------------
    // Ernährungsplan (nur freigegeben bzw. Vorlage)
    // ------------------------------------------------------------
    $berechnung = epBerechnung($plan);
    if ($plan['mitglied_id'] && !epFreigegeben($plan, $berechnung)) { http_response_code(403); die('Dieser Ernährungsplan ist nicht freigegeben.'); }

    $pdf->feldZeile('Ziel:', EP_ZIELE[$plan['ziel']]['label']);
    if ($berechnung['vollstaendig'] && $plan['mitglied_id']) {
        $pdf->abschnitt('Tägliche Richtwerte');
        $html = '<table border="0.3" cellpadding="4" style="font-size:9pt;"><tr>'
            . '<td ' . $th . ' width="25%" align="center">Energie</td><td ' . $th . ' width="25%" align="center">Eiweiß</td>'
            . '<td ' . $th . ' width="25%" align="center">Kohlenhydrate</td><td ' . $th . ' width="25%" align="center">Fett</td></tr><tr>'
            . '<td width="25%" align="center"><b>' . number_format($berechnung['kcal'], 0, ',', '.') . ' kcal</b></td>'
            . '<td width="25%" align="center"><b>' . $berechnung['protein_g'] . ' g</b></td>'
            . '<td width="25%" align="center"><b>' . $berechnung['kh_g'] . ' g</b></td>'
            . '<td width="25%" align="center"><b>' . $berechnung['fett_g'] . ' g</b></td></tr></table>';
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->hinweis('Trinkmenge: ca. ' . number_format($berechnung['wasser_l'], 1, ',', '') . ' l pro Tag (Wasser, ungesüßte Tees). Richtwerte sind Orientierungsgrößen – Hunger, Sättigung und Wohlbefinden haben Vorrang.');
    }
    if ($plan['hinweise']) {
        $pdf->abschnitt('Hinweise');
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->MultiCell(0, 5, $plan['hinweise'], 0, 'L');
    }

    $stmt = $db->prepare("SELECT * FROM ernaehrungsplan_mahlzeiten WHERE plan_id = ? ORDER BY CASE tagtyp WHEN 'alle' THEN 0 WHEN 'training' THEN 1 ELSE 2 END, sortierung, id");
    $stmt->execute([$plan['id']]);
    $je_typ = [];
    foreach ($stmt->fetchAll() as $m) $je_typ[$m['tagtyp']][] = $m;
    foreach (EP_TAGTYPEN as $tt => $tt_label) {
        if (empty($je_typ[$tt])) continue;
        $pdf->abschnitt('Mahlzeiten – ' . $tt_label);
        $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;"><thead><tr>'
            . '<td ' . $th . ' width="20%">Mahlzeit</td><td ' . $th . ' width="48%">Inhalt</td><td ' . $th . ' width="9%" align="right">kcal</td>'
            . '<td ' . $th . ' width="8%" align="right">E</td><td ' . $th . ' width="8%" align="right">KH</td><td ' . $th . ' width="7%" align="right">F</td></tr></thead><tbody>';
        foreach ($je_typ[$tt] as $m) {
            $html .= '<tr nobr="true"><td width="20%"><b>' . $h($m['mahlzeit']) . '</b>' . ($m['uhrzeit'] ? '<br>' . $h($m['uhrzeit']) : '') . '</td>'
                . '<td width="48%">' . nl2br($h($m['inhalt'])) . '</td>'
                . '<td width="9%" align="right">' . ($m['kcal'] !== null ? (int)$m['kcal'] : '') . '</td>'
                . '<td width="8%" align="right">' . ($m['protein_g'] !== null ? (int)$m['protein_g'] . ' g' : '') . '</td>'
                . '<td width="8%" align="right">' . ($m['kh_g'] !== null ? (int)$m['kh_g'] . ' g' : '') . '</td>'
                . '<td width="7%" align="right">' . ($m['fett_g'] !== null ? (int)$m['fett_g'] . ' g' : '') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($html, true, false, false, false, '');
    }
    $pdf->Ln(2);
    $pdf->hinweis(EP_HINWEIS);
}

$pdf->Output(($typ === 'training' ? 'Trainingsplan_' : 'Ernaehrungsplan_') . preg_replace('/[^A-Za-z0-9_-]/', '_', $plan['titel']) . '.pdf', 'I');
exit;
