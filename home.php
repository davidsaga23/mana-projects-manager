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
// THREE TABS (David, 2026-10-30): "General info" (who they are and the shows
// they are on), "Work" (what to do and what was said about it) and "Invoice and
// billing" (what to put on the month's invoice, where to send it, and what has
// been received and paid). ?tab= says which; Work is the default, because it is
// the one opened every day.
//
// ⚠ THE ONE THING THAT POSTS IS THE PERSON'S OWN PROFILE (phone, bio,
// picture; migration 074). Statuses and time stay on My tasks and Spell, which
// this links to: two screens that both change a status can disagree.
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

$salaried = home_is_salaried($pdo, $crewId);

// --- save their own profile ----------------------------------------------------
// The crew id is effective_crew_id(), above, and nothing posted can change it.
// The allow-list entry in includes/auth.php is the first lock; this is the
// second, and it has nothing to check because there is no id to forge.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        flash('ok', home_save_profile($pdo, $crewId, $_POST, $_FILES['photo'] ?? null, !empty($_POST['remove_photo'])));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: home.php?tab=general');
    exit;
}

$tabs = ['general' => 'General info', 'work' => 'Work'];
if (!$salaried) $tabs['invoice'] = 'Invoice and billing';
$tab = (string)($_GET['tab'] ?? 'work');
if (!isset($tabs[$tab])) $tab = 'work';

$profile = home_profile($pdo, $crewId);
$personName = (string)($profile['name'] ?: 'You');
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

$notes = home_notes_waiting($pdo, $crewId, $userIds);

$dateFmt = fn(?string $d) => $d ? date('j M Y', strtotime($d)) : '—';
$shortDate = fn(?string $d) => $d ? date('D j M', strtotime($d)) : '—';
$projectNames = [];
foreach ($shows as $pid => $s) $projectNames[$pid] = (string)$s['project']['name'];

// --- what each tab reads, only for the tab on screen ------------------------------
if ($tab === 'work') {
    $tickets = [];
    try {
        $tickets = tickets_for_crew($pdo, $crewId);
    } catch (PDOException $e) {
        $tickets = [];   // tickets before migration 017
    }
    $work = home_work_groups(home_tasks($pdo, $crewId), $tickets, $today);
    $latestVersions = home_latest_versions($pdo, $crewId);
    $latestNotes = home_latest_notes($pdo, $crewId, $userIds);

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
}

// Money, for a freelancer only, and only their own.
$terms = null; $posTerms = []; $invoiceInfo = ['lines' => [], 'totals' => [], 'outside' => 0];
$invoices = []; $byTask = []; $statements = [];
if ($tab === 'invoice') {
    $terms = own_crew_term($crewId);
    // Their positions with a contract of their own (finance_install_13):
    // [project id][step id] => row, their own only.
    $posTerms = own_position_terms($crewId);
    $invoiceInfo = home_invoice_lines($pdo, $crewId, $shows, $terms, $monthFrom, $monthTo, $posTerms);
    // One row per invoice they sent: a monthly invoice's lines folded together.
    $invoices = home_group_invoices($crewId, own_invoices_all($crewId));
    $byTask = home_logged_by_task($pdo, $crewId, $monthFrom, $monthTo);
    // Each show's statement, the figures statement.php shows, read through the
    // same own-or-admin doors.
    foreach ($shows as $pid => $s) {
        $statements[$pid] = artist_statement($pdo, (int)$pid, $crewId, $terms, own_invoices($crewId, (int)$pid),
            null, home_fx($crewId, (int)$pid), $posTerms[$pid] ?? []);
    }
}

$pageTitle = 'Home';
require __DIR__ . '/includes/header.php';
$tabUrl = fn(string $t) => 'home.php?tab=' . $t . ($t === 'invoice' && $month !== date('Y-m') ? '&month=' . $month : '');
?>

<div class="home-head">
  <?php if (!empty($profile['photo_stored'])): ?>
    <img class="home-avatar" src="avatar.php?crew=<?= $crewId ?>" alt="">
  <?php else: ?>
    <span class="home-avatar home-avatar-blank" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($personName, 0, 1))) ?></span>
  <?php endif; ?>
  <h2 class="wizard-title">Home <span class="muted">· <?= e($personName) ?></span>
    <a class="button secondary statement-link" href="mytasks.php">My tasks</a>
  </h2>
</div>

<nav class="proj-tabs home-tabs" aria-label="Home">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= e($tabUrl($k)) ?>" class="proj-tab <?= $k === $tab ? 'current' : '' ?>"
       <?= $k === $tab ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php // ---- Missing log days: on every tab, because it is the one thing asked of them every day. ?>
<?php if ($missing): ?>
  <div class="flash flash-warn home-missing">
    You haven't logged <strong><?= e(home_days_phrase($missing, $today)) ?></strong>.
    <a href="mytasks.php">Log your time on My tasks →</a>
  </div>
<?php endif; ?>

<?php if ($tab === 'general'): ?>
  <?php // =================================== GENERAL INFO ============================ ?>
  <div class="home-profile">
    <table class="review-table statement-due">
      <tbody>
        <tr><th>Name</th><td><?= e($personName) ?></td></tr>
        <tr><th>Email</th><td><?= $profile['email'] ? e((string)$profile['email']) : '<span class="muted">none on file</span>' ?></td></tr>
        <?php if (home_profile_available($pdo)): ?>
          <tr><th>Phone</th><td><?= $profile['phone'] ? e((string)$profile['phone']) : '<span class="muted">not added</span>' ?></td></tr>
          <tr><th>Bio</th><td><?= $profile['bio'] ? nl2br(e((string)$profile['bio'])) : '<span class="muted">not written yet</span>' ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <p class="muted small">Your name and email are what Anima uses to reach you; to change them, ask Production.</p>

    <?php if (!home_profile_available($pdo)): ?>
      <p class="muted small">Adding a phone, a bio and a picture needs database update 074.</p>
    <?php else: ?>
      <details class="board-expand home-profile-edit" <?= (!$profile['phone'] && !$profile['bio'] && !$profile['photo_stored']) ? 'open' : '' ?>>
        <summary><span class="board-version-title">Edit your profile</span></summary>
        <form method="post" enctype="multipart/form-data" class="stack">
          <input type="hidden" name="save_profile" value="1">
          <label>Phone <span class="muted small">(optional)</span>
            <input name="phone" maxlength="50" value="<?= e((string)($profile['phone'] ?? '')) ?>" placeholder="+52 55 1234 5678"></label>
          <label>Bio <span class="muted small">(a few lines about you and your work)</span>
            <textarea name="bio" rows="4" maxlength="2000"><?= e((string)($profile['bio'] ?? '')) ?></textarea></label>
          <label>Profile picture <span class="muted small">(JPG, PNG, GIF or WebP)</span>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp"></label>
          <?php if (!empty($profile['photo_stored'])): ?>
            <label class="small"><input type="checkbox" name="remove_photo" value="1"> Remove my picture</label>
          <?php endif; ?>
          <div class="actions"><button type="submit">Save profile</button></div>
        </form>
      </details>
    <?php endif; ?>
  </div>

  <h3>Your shows</h3>
  <?php if (!$shows): ?>
    <div class="empty-state"><p>You are not on any show at the moment.</p></div>
  <?php else: ?>
    <table class="review-table home-shows">
      <thead><tr>
        <th>Show</th><th>Your position</th><th>Dates</th><th class="num">Days left</th>
        <th>Supervisor</th><th>References</th>
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
            <td class="small home-refs">
              <a href="spell.php?project=<?= (int)$pid ?>">Spell</a>
              · <a href="brief.php?project=<?= (int)$pid ?>">Brief</a>
              · <a href="standards.php?project=<?= (int)$pid ?>">Standards</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">Days left are working days to the end of your dates, today included. Questions about a
      show go to its supervisor, or to Production when it has none.</p>
  <?php endif; ?>

<?php elseif ($tab === 'work'): ?>
  <?php // =================================== WORK =================================== ?>
  <div class="dash-strip">
    <div><span class="muted small">Logged today</span><strong><?= e(fmt_hours($loggedToday)) ?></strong></div>
    <div><span class="muted small">This week</span><strong><?= e(fmt_hours($loggedWeek)) ?></strong></div>
    <div><span class="muted small">This month</span><strong><?= e(fmt_hours($loggedMonth)) ?></strong></div>
    <div class="<?= $notes ? 'tile-pending' : '' ?>"><span class="muted small">Notes waiting on you</span><strong><?= count($notes) ?></strong></div>
  </div>
  <?php if (!$missing && $loggedToday <= 0 && is_working_day($today)): ?>
    <p class="muted small">Nothing logged today yet. <a href="mytasks.php">Log your time on My tasks →</a></p>
  <?php endif; ?>

  <?php
  $groups = [
      'current' => ['Current tasks', 'What you have picked up: in progress, waiting for review or sent back.', $work['current']],
      'next'    => ["What's next", 'Not started yet, due this week or overdue.', $work['next']],
      'later'   => ['Lined up after', 'Later, and anything without a date.', array_slice($work['later'], 0, 15)],
  ];
  ?>
  <?php foreach ($groups as $key => [$label, $hint, $rows]): ?>
    <h3><?= e($label) ?> <span class="muted">· <?= count($key === 'later' ? $work['later'] : $rows) ?></span></h3>
    <?php if (!$rows): ?>
      <p class="muted small">Nothing here.</p>
    <?php else: ?>
      <p class="muted small"><?= e($hint) ?></p>
      <table class="review-table">
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="small <?= $r['late'] ? 'warn-text' : '' ?>" style="width:7em;"><?= e($shortDate($r['due'])) ?><?= $r['late'] ? ' · late' : '' ?></td>
              <td class="muted small"><?= e($r['project'] ?: '—') ?></td>
              <td><a href="<?= e($r['href']) ?>"><?= e($r['label'] ?: '—') ?></a></td>
              <td class="muted small"><?= e($r['status'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (count($work['later']) > 15): ?>
    <p class="muted small"><?= count($work['later']) - 15 ?> more on <a href="mytasks.php">My tasks</a>.</p>
  <?php endif; ?>

  <h3>Notes waiting on you <span class="muted">· <?= count($notes) ?></span></h3>
  <?php if (!$notes): ?>
    <p class="muted small">Nothing waiting. Every note on your tasks has had a reply or a new version.</p>
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

  <?php // MY VERSIONS (David, 2026-10-30): the latest version on each task you are on. ?>
  <h3>My versions <span class="muted">· <?= count($latestVersions) ?></span></h3>
  <?php if (!$latestVersions): ?>
    <p class="muted small">No versions on your open tasks yet.</p>
  <?php else: ?>
    <div class="home-versions">
      <?php foreach ($latestVersions as $v):
        $href = home_task_path((int)$v['project_id'], (int)$v['step_id'], (string)$v['item_key']); ?>
        <a class="home-version" href="<?= e($href) ?>">
          <?php if (attachment_is_image((string)($v['mime_type'] ?? ''))): ?>
            <img src="attachment.php?kind=version&amp;id=<?= (int)$v['id'] ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="home-version-file"><?= e(strtoupper(pathinfo((string)$v['original_name'], PATHINFO_EXTENSION) ?: 'file')) ?></span>
          <?php endif; ?>
          <span class="home-version-cap">
            <strong><?= e(trim(home_item_label($v) . ' · ' . $v['step_name'], ' ·')) ?></strong>
            <span class="muted small"><?= e(version_label((int)$v['version_no'])) ?> · <?= e($v['project_code'] ?? '') ?> · <?= e($shortDate((string)$v['created_at'])) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php // MY NOTES (David, 2026-10-30, "same as my versions"): the latest note on each task. ?>
  <h3>My notes <span class="muted">· <?= count($latestNotes) ?></span></h3>
  <?php if (!$latestNotes): ?>
    <p class="muted small">Nobody has left a note on your open tasks yet.</p>
  <?php else: ?>
    <table class="review-table">
      <tbody>
        <?php foreach ($latestNotes as $n): ?>
          <tr>
            <td class="muted small"><?= e($n['project_name']) ?></td>
            <td><a href="<?= e(home_task_path((int)$n['project_id'], (int)$n['step_id'], (string)$n['item_key'])) ?>">
                <?= e(trim(home_item_label($n) . ' · ' . $n['step_name'], ' ·')) ?></a>
              <?php if (!empty($n['version_id'])): ?><span class="muted small">· review note</span><?php endif; ?></td>
            <td class="small"><?= e((string)($n['author'] ?: 'Somebody')) ?>:
              <span class="muted"><?= e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$n['body'])), 0, 140, '…')) ?></span></td>
            <td class="muted small"><?= e($shortDate((string)$n['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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

<?php elseif ($tab === 'invoice'): ?>
  <?php // ================================ INVOICE AND BILLING ======================== ?>
  <p class="small home-month">
    <a href="home.php?tab=invoice&amp;month=<?= e($prevMonth) ?>">← <?= e(date('F', strtotime($prevMonth . '-01'))) ?></a>
    · <strong><?= e(date('F Y', strtotime($monthFrom))) ?></strong>
    <?php if ($nextMonth <= date('Y-m')): ?>
      · <a href="home.php?tab=invoice&amp;month=<?= e($nextMonth) ?>"><?= e(date('F', strtotime($nextMonth . '-01'))) ?> →</a>
    <?php endif; ?>
  </p>

  <?php // INSTRUCTIONS FOR THE INVOICE, and where it goes (David, 2026-10-30). ?>
  <div class="flash flash-ok home-howto">
    <strong>How to invoice</strong>
    <ul>
      <?php foreach (home_invoice_instructions() as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
    </ul>
  </div>

  <?php
  // The email: to accounting, copied to the people $config['invoice_cc'] names
  // (David by default), with the month's lines already written in. The PDF is
  // still theirs to attach.
  $lineTexts = array_map('home_invoice_line_text', $invoiceInfo['lines']);
  $totalText = implode(' + ', array_map(fn($c, $a) => home_money((float)$a, (string)$c),
                    array_keys($invoiceInfo['totals']), $invoiceInfo['totals']));
  $allText = implode("\n", $lineTexts) . ($totalText !== '' ? "\nTotal: " . $totalText : '');
  $subject = 'Invoice · ' . $personName . ' · ' . date('F Y', strtotime($monthFrom));
  $mailto = 'mailto:' . HOME_INVOICE_TO . '?cc=' . rawurlencode(implode(',', home_invoice_cc()))
          . '&subject=' . rawurlencode($subject)
          . '&body=' . rawurlencode("Hello,\n\nPlease find my invoice for " . date('F Y', strtotime($monthFrom))
                                    . " attached.\n\n" . $allText . "\n\nThank you,\n" . $personName);
  ?>
  <p class="home-send">
    <a class="button" href="<?= e($mailto) ?>">Write the email to <?= e(HOME_INVOICE_TO) ?></a>
    <span class="muted small">Copied to <?= e(implode(', ', home_invoice_cc())) ?>. Attach your invoice PDF before sending.</span>
  </p>

  <h3>What to put on your invoice <span class="muted">· <?= e(date('F Y', strtotime($monthFrom))) ?></span></h3>
  <p class="muted small">One line per project and step. Click <strong>Copy</strong> to put a line on your clipboard and
    paste it into your invoice. It is information for your own invoice, not an invoice.
    <?= $monthTo < $monthEnd ? 'The month is not over yet: these figures count up to today.' : '' ?></p>
  <?php if (!$invoiceInfo['lines']): ?>
    <p class="muted">No time logged on your shows in <?= e(date('F', strtotime($monthFrom))) ?>.</p>
  <?php else: ?>
    <table class="review-table home-invoice">
      <thead><tr><th>Project</th><th>Step</th><th>Rate</th><th class="num">Days / units</th><th class="num">Line total</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($invoiceInfo['lines'] as $i => $l): ?>
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
            <td><button type="button" class="row-btn home-copy" data-copy="<?= e($lineTexts[$i]) ?>">Copy</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php foreach ($invoiceInfo['totals'] as $cur => $amt): ?>
          <tr class="statement-left"><th colspan="4">Month total<?= count($invoiceInfo['totals']) > 1 ? ' in ' . e($cur) : '' ?></th>
            <td class="num"><strong><?= e(home_money($amt, $cur)) ?></strong></td><td></td></tr>
        <?php endforeach; ?>
        <tr><td colspan="6"><button type="button" class="row-btn home-copy" data-copy="<?= e($allText) ?>">Copy every line</button></td></tr>
      </tfoot>
    </table>
    <?php if (count($invoiceInfo['totals']) > 1): ?>
      <p class="warn-text small">These lines are in more than one currency: a show has no exchange rate fixed
        for your contract yet. Ask Production before you send the invoice.</p>
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
      <thead><tr><th>Project</th><th>Reference</th><th>Period</th><th class="num">Amount</th><th>Status</th><th>Received</th><th>Paid on</th></tr></thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><?= e(implode(', ', array_map(fn($pid, $code) => $projectNames[$pid] ?? ($code !== '' ? $code : '—'),
                    array_keys($inv['projects']), $inv['projects'])) ?: '—') ?></td>
            <td><?= e((string)($inv['invoice_ref'] ?? '') ?: '—') ?></td>
            <td class="muted small"><?= e($dateFmt($inv['period_start'] ?? null)) ?> – <?= e($dateFmt($inv['period_end'] ?? null)) ?></td>
            <td class="num"><?= e(implode(' + ', array_map(fn($c, $a) => home_money((float)$a, (string)$c),
                    array_keys($inv['amounts']), $inv['amounts']))) ?></td>
            <td class="<?= ($inv['status'] ?? '') === 'paid' ? 'ok' : 'muted' ?>"><?= e(home_invoice_status_short($inv)) ?></td>
            <td class="muted small"><?= e($dateFmt($inv['issued_date'] ?? null)) ?></td>
            <td class="small"><?= e($dateFmt($inv['paid_date'] ?? null)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3>Logged time</h3>
  <div class="dash-strip">
    <div><span class="muted small">Today</span><strong><?= e(fmt_hours($loggedToday)) ?></strong></div>
    <div><span class="muted small">This week</span><strong><?= e(fmt_hours($loggedWeek)) ?></strong></div>
    <div><span class="muted small"><?= e(date('F', strtotime($monthFrom))) ?></span>
      <strong><?= e(fmt_hours(logged_total_for_crew($pdo, $crewId, null, $monthFrom, $monthTo))) ?></strong></div>
    <div><span class="muted small">All time</span><strong><?= e(fmt_hours(logged_total_for_crew($pdo, $crewId))) ?></strong></div>
  </div>
  <?php if ($byTask): ?>
    <table class="review-table">
      <thead><tr><th>Show</th><th>Task</th><th class="num">Logged in <?= e(date('F', strtotime($monthFrom))) ?></th><th>Last day</th></tr></thead>
      <tbody>
        <?php foreach ($byTask as $b): ?>
          <tr>
            <td class="muted small"><?= e((string)($b['project_name'] ?? '—')) ?></td>
            <td><?= e(time_log_target($b)) ?></td>
            <td class="num"><?= e(fmt_hours((int)$b['minutes'])) ?></td>
            <td class="muted small"><?= e($shortDate((string)$b['last_day'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted small">Nothing logged in <?= e(date('F', strtotime($monthFrom))) ?>.</p>
  <?php endif; ?>

  <h3>Statements</h3>
  <?php if (!$statements): ?>
    <p class="muted small">You are not on any show at the moment.</p>
  <?php else: ?>
    <table class="review-table">
      <thead><tr><th>Show</th><th class="num">Logged</th><th class="num">Due so far</th><th class="num">Invoiced</th><th class="num">Left to invoice</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($statements as $pid => $st): ?>
          <tr>
            <td><?= e($projectNames[$pid] ?? '') ?></td>
            <td class="num"><?= e(fmt_num($st['hours'], 1)) ?> h</td>
            <td class="num"><?= e(home_money($st['due'], (string)$st['currency'])) ?></td>
            <td class="num"><?= e(home_money($st['invoiced'], (string)$st['currency'])) ?></td>
            <td class="num"><strong><?= e(home_money($st['left'], (string)$st['currency'])) ?></strong></td>
            <td><a href="statement.php?project=<?= (int)$pid ?>">Statement →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">A statement counts everything inside your dates on the show; the lines above are one month.</p>
  <?php endif; ?>

  <?php // YOUR CONTRACT(S): a position with its own contract (finance_install_13) on a
        // row of its own, then the default for everything else. Their own only. ?>
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

  <script>
    // COPY A LINE (David, 2026-10-30): "a click and copy item line to include in
    // the invoice". The clipboard can be refused (an insecure origin, a browser
    // policy), so a refusal falls back to a prompt the text can be copied from.
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('.home-copy') : null;
      if (!b) return;
      var text = b.getAttribute('data-copy') || '';
      var done = function (ok) {
        var was = b.textContent;
        b.textContent = ok ? 'Copied' : 'Copy failed';
        setTimeout(function () { b.textContent = was; }, 1400);
        if (!ok) window.prompt('Copy this:', text);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
      } else {
        done(false);
      }
    });
  </script>
<?php endif; ?>

</div></body></html>
