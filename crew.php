<?php
// A project's crew — the slots its headcount implies, and who is in them.
//
// The idea this screen exists for: a crew is never complete until the show
// nearly is, because people arrive as their department starts. So the plan is
// built against SLOTS, and a slot becomes a person the day one is hired —
// without anything that already referenced it having to move.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/hiring.php';
require_once __DIR__ . '/includes/people_merge.php';
require_once __DIR__ . '/includes/artists.php';   // artist_missing(), for the gaps marker
require_once __DIR__ . '/includes/money.php';    // crew_terms(), for the contract line on a filled slot
require_login();

/**
 * One candidate in the running for a position: the stage in place, Hire when the
 * position is empty, and the way to their record. Drawn on empty positions and,
 * without Hire, among a filled position's backups.
 */
function staffing_candidate(array $h, bool $canHire): void {
    ?>
    <div class="cand">
      <strong><?= e($h['candidate_name']) ?></strong>
      <?php if (can_edit_area('staffing')): ?>
        <?php // The stage moves in place. "Hired" is NOT in this list:
              // set_hire_stage() would run the real hire from a dropdown with no
              // confirmation, and hiring somebody by mis-clicking a select is not
              // a mistake this screen should make possible. It has its own button. ?>
        <form method="post" class="inline-form cand-stage">
          <select name="stage" onchange="this.form.submit()"
                  aria-label="Stage for <?= e($h['candidate_name']) ?>">
            <?php foreach (HIRE_STAGES as $k => $lbl): if ($k === 'hired') continue; ?>
              <option value="<?= e($k) ?>" <?= $h['stage'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="action" value="stage:<?= (int)$h['id'] ?>">
        </form>
        <?php if ($canHire): ?>
          <form method="post" class="cand-hire"
                onsubmit="return confirm('Hire <?= e(addslashes($h['candidate_name'])) ?> into this position? Everything already planned for it becomes theirs.');">
            <button type="submit" class="row-btn row-btn-go" name="action"
                    value="hire:<?= (int)$h['id'] ?>">Hire</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <span class="muted small">(<?= e(strtolower(hire_stage_label($h['stage']))) ?>)</span>
      <?php endif; ?>
      <?php // ⚠ IT GOES TO THE PERSON, not to the Hiring screen (David, 2026-09-02:
            // "the view details should take you to the detailed page of that
            // person"). A CANDIDATE HAS NO person.php PAGE, and cannot: that page is
            // built on a crew record, and somebody being talked to deliberately
            // does not have one until they are hired. Their record is their entry
            // in the artist database, where every fact about them lives. ?>
      <a class="row-btn" href="artists.php?q=<?= urlencode((string)$h['candidate_name']) ?>">View details</a>
      <?php // WHAT IS STILL BLANK, said where the conversation is actually
            // happening. Nothing is mandatory, so this is the only thing standing
            // between a thin entry and a book nobody trusts. Quiet on purpose: a
            // reminder, not a fault. ?>
      <?php $gaps = artist_missing($h); if ($gaps): ?>
        <span class="cand-gaps"
              title="Their artist database entry has no <?= e(implode(', no ', $gaps)) ?>. Nothing here is required, but a record with these filled in is one the next show can use.">needs <?= e(implode(', ', $gaps)) ?></span>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * Somebody talked to for a position whose conversation has ended: they said no,
 * we said no, or they were hired and have since left it.
 *
 * David, 2026-09-18: "It happens that they leave the project and I need to see who
 * I talked to and what other options I have." So they stay on the position, with
 * how it ended and when, and one click puts them back in the running.
 */
function staffing_earlier_candidate(array $h, array $leftDates): void {
    $left = hire_has_left($h);
    $how  = $left ? 'Left the position' : hire_stage_label($h['stage']);
    // When it ended: the day they left, from the stage log, or the day the stage
    // last moved. Somebody who left before departures were recorded has no date.
    $when = $left ? ($leftDates[(int)$h['id']] ?? null) : ($h['stage_changed_at'] ?? null);
    $stamp = $when ? strtotime((string)$when) : false;
    ?>
    <div class="cand cand-earlier">
      <strong><?= e($h['candidate_name']) ?></strong>
      <span class="cand-was cand-was-<?= $left ? 'left' : e((string)$h['stage']) ?>"><?= e($how) ?><?=
        $stamp ? ', ' . e(date('j M Y', $stamp)) : '' ?></span>
      <?php if (can_edit_area('staffing')): ?>
        <form method="post" class="cand-hire">
          <button type="submit" class="row-btn" name="action"
                  value="reopen:<?= (int)$h['id'] ?>">Line up again</button>
        </form>
      <?php endif; ?>
      <a class="row-btn" href="artists.php?q=<?= urlencode((string)$h['candidate_name']) ?>">View details</a>
    </div>
    <?php
}

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$selfUrl = 'crew.php?project=' . $projectId;
$schemaReady = column_exists($pdo, 'crew', 'is_placeholder');
// Freelance or Vendor on each position (migration 050). Until it is applied the
// badge and the picker are simply absent rather than offering a choice that
// cannot be saved.
$typeReady = $schemaReady && collaborator_type_available($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!$schemaReady) {
            throw new RuntimeException(
                'The database updates this screen needs have not been applied yet, so nothing ' .
                'was saved. An administrator can apply them from the Migrations screen.'
            );
        }

        if ($action === 'generate') {
            $r = sync_project_lanes($pdo, $projectId);
            if ($r['created'] === 0 && $r['existing'] === 0) {
                flash('warn', 'No slots were created, no pipeline step has a headcount yet. ' .
                              'Set one on the Dates & headcount screen and come back.');
            } else {
                flash('ok', sprintf(
                    'Created %d new slot%s; %d already existed.',
                    $r['created'], $r['created'] === 1 ? '' : 's', $r['existing']
                ));
            }
            if (!empty($r['renamed'])) {
                flash('ok', sprintf(
                    'Renamed %d slot%s so two steps sharing a name can be told apart, ' .
                    'they now carry what they apply to, e.g. "Concept Character 01". ' .
                    'Only unfilled slots were touched.',
                    $r['renamed'], $r['renamed'] === 1 ? '' : 's'
                ));
            }
            if ($r['skipped']) {
                flash('warn', 'No headcount set, so no slots were made for: ' .
                              implode(', ', $r['skipped']) . '.');
            }
            // ONE message, not one per slot. Lowering a headcount from 7 to 2
            // produces five surplus slots at a stroke, and five near-identical
            // warnings pushed everything else off the screen — including the two
            // lines saying what had actually been done. The slots themselves are
            // marked in the table below, which is where they can be acted on.
            if ($r['surplus']) {
                $n = count($r['surplus']);
                flash('warn', sprintf(
                    '%d slot%s beyond what the current headcount asks for. %s kept, not deleted, ' .
                    '%s may already have work planned against %s. They are marked below.',
                    $n, $n === 1 ? ' is' : 's are', $n === 1 ? 'It was' : 'They were',
                    $n === 1 ? 'it' : 'they', $n === 1 ? 'it' : 'them'
                ));
            }

        } elseif ($action === 'clean_surplus') {
            $r = remove_surplus_lanes($pdo, $projectId);
            if ($r['removed'] === 0 && !$r['blocked']) {
                flash('warn', 'Nothing to clean up, every slot matches its step\'s headcount.');
            } else {
                flash('ok', sprintf(
                    'Removed %d surplus slot%s.%s',
                    $r['removed'], $r['removed'] === 1 ? '' : 's',
                    $r['kept_people'] > 0
                        ? ' ' . $r['kept_people'] . ' of them held a real person, they were taken off '
                          . 'this step but remain on the studio crew list.'
                        : ''
                ));
            }
            if ($r['blocked']) {
                $bits = [];
                foreach ($r['blocked'] as $b) $bits[] = $b['name'] . ' (' . $b['tasks'] . ')';
                flash('warn', sprintf(
                    '%d slot%s left alone because %s still %s scheduled work on %s: %s. ' .
                    'Re-run the schedule so that work moves onto the remaining slots, then clean up again.',
                    count($r['blocked']), count($r['blocked']) === 1 ? ' was' : 's were',
                    count($r['blocked']) === 1 ? 'it' : 'they',
                    count($r['blocked']) === 1 ? 'has' : 'have',
                    count($r['blocked']) === 1 ? 'it' : 'them',
                    implode(', ', $bits)
                ));
            }

        } elseif (preg_match('/^hire:(\d+)$/', $action, $m)) {
            // ⚠ THE SAME hire_candidate() THE HIRING SCREEN CALLS, not a second
            // way of doing it. The candidate is found or created once by name,
            // the position is pointed at them, their artist record is marked
            // hired and linked to the person it created, and the stage change
            // is logged.
            //
            // No name field on the button: the name is already on the hiring
            // record and hire_candidate() falls back to it. Retyping it is
            // exactly how "Luka Bromely" becomes a second person.
            foreach (hire_candidate($pdo, $projectId, (int)$m[1], [], current_user_display()) as $said) {
                flash('ok', $said);
            }

        } elseif (preg_match('/^stage:(\d+)$/', $action, $m)) {
            // set_hire_stage() routes "hired" through hire_candidate() itself,
            // so this cannot become a second, weaker way of hiring somebody.
            flash('ok', set_hire_stage($pdo, $projectId, (int)$m[1],
                                       (string)($_POST['stage'] ?? ''), '', current_user_display()));

        } elseif (preg_match('/^reopen:(\d+)$/', $action, $m)) {
            // "LINE UP AGAIN", from the people talked to before (David,
            // 2026-09-18). The same record goes back to Approached, so the person
            // keeps one history on this position rather than a second, empty copy
            // of them, and the stage log still says how it went the first time.
            // set_hire_stage() refuses anybody who still holds the position.
            $h = get_hire($pdo, (int)$m[1]);
            set_hire_stage($pdo, $projectId, (int)$m[1], 'approached', 'Lined up again',
                           current_user_display());
            flash('ok', sprintf('%s is lined up for this position again, at Approached.',
                                $h['candidate_name'] ?? 'They'));

        } elseif (preg_match('/^addcand:(\d+)$/', $action, $m)) {
            // TWO WAYS IN, one handler. Picking somebody the studio already
            // knows carries their record across; typing a name starts a new
            // one. save_hire() checks the lane belongs to THIS project in both
            // cases, so a posted id cannot attach anybody to another show.
            $laneId  = (int)$m[1];
            $knownId = (int)($_POST['known_id'] ?? 0);
            $r = $knownId > 0
                ? add_known_candidate($pdo, $projectId, $laneId, $knownId, current_user_display())
                : save_hire($pdo, $projectId, [
                      'candidate_name' => $_POST['candidate_name'] ?? '',
                      'email'          => $_POST['candidate_email'] ?? '',
                      'lane_id'        => $laneId,
                      'stage'          => HIRE_STAGE_NEW,
                  ], current_user_display());
            foreach ((array)$r as $said) if (is_string($said)) flash('ok', $said);

        } elseif (preg_match('/^assign:(\d+)$/', $action, $m)) {
            $crewId = (int)($_POST['assign_crew'][$m[1]] ?? 0);
            if ($crewId <= 0) throw new RuntimeException('Pick somebody to put in that slot.');
            $was = lane_crew_id($pdo, $projectId, (int)$m[1]);
            // The same Save carries the collaborator type, so switching somebody
            // from Freelance to Vendor is "Change assignee", keep the same name,
            // pick Vendor, Save. Nothing moves when the person is unchanged.
            $type = (string)($_POST['collab_type'][$m[1]] ?? '');
            if ($was !== $crewId) {
                assign_crew_to_lane($pdo, $projectId, (int)$m[1], $crewId);
                // Whoever was hired into it has now left it, and their history says when.
                note_left_position($pdo, $projectId, (int)$m[1], $was, current_user_display());
            }
            if ($typeReady && $type !== '') set_lane_collaborator_type($pdo, $projectId, (int)$m[1], $type);
            flash('ok', $was !== $crewId
                ? 'Slot filled. Any work already scheduled on that track moved with it.'
                : 'Saved.' . ($typeReady && $type !== ''
                    ? ' This position is now marked ' . collaborator_type_label($type) . '.' : ''));

        } elseif (preg_match('/^unassign:(\d+)$/', $action, $m)) {
            // Empties the slot without removing it — see unassign_lane() for why
            // that is a placeholder going back in rather than a NULL.
            $was = lane_crew_id($pdo, $projectId, (int)$m[1]);
            flash('ok', unassign_lane($pdo, $projectId, (int)$m[1]));
            // An empty position goes back to the default, so whoever fills it
            // next does not inherit the last occupant's Vendor.
            if ($typeReady) set_lane_collaborator_type($pdo, $projectId, (int)$m[1], 'freelance');
            // The person hired into it has left it: said in their history, and
            // shown with everybody else talked to for this position.
            note_left_position($pdo, $projectId, (int)$m[1], $was, current_user_display());

        } elseif (preg_match('/^remove_work:(\d+)$/', $action, $m)) {
            // THE WAY THROUGH. remove: refuses while a slot carries scheduled
            // work, and for two of the three reasons it can be blocked there is
            // no re-run that would ever clear it — see the refusal below. Left
            // at that, the slot could not be removed at all.
            //
            // So this removes the slot AND the work on it, in one transaction,
            // and the button that reaches it says how many tasks that is and
            // how many are hand-locked. Destructive and named as such: auto
            // tasks come back on the next Regenerate, hand-planned dates do not.
            $laneId = (int)$m[1];
            $stmt = $pdo->prepare(
                'SELECT cl.*, c.is_placeholder, c.name FROM crew_lanes cl
                   JOIN crew c ON c.id = cl.crew_id
                  WHERE cl.id = ? AND cl.project_id = ?'
            );
            $stmt->execute([$laneId, $projectId]);
            $lane = $stmt->fetch();
            if (!$lane) throw new RuntimeException('That slot no longer exists.');

            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare(
                    'DELETE FROM schedule WHERE project_id = ? AND step_id = ? AND lane = ?'
                );
                $del->execute([$projectId, (int)$lane['step_id'], (int)$lane['lane_no']]);
                $gone = $del->rowCount();

                $pdo->prepare('DELETE FROM crew_lanes WHERE id = ?')->execute([$laneId]);
                if ((int)$lane['is_placeholder'] === 1) {
                    $pdo->prepare(
                        'DELETE FROM crew WHERE id = ? AND is_placeholder = 1
                          AND NOT EXISTS (SELECT 1 FROM crew_lanes WHERE crew_id = crew.id)'
                    )->execute([(int)$lane['crew_id']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            flash('ok', sprintf('Removed the slot "%s" and %d scheduled task%s from it. '
                              . 'Regenerate to replan that work across the slots that remain.',
                                $lane['name'], $gone, $gone === 1 ? '' : 's'));

        } elseif (preg_match('/^remove:(\d+)$/', $action, $m)) {
            // Only ever removes the SLOT, never the person: a real crew member
            // belongs to the studio, not to one show.
            $laneId = (int)$m[1];
            $stmt = $pdo->prepare(
                'SELECT cl.*, c.is_placeholder, c.name, ps.planned_headcount
                   FROM crew_lanes cl
                   JOIN crew c ON c.id = cl.crew_id
                   JOIN pipeline_steps ps ON ps.id = cl.step_id
                  WHERE cl.id = ? AND cl.project_id = ?'
            );
            $stmt->execute([$laneId, $projectId]);
            $lane = $stmt->fetch();
            if (!$lane) throw new RuntimeException('That slot no longer exists.');

            // WHY THIS REFUSAL HAD TO LEARN THE CASES (David, 2026-08-31).
            //
            // It used to say "re-run the schedule first so that work moves
            // elsewhere" for every blocked slot. That advice is only true for
            // one of the three, and sends you round a loop in the other two:
            //
            //   * a HAND-LOCKED task survives every re-run by design —
            //     commit_schedule only deletes is_manual = 0 — so re-running
            //     can never clear it, however many times you try;
            //   * a lane WITHIN the headcount is rebuilt by the next run, which
            //     allocates max(1, ceil(headcount)) lanes. Lane 01 therefore
            //     always comes back, so that advice could never work for it;
            //   * only a SURPLUS lane carrying auto tasks is actually freed by
            //     re-running, which is the case the old message described.
            $used = $pdo->prepare(
                'SELECT COUNT(*) AS n, COALESCE(SUM(is_manual), 0) AS locked
                   FROM schedule WHERE step_id = ? AND lane = ? AND project_id = ?'
            );
            $used->execute([(int)$lane['step_id'], (int)$lane['lane_no'], $projectId]);
            $u = $used->fetch();
            $n = (int)($u['n'] ?? 0);
            $locked = (int)($u['locked'] ?? 0);

            if ($n > 0) {
                $isSurplus = (int)$lane['lane_no'] > step_slot_count($lane);
                $why = $locked > 0
                    ? ($locked === $n ? 'Re-running the schedule will not move it: it is hand-locked, '
                                      . 'and a re-run leaves locked tasks exactly where they are.'
                                      : $locked . ' of them are hand-locked, and a re-run leaves those '
                                      . 'exactly where they are.')
                    : (!$isSurplus
                        ? 'Re-running the schedule will not help either: this slot is still within the '
                        . 'headcount planned for the step, so the next run fills it again. Lower the '
                        . 'headcount on Dates & headcount first if the slot is not wanted.'
                        : 'Re-running the schedule would move this work elsewhere, since the slot is '
                        . 'beyond the planned headcount.');
                throw new RuntimeException(
                    "\"{$lane['name']}\" has $n scheduled task" . ($n === 1 ? '' : 's') .
                    ' on it. ' . $why .
                    ' To remove the slot and that work together, use "Remove slot and its work".'
                );
            }
            $pdo->prepare('DELETE FROM crew_lanes WHERE id = ?')->execute([$laneId]);
            // An empty placeholder that belongs to nothing else is just litter.
            if ((int)$lane['is_placeholder'] === 1) {
                $pdo->prepare(
                    'DELETE FROM crew WHERE id = ? AND is_placeholder = 1
                      AND NOT EXISTS (SELECT 1 FROM crew_lanes WHERE crew_id = crew.id)'
                )->execute([(int)$lane['crew_id']]);
            }
            flash('ok', 'Removed the slot "' . $lane['name'] . '".');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$lanes    = $schemaReady ? project_lanes($pdo, $projectId) : [];

// How much scheduled work sits on each slot, and how much of it is hand-locked.
// ONE grouped query rather than one per row: thirty-seven slots would otherwise
// be thirty-seven round trips to draw a button label.
//
// Needed on the way IN, not just when a removal is refused: whether "Remove
// slot" will work at all is exactly what you want to know before pressing it.
$laneWork = [];
if ($schemaReady) {
    $lw = $pdo->prepare(
        'SELECT step_id, lane, COUNT(*) AS n, COALESCE(SUM(is_manual), 0) AS locked
           FROM schedule WHERE project_id = ? GROUP BY step_id, lane'
    );
    $lw->execute([$projectId]);
    foreach ($lw as $r) {
        $laneWork[(int)$r['step_id'] . '|' . (int)$r['lane']] =
            ['n' => (int)$r['n'], 'locked' => (int)$r['locked']];
    }
}
// CONTRACT STATE AND LINK, for the filled slots (David, 2026-10-05: "add the
// contract status in line and the contract link as a hyperlink (no editable in
// this context) for convenience").
//
// ⚠ ONE CALL FOR THE WHOLE PAGE, and it opens the OTHER database. crew_terms()
// returns [] for anybody who cannot see money, so an editor's page simply has no
// contract line rather than a blocked one — the same way the day rate is absent
// from the artist grid rather than greyed out. Thirty-seven slots must not be
// thirty-seven connections to a second database for one line of text each.
//
// ⚠ AND IT SAYS WHY IT IS EMPTY. The first cut of this just returned [] on every
// failure, so "nobody has a contract recorded", "the column does not exist" and
// "the finance database is unreachable" were one blank row — which is lesson 11,
// and is exactly the comment hiring.php already carries three hundred lines
// away. David reported the line missing on 2026-10-05 and there was nothing on
// screen to tell him which of the three it was.
$laneTerms = [];
$contractsOff = null;          // a sentence when the state cannot be shown at all
if ($schemaReady && can_see_money()) {
    $ids = [];
    foreach ($lanes as $l) {
        if ((int)($l['is_placeholder'] ?? 0) !== 1 && (int)($l['crew_id'] ?? 0) > 0) {
            $ids[] = (int)$l['crew_id'];
        }
    }
    try {
        if (!finance_configured()) {
            $contractsOff = 'The finance database is not configured on this install, so contract '
                          . 'states cannot be read.';
        } elseif (contracts_need_upgrade()) {
            $contractsOff = 'Contract states need <code>finance_install_04.sql</code>, which has '
                          . 'not been imported yet. Contract links still show; the state does not.';
        } elseif ($ids) {
            $laneTerms = crew_terms(array_values(array_unique($ids)));
        }
        // The link lives on crew_terms whether or not 04 has run, so it is still
        // worth fetching when only the STATE is unavailable.
        if ($contractsOff !== null && $ids && finance_configured()) {
            $laneTerms = crew_terms(array_values(array_unique($ids)));
        }
    } catch (Throwable $e) {
        $contractsOff = 'The finance database could not be read, so contract states are missing '
                      . 'from this page: ' . $e->getMessage();
    }
}

$people   = $schemaReady ? assignable_crew($pdo) : [];
$unfilled = $schemaReady ? unfilled_slot_count($pdo, $projectId) : 0;

// Who is lined up for the empty slots. An unfilled slot with three candidates in
// play and one with nobody at all look identical on this screen otherwise, and
// they are completely different problems.
$hiringReady = hiring_available($pdo);
// THE ARTIST DATABASE, one row per person, read once for the whole page rather
// than once per position. known_candidates() is the same list the Hiring
// screen's type-ahead uses, which is the point: one book, read the same way
// wherever somebody is looked up.
$book = $hiringReady ? known_candidates($pdo) : [];
$candidatesByLane = $hiringReady ? hires_by_lane($pdo, $projectId) : [];
// When each person hired into a position they have since left, left it: read once
// for the page, for the "Left, 18 Sep 2026" beside their name. See hire_has_left().
$leftIds = [];
foreach ($candidatesByLane as $laneHires) {
    foreach ($laneHires as $h) if (hire_has_left($h)) $leftIds[] = (int)$h['id'];
}
$leftDates = $hiringReady ? hire_left_dates($pdo, $leftIds) : [];

// Steps with a headcount but no slots yet — the reason to press Generate.
$stmt = $pdo->prepare(
    'SELECT ps.id, ps.name, ps.planned_headcount
       FROM pipeline_steps ps WHERE ps.project_id = ? ORDER BY ps.step_order, ps.id'
);
$stmt->execute([$projectId]);
$steps = $stmt->fetchAll();

$haveByStep = [];
foreach ($lanes as $l) $haveByStep[(int)$l['step_id']] = ($haveByStep[(int)$l['step_id']] ?? 0) + 1;
$missing = 0;
foreach ($steps as $s) {
    $want = step_slot_count($s);
    $missing += max(0, $want - ($haveByStep[(int)$s['id']] ?? 0));
}

// Slots above what their step's headcount now asks for. Counted on every page
// view, not just after a sync: a flash message is gone on the next reload, and
// this is a state the project is IN rather than something that just happened.
$surplusCount = 0;
foreach ($lanes as $l) {
    if ((int)$l['lane_no'] > step_slot_count($l)) $surplusCount++;
}

// Grouped by step ID, not step name. Two steps can legitimately share a name —
// "Concept" for characters and "Concept" for environments — and keying by name
// merged them into one block showing "Concept 01" twice.
$byStep = [];
foreach ($lanes as $l) $byStep[(int)$l['step_id']][] = $l;

// DEPARTMENT FIRST, then the steps inside it (David, 2026-09-02: "the crew
// should only be a list per department"). pipeline_steps.department has carried
// this since migration 015 and nothing on this screen was reading it.
//
// The steps keep PIPELINE ORDER inside each department, and the departments
// themselves are ordered by their earliest step, so the page still reads top to
// bottom in the order the work happens. Sorting departments alphabetically
// would put Compositing above Layout, which is not how anybody reads a plan.
//
// A step with no department set groups under "Unassigned", last, and is not
// hidden - the same choice breakdown_asset_types() makes for an asset with no
// type. A blank field is a filing job, not a reason to disappear.
$byDept = [];
foreach ($byStep as $stepId => $rows) {
    $dept = trim((string)($rows[0]['department'] ?? '')) ?: 'Unassigned';
    $byDept[$dept][$stepId] = $rows;
}
uasort($byDept, function ($a, $b) {
    $first = function (array $steps) {
        $min = PHP_INT_MAX;
        foreach ($steps as $rows) $min = min($min, (int)$rows[0]['step_order']);
        return $min;
    };
    return $first($a) <=> $first($b);
});
if (isset($byDept['Unassigned'])) {
    $tail = $byDept['Unassigned'];
    unset($byDept['Unassigned']);
    $byDept['Unassigned'] = $tail;
}

$pageTitle = 'Staffing · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'crew'); ?>

<h2 class="wizard-title">Staffing <span class="muted">· <?= e($project['name']) ?></span></h2>

<?php // A SHEET TO SEND, not another view. David, 2026-09-02: something to let
      // the other supervisors know about these staffing decisions. It carries
      // the step's own dates, which this screen does not need and a message
      // leaving the building does: "empty" is a fact, "empty and starts on the
      // 14th" is a deadline with a name missing from it. ?>
<?php if ($lanes): ?>
  <p class="page-actions button-row">
    <a class="button secondary" href="export_staffing.php?project=<?= $projectId ?>">Export staffing ↓</a>
  </p>
<?php endif; ?>


<p class="lead">
  A crew is never complete until a show nearly is, people arrive as their department starts.
  So the plan is built against <strong>slots</strong>, one per person the headcount asks for,
  and a slot becomes a real person the day someone is hired into it. Everything already
  planned for that slot becomes their work, with nothing moved.
</p>

<?php if (!$schemaReady): ?>
  <div class="flash flash-err">
    This screen needs database updates that haven't been applied.
    <?= is_admin() ? '<a href="migrate.php">Apply them →</a>' : 'Ask an administrator to run them.' ?>
  </div>
<?php else: ?>

  <div class="summary-line">
    <?php if ($lanes): ?>
      <strong><?= count($lanes) ?></strong> slot<?= count($lanes) === 1 ? '' : 's' ?>
      · <strong><?= count($lanes) - $unfilled ?></strong> filled
      · <strong><?= $unfilled ?></strong> still a placeholder
      <?php if ($surplusCount): ?>
        · <span class="warn-text"><strong><?= $surplusCount ?></strong> beyond the headcount</span>
      <?php endif; ?>
    <?php else: ?>
      No slots yet.
    <?php endif; ?>
  </div>

  <?php if ($surplusCount): ?>
    <div class="flash flash-warn">
      <strong><?= $surplusCount ?></strong> slot<?= $surplusCount === 1 ? '' : 's' ?>
      <?= $surplusCount === 1 ? 'is' : 'are' ?> above what the headcount now asks for,
      lowering a step's headcount never deletes people, because work may already be planned
      against them. <?= $surplusCount === 1 ? 'It is' : 'They are' ?> highlighted below.
      Re-run the <a href="schedule.php?project=<?= $projectId ?>">schedule</a> first so that
      work moves onto the remaining slots, then remove the empty ones.
    </div>
  <?php endif; ?>

  <?php if (can_edit_area('staffing')): ?>
    <?php
    // Both buttons are always drawn, each disabled when it has nothing to do, so
    // the pair reads as "the slots and the headcount can be brought into line"
    // rather than as controls that appear and vanish. The title says WHY a
    // disabled button is disabled — a greyed-out control with no explanation is
    // indistinguishable from a broken one.
    ?>
    <div class="inline-form toggle-form slot-actions">
      <form method="post">
        <input type="hidden" name="action" value="generate">
        <button type="submit" <?= $missing === 0 ? 'disabled' : '' ?>
                title="<?= $missing === 0
                    ? 'Every step already has the slots its headcount asks for.'
                    : e($missing . ' slot(s) the headcount asks for are missing.') ?>">
          <?= $lanes ? 'Add missing slots' : 'Create slots from the headcount' ?><?php
            if ($missing > 0): ?> (<?= $missing ?>)<?php endif; ?>
        </button>
      </form>

      <form method="post"
            onsubmit="return confirm('Remove <?= $surplusCount ?> slot(s) above the current headcount? Any holding a real person keep the person on the studio crew list; any with scheduled work are left alone.');">
        <input type="hidden" name="action" value="clean_surplus">
        <button type="submit" class="secondary" <?= $surplusCount === 0 ? 'disabled' : '' ?>
                title="<?= $surplusCount === 0
                    ? 'No slot is above what its step\'s headcount asks for.'
                    : e($surplusCount . ' slot(s) are above the current headcount.') ?>">
          Remove surplus slots<?php if ($surplusCount > 0): ?> (<?= $surplusCount ?>)<?php endif; ?>
        </button>
      </form>

      <small>
        Adding never deletes anything. Removing only takes out slots above the headcount, and
        skips any that still have scheduled work on them.
      </small>
    </div>
  <?php endif; ?>

<?php // ⚠ SAID WHERE IT IS NOTICED. A person recorded twice appears as two
      // identical options in the picker below, and there is no way to tell them
      // apart by looking. Admin only, because merging them is.
      //
      // One query over `crew`, only for an administrator. The expensive part,
      // counting what each record carries, happens on the merge screen itself. ?>
<?php if ($contractsOff !== null): ?>
  <div class="flash flash-warn"><?= $contractsOff ?></div>
<?php endif; ?>

<?php if (is_admin() && ($dupes = duplicate_crew_groups($pdo))): ?>
  <div class="flash flash-warn">
    <strong><?= count($dupes) ?></strong>
    <?= count($dupes) === 1 ? 'person is' : 'people are' ?> recorded more than once in the studio
    crew list, so <?= count($dupes) === 1 ? 'their name appears' : 'their names appear' ?>
    twice in the picker below with no way to tell them apart.
    <a href="people_merge.php">Put them back together &rarr;</a>
  </div>
<?php endif; ?>

  <?php if (!$lanes): ?>
    <div class="empty-state">
      <h2>No crew slots yet</h2>
      <?php if ($missing > 0): ?>
        <p>Create them from the headcount you already entered, and the scheduler will start
           assigning work to them by name.</p>
      <?php else: ?>
        <p>Set a headcount per step on the <a href="wizard.php?project=<?= $projectId ?>&step=6">Dates
           &amp; headcount</a> screen first, that is what says how many people each step needs.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <?php foreach ($byDept as $deptName => $deptSteps): ?>
      <?php
        // What this department still needs, said on its own heading. How many
        // positions are still empty is the one number somebody opens this
        // screen for, and it was only available by counting rows by eye.
        // The same three states the rows carry, counted. "2 still to fill" is
        // worth knowing; "and nobody is being spoken to about one of them" is
        // the half that decides whether it is a problem this week.
        $deptSlots = 0; $deptEmpty = 0; $deptNobody = 0;
        foreach ($deptSteps as $deptRows) {
            foreach ($deptRows as $dl) {
                $deptSlots++;
                if ((int)$dl['is_placeholder'] !== 1) continue;
                $deptEmpty++;
                $anyLive = $hiringReady && array_filter(
                    $candidatesByLane[(int)$dl['id']] ?? [],
                    fn($h) => in_array($h['stage'], HIRE_STAGES_OPEN, true));
                if (!$anyLive) $deptNobody++;
            }
        }
      ?>
      <h3 class="crew-dept-head">
        <?= e($deptName) ?>
        <span class="muted small">
          <?= $deptSlots ?> position<?= $deptSlots === 1 ? '' : 's' ?>
          <?php if ($deptEmpty > 0): ?>
            · <span class="warn-text"><?= $deptEmpty ?> still to fill</span>
            <?php if ($deptNobody > 0): ?>
              · <span class="err"><?= $deptNobody ?> with nobody in the frame</span>
            <?php endif; ?>
          <?php else: ?>
            · <span class="ok">all filled</span>
          <?php endif; ?>
        </span>
      </h3>

    <?php foreach ($deptSteps as $rows): $first = $rows[0]; ?>
      <h3 class="crew-step-head">
        <span class="dot" style="background:<?= e($first['color'] ?: '#ccc') ?>"></span>
        <?= e($first['step_name']) ?>
        <span class="muted">· <?= e(applies_to_phrase($first['applies_to'],
                                     $first['applies_to_asset_type'] ?? null)) ?></span>
      </h3>
      <?php
      // Only two facts per row: which position, and who is in it. The step name
      // and what it applies to are in the heading above — repeating them on
      // every row made three columns that all said the same thing, and made
      // "Slot" and "Who" look like duplicates of each other.
      ?>
      <table class="review-table">
        <tr><th class="col-num">#</th><th>Who</th><th></th></tr>
        <?php foreach ($rows as $l):
          $isPh = (int)$l['is_placeholder'] === 1;
          // Beyond what this step's headcount now asks for. Shown on the row
          // rather than only in a flash message, so it is still visible on the
          // next page view and sits next to the button that removes it.
          $isSurplus = (int)$l['lane_no'] > step_slot_count($l);

          // ⚠ THREE STATES, NOT TWO (David, 2026-09-02: a visual cue for filled
          // against unfilled). Filled or not is the question asked, but an open
          // position with two people in the frame and an open position with
          // nobody are completely different problems, and a cue that painted
          // them the same colour would hide the only one that needs doing
          // something about today.
          //
          // Computed here rather than inside the cell below, because the whole
          // ROW carries the cue and the row opens first.
          $allHere = $hiringReady ? ($candidatesByLane[(int)$l['id']] ?? []) : [];
          $openHere = array_values(array_filter($allHere,
              fn($h) => in_array($h['stage'], HIRE_STAGES_OPEN, true)));
          // Everybody whose conversation about this position has ended. Not the
          // person holding it: their record reads hired and they are the name on
          // the row already.
          $earlierHere = array_values(array_filter($allHere,
              fn($h) => in_array($h['stage'], ['declined', 'passed'], true) || hire_has_left($h)));
          $liveHere = $isPh ? $openHere : [];
          $slotState = !$isPh ? 'filled' : ($liveHere ? 'inplay' : 'nobody');
        ?>
        <?php // ⚠ FILLED ROWS CARRY NO COLOUR AT ALL, and that is the design
              // rather than an omission. On a show that is mostly staffed, a
              // green stripe down thirty rows is a wall telling you nothing;
              // the eye should be pulled to what still needs work, not to what
              // is already done. So done is quiet and open is not. ?>
        <tr class="slot-<?= $slotState ?><?= $isSurplus ? ' has-problem' : '' ?>">
          <td class="col-num muted"><?= str_pad((string)(int)$l['lane_no'], 2, '0', STR_PAD_LEFT) ?></td>
          <td>
            <?php // Straight to that person's page — their workload, their pace
                  // and their days. The name was the one thing on this row that
                  // read like a link and was not one (David, 2026-08-31).
                  //
                  // Placeholders link too. person.php knows what a slot is and
                  // says so, and team.php has always listed them the same way —
                  // "what is Rough 02 carrying?" is a fair question before
                  // anyone is hired into it. ?>
            <strong><a href="person.php?project=<?= $projectId ?>&amp;crew=<?= (int)$l['crew_id'] ?>"><?= e($l['name']) ?></a></strong>
            <?php // FREELANCE OR VENDOR (David, 2026-10-17), beside the name and
                  // visible to everyone: unlike the contract it is not money.
                  // Only on a filled position, because an empty one has nobody
                  // to be either. Changed under "Change assignee". ?>
            <?php if ($typeReady && !$isPh):
              $cType = collaborator_type_of($l); ?>
              <span class="collab-chip collab-<?= e($cType) ?>"><?= e(collaborator_type_label($cType)) ?></span>
            <?php endif; ?>
            <?php // THE CONTRACT, READ ONLY (David, 2026-10-05). Changing one is
                  // still the Hiring screen's job — it has the dates, the log and
                  // the rate beside it, and a state picker on a row whose other
                  // three controls are staffing decisions would read as a fourth
                  // one. This is here "for convenience": to see at a glance who
                  // is signed and to open the document.
                  //
                  // Administrators only, because crew_terms lives in the finance
                  // database. An editor sees nothing here rather than a greyed
                  // control, which is the rule the day rate already follows. ?>
            <?php // ⚠ DRAWN EVEN WITH NO RECORD AT ALL, which is the fix for what
                  // David saw on 2026-10-05: the first cut only drew this line
                  // when crew_terms held a row, so everybody who had never had a
                  // rate or a contract entered — which on a freshly staffed show
                  // is everybody — looked exactly like the feature had not
                  // shipped. "Nobody has started the contract" is the single most
                  // actionable thing this line can say, so it is the one state it
                  // must not stay silent about. ?>
            <?php if (!$isPh && can_see_money()):
              $ct = $laneTerms[(int)$l['crew_id']] ?? [];
              $cUrl = trim((string)($ct['contract_url'] ?? ''));
              // No state while $contractsOff explains why on the line at the top
              // of the page — but a LINK still shows, because contract_url lives
              // on crew_terms whether or not finance_install_04 has been run.
              $showState = ($contractsOff === null);
              $cStatus = $showState ? ((string)($ct['contract_status'] ?? '') ?: 'none') : '';
              $waiting = ($showState && $ct) ? contract_days_waiting($ct) : null;
              if ($showState || $cUrl !== ''): ?>
              <div class="slot-contract">
                <?php if ($cStatus !== ''): ?>
                  <span class="ct-chip ct-<?= e($cStatus) ?>"
                        title="<?= e(contract_state_phrase($cStatus, $ct['contract_sent_date'] ?? null,
                                                           $ct['contract_signed_date'] ?? null)) ?>"><?=
                    e($cStatus === 'none' ? 'no contract' : $cStatus) ?></span>
                  <?php // The number that actually chases a contract, and free
                        // here because money.php already works it out. ?>
                  <?php if ($waiting !== null): ?>
                    <span class="muted small"><?= (int)$waiting ?> day<?= $waiting === 1 ? '' : 's' ?> out</span>
                  <?php endif; ?>
                <?php endif; ?>
                <?php // ⚠ TRUNCATED IN CSS, NOT IN PHP — the rule the reel column
                      // on the artist grid already follows: the whole address
                      // stays selectable and copyable, and the tooltip carries it
                      // in full. Cutting the string here would hand somebody a
                      // half URL they cannot paste anywhere. ?>
                <?php if ($cUrl !== ''): ?>
                  <a class="ct-link" href="<?= e($cUrl) ?>" target="_blank" rel="noopener"
                     title="<?= e($cUrl) ?>"><?= e($cUrl) ?></a>
                <?php endif; ?>
              </div>
            <?php endif; endif; ?>
            <?php // "placeholder" was internal vocabulary printed at a reader:
                  // it is the database's word for an empty position, not
                  // anybody's word for one. What the row needs to say is what
                  // state it is in, and a filled one needs to say nothing at
                  // all - the person's name has already said it. ?>
            <?php if ($isPh && $liveHere): ?>
              <span class="slot-state slot-state-inplay"><?= count($liveHere) ?>
                in the running</span>
            <?php elseif ($isPh): ?>
              <span class="slot-state slot-state-nobody">Nobody yet</span>
            <?php elseif (!$l['active']): ?>
              <span class="muted small">(inactive)</span>
            <?php endif; ?>
            <?php if ($isSurplus): ?>
              <div class="warn-text small">
                Beyond the <?= e(fmt_num($l['planned_headcount'])) ?> now planned for this step.
              </div>
            <?php endif; ?>
            <?php if ($isPh && $hiringReady): $live = $liveHere; ?>
              <?php // ⚠ THE WHOLE STAFFING LOOP, ON THE POSITION IT BELONGS TO
                    // (David, 2026-09-02: "I was asking to merge both of them and
                    // do all the staffing and hiring in the same page").
                    //
                    // A position and the conversations to fill it are one thing
                    // to N things, and one-to-many belongs nested, not on a
                    // second screen: you never look at an empty position without
                    // wanting to know who is in the frame for it, and you never
                    // move somebody's stage without knowing what they are being
                    // considered for. Put candidates, their stage and the hire
                    // itself on the row and the two lists are one list.
                    //
                    // The Hiring screen keeps what does NOT nest into a row: the
                    // full record per candidate, notes, quoted rates, contract
                    // states, and adding people in bulk. Reached from the row
                    // below rather than as a tab of its own. ?>
              <div class="lined-up">
                <?php if ($live): ?>
                  <?php foreach ($live as $h): ?>
                    <?php staffing_candidate($h, true); ?>
                  <?php endforeach; ?>
                <?php elseif ($earlierHere): ?>
                  <div class="muted small warn-text">Nobody in the running for this position right now.</div>
                <?php else: ?>
                  <div class="muted small warn-text">Nobody lined up for this position yet.</div>
                <?php endif; ?>

                <?php // EVERYBODY TALKED TO BEFORE, kept on the position (David,
                      // 2026-09-18: "It happens that they leave the project and I
                      // need to see who I talked to and what other options I
                      // have"). Declined, passed, and whoever was hired and has
                      // since left. OPEN when nobody is in the running, because
                      // that is the moment this list is the answer. ?>
                <?php if ($earlierHere): ?>
                  <details class="closed-cands cand-earlier-list" <?= $live ? '' : 'open' ?>>
                    <summary>Talked to before (<?= count($earlierHere) ?>)</summary>
                    <?php foreach ($earlierHere as $h): ?>
                      <?php staffing_earlier_candidate($h, $leftDates); ?>
                    <?php endforeach; ?>
                  </details>
                <?php endif; ?>

                <?php if (can_edit_area('staffing')): ?>
                  <?php // ADDING SOMEBODY HAPPENS HERE, against the position they
                        // are being considered for. Two fields, because that is
                        // all a first conversation has; everything else about
                        // them is filled in later on their record. ?>
                  <details class="cand-add">
                    <summary class="row-btn">+ Add a candidate</summary>

                    <?php // THE BOOK FIRST, typing second, because most people
                          // put up for a position are already in it and picking
                          // carries their email, reel, position and rating
                          // across. Typing a name that is already in the book
                          // is how a second, emptier copy of somebody gets
                          // made. Same order, and the same reasoning, as the
                          // two ways into a slot on the row above. ?>
                    <?php if ($book): ?>
                      <form method="post" class="inline-form cand-add-form">
                        <select name="known_id" aria-label="Somebody already in the artist database">
                          <option value="">from the artist database…</option>
                          <?php foreach ($book as $k): ?>
                            <option value="<?= (int)$k['id'] ?>"><?= e($k['candidate_name']) ?><?php
                              if (!empty($k['position'])) echo ' · ' . e($k['position']);
                              if (!empty($k['project_code'])) echo ' · last on ' . e($k['project_code']);
                            ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="secondary" name="action"
                                value="addcand:<?= (int)$l['id'] ?>">Line up candidate</button>
                      </form>
                    <?php endif; ?>

                    <form method="post" class="inline-form cand-add-form">
                      <input name="candidate_name" placeholder="or a name new to the studio" required
                             aria-label="New candidate name">
                      <input name="candidate_email" type="email" placeholder="Email (optional)"
                             aria-label="New candidate email">
                      <button type="submit" class="secondary" name="action"
                              value="addcand:<?= (int)$l['id'] ?>">Add and line up candidate</button>
                    </form>
                  </details>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php // THE BACKUPS, ON A FILLED POSITION (David, 2026-09-18: "is there
                  // a way we can keep the lined up artists in the staffing tab?").
                  // Hiring somebody used to take every other name off this row,
                  // so the people who might replace them were out of sight
                  // exactly when they were needed. Folded, because a filled row
                  // stays quiet; counted in the button, so the list is known to
                  // be there. No Hire button while somebody holds the position:
                  // taking them out comes first, and that is its own decision. ?>
            <?php if (!$isPh && ($openHere || $earlierHere)): ?>
              <details class="closed-cands cand-backups">
                <summary>Also lined up for this position (<?= count($openHere) + count($earlierHere) ?>)</summary>
                <div class="lined-up">
                  <?php foreach ($openHere as $h): ?>
                    <?php staffing_candidate($h, false); ?>
                  <?php endforeach; ?>
                  <?php foreach ($earlierHere as $h): ?>
                    <?php staffing_earlier_candidate($h, $leftDates); ?>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php endif; ?>
          </td>
          <?php // THREE BUTTONS ON ONE LINE, right-aligned (David, 2026-08-31).
                // They stacked before, because <details> is a block and the task
                // count sat under it — three controls in a column reading as a
                // list of unrelated things rather than the row's actions.
                //
                // The count moves under the row: still said BEFORE the click,
                // which is the whole reason it is on screen, but out of the line
                // the buttons make. ?>
          <td class="slot-actions">
            <?php $work = $laneWork[(int)$l['step_id'] . '|' . (int)$l['lane_no']]
                        ?? ['n' => 0, 'locked' => 0]; ?>
            <div class="slot-row">
            <?php // A BUTTON, matching the filled rows. An empty slot used to
                  // offer "Someone has been hired for this" as a line of text,
                  // so the two states of the same row looked like two different
                  // screens. "Register hire" is what it does (David,
                  // 2026-08-31), and it is the only action an empty slot has
                  // besides removing it. ?>
            <?php if (can_edit_area('staffing') && $isPh): ?>
              <?php // ⚠ NOTHING FILLS A POSITION FROM THIS COLUMN ANY MORE.
                    //
                    // "Register hire" went on 2026-09-02 because it made
                    // duplicate people. "Put somebody in" went the same day, for
                    // a different reason: it moved somebody already on the
                    // studio crew into a position, and David's studio has three
                    // full-time people. "We don't have employees... in that
                    // sense, that button doesn't work for anything." A control
                    // whose whole premise is a staff list this studio does not
                    // have is one more thing to explain on every screen.
                    //
                    // Filling a position now has ONE route, on the row itself:
                    // line somebody up and hire them. That is true for a
                    // freelancer and equally true for Junior, and one route is
                    // the answer to the question David asked three times about
                    // which of these buttons does what.
                    //
                    // The assign: handler stays. "Change assignee" on a FILLED
                    // position still uses it, and swapping one person for
                    // another mid-show is a real thing that needs the scheduled
                    // work carried across. ?>
              <?php // (kept for the record) NO "REGISTER HIRE" HERE EITHER.
                    //
                    // It used to type a name straight into this slot, and that
                    // is where every duplicate person in the studio list came
                    // from. Each slot owns its own crew row; registering
                    // RENAMED that row into the person, and nothing checked
                    // whether the name already existed. Put the same artist in
                    // three slots and the studio had three of them, each
                    // holding its own scheduled work, with no way to join them
                    // up. It also wrote nobody into the artist database, so
                    // somebody hired this way stayed "approached" in the book
                    // for ever.
                    //
                    // Hiring happens on the Hiring screen now, which is the one
                    // place that records WHO, from WHERE, at WHAT STAGE, and
                    // links the person it creates back to their entry in the
                    // book. What is left here is the staffing decision, which
                    // is a different act: put somebody the studio already knows
                    // into this position. That path re-points the slot rather
                    // than renaming it, so it cannot make a second copy of
                    // anybody. ?>
            <?php elseif (can_edit_area('staffing')): ?>
              <?php // BUTTONS, not a line of small print (David, 2026-08-31).
                    // These are the two things done to a filled slot, and they
                    // were previously a disclosure summary and a link — both
                    // reading as text on a row where everything else is text.
                    //
                    // The picker stays folded until asked for. A select naming
                    // every person on the books, drawn open on thirty-five rows,
                    // is a wall; behind a button it is one click when wanted and
                    // invisible otherwise. <details> does that with no
                    // JavaScript, which is why the summary IS the button rather
                    // than a script toggling a panel. ?>
              <details class="slot-change">
                <summary class="slot-btn">Change assignee</summary>
                <form method="post" class="inline-form slot-change-bar">
                  <select name="assign_crew[<?= (int)$l['id'] ?>]">
                    <option value="">pick somebody</option>
                    <?php foreach ($people as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$l['crew_id'] ? 'selected' : '' ?>>
                        <?= e($p['name']) ?><?= $p['active'] ? '' : ' (inactive)' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if ($typeReady): ?>
                    <select name="collab_type[<?= (int)$l['id'] ?>]" aria-label="Collaborator type">
                      <?php foreach (COLLABORATOR_TYPES as $t): ?>
                        <option value="<?= e($t) ?>" <?= $t === collaborator_type_of($l) ? 'selected' : '' ?>><?= e(collaborator_type_label($t)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                  <button type="submit" class="secondary" name="action" value="assign:<?= (int)$l['id'] ?>">Save</button>
                  <?php // type=button, so it dismisses rather than submitting the
                        // form it happens to sit inside. ?>
                  <button type="button" class="popover-close" aria-label="Close">&times;</button>
                </form>
              </details>

              <?php // TAKING THE PERSON OUT IS NOT REMOVING THE SLOT, and the two
                    // sit side by side, so the difference has to be in the words
                    // rather than left to be discovered. Removing a slot says the
                    // plan no longer needs this job done; taking the assignee out
                    // says it does, and somebody else will do it.
                    //
                    // The slot stays, its scheduled work stays on it, and the
                    // person stays on the studio crew list. Only shown where
                    // there is somebody to take out. ?>
              <form method="post"
                    onsubmit="return confirm('Take <?= e(addslashes($l['name'])) ?> out of this slot? The slot stays, its scheduled work stays on it, and they stay on the studio crew list.');">
                <button type="submit" class="secondary danger" name="action"
                        value="unassign:<?= (int)$l['id'] ?>">Remove assignee</button>
              </form>
            <?php endif; ?>
            <?php // ONE LABEL, "Remove slot". It always takes the work with it —
                  // that is what removing a slot means — so spelling it out in
                  // the button only made the row longer. The CONFIRMATION still
                  // says exactly what will go, including how much of it cannot
                  // come back from a Regenerate, which is where that detail is
                  // actually read. ?>
            <?php if (can_edit_area('staffing')): ?>
              <form method="post" class="slot-remove"
                    onsubmit="return confirm('<?= $work['n'] === 0
                        ? 'Remove this slot? The person stays on the studio crew list.'
                        : 'Remove this slot AND delete the ' . (int)$work['n'] . ' task'
                          . ($work['n'] === 1 ? '' : 's') . ' scheduled on it?'
                          . ($work['locked'] > 0
                              ? ' ' . (int)$work['locked'] . ' of them are hand-planned and will not come back from a Regenerate.'
                              : ' They are auto-scheduled, so a Regenerate will replan them across the slots that remain.') ?>');">
                <button type="submit" class="secondary danger" name="action"
                        value="<?= $work['n'] === 0 ? 'remove:' : 'remove_work:' ?><?= (int)$l['id'] ?>">Remove slot</button>
              </form>
            <?php endif; ?>
            </div>

            <?php // Under the buttons rather than among them, so the line of
                  // three stays a line of three. Still before the click. ?>
            <?php if ($work['n'] > 0): ?>
              <div class="muted small slot-note">
                <?= (int)$work['n'] ?> scheduled task<?= $work['n'] === 1 ? '' : 's' ?><?php
                  if ($work['locked'] > 0): ?>, <?= (int)$work['locked'] ?> hand-locked<?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach; ?>
    <?php endforeach; ?>

  <?php endif; ?>
<?php endif; ?>

<?php // Closing the popovers: an ×, a click outside, or Escape. The opening is
      // plain <details> and needs nothing, so a browser without this file still
      // works — it just closes by pressing the button again. ?>
<script src="assets/crew.js<?= asset_ver('crew.js') ?>"></script>

</div>
</body></html>
