<?php
/**
 * Athletikclub Steiermark – Admin: Bewegungsland-Steiermark-PDFs erzeugen
 * (Kooperationsvereinbarung / Rückmeldeblatt / Rechnung)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireAdmin();

require_once ROOT_PATH . '/vendor/autoload.php';

$db   = getDB();
$type = $_GET['type'] ?? '';

class KooperationPDF extends TCPDF
{
    public string $headerTitel = 'BEWEGUNGSLAND STEIERMARK';

    public function Header()
    {
        $this->SetFont('dejavusans', 'B', 14);
        $this->SetTextColor(31, 53, 86);
        $this->Cell(0, 8, $this->headerTitel, 0, 1, 'L');
        $this->SetDrawColor(198, 161, 53);
        $this->SetLineWidth(0.6);
        $this->Line(15, 22, 195, 22);
        $this->Ln(6);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 10, 'Athletikclub Steiermark · ZVR ' . VEREIN_ZVR . ' · Seite ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }

    public function feldZeile(string $label, ?string $wert, float $labelWidth = 65): void
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

function neuesPdf(string $titel): KooperationPDF
{
    $pdf = new KooperationPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->headerTitel = $titel;
    $pdf->SetCreator('Athletikclub Steiermark');
    $pdf->SetAuthor('Athletikclub Steiermark');
    $pdf->SetTitle($titel);
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15, 26, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    return $pdf;
}

// ----------------------------------------------------------------
// Typ: Kooperationsvereinbarung
// ----------------------------------------------------------------
if ($type === 'vereinbarung') {
    $kooperation_id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM kooperationen WHERE id = ? AND organization_id = ? LIMIT 1');
    $stmt->execute([$kooperation_id, currentOrgId()]);
    $k = $stmt->fetch();
    if (!$k) { http_response_code(404); die('Kooperation nicht gefunden.'); }

    $pdf = neuesPdf('KOOPERATIONSVEREINBARUNG GEMEINDE UND VEREIN');

    $pdf->abschnitt('Verein');
    $pdf->feldZeile('ZVR-Zahl:', VEREIN_ZVR);
    $pdf->feldZeile('Name:', APP_NAME);
    $pdf->feldZeile('Dachverband:', $k['dachverband']);
    $pdf->feldZeile('Vereinsadresse:', VEREIN_ADRESSE);
    $pdf->feldZeile('Ansprechpartner:in:', trim(($k['verein_ansprechpartner_name'] ?? '') . ' · ' . ($k['verein_ansprechpartner_email'] ?? '') . ' · ' . ($k['verein_ansprechpartner_tel'] ?? ''), ' ·'));

    $pdf->abschnitt('Gemeinde');
    $pdf->feldZeile('Gemeindename:', $k['gemeinde_name']);
    $pdf->feldZeile('Adresse:', $k['gemeinde_adresse']);
    $pdf->feldZeile('Bürgermeister:in:', $k['buergermeister']);
    $pdf->feldZeile('Ansprechpartner:in:', trim(($k['gemeinde_ansprechpartner_name'] ?? '') . ' · ' . ($k['gemeinde_ansprechpartner_email'] ?? '') . ' · ' . ($k['gemeinde_ansprechpartner_tel'] ?? ''), ' ·'));

    $pdf->abschnitt('Dachverband (Bewegungsland Steiermark)');
    $pdf->feldZeile('Name:', $k['dachverband'] . ' Steiermark');
    $pdf->feldZeile('Ansprechpartner:in:', $k['dachverband_ansprechpartner']);
    $pdf->feldZeile('Kooperationsbeginn:', $k['kooperationsbeginn'] ? date('m/Y', strtotime($k['kooperationsbeginn'])) : null);

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(0, 6,
        "Die Kooperationspartner erklären sich durch Unterzeichnung dieser Vereinbarung bereit, Fitness- und Gesundheitssport als ein wesentliches Ziel der Handlungstätigkeit anzusehen und die Bevölkerung in diesem Bereich zu unterstützen.\n\n" .
        "Die Kooperation beruht auf den Kooperationsrichtlinien von Bewegungsland Steiermark und definiert sich durch Wertschätzung und gegenseitige Akzeptanz der Gemeinde, des Vereins und der Dachverbände.",
        0, 'L');

    $pdf->Ln(14);
    $spalteBreite = 58;
    $y = $pdf->GetY();
    foreach (['Für den Verein', 'Für den Dachverband', 'Für die Gemeinde'] as $i => $label) {
        $x = 15 + $i * ($spalteBreite + 4);
        $pdf->SetXY($x, $y);
        $pdf->Cell($spalteBreite, 18, '', 1, 0, 'C');
        $pdf->SetXY($x, $y + 20);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell($spalteBreite, 5, $label, 0, 0, 'C');
    }

    $pdf->Output('Kooperationsvereinbarung_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $k['gemeinde_name']) . '.pdf', 'I');
    exit;
}

// ----------------------------------------------------------------
// Typ: Rückmeldeblatt
// ----------------------------------------------------------------
if ($type === 'rueckmeldeblatt') {
    $periode_id = (int)($_GET['periode_id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT p.*, k.gemeinde_name, k.dachverband, k.verein_ansprechpartner_name, k.verein_ansprechpartner_email, k.verein_ansprechpartner_tel
         FROM kooperations_perioden p JOIN kooperationen k ON k.id = p.kooperation_id
         WHERE p.id = ? AND p.organization_id = ? LIMIT 1'
    );
    $stmt->execute([$periode_id, currentOrgId()]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(404); die('Periode nicht gefunden.'); }

    $stmt = $db->prepare('SELECT * FROM kooperations_angebote WHERE periode_id = ? ORDER BY sortierung ASC, id ASC');
    $stmt->execute([$periode_id]);
    $angebote = $stmt->fetchAll();

    $pdf = neuesPdf('GEMEINDEKOOPERATION RÜCKMELDEBLATT');

    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(0, 6, 'Geplante Bewegungseinheiten für die Periode ' . $p['bezeichnung'] . ' (' . date('d.m.Y', strtotime($p['zeitraum_von'])) . ' – ' . date('d.m.Y', strtotime($p['zeitraum_bis'])) . '), Gemeinde ' . $p['gemeinde_name'] . '.', 0, 'L');

    $pdf->abschnitt('Auszufüllen vom Verein');
    $pdf->feldZeile('Vereinsname:', APP_NAME);
    $pdf->feldZeile('ZVR-Zahl:', VEREIN_ZVR);
    $pdf->feldZeile('Ansprechperson im Verein:', $p['verein_ansprechpartner_name']);
    $pdf->feldZeile('E-Mail / Tel.:', trim(($p['verein_ansprechpartner_email'] ?? '') . ' · ' . ($p['verein_ansprechpartner_tel'] ?? ''), ' ·'));

    if (empty($angebote)) {
        $pdf->Ln(4);
        $pdf->SetFont('dejavusans', 'I', 10);
        $pdf->Cell(0, 6, 'Noch keine Angebote erfasst.', 0, 1, 'L');
    }

    foreach ($angebote as $i => $a) {
        $pdf->Ln(3);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->Cell(0, 7, 'Angebot Nr. ' . ($i + 1) . ': ' . $a['angebotsname'], 0, 1, 'L');
        $pdf->feldZeile('Zielgruppe:', $a['zielgruppe']);
        $pdf->feldZeile('Umsetzungszeitraum:', trim(($a['zeitraum_von'] ?: '') . ' – ' . ($a['zeitraum_bis'] ?: '')), 65);
        $pdf->feldZeile('Geplante Gruppenanzahl:', $a['gruppenanzahl'] !== null ? (string)$a['gruppenanzahl'] : null);
        $pdf->feldZeile('Einheiten pro Gruppe:', $a['einheiten_pro_gruppe'] !== null ? (string)$a['einheiten_pro_gruppe'] : null);
    }

    $pdf->abschnitt('Auszufüllen vom Dachverband (' . $p['dachverband'] . ')');
    $pdf->feldZeile('Zugesagte(s) Angebot(e):', $p['zugesagte_angebote']);
    $pdf->feldZeile('Zugesagte Einheiten:', $p['zugesagte_einheiten']);
    $pdf->feldZeile('Zugesagtes Budget:', $p['zugesagtes_budget'] !== null ? number_format((float)$p['zugesagtes_budget'], 2, ',', '.') . ' €' : null);

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', 'I', 9);
    $pdf->MultiCell(0, 5, 'Wichtig: Eine finanzielle Unterstützung kann nur erfolgen, wenn die geplanten Einheiten vorab durch den Dachverband freigegeben wurden.', 0, 'L');

    $pdf->Output('Rueckmeldeblatt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $p['gemeinde_name']) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $p['bezeichnung']) . '.pdf', 'I');
    exit;
}

// ----------------------------------------------------------------
// Typ: Rechnung
// ----------------------------------------------------------------
if ($type === 'rechnung') {
    $periode_id = (int)($_GET['periode_id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT p.*, k.gemeinde_name
         FROM kooperations_perioden p JOIN kooperationen k ON k.id = p.kooperation_id
         WHERE p.id = ? AND p.organization_id = ? LIMIT 1'
    );
    $stmt->execute([$periode_id, currentOrgId()]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(404); die('Periode nicht gefunden.'); }

    $pdf = neuesPdf('RECHNUNG');

    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(90, 6, APP_NAME . "\nZVR: " . VEREIN_ZVR . "\n" . VEREIN_ADRESSE, 0, 'L');

    $pdf->SetXY(115, 32);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->MultiCell(80, 6, "Bewegungsland Steiermark gGmbH\nSchmiedgasse 34\n8010 Graz", 0, 'L');

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->Cell(95, 7, 'Rechnung Nr.: ' . ($p['rechnung_nr'] ?: '–'), 0, 0, 'L');
    $pdf->Cell(0, 7, 'Ort, Datum: ' . ($p['rechnung_datum'] ? date('d.m.Y', strtotime($p['rechnung_datum'])) : '–'), 0, 1, 'L');

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(0, 6, 'Sehr geehrte Damen und Herren,' . "\n\n" . 'hiermit erlauben wir uns für die Umsetzung der Bewegungseinheiten im Rahmen der Gemeindekooperation mit ' . $p['gemeinde_name'] . ' (Periode ' . $p['bezeichnung'] . ') folgende Summe in Rechnung zu stellen:', 0, 'L');

    $pdf->Ln(4);
    $betrag_4h  = (int)$p['rechnung_trainer_4h'] * 90;
    $betrag_56h = (int)$p['rechnung_trainer_56h'] * 120;

    $pdf->SetFont('dejavusans', '', 10);
    $pdf->Cell(100, 7, 'Trainer:innen ' . (int)$p['rechnung_trainer_4h'] . ' x € 90,00 (4 Stunden)', 0, 0, 'L');
    $pdf->Cell(0, 7, number_format($betrag_4h, 2, ',', '.') . ' €', 0, 1, 'R');
    $pdf->Cell(100, 7, 'Trainer:innen ' . (int)$p['rechnung_trainer_56h'] . ' x € 120,00 (5/6 Stunden)', 0, 0, 'L');
    $pdf->Cell(0, 7, number_format($betrag_56h, 2, ',', '.') . ' €', 0, 1, 'R');

    $pdf->Ln(2);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell(100, 8, 'GESAMT', 0, 0, 'L');
    $pdf->Cell(0, 8, number_format((float)$p['rechnung_betrag'], 2, ',', '.') . ' €', 0, 1, 'R');

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->MultiCell(0, 5, 'Der Verein ist gemäß § 6 Abs. 1 Z. 14 UStG 1994 sowie §§ 34–36 BAO unecht steuerbefreit und daher nicht berechtigt, eine Umsatzsteuer gesondert in Rechnung zu stellen.', 0, 'L');

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->Cell(0, 6, 'Wir bitten um Überweisung des Unterstützungsbetrages in Höhe von ' . number_format((float)$p['rechnung_betrag'], 2, ',', '.') . ' € auf folgendes Konto:', 0, 1, 'L');
    $pdf->Ln(2);
    $pdf->feldZeile('Kontoname:', $p['rechnung_kontoname']);
    $pdf->feldZeile('IBAN:', $p['rechnung_iban']);
    $pdf->feldZeile('BIC:', $p['rechnung_bic']);
    $pdf->feldZeile('Bank:', $p['rechnung_bank']);

    $pdf->Output('Rechnung_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $p['gemeinde_name']) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $p['bezeichnung']) . '.pdf', 'I');
    exit;
}

http_response_code(400);
die('Ungültiger Dokumenttyp.');
