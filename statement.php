<?php
// One artist's statement on one show: what to invoice, and why.
//
// David, 2026-10-26. Production opens it from Finance management → Artist
// statements for anybody; an artist opens their own from My tasks ("Artists see
// it") and can open nobody else's — the crew id is forced to their own, and the
// money is read through own_crew_term() / own_invoices(), which return nothing
// for anybody else's record (includes/money.php).
//
// Read-only. Every figure comes from includes/statements.php.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pipeline.php';
require_once __DIR__ . '/includes/statements.php';
require_once __DIR__ . '/includes/finance_nav.php';
require_login();

$admin  = can_see_money();
$myCrew = (int)(effective_crew_id($pdo) ?? 0);
// ⚠ NOT A PARAMETER FOR ANYBODY BUT AN ADMIN. An artist's statement is theirs:
// whatever ?crew= says, a non-admin is shown their own.
$crewId = $admin ? (int)($_GET['crew'] ?? $myCrew) : $myCrew;
$projectId = (int)($_GET['project'] ?? 0);

if ($crewId <= 0) {
    $pageTitle = 'Statement';
    require __DIR__ . '/includes/header.php';
    echo '<h2 class="wizard-title">Statement</h2><div class="empty-state"><p>Your login is not linked to a '
       . 'person on the crew, so there is no statement to show. Ask an administrator to link it on the '
       . 'Users page.</p></div></div></body></html>';
    exit;
}

$cq = $pdo->prepare('SELECT name FROM crew WHERE id = ?');
$cq->execute([$crewId]);
$personName = (string)($cq->fetchColumn() ?: 'Somebody');

// The shows this person has something on, for the picker and for a statement
// opened without a project.
$shows = [];
foreach ($pdo->query("SELECT id, name, code FROM projects WHERE status <> 'archived' ORDER BY name") as $p) {
    if (isset(statement_people($pdo, (int)$p['id'])[$crewId])) $shows[(int)$p['id']] = $p;
}
if (!$projectId || !isset($shows[$projectId])) {
    $projectId = $shows ? (int)array_key_first($shows) : 0;
}
$project = $projectId ? $shows[$projectId] : null;

$s = null;
if ($project) {
    $terms = own_crew_term($crewId);
    $invoices = own_invoices($crewId, $projectId);
    $sFx = own_project_fx_rates($crewId, $projectId);
    // A position with its own contract (finance_install_12, 2026-10-29) is
    // paid on its own line; read through the same own-or-admin door.
    $sPos = own_position_terms($crewId, $projectId)[$projectId] ?? [];
    $s = artist_statement($pdo, $projectId, $crewId, $terms, $invoices, null, $sFx, $sPos);
}

$money = fn(?float $v) => $v === null ? '—' : fmt_money_plain($v, $s['currency'] ?? 'USD');
$dateFmt = fn(?string $d) => $d ? date('j M Y', strtotime($d)) : '—';
$pageTitle = 'Statement · ' . $personName . ($project ? ' · ' . $project['name'] : '');
require __DIR__ . '/includes/header.php';

if ($admin && $project) {
    $pr = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
    $pr->execute([$projectId]);
    $full = $pr->fetch();
    if ($full) finance_nav($full, 'statements');
}
?>
<p class="crumb">
  <?php if ($admin && $project): ?>
    <a href="finance_project.php?project=<?= $projectId ?>&amp;tab=statements">← All artist statements</a>
  <?php else: ?>
    <a href="home.php">← Home</a>
  <?php endif; ?>
</p>

<h2 class="wizard-title">Statement <span class="muted">· <?= e($personName) ?></span></h2>

<?php if (!$project): ?>
  <div class="empty-state"><p>Nothing on any show yet: no position, no task and no logged time.</p></div>
<?php else: ?>

  <?php if (count($shows) > 1): ?>
    <form method="get" class="statement-pick">
      <?php if ($admin): ?><input type="hidden" name="crew" value="<?= $crewId ?>"><?php endif; ?>
      <label>Show
        <select name="project" onchange="this.form.submit()">
          <?php foreach ($shows as $id => $p): ?>
            <option value="<?= (int)$id ?>" <?= $id === $projectId ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  <?php else: ?>
    <p class="summary-line"><?= e($project['name']) ?></p>
  <?php endif; ?>

  <p class="statement-window">
    <?php if ($s['window']['from'] || $s['window']['to']): ?>
      Covers <strong><?= e($dateFmt($s['window']['from'])) ?></strong> to
      <strong><?= e($dateFmt($s['window']['to'])) ?></strong>,
      <?= $s['window']['source'] === 'contract'
            ? 'the contract dates on the show.'
            : 'the dates of the steps you are on (no contract dates are set yet).' ?>
    <?php else: ?>
      No contract dates and no dated steps yet, so this counts everything on the show so far.
    <?php endif; ?>
  </p>

  <div class="dash-strip statement-strip">
    <div><span class="muted small">Days planned</span><strong><?= e(fmt_num($s['planned_days'], 1)) ?></strong>
      <span class="muted small">from the expense projection</span></div>
    <div><span class="muted small">Time logged</span><strong><?= e(fmt_num($s['hours'], 1)) ?> h</strong>
      <span class="muted small"><?= e(fmt_num($s['days'], 2)) ?> days at <?= e(fmt_num($s['hours_per_day'], 1)) ?> h a day</span></div>
    <?php if ($s['planned_seconds'] > 0 || $s['approved_seconds'] > 0): ?>
      <div><span class="muted small">Seconds approved</span><strong><?= e(statement_secs($s['approved_seconds'])) ?></strong>
        <span class="muted small">of <?= e(statement_secs($s['planned_seconds'])) ?> planned</span></div>
    <?php endif; ?>
  </div>

  <h3>What to invoice</h3>
  <?php if ($s['rate'] === null && empty($s['lines'])): ?>
    <p class="muted">No rate is on file<?= $admin ? '' : ' for you' ?> on this show yet, so the figures above
      stand without an amount. <?= $admin ? 'Set it on the person page under Terms and contract.' : 'Production will add it.' ?></p>
  <?php else: ?>
    <?php // ONE LINE PER CONTRACT when a position has its own (2026-10-29):
          // Angelys's CH Layout on its rate, the rest on her default. ?>
    <?php if (!empty($s['lines'])): ?>
      <table class="review-table">
        <thead><tr><th>Position</th><th>Rate</th><th>Counted on</th><th class="num">Due so far</th></tr></thead>
        <tbody>
          <?php foreach ($s['lines'] as $l): ?>
            <tr>
              <td><?= e($l['position']) ?><?= $l['own_contract'] ? ' <span class="muted small">· own contract</span>' : '' ?></td>
              <td><?= $l['rate'] !== null ? e(fmt_money($l['rate'], $l['unit'], $l['rate_currency'])) : '<span class="muted">no rate yet</span>' ?>
                <?php if ($l['rate_usd'] !== null): ?><div class="muted small">= <?= e(number_format($l['rate_usd'], 2)) ?> USD</div><?php endif; ?></td>
              <td class="small">
                <?php if ($l['unit'] === 'flat'): ?>The flat fee
                <?php elseif ($l['unit'] === 'second'): ?><?= e(statement_secs($l['seconds'])) ?> of approved footage
                <?php elseif ($l['unit'] === 'hour'): ?><?= e(fmt_num($l['hours'], 2)) ?> logged hours
                <?php else: ?><?= e(fmt_num($l['days'], 2)) ?> days logged<?php endif; ?>
              </td>
              <td class="num"><?= e($l['due'] === null ? '—' : fmt_money_plain($l['due'], $l['currency'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($s['due'] === null): ?>
        <p class="warn-text small">These lines cannot be added into one amount yet: one has no rate, or they are
          in currencies with no exchange rate fixed for this show.</p>
      <?php endif; ?>
    <?php endif; ?>
    <table class="review-table statement-due">
      <tbody>
        <?php if (empty($s['lines'])): ?>
        <tr><th>Rate</th>
          <td><?= e(fmt_money($s['rate'], $s['unit'], $s['rate_currency'])) ?>
            <?php // A contract in another currency, converted at the one rate
                  // fixed for the show (2026-10-28): both, and where it came from. ?>
            <?php if ($s['fx'] && $s['rate_usd'] !== null): ?>
              <div class="muted small">= <?= e(number_format($s['rate_usd'], 2)) ?> USD, at the show's fixed
                rate (<?= e(fx_describe($s['fx'])) ?>). The amounts below are in US dollars.</div>
            <?php endif; ?></td></tr>
        <tr><th>Counted on</th>
          <td>
            <?php if ($s['unit'] === 'flat'): ?>
              The flat fee for the show
            <?php elseif ($s['unit'] === 'second'): ?>
              <?= e(statement_secs($s['approved_seconds'])) ?> of approved footage
            <?php elseif ($s['unit'] === 'hour'): ?>
              <?= e(fmt_num($s['hours'], 2)) ?> logged hours
            <?php else: ?>
              <?= e(fmt_num($s['days'], 2)) ?> days logged
            <?php endif; ?>
          </td></tr>
        <?php endif; ?>
        <tr><th>Due so far</th><td><strong><?= e($s['due_own'] !== null
              ? fmt_money_both($s['due'], $s['due_own'], $s['rate_currency']) : $money($s['due'])) ?></strong></td></tr>
        <tr><th>Invoiced</th><td><?= e($money($s['invoiced'])) ?></td></tr>
        <tr class="statement-left"><th>Left to invoice</th>
          <td><strong class="<?= ($s['left'] ?? 0) < 0 ? 'warn-text' : '' ?>"><?= e($money($s['left'])) ?></strong></td></tr>
      </tbody>
    </table>
    <?php if (($s['left'] ?? 0) < 0): ?>
      <p class="warn-text small">More has been invoiced than the logged work covers so far.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($s['hours_outside'] > 0): ?>
    <p class="warn-text small"><?= e(fmt_num($s['hours_outside'], 1)) ?> h logged on this show fall outside
      these dates and are not counted above.</p>
  <?php endif; ?>
  <?php if ($s['invoiced_other_currency']): ?>
    <p class="warn-text small">Some invoices are in a currency with no exchange rate fixed for this show,
      and are not in the Invoiced total.</p>
  <?php endif; ?>

  <?php if ($s['positions']): ?>
    <h3>Where the plan has you</h3>
    <table class="review-table">
      <thead><tr><th>Position</th><th class="num">Days planned</th><th class="num">Seconds planned</th></tr></thead>
      <tbody>
        <?php foreach ($s['positions'] as $p): ?>
          <tr><td><?= e($p['role']) ?></td>
            <td class="num"><?= e(fmt_num($p['days'], 1)) ?></td>
            <td class="num"><?= $p['seconds'] > 0 ? e(statement_secs($p['seconds'])) : '<span class="muted">—</span>' ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($s['invoices']): ?>
    <h3>Invoices on this show</h3>
    <table class="review-table">
      <thead><tr><th>Reference</th><th>Period</th><th class="num">Amount</th><th>Status</th><th>Paid</th></tr></thead>
      <tbody>
        <?php foreach ($s['invoices'] as $inv): ?>
          <tr>
            <td><?= e((string)($inv['invoice_ref'] ?? '') ?: '—') ?></td>
            <td class="muted small"><?= e($dateFmt($inv['period_start'] ?? null)) ?> – <?= e($dateFmt($inv['period_end'] ?? null)) ?></td>
            <?php $invUsd = ($inv['currency'] ?? 'USD') !== 'USD' && $s['currency'] === 'USD'
                      ? fx_to_usd((float)$inv['amount'], $inv['currency'], $sFx) : null; ?>
            <td class="num"><?= e($invUsd !== null
                  ? fmt_money_both($invUsd, (float)$inv['amount'], (string)$inv['currency'])
                  : fmt_money_plain((float)$inv['amount'], (string)($inv['currency'] ?? 'USD'))) ?></td>
            <td><?= e(ucfirst((string)($inv['status'] ?? ''))) ?></td>
            <td class="muted small"><?= e($dateFmt($inv['paid_date'] ?? null)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <p class="muted small statement-how">
    Time logged is what has been entered in Anima for this show between these dates; a day is
    <?= e(fmt_num($s['hours_per_day'], 1)) ?> hours on this show. Seconds count once a shot is
    <strong>approved</strong>, split evenly when two people share it. Days planned come from the
    show's expense projection.
  </p>
  <p><button type="button" class="secondary" onclick="window.print()">Print or save as PDF</button></p>
<?php endif; ?>
</div></body></html>
