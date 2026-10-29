<?php
// The light half of reviews: who reviews what, and how many tasks wait on them.
//
// ⚠ KEPT APART FROM includes/review.php because the top bar loads it on EVERY
// page (the Reviews button and its count). That file pulls in Spell's whole
// library; this one only needs auth.php, which every page has already loaded,
// and studio.php, which is functions only.

require_once __DIR__ . '/studio.php';

// The two stages, by status NAME (David, 2026-10-27). See includes/review.php.
const REVIEW_STAGES = [
    'supervisor' => ['from' => 'pending review',   'to' => 'ready for review', 'label' => 'Supervisor review'],
    'final'      => ['from' => 'ready for review', 'to' => 'complete',         'label' => 'Final review'],
];

/** A status name as compared here: lower case, single spaces. */
function review_norm(string $name): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
}

/**
 * Which queue is this account's? THE STUDIO'S RULES (David, 2026-10-29):
 *   1. a new version up       → Pending review     (board_version.php)
 *   2. Production or a supervisor approves → Ready for review
 *   3. Jorik / Tim approve    → Complete           (admins: the final stage)
 *   4. the client approves    → Final, set by hand for now
 * So admins review last, and Production (editors) review FIRST, beside the
 * supervisors. Anybody else reviews nothing, and gets no button.
 */
function review_stage(): ?string {
    if (is_admin()) return 'final';
    if (can_edit() || is_supervisor()) return 'supervisor';
    return null;
}

/**
 * Every stage this account reviews, in pipeline order. Admins see BOTH (David,
 * 2026-10-29, finding a Pending review version missing from his Reviews:
 * "let's do that and show both"), so work on a show with no supervisor is
 * never waiting on nobody. Production and supervisors have the first only.
 */
function review_stages(): array {
    $stage = review_stage();
    if ($stage === 'final') return ['supervisor', 'final'];
    return $stage ? [$stage] : [];
}

/** The statuses ("from") that put a task in this account's queue. */
function review_from_names(): array {
    return array_map(fn($k) => REVIEW_STAGES[$k]['from'], review_stages());
}

/**
 * The shows this account can review on: the same set Spell offers
 * (board_projects()), written here so the top bar need not load Spell.
 */
function review_project_ids(PDO $pdo): array {
    static $ids = null;
    if ($ids !== null) return $ids;
    try {
        $rows = $pdo->query(
            "SELECT id FROM projects WHERE status IN ('setup', 'active', 'on_hold')"
            . studio_exclude_sql($pdo, '')
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return $ids = [];
    }
    $rows = array_map('intval', $rows);
    $allowed = visible_project_ids($pdo);
    if ($allowed !== null) {
        $ok = array_flip(array_map('intval', $allowed));
        $rows = array_values(array_filter($rows, fn($id) => isset($ok[$id])));
    }
    return $ids = $rows;
}

/** How many tasks wait on this account. Never throws: chrome must not take a page down. */
function review_count(PDO $pdo): int {
    static $n = null;
    if ($n !== null) return $n;
    $from = review_from_names();
    $ids = $from ? review_project_ids($pdo) : [];
    if (!$ids) return $n = 0;
    try {
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM progress pr JOIN statuses st ON st.id = pr.status_id
              WHERE pr.project_id IN (' . implode(',', $ids) . ')
                AND LOWER(TRIM(st.name)) IN (' . implode(',', array_fill(0, count($from), '?')) . ')'
        );
        $q->execute($from);
        return $n = (int)$q->fetchColumn();
    } catch (PDOException $e) {
        return $n = 0;
    }
}
