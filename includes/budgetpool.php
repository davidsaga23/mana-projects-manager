<?php
// The show expressed in DAYS rather than money.
//
// David, 2026-10-16, after a conversation about how Skydance hands out budgets:
// "I'd like to create an MVP of that concept so I can start testing how that
// might work live." At Skydance a department is given forty weeks, not eighty
// thousand dollars, and the producer steers by the pool draining.
//
// WHY DAYS AT ALL, when the money is already on the Overview. Money is the
// ledger; days are the control. A producer cannot do anything with "we are
// 4,000 over" until it is turned back into "that is twenty layout days", and
// days are the unit the people doing the work already think in. The two are the
// same fact at different distances, and the rate card is the exchange rate.
//
// ⚠ NOTHING HERE IS TYPED IN. David: "Days should come from the headcount &
// days, this is what the math (quota, start/end dates, and headcount) tells us
// we need in days." So the pool is DERIVED on every page load from each step's
// dated window and the slots standing in it, exactly as the scheduler and the
// expense projection derive theirs. There is no budget_pool table, and fixing a
// date on the Dates & headcount screen moves this screen with it. A stored copy
// would be a fourth number claiming to describe the same plan, and this codebase
// has paid for that before.
//
// ⚠ TWO POOLS, NEVER SUMMED. People paid per approved second have no day rate
// and no day pool; their work is measured in seconds, and a second is not a day.
// David: "for those departments based on a per second the pool will have to be
// in seconds too... I think those pipeline steps won't add or subtract days to
// the total days pool." Same rule project_stop_cost() follows for currencies —
// a single total built out of incompatible units is worse than two honest ones.
//
// ⚠ AND THE UNIT BELONGS TO THE PERSON, NOT THE STEP. crew_terms is keyed by
// crew_id, so one step can hold a day-rate artist and a per-second artist at
// once — MSD's Color (Environment) has exactly that, Surendra by the day and
// Artie by the second. There is no such thing as "a seconds step", so the split
// is made per LANE and a step can appear in both pools.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crew.php';
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/money.php';
// ⚠ EXPLICIT, THOUGH finance.php HAPPENS TO PULL IT IN TODAY. hours_per_day()
// and time_logs_available() both live there, and depending on another file's
// require chain is how a fatal arrives the day somebody tidies that chain up.
require_once __DIR__ . '/timelog.php';

/**
 * How much of a pool has to be used before its pace is worth colouring.
 *
 * ⚠ EARLY STEPS SKEW RED FOR A STRUCTURAL REASON, not a real one: setup days
 * are consumed before the first shot lands, so every step reads "days spent,
 * nothing delivered" in its first week. Colouring that red teaches people to
 * ignore the colour, which costs more than the warning was worth.
 */
const POOL_PACE_MIN_USED = 15.0;

/**
 * Below this many items, days-against-progress cannot say anything.
 *
 * ⚠ A WHOLE-PROJECT STEP IS ONE ITEM, so it reads 0% done for its entire life
 * and then 100% on the last day. MSD's Color Script is exactly this: it would
 * have sat red from the first day to the last and gone green an hour before
 * delivery. A card that is red for an arithmetic reason is the confidently
 * wrong screen this codebase keeps paying for, so those say so instead.
 */
const POOL_PACE_MIN_ITEMS = 3.0;

/** How far apart the two percentages must be before it is worth a colour. */
const POOL_PACE_BAND = 3.0;

/**
 * Is the work keeping up with the days?
 *
 * Returns ['class', 'text'] — 'ok' when the work is ahead, 'err' when it is
 * behind, and a quiet 'muted' when the two are level or the comparison cannot
 * be trusted yet. The reason is always in the text: a badge that goes grey
 * without saying why reads as a bug.
 */
function pool_pace(float $pctUsed, float $pctDone, float $items, string $noun = 'days'): array {
    if ($items > 0 && $items < POOL_PACE_MIN_ITEMS) {
        return ['muted', 'too few items here to compare ' . $noun . ' against progress'];
    }
    if ($pctUsed < POOL_PACE_MIN_USED) {
        return ['muted', sprintf('%d%% of %s used, %d%% done — too early to read',
                                 round($pctUsed), $noun, round($pctDone))];
    }
    if ($pctDone >= $pctUsed + POOL_PACE_BAND) {
        return ['ok', sprintf('%d%% of %s used, %d%% done — ahead',
                              round($pctUsed), $noun, round($pctDone))];
    }
    if ($pctUsed >= $pctDone + POOL_PACE_BAND) {
        return ['err', sprintf('%d%% of %s used, only %d%% done',
                               round($pctUsed), $noun, round($pctDone))];
    }
    return ['muted', sprintf('%d%% of %s used, %d%% done — level',
                             round($pctUsed), $noun, round($pctDone))];
}

/**
 * Logged days per step, or an empty array when nobody has logged anything.
 *
 * ⚠ time_logs, NOT progress.time_spent_minutes. The second is whatever Plaky
 * was told and arrives with an import; the first is what somebody recorded in
 * Anima, including through the timer. project_stop_cost() drew the same line
 * for the same reason, and the two screens must not disagree about what the
 * word "logged" means.
 */
function pool_logged_days(PDO $pdo, int $projectId, float $hoursPerDay): array {
    if (!time_logs_available($pdo) || $hoursPerDay <= 0) return [];
    try {
        $q = $pdo->prepare(
            'SELECT pr.step_id, COALESCE(SUM(tl.minutes), 0) AS mins
               FROM time_logs tl
               JOIN progress pr ON pr.id = tl.progress_id
              WHERE pr.project_id = ?
              GROUP BY pr.step_id'
        );
        $q->execute([$projectId]);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($q as $r) {
        $d = ((float)$r['mins'] / 60) / $hoursPerDay;
        if ($d > 0) $out[(int)$r['step_id']] = $d;
    }
    return $out;
}

/**
 * The whole screen's figures.
 *
 * @return array{
 *   days: array, seconds: array, work: array, source: string,
 *   undated: array, has_money: bool, currency: string
 * }
 */
function budget_pool(PDO $pdo, array $project): array {
    $projectId = (int)$project['id'];
    $today     = date('Y-m-d');
    $hpd       = hours_per_day($pdo, $projectId);

    // Progress comes from the SAME function the production dashboard draws, so
    // "8 of 21 done" cannot mean one thing there and another here. David asked
    // for pipeline steps precisely so the two screens line up.
    $steps = dashboard_steps($pdo, $projectId, $project['delivery_date'] ?: null);
    $byId  = [];
    foreach ($steps as $s) $byId[(int)$s['step']['id']] = $s;

    $lanes = project_lanes($pdo, $projectId);
    $crewIds = array_values(array_unique(array_map(fn($l) => (int)$l['crew_id'], $lanes)));
    $terms   = $crewIds ? crew_terms($crewIds) : [];

    $logged  = pool_logged_days($pdo, $projectId, $hpd);
    $source  = $logged ? 'logged' : 'plan';

    // step id => accumulating row
    $acc = [];
    $undated = [];      // steps with a lane but no window to measure
    $secondsLanes = []; // step id => true, for lanes paid by the second

    foreach ($lanes as $l) {
        $stepId = (int)$l['step_id'];
        $start  = (string)($l['step_start'] ?? '');
        $end    = (string)($l['step_deadline'] ?? '');
        $t      = $terms[(int)$l['crew_id']] ?? null;
        $unit   = strtolower(trim((string)($t['rate_unit'] ?? 'day')));
        $perSec = str_contains($unit, 'sec');

        if ($perSec) {
            // Counted in the seconds pool below, from the step's own footage.
            // A per-second lane consumes no part of the day pool, by David's
            // instruction and because it genuinely has no day rate to consume it
            // with.
            $secondsLanes[$stepId] = true;
            continue;
        }

        // ⚠ A STEP WITHOUT BOTH DATES CANNOT BE MEASURED, and is NAMED rather
        // than counted as zero. A pool quietly missing a department reads as a
        // smaller, healthier show.
        if ($start === '' || $end === '') {
            $undated[$stepId] = (string)$l['step_name'];
            continue;
        }

        if (!isset($acc[$stepId])) {
            $acc[$stepId] = [
                'id' => $stepId, 'name' => (string)$l['step_name'],
                'color' => (string)($l['color'] ?? ''),
                'slots' => 0, 'filled' => 0,
                'pool' => 0.0, 'used_plan' => 0.0,
                'rates' => [], 'currency' => '',
            ];
        }
        $row =& $acc[$stepId];
        $row['slots']++;

        // THE POOL IS THE WHOLE WINDOW, INCLUDING SLOTS NOBODY HAS FILLED. The
        // plan is the plan: two heads for four weeks is forty days of pool
        // whether or not the second person has been hired.
        $row['pool'] += working_days_between($start, $end);

        // USED IS ONLY REAL PEOPLE, and the asymmetry is deliberate. An unfilled
        // slot burns nothing, so an understaffed step looks comfortably under
        // budget when it is actually heading for late — which is why the card
        // says "1 of 2 slots filled" whenever these two disagree. Without that
        // line the screen would quietly reward understaffing.
        if (empty($l['is_placeholder'])) {
            $row['filled']++;
            $upTo = min($today, $end);
            if ($upTo >= $start) $row['used_plan'] += working_days_between($start, $upTo);
        }

        if ($t && $t['day_rate'] !== null) {
            $row['rates'][] = (float)$t['day_rate'];
            $row['currency'] = strtoupper(trim((string)($t['rate_currency'] ?? 'USD'))) ?: 'USD';
        }
        unset($row);
    }

    // ---- the day pool ------------------------------------------------------
    $dayRows = [];
    $poolTotal = $usedTotal = 0.0;
    $workDone = $workTotal = 0.0;
    $currency = '';

    foreach ($acc as $stepId => $r) {
        $st = $byId[$stepId] ?? null;
        $target   = $st ? (float)$st['target'] : 0.0;
        $approved = $st ? (float)$st['approved_v'] : 0.0;
        $tracksSeconds = $st ? !empty($st['tracks_seconds']) : false;

        $used = $logged[$stepId] ?? $r['used_plan'];
        $pctUsed = $r['pool'] > 0 ? $used / $r['pool'] * 100 : 0.0;
        $pctDone = $target > 0 ? $approved / $target * 100 : 0.0;

        [$cls, $text] = pool_pace($pctUsed, $pctDone, $tracksSeconds ? 99 : $target);

        // One rate, or a spread. David, 2026-10-16: "at some point all the day
        // rates can be standardized per pipeline step... so all the slots for CH
        // layout will be 200 each". Saying which steps already agree is the map
        // of how far off that is.
        $rates = array_values(array_unique($r['rates']));
        sort($rates);

        $dayRows[] = $r + [
            'used'      => $used,
            'left'      => $r['pool'] - $used,
            'pct_used'  => $pctUsed,
            'pct_done'  => $pctDone,
            'done'      => $approved,
            'total'     => $target,
            'unit'      => $st ? (string)$st['unit'] : 'items',
            'pace_class' => $cls,
            'pace_text'  => $text,
            'rate_min'  => $rates ? $rates[0] : null,
            'rate_max'  => $rates ? $rates[count($rates) - 1] : null,
            'rate_same' => count($rates) === 1,
            'step_order' => $st ? (int)($st['step']['step_order'] ?? 0) : 0,
        ];

        $poolTotal += $r['pool'];
        $usedTotal += $used;
        // Seconds-tracked steps are left OUT of the headline item count: adding
        // 55 seconds to 84 shots makes a number that is not of anything.
        if (!$tracksSeconds) { $workDone += $approved; $workTotal += $target; }
        if ($currency === '' && $r['currency'] !== '') $currency = $r['currency'];
    }

    usort($dayRows, fn($a, $b) => [$a['step_order'], $a['name']] <=> [$b['step_order'], $b['name']]);

    // ---- the seconds pool --------------------------------------------------
    // A per-second person is paid for seconds APPROVED, so the pool is the
    // footage that step has to deliver and "used" is what has been approved.
    // Spend and progress are therefore the same measurement by construction,
    // which is why these cards carry no pace line: there is nothing to compare.
    $secRows = [];
    $secPool = $secUsed = 0.0;
    foreach (array_keys($secondsLanes) as $stepId) {
        $st = $byId[$stepId] ?? null;
        if (!$st) continue;
        $target   = (float)$st['target'];
        $approved = (float)$st['approved_v'];
        $rate = null;
        foreach ($lanes as $l) {
            if ((int)$l['step_id'] !== $stepId) continue;
            $t = $terms[(int)$l['crew_id']] ?? null;
            if ($t && $t['day_rate'] !== null
                && str_contains(strtolower((string)($t['rate_unit'] ?? '')), 'sec')) {
                $rate = (float)$t['day_rate'];
                if ($currency === '') $currency = strtoupper(trim((string)($t['rate_currency'] ?? 'USD'))) ?: 'USD';
            }
        }
        $secRows[] = [
            'id' => $stepId, 'name' => (string)$st['step']['name'],
            'color' => (string)($st['step']['color'] ?? ''),
            'pool' => $target, 'used' => $approved,
            'left' => $target - $approved,
            'pct_used' => $target > 0 ? $approved / $target * 100 : 0.0,
            'rate' => $rate,
            'tracks_seconds' => !empty($st['tracks_seconds']),
            'step_order' => (int)($st['step']['step_order'] ?? 0),
        ];
        $secPool += $target;
        $secUsed += $approved;
    }
    usort($secRows, fn($a, $b) => [$a['step_order'], $a['name']] <=> [$b['step_order'], $b['name']]);

    $pctUsedAll = $poolTotal > 0 ? $usedTotal / $poolTotal * 100 : 0.0;
    $pctDoneAll = $workTotal > 0 ? $workDone / $workTotal * 100 : 0.0;
    [$pcls, $ptext] = pool_pace($pctUsedAll, $pctDoneAll, $workTotal);

    return [
        'days' => [
            'pool' => $poolTotal, 'used' => $usedTotal, 'left' => $poolTotal - $usedTotal,
            'pct_used' => $pctUsedAll, 'rows' => $dayRows,
            'pace_class' => $pcls, 'pace_text' => $ptext,
        ],
        'seconds' => [
            'pool' => $secPool, 'used' => $secUsed, 'left' => $secPool - $secUsed,
            'pct_used' => $secPool > 0 ? $secUsed / $secPool * 100 : 0.0,
            'rows' => $secRows,
        ],
        'work'     => ['done' => $workDone, 'total' => $workTotal, 'pct' => $pctDoneAll],
        'source'   => $source,
        'undated'  => $undated,
        'hours_per_day' => $hpd,
        'currency' => $currency ?: 'USD',
    ];
}

/**
 * An interactive donut, as inline SVG: one segment per slice, and the figure in
 * the middle swaps to whichever segment the pointer is over (the small script
 * at the foot of the Budget pool tab, finance_project.php).
 *
 * Inline because this codebase ships no charting library and is not about to
 * start for a circle, the same reason grid_donut_svg() draws its own. Each
 * segment also carries a <title>, so the numbers are there on a touch screen and
 * to a screen reader, where nothing hovers.
 *
 * @param array  $slices [['value' => float, 'color' => css, 'big' => string,
 *                         'small' => string, 'tip' => string], ...]
 * @param string $big    the middle figure at rest
 * @param string $small  the line under it at rest
 */
function pool_donut_svg(array $slices, string $big, string $small, int $size = 150): string {
    $total = 0.0;
    foreach ($slices as $sl) $total += max(0.0, (float)$sl['value']);
    $c = 75; $ro = 66; $ri = 46;

    $svg = '<svg class="pool-donut" width="' . $size . '" height="' . $size . '" viewBox="0 0 150 150"'
         . ' role="img" aria-label="' . e($big . ' ' . $small) . '">';

    if ($total <= 0) {
        $svg .= '<circle cx="75" cy="75" r="56" fill="none" stroke="var(--border)" stroke-width="20"/>';
    } else {
        $angle = -M_PI / 2;   // twelve o'clock
        foreach ($slices as $sl) {
            $v = max(0.0, (float)$sl['value']);
            if ($v <= 0) continue;
            $frac = $v / $total;
            $attrs = ' class="pool-seg" data-big="' . e($sl['big']) . '" data-small="' . e($sl['small']) . '"';
            $title = '<title>' . e($sl['tip']) . '</title>';
            // A full circle cannot be one arc: its two ends coincide and the path
            // collapses to nothing. Draw a ring instead.
            if ($frac >= 0.9999) {
                $svg .= '<circle' . $attrs . ' cx="75" cy="75" r="56" fill="none" stroke="'
                      . e($sl['color']) . '" stroke-width="20">' . $title . '</circle>';
                break;
            }
            $end = $angle + $frac * 2 * M_PI;
            $large = ($end - $angle) > M_PI ? 1 : 0;
            $d = sprintf('M %.2f %.2f A %d %d 0 %d 1 %.2f %.2f L %.2f %.2f A %d %d 0 %d 0 %.2f %.2f Z',
                $c + $ro * cos($angle), $c + $ro * sin($angle), $ro, $ro, $large,
                $c + $ro * cos($end),   $c + $ro * sin($end),
                $c + $ri * cos($end),   $c + $ri * sin($end), $ri, $ri, $large,
                $c + $ri * cos($angle), $c + $ri * sin($angle));
            $svg .= '<path' . $attrs . ' d="' . $d . '" fill="' . e($sl['color']) . '">' . $title . '</path>';
            $angle = $end;
        }
    }

    $svg .= '<text class="pool-donut-big" x="75" y="74" text-anchor="middle" fill="var(--text)"'
          . ' data-rest="' . e($big) . '">' . e($big) . '</text>'
          . '<text class="pool-donut-small" x="75" y="94" text-anchor="middle" fill="var(--muted)"'
          . ' data-rest="' . e($small) . '">' . e($small) . '</text>'
          . '</svg>';
    return $svg;
}

/**
 * Days used against days still unused, as an interactive donut (David,
 * 2026-10-21: "show the remaining days unused ... when you hover the mouse they
 * should say how many days"). The unused part is its own segment, so it can be
 * pointed at, rather than being the empty track behind a progress ring.
 *
 * Red once the pool is overdrawn, the one state nobody may scroll past.
 */
function pool_days_donut_svg(float $used, float $pool, int $size = 150): string {
    $pct    = $pool > 0 ? $used / $pool * 100 : 0.0;
    $unused = max(0.0, $pool - $used);
    $over   = max(0.0, $used - $pool);
    $col    = $pct >= 100 ? 'var(--danger)' : ($pct >= 75 ? 'var(--warn)' : 'var(--ok)');
    $days   = function (float $n): string {
        return fmt_num($n, 1) . (abs($n - 1) < 0.05 ? ' day' : ' days');
    };

    $slices = [
        ['value' => $pool > 0 ? min($used, $pool) : $used, 'color' => $col,
         'big'   => fmt_num($used, 1),
         'small' => $over > 0 ? 'days used, ' . fmt_num($over, 1) . ' over' : 'days used',
         'tip'   => $days($used) . ' used' . ($over > 0 ? ', ' . $days($over) . ' over the pool' : '')],
        ['value' => $unused, 'color' => 'var(--border)',
         'big'   => fmt_num($unused, 1), 'small' => 'days unused',
         'tip'   => $days($unused) . ' still unused'],
    ];
    return pool_donut_svg($slices, (int)round($pct) . '%', 'of days used', $size);
}

/**
 * How many of a step's shots or assets sit in each status, for the pie beside
 * the days (David, 2026-10-21: "all the assets or shots in a pie chart next to
 * the days used that show how many assets/shots are in which status").
 *
 * Counts exactly what the card counts: the rows the step still covers
 * (progress_in_scope_sql()), against step_applicable_count() as the total, so
 * N/A items are left out and anything with no status yet is "Not started".
 *
 * @return array ['slices' => [...for pool_donut_svg], 'total' => int, 'noun' => string]
 */
function pool_status_slices(PDO $pdo, int $projectId, int $stepId): array {
    $q = $pdo->prepare('SELECT * FROM pipeline_steps WHERE id = ? AND project_id = ?');
    $q->execute([$stepId, $projectId]);
    $step = $q->fetch();
    if (!$step) return ['slices' => [], 'total' => 0, 'noun' => 'items'];

    $inScope = progress_in_scope_sql();
    $q = $pdo->prepare(
        "SELECT st.id, st.name, st.color, COALESCE(st.is_excluded, 0) AS is_excluded,
                COALESCE(st.sort_order, 9999) AS so, COUNT(*) AS n
           FROM progress pr
           JOIN pipeline_steps ps ON ps.id = pr.step_id
           LEFT JOIN statuses st  ON st.id = pr.status_id
           LEFT JOIN assets a     ON a.id  = pr.asset_id
          WHERE pr.step_id = ? AND {$inScope}
          GROUP BY st.id, st.name, st.color, st.is_excluded, st.sort_order
          ORDER BY so, st.name"
    );
    $q->execute([$stepId]);
    $rows = $q->fetchAll();

    $excluded = 0;
    foreach ($rows as $r) if ((int)$r['is_excluded'] === 1) $excluded += (int)$r['n'];

    $shotCount  = (int)$pdo->query('SELECT COUNT(*) FROM shots WHERE project_id = ' . (int)$projectId)->fetchColumn();
    $typeCounts = [];
    $assetCount = 0;
    $t = $pdo->prepare("SELECT COALESCE(asset_type,'') t, COUNT(*) n FROM assets WHERE project_id = ? GROUP BY asset_type");
    $t->execute([$projectId]);
    foreach ($t as $r) { $typeCounts[$r['t']] = (int)$r['n']; $assetCount += (int)$r['n']; }
    $total = step_applicable_count($step + ['excluded' => $excluded], $shotCount, $typeCounts, $assetCount);

    $nouns = ['shot' => ['shot', 'shots'], 'project' => ['deliverable', 'deliverables'],
              'both' => ['item', 'items'], 'asset' => ['asset', 'assets']];
    $noun = $nouns[$step['applies_to']] ?? ['item', 'items'];
    $word = function (int $n) use ($noun): string { return $n === 1 ? $noun[0] : $noun[1]; };

    $slices  = [];
    $counted = 0;
    foreach ($rows as $r) {
        // N/A leaves the step, and a row with no status is counted as not started below.
        if ((int)$r['is_excluded'] === 1 || $r['id'] === null) continue;
        $n = (int)$r['n'];
        $counted += $n;
        $slices[] = ['value' => $n, 'color' => $r['color'] ?: 'var(--step-default)',
                     'big' => (string)$n, 'small' => (string)$r['name'],
                     'tip' => $r['name'] . ', ' . $n . ' ' . $word($n)];
    }
    // Everything the step covers with no status yet: no progress row at all, or a
    // row with no status on it. Not started either way.
    $notStarted = max(0, $total - $counted);
    if ($notStarted > 0) {
        $slices[] = ['value' => $notStarted, 'color' => 'var(--border)',
                     'big' => (string)$notStarted, 'small' => 'not started',
                     'tip' => 'Not started, ' . $notStarted . ' ' . $word($notStarted)];
    }
    return ['slices' => $slices, 'total' => $total, 'noun' => $word($total)];
}
