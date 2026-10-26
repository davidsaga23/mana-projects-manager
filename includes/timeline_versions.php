<?php
// What the timeline draws, and saved versions of it.
//
// David, 2026-09-18: "Timeline already has persistent memory and stores the
// timeline as I adjust it. However, I need to check the historic changes."
//
// Every drag on the timeline saves straight into pipeline_steps, schedule and
// project_milestones, so the page always shows the latest state and nothing
// remembers the one before it. A VERSION is a copy of what the page drew at the
// moment somebody pressed "Save timeline's version", kept in timeline_versions
// (migration 035) and named V00_09182026, V01_09182026, V02_09192026.
//
// ONE BUILDER. timeline_picture() is what the page draws from AND what a
// version freezes, so a saved version can only ever look the way the timeline
// did: it is literally what the timeline was handed. A second copy of that code
// for the save would drift from the page within a month, and the history would
// start showing a timeline nobody ever saw.
//
// THE DRAWING, NOT THE IDS. A version stores the charts' own data, names and
// colours included, rather than ids pointing into tables that keep moving.
// Stored as ids, a step renamed or a shot deleted next month would quietly
// rewrite what an old version shows.
//
// READ ONLY. Nothing here writes a version back onto the live plan.

require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/quota.php';       // step_duration(), for the step editor
require_once __DIR__ . '/dashboard.php';   // dashboard_steps(), what the grid has approved

/** Has migration 035 run? */
function timeline_versions_ready(PDO $pdo): bool {
    return column_exists($pdo, 'timeline_versions', 'payload');
}

/**
 * What the timeline draws, read from the live tables.
 *
 * Both charts, the milestones, and what the filters above the detailed chart
 * offer. Moved here from timeline.php unchanged on 2026-09-18, so the page and
 * a saved version are built by the same code.
 *
 *   general     one bar per dated pipeline step (the page's GENERAL_TASKS)
 *   tasks       one bar per saved schedule row (DETAILED_TASKS)
 *   milestones  the markers drawn down both charts (MILESTONES)
 *   steps       the pipeline for the step filter: id, name, scope
 *   episodes    the episodes the detailed chart has bars for
 *   episodic    whether the project is split into episodes at all
 *   undated     steps with no dates, which the general chart cannot draw
 *
 * $steps is the project's pipeline when the caller already has it.
 */
function timeline_picture(PDO $pdo, array $project, ?array $steps = null): array {
    $projectId = (int)$project['id'];
    if ($steps === null) $steps = pipeline_steps($pdo, $projectId);
    $episodic = (string)project_setting($pdo, $projectId, 'episodic', '') === '1';

    // --- the detailed chart --------------------------------------------------
    $stmt = $pdo->prepare(
        "SELECT sc.id, sc.start_date, sc.end_date, sc.crew_id, sc.is_manual, sc.lane,
                sc.shot_id, sc.asset_id, sc.item_key,
                ps.id AS step_id, ps.name AS step_name, ps.step_order, ps.color,
                sh.episode, sh.shot_code, a.name AS asset_name,
                c.name AS crew_name,
                COALESCE(pr.is_approved, 0) AS is_approved
           FROM schedule sc
           JOIN pipeline_steps ps ON ps.id = sc.step_id
           LEFT JOIN shots  sh ON sh.id = sc.shot_id
           LEFT JOIN assets a  ON a.id  = sc.asset_id
           LEFT JOIN crew   c  ON c.id  = sc.crew_id
           LEFT JOIN progress pr ON pr.step_id = sc.step_id AND pr.item_key = sc.item_key
          WHERE sc.project_id = ?
          ORDER BY sc.start_date, ps.step_order, sc.lane, sc.id"
    );
    $stmt->execute([$projectId]);

    $episodes = [];
    $tasks = [];
    foreach ($stmt->fetchAll() as $r) {
        // What the bar is FOR. A shot carries its episode when the project has
        // them; an asset is just its name; a band or whole-project deliverable
        // has neither and falls back to the step, which is exactly what it
        // represents.
        if ($r['shot_code'] !== null) {
            $item = trim(($r['episode'] ? $r['episode'] . ' ' : '') . $r['shot_code']);
        } elseif ($r['asset_name'] !== null) {
            $item = $r['asset_name'];
        } else {
            $item = $r['step_name'];
        }
        if ($r['episode']) $episodes[$r['episode']] = true;

        $tasks[] = [
            'id'          => (string)$r['id'],
            // Frappe draws this to the RIGHT of the bar — the item and what is
            // being done to it, which is the pairing a supervisor scans for.
            'name'        => $item . ' · ' . $r['step_name'],
            'start'       => $r['start_date'],
            // NOT extended by a day. Frappe treats `end` as the inclusive last
            // day and stretches it internally; this platform stores it inclusive
            // too. STFC had both sides adding a day and the two errors hid each
            // other for weeks.
            'end'         => $r['end_date'],
            'progress'    => (int)$r['is_approved'] === 1 ? 100 : 0,
            'episode'     => $r['episode'] ?? '',
            'step_id'     => (int)$r['step_id'],
            'step_name'   => $r['step_name'],
            'step_order'  => (int)$r['step_order'],
            'item_label'  => $item,
            'color'       => $r['color'] ?: '#5FB2FF',
            'crew_id'     => $r['crew_id'] !== null ? (int)$r['crew_id'] : null,
            'crew_name'   => $r['crew_name'] ?? '',
            'is_approved' => (int)$r['is_approved'] === 1,
            'is_manual'   => (int)$r['is_manual'] === 1,
        ];
    }
    ksort($episodes);
    $episodes = array_keys($episodes);

    // --- the general chart: one bar per pipeline step --------------------------
    //
    // THE PLAN, NOT THE SCHEDULE (David, 2026-08-31). This used to span
    // MIN/MAX(schedule) for the step and fall back to its planned dates only
    // when nothing was scheduled. That made the bar a DERIVED value, which is
    // why dragging it never stuck: there was nothing to write to, and a reload
    // recomputed it from the scheduled items.
    //
    // Now the bar IS pipeline_steps.start_date / deadline — the same two fields
    // the Dates & headcount screen edits. Drag it and you are editing that
    // screen graphically; edit that screen and the bar moves. One number, two
    // ways in.
    //
    // The detailed chart still shows where work actually landed, which is the
    // half this one deliberately no longer answers.
    $depsMap = step_dependencies_map($pdo, $projectId);

    // WHAT THE STEP EDITOR SHOWS BESIDE EACH BAR (2026-09-18): how long the step
    // needs at its settings, the Duration column of Dates & headcount, and what
    // the tracking grid has approved, the dashboard's own figure from the same
    // function, so the two screens cannot disagree. Carried on the bars rather
    // than fetched when the editor opens, so a saved version shows them as they
    // were on the day it was saved.
    $estimates = project_estimates($pdo, $projectId);
    $retakePct = project_retake_pct($pdo, $projectId);
    $trackedBy = [];
    foreach (dashboard_steps($pdo, $projectId, $project['delivery_date'] ?: null) as $ds) {
        $trackedBy[(int)$ds['step']['id']] = $ds;
    }

    $general = [];
    $undated = [];      // steps with no window yet: reported, never silently dropped
    $filter  = [];      // every step, dated or not, for the step filter
    foreach ($steps as $s) {
        $scope = applies_to_phrase($s['applies_to'], $s['applies_to_asset_type'] ?? null);
        $filter[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'scope' => $scope];

        $start = $s['start_date'] ?: null;
        $end   = $s['deadline'] ?: null;
        if (!$start || !$end) { $undated[] = $s['name']; continue; }
        // A backwards pair would draw as a negative-width bar. The planning
        // screen already flags it in red; here it is simply clamped so the
        // chart still draws.
        if ($end < $start) $end = $start;

        // DEPENDENCIES ARE DRAWN, NOT ENFORCED. The arrows come from the
        // workflow graph so the shape is visible while you drag, but nothing
        // here blocks a move and nothing snaps back — David plans the general
        // timeline to the dates, and accepts that a dependency-respecting
        // schedule may run longer. A violated arrow turns red in the browser
        // and that is the whole of it.
        $depIds = [];
        foreach ($depsMap[(int)$s['id']] ?? [] as $d) $depIds[] = 'step-' . (int)$d;

        $dur = step_duration($pdo, $projectId, $s, $estimates, $retakePct);
        $trk = $trackedBy[(int)$s['id']] ?? null;

        $general[] = [
            'id'         => 'step-' . (int)$s['id'],
            'step_id'    => (int)$s['id'],
            'name'       => $s['name'] . ' · ' . $scope,
            'start'      => $start,
            'end'        => $end,
            // Frappe's own field, drawn as the darker part of the bar.
            //
            // ⚠ AUTOMATIC UNLESS SOMEBODY SET IT (David, 2026-10-26: "record the
            // progress in the bars automatically based on the assets/shots/
            // seconds delivered vs the pending ones ... with the option to
            // override manually"). Automatic is the share DELIVERED — approved
            // counts, being delivered and then some — in the step's own unit:
            // items, or seconds of footage for a step that tracks seconds, with
            // N/A items out of the total. The same figure the dashboard draws.
            //
            // HAND-SET is a progress with a "Set by … on …" beside it, which is
            // exactly what dragging the handle or typing in the editor has always
            // written (migration 036). So no new column: what people set before
            // today stays theirs, and "Back to automatic" clears the stamp.
            'progress'   => timeline_step_progress($s, $trk),
            'progress_auto'   => timeline_auto_progress($trk),
            'progress_manual' => trim((string)($s['progress_source'] ?? '')) !== '',
            'progress_source' => (string)($s['progress_source'] ?? ''),
            'color'      => $s['color'] ?: '#5FB2FF',
            // For the step editor.
            'step_name'  => $s['name'],
            'scope'      => $scope,
            'need_days'  => $dur['days'],
            'need_text'  => timeline_need_text($dur),
            'tracked_pct'  => timeline_auto_progress($trk),
            'tracked_text' => timeline_tracked_text($trk),
            // Frappe 0.6.1 reads this as a comma-separated list of task ids.
            'dependencies' => implode(',', $depIds),
            // Late now means "past the DELIVERY date". It used to mean "the
            // schedule ran past the step's own deadline", which cannot happen
            // any more: the bar and the deadline are the same field.
            'delivery'   => $project['delivery_date'] ?: null,
            'late'       => ($project['delivery_date'] && $end > $project['delivery_date']) ? 1 : 0,
        ];
    }

    // --- the milestones --------------------------------------------------------
    // 029 adds the link to a pipeline step. Checked separately so this keeps
    // working on a database where 028 has run and 029 has not.
    $milestones = [];
    $msReady = column_exists($pdo, 'project_milestones', 'id');
    $msSteps = $msReady && column_exists($pdo, 'project_milestones', 'step_id');
    if ($msReady) {
        $ms = $pdo->prepare(
            $msSteps
                ? 'SELECT m.id, m.name, m.milestone_date, m.notes, m.step_id,
                          ps.name AS step_name, ps.deadline AS step_end,
                          ps.applies_to AS step_applies, ps.applies_to_asset_type AS step_asset_type
                     FROM project_milestones m
                     LEFT JOIN pipeline_steps ps ON ps.id = m.step_id
                    WHERE m.project_id = ? ORDER BY m.milestone_date, m.id'
                : 'SELECT id, name, milestone_date, notes FROM project_milestones
                    WHERE project_id = ? ORDER BY milestone_date, id'
        );
        $ms->execute([$projectId]);
        foreach ($ms as $m) {
            $stepEnd = $m['step_end'] ?? null;
            $milestones[] = [
                'id'    => (int)$m['id'],
                'name'  => $m['name'],
                'date'  => $m['milestone_date'],
                'notes' => $m['notes'] ?? '',
                // The bar id the general chart uses, so the browser can put the
                // marker on that row without a second lookup.
                'step_id'   => isset($m['step_id']) && $m['step_id'] ? (int)$m['step_id'] : null,
                // Named the same way in the chart tooltip and the panel as it is
                // in the picker, so "reviews Concept" can never mean a different
                // Concept from the one that was chosen.
                'step_name' => isset($m['step_name']) && $m['step_name'] !== null
                    ? $m['step_name'] . ' · ' . applies_to_phrase($m['step_applies'],
                                                                  $m['step_asset_type'] ?? null)
                    : '',
                // EARLY means the step it reviews has not finished by then, so
                // the thing being reviewed will not exist. Worked out here
                // because the step's dates are already to hand.
                'early' => ($stepEnd && $m['milestone_date'] < $stepEnd) ? 1 : 0,
                'step_end' => $stepEnd,
            ];
        }
    }

    return [
        'general'    => $general,
        'tasks'      => $tasks,
        'milestones' => $milestones,
        'steps'      => $filter,
        'episodes'   => $episodes,
        'episodic'   => $episodic,
        'undated'    => $undated,
    ];
}

/**
 * How a step's duration is made up, in one sentence, for the step editor.
 *
 * Numbers formatted the way Dates & headcount prints them (fmt_rate() for the
 * work, the box's own value for days per item), so the sentence can be checked
 * against that screen line by line.
 */
function timeline_need_text(array $d): string {
    if ($d['units'] <= 0) return 'Nothing to schedule for this step yet.';
    $work = fmt_rate($d['units']) . ' ' . $d['label'];
    if ($d['days'] === null) {
        return $work . ', ' . fmt_rate($d['person_days']) . ' person-days of work. Set how many '
             . 'people are on it, on Dates & headcount, to get a duration.';
    }
    $one = abs($d['per_unit'] - 1) < 0.0005;
    $s = $work . ' at ' . fmt_num($d['per_unit'], 3) . ' day' . ($one ? '' : 's') . ' each';
    if ($d['retake_pct'] > 0) $s .= ', plus ' . fmt_num($d['retake_pct']) . '% retakes';
    $s .= abs($d['people'] - 1) < 0.0005 ? ', one person'
                                          : ', shared by ' . fmt_num($d['people']) . ' people';
    if ($d['estimated']) $s .= ', from the pre-production estimate';
    return $s . '.';
}

/**
 * What the tracking grid says about a step, for the step editor.
 *
 * "Nothing tracked" rather than "0 of 26 approved" when the grid holds no row
 * for the step at all: zero means nothing got done, and nothing tracked means
 * nobody knows, which are different statements (the same rule as the pace).
 */
function timeline_tracked_text(?array $t): string {
    if ($t === null || (float)$t['target'] <= 0) {
        return 'Nothing to count yet: this step has no shots, assets or seconds against it.';
    }
    $fmt = function ($v) use ($t) {
        return $t['unit'] === 'sec' ? fmt_secs((float)$v) : (string)(int)round((float)$v);
    };
    $what = $t['unit'] === 'sec' ? 'seconds' : 'items';
    return sprintf('%s of %s %s delivered, %d%%.',
                   $fmt($t['delivered_v']), $fmt($t['target']), $what, (int)$t['pct_delivered']);
}

/**
 * A step's AUTOMATIC progress: the share of its work delivered, 0 to 100, or
 * null when there is nothing to count against (no items, no seconds).
 * Delivered includes approved — status_flags() sets both for an approved status.
 */
function timeline_auto_progress(?array $t): ?int {
    if ($t === null || (float)$t['target'] <= 0 || $t['pct_delivered'] === null) return null;
    return max(0, min(100, (int)$t['pct_delivered']));
}

/** What the bar draws: the hand-set figure where there is one, else automatic. */
function timeline_step_progress(array $step, ?array $t): int {
    if (trim((string)($step['progress_source'] ?? '')) !== '') {
        return max(0, min(100, (int)($step['progress_pct'] ?? 0)));
    }
    return timeline_auto_progress($t) ?? 0;
}

/**
 * A version's name: V + its number + _ + the day it was saved as MMDDYYYY.
 *
 * David's format, 2026-09-18: V00_09182026, V01_09182026, V02_09192026. The
 * number runs on across days rather than restarting each morning, so it alone
 * says which of two versions came first.
 *
 * Cut from the stored text rather than passed through strtotime(): created_at
 * was written in the studio's timezone, and converting it anywhere would be a
 * chance for the date to walk a day (lesson 3, in PHP clothing).
 */
function timeline_version_label(int $seq, string $createdAt): string {
    $day = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $createdAt, $m)
         ? $m[2] . $m[3] . $m[1] : '00000000';
    return sprintf('V%02d_%s', $seq, $day);
}

/**
 * Every saved version of a project's timeline, newest first, without the
 * drawings, which are only read when one is opened.
 */
function timeline_versions(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT id, seq, created_at, created_by, step_count, task_count, milestone_count
           FROM timeline_versions
          WHERE project_id = ?
          ORDER BY seq DESC'
    );
    $stmt->execute([$projectId]);
    $out = [];
    foreach ($stmt as $v) {
        $v['id']    = (int)$v['id'];
        $v['seq']   = (int)$v['seq'];
        $v['label'] = timeline_version_label($v['seq'], (string)$v['created_at']);
        $v['time']  = substr((string)$v['created_at'], 11, 5);
        $out[] = $v;
    }
    return $out;
}

/**
 * One saved version, with its drawing, or null when it is not on this project.
 *
 * Scoped to the project in the address, like every other read here: a version
 * id from another show must not open on this one's page.
 */
function load_timeline_version(PDO $pdo, int $projectId, int $versionId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM timeline_versions WHERE id = ? AND project_id = ?');
    $stmt->execute([$versionId, $projectId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $label = timeline_version_label((int)$row['seq'], (string)$row['created_at']);
    $pic = json_decode((string)$row['payload'], true);
    if (!is_array($pic)) {
        // Said, never drawn as an empty chart: an empty old version would read
        // as "the timeline was empty then", which is a false history.
        throw new RuntimeException($label . ' could not be read back, its saved copy is damaged.');
    }
    // Whatever a later release adds to the drawing, a version saved before it
    // still opens: every key has a default.
    $pic += ['general' => [], 'tasks' => [], 'milestones' => [], 'steps' => [],
             'episodes' => [], 'episodic' => false, 'undated' => []];

    return [
        'id'         => (int)$row['id'],
        'seq'        => (int)$row['seq'],
        'label'      => $label,
        'created_at' => (string)$row['created_at'],
        'created_by' => (string)($row['created_by'] ?? ''),
        'picture'    => $pic,
    ];
}

/**
 * Save what the timeline draws right now as the project's next version.
 *
 * Returns the new version's id, number, name and what it holds, which the
 * page reports back rather than simply saying "saved".
 */
function save_timeline_version(PDO $pdo, array $project, string $savedBy): array {
    $projectId = (int)$project['id'];
    $pic = timeline_picture($pdo, $project);
    if (!$pic['general'] && !$pic['tasks']) {
        throw new RuntimeException('There is nothing to save yet: no step has dates and no '
                                 . 'schedule has been saved, so the timeline is empty.');
    }

    // format says how to read the rest, should the drawing ever change shape.
    $json = json_encode(['format' => 1] + $pic, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('The timeline could not be written down ('
                                 . json_last_error_msg() . '), so no version was saved.');
    }

    // A drawing larger than the database will take in one go fails with "MySQL
    // server has gone away", which names nothing. Checked first so the message
    // says what actually happened. 16,777,215 bytes is MEDIUMTEXT's limit.
    $limit = 16777215;
    try {
        $packet = (int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
        if ($packet > 0) $limit = min($limit, $packet - 65536);
    } catch (PDOException $e) {
        // Only the early warning is lost; the insert below still reports its own
        // failure.
    }
    if (strlen($json) > $limit) {
        throw new RuntimeException(sprintf(
            'This timeline is too large to keep as a version (%.1f MB, the database takes %.1f MB '
          . 'at a time), so nothing was saved.', strlen($json) / 1048576, $limit / 1048576));
    }

    // The studio's clock, not MySQL's: the date in the name is the day the
    // studio saved it (db.php sets the timezone from config.php).
    $now = date('Y-m-d H:i:s');
    $who = mb_substr(trim($savedBy), 0, 100);

    $ins = $pdo->prepare(
        'INSERT INTO timeline_versions
            (project_id, seq, created_at, created_by, step_count, task_count, milestone_count, payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $next = $pdo->prepare(
        'SELECT COALESCE(MAX(seq), -1) + 1 FROM timeline_versions WHERE project_id = ?'
    );
    // Numbered per project from 0. UNIQUE (project_id, seq) refuses a number
    // somebody else took in the same second; the loser simply takes the next.
    // No transaction: one INSERT is atomic on its own, and not opening one means
    // this is safe to call from inside somebody else's (lesson 18).
    for ($attempt = 1; ; $attempt++) {
        $next->execute([$projectId]);
        $seq = (int)$next->fetchColumn();
        $next->closeCursor();
        try {
            $ins->execute([$projectId, $seq, $now, $who !== '' ? $who : null,
                           count($pic['general']), count($pic['tasks']), count($pic['milestones']),
                           $json]);
            break;
        } catch (PDOException $e) {
            // 23000 is a broken unique key. Anything else is a real failure.
            if ($attempt >= 3 || (string)$e->getCode() !== '23000') throw $e;
        }
    }

    return [
        'id'         => (int)$pdo->lastInsertId(),
        'seq'        => $seq,
        'label'      => timeline_version_label($seq, $now),
        'steps'      => count($pic['general']),
        'tasks'      => count($pic['tasks']),
        'milestones' => count($pic['milestones']),
    ];
}
