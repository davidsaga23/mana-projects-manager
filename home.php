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
// picture; migration 075). Statuses and time stay on My tasks and Spell, which
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
require_once __DIR__ . '/includes/day_off_requests.php';
require_once __DIR__ . '/includes/digest.php';   // which email summaries you get (104)
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

// --- which email summaries you get ------------------------------------------
// David, 2026-11-17: two email summaries a day instead of an email per thing
// (includes/digest.php). Everybody gets both until they choose otherwise.
// ⚠ ALWAYS YOUR OWN: the person is $crewId, effective_crew_id(), and nothing
// posted names anybody. The allow-list entry in includes/auth.php is the first
// lock; this is the second.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_digest_pref'])) {
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        flash('ok', digest_set_pref($pdo, $crewId, (string)($_POST['digest_pref'] ?? '')));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: home.php?tab=general#email-summaries');
    exit;
}

// --- ask for days off, or take back a request nobody has answered -----------
// David, 2026-11-16: "a button to request days off in peoples home page, that
// one need to be sent to production or admins for approval". ⚠ THE PERSON IS
// ALWAYS $crewId, effective_crew_id(): asking names nobody, and withdrawing
// deletes only WHERE crew_id is this person's AND the request is still pending
// (withdraw_day_off_request()). Those are the second lock; the allow-list in
// includes/auth.php is the first.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['request_day_off']) || isset($_POST['withdraw_day_off']))) {
    try {
        if (preview_blocks_saving()) throw new RuntimeException('Nothing saves while you are previewing a role.');
        flash('ok', isset($_POST['withdraw_day_off'])
            ? withdraw_day_off_request($pdo, $crewId, (int)$_POST['withdraw_day_off'])
            : request_day_off($pdo, $crewId, (string)($_POST['from_date'] ?? ''), (string)($_POST['to_date'] ?? ''),
                              (string)($_POST['reason'] ?? ''), current_user_display()));
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: home.php?tab=general#days-off');
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
// ⚠ EACH PART IS READ ON ITS OWN, AND A FAILURE STAYS IN ITS PART (David,
// 2026-10-30: the tab answered HTTP 500 on the live site). One part that cannot
// be read — a finance table one import behind, a show whose statement throws —
// must not take the whole tab down with it. The part says so on the page, an
// admin sees why, and the server log gets the full message.
$invoiceErrors = [];
$tryPart = function (string $part, callable $fn, $fallback) use (&$invoiceErrors) {
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[Anima home invoice: ' . $part . '] ' . $e->getMessage() . ' in '
                  . $e->getFile() . ':' . $e->getLine());
        $invoiceErrors[$part] = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        return $fallback;
    }
};
if ($tab === 'invoice') {
    if (function_exists('set_time_limit')) @set_time_limit(90);   // a statement per show adds up
    // ...so the session is let go while they are worked out, and this person's
    // other tabs, the bell and the Realm are not queued behind them (KnownHost,
    // 2026-11-19; session_release() in includes/auth.php). Taken back below,
    // before the page is printed.
    session_release();
    $terms = $tryPart('your contract', fn() => own_crew_term($crewId), null);
    // Their positions with a contract of their own (finance_install_13):
    // [project id][step id] => row, their own only.
    $posTerms = $tryPart('your position contracts', fn() => own_position_terms($crewId), []);
    $invoiceInfo = $tryPart('the invoice lines',
        fn() => home_invoice_lines($pdo, $crewId, $shows, $terms, $monthFrom, $monthTo, $posTerms), $invoiceInfo);
    // One row per invoice they sent: a monthly invoice's lines folded together.
    $invoices = $tryPart('your invoices', fn() => home_group_invoices($crewId, own_invoices_all($crewId)), []);
    $byTask = $tryPart('logged time per task', fn() => home_logged_by_task($pdo, $crewId, $monthFrom, $monthTo), []);
    // Each show's statement, the figures statement.php shows, read through the
    // same own-or-admin doors.
    foreach ($shows as $pid => $show) {
        $st = $tryPart('the statement for ' . $show['project']['name'],
            fn() => artist_statement($pdo, (int)$pid, $crewId, $terms, own_invoices($crewId, (int)$pid),
                                     null, home_fx($crewId, (int)$pid), $posTerms[$pid] ?? []), null);
        if ($st) $statements[$pid] = $st;
    }
}

session_resume();   // a no-op unless the invoice tab let the session go above
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
    <?php if (day_off_requests_available($pdo)): ?>
      <button type="button" class="secondary statement-link" id="dayOffOpen">Request days off</button>
    <?php endif; ?>
  </h2>
</div>

<?php if (day_off_requests_available($pdo)): ?>
  <?php // The request form, a pop-up so the button works from every tab. ?>
  <link rel="stylesheet" href="assets/days_off.css<?= asset_ver('days_off.css') ?>">
  <dialog class="do-dialog" id="dayOffDialog" aria-labelledby="dayOffTitle">
    <form method="post" class="stack">
      <input type="hidden" name="request_day_off" value="1">
      <h3 id="dayOffTitle">Request days off</h3>
      <p class="muted small">Production or an administrator will approve or decline it, and you will be told
        in the bell and by email. Until it is approved, the days still count as working days.</p>
      <div class="row">
        <label>First day <input type="date" name="from_date" required></label>
        <label>Last day <input type="date" name="to_date"></label>
      </div>
      <label>Reason <input name="reason" maxlength="120" list="dayOffReasons" placeholder="e.g. Holiday"></label>
      <datalist id="dayOffReasons"><option value="Holiday"><option value="Sick"><option value="Personal"><option value="Course"></datalist>
      <p class="muted small">Leave the last day blank for a single day.</p>
      <div class="actions">
        <button type="submit">Send request</button>
        <button type="button" class="secondary" id="dayOffCancel">Cancel</button>
      </div>
    </form>
  </dialog>
  <script>
  (function () {
    var d = document.getElementById('dayOffDialog');
    var open = document.getElementById('dayOffOpen');
    if (!d || !open) return;
    if (typeof d.showModal !== 'function') {   // a browser without <dialog>: send them to the list
      open.addEventListener('click', function () { location.href = 'home.php?tab=general#days-off'; });
      return;
    }
    open.addEventListener('click', function () { d.showModal(); });
    document.getElementById('dayOffCancel').addEventListener('click', function () { d.close(); });
  })();
  </script>
<?php endif; ?>

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
    <?php // THREE COLUMNS (David, 2026-10-30): the picture in a square on the far
          // left, the details in the middle, the bio to the right of them. ?>
    <div class="home-profile-grid">
      <div class="home-photo">
        <?php if (!empty($profile['photo_stored'])): ?>
          <img src="avatar.php?crew=<?= $crewId ?>" alt="<?= e($personName) ?>">
        <?php else: ?>
          <span class="home-photo-blank"><?= e(mb_strtoupper(mb_substr($personName, 0, 1))) ?></span>
        <?php endif; ?>
      </div>
      <div>
        <table class="review-table statement-due">
          <tbody>
            <tr><th>Name</th><td><?= e($personName) ?></td></tr>
            <tr><th>Email</th><td><?= $profile['email'] ? e((string)$profile['email']) : '<span class="muted">none on file</span>' ?></td></tr>
            <?php if (home_profile_available($pdo)): ?>
              <tr><th>Phone</th><td><?= $profile['phone'] ? e((string)$profile['phone']) : '<span class="muted">not added</span>' ?></td></tr>
            <?php endif; ?>
            <?php if (birthday_available($pdo)):
                  $bdLabel = birthday_label((int)$profile['birth_month'], (int)$profile['birth_day']); ?>
              <tr><th>Birthday</th><td><?= $bdLabel !== '' ? e($bdLabel) : '<span class="muted">not added</span>' ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        <p class="muted small">Your email is where Anima reaches you, and it prints on your invoices: change it under
          <strong>Edit your profile</strong>. To change your name, ask Production.</p>
      </div>
      <?php if (home_profile_available($pdo)): ?>
        <div class="home-bio">
          <h4>Bio</h4>
          <?= $profile['bio'] ? '<p>' . nl2br(e((string)$profile['bio'])) . '</p>' : '<p class="muted">Not written yet.</p>' ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!home_profile_available($pdo)): ?>
      <p class="muted small">Adding a phone, a bio and a picture needs database update 075.</p>
    <?php else: ?>
      <details class="board-expand home-profile-edit" <?= (!$profile['phone'] && !$profile['bio'] && !$profile['photo_stored']) ? 'open' : '' ?>>
        <summary><span class="board-version-title">Edit your profile</span></summary>
        <form method="post" enctype="multipart/form-data" class="stack">
          <input type="hidden" name="save_profile" value="1">
          <?php // David, 2026-11-16: "let's include email to the invoice details and to the profile".
                // The one address Anima has for them (crew.email): notifications, invites, and
                // the From block of their invoice all use it, so there is no second copy. ?>
          <label>Email
            <input type="email" name="email" maxlength="190" required value="<?= e((string)($profile['email'] ?? '')) ?>" placeholder="you@example.com"></label>
          <label>Phone <span class="muted small">(optional)</span>
            <input name="phone" maxlength="50" value="<?= e((string)($profile['phone'] ?? '')) ?>" placeholder="+52 55 1234 5678"></label>
          <label>Bio <span class="muted small">(a few lines about you and your work)</span>
            <textarea name="bio" rows="4" maxlength="2000"><?= e((string)($profile['bio'] ?? '')) ?></textarea></label>
          <?php if (birthday_available($pdo)):
                // David, 2026-11-16: month and day only, never the year. Nobody
                // else sees the date: on the day the Realm decorates your house. ?>
            <fieldset class="home-birthday" style="border:0;padding:0;margin:0">
              <legend>Birthday <span class="muted small">(optional: month and day, no year)</span></legend>
              <select name="birth_month" aria-label="Birthday month">
                <option value="0">Month</option>
                <?php for ($bmI = 1; $bmI <= 12; $bmI++): ?>
                  <option value="<?= $bmI ?>" <?= (int)$profile['birth_month'] === $bmI ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $bmI, 1, 2000))) ?></option>
                <?php endfor; ?>
              </select>
              <select name="birth_day" aria-label="Birthday day">
                <option value="0">Day</option>
                <?php for ($bdI = 1; $bdI <= 31; $bdI++): ?>
                  <option value="<?= $bdI ?>" <?= (int)$profile['birth_day'] === $bdI ? 'selected' : '' ?>><?= $bdI ?></option>
                <?php endfor; ?>
              </select>
              <p class="muted small">Nobody sees the date. On the day (or the Friday before, if it falls on a weekend) your house in the Realm is decorated so everyone knows.</p>
            </fieldset>
          <?php endif; ?>
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

  <?php // ---- Your days off: what you asked for and the answer, and what is coming up.
        // David, 2026-11-16. Approved ones are the real days off (crew_absences);
        // a request is only a question until Production answers it. ?>
  <?php if (day_off_requests_available($pdo)):
        $myRequests = day_off_requests_for_crew($pdo, $crewId, 10);
        $myComing = array_values(array_filter(crew_absences($pdo, $crewId),
            function ($a) { return (string)$a['to_date'] >= date('Y-m-d'); })); ?>
    <h3 id="days-off">Your days off</h3>
    <?php if (!$myRequests && !$myComing): ?>
      <p class="muted small">Nothing asked for and nothing coming up. Use <strong>Request days off</strong> at the top.</p>
    <?php else: ?>
      <table class="review-table home-days-off">
        <thead><tr><th>Days</th><th class="num">Working days</th><th>Reason</th><th>State</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($myRequests as $r): if ($r['state'] === 'approved') continue; ?>
          <tr>
            <td><?= e(day_off_range_phrase((string)$r['from_date'], (string)$r['to_date'])) ?></td>
            <td class="num"><?= working_days_between((string)$r['from_date'], (string)$r['to_date']) ?></td>
            <td><?= e((string)($r['reason'] ?? '')) ?: '<span class="muted">—</span>' ?></td>
            <td>
              <?php if ($r['state'] === 'pending'): ?>
                <span class="do-state do-state-pending">Waiting for approval</span>
              <?php else: ?>
                <span class="do-state do-state-declined">Declined</span>
                <span class="muted small">by <?= e((string)$r['decided_by']) ?><?= $r['note'] ? ': ' . e((string)$r['note']) : '' ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['state'] === 'pending'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Withdraw this request?');">
                  <input type="hidden" name="withdraw_day_off" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="linkish">Withdraw</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($myComing as $a): ?>
          <tr>
            <td><?= e(day_off_range_phrase((string)$a['from_date'], (string)$a['to_date'])) ?></td>
            <td class="num"><?= working_days_between((string)$a['from_date'], (string)$a['to_date']) ?></td>
            <td><?= e((string)($a['reason'] ?? '')) ?: '<span class="muted">—</span>' ?></td>
            <td><span class="do-state do-state-approved">Approved</span></td>
            <td></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted small">Approved days off are never counted as days you forgot to log. To change one that is
        already approved, ask Production.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php // ---- Email summaries: which of the two daily emails you get. David,
        // 2026-11-17. Before migration 104 the section is not drawn at all. ?>
  <?php if (digest_available($pdo)): $myDigest = digest_pref($pdo, $crewId); ?>
    <h3 id="email-summaries">Email summaries</h3>
    <form method="post" class="home-digest">
      <input type="hidden" name="save_digest_pref" value="1">
      <label>Anima emails you a summary of what is new for you: mentions, new tasks, comments and review
        notes, Realm messages.
        <select name="digest_pref">
          <?php foreach (digest_pref_options() as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $k === $myDigest ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">Save</button>
    </form>
    <p class="muted small">A summary is only sent when something is new, and leaves out anything you have already
      seen in the bell. <?= $profile['email'] ? 'It goes to ' . e((string)$profile['email']) . '.' : 'You have no email address on file, so nothing can be sent: add one under <strong>Edit your profile</strong>.' ?></p>
  <?php endif; ?>

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
                <div class="muted small">from your departments' dates; no contract dates yet</div>
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
            <?php // Buttons, and the third one onto the Brief (David, 2026-11-11): "Standards"
                  // went to standards.php, a forwarding address to Dates & headcount since
                  // the ideal-quotas tab was removed (2026-10-16), which is planning, not a
                  // reference. The show's own rules for the work live on the Brief, and its
                  // naming convention has an anchor there. ?>
            <td class="small home-refs">
              <a class="row-btn" href="spell.php?project=<?= (int)$pid ?>">Spell</a>
              <a class="row-btn" href="brief.php?project=<?= (int)$pid ?>">Brief</a>
              <a class="row-btn" href="brief.php?project=<?= (int)$pid ?>#naming">Naming convention</a>
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
<?php ob_start(); try { ?>
  <?php // ================================ INVOICE AND BILLING ======================== ?>
  <p class="small home-month">
    <a href="home.php?tab=invoice&amp;month=<?= e($prevMonth) ?>">← <?= e(date('F', strtotime($prevMonth . '-01'))) ?></a>
    · <strong><?= e(date('F Y', strtotime($monthFrom))) ?></strong>
    <?php if ($nextMonth <= date('Y-m')): ?>
      · <a href="home.php?tab=invoice&amp;month=<?= e($nextMonth) ?>"><?= e(date('F', strtotime($nextMonth . '-01'))) ?> →</a>
    <?php endif; ?>
  </p>

  <?php if ($invoiceErrors): ?>
    <div class="flash flash-err">
      Part of this page could not be read: <?= e(implode(', ', array_keys($invoiceErrors))) ?>.
      The rest is below. <?= is_admin() || in_preview() ? '' : 'Production has been told in the server log.' ?>
      <?php if (is_admin() || in_preview()): ?>
        <ul class="small"><?php foreach ($invoiceErrors as $part => $why): ?><li><strong><?= e($part) ?>:</strong> <?= e($why) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php // INSTRUCTIONS FOR THE INVOICE, and where it goes (David, 2026-10-30). ?>
  <div class="flash flash-ok home-howto">
    <strong>How to invoice</strong>
    <ul>
      <?php foreach (home_invoice_instructions() as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
    </ul>
  </div>

  <?php // THE STUDIO'S DETAILS AND THE ARTIST'S (David, 2026-10-30). ?>
  <div class="home-invoice-details">
    <div>
      <h4>Address the invoice to</h4>
      <?php $company = home_invoice_company();
            $companyText = implode("\n", array_map(fn($k, $v) => $k . ': ' . $v, array_keys($company), $company)); ?>
      <table class="review-table statement-due">
        <tbody>
          <?php foreach ($company as $k => $v): ?><tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?>
        </tbody>
      </table>
      <button type="button" class="row-btn home-copy" data-copy="<?= e($companyText) ?>">Copy the company details</button>
    </div>
    <div>
      <h4>Your details, on every invoice</h4>
      <ul class="home-checklist">
        <?php foreach (home_invoice_artist_fields() as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
      </ul>
      <p class="muted small">Only the ones that apply to your bank and country. Save them once on
        <a href="invoice.php<?= $month !== date('Y-m') ? '?month=' . e($month) : '' ?>">your invoice</a> and Anima puts them on every month's.</p>
    </div>
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
  <?php // THE INVOICE ITSELF (David, 2026-11-11): Anima fills it in from the lines below. ?>
  <p class="home-send">
    <a class="button" href="invoice.php<?= $month !== date('Y-m') ? '?month=' . e($month) : '' ?>">Make my invoice for <?= e(date('F', strtotime($monthFrom))) ?></a>
    <span class="muted small">Filled in from your logged days, ready to save as a PDF.</span>
  </p>
  <p class="home-send">
    <a class="button secondary" href="<?= e($mailto) ?>">Write the email to <?= e(HOME_INVOICE_TO) ?></a>
    <span class="muted small">Copied to <?= e(implode(', ', home_invoice_cc())) ?>. Attach your invoice PDF before sending.</span>
  </p>

  <h3>What to put on your invoice <span class="muted">· <?= e(date('F Y', strtotime($monthFrom))) ?></span></h3>
  <p class="muted small">One line per project and department. Click <strong>Copy</strong> to put a line on your clipboard and
    paste it into your invoice. It is information for your own invoice, not an invoice.
    <?= $monthTo < $monthEnd ? 'The month is not over yet: these figures count up to today.' : '' ?></p>
  <?php if (!$invoiceInfo['lines']): ?>
    <p class="muted">No time logged on your shows in <?= e(date('F', strtotime($monthFrom))) ?>.</p>
  <?php else: ?>
    <table class="review-table home-invoice">
      <thead><tr><th>Project</th><th>Department</th><th>Rate</th><th class="num">Days / units</th><th class="num">Line total</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($invoiceInfo['lines'] as $i => $l): ?>
          <tr>
            <td><?= e($l['project']['name']) ?></td>
            <td><?= e($l['step']) ?><?= !empty($l['own_contract']) ? ' <span class="muted small">· own contract</span>' : '' ?>
              <?php if (!empty($l['dates'])): ?><div class="muted small">Days worked: <?= e(home_days_worked($l['dates'])) ?></div><?php endif; ?></td>
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
      <thead><tr><th>Show</th><th class="num">Logged</th><th class="num">Days left</th><th class="num">Earned so far</th><th class="num">Invoiced</th><th class="num">Left to invoice</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($statements as $pid => $st): ?>
          <tr>
            <td><?= e($projectNames[$pid] ?? '') ?></td>
            <td class="num"><?= e(fmt_num($st['hours'], 1)) ?> h</td>
            <?php // Planned less logged, as on the statement (2026-11-11). ?>
            <td class="num"><?= $st['days_left'] < -0.005 ? e(fmt_num(-$st['days_left'], 2)) . ' over' : e(fmt_num(max(0.0, $st['days_left']), 2)) ?></td>
            <td class="num"><?= e(home_money($st['due'], (string)$st['currency'])) ?></td>
            <td class="num"><?= e(home_money($st['invoiced'], (string)$st['currency'])) ?></td>
            <td class="num"><strong><?= e(home_money($st['left'], (string)$st['currency'])) ?></strong></td>
            <td><a href="statement.php?project=<?= (int)$pid ?>">Statement →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">A statement counts everything inside your dates on the show; the lines above are one month.
      Left to invoice is the planned work you have not logged yet, at your rate.</p>
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
<?php
    echo ob_get_clean();
  } catch (Throwable $e) {
    // Drawing the tab failed part-way: what was drawn is thrown away rather
    // than left half on screen, and the reason is said (2026-10-30).
    ob_end_clean();
    error_log('[Anima home invoice: drawing] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo '<div class="flash flash-err">This tab could not be drawn.'
       . ((is_admin() || in_preview()) ? ' ' . e($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '')
       . '</div>';
  } ?>
<?php endif; ?>

</div></body></html>
