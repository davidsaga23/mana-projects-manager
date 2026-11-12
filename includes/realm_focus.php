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
 * The work this person could start a clock on: their open production tasks on
 * running shows, then their open cards. The same two lists My tasks shows, by
 * the same rules (not approved, not excluded, shows that are active or in
 * set-up). A card with no project is left out: hours are costed to a show, so
 * a timer cannot run against it.
 *
 * Each row: key ('p:<progress id>' or 't:<ticket id>'), title, show, due.
 */
function realm_focus_tasks(PDO $pdo, int $crewId): array {
    $out = [];
    try {
        $q = $pdo->prepare(
            "SELECT pr.id, p.name AS project_name, ps.name AS step_name,
                    s.episode, s.shot_code, a.name AS asset_name, sc.end_date
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
        foreach ($q->fetchAll() as $r) {
            $what = (string)($r['shot_code'] ?? '') !== ''
                ? (function_exists('shot_label') ? shot_label($r['episode'], $r['shot_code']) : (string)$r['shot_code'])
                : (string)($r['asset_name'] ?? '');
            $out[] = [
                'key'   => 'p:' . (int)$r['id'],
                'title' => trim((string)$r['step_name'] . ($what !== '' ? ', ' . $what : '')),
                'show'  => (string)$r['project_name'],
                'due'   => $r['end_date'] ? date('j M', strtotime((string)$r['end_date'])) : '',
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
                    'key'   => 't:' . (int)$t['id'],
                    'title' => (string)$t['title'],
                    'show'  => (string)($t['project_name'] ?? ''),
                    'due'   => !empty($t['due_date']) ? date('j M', strtotime((string)$t['due_date'])) : '',
                ];
            }
        }
    } catch (Throwable $e) {
        // The production tasks above are still returned.
    }
    return $out;
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

/** Everything the focus desk draws: whether it can be used, the clock, the work. */
function realm_focus_state(PDO $pdo, int $crewId): array {
    $why = realm_focus_ready($pdo, $crewId);
    if ($why !== '') return ['ready' => false, 'why' => $why, 'now' => null, 'tasks' => []];
    return ['ready' => true, 'why' => '', 'now' => realm_focus_now($pdo, $crewId), 'tasks' => realm_focus_tasks($pdo, $crewId)];
}
