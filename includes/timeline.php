<?php
// Drawing the schedule as a Gantt chart.
//
// Server-side SVG, no library — the same choice workflow_diagram_svg() made and
// for the same reasons: nothing to load, nothing to cache-bust, and it prints.
// Two more reasons specific to this chart:
//
//   * STFC drew its timeline with Frappe Gantt, which treats `end` as inclusive
//     and extends it internally while the PHP was also adding a day. Two
//     compensating errors that hid each other for weeks (lesson 4). Owning the
//     conversion means there is exactly one place it can be wrong.
//   * A real show is a four-figure number of tasks. Handing that to a JS chart
//     library is a hang; grouping it server-side into one row per crew slot is
//     forty rows and a picture somebody can actually read.
//
// EVERY date here is handled as a string or a DateTime, never parsed in the
// browser — 'YYYY-MM-DD' through JS's Date() reads as UTC and walks backwards a
// day in a UTC-6 timezone (lesson 3).

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/calendar.php';

/** Whole days between two YYYY-MM-DD dates (calendar days, not working days). */
function timeline_day_offset(string $from, string $date): int {
    $a = new DateTime($from);
    $b = new DateTime($date);
    return (int)$a->diff($b)->format('%r%a');
}

/**
 * Group stored schedule rows into the rows of the chart.
 *
 * One row per crew slot — per (step, lane) — because that is the unit a producer
 * reads a timeline in: "who is doing what, when". It is also exactly the shape
 * the expense projection wants, so the two never disagree about how many people
 * a show needs.
 *
 * Rows with no lane information (a band, a whole-project deliverable) become a
 * row of their own for that step.
 */
function timeline_rows(array $schedule): array {
    $rows = [];
    foreach ($schedule as $t) {
        $key = $t['step_id'] . '|' . (int)($t['lane'] ?? 1);
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'step_id'   => (int)$t['step_id'],
                'step_name' => $t['step_name'],
                'color'     => $t['color'] ?: '#8a97a3',
                'lane'      => (int)($t['lane'] ?? 1),
                'label'     => $t['crew_name'] ?: $t['step_name'],
                'tasks'     => [],
                'start'     => $t['start_date'],
                'end'       => $t['end_date'],
                'manual'    => 0,
            ];
        }
        $r =& $rows[$key];
        $r['tasks'][] = $t;
        if ($t['start_date'] < $r['start']) $r['start'] = $t['start_date'];
        if ($t['end_date']   > $r['end'])   $r['end']   = $t['end_date'];
        if ((int)$t['is_manual'] === 1) $r['manual']++;
        unset($r);
    }
    return array_values($rows);
}

/**
 * The chart.
 *
 * $opts: today, delivery_date, deadlines (step_id => date), width.
 * Returns SVG markup, or '' when there is nothing to draw.
 */
function timeline_svg(array $rows, array $opts = []): string {
    if (!$rows) return '';

    $today    = $opts['today'] ?? date('Y-m-d');
    $delivery = $opts['delivery_date'] ?? null;
    $deadlines = $opts['deadlines'] ?? [];
    $holidays = non_working_days();

    $min = null; $max = null;
    foreach ($rows as $r) {
        if ($min === null || $r['start'] < $min) $min = $r['start'];
        if ($max === null || $r['end']   > $max) $max = $r['end'];
    }
    // The chart should show today and the delivery date even when no work sits
    // on them — a deadline off the edge of the picture is the one thing a
    // timeline must not hide.
    foreach ([$today, $delivery] as $d) {
        if (!$d) continue;
        if ($d < $min) $min = $d;
        if ($d > $max) $max = $d;
    }

    // Start the axis on the Monday of the first week so the week grid lines up
    // with weeks rather than with an arbitrary first task.
    $startDt = new DateTime($min);
    $startDt->modify('-' . ((int)$startDt->format('N') - 1) . ' days');
    $axisStart = $startDt->format('Y-m-d');
    $totalDays = timeline_day_offset($axisStart, $max) + 1;
    if ($totalDays < 1) $totalDays = 1;

    $gutter = 176;
    $rowH   = 22;
    $headH  = 34;
    $padY   = 6;
    // Fit a sensible page width, but never squeeze a day below a hairline or
    // stretch a two-week project across a wall.
    $maxPlot = max(420, (int)($opts['width'] ?? 1180) - $gutter);
    $pxDay   = $maxPlot / $totalDays;
    if ($pxDay > 14) $pxDay = 14;
    if ($pxDay < 1.4) $pxDay = 1.4;

    $plotW  = (int)ceil($totalDays * $pxDay);
    $width  = $gutter + $plotW + 8;
    $height = $headH + count($rows) * $rowH + $padY * 2;

    $x = function (string $date) use ($axisStart, $pxDay, $gutter) {
        return $gutter + timeline_day_offset($axisStart, $date) * $pxDay;
    };

    $svg  = '<svg class="gantt" viewBox="0 0 ' . $width . ' ' . $height . '" '
          . 'width="' . $width . '" height="' . $height . '" '
          . 'xmlns="http://www.w3.org/2000/svg" role="img" '
          . 'aria-label="Project timeline">';

    // --- weekends and non-working days ---------------------------------------
    // Shaded first so everything else sits on top. Holidays get the same
    // treatment as weekends because that is what they are now (migration 010).
    $d = new DateTime($axisStart);
    for ($i = 0; $i < $totalDays; $i++) {
        $s = $d->format('Y-m-d');
        $dow = (int)$d->format('N');
        if ($dow >= 6 || isset($holidays[$s])) {
            $svg .= '<rect x="' . round($gutter + $i * $pxDay, 2) . '" y="' . $headH
                  . '" width="' . round($pxDay, 2) . '" height="' . ($height - $headH - $padY)
                  . '" class="' . (isset($holidays[$s]) ? 'g-holiday' : 'g-weekend') . '" />';
        }
        $d->modify('+1 day');
    }

    // --- month labels and gridlines ------------------------------------------
    $d = new DateTime($axisStart);
    $d->modify('first day of this month');
    while ($d->format('Y-m-d') <= $max) {
        $s = $d->format('Y-m-d');
        if ($s >= $axisStart) {
            $mx = round($x($s), 2);
            $svg .= '<line x1="' . $mx . '" y1="' . $headH . '" x2="' . $mx . '" y2="'
                  . ($height - $padY) . '" class="g-month" />'
                  . '<text x="' . ($mx + 4) . '" y="22" class="g-month-label">'
                  . e($d->format('M Y')) . '</text>';
        }
        $d->modify('+1 month');
    }

    // --- rows -----------------------------------------------------------------
    $y = $headH;
    foreach ($rows as $r) {
        $svg .= '<rect x="0" y="' . $y . '" width="' . $width . '" height="' . $rowH
              . '" class="g-row" />';

        $label = $r['label'];
        if (mb_strlen($label) > 24) $label = mb_substr($label, 0, 23) . '…';
        $svg .= '<text x="8" y="' . ($y + 15) . '" class="g-label">'
              . '<title>' . e($r['label'] . ' · ' . $r['step_name']) . '</title>'
              . e($label) . '</text>';

        // One rect per task. They are packed end to end on a lane, so drawing
        // them individually shows the gaps — which is where a lane is idle, and
        // the thing worth seeing.
        foreach ($r['tasks'] as $t) {
            $bx = $x($t['start_date']);
            // +1 because end_date is INCLUSIVE everywhere in this platform, so a
            // task starting and ending on the same day is one day wide. This is
            // the ONLY place that conversion happens — STFC had PHP adding a day
            // and Frappe Gantt adding another, and the two hid each other.
            $days = timeline_day_offset($t['start_date'], $t['end_date']) + 1;
            $bw   = max(1.6, $days * $pxDay);
            $cls = 'g-bar' . ((int)$t['is_manual'] === 1 ? ' g-bar-manual' : '');

            $name = $t['shot_code'] ?? ($t['asset_name'] ?? null);
            if ($name === null) $name = $t['step_name'];
            $svg .= '<rect x="' . round($bx, 2) . '" y="' . ($y + 4) . '" width="' . round($bw, 2)
                  . '" height="' . ($rowH - 9) . '" rx="2" fill="' . e($r['color']) . '" class="' . $cls . '">'
                  . '<title>' . e($name . ' · ' . $t['start_date'] . ' → ' . $t['end_date']) . '</title>'
                  . '</rect>';
        }
        $y += $rowH;
    }

    // --- deadline and today markers ------------------------------------------
    // Drawn last so they are never hidden behind a bar. A date you are working
    // towards has to be the most visible thing on the chart.
    if ($delivery && $delivery >= $axisStart) {
        $dx = round($x($delivery), 2);
        $svg .= '<line x1="' . $dx . '" y1="' . ($headH - 8) . '" x2="' . $dx . '" y2="'
              . ($height - $padY) . '" class="g-delivery" />'
              . '<text x="' . ($dx - 4) . '" y="' . ($headH - 12) . '" class="g-delivery-label" '
              . 'text-anchor="end">delivery</text>';
    }
    if ($today >= $axisStart && $today <= $max) {
        $tx = round($x($today), 2);
        $svg .= '<line x1="' . $tx . '" y1="' . ($headH - 8) . '" x2="' . $tx . '" y2="'
              . ($height - $padY) . '" class="g-today" />'
              . '<text x="' . ($tx + 4) . '" y="' . ($headH - 12) . '" class="g-today-label">today</text>';
    }

    return $svg . '</svg>';
}
