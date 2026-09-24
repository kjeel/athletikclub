<?php
/**
 * Athletikclub Steiermark – TBE-Gesamtkonzept (Tägliche Bewegungseinheit)
 * Gemeinsame Labels und Berechnungen für Übersicht, Detailseite und Bericht.
 */

const TBE_STATUS = [
    'entwurf'       => ['label' => 'Entwurf',          'class' => 'badge-gray'],
    'eingereicht'   => ['label' => 'Eingereicht',      'class' => 'badge-warning'],
    'bewilligt'     => ['label' => 'Budget bewilligt', 'class' => 'badge-success'],
    'abgeschlossen' => ['label' => 'Abgeschlossen',    'class' => 'badge-navy'],
];

const TBE_EINRICHTUNGSTYPEN = [
    'kindergarten' => 'Kindergarten',
    'volksschule'  => 'Volksschule',
    'mittelschule' => 'Mittelschule',
    'sonstige'     => 'Sonstige',
];

/** Geplante Einheiten gesamt: Gruppen × Einheiten pro Woche × Wochen. */
function tbeEinheiten(array $projekt): float
{
    return (int)$projekt['anzahl_gruppen'] * (float)$projekt['einheiten_pro_woche'] * (int)$projekt['anzahl_wochen'];
}

/** Geplante Trainer:innen-Stunden gesamt (Einheiten × Dauer). */
function tbeStunden(array $projekt): float
{
    return tbeEinheiten($projekt) * (int)$projekt['dauer_minuten'] / 60;
}

/** Geschätzte Kosten eines Projekts (Stunden × Stundensatz), null ohne Stundensatz. */
function tbeKosten(array $projekt, $stundensatz): ?string
{
    if ($stundensatz === null || $stundensatz === '') return null;
    return moneyRound(round(tbeStunden($projekt) * (float)$stundensatz, 2));
}

/** Zahl im deutschen Format ohne überflüssige Nachkommastellen (z.B. 12 / 12,5 / 0,75). */
function tbeZahl(float $wert): string
{
    return rtrim(rtrim(number_format($wert, 2, ',', '.'), '0'), ',');
}
