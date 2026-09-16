<?php
/**
 * Ideal quotas — the studio's base sheet, one per kind of project.
 *
 * THE THREE NUMBERS, because this file only makes sense against the other two
 * and mistaking them for each other makes all three useless:
 *
 *   REQUIRED   what the deadline demands. step_quota() derives it from work
 *              remaining over working days left. Nobody types it; it moves when
 *              the date or the progress moves.
 *   ESTIMATED  what THIS project was planned at — pipeline_steps.days_per_unit.
 *   IDEAL      what the studio believes the work takes when done properly, for
 *              this KIND of project. Typed here, true across shows.
 *
 * The ideal is the only one that can contradict the other two, and that is its
 * whole value. A required pace far above the ideal is not an ambitious plan, it
 * is an impossible one, and today that is discoverable only in week three.
 *
 * Stored as DAYS PER UNIT rather than units per day, because everything it feeds
 * is already in those terms. Both are shown, because "half a day each" and "two
 * a day" are the same fact and people say whichever suits the step.
 */

require_once __DIR__ . '/functions.php';

/** Has migration 026 been applied? */
function standards_available(PDO $pdo): bool {
    return column_exists($pdo, 'quota_standards', 'days_per_unit');
}

/**
 * The types a base sheet can exist for: every project type, plus any type a
 * sheet was written for that is missing from that list.
 *
 * The second half should find nothing since migration 032 copied such types into
 * the list. It stays because a sheet no tab reaches is a set of studio numbers
 * nobody can see, correct or remove.
 *
 * Compared case-insensitively, the way MySQL compares them: a sheet stored under
 * "motion comic" already IS the "Motion comic" type's sheet, and a second tab for
 * it would show the same rows twice.
 */
function standard_project_types(PDO $pdo): array {
    $types = project_types($pdo);
    try {
        $used = $pdo->query('SELECT DISTINCT project_type FROM quota_standards')
                    ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $used = [];                       // migration 026 pending
    }
    $seen = array_map('mb_strtolower', $types);
    foreach (array_filter(array_map('strval', $used)) as $u) {
        if (in_array(mb_strtolower($u), $seen, true)) continue;
        $types[] = $u;
        $seen[] = mb_strtolower($u);
    }
    return $types;
}

/* ---------------------------------------------------------------------------
 * ADDING AND REMOVING A PROJECT TYPE
 *
 * David, 2026-09-16, about a 2D motion comic: technically a 2D project, "but the
 * pipeline steps and quotas are different, hence the budget also changes", and
 * then: "we should have a way to manually create new project types."
 *
 * A type exists to own a sheet. That is the one thing the platform does
 * differently per type (templates are offered by it, but any template can be
 * picked for any project), so these live beside the sheets, and the screen that
 * adds one is Ideal quotas.
 * ------------------------------------------------------------------------- */

/**
 * What is filed under a project type: its projects, archived ones included, the
 * pipeline templates offered for it, and how many ideals its sheet holds.
 *
 * ⚠ ARCHIVED PROJECTS COUNT. An archived show still has a plan and a budget read
 * through its type's sheet, and removing the type would change what those old
 * numbers say.
 */
function project_type_usage(PDO $pdo, string $type): array {
    $p = $pdo->prepare('SELECT id, name, code, status FROM projects WHERE type = ? ORDER BY name');
    $p->execute([$type]);
    $t = $pdo->prepare('SELECT name FROM pipeline_templates WHERE project_type = ? ORDER BY name');
    $t->execute([$type]);
    return [
        'projects'  => $p->fetchAll(),
        'templates' => $t->fetchAll(PDO::FETCH_COLUMN),
        'ideals'    => count(standards_for_type($pdo, $type)),
    ];
}

/** "3 projects (FC Mobile, Doomsday, 1 archived) and 1 pipeline template", for a sentence. */
function project_type_usage_phrase(array $use): string {
    $parts = [];
    $n = count($use['projects']);
    if ($n > 0) {
        $live = [];
        $archived = 0;
        foreach ($use['projects'] as $p) {
            if (($p['status'] ?? '') === 'archived') $archived++;
            else $live[] = (string)$p['name'];
        }
        // Four names, then a count. The names are there to recognise which shows
        // these are; past four they are a paragraph nobody reads to the end.
        $names = array_slice($live, 0, 4);
        if (count($live) > 4) $names[] = (count($live) - 4) . ' more';
        if ($archived > 0) $names[] = $archived . ' archived';
        $parts[] = $n . ' project' . ($n === 1 ? '' : 's') . ' (' . implode(', ', $names) . ')';
    }
    $k = count($use['templates']);
    if ($k > 0) $parts[] = $k . ' pipeline template' . ($k === 1 ? '' : 's');
    return implode(' and ', $parts);
}

/**
 * Add a project type, its sheet started as a copy of another type's, or empty.
 *
 * ⚠ THE COPY IS THE POINT, and the default. A type with an empty sheet puts every
 * project moved onto it back on its own days per item, most of them the 1 day
 * nobody changed, so the plan and the budget jump on the save that moves it.
 * Started as a copy, moving a project changes nothing until somebody changes a
 * number on the new sheet: the motion comic starts as 2D and parts from it one
 * step at a time, which is the order it should happen in.
 *
 * Each copied ideal keeps where its number came from, behind the name of the
 * sheet it was copied from, so a figure measured on four shows is not mistaken
 * for a guess after the move. Written through save_standards(), the one writer,
 * so each lands in quota_standards_log like any other change.
 *
 * @return array ['name' => string, 'copied' => int]
 */
function create_project_type(PDO $pdo, string $rawName, string $copyFrom, string $who = ''): array {
    if (!project_types_available($pdo)) {
        throw new RuntimeException('Adding a project type needs database update 032, which has not '
                                 . 'been applied yet, so nothing was created.');
    }

    // Tidied the way somebody means it: no spaces at either end, and a run of
    // them made one. MySQL ignores trailing spaces when it compares, so "3D " and
    // "3D" would be two types here and one type there.
    $name = trim((string)preg_replace('/\s+/u', ' ', $rawName));
    if ($name === '') {
        throw new RuntimeException('The new project type needs a name, so nothing was created.');
    }
    if (mb_strlen($name) > 20) {
        throw new RuntimeException(sprintf(
            '"%s" is %d characters long, and a project type name can be 20 at most, so nothing was '
          . 'created.', $name, mb_strlen($name)));
    }
    if (!preg_match('/[\p{L}\p{N}]/u', $name)) {
        throw new RuntimeException('A project type name needs at least one letter or number, so '
                                 . 'nothing was created.');
    }

    project_types($pdo, true);                     // the list as it is NOW, not at page load
    $existing = standard_project_types($pdo);
    foreach ($existing as $t) {
        // Case-insensitively, as the unique key and every WHERE type = ? compare.
        if (mb_strtolower($t) === mb_strtolower($name)) {
            throw new RuntimeException(sprintf(
                'There is already a project type called "%s", so nothing was created.', $t));
        }
    }

    $copyFrom = trim($copyFrom);
    $rows = [];
    if ($copyFrom !== '') {
        if (!in_array($copyFrom, $existing, true)) {
            throw new RuntimeException(sprintf(
                'There is no %s sheet to start from, so nothing was created.', $copyFrom));
        }
        foreach (standards_for_type($pdo, $copyFrom) as $s) {
            $was = trim((string)($s['source'] ?? ''));
            $rows[] = [
                'step_name'             => $s['step_name'],
                'applies_to'            => $s['applies_to'],
                'applies_to_asset_type' => $s['applies_to_asset_type'],
                'days_per_unit'         => (string)$s['days_per_unit'],
                'typical_headcount'     => $s['typical_headcount'] ?? '',
                // Cut to the column's 120 rather than refused. The sheet it came
                // from is the part that must survive, and it comes first.
                'source'                => mb_substr('Copied from ' . $copyFrom
                                                     . ($was !== '' ? ': ' . $was : ''), 0, 120),
                'notes'                 => $s['notes'] ?? '',
            ];
        }
    }

    $copied = 0;
    $pdo->beginTransaction();
    try {
        $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM project_types')
                         ->fetchColumn();
        $pdo->prepare('INSERT INTO project_types (name, sort_order, created_by) VALUES (?, ?, ?)')
            ->execute([$name, $next, $who !== '' ? $who : null]);

        if ($rows) {
            $r = save_standards($pdo, $name, $rows, $who);
            if (!empty($r['errors'])) {
                throw new RuntimeException('The ' . $copyFrom . ' sheet could not be copied, so nothing '
                                         . 'was created: ' . implode(' ', $r['errors']));
            }
            $copied = $r['saved'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Two people adding the same name at the same moment: the unique key
        // refuses the second, and that is the same answer as above.
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new RuntimeException(sprintf(
                'There is already a project type called "%s", so nothing was created.', $name));
        }
        throw $e;
    }

    project_types($pdo, true);
    return ['name' => $name, 'copied' => $copied];
}

/**
 * Remove a project type nothing is filed under, and its sheet with it.
 *
 * ⚠ REFUSED WHILE ANY PROJECT OR TEMPLATE USES IT, archived projects included,
 * rather than moving them somewhere. Which type a show is changes its plan and
 * its budget; that is decided on its Basics screen, one project at a time, and
 * never as a side effect of tidying a list.
 *
 * The sheet goes too, because a sheet without a type is exactly what
 * standard_project_types() turns back into a tab: the type would reappear the
 * moment it was removed. Each ideal is cleared through save_standards(), so what
 * it said stays in quota_standards_log.
 *
 * @return int how many ideals were removed with it
 */
function remove_project_type(PDO $pdo, string $type, string $who = ''): int {
    if (!project_types_available($pdo)) {
        throw new RuntimeException('Removing a project type needs database update 032, which has not '
                                 . 'been applied yet, so nothing was removed.');
    }
    $types = project_types($pdo, true);
    if (!in_array($type, $types, true)) {
        throw new RuntimeException(sprintf('There is no project type called "%s" to remove.', $type));
    }
    if (count($types) <= 1) {
        throw new RuntimeException(sprintf(
            '%s is the only project type left, and every project needs one, so it was kept.', $type));
    }
    $use = project_type_usage($pdo, $type);
    $users = count($use['projects']) + count($use['templates']);
    if ($users > 0) {
        throw new RuntimeException(sprintf(
            'Nothing was removed: %s %s still filed under %s. Give %s another type first.',
            project_type_usage_phrase($use), $users === 1 ? 'is' : 'are', $type,
            $users === 1 ? 'it' : 'them'));
    }

    $clear = [];
    foreach (standards_for_type($pdo, $type) as $s) {
        $clear[] = [
            'step_name'             => $s['step_name'],
            'applies_to'            => $s['applies_to'],
            'applies_to_asset_type' => $s['applies_to_asset_type'],
            'days_per_unit'         => '',          // a blank removes, see save_standards()
        ];
    }

    $removed = 0;
    $pdo->beginTransaction();
    try {
        if ($clear) $removed = save_standards($pdo, $type, $clear, $who)['removed'];
        $pdo->prepare('DELETE FROM project_types WHERE name = ?')->execute([$type]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    project_types($pdo, true);
    return $removed;
}

/**
 * What moving a project to another type does to its plan, as flash messages.
 *
 * ⚠ THE TYPE PICKS THE SHEET EVERY STEP PLANS ON, so changing it can move a
 * show's dates, crew and budget from a screen that looks like it edits a label.
 * Said step by step on the save that does it, rather than found on the budget a
 * week later.
 *
 * Compared on the number each step actually plans on under each type: the ideal
 * where that sheet has one, the project's own days per item where it does not.
 * That is effective_days_per_unit()'s rule, applied to both sheets.
 *
 * @return array of [flash type, message]
 */
function project_type_change_report(PDO $pdo, int $projectId, string $from, string $to): array {
    if (!standards_available($pdo)) return [];
    $steps = pipeline_steps($pdo, $projectId);
    if (!$steps) return [];

    $old = standards_for_type($pdo, $from);
    $new = standards_for_type($pdo, $to);
    $moved = [];
    $lost  = [];
    foreach ($steps as $s) {
        $key = standard_key($s['name'], $s['applies_to'] ?? 'shot', $s['applies_to_asset_type'] ?? '');
        $own = (float)($s['days_per_unit'] ?: 1.0);
        $hadIdeal = isset($old[$key]) && (float)$old[$key]['days_per_unit'] > 0;
        $hasIdeal = isset($new[$key]) && (float)$new[$key]['days_per_unit'] > 0;
        $before = $hadIdeal ? (float)$old[$key]['days_per_unit'] : $own;
        $after  = $hasIdeal ? (float)$new[$key]['days_per_unit'] : $own;

        $label = step_display_name($s, $steps);
        // The tolerance update_standard_days() uses: the column holds three
        // decimals, and anything finer is not a change anybody made.
        if (abs($before - $after) >= 0.0005) {
            $moved[] = sprintf('%s %s → %s', $label, fmt_num($before, 3), fmt_num($after, 3));
        }
        if ($hadIdeal && !$hasIdeal) $lost[] = $label;
    }

    // Eight named, then a count, for the reason project_type_usage_phrase() stops
    // at four: past that it is a paragraph, not a message.
    $list = function (array $items): string {
        $shown = array_slice($items, 0, 8);
        return implode(', ', $shown) . (count($items) > 8 ? ', and ' . (count($items) - 8) . ' more' : '');
    };

    $out = [];
    if (!$moved) {
        $out[] = ['ok', sprintf('Every step plans on the same days per item on the %s sheet as it did '
                              . 'on %s, so no date, headcount or budget moved.', $to, $from)];
    } else {
        $one = count($moved) === 1;
        $out[] = ['warn', sprintf('%d step%s now plan%s on different days per item, and the dates, crew '
                                . 'and budget moved with %s: %s.',
                                  count($moved), $one ? '' : 's', $one ? 's' : '',
                                  $one ? 'it' : 'them', $list($moved))];
    }
    if ($lost) {
        $one = count($lost) === 1;
        $out[] = ['warn', sprintf('%d step%s had a studio ideal on %s and %s none on %s, so %s on this '
                                . "project's own days per item until one is written there: %s.",
                                  count($lost), $one ? '' : 's', $from, $one ? 'has' : 'have', $to,
                                  $one ? 'it plans' : 'they plan', $list($lost))];
    }
    return $out;
}

/**
 * The "+ New project type" button, and the panel it opens.
 *
 * Drawn on Ideal quotas beside the type tabs, and on Basics beside the type
 * picker, which is where somebody finds out they need one. One renderer so the
 * two cannot drift, and one handler, in standards.php.
 *
 * ⚠ ON BASICS THESE CONTROLS BELONG TO ANOTHER FORM. The panel sits inside the
 * Basics form, and forms cannot nest: the parser drops an inner <form> tag, and
 * Create would have posted the project's details to the wrong page. So there
 * every control names its form with the form attribute, and the caller draws
 * that empty form after its own has closed. $formId says which; null draws an
 * ordinary form.
 *
 * @param string $copyDefault the sheet preselected to start from
 * @param array  $hidden      extra fields to post, such as where to come back to
 */
function project_type_new_form(PDO $pdo, string $copyDefault, array $hidden = [],
                               ?string $formId = null): void {
    $types = standard_project_types($pdo);
    $counts = [];
    if (standards_available($pdo)) {
        $q = $pdo->query('SELECT project_type, COUNT(*) AS n FROM quota_standards GROUP BY project_type');
        foreach ($q as $r) $counts[mb_strtolower((string)$r['project_type'])] = (int)$r['n'];
    }
    $f = $formId !== null ? ' form="' . e($formId) . '"' : '';
    ?>
    <details class="type-new">
      <summary class="row-btn">+ New project type</summary>
      <?php if ($formId === null): ?><form method="post" class="type-new-form"><?php else: ?><div class="type-new-form"><?php endif; ?>
        <input type="hidden" name="action" value="new_type"<?= $f ?>>
        <?php foreach ($hidden as $k => $v): ?>
          <input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>"<?= $f ?>>
        <?php endforeach; ?>
        <label class="inline">Name
          <input name="type_name" maxlength="20" size="18" required autocomplete="off"
                 placeholder="e.g. Motion comic"<?= $f ?>>
        </label>
        <label class="inline">Start its ideal quotas from
          <select name="copy_from"<?= $f ?>>
            <?php foreach ($types as $t): $n = $counts[mb_strtolower($t)] ?? 0; ?>
              <option value="<?= e($t) ?>" <?= $t === $copyDefault ? 'selected' : '' ?>>a copy of the <?= e($t) ?> sheet, <?= $n ?> ideal<?= $n === 1 ? '' : 's' ?></option>
            <?php endforeach; ?>
            <option value="" <?= in_array($copyDefault, $types, true) ? '' : 'selected' ?>>nothing, an empty sheet</option>
          </select>
        </label>
        <button type="submit"<?= $f ?>>Create project type</button>
        <small>
          A copy is the safe start: a project moved onto the new type plans exactly as it did until
          a number on the new sheet is changed, and changing the new sheet never touches the one it
          was copied from.
        </small>
      <?php if ($formId === null): ?></form><?php else: ?></div><?php endif; ?>
    </details>
    <?php
}

/**
 * The identity of a step, as a single string key.
 *
 * The triple migration 009 made unique, and the one ROADMAP names as the
 * cross-project comparison key. Lowercased so that a step typed "concept" on one
 * show matches "Concept" on another — the same normalisation the alias layer and
 * the asset-type merge apply, and for the same reason.
 */
function standard_key(string $name, string $appliesTo, ?string $assetType): string {
    return mb_strtolower(trim($name)) . '|' . $appliesTo . '|' . mb_strtolower(trim((string)$assetType));
}

/** Every standard for one kind of project, keyed by standard_key(). */
function standards_for_type(PDO $pdo, string $projectType): array {
    if (!standards_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT * FROM quota_standards WHERE project_type = ?
          ORDER BY step_name, applies_to, applies_to_asset_type'
    );
    $stmt->execute([$projectType]);
    $out = [];
    foreach ($stmt as $r) {
        $out[standard_key($r['step_name'], $r['applies_to'], $r['applies_to_asset_type'])] = $r;
    }
    return $out;
}

/**
 * The same list, IN PIPELINE ORDER — the order a show is actually made in.
 *
 * Alphabetical put Animatic next to CH sheet next to Cleanup, which is three
 * different phases of production filed by first letter. This sheet is read
 * beside the Dates & headcount screen, and the two disagreeing about the order
 * of the same steps makes comparing them a hunting exercise (David, 2026-08-17).
 *
 * ⚠ THE ORDER HAS TO BE BORROWED, because this table has none of its own. It is
 * studio-wide — one row per step identity per project TYPE — while step_order
 * lives on a project's own pipeline. So each standard takes the EARLIEST position
 * that identity holds on any project of that type, which is what "where this step
 * comes in a show of this kind" means when there are several shows.
 *
 * Identities no project currently uses have no position to borrow. They sort last,
 * alphabetically, rather than being dropped or guessed at — a standard written for
 * a step nobody runs yet is still a standard.
 */
function standards_list(PDO $pdo, string $projectType): array {
    if (!standards_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT qs.*,
                (SELECT ps.color
                   FROM pipeline_steps ps
                   JOIN projects p ON p.id = ps.project_id
                  WHERE p.type = qs.project_type
                    AND ps.name = qs.step_name
                    AND ps.applies_to = qs.applies_to
                    AND COALESCE(ps.applies_to_asset_type, \'\') = qs.applies_to_asset_type
                  ORDER BY ps.step_order LIMIT 1
                ) AS color,
                (SELECT MIN(ps.step_order)
                   FROM pipeline_steps ps
                   JOIN projects p ON p.id = ps.project_id
                  WHERE p.type = qs.project_type
                    AND ps.name = qs.step_name
                    AND ps.applies_to = qs.applies_to
                    AND COALESCE(ps.applies_to_asset_type, \'\') = qs.applies_to_asset_type
                ) AS pipeline_order
           FROM quota_standards qs
          WHERE qs.project_type = ?
          ORDER BY pipeline_order IS NULL, pipeline_order,
                   qs.step_name, qs.applies_to, qs.applies_to_asset_type'
    );
    $stmt->execute([$projectType]);
    return $stmt->fetchAll();
}

/* ---------------------------------------------------------------------------
 * THE ONE NUMBER THE PLAN RUNS ON
 *
 * David, 2026-08-17: *"Let's plan with the ideal quotas."* So the studio standard
 * is not a comparison any more — it IS days-per-item, everywhere. The value typed
 * on Dates & headcount is the fallback for steps the base sheet has no answer for.
 *
 * ⚠ EVERY CALCULATION GOES THROUGH effective_days_per_unit(). Before this there
 * were six separate readers of `pipeline_steps.days_per_unit` — the quota, the
 * scheduler, the budget, the time-log comparison, the CSV export and the estimate
 * baseline — and the budget had already been switched to the ideal on its own.
 * That is how the Dates & headcount screen and the expense projection came to
 * disagree about the same step. One reader, one answer.
 * ------------------------------------------------------------------------- */

/**
 * standards_for_type() memoised per request.
 *
 * The helper below is called once per step inside loops that already run per
 * step, so the uncached version turned one query into thirty. Static rather than
 * a cache table: it is only ever right for the length of one request anyway.
 */
function standards_cached(PDO $pdo, string $projectType): array {
    static $byType = [];
    if (!array_key_exists($projectType, $byType)) {
        $byType[$projectType] = standards_for_type($pdo, $projectType);
    }
    return $byType[$projectType];
}

/** A project's type, memoised — the helper needs it and callers have an id. */
function project_type_of(PDO $pdo, int $projectId): string {
    static $types = [];
    if (!array_key_exists($projectId, $types)) {
        $stmt = $pdo->prepare('SELECT type FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $types[$projectId] = (string)($stmt->fetchColumn() ?: '');
    }
    return $types[$projectId];
}

/** The standard governing one step, or null. */
function step_standard(PDO $pdo, int $projectId, array $step): ?array {
    if (!standards_available($pdo)) return null;
    $sheet = standards_cached($pdo, project_type_of($pdo, $projectId));
    if (!$sheet) return null;
    return $sheet[standard_key($step['name'] ?? '', $step['applies_to'] ?? 'shot',
                               $step['applies_to_asset_type'] ?? '')] ?? null;
}

/**
 * Days per item for planning: the studio ideal, or the typed estimate when the
 * base sheet has nothing to say about this step.
 *
 * ⚠ NOT retake-adjusted. Retakes are applied by the caller, because not every
 * caller applies them the same way — a per-second COST must not be inflated by
 * them (the seconds delivered do not change) while the DAYS must be. Folding the
 * retake in here would make that distinction impossible to express.
 */
function effective_days_per_unit(PDO $pdo, int $projectId, array $step): float {
    $std = step_standard($pdo, $projectId, $step);
    if ($std !== null && (float)$std['days_per_unit'] > 0) return (float)$std['days_per_unit'];
    return (float)($step['days_per_unit'] ?: 1.0);
}

/** 'ideal' or 'estimate' — which of the two the figure above came from. */
function effective_days_source(PDO $pdo, int $projectId, array $step): string {
    $std = step_standard($pdo, $projectId, $step);
    return ($std !== null && (float)$std['days_per_unit'] > 0) ? 'ideal' : 'estimate';
}

/**
 * The ideal for one step of one project, or null.
 *
 * Falls back from the project's own type to nothing — deliberately NOT to
 * another type. A 2D short's Concept standard is not evidence about a 3D
 * series, and quietly borrowing one would put a number on screen that nobody
 * agreed to. ROADMAP is explicit that comparing across types "would average
 * away the signal".
 */
function standard_for_step(PDO $pdo, string $projectType, array $step): ?array {
    $all = standards_for_type($pdo, $projectType);
    $key = standard_key($step['name'], $step['applies_to'] ?? 'shot',
                        $step['applies_to_asset_type'] ?? '');
    return $all[$key] ?? null;
}

/**
 * Save a base sheet, from the editing grid. Returns what it did.
 *
 * A blank days-per-unit REMOVES that standard rather than storing a zero: zero
 * days per item is not a slow standard, it is a step that takes no time, and the
 * planner would divide by it.
 *
 * @param array $rows  each ['step_name','applies_to','applies_to_asset_type',
 *                           'days_per_unit','typical_headcount','source','notes']
 * @return array ['saved' => int, 'removed' => int, 'errors' => string[]]
 */
function save_standards(PDO $pdo, string $projectType, array $rows, string $who = ''): array {
    if (!standards_available($pdo)) {
        throw new RuntimeException(
            'The database updates for ideal quotas have not been applied yet (migration 026).'
        );
    }
    $out = ['saved' => 0, 'removed' => 0, 'errors' => []];

    $ins = $pdo->prepare(
        'INSERT INTO quota_standards
           (project_type, step_name, applies_to, applies_to_asset_type,
            days_per_unit, typical_headcount, source, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            days_per_unit = VALUES(days_per_unit),
            typical_headcount = VALUES(typical_headcount),
            source = VALUES(source), notes = VALUES(notes),
            updated_by = VALUES(updated_by)'
    );
    $del = $pdo->prepare(
        'DELETE FROM quota_standards
          WHERE project_type = ? AND step_name = ? AND applies_to = ? AND applies_to_asset_type = ?'
    );
    $log = column_exists($pdo, 'quota_standards_log', 'days_per_unit')
        ? $pdo->prepare(
            'INSERT INTO quota_standards_log
               (project_type, step_name, applies_to, applies_to_asset_type,
                days_per_unit, typical_headcount, source, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
          )
        : null;

    foreach ($rows as $i => $row) {
        $name = trim((string)($row['step_name'] ?? ''));
        if ($name === '') continue;                       // an unused grid row

        $appliesTo = in_array($row['applies_to'] ?? '', ['shot', 'asset', 'both', 'project'], true)
                   ? $row['applies_to'] : 'shot';
        $assetType = trim((string)($row['applies_to_asset_type'] ?? ''));

        $rawDays = trim((string)($row['days_per_unit'] ?? ''));
        if ($rawDays === '') {
            // Cleared on purpose. Removed rather than zeroed — see the docblock.
            $del->execute([$projectType, $name, $appliesTo, $assetType]);
            if ($del->rowCount() > 0) {
                $out['removed']++;
                if ($log) $log->execute([$projectType, $name, $appliesTo, $assetType,
                                         null, null, 'cleared', $who]);
            }
            continue;
        }
        if (!is_numeric($rawDays) || (float)$rawDays <= 0) {
            $out['errors'][] = sprintf('"%s": %s is not a number of days above zero.', $name, $rawDays);
            continue;
        }
        $days = round((float)$rawDays, 3);

        $rawHead = trim((string)($row['typical_headcount'] ?? ''));
        $head = ($rawHead !== '' && is_numeric($rawHead) && (float)$rawHead > 0)
              ? round((float)$rawHead, 2) : null;

        $ins->execute([
            $projectType, $name, $appliesTo, $assetType, $days, $head,
            trim((string)($row['source'] ?? '')) ?: null,
            trim((string)($row['notes'] ?? '')) ?: null,
            $who,
        ]);
        $out['saved']++;
        if ($log) $log->execute([$projectType, $name, $appliesTo, $assetType, $days, $head,
                                 trim((string)($row['source'] ?? '')) ?: null, $who]);
    }
    return $out;
}

/**
 * Change the days-per-item of ONE existing studio ideal, and nothing else.
 *
 * David, 2026-09-16: Dates & headcount showed an editable "days per item" that
 * the plan ignored whenever a studio ideal existed. "It makes no sense to have
 * an editable field that's not used." The field now IS the ideal, and this is
 * what saving it calls.
 *
 * ⚠ A MERGE, NOT A PLAIN save_standards() CALL. save_standards() writes every
 * column of a standard - typical headcount, source and notes as well as the
 * days - so handing it a row with only the days would wipe those three on the
 * studio sheet for every project of the type. The existing row is read first
 * and only the days are replaced. It still goes THROUGH save_standards(), so
 * there is one writer and the change lands in quota_standards_log like any
 * other.
 *
 * ⚠ NEVER REMOVES A STANDARD. A blank on the Ideal quotas sheet deletes the
 * standard, which is right on the screen whose job that is. From a single
 * project's planning table it would delete a studio-wide number as a side effect
 * of clearing a box, so a blank is refused here.
 *
 * Returns the old value, or null when nothing changed. Throws, writing nothing,
 * when the value is not a number of days above zero.
 */
function update_standard_days(PDO $pdo, string $projectType, array $step, string $raw,
                              string $who = ''): ?float {
    $key = standard_key($step['name'] ?? '', $step['applies_to'] ?? 'shot',
                        $step['applies_to_asset_type'] ?? '');
    $sheet = standards_for_type($pdo, $projectType);
    $row = $sheet[$key] ?? null;
    if ($row === null) {
        throw new RuntimeException(sprintf('"%s" has no studio ideal to change.', $step['name'] ?? ''));
    }

    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException(sprintf(
            '%s: a studio ideal cannot be removed from a project screen, because it applies to every '
          . '%s project. It was left as it was. Remove it on the Ideal quotas sheet if that is meant.',
            $step['name'] ?? '', $projectType));
    }
    if (!is_numeric($raw) || (float)$raw <= 0) {
        throw new RuntimeException(sprintf('%s: "%s" is not a number of days above zero, so the '
                                         . 'studio ideal was left as it was.', $step['name'] ?? '', $raw));
    }

    $old = (float)$row['days_per_unit'];
    $new = round((float)$raw, 3);
    // ⚠ Within a thousandth is NOT a change. The column holds three decimals,
    // and a page that re-posts a displayed value must never rewrite a studio
    // number just by being saved.
    if (abs($new - $old) < 0.0005) return null;

    $r = save_standards($pdo, $projectType, [[
        'step_name'             => $row['step_name'] ?? ($step['name'] ?? ''),
        'applies_to'            => $row['applies_to'] ?? ($step['applies_to'] ?? 'shot'),
        'applies_to_asset_type' => $row['applies_to_asset_type'] ?? ($step['applies_to_asset_type'] ?? ''),
        'days_per_unit'         => (string)$new,
        // Carried through unchanged - see the docblock.
        'typical_headcount'     => $row['typical_headcount'] ?? '',
        'source'                => $row['source'] ?? '',
        'notes'                 => $row['notes'] ?? '',
    ]], $who);
    if (!empty($r['errors'])) throw new RuntimeException(implode(' ', $r['errors']));
    return $old;
}

/**
 * Compare a project's steps against the base sheet for its type.
 *
 * Returns one row per step with the ideal, this project's estimate, and the
 * difference — plus a `verdict` that is the sentence somebody actually needs.
 *
 * NOTHING IS CHANGED BY THIS. The comparison reports; a person decides. That is
 * the same rule the near-duplicate asset types follow (2026-08-13): detection
 * only, never a silent merge, because guessing quietly is the habit the alias
 * layer exists to avoid.
 */
function standards_drift(PDO $pdo, array $project): array {
    $out = [];
    if (!standards_available($pdo)) return $out;

    $standards = standards_for_type($pdo, (string)$project['type']);
    if (!$standards) return $out;

    foreach (pipeline_steps($pdo, (int)$project['id']) as $step) {
        $key = standard_key($step['name'], $step['applies_to'] ?? 'shot',
                            $step['applies_to_asset_type'] ?? '');
        $std = $standards[$key] ?? null;

        $planned = (float)($step['days_per_unit'] ?: 0);
        $ideal   = $std ? (float)$std['days_per_unit'] : null;

        $verdict = 'No standard recorded for this step yet.';
        $state   = 'none';
        if ($ideal !== null && $planned > 0) {
            $ratio = $planned / $ideal;
            if (abs($ratio - 1) < 0.05) {
                $verdict = 'Planned at the studio standard.';
                $state   = 'ok';
            } elseif ($ratio < 1) {
                // Under the money, whatever else it is. Deliverability is a
                // separate question and a separate warning.
                $verdict = sprintf('Planned %s%% faster than the standard, %s a day against %s.',
                    fmt_num((1 - $ratio) * 100, 0),
                    fmt_num($planned > 0 ? 1 / $planned : 0, 2),
                    fmt_num(1 / $ideal, 2));
                $state   = 'fast';
            } else {
                // The budget is built on the standard, so slower than it is
                // spending days nobody costed — not "the safe direction", which
                // is what this said before David corrected it (2026-08-17).
                $verdict = sprintf('Planned %s%% SLOWER than the standard, beyond the days the '
                                 . 'budget was built on.', fmt_num(($ratio - 1) * 100, 0));
                $state   = 'slow';
            }
        }

        $out[] = [
            'step'    => $step,
            'ideal'   => $ideal,
            'planned' => $planned ?: null,
            'standard' => $std,
            'verdict' => $verdict,
            'state'   => $state,
        ];
    }
    return $out;
}

/** "0.50 days each · 2.00 a day" — the same fact both ways round. */
function fmt_standard(float $daysPerUnit): string {
    if ($daysPerUnit <= 0) return '—';
    return fmt_num($daysPerUnit, 2) . ' days each · ' . fmt_num(1 / $daysPerUnit, 2) . ' a day';
}
