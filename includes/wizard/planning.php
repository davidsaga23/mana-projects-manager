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

    // The retake allowance, editable here as well as on Basics (David,
    // 2026-08-17). It is the one project-wide number that changes every figure
    // on this screen, and walking back to step 1 to try 5% instead of 10% is
    // enough friction that nobody does it.
    //
    // Saved before the action branches, so it lands whichever button was
    // pressed — including "spread", which posts the same form.
    //
    // ⚠ THE CAP IS THE SAME 200% BASICS APPLIES, deliberately duplicated rather
    // than trusted to one screen: a mistyped 1000 would silently multiply every
    // person-day on the project by eleven, and the two entry points must not
    // disagree about what is allowed (DECISIONS, 2026-08-12).
    if (isset($_POST['retake_pct'])) {
        $r = trim((string)$_POST['retake_pct']);
        set_project_setting($pdo, $projectId, 'retake_pct',
            (string)min(200, max(0, $r === '' ? 0 : (float)$r)));
    }

    if ($action === 'spread') {
        $start = $project['start_date'] ?: date('Y-m-d');
        $end   = $project['delivery_date'];
        if (!$end) {
            throw new RuntimeException('Set a delivery date on the Basics screen first, ' .
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
        if ($reason === '') $reason = $isFirst ? 'first record, already running' : 're-planned';

        $n = capture_baseline($pdo, $projectId, $reason);
        flash('ok', $isFirst
            ? "Recorded today's estimates for $n step" . ($n === 1 ? '' : 's') . '. This project ' .
              'was already running, so these are current numbers rather than what it started ' .
              'with, the record says so, and future projects get a true starting estimate.'
            : "Recorded today's estimates for $n step" . ($n === 1 ? '' : 's') .
              '. The previous baseline is kept, the history now shows both, so a revision ' .
              'reads as a revision rather than replacing what came before.');
        return wizard_url($projectId, 6);
    }

    $steps = pipeline_steps($pdo, $projectId);
    $upd = $pdo->prepare(
        'UPDATE pipeline_steps
            SET start_date = ?, deadline = ?, days_per_unit = ?, coverage_pct = ?, planned_headcount = ?
          WHERE id = ? AND project_id = ?'
    );
    require_once __DIR__ . '/../standards.php';
    $type  = (string)($project['type'] ?? '');
    $sheet = standards_available($pdo) ? standards_for_type($pdo, $type) : [];
    $idealChanges = [];
    $saved = 0; $warnings = [];
    foreach ($steps as $s) {
        $id = (int)$s['id'];
        $start    = trim($_POST['start_date'][$id] ?? '') ?: null;
        $deadline = trim($_POST['deadline'][$id] ?? '') ?: null;

        // ⚠ DAYS PER ITEM COMES FROM ONE OF TWO PLACES, AND IS SAVED BACK TO IT.
        //
        // Where the studio sheet has an ideal for this step, that ideal is what
        // the plan runs on, so it is what the screen shows and edits
        // (David, 2026-09-16). It is posted as ideal_days[] and written to the
        // STUDIO SHEET - never into this project's own column, which stays as
        // the fallback it has always been should the ideal ever be removed.
        //
        // Where there is no ideal, the project's own estimate drives the plan,
        // and it is posted as days_per_unit[] exactly as before.
        //
        // Only an administrator edits a studio ideal, here as on the Ideal quotas
        // sheet. The field is not even drawn for anybody else, and a crafted
        // post from an editor is ignored rather than trusted.
        $stdRow = $sheet[standard_key($s['name'], $s['applies_to'] ?? 'shot',
                                      $s['applies_to_asset_type'] ?? '')] ?? null;
        if ($stdRow !== null) {
            if (is_admin() && isset($_POST['ideal_days'][$id])) {
                try {
                    $was = update_standard_days($pdo, $type, $s, (string)$_POST['ideal_days'][$id],
                                                current_user_display());
                    if ($was !== null) {
                        $idealChanges[] = sprintf('%s from %s to %s',
                            step_display_name($s, $steps), fmt_num($was, 3),
                            fmt_num(round((float)$_POST['ideal_days'][$id], 3), 3));
                    }
                } catch (RuntimeException $e) {
                    $warnings[] = $e->getMessage();
                }
            }
            // The project's own number is left exactly as it was.
            $dpu = (float)($s['days_per_unit'] ?: 1);
        } else {
            $dpu = (float)($_POST['days_per_unit'][$id] ?? ($s['days_per_unit'] ?: 1));
        }
        // A whole-project step has no coverage input, so it keeps whatever it has.
        $coverage = isset($_POST['coverage_pct'][$id])
            ? min(100, max(0, (float)$_POST['coverage_pct'][$id]))
            : (float)($s['coverage_pct'] ?? 100);
        $headRaw  = trim($_POST['planned_headcount'][$id] ?? '');
        // Fractional on purpose: someone splitting a week across two shows is 0.5.
        $head     = $headRaw === '' ? null : max(0, (float)$headRaw);

        if ($dpu <= 0) $dpu = 1.0;
        // The same rules the step page applies, from the same function, so the
        // two places a deadline can be edited cannot disagree about which dates
        // are allowed. See step_deadline_check().
        $check = step_deadline_check((string)$s['name'], $start, $deadline,
                                     $project['delivery_date'] ?: null);
        if ($check['refuse']) {
            $warnings[] = $check['refuse'];
            continue;
        }
        if ($check['warn']) $warnings[] = $check['warn'];
        $upd->execute([$start, $deadline, $dpu, $coverage, $head, $id, $projectId]);
        $saved++;
    }

    flash('ok', "Saved dates and headcount for $saved step" . ($saved === 1 ? '' : 's') . '.');

    // ⚠ SAID IN FULL, because the reach of this is not visible from the screen it
    // was made on. A studio ideal changed from FC Mobile changes the plan and the
    // budget of every other project of the same type, and the person who made
    // the change is looking at one of them.
    if ($idealChanges) {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE type = ? AND status <> 'archived'");
        try { $cnt->execute([$type]); $n = (int)$cnt->fetchColumn(); }
        catch (PDOException $e) { $n = 0; }   // 'archived' arrives with migration 027
        flash('warn', 'Changed the studio ideal for ' . implode('; ', $idealChanges) . ' days per item. '
            . 'This is the ' . $type . ' base sheet, so it applies to every ' . $type . ' project'
            . ($n > 1 ? ', ' . $n . ' of them,' : '')
            . ' including their schedules and expense projections.');
    }
    foreach ($warnings as $w) flash('warn', $w);

    if ($action === 'continue') return wizard_next_url($projectId, 6);
    return wizard_url($projectId, 6);
}

function wizard_render_planning(PDO $pdo, ?array $project): void {
    // The studio's ideal for THIS kind of project.
    //
    // ⚠ THIS USED TO SAY "read-only here on purpose", and David reversed it on
    // 2026-09-16. The worry was that a number changeable from two places would
    // stop being a standard. What happened instead was worse: the Days per item
    // box stayed editable while the plan ignored it, so a typed 2 produced a
    // 3-day duration computed from the ideal's 0.5, with no visible reason.
    //
    // The ideal is now edited from here as well, and the worry is answered
    // rather than ignored: it is the SAME record, not a copy, through the SAME
    // writer (update_standard_days() -> save_standards()), logged the same way,
    // admin-only in both places, and the screen and the saved message both say it
    // reaches every project of the type.
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
    <p class="lead">Rough estimates are fine, this seeds the first timeline and the quota on
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
        <input name="rebaseline_reason" placeholder="Why, e.g. scope change" size="22">
        <button type="submit" class="secondary"><?= $baseline ? 'Re-baseline' : 'Record the starting estimate' ?></button>
      </form>

      <small>
        Editing the numbers below never changes what was recorded, that is the point.
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
          Nothing here is wrong, plans move. Re-baseline when the change is a decision you want
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
        instead, so <?= $missed === 1 ? 'it is' : 'they are' ?> not counted in the work below,
        and every date and headcount here is short by that much.
        <a href="<?= e(wizard_url($projectId, 5)) ?>">Fix it on the Assets screen →</a>
      </div>
    <?php endforeach; ?>

    <?php $estimates = project_estimates($pdo, $projectId); ?>
    <form method="post">
      <button type="submit" name="action" value="save" hidden></button>
      <?php $retakePct = project_retake_pct($pdo, $projectId); ?>
      <?php
      // ⚠ THE HOLIDAYS HAVE TO REACH THE BROWSER, or this screen lies.
      //
      // working_days_between() on the server subtracts non_working_days; the
      // live check in wizard.js only skipped weekends. So every window spanning
      // a studio holiday showed MORE available days here than the scheduler
      // would use — "1 is enough" on a plan the server thinks is short. The
      // error was always in the OPTIMISTIC direction, which is exactly what the
      // 2026-08-12 calendar entry calls the worst one for a deadline.
      //
      // Passed as a plain comma-separated list rather than JSON: it is a set of
      // dates, and a script block would be a second thing to keep in step.
      $holidayList = implode(',', array_keys(non_working_days($pdo)));
      ?>
      <?php
      // Above the table because it applies to every row in it. Everything below
      // — the minimum, the duration, the crew a date needs — moves when this
      // moves, which is exactly why it belongs where the effect is visible.
      ?>
      <p class="retake-bar">
        <label for="retake-pct"><strong>Retake allowance</strong></label>
        <input type="number" id="retake-pct" name="retake_pct" min="0" max="200" step="any"
               value="<?= e(fmt_num($retakePct)) ?>"> %
        <span class="muted small">
          added to the time each item takes, never to the number of items, 22 assets are still
          22 assets after a revision, they just take longer. Every figure below includes it, and
          the <strong>Retakes</strong> column shows what it comes to in days.
        </span>
      </p>

      <table class="planning-table" id="planning-table" data-today="<?= e(date('Y-m-d')) ?>"
             data-retake="<?= e(fmt_num($retakePct)) ?>"
             data-holidays="<?= e($holidayList) ?>">
        <?php
        // ⚠ WIDTHS ONLY BIND UNDER table-layout: fixed, which the stylesheet sets
        // for this table. With the default `auto` a browser reads `width` as a
        // suggestion and then ignores it in favour of the content — which is why
        // squeezing one column and widening another had no visible effect until
        // this colgroup existed.
        //
        // Twelve percentages, summing to 100. Tuned to David's reading of the
        // screen (2026-08-17): Step, Work and Days-per-item give space up;
        // Retakes, Duration and the verdict take it. Changing one means changing
        // another — that is the point of percentages, and the reason the sum is
        // written down here rather than discovered by trial.
        //
        //   Step 9 · Covers 6 · Work 6 · Dates 11 · Days/item 7 · People 5
        //   Minimum 8 · Retakes 9 · Duration 9 · Enough? 18 · Ideal 6 · Days 6
        ?>
        <colgroup>
          <col style="width:9%">   <?php // Step ?>
          <col style="width:6%">   <?php // Covers ?>
          <col style="width:6%">   <?php // Work ?>
          <col style="width:11%">  <?php // Dates ?>
          <col style="width:10%">  <?php // Days per item: the number, and whose it is ?>
          <col style="width:5%">   <?php // People ?>
          <col style="width:8%">   <?php // Minimum ?>
          <col style="width:9%">   <?php // Retakes ?>
          <col style="width:9%">   <?php // Duration ?>
          <col style="width:18%">  <?php // Is that enough? ?>
          <col style="width:9%">   <?php // Studio ideal ?>
        </colgroup>
        <thead>
        <tr>
          <th>Step</th>
          <th title="How much of its scope this step actually touches. FX might only land on a third of the running time.">Covers</th>
          <th title="The amount of work the quota is calculated from, after the coverage percentage is applied.">Work</th>
          <?php // Two date columns became one, stacked. A native date input cannot
                // be made much narrower than the text it holds, so two of them side
                // by side were ~250px of the table's width for two values that are
                // always read together. Stacked they cost one column and a little
                // height — which is the trade this whole screen now makes. ?>
          <th>Dates<div class="muted small">start / deadline</div></th>
          <th title="How long one shot or asset takes one person: the number the plan actually runs on. Where the studio has an ideal for this step it is that ideal, shared by every project of this type; otherwise it is this project's own estimate. Decimals are fine, 0.5 is half a day. On a step measured in seconds this is days per SECOND of footage.">Days per item<div class="muted small">what the plan uses</div></th>
          <th title="How many people are on this step. Decimals are fine, 2.5 means someone is half-time.">People</th>
          <?php // Studio ideal sits BEFORE Duration because that is the order the
                // cells are written in below. They were the other way round in the
                // header from 2026-08-17 until David spotted it: every figure under
                // both headings was being read as the other column. A header and its
                // cells are two lists that have to be kept in step by hand, and
                // nothing warns when they drift. ?>
          <?php // Minimum sits immediately before the ideal so the two can be read
                // against each other without moving your eyes across the row. It is
                // the arithmetic one: work over working days in the window over people,
                // and nothing on this screen can edit it. ?>
          <?php // Minimum and Studio ideal are deliberately in the SAME unit and
                // worded the same way, because the whole point is reading one
                // against the other. Days per item gets its own column rather
                // than being crammed under the ideal in a second unit — two
                // numbers in one cell is what made "0.3 d" read as a duration. ?>
          <?php
          // THIS PROJECT first, then a rule, then THE STUDIO'S VIEW.
          //
          // Everything left of the separator is about the show in front of you —
          // what the dates demand, how long the work takes, whether the people
          // cover it. Everything right of it is the base sheet's opinion, which
          // is set elsewhere and cannot be edited here. Mixing the two is what
          // made a studio standard read as this project's duration.
          ?>
          <th title="The arithmetic quota: what one person must finish each day for this step to hit its deadline. Derived from the work, the dates and the people, not editable.">Minimum<div class="muted small">per person / day</div></th>
          <?php // Retakes sits BEFORE Duration because it is one of the things the
                // duration is made of: work, plus the allowance, divided by people.
                // Reading it after the total it feeds is reading the sum before its
                // parts. ?>
          <th title="What the retake allowance adds to this step, in days. The percentage above, converted.">Retakes<div class="muted small">in days</div></th>
          <th title="How long the step takes at these settings: the work divided by the people on it, retakes included. The line under it compares that against the working days between this step's own start and deadline, this is a plan, so it is judged on the window you gave it, not on how much of that window has gone.">Duration<div class="muted small">at these settings</div></th>
          <th>Is that enough?</th>
          <th class="col-sep" title="The pace the studio's ideal implies, in the same unit as the Minimum column, so the two can be compared directly. Red when it cannot keep up with the deadline.">Studio ideal<div class="muted small">per person / day</div></th>
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
        <?php
        // The studio ideal for this step, as a data attribute so the live check
        // can compare it against the minimum as you type. Rendering the verdict
        // server-side would only be right until the first keystroke.
        $rowStd = $idealSheet[standard_key($s['name'], $s['applies_to'] ?? 'shot',
                                           $s['applies_to_asset_type'] ?? '')] ?? null;
        ?>
        <tr data-units="<?= e(fmt_num($work['units_raw'] ?? $work['units'], 3)) ?>"
            data-label="<?= e($work['label']) ?>"
            data-estimated="<?= !empty($work['estimated']) ? '1' : '0' ?>"
            data-ideal="<?= $rowStd ? e(fmt_num((float)$rowStd['days_per_unit'], 4)) : '' ?>">
          <?php // The scope goes on its own line under the name rather than beside
                // it. "Concept Character assets" on one unbreakable line is what
                // made this column wide enough to push the table off the screen;
                // stacked, the column needs the width of the longer of the two. ?>
          <td class="wf-name">
            <span class="dot" style="background:<?= e($s['color'] ?: '#ccc') ?>"></span><?= e($s['name']) ?>
            <div class="muted small step-scope"><?= e(applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null)) ?><?= $s['tracks_seconds'] ? ' · seconds' : '' ?></div>
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
          <td class="date-pair">
            <input type="date" name="start_date[<?= $id ?>]" value="<?= e($s['start_date'] ?? '') ?>"
                   title="Starts">
            <input type="date" name="deadline[<?= $id ?>]" value="<?= e($s['deadline'] ?? '') ?>"
                   title="Deadline">
          </td>
          <?php /* step="any" rather than a fixed increment — real estimates land on
                   awkward numbers like 1.5 days or 2.5 people, and the browser
                   should not reject them. */ ?>
          <?php /* A step measured in seconds is measured in seconds here too: this
                    is days per SECOND of footage, not per shot. The quota has always
                    read it that way; the scheduler now turns it into dates, where
                    getting it backwards is the difference between a two-day task and
                    a twenty-day one. */ ?>
          <td class="dpu-cell">
            <?php
            // ⚠ THE BOX SHOWS WHAT THE PLAN RUNS ON (David, 2026-09-16). Where the
            // studio has an ideal it IS the ideal, and the line under it says so,
            // because a number that reaches every project of the type must never
            // look like it belongs to this one.
            //
            // Rendered at the column's full three decimals: shown rounded, an ideal
            // of 0.125 would post back as 0.13, and simply saving the page would
            // rewrite a studio number nobody touched.
            if ($rowStd !== null): $idealVal = fmt_num((float)$rowStd['days_per_unit'], 3); ?>
              <?php if (is_admin()): ?>
                <input type="number" name="ideal_days[<?= $id ?>]" step="any" min="0.001"
                       class="ideal-input" value="<?= e($idealVal) ?>" data-was="<?= e($idealVal) ?>"
                       data-step-label="<?= e(step_display_name($s, $steps)) ?>"
                       title="Studio ideal: changing it changes every <?= e((string)$project['type']) ?> project">
                <div class="dpu-source is-studio">studio ideal · every <?= e((string)$project['type']) ?> project</div>
              <?php else: ?>
                <strong class="ideal-readonly"><?= e($idealVal) ?></strong>
                <div class="dpu-source is-studio">studio ideal · set by an administrator</div>
              <?php endif; ?>
            <?php else: ?>
              <input type="number" name="days_per_unit[<?= $id ?>]" step="any" min="0.01"
                     value="<?= e(fmt_num($s['days_per_unit'] ?? 1, 3)) ?>">
              <div class="dpu-source">this project only · no studio ideal yet</div>
            <?php endif; ?>
            <?php if ($s['tracks_seconds']): ?>
              <div class="muted small">per second</div>
            <?php endif; ?>
            <?php
            // ⚠ THIS FIELD IS DAYS PER ITEM, AND IT READS BACKWARDS TO PEOPLE.
            // 0.25 is a QUARTER OF A DAY each, not four days each — and it was
            // misread that way twice before this hint existed. The reciprocal is
            // filled in live by wizard.js, in the same words the Minimum and
            // Studio ideal columns use, so the two readings sit together.
            ?>
            <div class="muted small dpu-hint"></div>
          </td>
          <td><input type="number" name="planned_headcount[<?= $id ?>]" min="0" step="any"
                     value="<?= e(fmt_num($s['planned_headcount'])) ?>"
                     placeholder="—"></td>
          <?php // Filled by wizard.js, like the duration and the verdict beside
                // it — all three are recalculated as you type. ?>
          <td class="minimum"><span class="muted">—</span></td>
          <td class="retake-days"><span class="muted">—</span></td>
          <td class="duration"><span class="muted">—</span></td>
          <td class="verdict"><span class="muted">—</span></td>
          <?php // Filled by wizard.js in exactly the format the Minimum cell uses,
                // so the two read as the same kind of number rather than as two
                // different measurements. Rendering it server-side would only be
                // right until the first keystroke — the unit label follows the
                // Work column, which the coverage box can change. ?>
          <td class="ideal-cell col-sep"><span class="muted">&mdash;</span></td>

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
      The check updates as you type, nothing is saved until you press a button. The daily
      rate is what the <em>whole team</em> must finish to hit the date; it doesn't fall when
      you add people, only each person's share does.
    </p>

    <script src="assets/wizard.js<?= asset_ver('wizard.js') ?>"></script>
    <?php
}
