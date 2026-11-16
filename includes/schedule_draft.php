<?php
// DRAFTING THE DATES FROM THE PLAN, ON THE TIMELINE (David, 2026-11-16).
//
// The Schedule tab used to be a page of its own: a read-only preview of the
// forward pass (includes/scheduler.php) with a Save button. David: "the Schedule
// tab can't edit any dates", and he would rather adjust them in the Timeline.
// Asked whether the scheduler should go, he agreed to keep its engine and drop
// the tab. So the preview now opens as a dialog over the Timeline ("Draft dates
// from the plan…"), Apply saves it, and the Timeline is where the dates are then
// moved by hand. schedule.php only redirects here, for old links.
//
// NOTHING HERE DOES ANY SCHEDULING. build_schedule() works the plan out and
// commit_schedule() writes it, exactly as before; this file only shows the one
// and calls the other, so the two screens can never come to different answers.
//
// Needs includes/scheduler.php (and so pipeline.php, quota.php, calendar.php)
// loaded first, as timeline.php does.

/**
 * Can a drafted plan be saved? The scheduler writes `lane` and `source`, which
 * arrive in migration 010. The plan can be worked out and shown without them;
 * only Apply needs them, so it is the button that goes, with the reason.
 */
function schedule_schema_ready(PDO $pdo): bool {
    return column_exists($pdo, 'schedule', 'source')
        && column_exists($pdo, 'schedule', 'lane');
}

/**
 * A fingerprint of what the plan is made from, to tell when it has changed
 * since the dates were last drafted.
 *
 * WHY A FINGERPRINT AND NOT A DATE. None of the inputs carries an "updated at":
 * the headcount, days per item and coverage are columns on pipeline_steps, the
 * estimates are project settings, and a deleted shot leaves no trace at all. Nor
 * can the drafted plan be compared with a fresh one, because the pass starts
 * from TODAY: a plan drafted last week differs from this morning's in every date
 * even when nothing about the show has changed. So Apply stores this signature
 * (project_settings, no migration) and the Timeline compares it on each load.
 *
 * WHAT COUNTS (David's list: headcount, the breakdown, estimates, days off):
 *   - each department's headcount, days per item, coverage, what it applies to,
 *     and what waits for what;
 *   - the project's estimates and retake allowance;
 *   - the breakdown: shots (and their placeholder seconds), assets (and their
 *     type), which assets are in which shot, and the "none of this type" marks;
 *   - days off: the studio calendar, and the absences and engagement dates of
 *     the people on this show's lanes.
 * WHAT DOES NOT: approvals and progress (work getting done is not the plan
 * changing, and would raise the note every day), and the departments' own dates
 * dragged on the general chart (moving a bar is editing the dates, which is what
 * the Timeline is for).
 *
 * Each part is read on its own and a part that fails (a table not there yet)
 * reads as "-", so a missing migration can never throw from here; it just
 * contributes the same constant every time.
 */
function schedule_inputs_signature(PDO $pdo, int $projectId): string {
    // Order-independent per table: BIT_XOR of a CRC per row, plus the count.
    // Cheap (one aggregate each), and a row added, removed or changed moves it.
    $parts = [];
    $q = function (string $sql, array $args) use ($pdo): string {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($args);
            $r = $st->fetch(PDO::FETCH_NUM);
            return $r ? implode(':', array_map('strval', $r)) : '-';
        } catch (Throwable $e) {
            return '-';
        }
    };

    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT_WS('|', id, applies_to,
                COALESCE(applies_to_asset_type, ''), tracks_seconds, days_per_unit,
                coverage_pct, COALESCE(planned_headcount, ''))))
           FROM pipeline_steps WHERE project_id = ?", [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(d.step_id, '>', d.depends_on_step_id)))
           FROM step_dependencies d JOIN pipeline_steps s ON s.id = d.step_id
          WHERE s.project_id = ?", [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(setting_key, '=', setting_value)))
           FROM project_settings
          WHERE project_id = ?
            AND setting_key IN ('estimated_seconds', 'estimated_shots', 'estimated_assets', 'retake_pct')",
        [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(id, '|', COALESCE(placeholder_seconds, ''))))
           FROM shots WHERE project_id = ?", [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(id, '|', COALESCE(asset_type, ''))))
           FROM assets WHERE project_id = ?", [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(sa.shot_id, '|', sa.asset_id)))
           FROM shot_assets sa JOIN shots s ON s.id = sa.shot_id
          WHERE s.project_id = ?", [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(n.shot_id, '|', n.asset_type)))
           FROM shot_type_na n JOIN shots s ON s.id = n.shot_id
          WHERE s.project_id = ?", [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(day)) FROM non_working_days", []);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(a.crew_id, '|', a.from_date, '|', a.to_date)))
           FROM crew_absences a
          WHERE a.crew_id IN (SELECT crew_id FROM crew_lanes WHERE project_id = ?)", [$projectId]);
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(crew_id, '|', COALESCE(start_date, ''), '|',
                COALESCE(end_date, ''))))
           FROM crew_projects WHERE project_id = ?", [$projectId]);
    // Who is on which lane: hiring into a slot, or adding one, changes the plan.
    $parts[] = $q(
        "SELECT COUNT(*), BIT_XOR(CRC32(CONCAT(step_id, '|', lane_no, '|', crew_id)))
           FROM crew_lanes WHERE project_id = ?", [$projectId]);

    return sha1(implode(';', $parts));
}

/**
 * Has the plan changed since the dates were last drafted?
 *
 * False when no draft has been applied since this note arrived (2026-11-16):
 * with nothing stored there is nothing to compare, and guessing would raise the
 * note on every show at once. The first Apply stores the fingerprint.
 */
function schedule_plan_changed(PDO $pdo, int $projectId): bool {
    try {
        $stored = (string)project_setting($pdo, $projectId, 'schedule_inputs_sig', '');
    } catch (Throwable $e) {
        return false;
    }
    if ($stored === '') return false;
    return !hash_equals($stored, schedule_inputs_signature($pdo, $projectId));
}

/** When the dates were last drafted, "2026-11-16 14:05", or ''. Never throws. */
function schedule_drafted_at(PDO $pdo, int $projectId): string {
    try {
        return (string)project_setting($pdo, $projectId, 'schedule_drafted_at', '');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Apply: work the plan out and save it. Flashes what it did, what it left alone
 * and what is wrong with the plan; throws with the reason when nothing could be
 * saved. Hand-planned rows (is_manual = 1) are never touched: commit_schedule().
 */
function schedule_draft_apply(PDO $pdo, array $project): void {
    $projectId = (int)$project['id'];
    if (!schedule_schema_ready($pdo)) {
        throw new RuntimeException(
            'The database updates drafting needs (migration 010) have not been applied yet, so ' .
            'nothing was saved. An administrator can apply them from the Migrations screen.'
        );
    }
    $plan = build_schedule($pdo, $project);
    if (!$plan['tasks']) {
        throw new RuntimeException(
            'Nothing was saved: the plan came out empty. ' .
            ($plan['problems'] ? implode(' ', $plan['problems'])
                               : 'Usually a missing breakdown, a missing deadline, or no departments.')
        );
    }
    $res = commit_schedule($pdo, $projectId, $plan);

    // Stored after the save, so a failed save never claims a draft it did not
    // make. A failure here only costs the "plan changed" note, never the dates.
    try {
        set_project_setting($pdo, $projectId, 'schedule_inputs_sig', schedule_inputs_signature($pdo, $projectId));
        set_project_setting($pdo, $projectId, 'schedule_drafted_at', date('Y-m-d H:i'));
    } catch (Throwable $e) {
        // nothing to do: see above
    }

    flash('ok', sprintf(
        'Drafted %d task%s from the plan, replacing %d from the last draft. %s',
        $res['written'], $res['written'] === 1 ? '' : 's', $res['deleted'],
        $plan['kept'] ? count($plan['kept']) . ' hand-planned task' .
                        (count($plan['kept']) === 1 ? ' was' : 's were') . ' left alone.'
                      : 'No hand-planned tasks were affected.'
    ));
    foreach ($plan['problems'] as $p) flash('warn', $p);
}

/**
 * Clear: remove every task the scheduler wrote. is_manual alone, matching
 * commit_schedule() and the count on the button; see the comment there for why
 * `source` is not part of it.
 */
function schedule_draft_clear(PDO $pdo, int $projectId): void {
    if (!schedule_schema_ready($pdo)) {
        throw new RuntimeException(
            'The database updates drafting needs (migration 010) have not been applied yet, so ' .
            'nothing was removed. An administrator can apply them from the Migrations screen.'
        );
    }
    $del = $pdo->prepare('DELETE FROM schedule WHERE project_id = ? AND is_manual = 0');
    $del->execute([$projectId]);
    try {
        $pdo->prepare(
            "DELETE FROM project_settings
              WHERE project_id = ? AND setting_key IN ('schedule_inputs_sig', 'schedule_drafted_at')"
        )->execute([$projectId]);
    } catch (Throwable $e) {
        // only the "plan changed" note depends on it
    }
    flash('ok', 'Removed ' . $del->rowCount() . ' drafted task' . ($del->rowCount() === 1 ? '' : 's') .
                '. Hand-planned tasks were kept.');
}

/** How many saved tasks the scheduler wrote, i.e. what Clear would remove. */
function schedule_auto_count(PDO $pdo, int $projectId): int {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM schedule WHERE project_id = ? AND is_manual = 0');
        $st->execute([$projectId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The dialog: what Apply would do, then Apply / Cancel, and Clear behind a
 * confirmation. The same content the Schedule page showed, minus the full task
 * listing (a four-figure table does not belong in a dialog, and the Timeline
 * draws every task once applied).
 *
 * $plan is build_schedule()'s answer, which the Timeline already works out on
 * every load for its "out of date" check, so opening this costs nothing extra.
 */
function schedule_draft_dialog(array $plan, array $project, bool $schemaReady, int $savedAuto, string $draftedAt): void {
    $projectId = (int)$project['id'];
    $delivery  = (string)($project['delivery_date'] ?? '');
    $blocked   = 0;
    $late      = [];
    foreach ($plan['steps'] as $s) {
        $blocked += (int)$s['blocked'];
        if ($s['late_days'] > 0) $late[] = $s;
    }
    ?>
<dialog id="draftDialog" class="tl-draft" aria-labelledby="draftTitle">
  <form method="dialog" class="tl-draft-x"><button type="submit" class="linkish" aria-label="Close">×</button></form>
  <h3 id="draftTitle">Draft dates from the plan</h3>
  <p class="muted small">
    Works forwards from the pipeline: every department waits for the ones before it, the work is
    spread across as many people as are planned for it, and the finish date is whatever falls out.
    <strong>Nothing changes until you press Apply.</strong>
    Hand-planned tasks (dragged, typed or locked on the Timeline) are left exactly where they are.
    <?php if ($draftedAt !== ''): ?>
      Last drafted <?= e($draftedAt) ?>.
    <?php endif; ?>
  </p>

  <?php foreach ($plan['problems'] as $p): ?>
    <div class="flash flash-err"><?= e($p) ?></div>
  <?php endforeach; ?>
  <?php foreach ($plan['notes'] as $n): ?>
    <div class="flash flash-warn"><?= e($n) ?></div>
  <?php endforeach; ?>
  <?php if (!$schemaReady): ?>
    <div class="flash flash-err">
      This plan can be looked at but not applied: the database updates drafting needs
      (migration 010) haven't been applied, so there is nowhere to put it.
      <?= is_admin() ? '<a href="migrate.php">Apply them →</a>' : 'Ask an administrator to run them.' ?>
    </div>
  <?php endif; ?>

  <?php if ($plan['tasks']): ?>
    <div class="sched-headline">
      <div><span class="muted small">Starts</span><strong><?= e($plan['anchor']) ?></strong></div>
      <div><span class="muted small">Finishes</span>
        <strong class="<?= $delivery !== '' && $plan['end'] > $delivery ? 'warn-text' : '' ?>"><?= e($plan['end']) ?></strong></div>
      <div><span class="muted small">Delivery date</span><strong><?= e($delivery !== '' ? $delivery : '—') ?></strong></div>
      <div><span class="muted small">Tasks</span><strong><?= count($plan['tasks']) ?></strong></div>
      <div><span class="muted small">Blocked</span>
        <strong class="<?= $blocked ? 'warn-text' : '' ?>"><?= $blocked ?></strong></div>
    </div>
    <?php if ($late): ?>
      <p class="warn-text small">
        <?= count($late) ?> department<?= count($late) === 1 ? ' finishes' : 's finish' ?> late:
        <?= e(implode(', ', array_map(function ($s) {
            return $s['step']['name'] . ' (' . $s['late_days'] . ' working day' . ($s['late_days'] === 1 ? '' : 's') . ')';
        }, $late))) ?>.
        Either the date moves, or the department needs more people than planned.
      </p>
    <?php endif; ?>

    <h4>By department</h4>
    <div class="tl-draft-scroll">
    <table class="review-table sched-table">
      <tr>
        <th>Department</th><th>Work</th>
        <th title="How many people run side by side. Taken from the planned headcount; derived from the deadline when none is set.">People</th>
        <th>Starts</th><th>Finishes</th><th>Deadline</th>
      </tr>
      <?php foreach ($plan['steps'] as $s):
        $step = $s['step'];
        $isLate = $s['late_days'] > 0; ?>
      <tr class="<?= $isLate ? 'has-problem' : '' ?>">
        <td class="wf-name">
          <span class="dot" style="background:<?= e($step['color'] ?: '#ccc') ?>"></span><?= e($step['name']) ?>
          <span class="muted small"><?= e(applies_to_phrase($step['applies_to'], $step['applies_to_asset_type'] ?? null)) ?></span>
        </td>
        <td>
          <?php if ($s['scheduled'] === 0): ?>
            <span class="muted">nothing to place</span>
          <?php elseif ($s['mode'] === 'band'): ?>
            <span class="est-tag">one band</span>
          <?php elseif ($s['mode'] === 'project'): ?>
            <span class="muted">one deliverable</span>
          <?php else: ?>
            <?= (int)$s['scheduled'] ?> <span class="muted">of <?= (int)$s['items_total'] ?></span>
          <?php endif; ?>
          <?php
          $dropped = [];
          if ($s['done'])     $dropped[] = $s['done'] . ' approved';
          if ($s['excluded']) $dropped[] = $s['excluded'] . ' not applicable';
          if ($s['manual'])   $dropped[] = $s['manual'] . ' hand-planned';
          if ($dropped): ?>
            <div class="muted small"><?= e(implode(' · ', $dropped)) ?></div>
          <?php endif; ?>
          <?php if ($s['blocked']): ?>
            <div class="warn-text small"><?= (int)$s['blocked'] ?> blocked</div>
          <?php endif; ?>
        </td>
        <td>
          <?= (int)$s['lanes'] ?>
          <span class="muted small"><?= $s['lane_source'] === 'planned' ? 'planned'
             : ($s['lane_source'] === 'deadline' ? 'from the date'
             : ($s['lane_source'] === 'capped' ? 'capped' : 'assumed')) ?></span>
        </td>
        <td class="muted"><?= e($s['start_date'] ?: '—') ?></td>
        <td class="<?= $isLate ? 'warn-text' : 'muted' ?>"><?= e($s['end_date'] ?: '—') ?>
          <?php if ($isLate): ?><div class="small"><?= (int)$s['late_days'] ?>d late</div><?php endif; ?></td>
        <td class="muted"><?= e($s['deadline'] ?: '—') ?>
          <?php if ($s['deadline'] && !$s['deadline_own']): ?><span class="muted small">(project)</span><?php endif; ?></td>
      </tr>
      <?php foreach ($s['notes'] as $note): ?>
        <tr class="problem-row"><td></td><td colspan="5" class="muted small"><?= e($note) ?></td></tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </table>
    </div>

    <?php if ($plan['kept']): ?>
      <h4>Left alone <span class="muted small">· <?= count($plan['kept']) ?> hand-planned</span></h4>
      <p class="muted small">
        The draft reads these, and what comes after still waits for them, but never moves them.
      </p>
      <div class="tl-draft-scroll tl-draft-short">
      <table class="review-table sched-tasks">
        <tr><th>Department</th><th>Item</th><th>Starts</th><th>Finishes</th></tr>
        <?php foreach ($plan['kept'] as $k): ?>
        <tr>
          <td class="wf-name"><?= e($k['step']) ?></td>
          <td><?= e($k['item']) ?></td>
          <td class="muted"><?= e($k['start_date']) ?></td>
          <td class="muted"><?= e($k['end_date']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted">
      There is nothing to draft yet. The reasons are listed above: usually a missing breakdown,
      a missing deadline, or a pipeline with no departments in it.
    </p>
  <?php endif; ?>

  <div class="actions">
    <form method="dialog"><button type="submit" class="secondary">Cancel</button></form>
    <span class="spacer"></span>
    <?php if ($schemaReady && $plan['tasks']): ?>
      <?php // The action rides in a hidden field and the button is disabled as
            // the form goes, so a double click cannot draft twice. ?>
      <form method="post" action="timeline.php?project=<?= $projectId ?>"
            onsubmit="this.querySelector('button').disabled = true;">
        <input type="hidden" name="draft_action" value="apply">
        <button type="submit">Apply</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($schemaReady && $plan['tasks']): ?>
    <p class="muted small">Apply replaces whatever the last draft saved. Hand-planned tasks are never touched.</p>
  <?php endif; ?>

  <?php if ($schemaReady && $savedAuto > 0): ?>
    <form method="post" action="timeline.php?project=<?= $projectId ?>" class="tl-draft-clear"
          onsubmit="return confirm('Remove the <?= $savedAuto ?> drafted task<?= $savedAuto === 1 ? '' : 's' ?>? Hand-planned tasks are kept. This cannot be undone, but you can draft again at any time.');">
      <input type="hidden" name="draft_action" value="clear">
      <button type="submit" class="linkish link-danger">Clear the saved schedule</button>
      <small class="muted">Removes the <?= $savedAuto ?> drafted task<?= $savedAuto === 1 ? '' : 's' ?>, keeps the hand-planned ones.</small>
    </form>
  <?php endif; ?>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('draftDialog');
  if (!dlg) return;
  function openDraft(ev) {
    if (ev) ev.preventDefault();
    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
  }
  document.querySelectorAll('[data-open-draft]').forEach(function (b) {
    b.addEventListener('click', openDraft);
  });
  // timeline.php#draft, where the old Schedule page and its links now land.
  if (location.hash === '#draft') openDraft();
})();
</script>
    <?php
}
