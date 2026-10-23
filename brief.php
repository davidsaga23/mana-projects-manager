<?php
// The project brief — step 3 of the production cycle, and until now the only
// step with nowhere to live.
//
// A project has started here at "name, code, type, fps, delivery date" since
// Phase 2. What the client ASKED FOR — the deliverables, their formats, the
// reference, who to call — sat in somebody's email. Everything downstream is
// derived from it, and it is the document people go looking for in week six,
// after the person who received it has moved on to the next show.
//
// Deliberately free text rather than a form of fields. Every brief is shaped
// differently, and a form with the wrong boxes is worse than a blank page:
// people leave out whatever does not fit.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/naming.php';
require_once __DIR__ . '/includes/pipeline.php';   // applies_to_phrase(), on the step codes
require_login();

$projectId = (int)($_GET['project'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$selfUrl = 'brief.php?project=' . $projectId;
$userId  = (int)($_SESSION['user_id'] ?? 0);
$ready   = column_exists($pdo, 'project_brief', 'project_id');

// --- save -------------------------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $pdo->prepare(
        'INSERT INTO project_brief
           (project_id, client, client_contact, summary, deliverables, reference_links, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           client = VALUES(client), client_contact = VALUES(client_contact),
           summary = VALUES(summary), deliverables = VALUES(deliverables),
           reference_links = VALUES(reference_links), notes = VALUES(notes),
           updated_by = VALUES(updated_by)'
    )->execute([
        $projectId,
        trim($_POST['client'] ?? '') ?: null,
        trim($_POST['client_contact'] ?? '') ?: null,
        trim($_POST['summary'] ?? '') ?: null,
        trim($_POST['deliverables'] ?? '') ?: null,
        trim($_POST['reference_links'] ?? '') ?: null,
        trim($_POST['notes'] ?? '') ?: null,
        current_user_display(),
    ]);

    $notes = ['Brief saved.'];
    if (!empty($_FILES['attachments'])) {
        $res = save_attachments($pdo, normalize_files_array($_FILES['attachments']),
                                'project_attachments', 'project_id', $projectId, null, $userId);
        if ($res['saved'])  $notes[] = $res['saved'] . ' file(s) attached.';
        if ($res['errors']) $notes[] = implode(' ', $res['errors']);
    }
    flash('ok', implode(' ', $notes));
    header('Location: ' . $selfUrl);
    exit;
}

// --- remove one attachment --------------------------------------------------
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_attachment'])) {
    $aid = (int)$_POST['delete_attachment'];
    $q = $pdo->prepare('SELECT stored_name, uploaded_by FROM project_attachments WHERE id = ? AND project_id = ?');
    $q->execute([$aid, $projectId]);
    if ($a = $q->fetch()) {
        if (is_admin() || (int)$a['uploaded_by'] === $userId) {
            @unlink(upload_dir_path() . '/' . basename($a['stored_name']));
            $pdo->prepare('DELETE FROM project_attachments WHERE id = ?')->execute([$aid]);
            flash('ok', 'File removed.');
        } else {
            flash('err', 'You can only remove files you uploaded yourself.');
        }
    } else {
        flash('warn', 'That file is already gone.');
    }
    header('Location: ' . $selfUrl);
    exit;
}

// --- the naming convention (migration 067) ----------------------------------
//
// David, 2026-10-22: the Brief carries the show's naming convention "as a
// standard procedure", and a version whose file does not follow it is refused.
// Its own form and its own save, beside the brief rather than inside it: the
// brief is prose people rewrite, this is a rule a machine enforces, and saving
// one must not quietly re-save the other.
$namingReady = $ready && naming_available($pdo);
if ($namingReady && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_naming']) && can_edit()) {
    $clean = fn($t) => mb_substr(preg_replace('/\s+/', '', trim((string)$t)), 0, 255);
    $shots  = $clean($_POST['naming_shots'] ?? '');
    $assets = $clean($_POST['naming_assets'] ?? '');

    // A template that names a placeholder nobody knows would check against a
    // literal "{SHOTCODE}" and refuse every file on the show. Said, not saved.
    $known = array_keys(naming_tokens());
    $unknown = [];
    foreach ([$shots, $assets] as $t) {
        preg_match_all('/\{([^}]*)\}/', $t, $m);
        foreach ($m[1] as $tok) if (!in_array(strtoupper($tok), $known, true)) $unknown[] = '{' . $tok . '}';
    }
    $noVersion = array_filter([$shots, $assets], fn($t) => $t !== '' && stripos($t, '{VERSION}') === false);

    if ($unknown) {
        flash('err', 'Not saved: ' . implode(', ', array_unique($unknown)) . ' is not a placeholder this screen knows.');
    } elseif ($noVersion) {
        flash('err', 'Not saved: a template needs {VERSION}, or nothing can tell v03 from v02.');
    } else {
        // Placeholders upper-cased, so {shot} and {SHOT} are one thing.
        $up = fn($t) => preg_replace_callback('/\{([^}]*)\}/', fn($m) => '{' . strtoupper($m[1]) . '}', $t);
        $codes = ['steps' => [], 'types' => []];
        foreach ((array)($_POST['step_code'] ?? []) as $id => $c) {
            $c = strtoupper(naming_compact((string)$c));
            if ($c !== '') $codes['steps'][(string)(int)$id] = mb_substr($c, 0, 12);
        }
        foreach ((array)($_POST['type_code'] ?? []) as $i => $c) {
            $type = (string)($_POST['type_name'][$i] ?? '');
            $c = strtoupper(naming_compact((string)$c));
            if ($type !== '' && $c !== '') $codes['types'][$type] = mb_substr($c, 0, 12);
        }
        $pdo->prepare(
            'INSERT INTO project_brief (project_id, naming_shots, naming_assets, naming_codes, naming_first_version, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE naming_shots = VALUES(naming_shots), naming_assets = VALUES(naming_assets),
               naming_codes = VALUES(naming_codes), naming_first_version = VALUES(naming_first_version),
               updated_by = VALUES(updated_by)'
        )->execute([
            $projectId, $up($shots) ?: null, $up($assets) ?: null,
            json_encode($codes, JSON_UNESCAPED_UNICODE),
            ((string)($_POST['naming_first_version'] ?? '1')) === '0' ? 0 : 1,
            current_user_display(),
        ]);
        flash('ok', 'Naming convention saved. New versions on this show are checked against it from now on.');
    }
    header('Location: ' . $selfUrl . '#naming');
    exit;
}

$brief = null; $files = [];
if ($ready) {
    $q = $pdo->prepare('SELECT * FROM project_brief WHERE project_id = ?');
    $q->execute([$projectId]);
    $brief = $q->fetch() ?: null;

    $f = $pdo->prepare('SELECT * FROM project_attachments WHERE project_id = ? ORDER BY created_at');
    $f->execute([$projectId]);
    $files = $f->fetchAll();
}

$hasAny = $brief && (
    $brief['summary'] || $brief['deliverables'] || $brief['reference_links']
    || $brief['client'] || $brief['notes']
);

$pageTitle = 'Brief · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'brief'); ?>
<h2 class="wizard-title">Brief <span class="muted">· <?= e($project['name']) ?></span></h2>

<?php if (!$ready): ?>
  <div class="empty-state">
    <p><strong>The brief needs a database update that hasn't been applied yet.</strong></p>
    <p class="muted">Migration <code>023_project_brief.sql</code> creates the two tables this
       screen reads.</p>
    <?php if (is_admin()): ?><p><a class="button" href="migrate.php">Review and apply it →</a></p><?php endif; ?>
  </div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<p class="lead">
  What the client asked for, in their terms, the thing every date, quota and breakdown on the
  other screens is ultimately derived from. Written as prose rather than a form, because every
  brief is shaped differently and a box that doesn't fit is a box people leave empty.
</p>

<?php if (!$hasAny): ?>
  <div class="flash flash-warn">
    Nothing recorded yet. This is worth ten minutes at the start of a show: it is the document
    somebody goes looking for in week six, usually when the person who received it is busy on
    something else.
  </div>
<?php endif; ?>

<?php if ($brief && !empty($brief['updated_at'])): ?>
  <p class="muted small">
    Last updated <?= e(substr((string)$brief['updated_at'], 0, 16)) ?><?php
      if (!empty($brief['updated_by'])): ?> by <?= e($brief['updated_by']) ?><?php endif; ?>.
  </p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="stack brief-form"
      data-ticket-form data-redirect="<?= e($selfUrl) ?>">
  <input type="hidden" name="save" value="1">

  <div class="row">
    <label>Client
      <input name="client" value="<?= e($brief['client'] ?? '') ?>" placeholder="e.g. Scopely"
             <?= can_edit() ? '' : 'disabled' ?>>
    </label>
    <label>Contact <span class="muted small">(who to call)</span>
      <input name="client_contact" value="<?= e($brief['client_contact'] ?? '') ?>"
             placeholder="Name, email or phone" <?= can_edit() ? '' : 'disabled' ?>>
    </label>
  </div>

  <label>The ask
    <textarea name="summary" rows="6" placeholder="What the client wants, in their words."
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['summary'] ?? '') ?></textarea>
    <small>The brief itself. Paste the email if that is what arrived.</small>
  </label>

  <label>Deliverables
    <textarea name="deliverables" rows="5"
              placeholder="e.g. 6 × 30s spots, 1920×1080 ProRes 422 HQ, 23.976, stereo mix, captions as SRT"
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['deliverables'] ?? '') ?></textarea>
    <small>Formats, durations, resolutions, quantities, the half people re-read, and the half a
           disagreement is usually about.</small>
  </label>

  <label>Reference
    <textarea name="reference_links" rows="4" placeholder="Links to boards, look reference, previous work"
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['reference_links'] ?? '') ?></textarea>
  </label>

  <label>Notes
    <textarea name="notes" rows="3" placeholder="Anything agreed on a call, caveats, things to watch"
              <?= can_edit() ? '' : 'disabled' ?>><?= e($brief['notes'] ?? '') ?></textarea>
  </label>

  <?php if (can_edit()): ?>
    <label>Attach files
      <input type="file" name="attachments[]" multiple data-filepicker
             accept="image/png,image/jpeg,image/gif,image/webp,video/mp4,video/quicktime,video/webm,application/pdf">
      <small>The client's PDF, a reference board, a signed scope. Up to
             <?= (int)($config['upload_max_files'] ?? 5) ?> files, max
             <?= human_size((int)($config['upload_max_bytes'] ?? 26214400)) ?> each.</small>
    </label>
    <div class="file-list" data-filelist></div>
    <div class="upload-progress" data-progress style="display:none;">
      <div class="progress-track"><div class="progress-fill"></div></div>
      <div class="progress-label muted small">Preparing…</div>
    </div>
    <div class="actions"><button type="submit">Save brief</button></div>
  <?php endif; ?>
</form>

<?php if ($files): ?>
  <h3>Files <span class="muted">· <?= count($files) ?></span></h3>
  <div class="attachment-grid">
    <?php foreach ($files as $a):
      $url = 'attachment.php?kind=project&amp;id=' . (int)$a['id'];
      $name = e($a['original_name']);
      $canDelete = can_edit() && (is_admin() || (int)$a['uploaded_by'] === $userId);
    ?>
      <div class="attachment">
        <?php if (attachment_is_image($a['mime_type'])): ?>
          <a href="<?= $url ?>" class="att-zoom" data-name="<?= $name ?>" target="_blank" rel="noopener">
            <img src="<?= $url ?>" alt="<?= $name ?>"></a>
        <?php elseif (attachment_is_video($a['mime_type'])): ?>
          <video src="<?= $url ?>" controls preload="metadata"></video>
        <?php else: ?>
          <a href="<?= $url ?>" class="file-link" target="_blank" rel="noopener">📄 <?= $name ?></a>
        <?php endif; ?>
        <div class="attachment-meta">
          <span title="<?= $name ?>"><?= $name ?></span>
          <span class="muted small"><?= e(human_size((int)$a['file_size'])) ?></span>
          <?php if ($canDelete): ?>
            <form method="post" onsubmit="return confirm('Remove this file?');">
              <button type="submit" name="delete_attachment" value="<?= (int)$a['id'] ?>" class="link-danger">×</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($namingReady):
    // What the section draws from: the convention, every step with its code,
    // every asset type on the show with its code, and one worked example of
    // each template on a real task so the rule can be read as a file name.
    $naming = naming_settings($pdo, $projectId);
    $st = $pdo->prepare('SELECT id, name, applies_to, applies_to_asset_type FROM pipeline_steps
                          WHERE project_id = ? ORDER BY step_order, id');
    $st->execute([$projectId]);
    $namingSteps = $st->fetchAll();
    $ty = $pdo->prepare("SELECT DISTINCT asset_type FROM assets WHERE project_id = ? AND COALESCE(asset_type, '') <> ''
                          ORDER BY asset_type");
    $ty->execute([$projectId]);
    $namingTypes = $ty->fetchAll(PDO::FETCH_COLUMN);

    $examples = [];
    $me = naming_initials(current_user_display());
    foreach (['shot' => 's', 'asset' => 'a'] as $applies => $kind) {
        foreach ($namingSteps as $s) {
            if ($s['applies_to'] !== $applies) continue;
            $sql = $kind === 's'
                ? 'SELECT id FROM shots WHERE project_id = ? ORDER BY episode, shot_code LIMIT 1'
                : "SELECT id FROM assets WHERE project_id = ?"
                  . (trim((string)$s['applies_to_asset_type']) !== '' ? ' AND asset_type = ?' : '') . ' ORDER BY name LIMIT 1';
            $q = $pdo->prepare($sql);
            $q->execute(trim((string)$s['applies_to_asset_type']) !== '' && $kind === 'a'
                        ? [$projectId, $s['applies_to_asset_type']] : [$projectId]);
            $id = $q->fetchColumn();
            if (!$id) continue;
            $rule = naming_rule($pdo, $projectId, (int)$s['id'], $kind . $id);
            if ($rule) {
                $examples[$applies] = ['step' => $s['name'], 'name' => naming_example($rule, 1, $me)];
            }
            break;
        }
    }
    $mayNaming = can_edit();
?>
  <h3 id="naming">Naming convention</h3>
  <p class="muted small">
    How every file on this show is named, as a rule Anima checks. When somebody uploads a new
    version on Spell, the file name must follow this and carry the next version number, or the
    upload is refused and the expected name is shown. Leave a template empty and that kind of
    item is not checked.
  </p>

  <form method="post" class="stack naming-form">
    <input type="hidden" name="save_naming" value="1">
    <label>Shots
      <input name="naming_shots" value="<?= e($naming['shots']) ?>" <?= $mayNaming ? '' : 'disabled' ?>
             placeholder="{PROJECT}_{SHOT}_{STEP}_V{VERSION}_{INITIALS}">
    </label>
    <label>Assets
      <input name="naming_assets" value="<?= e($naming['assets']) ?>" <?= $mayNaming ? '' : 'disabled' ?>
             placeholder="{PROJECT}_{TYPE}_{ASSET}_{STEP}_V{VERSION}_{INITIALS}">
    </label>
    <?php if ($mayNaming): ?>
      <?php // The three shapes Mana's own documents use (MSD, FCM, STFC2). ?>
      <p class="naming-presets small">Start from Mana's standard:
        <button type="button" class="linkish" data-naming-preset="shots"
                data-value="{PROJECT}_{SHOT}_{STEP}_V{VERSION}_{INITIALS}">Shots</button> ·
        <button type="button" class="linkish" data-naming-preset="shots"
                data-value="{PROJECT}_{EPISODE}_{SHOT}_{STEP}_V{VERSION}_{INITIALS}">Shots with episodes</button> ·
        <button type="button" class="linkish" data-naming-preset="assets"
                data-value="{PROJECT}_{TYPE}_{ASSET}_{STEP}_V{VERSION}_{INITIALS}">Assets</button>
      </p>
    <?php endif; ?>
    <label>The first version is called
      <select name="naming_first_version" <?= $mayNaming ? '' : 'disabled' ?>>
        <option value="0" <?= $naming['first'] === 0 ? 'selected' : '' ?>>V00</option>
        <option value="1" <?= $naming['first'] === 1 ? 'selected' : '' ?>>V01</option>
      </select>
      <small class="muted">Anima counts v01 first; if this show's files start at V00, every check is offset by one.</small>
    </label>

    <details class="naming-help">
      <summary>Placeholders</summary>
      <ul class="small">
        <?php foreach (naming_tokens() as $tok => $what): ?>
          <li><code>{<?= e($tok) ?>}</code> <?= e($what) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="muted small">Capitals or not does not matter, and the file's extension (.png, .mp4) is ignored.</p>
    </details>

    <?php if ($namingSteps): ?>
      <h4>Step codes</h4>
      <p class="muted small">What {STEP} becomes for each step. Guessed from the name until you type one.</p>
      <div class="naming-codes">
        <?php foreach ($namingSteps as $s):
          $saved = (string)($naming['codes']['steps'][(string)$s['id']] ?? ''); ?>
          <label><span><?= e($s['name']) ?> <span class="muted small">· <?= e(applies_to_phrase((string)$s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?></span></span>
            <input name="step_code[<?= (int)$s['id'] ?>]" value="<?= e($saved) ?>" maxlength="12"
                   placeholder="<?= e(naming_default_code((string)$s['name'])) ?>" <?= $mayNaming ? '' : 'disabled' ?>>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($namingTypes): ?>
      <h4>Asset type codes</h4>
      <p class="muted small">What {TYPE} becomes for each kind of asset on this show.</p>
      <div class="naming-codes">
        <?php foreach ($namingTypes as $i => $type):
          $saved = (string)($naming['codes']['types'][$type] ?? ''); ?>
          <label><span><?= e($type) ?></span>
            <input type="hidden" name="type_name[<?= (int)$i ?>]" value="<?= e($type) ?>">
            <input name="type_code[<?= (int)$i ?>]" value="<?= e($saved) ?>" maxlength="12"
                   placeholder="<?= e(naming_default_code((string)$type)) ?>" <?= $mayNaming ? '' : 'disabled' ?>>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($examples): ?>
      <div class="naming-examples">
        <?php foreach ($examples as $applies => $ex): ?>
          <p><span class="muted small">A <?= e($applies) ?>, first version of <?= e($ex['step']) ?>:</span>
            <code><?= e($ex['name']) ?></code></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($mayNaming): ?>
      <div class="actions"><button type="submit">Save naming convention</button></div>
    <?php endif; ?>
  </form>
  <script>
  // The preset links fill their template box; nothing is saved until Save.
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-naming-preset]') : null;
    if (!b) return;
    var box = document.querySelector('input[name="naming_' + b.getAttribute('data-naming-preset') + '"]');
    if (box) { box.value = b.getAttribute('data-value'); box.focus(); }
  });
  </script>
<?php endif; ?>

<p class="muted small">
  Files live in the same protected directory as ticket attachments, behind the same login and
  the same checks, never on a guessable public URL. Contracts and rates are deliberately
  <em>not</em> here: those are in the finance database, reachable from a person's page and only
  by an administrator.
</p>

<script>
window.TICKET_MAX_FILES = <?= (int)($config['upload_max_files'] ?? 5) ?>;
window.TICKET_MAX_BYTES = <?= (int)($config['upload_max_bytes'] ?? 26214400) ?>;
</script>
<script src="assets/tickets.js<?= asset_ver('tickets.js') ?>"></script>
</div>
</body></html>
