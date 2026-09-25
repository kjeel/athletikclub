<?php
/**
 * Athletikclub Steiermark – Dashboard Hauptseite
 * Rollenbasierte Übersicht für Mitglied / Trainer / Admin
 */
define('ROOT_PATH', dirname(__DIR__));
$page_title = 'Übersicht';
$breadcrumb = 'Dashboard';
require_once ROOT_PATH . '/includes/dashboard-header.php';
require_once ROOT_PATH . '/includes/handlungsbedarf.php';

$db   = getDB();
$user = getCurrentUser();

// Dashboard 2.0: rollen-/rechtebasierter Handlungsbedarf und nächste Termine
$handlungsbedarf = handlungsbedarf($db);
$naechste_termine = meineNaechstenTermine($db);

// ----------------------------------------------------------------
// Daten je nach Rolle laden
// ----------------------------------------------------------------
$stats = [];

try {
    if (isAdmin()) {
        // Admin: Gesamtstatistiken
        $stats['mitglieder']   = (int)$db->query('SELECT COUNT(*) FROM users WHERE rolle = "mitglied"')->fetchColumn();
        $stats['trainer']      = (int)$db->query('SELECT COUNT(*) FROM users WHERE rolle IN ("trainer", "admin")')->fetchColumn();
        $stats['kurse']        = (int)$db->query('SELECT COUNT(*) FROM kurse WHERE status IN ("geplant","aktiv")')->fetchColumn();
        $stats['dokumente']    = (int)$db->query('SELECT COUNT(*) FROM dokumente')->fetchColumn();
        $stats['kontakt']      = (int)$db->query('SELECT COUNT(*) FROM kontakt_anfragen WHERE gelesen = 0')->fetchColumn();

        // Letzte Nutzer
        $latest_users = $db->query(
            'SELECT id, vorname, nachname, email, rolle, created_at FROM users ORDER BY created_at DESC LIMIT 5'
        )->fetchAll();

        // Nächste Kurse
        $next_kurse = $db->query(
            'SELECT k.*, u.vorname, u.nachname FROM kurse k
             LEFT JOIN users u ON k.trainer_id = u.id
             WHERE k.start_datum >= NOW() ORDER BY k.start_datum ASC LIMIT 5'
        )->fetchAll();

    } elseif (isTrainer()) {
        // Trainer: Eigene Kurse + Anmeldungen
        $stmt = $db->prepare('SELECT COUNT(*) FROM kurse WHERE trainer_id = ? AND status IN ("geplant","aktiv")');
        $stmt->execute([$user['id']]);
        $stats['meine_kurse'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM kurs_anmeldungen ka
             JOIN kurse k ON ka.kurs_id = k.id
             WHERE k.trainer_id = ? AND ka.status = "angemeldet"'
        );
        $stmt->execute([$user['id']]);
        $stats['anmeldungen'] = (int)$stmt->fetchColumn();

        $stats['mitglieder'] = (int)$db->query('SELECT COUNT(*) FROM users WHERE rolle = "mitglied" AND aktiv = 1')->fetchColumn();
        $stats['dokumente']  = (int)$db->query('SELECT COUNT(*) FROM dokumente WHERE sichtbar_fuer IN ("trainer","alle","mitglieder")')->fetchColumn();

        $stmt = $db->prepare(
            'SELECT k.*, (SELECT COUNT(*) FROM kurs_anmeldungen WHERE kurs_id = k.id AND status = "angemeldet") AS anmeldungen_count
             FROM kurse k WHERE k.trainer_id = ? AND k.start_datum >= NOW()
             ORDER BY k.start_datum ASC LIMIT 5'
        );
        $stmt->execute([$user['id']]);
        $next_kurse = $stmt->fetchAll();

    } else {
        // Mitglied: Eigene Anmeldungen
        $stmt = $db->prepare('SELECT COUNT(*) FROM kurs_anmeldungen WHERE user_id = ? AND status = "angemeldet"');
        $stmt->execute([$user['id']]);
        $stats['meine_kurse'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM kurs_anmeldungen WHERE user_id = ? AND status = "teilgenommen"');
        $stmt->execute([$user['id']]);
        $stats['absolviert'] = (int)$stmt->fetchColumn();

        $stats['dokumente'] = (int)$db->query('SELECT COUNT(*) FROM dokumente WHERE sichtbar_fuer IN ("alle","mitglieder")')->fetchColumn();

        $stmt = $db->prepare(
            'SELECT k.*, u.vorname, u.nachname FROM kurse k
             JOIN kurs_anmeldungen ka ON ka.kurs_id = k.id
             LEFT JOIN users u ON k.trainer_id = u.id
             WHERE ka.user_id = ? AND ka.status = "angemeldet" AND k.start_datum >= NOW()
             ORDER BY k.start_datum ASC LIMIT 5'
        );
        $stmt->execute([$user['id']]);
        $next_kurse = $stmt->fetchAll();
    }
} catch (Exception $e) {
    // DB noch nicht vorhanden
    $latest_users = [];
    $next_kurse   = [];
}
?>

<!-- Dashboard Header -->
<div class="dashboard-header">
    <h1 class="dashboard-title">
        Guten <?= date('H') < 12 ? 'Morgen' : (date('H') < 18 ? 'Tag' : 'Abend') ?>,
        <?= e($user['vorname']) ?> 👋
    </h1>
    <p class="dashboard-subtitle"><?= ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int)date('w')] ?>, <?= date('j') ?>. <?= ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'][(int)date('n')] ?> <?= date('Y') ?></p>
</div>

<style>
.hb-karte { margin-bottom: 1.5rem; }
.hb-liste { display: flex; flex-direction: column; }
.hb-eintrag { display: grid; grid-template-columns: auto 1fr auto; gap: 0.85rem; align-items: center; padding: 0.75rem 1.25rem; border-bottom: 1px solid var(--border-light); color: var(--text-primary); transition: background 0.15s; }
.hb-eintrag:last-child { border-bottom: none; }
.hb-eintrag:hover { background: var(--bg-muted); }
.hb-punkt { width: 10px; height: 10px; border-radius: 50%; }
.hb-kritisch .hb-punkt { background: #EF4444; box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.15); }
.hb-warnung .hb-punkt { background: #F59E0B; box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.15); }
.hb-info .hb-punkt { background: #3B82F6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12); }
.hb-text { font-size: 0.9rem; }
.hb-bereich { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.hb-anzahl { font-weight: 700; font-size: 1rem; min-width: 2rem; text-align: right; }
.hb-kritisch .hb-anzahl { color: #DC2626; }
.hb-leer { padding: 1.25rem; display: flex; align-items: center; gap: 0.6rem; color: var(--text-secondary); font-size: 0.9rem; }
.hb-termine { display: flex; flex-direction: column; }
.hb-termin { display: grid; grid-template-columns: 3.4rem 1fr; gap: 0.75rem; padding: 0.7rem 1.25rem; border-bottom: 1px solid var(--border-light); color: var(--text-primary); }
.hb-termin:last-child { border-bottom: none; }
.hb-tag { text-align: center; line-height: 1.1; }
.hb-tag strong { display: block; font-size: 1.15rem; }
.hb-tag span { font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); }
.hb-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 1.5rem; align-items: start; margin-bottom: 1.5rem; }
.hb-grid .hb-karte { margin-bottom: 0; }
@media (max-width: 900px) { .hb-grid { grid-template-columns: 1fr; } }
</style>

<div class="<?= $naechste_termine ? 'hb-grid' : '' ?>">
    <!-- Handlungsbedarf -->
    <div class="table-card hb-karte">
        <div class="table-card-header">
            <h2 class="table-card-title">Handlungsbedarf</h2>
            <?php $kritisch = count(array_filter($handlungsbedarf, fn($h) => $h['stufe'] === 'kritisch')); ?>
            <?php if ($kritisch): ?><span class="badge badge-danger"><?= $kritisch ?> dringend</span><?php endif; ?>
        </div>
        <?php if (!$handlungsbedarf): ?>
            <div class="hb-leer"><span class="badge badge-success">✓</span> Alles erledigt – aktuell ist nichts offen.</div>
        <?php else: ?>
            <div class="hb-liste">
                <?php foreach ($handlungsbedarf as $h): ?>
                <a class="hb-eintrag hb-<?= e($h['stufe']) ?>" href="<?= APP_URL . e($h['link']) ?>">
                    <span class="hb-punkt" aria-hidden="true"></span>
                    <span><span class="hb-text"><?= e($h['text']) ?></span><br><span class="hb-bereich"><?= e($h['bereich']) ?></span></span>
                    <span class="hb-anzahl"><?= (int)$h['anzahl'] ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($naechste_termine): ?>
    <!-- Nächste Termine (7 Tage) -->
    <div class="table-card hb-karte">
        <div class="table-card-header">
            <h2 class="table-card-title"><?= isTrainer() ? 'Meine nächsten Einsätze' : 'Meine nächsten Termine' ?></h2>
            <a href="<?= APP_URL ?>/dashboard/kalender.php?ansicht=woche" class="btn btn-ghost-light btn-sm">Kalender</a>
        </div>
        <div class="hb-termine">
            <?php foreach ($naechste_termine as $t): ?>
            <a class="hb-termin" href="<?= APP_URL ?>/dashboard/<?= $t['art'] === 'einsatz' ? 'einheit.php?id=' . (int)$t['id'] : 'kalender.php?ansicht=tag&datum=' . substr($t['start'], 0, 10) ?>">
                <span class="hb-tag"><span><?= ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', strtotime($t['start']))] ?></span><strong><?= date('d.m.', strtotime($t['start'])) ?></strong></span>
                <span><strong><?= e($t['titel']) ?></strong><br><span class="hb-bereich" style="text-transform: none; letter-spacing: 0;"><?= date('H:i', strtotime($t['start'])) ?>–<?= date('H:i', strtotime($t['ende'])) ?><?= $t['ort'] ? ' · ' . e($t['ort']) : '' ?></span></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Trainer-Onboarding (laufende Vorgänge mit Fortschritt)
$onboarding_laufend = [];
if (darf('onboarding.anzeigen')) {
    try {
        require_once ROOT_PATH . '/includes/onboarding.php';
        $stmt = $db->prepare("SELECT * FROM onboarding WHERE organization_id = ? AND status NOT IN ('aktiv','abgelehnt','zurueckgezogen') ORDER BY created_at LIMIT 6");
        $stmt->execute([currentOrgId()]);
        foreach ($stmt->fetchAll() as $o) $onboarding_laufend[] = $o + ['fp' => onboardingFortschritt($db, (int)$o['id'])];
    } catch (Exception $e) {}
}
?>
<?php if ($onboarding_laufend): ?>
<div class="table-card hb-karte">
    <div class="table-card-header"><h2 class="table-card-title">Trainer-Onboarding</h2><a href="<?= APP_URL ?>/dashboard/admin/onboarding.php" class="btn btn-ghost-light btn-sm">Alle</a></div>
    <div class="hb-liste">
        <?php foreach ($onboarding_laufend as $o): ?>
        <a class="hb-eintrag" href="<?= APP_URL ?>/dashboard/admin/onboarding.php?id=<?= (int)$o['id'] ?>" style="grid-template-columns: minmax(0, 1fr) auto;">
            <span><span class="hb-text"><strong><?= e($o['vorname'] . ' ' . $o['nachname']) ?></strong> · <?= e(ONBOARDING_STATUS[$o['status']]['label']) ?></span>
                <span style="display: block; height: 8px; border-radius: 4px; background: var(--bg-muted); overflow: hidden; margin: 0.35rem 0 0.2rem;"><span style="display: block; height: 100%; width: <?= (int)$o['fp']['prozent'] ?>%; background: var(--gold-accent);"></span></span>
                <?php if ($o['fp']['fehlend']): ?><span class="hb-bereich" style="text-transform: none; letter-spacing: 0;">Fehlend: <?= e(implode(', ', array_slice($o['fp']['fehlend'], 0, 3))) ?><?= count($o['fp']['fehlend']) > 3 ? ' …' : '' ?></span><?php endif; ?></span>
            <span class="hb-anzahl"><?= (int)$o['fp']['prozent'] ?> %</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="kpi-grid">
    <?php if (isAdmin()): ?>
        <div class="kpi-card" style="--kpi-color: #1F3556;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="kpi-value"><?= $stats['mitglieder'] ?? 0 ?></div>
            <div class="kpi-label">Mitglieder</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #C6A135;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg></div>
            <div class="kpi-value"><?= $stats['trainer'] ?? 0 ?></div>
            <div class="kpi-label">Trainer / Admins</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #00A896;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="kpi-value"><?= $stats['kurse'] ?? 0 ?></div>
            <div class="kpi-label">Aktive Kurse</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #7C3AED;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="kpi-value"><?= $stats['dokumente'] ?? 0 ?></div>
            <div class="kpi-label">Dokumente</div>
        </div>
        <?php if ($stats['kontakt'] > 0): ?>
        <div class="kpi-card" style="--kpi-color: #EF4444;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
            <div class="kpi-value"><?= $stats['kontakt'] ?></div>
            <div class="kpi-label">Neue Anfragen</div>
        </div>
        <?php endif; ?>

    <?php elseif (isTrainer()): ?>
        <div class="kpi-card" style="--kpi-color: #1F3556;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="kpi-value"><?= $stats['meine_kurse'] ?? 0 ?></div>
            <div class="kpi-label">Meine Kurse</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #C6A135;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="kpi-value"><?= $stats['anmeldungen'] ?? 0 ?></div>
            <div class="kpi-label">Anmeldungen</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #4EBA6F;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="kpi-value"><?= $stats['mitglieder'] ?? 0 ?></div>
            <div class="kpi-label">Gesamtmitglieder</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #7C3AED;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="kpi-value"><?= $stats['dokumente'] ?? 0 ?></div>
            <div class="kpi-label">Dokumente</div>
        </div>

    <?php else: ?>
        <div class="kpi-card" style="--kpi-color: #1F3556;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="kpi-value"><?= $stats['meine_kurse'] ?? 0 ?></div>
            <div class="kpi-label">Meine Kurse</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #C6A135;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
            <div class="kpi-value"><?= $stats['absolviert'] ?? 0 ?></div>
            <div class="kpi-label">Absolviert</div>
        </div>
        <div class="kpi-card" style="--kpi-color: #7C3AED;">
            <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="kpi-value"><?= $stats['dokumente'] ?? 0 ?></div>
            <div class="kpi-label">Dokumente</div>
        </div>
    <?php endif; ?>
</div>

<!-- Nächste Kurse + (Admin: Letzte User) -->
<div class="<?= isAdmin() ? 'grid-2' : '' ?>" style="display: grid; gap: 1.5rem;">

    <!-- Nächste Kurse -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">
                <?= isTrainer() && !isAdmin() ? 'Meine nächsten Kurse' : 'Nächste Kurse' ?>
            </h2>
            <a href="<?= APP_URL ?>/dashboard/kurse.php" class="btn btn-ghost-light btn-sm">Alle ansehen</a>
        </div>
        <?php if (empty($next_kurse)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <h3>Keine Kurse</h3>
                <p><?= isTrainer() ? 'Du hast noch keine Kurse erstellt.' : 'Du bist noch für keinen Kurs angemeldet.' ?></p>
                <a href="<?= isTrainer() ? APP_URL . '/dashboard/kurs-erstellen.php' : APP_URL . '/dashboard/kurse.php' ?>" class="btn btn-navy btn-sm">
                    <?= isTrainer() ? 'Kurs erstellen' : 'Kurse entdecken' ?>
                </a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kurs</th>
                            <th>Datum</th>
                            <?php if (isTrainer()): ?><th>Anmeldungen</th><?php endif; ?>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($next_kurse as $kurs): ?>
                        <tr>
                            <td>
                                <span class="text-primary">
                                    <a href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $kurs['id'] ?>">
                                        <?= e($kurs['titel']) ?>
                                    </a>
                                </span>
                                <?php if ($kurs['sportart']): ?>
                                    <br><span class="badge badge-gold" style="margin-top: 4px;"><?= e($kurs['sportart']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('d.m.Y H:i', strtotime($kurs['start_datum'])) ?></td>
                            <?php if (isTrainer()): ?>
                                <td><?= $kurs['anmeldungen_count'] ?? 'k. A.' ?><?= $kurs['max_teilnehmer'] ? ' / ' . $kurs['max_teilnehmer'] : '' ?></td>
                            <?php endif; ?>
                            <td>
                                <?php
                                $status_map = [
                                    'geplant'      => ['label' => 'Geplant',      'class' => 'badge-info'],
                                    'aktiv'        => ['label' => 'Aktiv',        'class' => 'badge-success'],
                                    'abgesagt'     => ['label' => 'Abgesagt',     'class' => 'badge-danger'],
                                    'abgeschlossen'=> ['label' => 'Abgeschlossen','class' => 'badge-gray'],
                                ];
                                $s = $status_map[$kurs['status']] ?? ['label' => $kurs['status'], 'class' => 'badge-gray'];
                                echo '<span class="badge ' . $s['class'] . '">' . e($s['label']) . '</span>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if (isAdmin() && !empty($latest_users)): ?>
    <!-- Neueste Nutzer (Admin) -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Neue Mitglieder</h2>
            <a href="<?= APP_URL ?>/dashboard/admin/nutzerverwaltung.php" class="btn btn-ghost-light btn-sm">Alle ansehen</a>
        </div>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Rolle</th>
                        <th>Registriert</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($latest_users as $u): ?>
                    <tr>
                        <td class="text-primary"><?= e($u['vorname'] . ' ' . $u['nachname']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><span class="role-badge role-<?= e($u['rolle']) ?>"><?= ucfirst(e($u['rolle'])) ?></span></td>
                        <td><?= date('d.m.Y', strtotime($u['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Schnellzugriff Buttons -->
<div style="margin-top: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
    <a href="<?= APP_URL ?>/dashboard/kurse.php" class="btn btn-navy btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Kurse ansehen
    </a>
    <a href="<?= APP_URL ?>/dashboard/dokumente.php" class="btn btn-ghost-light btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Dokumente
    </a>
    <a href="<?= APP_URL ?>/dashboard/profil.php" class="btn btn-ghost-light btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profil bearbeiten
    </a>
    <?php if (isTrainer()): ?>
    <a href="<?= APP_URL ?>/dashboard/kurs-erstellen.php" class="btn btn-primary btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Kurs erstellen
    </a>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
