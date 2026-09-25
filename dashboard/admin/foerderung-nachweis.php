<?php
/**
 * Athletikclub Steiermark – Verwendungsnachweis einer Förderung
 *   ?id=ID              PDF: Eckdaten, Budget, Kosten nach Kategorie, Belegliste,
 *                       Einnahmen und Leistungsnachweis (durchgeführte Einheiten, Teilnahmen)
 *   ?id=ID&format=csv   Belegliste als CSV (Excel, Semikolon, UTF-8)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';
require_once ROOT_PATH . '/includes/prae.php';

requireDarf('foerderungen.anzeigen');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM foerderungen WHERE id = ? AND organization_id = ?');
$stmt->execute([$id, currentOrgId()]);
$f = $stmt->fetch();
if (!$f) {
    http_response_code(404);
    exit('Förderung nicht gefunden.');
}

$budget = foerderBudget($db, $f);
$stmt = $db->prepare('SELECT b.*, p.name AS projekt_name FROM buchungen b LEFT JOIN projekte p ON p.id = b.projekt_id WHERE b.foerderung_id = ? ORDER BY b.datum, b.id');
$stmt->execute([$id]);
$buchungen = $stmt->fetchAll();
$kosten = array_values(array_filter($buchungen, fn($b) => $b['art'] === 'ausgabe'));
$einnahmen = array_values(array_filter($buchungen, fn($b) => $b['art'] === 'einnahme'));
$dateiname = 'Verwendungsnachweis_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $f['titel']);

// ----------------------------------------------------------------
// CSV
// ----------------------------------------------------------------
if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dateiname . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // Formel-Injection in Tabellenkalkulationen verhindern
    $zelle = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    fputcsv($out, ['Nr.', 'Datum', 'Art', 'Beleg-Nr.', 'Beschreibung', 'Kategorie', 'Projekt', 'Status', 'Betrag EUR'], ';');
    foreach ($buchungen as $i => $b) {
        fputcsv($out, array_map($zelle, [$i + 1, date('d.m.Y', strtotime($b['datum'])), $b['art'] === 'ausgabe' ? 'Ausgabe' : 'Einnahme', $b['belegnummer'] ?? '',
                      $b['beschreibung'], BUCHUNG_KATEGORIEN[$b['kategorie']] ?? $b['kategorie'], $b['projekt_name'] ?? '', $b['status'],
                      number_format((float)$b['betrag'], 2, ',', '')]), ';');
    }
    fputcsv($out, [], ';');
    foreach (['Bewilligt' => $budget['bewilligt'], 'Kosten gesamt' => $budget['verbraucht'], 'Restbudget' => $budget['rest'], 'Ausbezahlt' => $budget['ausbezahlt']] as $l => $w) {
        fputcsv($out, ['', '', '', '', $l, '', '', '', number_format((float)$w, 2, ',', '')], ';');
    }
    fclose($out);
    exit;
}

// ----------------------------------------------------------------
// Leistungsnachweis: Einheiten der verknüpften Projekte im Förderzeitraum
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT id, name, start_datum, end_datum FROM projekte WHERE organization_id = ? AND (foerderung_id = ? OR id = ?)');
$stmt->execute([currentOrgId(), $id, (int)($f['projekt_id'] ?? 0)]);
$projekte = $stmt->fetchAll();
$leistung = ['einheiten' => 0, 'minuten' => 0, 'teilnahmen' => 0, 'personen' => [], 'trainer' => []];
if ($projekte) {
    $pids = implode(',', array_map(fn($p) => (int)$p['id'], $projekte));
    $rows = $db->query("SELECT e.id, e.start, e.ende FROM einheiten e WHERE e.projekt_id IN ($pids) AND e.status = 'durchgefuehrt'")->fetchAll();
    $leistung['einheiten'] = count($rows);
    if ($rows) {
        $eids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        foreach ($db->query("SELECT einheit_id, user_id, dauer_min, teilnehmer_anzahl FROM einheit_trainer WHERE einheit_id IN ($eids) AND status <> 'storniert'")->fetchAll() as $t) {
            $leistung['minuten'] += (int)$t['dauer_min'];
            $leistung['trainer'][(int)$t['user_id']] = true;
        }
        foreach ($db->query("SELECT teilnehmer_key FROM anwesenheiten WHERE einheit_id IN ($eids) AND status IN ('anwesend','probetraining')")->fetchAll() as $a) {
            $leistung['teilnahmen']++;
            $leistung['personen'][$a['teilnehmer_key']] = true;
        }
    }
}

// ----------------------------------------------------------------
// PDF
// ----------------------------------------------------------------
require_once ROOT_PATH . '/vendor/autoload.php';
$einst = praeEinstellungen($db);

class NachweisPDF extends TCPDF
{
    public string $kopf = '';
    public function __construct(...$args) { parent::__construct(...$args); $this->tcpdflink = false; }
    public function Header()
    {
        $this->SetFont('dejavusans', 'B', 11);
        $this->SetTextColor(31, 53, 86);
        $this->Cell(0, 8, $this->kopf, 0, 1, 'L');
        $this->SetDrawColor(198, 161, 53);
        $this->SetLineWidth(0.6);
        $this->Line(15, 17, $this->getPageWidth() - 15, 17);
        $this->SetTextColor(0, 0, 0);
    }
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 10, 'Verwendungsnachweis · erstellt am ' . date('d.m.Y') . ' · Seite ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new NachweisPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator(APP_NAME);
$pdf->SetAuthor($einst['vereinsname']);
$pdf->SetTitle('Verwendungsnachweis ' . $f['titel']);
$pdf->SetMargins(15, 22, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->kopf = $einst['vereinsname'] . ' – Verwendungsnachweis';
$pdf->AddPage();
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$eur = fn($v) => number_format((float)$v, 2, ',', '.') . ' €';
$th = 'style="background-color:#EEF1F5; font-weight:bold;"';

$pdf->SetFont('dejavusans', 'B', 15);
$pdf->MultiCell(0, 8, $f['titel'], 0, 'L');
$pdf->SetFont('dejavusans', '', 9);
$verein = $einst['vereinsname'] . ($einst['zvr'] ? ' · ZVR ' . $einst['zvr'] : '') . ($einst['strasse'] ? ' · ' . $einst['strasse'] . ', ' . $einst['plz'] . ' ' . $einst['ort'] : '');
$pdf->MultiCell(0, 5, $verein, 0, 'L');
$pdf->Ln(3);

$zeilen = [
    'Fördergeber' => $f['foerderstelle'],
    'Förderprogramm' => $f['foerderprogramm'] ?? '',
    'Projekt(e)' => implode(', ', array_map(fn($p) => $p['name'], $projekte)),
    'Status' => FOERDER_STATUS[$f['status']]['label'] ?? $f['status'],
    'Bewilligt am' => $f['bewilligungsdatum'] ? date('d.m.Y', strtotime($f['bewilligungsdatum'])) : '',
    'Frist Verwendungsnachweis' => $f['nachweisfrist'] ? date('d.m.Y', strtotime($f['nachweisfrist'])) : '',
];
$html = '<table cellpadding="4" border="0">';
foreach ($zeilen as $l => $w) if ($w !== '') $html .= '<tr><td width="35%"><b>' . $h($l) . '</b></td><td width="65%">' . $h($w) . '</td></tr>';
$html .= '</table>';
$pdf->writeHTML($html, true, false, false, false, '');

// Budget
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->MultiCell(0, 7, '1. Finanzübersicht', 0, 'L');
$pdf->SetFont('dejavusans', '', 9);
$html = '<table cellpadding="4" border="1" style="border-color:#D5DBE3;">'
      . '<tr><td width="70%">Bewilligte Fördersumme</td><td width="30%" align="right">' . $eur($budget['bewilligt']) . '</td></tr>'
      . ($f['betrag_beantragt'] !== null ? '<tr><td>Beantragt</td><td align="right">' . $eur($f['betrag_beantragt']) . '</td></tr>' : '')
      . '<tr><td>Nachgewiesene Kosten gesamt (' . count($kosten) . ' Belege)</td><td align="right"><b>' . $eur($budget['verbraucht']) . '</b></td></tr>'
      . '<tr><td>' . (bccomp($budget['rest'], '0', 2) < 0 ? 'Mehrkosten (über Fördersumme, Eigenmittel)' : 'Nicht verbrauchtes Budget') . '</td><td align="right">' . $eur(ltrim($budget['rest'], '-')) . '</td></tr>'
      . '<tr><td>Bereits ausbezahlt</td><td align="right">' . $eur($budget['ausbezahlt']) . '</td></tr>'
      . '</table>';
$pdf->writeHTML($html, true, false, false, false, '');

// Kosten nach Kategorie
if ($kosten) {
    $je = [];
    foreach ($kosten as $b) $je[$b['kategorie']] = bcadd($je[$b['kategorie']] ?? '0.00', moneyRound($b['betrag']), 2);
    uasort($je, fn($a, $b) => bccomp($b, $a, 2));
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->MultiCell(0, 7, '2. Kosten nach Kategorie', 0, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $html = '<table cellpadding="4" border="1" style="border-color:#D5DBE3;"><tr><td width="55%" ' . $th . '>Kategorie</td><td width="20%" align="right" ' . $th . '>Anteil</td><td width="25%" align="right" ' . $th . '>Betrag</td></tr>';
    foreach ($je as $k => $s) {
        $anteil = bccomp($budget['verbraucht'], '0', 2) > 0 ? round((float)$s / (float)$budget['verbraucht'] * 100, 1) : 0;
        $html .= '<tr><td>' . $h(BUCHUNG_KATEGORIEN[$k] ?? $k) . '</td><td align="right">' . number_format($anteil, 1, ',', '.') . ' %</td><td align="right">' . $eur($s) . '</td></tr>';
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, false, false, '');
}

// Leistungsnachweis
if ($projekte) {
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->MultiCell(0, 7, '3. Leistungsnachweis', 0, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $html = '<table cellpadding="4" border="1" style="border-color:#D5DBE3;">'
          . '<tr><td width="70%">Durchgeführte Einheiten</td><td width="30%" align="right">' . $leistung['einheiten'] . '</td></tr>'
          . '<tr><td>Trainer:innen-Stunden</td><td align="right">' . number_format($leistung['minuten'] / 60, 1, ',', '.') . ' h</td></tr>'
          . '<tr><td>Eingesetzte Trainer:innen</td><td align="right">' . count($leistung['trainer']) . '</td></tr>'
          . '<tr><td>Teilnahmen (laut Anwesenheitsliste)</td><td align="right">' . $leistung['teilnahmen'] . '</td></tr>'
          . '<tr><td>Erreichte Personen</td><td align="right">' . count($leistung['personen']) . '</td></tr>'
          . '</table>';
    $pdf->writeHTML($html, true, false, false, false, '');
}

// Belegliste
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->MultiCell(0, 7, ($projekte ? '4' : '3') . '. Belegaufstellung', 0, 'L');
$pdf->SetFont('dejavusans', '', 8);
if (!$kosten) {
    $pdf->MultiCell(0, 6, 'Keine Kosten verbucht.', 0, 'L');
} else {
    $html = '<table cellpadding="3" border="1" style="border-color:#D5DBE3;"><tr><td width="6%" ' . $th . '>Nr.</td><td width="12%" ' . $th . '>Datum</td><td width="13%" ' . $th . '>Beleg</td><td width="39%" ' . $th . '>Beschreibung</td><td width="16%" ' . $th . '>Kategorie</td><td width="14%" align="right" ' . $th . '>Betrag</td></tr>';
    foreach ($kosten as $i => $b) {
        $html .= '<tr><td>' . ($i + 1) . '</td><td>' . date('d.m.Y', strtotime($b['datum'])) . '</td><td>' . $h($b['belegnummer'] ?? '') . '</td><td>' . $h($b['beschreibung'])
               . ($b['projekt_name'] ? '<br><font color="#777777">' . $h($b['projekt_name']) . '</font>' : '') . '</td><td>' . $h(BUCHUNG_KATEGORIEN[$b['kategorie']] ?? $b['kategorie'])
               . '</td><td align="right">' . $eur($b['betrag']) . '</td></tr>';
    }
    $html .= '<tr><td colspan="5"><b>Summe</b></td><td align="right"><b>' . $eur($budget['verbraucht']) . '</b></td></tr></table>';
    $pdf->writeHTML($html, true, false, false, false, '');
}

if ($einnahmen) {
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->MultiCell(0, 7, 'Einnahmen / Auszahlungen', 0, 'L');
    $pdf->SetFont('dejavusans', '', 8);
    $html = '<table cellpadding="3" border="1" style="border-color:#D5DBE3;"><tr><td width="15%" ' . $th . '>Datum</td><td width="65%" ' . $th . '>Beschreibung</td><td width="20%" align="right" ' . $th . '>Betrag</td></tr>';
    foreach ($einnahmen as $b) $html .= '<tr><td>' . date('d.m.Y', strtotime($b['datum'])) . '</td><td>' . $h($b['beschreibung']) . '</td><td align="right">' . $eur($b['betrag']) . '</td></tr>';
    $html .= '<tr><td colspan="2"><b>Summe</b></td><td align="right"><b>' . $eur($budget['einnahmen']) . '</b></td></tr></table>';
    $pdf->writeHTML($html, true, false, false, false, '');
}

// Bestätigung
$pdf->Ln(6);
$pdf->SetFont('dejavusans', '', 9);
$pdf->MultiCell(0, 5, 'Wir bestätigen, dass die Fördermittel widmungsgemäß verwendet wurden, die angeführten Ausgaben tatsächlich angefallen sind und die Originalbelege für mindestens sieben Jahre aufbewahrt werden.', 0, 'L');
$pdf->Ln(14);
$html = '<table cellpadding="2"><tr><td width="45%" style="border-top:0.5px solid #333;">Ort, Datum</td><td width="10%"></td><td width="45%" style="border-top:0.5px solid #333;">Obfrau/Obmann bzw. Kassier:in (Unterschrift)</td></tr></table>';
$pdf->writeHTML($html, true, false, false, false, '');

$pdf->Output($dateiname . '.pdf', 'I');
