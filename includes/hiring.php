<?php
/**
 * Hiring — the months between "the plan needs a person here" and "a named
 * person has agreed terms".
 *
 * The platform has always known both ends of that. An unfilled slot says the
 * plan needs somebody; the expense projection has lately been able to say how
 * much that gap costs, in unpriced days. What has had nowhere to live is the
 * middle: who was approached, what they wanted, whether they said yes.
 *
 * TWO THINGS TO KNOW BEFORE CHANGING ANY OF IT.
 *
 * 1. A CANDIDATE IS NOT A CREW ROW. They are somebody being talked to. Giving
 *    them a `crew` record early would put them in every assignee dropdown in
 *    the app and leave the ones who said no in the studio's permanent people
 *    list for ever. They become crew at exactly one moment — being hired.
 *
 * 2. HIRING LINKS A PERSON TO A POSITION. It does not turn the position into
 *    the person, which is what it did until 2026-09-02.
 *
 *    The old way renamed the slot's own crew row into the candidate, through
 *    promote_placeholder(). The id never changed, so everything already planned
 *    for that slot became theirs with nothing moved, and that was genuinely
 *    elegant for ONE slot. Across slots it was a data bug: every slot has its
 *    own crew row, nothing checked whether the name already existed, and an
 *    artist hired into three slots became three people. Every duplicate in the
 *    studio list came from it.
 *
 *    Now the person is found or created once, by exact name, and the slot is
 *    re-pointed at them by assign_crew_to_lane(), which carries the scheduled
 *    work across. Three slots point at one record, which is what hiring the
 *    same artist three times always meant. See people_merge.php for the mess
 *    the old way left and the tool that cleans it up.
 *
 * WHERE THE MONEY IS. Not here. What a candidate quoted lives in the finance
 * database behind includes/money.php, which is the only place that connection
 * is opened. This file holds the conversation, which any editor may see; the
 * number is admin-only. David's call, 2026-08-17 — the same split `crew` and
 * `crew_terms` already make for people who have been hired.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crew.php';
require_once __DIR__ . '/people_merge.php';
// The one door to pay data. Required rather than probed for with function_exists:
// hiring hands a quoted rate across at the moment somebody is taken on, and a
// dependency that is silently absent would make that step quietly not happen —
// which is the same failure as a feature that disables itself in a catch
// (START_HERE, lesson 11). Loading the file costs nothing; every function in it
// refuses on its own for anyone who is not an admin.
require_once __DIR__ . '/money.php';
// notify_admins_of_hire(): every hire tells the administrators who hired whom.
require_once __DIR__ . '/mailer.php';

/**
 * The stages, in the order somebody moves through them.
 *
 * An ENUM in the schema rather than rows in `statuses`, which is unusual here —
 * migration 024's header says why. The short version: production statuses vary
 * by show and these do not, and "offer out" does not map onto any production
 * state.
 */
const HIRE_STAGES = [
    'lead'       => 'Lead',
    'approached' => 'Approached',
    'in_talks'   => 'In talks',
    'offer_out'  => 'Offer out',
    'hired'      => 'Hired',
    'declined'   => 'Declined',
    'passed'     => 'Passed',
];

/**
 * Where a candidate STARTS (David, 2026-09-28: "in the staffing section let's
 * make sure the default option is lead instead of approached").
 *
 * Lead, because adding somebody to a slot is a note that they might do it, not
 * a claim that anyone has written to them yet. Starting at Approached made the
 * board say a conversation had happened the moment a name was typed — and the
 * one number a producer reads off this screen is how many people are actually
 * waiting on a reply.
 *
 * Named rather than repeated: the two ways in (pick from the book, type a name)
 * and save_hire()'s own fallback all read this, so the answer cannot differ by
 * which door was used. `hires.stage` has defaulted to 'lead' in the schema since
 * migration 024; this brings the code back in line with it.
 */
const HIRE_STAGE_NEW = 'lead';

/** Still in play — the ones that mean somebody should be doing something. */
const HIRE_STAGES_OPEN = ['lead', 'approached', 'in_talks', 'offer_out'];

/**
 * Finished. `declined` (they said no) and `passed` (we said no) are separate on
 * purpose: they are identical in a count and opposite in meaning, and which one
 * a pipeline is full of is the difference between paying too little and looking
 * in the wrong place.
 */
const HIRE_STAGES_CLOSED = ['hired', 'declined', 'passed'];

/** A short colour per stage, for the chips on the board. Hand-picked, no library. */
const HIRE_STAGE_COLORS = [
    'lead'       => '#8a97a3',
    'approached' => '#3f7fb3',
    'in_talks'   => '#c98a2e',
    'offer_out'  => '#7d5bbe',
    'hired'      => '#2e9e5b',
    'declined'   => '#c0504d',
    'passed'     => '#6b7280',
];

/**
 * The four seniorities in Mana's freelancer sheet, offered as suggestions.
 *
 * Suggestions, never a constraint — the column is VARCHAR. Studios differ past
 * these four and a fixed list in code is what forced a schema change on STFC
 * when Props arrived (2026-08-11).
 */
const HIRE_SENIORITY = ['Junior', 'Mid', 'Senior', 'Lead'];

/** What a person works in, as the sheet says it. Note "2D/3D", not "Mixed". */
const HIRE_PROJECT_TYPES = ['2D', '3D', '2D/3D'];

/**
 * Mana_Freelancer_DataBase.xlsx, COLUMN FOR COLUMN, including the two that are
 * not stored.
 *
 * ⚠ THE UNDERSCORED ENTRIES ARE WHY THIS LIST LOOKS ODD, AND THEY MATTER MORE
 * THAN THE REST. Somebody pasting from that sheet selects whole rows, so what
 * arrives includes Password at position 8 and Rate at position 11. Leaving them
 * out of this list would not skip them — it would shift Worked-with-us, Notes
 * and everything after them one column to the LEFT, silently, storing a
 * password as somebody's availability. Naming them and discarding them is what
 * keeps a straight paste aligned.
 *
 * The PASSWORD column is now kept, since migration 031 — it is the password an
 * artist puts on their own reel page, not a credential for anything here, and
 * that migration carries the reasoning for reversing the earlier refusal. It is
 * stored only where the column exists, so a paste still works before 031.
 *
 * `_rate` is money, which does not live in this database at all. It is REPORTED
 * when a paste contains one, rather than vanishing.
 */
const HIRE_SHEET_COLUMNS = [
    'candidate_name', 'location', 'position', 'seniority', 'project_type',
    'email', 'portfolio_url', 'portfolio_password', 'worked_with_us', 'notes',
    '_rate', 'rating',
];

/** The columns the entry grid offers, in the order the sheet reads. */
const HIRE_GRID_COLUMNS = [
    'candidate_name', 'location', 'position', 'seniority', 'project_type',
    'email', 'phone', 'portfolio_url', 'worked_with_us', 'rating', 'source', 'notes',
];

function hire_stage_label(?string $stage): string {
    // 'left' is not a stage a record can be in; it is a line in the stage log,
    // written when somebody hired into a position is taken out of it. See
    // note_left_position().
    if ($stage === 'left') return 'Left the position';
    return HIRE_STAGES[$stage ?? ''] ?? ($stage ?: 'Unknown');
}

function hire_stage_color(?string $stage): string {
    return HIRE_STAGE_COLORS[$stage ?? ''] ?? '#8a97a3';
}

/**
 * One option in a slot dropdown: "Color Script · Artie Rodriguez (filled)".
 *
 * Filled slots are LISTED, not hidden. Hiding them looked tidy and was wrong in
 * both directions: somebody hired before they were ever entered here has to be
 * recordable against the slot they already hold, and a producer looking for a
 * replacement for somebody leaving needs that slot in the list too. A slot
 * missing from a dropdown with no explanation reads as a bug, and did.
 * (David, 2026-08-17, trying to record Artie Rodriguez on Color Script.)
 */
function slot_option_label(array $lane): string {
    $label = trim((string)($lane['step_name'] ?? '')) . ' · ' . trim((string)($lane['name'] ?? ''));
    return (int)($lane['is_placeholder'] ?? 0) === 1 ? $label : $label . ' (filled)';
}

/** Has migration 024 been applied? Every screen checks before offering the feature. */
function hiring_available(PDO $pdo): bool {
    return column_exists($pdo, 'hires', 'stage');
}

/** Are the freelancer-database columns there? Migration 025, checked separately. */
function hire_extras_available(PDO $pdo): bool {
    return column_exists($pdo, 'hires', 'position');
}

/**
 * The freelancer fields, cleaned, keyed by COLUMN NAME so a caller can splice
 * them straight into an INSERT or an UPDATE without restating the order.
 */
function hire_extra_values(array $in): array {
    $rating = trim((string)($in['rating'] ?? ''));
    return [
        'location'     => trim((string)($in['location'] ?? '')) ?: null,
        'position'     => trim((string)($in['position'] ?? '')) ?: null,
        'seniority'    => trim((string)($in['seniority'] ?? '')) ?: null,
        'project_type' => trim((string)($in['project_type'] ?? '')) ?: null,
        // A checkbox that is absent from the POST means unticked, not unknown.
        'worked_with_us' => !empty($in['worked_with_us']) ? 1 : 0,
        // Clamped rather than rejected: a 7 typed into a 1-5 field is a slip,
        // and losing the whole row over it would be worse than storing a 5.
        'rating' => ($rating !== '' && is_numeric($rating))
                      ? max(1, min(5, (int)round((float)$rating))) : null,
    ];
}

/**
 * Put somebody the studio already knows up for a position.
 *
 * David, 2026-09-02: "I still don't see the whole list of candidates from the
 * artist database here." He was right, and the gap was real. Filling a position
 * has THREE cases, and the screen only offered two:
 *
 *   somebody brand new          typed  ->  save_hire()
 *   somebody already on crew    picked ->  assign_crew_to_lane()
 *   somebody in the BOOK but    ...nothing, until now
 *   not yet crew
 *
 * The third is the commonest one for a studio that has been filling the book,
 * and it is exactly what Luka Bromley was before she was hired.
 *
 * ⚠ IT CARRIES THE PERSON ACROSS, not just their name. A new row typed from
 * scratch would lose the email, the reel, the position and the rating somebody
 * already recorded, and a second row under the same name with less in it is
 * how a book decays. Only the PERSON-level fields come: the stage, the slot and
 * the project belong to this approach and start fresh, which is the same split
 * includes/artists.php makes when it writes an edit back across somebody's rows.
 */
function add_known_candidate(PDO $pdo, int $projectId, int $laneId, int $fromHireId,
                             string $who = ''): array {
    $src = get_hire($pdo, $fromHireId);
    if (!$src) throw new RuntimeException('That person is no longer in the artist database.');

    // No project check on the SOURCE, deliberately: the book is studio-wide and
    // the whole point is putting somebody from another show, or from no show at
    // all, up for this one.
    $in = [
        'candidate_name' => $src['candidate_name'],
        'email'          => $src['email'] ?? '',
        'phone'          => $src['phone'] ?? '',
        'portfolio_url'  => $src['portfolio_url'] ?? '',
        'source'         => $src['source'] ?? '',
        'lane_id'        => $laneId,
        'stage'          => HIRE_STAGE_NEW,
    ];
    if (hire_extras_available($pdo)) {
        foreach (['location', 'position', 'seniority', 'project_type', 'rating'] as $f) {
            if (isset($src[$f]) && $src[$f] !== null && $src[$f] !== '') $in[$f] = $src[$f];
        }
        if (!empty($src['worked_with_us'])) $in['worked_with_us'] = 1;
    }
    return save_hire($pdo, $projectId, $in, $who);
}

/**
 * What has already been typed on this project, for the datalists.
 *
 * Built from the data exactly as asset types and departments are — a studio's
 * real vocabulary made visible, rather than a list somebody has to maintain.
 */
function hire_suggestions(PDO $pdo, int $projectId): array {
    $out = ['position' => [], 'location' => [], 'source' => [], 'seniority' => HIRE_SENIORITY];
    if (!hiring_available($pdo)) return $out;

    $cols = ['source'];
    if (hire_extras_available($pdo)) { $cols[] = 'position'; $cols[] = 'location'; }
    foreach ($cols as $c) {
        // The column name is from this literal list, never from input.
        $q = $pdo->prepare(
            "SELECT DISTINCT `{$c}` AS v FROM hires
              WHERE project_id = ? AND `{$c}` IS NOT NULL AND `{$c}` <> '' ORDER BY v"
        );
        $q->execute([$projectId]);
        $out[$c] = $q->fetchAll(PDO::FETCH_COLUMN);
    }
    return $out;
}

/**
 * Everybody this studio has ever recorded as a candidate — ONE ROW PER PERSON,
 * across every project.
 *
 * ⚠ WHY THIS FUNCTION HAS TO EXIST, and it is worth understanding before
 * changing it. `hires` is one row per candidate PER SLOT (migration 025), so
 * the same animator approached for three jobs is three rows. That was the
 * shape chosen, and the cost it carries is exactly this: nothing in the table
 * knows that those three rows are one human. Without a deduplicated view,
 * somebody filling in the entry grid retypes a person who is already there,
 * with a slightly different spelling, and the two never meet again.
 *
 * The most RECENT row wins, on the assumption that the latest time somebody
 * typed a person's details is the best version of them. Matching is on the
 * lowercased name, which is the same rule the alias layer and the asset-type
 * merge use — deliberately not fuzzy, because a silent near-match is the thing
 * this codebase keeps refusing to do.
 */
function known_candidates(PDO $pdo): array {
    if (!hiring_available($pdo)) return [];
    return $pdo->query(
        'SELECT h.*, p.code AS project_code, p.name AS project_name
           FROM hires h
           JOIN (SELECT MAX(id) AS id FROM hires GROUP BY LOWER(candidate_name)) latest
                ON latest.id = h.id
           LEFT JOIN projects p ON p.id = h.project_id
          ORDER BY h.candidate_name'
    )->fetchAll();
}

/**
 * Is this person already a candidate for this same job?
 *
 * Same project AND the same slot AND (the same name OR the same email). The
 * lane comparison uses `<=>`, MySQL's null-safe equality, so two records with
 * no slot count as the same job rather than as two different NULLs — the same
 * trap `item_key` exists for (2026-08-11).
 *
 * The same person on the same project for a DIFFERENT slot is deliberately not
 * a duplicate: putting somebody forward for both Layout and Color is a real
 * thing to do, and it is two conversations.
 *
 * @return array|null the existing record, with its slot named, or null
 */
function hire_duplicate(PDO $pdo, int $projectId, string $name, ?string $email,
                        ?int $laneId, int $excludeId = 0): ?array {
    if (!hiring_available($pdo)) return null;
    $name = trim($name);
    if ($name === '') return null;
    $email = trim((string)$email);

    $q = $pdo->prepare(
        'SELECT h.*, cl.lane_no, c.name AS slot_name, ps.name AS slot_step
           FROM hires h
           LEFT JOIN crew_lanes cl     ON cl.id = h.lane_id
           LEFT JOIN crew c            ON c.id  = cl.crew_id
           LEFT JOIN pipeline_steps ps ON ps.id = cl.step_id
          WHERE h.project_id = ?
            AND (LOWER(h.candidate_name) = LOWER(?) OR (? <> \'\' AND h.email = ?))
            AND h.lane_id <=> ?
            AND h.id <> ?
          LIMIT 1'
    );
    $q->execute([$projectId, $name, $email, $email, $laneId, $excludeId]);
    return $q->fetch() ?: null;
}

/** "already up for Animation 02, at offer out" — for saying why a row was skipped. */
function hire_duplicate_phrase(array $dup): string {
    $where = !empty($dup['slot_name'])
        ? 'up for ' . $dup['slot_name']
        : 'on this project with no slot';
    return $where . ', at ' . strtolower(hire_stage_label($dup['stage']));
}

/** Are the engagement date columns there? Same migration, checked separately. */
function engagement_dates_available(PDO $pdo): bool {
    return column_exists($pdo, 'crew_projects', 'start_date');
}

/* -------------------------------------------------------------------------
 * Reading
 * ---------------------------------------------------------------------- */

/**
 * Every hiring record on a project, newest activity first within each stage.
 *
 * Joins out to the lane so the screen can say which slot somebody is for, but
 * falls back to the COPIED step_name when the lane has since been removed —
 * the record outlives the slot on purpose (migration 024).
 */
function project_hires(PDO $pdo, int $projectId): array {
    if (!hiring_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT h.*,
                cl.lane_no, cl.step_id AS lane_step_id, cl.crew_id AS lane_crew_id,
                ps.name AS live_step_name, ps.color AS step_color,
                ps.applies_to, ps.applies_to_asset_type,
                c.name AS slot_name, c.is_placeholder
           FROM hires h
           LEFT JOIN crew_lanes cl     ON cl.id = h.lane_id
           LEFT JOIN pipeline_steps ps ON ps.id = COALESCE(cl.step_id, h.step_id)
           LEFT JOIN crew c            ON c.id = cl.crew_id
          WHERE h.project_id = ?
          ORDER BY ps.step_order IS NULL, ps.step_order, cl.lane_no,
                   h.stage_changed_at DESC, h.id DESC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/**
 * Was this person hired into a position they no longer hold?
 *
 * David, 2026-09-18: "It happens that they leave the project and I need to see who
 * I talked to and what other options I have." Somebody taken out of a position,
 * or replaced in it, keeps a record reading "hired", which on its own says they
 * are still there.
 *
 * DERIVED, NOT STORED: the record says who was hired, the position says who holds
 * it now, and when the two differ the person has left it. Nothing has to be kept
 * in step. A record with no person linked (older ones) is never called left,
 * because there is nothing to compare.
 */
function hire_has_left(array $h): bool {
    return ($h['stage'] ?? '') === 'hired'
        && !empty($h['lane_id'])
        && (int)($h['crew_id'] ?? 0) > 0
        && (int)$h['crew_id'] !== (int)($h['lane_crew_id'] ?? 0);
}

/** When each of these records last left its position, from the stage log: id => datetime. */
function hire_left_dates(PDO $pdo, array $hireIds): array {
    $hireIds = array_values(array_filter(array_map('intval', $hireIds)));
    if (!$hireIds || !column_exists($pdo, 'hire_stage_log', 'to_stage')) return [];
    $in = implode(',', array_fill(0, count($hireIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT hire_id, MAX(changed_at) AS at FROM hire_stage_log
          WHERE to_stage = 'left' AND hire_id IN ($in) GROUP BY hire_id"
    );
    $stmt->execute($hireIds);
    $out = [];
    foreach ($stmt as $r) $out[(int)$r['hire_id']] = (string)$r['at'];
    return $out;
}

/** Who holds a position right now: its crew id, or 0 when it is not on this project. */
function lane_crew_id(PDO $pdo, int $projectId, int $laneId): int {
    $stmt = $pdo->prepare('SELECT crew_id FROM crew_lanes WHERE id = ? AND project_id = ?');
    $stmt->execute([$laneId, $projectId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Record that the person hired into a position has left it.
 *
 * Called once a position has been emptied or given to somebody else. Their
 * record keeps reading "hired", because that is what happened; this adds the line
 * to its history saying when it ended, which is the date the Staffing screen
 * shows beside their name.
 */
function note_left_position(PDO $pdo, int $projectId, int $laneId, int $crewId, string $who = ''): void {
    if ($crewId <= 0 || !hiring_available($pdo)) return;
    $stmt = $pdo->prepare(
        "SELECT id, candidate_name FROM hires
          WHERE project_id = ? AND lane_id = ? AND crew_id = ? AND stage = 'hired'"
    );
    $stmt->execute([$projectId, $laneId, $crewId]);
    foreach ($stmt->fetchAll() as $h) {
        log_hire_stage($pdo, (int)$h['id'], (string)$h['candidate_name'], 'hired', 'left',
                       'Taken out of the position', $who);
    }
}

/** Hiring records grouped by the lane they are for. Key 0 holds the unattached ones. */
function hires_by_lane(PDO $pdo, int $projectId): array {
    $out = [];
    foreach (project_hires($pdo, $projectId) as $h) {
        $out[(int)($h['lane_id'] ?? 0)][] = $h;
    }
    return $out;
}

/** One hiring record, or null. */
function get_hire(PDO $pdo, int $hireId): ?array {
    if (!hiring_available($pdo)) return null;
    $stmt = $pdo->prepare(
        'SELECT h.*, cl.lane_no, cl.crew_id AS lane_crew_id, cl.step_id AS lane_step_id
           FROM hires h
           LEFT JOIN crew_lanes cl ON cl.id = h.lane_id
          WHERE h.id = ?'
    );
    $stmt->execute([$hireId]);
    return $stmt->fetch() ?: null;
}

/** The stage history of one record, newest first. */
function hire_stage_history(PDO $pdo, int $hireId): array {
    if (!column_exists($pdo, 'hire_stage_log', 'to_stage')) return [];
    $stmt = $pdo->prepare(
        'SELECT * FROM hire_stage_log WHERE hire_id = ? ORDER BY changed_at DESC, id DESC'
    );
    $stmt->execute([$hireId]);
    return $stmt->fetchAll();
}

/**
 * How the pipeline stands: a count per stage, plus how many slots are still
 * unfilled and how many of those nobody has even started looking for.
 *
 * That last figure is the one worth having. An unfilled slot with three
 * candidates in play is a normal Tuesday; an unfilled slot with nobody against
 * it three weeks before the department starts is the thing that sinks a
 * schedule, and nothing in the platform could see it before.
 */
function hiring_summary(PDO $pdo, int $projectId): array {
    $out = [
        'by_stage'      => array_fill_keys(array_keys(HIRE_STAGES), 0),
        'open'          => 0,
        'slots_total'   => 0,
        'slots_unfilled' => 0,
        'slots_untouched' => 0,
        'untouched'     => [],
    ];
    if (!hiring_available($pdo)) return $out;

    foreach (project_hires($pdo, $projectId) as $h) {
        $s = $h['stage'];
        $out['by_stage'][$s] = ($out['by_stage'][$s] ?? 0) + 1;
        if (in_array($s, HIRE_STAGES_OPEN, true)) $out['open']++;
    }

    $lanes  = project_lanes($pdo, $projectId);
    $byLane = hires_by_lane($pdo, $projectId);
    foreach ($lanes as $l) {
        $out['slots_total']++;
        if ((int)$l['is_placeholder'] !== 1) continue;
        $out['slots_unfilled']++;

        // "Untouched" means no candidate is still in play for it. A slot whose
        // only two candidates both declined is untouched again, which is
        // exactly right — it needs somebody looking at it.
        $live = 0;
        foreach ($byLane[(int)$l['id']] ?? [] as $h) {
            if (in_array($h['stage'], HIRE_STAGES_OPEN, true)) $live++;
        }
        if ($live === 0) {
            $out['slots_untouched']++;
            $out['untouched'][] = $l;
        }
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Writing
 * ---------------------------------------------------------------------- */

/** http/https only, for a link somebody will click from a page that trusts them. */
function hiring_check_url(string $url, string $what): ?string {
    $url = trim($url);
    if ($url === '') return null;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('The ' . $what . ' link must start with http:// or https://, nothing was saved.');
    }
    if (mb_strlen($url) > 500) throw new RuntimeException('That link is too long to store.');
    return $url;
}

/**
 * Create or update a hiring record. Returns [hire id, sentence].
 *
 * The step is copied from the lane on every save rather than only on create,
 * so a record written before a step was renamed still reads correctly. It is a
 * historical field, and historical fields are kept true at the moment they are
 * written (DECISIONS, 2026-08-14).
 */
function save_hire(PDO $pdo, int $projectId, array $in, string $who = ''): array {
    if (!hiring_available($pdo)) {
        throw new RuntimeException(
            'The database updates this screen needs have not been applied yet, so nothing was '
            . 'saved. An administrator can apply them from the Migrations screen.'
        );
    }

    $name = trim((string)($in['candidate_name'] ?? ''));
    if ($name === '') throw new RuntimeException('A candidate needs a name, nothing was saved.');
    if (mb_strlen($name) > 120) throw new RuntimeException('That name is too long to store.');

    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('"' . $email . '" is not an email address, nothing was saved.');
    }

    $portfolio = hiring_check_url((string)($in['portfolio_url'] ?? ''), 'portfolio');

    // The lane is verified to belong to THIS project. Without that check a
    // posted lane id could attach a candidate to another show's slot, which
    // would then appear on a screen nobody expected it on.
    $laneId = (int)($in['lane_id'] ?? 0) ?: null;
    $stepId = null; $stepName = null;
    if ($laneId !== null) {
        $q = $pdo->prepare(
            'SELECT cl.step_id, ps.name FROM crew_lanes cl
               JOIN pipeline_steps ps ON ps.id = cl.step_id
              WHERE cl.id = ? AND cl.project_id = ?'
        );
        $q->execute([$laneId, $projectId]);
        $lane = $q->fetch();
        if (!$lane) throw new RuntimeException('That slot is not on this project.');
        $stepId   = (int)$lane['step_id'];
        $stepName = $lane['name'];
    }

    $stage = in_array($in['stage'] ?? '', array_keys(HIRE_STAGES), true)
           ? $in['stage'] : HIRE_STAGE_NEW;
    $id    = (int)($in['hire_id'] ?? 0);

    // The same person, for the same job, twice. Refused rather than merged:
    // this codebase detects near-duplicates and never resolves them silently
    // (asset types, 2026-08-13), and a second record would split one person's
    // history down the middle with nothing on screen to say why.
    $dup = hire_duplicate($pdo, $projectId, $name, $email, $laneId, $id);
    if ($dup !== null) {
        throw new RuntimeException(sprintf(
            '"%s" is already %s. Open that record and update it rather than adding a second one.',
            $dup['candidate_name'], hire_duplicate_phrase($dup)
        ));
    }

    // Built as name => value rather than as two parallel lists, so the extra
    // freelancer columns can be spliced in without anybody having to keep a
    // column order and a placeholder count in step. Getting those two out of
    // step is silent and stores every field one position to the left.
    $set = [
        'lane_id'        => $laneId,
        'step_id'        => $stepId,
        'step_name'      => $stepName,
        'candidate_name' => $name,
        'email'          => $email !== '' ? $email : null,
        'phone'          => trim((string)($in['phone'] ?? '')) ?: null,
        'portfolio_url'  => $portfolio,
        'source'         => trim((string)($in['source'] ?? '')) ?: null,
        'notes'          => trim((string)($in['notes'] ?? '')) ?: null,
    ];
    // Only when migration 025 has run. Until then the screen does not offer the
    // fields and this quietly stores the nine it has, rather than fataling on a
    // column that is not there.
    if (hire_extras_available($pdo)) {
        $set = array_merge($set, hire_extra_values($in));
    }
    // 031, probed on its own: a database with 025 and not 031 keeps working.
    if (column_exists($pdo, 'hires', 'portfolio_password')) {
        $set['portfolio_password'] =
            mb_substr(trim((string)($in['portfolio_password'] ?? '')), 0, 120) ?: null;
    }

    $cols = array_keys($set);
    $vals = array_values($set);

    if ($id > 0) {
        $existing = get_hire($pdo, $id);
        if (!$existing || (int)$existing['project_id'] !== $projectId) {
            throw new RuntimeException('That hiring record is not on this project.');
        }
        // The stage is NOT written here. It moves through set_hire_stage(), which
        // is the only thing that writes the log — a second path that changed a
        // stage without logging it would make the history quietly incomplete.
        $assign = implode(', ', array_map(function ($c) { return $c . ' = ?'; }, $cols));
        $pdo->prepare("UPDATE hires SET {$assign} WHERE id = ?")
            ->execute(array_merge($vals, [$id]));
        return [$id, 'Saved "' . $name . '".'];
    }

    $pdo->prepare(
        'INSERT INTO hires (project_id, ' . implode(', ', $cols) . ', stage, stage_changed_at, created_by)
         VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, NOW(), ?)'
    )->execute(array_merge([$projectId], $vals, [$stage, $who]));
    $id = (int)$pdo->lastInsertId();

    log_hire_stage($pdo, $id, $name, null, $stage, 'Added', $who);

    return [$id, 'Added "' . $name . '" at ' . strtolower(hire_stage_label($stage)) . '.'];
}

/** Append one line to the stage history. Never updates; there is nothing to update. */
function log_hire_stage(PDO $pdo, int $hireId, string $name, ?string $from, string $to,
                        ?string $note, string $who): void {
    if (!column_exists($pdo, 'hire_stage_log', 'to_stage')) return;
    $pdo->prepare(
        'INSERT INTO hire_stage_log (hire_id, candidate_name, from_stage, to_stage, note, changed_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$hireId, $name, $from, $to, $note !== '' ? $note : null, $who]);
}

/**
 * Move a candidate to a new stage.
 *
 * `hired` is not handled here — it is a different operation with real
 * consequences for the plan, so it has its own function and its own
 * confirmation. Everything else is a note in a log.
 *
 * @return string a sentence for the flash message
 */
function set_hire_stage(PDO $pdo, int $projectId, int $hireId, string $stage,
                        string $note = '', string $who = ''): string {
    if (!array_key_exists($stage, HIRE_STAGES)) {
        throw new RuntimeException('"' . $stage . '" is not a hiring stage.');
    }
    // "Hired" is offered in the stage list, because somebody is often entered
    // into the platform AFTER they were taken on — David, 2026-08-17. But it
    // does not merely relabel the record: it runs the real hire, so the slot
    // gets their name and the work planned for it becomes theirs. A record
    // reading "hired" beside a slot still showing a placeholder would be the
    // worst of both, and nothing on either screen would explain the mismatch.
    if ($stage === 'hired') {
        return implode(' ', hire_candidate($pdo, $projectId, $hireId, [
            'note' => $note,
        ], $who));
    }

    $hire = get_hire($pdo, $hireId);
    if (!$hire || (int)$hire['project_id'] !== $projectId) {
        throw new RuntimeException('That hiring record is not on this project.');
    }
    // ⚠ A HIRED RECORD ONLY MOVES ONCE ITS PERSON HAS LEFT THE POSITION. While they
    // hold it, moving the record would not move the work, so it is refused. Once
    // they have been taken out or replaced (hire_has_left()), the record is a
    // conversation again, and lining them up once more is how somebody coming
    // back, or a second attempt, is recorded (David, 2026-09-18).
    if ($hire['stage'] === 'hired' && !hire_has_left($hire)) {
        throw new RuntimeException(
            '"' . $hire['candidate_name'] . '" has already been hired. Change who is in the slot '
            . 'on the Staffing screen instead, moving the hiring record would not move the work.'
        );
    }
    if ($hire['stage'] === $stage) {
        return '"' . $hire['candidate_name'] . '" is already at ' . strtolower(hire_stage_label($stage)) . '.';
    }

    $pdo->prepare('UPDATE hires SET stage = ?, stage_changed_at = NOW() WHERE id = ?')
        ->execute([$stage, $hireId]);
    log_hire_stage($pdo, $hireId, (string)$hire['candidate_name'], $hire['stage'], $stage, $note, $who);

    return sprintf('"%s" moved from %s to %s.',
        $hire['candidate_name'], strtolower(hire_stage_label($hire['stage'])),
        strtolower(hire_stage_label($stage)));
}

/**
 * Hire somebody: the hand-off from a conversation to a person on the plan.
 *
 * THIS IS THE POINT OF THE WHOLE PHASE, so what it does and does not do matters.
 *
 * ⚠ IT LINKS A PERSON TO A POSITION. It does not turn the position INTO the
 * person, which is what it used to do (David, 2026-09-02).
 *
 * The old way called promote_placeholder(), renaming the slot's own crew row
 * into the candidate. That was cheap - the slot's id was already on every task
 * the scheduler had planned, so the work became theirs at a stroke - and it was
 * wrong in the way that matters: a person hired into three slots became three
 * people, because nothing checked whether the name already existed. Every
 * duplicate in the studio list came from it.
 *
 * Now the person is found or created ONCE, by name, and the slot is RE-POINTED
 * at them through assign_crew_to_lane(), which moves the scheduled work across.
 * A slot is a position and a person is a person; filling one is a link between
 * them. Hiring the same artist into three slots now points three slots at one
 * record, which is what it always meant.
 *
 * Without a slot: the same find-or-create, for somebody taken on before it is
 * settled which job they will do. They go into a slot later on the Crew screen.
 *
 * ⚠ ONE CONSEQUENCE WORTH KNOWING. An unfilled slot carries its own day rate,
 * keyed in the finance database on the placeholder's crew id - that is what
 * prices a position nobody holds yet, and what the Historic fees screen calls a
 * slot rate. Renaming used to carry that rate onto the person by accident,
 * because the id never changed. Re-pointing does not: the person is priced at
 * THEIR rate, and if they have none the step shows unpriced days until one is
 * set. That is the honest answer - you do not know what they cost yet - and the
 * caller says so rather than leaving it to be discovered on the projection.
 *
 * @return string[] sentences for the flash messages — one per thing that happened
 */
function hire_candidate(PDO $pdo, int $projectId, int $hireId, array $in, string $who = ''): array {
    $hire = get_hire($pdo, $hireId);
    if (!$hire || (int)$hire['project_id'] !== $projectId) {
        throw new RuntimeException('That hiring record is not on this project.');
    }
    if ($hire['stage'] === 'hired') {
        throw new RuntimeException('"' . $hire['candidate_name'] . '" is already hired.');
    }

    $name  = trim((string)($in['real_name'] ?? '')) ?: (string)$hire['candidate_name'];
    $email = trim((string)($in['email'] ?? '')) ?: (string)($hire['email'] ?? '');
    $out   = [];
    $crewId = null;

    $pdo->beginTransaction();
    try {
        // ---- THE PERSON, once ------------------------------------------
        // An exact name match reuses the existing record rather than making a
        // second copy — the same rule the alias layer, link_login_to_crew() and
        // the artist book all follow. Deliberately not fuzzy.
        $find = $pdo->prepare('SELECT id, name FROM crew WHERE name = ? AND is_placeholder = 0 LIMIT 1');
        $find->execute([$name]);
        $found = $find->fetch();
        if ($found) {
            $crewId = (int)$found['id'];
            $out[] = '"' . $name . '" was already on the studio crew list, so they were linked to '
                   . 'that person rather than a second copy being made.';
        } else {
            $pdo->prepare(
                'INSERT INTO crew (name, role, email, is_team, is_placeholder, active)
                 VALUES (?, ?, ?, 0, 0, 1)'
            )->execute([$name, $hire['step_name'] ?: null, $email !== '' ? $email : null]);
            $crewId = (int)$pdo->lastInsertId();
            $out[] = 'Added "' . $name . '" to the studio crew list.';
        }
        $pdo->prepare('INSERT IGNORE INTO crew_projects (crew_id, project_id) VALUES (?, ?)')
            ->execute([$crewId, $projectId]);

        // ---- THE POSITION, linked to them ------------------------------
        if (!empty($hire['lane_id'])) {
            $occupantId = (int)($hire['lane_crew_id'] ?? 0);
            $occupant = null;
            if ($occupantId > 0) {
                $slot = $pdo->prepare('SELECT id, name, is_placeholder FROM crew WHERE id = ?');
                $slot->execute([$occupantId]);
                $occupant = $slot->fetch() ?: null;
            }

            if ($occupant && $occupantId === $crewId) {
                // Already theirs. A hiring record written after the fact, for
                // somebody put into the slot on the Crew screen weeks ago.
                $out[] = sprintf('%s already holds that slot, so nothing moved, the hiring record '
                               . 'now points at them and the slot is unchanged.', $name);

            } elseif ($occupant && (int)$occupant['is_placeholder'] === 0) {
                // A DIFFERENT real person is in this slot. Refusing is right:
                // silently replacing them would move their planned work onto
                // somebody else without anyone asking for it.
                throw new RuntimeException(sprintf(
                    'That slot already holds %s, so it was not overwritten. If %s is replacing '
                    . 'them, change who is in the slot on the Staffing screen first, that is the '
                    . 'screen that moves the planned work with it.',
                    $occupant['name'], $name));

            } else {
                // The slot is empty, so point it at them. This moves every
                // scheduled task on that track across; see assign_crew_to_lane().
                assign_crew_to_lane($pdo, $projectId, (int)$hire['lane_id'], $crewId);
                $out[] = sprintf('The slot "%s" is now %s, and every task already scheduled on '
                               . 'that track is theirs.',
                                 $occupant['name'] ?? 'that position', $name);

                // ⚠ THE SLOT'S OWN RATE DOES NOT COME WITH THEM. It is keyed on
                // the placeholder's crew id in the finance database, and it was
                // a budgeting assumption about the position, not a fact about
                // this person. Said here rather than left to be noticed as a
                // number changing on the projection.
                if ($occupant) {
                    $slotMoney = crew_money_footprint($occupantId);
                    $theirs    = crew_money_footprint($crewId);
                    if ($slotMoney['reachable'] && $theirs['reachable']
                        && $slotMoney['terms'] > 0 && $theirs['terms'] === 0) {
                        $out[] = sprintf('That slot had a day rate of its own, which was an '
                                       . 'estimate for the position rather than a fact about %s. '
                                       . 'It has not been carried over, so this step will show '
                                       . 'unpriced days until their rate is set under Finance '
                                       . 'management.', $name);
                    }
                }
            }
        }

        $pdo->prepare('UPDATE hires SET stage = ?, stage_changed_at = NOW(), crew_id = ?, candidate_name = ? WHERE id = ?')
            ->execute(['hired', $crewId, $name, $hireId]);
        log_hire_stage($pdo, $hireId, $name, $hire['stage'], 'hired',
                       trim((string)($in['note'] ?? '')) ?: null, $who);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Everything below is outside the transaction on purpose: it writes to the
    // OTHER database. A single transaction cannot span two connections, so the
    // production side is committed first and the finance side reports its own
    // outcome rather than being able to roll the hire back.
    if (!empty($in['start_date']) || !empty($in['end_date'])) {
        try {
            $out[] = set_engagement_dates($pdo, $crewId, $projectId,
                                          $in['start_date'] ?? '', $in['end_date'] ?? '');
        } catch (Throwable $e) {
            $out[] = 'Hired, but the dates were not saved: ' . $e->getMessage();
        }
    }

    try {
        $said = seed_terms_from_quote($hireId, $crewId, $name, $who);
        if ($said !== null) $out[] = $said;
    } catch (Throwable $e) {
        // A finance database that is unreachable must not make a hire look as
        // though it failed — the hire is already committed. Report and move on.
        $out[] = 'Hired. The quoted rate could not be carried across: ' . $e->getMessage();
    }

    // The administrators hear about every hire, whoever pressed the button
    // (David, 2026-10-21). After the commit, because SMTP is slow and a mail
    // failure must never undo a hire. Somebody who cannot see rates (a
    // supervisor, Production) never reaches seed_terms_from_quote(), so the
    // email says the rate is still to be checked.
    $proj = $pdo->prepare('SELECT code, name FROM projects WHERE id = ?');
    $proj->execute([$projectId]);
    $p = $proj->fetch() ?: ['code' => '', 'name' => ''];
    [, $said] = notify_admins_of_hire($pdo, [
        'project_id'   => $projectId,
        'project_code' => (string)$p['code'],
        'project_name' => (string)$p['name'],
        'candidate'    => $name,
        'slot'         => (string)($occupant['name'] ?? ''),
        'step'         => (string)($hire['step_name'] ?? ''),
        'by'           => $who !== '' ? $who : 'Somebody',
        'rate_note'    => can_see_money() ? ''
            : ($who !== '' ? $who : 'Whoever hired them') . ' cannot see rates, so any rate quoted for ' . $name . ' was not carried onto '
              . 'their terms. Check it under Finance management.',
    ]);
    if ($said !== '') $out[] = $said;

    return $out;
}

/* -------------------------------------------------------------------------
 * Engagement dates — when somebody is actually on the show
 * ---------------------------------------------------------------------- */

/** One person's engagement on one project, or null. */
function engagement(PDO $pdo, int $crewId, int $projectId): ?array {
    if (!engagement_dates_available($pdo)) return null;
    $stmt = $pdo->prepare(
        'SELECT start_date, end_date, allocation_pct FROM crew_projects
          WHERE crew_id = ? AND project_id = ?'
    );
    $stmt->execute([$crewId, $projectId]);
    return $stmt->fetch() ?: null;
}

/**
 * Set when somebody starts and finishes on a project.
 *
 * Creates the crew_projects row if it is missing: somebody can be scheduled
 * through a lane without ever having been added to the project explicitly, and
 * refusing to record their dates because of a bookkeeping row nobody knew about
 * would be a silent no-op.
 */
function set_engagement_dates(PDO $pdo, int $crewId, int $projectId, ?string $start, ?string $end): string {
    if (!engagement_dates_available($pdo)) {
        throw new RuntimeException(
            'The database updates for engagement dates have not been applied yet.'
        );
    }
    $clean = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new RuntimeException('"' . $v . '" is not a date in YYYY-MM-DD form.');
        }
        return $v;
    };
    $s = $clean($start);
    $e = $clean($end);
    if ($s !== null && $e !== null && $e < $s) {
        throw new RuntimeException('The end date is before the start date, nothing was saved.');
    }

    $pdo->prepare(
        'INSERT INTO crew_projects (crew_id, project_id, start_date, end_date)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE start_date = VALUES(start_date), end_date = VALUES(end_date)'
    )->execute([$crewId, $projectId, $s, $e]);

    if ($s === null && $e === null) return 'Engagement dates cleared.';
    if ($s !== null && $e !== null) return 'On the show from ' . $s . ' to ' . $e . '.';
    return $s !== null ? 'Starts ' . $s . '.' : 'Finishes ' . $e . '.';
}

/**
 * Does the plan ask for work outside somebody's engagement?
 *
 * ⚠ WHY THIS EXISTS AT ALL. The scheduler does not read engagement dates yet —
 * making a start date move a bar changes every date on every project the moment
 * somebody types one, and that belongs with crew_absences as its own phase
 * (ROADMAP). But a column that looks like it is being obeyed and is not is the
 * exact failure this codebase keeps paying for: a feature that quietly does
 * nothing is indistinguishable from one that was never built (lesson 11).
 *
 * So the gap is REPORTED instead of being closed. The person page says "the plan
 * has 6 tasks for them before they start", which is a true and useful sentence,
 * rather than letting the dates sit there implying a constraint nobody applied.
 *
 * Returns null when there is nothing to say — no dates, or no scheduled work.
 */
function engagement_conflicts(PDO $pdo, int $crewId, int $projectId): ?array {
    $eng = engagement($pdo, $crewId, $projectId);
    if (!$eng || ($eng['start_date'] === null && $eng['end_date'] === null)) return null;

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n, MIN(start_date) AS first_start, MAX(end_date) AS last_end
           FROM schedule WHERE project_id = ? AND crew_id = ?'
    );
    $stmt->execute([$projectId, $crewId]);
    $all = $stmt->fetch();
    if (!$all || (int)$all['n'] === 0) return null;

    $out = [
        'start'        => $eng['start_date'],
        'end'          => $eng['end_date'],
        'first_start'  => $all['first_start'],
        'last_end'     => $all['last_end'],
        'before'       => 0,
        'after'        => 0,
    ];

    if ($eng['start_date'] !== null) {
        // A task counts as "before they start" when it BEGINS before that date.
        // Comparing the end instead would let a task quietly straddle the
        // boundary and report as fine.
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM schedule WHERE project_id = ? AND crew_id = ? AND start_date < ?'
        );
        $q->execute([$projectId, $crewId, $eng['start_date']]);
        $out['before'] = (int)$q->fetchColumn();
    }
    if ($eng['end_date'] !== null) {
        // end_date is INCLUSIVE everywhere in this codebase (DECISIONS,
        // 2026-08-11), so work ending exactly on the last day is fine and only
        // a later end is over.
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM schedule WHERE project_id = ? AND crew_id = ? AND end_date > ?'
        );
        $q->execute([$projectId, $crewId, $eng['end_date']]);
        $out['after'] = (int)$q->fetchColumn();
    }

    return ($out['before'] === 0 && $out['after'] === 0) ? null : $out;
}

/**
 * Everybody on a project with an engagement window, for the hiring screen's
 * roster. Real people only — a slot has nobody to have agreed dates with.
 */
function project_engagements(PDO $pdo, int $projectId): array {
    if (!engagement_dates_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.role, c.email, c.active,
                cp.start_date, cp.end_date, cp.allocation_pct
           FROM crew_projects cp
           JOIN crew c ON c.id = cp.crew_id
          WHERE cp.project_id = ? AND c.is_placeholder = 0 AND c.is_team = 0
          ORDER BY cp.start_date IS NULL, cp.start_date, c.name'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

/* -------------------------------------------------------------------------
 * Adding several at once — the grid and the spreadsheet paste
 * ---------------------------------------------------------------------- */

/**
 * Read one pasted line into the fields of a hiring record.
 *
 * TABS ARE SPLIT ONE AT A TIME, NOT COLLAPSED. The wizard's paste helpers use
 * `/[,;\t]+/`, which is right for two or three fields and wrong here: Excel
 * emits an empty cell as two adjacent tabs, and the `+` would swallow the pair
 * and shift every later column left. A twelve-column paste in which Location
 * happens to be blank would then store the position as the location, quietly.
 *
 * A line with no tab in it is treated as hand-typed and split on commas —
 * again singly, for the same reason.
 *
 * @return array|null  field => value, or null for a line with no name in it
 */
function parse_hire_paste_line(string $line): ?array {
    $line = rtrim($line, "\r\n");
    if (trim($line) === '') return null;

    $parts = strpos($line, "\t") !== false
        ? preg_split('/\t/', $line)
        : preg_split('/[,;]/', $line);

    $row = [];
    foreach (HIRE_SHEET_COLUMNS as $i => $col) {
        $row[$col] = trim((string)($parts[$i] ?? ''));
    }
    if ($row['candidate_name'] === '') return null;

    // The sheet uses 1 and 0, but anybody retyping it writes yes. Anything
    // unrecognised, blank included, reads as no.
    $w = mb_strtolower($row['worked_with_us']);
    $row['worked_with_us'] = in_array($w, ['1', 'y', 'yes', 'true'], true) ? 1 : 0;

    return $row;
}

/**
 * Turn a pasted block into rows, reporting what it decided rather than deciding
 * quietly.
 *
 * @return array ['rows' => array, 'skipped' => int, 'header' => bool,
 *                'passwords' => int, 'rates' => array]
 */
function parse_hire_paste(string $text): array {
    $out = ['rows' => [], 'skipped' => 0, 'header' => false, 'passwords' => 0, 'rates' => []];

    foreach (preg_split('/\R/', $text) as $n => $line) {
        // Pasting the header row along with the data is the obvious mistake,
        // and it would otherwise create a candidate called "Artist".
        if ($n === 0 && preg_match('/^\s*artist\b/i', $line)) {
            $out['header'] = true;
            continue;
        }
        $row = parse_hire_paste_line($line);
        if ($row === null) {
            if (trim($line) !== '') $out['skipped']++;
            continue;
        }
        if (($row['portfolio_password'] ?? '') !== '') $out['passwords']++;
        if ($row['_rate'] !== '') {
            $out['rates'][] = $row['candidate_name'] . ' (' . $row['_rate'] . ')';
        }
        unset($row['_rate']);
        $out['rows'][] = $row;
    }
    return $out;
}

/**
 * Save many candidates in one go, from the grid or from a paste.
 *
 * One row failing never stops the rest — a batch of twelve whose fourth has a
 * malformed email should land eleven and say which one it could not, rather
 * than refusing the lot. That is the rule the Plaky importer follows, and the
 * one the Finance hub learned after a single project's projection took the
 * whole page down.
 *
 * Rows whose stage is `hired` go through the real hire, so the slot they name
 * gets their name and its planned work becomes theirs. If that part fails —
 * the slot is already taken — the RECORD is still kept, at the stage before
 * hired, and the reason is reported. Losing a typed row over it would be worse.
 *
 * A row naming somebody already up for the same job is counted as a DUPLICATE
 * rather than an error. Pasting a block that overlaps one already entered is an
 * ordinary thing to do, and forty red lines saying so would bury the rows that
 * did land.
 *
 * @param array $rows  each a field => value map
 * @return array ['added' => int, 'hired' => int, 'errors' => array,
 *                'notes' => array, 'duplicates' => array]
 */
function save_hires_bulk(PDO $pdo, int $projectId, array $rows, string $who = ''): array {
    $out = ['added' => 0, 'hired' => 0, 'errors' => [], 'notes' => [], 'duplicates' => []];

    foreach ($rows as $i => $row) {
        $name = trim((string)($row['candidate_name'] ?? ''));
        if ($name === '') continue;          // a blank line in the grid is not an error

        $dup = hire_duplicate($pdo, $projectId, $name,
                              (string)($row['email'] ?? ''),
                              (int)($row['lane_id'] ?? 0) ?: null);
        if ($dup !== null) {
            $out['duplicates'][] = $dup['candidate_name'] . ' (already ' . hire_duplicate_phrase($dup) . ')';
            continue;
        }

        $stage = in_array($row['stage'] ?? '', array_keys(HIRE_STAGES), true)
               ? $row['stage'] : HIRE_STAGE_NEW;

        try {
            // Created at a non-final stage first and hired as a second step, so
            // that a failed hire leaves an honest record rather than one
            // claiming somebody is on the show when they are not.
            $row['stage'] = $stage === 'hired' ? 'offer_out' : $stage;
            list($hireId, ) = save_hire($pdo, $projectId, $row, $who);
            $out['added']++;

            if ($stage === 'hired') {
                try {
                    foreach (hire_candidate($pdo, $projectId, $hireId, $row, $who) as $said) {
                        $out['notes'][] = $said;
                    }
                    $out['hired']++;
                } catch (Throwable $e) {
                    $out['errors'][] = sprintf(
                        '"%s" was saved, but could not be hired into that slot: %s',
                        $name, $e->getMessage()
                    );
                }
            }
        } catch (Throwable $e) {
            $out['errors'][] = sprintf('Row %d ("%s") was not saved: %s',
                                       $i + 1, $name, $e->getMessage());
        }
    }
    return $out;
}
