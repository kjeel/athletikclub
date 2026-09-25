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

/**
 * Bild (JPEG/PNG/WebP, max. 5 MB) prüfen und – wenn GD vorhanden – neu kodiert und auf
 * max. 1600 px verkleinert speichern (entfernt Metadaten und eingeschleuste Inhalte).
 * Liefert ['datei' => Name relativ zu IMG_PATH] oder ['fehler' => …].
 */
function bildUpload(array $datei, string $ordner = 'kurse'): array
{
    if (($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($datei['tmp_name'] ?? '')) return ['fehler' => 'Bitte ein Bild auswählen.'];
    if ((int)$datei['size'] > 5 * 1024 * 1024) return ['fehler' => 'Das Bild ist zu groß (max. 5 MB).'];
    $info = @getimagesize($datei['tmp_name']);
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $datei['tmp_name']) : ($info['mime'] ?? '');
    $erlaubt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($erlaubt[$mime]) || $info['mime'] !== $mime) return ['fehler' => 'Nur JPG-, PNG- oder WebP-Bilder sind erlaubt.'];
    $ziel_ordner = IMG_PATH . '/' . preg_replace('/[^a-z0-9_-]/', '', $ordner);
    if (!is_dir($ziel_ordner)) mkdir($ziel_ordner, 0755, true);
    $basis = date('Ymd_His') . '_' . bin2hex(random_bytes(6));

    if (function_exists('imagecreatefromstring')) {
        $bild = @imagecreatefromstring((string)file_get_contents($datei['tmp_name']));
        if (!$bild) return ['fehler' => 'Das Bild konnte nicht gelesen werden.'];
        [$b, $h] = [imagesx($bild), imagesy($bild)];
        $faktor = min(1, 1600 / max($b, $h));
        if ($faktor < 1) {
            $neu = imagecreatetruecolor((int)round($b * $faktor), (int)round($h * $faktor));
            imagealphablending($neu, false);
            imagesavealpha($neu, true);
            imagecopyresampled($neu, $bild, 0, 0, 0, 0, imagesx($neu), imagesy($neu), $b, $h);
            imagedestroy($bild);
            $bild = $neu;
        }
        $png = $mime === 'image/png';
        $name = $basis . ($png ? '.png' : '.jpg');
        $ok = $png ? imagepng($bild, $ziel_ordner . '/' . $name, 7) : imagejpeg($bild, $ziel_ordner . '/' . $name, 85);
        imagedestroy($bild);
        if (!$ok) return ['fehler' => 'Das Bild konnte nicht gespeichert werden.'];
    } else {
        $name = $basis . '.' . $erlaubt[$mime];
        if (!move_uploaded_file($datei['tmp_name'], $ziel_ordner . '/' . $name)) return ['fehler' => 'Das Bild konnte nicht gespeichert werden.'];
    }
    return ['datei' => $ordner . '/' . $name];
}

/** Zufälliger, nicht erratbarer Speichername (Datum + 12 Hex-Zeichen). */
function pdfSpeichername(): string
{
    return date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.pdf';
}
