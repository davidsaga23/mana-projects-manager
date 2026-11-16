<?php
// A person's birthday: month and day, never the year (migration 100).
//
// David, 2026-11-16: "Celebrations: but only for birthdays, and only if they're
// available in their profile. Their house should be decorated with balloons, and
// colors so everyone knows." Asked how, he agreed the same day to: an optional
// birthday in each person's own profile, filled in by them; month and day only, so
// nobody's age is stored or shown; nobody sees the date itself; on the day the
// Realm decorates their house and says "Birthday today"; a birthday on a Saturday
// or Sunday is celebrated on the Friday before, so the team is around to see it;
// 29 February is celebrated on 28 February in years without one.
//
// ⚠ NEVER A LIST OF UPCOMING BIRTHDAYS, and never the date on anybody else's
// screen. The only thing that leaves the server about somebody else's birthday is
// "today is the celebration", on that one day. The person reads and changes their
// own date in their own profile (Home, or the portrait in their Realm house).
//
// ⚠ PURE AND LIGHT. No uploads, no money, nothing that can throw past its own
// try/catch: includes/realm.php loads this on every heartbeat.

/** Has migration 100 run? Never throws. */
function birthday_available(PDO $pdo): bool {
    try {
        return function_exists('column_exists') && column_exists($pdo, 'crew', 'birth_month');
    } catch (Throwable $e) {
        return false;
    }
}

/** A month and a day that exist in some year (29 February included), or false. */
function birthday_valid(int $month, int $day): bool {
    return $month >= 1 && $month <= 12 && $day >= 1 && checkdate($month, $day, 2000);
}

/** "14 March", for the person's own eyes only. Empty when not set. */
function birthday_label(?int $month, ?int $day): string {
    if (!$month || !$day || !birthday_valid($month, $day)) return '';
    return $day . ' ' . date('F', mktime(0, 0, 0, $month, 1, 2000));
}

/**
 * The day a birthday is celebrated in a given year, as 'Y-m-d': the birthday
 * itself on a weekday, the Friday before when it falls on a weekend, and
 * 28 February for 29 February in a year without one.
 */
function birthday_party_day(int $month, int $day, int $year): string {
    if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) $day = 28;
    $t = mktime(12, 0, 0, $month, $day, $year);
    $dow = (int)date('N', $t);              // 6 = Saturday, 7 = Sunday
    if ($dow >= 6) $t -= ($dow - 5) * 86400;
    return date('Y-m-d', $t);
}

/**
 * Is today (the studio's time zone, set in includes/db.php from config) the
 * celebration? Next year's birthday is asked too: a birthday on 1 or 2 January
 * that lands on a weekend is celebrated on the Friday at the end of December.
 */
function birthday_is_today(?int $month, ?int $day, ?string $today = null): bool {
    if (!$month || !$day || !birthday_valid($month, $day)) return false;
    $today = $today ?? date('Y-m-d');
    $year = (int)substr($today, 0, 4);
    return birthday_party_day($month, $day, $year) === $today
        || birthday_party_day($month, $day, $year + 1) === $today;
}

/**
 * The crew ids whose birthday is celebrated today, as [crew_id => true]. Empty
 * before migration 100 or if anything fails: the Realm then decorates nobody.
 */
function birthdays_today(PDO $pdo): array {
    if (!birthday_available($pdo)) return [];
    $out = [];
    try {
        foreach ($pdo->query('SELECT id, birth_month, birth_day FROM crew
                               WHERE birth_month IS NOT NULL AND birth_day IS NOT NULL') as $r) {
            if (birthday_is_today((int)$r['birth_month'], (int)$r['birth_day'])) $out[(int)$r['id']] = true;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}
