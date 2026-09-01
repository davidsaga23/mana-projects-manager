<?php
// The artist database — everybody ever entered as a candidate, across every show.
//
// This data already existed and had nowhere to be read. Every name typed into
// "Add candidates" on the Hiring screen goes into `hires`, and known_candidates()
// has always gathered them across projects — but only to feed the autocomplete
// that stops you entering the same person twice. David, 2026-08-31: "where can
// I see all the artist database I'm filling in the add candidates under hiring?"
// Nowhere, until now.
//
// STUDIO-WIDE, like the step library and the ideal quotas. A freelancer is not
// the property of the show that first spoke to them; the whole value of writing
// them down is that the next show can find them.
//
// ONE ROW PER PERSON, not per approach. `hires` records a conversation about a
// SLOT, so a person approached for three jobs is three rows — see DECISIONS,
// 2026-08-17, where the duplication was accepted deliberately rather than
// normalised into a separate freelancers table.
//
// THAT SHAPE DECIDES HOW EDITING WORKS. Correcting somebody's email here is a
// fact about the PERSON, so it is written to every row bearing their name, not
// only the newest — otherwise the older rows keep showing the old address on the
// projects that hold them. What stays per-approach is exactly what belongs to
// one: the stage, the slot, the step, the project.
//
// ⚠ THE DAY RATE IS NOT IN THIS DATABASE. It lives in the finance database
// behind includes/money.php, on `hire_quotes`, and is only ever visible to an
// administrator. The standing rule is that a rate never gets a column in the
// production schema "just for now" — this screen does not break it.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hiring.php';
require_once __DIR__ . '/includes/artists.php';
require_once __DIR__ . '/includes/money.php';
require_once __DIR__ . '/includes/xlsx.php';
require_login();

$ready = hiring_available($pdo);

// The optional columns arrive with migration 025, and 030 makes the project
// optional. Both are probed rather than assumed, so the screen degrades one
// feature at a time instead of failing whole.
$extra = [];
foreach (['location', 'position', 'seniority', 'project_type', 'worked_with_us', 'rating',
          'portfolio_password'] as $c) {
    if (column_exists($pdo, 'hires', $c)) $extra[] = $c;
}
$canAddFree = $ready && column_is_nullable($pdo, 'hires', 'project_id');
$has = function (string $c) use ($extra) { return in_array($c, $extra, true); };

$selfUrl = 'artists.php';

// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!$ready) throw new RuntimeException('The hiring database updates have not been applied yet.');

        if ($action === 'save_artist') {
            $orig = trim((string)($_POST['orig_name'] ?? ''));
            $name = trim((string)($_POST['candidate_name'] ?? ''));
            if ($orig === '') throw new RuntimeException('No artist named.');
            if ($name === '') throw new RuntimeException('An artist needs a name.');

            $n = artists_save_person($pdo, $orig, $_POST, $extra);
            flash('ok', sprintf('Saved %s across %d record%s.', $name, $n, $n === 1 ? '' : 's'));

            // The rate goes through the one door, onto their most recent
            // approach — a quote belongs to a conversation, and the latest one
            // is what they are asking now.
            if (can_see_money() && array_key_exists('quoted_rate', $_POST)) {
                $hid = (int)($_POST['hire_id'] ?? 0);
                if ($hid > 0) flash('ok', save_hire_quote($hid, $name, null, $_POST, current_user_display()));
            }

        } elseif ($action === 'add_artists') {
            if (!$canAddFree) {
                throw new RuntimeException(
                    'Adding an artist without a project needs database update 030. Until it is '
                    . 'applied, add people from a project\'s Hiring screen.'
                );
            }
            $r = artists_add_bulk($pdo, is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [], $extra);
            if ($r['added']) flash('ok', sprintf('Added %d artist%s.', $r['added'], $r['added'] === 1 ? '' : 's'));
            foreach ($r['skipped'] as $s) flash('warn', $s);
            if (!$r['added'] && !$r['skipped']) flash('warn', 'Nothing had a name in it, so nothing was added.');

        } elseif ($action === 'import') {
            if (!$canAddFree) {
                throw new RuntimeException(
                    'Importing needs database update 030, which lets an artist exist before any '
                    . 'project wants them.'
                );
            }
            if (!isset($_FILES['sheet']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose an .xlsx file to import.');
            }
            $rows = xlsx_read_rows($_FILES['sheet']['tmp_name']);
            if (!$rows) throw new RuntimeException('That workbook had no rows in its first sheet.');

            $r = artists_import_rows($pdo, $rows, $extra, !empty($_POST['overwrite']));

            if ($r['added'])   flash('ok', sprintf('Added %d artist%s.', $r['added'], $r['added'] === 1 ? '' : 's'));
            if ($r['updated']) flash('ok', sprintf('Updated %d already in the list.', $r['updated']));
            if ($r['skipped']) {
                flash('warn', sprintf('%d were already in the list and were left alone. Tick '
                                    . '"update people already here" to bring them in line.', $r['skipped']));
            }
            if ($r['dupes']) {
                flash('warn', sprintf('%d name%s appear twice IN THE SHEET, so the second was '
                                    . 'skipped: %s. Merging two rows means choosing which notes to '
                                    . 'lose, which is not a choice to make for you.',
                                    count($r['dupes']), count($r['dupes']) === 1 ? '' : 's',
                                    implode(', ', array_slice($r['dupes'], 0, 8))
                                        . (count($r['dupes']) > 8 ? ' …' : '')));
            }
            // Reported, never stored. Same answer the paste parser gave this
            // column on 2026-08-17.
            if ($r['passwords']) {
                flash('ok', sprintf('%d row%s carried a reel password, saved beside the link. It '
                                  . 'opens the artist's own showreel page, nothing of ours.',
                                  $r['passwords'], $r['passwords'] === 1 ? '' : 's'));
            }
            // The rates go through the one door, and only for somebody who can
            // open it.
            if ($r['rates']) {
                if (!can_see_money()) {
                    flash('warn', sprintf('%d row%s carried a rate. Rates live in the finance '
                                        . 'database, which this account cannot open, so they were '
                                        . 'not imported. An administrator can run the import again '
                                        . 'to bring them in.',
                                        count($r['rates']), count($r['rates']) === 1 ? '' : 's'));
                } else {
                    $saved = 0;
                    foreach ($r['rates'] as $rt) {
                        try {
                            save_hire_quote((int)$rt['id'], $rt['name'], null,
                                            ['quoted_rate' => $rt['rate'], 'rate_unit' => 'day',
                                             'rate_currency' => 'USD'], current_user_display());
                            $saved++;
                        } catch (Throwable $e) {
                            // One unreadable number must not lose the other sixteen.
                            flash('warn', $rt['name'] . ': ' . $e->getMessage());
                        }
                    }
                    if ($saved) flash('ok', sprintf('%d day rate%s recorded in the finance database.',
                                                    $saved, $saved === 1 ? '' : 's'));
                }
            }
            if (!$r['added'] && !$r['updated'] && !$r['skipped']) {
                flash('warn', 'Nothing in that sheet had a name in the first column.');
            }

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: ' . $selfUrl);
    exit;
}

$q   = trim((string)($_GET['q'] ?? ''));
$pos = trim((string)($_GET['position'] ?? ''));
$positions = $ready ? artists_positions($pdo) : [];
// A position that no longer exists would silently show nobody, so it is dropped
// rather than left filtering against nothing.
if ($pos !== '' && !in_array($pos, $positions, true)) $pos = '';

[$people, $approaches] = $ready ? artists_list($pdo, $q, $extra, $pos) : [[], []];

// Their quotes, for the rate column. One query for the page, admin only —
// finance_db() hands a non-admin nothing, so this is empty for everyone else.
$quotes = [];
if ($people && can_see_money() && hire_quotes_available()) {
    $quotes = hire_quotes(array_map(function ($p) { return (int)$p['id']; }, $people));
}

$pageTitle = 'Artists';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Artists <span class="muted">· everyone ever put up for a job</span></h2>

<p class="lead">
  Built from what you type into <strong>Add candidates</strong> on any project's Hiring screen,
  and from the box at the bottom of this page — the same list that already stops you entering
  somebody twice, now readable and editable. One row per person, with every job they have been
  up for beside them.
</p>

<?php if (!$ready): ?>
  <div class="flash flash-err">
    This screen needs the hiring database updates (migration <strong>024</strong>).
    <?= is_admin() ? '<a href="migrate.php">Apply them →</a>' : 'Ask an administrator to run them.' ?>
  </div>
<?php else: ?>

  <?php if (!$extra): ?>
    <div class="flash flash-warn">
      Migration <strong>025</strong> has not been applied, so location, position, seniority,
      project type, worked-with-us and rating are not stored yet. Everything else works.
    </div>
  <?php endif; ?>

  <form method="get" class="inline-form">
    <input name="q" value="<?= e($q) ?>" size="30"
           placeholder="Search name, email, source, notes<?= $extra ? ', location, position' : '' ?>">
    <?php if ($positions): ?>
      <?php // Submits on change, like the role picker on users.php — a filter
            // you have to press a second button to apply is a filter people
            // stop using. ?>
      <select name="position" onchange="this.form.submit()">
        <option value="">All positions</option>
        <?php foreach ($positions as $o): ?>
          <option value="<?= e($o) ?>" <?= $pos === $o ? 'selected' : '' ?>><?= e($o) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <button type="submit" class="secondary">Search</button>
    <?php if ($q !== '' || $pos !== ''): ?><a href="artists.php">Clear</a><?php endif; ?>
    <span class="muted small">
      <?= count($people) ?> <?= count($people) === 1 ? 'person' : 'people' ?><?php
        if ($pos !== ''): ?> in <?= e($pos) ?><?php endif; ?><?php
        if ($q !== ''): ?> matching &ldquo;<?= e($q) ?>&rdquo;<?php endif; ?>
    </span>
  </form>

  <?php if (!$people): ?>
    <div class="empty-state">
      <h2><?= $q !== '' ? 'Nobody matches that' : 'Nobody entered yet' ?></h2>
      <p><?= $q !== ''
            ? 'Matching is on the words as typed, never fuzzy — try a shorter search.'
            : 'Add some below, or enter them from a project\'s Hiring screen.' ?></p>
    </div>
  <?php else: ?>
    <div class="grid-scroll">
      <table class="artist-table">
        <thead>
          <tr>
            <th>Name</th>
            <?php if ($has('position')):  ?><th>Position</th><?php endif; ?>
            <?php if ($has('seniority')): ?><th>Seniority</th><?php endif; ?>
            <?php if ($has('location')):  ?><th>Location</th><?php endif; ?>
            <?php // Between Location and Type, where David asked for it. Shown
                  // only to an administrator, because it is the finance
                  // database's number and nobody else can see that connection. ?>
            <?php if (can_see_money()): ?><th class="num">Day rate</th><?php endif; ?>
            <?php if ($has('project_type')): ?><th>Type</th><?php endif; ?>
            <?php if ($has('rating')):    ?><th>Rating</th><?php endif; ?>
            <th>Contact</th>
            <th>Reel</th>
            <th>Where from</th>
            <th>Up for</th>
            <?php if (can_edit()): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($people as $p): $key = mb_strtolower(trim($p['candidate_name']));
          $qt = $quotes[(int)$p['id']] ?? null; ?>
          <tr>
            <td>
              <strong><?= e($p['candidate_name']) ?></strong>
              <?php if (!empty($p['worked_with_us'])): ?>
                <span class="ready-tag">worked with us</span>
              <?php endif; ?>
              <?php if (!empty($p['notes'])): ?>
                <div class="muted small"><?= e($p['notes']) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($has('position')):  ?><td><?= e($p['position'] ?? '') ?></td><?php endif; ?>
            <?php if ($has('seniority')): ?><td><?= e($p['seniority'] ?? '') ?></td><?php endif; ?>
            <?php if ($has('location')):  ?><td><?= e($p['location'] ?? '') ?></td><?php endif; ?>
            <?php if (can_see_money()): ?>
              <td class="num">
                <?= $qt && $qt['quoted_rate'] !== null
                      ? e(fmt_money((float)$qt['quoted_rate'], $qt['rate_unit'] ?? 'day',
                                    $qt['rate_currency'] ?? 'USD'))
                      : '<span class="muted">—</span>' ?>
              </td>
            <?php endif; ?>
            <?php if ($has('project_type')): ?><td><?= e($p['project_type'] ?? '') ?></td><?php endif; ?>
            <?php if ($has('rating')): ?>
              <td class="num"><?= $p['rating'] !== null && $p['rating'] !== ''
                    ? str_repeat('★', max(0, min(5, (int)$p['rating'])))
                    : '<span class="muted">—</span>' ?></td>
            <?php endif; ?>
            <td class="small">
              <?php if (!empty($p['email'])): ?>
                <a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a><br>
              <?php endif; ?>
              <?= e($p['phone'] ?? '') ?>
            </td>
            <td class="small reel-cell">
              <?php // THE ADDRESS, not the word "reel". Which site somebody's
                    // work is on is half of what you want to know before
                    // clicking — behance, vimeo, an artstation — and a link
                    // reading "reel" hides exactly that. Truncated in CSS
                    // rather than in PHP so the whole thing stays selectable
                    // and copyable, and the title carries it in full. ?>
              <?php if (!empty($p['portfolio_url'])): ?>
                <a href="<?= e($p['portfolio_url']) ?>" target="_blank" rel="noopener"
                   title="<?= e($p['portfolio_url']) ?>"><?= e($p['portfolio_url']) ?></a>
              <?php endif; ?>
              <?php if (!empty($p['portfolio_password'])): ?>
                <?php // Shown, because a reel password that has to be looked up
                      // somewhere else is no better than not having it. It opens
                      // the artist's own showreel page and nothing of Mana's —
                      // see migration 031. ?>
                <div class="muted">pw <code class="reel-pw"><?= e($p['portfolio_password']) ?></code></div>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= e($p['source'] ?? '') ?></td>
            <td class="small">
              <?php // Every approach, newest first — seeing that Layout already
                    // spoke to this person on another show is the point. ?>
              <?php foreach (($approaches[$key] ?? []) as $a): ?>
                <div>
                  <?php if (!empty($a['project_id'])): ?>
                    <a href="hiring.php?project=<?= (int)$a['project_id'] ?>"><?= e($a['project_code'] ?: 'project') ?></a>
                  <?php else: ?>
                    <span class="muted">no project</span>
                  <?php endif; ?>
                  <?= e($a['step_name'] ?: '') ?>
                  <span class="muted">· <?= e(hire_stage_label($a['stage'])) ?></span>
                </div>
              <?php endforeach; ?>
            </td>
            <?php if (can_edit()): ?>
              <td class="row-actions">
                <?php // A modal rather than twelve inputs in the row. This table
                      // already scrolls sideways; making every field an input
                      // would put the last of them somewhere nobody scrolls to. ?>
                <button type="button" class="linkish artist-edit"
                        data-artist="<?= e(json_encode($p + ['quote' => $qt], JSON_UNESCAPED_UNICODE)) ?>">Edit</button>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php // ---- the spreadsheet, in and out ----------------------------------- ?>
  <?php if ($people): ?>
    <p class="page-actions">
      <?php // Carries the search, so what downloads is what you were looking
            // at rather than everything regardless. ?>
      <a class="button secondary"
         href="export_artists.php<?= ($q !== '' || $pos !== '')
              ? '?' . http_build_query(array_filter(['q' => $q, 'position' => $pos]))
              : '' ?>">Download as Excel ↓</a>
      <span class="muted small">Same layout as the studio's freelancer sheet — the same columns,
        the red title bar, Arial throughout.</span>
    </p>
  <?php endif; ?>

  <?php if (can_edit() && $canAddFree): ?>
    <h3>Import the freelancer spreadsheet</h3>
    <p class="muted small">
      Reads <code>Mana_Freelancer_DataBase.xlsx</code> as it is: Artist, Location, Position,
      Seniority, Project type, Email, Demo link, Password, Worked with us, Notes, Rate, Rating.
      The <strong>password</strong> is kept beside the link, and the <strong>rate</strong> goes to
      the finance database rather than this one. Anyone already here is left alone unless you say
      otherwise.
    </p>
    <form method="post" enctype="multipart/form-data" class="inline-form">
      <input type="file" name="sheet" accept=".xlsx" required>
      <label class="inline"><input type="checkbox" name="overwrite" value="1">
        update people already here</label>
      <button type="submit" name="action" value="import" class="secondary">Import</button>
    </form>
  <?php endif; ?>

  <?php // ---- add several at once ------------------------------------------ ?>
  <?php if (can_edit()): ?>
    <h3>Add artists</h3>
    <?php if (!$canAddFree): ?>
      <div class="flash flash-warn">
        Adding somebody here without naming a project needs database update
        <strong>030</strong>.
        <?= is_admin() ? '<a href="migrate.php">Apply it →</a>' : 'Ask an administrator to run it.' ?>
        Until then, add people from a project's Hiring screen.
      </div>
    <?php else: ?>
      <p class="muted small">
        Five at a time, the same shape as the hiring grid. Nobody added here belongs to a
        project yet — they are simply known to the studio, and turn up in the candidate
        lookup on every show from now on. Leave a row blank to skip it.
      </p>
      <form method="post">
        <div class="grid-scroll">
          <table class="artist-table">
            <thead>
              <tr>
                <th>Name</th>
                <?php if ($has('position')):  ?><th>Position</th><?php endif; ?>
                <?php if ($has('seniority')): ?><th>Seniority</th><?php endif; ?>
                <?php if ($has('location')):  ?><th>Location</th><?php endif; ?>
                <?php if ($has('project_type')): ?><th>Type</th><?php endif; ?>
                <?php if ($has('rating')):    ?><th>Rating</th><?php endif; ?>
                <th>Email</th><th>Phone</th><th>Reel</th>
                <?php if ($has('portfolio_password')): ?><th>Reel pw</th><?php endif; ?>
                <th>Where from</th><th>Notes</th>
              </tr>
            </thead>
            <tbody>
            <?php for ($i = 0; $i < 5; $i++): $n = 'rows[' . $i . ']'; ?>
              <tr>
                <td><input name="<?= $n ?>[candidate_name]" size="20" maxlength="120"></td>
                <?php if ($has('position')): ?>
                  <td><input name="<?= $n ?>[position]" size="14" maxlength="80"></td><?php endif; ?>
                <?php if ($has('seniority')): ?>
                  <td><?= artists_seniority_select($n . '[seniority]', '') ?></td><?php endif; ?>
                <?php if ($has('location')): ?>
                  <td><input name="<?= $n ?>[location]" size="10" maxlength="80"></td><?php endif; ?>
                <?php if ($has('project_type')): ?>
                  <td><input name="<?= $n ?>[project_type]" size="7" maxlength="20"></td><?php endif; ?>
                <?php if ($has('rating')): ?>
                  <td><?= artists_rating_select($n . '[rating]', null) ?></td><?php endif; ?>
                <td><input type="email" name="<?= $n ?>[email]" size="20" maxlength="190"></td>
                <td><input name="<?= $n ?>[phone]" size="12" maxlength="50"></td>
                <td><input name="<?= $n ?>[portfolio_url]" size="16" maxlength="500"></td>
                <?php if ($has('portfolio_password')): ?>
                  <td><input name="<?= $n ?>[portfolio_password]" size="10" maxlength="120"></td>
                <?php endif; ?>
                <td><input name="<?= $n ?>[source]" size="12" maxlength="100"></td>
                <td><input name="<?= $n ?>[notes]" size="22"></td>
              </tr>
            <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div class="actions">
          <button type="submit" name="action" value="add_artists">Add these artists</button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php // ---- the one editor ------------------------------------------------ ?>
  <?php if (can_edit()): ?>
    <div id="artistEditor" class="tl-modal" style="display:none;">
      <div class="tl-modal-box">
        <h3>Edit artist</h3>
        <p class="muted small" id="artistScope"></p>
        <form method="post">
          <input type="hidden" name="action" value="save_artist">
          <input type="hidden" name="orig_name" id="aOrig">
          <input type="hidden" name="hire_id" id="aHireId">
          <div class="row">
            <label>Name <input name="candidate_name" id="aName" maxlength="120" required></label>
            <?php if ($has('position')): ?>
              <label>Position <input name="position" id="aPosition" maxlength="80"></label>
            <?php endif; ?>
          </div>
          <div class="row">
            <?php if ($has('seniority')): ?>
              <label>Seniority <?= artists_seniority_select('seniority', '', 'aSeniority') ?></label>
            <?php endif; ?>
            <?php if ($has('location')): ?>
              <label>Location <input name="location" id="aLocation" maxlength="80"></label>
            <?php endif; ?>
            <?php if ($has('project_type')): ?>
              <label>Type <input name="project_type" id="aType" maxlength="20"
                                 placeholder="2D / 3D / 2D-3D"></label>
            <?php endif; ?>
          </div>
          <div class="row">
            <label>Email <input type="email" name="email" id="aEmail" maxlength="190"></label>
            <label>Phone <input name="phone" id="aPhone" maxlength="50"></label>
          </div>
          <div class="row">
            <label>Reel <input name="portfolio_url" id="aReel" maxlength="500"></label>
            <?php if ($has('portfolio_password')): ?>
              <label>Reel password <input name="portfolio_password" id="aReelPw" maxlength="120">
                <small>The one the artist puts on their own page so people can watch it.
                       Not a login for anything here.</small></label>
            <?php endif; ?>
            <label>Where from <input name="source" id="aSource" maxlength="100"></label>
          </div>
          <div class="row">
            <?php if ($has('rating')): ?>
              <label>Rating <?= artists_rating_select('rating', null, 'aRating') ?></label>
            <?php endif; ?>
            <?php if ($has('worked_with_us')): ?>
              <label class="inline"><input type="checkbox" name="worked_with_us" id="aWorked" value="1">
                Has worked with us</label>
            <?php endif; ?>
          </div>
          <label>Notes <input name="notes" id="aNotes"></label>

          <?php if (can_see_money()): ?>
            <?php // The only field on this form that is NOT in the production
                  // database. It goes through includes/money.php onto their most
                  // recent approach, and no editor ever sees this block. ?>
            <div class="row">
              <label>Day rate <input name="quoted_rate" id="aRate" inputmode="decimal"
                                     placeholder="what they ask"></label>
              <label>Per
                <select name="rate_unit" id="aUnit">
                  <?php foreach (['day' => 'day', 'hour' => 'hour', 'second' => 'second',
                                  'flat' => 'flat fee'] as $k => $lbl): ?>
                    <option value="<?= $k ?>"><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Currency <input name="rate_currency" id="aCur" maxlength="3" size="4"
                                     style="text-transform:uppercase" value="USD"></label>
            </div>
            <p class="muted small">
              Stored in the separate finance database, never here, and visible only to
              administrators. Recorded against their most recent approach — a quote belongs to
              a conversation.
            </p>
          <?php endif; ?>

          <div class="actions">
            <button type="button" class="secondary" onclick="closeArtistEditor()">Cancel</button>
            <span class="spacer"></span>
            <button type="submit">Save</button>
          </div>
        </form>
      </div>
    </div>
    <script src="assets/artists.js<?= asset_ver('artists.js') ?>"></script>
  <?php endif; ?>

<?php endif; ?>

</div>
</body></html>
