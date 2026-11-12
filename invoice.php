<?php
// The invoice template: a freelancer's monthly invoice, filled in by Anima,
// ready to print to PDF and send to accounting@mana.tv (David, 2026-11-11).
//
// ⚠ WHOSE INVOICE. For everybody it is their own: effective_crew_id(), as on
// Home. Only an administrator may name somebody else with ?crew= (the link
// from Finance → Monthly invoices), and the money behind it still comes
// through the own-or-admin doors in includes/money.php, which refuse any
// record but the viewer's own to anybody else.
//
// ⚠ "INVOICE", NEVER "BILL" (David, 2026-10-28).
//
// The rules and the data live in includes/invoice_doc.php; the look on paper is
// assets/invoice.css.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/invoice_doc.php';
require_login();

$ownCrewId = (int)(effective_crew_id($pdo) ?? 0);
$crewId = (is_admin() && !empty($_GET['crew'])) ? (int)$_GET['crew'] : $ownCrewId;
$forOther = $crewId !== $ownCrewId;
$month = invoice_doc_month($_GET['month'] ?? null);
$selfUrl = 'invoice.php?month=' . $month . ($forOther ? '&crew=' . $crewId : '');

$person = null;
if ($crewId > 0) {
    $q = $pdo->prepare('SELECT id, name, email FROM crew WHERE id = ?');
    $q->execute([$crewId]);
    $person = $q->fetch() ?: null;
}
if (!$person) {
    $pageTitle = 'Invoice';
    require __DIR__ . '/includes/header.php';
    echo '<h2 class="wizard-title">Invoice</h2><div class="empty-state"><p>'
       . ($forOther ? 'That person is not on the crew list.'
                    : 'Your login is not linked to a person on the crew, so there is no invoice of yours to make. '
                      . 'Ask an administrator to link it on the Users page.')
       . '</p></div></div></body></html>';
    exit;
}
$personName = (string)$person['name'];
// Salaried staff never invoice (the money rules in CLAUDE.md).
$salaried = home_is_salaried($pdo, $crewId);

// --- save the details the invoice carries ---------------------------------------
// The crew id is the one worked out above from the login (or an admin's ?crew=),
// never anything the form sent. The allow-list entry in includes/auth.php is the
// first lock; save_own_invoice_details() going through money_own_db() is the second.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice_details'])) {
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        if ($salaried) throw new RuntimeException('Salaried staff do not send invoices.');
        flash('ok', save_own_invoice_details($crewId, $personName, $_POST, current_user_display()));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$info = ['lines' => [], 'totals' => [], 'outside' => 0, 'from' => $month . '-01',
         'to' => date('Y-m-t', strtotime($month . '-01')), 'counted_to' => date('Y-m-d')];
$problem = null;
$details = ['ready' => false, 'row' => []];
if (!$salaried) {
    if (function_exists('set_time_limit')) @set_time_limit(90);
    try {
        $info = invoice_doc_lines($pdo, $crewId, $month);
    } catch (Throwable $e) {
        error_log('[Anima invoice lines] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $problem = 'The lines could not be worked out: ' . $e->getMessage();
    }
    try {
        $details = own_invoice_details($crewId);
    } catch (Throwable $e) {
        error_log('[Anima invoice details] ' . $e->getMessage());
    }
}
$d = $details['row'];
$val = fn(string $k): string => trim((string)($d[$k] ?? ''));
$legalName = $val('legal_name') !== '' ? $val('legal_name') : $personName;
$email = $val('email') !== '' ? $val('email') : (string)($person['email'] ?? '');

$monthLabel = date('F Y', strtotime($info['from']));
$prevMonth = date('Y-m', strtotime($info['from'] . ' -1 month'));
$nextMonth = date('Y-m', strtotime($info['from'] . ' +1 month'));
$monthUrl = fn(string $m) => 'invoice.php?month=' . $m . ($forOther ? '&crew=' . $crewId : '');
$fmtDate = fn(string $x) => date('j M Y', strtotime($x));

// Every currency a line is in gets a total row, so an amount typed for a
// per-second or flat line has a total to join.
$totals = $info['totals'];
foreach ($info['lines'] as $l) if (!isset($totals[$l['currency']])) $totals[$l['currency']] = 0.0;
ksort($totals);
if (isset($totals['USD'])) $totals = ['USD' => $totals['USD']] + $totals;   // dollars first
$anyTyped = false;
foreach ($info['lines'] as $l) if ($l['total'] === null) $anyTyped = true;

$bankKeys = ['bank_name', 'account_holder', 'account_number', 'routing_number', 'swift_iban', 'clabe', 'cpf'];
$hasBank = false;
foreach (array_merge($bankKeys, ['holder_address']) as $k) if ($val($k) !== '') $hasBank = true;

$pageTitle = 'Invoice · ' . $personName . ' · ' . $monthLabel;
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/invoice.css<?= asset_ver('invoice.css') ?>">

<p class="crumb">
  <?php if ($forOther): ?>
    <a href="finance_invoice.php?month=<?= e($month) ?>&amp;crew=<?= (int)$crewId ?>">← Monthly invoices · <?= e($personName) ?></a>
  <?php else: ?>
    <a href="home.php?tab=invoice<?= $month !== date('Y-m') ? '&amp;month=' . e($month) : '' ?>">← Home · Invoice and billing</a>
  <?php endif; ?>
</p>
<h2 class="wizard-title"><?= $forOther ? e($personName) . ' · ' : 'Your ' ?>invoice for <?= e($monthLabel) ?></h2>

<?php if ($salaried): ?>
  <div class="empty-state"><p><?= $forOther ? e($personName) . ' is' : 'You are' ?> on a salary, and salaried
    staff do not send invoices.</p></div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<div class="inv-tools">
  <p class="small inv-month">
    <a href="<?= e($monthUrl($prevMonth)) ?>">← <?= e(date('F', strtotime($prevMonth . '-01'))) ?></a>
    · <strong><?= e($monthLabel) ?></strong>
    <?php if ($nextMonth <= date('Y-m')): ?>
      · <a href="<?= e($monthUrl($nextMonth)) ?>"><?= e(date('F', strtotime($nextMonth . '-01'))) ?> →</a>
    <?php endif; ?>
  </p>
  <div class="inv-actions">
    <button type="button" class="button primary" onclick="window.print()">Print or save as PDF</button>
    <span class="muted small">In the print window, choose <strong>Save as PDF</strong>, then email the PDF to
      <strong><?= e(HOME_INVOICE_TO) ?></strong><?php $cc = home_invoice_cc(); if ($cc): ?>, copying
      <?= e(implode(', ', $cc)) ?><?php endif; ?>. One invoice a month, sent nowhere else.</span>
  </div>

  <?php if ($problem): ?><div class="flash flash-err"><?= e($problem) ?></div><?php endif; ?>
  <?php if ($info['counted_to'] < $info['to']): ?>
    <p class="flash flash-warn small"><?= e(date('F', strtotime($info['from']))) ?> is not over yet: the lines count
      the days logged up to today. Send the invoice once the month has ended.</p>
  <?php endif; ?>
  <?php if ($info['outside'] > 0): ?>
    <p class="muted small"><?= e(fmt_num($info['outside'] / 60, 1)) ?> hours logged this month fall outside
      <?= $forOther ? 'their' : 'your' ?> dates on a show, so they are not on the invoice. Ask Production if that is wrong.</p>
  <?php endif; ?>
  <?php if ($anyTyped): ?>
    <p class="muted small">A line paid per second or as a flat fee has no amount from the logged days: type it in from
      the show's statement, and the total adds it up.</p>
  <?php endif; ?>

  <?php
  // The details Anima cannot know. Open when nothing is saved yet, so the
  // first visit starts here; folded away once they are filled in.
  $empty = !$d;
  ?>
  <details class="inv-details" <?= $empty ? 'open' : '' ?>>
    <summary><strong><?= $forOther ? e($personName) . "'s" : 'Your' ?> details on the invoice</strong>
      <span class="muted small">· name, address, tax ID and bank account<?= $d && !empty($d['updated_at'])
        ? ' · last changed ' . e($fmtDate((string)$d['updated_at'])) . (!empty($d['updated_by']) ? ' by ' . e((string)$d['updated_by']) : '')
        : '' ?></span></summary>
    <?php if (!finance_configured()): ?>
      <p class="muted small">The finance database is not set up, so these details cannot be saved yet.</p>
    <?php elseif (!$details['ready']): ?>
      <p class="muted small">These details can be saved once David imports finance update 18
        (finance_install_18.sql). Until then the invoice prints without them.</p>
    <?php else: ?>
      <p class="muted small">Saved in Anima's finance records, which only you and the studio's administrators
        can see. They go on every invoice you make here.</p>
      <form method="post" action="<?= e($selfUrl) ?>" class="inv-form">
        <?php foreach (INVOICE_DETAIL_FIELDS as $k => $label):
          $long = in_array($k, INVOICE_DETAIL_LONG, true);
          $ph = ['legal_name' => $personName, 'job_title' => 'e.g. 2D Animator', 'email' => (string)($person['email'] ?? ''),
                 'tax_id' => 'if you have one', 'clabe' => 'Mexican accounts only', 'cpf' => 'Brazilian accounts only',
                 'payment_notes' => 'e.g. This account is only for ACH transfers'][$k] ?? ''; ?>
          <label class="<?= $long ? 'inv-wide' : '' ?>"><span><?= e($label) ?></span>
            <?php if ($long): ?>
              <textarea name="<?= e($k) ?>" rows="3" placeholder="<?= e($ph) ?>"><?= e($val($k)) ?></textarea>
            <?php else: ?>
              <input type="<?= $k === 'email' ? 'email' : 'text' ?>" name="<?= e($k) ?>" value="<?= e($val($k)) ?>" placeholder="<?= e($ph) ?>">
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <div class="inv-wide"><button class="button primary" type="submit" name="save_invoice_details" value="1">Save details</button></div>
      </form>
    <?php endif; ?>
  </details>
</div>

<?php // ===== THE INVOICE. Everything above is screen only; this is what prints. ===== ?>
<article class="inv-sheet" aria-label="Invoice">
  <h1 class="inv-title">Invoice</h1>

  <div class="inv-meta">
    <div><span class="inv-label">Invoice number</span>
      <input class="inv-edit" type="text" value="<?= e(invoice_doc_number($month, $legalName)) ?>" aria-label="Invoice number"></div>
    <div><span class="inv-label">Date of issue</span>
      <input class="inv-edit" type="text" value="<?= e($fmtDate(date('Y-m-d'))) ?>" aria-label="Date of issue"></div>
    <div><span class="inv-label">Period</span>
      <span><?= e($fmtDate($info['from'])) ?> – <?= e($fmtDate($info['to'])) ?></span></div>
  </div>

  <div class="inv-parties">
    <div>
      <span class="inv-label">Billed to</span>
      <?php foreach (home_invoice_company() as $k => $v): ?>
        <div><?= e($k) ?>: <?= e($v) ?></div>
      <?php endforeach; ?>
    </div>
    <div>
      <span class="inv-label">From</span>
      <div><strong><?= e($legalName) ?></strong></div>
      <?php if ($val('job_title') !== ''): ?><div><?= e($val('job_title')) ?></div><?php endif; ?>
      <?php if ($email !== ''): ?><div><?= e($email) ?></div><?php endif; ?>
      <?php if ($val('address') !== ''): ?><div><?= nl2br(e($val('address'))) ?></div><?php endif; ?>
      <?php if ($val('tax_id') !== ''): ?><div>Tax ID: <?= e($val('tax_id')) ?></div><?php endif; ?>
    </div>
  </div>

  <table class="inv-lines">
    <thead><tr><th>Description</th><th class="num">Unit cost</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
    <tbody>
      <?php if (!$info['lines']): ?>
        <tr><td colspan="4" class="muted">No time logged on a show in <?= e($monthLabel) ?>.</td></tr>
      <?php endif; ?>
      <?php foreach ($info['lines'] as $l): [$title, $sub] = invoice_doc_description($l); ?>
        <tr>
          <td><?= e($title) ?><?php if ($sub !== ''): ?><div class="inv-sub"><?= e($sub) ?></div><?php endif; ?></td>
          <td class="num"><?= e((string)$l['rate_phrase']) ?></td>
          <td class="num"><?= e(invoice_doc_qty($l)) ?></td>
          <td class="num">
            <?php if ($l['total'] !== null): ?>
              <?= e(invoice_doc_amount($l)) ?>
            <?php else: ?>
              <input class="inv-edit inv-amount" type="text" inputmode="decimal" data-cur="<?= e((string)$l['currency']) ?>"
                     placeholder="amount" aria-label="Amount for <?= e($title) ?>">
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <?php foreach ($totals as $cur => $amt): ?>
        <tr class="inv-total">
          <th colspan="3">Invoice total (<?= e((string)$cur) ?>)</th>
          <td class="num" data-cur="<?= e((string)$cur) ?>" data-base="<?= e(number_format((float)$amt, 2, '.', '')) ?>"
              data-sym="<?= e((string)(home_currency_symbol((string)$cur) ?? '')) ?>"><?= e(home_money((float)$amt, (string)$cur)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tfoot>
  </table>

  <?php if ($val('payment_notes') !== ''): ?>
    <section class="inv-block">
      <span class="inv-label">Notes</span>
      <div><?= nl2br(e($val('payment_notes'))) ?></div>
    </section>
  <?php endif; ?>

  <section class="inv-block">
    <span class="inv-label">Bank account details</span>
    <?php if (!$hasBank): ?>
      <div class="inv-missing">Add your bank details above before you send this invoice.</div>
    <?php endif; ?>
    <?php foreach ($bankKeys as $k): if ($val($k) === '') continue; ?>
      <div><?= e(INVOICE_DETAIL_FIELDS[$k]) ?>: <?= e($val($k)) ?></div>
    <?php endforeach; ?>
    <?php if ($val('holder_address') !== ''): ?>
      <div><?= e(INVOICE_DETAIL_FIELDS['holder_address']) ?>:<br><?= nl2br(e($val('holder_address'))) ?></div>
    <?php endif; ?>
  </section>

  <?php // For the email intake: the same plain facts on every invoice, in one line. ?>
  <p class="inv-foot">Made with Anima · Freelancer ID <?= (int)$crewId ?> · Period <?= e($info['from']) ?> to <?= e($info['to']) ?></p>
</article>

<?php if ($anyTyped): ?>
<script>
// A typed amount (per-second or flat line) joins the total in its currency.
(function () {
  var inputs = document.querySelectorAll('.inv-amount');
  function sum() {
    var add = {};
    inputs.forEach(function (i) {
      var v = parseFloat(String(i.value).replace(/[^0-9.\-]/g, ''));
      if (!isNaN(v)) add[i.dataset.cur] = (add[i.dataset.cur] || 0) + v;
    });
    document.querySelectorAll('.inv-total td[data-cur]').forEach(function (td) {
      var t = parseFloat(td.dataset.base) + (add[td.dataset.cur] || 0);
      var n = t.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      td.textContent = td.dataset.sym ? td.dataset.sym + n : n + ' ' + td.dataset.cur;
    });
  }
  inputs.forEach(function (i) { i.addEventListener('input', sum); });
})();
</script>
<?php endif; ?>

</div></body></html>
