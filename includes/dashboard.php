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

        $out[] = [
            'step'       => $s,
            'applicable' => $applicable,
            'tracked'    => (int)$s['tracked'],
            'delivered'  => (int)$s['delivered'],
            'approved'   => (int)$s['approved'],
            'blocked'    => (int)$s['blocked'],
            'pct'        => $applicable > 0 ? min(100, round((int)$s['approved'] / $applicable * 100)) : null,
            'pct_delivered' => $applicable > 0 ? min(100, round((int)$s['delivered'] / $applicable * 100)) : null,
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
                    SUM(items_approved_cum) AS approved,
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

    return [
        'from' => $first['snapshot_date'], 'to' => $last['snapshot_date'],
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
