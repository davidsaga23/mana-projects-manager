<?php
// The Realm's focus desk: your open work, and Anima's own timer, from the Realm.
//
// David, 2026-11-12: "how can I change to in focus time? I think we should play
// a soft music while on focus and maybe list all the pending tasks you have in
// anima? With the option to start the clock."
//
// ⚠ THERE IS NO SECOND CLOCK. Starting and stopping here calls start_timer() and
// stop_timer() in includes/timelog.php, the same functions My tasks calls, on
// the same one-row-per-person time_timers table. So a timer started in the
// Realm is the timer My tasks shows, the hours land in the same time_logs, and
// the two screens cannot disagree about what somebody worked on. The music is
// the only thing here that is the Realm's own, and it is in the browser.
//
// ⚠ YOUR OWN WORK ONLY, checked the way mytasks.php checks it: a timer that
// could be started on somebody else's task would write that person's show into
// your hours when it stops. The project the hours are costed to is read from
// the task itself, never taken from the request.
//
// ⚠ THE REAL LOGIN'S PERSON, as everywhere in the Realm: the crew id is the one
// linked to realm_user_id(), not a previewed one.
//
// While a focus block runs, the person's door is sealed for them, and opened
// again when it stops or turns into a break. That is the "do not disturb" David
// meant by focus time; anybody who wants a different door during a break can
// still set it from the top bar.

/** The person a login is linked to, or 0. Never throws. */
function realm_crew_id(PDO $pdo, int $uid): int {
    try {
        $q = $pdo->prepare('SELECT crew_id FROM users WHERE id = ?');
        $q->execute([$uid]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** '' when the focus desk can be used, otherwise the sentence that says why not. */
function realm_focus_ready(PDO $pdo, int $crewId): string {
    if ($crewId <= 0) return 'Your login is not linked to a person yet, so there is no work to list. An administrator links it on the Users page.';
    if (!function_exists('timers_available') || !timers_available($pdo)) return 'Timers are not set up on this database yet.';
    if (function_exists('feature_on') && !feature_on('time_log')) return 'Timer and time logging is switched off for your account.';
    return '';
}

/**
 * May this account change the status of its own work from the Realm at all?
 *
 * The same two questions My tasks asks: the role ($canSetStatus there: Production,
 * artists and supervisors), and the "Set the status of my own task" switch
 * (`own_status`, which FEATURE_POSTS also asks before the save). When the answer
 * is no, the window shows each status as plain words, not a control that fails.
 */
function realm_focus_may_status(): bool {
    return (can_edit() || is_artist() || is_supervisor())
        && (!function_exists('feature_on') || feature_on('own_status'));
}

/**
 * The work this person could start a clock on: their open production tasks on
 * running shows, then their open cards. The same two lists My tasks shows, by
 * the same rules (not approved, not excluded, shows that are active or in
 * set-up). A card with no project is left out: hours are costed to a show, so
 * a timer cannot run against it.
 *
 * David, 2026-11-12, with a picture of the Production tasks table on My tasks:
 * "I'd like to expand what we see for each task and include status, editable in
 * this context, and due date". So each row now carries what that table shows.
 *
 * Each row:
 *   key      'p:<progress id>' or 't:<ticket id>'
 *   title    the step and the shot or asset, or the card's title
 *   show     the project's name
 *   pid      the project, which says whose statuses apply (realm_focus_statuses())
 *   sid      the status it has now, or null
 *   status   that status's name, '' when it has none
 *   color    that status's own colour, from the show's status list
 *   blocked  the status is one marked "blocked"
 *   due      the date, as '29 Oct', '' when it has none
 *   late     the date has passed, by the test My tasks uses (before today)
 *   logged   hours already logged on a production task ('3.5 h'), else ''.
 *            Everybody's hours on that task, the figure My tasks shows.
 *   ref      a card's reference ('RTC-12'), '' for a production task
 *   link     where Anima shows it: the task in Spell, or the card
 */
function realm_focus_tasks(PDO $pdo, int $crewId): array {
    $out = [];
    $today = date('Y-m-d');
    try {
        $q = $pdo->prepare(
            "SELECT pr.id, pr.project_id, pr.step_id, pr.item_key, pr.status_id,
                    p.name AS project_name, ps.name AS step_name,
                    s.episode, s.shot_code, a.name AS asset_name, sc.end_date,
                    st.name AS status_name, st.color AS status_color, st.is_blocked
               FROM progress pr
               JOIN projects p ON p.id = pr.project_id AND p.status IN ('active','setup')
               JOIN pipeline_steps ps ON ps.id = pr.step_id
               LEFT JOIN shots  s ON s.id = pr.shot_id
               LEFT JOIN assets a ON a.id = pr.asset_id
               LEFT JOIN statuses st ON st.id = pr.status_id
               LEFT JOIN schedule sc ON sc.step_id = pr.step_id AND sc.item_key = pr.item_key
              WHERE (pr.crew_id = ?
                     OR EXISTS (SELECT 1 FROM progress_crew pc
                                 WHERE pc.progress_id = pr.id AND pc.crew_id = ?))
                AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
                AND COALESCE(st.is_excluded, 0) = 0
              ORDER BY sc.end_date IS NULL, sc.end_date, p.code, ps.step_order
              LIMIT 40"
        );
        $q->execute([$crewId, $crewId]);
        $rows = $q->fetchAll();
        // Hours already logged, as My tasks shows them. Its own try: a database
        // with no time log yet still lists the work.
        $logged = [];
        try {
            if ($rows && function_exists('logged_by_progress')) {
                $logged = logged_by_progress($pdo, array_map(function ($r) { return (int)$r['id']; }, $rows));
            }
        } catch (Throwable $e) {
            $logged = [];
        }
        foreach ($rows as $r) {
            $what = (string)($r['shot_code'] ?? '') !== ''
                ? (function_exists('shot_label') ? shot_label($r['episode'], $r['shot_code']) : (string)$r['shot_code'])
                : (string)($r['asset_name'] ?? '');
            $mins = (int)($logged[(int)$r['id']] ?? 0);
            $out[] = [
                'key'     => 'p:' . (int)$r['id'],
                'title'   => trim((string)$r['step_name'] . ($what !== '' ? ', ' . $what : '')),
                'show'    => (string)$r['project_name'],
                'pid'     => (int)$r['project_id'],
                'sid'     => $r['status_id'] !== null ? (int)$r['status_id'] : null,
                'status'  => (string)($r['status_name'] ?? ''),
                'color'   => (string)($r['status_color'] ?? ''),
                'blocked' => (int)($r['is_blocked'] ?? 0) === 1,
                'due'     => $r['end_date'] ? date('j M', strtotime((string)$r['end_date'])) : '',
                'late'    => !empty($r['end_date']) && (string)$r['end_date'] < $today,
                'logged'  => $mins > 0 && function_exists('fmt_hours') ? fmt_hours($mins) : '',
                'ref'     => '',
                // The address Anima's own bell and e-mails use to open a task in Spell.
                'link'    => 'spell.php?project=' . (int)$r['project_id']
                           . '&open=' . rawurlencode((int)$r['step_id'] . ':' . (string)$r['item_key']),
            ];
        }
    } catch (Throwable $e) {
        // The cards below may still load.
    }
    try {
        if (function_exists('tickets_for_crew')) {
            foreach (array_slice(tickets_for_crew($pdo, $crewId), 0, 40) as $t) {
                if ((int)($t['project_id'] ?? 0) <= 0) continue;
                $out[] = [
                    'key'     => 't:' . (int)$t['id'],
                    'title'   => (string)$t['title'],
                    'show'    => (string)($t['project_name'] ?? ''),
                    'pid'     => (int)$t['project_id'],
                    'sid'     => ($t['status_id'] ?? null) !== null ? (int)$t['status_id'] : null,
                    'status'  => (string)($t['status_name'] ?? ''),
                    'color'   => (string)($t['status_color'] ?? ''),
                    'blocked' => (int)($t['status_blocked'] ?? 0) === 1,
                    'due'     => !empty($t['due_date']) ? date('j M', strtotime((string)$t['due_date'])) : '',
                    'late'    => !empty($t['due_date']) && (string)$t['due_date'] < $today,
                    'logged'  => '',
                    'ref'     => function_exists('ticket_ref') ? ticket_ref($t) : '',
                    'link'    => 'ticket.php?id=' . (int)$t['id'],
                ];
            }
        }
    } catch (Throwable $e) {
        // The production tasks above are still returned.
    }
    return $out;
}

/**
 * The statuses each listed task could be given, by project id: every show names
 * its own, so the window needs one list per show and sends each only once.
 * own_status_options() (includes/own_status.php) says which of them this account
 * may choose. A show whose list cannot be read gets none, and its tasks show
 * their status as plain words.
 */
function realm_focus_statuses(PDO $pdo, array $tasks): array {
    $out = [];
    if (!function_exists('own_status_options') || !function_exists('statuses_by_id')) return $out;
    foreach ($tasks as $t) {
        $pid = (int)($t['pid'] ?? 0);
        if ($pid <= 0 || isset($out[$pid])) continue;
        try {
            $out[$pid] = own_status_options(statuses_by_id($pdo, $pid));
        } catch (Throwable $e) {
            $out[$pid] = [];
        }
    }
    return $out;
}

/**
 * Set the status of one of this person's own tasks or cards, from the Realm.
 *
 * ⚠ NO RULES OF ITS OWN. It reads which row is meant and hands over to the two
 * functions My tasks calls (includes/own_status.php), which check the row is
 * theirs, that the status is that project's and that this account may choose
 * it, and which write the same log My tasks writes. $raw is the posted status
 * id; empty means "none", which only Production may choose.
 *
 * Returns the sentence those functions give ("Animation set to In progress.").
 *
 * @throws RuntimeException with the refusal to show
 */
function realm_focus_set_status(PDO $pdo, int $uid, int $crewId, string $key, string $raw): string {
    if (preg_match('/^([pt]):(\d+)$/', $key, $m) !== 1) throw new RuntimeException('Pick a task first.');
    if (!realm_focus_may_status() || !function_exists('own_task_set_status')) {
        throw new RuntimeException('Your account cannot change a status here. Production can change it for you.');
    }
    $statusId = !empty($raw) ? (int)$raw : null;
    return $m[1] === 'p'
        ? own_task_set_status($pdo, $crewId, (int)$m[2], $statusId)
        : own_ticket_set_status($pdo, $crewId, $uid, (int)$m[2], $statusId);
}

/**
 * The clock that is running for this person right now, or null.
 * `remaining` is the seconds left in a Pomodoro phase, or null for a plain timer.
 */
function realm_focus_now(PDO $pdo, int $crewId): ?array {
    try {
        $t = function_exists('running_timer') ? running_timer($pdo, $crewId) : null;
        if (!$t) return null;
        $phase = $t['phase'] ?? null;
        return [
            'key'       => !empty($t['progress_id']) ? 'p:' . (int)$t['progress_id'] : 't:' . (int)$t['ticket_id'],
            'phase'     => $phase ?: 'timer',
            'label'     => $phase && function_exists('pomodoro_phase_label') ? pomodoro_phase_label($phase) : 'Timer',
            'remaining' => $phase ? (int)($t['remaining_seconds'] ?? 0) : null,
            'elapsed'   => (int)($t['elapsed_seconds'] ?? 0),
            'since'     => date('H:i', strtotime((string)$t['started_at'])),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Start the clock on one of this person's own tasks or cards.
 * Returns the sentence start_timer() gives (it reports what the previous timer
 * did, because that wrote hours).
 *
 * @throws RuntimeException when the work is not theirs, or cannot be timed
 */
function realm_focus_start(PDO $pdo, int $uid, int $crewId, string $key): string {
    if (preg_match('/^([pt]):(\d+)$/', $key, $m) !== 1) throw new RuntimeException('Pick something to work on first.');
    $id = (int)$m[2];
    if ($m[1] === 'p') {
        $q = $pdo->prepare(
            'SELECT pr.project_id FROM progress pr WHERE pr.id = ? AND (pr.crew_id = ?
                OR EXISTS (SELECT 1 FROM progress_crew pc
                            WHERE pc.progress_id = pr.id AND pc.crew_id = ?))'
        );
        $q->execute([$id, $crewId, $crewId]);
        $projectId = (int)$q->fetchColumn();
        if ($projectId <= 0) throw new RuntimeException('That task is not assigned to you.');
        return start_timer($pdo, ['crew_id' => $crewId, 'project_id' => $projectId, 'progress_id' => $id,
                                  'ticket_id' => null, 'entered_by' => $uid]);
    }
    $q = $pdo->prepare('SELECT project_id FROM tickets WHERE id = ? AND assigned_crew_id = ?');
    $q->execute([$id, $crewId]);
    $projectId = $q->fetchColumn();
    if ($projectId === false) throw new RuntimeException('That card is not assigned to you.');
    if ((int)$projectId <= 0) throw new RuntimeException('That card has no show yet, and hours are costed to a show.');
    return start_timer($pdo, ['crew_id' => $crewId, 'project_id' => (int)$projectId, 'progress_id' => null,
                              'ticket_id' => $id, 'entered_by' => $uid]);
}

/**
 * Set the door to match the clock: sealed during a focus block, open otherwise.
 * Called after every focus action. Somebody with no house yet has no door.
 */
function realm_focus_door(PDO $pdo, int $uid, ?array $now): void {
    try {
        $pdo->prepare('UPDATE realm_people SET door = ? WHERE user_id = ?')
            ->execute([$now && $now['phase'] === 'focus' ? 'sealed' : 'open', $uid]);
    } catch (Throwable $e) {
        // The clock is what matters; the door can be set by hand.
    }
}

/**
 * Everything the focus desk draws: whether it can be used, the clock, the work,
 * and what this person may do to a status (`may`: change one at all, and clear
 * one, which is Production's alone, as on My tasks).
 */
function realm_focus_state(PDO $pdo, int $crewId): array {
    $why = realm_focus_ready($pdo, $crewId);
    if ($why !== '') {
        return ['ready' => false, 'why' => $why, 'now' => null, 'tasks' => [],
                'statuses' => (object)[], 'may' => ['status' => false, 'clear' => false]];
    }
    $tasks = realm_focus_tasks($pdo, $crewId);
    $may = realm_focus_may_status();
    return ['ready' => true, 'why' => '', 'now' => realm_focus_now($pdo, $crewId), 'tasks' => $tasks,
            // (object): a list keyed by project id must reach the page as {} even when empty.
            'statuses' => (object)($may ? realm_focus_statuses($pdo, $tasks) : []),
            'may' => ['status' => $may, 'clear' => $may && can_edit()]];
}
