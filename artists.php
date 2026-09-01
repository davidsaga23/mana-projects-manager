<?php
// The artist database — everybody ever entered as a candidate, across every show.
//
// This data already existed and had nowhere to be read. Every name typed into
// "Add candidates" on the Hiring screen goes into `hires`, and known_candidates()
// has always gathered them across projects — but only to feed the autocomplete
// that stops you entering the same person twice. David, 2026-08-31: "where can
// I see all the artist database I'm filling in the add candidates under hiring?"
// Nowhere, until now.
//
// STUDIO-WIDE, like the step library and the ideal quotas. A freelancer is not
// the property of the show that first spoke to them; the whole value of writing
// them down is that the next show can find them.
//
// ONE ROW PER PERSON, not per approach. `hires` records a conversation about a
// SLOT, so a person approached for three jobs is three rows — see DECISIONS,
// 2026-08-17, where the duplication was accepted deliberately rather than
// normalised into a separate freelancers table. Their details are taken from the
// most recent of those rows, because that is the version most recently checked,
// and every approach is listed underneath.
//
// NOT admin-only: this is planning data, the same as the crew list. What a
// candidate QUOTED is not here — that lives in the finance database behind
// includes/money.php and is only ever shown on the Hiring screen.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hiring.php';
require_login();

$ready = hiring_available($pdo);

// The optional columns arrive with migration 025 and are read only where they
// exist, so this screen works on a database that has 024 and not 025.
$extra = [];
foreach (['location', 'position', 'seniority', 'project_type', 'worked_with_us', 'rating'] as $c) {
    if (column_exists($pdo, 'hires', $c)) $extra[] = $c;
}

$q = trim((string)($_GET['q'] ?? ''));

$people = [];
if ($ready) {
    // Latest row per person, by lowercased name — the same matching rule
    // known_candidates() uses, and deliberately not fuzzy: a silent near-match
    // is the thing this codebase keeps refusing to do.
    $cols = 'h.id, h.candidate_name, h.email, h.phone, h.portfolio_url, h.source, h.notes,
             h.stage, h.project_id, h.step_name';
    foreach ($extra as $c) $cols .= ', h.`' . $c . '`';

    $sql = "SELECT {$cols}, p.code AS project_code, p.name AS project_name
              FROM hires h
              JOIN (SELECT MAX(id) AS id FROM hires GROUP BY LOWER(candidate_name)) latest
                   ON latest.id = h.id
              LEFT JOIN projects p ON p.id = h.project_id";
    $args = [];
    if ($q !== '') {
        // Searched across the fields somebody would actually remember a person
        // by. LIKE with the term bound, never interpolated.
        $like = '%' . $q . '%';
        $where = ['h.candidate_name LIKE ?', 'h.email LIKE ?', 'h.source LIKE ?', 'h.notes LIKE ?'];
        $args  = [$like, $like, $like, $like];
        foreach (['location', 'position'] as $c) {
            if (in_array($c, $extra, true)) { $where[] = 'h.`' . $c . '` LIKE ?'; $args[] = $like; }
        }
        $sql .= ' WHERE (' . implode(' OR ', $where) . ')';
    }
    $sql .= ' ORDER BY h.candidate_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $people = $stmt->fetchAll();

    // Every approach for the people on screen, in one query rather than one per
    // row: two hundred artists would otherwise be two hundred round trips.
    $names = array_map(function ($p) { return mb_strtolower(trim($p['candidate_name'])); }, $people);
    $approaches = [];
    if ($names) {
        $in = implode(',', array_fill(0, count($names), '?'));
        $ap = $pdo->prepare(
            "SELECT h.candidate_name, h.stage, h.step_name, h.project_id,
                    p.code AS project_code
               FROM hires h
               LEFT JOIN projects p ON p.id = h.project_id
              WHERE LOWER(h.candidate_name) IN ({$in})
              ORDER BY h.id DESC"
        );
        $ap->execute($names);
        foreach ($ap as $r) {
            $approaches[mb_strtolower(trim($r['candidate_name']))][] = $r;
        }
    }
}

$pageTitle = 'Artists';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Artists <span class="muted">· everyone ever put up for a job</span></h2>

<p class="lead">
  Built from what you type into <strong>Add candidates</strong> on any project's Hiring screen —
  this is the same list that already stops you entering somebody twice, now readable.
  One row per person, taking their details from the most recent time they were entered, with
  every job they have been up for underneath.
</p>

<?php if (!$ready): ?>
  <div class="flash flash-err">
    This screen needs the hiring database updates (migration <strong>024</strong>).
    <?= is_admin() ? '<a href="migrate.php">Apply them →</a>' : 'Ask an administrator to run them.' ?>
  </div>
<?php else: ?>

  <?php if (!$extra): ?>
    <div class="flash flash-warn">
      Migration <strong>025</strong> has not been applied, so location, position, seniority,
      project type, worked-with-us and rating are not stored yet. Everything else is shown.
    </div>
  <?php endif; ?>

  <form method="get" class="inline-form">
    <input name="q" value="<?= e($q) ?>" size="30"
           placeholder="Search name, email, source, notes<?= $extra ? ', location, position' : '' ?>">
    <button type="submit" class="secondary">Search</button>
    <?php if ($q !== ''): ?>
      <a href="artists.php">Clear</a>
    <?php endif; ?>
    <span class="muted small">
      <?= count($people) ?> <?= count($people) === 1 ? 'person' : 'people' ?><?php
        if ($q !== ''): ?> matching “<?= e($q) ?>”<?php endif; ?>
    </span>
  </form>

  <?php if (!$people): ?>
    <div class="empty-state">
      <h2><?= $q !== '' ? 'Nobody matches that' : 'Nobody entered yet' ?></h2>
      <p><?= $q !== ''
            ? 'Try a shorter search — matching is on whole words as typed, never fuzzy.'
            : 'Open a project\'s Hiring screen and add candidates; they collect here automatically.' ?></p>
    </div>
  <?php else: ?>
    <div class="grid-scroll">
      <table class="artist-table">
        <thead>
          <tr>
            <th>Name</th>
            <?php if (in_array('position', $extra, true)): ?><th>Position</th><?php endif; ?>
            <?php if (in_array('seniority', $extra, true)): ?><th>Seniority</th><?php endif; ?>
            <?php if (in_array('location', $extra, true)): ?><th>Location</th><?php endif; ?>
            <?php if (in_array('project_type', $extra, true)): ?><th>Type</th><?php endif; ?>
            <?php if (in_array('rating', $extra, true)): ?><th>Rating</th><?php endif; ?>
            <th>Contact</th>
            <th>Where from</th>
            <th>Up for</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($people as $p): $key = mb_strtolower(trim($p['candidate_name'])); ?>
          <tr>
            <td>
              <strong><?= e($p['candidate_name']) ?></strong>
              <?php if (!empty($p['worked_with_us'])): ?>
                <span class="ready-tag">worked with us</span>
              <?php endif; ?>
              <?php if (!empty($p['notes'])): ?>
                <div class="muted small"><?= e($p['notes']) ?></div>
              <?php endif; ?>
            </td>
            <?php if (in_array('position', $extra, true)): ?>
              <td><?= e($p['position'] ?? '') ?></td><?php endif; ?>
            <?php if (in_array('seniority', $extra, true)): ?>
              <td><?= e($p['seniority'] ?? '') ?></td><?php endif; ?>
            <?php if (in_array('location', $extra, true)): ?>
              <td><?= e($p['location'] ?? '') ?></td><?php endif; ?>
            <?php if (in_array('project_type', $extra, true)): ?>
              <td><?= e($p['project_type'] ?? '') ?></td><?php endif; ?>
            <?php if (in_array('rating', $extra, true)): ?>
              <td class="num"><?= $p['rating'] !== null && $p['rating'] !== ''
                    ? str_repeat('★', max(0, min(5, (int)$p['rating']))) : '<span class="muted">—</span>' ?></td>
            <?php endif; ?>
            <td class="small">
              <?php if (!empty($p['email'])): ?>
                <a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a><br>
              <?php endif; ?>
              <?= e($p['phone'] ?? '') ?>
              <?php if (!empty($p['portfolio_url'])): ?>
                <?php // rel=noopener because these are links somebody typed. ?>
                <br><a href="<?= e($p['portfolio_url']) ?>" target="_blank" rel="noopener">reel</a>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= e($p['source'] ?? '') ?></td>
            <td class="small">
              <?php // Every approach, newest first — the point of a shared list
                    // is seeing that Layout already spoke to this person on
                    // another show before you approach them again. ?>
              <?php foreach (($approaches[$key] ?? []) as $a): ?>
                <div>
                  <?php if (!empty($a['project_id'])): ?>
                    <a href="hiring.php?project=<?= (int)$a['project_id'] ?>"><?= e($a['project_code'] ?: 'project') ?></a>
                  <?php else: ?>
                    <span class="muted">no project</span>
                  <?php endif; ?>
                  <?= e($a['step_name'] ?: '') ?>
                  <span class="muted">· <?= e(hire_stage_label($a['stage'])) ?></span>
                </div>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>

</div>
</body></html>
