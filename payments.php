<?php
// Invoice payments — every invoice waiting to be paid, on every show, in one
// list, so Jorik can check each one and pay it. ADMIN ONLY.
//
// David, 2026-11-11: "let's create an invoice payment tab/button at the top
// next to reviews for Jorik. It should list all the pending invoices, access to
// the pdfs, amounts, positions, names, dates and status so Jorik can easily
// check them, pay each one of them ... and then that invoice disappears from the
// filter (don't delete them from that view, just filter all the pending
// invoices)". Reached from the "Invoices" button in the top bar, beside
// Reviews, which carries the waiting count.
//
// Nothing is entered here: invoices are recorded on a project's Invoices tab or
// in Monthly invoices (finance_invoice.php). This page only pays them, and
// links to where each one is edited. Data and rules: includes/payments.php.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/finance.php';   // invoice_status_label(), fmt_money_plain()
require_admin();

$who = current_user_display();
$view = (string)($_GET['view'] ?? 'waiting');
if (!isset(PAYMENT_VIEWS[$view])) $view = 'waiting';
$selfUrl = 'payments.php' . ($view !== 'waiting' ? '?view=' . $view : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        $kind = (string)($_POST['kind'] ?? '') === 'monthly' ? 'monthly' : 'single';
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('No invoice was chosen.');
        if (isset($_POST['mark_paid'])) {
            flash('ok', payments_mark_paid($kind, $id, $_POST['paid_on'] ?? '', $who));
        } elseif (isset($_POST['mark_unpaid'])) {
            flash('ok', payments_mark_unpaid($kind, $id, $who));
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$configured = finance_configured();
$rows = []; $counts = ['waiting' => 0, 'expected' => 0, 'paid' => 0]; $names = [];
$loadError = null;
if ($configured) {
    try {
        $counts = payments_counts();
        $rows = payments_rows($view);
        $names = payments_project_names($pdo, $rows);
        // The page has just counted; keep the top bar's number in step with it.
        $_SESSION['payments_waiting'] = ['n' => $counts['waiting'], 'at' => time()];
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}

// What is waiting, in dollars, for the line under the title. A currency with no
// fixed rate is added up on its own rather than guessed.
$waitingTotal = [];
if ($view === 'waiting') {
    foreach ($rows as $r) {
        if ($r['usd'] !== null) {
            $waitingTotal['USD'] = round(($waitingTotal['USD'] ?? 0) + $r['usd'], 2);
        } else {
            foreach ($r['own'] as $c => $a) $waitingTotal[$c] = round(($waitingTotal[$c] ?? 0) + $a, 2);
        }
    }
}
$todayStr = date('Y-m-d');

/** "1 Nov 2026", or a dash. */
$day = fn($d) => $d ? date('j M Y', strtotime((string)$d)) : '—';

$pageTitle = 'Invoice payments';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Invoice payments
  <span class="muted">· <?= (int)$counts['waiting'] ?> waiting to be paid</span>
  <span class="admin-only-tag">administrators only</span></h2>
<p class="lead">
  Every invoice that has arrived and is waiting to be paid, on every show. Open the document, check
  it, and mark it paid: it leaves this list and moves to <em>Paid</em>, where it stays. A monthly
  invoice covering several shows is one row, and paying it pays every line.
</p>

<?php if (!$configured): ?>
  <div class="empty-state"><p><strong>The finance database isn't set up yet.</strong></p></div>
<?php elseif ($loadError !== null): ?>
  <div class="flash flash-err">The invoices could not be read: <?= e($loadError) ?></div>
<?php else: ?>

<p class="board-projects payments-views">
  <?php foreach (PAYMENT_VIEWS as $k => $label):
    $n = $k === 'all' ? array_sum($counts) : (int)$counts[$k]; ?>
    <a class="board-chip<?= $view === $k ? ' is-on' : '' ?>" href="payments.php<?= $k !== 'waiting' ? '?view=' . $k : '' ?>"><?= e($label) ?> (<?= $n ?>)</a>
  <?php endforeach; ?>
  <?php if ($view === 'waiting' && $waitingTotal): ?>
    <span class="muted small payments-total">Total waiting:
      <strong><?= e(implode(' + ', array_map(fn($c, $a) => fmt_money_plain($a, $c), array_keys($waitingTotal), $waitingTotal))) ?></strong></span>
  <?php endif; ?>
</p>
<?php if ($view === 'paid' && $counts['paid'] > count($rows)): ?>
  <p class="muted small">The <?= count($rows) ?> most recent payments of each kind are shown.</p>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty-state"><p><?= $view === 'waiting'
      ? 'Nothing is waiting to be paid.'
      : 'No invoices here.' ?></p></div>
<?php else: ?>
  <div class="table-scroll">
  <table class="payments-table">
    <thead>
      <tr><th>Who</th><th>Show · position</th><th>Invoice</th><th>Period</th>
          <th>Received</th><th>Due</th><th class="num">Amount</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $late = $r['state'] === 'waiting' && $r['due_date'] && $r['due_date'] < $todayStr;
      $editUrl = $r['kind'] === 'monthly'
          ? 'finance_invoice.php?id=' . $r['id']
          : 'finance_project.php?project=' . (int)$r['lines'][0]['project_id'] . '&tab=invoices'; ?>
      <tr class="<?= $late ? 'row-overdue' : '' ?>">
        <td>
          <?php if ($r['crew_id']): ?>
            <a href="person.php?id=<?= (int)$r['crew_id'] ?>"><?= e($r['name'] ?: '(no name)') ?></a>
          <?php else: ?><?= e($r['name'] ?: '(no name)') ?><?php endif; ?>
          <?php // Room for the invoice intake (DECISIONS 2026-10-28): an invoice
                // that arrived by email and needs a look says so here. ?>
          <?php if ($r['needs_review']): ?>
            <br><span class="warn-text small" title="<?= e($r['review_reason']) ?>">needs a look</span>
          <?php endif; ?>
        </td>
        <td class="small">
          <?php foreach ($r['lines'] as $l):
            $pn = $names[(int)$l['project_id']] ?? null;
            $code = $l['project_code'] ?: ($pn['code'] ?? 'Project ' . $l['project_id']); ?>
            <div><a href="finance_project.php?project=<?= (int)$l['project_id'] ?>&amp;tab=invoices"
                    title="<?= e($pn['name'] ?? '') ?>"><?= e($code) ?></a>
              <span class="muted">· <?= e($l['position'] ?: 'no particular position') ?></span>
              <?php if (count($r['lines']) > 1): ?>
                <span class="muted">· <?= e(fmt_money_plain($l['amount'], $l['currency'])) ?></span>
              <?php endif; ?></div>
          <?php endforeach; ?>
          <?php if (!$r['lines']): ?><span class="muted">no lines yet</span><?php endif; ?>
        </td>
        <td class="small">
          <?= e($r['invoice_ref'] ?: 'no number') ?>
          <?php if ($r['document_url'] !== ''): ?>
            <br><a href="<?= e($r['document_url']) ?>" target="_blank" rel="noopener">Open document ↗</a>
          <?php else: ?>
            <br><span class="muted">no document linked</span>
          <?php endif; ?>
          <?php if ($r['kind'] === 'monthly'): ?>
            <br><span class="muted"><?= e(date('F Y', strtotime($r['month'] . '-01'))) ?> invoice</span>
          <?php endif; ?>
        </td>
        <td class="muted small"><?= e($r['period_start'] || $r['period_end']
              ? $day($r['period_start']) . ' → ' . $day($r['period_end']) : '—') ?></td>
        <td class="muted small"><?= e($day($r['issued_date'])) ?></td>
        <td class="small <?= $late ? 'err' : 'muted' ?>"><?= e($day($r['due_date'])) ?><?= $late ? '<br>late' : '' ?></td>
        <?php // Dollars first; a non-USD contract also shows its own money,
              // converted at the show's fixed rate (2026-10-28). A currency with
              // no fixed rate is shown as it is, and says so. ?>
        <td class="num">
          <?php $ownNonUsd = array_filter($r['own'], fn($c) => $c !== 'USD', ARRAY_FILTER_USE_KEY); ?>
          <?php if ($r['usd'] !== null): ?>
            <strong><?= e(fmt_money_plain($r['usd'], 'USD')) ?></strong>
            <?php foreach ($ownNonUsd as $c => $a): ?>
              <br><span class="muted small">(<?= e(fmt_money_plain($a, $c)) ?>)</span>
            <?php endforeach; ?>
          <?php else: ?>
            <strong><?= e(implode(' + ', array_map(fn($c, $a) => fmt_money_plain($a, $c), array_keys($r['own']), $r['own']))) ?></strong>
            <br><span class="warn-text small" title="The show has no exchange rate fixed for this currency">no fixed rate</span>
          <?php endif; ?>
        </td>
        <td class="small">
          <?php if ($r['state'] === 'paid'): ?>
            <span class="ok">Paid <?= e($day($r['paid_date'])) ?></span>
          <?php elseif ($r['state'] === 'expected'): ?>
            <span class="muted">Expected</span>
          <?php else: ?>
            <span class="warn-text"><?= e(invoice_status_label($r['status'])) ?></span>
          <?php endif; ?>
          <?php if ($r['notes'] !== ''): ?>
            <br><span class="muted payments-note" title="<?= e($r['notes']) ?>"><?= e(mb_strimwidth($r['notes'], 0, 60, '…')) ?></span>
          <?php endif; ?>
        </td>
        <td class="row-actions">
          <?php if ($r['state'] === 'waiting'): ?>
            <form method="post" class="payments-pay">
              <input type="hidden" name="kind" value="<?= e($r['kind']) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="date" name="paid_on" value="<?= e($todayStr) ?>" max="<?= e($todayStr) ?>"
                     aria-label="Paid on" title="The day it was paid">
              <button type="submit" name="mark_paid" value="1" class="row-btn row-btn-go"
                      <?= $r['kind'] === 'monthly' && count($r['lines']) > 1
                          ? 'title="Pays the invoice and every line of it, on every show"' : '' ?>>Mark paid</button>
            </form>
          <?php elseif ($r['state'] === 'paid'): ?>
            <form method="post" class="payments-pay"
                  onsubmit="return confirm('Set this invoice back to waiting to be paid? Its paid date is cleared.');">
              <input type="hidden" name="kind" value="<?= e($r['kind']) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" name="mark_unpaid" value="1" class="linkish small">Not paid after all</button>
            </form>
          <?php endif; ?>
          <a class="small" href="<?= e($editUrl) ?>"><?= $r['kind'] === 'monthly' ? 'Open the invoice' : 'Edit on the show' ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<p class="muted small">
  Invoices are entered on a show's <em>Finance management → Invoices</em> tab, or in
  <a href="finance_invoice.php">Monthly invoices</a> for one invoice covering several shows.
  <em>Expected</em> means due from somebody but not arrived yet, so there is nothing to pay.
</p>

<?php endif; ?>

</div></body></html>
