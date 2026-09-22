<?php
/**
 * Athletikclub Steiermark – Multi-Tenancy-Helper
 *
 * Grundlage für die spätere Mandantenfähigkeit. Aktuell existiert genau
 * eine Organisation (organizations.id = 1); jede Session trägt trotzdem
 * schon eine organization_id, damit neue Abfragen von Anfang an
 * org-gescoped geschrieben werden und beim Aktivieren weiterer
 * Organisationen keine stille Datenvermischung entsteht.
 */

/**
 * Gibt die organization_id des eingeloggten Nutzers zurück.
 * Fällt auf Org 1 zurück, falls (z.B. vor Login) keine Session existiert –
 * so bleibt der öffentliche Seitenbereich (noch einzige Organisation) nutzbar.
 */
function currentOrgId(): int
{
    return (int)($_SESSION['organization_id'] ?? 1);
}
