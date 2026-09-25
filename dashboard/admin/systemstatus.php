<?php
/**
 * Athletikclub Steiermark – Systemstatus
 * Datenbank, Migrationen, E-Mail, Cron/Automatisierungen, Speicher, PHP-Erweiterungen und die
 * letzten Fehler. Zugangsdaten werden nie angezeigt; Fehlertexte werden vor der Anzeige maskiert.
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/plattform.php';
require_once ROOT_PATH . '/includes/einstellungen.php';
require_once ROOT_PATH . '/includes/kommunikation.php';

requireDarf('system.anzeigen');

$db  = getDB();
$org = currentOrgId();
$treiber = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
$abfrage = function (string $sql, array $p = []) use ($db) {
    try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Exception $e) { return null; }
};
/** Mögliche Zugangsdaten aus Fehlertexten entfernen. */
$maskieren = fn(?string $s) => preg_replace(['/(pass(word)?|pwd|secret|key|token)\s*[=:]\s*\S+/i', '/[\w.+-]+@[\w-]+\.[\w.]+/'], ['$1=***', '***@***'], (string)$s);
$alter = function (?string $zeit): string {
    if (!$zeit) return 'nie';
    $s = time() - strtotime($zeit);
    if ($s < 90) return 'vor ' . max(0, $s) . ' Sek.';
    if ($s < 5400) return 'vor ' . round($s / 60) . ' Min.';
    if ($s < 172800) return 'vor ' . round($s / 3600) . ' Std.';
    return 'vor ' . round($s / 86400) . ' Tagen';
};
$groesse = function ($bytes): string {
    if ($bytes === null || $bytes === false) return '–';
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $e) if ($bytes < 1024 ** ($i + 1) || $e === 'TB') return number_format($bytes / 1024 ** $i, $i ? 1 : 0, ',', '.') . ' ' . $e;
};

// Datenbank
$db_version = '';
try { $db_version = (string)$db->getAttribute(PDO::ATTR_SERVER_VERSION); } catch (Exception $e) {}
$db_groesse = null;
if ($treiber === 'mysql') {
    $r = $abfrage('SELECT SUM(data_length + index_length) AS b FROM information_schema.tables WHERE table_schema = DATABASE()');
    $db_groesse = $r ? (int)$r[0]['b'] : null;
}
// Zeitabgleich: NOW() der Datenbank vs. PHP (Europe/Vienna) – Abweichung verfälscht Fristen und Rate-Limits
$db_zeit = null;
try { $db_zeit = (string)$db->query($treiber === 'mysql' ? 'SELECT NOW()' : "SELECT datetime('now', 'localtime')")->fetchColumn(); } catch (Exception $e) {}
$zeit_abweichung = $db_zeit ? abs(strtotime($db_zeit) - time()) : null;
$dateien = array_map('basename', glob(ROOT_PATH . '/sql/migrations/*.sql') ?: []);
sort($dateien);
$eingespielt = array_column($abfrage('SELECT filename, executed_at FROM migrations ORDER BY id') ?? [], 'executed_at', 'filename');
$fehlend = array_values(array_filter($dateien, fn($f) => !isset($eingespielt[$f]) && !isset($eingespielt[pathinfo($f, PATHINFO_FILENAME)])));

// Automatisierung & Cron
$letzter_lauf = systemStatus($db, 'automation_letzter_lauf_' . $org);
$letzter_cron = systemStatus($db, 'cron_letzter_aufruf_' . $org);
$automationen_fehler = $abfrage("SELECT code, letzter_fehler, letzte_ausfuehrung FROM automationen WHERE organization_id = ? AND letzter_status = 'fehler' ORDER BY letzte_ausfuehrung DESC", [$org]) ?? [];
$auto_24h = $abfrage("SELECT ergebnis, COUNT(*) AS n FROM automation_log WHERE organization_id = ? AND created_at >= ? GROUP BY ergebnis", [$org, date('Y-m-d H:i:s', strtotime('-24 hours'))]);
$auto_24h = $auto_24h ? array_column($auto_24h, 'n', 'ergebnis') : [];

// E-Mail
$mail_ok = mailKonfiguriert();
$mail_7t = $abfrage("SELECT status, COUNT(*) AS n FROM mail_log WHERE organization_id = ? AND created_at >= ? GROUP BY status", [$org, date('Y-m-d H:i:s', strtotime('-7 days'))]);
$mail_7t = $mail_7t ? array_column($mail_7t, 'n', 'status') : [];
$letzte_mail = $abfrage("SELECT created_at FROM mail_log WHERE organization_id = ? AND status = 'gesendet' ORDER BY id DESC LIMIT 1", [$org]);

// Speicher
$upload = defined('UPLOAD_PATH') ? UPLOAD_PATH : ROOT_PATH . '/uploads';
$frei = @disk_free_space($upload);
$gesamt = @disk_total_space($upload);
$upload_bytes = 0;
$upload_dateien = 0;
if (is_dir($upload)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) { $upload_bytes += $f->getSize(); $upload_dateien++; } if ($upload_dateien > 50000) break; }
}
$beschreibbar = is_dir($upload) && is_writable($upload);

// Letzte Fehler (Automationen, E-Mails, PHP-Fehlerprotokoll)
$fehlerliste = [];
foreach ($abfrage("SELECT created_at, automation, fehler FROM automation_log WHERE organization_id = ? AND ergebnis = 'fehler' ORDER BY id DESC LIMIT 10", [$org]) ?? [] as $r)
    $fehlerliste[] = [$r['created_at'], 'Automation ' . $r['automation'], $r['fehler']];
foreach ($abfrage("SELECT created_at, betreff, fehler FROM mail_log WHERE organization_id = ? AND status = 'fehler' ORDER BY id DESC LIMIT 10", [$org]) ?? [] as $r)
    $fehlerliste[] = [$r['created_at'], 'E-Mail „' . $r['betreff'] . '“', $r['fehler']];
usort($fehlerliste, fn($a, $b) => strcmp($b[0], $a[0]));
$fehlerliste = array_slice($fehlerliste, 0, 12);
$php_log = ini_get('error_log');
$php_zeilen = [];
if ($php_log && is_file($php_log) && is_readable($php_log) && filesize($php_log) > 0) {
    $fh = fopen($php_log, 'r');
    fseek($fh, max(0, filesize($php_log) - 8192));
    $php_zeilen = array_slice(array_filter(explode("\n", (string)stream_get_contents($fh))), -8);
    fclose($fh);
}

$erweiterungen = ['pdo_mysql' => 'Datenbank', 'mbstring' => 'Umlaute/Texte', 'bcmath' => 'Geldbeträge', 'gd' => 'Bildverarbeitung', 'fileinfo' => 'Upload-Prüfung', 'zlib' => 'Excel-Export', 'openssl' => 'Verschlüsselung'];
if (!class_exists('TCPDF') && is_file(ROOT_PATH . '/vendor/autoload.php')) require_once ROOT_PATH . '/vendor/autoload.php';
$tcpdf = class_exists('TCPDF');

$ampel = fn(bool $ok, bool $warn = false) => '<span class="sy-ampel ' . ($ok ? 'sy-ok' : ($warn ? 'sy-warn' : 'sy-fehler')) . '" aria-hidden="true"></span>';

$page_title = 'Systemstatus';
$breadcrumb = 'Systemstatus';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>
<style>
.sy-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); gap: 1.25rem; align-items: start; }
.sy-liste { list-style: none; margin: 0; padding: 0.4rem 1.25rem 1rem; }
.sy-liste li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.5rem 0; border-bottom: 1px solid var(--border-light); font-size: 0.88rem; }
.sy-liste li:last-child { border-bottom: 0; }
.sy-liste li > span:first-child { color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; }
.sy-liste li > span:last-child { text-align: right; font-weight: 600; }
.sy-ampel { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.sy-ok { background: #22C55E; } .sy-warn { background: #F59E0B; } .sy-fehler { background: #EF4444; }
.sy-log { font-family: ui-monospace, Consolas, monospace; font-size: 0.75rem; white-space: pre-wrap; word-break: break-all; background: var(--bg-muted); padding: 0.75rem 1rem; margin: 0 1.25rem 1rem; border-radius: 8px; max-height: 240px; overflow: auto; }
.sy-hinweis { font-size: 0.8rem; color: var(--text-muted); padding: 0 1.25rem 1rem; margin: 0; }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Systemstatus</h1>
    <p class="dashboard-subtitle">Stand <?= date('d.m.Y H:i:s') ?> · PHP <?= e(PHP_VERSION) ?></p>
</div>

<?php if ($fehlend): ?>
    <div class="alert alert-warning">Nicht eingespielte Migrationen: <strong><?= e(implode(', ', $fehlend)) ?></strong>. Bitte in phpMyAdmin ausführen – neue Funktionen sind bis dahin eingeschränkt.</div>
<?php endif; ?>

<div class="sy-grid">
    <section class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Datenbank</h2></div>
        <ul class="sy-liste">
            <li><span><?= $ampel(true) ?>Verbindung</span><span>OK (<?= e($treiber) ?> <?= e($db_version) ?>)</span></li>
            <li><span>Größe</span><span><?= $groesse($db_groesse) ?></span></li>
            <li><span><?= $ampel($zeit_abweichung !== null && $zeit_abweichung < 120, $zeit_abweichung === null) ?>Uhrzeit DB / PHP</span><span><?= $db_zeit ? date('H:i', strtotime($db_zeit)) . ' / ' . date('H:i') . ' (' . e(date_default_timezone_get()) . ')' : 'unbekannt' ?></span></li>
            <li><span><?= $ampel(!$fehlend, true) ?>Migrationen</span><span><?= count($dateien) - count($fehlend) ?> / <?= count($dateien) ?> eingespielt</span></li>
            <?php if ($eingespielt): ?><li><span>Letzte Migration</span><span><?= e((string)array_key_last($eingespielt)) ?></span></li><?php endif; ?>
        </ul>
    </section>

    <section class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Automatisierung &amp; Cron</h2></div>
        <ul class="sy-liste">
            <li><span><?= $ampel($letzter_lauf && strtotime($letzter_lauf) > time() - 3600, (bool)$letzter_lauf) ?>Letzter Automatisierungslauf</span><span><?= e($alter($letzter_lauf)) ?></span></li>
            <li><span><?= $ampel((bool)$letzter_cron && strtotime($letzter_cron) > time() - 3600, true) ?>Letzter Cron-Aufruf</span><span><?= e($alter($letzter_cron)) ?></span></li>
            <li><span>Letzte 24 Std.</span><span><?= (int)($auto_24h['ok'] ?? 0) ?> ausgeführt · <?= (int)($auto_24h['fehler'] ?? 0) ?> Fehler</span></li>
            <li><span><?= $ampel(!$automationen_fehler) ?>Automationen mit Fehler</span><span><?= count($automationen_fehler) ?></span></li>
        </ul>
        <?php if (!$letzter_cron): ?><p class="sy-hinweis">Kein Cron eingerichtet – Automationen laufen beim Seitenaufruf (höchstens alle 10 Minuten). Für pünktliche Erinnerungen den Cron-Aufruf auf der Seite „Automatisierungen“ einrichten.</p><?php endif; ?>
        <?php foreach ($automationen_fehler as $a): ?><p class="sy-hinweis"><strong><?= e($a['code']) ?>:</strong> <?= e($maskieren($a['letzter_fehler'])) ?></p><?php endforeach; ?>
    </section>

    <section class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">E-Mail</h2></div>
        <ul class="sy-liste">
            <li><span><?= $ampel($mail_ok) ?>Versand</span><span><?= $mail_ok ? 'aktiv' : 'inaktiv / nicht konfiguriert' ?></span></li>
            <li><span>Absender</span><span><?= e(einstellung('mail_absender', MAIL_FROM)) ?></span></li>
            <li><span>Letzte 7 Tage</span><span><?= (int)($mail_7t['gesendet'] ?? 0) ?> gesendet · <?= (int)($mail_7t['fehler'] ?? 0) ?> Fehler</span></li>
            <li><span>Zuletzt gesendet</span><span><?= e($alter($letzte_mail[0]['created_at'] ?? null)) ?></span></li>
        </ul>
    </section>

    <section class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">Speicher</h2></div>
        <ul class="sy-liste">
            <li><span><?= $ampel($beschreibbar) ?>Upload-Verzeichnis</span><span><?= $beschreibbar ? 'beschreibbar' : 'nicht beschreibbar' ?></span></li>
            <li><span>Hochgeladene Dateien</span><span><?= number_format($upload_dateien, 0, ',', '.') ?> · <?= $groesse($upload_bytes) ?></span></li>
            <li><span><?= $ampel($frei === false || $gesamt === false || $frei / max(1, $gesamt) > 0.1, true) ?>Freier Speicher</span><span><?= $groesse($frei) ?><?= $gesamt ? ' von ' . $groesse($gesamt) : '' ?></span></li>
        </ul>
    </section>

    <section class="table-card">
        <div class="table-card-header"><h2 class="table-card-title">PHP-Umgebung</h2></div>
        <ul class="sy-liste">
            <?php foreach ($erweiterungen as $ext => $zweck): $da = extension_loaded($ext); ?>
                <li><span><?= $ampel($da, !in_array($ext, ['pdo_mysql', 'mbstring', 'bcmath'], true)) ?><?= e($ext) ?></span><span><?= $da ? 'vorhanden' : 'fehlt' ?> · <?= e($zweck) ?></span></li>
            <?php endforeach; ?>
            <li><span><?= $ampel($tcpdf) ?>TCPDF (PDF-Erzeugung)</span><span><?= $tcpdf ? 'vorhanden' : 'fehlt' ?></span></li>
            <li><span>Speicherlimit / Upload</span><span><?= e(ini_get('memory_limit')) ?> / <?= e(ini_get('upload_max_filesize')) ?></span></li>
        </ul>
    </section>
</div>

<section class="table-card" style="margin-top: 1.25rem;">
    <div class="table-card-header"><h2 class="table-card-title">Letzte Fehler</h2></div>
    <?php if (!$fehlerliste && !$php_zeilen): ?><p class="sy-hinweis" style="padding-top: 1rem;">Keine Fehler protokolliert.</p><?php endif; ?>
    <?php if ($fehlerliste): ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Zeitpunkt</th><th>Quelle</th><th>Meldung</th></tr></thead>
        <tbody><?php foreach ($fehlerliste as [$zeit, $quelle, $text]): ?>
            <tr><td style="white-space: nowrap;"><?= date('d.m.Y H:i', strtotime($zeit)) ?></td><td><?= e($quelle) ?></td><td><?= e($maskieren($text)) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($php_zeilen): ?>
        <p class="sy-hinweis" style="padding-top: 1rem;">PHP-Fehlerprotokoll (letzte Einträge, Zugangsdaten maskiert):</p>
        <div class="sy-log"><?= e($maskieren(implode("\n", $php_zeilen))) ?></div>
    <?php endif; ?>
</section>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
