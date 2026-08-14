<?php
/**
 * Tickets and tasks — the reading and writing half of the board.
 *
 * Ported from STFC's kanban.php, which is the version the team uses every day.
 * The differences are the four this codebase always has to check on a port:
 *
 *   multi-project   every query is scoped by project_id; the human reference is
 *                   per project ("RTC-12"), not a global counter
 *   assets          a ticket can name an asset as readily as a shot, and the
 *                   shot label follows the project's `episodic` flag rather than
 *                   assuming EP001_SH010
 *   crew slots      the assignee list includes placeholder slots and teams, both
 *                   of which are legitimate owners and neither of which has an
 *                   email address — said on screen, never silently skipped
 *   statuses        board columns are the project's own status vocabulary, not a
 *                   hardcoded ENUM (see migrations/017_tickets.sql)
 */

require_once __DIR__ . '/statuses.php';
// delete_ticket_with_files() needs upload_dir_path(). Pulled in here rather than
// left to each caller, so this file works wherever it is required from.
require_once __DIR__ . '/uploads.php';

/**
 * Is this request the board's own uploader rather than a browser navigation?
 *
 * It matters because of a bug inherited from STFC and fixed here. The attachment
 * forms submit through XMLHttpRequest to get a progress bar, and the server
 * answers a save with POST-redirect-GET. The XHR FOLLOWS that redirect and
 * fetches the page itself — which reads and clears the flash message — and only
 * then does the browser navigate, to a page with nothing left to say. Every
 * "3 files attached", every "no shot by that name", every "email not
 * configured" was being swallowed by the request that was supposed to deliver it.
 *
 * So an XHR gets a small JSON reply naming where to go instead, and the flash
 * survives in the session for the real page load that follows.
 */
function is_xhr_request(): bool {
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/** Finish a save: JSON for the uploader, a redirect for an ordinary form post. */
function finish_post(string $url): void {
    if (is_xhr_request()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => $url]);
        exit;
    }
    header('Location: ' . $url);
    exit;
}

/** Priorities, most urgent first — the order the board sorts cards in. */
function ticket_priorities(): array {
    return ['urgent', 'high', 'medium', 'low'];
}

/**
 * The human reference people quote in Slack: "RTC-12".
 *
 * Built at display time from the project's current code rather than stored, so
 * correcting a project code re-labels its tickets instead of leaving a stale
 * prefix behind. Same reasoning as derived lane names (DECISIONS, 2026-08-12).
 */
function ticket_ref(array $ticket, ?string $projectCode = null): string {
    $code = $projectCode ?: ($ticket['project_code'] ?? '');
    $seq  = (int)($ticket['seq'] ?? 0);
    return $code !== '' ? $code . '-' . $seq : '#' . (int)($ticket['id'] ?? 0);
}

/**
 * The statuses that are board columns, in display order.
 *
 * FALLS BACK rather than rendering an empty board: a project whose statuses have
 * all been unticked (or one created before migration 017) would otherwise get a
 * screen with nowhere to put a card and no explanation. The fallback picks one
 * status per meaning — first unfinished, first blocked, first delivered, first
 * approved — which is the same five-column shape STFC hardcoded.
 *
 * Returns [columns, $usedFallback].
 */
function ticket_board_columns(PDO $pdo, int $projectId): array {
    $all = project_statuses($pdo, $projectId);
    if (!$all) return [[], false];

    $hasFlag = column_exists($pdo, 'statuses', 'on_ticket_board');
    if ($hasFlag) {
        $picked = array_values(array_filter($all, fn($s) => (int)($s['on_ticket_board'] ?? 0) === 1));
        if ($picked) return [$picked, false];
    }

    // Fallback. Excluded statuses ("N/A") are never a column: that flag means the
    // work does not exist, which is not a place a ticket can sit.
    $usable = array_values(array_filter($all, fn($s) => (int)($s['is_excluded'] ?? 0) !== 1));

    $picked = [];
    $seen = [];
    $firstUnpicked = function (callable $test) use ($usable, &$seen): ?array {
        foreach ($usable as $s) {
            if (!isset($seen[(int)$s['id']]) && $test($s)) return $s;
        }
        return null;
    };
    $add = function (?array $s) use (&$picked, &$seen): void {
        if ($s === null) return;
        $seen[(int)$s['id']] = true;
        $picked[] = $s;
    };

    // Three unfinished states, then blocked, then the two finished meanings —
    // which on the seeded vocabulary lands on Waiting to start / Ready to start /
    // In progress / On Hold / a review state / Completed. Three rather than two
    // because "In progress" is also maps_to='none', and a board without it is not
    // a board.
    $unfinished = fn($s) => $s['maps_to'] === 'none' && (int)$s['is_blocked'] !== 1;
    $add($firstUnpicked($unfinished));
    $add($firstUnpicked($unfinished));
    $add($firstUnpicked($unfinished));
    $add($firstUnpicked(fn($s) => (int)$s['is_blocked'] === 1));
    $add($firstUnpicked(fn($s) => $s['maps_to'] === 'delivered'));
    $add($firstUnpicked(fn($s) => $s['maps_to'] === 'approved'));

    // Back into the project's own order: picked semantically, shown as the team
    // reads them.
    usort($picked, fn($a, $b) => (int)$a['sort_order'] <=> (int)$b['sort_order']);

    return [$picked, true];
}

/** Does this status mean the card is finished? */
function ticket_status_is_done(?array $status): bool {
    return $status !== null && ($status['maps_to'] ?? '') === 'approved';
}

/**
 * The next per-project ticket number.
 *
 * Called inside the same transaction as the INSERT, and the table carries
 * UNIQUE (project_id, seq) — so if two people file a ticket in the same second,
 * the second one fails loudly and retries rather than quietly reusing a number
 * somebody has already pasted into a chat.
 */
function next_ticket_seq(PDO $pdo, int $projectId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM tickets WHERE project_id = ?');
    $stmt->execute([$projectId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Keep completed_at in step with the status.
 *
 * Set when a card first reaches an approved status; cleared when it leaves one,
 * so a card reopened and finished again is archived from the SECOND finish. Not
 * derived from updated_at, which bumps on any edit — renaming a finished ticket
 * would otherwise restart its archive clock.
 */
function sync_ticket_completion(PDO $pdo, int $ticketId, ?array $status): void {
    if (ticket_status_is_done($status)) {
        $pdo->prepare('UPDATE tickets SET completed_at = COALESCE(completed_at, NOW()) WHERE id = ?')
            ->execute([$ticketId]);
    } else {
        $pdo->prepare('UPDATE tickets SET completed_at = NULL, archived = 0 WHERE id = ?')
            ->execute([$ticketId]);
    }
}

/**
 * Sweep finished cards off the board after the configured window.
 *
 * Lazy, on board load, rather than on a cron — there is no cPanel cron here and
 * this is the only screen where it matters. Returns how many moved, which the
 * board reports rather than letting cards vanish unexplained.
 */
function archive_stale_done(PDO $pdo, int $projectId): int {
    $days = (int)project_setting($pdo, $projectId, 'archive_done_after_days', '3');
    if ($days <= 0) return 0;   // 0 disables archiving entirely
    $days = min($days, 3650);   // a mistyped 99999 would just mean "never"; cap it

    // $days is interpolated, NOT bound. db.php sets EMULATE_PREPARES => false, so
    // every prepare here is a real server-side one, and this codebase has already
    // paid once for putting a placeholder somewhere MySQL's protocol would not
    // take it (functions.php, column_exists). The value is a cast int from a
    // setting we wrote, so there is nothing to inject; the project_id beside it
    // is still bound.
    $stmt = $pdo->prepare(
        "UPDATE tickets t
           JOIN statuses s ON s.id = t.status_id
            SET t.archived = 1
          WHERE t.project_id = ?
            AND t.archived = 0
            AND s.maps_to = 'approved'
            AND t.completed_at IS NOT NULL
            AND t.completed_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
    );
    $stmt->execute([$projectId]);
    return $stmt->rowCount();
}

/**
 * The SELECT every board and list shares.
 *
 * One place so the card on the board, the card in the archive and the row in the
 * cross-project list can never disagree about what a ticket says.
 */
function ticket_select_sql(): string {
    return
        'SELECT t.*,
                p.code AS project_code, p.name AS project_name,
                c.name AS crew_name, c.is_team, c.is_placeholder, c.email AS crew_email,
                u.display_name AS reporter_name,
                st.name AS status_name, st.color AS status_color,
                st.maps_to AS status_maps_to, st.is_blocked AS status_blocked,
                s.episode, s.shot_code,
                a.name AS asset_name,
                ps.name AS step_name,
                (SELECT COUNT(*) FROM ticket_attachments ta WHERE ta.ticket_id = t.id) AS attachment_count,
                (SELECT COUNT(*) FROM ticket_comments tc
                  WHERE tc.ticket_id = t.id AND tc.kind = \'user\') AS comment_count
           FROM tickets t
           JOIN projects p        ON p.id  = t.project_id
           LEFT JOIN crew c       ON c.id  = t.assigned_crew_id
           LEFT JOIN users u      ON u.id  = t.created_by
           LEFT JOIN statuses st  ON st.id = t.status_id
           LEFT JOIN shots s      ON s.id  = t.shot_id
           LEFT JOIN assets a     ON a.id  = t.asset_id
           LEFT JOIN pipeline_steps ps ON ps.id = t.step_id';
}

/** Cards for one project's board, grouped by status_id. */
function board_tickets(PDO $pdo, int $projectId, bool $archived = false): array {
    $stmt = $pdo->prepare(
        ticket_select_sql() . '
          WHERE t.project_id = ? AND t.archived = ?
          ORDER BY FIELD(t.priority, \'urgent\',\'high\',\'medium\',\'low\'),
                   t.due_date IS NULL, t.due_date ASC, t.created_at ASC'
    );
    $stmt->execute([$projectId, $archived ? 1 : 0]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        // A card whose status was deleted keeps its place on the board under key
        // 0, which the board renders in its first column. Dropping it would make
        // a real ticket invisible, which is the failure this app keeps paying for.
        $out[(int)($r['status_id'] ?? 0)][] = $r;
    }
    return $out;
}

/** One ticket, with everything the detail page shows. */
function load_ticket(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(ticket_select_sql() . ' WHERE t.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Who a ticket on this project can be assigned to.
 *
 * This project's crew, not the whole studio: a global list of everyone who has
 * ever worked at Mana is a dropdown nobody can find their compositor in. Teams
 * (`is_team`) and unfilled slots (`is_placeholder`) are included and labelled —
 * both are legitimate owners of a ticket. Neither has an email, which is why the
 * notification helper reports it rather than doing nothing.
 */
function ticket_assignees(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.id, c.name, c.role, c.email, c.is_team, c.is_placeholder
           FROM crew c
           LEFT JOIN crew_projects cp ON cp.crew_id = c.id AND cp.project_id = ?
           LEFT JOIN crew_lanes   cl ON cl.crew_id = c.id AND cl.project_id = ?
          WHERE c.active = 1
            AND (cp.crew_id IS NOT NULL OR cl.crew_id IS NOT NULL OR c.is_team = 1)
          ORDER BY c.is_team DESC, c.is_placeholder ASC, c.name'
    );
    $stmt->execute([$projectId, $projectId]);
    return $stmt->fetchAll();
}

/** How an assignee should read in a dropdown: "Animation 02 (unfilled slot)". */
function assignee_label(array $crew): string {
    if ((int)($crew['is_team'] ?? 0) === 1)        return $crew['name'] . ' (team)';
    if ((int)($crew['is_placeholder'] ?? 0) === 1) return $crew['name'] . ' (unfilled slot)';
    return $crew['name'];
}

/**
 * The label for a shot, following the project's own episode setting.
 *
 * STFC could hardcode EP002_SH050 because that show is a series. Here `episodic`
 * is a per-project flag (DECISIONS, 2026-08-11) and a commercial has one flat
 * shot list — prefixing those with an empty episode would produce "_SH010".
 */
function shot_label(?string $episode, ?string $shotCode): string {
    $episode  = trim((string)$episode);
    $shotCode = trim((string)$shotCode);
    if ($shotCode === '') return '';
    return $episode !== '' ? $episode . '_' . $shotCode : $shotCode;
}

/** Match a typed shot label back to a row in THIS project. Null when blank or unknown. */
function resolve_shot_id(PDO $pdo, int $projectId, ?string $label): ?int {
    $label = trim((string)$label);
    if ($label === '') return null;

    // Try "EPISODE_CODE" first, then the bare code — in that order, because a
    // shot code alone can repeat across episodes and the qualified form cannot.
    if (str_contains($label, '_')) {
        [$ep, $code] = explode('_', $label, 2);
        $stmt = $pdo->prepare('SELECT id FROM shots WHERE project_id = ? AND episode = ? AND shot_code = ?');
        $stmt->execute([$projectId, $ep, $code]);
        if ($id = $stmt->fetchColumn()) return (int)$id;
    }
    $stmt = $pdo->prepare('SELECT id FROM shots WHERE project_id = ? AND shot_code = ? ORDER BY episode LIMIT 1');
    $stmt->execute([$projectId, $label]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/** Match a typed asset name back to a row in THIS project. */
function resolve_asset_id(PDO $pdo, int $projectId, ?string $name): ?int {
    $name = trim((string)$name);
    if ($name === '') return null;
    $stmt = $pdo->prepare('SELECT id FROM assets WHERE project_id = ? AND name = ?');
    $stmt->execute([$projectId, $name]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Record a change in the ticket's own thread.
 *
 * The thread is titled "Activity" and, on STFC, only ever held what people
 * typed — so "who moved this to Done, and when" was nowhere. These lines sit in
 * the same list, rendered differently, which means no second table, no second
 * query, and a card whose history reads as one story.
 */
function log_ticket_event(PDO $pdo, int $ticketId, ?int $userId, string $body): void {
    $pdo->prepare(
        'INSERT INTO ticket_comments (ticket_id, user_id, kind, body) VALUES (?, ?, \'system\', ?)'
    )->execute([$ticketId, $userId ?: null, $body]);
}

/**
 * Describe what changed between two versions of a ticket, in plain words.
 *
 * Returns one string per change, ready to be written into the thread. Only the
 * fields worth a line: status, assignee, priority and due date are what people
 * ask about after the fact. A retitled ticket is visibly retitled.
 */
function describe_ticket_changes(array $before, array $after, array $statusNames, array $crewNames): array {
    $lines = [];
    $nameOf = function (?int $id, array $map, string $none) {
        return $id && isset($map[$id]) ? $map[$id] : $none;
    };

    $bs = $before['status_id'] ? (int)$before['status_id'] : null;
    $as = $after['status_id']  ? (int)$after['status_id']  : null;
    if ($bs !== $as) {
        $lines[] = 'Status: ' . $nameOf($bs, $statusNames, 'none')
                 . ' → ' . $nameOf($as, $statusNames, 'none');
    }

    $bc = $before['assigned_crew_id'] ? (int)$before['assigned_crew_id'] : null;
    $ac = $after['assigned_crew_id']  ? (int)$after['assigned_crew_id']  : null;
    if ($bc !== $ac) {
        $lines[] = 'Assigned: ' . $nameOf($bc, $crewNames, 'nobody')
                 . ' → ' . $nameOf($ac, $crewNames, 'nobody');
    }

    if (($before['priority'] ?? '') !== ($after['priority'] ?? '')) {
        $lines[] = 'Priority: ' . ucfirst($before['priority'] ?? '') . ' → ' . ucfirst($after['priority'] ?? '');
    }

    $bd = $before['due_date'] ?: null;
    $ad = $after['due_date']  ?: null;
    if ($bd !== $ad) {
        $lines[] = 'Due date: ' . ($bd ? date('M j, Y', strtotime($bd)) : 'none')
                 . ' → ' . ($ad ? date('M j, Y', strtotime($ad)) : 'none');
    }

    return $lines;
}

/**
 * Open tickets assigned to one person, across every project.
 *
 * The thing STFC could not do at all, and the reason the platform is
 * multi-project: somebody booked on two shows has one list of what is waiting
 * for them, not two boards to remember to check.
 *
 * "Open" means the status does not count as approved. A ticket with no status at
 * all counts as open — it certainly is not finished.
 */
function tickets_for_crew(PDO $pdo, int $crewId): array {
    $stmt = $pdo->prepare(
        ticket_select_sql() . "
          WHERE t.assigned_crew_id = ?
            AND t.archived = 0
            AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
          ORDER BY t.due_date IS NULL, t.due_date ASC,
                   FIELD(t.priority, 'urgent','high','medium','low'),
                   p.code, t.seq"
    );
    $stmt->execute([$crewId]);
    return $stmt->fetchAll();
}

/**
 * Tickets attached to one pipeline step, for the step detail page.
 *
 * Open ones only, and only where somebody linked the ticket to the step — this
 * is a "what is outstanding against this step" list, not an audit trail.
 */
function tickets_for_step(PDO $pdo, int $stepId): array {
    $stmt = $pdo->prepare(
        ticket_select_sql() . "
          WHERE t.step_id = ?
            AND t.archived = 0
            AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
          ORDER BY FIELD(t.priority, 'urgent','high','medium','low'), t.due_date IS NULL, t.due_date"
    );
    $stmt->execute([$stepId]);
    return $stmt->fetchAll();
}

/**
 * Delete a ticket AND its attachment files.
 *
 * The comment and attachment ROWS cascade away on their own, but the files on
 * disk would be orphaned — so they are unlinked first, while their stored names
 * can still be read. Returns what actually happened, for reporting.
 *
 * @return array{deleted:bool, files:int}
 */
function delete_ticket_with_files(PDO $pdo, int $ticketId): array {
    $filesRemoved = 0;
    try {
        $q = $pdo->prepare('SELECT stored_name FROM ticket_attachments WHERE ticket_id = ?');
        $q->execute([$ticketId]);
        $dir = upload_dir_path();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $stored) {
            $path = $dir . '/' . basename($stored);
            if (is_file($path) && @unlink($path)) $filesRemoved++;
        }
    } catch (Throwable $e) {
        // Attachments table missing (migration not yet applied) — deleting the
        // ticket is still a valid thing to do, so this is not fatal. It is also
        // not silent: the caller reports how many files went, and zero is zero.
    }

    $del = $pdo->prepare('DELETE FROM tickets WHERE id = ?');
    $del->execute([$ticketId]);
    return ['deleted' => $del->rowCount() > 0, 'files' => $filesRemoved];
}

/** A stable colour per crew member, so the same person reads the same on every card. */
function crew_color_map(array $crew): array {
    $palette = ['#2f6fb2','#1e7e34','#b8860b','#7b4fa0','#b02a37','#0e7490',
                '#c2410c','#0f766e','#9a3412','#5b21b6','#475569','#a16207'];
    $out = [];
    foreach (array_values($crew) as $i => $c) {
        $out[(int)$c['id']] = $palette[$i % count($palette)];
    }
    return $out;
}
