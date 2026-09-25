<?php
/**
 * Athletikclub Steiermark – sichere PDF-Uploads
 * Der vom Browser gemeldete MIME-Typ ist fälschbar; geprüft wird daher serverseitig
 * die Dateisignatur (%PDF-) und der per finfo ermittelte Typ. Gespeichert wird immer
 * unter einem zufälligen Namen mit fester Endung .pdf (nie mit der Original-Endung).
 */

/** Fehlermeldung oder null, wenn die hochgeladene Datei ein gültiges PDF ist. */
function pdfUploadFehler(array $datei): ?string
{
    if (($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($datei['tmp_name'] ?? '')) return 'Bitte wähle eine PDF-Datei aus.';
    if ((int)$datei['size'] > MAX_PDF_SIZE) return 'Datei zu groß (max. 10 MB).';
    $kopf = (string)file_get_contents($datei['tmp_name'], false, null, 0, 5);
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $datei['tmp_name']) : 'application/pdf';
    if ($kopf !== '%PDF-' || !in_array($mime, ['application/pdf', 'application/x-pdf'], true)) return 'Nur echte PDF-Dateien sind erlaubt.';
    return null;
}

/** Zufälliger, nicht erratbarer Speichername (Datum + 12 Hex-Zeichen). */
function pdfSpeichername(): string
{
    return date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.pdf';
}
