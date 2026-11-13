<?php
// The Realm — mana, gold and praise (migration 088).
//
// David, 2026-11-12: "people should be able to praise with a spell or something
// people doing a good job". He chose TWO POOLS that day:
//   * MANA is only for praising others. "They add mana each continuous day you
//     log time and you don't earn if you don't log time." Working days only;
//     weekends, studio holidays and booked days off neither earn nor break the
//     run; late logging counts until noon the next day; you hold at most 5.
//   * GOLD is private ("No visible balances") and in whole coins. "If you log
//     daily your time log for 1 week you win a gold coin (no cap)", and praise
//     received gives gold too.
//
// APPROVED WORK (David, 2026-11-13): "let's keep 1 gold for each approved, and if
// you approved on time based on the task due dates you receive 1 mana and 1
// gold". So each task of yours that is approved gives 1 gold, and one approved
// on or before its due date gives 1 mana as well. Read from progress_log, the
// record every status change already writes; nothing is added to the board, the
// review queue or My tasks. See realm_purse_approvals().
//
// The rules of a praise, agreed with David on 2026-11-13:
//   * FACE TO FACE, anywhere: the two characters are in the same place, inside
//     the same room or standing at the same spot on the map. Checked HERE, from
//     `place` and `inside` in realm_people, not only on the screen.
//   * IT COSTS THE GIVER 1 MANA and lands at once: the receiver does not have to
//     accept it.
//   * THE RECEIVER ALWAYS GETS A KEEPSAKE (the praise itself, kept for good and
//     shown in their profile), and ONE GOLD COIN ONLY FOR THE FIRST PRAISE FROM
//     THE SAME PERSON IN A WEEK. That stops two friends praising each other in a
//     loop to make gold, without ever refusing a sincere second praise.
//   * FOUR KINDS: Craft, Help, Idea, Rescue. None is about speed or hours, which
//     would be read as a productivity score.
//   * IT SAYS WHAT FOR, in one line: "it could be the work, it could be just
//     kudos from a default menu" (David). The ready-made lines are on the screen
//     (assets/realm_praise.js); here a line is only checked for length.
//
// ⚠ A PURSE IS SHOWN ONLY TO ITS OWNER. realm_purse() is called with the
// session's own id and nothing else reads mana or gold for the screen. There is
// no list of who holds what and no ranking, on purpose.
//
// ⚠ NOTHING HERE TOUCHES MONEY. Gold is a game coin: it is never worked out
// from a rate, a salary or an invoice and is never turned into one. Days off are
// read through includes/absences.php, whose own includes define the money
// functions but open no connection; nothing here calls them.
//
// NOT BUILT YET, and said so on the screen where it matters: anything to spend gold on, gifting and
// swapping, the Herald, and a keepsake drawn in the office. The word for praise
// is "praise" (David, 2026-11-13: "Keep praise"): Anima's task board is already
// called Spell.
//
// My readings, to confirm with David:
//   * "1 week" is five working days in a row, counted on the run itself, so a
//     week that has a holiday in it still pays after five logged days.
//   * "noon the next day" is noon of the next WORKING day, so Friday can still
//     be logged on Monday morning.
//   * The week for the gold rule is Monday to Sunday.
//   * On time gives 1 gold and 1 mana in all, not 2 gold: REALM_GOLD_ON_TIME is
//     the one number to change if David meant a second coin.
//   * "On time" is the day of the approval against the task's scheduled end
//     date. A task with no due date gives the coin and no mana.
//   * A task pays once: approved, sent back and approved again is one coin.
//   * Statuses that arrive through an import do not pay, or loading a show's
//     history would hand out hundreds of coins in one go.
//   * Tickets are not counted: they have no approval.
//   * Somebody whose login is not linked to a person has no time log, so earns
//     no mana and cannot praise, but can be praised.

require_once __DIR__ . '/realm_focus.php';   // realm_crew_id()
require_once __DIR__ . '/realm_chat.php';    // realm_person_name()

/** The most mana anybody holds. David, 2026-11-12. */
const REALM_MANA_CAP = 5;
/** Gold for a task approved late or with no due date, and for one approved on time. */
const REALM_GOLD_APPROVED = 1;
const REALM_GOLD_ON_TIME  = 1;
/** Mana for a task approved on or before its due date. */
const REALM_MANA_ON_TIME  = 1;
/** Working days in a row that earn one gold coin. */
const REALM_GOLD_EVERY = 5;
/** The kinds of praise, id => name. assets/realm_praise.js has the same four. */
const REALM_PRAISE_KINDS = ['craft' => 'Craft', 'help' => 'Help', 'idea' => 'Idea', 'rescue' => 'Rescue'];
/** The longest line a praise may carry. */
const REALM_PRAISE_NOTE = 160;
/** How long a praise is news for everybody on the map, in seconds. */
const REALM_PRAISE_NEWS_SECONDS = 120;
/** How far back a brand-new purse looks, in days, so a first visit is not empty-handed. */
const REALM_PURSE_LOOK_BACK = 8;

/** Has migration 088 arrived? Asked once per request; never throws. */
function realm_praise_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_purse LIMIT 1');
        $pdo->query('SELECT 1 FROM realm_praise LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Bring $uid's purse up to date with their time log, then return it:
 *   ['ready', 'linked', 'mana', 'gold', 'streak', 'cap', 'every']
 *
 * Every working day since `counted_to` is looked at once, oldest first:
 *   - a weekend, a studio holiday or a booked day off is passed over;
 *   - a day with time logged before noon of the next working day adds one to
 *     the run and one mana (to the cap), and every fifth day of a run one gold;
 *   - a day whose noon has passed with nothing logged ends the run;
 *   - a day that can still be logged in time stops the count there, so it is
 *     looked at again next time.
 *
 * ⚠ ONLY EVER CALLED WITH THE SESSION'S OWN ID. Not on the heartbeat: when the
 * page opens, when the chest is opened and before a praise is given.
 * Never throws: a purse that cannot be read is an empty one.
 */
function realm_purse(PDO $pdo, int $uid): array {
    $out = ['ready' => realm_praise_available($pdo), 'linked' => false, 'mana' => 0, 'gold' => 0, 'streak' => 0,
            'cap' => REALM_MANA_CAP, 'every' => REALM_GOLD_EVERY];
    if (!$out['ready'] || $uid <= 0) return $out;
    $began = false;
    try {
        $crewId = realm_crew_id($pdo, $uid);
        $out['linked'] = $crewId > 0;
        // The database's own clock decides what "now" and "today" are, because
        // time_logs.created_at was written by it.
        $clock = $pdo->query('SELECT NOW() AS n, CURDATE() AS d')->fetch();
        $now   = (string)$clock['n'];
        $today = (string)$clock['d'];

        $pdo->prepare('INSERT IGNORE INTO realm_purse (user_id, updated_at) VALUES (?, NOW())')->execute([$uid]);
        $pdo->beginTransaction();
        $began = true;
        $q = $pdo->prepare('SELECT * FROM realm_purse WHERE user_id = ? FOR UPDATE');
        $q->execute([$uid]);
        $row = $q->fetch();
        $mana   = min(REALM_MANA_CAP, max(0, (int)$row['mana']));
        $gold   = max(0, (int)$row['gold']);
        $streak = max(0, (int)$row['streak']);
        $counted = $row['counted_to'] !== null ? (string)$row['counted_to']
                 : (new DateTime($today))->modify('-' . REALM_PURSE_LOOK_BACK . ' days')->format('Y-m-d');

        if ($crewId > 0 && $counted < $today) {
            $from = (new DateTime($counted))->modify('+1 day')->format('Y-m-d');
            // The day, and when its first line was typed.
            $q = $pdo->prepare('SELECT log_date, MIN(created_at) AS typed FROM time_logs
                                 WHERE crew_id = ? AND log_date BETWEEN ? AND ? GROUP BY log_date');
            $q->execute([$crewId, $from, $today]);
            $logged = [];
            foreach ($q->fetchAll() as $r) $logged[(string)$r['log_date']] = (string)$r['typed'];

            require_once __DIR__ . '/absences.php';
            $off = [];
            try {
                $off = crew_days_off_between($pdo, [$crewId], $from, $today)[$crewId] ?? [];
            } catch (Throwable $e) {
                // Without the days-off table a day off counts as a missed day. Rare, and not fatal.
            }
            $holidays = non_working_days($pdo);

            $d = new DateTime($from);
            for ($i = 0; $i < 400 && $d->format('Y-m-d') <= $today; $i++, $d->modify('+1 day')) {
                $day = $d->format('Y-m-d');
                if (!is_working_day($day, $holidays) || isset($off[$day])) { $counted = $day; continue; }
                $next     = (clone $d)->modify('+1 day')->format('Y-m-d');
                $deadline = next_working_day($next, $holidays) . ' 12:00:00';
                if (isset($logged[$day]) && $logged[$day] <= $deadline) {
                    $streak++;
                    $mana = min(REALM_MANA_CAP, $mana + 1);
                    if ($streak % REALM_GOLD_EVERY === 0) $gold++;
                    $counted = $day;
                } elseif ($now > $deadline) {
                    $streak  = 0;
                    $counted = $day;
                } else {
                    break;   // still time to log this day: look again later
                }
            }
        }
        $pdo->prepare('UPDATE realm_purse SET mana = ?, gold = ?, streak = ?, counted_to = ?, updated_at = NOW() WHERE user_id = ?')
            ->execute([$mana, $gold, $streak, $counted, $uid]);
        // Approved work, counted after the time log so the two never undo each other.
        if ($crewId > 0 && array_key_exists('approvals_to', $row)) {
            $paid = realm_purse_approvals($pdo, $crewId, $row['approvals_to'] !== null ? (int)$row['approvals_to'] : null, $today);
            if ($paid !== null) {
                $gold += $paid['gold'];
                $mana  = min(REALM_MANA_CAP, $mana + $paid['mana']);
                $pdo->prepare('UPDATE realm_purse SET mana = ?, gold = ?, approvals_to = ? WHERE user_id = ?')
                    ->execute([$mana, $gold, $paid['to'], $uid]);
            }
        }
        $pdo->commit();
        $out['mana'] = $mana; $out['gold'] = $gold; $out['streak'] = $streak;
    } catch (Throwable $e) {
        if ($began && $pdo->inTransaction()) $pdo->rollBack();
    }
    return $out;
}

/**
 * What $crewId has earned from approved work since the line `$after` of
 * progress_log: ['gold', 'mana', 'to' => the last line looked at], or null when
 * it could not be read, in which case nothing is paid and nothing is marked as
 * counted.
 *
 * A line counts when it moved a task into an approved status, the task is
 * assigned to this person (as its one assignee or as one of several), it did not
 * come from an import, and the same task has no earlier approval: a task pays
 * once. On time means the approval's day is on or before the task's scheduled
 * end date.
 *
 * With `$after` null (a purse that has never counted approvals) it looks back
 * REALM_PURSE_LOOK_BACK days, like the time log does.
 *
 * ⚠ READ ONLY. The mana here is added by the caller, which holds the cap.
 */
function realm_purse_approvals(PDO $pdo, int $crewId, ?int $after, string $today): ?array {
    try {
        // Read first, so a line written while the query runs is counted next time, not skipped.
        $to = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM progress_log')->fetchColumn();
        $since = $after !== null ? 'pl.id > ?' : 'pl.log_date >= ?';
        $arg   = $after !== null ? $after
               : (new DateTime($today))->modify('-' . REALM_PURSE_LOOK_BACK . ' days')->format('Y-m-d');
        $q = $pdo->prepare(
            "SELECT pl.id, pl.log_date,
                    (SELECT MAX(sc.end_date) FROM schedule sc
                      WHERE sc.step_id = pl.step_id AND sc.item_key = pl.item_key) AS due
               FROM progress_log pl
               JOIN progress pr ON pr.project_id = pl.project_id AND pr.step_id = pl.step_id AND pr.item_key = pl.item_key
              WHERE pl.change_type = 'approved' AND pl.source <> 'import' AND pl.id <= ? AND $since
                AND (pr.crew_id = ? OR EXISTS (SELECT 1 FROM progress_crew pc
                                                WHERE pc.progress_id = pr.id AND pc.crew_id = ?))
                AND NOT EXISTS (SELECT 1 FROM progress_log e
                                 WHERE e.project_id = pl.project_id AND e.step_id = pl.step_id
                                   AND e.item_key = pl.item_key AND e.change_type = 'approved' AND e.id < pl.id)"
        );
        $q->execute([$to, $arg, $crewId, $crewId]);
        $gold = 0;
        $mana = 0;
        foreach ($q->fetchAll() as $r) {
            $onTime = $r['due'] !== null && (string)$r['log_date'] <= (string)$r['due'];
            $gold += $onTime ? REALM_GOLD_ON_TIME : REALM_GOLD_APPROVED;
            if ($onTime) $mana += REALM_MANA_ON_TIME;
        }
        return ['gold' => $gold, 'mana' => $mana, 'to' => $to];
    } catch (Throwable $e) {
        // A table this reads is missing or shaped differently on this host: pay nothing rather than guess.
        return null;
    }
}

/**
 * $uid praises $to. Returns '' when the praise was given, or the sentence to show.
 *
 * ⚠ THE SECOND LOCK. `praise` is in artist_may_post() so anybody in the Realm
 * may send it; this function is what decides. The giver is the session's own
 * account, the two must be in the same place right now, and the mana is taken
 * in the same statement that checks there is some, so two quick clicks cannot
 * spend one mana twice.
 */
function realm_praise_give(PDO $pdo, int $uid, int $to, string $kind, string $note): string {
    if (!realm_praise_available($pdo)) return 'Praise needs a database update first. An administrator opens migrate.php once.';
    if ($to === $uid || $to <= 0) return 'Praise is for somebody else.';
    if (!isset(REALM_PRAISE_KINDS[$kind])) return 'Choose what the praise is for: Craft, Help, Idea or Rescue.';
    $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
    if ($note === '') return 'Say what the praise is for, in a line.';
    if (mb_strlen($note) > REALM_PRAISE_NOTE) return 'That line is too long. Keep it under ' . REALM_PRAISE_NOTE . ' characters.';

    // Face to face: both here now, in the same place, both inside or both outside.
    $q = $pdo->prepare('SELECT rp.user_id, rp.place, rp.inside, TIMESTAMPDIFF(SECOND, rp.last_ping, NOW()) AS age, u.role, u.active
                          FROM realm_people rp JOIN users u ON u.id = rp.user_id WHERE rp.user_id IN (?, ?)');
    $q->execute([$uid, $to]);
    $at = [];
    foreach ($q->fetchAll() as $r) $at[(int)$r['user_id']] = $r;
    if (!isset($at[$uid])) return 'Open the Realm first.';
    if (!isset($at[$to]) || (int)$at[$to]['active'] !== 1 || (string)$at[$to]['role'] === 'client') return 'That person is not in the Realm.';
    $name = explode(' ', realm_person_name($pdo, $to))[0];
    if ($at[$to]['age'] === null || (int)$at[$to]['age'] > REALM_ONLINE_SECONDS) return $name . ' is away. Praise is given face to face.';
    if ((string)$at[$to]['place'] === '' || (string)$at[$to]['place'] !== (string)$at[$uid]['place']
        || (int)$at[$to]['inside'] !== (int)$at[$uid]['inside']) {
        return 'Praise is given face to face. Walk to where ' . $name . ' is first.';
    }

    $purse = realm_purse($pdo, $uid);
    if (!$purse['linked']) return 'Your login is not linked to a person yet, so you have no time log to earn mana from. An administrator links it on the Users page.';
    if ($purse['mana'] < 1) return 'You have no mana left. You earn one each working day you log your time.';

    try {
        $pdo->beginTransaction();
        $u = $pdo->prepare('UPDATE realm_purse SET mana = mana - 1, updated_at = NOW() WHERE user_id = ? AND mana >= 1');
        $u->execute([$uid]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); return 'You have no mana left. You earn one each working day you log your time.'; }
        // The first praise from this person to that person since Monday carries a coin.
        $q = $pdo->prepare('SELECT 1 FROM realm_praise WHERE from_user = ? AND to_user = ?
                               AND YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1) LIMIT 1');
        $q->execute([$uid, $to]);
        $coin = $q->fetchColumn() ? 0 : 1;
        if ($coin) {
            $pdo->prepare('INSERT INTO realm_purse (user_id, gold, updated_at) VALUES (?, 1, NOW())
                           ON DUPLICATE KEY UPDATE gold = gold + 1, updated_at = NOW()')->execute([$to]);
        }
        $pdo->prepare('INSERT INTO realm_praise (from_user, to_user, kind, note, gold, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$uid, $to, $kind, $note, $coin]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'The praise could not be given. Try again in a moment.';
    }
    return '';
}

/** One praise as the screen wants it. Never says whether it carried a coin: gold is private. */
function realm_praise_row(array $r): array {
    $name = function (array $r, string $p): string {
        return trim((string)(($r[$p . '_crew'] ?? '') ?: (($r[$p . '_display'] ?? '') ?: ($r[$p . '_login'] ?? 'Somebody'))));
    };
    $kind = isset(REALM_PRAISE_KINDS[(string)$r['kind']]) ? (string)$r['kind'] : 'craft';
    return ['id' => (int)$r['id'], 'from' => (int)$r['from_user'], 'to' => (int)$r['to_user'],
            'from_name' => $name($r, 'f'), 'to_name' => $name($r, 't'),
            'kind' => $kind, 'kind_name' => REALM_PRAISE_KINDS[$kind],
            'note' => (string)$r['note'], 'on' => substr((string)$r['created_at'], 0, 10)];
}

/** The SELECT both lists below share. */
function realm_praise_sql(string $where): string {
    return 'SELECT p.id, p.from_user, p.to_user, p.kind, p.note, p.created_at,
                   fu.display_name AS f_display, fu.username AS f_login, fc.name AS f_crew,
                   tu.display_name AS t_display, tu.username AS t_login, tc.name AS t_crew
              FROM realm_praise p
              JOIN users fu ON fu.id = p.from_user LEFT JOIN crew fc ON fc.id = fu.crew_id
              JOIN users tu ON tu.id = p.to_user   LEFT JOIN crew tc ON tc.id = tu.crew_id
             WHERE ' . $where;
}

/**
 * What travels with the map, for everybody: has 088 run, the asker's OWN mana
 * (so the button can say whether they have any), and the praises of the last
 * two minutes, which is how the others see one happen. Praise is public.
 * One small read, no time-log work. Never throws.
 */
function realm_praise_news(PDO $pdo, int $uid): array {
    $out = ['ready' => realm_praise_available($pdo), 'mana' => 0, 'cap' => REALM_MANA_CAP, 'recent' => []];
    if (!$out['ready']) return $out;
    try {
        $q = $pdo->prepare('SELECT mana FROM realm_purse WHERE user_id = ?');
        $q->execute([$uid]);
        $out['mana'] = min(REALM_MANA_CAP, max(0, (int)$q->fetchColumn()));
        $rows = $pdo->query(realm_praise_sql('p.created_at > DATE_SUB(NOW(), INTERVAL ' . REALM_PRAISE_NEWS_SECONDS . ' SECOND) ORDER BY p.id'))->fetchAll();
        foreach ($rows as $r) $out['recent'][] = realm_praise_row($r);
    } catch (Throwable $e) {
        // No news rather than a failed map.
    }
    return $out;
}

/**
 * The keepsakes in $owner's profile: how many of each kind, and the latest 20
 * with who gave them and the line. Anybody in the Realm may read these: praise
 * is public. Never throws.
 */
function realm_praise_received(PDO $pdo, int $owner): array {
    $out = ['ready' => realm_praise_available($pdo), 'total' => 0, 'kinds' => [], 'latest' => []];
    if (!$out['ready']) return $out;
    try {
        $q = $pdo->prepare('SELECT kind, COUNT(*) AS n FROM realm_praise WHERE to_user = ? GROUP BY kind');
        $q->execute([$owner]);
        foreach ($q->fetchAll() as $r) {
            if (!isset(REALM_PRAISE_KINDS[(string)$r['kind']])) continue;
            $out['kinds'][(string)$r['kind']] = (int)$r['n'];
            $out['total'] += (int)$r['n'];
        }
        $q = $pdo->prepare(realm_praise_sql('p.to_user = ? ORDER BY p.id DESC LIMIT 20'));
        $q->execute([$owner]);
        foreach ($q->fetchAll() as $r) $out['latest'][] = realm_praise_row($r);
    } catch (Throwable $e) {
        // An empty shelf rather than a profile that will not open.
    }
    return $out;
}
