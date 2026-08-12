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
?>
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migrations · Mana Production Platform</title>
<link rel="stylesheet" href="assets/style.css<?= asset_ver('style.css') ?>">
</head>
<body>
<div class="wrap">
  <h1>Schema migrations</h1>
  <p><a href="index.php">← Back to dashboard</a></p>

  <?php if (!$files): ?>
    <p class="ok">No migration files exist yet — the baseline schema is all there is. ✓</p>
  <?php elseif ($dryRun && $pendingCount === 0): ?>
    <p class="ok">✓ Database is up to date — <?= count($files) ?> migration file(s), all applied.</p>
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
</div>
</body></html>
