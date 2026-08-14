<?php
// The studio calendar: days nobody works.
//
// Studio-wide rather than per project — a public holiday is a public holiday,
// and every project running that week loses the same day. Every quota and every
// scheduled date in the app divides by this list, so it is worth a screen of its
// own rather than a config constant nobody can see.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
// require_once, not require: functions.php already pulls calendar.php in, and
// loading it a second time would redeclare every function in it.
require_once __DIR__ . '/includes/calendar.php';
require_login();

/**
 * US days the studio MIGHT take, offered one click away.
 *
 * Mana works to US client calendars, so this is the American federal list minus
 * the days migration 014 already seeds — the near-universal ones. What is left
 * here is genuinely split: plenty of studios work MLK Day and Veterans Day, and
 * seeding a day the team actually works would pad every schedule silently. So
 * they are offered, not assumed.
 *
 * Dates are written out rather than computed. "Third Monday in January" is easy
 * to get subtly wrong in code, the list only needs extending every few years,
 * and a wrong holiday is invisible until a deadline slips. Observed dates are
 * used where the nominal one falls at a weekend.
 */
function suggested_days(): array {
    return [
        // 2026
        '2026-01-19' => 'Martin Luther King Jr. Day',
        '2026-02-16' => "Presidents' Day",
        '2026-06-19' => 'Juneteenth',
        '2026-10-12' => 'Columbus Day',
        '2026-11-11' => 'Veterans Day',
        '2026-12-24' => 'Christmas Eve',
        '2026-12-31' => "New Year's Eve",
        // 2027
        '2027-01-18' => 'Martin Luther King Jr. Day',
        '2027-02-15' => "Presidents' Day",
        '2027-06-18' => 'Juneteenth (observed)',
        '2027-10-11' => 'Columbus Day',
        '2027-11-11' => 'Veterans Day',
        '2027-12-23' => 'Christmas Eve (observed)',
        '2027-12-31' => "New Year's Eve",
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $day  = trim($_POST['day'] ?? '');
            $name = trim($_POST['name'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                throw new RuntimeException('Pick a date.');
            }
            if ($name === '') $name = 'Day off';
            $stmt = $pdo->prepare(
                'INSERT INTO non_working_days (day, name) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)'
            );
            $stmt->execute([$day, $name]);
            // rowCount is 1 for an insert and 2 for an update, which is the only
            // way to tell the two apart — and the difference is worth reporting.
            flash('ok', $stmt->rowCount() >= 2
                ? "Renamed $day to \"$name\"."
                : "Added $day — \"$name\". Every quota and schedule now skips it.");

        } elseif ($action === 'add_suggested') {
            $wanted = (array)($_POST['suggest'] ?? []);
            $known  = suggested_days();
            $ins = $pdo->prepare(
                'INSERT IGNORE INTO non_working_days (day, name) VALUES (?, ?)'
            );
            $n = 0;
            foreach ($wanted as $day) {
                // Only ever accept dates this screen actually offered.
                if (!isset($known[$day])) continue;
                $ins->execute([$day, $known[$day]]);
                $n += $ins->rowCount();
            }
            flash($n ? 'ok' : 'warn', $n
                ? "Added $n day(s) to the calendar."
                : 'Nothing was added — those days were already on the calendar.');

        } elseif ($action === 'delete') {
            $day = trim($_POST['day'] ?? '');
            $stmt = $pdo->prepare('DELETE FROM non_working_days WHERE day = ?');
            $stmt->execute([$day]);
            flash('ok', $stmt->rowCount()
                ? "Removed $day. It counts as a working day again."
                : 'That day was not on the calendar.');
        }
    } catch (Throwable $e) {
        flash('err', $e->getMessage());
    }
    header('Location: calendar.php');
    exit;
}

try {
    $days = $pdo->query('SELECT day, name FROM non_working_days ORDER BY day')->fetchAll();
    $tableMissing = false;
} catch (PDOException $e) {
    $days = [];
    $tableMissing = true;
}

$byYear = [];
foreach ($days as $d) $byYear[substr($d['day'], 0, 4)][] = $d;

$onCalendar = array_column($days, 'day');
$onCalendar = array_flip($onCalendar);
$suggestions = [];
$todayStr = date('Y-m-d');
foreach (suggested_days() as $day => $name) {
    if (isset($onCalendar[$day]) || $day < $todayStr) continue;
    $suggestions[$day] = $name;
}
ksort($suggestions);

$pageTitle = 'Studio calendar';
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="index.php">← All projects</a></p>

<h2 class="wizard-title">Studio calendar</h2>

<p class="lead">
  Days nobody works. Every deadline in this tool counts working days, and without this it
  would mean Monday to Friday and nothing else — so a holiday would be quietly treated as a
  day someone could finish a shot on. That error always makes a plan look more achievable
  than it is.
</p>
<p class="lead">
  Mana works to <strong>US client calendars</strong>, so this is seeded with the American
  holidays almost every company closes for. It is a starting point, not a rule — remove any
  the studio works, and add whatever else it takes.
</p>

<?php if ($tableMissing): ?>
  <div class="flash flash-err">
    The calendar table doesn't exist yet. An administrator needs to
    <a href="migrate.php">apply the pending database updates</a> before this screen works.
  </div>
<?php else: ?>

  <?php if (!$days): ?>
    <div class="flash flash-warn">
      The calendar is empty, so every weekday counts as workable.
    </div>
  <?php endif; ?>

  <?php if ($suggestions && can_edit()): ?>
    <h3>Taken by some studios, not all</h3>
    <p class="muted small">
      The rest of the US federal calendar. These weren't added for you because studios genuinely
      differ on them — plenty work Martin Luther King Jr. Day and Veterans Day — and a day off
      the team actually works would quietly pad every schedule. Tick the ones Mana takes.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="add_suggested">
      <div class="suggest-grid">
        <?php foreach ($suggestions as $day => $name): ?>
          <label class="suggest-item">
            <input type="checkbox" name="suggest[]" value="<?= e($day) ?>">
            <span><strong><?= e($day) ?></strong>
              <span class="muted"><?= e($name) ?></span>
              <span class="muted small"><?= e(date('D', strtotime($day))) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="actions">
        <button type="submit" class="secondary">Add the ticked days</button>
      </div>
    </form>
  <?php endif; ?>

  <h3>On the calendar</h3>
  <?php if (!$days): ?>
    <p class="muted">Nothing yet.</p>
  <?php else: ?>
    <?php foreach ($byYear as $year => $rows): ?>
      <h4><?= e($year) ?></h4>
      <table class="review-table">
        <tr><th>Date</th><th>Day</th><th>What it is</th><th></th></tr>
        <?php foreach ($rows as $d):
          $dow = (int)date('N', strtotime($d['day']));
          $weekend = $dow >= 6;
        ?>
        <tr class="<?= $d['day'] < $todayStr ? 'muted' : '' ?>">
          <td><?= e($d['day']) ?></td>
          <td class="muted">
            <?= e(date('D', strtotime($d['day']))) ?>
            <?php if ($weekend): ?>
              <span class="muted small">— a weekend anyway, so it costs nothing</span>
            <?php endif; ?>
          </td>
          <td><?= e($d['name']) ?></td>
          <td>
            <?php if (can_edit()): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="day" value="<?= e($d['day']) ?>">
              <button type="submit" class="linkish">Remove</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (can_edit()): ?>
    <h3>Add a day</h3>
    <form method="post" class="inline-form">
      <input type="hidden" name="action" value="add">
      <input type="date" name="day" required>
      <input type="text" name="name" placeholder="What it is — e.g. studio shutdown" size="34">
      <button type="submit">Add</button>
    </form>
    <p class="muted small">
      Adding a date that is already listed renames it rather than duplicating it.
      A shutdown of several days is entered one day at a time — a range would hide which
      individual days were actually taken.
    </p>
  <?php endif; ?>

<?php endif; ?>

</div>
</body></html>
