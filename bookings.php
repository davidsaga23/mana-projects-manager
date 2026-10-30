<?php
// Bookings: who is needed on which show, and when, across every show at once.
//
// David, 2026-09-21: "is there a way I can see the overlapping of artists across
// projects and general timelines?" includes/bookings.php says what a booking and
// a clash are. This page gathers them and hands them to assets/bookings.js, which
// draws two views of the same facts: BY PERSON (one row each, their shows side by
// side, red where two cross) and BY SHOW (every show's general timeline stacked,
// the people on each step, red where one of them is needed elsewhere).
//
// Read only, and for everybody who can log in: positions and dates are
// production facts, and no rate is anywhere near this page.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bookings.php';
require_login();

$projects   = bookings_projects($pdo);
$projectIds = array_map(function ($p) { return (int)$p['id']; }, $projects);
$positions  = bookings_positions($pdo, $projectIds);
$bookings   = $positions['bookings'];
$steps      = bookings_steps($pdo, $projectIds);
$clashes    = bookings_clashes($bookings);
$absences   = bookings_absences($pdo, $bookings);

$projectById = [];
foreach ($projects as $p) $projectById[(int)$p['id']] = $p;

$pageTitle = 'Bookings';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title">Bookings <span class="muted">· every show</span></h2>
<p class="lead">
  Who is needed on which show, and when, read from each show's <strong>general timeline</strong>:
  somebody holding a position on a step is booked from that step's start to its deadline.
  <strong class="bk-clash-text">Red</strong> marks the same person needed on two shows on the
  same working days. Shows in setup, active or on hold are included; wrapped and archived ones
  are not. <strong>Stripes</strong> are a person's days off, entered on their person page.
</p>

<?php if (!$projects): ?>
  <div class="empty-state">
    <h2>No show to read</h2>
    <p>No show is in setup, active or on hold.</p>
  </div>
<?php else: ?>

  <div class="bk-toolbar">
    <?php // Two views of the same facts. Buttons, as David prefers, and the
          // chosen one is remembered by the script for next time. ?>
    <span class="bk-views" role="group" aria-label="View">
      <button type="button" class="secondary" data-view="person">By person</button>
      <button type="button" class="secondary" data-view="show">By show</button>
    </span>
    <label class="inline"><input type="checkbox" id="bkOnlyClashes">
      <span id="bkOnlyLabel">Only people on two shows at once</span></label>
    <span class="zoom-controls">
      <button type="button" class="secondary zoom-btn" id="bkZoomOut" title="Zoom out">−</button>
      <span class="zoom-label" id="bkZoomLabel">Weeks</span>
      <button type="button" class="secondary zoom-btn" id="bkZoomIn" title="Zoom in">+</button>
    </span>
  </div>

  <?php // One chip per show: its colour on the By person chart, and a switch to
        // leave it out. Clashes with a show that is switched off disappear too. ?>
  <div class="bk-shows" id="bkShows" aria-label="Shows"></div>

  <div id="bkSummary" class="bk-summary"></div>

  <div class="bk-chart-wrap">
    <div id="bkChart" class="bk-chart"></div>
  </div>

  <?php if ($positions['undated']): ?>
    <p class="muted small">
      Not drawn, because the step has no dates on its show's timeline yet:
      <?php
        $names = [];
        foreach ($positions['undated'] as $u) {
            $code = $projectById[$u['project_id']]['code'] ?? '?';
            $names[] = $u['person'] . ' on ' . $code . ' ' . $u['step'];
        }
      ?>
      <strong><?= e(implode(', ', array_slice($names, 0, 10))) ?></strong><?php
        if (count($names) > 10): ?> and <?= count($names) - 10 ?> more<?php endif; ?>.
    </p>
  <?php endif; ?>

  <p class="muted small">
    People appear here once they are hired into positions on a show's <strong>Staffing</strong>
    screen; a position nobody holds yet books nobody. Click a bar to open that show's timeline.
  </p>

<script>
<?php // Everything the chart draws, for assets/bookings.js. JSON_HEX_TAG as
      // well as the default escaping of slashes: names are typed by people. ?>
window.BK = <?= json_encode([
    'today'    => date('Y-m-d'),
    'projects' => array_map(function ($p) {
        return ['id' => (int)$p['id'], 'code' => $p['code'], 'name' => $p['name'],
                'status' => $p['status'], 'delivery' => $p['delivery_date'] ?: null];
    }, $projects),
    'steps'    => $steps,
    'bookings' => $bookings,
    'unfilled' => (object)$positions['unfilled'],
    'clashes'  => $clashes,
    'absences' => (object)$absences,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="assets/bookings.js<?= asset_ver('bookings.js') ?>"></script>

<?php endif; ?>

</div>
</body></html>
