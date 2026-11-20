<?php
/**
 * Subscribing to tasks on Spell — migration 108.
 *
 * David, 2026-11-20: "we need a way to select all or by and the tasks and add a
 * subscription button, that will basically means that whoever is subscribed to a
 * task, will get notifications on all the tasks they're subscribed to".
 *
 * Who follows a task is still worked out first the way migration 060 decided
 * (notify_subscribers(): on it, spoken on it, named on it). This file holds the
 * two choices a person can make on top of that, one row per task and person in
 * task_subscriptions:
 *
 *   following = 1   subscribed by hand
 *   following = 0   unsubscribed, which mutes even the derived follow
 *
 * ⚠ UNSUBSCRIBING DOES NOT SILENCE WHAT IS ADDRESSED TO YOU. Being handed a task
 * ('assigned') and being @named ('mention') still ring: those are somebody
 * talking to you, not news about a task you watch.
 *
 * ⚠ NOTHING HERE THROWS ON A READ. notify_subscribers() calls in here from the
 * middle of every status change, comment and upload; a subscription that cannot
 * be read must never be the reason somebody's save is not. Before migration 108
 * every reader answers "nobody chose anything".
 */

require_once __DIR__ . '/grid.php';   // step_covers_item()

/** The small bell Spell draws for a subscription, inline like the top bar's. */
function task_follow_bell_svg(int $size = 14): string {
    return '<svg viewBox="0 0 20 20" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">'
         . '<path fill="currentColor" d="M10 2a5 5 0 0 0-5 5v3.1l-1.3 2.2a.8.8 0 0 0 .7 1.2h11.2a.8.8 0 0 0 .7-1.2L15 10.1V7a5 5 0 0 0-5-5Zm0 15.5a2.3 2.3 0 0 0 2.2-1.7H7.8A2.3 2.3 0 0 0 10 17.5Z"/></svg>';
}

/** Has migration 108 run? Asked once per request. */
function task_follow_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = function_exists('column_exists') && column_exists($pdo, 'task_subscriptions', 'following');
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * One task's hand-made choices: ['on' => crew ids, 'off' => crew ids].
 * Never throws; both lists empty before migration 108.
 */
function task_follow_choices(PDO $pdo, int $stepId, string $itemKey): array {
    $out = ['on' => [], 'off' => []];
    if ($stepId <= 0 || $itemKey === '' || !task_follow_available($pdo)) return $out;
    try {
        $q = $pdo->prepare('SELECT crew_id, following FROM task_subscriptions WHERE step_id = ? AND item_key = ?');
        $q->execute([$stepId, $itemKey]);
        foreach ($q as $r) $out[(int)$r['following'] === 1 ? 'on' : 'off'][] = (int)$r['crew_id'];
    } catch (Throwable $e) {
        error_log('[Anima follow] choices: ' . $e->getMessage());
    }
    return $out;
}

/** The same, from a progress row's id (what notify_subscribers() is handed). */
function task_follow_choices_by_progress(PDO $pdo, int $progressId): array {
    if ($progressId <= 0 || !task_follow_available($pdo)) return ['on' => [], 'off' => []];
    try {
        $q = $pdo->prepare('SELECT step_id, item_key FROM progress WHERE id = ?');
        $q->execute([$progressId]);
        $r = $q->fetch();
        if (!$r) return ['on' => [], 'off' => []];
        return task_follow_choices($pdo, (int)$r['step_id'], (string)$r['item_key']);
    } catch (Throwable $e) {
        error_log('[Anima follow] by progress: ' . $e->getMessage());
        return ['on' => [], 'off' => []];
    }
}

/**
 * Everything one person chose on one show, for drawing Spell's rows:
 * "stepId:itemKey" => true (subscribed) / false (unsubscribed). Never throws.
 */
function task_follow_mine(PDO $pdo, int $crewId, int $projectId): array {
    $out = [];
    if ($crewId <= 0 || $projectId <= 0 || !task_follow_available($pdo)) return $out;
    try {
        $q = $pdo->prepare('SELECT step_id, item_key, following FROM task_subscriptions
                             WHERE crew_id = ? AND project_id = ?');
        $q->execute([$crewId, $projectId]);
        foreach ($q as $r) $out[(int)$r['step_id'] . ':' . $r['item_key']] = (int)$r['following'] === 1;
    } catch (Throwable $e) {
        error_log('[Anima follow] mine: ' . $e->getMessage());
    }
    return $out;
}

/**
 * The people who subscribed BY HAND and are not the one acting: who hears about
 * the things that, before 108, rang nobody (a plain comment, a new assignee, a
 * moved date, a version put up while the task was already in review).
 *
 * ⚠ ONLY THE HAND-SUBSCRIBED, deliberately. The assignee and the people talking
 * on a task already hear what migration 060 decided they hear; turning every plain
 * comment into a bell for all of them would change the bell for everybody to
 * serve the few who asked. Subscribing is the asking.
 *
 * @param int[] $skip crew ids already told about this same event some other way
 * @return int[]
 */
function task_follow_hand_subscribed(PDO $pdo, int $stepId, string $itemKey, array $skip = []): array {
    $on = task_follow_choices($pdo, $stepId, $itemKey)['on'];
    if (!$on) return [];
    $skip = array_flip(array_map('intval', $skip));
    return array_values(array_filter($on, fn($id) => !isset($skip[$id])));
}

/**
 * Ring the hand-subscribed about one change. Never throws (notify_add() doesn't).
 * The kind is 'update' (migration 108); before it runs there is nobody to ring.
 *
 * @param string $what what changed, after the task's name: "new comment", "due 14 Jan"
 */
function task_follow_ring(PDO $pdo, int $projectId, int $stepId, string $itemKey, int $progressId,
                          string $taskName, string $what, array $skip = [], ?int $commentId = null): int {
    if (!function_exists('notify_add')) return 0;
    $who = task_follow_hand_subscribed($pdo, $stepId, $itemKey, $skip);
    if (!$who) return 0;
    return notify_add($pdo, $who, [
        'kind'        => 'update',
        'actor'       => function_exists('current_user_display') ? current_user_display() : 'Anima',
        'summary'     => trim($taskName) . ($what !== '' ? ' · ' . $what : ''),
        'project_id'  => $projectId,
        'step_id'     => $stepId,
        'item_key'    => $itemKey,
        'progress_id' => $progressId > 0 ? $progressId : null,
        'comment_id'  => $commentId,
    ], (int)(effective_crew_id($pdo) ?? 0));
}

/**
 * Subscribe or unsubscribe ONE PERSON to many tasks of one show, in one go.
 *
 * Every task named is checked against the show, as board_api.php checks a single
 * cell: the Department must be this show's, the shot or asset must be this
 * show's, and the Department must apply to it. Anything that fails is skipped and
 * counted, never written. The caller has already checked the person may see the
 * show at all (board_projects()).
 *
 * @param array $tasks list of [stepId, itemKey]
 * @return array{changed:int, skipped:int}
 */
function task_follow_set(PDO $pdo, int $crewId, int $projectId, array $tasks, bool $on): array {
    if (!task_follow_available($pdo)) {
        throw new RuntimeException('Subscribing needs database update 108, which has not been applied yet.');
    }
    if ($crewId <= 0) {
        throw new RuntimeException('This login is not linked to a person, so there is nobody to subscribe. An administrator links it on the Users screen.');
    }

    // The show's Departments, shots and assets, read once for the whole batch.
    $q = $pdo->prepare('SELECT * FROM pipeline_steps WHERE project_id = ?');
    $q->execute([$projectId]);
    $steps = [];
    foreach ($q as $s) $steps[(int)$s['id']] = $s;

    $q = $pdo->prepare('SELECT id FROM shots WHERE project_id = ?');
    $q->execute([$projectId]);
    $shots = array_flip(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));

    $q = $pdo->prepare('SELECT id, asset_type FROM assets WHERE project_id = ?');
    $q->execute([$projectId]);
    $assets = [];
    foreach ($q as $a) $assets[(int)$a['id']] = $a['asset_type'];

    $write = $pdo->prepare(
        'INSERT INTO task_subscriptions (step_id, item_key, crew_id, project_id, following)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE following = VALUES(following), project_id = VALUES(project_id)'
    );

    $changed = 0;
    $skipped = 0;
    $seen = [];
    $pdo->beginTransaction();
    try {
        foreach ($tasks as $t) {
            $stepId  = (int)($t[0] ?? 0);
            $itemKey = trim((string)($t[1] ?? ''));
            if (isset($seen[$stepId . ':' . $itemKey])) continue;
            $seen[$stepId . ':' . $itemKey] = true;

            $step = $steps[$stepId] ?? null;
            $kind = substr($itemKey, 0, 1);
            $itemId = (int)substr($itemKey, 1);
            $ok = $step !== null && $itemId > 0 && strlen($itemKey) <= 20;
            if ($ok) {
                if ($kind === 's')      $ok = isset($shots[$itemId]) && step_covers_item($step, ['asset_type' => null], 'shot');
                elseif ($kind === 'a')  $ok = array_key_exists($itemId, $assets)
                                           && step_covers_item($step, ['asset_type' => $assets[$itemId]], 'asset');
                elseif ($kind === 'p')  $ok = $itemId === $projectId && step_covers_item($step, ['asset_type' => null], 'project');
                else                    $ok = false;
            }
            if (!$ok) { $skipped++; continue; }

            $write->execute([$stepId, $itemKey, $crewId, $projectId, $on ? 1 : 0]);
            $changed++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['changed' => $changed, 'skipped' => $skipped];
}
