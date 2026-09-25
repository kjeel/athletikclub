<?php
/**
 * Athletikclub Steiermark – Exporte (CSV, XLSX, PDF) aus einer einheitlichen Berichtsstruktur
 *
 * Bericht = [
 *   'titel' => 'Teilnehmerliste', 'untertitel' => 'Kurs …', 'dateiname' => 'teilnehmerliste-kurs-3',
 *   'quer' => false,                                  // PDF im Querformat
 *   'info' => ['Zeitraum' => '…', …],                 // Kopfdaten
 *   'abschnitte' => [[
 *       'titel' => '…', 'spalten' => [['titel' => 'Name', 'typ' => 'text|zahl|geld|datum|datumzeit', 'breite' => 30], …],
 *       'zeilen' => [[…], …], 'summe' => [… oder null je Spalte], 'hinweis' => '…',
 *   ]],
 *   'fusszeile' => 'Optionaler Text unter den Tabellen',
 * ]
 * Für XLSX wird kein ZipArchive benötigt (eigener ZIP-Writer).
 */

require_once ROOT_PATH . '/includes/einstellungen.php';
require_once ROOT_PATH . '/includes/money.php';

/** Zellwert für Anzeige (PDF/CSV) formatieren. */
function exportFormat($wert, string $typ, bool $pdf = true): string
{
    if ($wert === null || $wert === '') return '';
    switch ($typ) {
        case 'geld':  return number_format((float)$wert, 2, ',', '.') . ($pdf ? ' €' : '');
        case 'zahl':  return is_float($wert) || (is_string($wert) && strpos($wert, '.') !== false) ? number_format((float)$wert, 1, ',', '.') : (string)$wert;
        case 'datum': return ($t = strtotime((string)$wert)) ? date('d.m.Y', $t) : (string)$wert;
        case 'datumzeit': return ($t = strtotime((string)$wert)) ? date('d.m.Y H:i', $t) : (string)$wert;
        default: return (string)$wert;
    }
}

/** Schutz vor Formel-Injection in Tabellenprogrammen (CSV). */
function exportZelleSicher(string $s): string
{
    return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s) ? "'" . $s : $s;
}

function exportDateiname(string $name, string $endung): string
{
    $name = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'])));
    return trim($name, '-') . '-' . date('Y-m-d') . '.' . $endung;
}

// ----------------------------------------------------------------
// CSV (Semikolon, UTF-8 mit BOM – öffnet in Excel mit Umlauten korrekt)
// ----------------------------------------------------------------
function exportCsv(array $bericht): string
{
    $f = fopen('php://temp', 'r+');
    fwrite($f, "\xEF\xBB\xBF");
    $mehrere = count($bericht['abschnitte']) > 1;
    foreach ($bericht['abschnitte'] as $i => $a) {
        if ($i > 0) fputcsv($f, [], ';');
        if ($mehrere && !empty($a['titel'])) fputcsv($f, [exportZelleSicher($a['titel'])], ';');
        fputcsv($f, array_map(fn($s) => exportZelleSicher($s['titel']), $a['spalten']), ';');
        $zeilen = $a['zeilen'];
        if (!empty($a['summe'])) $zeilen[] = $a['summe'];
        foreach ($zeilen as $z) {
            $out = [];
            foreach ($a['spalten'] as $j => $s) $out[] = exportZelleSicher(exportFormat($z[$j] ?? null, $s['typ'] ?? 'text', false));
            fputcsv($f, $out, ';');
        }
    }
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return $csv;
}

// ----------------------------------------------------------------
// XLSX (Office Open XML, ein Tabellenblatt je Abschnitt)
// ----------------------------------------------------------------
function exportXlsx(array $bericht): string
{
    $x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $spalte = function (int $n): string { $s = ''; for ($n++; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s; return $s; };
    $blaetter = [];
    $namen = [];
    foreach ($bericht['abschnitte'] as $i => $a) {
        $name = mb_substr(trim(preg_replace('#[\[\]:*?/\\\\]#', ' ', $a['titel'] ?: $bericht['titel'])) ?: 'Tabelle', 0, 28);
        while (in_array($name, $namen, true)) $name = mb_substr($name, 0, 25) . ' ' . ($i + 1);
        $namen[] = $name;
        $zeilen = [];
        $r = 1;
        $zelle = function ($wert, string $typ, int $c, int $r, bool $kopf = false) use ($x, $spalte) {
            $ref = $spalte($c) . $r;
            if ($wert === null || $wert === '') return '';
            if (!$kopf && in_array($typ, ['geld', 'zahl'], true) && is_numeric($wert)) {
                return '<c r="' . $ref . '" s="' . ($typ === 'geld' ? 2 : 0) . '"><v>' . (float)$wert . '</v></c>';
            }
            if (!$kopf && in_array($typ, ['datum', 'datumzeit'], true)) $wert = exportFormat($wert, $typ, false);
            return '<c r="' . $ref . '" t="inlineStr"' . ($kopf ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x($wert) . '</t></is></c>';
        };
        $kopf = '';
        foreach ($a['spalten'] as $c => $s) $kopf .= $zelle($s['titel'], 'text', $c, $r, true);
        $zeilen[] = '<row r="' . $r . '">' . $kopf . '</row>';
        $alle = $a['zeilen'];
        if (!empty($a['summe'])) $alle[] = $a['summe'];
        foreach ($alle as $z) {
            $r++;
            $zeile = '';
            foreach ($a['spalten'] as $c => $s) $zeile .= $zelle($z[$c] ?? null, $s['typ'] ?? 'text', $c, $r);
            $zeilen[] = '<row r="' . $r . '">' . $zeile . '</row>';
        }
        $cols = '';
        foreach ($a['spalten'] as $c => $s) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . max(8, min(60, (int)($s['breite'] ?? 16))) . '" customWidth="1"/>';
        $blaetter[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>' . implode('', $zeilen) . '</sheetData></worksheet>';
    }
    $dateien = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($blaetter)))
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(fn($i) => '<sheet name="' . $x($namen[$i]) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', array_keys($blaetter)))
            . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(fn($i) => '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>', array_keys($blaetter)))
            . '<Relationship Id="rId' . (count($blaetter) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F3556"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs></styleSheet>',
    ];
    foreach ($blaetter as $i => $b) $dateien['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $b;
    return zipErstellen($dateien);
}

/** Minimaler ZIP-Writer (Deflate, falls verfügbar, sonst unkomprimiert). */
function zipErstellen(array $dateien): string
{
    $daten = $zentral = '';
    $n = 0;
    $zeit = getdate();
    $dosZeit = ($zeit['hours'] << 11) | ($zeit['minutes'] << 5) | intdiv($zeit['seconds'], 2);
    $dosDatum = (($zeit['year'] - 1980) << 9) | ($zeit['mon'] << 5) | $zeit['mday'];
    foreach ($dateien as $name => $inhalt) {
        $crc = crc32($inhalt);
        $methode = function_exists('gzdeflate') ? 8 : 0;
        $komp = $methode ? gzdeflate($inhalt, 6) : $inhalt;
        $kopf = pack('vvvvvVVVvv', 20, 0x0800, $methode, $dosZeit, $dosDatum, $crc, strlen($komp), strlen($inhalt), strlen($name), 0);
        $offset = strlen($daten);
        $daten .= "PK\x03\x04" . $kopf . $name . $komp;
        $zentral .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $methode, $dosZeit, $dosDatum, $crc, strlen($komp), strlen($inhalt), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $n++;
    }
    return $daten . $zentral . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($zentral), strlen($daten), 0);
}

// ----------------------------------------------------------------
// PDF im Corporate Design (Navy #1F3556, Gold #C6A135)
// ----------------------------------------------------------------
function exportPdf(array $bericht): string
{
    if (!class_exists('TCPDF') && is_file(ROOT_PATH . '/vendor/autoload.php')) require_once ROOT_PATH . '/vendor/autoload.php';
    require_once ROOT_PATH . '/includes/rechnung-pdf.php';
    $v = verein();
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $quer = !empty($bericht['quer']);

    $pdf = new class($quer ? 'L' : 'P', 'mm', 'A4', true, 'UTF-8', false) extends TCPDF {
        public string $fuss = '';
        public function __construct(...$a) { parent::__construct(...$a); $this->tcpdflink = false; }
        public function Header() {}
        public function Footer()
        {
            $this->SetY(-14);
            $this->SetDrawColor(198, 161, 53);
            $this->SetLineWidth(0.3);
            $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());
            $this->SetFont('dejavusans', '', 7);
            $this->SetTextColor(110, 110, 110);
            $this->Cell(0, 6, $this->fuss, 0, 0, 'L');
            $this->SetX(15);
            $this->Cell(0, 6, $this->getAliasRightShift() . 'Seite ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    };
    $pdf->SetCreator(APP_NAME);
    $pdf->SetAuthor($v['vereinsname']);
    $pdf->SetTitle($bericht['titel']);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->fuss = $v['vereinsname'] . ' · ' . $bericht['titel'] . ' · erstellt am ' . date('d.m.Y H:i');
    $pdf->AddPage();
    $breite = $pdf->getPageWidth() - 30;

    $logo = $v['logo'] && preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_]+\.(jpg|png)$#', $v['logo']) && is_file(IMG_PATH . '/' . $v['logo']) ? IMG_PATH . '/' . $v['logo'] : null;
    if ($logo) $pdf->Image($logo, 15, 12, 0, 13);
    else $pdf->ImageSVG('@' . VEREIN_LOGO_SVG, 15, 12, 13, 13);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(31, 53, 86);
    $pdf->SetXY(31, 15);
    $pdf->Cell(100, 6, mb_strtoupper($v['vereinsname']), 0, 0);
    $pdf->SetFont('dejavusans', '', 7.5);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY($pdf->getPageWidth() - 115, 13);
    $pdf->MultiCell(100, 3.6, implode("\n", array_filter([$v['adresse_zeile'], $v['email'], $v['website']])), 0, 'R');

    $pdf->SetXY(15, 32);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetTextColor(31, 53, 86);
    $pdf->Cell(0, 8, $bericht['titel'], 0, 1);
    if (!empty($bericht['untertitel'])) {
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 6, $bericht['untertitel'], 0, 1);
    }
    $pdf->SetDrawColor(198, 161, 53);
    $pdf->SetLineWidth(0.7);
    $pdf->Line(15, $pdf->GetY() + 1.5, 45, $pdf->GetY() + 1.5);
    $pdf->Ln(5);

    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->SetTextColor(30, 30, 30);
    if (!empty($bericht['info'])) {
        $html = '<table cellpadding="2.5">';
        $paare = array_chunk($bericht['info'], 2, true);
        foreach ($paare as $paar) {
            $html .= '<tr>';
            foreach ($paar as $k => $w) $html .= '<td width="16%" style="color:#6b7280;">' . $h($k) . '</td><td width="34%"><b>' . $h($w) . '</b></td>';
            $html .= '</tr>';
        }
        $pdf->writeHTML($html . '</table>', true, false, true, false, '');
        $pdf->Ln(2);
    }

    foreach ($bericht['abschnitte'] as $a) {
        if (!empty($a['titel'])) {
            $pdf->SetFont('dejavusans', 'B', 10.5);
            $pdf->SetTextColor(31, 53, 86);
            $pdf->Cell(0, 7, $a['titel'], 0, 1);
        }
        $gesamt = array_sum(array_map(fn($s) => (float)($s['breite'] ?? 16), $a['spalten'])) ?: 1;
        $html = '<table cellpadding="3" border="0"><thead><tr style="background-color:#1F3556;color:#ffffff;font-weight:bold;">';
        foreach ($a['spalten'] as $s) {
            $rechts = in_array($s['typ'] ?? 'text', ['geld', 'zahl'], true);
            $html .= '<th width="' . round(($s['breite'] ?? 16) / $gesamt * 100, 2) . '%"' . ($rechts ? ' align="right"' : '') . '>' . $h($s['titel']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if (!$a['zeilen']) {
            $html .= '<tr><td colspan="' . count($a['spalten']) . '" style="color:#6b7280;">Keine Einträge.</td></tr>';
        }
        foreach ($a['zeilen'] as $i => $z) {
            $html .= '<tr' . ($i % 2 ? ' style="background-color:#F5F6F8;"' : '') . '>';
            foreach ($a['spalten'] as $j => $s) {
                $rechts = in_array($s['typ'] ?? 'text', ['geld', 'zahl'], true);
                $html .= '<td width="' . round(($s['breite'] ?? 16) / $gesamt * 100, 2) . '%"' . ($rechts ? ' align="right"' : '') . '>' . nl2br($h(exportFormat($z[$j] ?? null, $s['typ'] ?? 'text'))) . '</td>';
            }
            $html .= '</tr>';
        }
        if (!empty($a['summe'])) {
            $html .= '<tr style="font-weight:bold;border-top:1px solid #C6A135;">';
            foreach ($a['spalten'] as $j => $s) {
                $rechts = in_array($s['typ'] ?? 'text', ['geld', 'zahl'], true);
                $html .= '<td width="' . round(($s['breite'] ?? 16) / $gesamt * 100, 2) . '%"' . ($rechts ? ' align="right"' : '') . ' style="border-top:1px solid #C6A135;">' . $h(exportFormat($a['summe'][$j] ?? null, $s['typ'] ?? 'text')) . '</td>';
            }
            $html .= '</tr>';
        }
        $pdf->SetFont('dejavusans', '', count($a['spalten']) > 9 ? 6.5 : 8);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->writeHTML($html . '</tbody></table>', true, false, true, false, '');
        if (!empty($a['hinweis'])) {
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->MultiCell($breite, 4, $a['hinweis'], 0, 'L');
        }
        $pdf->Ln(3);
    }
    if (!empty($bericht['fusszeile'])) {
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell($breite, 4.5, $bericht['fusszeile'], 0, 'L');
    }
    if (!empty($bericht['unterschrift'])) {
        $pdf->Ln(14);
        $y = $pdf->GetY();
        $pdf->SetDrawColor(150, 150, 150);
        $pdf->SetLineWidth(0.2);
        foreach ($bericht['unterschrift'] as $i => $label) {
            $x = 15 + $i * 90;
            $pdf->Line($x, $y, $x + 70, $y);
            $pdf->SetXY($x, $y + 1);
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->Cell(70, 4, $label, 0, 0);
        }
    }
    return $pdf->Output('', 'S');
}

/** Bericht im gewünschten Format ausliefern und beenden. */
function exportAusliefern(array $bericht, string $format): void
{
    $name = $bericht['dateiname'] ?? $bericht['titel'];
    switch ($format) {
        case 'csv':  $inhalt = exportCsv($bericht);  $typ = 'text/csv; charset=utf-8'; $datei = exportDateiname($name, 'csv'); $inline = false; break;
        case 'xlsx': $inhalt = exportXlsx($bericht); $typ = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; $datei = exportDateiname($name, 'xlsx'); $inline = false; break;
        default:     $inhalt = exportPdf($bericht);  $typ = 'application/pdf'; $datei = exportDateiname($name, 'pdf'); $inline = true;
    }
    if (!headers_sent()) {
        header('Content-Type: ' . $typ);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $datei . '"');
        header('Content-Length: ' . strlen($inhalt));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $inhalt;
    exit;
}
