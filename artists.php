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

        if ($action === 'save_grid') {
            // TRUNCATION IS CHECKED FIRST, before anything is written. PHP drops
            // everything past max_input_vars (1000 by default) with no error at
            // all, so a large post arrives looking complete and half empty. The
            // sentinel is the last field in the form: if it did not arrive, the
            // tail was cut and what did arrive must not be trusted.
            if (!isset($_POST['_end'])) {
                throw new RuntimeException(
                    'That save was too large for the server to accept whole, so NOTHING was '
                    . 'written. Filter to fewer people and save again, or ask the host to raise '
                    . 'max_input_vars.'
                );
            }
            $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
            $saved = 0;

            foreach ($rows as $hid => $row) {
                if (!is_array($row)) continue;
                $orig = trim((string)($row['orig_name'] ?? ''));
                $name = trim((string)($row['candidate_name'] ?? ''));
                if ($orig === '') continue;
                if ($name === '') {
                    flash('warn', '"' . $orig . '" was left with a blank name, so it was not changed.');
                    continue;
                }
                artists_save_person($pdo, $orig, $row, $extra);
                $saved++;

                // Through the one door, onto that person's most recent approach.
                // Never written from here into `hires`.
                if (can_see_money() && array_key_exists('quoted_rate', $row)) {
                    try {
                        save_hire_quote((int)$hid, $name, null,
                                        ['quoted_rate' => $row['quoted_rate'], 'rate_unit' => 'day',
                                         'rate_currency' => 'USD'], current_user_display());
                    } catch (Throwable $e) {
                        // One unreadable number must not lose the rest of the row.
                        flash('warn', $name . ': ' . $e->getMessage());
                    }
                }
            }
            if ($saved) {
                flash('ok', sprintf('Saved %d artist%s. Each change applies to every record of '
                                  . 'that person, on every project.',
                                  $saved, $saved === 1 ? '' : 's'));
            } else {
                flash('warn', 'Nothing had changed, so nothing was saved.');
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
                                  . 'opens their own showreel page, nothing of ours.',
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
$positions = $ready ? artists_positions($pdo) : [];
// SEVERAL at once. Anything that is not a position this database actually holds
// is dropped rather than left filtering against nothing — a stale bookmark
// should not look like an empty database.
$pos = (array)($_GET['position'] ?? []);
$pos = array_values(array_intersect(array_map('strval', $pos), $positions));

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
      <?php // MULTIPLE, so "Layout or BG Layout or CH Layout" is one question.
            // No submit-on-change, unlike the single-value version it replaces:
            // picking a second entry would reload the page before you got there. ?>
      <label class="ms-positions">
        <span class="muted small">Positions &mdash; ctrl/cmd-click for several</span>
        <select name="position[]" multiple size="6">
          <?php foreach ($positions as $o): ?>
            <option value="<?= e($o) ?>" <?= in_array($o, $pos, true) ? 'selected' : '' ?>><?= e($o) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <button type="submit" class="secondary">Search</button>
    <?php if ($q !== '' || $pos): ?><a href="artists.php">Clear</a><?php endif; ?>
    <span class="muted small">
      <?= count($people) ?> <?= count($people) === 1 ? 'person' : 'people' ?><?php
        if ($pos): ?> in <?= e(implode(', ', $pos)) ?><?php endif; ?><?php
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
    <?php // EVERY FIELD IS AN INPUT, edited in the row (David, 2026-08-31:
          // "all the fields should be editable from this view without having to
          // click on Edit"). One form over the whole table, one Save.
          //
          // ⚠ ONLY CHANGED ROWS ARE POSTED, and that is correctness rather than
          // polish. PHP's max_input_vars defaults to 1000; this grid is about
          // thirteen fields a row, so seventy-odd rows fills it and everything
          // past that is dropped SILENTLY — a save that looks like it worked and
          // wrote nothing. assets/artists.js disables untouched rows before
          // submitting, so an ordinary edit posts a handful of fields, and the
          // sentinel at the end of the form catches a truncated post rather
          // than trusting it. ?>
    <form method="post" id="artistGrid">
      <input type="hidden" name="action" value="save_grid">
      <div class="grid-scroll">
        <table class="artist-table artist-grid">
          <thead>
            <tr>
              <th>Name</th>
              <?php if ($has('position')):  ?><th>Position</th><?php endif; ?>
              <?php if ($has('seniority')): ?><th>Seniority</th><?php endif; ?>
              <?php if ($has('location')):  ?><th>Location</th><?php endif; ?>
              <?php // Between Location and Type, where David asked for it. Admin
                    // only: it is the finance database's number, and no other
                    // account can open that connection. ?>
              <?php if (can_see_money()): ?><th>Day rate</th><?php endif; ?>
              <?php if ($has('project_type')): ?><th>Type</th><?php endif; ?>
              <?php if ($has('rating')):    ?><th>Rating</th><?php endif; ?>
              <th>Email</th><th>Phone</th><th>Reel</th>
              <?php if ($has('portfolio_password')): ?><th>Reel pw</th><?php endif; ?>
              <th>Where from</th><th>Notes</th>
              <?php if ($has('worked_with_us')): ?><th>Worked</th><?php endif; ?>
              <th>Up for</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($people as $p): $key = mb_strtolower(trim($p['candidate_name']));
            $id = (int)$p['id'];
            $n  = 'rows[' . $id . ']';
            $qt = $quotes[$id] ?? null; ?>
            <tr>
              <td>
                <?php // The name AS STORED, carried hidden, because that is what
                      // the update matches on — renaming somebody must still
                      // find their other records. ?>
                <input type="hidden" name="<?= $n ?>[orig_name]" value="<?= e($p['candidate_name']) ?>">
                <input name="<?= $n ?>[candidate_name]" value="<?= e($p['candidate_name']) ?>"
                       size="20" maxlength="120" class="a-name">
              </td>
              <?php if ($has('position')): ?>
                <td><input name="<?= $n ?>[position]" value="<?= e($p['position'] ?? '') ?>"
                           size="14" maxlength="80" list="position-list"></td>
              <?php endif; ?>
              <?php if ($has('seniority')): ?>
                <td><?= artists_seniority_select($n . '[seniority]', $p['seniority'] ?? null) ?></td>
              <?php endif; ?>
              <?php if ($has('location')): ?>
                <td><input name="<?= $n ?>[location]" value="<?= e($p['location'] ?? '') ?>"
                           size="10" maxlength="80"></td>
              <?php endif; ?>
              <?php if (can_see_money()): ?>
                <td><input name="<?= $n ?>[quoted_rate]" inputmode="decimal" size="7"
                           value="<?= $qt && $qt['quoted_rate'] !== null
                                      ? e(fmt_num((float)$qt['quoted_rate'], 2)) : '' ?>"
                           title="Stored in the finance database, not this one"></td>
              <?php endif; ?>
              <?php if ($has('project_type')): ?>
                <td><input name="<?= $n ?>[project_type]" value="<?= e($p['project_type'] ?? '') ?>"
                           size="6" maxlength="20"></td>
              <?php endif; ?>
              <?php if ($has('rating')): ?>
                <td><?= artists_rating_select($n . '[rating]', $p['rating'] ?? null) ?></td>
              <?php endif; ?>
              <td><input type="email" name="<?= $n ?>[email]" value="<?= e($p['email'] ?? '') ?>"
                         size="20" maxlength="190"></td>
              <td><input name="<?= $n ?>[phone]" value="<?= e($p['phone'] ?? '') ?>"
                         size="12" maxlength="50"></td>
              <td class="reel-cell">
                <input name="<?= $n ?>[portfolio_url]" value="<?= e($p['portfolio_url'] ?? '') ?>"
                       size="22" maxlength="500" title="<?= e($p['portfolio_url'] ?? '') ?>">
                <?php if (!empty($p['portfolio_url'])): ?>
                  <a href="<?= e($p['portfolio_url']) ?>" target="_blank" rel="noopener"
                     class="reel-open" title="Open in a new tab">&#8599;</a>
                <?php endif; ?>
              </td>
              <?php if ($has('portfolio_password')): ?>
                <td><input name="<?= $n ?>[portfolio_password]" size="10" maxlength="120"
                           value="<?= e($p['portfolio_password'] ?? '') ?>"></td>
              <?php endif; ?>
              <td><input name="<?= $n ?>[source]" value="<?= e($p['source'] ?? '') ?>"
                         size="12" maxlength="100"></td>
              <td><input name="<?= $n ?>[notes]" value="<?= e($p['notes'] ?? '') ?>" size="24"></td>
              <?php if ($has('worked_with_us')): ?>
                <td class="num"><input type="checkbox" name="<?= $n ?>[worked_with_us]" value="1"
                       <?= !empty($p['worked_with_us']) ? 'checked' : '' ?>></td>
              <?php endif; ?>
              <td class="small">
                <?php foreach (($approaches[$key] ?? []) as $a): ?>
                  <div>
                    <?php if (!empty($a['project_id'])): ?>
                      <a href="hiring.php?project=<?= (int)$a['project_id'] ?>"><?= e($a['project_code'] ?: 'project') ?></a>
                    <?php endif; ?>
                    <?= e($a['step_name'] ?: '') ?>
                    <span class="muted">&middot; <?= e(hire_stage_label($a['stage'])) ?></span>
                  </div>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php // Offered once for every position box on the page, so a position is
            // picked from what already exists rather than retyped into a new one. ?>
      <?php if ($positions): ?>
        <datalist id="position-list">
          <?php foreach ($positions as $o): ?><option value="<?= e($o) ?>"></option><?php endforeach; ?>
        </datalist>
      <?php endif; ?>

      <div class="actions">
        <button type="submit">Save changes</button>
        <span class="hint" id="dirtyCount">nothing changed yet</span>
        <span class="spacer"></span>
        <?php // LAST FIELD IN THE FORM, deliberately. If PHP drops the tail of a
              // large post this never arrives, and the save refuses rather than
              // writing whatever happened to survive. ?>
        <input type="hidden" name="_end" value="1">
      </div>
    </form>
  <?php endif; ?>

  <?php // ---- the spreadsheet, in and out ----------------------------------- ?>
  <?php if ($people): ?>
    <p class="page-actions">
      <?php // Carries the search, so what downloads is what you were looking
            // at rather than everything regardless. ?>
      <a class="button secondary"
         href="export_artists.php<?= ($q !== '' || $pos)
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
    <script src="assets/artists.js<?= asset_ver('artists.js') ?>"></script>
  <?php endif; ?>

<?php endif; ?>

</div>
</body></html>
