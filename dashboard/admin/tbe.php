<?php
/**
 * Athletikclub Steiermark – Admin: TBE-Gesamtkonzepte (Tägliche Bewegungseinheit)
 */
define('ROOT_PATH', dirname(dirname(__DIR__)));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/money.php';
require_once ROOT_PATH . '/includes/tbe.php';

requireAdmin();

$db     = getDB();
$errors = [];

// ----------------------------------------------------------------
// Neues Gesamtkonzept anlegen
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'konzept_erstellen') {
    requireCsrf();

    $bezeichnung  = trim($_POST['bezeichnung'] ?? '');
    $zeitraum_von = trim($_POST['zeitraum_von'] ?? '');
    $zeitraum_bis = trim($_POST['zeitraum_bis'] ?? '');
    $stundensatz  = trim($_POST['stundensatz'] ?? '');

    if ($bezeichnung === '') $errors['bezeichnung'] = 'Bitte eine Bezeichnung angeben (z.B. 2026/27).';
    if (!strtotime($zeitraum_von)) $errors['zeitraum_von'] = 'Bitte ein gültiges Startdatum angeben.';
    if (!strtotime($zeitraum_bis)) $errors['zeitraum_bis'] = 'Bitte ein gültiges Enddatum angeben.';
    if (empty($errors) && strtotime($zeitraum_bis) < strtotime($zeitraum_von)) $errors['zeitraum_bis'] = 'Das Enddatum liegt vor dem Startdatum.';

    if (empty($errors)) {
        $db->prepare(
            'INSERT INTO tbe_konzepte (organization_id, bezeichnung, zeitraum_von, zeitraum_bis, stundensatz, erstellt_von)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            currentOrgId(),
            $bezeichnung,
            date('Y-m-d', strtotime($zeitraum_von)),
            date('Y-m-d', strtotime($zeitraum_bis)),
            $stundensatz !== '' ? moneyRound($stundensatz) : null,
            getCurrentUserId(),
        ]);
        $konzept_id = (int)$db->lastInsertId();
        logActivity('tbe_konzept_erstellt', "Konzept-ID: {$konzept_id}, {$bezeichnung}");
        flashMessage('success', 'Gesamtkonzept ' . $bezeichnung . ' angelegt. Jetzt die Projekte erfassen.');
        redirect(APP_URL . '/dashboard/admin/tbe-konzept.php?id=' . $konzept_id);
    }
}

$stmt = $db->prepare(
    "SELECT k.*,
        (SELECT COUNT(*) FROM tbe_projekte p WHERE p.konzept_id = k.id) AS anzahl_projekte,
        (SELECT COALESCE(SUM(p.anzahl_gruppen * p.einheiten_pro_woche * p.anzahl_wochen * p.dauer_minuten), 0) / 60
           FROM tbe_projekte p WHERE p.konzept_id = k.id) AS stunden_gesamt
     FROM tbe_konzepte k
     WHERE k.organization_id = ?
     ORDER BY k.zeitraum_von DESC"
);
$stmt->execute([currentOrgId()]);
$konzepte = $stmt->fetchAll();

// Vorschlag für ein neues Konzept: nächstes Schuljahr (September bis Juni)
$jahr = (int)date('Y') + ((int)date('n') >= 9 ? 1 : 0);
$vorschlag = [
    'bezeichnung'  => $jahr . '/' . substr((string)($jahr + 1), -2),
    'zeitraum_von' => $jahr . '-09-01',
    'zeitraum_bis' => ($jahr + 1) . '-06-30',
];
$form = array_merge($vorschlag, ['stundensatz' => ''], $_POST);

$page_title = 'TBE-Gesamtkonzept';
$breadcrumb = 'TBE-Gesamtkonzept';
require_once ROOT_PATH . '/includes/dashboard-header.php';
?>

<div class="dashboard-header">
    <h1 class="dashboard-title">TBE-Gesamtkonzept</h1>
    <p class="dashboard-subtitle">Tägliche Bewegungseinheit – alle Projekte an Schulen und Kindergärten pro Förderjahr sammeln, als Bericht einreichen und den bewilligten Budgetrahmen verteilen.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="flash-message flash-error" style="border-radius: 0.75rem; margin-bottom: 1.5rem;">
    <span><?= implode(' | ', array_map('e', $errors)) ?></span>
</div>
<?php endif; ?>

<div class="table-card" style="margin-bottom: 1.5rem;">
    <div class="table-card-header">
        <h2 class="table-card-title">Gesamtkonzepte (<?= count($konzepte) ?>)</h2>
    </div>
    <?php if (empty($konzepte)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
            </div>
            <h3>Noch kein Gesamtkonzept angelegt</h3>
            <p>Lege unten das erste Förderjahr an und erfasse danach die Projekte.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Förderjahr</th>
                        <th>Zeitraum</th>
                        <th>Projekte</th>
                        <th>Stunden</th>
                        <th>Budgetrahmen</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($konzepte as $k): $s = TBE_STATUS[$k['status']] ?? ['label' => $k['status'], 'class' => 'badge-gray']; ?>
                    <tr>
                        <td class="text-primary"><?= e($k['bezeichnung']) ?></td>
                        <td><?= date('d.m.Y', strtotime($k['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($k['zeitraum_bis'])) ?></td>
                        <td><?= (int)$k['anzahl_projekte'] ?></td>
                        <td><?= tbeZahl((float)$k['stunden_gesamt']) ?></td>
                        <td><?= $k['budget_rahmen'] !== null ? moneyFormat($k['budget_rahmen']) : '–' ?></td>
                        <td><span class="badge <?= $s['class'] ?>"><?= e($s['label']) ?></span></td>
                        <td><a href="<?= APP_URL ?>/dashboard/admin/tbe-konzept.php?id=<?= $k['id'] ?>" class="btn btn-ghost-light btn-sm">Öffnen</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="table-card">
    <div class="table-card-header">
        <h2 class="table-card-title">Neues Gesamtkonzept anlegen</h2>
    </div>
    <div style="padding: 1.25rem;">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="konzept_erstellen">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Förderjahr <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['bezeichnung']) ? 'error' : '' ?>" type="text" name="bezeichnung" maxlength="20" value="<?= e($form['bezeichnung']) ?>" placeholder="z.B. 2026/27">
                </div>
                <div class="form-group">
                    <label class="form-label">Stundensatz Trainer:in (€)</label>
                    <input class="form-control" type="number" min="0" step="0.01" name="stundensatz" value="<?= e($form['stundensatz']) ?>" placeholder="optional, für die Kostenschätzung">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Zeitraum von <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['zeitraum_von']) ? 'error' : '' ?>" type="date" name="zeitraum_von" value="<?= e($form['zeitraum_von']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">bis <span class="required">*</span></label>
                    <input class="form-control <?= isset($errors['zeitraum_bis']) ? 'error' : '' ?>" type="date" name="zeitraum_bis" value="<?= e($form['zeitraum_bis']) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Gesamtkonzept anlegen</button>
        </form>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
