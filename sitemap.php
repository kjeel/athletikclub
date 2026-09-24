<?php
/**
 * Athletikclub Steiermark – XML-Sitemap für Suchmaschinen
 * Aufruf über /sitemap.xml (Rewrite in .htaccess). Neue öffentliche
 * Seiten in $pages ergänzen; lastmod kommt aus dem Änderungsdatum der Datei.
 */
define('ROOT_PATH', __DIR__);
require_once ROOT_PATH . '/config/config.php';

// Pfad => [Priorität, Änderungshäufigkeit]
$pages = [
    '/'                           => ['1.0', 'weekly'],
    '/pages/mitglied-werden.php'  => ['0.9', 'monthly'],
    '/pages/trainer-werden.php'   => ['0.8', 'monthly'],
    '/pages/kontakt.php'          => ['0.8', 'yearly'],
    '/pages/team.php'             => ['0.7', 'monthly'],
    '/pages/vision.php'           => ['0.6', 'yearly'],
    '/pages/mission.php'          => ['0.6', 'yearly'],
    '/pages/leitbild.php'         => ['0.6', 'yearly'],
    '/pages/partner.php'          => ['0.6', 'monthly'],
    '/pages/impressum.php'        => ['0.2', 'yearly'],
    '/pages/datenschutz.php'      => ['0.2', 'yearly'],
];

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $path => [$priority, $changefreq]) {
    $file = ROOT_PATH . ($path === '/' ? '/index.php' : $path);
    if (!is_file($file)) continue;
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars(SITE_URL . $path, ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . date('Y-m-d', filemtime($file)) . "</lastmod>\n";
    echo "    <changefreq>$changefreq</changefreq>\n";
    echo "    <priority>$priority</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
