<?php
/**
 * Athletikclub Steiermark – Kommunikationszentrale
 * Nachrichten an Gruppen (Mitglieder, Trainer:innen, Kurs, Eltern eines Kurses, Projekt,
 * Projektteam, Eventteilnehmende, Einzelperson) über die Kanäle Dashboard und E-Mail.
 * Vorlagen mit {{variablen}}, Versandverlauf und Mail-Protokoll.
 * Trainer:innen ohne Sonderrecht dürfen nur an ihre eigenen Kurse schreiben.
 */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/kommunikation.php';

requireTrainer();

$db     = getDB();
$org_id = currentOrgId();
$me     = (int)getCurrentUserId();
$voll   = darf('kommunikation.senden');
$vorlagen_recht = darf('vorlagen.bearbeiten');
$self   = APP_URL . '/dashboard/kommunikation.php';
$tabs = ['senden' => 'Nachricht senden', 'verlauf' => 'Verlauf'] + ($vorlagen_recht ? ['vorlagen' => 'Vorlagen'] : []) + (isAdmin() || darf('system.anzeigen') ? ['protokoll' => 'E-Mail-Protokoll'] : []);
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'senden';

$gruppen = [
    'kurs'          => 'Teilnehmende eines Kurses',
    'kurs_eltern'   => 'Eltern eines Kurses',
    'event'         => 'Teilnehmende eines Events',
] + ($voll ? [
    'alle_mitglieder' => 'Alle Mitglieder',
    'alle_trainer'    => 'Alle Trainer:innen',
    'projekt'         => 'Teilnehmende eines Projekts (alle Projektkurse)',
    'projektteam'     => 'Projektteam',
    'person'          => 'Einzelne Person',
] : []);

/** Kurse/Events, an die die Person schreiben darf. */
function kommKurse(PDO $db, bool $voll, string $art): array
{
    $sql = "SELECT id, titel, start_datum FROM kurse WHERE organization_id = ? AND art = ? AND status IN ('geplant','aktiv','abgeschlossen') AND end_datum >= ?";
    $p = [currentOrgId(), $art, date('Y-m-d', strtotime('-60 days'))];
    if (!$voll) { $sql .= ' AND trainer_id = ?'; $p[] = getCurrentUserId(); }
    $stmt = $db->prepare($sql . ' ORDER BY start_datum');
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/** Empfänger (user_id => Anzeigename) einer Gruppe, serverseitig auf Rechte geprüft. */
function kommEmpfaenger(PDO $db, string $typ, int $ref, bool $voll): array
{
    $org = currentOrgId();
    $aktiv = "('angemeldet','angefragt','teilgenommen')";
    $erlaubte_kurse = null;
    if (in_array($typ, ['kurs', 'kurs_eltern', 'event'], true)) {
        $ids = array_map(fn($k) => (int)$k['id'], kommKurse($db, $voll, $typ === 'event' ? 'event' : 'kurs'));
        if (!in_array($ref, $ids, true)) return [];
    } elseif (!$voll) {
        return [];
    }
    $sql = match ($typ) {
        'kurs', 'event'   => "SELECT DISTINCT u.id, u.vorname, u.nachname FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id WHERE ka.kurs_id = ? AND ka.status IN $aktiv",
        'kurs_eltern'     => "SELECT DISTINCT u.id, u.vorname, u.nachname FROM kurs_anmeldungen ka JOIN users u ON u.id = ka.user_id WHERE ka.kurs_id = ? AND ka.kind_id > 0 AND ka.status IN $aktiv",
        'alle_mitglieder' => "SELECT id, vorname, nachname FROM users WHERE organization_id = ? AND rolle = 'mitglied' AND aktiv = 1",
        'alle_trainer'    => "SELECT id, vorname, nachname FROM users WHERE organization_id = ? AND rolle IN ('trainer','admin') AND aktiv = 1",
        'projekt'         => "SELECT DISTINCT u.id, u.vorname, u.nachname FROM kurs_anmeldungen ka JOIN kurse k ON k.id = ka.kurs_id JOIN users u ON u.id = ka.user_id WHERE k.projekt_id = ? AND ka.status IN $aktiv",
        'projektteam'     => 'SELECT DISTINCT u.id, u.vorname, u.nachname FROM users u WHERE u.id IN (SELECT user_id FROM projekt_team WHERE projekt_id = ?) OR u.id = (SELECT leitung_id FROM projekte WHERE id = ?)',
        'person'          => "SELECT id, vorname, nachname FROM users WHERE id = ? AND organization_id = ? AND aktiv = 1",
        default           => null,
    };
    if (!$sql) return [];
    $p = match ($typ) {
        'alle_mitglieder', 'alle_trainer' => [$org],
        'projektteam' => [$ref, $ref],
        'person' => [$ref, $org],
        default => [$ref],
    };
    if (in_array($typ, ['projekt', 'projektteam'], true)) {
        $stmt = $db->prepare('SELECT 1 FROM projekte WHERE id = ? AND organization_id = ?');
        $stmt->execute([$ref, $org]);
        if (!$stmt->fetchColumn()) return [];
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($p);
    $r = [];
    foreach ($stmt->fetchAll() as $u) $r[(int)$u['id']] = $u['vorname'] . ' ' . $u['nachname'];
    return $r;
}

// ----------------------------------------------------------------
// Aktionen
// ----------------------------------------------------------------
$vorschau = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['vorschau', 'senden'], true)) {
        $typ = isset($gruppen[$_POST['gruppe'] ?? '']) ? $_POST['gruppe'] : '';
        $ref = (int)($_POST['ref_' . $typ] ?? 0);
        $betreff = mb_substr(trim($_POST['betreff'] ?? ''), 0, 200);
        $text = trim($_POST['text'] ?? '');
        $kanaele = array_values(array_intersect((array)($_POST['kanal'] ?? []), array_keys(KOMM_KANAELE)));
        $empf = $typ ? kommEmpfaenger($db, $typ, $ref, $voll) : [];
        $fehler = [];
        if (!$typ) $fehler[] = 'Bitte eine Empfängergruppe wählen.';
        elseif (!$empf) $fehler[] = 'Diese Gruppe hat keine Empfänger:innen (oder du darfst ihr nicht schreiben).';
        if ($betreff === '' || $text === '') $fehler[] = 'Bitte Betreff und Text eingeben.';
        if (!$kanaele) $fehler[] = 'Bitte mindestens einen Kanal wählen.';
        if ($fehler) {
            flashMessage('error', implode(' ', $fehler));
            $vorschau = ['fehler' => true];
        } elseif ($action === 'vorschau') {
            $vorschau = ['anzahl' => count($empf), 'namen' => array_slice($empf, 0, 12, true)];
        } else {
            // Kursbezogene Variablen einmal ermitteln
            $vars = [];
            if (in_array($typ, ['kurs', 'kurs_eltern', 'event'], true) && ($k = kursDatenFuerVorlage($db, $ref))) $vars = kursVariablen($db, $k);
            $db->prepare('INSERT INTO nachrichten (organization_id, betreff, text, kanaele, empfaenger_typ, empfaenger_ref, empfaenger_anzahl, vorlage_id, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$org_id, $betreff, $text, implode(',', $kanaele), $typ, $ref ?: null, count($empf), (int)($_POST['vorlage_id'] ?? 0) ?: null, $me]);
            $nid = (int)$db->lastInsertId();
            $mails = 0;
            $stmt = $db->prepare('SELECT id, vorname, nachname, email FROM users WHERE id = ?');
            foreach (array_keys($empf) as $uid) {
                $stmt->execute([$uid]);
                $u = $stmt->fetch();
                if (!$u) continue;
                $v = array_merge(standardVariablen(), $vars, ['vorname' => $u['vorname'], 'nachname' => $u['nachname'], 'link' => '']);
                $b = textErsetzen($betreff, $v);
                $t = textErsetzen($text, $v);
                if (in_array('intern', $kanaele, true)) benachrichtigen($uid, 'nachricht', mb_substr($b, 0, 200), mb_substr(preg_replace('/\s+/', ' ', $t), 0, 480), null, 'nachricht-' . $nid);
                if (in_array('email', $kanaele, true) && mailSenden($db, $u['email'], $b, $t, $uid, 'nachricht:' . $nid)) $mails++;
            }
            $db->prepare('UPDATE nachrichten SET mails_gesendet = ? WHERE id = ?')->execute([$mails, $nid]);
            auditLog('erstellt', 'nachrichten', $nid, null, ['gruppe' => $typ, 'ref' => $ref, 'empfaenger' => count($empf), 'kanaele' => implode(',', $kanaele)], $betreff);
            flashMessage('success', 'Nachricht an ' . count($empf) . ' Person(en) gesendet' . (in_array('email', $kanaele, true) ? " – {$mails} E-Mail(s) übergeben." : '.'));
            redirect($self . '?tab=verlauf');
        }
    }

    if ($vorlagen_recht && $action === 'vorlage_speichern') {
        $id = (int)($_POST['id'] ?? 0);
        $d = ['name' => mb_substr(trim($_POST['name'] ?? ''), 0, 150), 'betreff' => mb_substr(trim($_POST['betreff'] ?? ''), 0, 200), 'text' => trim($_POST['text'] ?? ''),
              'kanal' => in_array($_POST['kanal'] ?? '', ['intern', 'email', 'beide'], true) ? $_POST['kanal'] : 'beide', 'aktiv' => !empty($_POST['aktiv']) ? 1 : 0];
        if ($d['name'] === '' || $d['betreff'] === '' || $d['text'] === '') {
            flashMessage('error', 'Name, Betreff und Text sind Pflichtfelder.');
            redirect($self . '?tab=vorlagen' . ($id ? '&id=' . $id : '&neu=1'));
        }
        if ($id) {
            $stmt = $db->prepare('SELECT * FROM nachricht_vorlagen WHERE id = ? AND organization_id = ?');
            $stmt->execute([$id, $org_id]);
            $alt = $stmt->fetch();
            if (!$alt) redirect($self . '?tab=vorlagen');
            if ($alt['system']) $d['aktiv'] = 1; // Systemvorlagen bleiben aktiv, damit Abläufe funktionieren
            $db->prepare('UPDATE nachricht_vorlagen SET name = ?, betreff = ?, text = ?, kanal = ?, aktiv = ? WHERE id = ?')->execute([...array_values($d), $id]);
            auditLog('geaendert', 'nachricht_vorlagen', $id, $alt, $d, $d['name']);
        } else {
            $code = 'eigen_' . substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $d['name']) ?: 'vorlage')), 0, 40) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $db->prepare('INSERT INTO nachricht_vorlagen (organization_id, code, name, betreff, text, kanal, aktiv, system) VALUES (?, ?, ?, ?, ?, ?, ?, 0)')
               ->execute([$org_id, $code, ...array_values($d)]);
            $id = (int)$db->lastInsertId();
            auditLog('erstellt', 'nachricht_vorlagen', $id, null, $d, $d['name']);
        }
        flashMessage('success', 'Vorlage gespeichert.');
        redirect($self . '?tab=vorlagen');
    }

    if ($vorlagen_recht && $action === 'vorlage_loeschen') {
        $stmt = $db->prepare('SELECT * FROM nachricht_vorlagen WHERE id = ? AND organization_id = ? AND system = 0');
        $stmt->execute([(int)($_POST['id'] ?? 0), $org_id]);
        if ($v = $stmt->fetch()) {
            $db->prepare('DELETE FROM nachricht_vorlagen WHERE id = ?')->execute([$v['id']]);
            auditLog('geloescht', 'nachricht_vorlagen', (int)$v['id'], $v, null, $v['name']);
            flashMessage('success', 'Vorlage gelöscht.');
        }
        redirect($self . '?tab=vorlagen');
    }
}

function kursDatenFuerVorlage(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM kurse WHERE id = ? AND organization_id = ?');
    $stmt->execute([$id, currentOrgId()]);
    return $stmt->fetch() ?: null;
}

// ----------------------------------------------------------------
// Daten für die Anzeige
// ----------------------------------------------------------------
$alle_vorlagen = [];
try {
    $stmt = $db->prepare('SELECT * FROM nachricht_vorlagen WHERE organization_id = ? ORDER BY system DESC, name');
    $stmt->execute([$org_id]);
    $alle_vorlagen = $stmt->fetchAll();
} catch (Exception $e) {}
$vorlage_gewaehlt = null;
if (!empty($_GET['vorlage'])) foreach ($alle_vorlagen as $v) if ((int)$v['id'] === (int)$_GET['vorlage']) $vorlage_gewaehlt = $v;

$page_title = 'Kommunikation';
$breadcrumb = 'Kommunikation';
require_once ROOT_PATH . '/includes/dashboard-header.php';
$kurse_liste = kommKurse($db, $voll, 'kurs');
$events_liste = kommKurse($db, $voll, 'event');
$projekte_liste = $voll ? plattformProjekte($db, false) : [];
$f = $_POST + ['ref_event' => (int)($_GET['ref_event'] ?? 0), 'gruppe' => $_GET['gruppe'] ?? '', 'betreff' => $vorlage_gewaehlt['betreff'] ?? '', 'text' => $vorlage_gewaehlt['text'] ?? '', 'kanal' => ['intern', 'email']];
if (!empty($_GET['kurs'])) { $f['gruppe'] = $f['gruppe'] ?: 'kurs'; $f['ref_kurs'] = (int)$_GET['kurs']; $f['ref_kurs_eltern'] = (int)$_GET['kurs']; }
$kanal_label = ['intern' => 'Dashboard', 'email' => 'E-Mail', 'beide' => 'Dashboard + E-Mail'];
?>

<style>
.ko-tabs { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.ko-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
.ko-mini { font-size: 0.78rem; color: var(--text-muted); }
.ko-var { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.ko-var button { border: 1px solid var(--border-light); background: var(--bg-muted); border-radius: 0.4rem; padding: 0.15rem 0.45rem; font-size: 0.75rem; cursor: pointer; font-family: monospace; color: var(--text-primary); }
.ko-ref { display: none; }
.ko-ref.an { display: block; }
.ko-vorschau { background: var(--bg-muted); border-radius: 0.75rem; padding: 1rem; margin-bottom: 1rem; font-size: 0.9rem; }
@media (max-width: 900px) { .ko-grid { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="dashboard-header">
    <h1 class="dashboard-title">Kommunikation</h1>
    <p class="dashboard-subtitle">Nachrichten an Mitglieder, Eltern, Kurse, Projekte und Teams – im Dashboard und per E-Mail.</p>
</div>

<div class="ko-tabs" role="tablist">
    <?php foreach ($tabs as $k => $l): ?><a href="?tab=<?= $k ?>" class="btn <?= $tab === $k ? 'btn-navy' : 'btn-ghost-light' ?> btn-sm" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($l) ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'senden'): ?>
    <?php if (!mailKonfiguriert()): ?><div class="flash-message flash-info" style="border-radius: 0.6rem; margin-bottom: 1rem;"><span>E-Mail-Versand ist derzeit deaktiviert oder nicht eingerichtet – Nachrichten erscheinen dann nur im Dashboard.</span></div><?php endif; ?>
    <div class="ko-grid">
        <form method="POST" class="form-card" id="ko-form">
            <?= csrfField() ?>
            <?php if ($vorschau && empty($vorschau['fehler'])): ?>
                <div class="ko-vorschau"><strong><?= (int)$vorschau['anzahl'] ?> Empfänger:innen</strong><br><?= e(implode(', ', $vorschau['namen'])) ?><?= $vorschau['anzahl'] > 12 ? ' …' : '' ?></div>
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group"><label class="form-label" for="ko-gruppe">Empfänger:innen</label>
                    <select class="form-control" id="ko-gruppe" name="gruppe" onchange="koRef()"><option value="">Bitte wählen …</option><?php foreach ($gruppen as $k => $l): ?><option value="<?= $k ?>" <?= $f['gruppe'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="form-group">
                    <div class="ko-ref" data-fuer="kurs kurs_eltern"><label class="form-label">Kurs</label>
                        <select class="form-control" name="ref_kurs" onchange="this.form.ref_kurs_eltern.value = this.value"><?php foreach ($kurse_liste as $k): ?><option value="<?= $k['id'] ?>" <?= (int)($f['ref_kurs'] ?? 0) === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['titel']) ?> (<?= date('d.m.Y', strtotime($k['start_datum'])) ?>)</option><?php endforeach; ?></select>
                        <input type="hidden" name="ref_kurs_eltern" value="<?= (int)($f['ref_kurs_eltern'] ?? ($kurse_liste[0]['id'] ?? 0)) ?>"></div>
                    <div class="ko-ref" data-fuer="event"><label class="form-label">Event</label>
                        <select class="form-control" name="ref_event"><?php foreach ($events_liste as $k): ?><option value="<?= $k['id'] ?>" <?= (int)($f['ref_event'] ?? 0) === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['titel']) ?> (<?= date('d.m.Y', strtotime($k['start_datum'])) ?>)</option><?php endforeach; ?></select></div>
                    <div class="ko-ref" data-fuer="projekt projektteam"><label class="form-label">Projekt</label>
                        <select class="form-control" name="ref_projekt" onchange="this.form.ref_projektteam.value = this.value"><?php foreach ($projekte_liste as $p): ?><option value="<?= $p['id'] ?>" <?= (int)($f['ref_projekt'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
                        <input type="hidden" name="ref_projektteam" value="<?= (int)($f['ref_projektteam'] ?? ($projekte_liste[0]['id'] ?? 0)) ?>"></div>
                    <div class="ko-ref" data-fuer="person"><label class="form-label">Person</label>
                        <select class="form-control" name="ref_person"><?php if ($voll): foreach ($db->query("SELECT id, vorname, nachname, rolle FROM users WHERE aktiv = 1 AND organization_id = " . (int)$org_id . " ORDER BY nachname, vorname LIMIT 2000") as $u): ?><option value="<?= $u['id'] ?>" <?= (int)($f['ref_person'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nachname'] . ' ' . $u['vorname']) ?><?= $u['rolle'] !== 'mitglied' ? ' (' . e($u['rolle']) . ')' : '' ?></option><?php endforeach; endif; ?></select></div>
                </div>
            </div>
            <?php if ($alle_vorlagen): ?>
            <div class="form-group"><label class="form-label">Vorlage übernehmen</label>
                <select class="form-control" onchange="if (this.value) location.href='?tab=senden&vorlage=' + this.value + '&gruppe=' + document.getElementById('ko-gruppe').value"><option value="">– ohne Vorlage –</option><?php foreach ($alle_vorlagen as $v): if (!$v['aktiv']) continue; ?><option value="<?= $v['id'] ?>" <?= $vorlage_gewaehlt && (int)$vorlage_gewaehlt['id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select>
                <input type="hidden" name="vorlage_id" value="<?= (int)($vorlage_gewaehlt['id'] ?? ($_POST['vorlage_id'] ?? 0)) ?>"></div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label" for="ko-betreff">Betreff</label><input class="form-control" id="ko-betreff" name="betreff" maxlength="200" value="<?= e($f['betreff']) ?>" required></div>
            <div class="form-group"><label class="form-label" for="ko-text">Text</label><textarea class="form-control" id="ko-text" name="text" rows="9" required><?= e($f['text']) ?></textarea>
                <div class="ko-var" style="margin-top: 0.4rem;"><?php foreach (['vorname', 'nachname', 'kurs', 'datum', 'uhrzeit', 'ort', 'trainer', 'verein'] as $var): ?><button type="button" onclick="koVar('<?= $var ?>')">{{<?= $var ?>}}</button><?php endforeach; ?></div>
                <span class="form-hint">Variablen werden je Empfänger:in ersetzt (Kursangaben bei Kurs-/Eventgruppen).</span></div>
            <div class="form-group"><label class="form-label">Kanäle</label>
                <?php foreach (KOMM_KANAELE as $k => $l): ?><label class="form-check" style="margin-bottom: 0.3rem;"><input type="checkbox" name="kanal[]" value="<?= $k ?>" <?= in_array($k, (array)$f['kanal'], true) ? 'checked' : '' ?>><span class="form-check-label"><?= e($l) ?></span></label><?php endforeach; ?></div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button type="submit" name="action" value="vorschau" class="btn btn-ghost-light">Empfänger prüfen</button>
                <button type="submit" name="action" value="senden" class="btn btn-primary" onclick="return confirm('Nachricht jetzt senden?');">Senden</button>
            </div>
        </form>
        <div class="table-card">
            <div class="table-card-header"><h2 class="table-card-title">Hinweise</h2></div>
            <div style="padding: 1rem 1.25rem; font-size: 0.875rem; line-height: 1.6;">
                <p>„Teilnehmende“ umfasst bestätigte und angefragte Anmeldungen. Bei Kindern erhält die erziehungsberechtigte Person die Nachricht.</p>
                <?php if (!$voll): ?><p>Du kannst an die Teilnehmenden deiner eigenen Kurse und Events schreiben.</p><?php endif; ?>
                <p class="ko-mini">Automatische Nachrichten (Bestätigungen, Erinnerungen, Warteliste) laufen über die <?= $vorlagen_recht ? '<a href="?tab=vorlagen">Vorlagen</a>' : 'Vorlagen' ?>.</p>
            </div>
        </div>
    </div>
    <script>
    function koRef() {
        var g = document.getElementById('ko-gruppe').value;
        document.querySelectorAll('.ko-ref').forEach(function (el) { el.classList.toggle('an', (' ' + el.dataset.fuer + ' ').indexOf(' ' + g + ' ') >= 0); });
    }
    function koVar(v) {
        var t = document.getElementById('ko-text'), s = t.selectionStart || t.value.length, ins = '{{' + v + '}}';
        t.value = t.value.slice(0, s) + ins + t.value.slice(t.selectionEnd || s); t.focus(); t.selectionStart = t.selectionEnd = s + ins.length;
    }
    koRef();
    </script>

<?php elseif ($tab === 'verlauf'): ?>
    <?php
    $sql = 'SELECT n.*, u.vorname, u.nachname FROM nachrichten n LEFT JOIN users u ON u.id = n.erstellt_von WHERE n.organization_id = ?' . ($voll ? '' : ' AND n.erstellt_von = ' . $me);
    $stmt = $db->prepare($sql . ' ORDER BY n.created_at DESC LIMIT 100');
    $stmt->execute([$org_id]);
    $verlauf = $stmt->fetchAll();
    ?>
    <div class="table-card">
        <?php if (!$verlauf): ?><div class="empty-state" style="padding: 2rem 1rem;"><p>Noch keine Nachrichten versendet.</p></div>
        <?php else: ?><div style="overflow-x: auto;"><table class="data-table">
            <thead><tr><th>Datum</th><th>Betreff</th><th>Gruppe</th><th>Empfänger</th><th>Kanäle</th><th>Von</th></tr></thead>
            <tbody><?php foreach ($verlauf as $n): ?><tr>
                <td style="white-space: nowrap;"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></td>
                <td class="text-primary"><?= e($n['betreff']) ?><div class="ko-mini"><?= e(mb_strimwidth($n['text'], 0, 90, '…')) ?></div></td>
                <td><?= e($gruppen[$n['empfaenger_typ']] ?? $n['empfaenger_typ']) ?></td>
                <td><?= (int)$n['empfaenger_anzahl'] ?><?= str_contains($n['kanaele'], 'email') ? '<div class="ko-mini">' . (int)$n['mails_gesendet'] . ' E-Mails</div>' : '' ?></td>
                <td><?= e(implode(', ', array_map(fn($k) => KOMM_KANAELE[$k] ?? $k, explode(',', $n['kanaele'])))) ?></td>
                <td><?= e(trim(($n['vorname'] ?? '') . ' ' . ($n['nachname'] ?? ''))) ?></td>
            </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>

<?php elseif ($tab === 'vorlagen'): ?>
    <?php
    $edit = null;
    if (!empty($_GET['id'])) foreach ($alle_vorlagen as $v) if ((int)$v['id'] === (int)$_GET['id']) $edit = $v;
    $neu = !empty($_GET['neu']);
    ?>
    <?php if ($edit || $neu): $v = $edit ?? ['id' => 0, 'name' => '', 'betreff' => '', 'text' => '', 'kanal' => 'beide', 'aktiv' => 1, 'system' => 0, 'code' => '']; ?>
    <div class="ko-grid">
        <form method="POST" class="form-card"><?= csrfField() ?><input type="hidden" name="action" value="vorlage_speichern"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Name</label><input class="form-control" name="name" maxlength="150" required value="<?= e($v['name']) ?>"></div>
                <div class="form-group"><label class="form-label">Kanal</label><select class="form-control" name="kanal"><?php foreach ($kanal_label as $k => $l): ?><option value="<?= $k ?>" <?= $v['kanal'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label">Betreff</label><input class="form-control" id="ko-betreff" name="betreff" maxlength="200" required value="<?= e($v['betreff']) ?>"></div>
            <div class="form-group"><label class="form-label">Text</label><textarea class="form-control" id="ko-text" name="text" rows="12" required><?= e($v['text']) ?></textarea></div>
            <?php if (!$v['system']): ?><label class="form-check" style="margin-bottom: 1rem;"><input type="checkbox" name="aktiv" value="1" <?= $v['aktiv'] ? 'checked' : '' ?>><span class="form-check-label">Aktiv</span></label><?php else: ?><input type="hidden" name="aktiv" value="1"><p class="ko-mini">Systemvorlage (<?= e($v['code']) ?>) – wird von automatischen Abläufen verwendet.</p><?php endif; ?>
            <div style="display: flex; gap: 0.5rem;"><button class="btn btn-primary" type="submit">Speichern</button><a class="btn btn-ghost-light" href="?tab=vorlagen">Abbrechen</a></div>
        </form>
        <div class="table-card">
            <div class="table-card-header"><h2 class="table-card-title">Variablen</h2></div>
            <div style="padding: 1rem 1.25rem;"><div class="ko-var" style="flex-direction: column; align-items: flex-start;">
                <?php foreach (VORLAGEN_VARIABLEN as $k => $l): ?><div><button type="button" onclick="koVar('<?= $k ?>')">{{<?= $k ?>}}</button> <span class="ko-mini"><?= e($l) ?></span></div><?php endforeach; ?>
            </div><p class="ko-mini" style="margin-top: 0.75rem;">Variablen werden als reiner Text eingesetzt – es wird kein Code ausgeführt.</p></div>
        </div>
    </div>
    <script>function koVar(v) { var t = document.getElementById('ko-text'), s = t.selectionStart || t.value.length, ins = '{{' + v + '}}'; t.value = t.value.slice(0, s) + ins + t.value.slice(t.selectionEnd || s); t.focus(); }</script>
    <?php else: ?>
    <div style="margin-bottom: 1rem;"><a href="?tab=vorlagen&neu=1" class="btn btn-primary btn-sm">+ Neue Vorlage</a></div>
    <div class="table-card"><div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Vorlage</th><th>Betreff</th><th>Kanal</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($alle_vorlagen as $v): ?><tr>
            <td class="text-primary"><a href="?tab=vorlagen&id=<?= $v['id'] ?>"><?= e($v['name']) ?></a><?= $v['system'] ? ' <span class="badge badge-gray">System</span>' : '' ?></td>
            <td><?= e($v['betreff']) ?></td><td><?= e($kanal_label[$v['kanal']] ?? $v['kanal']) ?></td>
            <td><span class="badge <?= $v['aktiv'] ? 'badge-success' : 'badge-gray' ?>"><?= $v['aktiv'] ? 'aktiv' : 'inaktiv' ?></span></td>
            <td style="white-space: nowrap;"><a href="?tab=senden&vorlage=<?= $v['id'] ?>" class="btn btn-ghost-light btn-sm">Verwenden</a>
                <?php if (!$v['system']): ?><form method="POST" style="display: inline;" onsubmit="return confirm('Vorlage löschen?');"><?= csrfField() ?><input type="hidden" name="action" value="vorlage_loeschen"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="btn btn-ghost-light btn-sm" aria-label="Löschen">✕</button></form><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>

<?php else: /* protokoll */ ?>
    <?php
    $stmt = $db->prepare('SELECT * FROM mail_log WHERE organization_id = ? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$org_id]);
    $log = $stmt->fetchAll();
    $st_badge = ['gesendet' => 'badge-success', 'fehler' => 'badge-danger', 'deaktiviert' => 'badge-gray'];
    ?>
    <div class="table-card"><?php if (!$log): ?><div class="empty-state" style="padding: 2rem 1rem;"><p>Noch keine E-Mails protokolliert.</p></div>
    <?php else: ?><div style="overflow-x: auto;"><table class="data-table">
        <thead><tr><th>Zeit</th><th>Empfänger:in</th><th>Betreff</th><th>Status</th><th>Bezug</th></tr></thead>
        <tbody><?php foreach ($log as $m): ?><tr>
            <td style="white-space: nowrap;"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></td><td><?= e($m['email']) ?></td><td><?= e($m['betreff']) ?></td>
            <td><span class="badge <?= $st_badge[$m['status']] ?? 'badge-gray' ?>"><?= e($m['status']) ?></span><?= $m['fehler'] ? '<div class="ko-mini">' . e($m['fehler']) . '</div>' : '' ?></td><td class="ko-mini"><?= e($m['bezug'] ?? '') ?></td>
        </tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/dashboard-footer.php'; ?>
