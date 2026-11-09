<?php
/**
 * Budget requests: asking for a change to a signed-off budget, and Jorik's
 * answer.
 *
 * David, 2026-11-06: "Kick-offs have versions: v1, v2, v3. A new version is
 * made when Jorik approves a budget request, or when the project is sent for
 * sign-off again after a re-plan." Asked whether Jorik's own edit was enough,
 * he chose "Add a request step": an administrator proposes a new amount and a
 * reason, the people ticked "Changes budget" (Jorik) are emailed, and approving
 * changes the budget AND saves the next kick-off version.
 *
 * Jorik can still change the budget directly, as since 2026-10-28: he is the
 * approver, so his own change is already approved, and it makes a version too
 * (kickoff_budget_version()).
 *
 * THE REQUESTS ARE MONEY, so they live in the FINANCE database
 * (budget_requests, finance_install_16.sql, imported by hand). Until that is
 * imported the request form says so and nothing else changes.
 *
 * Approving goes through save_project_budget() with the lock, the same door as
 * a direct change, so the budget history (budget_changes) records it exactly as
 * it records his own edits: old, new, who, when and why.
 */

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/budget_lock.php';
require_once __DIR__ . '/kickoff.php';

/** Has finance_install_16.sql been imported? */
function budget_requests_ready(): bool {
    return can_see_money() && finance_column_exists('budget_requests', 'state');
}

/** Every request on a show, newest first. */
function budget_requests(int $projectId): array {
    if (!budget_requests_ready()) return [];
    try {
        $q = finance_db()->prepare('SELECT * FROM budget_requests WHERE project_id = ? ORDER BY id DESC');
        $q->execute([$projectId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** One request, checked to belong to the show. */
function budget_request_get(int $projectId, int $requestId): array {
    $q = finance_db()->prepare('SELECT * FROM budget_requests WHERE id = ? AND project_id = ?');
    $q->execute([$requestId, $projectId]);
    $r = $q->fetch();
    if (!$r) throw new RuntimeException('That budget request no longer exists.');
    return $r;
}

/** Link to the show's finance Overview, for emails. */
function budget_request_url(int $projectId): string {
    return str_replace('/kickoff.php?', '/finance_project.php?', kickoff_url($projectId)) . '&tab=overview';
}

/** The people ticked "Changes budget", with the address each is emailed at. */
function budget_request_approvers(PDO $pdo): array {
    if (!budget_lock_ready($pdo)) return [];
    $out = [];
    try {
        $ids = $pdo->query("SELECT id FROM users WHERE changes_budget = 1 AND active = 1 AND role = 'admin'")
                   ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
    foreach ($ids as $id) {
        if ($to = kickoff_user_email($pdo, (int)$id)) $out[strtolower($to['email'])] = $to;
    }
    return array_values($out);
}

/**
 * Ask for a new budget. Any administrator; one open request per show, so the
 * approver never has two answers to reconcile.
 *
 * @return string[] sentences for the screen
 */
function budget_request_create(PDO $pdo, array $project, array $in, ?array $lock): array {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can ask for a budget change.');
    if ($lock === null) throw new RuntimeException('This budget is not signed off yet, so it is changed directly.');
    if (!budget_requests_ready()) {
        throw new RuntimeException('Budget requests need finance_install_16.sql imported (by hand, in phpMyAdmin). Nothing was sent.');
    }
    $pid = (int)$project['id'];
    foreach (budget_requests($pid) as $r) {
        if ($r['state'] === 'pending') {
            throw new RuntimeException('There is already a budget request waiting for an answer. Withdraw it first to ask for a different one.');
        }
    }
    $raw = trim((string)($in['request_amount'] ?? ''));
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
    if ($clean === '' || !is_numeric($clean) || (float)$clean < 0) {
        throw new RuntimeException('"' . $raw . '" is not a budget amount. Nothing was sent.');
    }
    $amount = round((float)$clean, 2);
    $cur = strtoupper(trim((string)($in['request_currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';
    $reason = mb_substr(trim((string)($in['request_reason'] ?? '')), 0, 500);
    if ($reason === '') throw new RuntimeException('Say in a few words why the budget should change. Nothing was sent.');

    $budget = project_budget($pid) ?: [];
    $old = isset($budget['budget_amount']) && $budget['budget_amount'] !== null ? round((float)$budget['budget_amount'], 2) : null;
    $oldCur = (string)($budget['currency'] ?? 'USD');
    if ($old === $amount && $oldCur === $cur) {
        throw new RuntimeException('That is the budget already approved. Nothing was sent.');
    }

    $who = mb_substr(current_user_display(), 0, 100);
    finance_db()->prepare(
        'INSERT INTO budget_requests (project_id, kickoff_id, old_amount, old_currency, new_amount, new_currency,
            reason, state, requested_by_user_id, requested_by, requested_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$pid, $lock['kickoff_id'] ?: null, $old, $oldCur, $amount, $cur, $reason, 'pending',
                $lock['user_id'] ?: null, $who, date('Y-m-d H:i:s')]);

    $msgs = ['Budget request sent: ' . fmt_money_plain($amount, $cur) . '. It changes nothing until it is approved.'];
    $approvers = budget_request_approvers($pdo);
    $url  = budget_request_url($pid);
    $line = $who . ' asks to change the approved budget of ' . $project['name'] . ' from '
          . ($old === null ? 'none' : fmt_money_plain($old, $oldCur)) . ' to ' . fmt_money_plain($amount, $cur) . '.';
    foreach ($approvers as $to) {
        [, $res] = kickoff_mail($to,
            sprintf('[%s] Budget request: %s', $project['code'], $project['name']),
            kickoff_mail_html(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), [], $url, 'Approve or decline', 'Why: ' . $reason),
            "{$line}\n\nWhy: {$reason}\n\nApprove or decline: {$url}\n");
        $msgs[] = $to['name'] . ': ' . $res . '.';
    }
    if (!$approvers) $msgs[] = 'Nobody ticked "Changes budget" has an email address on file, so nobody was emailed; it waits on the Overview.';
    return $msgs;
}

/**
 * Approve or decline a request. Only someone ticked "Changes budget".
 * Approving changes the budget through save_project_budget() and saves the next
 * kick-off version.
 *
 * @return string[] sentences for the screen
 */
function budget_request_decide(PDO $pdo, array $project, int $requestId, string $decision, string $note,
                               ?array $lock, string $who): array {
    $pid = (int)$project['id'];
    if (!$lock || !$lock['may_change']) {
        throw new RuntimeException('Only ' . ($lock && $lock['changers'] ? implode(' or ', $lock['changers']) : 'somebody ticked "Changes budget"')
            . ' can answer a budget request.');
    }
    $r = budget_request_get($pid, $requestId);
    if ($r['state'] !== 'pending') throw new RuntimeException('That budget request has already been answered.');
    $note = mb_substr(trim($note), 0, 500);
    $now  = date('Y-m-d H:i:s');
    $msgs = [];

    if ($decision === 'approved') {
        $budget = project_budget($pid) ?: [];
        // Through the same door as a direct change, so the history row is
        // written the same way. Terms and notes are kept as they are.
        $msgs[] = save_project_budget($pid, $project, [
            'budget_amount' => (string)$r['new_amount'],
            'currency'      => (string)$r['new_currency'],
            'terms'         => (string)($budget['terms'] ?? ''),
            'notes'         => (string)($budget['notes'] ?? ''),
            'change_reason' => mb_substr($r['reason'] . ' (asked by ' . $r['requested_by'] . ')', 0, 500),
        ], $who, $lock);
        try {
            if ($v = kickoff_budget_version($pdo, $project, (int)$lock['kickoff_id'])) $msgs[] = $v;
        } catch (Throwable $e) {
            $msgs[] = 'The budget is changed and recorded, but it could not be saved as a new kick-off version: ' . $e->getMessage();
        }
    } elseif ($decision !== 'declined') {
        throw new RuntimeException('That is not an answer this page knows.');
    } else {
        $msgs[] = 'Budget request declined. The approved budget is unchanged.';
    }

    finance_db()->prepare(
        'UPDATE budget_requests SET state = ?, decided_by_user_id = ?, decided_by = ?, decided_at = ?, decision_note = ?
          WHERE id = ? AND state = ?'
    )->execute([$decision, $lock['user_id'] ?: null, mb_substr($who, 0, 100), $now, $note !== '' ? $note : null,
                $requestId, 'pending']);

    if ($to = kickoff_user_email($pdo, $r['requested_by_user_id'] !== null ? (int)$r['requested_by_user_id'] : null)) {
        $line = $who . ($decision === 'approved' ? ' approved' : ' declined') . ' your request to change the budget of '
              . $project['name'] . ' to ' . fmt_money_plain((float)$r['new_amount'], (string)$r['new_currency']) . '.';
        [, $res] = kickoff_mail($to,
            sprintf('[%s] Budget request %s: %s', $project['code'], $decision, $project['name']),
            kickoff_mail_html(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), [], budget_request_url($pid), 'Open the Overview', $note),
            "{$line}\n\n{$note}\n\n" . budget_request_url($pid) . "\n");
        $msgs[] = $r['requested_by'] . ': ' . $res . '.';
    }
    return $msgs;
}

/** Take back a request that is still waiting. Any administrator. */
function budget_request_withdraw(int $projectId, int $requestId): void {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can withdraw a budget request.');
    $r = budget_request_get($projectId, $requestId);
    if ($r['state'] !== 'pending') throw new RuntimeException('That budget request has already been answered.');
    finance_db()->prepare("UPDATE budget_requests SET state = 'withdrawn', decided_by = ?, decided_at = ? WHERE id = ?")
        ->execute([mb_substr(current_user_display(), 0, 100), date('Y-m-d H:i:s'), $requestId]);
}

/**
 * The requests block under the budget box: the form to ask (anybody who may
 * not change it directly), what is waiting with Approve / Decline for the
 * approver, and the answered ones folded underneath.
 */
function budget_requests_render(int $projectId, array $lock, string $currency): void {
    if (!budget_requests_ready()) {
        if (!$lock['may_change']) { ?>
          <p class="muted small">To ask for a change to this budget, <code>finance_install_16.sql</code> needs importing
            into the finance database (by hand, in phpMyAdmin).</p>
        <?php }
        return;
    }
    $all = budget_requests($projectId);
    $open = array_values(array_filter($all, fn($r) => $r['state'] === 'pending'));
    $done = array_values(array_filter($all, fn($r) => $r['state'] !== 'pending'));
    $money = fn($a, $c) => $a === null ? 'none' : fmt_money_plain((float)$a, (string)($c ?: 'USD'));
    ?>
    <div class="budget-requests">
    <?php foreach ($open as $r): ?>
      <div class="flash flash-warn">
        <p><strong>Budget request waiting:</strong> <?= e($r['requested_by']) ?> asks to change it from
          <?= e($money($r['old_amount'], $r['old_currency'])) ?> to <strong><?= e($money($r['new_amount'], $r['new_currency'])) ?></strong>
          <span class="muted small">(<?= e(substr((string)$r['requested_at'], 0, 16)) ?>)</span></p>
        <p class="kickoff-prose"><?= e($r['reason']) ?></p>
        <form method="post" class="inline-form">
          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
          <?php if ($lock['may_change']): ?>
            <input name="decision_note" maxlength="500" placeholder="Note (optional)">
            <button type="submit" name="budget_request" value="approved"
                    onclick="return confirm('Approve this budget? It changes the budget and saves a new kick-off version.');">Approve</button>
            <button type="submit" name="budget_request" value="declined" class="secondary">Decline</button>
          <?php else: ?>
            <span class="muted small">Waiting for <?= e($lock['changers'] ? implode(' or ', $lock['changers']) : 'somebody ticked "Changes budget"') ?>.</span>
          <?php endif; ?>
          <button type="submit" name="budget_request" value="withdraw" class="secondary"
                  onclick="return confirm('Withdraw this budget request?');">Withdraw</button>
        </form>
      </div>
    <?php endforeach; ?>

    <?php if (!$open && !$lock['may_change']): ?>
      <details class="budget-request-ask">
        <summary>Ask for a budget change</summary>
        <p class="muted small">Sent to <?= e($lock['changers'] ? implode(' or ', $lock['changers']) : 'whoever is ticked "Changes budget"') ?>
          to approve. Approving changes the budget and saves the show as a new kick-off version.</p>
        <form method="post" class="money-form">
          <input type="hidden" name="budget_request" value="ask">
          <div class="row">
            <label>New approved budget
              <input name="request_amount" inputmode="decimal" required placeholder="e.g. 42000"></label>
            <label style="flex:0 1 90px;">Currency
              <input name="request_currency" maxlength="3" style="text-transform:uppercase" value="<?= e($currency) ?>"></label>
            <label style="flex:2 1 260px;">Why
              <input name="request_reason" maxlength="500" required placeholder="e.g. client added two episodes"></label>
            <div class="row-save"><button type="submit">Send request</button></div>
          </div>
        </form>
      </details>
    <?php endif; ?>

    <?php if ($done): ?>
      <details>
        <summary class="muted small">Answered budget requests (<?= count($done) ?>)</summary>
        <table class="data">
          <thead><tr><th>Asked</th><th>By</th><th class="num">Asked for</th><th>Why</th><th>Answer</th></tr></thead>
          <tbody>
          <?php foreach ($done as $r): ?>
            <tr>
              <td><?= e(substr((string)$r['requested_at'], 0, 16)) ?></td>
              <td><?= e($r['requested_by']) ?></td>
              <td class="num"><?= e($money($r['new_amount'], $r['new_currency'])) ?></td>
              <td><?= e($r['reason']) ?></td>
              <td><?= e(ucfirst((string)$r['state'])) ?><?= $r['decided_by'] ? ' by ' . e($r['decided_by']) : '' ?>
                <?= $r['decided_at'] ? '<span class="muted small">' . e(substr((string)$r['decided_at'], 0, 16)) . '</span>' : '' ?>
                <?php if ($r['decision_note']): ?><div class="muted small"><?= e($r['decision_note']) ?></div><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>
    </div>
    <?php
}
