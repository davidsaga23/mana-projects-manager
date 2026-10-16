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
    // ⚠ ZERO FOR AN UNFILED CARD. `seq` is a per-project counter and there is no
    // project to count within; ticket_ref() falls back to "#id" when there is no
    // code, so the card still has something to be called. The unique key permits
    // many (NULL, 0) rows because MySQL does not compare NULLs as equal.
    if ($projectId <= 0) return 0;
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
           -- ⚠ LEFT, since migration 041. An INNER JOIN here would have hidden
           -- every card with no project yet from the board, from Studio Tasks and
           -- from My tasks — which is to say it would have hidden them entirely,
           -- and silently, which is the worst way for a card to be missing.
           LEFT JOIN projects p   ON p.id  = t.project_id
           LEFT JOIN crew c       ON c.id  = t.assigned_crew_id
           LEFT JOIN users u      ON u.id  = t.created_by
           LEFT JOIN statuses st  ON st.id = t.status_id
           LEFT JOIN shots s      ON s.id  = t.shot_id
           LEFT JOIN assets a     ON a.id  = t.asset_id
           LEFT JOIN pipeline_steps ps ON ps.id = t.step_id';
}

/**
 * MOVE A CARD TO ANOTHER SHOW.
 *
 * David, 2026-10-07: "let's add a feature to select project... That way we can
 * move those around projects." The case that asked for it: work out of a call
 * transcript lands as a pile of tasks, and which show each one belongs to is
 * only decided while reading them.
 *
 * ⚠ FOUR THINGS CHANGE, and three of them would be silent bugs if they did not.
 *
 *   1. THE REFERENCE. `seq` is per project (migration 017), so the card takes the
 *      next number in its new home and FCM-12 becomes MSD-5. Unavoidable, and
 *      worth saying out loud: anybody who wrote the old ref in an email now holds
 *      a reference to a different card. It is written into the thread for that
 *      reason.
 *
 *   2. THE STATUS. Board columns are the PROJECT'S OWN statuses, so the id a card
 *      carries means nothing in another show. Matched by NAME first — most
 *      projects call it "Waiting to start" either way — and failing that the
 *      target's first column, so the card lands somewhere a person can see it.
 *      Never left pointing at the old project's status: that is a card on a board
 *      with no column.
 *
 *   3. THE SHOT, THE ASSET AND THE STEP. All three are rows belonging to the OLD
 *      project. Carried across they would point a card at another show's SH050.
 *      Cleared, and said so, because losing the link is obvious while a wrong one
 *      is not.
 *
 *   4. Nothing else. The assignee stays: `crew` is studio-wide (DECISIONS,
 *      2026-08-11), so the person is still the person. Comments, attachments and
 *      mentions hang off the ticket id and travel with it untouched.
 *
 * @return array{ok:bool, said:string}
 */
function move_ticket_to_project(PDO $pdo, int $ticketId, int $toProjectId): array {
    // ⚠ LEFT, since migration 041. An unfiled card is exactly the card this
    // function exists to file, and an INNER JOIN would have told the user it "no
    // longer exists" — a lie, about the one case that matters most.
    $q = $pdo->prepare(
        'SELECT t.*, p.code AS project_code FROM tickets t
           LEFT JOIN projects p ON p.id = t.project_id WHERE t.id = ?'
    );
    $q->execute([$ticketId]);
    $t = $q->fetch();
    if (!$t) return ['ok' => false, 'said' => 'That card no longer exists.'];
    $wasUnfiled = $t['project_id'] === null;
    if (!$wasUnfiled && (int)$t['project_id'] === $toProjectId) {
        return ['ok' => false, 'said' => 'That card is already on that project.'];
    }

    $to = $pdo->prepare('SELECT id, code, name FROM projects WHERE id = ?');
    $to->execute([$toProjectId]);
    $target = $to->fetch();
    if (!$target) return ['ok' => false, 'said' => 'That project no longer exists.'];

    $oldRef = ticket_ref($t);

    // The status, by name in the new project.
    // An unfiled card has no status to carry, so it lands in the new project's
    // first column rather than arriving with none — a card with no status is on no
    // board, which is the state filing it was meant to end.
    $newStatus = null;
    $statusNote = '';
    if ($t['status_id'] === null) {
        $first = $pdo->prepare(
            'SELECT id, name FROM statuses WHERE project_id = ? ORDER BY sort_order, name LIMIT 1'
        );
        $first->execute([$toProjectId]);
        if ($row = $first->fetch()) {
            $newStatus  = (int)$row['id'];
            $statusNote = ' It had no status, so it starts in "' . $row['name'] . '".';
        } else {
            $statusNote = ' ' . $target['code'] . ' has no statuses set up, so the card still has none.';
        }
    } elseif ($t['status_id'] !== null) {
        $was = $pdo->prepare('SELECT name FROM statuses WHERE id = ?');
        $was->execute([(int)$t['status_id']]);
        $wasName = (string)$was->fetchColumn();
        if ($wasName !== '') {
            $find = $pdo->prepare('SELECT id FROM statuses WHERE project_id = ? AND LOWER(name) = LOWER(?)');
            $find->execute([$toProjectId, $wasName]);
            $hit = $find->fetchColumn();
            if ($hit !== false) $newStatus = (int)$hit;
        }
        if ($newStatus === null) {
            $first = $pdo->prepare(
                'SELECT id, name FROM statuses WHERE project_id = ? ORDER BY sort_order, name LIMIT 1'
            );
            $first->execute([$toProjectId]);
            $row = $first->fetch();
            if ($row) {
                $newStatus = (int)$row['id'];
                $statusNote = ' ' . $target['code'] . ' has no status called "' . $wasName
                            . '", so it is now "' . $row['name'] . '".';
            } else {
                $statusNote = ' ' . $target['code'] . ' has no statuses set up, so the card has none.';
            }
        }
    }

    $dropped = [];
    if ($t['shot_id'])  $dropped[] = 'the shot';
    if ($t['asset_id']) $dropped[] = 'the asset';
    if ($t['step_id'])  $dropped[] = 'the step';

    $pdo->beginTransaction();
    try {
        // The new number is taken inside the transaction, so two moves at once
        // cannot be handed the same one.
        $n = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM tickets WHERE project_id = ?');
        $n->execute([$toProjectId]);
        $seq = (int)$n->fetchColumn();

        $pdo->prepare(
            'UPDATE tickets SET project_id = ?, seq = ?, status_id = ?,
                    shot_id = NULL, asset_id = NULL, step_id = NULL
              WHERE id = ?'
        )->execute([$toProjectId, $seq, $newStatus, $ticketId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'said' => 'The card was not moved: ' . $e->getMessage()];
    }

    $newRef = $target['code'] . '-' . $seq;
    $said = ($wasUnfiled ? 'Filed under ' : 'Moved to ') . $target['name'] . '. '
          . $oldRef . ' is now ' . $newRef . '.' . $statusNote;
    if ($dropped) {
        $said .= ' ' . ucfirst(implode(', ', $dropped))
               . ' belonged to the old project, so ' . (count($dropped) === 1 ? 'it was' : 'they were')
               . ' cleared.';
    }
    return ['ok' => true, 'said' => $said];
}

/* ---------------------------------------------------------------------------
 * REPLIES AND MENTIONS (David, 2026-10-06), migration 039.
 * ------------------------------------------------------------------------- */

/** Has migration 039 run? Probed per feature, so one can arrive without the other. */
function ticket_threads_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'ticket_comments', 'parent_id');
    return $ok;
}

function ticket_mentions_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'ticket_mentions', 'crew_id');
    return $ok;
}

/**
 * Find the people named in a comment.
 *
 * ⚠ MATCHED AGAINST THE KNOWN LIST, LONGEST NAME FIRST, not by a pattern. A
 * name is not a word: "Ana Ruiz" has a space in it, and a rule like `@\w+` would
 * read "@Ana Ruiz" as a mention of somebody called Ana and then leave "Ruiz"
 * lying in the text. Sorting the candidates longest-first also means "@Ana Ruiz"
 * is never mistaken for "@Ana" when both exist.
 *
 * Case-insensitive, because nobody capitalises consistently at speed.
 *
 * @param array $people rows with id and name, from ticket_assignees()
 * @return array crew_id => the name as it was actually written
 */
function ticket_parse_mentions(string $body, array $people): array {
    if (strpos($body, '@') === false) return [];

    $byLength = $people;
    usort($byLength, function ($a, $b) {
        return mb_strlen((string)$b['name']) <=> mb_strlen((string)$a['name']);
    });

    $found = [];
    $haystack = $body;
    foreach ($byLength as $person) {
        $name = trim((string)$person['name']);
        if ($name === '') continue;
        $needle = '@' . $name;
        $at = mb_stripos($haystack, $needle);
        if ($at === false) continue;
        $found[(int)$person['id']] = $name;
        // Blanked out rather than removed, so later offsets still line up and a
        // shorter name inside this one cannot match the same characters twice.
        $haystack = mb_substr($haystack, 0, $at)
                  . str_repeat(' ', mb_strlen($needle))
                  . mb_substr($haystack, $at + mb_strlen($needle));
    }
    return $found;
}

/** Record who a comment named. Replaces whatever was there, so an edit is clean. */
function ticket_save_mentions(PDO $pdo, int $commentId, array $mentions): void {
    if (!ticket_mentions_available($pdo)) return;
    $pdo->prepare('DELETE FROM ticket_mentions WHERE comment_id = ?')->execute([$commentId]);
    if (!$mentions) return;
    $ins = $pdo->prepare(
        'INSERT INTO ticket_mentions (comment_id, crew_id, named_as) VALUES (?, ?, ?)'
    );
    foreach ($mentions as $crewId => $named) $ins->execute([$commentId, (int)$crewId, $named]);
}

/**
 * A comment body, escaped, with the people in it marked.
 *
 * ⚠ ESCAPED FIRST, MARKED SECOND, and never the other way round. Building the
 * highlight into the raw text and escaping afterwards would escape our own
 * markup; escaping afterwards per fragment is how an XSS gets in. So the whole
 * body is escaped once, and the replacement operates on already-safe text with
 * an already-safe name.
 */
function ticket_render_body(string $body, array $mentions): string {
    $html = nl2br(e($body));
    foreach ($mentions as $named) {
        $safe = e('@' . $named);
        $html = str_ireplace($safe, '<span class="mention">' . $safe . '</span>', $html);
    }
    return $html;
}

/**
 * Comments arranged as threads: every top-level comment with its replies under it.
 *
 * ⚠ ONE LEVEL. A reply whose parent is itself a reply is attached to the
 * grandparent instead of starting a third tier — see migration 039 for why. A
 * reply whose parent has been deleted is shown at the top level rather than
 * dropped; losing somebody's words to a missing parent would be worse than an
 * odd position.
 */
function ticket_thread(array $comments): array {
    $byId = [];
    foreach ($comments as $c) $byId[(int)$c['id']] = $c;

    $out = [];
    $replies = [];
    foreach ($comments as $c) {
        $parent = (int)($c['parent_id'] ?? 0);
        // Climb to the top of the chain, so depth can never exceed one.
        $seen = [];
        while ($parent > 0 && isset($byId[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $next = (int)($byId[$parent]['parent_id'] ?? 0);
            if ($next <= 0 || !isset($byId[$next])) break;
            $parent = $next;
        }
        if ($parent > 0 && isset($byId[$parent]) && $parent !== (int)$c['id']) {
            $replies[$parent][] = $c;
        } else {
            $out[] = $c;
        }
    }
    foreach ($out as $i => $c) $out[$i]['replies'] = $replies[(int)$c['id']] ?? [];
    return $out;
}

/**
 * WHAT A TICKET IS ABOUT, when it is not about a part of the pipeline.
 *
 * David, 2026-10-05: "let's have a Anima Dev so they can relate tickets to the
 * self improvement of the app". Offered on every project, because the app is the
 * one thing every show has in common.
 *
 * ⚠ IT SHARES THE STEP DROPDOWN BUT IS NOT A STEP. See migration 038 for why a
 * real pipeline step would have been the expensive answer. The select posts a
 * step id OR this word, and ticket_about_from_post() tells them apart — one
 * control on the form, two columns underneath.
 */
const TICKET_ABOUT_PLATFORM = 'platform';

/** The label, where a step name would otherwise go. */
function ticket_about_label(string $about): string {
    return $about === TICKET_ABOUT_PLATFORM ? 'Anima Dev' : '';
}

/** Has migration 038 run? Probed, never assumed — the screens degrade one feature. */
function ticket_about_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'tickets', 'about');
    return $ok;
}

/**
 * Read the one select into the two things it can mean.
 *
 * @return array{step_id:?int, about:string}
 */
function ticket_about_from_post($raw): array {
    $raw = trim((string)$raw);
    if ($raw === TICKET_ABOUT_PLATFORM) {
        // No step, deliberately: a platform ticket is not about the show's work,
        // and leaving a step on it would put it on that step's detail page.
        return ['step_id' => null, 'about' => TICKET_ABOUT_PLATFORM];
    }
    return ['step_id' => $raw !== '' ? (int)$raw : null, 'about' => ''];
}

/**
 * The one dropdown, drawn the same way on the new-ticket form and the ticket
 * page, so the two cannot drift about what the options are.
 *
 * $selected is a step id, or TICKET_ABOUT_PLATFORM, or '' for neither.
 */
function ticket_step_options(array $steps, $selectedStep, string $about, bool $aboutReady): string {
    $out = '<option value="">not step-specific</option>';
    if ($aboutReady) {
        $sel = $about === TICKET_ABOUT_PLATFORM ? ' selected' : '';
        $out .= '<option value="' . TICKET_ABOUT_PLATFORM . '"' . $sel . '>'
              . e(ticket_about_label(TICKET_ABOUT_PLATFORM)) . ' — about the app itself</option>';
    }
    foreach ($steps as $st) {
        $sel = ($about === '' && (int)$selectedStep === (int)$st['id']) ? ' selected' : '';
        $out .= '<option value="' . (int)$st['id'] . '"' . $sel . '>' . e($st['name']) . '</option>';
    }
    return $out;
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
    // Anyone with a login is an owner on EVERY project, not only shows they are
    // booked on. `crew_projects` is a production allocation — it says who is
    // working the show for scheduling and quota purposes. A producer or a
    // coordinator oversees all of them and should not need a booking on each
    // just to be handed a ticket. The column arrives in migration 017, so it is
    // checked rather than assumed.
    $loggedIn = column_exists($pdo, 'users', 'crew_id')
        ? ' OR EXISTS (SELECT 1 FROM users u WHERE u.crew_id = c.id AND u.active = 1)'
        : '';

    $stmt = $pdo->prepare(
        'SELECT DISTINCT c.id, c.name, c.role, c.email, c.is_team, c.is_placeholder
           FROM crew c
           LEFT JOIN crew_projects cp ON cp.crew_id = c.id AND cp.project_id = ?
           LEFT JOIN crew_lanes   cl ON cl.crew_id = c.id AND cl.project_id = ?
          WHERE c.active = 1
            AND (cp.crew_id IS NOT NULL OR cl.crew_id IS NOT NULL OR c.is_team = 1' . $loggedIn . ')
          ORDER BY c.is_team ASC, COALESCE(c.is_placeholder, 0) ASC, c.name'
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

/**
 * Create one card, from whichever screen asked.
 *
 * David, 2026-10-16: "ok but I still can't create task from this view." The
 * board has had a create dialog since migration 017; Studio Tasks had only a
 * button through to it, and a cross-project list is exactly where a card that
 * belongs to no show gets thought of.
 *
 * ⚠ LIFTED OUT OF tasks.php RATHER THAN COPIED. Everything below was inline in
 * that one page. A second copy in alltasks.php would be two implementations of
 * "what a new card is" — the seq race, the ticket-needs-an-owner rule, the
 * platform/step split, the unmatched-name reporting — and they would drift the
 * first time one of them was fixed. Same reasoning the Studio project itself was
 * argued from on 2026-10-12.
 *
 * ⚠ $projectId IS NULLABLE, and that is the point of calling it from a
 * cross-project screen. Capture-first (migration 041) says a card may exist
 * before anybody decides which show it is for, so everything project-shaped here
 * is skipped rather than faked when there is no project: no shot or asset can be
 * resolved, no status vocabulary exists to validate against, and next_ticket_seq()
 * returns 0 so ticket_ref() falls back to "#id".
 *
 * Returns ['id', 'seq', 'type', 'ref', 'notes' => string[]]. The notes are things
 * that happened but were not asked for — a shot name nobody recognised, files
 * attached, who was emailed — and the caller is expected to say them. A typed
 * shot label silently becoming "no shot" is the unmatched-name failure the alias
 * layer exists to prevent.
 *
 * @throws RuntimeException with a sentence fit to show, having written nothing
 */
function create_ticket(PDO $pdo, ?int $projectId, array $in, array $files = [],
                       int $userId = 0): array {
    $projectId = ($projectId !== null && $projectId > 0) ? $projectId : null;

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new RuntimeException('A card needs a title, nothing was created.');

    $type = (($in['type'] ?? 'task') === 'ticket') ? 'ticket' : 'task';
    $aboutReady = ticket_about_available($pdo);
    $about = ticket_about_from_post($in['step_id'] ?? '');
    $crewId = !empty($in['assigned_crew_id']) ? (int)$in['assigned_crew_id'] : null;

    // A ticket is a problem somebody owns. Without an assignee it sits unowned
    // and nobody is told about it — checked here as well as in the form, so a
    // hand-made POST cannot slip past.
    if ($type === 'ticket' && !$crewId) {
        throw new RuntimeException('A ticket needs somebody to own it, nothing was created. '
                                 . 'Pick who should look at it.');
    }

    $statusId = !empty($in['status_id']) ? (int)$in['status_id'] : null;
    $priority = in_array($in['priority'] ?? '', ticket_priorities(), true)
        ? (string)$in['priority'] : 'medium';

    // Only meaningful within a project: both resolvers look the name up on one
    // show, and there is no show to look on.
    $shotId  = $projectId ? resolve_shot_id($pdo, $projectId, $in['shot_label'] ?? '') : null;
    $assetId = $projectId ? resolve_asset_id($pdo, $projectId, $in['asset_label'] ?? '') : null;

    $ticketId = null;
    $seq = 0;
    try {
        $pdo->beginTransaction();
        // seq is read and written inside the transaction, and the table carries
        // UNIQUE (project_id, seq) — so two people filing at once collide loudly
        // rather than quietly sharing a reference number.
        $seq = next_ticket_seq($pdo, (int)$projectId);
        $pdo->prepare(
            'INSERT INTO tickets
               (project_id, seq, type, title, description, status_id, priority,
                assigned_crew_id, due_date, shot_id, asset_id, step_id, created_by'
             . ($aboutReady ? ', about' : '') . ')
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?'
             . ($aboutReady ? ', ?' : '') . ')'
        )->execute(array_merge([
            $projectId, $seq, $type, $title,
            trim((string)($in['description'] ?? '')) ?: null,
            $statusId, $priority, $crewId,
            !empty($in['due_date']) ? $in['due_date'] : null,
            $shotId, $assetId, $about['step_id'],
            $userId ?: null,
        ], $aboutReady ? [$about['about']] : []));
        $ticketId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('That card could not be saved: ' . $e->getMessage());
    }

    $statusRow = ($statusId && $projectId)
        ? (statuses_by_id($pdo, $projectId)[$statusId] ?? null) : null;
    sync_ticket_completion($pdo, $ticketId, $statusRow);

    $notes = [];
    $typedShot  = trim((string)($in['shot_label'] ?? ''));
    $typedAsset = trim((string)($in['asset_label'] ?? ''));
    if ($projectId && $typedShot !== '' && !$shotId) {
        $notes[] = 'No shot called "' . $typedShot . '" in this project, so the card was not linked to one.';
    }
    if ($projectId && $typedAsset !== '' && !$assetId) {
        $notes[] = 'No asset called "' . $typedAsset . '" in this project, so the card was not linked to one.';
    }

    if ($files) {
        $res = save_ticket_attachments($pdo, $files, $ticketId, null, $userId);
        if ($res['saved'])  $notes[] = $res['saved'] . ' file(s) attached.';
        if ($res['errors']) $notes[] = implode(' ', $res['errors']);
    }
    if ($crewId) {
        [, $mailMsg] = notify_assignee($pdo, $ticketId, 'assigned');
        $notes[] = $mailMsg;
    }

    // The reference as people will see it, built the one way it is ever built.
    $code = '';
    if ($projectId) {
        $q = $pdo->prepare('SELECT code FROM projects WHERE id = ?');
        $q->execute([$projectId]);
        $code = (string)$q->fetchColumn();
    }
    $ref = ticket_ref(['project_code' => $code, 'seq' => $seq, 'id' => $ticketId]);

    return ['id' => $ticketId, 'seq' => $seq, 'type' => $type, 'ref' => $ref, 'notes' => $notes];
}
