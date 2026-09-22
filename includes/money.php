<?php
/**
 * Athletikclub Steiermark – Geldbeträge (Decimal-sicher via BCMath)
 *
 * Alle Geld-Berechnungen (Provisionen, Summen) laufen über diese
 * Funktionen statt über native PHP-Floats, um Rundungsfehler bei
 * Centbeträgen zu vermeiden. Beträge werden als Strings mit 2
 * Nachkommastellen übergeben/zurückgegeben (kompatibel mit
 * MySQL DECIMAL(10,2)-Spalten).
 */

const MONEY_SCALE = 2;

/** Rundet/normalisiert einen Betrag auf 2 Nachkommastellen (String). */
function moneyRound(string|float|int $amount): string
{
    return bcadd((string)$amount, '0', MONEY_SCALE);
}

/** amount * percent / 100, Decimal-sicher. */
function moneyPercent(string|float|int $amount, string|float|int $percent): string
{
    $product = bcmul((string)$amount, (string)$percent, MONEY_SCALE + 4);
    return bcdiv($product, '100', MONEY_SCALE);
}

/** Summiert eine Liste von Beträgen Decimal-sicher. */
function moneySum(array $amounts): string
{
    $sum = '0.00';
    foreach ($amounts as $a) {
        $sum = bcadd($sum, (string)$a, MONEY_SCALE);
    }
    return $sum;
}

/** Formatiert einen Betrag für die Anzeige (z.B. "1.234,50 €"). */
function moneyFormat(string|float|int $amount): string
{
    return number_format((float)$amount, 2, ',', '.') . ' €';
}
