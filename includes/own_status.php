<?php
// Somebody setting the status of THEIR OWN work: one production task, or one card.
//
// These two functions are the two handlers My tasks has had since 2026-09-28
// (`set_status` and `set_ticket_status`), lifted out of mytasks.php word for
// word on 2026-11-12 so that a second screen can call them.
//
// David, 2026-11-12, about the Realm's task list: "I'd like to expand what we
// see for each task and include status, editable in this context, and due date".
// A status changed from the Realm has to be indistinguishable from one changed
// on My tasks: the same ownership check, the same In progress / Pending review
// limit for anybody who is not Production, the same flags, the same line in the
// status log or in the card's thread. Two copies of that would drift the first
// time one of them was touched, so there is ONE, here, and both screens call it.
//
// ⚠ WHO IS ASKING is decided by the caller, and it matters:
//   mytasks.php  passes effective_crew_id(), which follows a "view as" preview;
//   realm_api.php passes the REAL login's person (realm_crew_id()), because the
//                 Realm refuses a preview altogether.
// The functions only ever touch a row that belongs to the crew id they are
// given. That check is the second lock; the first is the allow-list in
// artist_may_post() (includes/auth.php), which names each screen's own field.
//
// ⚠ WHAT IS NOT HERE: the `own_status` access switch. It is asked before any
// handler runs, by feature_gate_request() reading FEATURE_POSTS
// (includes/features.php), which names both screens.
//
// A status set by hand may be overwritten by the next Plaky import. That was
// true on My tasks and is unchanged.

require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/tickets.php';

/**
 * Set the status of one production task that belongs to this person.
 * $statusId null means "nothing recorded", which only Production may choose.
 *
 * Returns the sentence to show ("Animation set to In progress.").
 *
 * @throws RuntimeException when the task is not theirs, the status is not that
 *         project's, or it is a status only Production may set
 */
function own_task_set_status(PDO $pdo, int $crewId, int $progressId, ?int $statusId): string {
    // The row must be one of THEIRS. Without this check the posted id is a way
    // to edit anybody's task.
    $q = $pdo->prepare(
        'SELECT pr.*, ps.name AS step_name FROM progress pr
           JOIN pipeline_steps ps ON ps.id = pr.step_id
          WHERE pr.id = ? AND (pr.crew_id = ?
                OR EXISTS (SELECT 1 FROM progress_crew pc
                            WHERE pc.progress_id = pr.id AND pc.crew_id = ?))'
    );
    $q->execute([$progressId, $crewId, $crewId]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('That task is not assigned to you.');

    $map = statuses_by_id($pdo, (int)$row['project_id']);
    if ($statusId !== null && !isset($map[$statusId])) {
        throw new RuntimeException('That status is not part of that project.');
    }
    $status = $statusId ? $map[$statusId] : null;
    if (!status_self_serve($status)) throw new RuntimeException(status_self_serve_refusal());
    $flags  = status_flags($status);
    $oldId  = $row['status_id'] !== null ? (int)$row['status_id'] : null;

    $pdo->prepare(
        'UPDATE progress SET status_id = ?, is_delivered = ?, is_approved = ? WHERE id = ?'
    )->execute([$statusId, $flags['is_delivered'], $flags['is_approved'], $progressId]);

    log_status_change_by_id($pdo, (int)$row['project_id'],
        ['id' => (int)$row['step_id'], 'name' => $row['step_name']],
        (string)$row['item_key'], $oldId, $statusId, [
            'shot_id'    => $row['shot_id'],
            'asset_id'   => $row['asset_id'],
            'crew_id'    => $crewId,
            'crew_name'  => current_user_display(),
            'source'     => 'item',
            'changed_by' => current_user_display(),
        ]);

    return $row['step_name'] . ' set to ' . ($status['name'] ?? 'nothing recorded') . '.';
}

/**
 * Set the status of one card (a task or a ticket) assigned to this person.
 *
 * Added 2026-09-28 with the artist role. The ticket screen's own `update` was
 * not reusable: it rewrites the whole card (title, assignee, priority, due
 * date) and an artist saying "this is ready for review" should not be able to
 * hand the ticket to somebody else in the same request.
 *
 * $userId is the REAL login, so the line in the card's thread names who
 * actually did it.
 *
 * Returns the sentence to show ("RTC-12 set to Pending review.").
 *
 * @throws RuntimeException when the card is not theirs, has no project, the
 *         status is not that project's, or only Production may set it
 */
function own_ticket_set_status(PDO $pdo, int $crewId, ?int $userId, int $ticketId, ?int $statusId): string {
    // ⚠ LEFT, since migration 041: an INNER JOIN would have told the owner
    // of an unfiled card it "is not assigned to you", which is false.
    $q = $pdo->prepare(
        'SELECT t.*, p.code AS project_code FROM tickets t
           LEFT JOIN projects p ON p.id = t.project_id
          WHERE t.id = ? AND t.assigned_crew_id = ?'
    );
    $q->execute([$ticketId, $crewId]);
    $tk = $q->fetch();
    if (!$tk) throw new RuntimeException('That ticket is not assigned to you.');

    if ($tk['project_id'] === null) {
        throw new RuntimeException(
            'That card is not on a project yet, so there are no statuses to choose from. '
          . 'Open it and file it on a show first.'
        );
    }

    $map = statuses_by_id($pdo, (int)$tk['project_id']);
    if ($statusId !== null && !isset($map[$statusId])) {
        throw new RuntimeException('That status is not part of that project.');
    }
    $status = $statusId ? $map[$statusId] : null;
    if (!status_self_serve($status)) throw new RuntimeException(status_self_serve_refusal());
    $oldId  = $tk['status_id'] !== null ? (int)$tk['status_id'] : null;

    if ($oldId === $statusId) return 'That was already its status, nothing changed.';

    $pdo->prepare('UPDATE tickets SET status_id = ? WHERE id = ?')
        ->execute([$statusId, $ticketId]);
    // Closing a card is a property of the STATUS, not of a separate tick, so
    // the same helper the ticket screen uses keeps the two screens agreeing.
    sync_ticket_completion($pdo, $ticketId, $status);

    // Into the card's own thread, so its history reads the same whoever moved
    // it and from wherever.
    $was = $oldId && isset($map[$oldId]) ? $map[$oldId]['name'] : 'no status';
    $now = $status['name'] ?? 'no status';
    log_ticket_event($pdo, $ticketId, $userId, 'Status: ' . $was . ' → ' . $now);

    return ticket_ref($tk) . ' set to ' . $now . '.';
}

/**
 * One project's statuses as a picker for somebody's own work may offer them.
 *
 * Each: id, name, color, and `open`: may the account that is asking move its
 * own work INTO this status (status_self_serve(): everything for Production, In
 * progress and Pending review for anybody else). A status marked "excluded" is
 * left out, as My tasks leaves it out.
 *
 * ⚠ This only decides what a screen DRAWS. The two functions above ask
 * status_self_serve() again on the way in, so a hand-made request cannot choose
 * a status that was not offered. mytasks.php draws its two dropdowns inline by
 * the same test; the Realm reads this list.
 */
function own_status_options(array $statuses): array {
    $out = [];
    foreach ($statuses as $s) {
        if ((int)($s['is_excluded'] ?? 0) === 1) continue;
        $out[] = [
            'id'    => (int)$s['id'],
            'name'  => (string)$s['name'],
            'color' => (string)($s['color'] ?? ''),
            'open'  => status_self_serve($s),
        ];
    }
    return $out;
}
