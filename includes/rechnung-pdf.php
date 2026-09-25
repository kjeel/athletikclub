<?php
/**
 * Athletikclub Steiermark – Rechnungs-PDF (Corporate Design: Navy #1F3556, Gold #C6A135)
 * Vereinsdaten ausschließlich aus verein()/einstellung() – nichts ist fest eingetragen.
 */

require_once ROOT_PATH . '/includes/rechnungen.php';

/** SVG-Bildmarke des Vereins (identisch mit dem Website-Logo). */
const VEREIN_LOGO_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50" width="50" height="50"><circle cx="25" cy="25" r="23" fill="none" stroke="#C6A135" stroke-width="2.5"/><path d="M14 34L25 14L36 34" fill="none" stroke="#C6A135" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 28H32" fill="none" stroke="#C6A135" stroke-width="2" stroke-linecap="round"/><circle cx="25" cy="14" r="2.5" fill="#C6A135"/></svg>';

function rechnungPdfErzeugen(PDO $db, array $r): string
{
    if (!class_exists('TCPDF') && is_file(ROOT_PATH . '/vendor/autoload.php')) require_once ROOT_PATH . '/vendor/autoload.php';
    $v = verein();
    $pos = rechnungPositionen($db, (int)$r['id']);
    $bezahlt = rechnungBezahlt($db, (int)$r['id']);
    $storno = $r['typ'] === 'storno';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    $d = fn($x) => $x ? date('d.m.Y', strtotime($x)) : '';

    $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends TCPDF {
        public string $fuss = '';
        public function __construct(...$a) { parent::__construct(...$a); $this->tcpdflink = false; }
        public function Header() {}
        public function Footer()
        {
            $this->SetY(-18);
            $this->SetDrawColor(198, 161, 53);
            $this->SetLineWidth(0.4);
            $this->Line(20, $this->GetY(), 190, $this->GetY());
            $this->SetFont('dejavusans', '', 7);
            $this->SetTextColor(110, 110, 110);
            $this->MultiCell(0, 4, $this->fuss, 0, 'C');
        }
    };
    $pdf->SetCreator(APP_NAME);
    $pdf->SetAuthor($v['vereinsname']);
    $pdf->SetTitle(($storno ? 'Stornorechnung ' : 'Rechnung ') . ($r['nummer'] ?? 'Entwurf'));
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->fuss = implode(' · ', array_filter([$v['vereinsname'], $v['adresse_zeile'], $v['zvr'] ? 'ZVR ' . $v['zvr'] : null, $v['steuernummer'] ? 'St.-Nr. ' . $v['steuernummer'] : null]))
               . "\n" . implode(' · ', array_filter([$v['email'], $v['telefon'], $v['website']]));
    $pdf->AddPage();

    // Kopf: Logo + Vereinsname links, Absender rechts
    $logo = $v['logo'] && preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_]+\.(jpg|png)$#', $v['logo']) && is_file(IMG_PATH . '/' . $v['logo']) ? IMG_PATH . '/' . $v['logo'] : null;
    if ($logo) $pdf->Image($logo, 20, 15, 0, 16);
    else $pdf->ImageSVG('@' . VEREIN_LOGO_SVG, 20, 14, 16, 16);
    $pdf->SetXY(39, 15);
    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->SetTextColor(31, 53, 86);
    $pdf->SetXY(39, 18);
    $pdf->Cell(80, 7, mb_strtoupper($v['vereinsname']), 0, 0);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(125, 15);
    $pdf->MultiCell(65, 4, implode("\n", array_filter([$v['vereinsname'], $v['strasse'], trim(($v['plz'] ?? '') . ' ' . ($v['ort'] ?? '')), $v['email'], $v['telefon']])), 0, 'R');

    // Empfänger (Fensterposition)
    $pdf->SetXY(20, 48);
    $pdf->SetFont('dejavusans', '', 6.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(85, 4, $v['vereinsname'] . ($v['adresse_zeile'] ? ' · ' . $v['adresse_zeile'] : ''), 0, 1);
    $pdf->SetX(20);
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetTextColor(20, 20, 20);
    $pdf->MultiCell(85, 5, implode("\n", array_filter([$r['empf_name'], $r['empf_zusatz'], $r['empf_strasse'], trim(($r['empf_plz'] ?? '') . ' ' . ($r['empf_ort'] ?? '')), $r['empf_land'],
                                                        $r['empf_uid'] ? 'UID: ' . $r['empf_uid'] : null])), 0, 'L');

    // Rechnungsdaten rechts
    $meta = [
        $storno ? 'Stornorechnung Nr.' : 'Rechnung Nr.' => $r['nummer'] ?: 'ENTWURF',
        'Rechnungsdatum' => $d($r['rechnungsdatum']) ?: $d(date('Y-m-d')),
        'Leistungszeitraum' => $r['leistung_von'] ? $d($r['leistung_von']) . ($r['leistung_bis'] && $r['leistung_bis'] !== $r['leistung_von'] ? ' – ' . $d($r['leistung_bis']) : '') : '',
        'Zahlbar bis' => $storno ? '' : $d($r['faellig_am']),
    ];
    $pdf->SetXY(125, 52);
    $html = '<table cellpadding="2">';
    foreach ($meta as $k => $w) if ($w !== '') $html .= '<tr><td width="45%" style="color:#6b7280;">' . $h($k) . '</td><td width="55%" align="right"><b>' . $h($w) . '</b></td></tr>';
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->writeHTMLCell(65, 0, 125, 52, $html . '</table>', 0, 1);

    // Titel
    $pdf->SetXY(20, 92);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetTextColor(31, 53, 86);
    $pdf->Cell(0, 9, $storno ? 'STORNORECHNUNG' : ($r['status'] === 'entwurf' ? 'RECHNUNG (ENTWURF)' : 'RECHNUNG'), 0, 1);
    $pdf->SetDrawColor(198, 161, 53);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(20, $pdf->GetY(), 45, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetFont('dejavusans', '', 9.5);
    if (trim((string)$r['text_oben']) !== '') { $pdf->MultiCell(0, 5, $r['text_oben'], 0, 'L'); $pdf->Ln(2); }

    // Positionen
    $mit_ust = (float)$r['betrag_ust'] != 0;
    $th = 'style="background-color:#1F3556;color:#ffffff;font-weight:bold;"';
    $html = '<table cellpadding="4" style="border-collapse:collapse;"><tr>'
          . '<td width="7%" ' . $th . '>Pos.</td><td width="' . ($mit_ust ? '43' : '51') . '%" ' . $th . '>Beschreibung</td><td width="12%" align="right" ' . $th . '>Menge</td>'
          . '<td width="15%" align="right" ' . $th . '>Einzelpreis</td>' . ($mit_ust ? '<td width="8%" align="right" ' . $th . '>USt</td>' : '') . '<td width="15%" align="right" ' . $th . '>Betrag</td></tr>';
    foreach ($pos as $i => $p) {
        $bg = $i % 2 ? ' style="background-color:#F4F6F9;"' : '';
        $html .= '<tr' . $bg . '><td>' . (int)$p['pos'] . '</td><td>' . nl2br($h($p['beschreibung'])) . '</td><td align="right">' . $h(rtrim(rtrim(number_format((float)$p['menge'], 2, ',', '.'), '0'), ',')) . ($p['einheit'] ? ' ' . $h($p['einheit']) : '') . '</td>'
               . '<td align="right">' . $eur($p['einzelpreis']) . '</td>' . ($mit_ust ? '<td align="right">' . $h(rtrim(rtrim(number_format((float)$p['ust_satz'], 2, ',', ''), '0'), ',')) . ' %</td>' : '') . '<td align="right">' . $eur($p['betrag']) . '</td></tr>';
    }
    $html .= '</table>';
    $pdf->writeHTML($html, true, false, false, false, '');

    // Summen
    $sum = '<table cellpadding="3">';
    if ($mit_ust) {
        $sum .= '<tr><td width="70%" align="right">Summe netto</td><td width="30%" align="right">' . $eur($r['betrag_netto']) . '</td></tr>'
              . '<tr><td align="right">Umsatzsteuer</td><td align="right">' . $eur($r['betrag_ust']) . '</td></tr>';
    }
    $sum .= '<tr><td width="70%" align="right"><b>' . ($storno ? 'Gutschrift gesamt' : 'Gesamtbetrag') . '</b></td><td width="30%" align="right" style="border-top:1px solid #1F3556;"><b>' . $eur($r['betrag_brutto']) . '</b></td></tr>';
    if (!$storno && bccomp($bezahlt, '0', 2) > 0) {
        $sum .= '<tr><td align="right">bereits bezahlt</td><td align="right">– ' . $eur($bezahlt) . '</td></tr>'
              . '<tr><td align="right"><b>offener Betrag</b></td><td align="right"><b>' . $eur(bcsub(moneyRound($r['betrag_brutto']), $bezahlt, 2)) . '</b></td></tr>';
    }
    $pdf->writeHTML($sum . '</table>', true, false, false, false, '');
    $pdf->Ln(3);

    $pdf->SetFont('dejavusans', '', 9);
    if (trim((string)$r['steuerhinweis']) !== '') { $pdf->MultiCell(0, 5, $r['steuerhinweis'], 0, 'L'); $pdf->Ln(1); }
    if (!$storno && $r['status'] !== 'bezahlt') {
        $zahl = '<table cellpadding="5" style="background-color:#F4F6F9;"><tr><td><b style="color:#1F3556;">Zahlungsinformationen</b><br>'
              . 'Bitte überweise den Betrag bis ' . $h($d($r['faellig_am']) ?: 'zum Fälligkeitsdatum') . ' auf folgendes Konto:<br>'
              . 'Empfänger: ' . $h($v['vereinsname']) . '<br>'
              . ($v['iban'] ? 'IBAN: <b>' . $h(ibanFormat($v['iban'])) . '</b>' . ($v['bic'] ? ' · BIC: ' . $h($v['bic']) : '') . '<br>' : '<i>Bankverbindung in den Einstellungen hinterlegen.</i><br>')
              . 'Verwendungszweck: <b>' . $h($r['nummer'] ?: 'Rechnungsnummer') . '</b></td></tr></table>';
        $pdf->writeHTML($zahl, true, false, false, false, '');
    } elseif ($r['status'] === 'bezahlt') {
        $pdf->SetTextColor(21, 128, 61);
        $pdf->MultiCell(0, 5, 'Vielen Dank – diese Rechnung ist bezahlt.', 0, 'L');
        $pdf->SetTextColor(20, 20, 20);
    }
    $unten = trim(implode("\n", array_filter([(string)$r['text_unten'], einstellung('rechnung_fusszeile', '')])));
    if ($unten !== '') { $pdf->Ln(2); $pdf->MultiCell(0, 5, $unten, 0, 'L'); }

    return $pdf->Output('rechnung.pdf', 'S');
}

function rechnungPdfDateiname(array $r): string
{
    return ($r['typ'] === 'storno' ? 'Stornorechnung_' : 'Rechnung_') . preg_replace('/[^A-Za-z0-9-]/', '_', $r['nummer'] ?: 'Entwurf-' . $r['id']) . '.pdf';
}
