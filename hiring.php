<?php
// Hiring — the gap between "the plan needs a person here" and "a named person
// has agreed terms".
//
// The screen is organised by SLOT rather than by candidate, because the
// question it exists to answer is "which jobs still have nobody lined up" —
// not "who have we spoken to". A list of candidates sorted by name would bury
// the one fact that sinks a schedule: an unfilled slot three weeks from its
// department starting, with nobody against it.
//
// The money is admin-only and lives in the OTHER database. What a candidate
// quoted goes through includes/money.php like every other rate; everything else
// on this page is ordinary planning data any editor may see. David's call,
// 2026-08-17.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/crew.php';
require_once __DIR__ . '/includes/hiring.php';
require_once __DIR__ . '/includes/money.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('err', 'That project no longer exists.');
    header('Location: index.php');
    exit;
}

$selfUrl = 'hiring.php?project=' . $projectId;
$ready   = hiring_available($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!$ready) {
            throw new RuntimeException(
                'The database updates this screen needs have not been applied yet, so nothing '
                . 'was saved. An administrator can apply them from the Migrations screen.'
            );
        }
        $who = current_user_display();

        if ($action === 'save_hire') {
            [, $said] = save_hire($pdo, $projectId, $_POST, $who);
            flash('ok', $said);

        } elseif (preg_match('/^stage:(\d+)$/', $action, $m)) {
            flash('ok', set_hire_stage($pdo, $projectId, (int)$m[1],
                        (string)($_POST['stage'] ?? ''), (string)($_POST['note'] ?? ''), $who));

        } elseif (preg_match('/^hire:(\d+)$/', $action, $m)) {
            foreach (hire_candidate($pdo, $projectId, (int)$m[1], $_POST, $who) as $said) {
                flash('ok', $said);
            }

        } elseif (preg_match('/^quote:(\d+)$/', $action, $m)) {
            // Writes to the FINANCE database. money.php refuses for a non-admin,
            // so this cannot leak even if the button were somehow reachable.
            $hireId = (int)$m[1];
            $hire   = get_hire($pdo, $hireId);
            if (!$hire || (int)$hire['project_id'] !== $projectId) {
                throw new RuntimeException('That hiring record is not on this project.');
            }
            flash('ok', save_hire_quote($hireId, (string)$hire['candidate_name'],
                                        $projectId, $_POST, $who));

        } elseif (preg_match('/^contract:(\d+)$/', $action, $m)) {
            $crewId = (int)$m[1];
            $c = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
            $c->execute([$crewId]);
            $name = (string)($c->fetchColumn() ?: '');
            if ($name === '') throw new RuntimeException('That person is no longer in the crew list.');
            flash('ok', set_contract_state($crewId, $name, (string)($_POST['contract_status'] ?? ''),
                                           $_POST['contract_sent_date'] ?? null,
                                           $_POST['contract_signed_date'] ?? null, $who));

        } elseif (preg_match('/^dates:(\d+)$/', $action, $m)) {
            flash('ok', set_engagement_dates($pdo, (int)$m[1], $projectId,
                        $_POST['start_date'] ?? '', $_POST['end_date'] ?? ''));
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$lanes   = $ready ? project_lanes($pdo, $projectId) : [];
$byLane  = $ready ? hires_by_lane($pdo, $projectId) : [];
$summary = $ready ? hiring_summary($pdo, $projectId) : null;
$roster  = $ready ? project_engagements($pdo, $projectId) : [];

// The finance side. Every one of these is admin-only inside money.php, so an
// editor gets empty arrays rather than a guard that somebody has to remember.
$quotes = []; $contracts = []; $financeError = null; $needsFinanceUpgrade = false;
if (can_see_money()) {
    try {
        $hireIds = [];
        foreach ($byLane as $rows) foreach ($rows as $h) $hireIds[] = (int)$h['id'];
        $quotes = hire_quotes($hireIds);

        $crewIds = [];
        foreach ($roster as $p) $crewIds[] = (int)$p['id'];
        $contracts = contract_states($crewIds);
        $needsFinanceUpgrade = finance_configured() && contracts_need_upgrade();
    } catch (Throwable $e) {
        // Reported, never swallowed: "no contract on file" and "the finance
        // database is unreachable" look identical otherwise (lesson 11).
        $financeError = $e->getMessage();
    }
}

// Slots grouped by step, in pipeline order — the same shape the Crew screen uses.
$slotsByStep = [];
foreach ($lanes as $l) $slotsByStep[(int)$l['step_id']][] = $l;

$unattached = $byLane[0] ?? [];

$pageTitle = 'Hiring · ' . $project['name'];
require __DIR__ . '/includes/header.php';
?>

<?php project_nav($project, 'hiring'); ?>

<h2 class="wizard-title">Hiring <span class="muted">· <?= e($project['name']) ?></span></h2>

<p class="lead">
  The plan knows a slot needs somebody, and the crew list knows when one is filled. This is the
  months in between — who was approached, what they asked for, and whether they said yes.
</p>

<?php if (!$ready): ?>
  <div class="flash flash-err">
    This screen needs database updates that haven't been applied.
    <?= is_admin() ? '<a href="migrate.php">Apply them →</a>' : 'Ask an administrator to run them.' ?>
  </div>
<?php else: ?>

  <div class="summary-line">
    <strong><?= (int)$summary['slots_unfilled'] ?></strong> of <?= (int)$summary['slots_total'] ?>
    slot<?= (int)$summary['slots_total'] === 1 ? '' : 's' ?> still unfilled
    · <strong><?= (int)$summary['open'] ?></strong> conversation<?= (int)$summary['open'] === 1 ? '' : 's' ?> in play
    <?php if ((int)$summary['slots_untouched'] > 0): ?>
      · <span class="warn-text"><strong><?= (int)$summary['slots_untouched'] ?></strong> with nobody lined up</span>
    <?php endif; ?>
  </div>

  <?php if ((int)$summary['slots_untouched'] > 0): ?>
    <div class="flash flash-warn">
      <strong><?= (int)$summary['slots_untouched'] ?></strong>
      unfilled slot<?= (int)$summary['slots_untouched'] === 1 ? ' has' : 's have' ?>
      nobody in play — no candidate at all, or everyone approached has since declined.
      <?php if (can_see_money()): ?>
        These are also what the
        <a href="finance_project.php?project=<?= $projectId ?>">expense projection</a>
        reports as unpriced days: work the plan needs that has no cost behind it yet.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($financeError !== null): ?>
    <div class="flash flash-err"><?= e($financeError) ?></div>
  <?php elseif ($needsFinanceUpgrade): ?>
    <div class="flash flash-warn">
      Contract tracking needs <code>finance_install_04.sql</code>, which hasn't been imported
      into the finance database yet. Quotes and contract states are unavailable until it is —
      everything else on this page works.
    </div>
  <?php endif; ?>

  <?php // ---- add somebody ------------------------------------------------ ?>
  <?php if (can_edit()): ?>
    <details class="inline-actions" <?= $summary['open'] === 0 ? 'open' : '' ?>>
      <summary>Add a candidate</summary>
      <form method="post" class="stack">
        <input type="hidden" name="action" value="save_hire">
        <div class="row">
          <label>Name <input name="candidate_name" required placeholder="Who they are"></label>
          <label>For which slot
            <select name="lane_id">
              <option value="">— not tied to a slot yet —</option>
              <?php foreach ($lanes as $l): if ((int)$l['is_placeholder'] !== 1) continue; ?>
                <option value="<?= (int)$l['id'] ?>">
                  <?= e($l['step_name']) ?> · <?= e($l['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Stage
            <select name="stage">
              <?php foreach (HIRE_STAGES as $k => $lbl): if (in_array($k, HIRE_STAGES_CLOSED, true)) continue; ?>
                <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="row">
          <label>Email <input type="email" name="email" placeholder="Optional"></label>
          <label>Phone <input name="phone" placeholder="Optional"></label>
          <label>Where they came from
            <input name="source" list="hireSources" placeholder="e.g. referral, agency, reel">
            <datalist id="hireSources">
              <?php
              // Built from what has actually been typed on this project, the same
              // way asset types and departments build their suggestions.
              $seen = [];
              foreach ($byLane as $rows) foreach ($rows as $h) {
                  if (!empty($h['source'])) $seen[$h['source']] = true;
              }
              foreach (array_keys($seen) as $s): ?>
                <option value="<?= e($s) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </label>
        </div>
        <label>Portfolio or reel <input type="url" name="portfolio_url" placeholder="https://…"></label>
        <label>Notes <textarea name="notes" rows="2" placeholder="What was said, what they are asking for"></textarea></label>
        <div class="actions"><button type="submit">Add candidate</button></div>
      </form>
      <?php if (can_see_money()): ?>
        <p class="muted small">
          What they quoted is recorded separately, on the candidate once they exist — it lives in
          the finance database and only administrators ever see it.
        </p>
      <?php endif; ?>
    </details>
  <?php endif; ?>

  <?php // ---- the slots -------------------------------------------------- ?>
  <?php if (!$lanes): ?>
    <div class="empty-state">
      <h2>No crew slots on this project yet</h2>
      <p>Hiring is organised around the slots the headcount asks for. Create them on the
         <a href="crew.php?project=<?= $projectId ?>">Crew screen</a> and they will appear here,
         each one a job to find somebody for.</p>
    </div>
  <?php else: ?>

    <?php foreach ($slotsByStep as $stepId => $slots): $first = $slots[0]; ?>
      <h3 class="crew-step-head">
        <span class="dot" style="background:<?= e($first['color'] ?: '#ccc') ?>"></span>
        <?= e($first['step_name']) ?>
        <span class="muted">· <?= e(applies_to_phrase($first['applies_to'],
                                     $first['applies_to_asset_type'] ?? null)) ?></span>
      </h3>

      <?php foreach ($slots as $l):
        $isPh    = (int)$l['is_placeholder'] === 1;
        $cands   = $byLane[(int)$l['id']] ?? [];
        $live    = array_filter($cands, function ($h) { return in_array($h['stage'], HIRE_STAGES_OPEN, true); });
        $needs   = $isPh && !$live;
      ?>
        <table class="review-table <?= $needs ? 'has-problem' : '' ?>">
          <tr>
            <th style="width:24%">
              <?= e($l['name']) ?>
              <?php if ($isPh): ?><span class="est-tag">unfilled</span>
              <?php else: ?><span class="ready-tag">filled</span><?php endif; ?>
            </th>
            <th>Candidates</th>
          </tr>
          <tr>
            <td class="muted small">
              <?php if ($needs): ?>
                <span class="warn-text">Nobody lined up.</span>
              <?php elseif ($isPh): ?>
                <?= count($live) ?> in play
              <?php else: ?>
                <a href="person.php?project=<?= $projectId ?>&crew=<?= (int)$l['crew_id'] ?>">Open their page →</a>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$cands): ?>
                <span class="muted small">None yet.</span>
              <?php else: ?>
                <?php foreach ($cands as $h): $c = hire_stage_color($h['stage']); ?>
                  <div class="cat-row">
                    <span class="status-chip"
                          style="background:<?= e($c) ?>1a;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44;">
                      <?= e(hire_stage_label($h['stage'])) ?></span>
                    <strong><?= e($h['candidate_name']) ?></strong>
                    <?php if (!empty($h['source'])): ?>
                      <span class="muted small">via <?= e($h['source']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($h['portfolio_url'])): ?>
                      · <a href="<?= e($h['portfolio_url']) ?>" target="_blank" rel="noopener">reel</a>
                    <?php endif; ?>
                    <?php if (!empty($h['email'])): ?>
                      · <a href="mailto:<?= e($h['email']) ?>"><?= e($h['email']) ?></a>
                    <?php endif; ?>
                    <?php if (can_see_money() && isset($quotes[(int)$h['id']])):
                      $q = $quotes[(int)$h['id']];
                      if ($q['quoted_rate'] !== null): ?>
                        · <span class="chip" title="Only administrators see this">
                            asks <?= e(fmt_money((float)$q['quoted_rate'], $q['rate_unit'], $q['rate_currency'])) ?>
                          </span>
                    <?php endif; endif; ?>

                    <?php if (!empty($h['stage_changed_at'])): ?>
                      <span class="muted small">· <?= e(substr((string)$h['stage_changed_at'], 0, 10)) ?></span>
                    <?php endif; ?>

                    <?php if (!empty($h['notes'])): ?>
                      <div class="muted small"><?= e($h['notes']) ?></div>
                    <?php endif; ?>

                    <?php if (can_edit() && $h['stage'] !== 'hired'): ?>
                      <details class="inline-actions">
                        <summary>Update</summary>

                        <form method="post" class="inline-form">
                          <select name="stage">
                            <?php foreach (HIRE_STAGES as $k => $lbl):
                              if ($k === 'hired') continue; ?>
                              <option value="<?= e($k) ?>" <?= $h['stage'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <input name="note" placeholder="What happened (optional)">
                          <button type="submit" class="secondary" name="action" value="stage:<?= (int)$h['id'] ?>">Move stage</button>
                        </form>

                        <?php if (can_see_money()): $q = $quotes[(int)$h['id']] ?? null; ?>
                          <form method="post" class="inline-form">
                            <input name="quoted_rate" inputmode="decimal" placeholder="They ask"
                                   value="<?= e($q && $q['quoted_rate'] !== null ? fmt_num((float)$q['quoted_rate'], 2) : '') ?>">
                            <input name="offered_rate" inputmode="decimal" placeholder="We offer"
                                   value="<?= e($q && $q['offered_rate'] !== null ? fmt_num((float)$q['offered_rate'], 2) : '') ?>">
                            <select name="rate_unit">
                              <?php foreach (['day' => 'per day', 'hour' => 'per hour', 'flat' => 'flat fee'] as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($q['rate_unit'] ?? 'day') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                              <?php endforeach; ?>
                            </select>
                            <input name="rate_currency" maxlength="3" style="text-transform:uppercase;width:5em"
                                   value="<?= e($q['rate_currency'] ?? 'USD') ?>">
                            <button type="submit" class="secondary" name="action" value="quote:<?= (int)$h['id'] ?>">Save quote</button>
                          </form>
                          <p class="muted small">
                            Administrators only, stored in the separate finance database. Carried onto
                            their pay terms automatically when they are hired.
                          </p>
                        <?php endif; ?>

                        <form method="post" class="stack"
                              onsubmit="return confirm('Hire <?= e(addslashes($h['candidate_name'])) ?> into this slot? Everything already planned for the slot becomes theirs.');">
                          <div class="row">
                            <label>Their name as it should appear
                              <input name="real_name" value="<?= e($h['candidate_name']) ?>" required></label>
                            <label>Email <input type="email" name="email" value="<?= e($h['email'] ?? '') ?>"></label>
                          </div>
                          <div class="row">
                            <label>Starts <input type="date" name="start_date"></label>
                            <label>Finishes <input type="date" name="end_date"></label>
                          </div>
                          <div class="actions">
                            <button type="submit" name="action" value="hire:<?= (int)$h['id'] ?>">Hire into this slot</button>
                          </div>
                        </form>
                      </details>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
          </tr>
        </table>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if ($unattached): ?>
      <h3>Not tied to a slot</h3>
      <p class="muted small">
        People worth remembering who aren't attached to a particular job yet. Editing one lets you
        point it at a slot.
      </p>
      <table class="review-table">
        <tr><th>Who</th><th>Stage</th><th>Where from</th><th>Notes</th></tr>
        <?php foreach ($unattached as $h): $c = hire_stage_color($h['stage']); ?>
          <tr>
            <td><strong><?= e($h['candidate_name']) ?></strong>
              <?php if (!empty($h['email'])): ?><div class="muted small"><?= e($h['email']) ?></div><?php endif; ?>
            </td>
            <td><span class="status-chip"
                      style="background:<?= e($c) ?>1a;color:<?= e($c) ?>;border:1px solid <?= e($c) ?>44;">
                  <?= e(hire_stage_label($h['stage'])) ?></span></td>
            <td class="muted small"><?= e($h['source'] ?: '—') ?></td>
            <td class="muted small"><?= e($h['notes'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

  <?php endif; ?>

  <?php // ---- who is on the show, and until when -------------------------- ?>
  <?php if ($roster): ?>
    <h3>Who is on the show</h3>
    <p class="muted small">
      When each person starts and finishes on this project. Recorded per show, not per person —
      the same compositor can be on this one from September and on the next from January.
      <strong>The scheduler does not read these dates yet</strong>: they are a record, and where
      the plan asks for work outside somebody's dates their person page says so.
    </p>
    <table class="review-table">
      <tr><th>Who</th><th>Role</th><th>Starts</th><th>Finishes</th>
        <?php if (can_see_money()): ?><th>Contract</th><?php endif; ?>
        <th></th></tr>
      <?php foreach ($roster as $p): $ct = $contracts[(int)$p['id']] ?? null; ?>
        <tr>
          <td><a href="person.php?project=<?= $projectId ?>&crew=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a>
            <?php if (!$p['active']): ?><span class="muted small">(inactive)</span><?php endif; ?></td>
          <td class="muted small"><?= e($p['role'] ?: '—') ?></td>
          <td class="muted small"><?= e($p['start_date'] ?: '—') ?></td>
          <td class="muted small"><?= e($p['end_date'] ?: '—') ?></td>
          <?php if (can_see_money()): ?>
            <td class="small">
              <?php if ($ct === null): ?>
                <span class="muted">nothing on file</span>
              <?php else:
                $waiting = contract_days_waiting($ct);
                $sig = $ct['contract_status'] ?? 'none'; ?>
                <span class="<?= $sig === 'signed' ? 'ok' : ($sig === 'declined' ? 'err' : 'warn-text') ?>">
                  <?= e(ucfirst(contract_state_phrase($sig, $ct['contract_sent_date'] ?? null,
                                                      $ct['contract_signed_date'] ?? null))) ?>
                </span>
                <?php if ($waiting !== null && $waiting > 0): ?>
                  <div class="muted small"><?= (int)$waiting ?> day<?= $waiting === 1 ? '' : 's' ?> waiting</div>
                <?php endif; ?>
                <?php if (!empty($ct['contract_url'])): ?>
                  <div><a href="<?= e($ct['contract_url']) ?>" target="_blank" rel="noopener">open →</a></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td>
            <?php if (can_edit()): ?>
              <details class="inline-actions">
                <summary>Edit</summary>
                <form method="post" class="inline-form">
                  <label class="muted small">Starts <input type="date" name="start_date" value="<?= e($p['start_date'] ?? '') ?>"></label>
                  <label class="muted small">Finishes <input type="date" name="end_date" value="<?= e($p['end_date'] ?? '') ?>"></label>
                  <button type="submit" class="secondary" name="action" value="dates:<?= (int)$p['id'] ?>">Save dates</button>
                </form>
                <?php if (can_see_money() && !$needsFinanceUpgrade && $financeError === null): ?>
                  <form method="post" class="inline-form">
                    <select name="contract_status">
                      <?php foreach (CONTRACT_STATES as $s): ?>
                        <option value="<?= e($s) ?>" <?= ($ct['contract_status'] ?? 'none') === $s ? 'selected' : '' ?>>
                          <?= e(ucfirst($s === 'none' ? 'no contract yet' : $s)) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="secondary" name="action" value="contract:<?= (int)$p['id'] ?>">Save contract</button>
                  </form>
                  <p class="muted small">
                    Marking it sent or signed fills in today's date if you leave it blank. The rate
                    is untouched — set that on their person page.
                  </p>
                <?php endif; ?>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <?php if (can_see_money() && !$needsFinanceUpgrade && $financeError === null):
      $unsigned = [];
      foreach ($roster as $p) {
          $ct = $contracts[(int)$p['id']] ?? null;
          if ($ct === null || ($ct['contract_status'] ?? 'none') !== 'signed') $unsigned[] = $p;
      }
      if ($unsigned): ?>
        <div class="flash flash-warn">
          <strong><?= count($unsigned) ?></strong> of <?= count($roster) ?> people on this show
          <?= count($unsigned) === 1 ? 'has' : 'have' ?> no signed contract on file:
          <?= e(implode(', ', array_map(function ($p) { return $p['name']; }, $unsigned))) ?>.
        </div>
      <?php else: ?>
        <p class="muted small ok">Everybody on the show has a signed contract on file.</p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <p class="muted small">
    Hiring somebody <strong>renames the slot</strong> rather than creating a person: the crew
    record keeps its id, so every scheduled task and every progress record already pointing at that
    slot becomes theirs with nothing moved. Records of people who declined are kept — the one who
    said no is exactly the record worth having when the other falls through.
  </p>

<?php endif; ?>

</div>
</body></html>
