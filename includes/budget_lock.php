<?php
/**
 * The budget lock: once a show's kick-off is signed off, its approved
 * production budget is fixed, and only the people ticked "Changes budget" can
 * move it, by hand, with a reason.
 *
 * David, 2026-10-28: "Only Jorik can raise a budget. I can make the budget
 * static after the project sign off but he'll need to change it manually.
 * Let's implement this already." Tim and Jorik the same day: a change after
 * sign-off saves the old amount, new amount, who, when and a short reason.
 *
 * THE TWO HALVES LIVE IN TWO DATABASES, like the kick-off itself:
 *   - whether the show is signed off, and who may change it, are production
 *     facts (project_kickoffs, users.changes_budget from migration 070). They
 *     are read HERE, on the ordinary connection.
 *   - the budget and its history are money (project_budgets, budget_changes
 *     from finance_install_10.sql). They are written in includes/finance.php,
 *     which is handed the answer from here.
 *
 * WHY A TICK AND NOT A NAME: nothing in the code knows who Jorik is, and it
 * should not — a new COO is a tick on Users, not a deploy. It mirrors
 * users.signs_off_kickoff (migration 049) and is ticked beside it.
 *
 * WHY "ANY CHANGE" AND NOT "INCREASES ONLY": David said "raise", but also
 * "static" and "he'll need to change it manually". A budget cut after sign-off
 * is as much a change to what was approved as a rise, so both go through the
 * same door and both are recorded.
 */

require_once __DIR__ . '/functions.php';

/** Has migration 070 run? */
function budget_lock_ready(PDO $pdo): bool {
    return column_exists($pdo, 'users', 'changes_budget');
}

/**
 * Names of the people who may change a signed-off budget: active
 * administrators with the tick. An editor's tick would mean nothing, because
 * only administrators can see money at all (can_see_money()).
 *
 * @return string[]
 */
function budget_changers(PDO $pdo): array {
    if (!budget_lock_ready($pdo)) return [];
    try {
        return $pdo->query(
            "SELECT COALESCE(NULLIF(display_name, ''), username) AS name FROM users
              WHERE changes_budget = 1 AND active = 1 AND role = 'admin' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Is the LOGGED-IN account one of them?
 *
 * The real session user, never a previewed one: viewing the app as somebody
 * else must not lend you their right to move a budget.
 */
function budget_user_may_change(PDO $pdo): bool {
    if (!is_admin() || in_preview()) return false;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0 || !budget_lock_ready($pdo)) return false;
    try {
        $q = $pdo->prepare("SELECT changes_budget FROM users WHERE id = ? AND active = 1 AND role = 'admin'");
        $q->execute([$uid]);
        return (int)$q->fetchColumn() === 1;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * The lock on one show's budget, or null when there is none (no signed-off
 * kick-off yet, or migration 049 has not run — the budget is then edited as it
 * always was).
 *
 * Returns:
 *   kickoff_id   the approved kick-off that fixed it
 *   signed_at    when it was approved
 *   may_change   whether the logged-in account may change it
 *   changers     names of everybody who may
 *   problem      a sentence when nobody can change it until something is run
 *   user_id      the logged-in account, for the history row
 */
function budget_lock(PDO $pdo, int $projectId): ?array {
    try {
        $q = $pdo->prepare(
            "SELECT id, decided_at FROM project_kickoffs WHERE project_id = ? AND state = 'approved'
              ORDER BY id DESC LIMIT 1"
        );
        $q->execute([$projectId]);
        $k = $q->fetch();
    } catch (PDOException $e) {
        return null;   // migration 049 pending: no sign-off exists to lock anything
    }
    if (!$k) return null;

    $problem = null;
    if (!budget_lock_ready($pdo)) {
        // Locked for everybody rather than open to everybody: the rule is the
        // point, and a missing column must not quietly undo it.
        $problem = 'Nobody can change it until migration 070 has been run (open migrate.php), '
                 . 'which adds the "Changes budget" tick.';
    }
    return [
        'kickoff_id' => (int)$k['id'],
        'signed_at'  => $k['decided_at'],
        'may_change' => $problem === null && budget_user_may_change($pdo),
        'changers'   => budget_changers($pdo),
        'problem'    => $problem,
        'user_id'    => (int)($_SESSION['user_id'] ?? 0),
    ];
}
