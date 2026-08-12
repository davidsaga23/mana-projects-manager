<?php
// New Project setup wizard.
//
// Seven steps, each saving as it goes so the flow can be left and resumed —
// a project row exists from step 1 with status 'setup', and only becomes
// 'active' when the wizard is finished.
//
// Every step is POST -> save -> redirect -> GET, so a refresh never resubmits
// and every save gets a chance to report what it did through flash messages.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require __DIR__ . '/includes/quota.php';
require_login();

const WIZARD_STEPS = [
    1 => ['slug' => 'basics',   'label' => 'Basics'],
    2 => ['slug' => 'pipeline', 'label' => 'Pipeline'],
    3 => ['slug' => 'workflow', 'label' => 'Workflow'],
    4 => ['slug' => 'shots',    'label' => 'Shots'],
    5 => ['slug' => 'assets',   'label' => 'Assets'],
    6 => ['slug' => 'planning', 'label' => 'Dates & headcount'],
    7 => ['slug' => 'review',   'label' => 'Review'],
];
const WIZARD_LAST = 7;

function wizard_url(int $projectId, int $step): string {
    return 'wizard.php?project=' . $projectId . '&step=' . $step;
}

/** Where a "Save and continue" / "Save" button should land. */
function wizard_next_url(int $projectId, int $currentStep): string {
    if (isset($_POST['stay'])) return wizard_url($projectId, $currentStep);
    return wizard_url($projectId, min(WIZARD_LAST, $currentStep + 1));
}

/** Remember how far the wizard got, so index.php can offer to resume. */
function wizard_mark_progress(PDO $pdo, int $projectId, int $step): void {
    $furthest = (int)project_setting($pdo, $projectId, 'wizard_step', '1');
    if ($step > $furthest) set_project_setting($pdo, $projectId, 'wizard_step', (string)$step);
}

// The wizard needs the planning columns from migration 001. Checking here turns
// what would be a raw SQL error into a sentence saying exactly what to do.
$hasPlanningFields = (bool)$pdo->query("SHOW COLUMNS FROM pipeline_steps LIKE 'days_per_unit'")->fetch();
if (!$hasPlanningFields) {
    $pageTitle = 'Update needed';
    require __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><h2>One database update is needed first</h2>'
       . '<p>The setup wizard uses fields that arrived after the first install.</p>'
       . '<p><a class="button" href="migrate.php">Open the migration screen</a></p>'
       . '<p class="muted small">It shows what will change before applying anything.</p>'
       . '</div></div></body></html>';
    exit;
}

$stepNum = (int)($_GET['step'] ?? 1);
if (!isset(WIZARD_STEPS[$stepNum])) $stepNum = 1;

$projectId = (int)($_GET['project'] ?? 0);
$project = null;
if ($projectId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        flash('err', 'That project no longer exists.');
        header('Location: index.php');
        exit;
    }
}
// Every step after the first needs a project to attach to.
if (!$project && $stepNum > 1) {
    $stepNum = 1;
}

require __DIR__ . '/includes/wizard/' . WIZARD_STEPS[$stepNum]['slug'] . '.php';
$saveFn   = 'wizard_save_'   . WIZARD_STEPS[$stepNum]['slug'];
$renderFn = 'wizard_render_' . WIZARD_STEPS[$stepNum]['slug'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Discarding an unfinished draft is handled here so every step can offer it.
    if (isset($_POST['discard_draft']) && $project && $project['status'] === 'setup') {
        $del = $pdo->prepare('DELETE FROM projects WHERE id = ? AND status = ?');
        $del->execute([$project['id'], 'setup']);
        flash('ok', 'Discarded the unfinished draft "' . $project['name'] . '".');
        header('Location: index.php');
        exit;
    }

    try {
        $url = $saveFn($pdo, $project);
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
        $url = $project ? wizard_url((int)$project['id'], $stepNum) : 'wizard.php';
    }
    header('Location: ' . $url);
    exit;
}

if ($project) wizard_mark_progress($pdo, (int)$project['id'], $stepNum);

$pageTitle = $project ? 'Setup · ' . $project['name'] : 'New project';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">
  <?= $project ? e($project['name']) . ' <span class="muted">(' . e($project['code']) . ')</span>' : 'New project' ?>
</h2>

<ol class="wizard-nav">
<?php foreach (WIZARD_STEPS as $n => $s): ?>
  <?php
    $cls = $n === $stepNum ? 'current' : '';
    $clickable = $project !== null && $n !== $stepNum;
  ?>
  <li class="<?= $cls ?>">
    <?php if ($clickable): ?>
      <a href="<?= e(wizard_url((int)$project['id'], $n)) ?>"><span class="num"><?= $n ?></span> <?= e($s['label']) ?></a>
    <?php else: ?>
      <span class="num"><?= $n ?></span> <?= e($s['label']) ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ol>

<div class="wizard-body">
<?php $renderFn($pdo, $project); ?>
</div>

<?php if ($project && $project['status'] === 'setup'): ?>
<form method="post" class="discard-form"
      onsubmit="return confirm('Discard this unfinished project and everything entered so far? This cannot be undone.');">
  <button type="submit" name="discard_draft" value="1" class="link-danger">Discard this draft</button>
</form>
<?php endif; ?>

</div>
</body></html>
