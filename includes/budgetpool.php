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
// we need in days." So the pool is DERIVED on every page load. There is no
// budget_pool table: a stored copy would be a fourth number claiming to describe
// the same plan, and this codebase has paid for that before.
//
// ⚠ THE POOL IS THE EXPENSE PROJECTION'S DAYS, NOT THE CALENDAR (2026-10-29).
// It used to be each slot times the working days in its step's window. On MSD
// that made a 269-day pool beside a 219-day projection, and David asked why
// they disagreed. They measured different things: the window is the time the
// dates ALLOW, the projection is the work there IS (items x days per item x
// retakes, a whole day per person). Freelancers are paid for the days they
// work, so the projection is what the show costs, and the pool now reads its
// days row for row — the two screens cannot disagree by construction. The
// window is still worked out, as CAPACITY beside it: "needs 48, dates allow 36"
// is the warning that a step does not fit its dates, which the old pool hid
// by being the dates.
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
require_once __DIR__ . '/finance.php';   // expense_projection(), which the pool reads

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
 * $proj is expense_projection() for this project when the caller already has
 * it (the kick-off snapshot does), so it is not worked out twice.
 *
 * @return array{
 *   days: array (pool, used, left, window, short, rows...), seconds: array,
 *   work: array, source: string,
 *   unlogged: string[], undated: array, has_money: bool, currency: string
 * }
 */
function budget_pool(PDO $pdo, array $project, ?array $proj = null): array {
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
    // A position's own contract wins over the person's default
    // (finance_install_13, 2026-10-29), as in expense_projection().
    $posTerms = $crewIds ? position_terms_for($crewIds, (int)$projectId) : [];

    // ⚠ USED IS LOGGED TIME, AND NOTHING ELSE (David, 2026-10-29: "let's start
    // using logged time to subtract from the total budget pool instead of using
    // the start dates"). Before this a show with no logs at all read its used
    // days off the calendar, as if everybody had worked from the day their step
    // opened. Artists log every day, so a step with nothing logged has used
    // nothing on record, and the screen says so rather than guessing.
    $logged  = pool_logged_days($pdo, $projectId, $hpd);
    $source  = 'logged';

    // step id => accumulating row
    $acc = [];
    $undated = [];      // steps in the pool with no window to check them against
    $secondsLanes = []; // step id => true, for lanes paid by the second

    // ONE ROW OF THE PROJECTION IS ONE LANE: a person or a slot on a step, or a
    // step nobody stands in yet. Read row for row, so the pool's days are the
    // projection's days exactly, with the same rounding and the same contracts.
    $proj = $proj ?? expense_projection($pdo, $projectId);
    foreach ($proj['rows'] as $pr) {
        $stepId = (int)$pr['step']['id'];
        // Supervision (a negative id) is a person for a stretch of dates, not a
        // step with work in it; it has never been part of the pool.
        if ($stepId <= 0) continue;
        $unit = strtolower(trim((string)($pr['rate_unit'] ?? 'day')));

        if (str_contains($unit, 'sec')) {
            // Counted in the seconds pool below, from the step's own footage.
            // A per-second lane consumes no part of the day pool, by David's
            // instruction and because it genuinely has no day rate to consume it
            // with.
            $secondsLanes[$stepId] = true;
            continue;
        }

        $start = (string)($pr['step']['start_date'] ?? '');
        $end   = (string)($pr['step']['deadline'] ?? '');
        $dated = $start !== '' && $end !== '';
        // ⚠ A STEP WITHOUT BOTH DATES IS STILL IN THE POOL — its work needs no
        // dates — but its capacity cannot be checked, so it is NAMED rather than
        // shown as fitting.
        if (!$dated) $undated[$stepId] = (string)$pr['step']['name'];

        if (!isset($acc[$stepId])) {
            $acc[$stepId] = [
                'id' => $stepId, 'name' => (string)$pr['step']['name'],
                'color' => (string)($pr['step']['color'] ?? ''),
                'slots' => 0, 'filled' => 0,
                'pool' => 0.0, 'window' => $dated ? 0.0 : null, 'lane_days' => 0.0,
                'rates' => [], 'currency' => '',
            ];
        }
        $row =& $acc[$stepId];
        $laneDays = (float)$pr['days'];
        $window   = $dated ? working_days_between($start, $end) : 0;
        if ($pr['crew_id']) $row['slots']++;
        $row['lane_days'] = $laneDays;   // the same for every lane of a step

        // THE POOL COUNTS SLOTS NOBODY HAS FILLED. The plan is the plan: the
        // second animator's share of the work has to be paid for whether or not
        // they have been hired yet.
        $row['pool'] += $laneDays;
        if ($dated) $row['window'] += $window;

        // USED IS ONLY REAL PEOPLE, and the asymmetry is deliberate. An unfilled
        // slot burns nothing, so an understaffed step looks comfortably under
        // budget when it is actually heading for late — which is why the card
        // says "1 of 2 slots filled" whenever these two disagree. Without that
        // line the screen would quietly reward understaffing.
        if ($pr['crew_id'] && empty($pr['is_slot'])) $row['filled']++;

        // A DAY RATE ONLY. A flat fee or an hourly rate is not "a day", and the
        // card used to print Grafiart's 1,500 flat as "180—1,500 a day".
        if ($pr['rate'] !== null && $unit === 'day') {
            $row['rates'][] = (float)$pr['rate'];
            $row['currency'] = strtoupper(trim((string)($pr['currency'] ?? 'USD'))) ?: 'USD';
        }
        unset($row);
    }

    // ---- the day pool ------------------------------------------------------
    $dayRows = [];
    $poolTotal = $usedTotal = 0.0;
    $workDone = $workTotal = 0.0;
    $currency = '';
    $unloggedNames = [];   // steps reading 0 used because nobody logged on them
    $windowTotal = 0.0;    // what the dates allow, across the dated steps
    $shortNames  = [];     // steps whose work does not fit their dates

    foreach ($acc as $stepId => $r) {
        $st = $byId[$stepId] ?? null;
        $target   = $st ? (float)$st['target'] : 0.0;
        $approved = $st ? (float)$st['approved_v'] : 0.0;
        $tracksSeconds = $st ? !empty($st['tracks_seconds']) : false;

        // ⚠ A STEP WITH NOTHING LOGGED HAS USED NOTHING ON RECORD, and says so.
        // It once fell back to the calendar step by step, so MSD's headline read
        // "180.4 days used, from logged hours" when only about 6 had been logged
        // (David, 2026-10-29). A zero that says "nothing logged" is the honest
        // figure: artists log every day, and the gap is the thing to chase.
        $unlogged = !isset($logged[$stepId]);
        $used = $logged[$stepId] ?? 0.0;
        $pctUsed = $r['pool'] > 0 ? $used / $r['pool'] * 100 : 0.0;
        $pctDone = $target > 0 ? $approved / $target * 100 : 0.0;

        [$cls, $text] = $unlogged
            ? ['muted', 'no time logged on this step yet']
            : pool_pace($pctUsed, $pctDone, $tracksSeconds ? 99 : $target);
        if ($unlogged) $unloggedNames[] = (string)$r['name'];

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
            'unlogged'  => $unlogged,
            'rate_min'  => $rates ? $rates[0] : null,
            'rate_max'  => $rates ? $rates[count($rates) - 1] : null,
            'rate_same' => count($rates) === 1,
            'step_order' => $st ? (int)($st['step']['step_order'] ?? 0) : 0,
            // DOES THE WORK FIT THE DATES? How many person-days the work needs
            // beyond what its slots times its window allow; 0 when it fits, and
            // null when the step has no dates to check against. MSD's BG Color
            // needs 48 and its dates allow 36: 12 short, so either the dates move,
            // a head is added, or the days per item are wrong.
            'short'     => $r['window'] === null ? null : max(0.0, $r['pool'] - $r['window']),
        ];

        if ($r['window'] !== null) {
            $windowTotal += $r['window'];
            if ($r['pool'] > $r['window'] + 0.0001) $shortNames[] = (string)$r['name'];
        }
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
            $t = $posTerms[(int)$l['crew_id'] . '|' . $stepId] ?? ($terms[(int)$l['crew_id']] ?? null);
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
            // Capacity: slots x windows on the dated steps, and which steps need
            // more than theirs allow. Beside the pool, never instead of it.
            'window' => $windowTotal, 'short' => $shortNames,
        ],
        'seconds' => [
            'pool' => $secPool, 'used' => $secUsed, 'left' => $secPool - $secUsed,
            'pct_used' => $secPool > 0 ? $secUsed / $secPool * 100 : 0.0,
            'rows' => $secRows,
        ],
        'work'     => ['done' => $workDone, 'total' => $workTotal, 'pct' => $pctDoneAll],
        'source'   => $source,
        'unlogged' => $unloggedNames,
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

/**
 * Who is working on one step, and how each person stands against their share of
 * the pool (David, 2026-10-21: "a list to show who is working on it and how much
 * has been spent on that person vs. budget, so management can spot who is
 * over/under budget at a glance").
 *
 * Per person, built from the same pieces the card is built from, so the rows add
 * up to the card:
 *   budget   their share of the step's projected work in days ($laneDays, the
 *            card's lane_days; the expense projection's Days column), and that
 *            times their day rate. Without $laneDays, the old measure: their
 *            slot's window in working days
 *   used     their logged days on this step, and nothing else: no logs is 0
 *            (2026-10-29), as on the card
 *   invoiced what they have actually billed against this position (invoices)
 *   work     the step's items assigned to them, and how many are approved
 *
 * The reading: OVER BUDGET whenever used passes budget; otherwise the same
 * days-against-progress comparison the card makes (pool_pace()), on their own
 * items. Somebody with nothing assigned is read on days alone.
 *
 * Per-second lanes are left out, as they are from the day pool: they have no
 * day budget to be over. Two slots held by one person are one row.
 *
 * @param string $source from budget_pool(); always 'logged' since 2026-10-29
 * @return array ['rows' => [...], 'totals' => [...], 'currency' => string]
 */
function pool_step_people(PDO $pdo, array $project, int $stepId, string $source,
                          ?float $laneDays = null): array {
    $projectId = (int)$project['id'];
    $today = date('Y-m-d');
    $hpd   = hours_per_day($pdo, $projectId);

    $lanes = array_values(array_filter(project_lanes($pdo, $projectId),
        fn($l) => (int)$l['step_id'] === $stepId));
    $crewIds = array_values(array_unique(array_map(fn($l) => (int)$l['crew_id'], $lanes)));
    $terms   = $crewIds ? crew_terms($crewIds) : [];
    // A position's own contract wins over the person's default
    // (finance_install_13, 2026-10-29), as in expense_projection().
    $posTerms = $crewIds ? position_terms_for($crewIds, (int)$projectId) : [];
    $billed  = function_exists('invoiced_by_position') ? invoiced_by_position($projectId) : [];

    // Logged days per person on this step, through the progress row the time
    // was logged against (the same link pool_logged_days() follows).
    $logged = [];
    if ($hpd > 0) {
        try {
            $q = $pdo->prepare(
                'SELECT tl.crew_id, COALESCE(SUM(tl.minutes), 0) AS mins
                   FROM time_logs tl JOIN progress pr ON pr.id = tl.progress_id
                  WHERE pr.project_id = ? AND pr.step_id = ?
                  GROUP BY tl.crew_id'
            );
            $q->execute([$projectId, $stepId]);
            foreach ($q as $r) $logged[(int)$r['crew_id']] = ((float)$r['mins'] / 60) / $hpd;
        } catch (PDOException $e) {
            $logged = [];
        }
    }

    // The step's items assigned to each person, and how many are approved: the
    // rows the step still covers, N/A left out, primary assignee or a second name.
    $work = [];
    if ($crewIds) {
        $ids = implode(',', array_map('intval', $crewIds));
        $inScope = progress_in_scope_sql();
        $q = $pdo->prepare(
            "SELECT c.crew_id, COUNT(DISTINCT pr.id) AS n,
                    COUNT(DISTINCT CASE WHEN pr.is_approved = 1 THEN pr.id END) AS done
               FROM progress pr
               JOIN pipeline_steps ps ON ps.id = pr.step_id
               LEFT JOIN statuses st  ON st.id = pr.status_id
               LEFT JOIN assets a     ON a.id  = pr.asset_id
               JOIN (SELECT id AS progress_id, crew_id FROM progress
                      WHERE step_id = ? AND crew_id IN ({$ids})
                     UNION
                     SELECT pc.progress_id, pc.crew_id FROM progress_crew pc
                      WHERE pc.crew_id IN ({$ids})) c ON c.progress_id = pr.id
              WHERE pr.step_id = ? AND {$inScope} AND COALESCE(st.is_excluded, 0) = 0
              GROUP BY c.crew_id"
        );
        try {
            $q->execute([$stepId, $stepId]);
            foreach ($q as $r) $work[(int)$r['crew_id']] = ['n' => (int)$r['n'], 'done' => (int)$r['done']];
        } catch (PDOException $e) {
            $work = [];
        }
    }

    $people = [];
    $currency = '';
    foreach ($lanes as $l) {
        $cid  = (int)$l['crew_id'];
        $t    = $posTerms[$cid . '|' . $stepId] ?? ($terms[$cid] ?? null);
        $unit = strtolower(trim((string)($t['rate_unit'] ?? 'day')));
        if (str_contains($unit, 'sec')) continue;     // no day budget to be over
        $start = (string)($l['step_start'] ?? '');
        $end   = (string)($l['step_deadline'] ?? '');
        $dated = $start !== '' && $end !== '';
        // With no projected share to go on, an undated slot has no budget at all.
        if (!$dated && $laneDays === null) continue;   // named as undated on the card
        $window = $dated ? working_days_between($start, $end) : 0;

        $slot = !empty($l['is_placeholder']);
        $key  = $slot ? 'slot' . (int)$l['id'] : 'c' . $cid;
        if (!isset($people[$key])) {
            $rate = ($t && $t['day_rate'] !== null && $unit === 'day') ? (float)$t['day_rate'] : null;
            if ($rate !== null && $currency === '') {
                $currency = strtoupper(trim((string)($t['rate_currency'] ?? 'USD'))) ?: 'USD';
            }
            $people[$key] = [
                'crew_id' => $cid, 'name' => (string)$l['name'], 'is_slot' => $slot,
                'rate' => $rate, 'rate_unit' => $t ? $unit : null,
                'budget_days' => 0.0, 'used_days' => 0.0,
                'invoiced' => $slot ? null : ($billed[$cid . '|' . $stepId] ?? null),
                'items' => $work[$cid]['n'] ?? 0, 'done' => $work[$cid]['done'] ?? 0,
            ];
        }
        // Their share of the projected work, as the card counts it (2026-10-29).
        $share = $laneDays ?? (float)$window;
        $people[$key]['budget_days'] += $share;
    }

    $rows = [];
    $tot = ['budget_days' => 0.0, 'used_days' => 0.0, 'budget_money' => 0.0, 'used_money' => 0.0,
            'invoiced' => 0.0, 'priced' => true];
    foreach ($people as $p) {
        // An unfilled slot burns nothing; a person has used what they logged.
        if (!$p['is_slot']) $p['used_days'] = $logged[$p['crew_id']] ?? 0.0;
        $p['left_days']    = $p['budget_days'] - $p['used_days'];
        $p['budget_money'] = $p['rate'] !== null ? round($p['budget_days'] * $p['rate'], 2) : null;
        $p['used_money']   = $p['rate'] !== null ? round($p['used_days'] * $p['rate'], 2) : null;
        $p['left_money']   = $p['rate'] !== null ? round($p['left_days'] * $p['rate'], 2) : null;
        $pctUsed = $p['budget_days'] > 0 ? $p['used_days'] / $p['budget_days'] * 100 : 0.0;
        $pctDone = $p['items'] > 0 ? $p['done'] / $p['items'] * 100 : 0.0;

        if ($p['is_slot']) {
            [$cls, $text] = ['warn-text', 'nobody in this slot yet, so nothing is being used'];
        } elseif ($p['used_days'] > $p['budget_days'] + 0.05) {
            $cls  = 'err';
            $text = 'over budget by ' . fmt_num($p['used_days'] - $p['budget_days'], 1) . ' days';
        } elseif ($p['items'] > 0) {
            [$cls, $text] = pool_pace($pctUsed, $pctDone, (float)$p['items']);
        } else {
            [$cls, $text] = ['muted', sprintf('%d%% of their days used, no items assigned to them yet',
                                              round($pctUsed))];
        }
        $p['pct_used'] = $pctUsed;
        $p['reading_class'] = $cls;
        $p['reading'] = $text;
        $rows[] = $p;

        $tot['budget_days'] += $p['budget_days'];
        $tot['used_days']   += $p['used_days'];
        $tot['invoiced']    += (float)($p['invoiced'] ?? 0);
        if ($p['rate'] === null) { $tot['priced'] = false; continue; }
        $tot['budget_money'] += $p['budget_money'];
        $tot['used_money']   += $p['used_money'];
    }
    // Real people first, worst first, so the one to talk to is at the top.
    usort($rows, function ($a, $b) {
        if ($a['is_slot'] !== $b['is_slot']) return $a['is_slot'] ? 1 : -1;
        return $b['pct_used'] <=> $a['pct_used'] ?: strcasecmp($a['name'], $b['name']);
    });
    return ['rows' => $rows, 'totals' => $tot, 'currency' => $currency ?: 'USD'];
}
