<?php
// Wizard step 6 — estimated dates and headcount per step.
//
// This is what the review screen turns into a quota, and what the scheduler
// will later use for its first pass. Two numbers matter most:
//
//   days per item  — how long ONE shot or asset takes one person
//   headcount      — how many people are on that step
//
// Headcount is an estimate of the team, never "how many things run at once".
// Conflating the two on STFC serialised 188 shots into nine months.

function wizard_save_planning(PDO $pdo, ?array $project): string {
    $projectId = (int)$project['id'];
    $action = $_POST['action'] ?? 'save';

    if ($action === 'spread') {
        $start = $project['start_date'] ?: date('Y-m-d');
        $end   = $project['delivery_date'];
        if (!$end) {
            throw new RuntimeException('Set a delivery date on the Basics screen first — ' .
                                       'there is nothing to spread the steps across.');
        }
        if ($end < $start) throw new RuntimeException('The delivery date is before the start date.');

        $days = working_days_list($start, $end);
        if (!$days) throw new RuntimeException('There are no working days between those dates.');

        $steps   = pipeline_steps($pdo, $projectId);
        $depsMap = step_dependencies_map($pdo, $projectId);
        $upd = $pdo->prepare('UPDATE pipeline_steps SET start_date = ?, deadline = ? WHERE id = ? AND project_id = ?');

        // Group by how deep into the graph each step sits. Everything at the same
        // depth genuinely runs at the same time, so it shares a slot — and this
        // works across chains, which per-chain clustering could not.
        $levels = workflow_levels($steps, $depsMap);
        $byLevel = [];
        foreach ($steps as $s) $byLevel[$levels[(int)$s['id']] ?? 0][] = $s;
        ksort($byLevel);

        $touched = 0;
        $n = count($days);
        $c = max(1, count($byLevel));
        $i = 0;
        foreach ($byLevel as $group) {
            $startIdx = (int)floor($n * $i / $c);
            $endIdx   = max($startIdx, (int)floor($n * ($i + 1) / $c) - 1);
            foreach ($group as $step) {
                $upd->execute([$days[$startIdx], $days[$endIdx], $step['id'], $projectId]);
                $touched++;
            }
            $i++;
        }
        flash('ok', "Spread $touched steps across $c stage" . ($c === 1 ? '' : 's') .
                    " between $start and $end. Steps that run at the same time share dates. " .
                    'Adjust anything below.');
        return wizard_url($projectId, 6);
    }

    // A deliberate re-plan. Never automatic: the value of a baseline is that it
    // says what was believed at a particular moment, and one that silently
    // followed every edit would say nothing at all.
    if ($action === 'rebaseline') {
        // A project that was already running when baselines arrived has no
        // original to revise, and its numbers have been edited since it started.
        // Labelling that first capture "re-planned" would imply an earlier
        // estimate existed; saying so plainly keeps it from ever being read as
        // what the project actually set out with.
        $isFirst = last_baseline($pdo, $projectId) === null;
        $reason  = trim($_POST['rebaseline_reason'] ?? '');
        if ($reason === '') $reason = $isFirst ? 'first record — already running' : 're-planned';

        $n = capture_baseline($pdo, $projectId, $reason);
        flash('ok', $isFirst
            ? "Recorded today's estimates for $n step" . ($n === 1 ? '' : 's') . '. This project ' .
              'was already running, so these are current numbers rather than what it started ' .
              'with — the record says so, and future projects get a true starting estimate.'
            : "Recorded today's estimates for $n step" . ($n === 1 ? '' : 's') .
              '. The previous baseline is kept — the history now shows both, so a revision ' .
              'reads as a revision rather than replacing what came before.');
        return wizard_url($projectId, 6);
    }

    $steps = pipeline_steps($pdo, $projectId);
    $upd = $pdo->prepare(
        'UPDATE pipeline_steps
            SET start_date = ?, deadline = ?, days_per_unit = ?, coverage_pct = ?, planned_headcount = ?
          WHERE id = ? AND project_id = ?'
    );
    $saved = 0; $warnings = [];
    foreach ($steps as $s) {
        $id = (int)$s['id'];
        $start    = trim($_POST['start_date'][$id] ?? '') ?: null;
        $deadline = trim($_POST['deadline'][$id] ?? '') ?: null;
        $dpu      = (float)($_POST['days_per_unit'][$id] ?? 1);
        // A whole-project step has no coverage input, so it keeps whatever it has.
        $coverage = isset($_POST['coverage_pct'][$id])
            ? min(100, max(0, (float)$_POST['coverage_pct'][$id]))
            : (float)($s['coverage_pct'] ?? 100);
        $headRaw  = trim($_POST['planned_headcount'][$id] ?? '');
        // Fractional on purpose: someone splitting a week across two shows is 0.5.
        $head     = $headRaw === '' ? null : max(0, (float)$headRaw);

        if ($dpu <= 0) $dpu = 1.0;
        if ($start && $deadline && $deadline < $start) {
            $warnings[] = $s['name'] . ': the deadline is before the start date, so it was left unchanged.';
            continue;
        }
        if ($deadline && $project['delivery_date'] && $deadline > $project['delivery_date']) {
            $warnings[] = $s['name'] . ': the deadline is after the project delivery date.';
        }
        $upd->execute([$start, $deadline, $dpu, $coverage, $head, $id, $projectId]);
        $saved++;
    }

    flash('ok', "Saved dates and headcount for $saved step" . ($saved === 1 ? '' : 's') . '.');
    foreach ($warnings as $w) flash('warn', $w);

    if ($action === 'continue') return wizard_next_url($projectId, 6);
    return wizard_url($projectId, 6);
}

function wizard_render_planning(PDO $pdo, ?array $project): void {
    // The studio's ideal for THIS kind of project, shown beside each estimate.
    // Read-only here on purpose: the base sheet is edited on its own screen, and
    // a number that could be changed from two places would stop being a standard.
    require_once __DIR__ . '/../standards.php';
    $idealSheet = standards_available($pdo)
        ? standards_for_type($pdo, (string)($project['type'] ?? ''))
        : [];
    $projectId = (int)$project['id'];
    $steps = pipeline_steps($pdo, $projectId);

    if (!$steps) {
        ?>
        <p class="lead">No pipeline steps yet.</p>
        <p><a href="<?= e(wizard_url($projectId, 2)) ?>">← Add the pipeline steps first</a></p>
        <?php
        return;
    }
    ?>
    <p class="lead">Rough estimates are fine — this seeds the first timeline and the quota on
       the next screen. Everything stays editable once the project is running.</p>

    <p class="summary-line">
      Project runs <strong><?= e($project['start_date'] ?: 'not set') ?></strong>
      to <strong><?= e($project['delivery_date'] ?: 'not set') ?></strong>
      <?php if ($project['start_date'] && $project['delivery_date']): ?>
        · <?= working_days_between($project['start_date'], $project['delivery_date']) ?> working days
      <?php endif; ?>
    </p>

    <form method="post" class="inline-form">
      <input type="hidden" name="action" value="spread">
      <button type="submit" class="secondary">Spread the steps evenly across those dates</button>
      <small>A starting point: each stage gets an equal slice, and parallel steps share dates.</small>
    </form>

    <?php
    // What this project committed to, and how far today's numbers have moved
    // from it. Drift is not an error — plans change — but it should be a thing
    // somebody decided, not something that happened.
    $baseline = last_baseline($pdo, $projectId);
    $drift    = $baseline ? baseline_drift($pdo, $projectId) : [];
    ?>
    <div class="inline-form toggle-form">
      <?php if ($baseline): ?>
        <span>
          Starting estimate recorded
          <strong><?= e(substr((string)$baseline['captured_at'], 0, 10)) ?></strong>
          <span class="muted">(<?= e($baseline['reason']) ?>, <?= (int)$baseline['steps'] ?> steps)</span>
        </span>
      <?php else: ?>
        <span class="warn-text">No starting estimate recorded for this project yet.</span>
      <?php endif; ?>

      <form method="post"
            onsubmit="return confirm('Record today\'s estimates as a new baseline? The previous one is kept.');">
        <input type="hidden" name="action" value="rebaseline">
        <input name="rebaseline_reason" placeholder="Why — e.g. scope change" size="22">
        <button type="submit" class="secondary"><?= $baseline ? 'Re-baseline' : 'Record the starting estimate' ?></button>
      </form>

      <small>
        Editing the numbers below never changes what was recorded — that is the point.
        Comparing the two is how next project's estimates get better
        <?php if ($drift): ?>
          · <strong><?= count($drift) ?></strong> step<?= count($drift) === 1 ? '' : 's' ?>
          now differ<?= count($drift) === 1 ? 's' : '' ?> from the baseline
        <?php endif; ?>
      </small>
    </div>

    <?php if ($drift): ?>
      <details class="inline-actions">
        <summary><?= count($drift) ?> step<?= count($drift) === 1 ? '' : 's' ?>
          changed since the estimate was recorded</summary>
        <table class="review-table">
          <tr><th>Step</th><th>What changed</th></tr>
          <?php foreach ($drift as $d): ?>
            <tr>
              <td class="wf-name">
                <span class="dot" style="background:<?= e($d['step']['color'] ?: '#ccc') ?>"></span>
                <?= e($d['step']['name']) ?>
              </td>
              <td class="muted small">
                <?php
                $labels = ['days_per_unit' => 'days per item', 'coverage_pct' => 'covers',
                           'planned_headcount' => 'people'];
                $bits = [];
                foreach ($labels as $key => $label) {
                    if (!isset($d[$key])) continue;
                    [$was, $now] = $d[$key];
                    $bits[] = $label . ': ' . ($was === null ? '—' : fmt_num($was))
                            . ' → ' . ($now === null ? '—' : fmt_num($now));
                }
                echo e(implode(' · ', $bits));
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="muted small">
          Nothing here is wrong — plans move. Re-baseline when the change is a decision you want
          recorded, and leave it when you are still adjusting.
        </p>
      </details>
    <?php endif; ?>

    <?php
    // A step scoped to an asset type that is ALSO spelled another way counts only
    // its own spelling. That is where this screen's "1.0 Environment assets"
    // against seven environments came from, and nothing here said so.
    $similar = similar_asset_types($pdo, $projectId);
    $scoped  = [];
    foreach ($steps as $s) {
        $t = $s['applies_to_asset_type'] ?? '';
        if ($t !== '') $scoped[mb_strtolower(trim($t))] = $t;
    }
    foreach ($similar as $spellings):
        // Only worth raising here if a step actually points at one of them.
        $hit = null;
        foreach (array_keys($spellings) as $name) {
            if (isset($scoped[mb_strtolower(trim((string)$name))])) { $hit = (string)$name; break; }
        }
        if ($hit === null) continue;
        $others = array_diff_key($spellings, [$hit => true]);
        $missed = array_sum($others);
    ?>
      <div class="flash flash-warn">
        A step is scoped to <strong>"<?= e($hit) ?>"</strong>, but
        <strong><?= $missed ?></strong> asset<?= $missed === 1 ? ' is' : 's are' ?> typed
        <?= e(implode(' or ', array_map(function ($n) { return '"' . $n . '"'; }, array_keys($others)))) ?>
        instead — so <?= $missed === 1 ? 'it is' : 'they are' ?> not counted in the work below,
        and every date and headcount here is short by that much.
        <a href="<?= e(wizard_url($projectId, 5)) ?>">Fix it on the Assets screen →</a>
      </div>
    <?php endforeach; ?>

    <?php $estimates = project_estimates($pdo, $projectId); ?>
    <form method="post">
      <button type="submit" name="action" value="save" hidden></button>
      <?php $retakePct = project_retake_pct($pdo, $projectId); ?>
      <table class="planning-table" id="planning-table" data-today="<?= e(date('Y-m-d')) ?>"
             data-retake="<?= e(fmt_num($retakePct)) ?>">
        <thead>
        <tr>
          <th>Step</th>
          <th title="How much of its scope this step actually touches. FX might only land on a third of the running time.">Covers</th>
          <th title="The amount of work the quota is calculated from, after the coverage percentage is applied.">Work</th>
          <th>Starts</th><th>Deadline</th>
          <th title="How long one shot or asset takes one person. Decimals are fine — 0.5 is half a day. On a step measured in seconds this is days per SECOND of footage, so a ten-second shot takes ten times it.">Days per item</th>
          <th title="How many people are on this step. Decimals are fine — 2.5 means someone is half-time.">People</th>
          <th title="How long the step actually takes at these settings: the work divided by the people on it. Compare it against the gap between Starts and Deadline.">Duration</th>
          <th>Studio ideal</th>
          <th>Is that enough?</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($steps as $s): $id = (int)$s['id'];
              $work = step_work_units($pdo, $projectId, $s, $estimates); ?>
        <?php
        // data-units is the FULL scope; coverage is applied by the live check
        // from the input beside it, so the two never double up. Keep the closing
        // tag on its own line: a closing PHP tag written inside a one-line
        // comment still ends PHP mode (lesson 9 in START_HERE).
        ?>
        <tr data-units="<?= e(fmt_num($work['units_raw'] ?? $work['units'], 3)) ?>"
            data-label="<?= e($work['label']) ?>"
            data-estimated="<?= !empty($work['estimated']) ? '1' : '0' ?>">
          <td class="wf-name">
            <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span>
            <?= e($s['name']) ?>
            <span class="muted small"><?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?><?= $s['tracks_seconds'] ? ' · seconds' : '' ?></span>
          </td>
          <td>
            <?php if ($s['applies_to'] === 'project'): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <span class="pct-field">
                <input type="number" name="coverage_pct[<?= $id ?>]" min="0" max="100" step="any"
                       value="<?= e(fmt_num($s['coverage_pct'] ?? 100)) ?>">%
              </span>
            <?php endif; ?>
          </td>
          <?php
          /* What the quota is actually computed from. Recalculated live as the
             coverage beside it changes. */
          ?>
          <td class="work-cell">
            <strong><?= e(fmt_rate((float)$work['units'])) ?></strong>
            <span class="muted"><?= e($work['label']) ?></span>
            <?php if (($work['coverage'] ?? 1) < 1): ?>
              <div class="muted small">of <?= e(fmt_rate((float)($work['units_raw'] ?? 0))) ?></div>
            <?php endif; ?>
            <?php if (!empty($work['estimated'])): ?>
              <div><span class="est-tag">est.</span></div>
            <?php endif; ?>
          </td>
          <td><input type="date" name="start_date[<?= $id ?>]" value="<?= e($s['start_date'] ?? '') ?>"></td>
          <td><input type="date" name="deadline[<?= $id ?>]" value="<?= e($s['deadline'] ?? '') ?>"></td>
          <?php /* step="any" rather than a fixed increment — real estimates land on
                   awkward numbers like 1.5 days or 2.5 people, and the browser
                   should not reject them. */ ?>
          <?php /* A step measured in seconds is measured in seconds here too: this
                    is days per SECOND of footage, not per shot. The quota has always
                    read it that way; the scheduler now turns it into dates, where
                    getting it backwards is the difference between a two-day task and
                    a twenty-day one. */ ?>
          <td><input type="number" name="days_per_unit[<?= $id ?>]" step="any" min="0.01"
                     value="<?= e(fmt_num($s['days_per_unit'] ?? 1)) ?>">
            <?php if ($s['tracks_seconds']): ?>
              <div class="muted small">per second</div>
            <?php endif; ?>
          </td>
          <td><input type="number" name="planned_headcount[<?= $id ?>]" min="0" step="any"
                     value="<?= e(fmt_num($s['planned_headcount'])) ?>"
                     placeholder="—"></td>
          <?php
          // The ideal for this step identity, if the studio has written one down.
          // It is NOT applied and NOT defaulted in — a standard that quietly
          // overwrote somebody's estimate would stop being a reference and start
          // being a decision nobody made. Reported, and a person decides; the same
          // rule the duplicate asset types follow.
          $std = $idealSheet[standard_key($s['name'], $s['applies_to'] ?? 'shot',
                                          $s['applies_to_asset_type'] ?? '')] ?? null;
          $planned = (float)($s['days_per_unit'] ?: 0);
          ?>
          <td class="small">
            <?php if ($std === null): ?>
              <span class="muted">no standard</span>
            <?php else:
              $ideal = (float)$std['days_per_unit'];
              $ratio = ($ideal > 0 && $planned > 0) ? $planned / $ideal : null;
              // Faster than the studio standard is the direction worth flagging.
              // Slower is somebody being careful, which needs no warning.
              $cls = ($ratio !== null && $ratio < 0.95) ? 'err'
                   : (($ratio !== null && $ratio > 1.05) ? 'muted' : 'ok'); ?>
              <span class="<?= $cls ?>"><?= e(fmt_num($ideal, 2)) ?> d</span>
              <div class="muted small"><?= e(fmt_num(1 / max(0.001, $ideal), 2)) ?> a day</div>
              <?php if ($ratio !== null && $ratio < 0.95): ?>
                <div class="err small">
                  planned <?= e(fmt_num((1 - $ratio) * 100, 0)) ?>% faster
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <?php /* Filled by wizard.js, like the verdict beside it — both are
                   recalculated as you type, so rendering a number here on load
                   would only be right until the first keystroke. */ ?>
          <td class="duration"><span class="muted">—</span></td>
          <td class="verdict"><span class="muted">—</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="actions">
        <button type="submit" name="action" value="save" class="secondary">Save and stay here</button>
        <span class="hint">keeps your changes, leaves you on this screen</span>
        <span class="spacer"></span>
        <button type="submit" name="action" value="continue">Save and continue →</button>
      </div>
    </form>

    <p class="muted small">
      The check updates as you type — nothing is saved until you press a button. The daily
      rate is what the <em>whole team</em> must finish to hit the date; it doesn't fall when
      you add people, only each person's share does.
    </p>

    <script src="assets/wizard.js<?= asset_ver('wizard.js') ?>"></script>
    <?php
}
