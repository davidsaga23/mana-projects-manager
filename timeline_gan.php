<?php
// Export the detailed schedule as a GanttProject (.gan) file.
//
// One parent task per group, children are "sh_0010 - Lighting". Crew become
// GanttProject resources with allocations, so the assignee column populates on
// the other side.
//
// Grouping differs from STFC's, which could assume episodes: this platform has
// shots AND assets, and episodes are optional. So the parent is the episode when
// the project has them, and otherwise "Shots" / "Assets" / the step itself —
// which keeps the file navigable without inventing a hierarchy that isn't there.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/pipeline.php';
require_login();

$projectId = (int)($_GET['project'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    header('Content-Type: text/plain; charset=utf-8');
    exit('That project no longer exists.');
}

$episodeFilter = trim($_GET['ep'] ?? '') ?: null;
$stepIds = array_values(array_filter(array_map('intval',
    explode(',', trim($_GET['steps'] ?? '')))));

$sql = "SELECT sc.start_date, sc.end_date, sc.is_manual,
               sh.episode, sh.shot_code, a.name AS asset_name,
               ps.id AS step_id, ps.name AS step_name, ps.step_order,
               c.id AS crew_id, c.name AS crew_name,
               COALESCE(pr.is_approved, 0) AS is_approved
          FROM schedule sc
          JOIN pipeline_steps ps ON ps.id = sc.step_id
          LEFT JOIN shots  sh ON sh.id = sc.shot_id
          LEFT JOIN assets a  ON a.id  = sc.asset_id
          LEFT JOIN crew   c  ON c.id  = sc.crew_id
          LEFT JOIN progress pr ON pr.step_id = sc.step_id AND pr.item_key = sc.item_key
         WHERE sc.project_id = ?";
$params = [$projectId];
if ($episodeFilter && $episodeFilter !== 'all') {
    $sql .= ' AND sh.episode = ?';
    $params[] = $episodeFilter;
}
if ($stepIds) {
    $sql .= ' AND ps.id IN (' . implode(',', array_fill(0, count($stepIds), '?')) . ')';
    $params = array_merge($params, $stepIds);
}
$sql .= ' ORDER BY sh.episode, sh.shot_code, a.name, ps.step_order';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (!$rows) {
    header('Content-Type: text/plain; charset=utf-8');
    exit('No scheduled tasks match those filters. Save a schedule first, or widen the filters.');
}

// Only crew actually referenced, so the file stays tidy.
$resources = [];
foreach ($rows as $r) {
    if ($r['crew_id'] !== null && !isset($resources[(int)$r['crew_id']])) {
        $resources[(int)$r['crew_id']] = $r['crew_name'];
    }
}
$resourceIndex = array_flip(array_keys($resources));

$groups = [];
foreach ($rows as $r) {
    if ($r['episode'])                  $key = $r['episode'];
    elseif ($r['shot_code'] !== null)   $key = 'Shots';
    elseif ($r['asset_name'] !== null)  $key = 'Assets';
    else                                $key = 'Whole project';
    $groups[$key][] = $r;
}

$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); };

/**
 * GanttProject's duration is a count of WORKING days, and its default calendar
 * skips weekends — which is what working_days_between() counts too. This is the
 * only place the two conventions meet, so it is the only place that can be wrong.
 */
function gan_duration(string $start, string $end): int {
    return max(1, working_days_between($start, $end));
}

$taskId = 0;
$allocations = [];
$taskXml = '';

foreach ($groups as $groupName => $tasks) {
    $gStart = min(array_column($tasks, 'start_date'));
    $gEnd   = max(array_column($tasks, 'end_date'));
    $gid = $taskId++;

    $taskXml .= '        <task id="' . $gid . '" name="' . $esc($groupName) . '" color="#8cb6ce"'
              . ' meeting="false" start="' . $esc($gStart) . '"'
              . ' duration="' . gan_duration($gStart, $gEnd) . '" complete="0" expand="true">' . "\n";

    foreach ($tasks as $r) {
        $tid = $taskId++;
        $item = $r['shot_code'] ?? ($r['asset_name'] ?? $r['step_name']);
        // Plain ASCII hyphen, not "·" — the middle dot renders inconsistently
        // once the file is opened in GanttProject.
        $name = $item . ' - ' . $r['step_name'];
        $color = (int)$r['is_manual'] === 1 ? '#f59e0b' : '#3b82f6';

        $taskXml .= '            <task id="' . $tid . '" name="' . $esc($name) . '" color="' . $color . '"'
                  . ' meeting="false" start="' . $esc($r['start_date']) . '"'
                  . ' duration="' . gan_duration($r['start_date'], $r['end_date']) . '"'
                  . ' complete="' . ((int)$r['is_approved'] === 1 ? 100 : 0) . '" expand="false"/>' . "\n";

        if ($r['crew_id'] !== null) {
            $allocations[] = ['task' => $tid, 'res' => $resourceIndex[(int)$r['crew_id']]];
        }
    }
    $taskXml .= '        </task>' . "\n";
}

$resourceXml = '';
foreach ($resources as $cid => $name) {
    $resourceXml .= '        <resource id="' . $resourceIndex[$cid] . '" name="' . $esc($name) . '"'
                  . ' function="Default:0" contacts="" phone=""/>' . "\n";
}
$allocXml = '';
foreach ($allocations as $a) {
    $allocXml .= '        <allocation task-id="' . $a['task'] . '" resource-id="' . $a['res'] . '"'
               . ' function="Default:0" responsible="true" load="100.0"/>' . "\n";
}

$viewDate = min(array_column($rows, 'start_date'));

// The applied filters are named in BOTH the filename and the description, so it
// is obvious at a glance whether the export honoured your selection rather than
// quietly giving you everything.
$stepNames = array_values(array_unique(array_column($rows, 'step_name')));
$parts = [];
if ($episodeFilter && $episodeFilter !== 'all') $parts[] = $episodeFilter;
if ($stepIds) {
    $parts[] = count($stepNames) === 1
        ? preg_replace('/[^A-Za-z0-9]/', '', $stepNames[0])
        : count($stepNames) . 'steps';
}

$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $project['code'] ?: $project['name'])
          . '_DetailedSchedule' . ($parts ? '_' . implode('_', $parts) : '_All')
          . '_' . date('Y-m-d') . '.gan';

$filterDesc = 'Episodes: ' . ($episodeFilter && $episodeFilter !== 'all' ? $episodeFilter : 'all')
            . ' | Steps: ' . ($stepIds ? implode(', ', $stepNames) : 'all')
            . ' | ' . count($rows) . ' tasks';

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<project name="<?= $esc($project['name']) ?>, Detailed schedule" company="Mana Animation Studio" webLink="" view-date="<?= $esc($viewDate) ?>" view-index="0" gantt-divider-location="380" resource-divider-location="300" version="3.2" locale="en">
    <description>Exported from Anima on <?= date('Y-m-d H:i') ?>. <?= $esc($filterDesc) ?></description>
    <view zooming-state="default:3" id="gantt-chart">
        <field id="tpd3" name="Name" width="280" order="0"/>
        <field id="tpd4" name="Begin date" width="80" order="1"/>
        <field id="tpd5" name="End date" width="80" order="2"/>
        <field id="tpd6" name="Duration" width="60" order="3"/>
        <field id="tpc0" name="Resources" width="140" order="4"/>
    </view>
    <view id="resource-table">
        <field id="0" name="Name" width="210" order="0"/>
        <field id="1" name="Default role" width="120" order="1"/>
    </view>
    <calendars>
        <day-types>
            <day-type id="0"/>
            <day-type id="1"/>
            <default-week id="1" name="default" sun="1" mon="0" tue="0" wed="0" thu="0" fri="0" sat="1"/>
            <only-show-weekends value="false"/>
            <overriden-day-types/>
            <days/>
        </day-types>
    </calendars>
    <tasks empty-milestones="true">
        <taskproperties>
            <taskproperty id="tpd0" name="type" type="default" valuetype="icon"/>
            <taskproperty id="tpd1" name="priority" type="default" valuetype="icon"/>
            <taskproperty id="tpd2" name="info" type="default" valuetype="icon"/>
            <taskproperty id="tpd3" name="name" type="default" valuetype="text"/>
            <taskproperty id="tpd4" name="begindate" type="default" valuetype="date"/>
            <taskproperty id="tpd5" name="enddate" type="default" valuetype="date"/>
            <taskproperty id="tpd6" name="duration" type="default" valuetype="int"/>
            <taskproperty id="tpd7" name="completion" type="default" valuetype="int"/>
            <taskproperty id="tpd8" name="coordinator" type="default" valuetype="text"/>
            <taskproperty id="tpd9" name="predecessorsr" type="default" valuetype="text"/>
        </taskproperties>
<?= $taskXml ?>
    </tasks>
    <resources>
<?= $resourceXml ?>
    </resources>
    <allocations>
<?= $allocXml ?>
    </allocations>
    <vacations/>
    <previous/>
    <roles roleset-name="Default"/>
</project>
