<?php
// Home — one person's work, contract and invoice information, across every show.
//
// David, 2026-10-28: the one screen an artist opens. Today they land on My
// tasks and open statement.php one show at a time; this puts the lot on one
// page: their shows with dates and days left, who supervises them, what they
// have logged and which days they have not, their notes and versions in Spell,
// what is due next, and — for a freelancer — their contract, what goes on this
// month's invoice and which invoices have been received and paid.
//
// ⚠ READ-ONLY, AND MY TASKS STAYS WHERE THE EDITING IS. Nothing here posts.
// Every task links to Spell or My tasks; logging time links to My tasks. Two
// screens that both change a status are two screens that can disagree.
//
// ⚠ THE CREW ID IS NOT A PARAMETER, FOR ANYBODY. Unlike statement.php there
// is no admin override: Home is "mine". It is always effective_crew_id(), so an
// admin viewing as a person (David, 2026-10-21) sees that person's Home, and
// nobody can ask for somebody else's. The money comes through the own_*()
// doors in includes/money.php, which refuse any record but the viewer's own.
//
// Never shown, by design (David, 2026-10-28): budgets, other people's rates,
// cost forecasts, comparisons with other artists. None of it is even read.
//
// Data and rules live in includes/home.php.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/home.php';
require_once __DIR__ . '/includes/tickets.php';   // tickets_for_crew()
require_login();

$crewId = (int)(effective_crew_id($pdo) ?? 0);
$today = date('Y-m-d');

if ($crewId <= 0) {
    $pageTitle = 'Home';
    require __DIR__ . '/includes/header.php';
    echo '<h2 class="wizard-title">Home</h2><div class="empty-state"><p>Your login is not linked to a '
       . 'person on the crew, so there is nothing of yours to show yet. Ask an administrator to link it '
       . 'on the Users page.</p></div></div></body></html>';
    exit;
}

$cq = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
$cq->execute([$crewId]);
$personName = (string)($cq->fetchColumn() ?: 'You');

$salaried = home_is_salaried($pdo, $crewId);
$userIds = home_user_ids($pdo, $crewId);

// WHICH MONTH THE INVOICE INFORMATION IS FOR. This month by default; the
// previous one a click away, because an invoice for October is written in the
// first days of November. Only a well-formed YYYY-MM is read, and never a
// future month.
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > date('Y-m')) $month = date('Y-m');
$monthFrom = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthFrom));
$monthTo = $monthEnd < $today ? $monthEnd : $today;
$prevMonth = date('Y-m', strtotime($monthFrom . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthFrom . ' +1 month'));

// Their shows, each with dates, positions, supervisors and logged time.
$shows = [];
foreach (home_projects($pdo, $crewId) as $pid => $p) {
    $shows[$pid] = home_show($pdo, $p, $crewId, date('Y-m-01'), $today);
}
$windows = array_map(fn($s) => $s['window'], array_values($shows));

// MISSING LOG DAYS: last week and this week, up to yesterday. Today is still
// going on, so it is a reminder of its own rather than a day already missed.
$lookFrom = date('Y-m-d', strtotime('monday last week', strtotime($today)));
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$missing = home_missing_days($pdo, $crewId, $windows, $lookFrom, $yesterday);
$loggedToday = logged_total_for_crew($pdo, $crewId, null, $today, $today);
$weekFrom = date('Y-m-d', strtotime('monday this week', strtotime($today)));
$loggedWeek = logged_total_for_crew($pdo, $crewId, null, $weekFrom, $today);
$loggedMonth = logged_total_for_crew($pdo, $crewId, null, date('Y-m-01'), $today);

$tickets = [];
try {
    $tickets = tickets_for_crew($pdo, $crewId);
} catch (PDOException $e) {
    $tickets = [];   // tickets before migration 017
}
$next = home_whats_next(home_tasks($pdo, $crewId), $tickets, $today);
$notes = home_notes_waiting($pdo, $crewId, $userIds);
$versions = home_versions($pdo, $userIds);

// Money, for a freelancer only, and only their own.
$terms = null; $posTerms = []; $invoiceInfo = ['lines' => [], 'totals' => [], 'outside' => 0]; $invoices = [];
if (!$salaried) {
    $terms = own_crew_term($crewId);
    // Their positions with a contract of their own (finance_install_13):
    // [project id][step id] => row, their own only.
    $posTerms = own_position_terms($crewId);
    $invoiceInfo = home_invoice_lines($pdo, $crewId, $shows, $terms, $monthFrom, $monthTo, $posTerms);
    // One row per invoice they sent: a monthly invoice's lines folded together.
    $invoices = home_group_invoices($crewId, own_invoices_all($crewId));
}

// The supervisor's extra panel: a supervisor login, or anybody holding a
// supervision position on one of their shows.
$supervisesSomething = false;
foreach ($shows as $s) if ($s['supervises']) $supervisesSomething = true;
$team = [];
if (is_supervisor() || $supervisesSomething) {
    $teamFrom = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    if ($teamFrom > $yesterday) $teamFrom = $lookFrom;   // a Monday: look at last week
    $team = home_team($pdo, $crewId, $shows, is_supervisor(), $teamFrom, $yesterday);
}

$dateFmt = fn(?string $d) => $d ? date('j M Y', strtotime($d)) : '—';
$shortDate = fn(?string $d) => $d ? date('D j M', strtotime($d)) : '—';
$projectNames = [];
foreach ($shows as $pid => $s) $projectNames[$pid] = (string)$s['project']['name'];

$pageTitle = 'Home';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Home <span class="muted">· <?= e($personName) ?></span>
  <a class="button secondary statement-link" href="mytasks.php">My tasks</a>
</h2>

<?php // ---- Missing log days: first, because it is the one thing asked of them every day. ?>
<?php if ($missing): ?>
  <div class="flash flash-warn home-missing">
    You haven't logged <strong><?= e(home_days_phrase($missing, $today)) ?></strong>.
    <a href="mytasks.php">Log your time on My tasks →</a>
  </div>
<?php elseif ($loggedToday <= 0 && is_working_day($today)): ?>
  <p class="muted small">Nothing logged today yet. <a href="mytasks.php">Log your time on My tasks →</a></p>
<?php endif; ?>

<div class="dash-strip">
  <div><span class="muted small">Logged today</span><strong><?= e(fmt_hours($loggedToday)) ?></strong></div>
  <div><span class="muted small">This week</span><strong><?= e(fmt_hours($loggedWeek)) ?></strong></div>
  <div><span class="muted small">This month</span><strong><?= e(fmt_hours($loggedMonth)) ?></strong></div>
  <div class="<?= $next['overdue'] ? 'tile-pending' : '' ?>"><span class="muted small">Overdue</span><strong><?= count($next['overdue']) ?></strong></div>
  <div class="<?= $notes ? 'tile-pending' : '' ?>"><span class="muted small">Notes waiting on you</span><strong><?= count($notes) ?></strong></div>
</div>

<?php // ---- Their shows ---- ?>
<h3>Your shows</h3>
<?php if (!$shows): ?>
  <div class="empty-state"><p>You are not on any show at the moment.</p></div>
<?php else: ?>
  <table class="review-table home-shows">
    <thead><tr>
      <th>Show</th><th>Your position</th><th>Dates</th><th class="num">Days left</th>
      <th>Supervisor</th><th class="num">Logged</th><th>References</th>
    </tr></thead>
    <tbody>
      <?php foreach ($shows as $pid => $s): $w = $s['window']; ?>
        <tr>
          <td><strong><?= e($s['project']['name']) ?></strong>
            <?php if (($s['project']['status'] ?? '') === 'on_hold'): ?><span class="muted small">· on hold</span><?php endif; ?></td>
          <td><?= $s['positions'] ? e(implode(', ', $s['positions'])) : '<span class="muted">—</span>' ?></td>
          <td class="small">
            <?= e($dateFmt($w['from'])) ?> – <?= e($dateFmt($w['to'])) ?>
            <?php if ($w['source'] === 'department'): ?>
              <div class="muted small">from your steps' dates; no contract dates yet</div>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ($s['days_left'] === null): ?><span class="muted">—</span>
            <?php elseif ($s['days_left'] === 0 && $w['to'] < $today): ?><span class="muted">ended</span>
            <?php else: ?><?= (int)$s['days_left'] ?><?php endif; ?>
          </td>
          <td class="small">
            <?php if ($s['supervisors']): ?>
              <?php foreach ($s['supervisors'] as $sup): ?>
                <div><?= e($sup['name']) ?> <span class="muted">· <?= e($sup['title']) ?></span></div>
              <?php endforeach; ?>
            <?php else: ?><span class="muted">Ask Production</span><?php endif; ?>
          </td>
          <td class="num"><?= e(fmt_hours($s['minutes'])) ?>
            <?php if ($s['minutes'] > 0 && $s['hours_per_day'] > 0): ?>
              <div class="muted small"><?= e(fmt_num($s['minutes'] / 60 / $s['hours_per_day'], 1)) ?> days</div>
            <?php endif; ?>
          </td>
          <td class="small home-refs">
            <a href="spell.php?project=<?= (int)$pid ?>">Spell</a>
            · <a href="brief.php?project=<?= (int)$pid ?>">Brief</a>
            · <a href="standards.php?project=<?= (int)$pid ?>">Standards</a>
            <?php if (!$salaried): ?>· <a href="statement.php?project=<?= (int)$pid ?>">Statement</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">Days left are working days to the end of your dates, today included. Questions about a
    show go to its supervisor, or to Production when it has none.</p>
<?php endif; ?>

<?php // ---- Contract: a freelancer's own terms, never anybody else's. ---- ?>
<?php if (!$salaried): ?>
  <?php // ONE ROW PER CONTRACT (David, 2026-10-29): a position with its own
        // contract on a show (finance_install_13) on a row of its own, and the
        // person's default for everything else. Their own only, by construction. ?>
  <?php
  $contracts = [];
  foreach ($posTerms as $cpid => $bySid) {
      if (!isset($shows[$cpid])) continue;           // a finished show
      foreach ($bySid as $csid => $pt) {
          $contracts[] = ['label' => $shows[$cpid]['project']['name'] . ' · ' . ($pt['position_name'] ?: 'Position'),
                          'term' => $pt, 'pids' => [(int)$cpid]];
      }
  }
  if ($terms) {
      $contracts[] = ['label' => $contracts ? 'All your other positions' : 'All your positions',
                      'term' => $terms, 'pids' => array_keys($shows)];
  }
  ?>
  <h3><?= count($contracts) > 1 ? 'Your contracts' : 'Your contract' ?></h3>
  <?php if (!$contracts): ?>
    <p class="muted">No rate is on file for you yet. Production will add it.</p>
  <?php else: ?>
    <table class="review-table statement-due">
      <thead><tr><th>For</th><th>Rate</th><th>Contract</th></tr></thead>
      <tbody>
        <?php foreach ($contracts as $c):
          $ct = $c['term'];
          $cUnit = (string)($ct['rate_unit'] ?? 'day');
          $cCur = strtoupper((string)($ct['rate_currency'] ?? 'USD')) ?: 'USD'; ?>
          <tr>
            <td><?= e($c['label']) ?></td>
            <td>
              <?php if ($ct['day_rate'] === null): ?>
                <span class="muted">no rate yet</span>
              <?php else: ?>
                <strong><?= e(home_rate_phrase((float)$ct['day_rate'], $cUnit, $cCur)) ?></strong>
                <?php // A contract in another currency is converted at ONE rate fixed
                      // per show (2026-10-28), so its dollar figure is per show. ?>
                <?php if ($cCur !== 'USD'): ?>
                  <?php foreach ($c['pids'] as $pid):
                    $fx = home_fx($crewId, (int)$pid);
                    $usd = function_exists('fx_to_usd') ? fx_to_usd((float)$ct['day_rate'], $cCur, $fx) : null; ?>
                    <div class="muted small"><?= e($shows[$pid]['project']['name']) ?>:
                      <?= $usd !== null ? e(home_rate_phrase((float)$ct['day_rate'], $cUnit, $cCur, $usd))
                                        : 'no exchange rate fixed for this show yet' ?></div>
                  <?php endforeach; ?>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="small"><?= !empty($ct['contract_status']) && $ct['contract_status'] !== 'none'
                ? e(contract_state_phrase($ct['contract_status'], $ct['contract_sent_date'] ?? null, $ct['contract_signed_date'] ?? null))
                : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php // ---- What's next ---- ?>
<h3>What's next</h3>
<?php
$nextGroups = [
    'overdue' => ['Overdue', $next['overdue']],
    'week'    => ['Due this week', $next['week']],
    'later'   => ['Lined up after', array_slice($next['later'], 0, 12)],
];
$anyNext = $next['overdue'] || $next['week'] || $next['later'];
?>
<?php if (!$anyNext): ?>
  <p class="muted">Nothing open on your list.</p>
<?php else: ?>
  <?php foreach ($nextGroups as $key => [$label, $rows]): if (!$rows) continue; ?>
    <h4 class="<?= $key === 'overdue' ? 'warn-text' : '' ?>"><?= e($label) ?></h4>
    <table class="review-table">
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small" style="width:7em;"><?= e($shortDate($r['due'])) ?></td>
            <td class="muted small"><?= e($r['project'] ?: '—') ?></td>
            <td><a href="<?= e($r['href']) ?>"><?= e($r['label'] ?: '—') ?></a></td>
            <td class="muted small"><?= e($r['status'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
  <?php if (count($next['later']) > 12): ?>
    <p class="muted small"><?= count($next['later']) - 12 ?> more on <a href="mytasks.php">My tasks</a>.</p>
  <?php endif; ?>
<?php endif; ?>

<?php // ---- Notes waiting on them ---- ?>
<h3>Notes waiting on you</h3>
<?php if (!$notes): ?>
  <p class="muted">Nothing waiting. Every note on your tasks has had a reply or a new version.</p>
<?php else: ?>
  <table class="review-table">
    <tbody>
      <?php foreach ($notes as $n): ?>
        <tr>
          <td class="muted small"><?= e($n['project_name']) ?></td>
          <td><a href="<?= e(home_task_path((int)$n['project_id'], (int)$n['step_id'], (string)$n['item_key'])) ?>">
              <?= e(trim(home_item_label($n) . ' · ' . $n['step_name'], ' ·')) ?></a>
            <?php if ($n['is_retake']): ?><span class="warn-text small">· sent back (<?= e($n['status_name']) ?>)</span><?php endif; ?>
            <?php if ($n['is_review']): ?><span class="muted small">· review note</span><?php endif; ?>
          </td>
          <td class="small">
            <?php if ($n['count'] > 0): ?>
              <?= e((string)($n['author'] ?: 'Somebody')) ?>:
              <span class="muted"><?= e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$n['body'])), 0, 120, '…')) ?></span>
              <?php if ($n['count'] > 1): ?><span class="muted">(+<?= (int)$n['count'] - 1 ?> more)</span><?php endif; ?>
            <?php else: ?><span class="muted">No note yet: ask your supervisor what to change.</span><?php endif; ?>
          </td>
          <td class="muted small"><?= e($n['created_at'] ? $shortDate((string)$n['created_at']) : '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">A note counts as answered once you reply on the task or put up a new version.</p>
<?php endif; ?>

<?php // ---- Latest versions in Spell ---- ?>
<?php if ($versions): ?>
  <h3>Your latest versions</h3>
  <table class="review-table">
    <tbody>
      <?php foreach ($versions as $v): ?>
        <tr>
          <td class="muted small"><?= e($v['project_name']) ?></td>
          <td><a href="<?= e(home_task_path((int)$v['project_id'], (int)$v['step_id'], (string)$v['item_key'])) ?>">
              <?= e(trim(home_item_label($v) . ' · ' . $v['step_name'], ' ·')) ?></a></td>
          <td>v<?= (int)$v['version_no'] ?> <span class="muted small"><?= e($v['original_name']) ?></span></td>
          <td class="muted small"><?= e($shortDate((string)$v['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php // ---- Invoice information: what goes on the month's invoice. NOT an invoice. ---- ?>
<?php if (!$salaried): ?>
  <h3>Invoice information · <?= e(date('F Y', strtotime($monthFrom))) ?></h3>
  <p class="small">
    <a href="home.php?month=<?= e($prevMonth) ?>">← <?= e(date('F', strtotime($prevMonth . '-01'))) ?></a>
    <?php if ($nextMonth <= date('Y-m')): ?>
      · <a href="home.php?month=<?= e($nextMonth) ?>"><?= e(date('F', strtotime($nextMonth . '-01'))) ?> →</a>
    <?php endif; ?>
  </p>
  <?php // HOW TO SEND IT, in Tim's words (relayed by David, 2026-10-28): "Send one
        // invoice a month to accounting@mana.tv, with one line per project.
        // Nowhere else. Jorik pays it once." Said plainly, because an invoice sent
        // to a producer or a supervisor as well is one that can be paid twice. ?>
  <div class="flash flash-ok home-howto">
    <strong>How to invoice:</strong> send <strong>one invoice a month</strong>, with
    <strong>one line per project</strong>, to <a href="mailto:accounting@mana.tv">accounting@mana.tv</a>
    and nowhere else (not to producers or supervisors). Jorik pays it once.
  </div>
  <p class="muted small">Below is what to put on your invoice for this month. It is information for
    your own invoice, not an invoice.
    <?= $monthTo < $monthEnd ? 'The month is not over yet: these figures count up to today.' : '' ?></p>
  <?php if (!$invoiceInfo['lines']): ?>
    <p class="muted">No time logged on your shows in <?= e(date('F', strtotime($monthFrom))) ?>.</p>
  <?php else: ?>
    <table class="review-table home-invoice">
      <thead><tr><th>Project</th><th>Step</th><th>Rate</th><th class="num">Days / units</th><th class="num">Line total</th></tr></thead>
      <tbody>
        <?php foreach ($invoiceInfo['lines'] as $l): ?>
          <tr>
            <td><?= e($l['project']['name']) ?></td>
            <td><?= e($l['step']) ?><?= !empty($l['own_contract']) ? ' <span class="muted small">· own contract</span>' : '' ?></td>
            <td><?= e($l['rate_phrase']) ?></td>
            <td class="num"><?= e($l['qty_label']) ?></td>
            <td class="num">
              <?php if ($l['total'] !== null): ?>
                <strong><?= e(home_money($l['total'], $l['currency'])) ?></strong>
                <?php if ($l['total_own'] !== null): ?><div class="muted small">(<?= e(home_money($l['total_own'], $l['own_currency'])) ?>)</div><?php endif; ?>
              <?php elseif ($l['rate'] === null): ?><span class="muted">no rate on file</span>
              <?php else: ?><span class="muted small">see the <a href="statement.php?project=<?= (int)$l['project']['id'] ?>">statement</a></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($invoiceInfo['totals']): ?>
        <tfoot>
          <?php foreach ($invoiceInfo['totals'] as $cur => $amt): ?>
            <tr class="statement-left"><th colspan="4">Month total<?= count($invoiceInfo['totals']) > 1 ? ' in ' . e($cur) : '' ?></th>
              <td class="num"><strong><?= e(home_money($amt, $cur)) ?></strong></td></tr>
          <?php endforeach; ?>
        </tfoot>
      <?php endif; ?>
    </table>
    <?php if (count($invoiceInfo['totals']) > 1): ?>
      <p class="warn-text small">These lines are in more than one currency: a show has no exchange rate fixed
        for your contract yet. Ask Production before you send the invoice.</p>
    <?php endif; ?>
    <?php if ($terms && in_array((string)($terms['rate_unit'] ?? ''), ['second', 'flat'], true)): ?>
      <p class="muted small">Your rate is <?= ($terms['rate_unit'] ?? '') === 'flat' ? 'a flat fee' : 'per approved second' ?>,
        which is not counted from logged time: each show's statement says what is due.</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($invoiceInfo['outside'] > 0): ?>
    <p class="warn-text small"><?= e(fmt_hours((int)$invoiceInfo['outside'])) ?> logged this month fall outside your
      dates on a show and are not counted above. Ask Production if your dates need changing.</p>
  <?php endif; ?>
  <p class="muted small">Days are your logged hours divided by the show's working day. Studio time (meetings,
    desk time) is not on a show and is not listed here.</p>

  <h3>Your invoices</h3>
  <?php if (!$invoices): ?>
    <p class="muted">No invoices on file yet.</p>
  <?php else: ?>
    <table class="review-table">
      <thead><tr><th>Project</th><th>Reference</th><th>Period</th><th class="num">Amount</th><th>Status</th><th>Dated</th><th>Paid on</th></tr></thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><?= e(implode(', ', array_map(fn($pid, $code) => $projectNames[$pid] ?? ($code !== '' ? $code : '—'),
                    array_keys($inv['projects']), $inv['projects'])) ?: '—') ?></td>
            <td><?= e((string)($inv['invoice_ref'] ?? '') ?: '—') ?></td>
            <td class="muted small"><?= e($dateFmt($inv['period_start'] ?? null)) ?> – <?= e($dateFmt($inv['period_end'] ?? null)) ?></td>
            <td class="num"><?= e(implode(' + ', array_map(fn($c, $a) => home_money((float)$a, (string)$c),
                    array_keys($inv['amounts']), $inv['amounts']))) ?></td>
            <td class="<?= ($inv['status'] ?? '') === 'paid' ? '' : 'muted' ?>"><?= e(home_invoice_status($inv)) ?></td>
            <td class="muted small"><?= e($dateFmt($inv['issued_date'] ?? null)) ?></td>
            <td class="small"><?= e($dateFmt($inv['paid_date'] ?? null)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php // ---- A supervisor's team: missing log days and notes waiting. No money. ---- ?>
<?php if ($team): ?>
  <h3>Your team</h3>
  <p class="muted small">Everybody on the shows you supervise. Missing days are working days this week
    (last week on a Monday) with nothing logged, leaving out days off.</p>
  <table class="review-table">
    <thead><tr><th>Person</th><th>Shows</th><th>Not logged</th><th class="num">Notes waiting</th></tr></thead>
    <tbody>
      <?php foreach ($team as $m): ?>
        <tr>
          <td><?= e($m['name']) ?></td>
          <td class="muted small"><?= e(implode(', ', $m['shows'])) ?></td>
          <td class="<?= $m['missing'] ? 'warn-text' : 'muted' ?> small">
            <?= $m['missing'] ? e(home_days_phrase($m['missing'], $today)) : 'All logged' ?></td>
          <td class="num"><?= $m['notes'] > 0 ? (int)$m['notes'] : '<span class="muted">0</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

</div></body></html>
