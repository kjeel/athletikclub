<?php
/**
 * Athletikclub Steiermark – PRAE-PDFs
 *   ?abrechnung=ID          Monatsabrechnung zum Unterschreiben (Admin oder die Empfänger:in selbst)
 *   ?jahr=JJJJ&monat=M      alle Monatsabrechnungen eines Monats (Admin)
 *   ?jahresuebersicht=JJJJ  Jahresübersicht für Aufzeichnungen/Kassaprüfung (Admin)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/prae.php';

requireLogin();
require_once ROOT_PATH . '/vendor/autoload.php';

$db    = getDB();
$einst = praeEinstellungen($db);

class PraePDF extends TCPDF
{
    public string $kopf = '';

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->tcpdflink = false;
    }

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
        $this->Cell(0, 10, 'Pauschale Reiseaufwandsentschädigung gem. § 3 Abs. 1 Z 16c EStG · Aufbewahrung 7 Jahre · Seite ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new PraePDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator(APP_NAME);
$pdf->SetAuthor($einst['vereinsname']);
$pdf->SetMargins(15, 22, 15);
$pdf->SetAutoPageBreak(true, 18);
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$th = 'style="background-color:#EEF1F5; font-weight:bold;"';
$verein_zeile = $einst['vereinsname'] . ' · ZVR ' . $einst['zvr'] . ($einst['strasse'] ? ' · ' . $einst['strasse'] . ', ' . $einst['plz'] . ' ' . $einst['ort'] : '');

/** Eine Monatsabrechnung als Seite. */
$abrechnungSeite = function (array $a) use ($pdf, $db, $einst, $h, $th, $verein_zeile) {
    $e = praeEmpfaenger($db, (int)$a['empfaenger_id']);
    $einsaetze = praeEinsaetze($db, (int)$a['empfaenger_id'], (int)$a['jahr'], (int)$a['monat']);
    $b = praeMonatBerechnen($einsaetze);
    $pdf->kopf = 'PRAE-ABRECHNUNG · ' . mb_strtoupper(PRAE_MONATE[(int)$a['monat']]) . ' ' . $a['jahr'];
    $pdf->AddPage();

    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->MultiCell(0, 4.5, $verein_zeile, 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->MultiCell(0, 7, 'Pauschale Reiseaufwandsentschädigung', 0, 'L');
    $pdf->Ln(1);

    $zeilen = [
        'Empfänger:in' => praeName($e),
        'Funktion'     => PRAE_ROLLEN[$e['rolle']] ?? $e['rolle'],
        'Geburtsdatum' => $e['geburtsdatum'] ? date('d.m.Y', strtotime($e['geburtsdatum'])) : '–',
        'Anschrift'    => trim(($e['strasse'] ?? '') . ', ' . ($e['plz'] ?? '') . ' ' . ($e['ort'] ?? ''), ', ') ?: '–',
        'Zeitraum'     => PRAE_MONATE[(int)$a['monat']] . ' ' . $a['jahr'],
    ];
    $html = '<table cellpadding="2" style="font-size:9pt;">';
    foreach ($zeilen as $k => $w) $html .= '<tr><td width="30%"><b>' . $h($k) . '</b></td><td width="70%">' . $h($w) . '</td></tr>';
    $html .= '</table>';
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->writeHTML($html, true, false, false, false, '');

    $html = '<table border="0.3" cellpadding="3" style="font-size:8.5pt;"><thead><tr>'
          . '<td ' . $th . ' width="22%">Datum</td><td ' . $th . ' width="18%">Art</td><td ' . $th . ' width="42%">Beschreibung</td><td ' . $th . ' width="18%" align="right">Betrag</td></tr></thead><tbody>';
    $wt = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    foreach ($einsaetze as $es) {
        $ueber = bccomp(moneyRound($es['betrag']), PRAE_TAG_MAX, 2) > 0;
        $html .= '<tr nobr="true"><td width="22%">' . $wt[(int)date('N', strtotime($es['datum'])) - 1] . ', ' . date('d.m.Y', strtotime($es['datum'])) . '</td>'
               . '<td width="18%">' . $h(PRAE_ARTEN[$es['art']] ?? $es['art']) . '</td><td width="42%">' . $h($es['beschreibung'] ?? '') . '</td>'
               . '<td width="18%" align="right"' . ($ueber ? ' style="color:#B91C1C;"' : '') . '>' . $h(moneyFormat($es['betrag'])) . '</td></tr>';
    }
    $html .= '<tr><td width="82%" colspan="3"><b>' . $b['tage'] . ' Einsatztage – Summe</b></td><td width="18%" align="right"><b>' . $h(moneyFormat($b['gesamt'])) . '</b></td></tr>';
    $html .= '<tr><td width="82%" colspan="3">davon steuer- und sozialversicherungsfrei (max. 120 € je Einsatztag, 720 € je Monat)</td><td width="18%" align="right">' . $h(moneyFormat($b['steuerfrei'])) . '</td></tr>';
    if (bccomp($b['ueberschuss'], '0', 2) > 0) {
        $html .= '<tr><td width="82%" colspan="3" style="color:#B91C1C;">steuerpflichtiger Mehrbetrag (Abrechnung über die Lohnverrechnung)</td><td width="18%" align="right" style="color:#B91C1C;">' . $h(moneyFormat($b['ueberschuss'])) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->writeHTML($html, true, false, false, false, '');

    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', 'B', 9.5);
    $pdf->Cell(0, 6, 'Erklärung der Empfängerin / des Empfängers', 0, 1);
    $pdf->SetFont('dejavusans', '', 8.5);
    foreach (PRAE_ERKLAERUNGEN as $text) $pdf->MultiCell(0, 4.6, '•  ' . $text, 0, 'L');

    $pdf->Ln(2);
    $auszahlung = $a['status'] === 'ausbezahlt'
        ? 'Ausbezahlt am ' . date('d.m.Y', strtotime($a['ausgezahlt_am'])) . ' per ' . ($a['zahlungsart'] === 'bar' ? 'Barzahlung' : 'Überweisung' . ($e['iban'] ? ' auf ' . substr(preg_replace('/\s+/', '', $e['iban']), 0, 4) . ' •••• ' . substr(preg_replace('/\s+/', '', $e['iban']), -4) : ''))
        : 'Auszahlung: ' . PRAE_STATUS[$a['status']]['label'];
    $pdf->MultiCell(0, 5, $auszahlung . ($a['bestaetigt_am'] ? ' · im System bestätigt am ' . date('d.m.Y H:i', strtotime($a['bestaetigt_am'])) : ''), 0, 'L');

    $pdf->Ln(14);
    $y = $pdf->GetY();
    $pdf->Line(15, $y, 95, $y);
    $pdf->Line(115, $y, 195, $y);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetXY(15, $y + 1);
    $pdf->Cell(80, 4, 'Ort, Datum, Unterschrift Empfänger:in', 0, 0);
    $pdf->SetXY(115, $y + 1);
    $pdf->Cell(80, 4, 'Für den Verein' . ($einst['verantwortlich'] ? ': ' . $einst['verantwortlich'] : ''), 0, 1);
};

if (!empty($_GET['abrechnung'])) {
    $stmt = $db->prepare('SELECT a.*, e.user_id FROM prae_abrechnungen a JOIN prae_empfaenger e ON e.id = a.empfaenger_id WHERE a.id = ? AND a.organization_id = ?');
    $stmt->execute([(int)$_GET['abrechnung'], currentOrgId()]);
    $a = $stmt->fetch();
    if (!$a || (!isAdmin() && (int)$a['user_id'] !== (int)getCurrentUserId())) { http_response_code(404); exit('Abrechnung nicht gefunden.'); }
    $abrechnungSeite($a);
    $name = 'PRAE_' . $a['jahr'] . '-' . sprintf('%02d', $a['monat']) . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', praeName(praeEmpfaenger($db, (int)$a['empfaenger_id'])));
} elseif (!empty($_GET['jahresuebersicht'])) {
    requireAdmin();
    $jahr = (int)$_GET['jahresuebersicht'];
    $daten = praeJahresdaten($db, $jahr);
    $pdf->kopf = 'PRAE-JAHRESÜBERSICHT ' . $jahr;
    $pdf->AddPage('L');
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->MultiCell(0, 4.5, $verein_zeile . ($einst['steuernummer'] ? ' · St.Nr. ' . $einst['steuernummer'] : ''), 0, 'L');
    $pdf->Ln(2);
    $html = '<table border="0.3" cellpadding="3" style="font-size:7.5pt;"><thead><tr><td ' . $th . ' width="20%">Empfänger:in</td>';
    foreach (PRAE_MONATE as $m => $label) $html .= '<td ' . $th . ' width="5.5%" align="right">' . mb_substr($label, 0, 3) . '</td>';
    $html .= '<td ' . $th . ' width="7%" align="right">Summe L19</td><td ' . $th . ' width="7%" align="right">steuerpfl.</td></tr></thead><tbody>';
    $gesamt = $gesamt_ueber = [];
    foreach ($daten as $d) {
        $stmt = $db->prepare("SELECT monat, betrag_steuerfrei FROM prae_abrechnungen WHERE empfaenger_id = ? AND jahr = ? AND status = 'ausbezahlt'");
        $stmt->execute([$d['empfaenger_id'], $jahr]);
        $je_monat = array_column($stmt->fetchAll(), 'betrag_steuerfrei', 'monat');
        $html .= '<tr nobr="true"><td width="20%">' . $h(praeName($d['empfaenger'])) . '<br><span style="color:#666;">' . $h($d['refnr']) . '</span></td>';
        foreach (PRAE_MONATE as $m => $_) $html .= '<td width="5.5%" align="right">' . (isset($je_monat[$m]) ? number_format((float)$je_monat[$m], 2, ',', '.') : '') . '</td>';
        $html .= '<td width="7%" align="right"><b>' . number_format((float)$d['betrag'], 2, ',', '.') . '</b></td><td width="7%" align="right">' . (bccomp($d['ueberschuss'], '0', 2) > 0 ? number_format((float)$d['ueberschuss'], 2, ',', '.') : '') . '</td></tr>';
        $gesamt[] = $d['betrag'];
        $gesamt_ueber[] = $d['ueberschuss'];
    }
    $html .= '<tr><td width="86%" colspan="13"><b>Gesamt</b></td><td width="7%" align="right"><b>' . number_format((float)moneySum($gesamt), 2, ',', '.') . '</b></td><td width="7%" align="right">' . number_format((float)moneySum($gesamt_ueber), 2, ',', '.') . '</td></tr></tbody></table>';
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->writeHTML($html, true, false, false, false, '');
    $pdf->SetFont('dejavusans', '', 7.5);
    $pdf->MultiCell(0, 4, 'Ausbezahlte, steuerfreie Beträge je Monat. Meldung an das Finanzamt per L19 über ELDA bis ' . date('d.m.Y', strtotime(praeMeldefrist($jahr))) . '. Steuerpflichtige Mehrbeträge sind über die Lohnverrechnung (L16) abzurechnen. Erstellt am ' . date('d.m.Y') . '.', 0, 'L');
    $name = 'PRAE_Jahresuebersicht_' . $jahr;
} else {
    requireAdmin();
    $jahr = (int)($_GET['jahr'] ?? date('Y'));
    $monat = (int)($_GET['monat'] ?? date('n'));
    $stmt = $db->prepare('SELECT a.* FROM prae_abrechnungen a JOIN prae_empfaenger e ON e.id = a.empfaenger_id WHERE a.organization_id = ? AND a.jahr = ? AND a.monat = ? ORDER BY e.nachname, e.vorname');
    $stmt->execute([currentOrgId(), $jahr, $monat]);
    $liste = $stmt->fetchAll();
    if (!$liste) { http_response_code(404); exit('Keine Abrechnungen in diesem Monat.'); }
    foreach ($liste as $a) $abrechnungSeite($a);
    $name = 'PRAE_' . $jahr . '-' . sprintf('%02d', $monat) . '_alle';
}

$pdf->SetTitle($name);
$pdf->Output($name . '.pdf', 'I');
exit;
