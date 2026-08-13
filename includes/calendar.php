<?php
// Which days the studio actually works.
//
// Until now that meant Mon-Fri and nothing else, which quietly counted every
// Mexican public holiday as a working day. The error is always optimistic — a
// plan looks more achievable than it is — and it was never confined to the
// scheduler: the quota on the wizard's review screen divides by exactly these
// numbers.
//
// Loaded once per request and cached. The set is tiny (a dozen rows a year) and
// working_days_between() is called in a loop over every step, so re-reading it
// per call would be the one genuinely wasteful query in the app.

/**
 * Every non-working day, as a YYYY-MM-DD => name map.
 *
 * Returns an empty set rather than throwing when the table doesn't exist yet:
 * that means migration 010 is still pending, and refusing to draw a schedule
 * over a missing calendar would be a worse failure than drawing one without
 * holidays. The screens say when the calendar is empty.
 */
function non_working_days(?PDO $db = null): array {
    static $days = null;
    if ($days !== null) return $days;

    $db = $db ?? ($GLOBALS['pdo'] ?? null);
    if (!($db instanceof PDO)) return [];        // no DB yet; don't cache the miss

    try {
        $days = $db->query('SELECT day, name FROM non_working_days')
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $days = [];
    }
    return $days;
}

/** Mon-Fri and not a holiday. */
function is_working_day(string $date, ?array $holidays = null): bool {
    $holidays = $holidays ?? non_working_days();
    if (isset($holidays[$date])) return false;
    return (int)(new DateTime($date))->format('N') < 6;
}

/**
 * The next working day on or after $date.
 *
 * The 400-iteration cap is a guard against a calendar that has somehow been
 * filled with every day of the year: a scheduler that hangs is far harder to
 * diagnose than one that returns a date and reports the problem.
 */
function next_working_day(string $date, ?array $holidays = null): string {
    $holidays = $holidays ?? non_working_days();
    $d = new DateTime($date);
    for ($i = 0; $i < 400; $i++) {
        $s = $d->format('Y-m-d');
        if (is_working_day($s, $holidays)) return $s;
        $d->modify('+1 day');
    }
    return $date;
}

/**
 * A running list of working days from an anchor, extended on demand.
 *
 * The scheduler works in whole and PART days — "0.5 days per item" is something
 * the planning screen explicitly invites — so it needs to address working days
 * by index (day 0, day 1, day 17.5) and convert to real dates only at the end.
 * Two half-day tasks then land on the same date, which is what actually happens,
 * instead of each being rounded up to a day and doubling the step's length.
 *
 * $limit caps how far ahead it will ever build, so an absurd plan (a step with
 * no deadline and one lane for 10,000 items) ends with a reported problem rather
 * than an out-of-memory error.
 */
function working_day_axis(string $anchor, ?array $holidays = null, int $limit = 3000): array {
    $holidays = $holidays ?? non_working_days();
    return ['anchor' => $anchor, 'days' => [], 'cursor' => new DateTime($anchor),
            'holidays' => $holidays, 'limit' => $limit, 'exhausted' => false];
}

/**
 * The date of working day number $index (0-based) on that axis, building the
 * list out as far as it needs to go. Returns null once the cap is hit.
 */
function axis_date(array &$axis, int $index): ?string {
    if ($index < 0) $index = 0;
    while (count($axis['days']) <= $index) {
        if (count($axis['days']) >= $axis['limit']) {
            $axis['exhausted'] = true;
            return null;
        }
        $s = $axis['cursor']->format('Y-m-d');
        if (is_working_day($s, $axis['holidays'])) $axis['days'][] = $s;
        $axis['cursor']->modify('+1 day');
    }
    return $axis['days'][$index];
}

/**
 * Where a date sits on the axis: the index of the first working day on or after
 * it. Used to convert a step's start date or a deadline into axis terms.
 * Returns null if the date lies beyond the cap.
 */
function axis_index(array &$axis, string $date): ?int {
    if ($date <= $axis['anchor']) return 0;
    for ($i = 0; ; $i++) {
        $d = axis_date($axis, $i);
        if ($d === null) return null;
        if ($d >= $date) return $i;
    }
}

/**
 * Working days strictly AFTER $after, up to and including $to. Zero if $to is on
 * or before $after.
 *
 * This is how late something is. Counting inclusively from the deadline and
 * subtracting one gets the same answer only when the deadline is itself a
 * working day — on a deadline that falls on a Sunday it silently reports a day
 * fewer than reality, which is the optimistic direction this file exists to
 * remove.
 */
function working_days_after(string $after, string $to): int {
    if ($to <= $after) return 0;
    $from = (new DateTime($after))->modify('+1 day')->format('Y-m-d');
    return working_days_between($from, $to);
}

/**
 * How many holidays fall inside a range — for saying WHY a span is shorter than
 * the calendar suggests. "20 working days (2 public holidays)" is a fact someone
 * can check; "20 working days" alone invites a recount by hand.
 */
function holidays_between(string $from, string $to, ?array $holidays = null): array {
    $holidays = $holidays ?? non_working_days();
    $out = [];
    foreach ($holidays as $day => $name) {
        if ($day < $from || $day > $to) continue;
        // A holiday landing on a Saturday costs nothing, so counting it would
        // overstate the loss.
        if ((int)(new DateTime($day))->format('N') >= 6) continue;
        $out[$day] = $name;
    }
    ksort($out);
    return $out;
}
