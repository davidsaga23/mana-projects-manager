<?php
// A person's days off, and the days they are not on a show at all.
//
// ROADMAP, "Days off are a scheduler input, not an HR note": somebody away for
// two weeks made every date that assumed they were not quietly wrong, always in
// the optimistic direction. So an absence is not a note on a person; it is a
// table the scheduler reads (migration 060). Public holidays are a different
// thing and stay where they are, in non_working_days (includes/calendar.php):
// those close the studio, these take one person out of it.
//
// The scheduler reads two things from here, per person:
//   * their ABSENCES, any show — a holiday is a holiday everywhere;
//   * their ENGAGEMENT on this show (crew_projects.start_date / end_date,
//     migration 024) — nothing before the start, nothing after the end.
// Both reduce to one question, "can this person work on this date?", which is
// what person_unavailability() answers.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/hiring.php';   // engagement_dates_available()

/** Has migration 060 run? Everything here is a no-op until it has. */
function absences_available(PDO $pdo): bool {
    return column_exists($pdo, 'crew_absences', 'crew_id');
}

/** One person's absences, soonest first. */
function crew_absences(PDO $pdo, int $crewId): array {
    if (!absences_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT id, from_date, to_date, reason, created_by, created_at
           FROM crew_absences WHERE crew_id = ? ORDER BY from_date, id'
    );
    $stmt->execute([$crewId]);
    return $stmt->fetchAll();
}

/**
 * Record an absence. Returns the sentence the flash area shows.
 *
 * A one-day absence is from = to; an empty "to" means exactly that, because the
 * commonest thing typed here is a single day off.
 */
function add_crew_absence(PDO $pdo, int $crewId, string $from, string $to, string $reason, string $by): string {
    if (!absences_available($pdo)) {
        throw new RuntimeException('The database update for days off (migration 060) has not been applied yet.');
    }
    $from = trim($from);
    $to   = trim($to) === '' ? $from : trim($to);
    foreach ([$from, $to] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            throw new RuntimeException('A day off needs a date in YYYY-MM-DD form, nothing was saved.');
        }
    }
    if ($to < $from) {
        throw new RuntimeException('The last day is before the first, nothing was saved.');
    }
    $reason = mb_substr(trim($reason), 0, 120);
    $pdo->prepare(
        'INSERT INTO crew_absences (crew_id, from_date, to_date, reason, created_by) VALUES (?, ?, ?, ?, ?)'
    )->execute([$crewId, $from, $to, $reason !== '' ? $reason : null, $by]);

    $days = working_days_between($from, $to);
    return sprintf(
        'Days off saved, %s%s, %d working day%s. Re-run the schedule for the plan to move around them.',
        $from, $to !== $from ? ' to ' . $to : '', $days, $days === 1 ? '' : 's'
    );
}

/** Remove one absence, only if it belongs to this person. */
function delete_crew_absence(PDO $pdo, int $crewId, int $absenceId): string {
    if (!absences_available($pdo)) return 'Nothing to remove.';
    $stmt = $pdo->prepare('DELETE FROM crew_absences WHERE id = ? AND crew_id = ?');
    $stmt->execute([$absenceId, $crewId]);
    return $stmt->rowCount()
        ? 'Days off removed. Re-run the schedule for the plan to use those days again.'
        : 'Those days off were already gone.';
}

/**
 * When each person on a project cannot work, for the scheduler.
 *
 * crew_id => ['days' => [YYYY-MM-DD => reason], 'start' => ?date, 'end' => ?date]
 *
 * 'days' holds every date inside an absence; weekends and holidays are left in
 * (the axis never asks about them, so they cost nothing). 'start' and 'end' are
 * the engagement on THIS project, either of them null when not set. A person
 * with neither is left out entirely, so the scheduler's fast path stays the
 * common case.
 */
function person_unavailability(PDO $pdo, int $projectId, array $crewIds): array {
    $crewIds = array_values(array_unique(array_filter(array_map('intval', $crewIds))));
    if (!$crewIds) return [];
    $in  = implode(',', array_fill(0, count($crewIds), '?'));
    $out = [];

    if (absences_available($pdo)) {
        $stmt = $pdo->prepare(
            "SELECT crew_id, from_date, to_date, reason FROM crew_absences WHERE crew_id IN ($in)"
        );
        $stmt->execute($crewIds);
        foreach ($stmt as $r) {
            $id = (int)$r['crew_id'];
            $d  = new DateTime($r['from_date']);
            $to = (string)$r['to_date'];
            // Capped: a typo of 2062 for 2026 must not build forty years of dates.
            for ($i = 0; $i < 400 && $d->format('Y-m-d') <= $to; $i++) {
                $out[$id]['days'][$d->format('Y-m-d')] = (string)($r['reason'] ?? '') ?: 'day off';
                $d->modify('+1 day');
            }
        }
    }

    if (engagement_dates_available($pdo)) {
        $stmt = $pdo->prepare(
            "SELECT crew_id, start_date, end_date FROM crew_projects
              WHERE project_id = ? AND crew_id IN ($in)
                AND (start_date IS NOT NULL OR end_date IS NOT NULL)"
        );
        $stmt->execute(array_merge([$projectId], $crewIds));
        foreach ($stmt as $r) {
            $id = (int)$r['crew_id'];
            $out[$id]['start'] = $r['start_date'] ?: null;
            $out[$id]['end']   = $r['end_date'] ?: null;
        }
    }

    foreach ($out as $id => $u) {
        $out[$id] += ['days' => [], 'start' => null, 'end' => null];
    }
    return $out;
}
