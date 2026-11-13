<?php
// The Realm: what is always inside a show's camp (migration 089).
//
// David, 2026-11-13: "for all the project camps, let's always have the
// following: A bar with the finished tasks and the progress for 'slaying' the
// project. A kind of papyrus on the wall so they can see live the brief of that
// project. A calendar to visualize the timeline. A checkbox list so they can see
// the production tracking, to see all the shots and their status in the checkbox.
// Decorate is also available but only one item per person."
//
// What that became:
//   * FOUR THINGS ARE IN EVERY CAMP and cannot be moved or removed: the slaying
//     bar over the back wall, the scroll (the Brief), the calendar (the
//     Timeline) and the board (Production tracking). A map table stands on the
//     floor as before. Where they are is REALM_CAMP_FIXED below, and the same
//     list is in assets/realm.js (THINGS, the camp_ rows, and CAMP_FIX).
//   * EVERYTHING SHOWN IS ANIMA'S OWN, READ WHEN IT IS ASKED FOR. Nothing is
//     copied or kept here. Each window calls the very function its screen in
//     Anima calls (projects_overall_pct(), timeline_picture(), build_grid()), so
//     a number in the camp cannot disagree with the number on that screen.
//   * NOTHING IN A CAMP CHANGES ANYTHING IN ANIMA. No status, date or brief can
//     be set from here. The one thing written is a person's own decoration.
//   * ONE DECORATION EACH. Anybody on the show may stand ONE thing from the
//     house library in the camp, move it and take it out again. It is one row
//     per show and person in realm_camp_things, so a second cannot exist.
//
// ⚠ THE SHOW IS NEVER TAKEN FROM THE BROWSER. Every request names a LAND
// ("peaks"); realm_camp_open() looks up which show holds that land in
// realm_lands and asks may_see_project() before anything else is read. Somebody
// who is not on the show gets one sentence and no data, whatever they send.
//
// ⚠ ANIMA'S ACCESS SWITCHES HOLD HERE TOO. realm_api.php asks feature_on() for
// 'brief', 'production' and 'timeline' before answering for each window, so a
// screen that is switched off for somebody cannot be read through the Realm.
//
// ⚠ NOTHING HERE TOUCHES MONEY. No budget, rate, cost or invoice is read, and
// includes/money.php is never loaded. The Brief's client CONTACT (who to call)
// is left out as well: David asked for the ask, the deliverables and the
// references, and a phone number is not something to hand round a room unasked.

require_once __DIR__ . '/realm_room.php';   // the library, and the rules for two things side by side

/**
 * What always stands in a camp, and where. id => [where it goes, x, y, width,
 * height], in the room's own units (240 wide, 160 tall, as realm_room.php).
 * ⚠ ALSO IN assets/realm.js (THINGS and CAMP_FIX). The numbers MUST match: this
 * list decides whether a decoration may stand somewhere, that one draws them.
 */
const REALM_CAMP_FIXED = [
    'camp_scroll'   => ['wall',  20, 18, 16, 26],
    'camp_calendar' => ['wall',  44, 20, 22, 24],
    'camp_board'    => ['wall', 176, 16, 44, 30],
    'camp_table'    => ['floor', 96, 64, 48, 16],
];

/**
 * What the calendar shows to somebody whose "Timeline: see" switch is OFF
 * (artists, by default):
 *   'closed'      nothing: the calendar says the Timeline is switched off.
 *   'milestones'  the delivery date and the milestones only, no step bars.
 * ⚠ DAVID'S TO DECIDE (asked 2026-11-13). It starts at 'closed', which is what
 * the switch means today, so that nothing is shown he has not agreed to.
 */
const REALM_CAMP_TIMELINE_WITHOUT_SWITCH = 'closed';

/** Has migration 089 run? Until it has, a camp has its fixtures and no decorations. */
function realm_camp_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_camp_things LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * The show whose camp stands on $land, for the person asking: its id, name and
 * delivery date. Null, with $why set to the sentence to show, when no show has
 * that land or the asker may not see the show.
 *
 * ⚠ THE ONE DOOR. Every camp request goes through here first, so the project id
 * used afterwards always comes from realm_lands and has always been checked.
 */
function realm_camp_open(PDO $pdo, string $land, string &$why): ?array {
    $why = 'No show has made camp there.';
    if (!in_array($land, REALM_LANDS, true)) return null;
    $q = $pdo->prepare('SELECT p.id, p.name, p.delivery_date
                          FROM realm_lands l JOIN projects p ON p.id = l.project_id
                         WHERE l.land = ?');
    $q->execute([$land]);
    $p = $q->fetch();
    if (!$p) return null;
    if (function_exists('may_see_project') && !may_see_project($pdo, (int)$p['id'])) {
        $why = 'That camp belongs to a show you are not on, so its boards are covered.';
        return null;
    }
    return $p;
}

/**
 * THE SLAYING BAR: how much of the show is finished, counted in tasks (one item
 * through one step), which is how Anima counts a whole show.
 *
 * projects_overall_pct() is the figure on the progress capsule of the project
 * list, and it hands its rows to dashboard_overall(), the one place a show is
 * turned into a percentage for the Overview and the Dashboard. It is used
 * rather than dashboard_steps() because that one runs several queries per step
 * and this is asked again every 45 seconds by everybody standing in a camp;
 * this runs three, whatever the show.
 *
 * Null when the show has nothing to count yet, which is not the same as 0%.
 */
function realm_camp_progress(PDO $pdo, array $project): ?array {
    try {
        require_once __DIR__ . '/dashboard.php';
        $all = projects_overall_pct($pdo, [$project]);
        $o = $all[(int)$project['id']] ?? null;
        if (!$o || $o['pct'] === null || (int)$o['applicable'] <= 0) return null;
        return ['done' => (int)$o['approved'], 'total' => (int)$o['applicable'], 'pct' => (int)$o['pct']];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The decorations standing in a show's camp: [{thing, x, y, user, name, mine}].
 * A thing that has left the library, or that no longer fits beside the
 * fixtures, is not listed, so a camp can always be drawn. Things of switched-off
 * accounts are not listed either. Never throws.
 */
function realm_camp_things(PDO $pdo, int $projectId, int $uid): array {
    if (!realm_camp_available($pdo)) return [];
    $out = [];
    try {
        $q = $pdo->prepare('SELECT t.user_id, t.thing, t.x, t.y, u.display_name, u.username, c.name AS crew_name
                              FROM realm_camp_things t
                              JOIN users u ON u.id = t.user_id
                         LEFT JOIN crew c ON c.id = u.crew_id
                             WHERE t.project_id = ? AND u.active = 1
                          ORDER BY t.updated_at, t.user_id');
        $q->execute([$projectId]);
        $kept = [];
        foreach ($q->fetchAll() as $r) {
            $why = '';
            $it = realm_camp_fits((string)$r['thing'], (int)$r['x'], (int)$r['y'], $kept, $why);
            if ($it === null) continue;
            $kept[] = $it;
            $out[] = ['thing' => $it[0], 'x' => $it[1], 'y' => $it[2], 'user' => (int)$r['user_id'],
                      'name' => trim((string)($r['crew_name'] ?: ($r['display_name'] ?: $r['username']))),
                      'mine' => (int)$r['user_id'] === $uid];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * May $thing stand at $x, $y in a camp, beside $others ([[thing, x, y], ...])?
 * Returns it tidied, or null with $why set to the sentence to show.
 *
 * The house rules (realm_room_check()), asked of one thing: it is in the
 * library and free, it stands inside the room, and it is clear of every other
 * thing (realm_room_clash()). Two rules are the camp's own: the bookshelf, the
 * chest and the portrait belong to a house, and nothing may cover the scroll,
 * the calendar, the board or the map table.
 */
function realm_camp_fits(string $thing, int $x, int $y, array $others, string &$why): ?array {
    $why = 'That is not a thing from the library.';
    if (!isset(REALM_ROOM_THINGS[$thing])) return null;
    [$zone, $w, $h, $hang, , $always, $gold] = REALM_ROOM_THINGS[$thing];
    if ($gold) { $why = 'That one is bought with gold, and gold has not arrived yet.'; return null; }
    if ($always) { $why = 'The bookshelf, the chest and the portrait belong in a house.'; return null; }
    if ($zone === 'wall') $y = $hang;
    $why = 'That would stand outside the camp.';
    if ($x < 12 || $x > 228 - $w) return null;
    if ($zone === 'floor' && ($y + $h < 60 || $y + $h > 148)) return null;
    $why = 'Something is already there.';
    foreach (REALM_CAMP_FIXED as $f) {
        if ($f[0] !== $zone) continue;
        if ($zone === 'wall') {
            if ($x < $f[1] + $f[3] + 2 && $f[1] < $x + $w + 2) return null;
            continue;
        }
        // The same ground rule as two floor things in a house: only the bottom
        // 10 units of each count, so a tall thing may rise behind a low one.
        $fa = min(10, $h);
        $fb = min(10, $f[4]);
        $ay = $y + $h - $fa;
        $by = $f[2] + $f[4] - $fb;
        if ($x < $f[1] + $f[3] && $f[1] < $x + $w && $ay < $by + $fb && $by < $ay + $fa) return null;
    }
    foreach ($others as $o) {
        if (realm_room_clash([$thing, $x, $y], $o)) return null;
    }
    $why = '';
    return [$thing, $x, $y];
}

/**
 * Stand the asker's ONE thing in the camp, or move it. Returns '' when saved, or
 * the sentence to show.
 *
 * ⚠ ONE EACH (David, 2026-11-13: "only one item per person"). The table's key is
 * the show and the person, so a second row cannot exist; and somebody who
 * already has a thing there and sends a DIFFERENT one is refused rather than
 * having the first quietly swapped, so the rule is seen and not just enforced.
 */
function realm_camp_place(PDO $pdo, int $uid, int $projectId, string $thing, int $x, int $y): string {
    if (!realm_camp_available($pdo)) return 'Decorating a camp needs a database update first. An administrator opens migrate.php once.';
    $others = [];
    foreach (realm_camp_things($pdo, $projectId, $uid) as $t) {
        if ($t['mine']) {
            if ($t['thing'] !== $thing) return 'You already have one thing in this camp. Take it out first to choose another.';
            continue;
        }
        $others[] = [$t['thing'], $t['x'], $t['y']];
    }
    $why = '';
    $it = realm_camp_fits($thing, $x, $y, $others, $why);
    if ($it === null) return $why;
    $pdo->prepare('INSERT INTO realm_camp_things (project_id, user_id, thing, x, y, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE thing = VALUES(thing), x = VALUES(x), y = VALUES(y), updated_at = NOW()')
        ->execute([$projectId, $uid, $it[0], $it[1], $it[2]]);
    return '';
}

/**
 * Take a thing out of the camp: the asker's own ($whose is 0 or their own id),
 * or anybody's when $mayAny (Production and admins, so a camp can be tidied).
 * Returns '' when it is gone, or the sentence to show.
 */
function realm_camp_remove(PDO $pdo, int $uid, int $projectId, int $whose, bool $mayAny): string {
    if (!realm_camp_available($pdo)) return 'Decorating a camp needs a database update first. An administrator opens migrate.php once.';
    if ($whose <= 0) $whose = $uid;
    if ($whose !== $uid && !$mayAny) return 'That one is not yours. Only Production can take out somebody else\'s thing.';
    $pdo->prepare('DELETE FROM realm_camp_things WHERE project_id = ? AND user_id = ?')->execute([$projectId, $whose]);
    return '';
}

/**
 * What the screen needs to draw a camp: the show, the slaying bar's numbers,
 * the decorations, and which of the three windows this person may open.
 * $can is ['brief' => bool, 'timeline' => bool, 'production' => bool], Anima's
 * own switches as realm_api.php read them; $mayClear says whether this person
 * may take out somebody else's thing.
 */
function realm_camp_answer(PDO $pdo, int $uid, string $land, array $project, array $can, bool $mayClear): array {
    return ['land' => $land, 'project_id' => (int)$project['id'], 'name' => (string)$project['name'],
            'ready' => realm_camp_available($pdo),
            'can' => ['brief' => !empty($can['brief']), 'production' => !empty($can['production']),
                      // The calendar opens for somebody without the switch only if David chose the reduced view.
                      'timeline' => !empty($can['timeline']) || REALM_CAMP_TIMELINE_WITHOUT_SWITCH === 'milestones'],
            'may_clear' => $mayClear,
            'progress' => realm_camp_progress($pdo, $project),
            'things' => realm_camp_things($pdo, (int)$project['id'], $uid)];
}

/**
 * THE SCROLL: the show's Brief as Anima holds it now (brief.php reads the same
 * two tables, migration 023). The client's name, the ask, the deliverables, the
 * references, the notes, and the attached files by name. Never the client's
 * contact: see the note at the top.
 *
 * `ready` is false until migration 023 has run; `empty` is true when nothing
 * has been written yet. Files are links to attachment.php, the same guarded
 * address the Brief screen uses; no picture is loaded here.
 */
function realm_camp_brief(PDO $pdo, int $projectId): array {
    $out = ['ready' => false, 'empty' => true, 'client' => '', 'summary' => '', 'deliverables' => '',
            'reference' => '', 'notes' => '', 'updated' => '', 'files' => [],
            'url' => 'brief.php?project=' . $projectId];
    if (!function_exists('column_exists') || !column_exists($pdo, 'project_brief', 'project_id')) return $out;
    $out['ready'] = true;
    $q = $pdo->prepare('SELECT client, summary, deliverables, reference_links, notes, updated_at, updated_by
                          FROM project_brief WHERE project_id = ?');
    $q->execute([$projectId]);
    if ($b = $q->fetch()) {
        $out['client']       = trim((string)($b['client'] ?? ''));
        $out['summary']      = trim((string)($b['summary'] ?? ''));
        $out['deliverables'] = trim((string)($b['deliverables'] ?? ''));
        $out['reference']    = trim((string)($b['reference_links'] ?? ''));
        $out['notes']        = trim((string)($b['notes'] ?? ''));
        if (!empty($b['updated_at'])) {
            $out['updated'] = substr((string)$b['updated_at'], 0, 16)
                            . (trim((string)($b['updated_by'] ?? '')) !== '' ? ' by ' . trim((string)$b['updated_by']) : '');
        }
    }
    try {
        $f = $pdo->prepare('SELECT id, original_name, file_size FROM project_attachments WHERE project_id = ? ORDER BY created_at');
        $f->execute([$projectId]);
        foreach ($f->fetchAll() as $a) {
            $out['files'][] = ['name' => (string)$a['original_name'],
                               'size' => function_exists('human_size') ? human_size((int)$a['file_size']) : '',
                               'url'  => 'attachment.php?kind=project&id=' . (int)$a['id']];
        }
    } catch (Throwable $e) {
        // The words are still shown; the files are on the Brief screen.
    }
    $out['empty'] = $out['client'] === '' && $out['summary'] === '' && $out['deliverables'] === ''
                 && $out['reference'] === '' && $out['notes'] === '' && !$out['files'];
    return $out;
}

/**
 * THE CALENDAR: the show's timeline, small enough for a window.
 *
 * timeline_picture() is what timeline.php draws from, so nothing is scheduled
 * or worked out a second time here: this only keeps the parts a compact view
 * needs. One bar per dated pipeline step (the plan, with the share delivered),
 * the milestones, the delivery date, today, and the working days left by
 * Anima's own calendar (weekends and the studio's holidays).
 *
 * $full is false for somebody whose Timeline switch is off and who is given
 * the reduced view (REALM_CAMP_TIMELINE_WITHOUT_SWITCH): then no step bars are
 * sent at all, only the delivery date and the milestones.
 *
 * Left out on purpose: who is on each task, the detailed schedule, the
 * milestones' notes and the step editor's sentences. Those are on the Timeline.
 */
function realm_camp_timeline(PDO $pdo, array $project, bool $full): array {
    require_once __DIR__ . '/timeline_versions.php';
    $pid = (int)$project['id'];
    $today = date('Y-m-d');
    $delivery = !empty($project['delivery_date']) ? (string)$project['delivery_date'] : '';
    $pic = timeline_picture($pdo, $project);
    $steps = [];
    if ($full) {
        foreach ($pic['general'] as $g) {
            $steps[] = ['name' => (string)$g['step_name'], 'scope' => (string)$g['scope'],
                        'start' => (string)$g['start'], 'end' => (string)$g['end'],
                        'progress' => (int)$g['progress'], 'color' => (string)$g['color'], 'late' => (int)$g['late'] === 1];
        }
    }
    $marks = [];
    foreach ($pic['milestones'] as $m) {
        $marks[] = ['name' => (string)$m['name'], 'date' => (string)$m['date'], 'step' => $full ? (string)$m['step_name'] : ''];
    }
    return ['today' => $today, 'delivery' => $delivery,
            'days_left' => ($delivery !== '' && $delivery > $today && function_exists('working_days_after'))
                ? working_days_after($today, $delivery) : null,
            'reduced' => !$full, 'steps' => $steps, 'milestones' => $marks,
            'undated' => $full ? count($pic['undated']) : 0,
            'url' => 'timeline.php?project=' . $pid];
}

/**
 * THE BOARD: the show's production tracking, as production.php draws it: a row
 * per shot (or asset, or the one whole-show row), a cell per pipeline step, the
 * DONE count, and the show's own statuses for the legend.
 *
 * build_grid() is that screen's own builder, so which steps are columns, which
 * cells are a dash, and what counts as done are its answers, not a second set.
 * Sent small, because a show can have hundreds of shots: each cell is a number
 * (a status id, 0 for nothing recorded yet, -1 where the step does not apply).
 * Who is on each cell is not sent.
 *
 * $scope is 'shot', 'asset' or 'project' (the screen's Production,
 * Pre-production and Post-production tabs); anything else picks the first of
 * shots, assets that the show has any of.
 */
function realm_camp_tracking(PDO $pdo, int $projectId, string $scope): array {
    require_once __DIR__ . '/statuses.php';
    require_once __DIR__ . '/pipeline.php';
    require_once __DIR__ . '/grid.php';
    $counts = [];
    foreach (['shot' => 'shots', 'asset' => 'assets'] as $k => $table) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE project_id = ?");
        $q->execute([$projectId]);
        $counts[$k] = (int)$q->fetchColumn();
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM pipeline_steps WHERE project_id = ? AND applies_to = 'project'");
    $q->execute([$projectId]);
    $counts['project'] = (int)$q->fetchColumn();
    if (!in_array($scope, ['shot', 'asset', 'project'], true)) {
        $scope = $counts['shot'] > 0 ? 'shot' : ($counts['asset'] > 0 ? 'asset' : 'shot');
    }

    $grid = build_grid($pdo, $projectId, $scope);
    $steps = [];
    foreach ($grid['steps'] as $st) {
        $steps[] = ['id' => (int)$st['id'], 'name' => (string)$st['name'],
                    'of' => applies_to_phrase((string)$st['applies_to'], $st['applies_to_asset_type'] ?? null)];
    }
    $statuses = [];
    foreach ($grid['statuses'] as $s) {
        $statuses[] = ['id' => (int)$s['id'], 'name' => (string)$s['name'], 'color' => (string)($s['color'] ?? ''),
                       'ready' => ($s['maps_to'] ?? '') === 'approved',
                       'blocked' => (int)($s['is_blocked'] ?? 0) === 1,
                       'na' => (int)($s['is_excluded'] ?? 0) === 1];
    }
    $rows = [];
    $ready = 0;
    foreach ($grid['rows'] as $r) {
        $cells = [];
        foreach ($grid['steps'] as $st) {
            $c = $r['cells'][(int)$st['id']] ?? ['kind' => 'n/a'];
            $cells[] = $c['kind'] === 'n/a' ? -1 : ($c['kind'] === 'status' ? (int)$c['status']['id'] : 0);
        }
        if ($r['ready']) $ready++;
        $rows[] = ['n' => (string)$r['name'], 'g' => (string)$r['group'], 'c' => $cells,
                   'd' => (int)$r['done'], 't' => (int)$r['total'], 'r' => (bool)$r['ready']];
    }
    return ['scope' => $scope, 'counts' => $counts, 'steps' => $steps, 'statuses' => $statuses, 'rows' => $rows,
            'done' => (int)$grid['done_cells'], 'total' => (int)$grid['total_cells'], 'ready' => $ready,
            'url' => 'production.php?project=' . $projectId . '&scope=' . $scope];
}
