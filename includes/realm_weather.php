<?php
// The Realm: each show's sky (no migration).
//
// David, 2026-11-13, chose "shows that react to production": the land a show
// has claimed shows the state of that show at a glance. Fireworks over the camp
// when work is approved, storm clouds as the delivery date closes in with work
// still open. "You'd see the state of every show at a glance."
//
// What he settled the same day, and is relied on below:
//   * THE SKY IS ANIMA'S OWN VERDICT, NOT A NEW ONE. "Anima's own rule": the
//     same green / amber / red that colours a cell of the breakdown grid
//     (cell_health() in includes/dashboard.php), applied to the whole show:
//     work approved against working days used between the show's start and
//     delivery dates. Green is a clear sky, amber clouds, red rain. A STORM is
//     the one thing the grid has no colour for: the delivery date has passed
//     and work is still open. No delivery date, or too early to judge, is a
//     clear sky: the map does not invent worry Anima itself would not show.
//   * FIREWORKS: "Approvals today + milestones". Small fireworks over the camp
//     whenever a task on the show was approved in the last hour (Anima's status
//     log, progress_log, migration 021). A big burst and a banner for the rest
//     of the day when the show crosses 25, 50, 75 or 100% approved today.
//   * EVERYONE SEES EVERY SKY. "Everyone in the Realm". Only the MOOD travels,
//     though: three words and a milestone, never a count, and the land of a show
//     you are not on is still called "Another show" (realm_lands()).
//
// THE NUMBERS ARE THE SLAYING BAR'S. The percentage is projects_overall_pct(),
// the very call the bar in a camp makes (realm_camp_progress()), so a camp's
// bar and the sky over it cannot disagree.
//
// ⚠ CHEAP, BECAUSE IT RIDES THE HEARTBEAT. realm_state() is answered every 25
// seconds for everybody on the page. Working the skies out costs four queries
// for all five shows together (three in projects_overall_pct(), one over
// today's lines of progress_log, which has an index on project and date), and
// even that is done at most once every REALM_SKY_SECONDS for the whole studio:
// the answer is kept in a small file in the server's temporary folder. It holds
// only the five land names and their weather words; nothing about a person.
//
// ⚠ NOTHING IS WRITTEN TO ANIMA. Every figure is read; no status, date or
// count is ever changed to make a sky look better.

/** How long one working-out of the skies is reused, in seconds. */
const REALM_SKY_SECONDS = 90;

/** A clear sky, no fireworks: what every land shows when anything goes wrong. */
function realm_sky_none(): array {
    return ['w' => 'clear', 'cheer' => false, 'mile' => 0];
}

/**
 * The sky over every claimed land: [land => ['w' => clear|cloud|rain|storm,
 * 'cheer' => approved in the last hour, 'mile' => 25|50|75|100 crossed today, or 0]].
 * Never throws; a land it cannot work out is simply clear.
 */
function realm_skies(PDO $pdo): array {
    $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'anima_realm_sky_' . md5(__DIR__) . '.json';
    try {
        if (is_file($file) && time() - (int)@filemtime($file) < REALM_SKY_SECONDS) {
            $kept = json_decode((string)@file_get_contents($file), true);
            if (is_array($kept)) return $kept;
        }
    } catch (Throwable $e) {
        // Worked out afresh below.
    }
    $out = realm_skies_now($pdo);
    try {
        @file_put_contents($file, json_encode($out), LOCK_EX);
    } catch (Throwable $e) {
        // Not kept: the next heartbeat works it out again, which is only slower.
    }
    return $out;
}

/** The working-out itself, uncached. */
function realm_skies_now(PDO $pdo, ?string $today = null): array {
    $out = [];
    try {
        $rows = $pdo->query('SELECT l.land, p.id, p.start_date, p.delivery_date
                               FROM realm_lands l JOIN projects p ON p.id = l.project_id')->fetchAll();
        if (!$rows) return [];
        require_once __DIR__ . '/dashboard.php';
        $today = $today ?: date('Y-m-d');
        $pct = projects_overall_pct($pdo, $rows);

        // Today's approvals and retakes per show, and how long since the last
        // approval. Minutes are counted by the database against its own clock,
        // because created_at was stamped by that clock, not PHP's.
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $log = [];
        try {
            $q = $pdo->prepare("SELECT project_id,
                                       SUM(change_type = 'approved') AS ok,
                                       SUM(change_type = 'retake')   AS back,
                                       TIMESTAMPDIFF(MINUTE, MAX(IF(change_type = 'approved', created_at, NULL)), NOW()) AS mins
                                  FROM progress_log
                                 WHERE project_id IN ($ids) AND log_date = ?
                              GROUP BY project_id");
            $q->execute([$today]);
            foreach ($q as $r) $log[(int)$r['project_id']] = $r;
        } catch (Throwable $e) {
            // Before migration 021, or the log is unreadable: no fireworks, the weather still shows.
        }

        foreach ($rows as $r) {
            $pid = (int)$r['id'];
            $o = $pct[$pid] ?? null;
            $done = $o ? (int)$o['approved'] : 0;
            $total = $o ? (int)$o['applicable'] : 0;
            $out[(string)$r['land']] = realm_sky_for($done, $total, $r['start_date'] ?: null,
                                                     $r['delivery_date'] ?: null, $log[$pid] ?? null, $today);
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * One show's sky from its figures. Kept apart from the queries so the rule can
 * be read on its own.
 *
 * $log is that show's line of today's progress_log summary (ok, back, mins), or null.
 */
function realm_sky_for(int $done, int $total, ?string $start, ?string $due, ?array $log, string $today): array {
    $sky = realm_sky_none();
    if ($total <= 0) return $sky;   // nothing to count yet: nothing to judge

    // The weather: the grid's own verdict, then the storm the grid has no colour for.
    if ($due !== null && $due < $today && $done < $total) {
        $sky['w'] = 'storm';
    } else {
        $h = cell_health((float)$done, (float)$total, $start, $due, $today);
        $sky['w'] = ['amber' => 'cloud', 'red' => 'rain'][$h] ?? 'clear';
    }

    if ($log) {
        // A task approved within the hour: small fireworks.
        $sky['cheer'] = $log['mins'] !== null && (int)$log['mins'] <= 60;
        // A milestone crossed today. Where the show stood this morning is today's
        // figure less what was approved today plus what went back for a retake.
        // It is an estimate (a task approved twice in a day counts twice), and
        // only decides whether a banner flies, never a number anybody reads.
        $before = max(0, min($total, $done - (int)$log['ok'] + (int)$log['back']));
        $now = (int)floor($done / $total * 100);
        $was = (int)floor($before / $total * 100);
        foreach ([100, 75, 50, 25] as $m) {
            if ($now >= $m && $was < $m) { $sky['mile'] = $m; break; }
        }
    }
    return $sky;
}
