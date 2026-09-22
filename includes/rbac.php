<?php
/**
 * Athletikclub Steiermark – RBAC-Helper (granulare Permissions)
 *
 * Ergänzt (ersetzt NICHT) die bestehenden Rollen-Helfer in auth.php
 * (isAdmin()/isTrainer()/requireAdmin()/requireTrainer()), die weiterhin
 * die primäre Zugriffskontrolle für den heutigen Funktionsumfang sind.
 *
 * can()/requirePermission() sind die Grundlage für granularere Prüfungen,
 * sobald Rollen wie ADMINISTRATION oder MANAGEMENT eigene, engere Rechte
 * als ORGANIZATION_ADMIN bekommen. Bis dahin schadet paralleler Einsatz
 * nicht (reine Zusatzprüfung), ersetzt aber bewusst noch keine bestehende
 * Guard-Funktion, um das laufende System nicht zu gefährden.
 */

/**
 * Prüft, ob der eingeloggte Nutzer in seiner aktuellen Organisation
 * über eine bestimmte Permission verfügt (über beliebig viele Rollen).
 */
function can(string $permissionCode): bool
{
    if (!isLoggedIn()) return false;

    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ? AND ur.organization_id = ? AND p.code = ?'
        );
        $stmt->execute([getCurrentUserId(), currentOrgId(), $permissionCode]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        // RBAC-Tabellen evtl. noch nicht migriert – konservativ verweigern.
        return false;
    }
}

/** Bricht mit 403 ab, wenn die Permission fehlt. */
function requirePermission(string $permissionCode): void
{
    requireLogin();
    if (!can($permissionCode)) {
        http_response_code(403);
        die('Keine Berechtigung.');
    }
}
