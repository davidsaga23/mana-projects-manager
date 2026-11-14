<?php
/**
 * MOVING AN INVOICE TO ANOTHER PROJECT (David, 2026-11-13: "can we add a new
 * button in the edit to change this invoice to another project?").
 *
 * A freelancer's invoice, or one line of their monthly invoice (IN4,
 * includes/invoice_split.php), is sometimes recorded on the wrong show. Before
 * this the only fix was to remove it and type it again on the right show,
 * which loses its paid state, its note and its link to the monthly invoice.
 *
 * ⚠ THE INVOICE ROW IS UPDATED IN PLACE, never deleted and recreated: only its
 * project and the position it pays for change. Its id, amount, currency,
 * sender, number, document link, dates, status, paid date, note and monthly
 * invoice (header_id) all stay. Because every page reads invoices BY PROJECT
 * (the project's totals and Final balance, the expense projection's Actual,
 * the statements, the hub, the fee history), they all read the new answer
 * with no other change.
 *
 * A MONTHLY INVOICE LINE moves alone. The header has no project of its own:
 * its total is what is printed on the invoice, and its status and paid date are
 * copied onto every line whatever project the line is on, so moving one line
 * changes neither. The other lines stay where they are.
 *
 * WHO MOVED IT, WHEN AND FROM WHERE is written twice, on purpose:
 *   – into the invoice's own NOTES, because that is the column David reads on
 *     the Invoices tab, so the move is visible where the invoice is;
 *   – into the invoice_moves table (finance_install_19), because a note is
 *     editable in place and a record that can be typed over is not a record.
 * Until 19 is imported the move still happens and the note is still written;
 * the page says the log could not be.
 *
 * ADMIN ONLY: can_see_money(), on an admin-only page, after a confirmation
 * step that shows what will change.
 */

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/fx.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/studio.php';

/** Has finance_install_19 been imported? */
function invoice_moves_available(): bool {
    return finance_column_exists('invoice_moves', 'id');
}

/** Every show an invoice could move to, except $exceptId: the Studio is never costed. */
function invoice_move_projects(PDO $pdo, int $exceptId): array {
    $q = $pdo->prepare("SELECT p.id, p.name, p.code, p.status FROM projects p WHERE p.id <> ?"
        . studio_exclude_sql($pdo, 'p')
        . " ORDER BY FIELD(p.status, 'active','setup','on_hold','wrapped','postmortem','archived'), p.name");
    $q->execute([$exceptId]);
    return $q->fetchAll();
}

/**
 * The picker's "holderId:stepId" (or "0:stepId" for a whole step, '' for
 * unattached), checked against the TARGET show's own positions and steps,
 * never trusted from the browser. Same rules as resolve_invoice_target().
 *
 * @return array ['step_id' => ?int, 'step_name' => ?string, 'holder' => ?int (0 = the step itself)]
 */
function invoice_move_target(PDO $pdo, int $toProjectId, string $position): array {
    $t = resolve_invoice_target($position, '', project_positions($pdo, $toProjectId),
                                pipeline_steps($pdo, $toProjectId), []);
    if ($t['step_id'] === null) return ['step_id' => null, 'step_name' => null, 'holder' => null];
    // With no sender picked, a position's holder comes back as crew_id and the
    // whole step as position_crew_id 0.
    $holder = $t['position_crew_id'] ?? $t['crew_id'] ?? 0;
    return ['step_id' => (int)$t['step_id'], 'step_name' => $t['step_name'], 'holder' => (int)$holder];
}

/** "FCM · Layout" — a project and what the invoice pays for on it, for notes and messages. */
function invoice_move_place(array $project, ?string $stepName): string {
    $p = trim((string)($project['code'] ?? '')) ?: (string)($project['name'] ?? '');
    return $p . ' · ' . (trim((string)$stepName) !== '' ? $stepName : 'unattached');
}

/**
 * Move one invoice from $from to $to, onto $target (from invoice_move_target()).
 *
 * The sender (crew_id) never changes: whoever sent it still sent it. When the
 * target position is somebody else's, position_crew_id says whose, exactly as
 * the edit form does (finance_install_15).
 *
 * @return array ['message' => string, 'warn' => ?string]
 */
function move_invoice(int $id, array $from, array $to, array $target, string $who = ''): array {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can move an invoice.');
    unset($_SESSION['payments_waiting']);   // the top bar's Invoices count (includes/payments.php)
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet.');
    if ((int)$from['id'] === (int)$to['id']) {
        throw new RuntimeException('That invoice is already on ' . $to['name'] . ', so nothing was moved.');
    }

    $q = $db->prepare('SELECT * FROM invoices WHERE id = ? AND project_id = ?');
    $q->execute([$id, (int)$from['id']]);
    $iv = $q->fetch();
    if (!$iv) throw new RuntimeException('That invoice is not on this show, so nothing was moved.');

    $hasPosition = finance_column_exists('invoices', 'step_id');
    $hasHolder = $hasPosition && invoice_holder_available();
    $stepId = $target['step_id'] ?? null;
    $holder = $stepId === null ? null : (int)($target['holder'] ?? 0);
    if ($stepId !== null && !$hasPosition) {
        throw new RuntimeException('Putting an invoice on a position needs finance_install_03.sql imported into '
            . 'the finance database. Choose "unattached", or import it first. Nothing was moved.');
    }
    $sender = $iv['crew_id'] !== null ? (int)$iv['crew_id'] : null;
    // Stored only when it differs from the sender, as everywhere else.
    $posCrew = ($stepId !== null && $holder !== $sender) ? $holder : null;
    if ($posCrew !== null && !$hasHolder) {
        // A whole step with no sender already reads as the step without it.
        if ($posCrew === 0 && $sender === null) $posCrew = null;
        else throw new RuntimeException("Putting an invoice on somebody else's position, or on a whole step, needs "
            . 'finance_install_15.sql imported into the finance database. Nothing was moved.');
    }

    $fromPlace = invoice_move_place($from, $iv['step_name'] ?? null);
    $toPlace = invoice_move_place($to, $target['step_name'] ?? null);
    // One line, appended: an <input> drops line breaks, so " · " separates it
    // from whatever the note already said.
    $line = 'Moved from ' . $fromPlace . ' by ' . ($who ?: 'an administrator') . ', ' . date('Y-m-d') . '.';
    $old = trim((string)($iv['notes'] ?? ''));
    $notes = $old !== '' ? $old . ' · ' . $line : $line;

    $sets = 'project_id = ?, project_code = ?, notes = ?, updated_by = ?';
    $args = [(int)$to['id'], $to['code'] ?? null, $notes, $who];
    if ($hasPosition) {
        $sets .= ', step_id = ?, step_name = ?';
        array_push($args, $stepId, $stepId !== null ? ($target['step_name'] ?? null) : null);
    }
    if ($hasHolder) {
        $sets .= ', position_crew_id = ?';
        $args[] = $posCrew;
    }
    array_push($args, $id, (int)$from['id']);

    $logged = invoice_moves_available();
    $db->beginTransaction();
    try {
        $u = $db->prepare("UPDATE invoices SET {$sets} WHERE id = ? AND project_id = ?");
        $u->execute($args);
        if ($u->rowCount() !== 1) throw new RuntimeException('That invoice changed while you were looking at it. Nothing was moved; try again.');
        if ($logged) {
            $db->prepare('INSERT INTO invoice_moves
                            (invoice_id, header_id, crew_id, crew_name,
                             from_project_id, from_project_code, from_step_id, from_step_name, from_position_crew_id,
                             to_project_id, to_project_code, to_step_id, to_step_name, to_position_crew_id,
                             amount, currency, moved_at, moved_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$id, !empty($iv['header_id']) ? (int)$iv['header_id'] : null, $sender, $iv['crew_name'] ?? null,
                          (int)$from['id'], $from['code'] ?? null,
                          isset($iv['step_id']) && $iv['step_id'] !== null ? (int)$iv['step_id'] : null,
                          $iv['step_name'] ?? null,
                          isset($iv['position_crew_id']) && $iv['position_crew_id'] !== null ? (int)$iv['position_crew_id'] : null,
                          (int)$to['id'], $to['code'] ?? null, $stepId, $stepId !== null ? ($target['step_name'] ?? null) : null,
                          $posCrew, $iv['amount'], $iv['currency'] ?? null, date('Y-m-d H:i:s'), $who]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'message' => 'Moved ' . (($iv['crew_name'] ?? '') ?: 'the invoice') . "'s "
                   . fmt_money_plain((float)$iv['amount'], (string)($iv['currency'] ?? 'USD'))
                   . ' from ' . $fromPlace . ' to ' . $toPlace . '. It is now on ' . $to['name'] . "'s Invoices tab.",
        'warn' => $logged ? null
            : 'The move is written in the invoice\'s notes, but not in the move log: import '
            . 'finance_install_19.sql (Migrations → Finance database updates) to keep one.',
    ];
}

/**
 * THE CONFIRMATION STEP, drawn above the Invoices list when "Move to another
 * project…" is clicked in an invoice's edit row. Two steps without JavaScript:
 * pick the show (a GET, which reloads with its positions), then pick the
 * position and confirm (the POST). Everything a move would change, and every
 * reason to think twice, is said here before anything is written.
 *
 * @param array $iv      the invoice (a row of project_invoices())
 * @param array $fromFx  this project's fixed exchange rates
 */
function invoice_move_panel(PDO $pdo, array $project, array $iv, int $toId, array $fromFx): void {
    $pid = (int)$project['id'];
    $id = (int)$iv['id'];
    $shows = invoice_move_projects($pdo, $pid);
    $to = null;
    foreach ($shows as $s) if ((int)$s['id'] === $toId) $to = $s;
    $sender = $iv['crew_id'] !== null ? (int)$iv['crew_id'] : null;
    $senderName = ($iv['crew_name'] ?? '') ?: 'this person';
    $cur = (string)($iv['currency'] ?? 'USD');
    $amount = (float)$iv['amount'];
    $back = 'finance_project.php?project=' . $pid . '&tab=invoices';

    // A line of a monthly invoice: which one, for the sentence that says only this line moves.
    $header = null;
    if (!empty($iv['header_id']) && finance_column_exists('invoice_headers', 'month')) {
        try {
            $hq = finance_db()->prepare('SELECT * FROM invoice_headers WHERE id = ?');
            $hq->execute([(int)$iv['header_id']]);
            $header = $hq->fetch() ?: null;
        } catch (Throwable $e) {
            $header = null;
        }
    }
    ?>
<section class="panel invoice-move" id="move">
  <h3>Move an invoice to another project</h3>
  <p>
    <strong><?= e($senderName) ?></strong>, <?= e(fmt_money_plain($amount, $cur)) ?>
    <?= !empty($iv['invoice_ref']) ? '(their ref ' . e($iv['invoice_ref']) . ')' : '' ?>,
    now on <strong><?= e($project['name']) ?></strong> ·
    <?= e(($iv['step_name'] ?? '') ?: 'unattached') ?>.
  </p>

  <form method="get" class="invoice-move-pick">
    <input type="hidden" name="project" value="<?= $pid ?>">
    <input type="hidden" name="tab" value="invoices">
    <input type="hidden" name="move_invoice" value="<?= $id ?>">
    <label>Move to
      <select name="to" onchange="this.form.submit()">
        <option value="">choose a project…</option>
        <?php foreach ($shows as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $toId ? 'selected' : '' ?>>
            <?= e($s['name']) ?><?= ($s['status'] ?? 'active') !== 'active' ? ' (' . e(str_replace('_', ' ', (string)$s['status'])) . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript><button type="submit" class="secondary">Next</button></noscript>
    <a href="<?= e($back) ?>">Cancel</a>
  </form>

  <?php if ($to):
    $toPositions = project_positions($pdo, (int)$to['id']);
    $toSteps = pipeline_steps($pdo, (int)$to['id']);
    $mine = []; $others = [];
    foreach ($toPositions as $ps) {
        if ($sender !== null && (int)$ps['crew_id'] === $sender) $mine[] = $ps; else $others[] = $ps;
    }
    // What to preselect: their position on the step of the same name, else
    // their first position there, else the step of the same name, else nothing.
    $curStep = strtolower(trim((string)($iv['step_name'] ?? '')));
    $sel = '';
    foreach ($mine as $ps) {
        if ($curStep !== '' && strtolower(trim((string)$ps['step_name'])) === $curStep) { $sel = $ps['crew_id'] . ':' . $ps['step_id']; break; }
    }
    if ($sel === '' && $mine) $sel = $mine[0]['crew_id'] . ':' . $mine[0]['step_id'];
    if ($sel === '' && $curStep !== '') {
        foreach ($toSteps as $st) if (strtolower(trim((string)$st['name'])) === $curStep) { $sel = '0:' . (int)$st['id']; break; }
    }
    // Is the sender on the target show at all?
    $onShow = $sender === null;
    if ($sender !== null) {
        foreach (project_people($pdo, (int)$to['id']) as $pp) if ((int)$pp['id'] === $sender) $onShow = true;
    }
    // The amount in dollars on each show, at each one's own fixed rate.
    $toFx = project_fx_rates((int)$to['id']);
    $usdFrom = fx_to_usd($amount, $cur, $fromFx);
    $usdTo = fx_to_usd($amount, $cur, $toFx);
    $c = fx_code($cur) ?? 'USD';
    ?>
  <ul class="invoice-move-notes">
    <?php if ($header): ?>
      <li>This is one line of <?= e($senderName) ?>'s
        <a href="finance_invoice.php?id=<?= (int)$header['id'] ?>">monthly invoice for <?= e($header['month']) ?></a>.
        <strong>Only this line moves</strong>; the other lines stay on their projects. The invoice's
        total<?= $header['total'] !== null ? ' (' . e(fmt_money_plain((float)$header['total'], (string)$header['currency'])) . ' as printed)' : '' ?>,
        its status and its paid date belong to the whole invoice, so they do not change.</li>
    <?php endif; ?>
    <?php if (!$onShow): ?>
      <li class="warn-text"><?= e($senderName) ?> is not on <?= e($to['name']) ?>'s crew. The invoice will still
        say it is from them, and it will be listed on their <?= e($to['name']) ?> statement.</li>
    <?php endif; ?>
    <?php if ($sender !== null && !$mine): ?>
      <li><?= e($senderName) ?> has no position on <?= e($to['name']) ?>: choose somebody else's position,
        a whole step, or leave it unattached.</li>
    <?php endif; ?>
    <?php if ($c !== 'USD'): ?>
      <li>The invoice stays in <?= e($c) ?> (<?= e(fmt_money_plain($amount, $c)) ?>); each show counts it in dollars at
        its own fixed rate.
        On <?= e($project['name']) ?>: <?= $usdFrom !== null ? '<strong>' . e(fmt_money_plain($usdFrom, 'USD')) . '</strong> (' . e(fx_describe($fromFx[$c])) . ')' : 'no ' . e($c) . ' rate fixed' ?>.
        On <?= e($to['name']) ?>: <?= $usdTo !== null ? '<strong>' . e(fmt_money_plain($usdTo, 'USD')) . '</strong> (' . e(fx_describe($toFx[$c])) . ')'
            : 'no ' . e($c) . ' rate fixed yet; one is fixed the first time its Finance page is opened, and until then it counts as ' . e($c) ?>.</li>
    <?php endif; ?>
    <?php if (empty($iv['period_start']) || empty($iv['period_end'])): ?>
      <li>Its period is not typed, so it will show the new position's dates on <?= e($to['name']) ?>'s timeline.</li>
    <?php endif; ?>
    <li>Everything else stays: the amount, the document link, the dates, the status, the paid date and the note.
      The move is added to its note (who, when, from where)<?= invoice_moves_available() ? ' and to the move log' : '' ?>.</li>
  </ul>

  <form method="post" class="invoice-move-confirm"
        onsubmit="return confirm(<?= e(json_encode('Move this invoice to ' . $to['name'] . '?')) ?>);">
    <input type="hidden" name="move_invoice" value="<?= $id ?>">
    <input type="hidden" name="to_project" value="<?= (int)$to['id'] ?>">
    <label>Pays for, on <?= e($to['name']) ?>
      <select name="position">
        <option value="" <?= $sel === '' ? 'selected' : '' ?>>no particular position (unattached)</option>
        <?php if ($mine): ?>
          <optgroup label="<?= e($senderName) ?>'s positions">
            <?php foreach ($mine as $ps): $v = (int)$ps['crew_id'] . ':' . (int)$ps['step_id']; ?>
              <option value="<?= e($v) ?>" <?= $sel === $v ? 'selected' : '' ?>><?= e($ps['label']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php if ($others): ?>
          <optgroup label="Somebody else's position">
            <?php foreach ($others as $ps): $v = (int)$ps['crew_id'] . ':' . (int)$ps['step_id']; ?>
              <option value="<?= e($v) ?>" <?= $sel === $v ? 'selected' : '' ?>><?= e($ps['label']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <optgroup label="A whole step, no position">
          <?php foreach ($toSteps as $st): $v = '0:' . (int)$st['id']; ?>
            <option value="<?= e($v) ?>" <?= $sel === $v ? 'selected' : '' ?>><?= e($st['name']) ?>, whole step</option>
          <?php endforeach; ?>
        </optgroup>
      </select>
    </label>
    <button type="submit">Move to <?= e($to['name']) ?></button>
    <a href="<?= e($back) ?>">Cancel</a>
  </form>
  <?php endif; ?>
</section>
<?php
}
