<?php
/**
 * Athletikclub Steiermark – Admin: Basisförderung SPORTUNION Steiermark
 * (Förderansuchen je Kalenderjahr + Förderkatalog)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/money.php';
require_once ROOT_PATH . '/includes/basisfoerderung.php';

requireAdmin();

$db     = getDB();
$org_id = currentOrgId();
$errors = [];

// Vorschlag Förderjahr: bis zur Frist 31.10. das laufende Jahr, danach das nächste
$vorschlag_jahr = (int)date('Y') + (date('m-d') > '10-31' ? 1 : 0);

// ----------------------------------------------------------------
// Neues Förderansuchen anlegen (Stammdaten vom letzten Ansuchen übernehmen)
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'antrag_erstellen') {
    requireCsrf();

    $jahr  = (int)($_POST['jahr'] ?? 0);
    $titel = trim($_POST['titel'] ?? '');
    if ($jahr < 2020 || $jahr > 2100) $errors['jahr'] = 'Bitte ein gültiges Förderjahr angeben.';
    if ($titel === '') $titel = 'Basisförderung ' . $jahr;

    if (empty($errors)) {
        $stmt = $db->prepare('SELECT * FROM su_antraege WHERE organization_id = ? ORDER BY jahr DESC, id DESC LIMIT 1');
        $stmt->execute([$org_id]);
        $vorlage = $stmt->fetch() ?: [];

        // Aktuelle Mitgliederzahlen aus der Mitgliederverwaltung
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS gesamt,
                    SUM(mp.geburtsdatum IS NOT NULL AND mp.geburtsdatum > DATE_SUB(CURDATE(), INTERVAL 19 YEAR)) AS jugend
             FROM users u JOIN mitglieder_profile mp ON mp.user_id = u.id
             WHERE u.organization_id = ? AND u.rolle = 'mitglied' AND mp.mitgliedsstatus = 'aktiv'"
        );
        $stmt->execute([$org_id]);
        $zahlen = $stmt->fetch();

        $user = getCurrentUser();
        $db->prepare(
            'INSERT INTO su_antraege (organization_id, jahr, titel, sportarten, mitglieder_gesamt, mitglieder_jugend, vereinsbeschreibung,
                obmann_name, vertreter2_funktion, vertreter2_name, kontakt_name, kontakt_email, kontakt_telefon,
                kontoinhaber, iban, bic, bank, erstellt_von)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $org_id, $jahr, $titel,
            $vorlage['sportarten'] ?? 'Calisthenics, Skateboarding, Tischtennis, Padel Tennis',
            (int)$zahlen['gesamt'] ?: ($vorlage['mitglieder_gesamt'] ?? null),
            (int)$zahlen['jugend'] ?: ($vorlage['mitglieder_jugend'] ?? null),
            $vorlage['vereinsbeschreibung'] ?? null,
            $vorlage['obmann_name'] ?? null,
            $vorlage['vertreter2_funktion'] ?? 'kassier',
            $vorlage['vertreter2_name'] ?? null,
            $vorlage['kontakt_name'] ?? trim($user['vorname'] . ' ' . $user['nachname']),
            $vorlage['kontakt_email'] ?? $user['email'],
            $vorlage['kontakt_telefon'] ?? null,
            $vorlage['kontoinhaber'] ?? APP_NAME,
            $vorlage['iban'] ?? null,
            $vorlage['bic'] ?? null,
            $vorlage['bank'] ?? null,
            $user['id'],
        ]);
        $antrag_id = (int)$db->lastInsertId();
        logActivity('su_antrag_erstellt', "Antrag-ID: {$antrag_id}, {$titel}");
        flashMessage('success', 'Förderansuchen ' . $jahr . ' angelegt. Jetzt die Fördergegenstände erfassen.');
        redirect(APP_URL . '/dashboard/admin/basisfoerderung-antrag.php?id=' . $antrag_id);
    }
}

// ----------------------------------------------------------------
// Ansuchen mit Summen laden
// ----------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM su_antraege WHERE organization_id = ? ORDER BY jahr DESC, id DESC');
$stmt->execute([$org_id]);
$antraege = $stmt->fetchAll();

$stmt = $db->prepare('SELECT p.* FROM su_antrag_positionen p JOIN su_antraege a ON a.id = p.antrag_id WHERE a.organization_id = ?');
$stmt->execute([$org_id]);
$positionen = $stmt->fetchAll();

$stmt = $db->prepare(
    'SELECT k.* FROM su_antrag_kosten k JOIN su_antrag_positionen p ON p.id = k.position_id JOIN su_antraege a ON a.id = p.antrag_id WHERE a.organization_id = ?'
);
$stmt->execute([$org_id]);
$kosten_je_position = [];
foreach ($stmt->fetchAll() as $k) $kosten_je_position[$k['position_id']][] = $k;

$summen = [];
foreach ($positionen as $p) {
    $ks = suKostenSumme($kosten_je_position[$p['id']] ?? []);
    $summen[$p['antrag_id']]['anzahl']     = ($summen[$p['antrag_id']]['anzahl'] ?? 0) + 1;
    $summen[$p['antrag_id']]['beantragt'][] = suBeantragt($p, $ks);
    if ($p['betrag_zugesagt'] !== null) $summen[$p['antrag_id']]['zugesagt'][] = $p['betrag_zugesagt'];
}

$aktuell = array_filter($antraege, fn($a) => (int)$a['jahr'] === (int)date('Y'));
$beantragt_jahr = moneySum(array_merge([], ...array_map(fn($a) => $summen[$a['id']]['beantragt'] ?? [], $aktuell)));
$zugesagt_jahr  = moneySum(array_merge([], ...array_map(fn($a) => $summen[$a['id']]['zugesagt'] ?? [], $aktuell)));

// Nächste Fristen im laufenden Jahr
$y = (int)date('Y');
$fristen = [
    [$y . '-02-28', 'PRAE-Jahresmeldung (pauschale Reiseaufwandsentschädigungen des Vorjahres) ans Finanzamt'],
    [$y . '-03-31', 'Einreichfrist Bausubventionen und Kostenvoranschläge für Lehrgänge/Kadertrainings · Vereinsbonus Sommersemester*'],
    [$y . '-09-30', 'Vereinsbonus: Anträge für Maßnahmen im Herbst*'],
    [$y . '-10-31', 'Einreichfrist alle übrigen Förderungen · Abrechnung Bausubventionen'],
    [$y . '-11-30', 'Späteste Abrechnung aller übrigen Subventionen (binnen 3 Monaten nach Zusage)'],
    [$y . '-12-31', 'Ende Förderzeitraum – Rechnungs- und Zahlungsdatum müssen im Kalenderjahr liegen'],
];
$naechste_frist = null;
foreach ($fristen as $f) { if ($f[0] >= date('Y-m-d')) { $naechste_frist = $f; break; } }

$form = array_merge(['jahr' => $vorschlag_jahr, 'titel' => ''], $_POST);

$page_title = 'Basisförderung SPORTUNION';
$breadcrumb = 'Basisförderung';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Basisförderung SPORTUNION Steiermark</h1>
    <p class="dashboard-subtitle">Förderansuchen an den Landesverband zusammenstellen, prüfen und als PDF erzeugen – mit allen Förderarten, Sätzen und Fristen aus den Förderrichtlinien.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #F59E0B;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="kpi-value"><?= moneyFormat($beantragt_jahr) ?></div>
        <div class="kpi-label">Beantragt <?= date('Y') ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color: #22C55E;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div class="kpi-value"><?= moneyFormat($zugesagt_jahr) ?></div>
        <div class="kpi-label">Zugesagt <?= date('Y') ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <div class="kpi-value"><?= $naechste_frist ? date('d.m.', strtotime($naechste_frist[0])) : '–' ?></div>
        <div class="kpi-label"><?= $naechste_frist ? e($naechste_frist[1]) : 'Keine Frist mehr in diesem Jahr' ?></div>
    </div>
</div>

<!-- Ansuchen -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Förderansuchen (<?= count($antraege) ?>)</h2>
    </div>
    <?php if (empty($antraege)): ?>
        <div class="empty-state">
            <h3>Noch kein Förderansuchen angelegt</h3>
            <p>Lege unten das Ansuchen für ein Förderjahr an und erfasse alles, was gefördert werden soll.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr><th>Jahr</th><th>Ansuchen</th><th>Fördergegenstände</th><th>Beantragt</th><th>Zugesagt</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($antraege as $a): $s = SU_STATUS[$a['status']] ?? ['label' => $a['status'], 'class' => 'badge-gray']; $sm = $summen[$a['id']] ?? []; ?>
                    <tr>
                        <td><?= (int)$a['jahr'] ?></td>
                        <td class="text-primary"><?= e($a['titel']) ?></td>
                        <td><?= (int)($sm['anzahl'] ?? 0) ?></td>
                        <td><?= moneyFormat(moneySum($sm['beantragt'] ?? [])) ?></td>
                        <td><?= !empty($sm['zugesagt']) ? moneyFormat(moneySum($sm['zugesagt'])) : '–' ?></td>
                        <td><span class="badge <?= $s['class'] ?>"><?= e($s['label']) ?></span></td>
                        <td><a href="<?= APP_URL ?>/dashboard/admin/basisfoerderung-antrag.php?id=<?= $a['id'] ?>" class="btn btn-ghost-light btn-sm">Öffnen</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div style="padding: 1.25rem; border-top: 1px solid var(--border-light);">
        <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.75rem;">Neues Förderansuchen</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="antrag_erstellen">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Förderjahr (Kalenderjahr) <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['jahr']) ? 'error' : '' ?>" type="number" min="2020" max="2100" name="jahr" value="<?= e((string)$form['jahr']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Bezeichnung</label>
                    <input class="form-control" type="text" name="titel" maxlength="150" value="<?= e((string)$form['titel']) ?>" placeholder="leer = „Basisförderung <?= (int)$form['jahr'] ?>“">
                </div>
            </div>
            <p class="form-hint" style="margin-bottom: 1rem;">Vereinsdaten, Vertreter:innen und Bankverbindung werden vom letzten Ansuchen übernommen, die Mitgliederzahlen aus der Mitgliederverwaltung.</p>
            <button type="submit" class="btn btn-primary btn-sm">Förderansuchen anlegen</button>
        </form>
    </div>
</div>

<!-- Fristen -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Fristen <?= $y ?></h2>
    </div>
    <div style="overflow-x: auto;">
        <table class="data-table">
            <tbody>
                <?php foreach ($fristen as [$datum, $text]): $vorbei = $datum < date('Y-m-d'); ?>
                <tr style="<?= $vorbei ? 'opacity: 0.5;' : '' ?>">
                    <td style="white-space: nowrap; font-weight: 700;"><?= date('d.m.Y', strtotime($datum)) ?></td>
                    <td><?= e($text) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="form-hint" style="padding: 0.75rem 1.25rem;">* Vereinsbonus-Fristen laut anderen SPORTUNION-Landesverbänden für 2026 – für die Steiermark beim Landesverband bestätigen. Anträge immer vor Beginn der Maßnahme.</p>
</div>

<!-- Förderkatalog -->
<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Förderkatalog – was die SPORTUNION Steiermark fördert</h2>
    </div>
    <div style="padding: 1.25rem;">
        <p class="form-hint" style="margin-bottom: 1rem;">
            Voraussetzungen: Mitgliedschaft, keine offenen Verbindlichkeiten (Landesumlage), kein Rechtsstreit mit dem Verband, Einhaltung von Fair-Play, Anti-Doping und Ehrenkodex.
            Ansuchen stellen Obmann/Obfrau mit Kassier:in oder Schriftführer:in, digital über die Vereinsdatenbank, grundsätzlich VOR der Maßnahme.
            Ab <?= moneyFormat(SU_FINANZIERUNGSPLAN_AB) ?> ist ein Finanzierungsplan Pflicht. Es besteht kein Rechtsanspruch – die Vergabe erfolgt im Ermessen der Landesleitung.
        </p>
        <?php foreach (SU_BEREICHE as $bereich => $bereich_label): ?>
            <?php if ($bereich === 'infrastruktur'): ?>
                <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; margin: 1rem 0 0.25rem;">A · Landesverbandsförderung nach den Förderrichtlinien</h3>
            <?php elseif ($bereich === 'vereinsbonus'): ?>
                <h3 style="font-family: 'Montserrat', sans-serif; font-size: 1rem; font-weight: 800; margin: 2rem 0 0.25rem;">B · SPORTUNION Vereinsbonus 2026</h3>
                <p class="form-hint" style="margin-bottom: 0.5rem;">
                    Offenes Fördersystem mit festen Höchstbeträgen, die Fördersäulen sind frei kombinierbar und mehrfach nutzbar.
                    Voraussetzungen: mind. ein aktives Fit-Sport-Austria-Qualitätssiegel, Beratungsgespräch vor der ersten Förderung, Antrag über die Vereinsdatenbank VOR Beginn der Maßnahme.
                    Abgerechnet wird nach den Richtlinien der Bundes-Sport GmbH, Leistungszeitraum ist das Kalenderjahr.
                </p>
            <?php endif; ?>
            <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin: 1.25rem 0 0.5rem;"><?= e($bereich_label) ?></h3>
            <?php foreach (SU_FOERDERARTEN as $schluessel => $art): if ($art['bereich'] !== $bereich) continue; ?>
                <details style="border: 1px solid var(--border-light); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 0.5rem;">
                    <summary style="cursor: pointer; font-weight: 700;">
                        <?= e($art['label']) ?>
                        <span style="font-weight: 400; color: var(--text-muted); font-size: 0.8rem;">
                            · Frist <?= e($art['frist']) ?>
                            <?php if ($art['berechnung'] === 'rahmen'): ?> · <?= moneyFormat($art['grund']) ?> – <?= moneyFormat($art['hoechst']) ?><?php endif; ?>
                            <?php if ($art['berechnung'] === 'fix'): ?> · <?= moneyFormat($art['betrag']) ?><?php endif; ?>
                            <?php if ($art['berechnung'] === 'ausbildung'): ?> · € 100,– bis € 450,– je Abschluss<?php endif; ?>
                            <?php if ($art['berechnung'] === 'pro_person'): ?> · <?= moneyFormat($art['satz']) ?>/<?= moneyFormat($art['satz_uebernachtung']) ?> je Person<?php endif; ?>
                            <?php if ($art['berechnung'] === 'ermessen'): ?> · nach Ermessen<?php endif; ?>
                            <?php if ($art['berechnung'] === 'deckel'): ?> · max. <?= moneyFormat($art['max_je']) ?> je <?= e($art['je']) ?><?php endif; ?>
                        </span>
                    </summary>
                    <p style="margin: 0.75rem 0 0.5rem;"><?= e($art['kurz']) ?><?= $art['beispiel'] ? ' <em>' . e($art['beispiel']) . '</em>' : '' ?></p>
                    <ul style="margin: 0 0 0.5rem 1.25rem; font-size: 0.9rem;">
                        <?php foreach ($art['regeln'] as $regel): ?><li><?= e($regel) ?></li><?php endforeach; ?>
                    </ul>
                    <p style="font-size: 0.85rem; margin: 0;"><strong>Nachweise:</strong> <?= e(implode(' · ', $art['nachweise'])) ?></p>
                </details>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="grid-2" style="margin-top: 1.5rem; gap: 1.5rem;">
            <div>
                <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.5rem;">Unterlagen &amp; Links</h3>
                <ul style="margin-left: 1.25rem;">
                    <?php foreach (SU_QUELLEN as $label => $url): ?>
                        <li><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin: 1rem 0 0.5rem;">Abrechnungsformulare</h3>
                <ul style="margin-left: 1.25rem;">
                    <?php foreach (SU_ABRECHNUNGSFORMULARE as $label => $url): ?>
                        <li><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h3 style="font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 800; text-transform: uppercase; margin-bottom: 0.5rem;">Ansprechpersonen</h3>
                <?php foreach (SU_KONTAKTE as $k): ?>
                    <p style="margin-bottom: 0.5rem; font-size: 0.9rem;">
                        <strong><?= e($k['rolle']) ?>:</strong> <?= e($k['name']) ?><br>
                        <a href="mailto:<?= e($k['email']) ?>"><?= e($k['email']) ?></a> · <?= e($k['tel']) ?>
                    </p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
