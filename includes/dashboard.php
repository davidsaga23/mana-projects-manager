<?php
// The numbers a dashboard is for.
//
// Everything else in this app answers "what is true". These answer "is that a
// problem" — which needs a comparison, and the comparisons are the whole value:
//
//   * what is finished, against what has to be
//   * when the plan lands, against when it is due
//   * what was finished per day, against what has to be finished per day
//
// The third is the one that needs history, which is why daily_snapshots had to
// start recording before this screen could be worth building.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/quota.php';

/**
 * Per-step progress: how many items exist, how many are tracked, delivered,
 * approved and blocked, plus what the saved schedule says about the dates.
 *
 * "Applicable" is counted the same way project.php counts it — a whole-project
 * step is one deliverable, a type-scoped step counts only its own type. If the
 * two screens disagreed about the denominator, every percentage on this page
 * would be quietly wrong.
 */
function dashboard_steps(PDO $pdo, int $projectId, ?string $deliveryDate = null): array {
    $shotCount  = (int)$pdo->query("SELECT COUNT(*) FROM shots  WHERE project_id = $projectId")->fetchColumn();

    $typeCounts = [];
    $stmt = $pdo->prepare("SELECT COALESCE(asset_type,'') t, COUNT(*) n FROM assets WHERE project_id = ? GROUP BY asset_type");
    $stmt->execute([$projectId]);
    $assetCount = 0;
    foreach ($stmt as $r) { $typeCounts[$r['t']] = (int)$r['n']; $assetCount += (int)$r['n']; }

    $stmt = $pdo->prepare(
        'SELECT ps.*,
                COUNT(pr.id) AS tracked,
                COALESCE(SUM(pr.is_delivered), 0) AS delivered,
                COALESCE(SUM(pr.is_approved), 0)  AS approved,
                COALESCE(SUM(st.is_blocked = 1), 0) AS blocked,
                COALESCE(SUM(st.is_excluded = 1), 0) AS excluded
           FROM pipeline_steps ps
           LEFT JOIN progress pr ON pr.step_id = ps.id
           LEFT JOIN statuses st ON st.id = pr.status_id
          WHERE ps.project_id = ?
          GROUP BY ps.id
          ORDER BY ps.step_order, ps.id'
    );
    $stmt->execute([$projectId]);
    $steps = $stmt->fetchAll();

    $sched = $pdo->prepare(
        'SELECT MIN(start_date) s, MAX(end_date) e, COUNT(*) n FROM schedule WHERE step_id = ?'
    );

    // Footage, for steps measured in seconds. Computed live rather than read
    // from the snapshots so the figures are right on day one, before any
    // history exists — the snapshots are for the TREND, not for the total.
    $totalSeconds = (float)$pdo->query(
        "SELECT COALESCE(SUM(placeholder_seconds), 0) FROM shots WHERE project_id = $projectId"
    )->fetchColumn();
    $secApproved = $pdo->prepare(
        'SELECT COALESCE(SUM(sh.placeholder_seconds), 0) AS ap,
                COALESCE(SUM(CASE WHEN pr.is_delivered = 1 THEN sh.placeholder_seconds ELSE 0 END), 0) AS de
           FROM progress pr JOIN shots sh ON sh.id = pr.shot_id
          WHERE pr.step_id = ? AND pr.is_approved = 1'
    );
    $secDelivered = $pdo->prepare(
        'SELECT COALESCE(SUM(sh.placeholder_seconds), 0)
           FROM progress pr JOIN shots sh ON sh.id = pr.shot_id
          WHERE pr.step_id = ? AND pr.is_delivered = 1'
    );

    $out = [];
    foreach ($steps as $s) {
        $type = $s['applies_to_asset_type'] ?? '';
        if ($s['applies_to'] === 'project') {
            $applicable = 1;
        } else {
            $applicable = 0;
            if (in_array($s['applies_to'], ['shot', 'both'], true))  $applicable += $shotCount;
            if (in_array($s['applies_to'], ['asset', 'both'], true)) {
                $applicable += $type !== '' ? ($typeCounts[$type] ?? 0) : $assetCount;
            }
        }
        // Items marked not-applicable are not work, so they leave the
        // denominator. Otherwise a step could never reach 100%.
        $applicable = max(0, $applicable - (int)$s['excluded']);

        $sched->execute([(int)$s['id']]);
        $sp = $sched->fetch();
        $deadline = $s['deadline'] ?: $deliveryDate;
        $tracksSeconds = (int)$s['tracks_seconds'] === 1;
        // A step set to track seconds is only MEASURABLE in seconds once shots
        // carry durations. Without them every figure comes out 0:00.00 / 0:00.00
        // — a row of zeros that looks like no progress rather than like a
        // missing input. Fall back to counting items, and say so on the card.
        $secondsMissing = $tracksSeconds && $totalSeconds <= 0;
        if ($secondsMissing) $tracksSeconds = false;

        // A step measured in footage is measured in footage everywhere: its
        // total, its progress and its quota are all seconds, not item counts.
        if ($tracksSeconds) {
            $secApproved->execute([(int)$s['id']]);
            $secDelivered->execute([(int)$s['id']]);
            $target    = $totalSeconds;
            $approved  = (float)$secApproved->fetchColumn();
            $delivered = (float)$secDelivered->fetchColumn();
            $unit = 'sec';
        } else {
            $target    = (float)$applicable;
            $approved  = (float)min((int)$s['approved'], $applicable);
            $delivered = (float)min((int)$s['delivered'], $applicable);
            $unit = 'items';
        }
        $remaining = max(0.0, $target - $approved);

        $req    = step_required_pace($remaining, $deadline);
        $actual = step_actual_pace($pdo, (int)$s['id'], $tracksSeconds);
        $delta  = step_last_deltas($pdo, (int)$s['id'], $tracksSeconds);

        // The quota split across the people actually on the step — the number a
        // producer repeats in a stand-up. Headcount, not lanes: half a person is
        // a real answer here.
        $head = $s['planned_headcount'] !== null ? (float)$s['planned_headcount'] : null;
        $perArtist = ($req['pace'] !== null && !is_infinite($req['pace']) && $head > 0)
                   ? $req['pace'] / $head : null;

        $out[] = [
            'step'       => $s,
            'unit'       => $unit,
            'tracks_seconds' => $tracksSeconds,
            'seconds_missing' => $secondsMissing,
            'applicable' => $applicable,
            'tracked'    => (int)$s['tracked'],
            'delivered'  => (int)$s['delivered'],
            'approved'   => (int)$s['approved'],
            'blocked'    => (int)$s['blocked'],
            'target'     => $target,
            'approved_v' => $approved,
            'delivered_v'=> $delivered,
            'remaining'  => $remaining,
            'pct'        => $target > 0 ? min(100, round($approved / $target * 100)) : null,
            'pct_delivered' => $target > 0 ? min(100, round($delivered / $target * 100)) : null,
            'required_pace' => $req['pace'],
            'days_left'  => $req['days'],
            'overdue'    => $req['overdue'],
            'actual_pace'=> $actual,
            'per_artist' => $perArtist,
            'headcount'  => $head,
            'status'     => pace_status($actual, $req['pace']),
            'delta'      => $delta,
            'sched_start'=> $sp['s'] ?: null,
            'sched_end'  => $sp['e'] ?: null,
            'sched_n'    => (int)$sp['n'],
            'deadline'   => $deadline,
            'late_days'  => ($deadline && $sp['e'] && $sp['e'] > $deadline)
                            ? working_days_after($deadline, $sp['e']) : 0,
        ];
    }
    return $out;
}

/** Format a value in a step's own unit. */
function fmt_unit(float $v, string $unit): string {
    return $unit === 'sec' ? fmt_secs($v) : fmt_rate($v);
}

/**
 * A required pace, or the plain truth when there isn't one.
 *
 * INF is printed as "overdue", never as a number: a pace of infinity is not a
 * target somebody should see on a screen and try to plan against.
 */
function pace_text($v, string $unit): string {
    if ($v === null) return '—';
    if (is_infinite($v)) return 'overdue';
    return fmt_unit((float)$v, $unit);
}

/**
 * The breakdown grid: groups down the side, steps across the top.
 *
 * TWO grids, not one. STFC could put episodes down the side because everything
 * it tracked was a shot. Here a step applies to shots or to assets, and a single
 * grid would have to leave most of itself blank — so shots group by episode and
 * assets group by type, each against only the steps that actually touch them.
 *
 * Returns a list of ['title', 'unit_label', 'steps', 'groups'].
 */
function dashboard_breakdown(PDO $pdo, int $projectId, array $steps, bool $episodic): array {
    $tables = [];

    // --- shots, by episode ---------------------------------------------------
    $shotSteps = array_values(array_filter($steps, function ($s) {
        return in_array($s['step']['applies_to'], ['shot', 'both'], true);
    }));
    if ($shotSteps) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(episode, ''), 'No episode') AS grp,
                    COUNT(*) AS n, COALESCE(SUM(placeholder_seconds), 0) AS secs
               FROM shots WHERE project_id = ? GROUP BY grp ORDER BY grp"
        );
        $stmt->execute([$projectId]);
        $groups = [];
        foreach ($stmt as $r) {
            $groups[$r['grp']] = ['name' => $r['grp'], 'count' => (int)$r['n'],
                                  'secs' => (float)$r['secs'], 'cells' => []];
        }
        if ($groups) {
            $cell = $pdo->prepare(
                "SELECT COALESCE(NULLIF(sh.episode, ''), 'No episode') AS grp,
                        COALESCE(SUM(pr.is_approved), 0) AS ap,
                        COALESCE(SUM(CASE WHEN pr.is_approved = 1 THEN sh.placeholder_seconds ELSE 0 END), 0) AS ap_secs
                   FROM progress pr JOIN shots sh ON sh.id = pr.shot_id
                  WHERE pr.step_id = ? GROUP BY grp"
            );
            foreach ($shotSteps as $st) {
                $cell->execute([(int)$st['step']['id']]);
                foreach ($cell as $r) {
                    if (!isset($groups[$r['grp']])) continue;
                    $groups[$r['grp']]['cells'][(int)$st['step']['id']] =
                        $st['tracks_seconds'] ? (float)$r['ap_secs'] : (float)$r['ap'];
                }
            }
            $tables[] = ['title' => $episodic ? 'Shots by episode' : 'Shots',
                         'unit_label' => 'Shots', 'steps' => $shotSteps,
                         'groups' => array_values($groups), 'kind' => 'shot'];
        }
    }

    // --- assets, by type -----------------------------------------------------
    $assetSteps = array_values(array_filter($steps, function ($s) {
        return in_array($s['step']['applies_to'], ['asset', 'both'], true);
    }));
    if ($assetSteps) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(asset_type, ''), 'No type') AS grp, COUNT(*) AS n
               FROM assets WHERE project_id = ? GROUP BY grp ORDER BY grp"
        );
        $stmt->execute([$projectId]);
        $groups = [];
        foreach ($stmt as $r) {
            $groups[$r['grp']] = ['name' => $r['grp'], 'count' => (int)$r['n'],
                                  'secs' => 0.0, 'cells' => []];
        }
        if ($groups) {
            $cell = $pdo->prepare(
                "SELECT COALESCE(NULLIF(a.asset_type, ''), 'No type') AS grp,
                        COALESCE(SUM(pr.is_approved), 0) AS ap
                   FROM progress pr JOIN assets a ON a.id = pr.asset_id
                  WHERE pr.step_id = ? GROUP BY grp"
            );
            foreach ($assetSteps as $st) {
                $cell->execute([(int)$st['step']['id']]);
                foreach ($cell as $r) {
                    if (!isset($groups[$r['grp']])) continue;
                    $groups[$r['grp']]['cells'][(int)$st['step']['id']] = (float)$r['ap'];
                }
            }
            $tables[] = ['title' => 'Assets by type', 'unit_label' => 'Assets',
                         'steps' => $assetSteps, 'groups' => array_values($groups),
                         'kind' => 'asset'];
        }
    }

    return $tables;
}

/**
 * How many of a group's items a step actually covers.
 *
 * A step scoped to "Character" covers none of the environments, so its cell in
 * the environment row is not 0-of-6 — it is not applicable at all, and colouring
 * it red would invent a problem.
 */
function breakdown_cell_total(array $st, array $group, string $kind): ?float {
    $step = $st['step'];
    if ($kind === 'asset') {
        $type = $step['applies_to_asset_type'] ?? '';
        if ($type !== '' && $type !== $group['name']) return null;
        return (float)$group['count'];
    }
    if ($st['tracks_seconds']) return (float)$group['secs'];
    return (float)$group['count'];
}

/**
 * Seconds as a running time: 233.62 → "3:53.62".
 *
 * A step measured in footage is quoted in minutes and seconds by everyone who
 * works on it; printing "233.62 sec" makes people do the arithmetic themselves.
 */
function fmt_secs(float $s): string {
    if ($s < 0) $s = 0;
    $m = (int)floor($s / 60);
    $r = $s - $m * 60;
    return $m . ':' . str_pad(number_format($r, 2, '.', ''), 5, '0', STR_PAD_LEFT);
}

/**
 * What a step has actually been finishing per working day, from the snapshots.
 *
 * Reads the last seven days where possible and falls back to the whole recorded
 * history when there isn't that much yet. Returns null rather than 0 when there
 * is nothing to measure — zero means "nothing got done", which is a completely
 * different statement from "we don't know yet", and showing one as the other is
 * how a dashboard starts lying.
 */
function step_actual_pace(PDO $pdo, int $stepId, bool $tracksSeconds, int $windowDays = 7): ?float {
    $col = $tracksSeconds ? 'seconds_approved_cum' : 'items_approved_cum';
    try {
        $s = $pdo->prepare("SELECT snapshot_date, $col AS v FROM daily_snapshots
                             WHERE step_id = ? ORDER BY snapshot_date DESC LIMIT 1");
        $s->execute([$stepId]);
        $latest = $s->fetch();
        if (!$latest) return null;

        $s2 = $pdo->prepare("SELECT snapshot_date, $col AS v FROM daily_snapshots
                              WHERE step_id = ? AND snapshot_date <= DATE_SUB(?, INTERVAL $windowDays DAY)
                              ORDER BY snapshot_date DESC LIMIT 1");
        $s2->execute([$stepId, $latest['snapshot_date']]);
        $prior = $s2->fetch();

        if (!$prior) {
            $s3 = $pdo->prepare("SELECT snapshot_date, $col AS v FROM daily_snapshots
                                  WHERE step_id = ? ORDER BY snapshot_date ASC LIMIT 1");
            $s3->execute([$stepId]);
            $prior = $s3->fetch();
        }
        if (!$prior || $prior['snapshot_date'] === $latest['snapshot_date']) return null;

        $wd = max(1, working_days_between($prior['snapshot_date'], $latest['snapshot_date']) - 1);
        return ((float)$latest['v'] - (float)$prior['v']) / $wd;
    } catch (PDOException $e) {
        return null;
    }
}

/** The change since the previous snapshot, for the "today" tiles. */
function step_last_deltas(PDO $pdo, int $stepId, bool $tracksSeconds): array {
    $a = $tracksSeconds ? 'seconds_approved_cum'  : 'items_approved_cum';
    $d = $tracksSeconds ? 'seconds_delivered_cum' : 'items_delivered_cum';
    $out = ['approved' => null, 'delivered' => null, 'date' => null];
    try {
        $s = $pdo->prepare("SELECT snapshot_date, $a AS ap, $d AS de FROM daily_snapshots
                             WHERE step_id = ? ORDER BY snapshot_date DESC LIMIT 2");
        $s->execute([$stepId]);
        $rows = $s->fetchAll();
    } catch (PDOException $e) { return $out; }

    if (!$rows) return $out;
    $out['date'] = $rows[0]['snapshot_date'];
    if (count($rows) < 2) return $out;
    $out['approved']  = max(0, (float)$rows[0]['ap'] - (float)$rows[1]['ap']);
    $out['delivered'] = max(0, (float)$rows[0]['de'] - (float)$rows[1]['de']);
    return $out;
}

/**
 * The quota, per step: what has to be finished each day from here to hit the
 * deadline, and what that means for each person on it.
 *
 * This is the reverse-quota maths the wizard shows before a project starts,
 * re-measured against what is actually left. INF when the deadline has passed
 * with work outstanding — which the screen must print as "overdue", never as a
 * very large number somebody might try to plan against.
 */
function step_required_pace(float $remaining, ?string $deadline, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    if (!$deadline)          return ['pace' => null, 'days' => null, 'overdue' => false];
    if ($deadline < $today)  return ['pace' => $remaining > 0 ? INF : 0.0, 'days' => 0,
                                     'overdue' => $remaining > 0];
    $wd = working_days_between($today, $deadline);
    if ($wd <= 0)            return ['pace' => $remaining > 0 ? INF : 0.0, 'days' => 0,
                                     'overdue' => $remaining > 0];
    return ['pace' => $remaining / $wd, 'days' => $wd, 'overdue' => false];
}

/** ontrack / behind / critical, comparing what is happening to what is needed. */
function pace_status(?float $actual, $required): string {
    if ($actual === null || $required === null) return 'unknown';
    if (is_infinite($required)) return 'critical';
    if ($required <= 0) return 'ontrack';
    if ($actual >= $required) return 'ontrack';
    return $actual >= $required * 0.6 ? 'behind' : 'critical';
}

/**
 * The colour of a cell in the breakdown grid.
 *
 * Compares how much is done against how much SHOULD be done by now, judged on
 * elapsed time between the step's start and its deadline. A step that is 40%
 * done is fine in week one and critical in the last week, and a flat percentage
 * threshold cannot tell those apart.
 */
function cell_health(float $approved, float $total, ?string $start, ?string $deadline, ?string $today = null): string {
    if ($total <= 0 || !$deadline) return 'neutral';
    $today = $today ?: date('Y-m-d');
    $start = $start ?: $today;
    if ($start > $deadline) return 'neutral';

    $totalWd   = max(1, working_days_between($start, $deadline));
    $elapsedWd = min($totalWd, max(0, working_days_between($start, $today)));
    $expected  = $elapsedWd / $totalWd;
    if ($expected <= 0.01) return 'neutral';       // too early to judge

    $ratio = ($approved / $total) / $expected;
    if ($ratio >= 1.0) return 'green';
    return $ratio >= 0.6 ? 'amber' : 'red';
}

/**
 * The whole project as one figure.
 *
 * Counted in TASKS — one item passing through one step — because that is the
 * only unit every step shares. Seconds and item counts cannot be added
 * together, so a project total measured in either would be nonsense the moment
 * a show mixes them, which every show does.
 */
function dashboard_overall(array $steps, ?string $delivery, ?array $pace, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $applicable = 0; $approved = 0; $delivered = 0; $blocked = 0;
    foreach ($steps as $s) {
        $applicable += $s['applicable'];
        // Capped: a step can carry progress rows for items that have since been
        // removed, and an uncapped sum could show 103% complete.
        $approved   += min($s['approved'],  $s['applicable']);
        $delivered  += min($s['delivered'], $s['applicable']);
        $blocked    += $s['blocked'];
    }
    $remaining = max(0, $applicable - $approved);
    $req = step_required_pace((float)$remaining, $delivery, $today);
    $actual = $pace['actual_per_day'] ?? null;

    return [
        'applicable' => $applicable, 'approved' => $approved,
        'delivered' => $delivered, 'blocked' => $blocked, 'remaining' => $remaining,
        'pct'           => $applicable > 0 ? min(100, round($approved / $applicable * 100)) : null,
        'pct_delivered' => $applicable > 0 ? min(100, round($delivered / $applicable * 100)) : null,
        'required_pace' => $req['pace'], 'days_left' => $req['days'], 'overdue' => $req['overdue'],
        'actual_pace'   => $actual,
        'status'        => pace_status($actual, $req['pace']),
        'delta_approved'  => $pace['delta_approved']  ?? null,
        'delta_delivered' => $pace['delta_delivered'] ?? null,
        'last_reading'  => $pace['to'] ?? null,
    ];
}

/**
 * The overall-progress block, rendered from one place.
 *
 * Drawn on both the dashboard and the project overview. Written as a function
 * rather than copied because two copies of a block this arithmetic-heavy drift
 * the first time one of them is corrected — and a headline figure that differs
 * between two screens of the same project is worse than not showing it twice.
 */
function render_overall_progress(array $overall, bool $compact = false): void {
    ?>
    <section class="dash-primary">
      <div class="dash-primary-head">
        <div>
          <h3>Overall progress</h3>
          <p class="muted small">
            Every shot and asset, through every step —
            <strong><?= (int)$overall['applicable'] ?></strong> tasks in total.
            <?php if ($overall['days_left'] !== null): ?>
              <span class="<?= $overall['days_left'] <= 5 ? 'warn-text' : '' ?>">
                <?= (int)$overall['days_left'] ?> working days to delivery</span>
            <?php elseif ($overall['overdue']): ?>
              <span class="warn-text">the delivery date has passed</span>
            <?php else: ?>
              <span class="muted">no delivery date set</span>
            <?php endif; ?>
          </p>
        </div>
        <div class="dash-big">
          <div class="big-pct"><?= $overall['pct'] === null ? '—' : $overall['pct'] . '%' ?></div>
          <div class="muted small">of all work approved</div>
        </div>
      </div>

      <div class="stacked-progress">
        <span class="band-approved" style="width:<?= (float)($overall['pct'] ?? 0) ?>%"></span>
        <span class="band-delivered" style="left:<?= (float)($overall['pct'] ?? 0) ?>%;width:<?= max(0, (float)($overall['pct_delivered'] ?? 0) - (float)($overall['pct'] ?? 0)) ?>%"></span>
      </div>
      <div class="dash-legend">
        <span><i class="sw sw-approved"></i>Approved <?= (int)$overall['approved'] ?></span>
        <span><i class="sw sw-delivered"></i>Delivered pending <?= (int)max(0, $overall['delivered'] - $overall['approved']) ?></span>
        <span class="muted">Remaining <?= (int)$overall['remaining'] ?></span>
        <?php if ($overall['blocked']): ?>
          <span class="warn-text"><?= (int)$overall['blocked'] ?> blocked</span>
        <?php endif; ?>
      </div>

      <?php // The overview shows the shape; the dashboard shows the arithmetic. ?>
      <?php if (!$compact): ?>
      <div class="kpi-grid">
        <div><div class="kpi-label">Approved since last reading</div>
          <div class="kpi-value ok"><?= $overall['delta_approved'] === null ? '—'
                : '+' . (int)$overall['delta_approved'] ?></div>
          <div class="muted small"><?= $overall['last_reading'] ? 'as of ' . e($overall['last_reading']) : 'no readings yet' ?></div></div>
        <div><div class="kpi-label">Delivered since last reading</div>
          <div class="kpi-value info"><?= $overall['delta_delivered'] === null ? '—'
                : '+' . (int)$overall['delta_delivered'] ?></div></div>
        <div><div class="kpi-label">Required pace</div>
          <div class="kpi-value warn"><?= e(pace_text($overall['required_pace'], 'items')) ?>
            <span class="unit">tasks/day</span></div>
          <div class="muted small">across the whole team</div></div>
        <div><div class="kpi-label">Actual, last 7 days</div>
          <div class="kpi-value status-<?= e($overall['status']) ?>">
            <?= $overall['actual_pace'] === null ? '—' : e(fmt_rate((float)$overall['actual_pace'])) ?>
            <span class="unit">tasks/day</span>
            <?php if ($overall['status'] === 'ontrack'): ?><span class="trend up">▲ on track</span>
            <?php elseif ($overall['status'] === 'behind'): ?><span class="trend down">▼ behind</span>
            <?php elseif ($overall['status'] === 'critical'): ?><span class="trend down">▼ critical</span>
            <?php else: ?><span class="muted small">no history yet</span><?php endif; ?>
          </div></div>
      </div>
      <p class="muted small">
        Counted in <strong>tasks</strong> — one item through one step — because that is the only
        unit every step shares. A step measured in seconds and one measured in shots cannot be
        added together, so each card below keeps its own unit.
      </p>
      <?php endif; ?>
    </section>
    <?php
}

/**
 * Are we keeping up?
 *
 * Compares what has actually been approved per working day against what the
 * remaining work and the remaining days demand. This is the reverse-quota
 * number from the wizard, measured rather than planned — and it is the one
 * figure that says whether a plan is being met, as opposed to whether it was
 * ever achievable.
 *
 * Returns null when there is not enough history to say anything. Two data
 * points is not a rate, and a confident number drawn from one day would be
 * worse than none: people plan against it.
 */
function dashboard_pace(PDO $pdo, int $projectId, ?string $deliveryDate, ?string $today = null): ?array {
    $today = $today ?: date('Y-m-d');
    try {
        $stmt = $pdo->prepare(
            'SELECT snapshot_date,
                    SUM(items_approved_cum)  AS approved,
                    SUM(items_delivered_cum) AS delivered,
                    SUM(items_total) AS total
               FROM daily_snapshots WHERE project_id = ?
              GROUP BY snapshot_date ORDER BY snapshot_date'
        );
        $stmt->execute([$projectId]);
        $days = $stmt->fetchAll();
    } catch (PDOException $e) {
        return null;                       // migration 011 not applied
    }
    if (count($days) < 2) return null;

    $first = $days[0];
    $last  = $days[count($days) - 1];
    $span  = max(1, working_days_between($first['snapshot_date'], $last['snapshot_date']) - 1);

    $doneSoFar = (int)$last['approved'] - (int)$first['approved'];
    $actual    = $doneSoFar / $span;

    $remaining = max(0, (int)$last['total'] - (int)$last['approved']);
    $needed    = null;
    $daysLeft  = null;
    if ($deliveryDate && $deliveryDate >= $today) {
        $daysLeft = max(1, working_days_between($today, $deliveryDate));
        $needed   = $remaining / $daysLeft;
    }

    // Movement since the previous reading — the "what happened today" figures.
    $prev = $days[count($days) - 2];

    return [
        'from' => $first['snapshot_date'], 'to' => $last['snapshot_date'],
        'delta_approved'  => max(0, (int)$last['approved']  - (int)$prev['approved']),
        'delta_delivered' => max(0, (int)$last['delivered'] - (int)$prev['delivered']),
        'span_days' => $span, 'done_in_span' => $doneSoFar,
        'actual_per_day' => $actual, 'needed_per_day' => $needed,
        'remaining' => $remaining, 'days_left' => $daysLeft,
        'points' => $days,
        // Deliberately conservative: below three readings this is arithmetic,
        // not a trend, and the screen says so rather than drawing a line.
        'confident' => count($days) >= 3,
    ];
}

/** Who is carrying what: tasks, days and the next thing due, per crew slot. */
function dashboard_workload(PDO $pdo, int $projectId, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.is_placeholder,
                COUNT(sc.id) AS tasks,
                MIN(sc.start_date) AS first_start,
                MAX(sc.end_date)   AS last_end,
                COALESCE(SUM(sc.end_date >= ?), 0) AS remaining
           FROM schedule sc
           JOIN crew c ON c.id = sc.crew_id
          WHERE sc.project_id = ?
          GROUP BY c.id, c.name, c.is_placeholder
          ORDER BY remaining DESC, c.name'
    );
    $stmt->execute([$today, $projectId]);
    return $stmt->fetchAll();
}

/**
 * A burn-up: approved over time against the total that has to be reached.
 *
 * Plain SVG, like the workflow diagram — no library, and it prints. Returns ''
 * when there is too little history to draw anything honest.
 */
function dashboard_burnup_svg(array $points, ?string $deliveryDate = null, int $width = 640, int $height = 150): string {
    if (count($points) < 2) return '';

    $maxTotal = 0;
    foreach ($points as $p) $maxTotal = max($maxTotal, (int)$p['total']);
    if ($maxTotal <= 0) return '';

    $padL = 34; $padR = 10; $padT = 10; $padB = 20;
    $plotW = $width - $padL - $padR;
    $plotH = $height - $padT - $padB;

    $first = $points[0]['snapshot_date'];
    // The x axis runs to the delivery date when there is one, so the gap between
    // the line and the target is visible rather than cropped out.
    $last  = $deliveryDate && $deliveryDate > $points[count($points) - 1]['snapshot_date']
           ? $deliveryDate : $points[count($points) - 1]['snapshot_date'];
    $totalDays = max(1, (int)(new DateTime($first))->diff(new DateTime($last))->format('%a'));

    $x = function ($date) use ($first, $totalDays, $padL, $plotW) {
        $d = (int)(new DateTime($first))->diff(new DateTime($date))->format('%r%a');
        return $padL + ($d / $totalDays) * $plotW;
    };
    $y = function ($v) use ($maxTotal, $padT, $plotH) {
        return $padT + $plotH - ($v / $maxTotal) * $plotH;
    };

    $svg = '<svg class="burnup" viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" '
         . 'height="' . $height . '" xmlns="http://www.w3.org/2000/svg" role="img" '
         . 'aria-label="Approved work over time">';

    // The target: everything, by the delivery date.
    $svg .= '<line x1="' . round($padL, 1) . '" y1="' . round($y($maxTotal), 1)
          . '" x2="' . round($width - $padR, 1) . '" y2="' . round($y($maxTotal), 1)
          . '" class="bu-target" />'
          . '<text x="2" y="' . round($y($maxTotal) + 4, 1) . '" class="bu-axis">' . $maxTotal . '</text>'
          . '<text x="2" y="' . round($y(0) + 4, 1) . '" class="bu-axis">0</text>';

    $d = '';
    foreach ($points as $i => $p) {
        $d .= ($i ? ' L' : 'M') . round($x($p['snapshot_date']), 1) . ',' . round($y((int)$p['approved']), 1);
    }
    $svg .= '<path d="' . $d . '" class="bu-line" fill="none" />';

    foreach ($points as $p) {
        $svg .= '<circle cx="' . round($x($p['snapshot_date']), 1) . '" cy="'
              . round($y((int)$p['approved']), 1) . '" r="2.5" class="bu-dot">'
              . '<title>' . e($p['snapshot_date'] . ': ' . (int)$p['approved'] . ' of ' . (int)$p['total'] . ' approved')
              . '</title></circle>';
    }

    if ($deliveryDate && $deliveryDate >= $first) {
        $dx = round($x($deliveryDate), 1);
        $svg .= '<line x1="' . $dx . '" y1="' . $padT . '" x2="' . $dx . '" y2="' . ($padT + $plotH)
              . '" class="bu-delivery" />';
    }

    $svg .= '<text x="' . $padL . '" y="' . ($height - 6) . '" class="bu-axis">' . e($first) . '</text>'
          . '<text x="' . ($width - $padR) . '" y="' . ($height - 6) . '" class="bu-axis" text-anchor="end">'
          . e($last) . '</text>';

    return $svg . '</svg>';
}
