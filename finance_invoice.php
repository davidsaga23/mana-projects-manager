<?php
// Monthly invoices — one freelancer's invoice for a month, split across the
// projects it covers (Studio board IN4). ADMIN ONLY.
//
// Tim and Jorik, 2026-10-28: a freelancer sends ONE invoice per month to
// accounting@mana.tv, a line per project and position ("FCM CH Layout 5 days,
// MSD BG Color 3 days"), and Jorik pays it once. This is where it is entered:
// pick the month, pick the person, and the lines are already there from the
// days they logged on each show that month (the same figures their Home shows
// them to put on it). Correct what the invoice actually says, type their
// number and the date it arrived, save. Each line then sits on its own
// project's Invoices tab as an ordinary invoice, so that project's totals,
// statements and Final balance count it with nothing else changed.
//
// Without ?crew= it is the month's list: everybody who logged time on a show,
// what the log says they should invoice, and whether their invoice is in.
//
// Data and rules live in includes/invoice_split.php.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/invoice_split.php';
require_admin();

$who = current_user_display();
$configured = finance_configured();
$ready = $configured && invoice_split_available();

// ?id= opens one invoice wherever it is (the link from a project's Invoices
// tab); otherwise ?crew= and ?month=.
$header = null;
if (!empty($_GET['id']) && $ready) {
    $header = invoice_header((int)$_GET['id']);
    if (!$header) {
        flash('err', 'That invoice no longer exists.');
        header('Location: finance_invoice.php');
        exit;
    }
}
$month  = $header ? (string)$header['month'] : invoice_split_month($_GET['month'] ?? null);
$crewId = $header ? (int)$header['crew_id'] : (int)($_GET['crew'] ?? 0);
$listUrl = 'finance_invoice.php?month=' . $month;
$selfUrl = $listUrl . ($crewId ? '&crew=' . $crewId : '');
$monthLabel = date('F Y', strtotime($month . '-01'));

$person = null;
if ($crewId > 0) {
    $q = $pdo->prepare('SELECT id, name FROM crew WHERE id = ?');
    $q->execute([$crewId]);
    $person = $q->fetch() ?: null;
    // A header whose person was deleted from the crew list still opens, under
    // the name copied onto it.
    if (!$person && $header) $person = ['id' => $crewId, 'name' => (string)$header['crew_name']];
    if (!$person) {
        flash('err', 'That person is no longer on the crew list.');
        header('Location: ' . $listUrl);
        exit;
    }
}

// ---- One person: their invoice, its lines, and what the log says ---------
$prefill = ['lines' => [], 'outside' => 0, 'terms' => null];
$lines = []; $positions = [];
if ($person && $configured) {
    try {
        $header = $header ?? ($ready ? invoice_header_for($crewId, $month) : null);
        $lines = $header ? invoice_header_lines((int)$header['id']) : [];
        $prefill = invoice_split_prefill($pdo, $crewId, $month);

        // Where a line may go: their positions on every show they logged on
        // that month, are on now, or already have a line on.
        $pids = [];
        foreach ($prefill['lines'] as $pl) $pids[] = (int)$pl['project']['id'];
        foreach ($lines as $l) $pids[] = (int)$l['project_id'];
        foreach (array_keys(home_projects($pdo, $crewId)) as $pid) $pids[] = (int)$pid;
        $positions = invoice_split_positions($pdo, $crewId, $pids);
        // A step they logged time on without holding a slot on it is still a
        // line the log asks for; so is whatever a saved line already says.
        foreach ($prefill['lines'] as $pl) {
            $k = (int)$pl['project']['id'] . ':' . (int)($pl['step_id'] ?? 0);
            if (!isset($positions[$k]) && !empty($pl['step_id'])) {
                $positions[$k] = ['project_id' => (int)$pl['project']['id'],
                                  'project_code' => (string)$pl['project']['code'],
                                  'project_name' => (string)$pl['project']['name'],
                                  'step_id' => (int)$pl['step_id'], 'step_name' => (string)$pl['step'],
                                  'label' => $pl['project']['name'] . ' · ' . $pl['step']];
            }
        }
        foreach ($lines as $l) {
            $k = (int)$l['project_id'] . ':' . (int)($l['step_id'] ?? 0);
            if (!isset($positions[$k])) {
                $positions[$k] = ['project_id' => (int)$l['project_id'], 'project_code' => (string)$l['project_code'],
                                  'project_name' => (string)$l['project_code'],
                                  'step_id' => $l['step_id'] !== null ? (int)$l['step_id'] : null,
                                  'step_name' => $l['step_name'],
                                  'label' => ($l['project_code'] ?: 'Project ' . $l['project_id'])
                                           . ' · ' . ($l['step_name'] ?: 'no particular position')];
            }
        }
        uasort($positions, fn($a, $b) => strcmp($a['label'], $b['label']));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $person) {
    try {
        if (isset($_POST['save_invoice_split'])) {
            $rows = is_array($_POST['rows'] ?? null) ? array_values($_POST['rows']) : [];
            $r = save_invoice_split($crewId, (string)$person['name'], $month, $_POST, $rows, $positions, $who);
            flash('ok', $r['message']);
            foreach ($r['errors'] as $err) flash('err', $err);
        } elseif (isset($_POST['pay_invoice_header']) && $header) {
            flash('ok', pay_invoice_header((int)$header['id'], (string)($_POST['paid_on'] ?? ''), $who));
        } elseif (isset($_POST['delete_invoice_header']) && $header) {
            flash('ok', delete_invoice_header((int)$header['id']));
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

// ---- The month's list ------------------------------------------------------
$people = []; $headers = []; $expected = [];
if (!$person && $configured) {
    try {
        $people = invoice_split_people($pdo, $month);
        $headers = invoice_headers_for_month($month);
        foreach ($people as $cid => $pp) {
            if ($pp['salaried']) continue;
            $expected[$cid] = [];
            foreach (invoice_split_prefill($pdo, $cid, $month)['lines'] as $pl) {
                if ($pl['total'] === null) continue;
                $expected[$cid][$pl['currency']] = round(($expected[$cid][$pl['currency']] ?? 0) + $pl['total'], 2);
            }
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
}

$money = function (array $byCur): string {
    if (!$byCur) return '—';
    $out = [];
    foreach ($byCur as $c => $a) $out[] = fmt_money_plain((float)$a, (string)$c);
    return implode(' + ', $out);
};
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

$pageTitle = $person ? 'Invoice · ' . $person['name'] : 'Monthly invoices';
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="finance.php">← Studio finances</a>
  <?php if ($person): ?> · <a href="<?= e($listUrl) ?>">Monthly invoices, <?= e($monthLabel) ?></a><?php endif; ?></p>
<h2 class="wizard-title"><?= $person ? e($person['name']) . ' · ' : '' ?>Invoice for <?= e($monthLabel) ?>
  <span class="admin-only-tag">administrators only</span></h2>

<p class="muted small">
  <a href="finance_invoice.php?month=<?= e($prevMonth) ?><?= $person ? '&amp;crew=' . $crewId : '' ?>">← <?= e(date('F', strtotime($prevMonth . '-01'))) ?></a>
  <?php if ($nextMonth <= date('Y-m')): ?>
    · <a href="finance_invoice.php?month=<?= e($nextMonth) ?><?= $person ? '&amp;crew=' . $crewId : '' ?>"><?= e(date('F', strtotime($nextMonth . '-01'))) ?> →</a>
  <?php endif; ?>
</p>

<?php if (!$configured): ?>
  <div class="empty-state"><p><strong>The finance database isn't set up yet.</strong></p></div>
  </div></body></html>
  <?php exit; ?>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="flash flash-warn">
    <strong>The finance database needs <code>finance_install_12.sql</code>.</strong>
    Until it is imported into the finance database via phpMyAdmin, a monthly invoice cannot be
    saved here. What the log says each person should invoice is shown, and invoices entered on a
    project's Invoices tab work as before.
  </div>
<?php endif; ?>

<?php if (!$person): // ================= THE MONTH'S LIST ================= ?>

<p class="lead">
  Each freelancer sends one invoice a month to accounting@mana.tv, with a line per project. Open a
  person to enter theirs: the lines come filled in from the days they logged on each show, and each
  line lands on its own project's Invoices tab.
</p>

<?php $freelancers = array_filter($people, fn($pp) => !$pp['salaried']);
      $others = array_diff_key($headers, $freelancers); ?>
<?php if (!$freelancers && !$others): ?>
  <p class="muted">Nobody logged time on a show in <?= e($monthLabel) ?>.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Person</th><th class="num" title="Days logged on each show that month × their rate: what their Home tells them to invoice.">From the log</th>
        <th>Their invoice</th><th class="num">Lines total</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($freelancers + array_map(fn($h) => ['name' => (string)$h['crew_name'], 'salaried' => false], $others) as $cid => $pp):
      $h = $headers[$cid] ?? null;
      $hl = $h ? invoice_lines_total(invoice_header_lines((int)$h['id'])) : []; ?>
      <tr>
        <td><?= e($pp['name']) ?><?php if ($h && !empty($h['needs_review'])): ?>
            <span class="warn-text small" title="<?= e((string)$h['review_reason']) ?>">needs a look</span><?php endif; ?></td>
        <td class="num muted"><?= e($money($expected[$cid] ?? [])) ?></td>
        <td class="small"><?= $h ? e($h['invoice_ref'] ?: 'no number yet') : '<span class="muted">not entered</span>' ?></td>
        <td class="num"><?= $h ? e($money($hl)) : '' ?></td>
        <td class="small"><?php if ($h): ?>
            <?= $h['paid_date'] ? '<span class="ok">Paid ' . e($h['paid_date']) . '</span>'
                                : e(invoice_status_label((string)$h['status'])) ?>
          <?php endif; ?></td>
        <td class="row-actions"><div class="cell-btns">
          <a class="row-btn row-btn-go" href="finance_invoice.php?month=<?= e($month) ?>&amp;crew=<?= (int)$cid ?>"><?= $h ? 'Open' : 'Enter' ?></a>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php $salaried = array_filter($people, fn($pp) => $pp['salaried']); ?>
<?php if ($salaried): ?>
  <p class="muted small">Salaried, so no invoice: <?= e(implode(', ', array_map(fn($pp) => $pp['name'], $salaried))) ?>.</p>
<?php endif; ?>

<?php else: // ================= ONE PERSON'S INVOICE ================= ?>

<?php
  // What goes in the form: the saved lines first, then whatever the log asks
  // for that is not on the invoice yet, then two empty rows for anything else
  // (a kill fee, a show they did not log on).
  $terms = $prefill['terms'];
  $rows = []; $onInvoice = [];
  foreach ($lines as $l) {
      $k = (int)$l['project_id'] . ':' . (int)($l['step_id'] ?? 0);
      $onInvoice[$k] = true;
      $rows[] = ['line_id' => (int)$l['id'], 'pos' => $k, 'use' => true,
                 'quantity' => $l['quantity'] ?? null, 'unit' => $l['quantity_unit'] ?? null,
                 'rate' => $l['unit_rate'] ?? null, 'amount' => (float)$l['amount'],
                 'currency' => (string)$l['currency'], 'log' => null];
  }
  $logged = [];
  foreach ($prefill['lines'] as $pl) {
      $k = (int)$pl['project']['id'] . ':' . (int)($pl['step_id'] ?? 0);
      $logged[$k] = $pl;
      if (isset($onInvoice[$k])) continue;
      $qty = $pl['qty'] !== null ? round((float)$pl['qty'], 2) : null;
      // The rate in the line's money: converted at the show's fixed rate when
      // the line is (a £ contract invoiced in dollars), as Home shows it.
      $rate = $pl['rate'];
      if ($pl['total'] !== null && $qty && $pl['currency'] !== $pl['own_currency']) $rate = round($pl['total'] / $qty, 2);
      $rows[] = ['line_id' => 0, 'pos' => $k, 'use' => !$header && $pl['total'] !== null,
                 'quantity' => $qty, 'unit' => in_array($pl['unit'], ['day', 'hour'], true) ? $pl['unit'] : null,
                 'rate' => $rate, 'amount' => $pl['total'], 'currency' => $pl['currency'], 'log' => $pl];
  }
  $defaultCur = $header['currency'] ?? null;
  if (!$defaultCur) {
      $curs = array_unique(array_map(fn($r) => $r['currency'], $rows));
      $defaultCur = count($curs) === 1 ? reset($curs) : strtoupper((string)($terms['rate_currency'] ?? 'USD'));
  }
  for ($i = 0; $i < 2; $i++) {
      $rows[] = ['line_id' => 0, 'pos' => '', 'use' => false, 'quantity' => null,
                 'unit' => ($terms['rate_unit'] ?? 'day') === 'hour' ? 'hour' : 'day',
                 'rate' => $terms['day_rate'] ?? null, 'amount' => null, 'currency' => $defaultCur, 'log' => null];
  }
  $lineTotals = invoice_lines_total($lines);
  $num = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
?>

<?php if ($header && !empty($header['needs_review'])): ?>
  <div class="flash flash-warn"><strong>Needs a look.</strong> <?= e((string)($header['review_reason'] ?: 'No reason given.')) ?></div>
<?php endif; ?>

<?php if (!$terms || $terms['day_rate'] === null): ?>
  <p class="warn-text small">No rate on file for <?= e($person['name']) ?>, so the lines from the log have
    days but no amounts. Set it on <a href="person.php?id=<?= $crewId ?>">their person page</a>, or type the amounts.</p>
<?php elseif (in_array((string)$terms['rate_unit'], ['second', 'flat'], true)): ?>
  <p class="muted small">Their rate is <?= $terms['rate_unit'] === 'flat' ? 'a flat fee' : 'per approved second' ?>,
    which is not counted from logged time: each show's statement says what is due. Type the amounts.</p>
<?php endif; ?>
<?php if ($prefill['outside'] > 0): ?>
  <p class="warn-text small"><?= e(fmt_hours((int)$prefill['outside'])) ?> logged this month fall outside their dates
    on a show and are not counted in the lines from the log.</p>
<?php endif; ?>

<form method="post" class="invoice-split-form">
  <input type="hidden" name="save_invoice_split" value="1">

  <h3>The invoice</h3>
  <div class="invoice-edit-form">
    <label>Their invoice number <input name="invoice_ref" value="<?= e($header['invoice_ref'] ?? '') ?>" size="12"></label>
    <label>Received on <input type="date" name="received_date" value="<?= e($header['received_date'] ?? date('Y-m-d')) ?>"></label>
    <label>Status
      <select name="status">
        <?php foreach (invoice_statuses() as $sv => $sl): ?>
          <option value="<?= e($sv) ?>" <?= ($header['status'] ?? invoice_default_status()) === $sv ? 'selected' : '' ?>><?= e($sl) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Paid on <input type="date" name="paid_date" value="<?= e($header['paid_date'] ?? '') ?>"></label>
    <label title="The total printed on the invoice, to check against the lines. Leave it empty if you just want the lines.">Total on the invoice
      <input name="total" inputmode="decimal" size="10" value="<?= e($num($header['total'] ?? null)) ?>"></label>
    <label>Currency <input name="currency" maxlength="3" size="3" style="text-transform:uppercase" value="<?= e($defaultCur) ?>"></label>
    <label>PDF link <input type="url" name="document_url" size="28" placeholder="https://drive.google.com/…"
           value="<?= e($header['document_url'] ?? '') ?>"></label>
    <label><input type="checkbox" name="needs_review" value="1" <?= !empty($header['needs_review']) ? 'checked' : '' ?>> Needs a look</label>
    <label>Why <input name="review_reason" size="28" maxlength="500" value="<?= e($header['review_reason'] ?? '') ?>"></label>
    <label class="invoice-edit-notes">Notes <input name="notes" maxlength="2000" value="<?= e($header['notes'] ?? '') ?>"></label>
  </div>

  <h3>Lines <span class="muted">· one per project and position</span></h3>
  <p class="muted small">
    Ticked lines are saved; each becomes an invoice on its own project's Invoices tab. Leave the amount
    empty and it is days × rate. <strong>From the log</strong> is what <?= e($person['name']) ?> logged that
    month, the same figure their Home tells them to invoice.
  </p>
  <table class="invoice-split-lines">
    <thead><tr><th></th><th>Project · position</th><th class="num">Days / hours</th><th class="num">Rate</th>
        <th class="num">Amount</th><th>Currency</th><th class="muted">From the log</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r):
      $pl = $r['log'] ?? ($logged[$r['pos']] ?? null); ?>
      <tr>
        <td>
          <input type="hidden" name="rows[<?= $i ?>][line_id]" value="<?= (int)$r['line_id'] ?>">
          <?php if ($r['line_id']): ?>
            <label class="small" title="Take this line off the invoice and off its project"><input type="checkbox" name="rows[<?= $i ?>][remove]" value="1"> remove</label>
          <?php else: ?>
            <input type="checkbox" name="rows[<?= $i ?>][use]" value="1" <?= $r['use'] ? 'checked' : '' ?> title="Put this line on the invoice">
          <?php endif; ?>
        </td>
        <td>
          <select name="rows[<?= $i ?>][pos]">
            <option value="">choose a project…</option>
            <?php foreach ($positions as $k => $ps): ?>
              <option value="<?= e($k) ?>" <?= $k === $r['pos'] ? 'selected' : '' ?>><?= e($ps['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="num"><input name="rows[<?= $i ?>][quantity]" inputmode="decimal" size="5" class="js-qty" value="<?= e($num($r['quantity'])) ?>">
          <select name="rows[<?= $i ?>][unit]">
            <option value="day" <?= ($r['unit'] ?? 'day') !== 'hour' ? 'selected' : '' ?>>days</option>
            <option value="hour" <?= ($r['unit'] ?? '') === 'hour' ? 'selected' : '' ?>>hours</option>
          </select></td>
        <td class="num"><input name="rows[<?= $i ?>][rate]" inputmode="decimal" size="7" class="js-rate" value="<?= e($num($r['rate'])) ?>"></td>
        <td class="num"><input name="rows[<?= $i ?>][amount]" inputmode="decimal" size="9" class="js-amount"
               value="<?= $r['amount'] !== null ? e(number_format((float)$r['amount'], 2, '.', '')) : '' ?>"></td>
        <td><input name="rows[<?= $i ?>][currency]" maxlength="3" size="3" style="text-transform:uppercase" value="<?= e($r['currency']) ?>"></td>
        <td class="muted small"><?= $pl ? e($pl['qty_label'] . ($pl['total'] !== null ? ' · ' . fmt_money_plain((float)$pl['total'], $pl['currency']) : ''))
                                        : ($r['line_id'] ? 'nothing logged' : '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($lineTotals): ?>
    <p>Lines total: <strong><?= e($money($lineTotals)) ?></strong>
    <?php if ($header && $header['total'] !== null
              && abs(($lineTotals[$header['currency']] ?? 0) - (float)$header['total']) >= 0.01): ?>
      · <span class="warn-text">the invoice says <?= e(fmt_money_plain((float)$header['total'], (string)$header['currency'])) ?></span>
    <?php endif; ?>
    <?php if (count($lineTotals) > 1): ?>
      · <span class="warn-text small">more than one currency: a show has no exchange rate fixed for this contract.</span>
    <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if ($ready): ?>
    <button type="submit"><?= $header ? 'Save the invoice' : 'Record the invoice' ?></button>
  <?php endif; ?>
</form>

<?php if ($header): ?>
  <?php if ((string)$header['status'] !== 'paid'): ?>
    <form method="post" class="inline-form" style="margin-top:14px">
      <input type="hidden" name="pay_invoice_header" value="1">
      <label>Paid on <input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>"></label>
      <button type="submit">Mark paid</button>
      <span class="muted small">Pays the invoice and every line of it, on every project, at once.</span>
    </form>
  <?php endif; ?>

  <h3>Where the lines are</h3>
  <ul class="small">
    <?php foreach ($lines as $l): ?>
      <li><a href="finance_project.php?project=<?= (int)$l['project_id'] ?>&amp;tab=invoices"><?= e($l['project_code'] ?: 'Project ' . $l['project_id']) ?></a>
        · <?= e($l['step_name'] ?: 'no particular position') ?> · <?= e(fmt_money_plain((float)$l['amount'], (string)$l['currency'])) ?></li>
    <?php endforeach; ?>
  </ul>

  <form method="post" onsubmit="return confirm('Remove this invoice and its lines from every project?');" style="margin-top:14px">
    <button type="submit" name="delete_invoice_header" value="1" class="link-danger">Remove this invoice</button>
  </form>
<?php endif; ?>

<script>
// Days × rate into the amount as you type either, so the line shows what will
// be saved. A typed amount is left alone until days or rate change again.
document.querySelectorAll('.invoice-split-lines tbody tr').forEach(function (tr) {
  var q = tr.querySelector('.js-qty'), r = tr.querySelector('.js-rate'), a = tr.querySelector('.js-amount');
  if (!q || !r || !a) return;
  function calc() {
    var qv = parseFloat(q.value.replace(/,/g, '')), rv = parseFloat(r.value.replace(/,/g, ''));
    if (!isNaN(qv) && !isNaN(rv)) a.value = (qv * rv).toFixed(2);
  }
  q.addEventListener('input', calc);
  r.addEventListener('input', calc);
});
</script>

<?php endif; ?>

</div>
</body></html>
