<?php
/**
 * Athletikclub Steiermark – Mein Umsatz (Trainer)
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';

requireTrainer();

$db     = getDB();
$user   = getCurrentUser();
$errors = [];

// ----------------------------------------------------------------
// Manuellen Umsatz-Eintrag anlegen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'eintrag_anlegen') {
    requireCsrf();

    $beschreibung   = trim($_POST['beschreibung'] ?? '');
    $betrag         = trim($_POST['betrag'] ?? '');
    $leistungsdatum = trim($_POST['leistungsdatum'] ?? '');

    if (mb_strlen($beschreibung) < 3) $errors['beschreibung'] = 'Bitte eine Beschreibung angeben.';
    if (!is_numeric($betrag) || (float)$betrag <= 0) $errors['betrag'] = 'Bitte einen gültigen Betrag angeben.';
    if (empty($leistungsdatum) || !strtotime($leistungsdatum)) $errors['leistungsdatum'] = 'Bitte ein gültiges Datum angeben.';

    if (empty($errors)) {
        $db->prepare(
            'INSERT INTO umsatz_eintraege (trainer_id, beschreibung, betrag, leistungsdatum) VALUES (?, ?, ?, ?)'
        )->execute([$user['id'], $beschreibung, (float)$betrag, date('Y-m-d', strtotime($leistungsdatum))]);
        logActivity('umsatz_eintrag_angelegt');
        flashMessage('success', 'Eintrag gespeichert.');
        redirect(APP_URL . '/dashboard/umsatz.php');
    }
}

// Manuellen Eintrag löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'eintrag_loeschen') {
    requireCsrf();
    $eintrag_id = (int)($_POST['eintrag_id'] ?? 0);
    $db->prepare('DELETE FROM umsatz_eintraege WHERE id = ? AND trainer_id = ?')->execute([$eintrag_id, $user['id']]);
    logActivity('umsatz_eintrag_geloescht', "ID: {$eintrag_id}");
    redirect(APP_URL . '/dashboard/umsatz.php');
}

// ----------------------------------------------------------------
// Daten laden
// ----------------------------------------------------------------
$stmt = $db->prepare(
    "SELECT k.id, k.titel, k.start_datum, k.preis,
            COUNT(ka.id) AS bezahlte_teilnehmer,
            (COUNT(ka.id) * k.preis) AS summe
     FROM kurse k
     JOIN kurs_anmeldungen ka ON ka.kurs_id = k.id AND ka.bezahlt = 1
     WHERE k.trainer_id = ?
     GROUP BY k.id
     ORDER BY k.start_datum DESC"
);
$stmt->execute([$user['id']]);
$kurs_umsatz = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM umsatz_eintraege WHERE trainer_id = ? ORDER BY leistungsdatum DESC');
$stmt->execute([$user['id']]);
$manuelle_eintraege = $stmt->fetchAll();

$kursumsatz_summe = array_sum(array_column($kurs_umsatz, 'summe'));
$manuell_summe    = array_sum(array_column($manuelle_eintraege, 'betrag'));
$gesamt_summe     = $kursumsatz_summe + $manuell_summe;

$page_title = 'Mein Umsatz';
$breadcrumb = 'Mein Umsatz';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">Mein Umsatz</h1>
    <p class="dashboard-subtitle">Übersicht deiner Kurseinnahmen und manuellen Einträge</p>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color: #1F3556;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="kpi-value"><?= number_format($gesamt_summe, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Gesamtumsatz</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #C6A135;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <div class="kpi-value"><?= number_format($kursumsatz_summe, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Aus Kursen (bezahlt)</div>
    </div>
    <div class="kpi-card" style="--kpi-color: #4EBA6F;">
        <div class="kpi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div class="kpi-value"><?= number_format($manuell_summe, 2, ',', '.') ?> €</div>
        <div class="kpi-label">Manuelle Einträge</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; margin-top: 0.5rem;">

    <!-- Kursumsatz -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Kursumsatz (bezahlte Anmeldungen)</h2>
        </div>
        <?php if (empty($kurs_umsatz)): ?>
            <div class="empty-state"><h3>Noch keine bezahlten Anmeldungen</h3></div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead><tr><th>Kurs</th><th>Bezahlt</th><th>Betrag</th></tr></thead>
                    <tbody>
                        <?php foreach ($kurs_umsatz as $k): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/dashboard/kurs-detail.php?id=<?= $k['id'] ?>" class="text-primary"><?= e($k['titel']) ?></a><br>
                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?= date('d.m.Y', strtotime($k['start_datum'])) ?></span>
                            </td>
                            <td><?= (int)$k['bezahlte_teilnehmer'] ?></td>
                            <td><?= number_format((float)$k['summe'], 2, ',', '.') ?> €</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Manuelle Einträge -->
    <div class="table-card">
        <div class="table-card-header">
            <h2 class="table-card-title">Manuelle Einträge</h2>
        </div>
        <div style="padding: 1.25rem;">
            <form method="POST" action="" style="margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-light);">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="eintrag_anlegen">
                <div class="form-group">
                    <label class="form-label">Beschreibung</label>
                    <input class="form-control <?= isset($errors['beschreibung']) ? 'error' : '' ?>" type="text" name="beschreibung" placeholder="z.B. Einzeltraining Max Mustermann" value="<?= e($_POST['beschreibung'] ?? '') ?>">
                    <?php if (isset($errors['beschreibung'])): ?><span class="form-error"><?= e($errors['beschreibung']) ?></span><?php endif; ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Betrag (€)</label>
                        <input class="form-control <?= isset($errors['betrag']) ? 'error' : '' ?>" type="number" step="0.01" min="0" name="betrag" value="<?= e($_POST['betrag'] ?? '') ?>">
                        <?php if (isset($errors['betrag'])): ?><span class="form-error"><?= e($errors['betrag']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Datum</label>
                        <input class="form-control <?= isset($errors['leistungsdatum']) ? 'error' : '' ?>" type="date" name="leistungsdatum" value="<?= e($_POST['leistungsdatum'] ?? date('Y-m-d')) ?>">
                        <?php if (isset($errors['leistungsdatum'])): ?><span class="form-error"><?= e($errors['leistungsdatum']) ?></span><?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Eintrag hinzufügen</button>
            </form>

            <?php if (empty($manuelle_eintraege)): ?>
                <p style="color: var(--text-muted); font-size: 0.875rem;">Noch keine manuellen Einträge.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                    <?php foreach ($manuelle_eintraege as $e): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.65rem 0.85rem; background: var(--bg-muted); border-radius: 0.5rem;">
                            <div style="min-width: 0;">
                                <div style="font-weight: 600; font-size: 0.85rem;"><?= e($e['beschreibung']) ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= date('d.m.Y', strtotime($e['leistungsdatum'])) ?></div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.6rem; flex-shrink: 0;">
                                <span style="font-weight: 700; font-size: 0.875rem;"><?= number_format((float)$e['betrag'], 2, ',', '.') ?> €</span>
                                <form method="POST" onsubmit="return confirm('Eintrag wirklich löschen?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="eintrag_loeschen">
                                    <input type="hidden" name="eintrag_id" value="<?= $e['id'] ?>">
                                    <button type="submit" style="background: none; border: none; color: var(--danger); cursor: pointer; font-size: 0.9rem;">×</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
