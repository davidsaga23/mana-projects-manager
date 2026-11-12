<?php
// The DAYS OFF tab on Bookings: one calendar for everybody's days away.
// bookings.php includes this file BEFORE its header (it saves and redirects)
// and includes/days_off/view.php where the tab is drawn.
//
// David, 2026-11-12, after a first version as its own page: "let's include it
// as a tab in the bookings page". Bookings is already where the studio looks
// at who is where; the timeline and calendar there already draw days off.
//
// David, 2026-11-12: "let's create a calendar for days off so anima can
// register those days". Days off already existed (crew_absences, migration 060,
// includes/absences.php) but were typed one person at a time on the person
// page, so the studio's month was never seen in one place. This is that place:
// a month, one row per person, a column per day. Drag across a person's days to
// add days off; click a day off to change or remove it.
//
// NOTHING NEW IS STORED. Every save goes through add_crew_absence(),
// replace_crew_absence() and delete_crew_absence(), so the scheduler, Home's
// missing-log-days panel, Bookings' stripes and the Realm streak (David,
// 2026-11-12: "the Realm streak will read from it") all read what is entered
// here without being told about this screen. crew_days_off_between() is the
// one question they should ask.
//
// WHO: Production and admins enter days off for anybody (Bookings' own Edit
// switch, bookings_edit, locked to them). Supervisors see everybody, read only,
// as on the rest of Bookings. An artist, if Bookings is ever switched on for
// one, sees ONLY THEIR OWN ROW: somebody else's sick day is not
// theirs to read. Artists asking for days off, for Production to approve, was
// offered and not chosen for the first version; it would need a state on
// crew_absences (a migration).

require_once __DIR__ . '/../absences.php';
require_once __DIR__ . '/../bookings.php';   // bookings_projects(): the shows still running
require_once __DIR__ . '/../people.php';     // crew_departments()

$mayEdit   = can_edit() && (!function_exists('feature_on') || feature_on('bookings_edit'));
$seeAll    = can_edit() || is_supervisor();
$myCrewId  = effective_crew_id($pdo);
$available = absences_available($pdo);
$hasDept   = column_exists($pdo, 'crew', 'department');

// --- which month, and which people ------------------------------------------
$month = (string)($_GET['m'] ?? $_POST['m'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$first = new DateTime($month . '-01');
$monthStart = $first->format('Y-m-d');
$monthEnd   = $first->format('Y-m-t');
$prevMonth  = (clone $first)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $first)->modify('+1 month')->format('Y-m');

// Show filter. 'any' (the default) is everybody on a show still running, which
// keeps the long tail of the Artists pool, people who have not worked here in a
// year, off the grid. 'all' is everybody in Anima, for the rare case.
$show = (string)($_GET['show'] ?? $_POST['show'] ?? 'any');
if ($show !== 'any' && $show !== 'all' && !ctype_digit($show)) $show = 'any';
$dept = (string)($_GET['dept'] ?? $_POST['dept'] ?? '');

$selfQuery = function (array $over = []) use ($month, $show, $dept): string {
    $q = array_merge(['tab' => 'days-off', 'm' => $month, 'show' => $show, 'dept' => $dept], $over);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    if (($q['show'] ?? '') === 'any') unset($q['show']);
    return 'bookings.php?' . http_build_query($q);
};

// --- saving: add, change, remove ---------------------------------------------
// Production and admins only. The guard in auth.php already refuses a POST from
// anybody who is not an editor (this screen is not on artist_may_post()'s
// list), FEATURE_POSTS refuses it when bookings_edit is off, and this is the
// third lock. Whose absence it is, is checked by delete_crew_absence() itself
// (id AND crew_id), so a forged absence id cannot remove somebody else's row.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$mayEdit) {
        flash('err', 'Only Production can enter days off. Nothing was saved.');
    } else {
        $crewId    = (int)($_POST['crew_id'] ?? 0);
        $absenceId = (int)($_POST['absence_id'] ?? 0);
        $action    = (string)($_POST['action'] ?? '');
        try {
            $stmt = $pdo->prepare('SELECT id FROM crew WHERE id = ?');
            $stmt->execute([$crewId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('That person was not found, nothing was saved.');
            }
            $from   = (string)($_POST['from_date'] ?? '');
            $to     = (string)($_POST['to_date'] ?? '');
            $reason = (string)($_POST['reason'] ?? '');
            if ($action === 'delete') {
                flash('ok', delete_crew_absence($pdo, $crewId, $absenceId));
            } elseif ($action === 'edit' && $absenceId > 0) {
                flash('ok', replace_crew_absence($pdo, $crewId, $absenceId, $from, $to, $reason, current_user_display()));
            } elseif ($action === 'add') {
                flash('ok', add_crew_absence($pdo, $crewId, $from, $to, $reason, current_user_display()));
            }
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
    }
    header('Location: ' . $selfQuery());
    exit;
}

// --- the people on the grid --------------------------------------------------
$projects = $projects ?? bookings_projects($pdo);
$projectIds = array_map(function ($p) { return (int)$p['id']; }, $projects);
if (ctype_digit($show) && !in_array((int)$show, $projectIds, true)) $show = 'any';

$people = [];
if (!$seeAll) {
    // An artist: their own row and nothing else.
    if ($myCrewId) {
        $stmt = $pdo->prepare('SELECT id, name, role' . ($hasDept ? ', department' : '') . ' FROM crew WHERE id = ?');
        $stmt->execute([$myCrewId]);
        $people = $stmt->fetchAll();
    }
} else {
    $where = ['c.is_team = 0', 'c.is_placeholder = 0'];
    $args  = [];
    if ($show === 'all') {
        $where[] = 'c.active = 1';
    } else {
        // "On a show" is either a crew_projects row or a position on the
        // show's Staffing (crew_lanes); Bookings reads the second, the
        // engagement dates the first, and somebody can be in only one of them.
        $ids = $show === 'any' ? $projectIds : [(int)$show];
        if (!$ids) $ids = [0];
        $in = implode(',', array_map('intval', $ids));   // integers, cast here
        $where[] = "(c.id IN (SELECT crew_id FROM crew_projects WHERE project_id IN ($in))
                  OR c.id IN (SELECT crew_id FROM crew_lanes WHERE project_id IN ($in)))";
    }
    if ($hasDept && $dept !== '') {
        $where[] = 'c.department = ?';
        $args[]  = $dept;
    }
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.role' . ($hasDept ? ', c.department' : '') . '
           FROM crew c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.name'
    );
    $stmt->execute($args);
    $people = $stmt->fetchAll();
}
$personIds = array_map(function ($p) { return (int)$p['id']; }, $people);

// One query for the whole month, everybody on the grid.
$absences = $personIds ? absences_between($pdo, $personIds, $monthStart, $monthEnd) : [];

// The days of the month, each with what the studio thinks of it.
$holidays = non_working_days($pdo);
$days = [];
for ($d = clone $first; $d->format('Y-m-d') <= $monthEnd; $d->modify('+1 day')) {
    $iso = $d->format('Y-m-d');
    $days[] = [
        'iso'     => $iso,
        'num'     => (int)$d->format('j'),
        'dow'     => substr($d->format('D'), 0, 2),
        'weekend' => (int)$d->format('N') >= 6,
        'holiday' => $holidays[$iso] ?? null,
        'today'   => $iso === date('Y-m-d'),
    ];
}

/** A colour family for a reason, by its first word. Anything else is grey. */
function days_off_kind(string $reason): string {
    $r = strtolower($reason);
    if (preg_match('/\b(sick|ill|doctor|medical)/', $r)) return 'sick';
    if (preg_match('/\b(holiday|vacation|leave|pto|annual)/', $r)) return 'holiday';
    if (preg_match('/\b(personal|family|moving|wedding|funeral)/', $r)) return 'personal';
    if (preg_match('/\b(course|training|conference|festival)/', $r)) return 'course';
    return 'other';
}

/** The month and filters, carried through a save so the page comes back as it was. */
function days_off_keep_fields(string $month, string $show, string $dept): string {
    return '<input type="hidden" name="tab" value="days-off">'
         . '<input type="hidden" name="m" value="' . e($month) . '">'
         . '<input type="hidden" name="show" value="' . e($show) . '">'
         . '<input type="hidden" name="dept" value="' . e($dept) . '">';
}
