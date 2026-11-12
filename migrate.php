<?php
// -----------------------------------------------------------------------------
// Migration runner.
// Compares migrations/*.sql against the schema_migrations table and applies
// whatever is pending, in filename order. Reports every file as applied,
// already-applied, or failed — nothing happens silently.
//
// Safe to open any time: with nothing pending it just shows a green "up to
// date". Migrations only ever ADD to the schema; install.sql (which drops
// everything) is the only destructive file, and this runner never touches it.
//
// Note: MySQL auto-commits DDL, so a failed file can leave its own changes
// half-applied — the report says exactly which statement failed so the fix is
// obvious. Files after a failure are not attempted.
//
// Since 2026-11-11 this page also applies the FINANCE database's
// finance_install_NN.sql files (second section, at the bottom). That runner
// lives in includes/money.php, the only file allowed to open the finance
// connection; this page only calls it. See the comment above
// finance_migration_checks() there for how hand-imported files are recognised.
// -----------------------------------------------------------------------------

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
// Changing the database schema is an administrator's job.
require_admin();

// Naive but sufficient splitter for our migration style (plain DDL/DML, no
// stored procedures): statements end with ';' at end of line. Strips '--'
// comment lines.
function split_sql_statements(string $sql): array {
    $lines = preg_split('/\R/', $sql);
    $stmts = [];
    $buf = '';
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $buf .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            $stmt = trim($buf);
            if ($stmt !== '' && $stmt !== ';') $stmts[] = $stmt;
            $buf = '';
        }
    }
    if (trim($buf) !== '') $stmts[] = trim($buf);
    return $stmts;
}

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

// results: [filename, status ('applied'|'skipped'|'failed'|'not attempted'), detail]
$results = [];
$failed = false;

$dryRun = !isset($_POST['run']);   // GET shows the plan; the button executes it

foreach ($files as $file) {
    $name = basename($file);
    if (isset($appliedSet[$name])) {
        $results[] = [$name, 'skipped', 'Already applied'];
        continue;
    }
    if ($dryRun) {
        $results[] = [$name, 'pending', 'Will be applied'];
        continue;
    }
    if ($failed) {
        $results[] = [$name, 'not attempted', 'Skipped because an earlier migration failed'];
        continue;
    }
    $sql = file_get_contents($file);
    $stmts = split_sql_statements($sql);
    $ok = true;
    $detail = count($stmts) . ' statement(s) executed';
    foreach ($stmts as $i => $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            $ok = false;
            $failed = true;
            $detail = 'Statement ' . ($i + 1) . ' failed: ' . $e->getMessage();
            break;
        }
    }
    if ($ok) {
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
        $results[] = [$name, 'applied', $detail];
    } else {
        $results[] = [$name, 'failed', $detail];
    }
}

$pendingCount = count(array_filter($results, function ($r) { return $r[1] === 'pending'; }));

// --- The FINANCE database (David, 2026-11-11) --------------------------------
// "why do I need to paste so many codes to the DB when we used to do it
// through the migration system?" The finance_install_NN.sql files now apply
// from here too. This page never opens the finance connection itself: rule 1
// at the top of includes/money.php says only that file may, so everything
// below is a question asked of it. Same shape as above: GET shows the plan,
// a button runs it, and the result lists every file and what happened to it.
require_once __DIR__ . '/includes/money.php';
$me = current_user();
$who = (string)($me['display_name'] ?? $me['username'] ?? '');
$finRun = null;       // the report of a finance run, when one just happened
$finMarkError = null; // why "mark as done" did not work, when it did not
if (isset($_POST['finance_run'])) {
    $finRun = finance_apply_migrations('split_sql_statements', $who);
    finance_pending_forget();
} elseif (isset($_POST['finance_mark'])) {
    $finMarkError = finance_mark_migration_done((string)$_POST['finance_mark'], $who);
    finance_pending_forget();
}
$fin = finance_migration_status();
$finWaiting = 0;
foreach ($fin['files'] ?? [] as $f) if ($f[1] !== 'applied') $finWaiting++;
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migrations · Anima | Project manager</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><a href="index.php" class="brand"><?= site_logo() ?><span class="brand-name">Anima <span class="muted">| Project manager</span></span></a></h1>
  </div>
  <p class="crumb"><a href="index.php">← Back to the projects list</a></p>
  <h2 class="wizard-title">Schema migrations</h2>

  <?php if (!$files): ?>
    <p class="ok">No migration files exist yet, the baseline schema is all there is. ✓</p>
  <?php elseif ($dryRun && $pendingCount === 0): ?>
    <p class="ok">✓ Database is up to date, <?= count($files) ?> migration file(s), all applied.</p>
  <?php elseif ($dryRun): ?>
    <p><strong><?= $pendingCount ?></strong> migration(s) pending. Review the list, then apply:</p>
    <form method="post"><button type="submit" name="run" value="1">Apply pending migrations</button></form>
  <?php else: ?>
    <p><strong>Run finished.</strong> Every file and what happened to it:</p>
  <?php endif; ?>

  <?php if ($results): ?>
  <table>
    <tr><th>File</th><th>Status</th><th>Detail</th></tr>
    <?php foreach ($results as [$name, $status, $detail]): ?>
    <tr class="mig-<?= e($status === 'not attempted' ? 'na' : $status) ?>">
      <td><code><?= e($name) ?></code></td>
      <td><?= e($status) ?></td>
      <td><?= e($detail) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <h2 class="wizard-title" id="finance">Finance database updates</h2>
  <p class="muted">The <code>finance_install_NN.sql</code> files, for the separate finance database
    (rates, budgets, invoices). They used to be pasted into phpMyAdmin by hand; they apply from here now.
    Ones you already imported by hand are recognised and recorded, never run twice.</p>

  <?php if ($finRun !== null && $finRun['error']): ?>
    <p class="flash flash-err"><?= e($finRun['error']) ?></p>
  <?php endif; ?>
  <?php if ($finMarkError !== null): ?>
    <p class="flash flash-err"><?= e($finMarkError) ?></p>
  <?php endif; ?>

  <?php if (!$fin): ?>
    <p class="muted">Only an administrator can see the finance database.</p>
  <?php elseif (!$fin['ok']): ?>
    <p class="flash flash-err"><?= e($fin['error'] ?? 'The finance database could not be read.') ?></p>
  <?php else: ?>
    <?php if (!$fin['baseline']): ?>
      <p class="flash flash-warn">The finance database does not have its first tables yet. Import
        <code>finance_install.sql</code> by hand once (it is part of setting finance up), then come back.</p>
    <?php elseif ($finWaiting > 0 && $fin['rights'] === false && ($finRun === null || !$finRun['error'])): ?>
      <p class="flash flash-warn"><?= e(finance_rights_message()) ?></p>
    <?php endif; ?>

    <?php if ($finRun !== null && $finRun['files']): ?>
      <p><strong>Run finished.</strong> Every file and what happened to it:</p>
      <table>
        <tr><th>File</th><th>Status</th><th>Detail</th></tr>
        <?php foreach ($finRun['files'] as [$name, $status, $detail]): ?>
        <tr class="mig-<?= e($status === 'not attempted' ? 'na' : ($status === 'recorded' ? 'applied' : $status)) ?>">
          <td><code><?= e($name) ?></code></td>
          <td><?= e($status) ?></td>
          <td><?= e($detail) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if (!$fin['files']): ?>
      <p class="ok">No finance update files exist. ✓</p>
    <?php elseif ($finWaiting === 0): ?>
      <p class="ok">✓ Finance database is up to date, <?= count($fin['files']) ?> update file(s), all applied.</p>
    <?php else: ?>
      <p><strong><?= $finWaiting ?></strong> finance update(s) waiting. Review the list, then apply:</p>
      <?php if ($fin['baseline'] && $fin['rights'] !== false): ?>
      <form method="post" action="migrate.php#finance"><button type="submit" name="finance_run" value="1">Apply finance updates</button></form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($fin['files'] && ($finRun === null || $finWaiting > 0)): ?>
    <table>
      <tr><th>File</th><th>Status</th><th>Detail</th><th></th></tr>
      <?php foreach ($fin['files'] as [$name, $state, $detail]):
            $label = ['applied' => 'applied', 'found' => 'imported by hand',
                      'pending' => 'waiting', 'partial' => 'needs a look'][$state] ?? $state;
            $cls = ['applied' => 'skipped', 'found' => 'pending',
                    'pending' => 'pending', 'partial' => 'failed'][$state] ?? 'na'; ?>
      <tr class="mig-<?= e($cls) ?>">
        <td><code><?= e($name) ?></code></td>
        <td><?= e($label) ?></td>
        <td><?= e($detail) ?></td>
        <td><?php if ($state === 'partial' || $state === 'pending'): ?>
          <form method="post" action="migrate.php#finance"
                onsubmit="return confirm('Record <?= e($name) ?> as done WITHOUT running it? Only do this if you already imported it by hand.');">
            <button type="submit" name="finance_mark" value="<?= e($name) ?>" class="linkish">Mark as done</button>
          </form>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body></html>
