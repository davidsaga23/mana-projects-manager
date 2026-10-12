<?php
/**
 * Ideal quotas: what each step of a project should take, kept per project.
 *
 * THE TWO NUMBERS, because this file only makes sense against the other one:
 *
 *   REQUIRED  what the deadline demands. step_quota() derives it from work
 *             remaining over working days left. Nobody types it; it moves when
 *             the date or the progress moves.
 *   IDEAL     what THIS project says a step takes when done properly, as days per
 *             item: pipeline_steps.days_per_unit. The schedule, the quotas and the
 *             budget all run on it.
 *
 * A required pace far above the ideal is not an ambitious plan, it is one the
 * people on it cannot deliver, and that comparison is what the screens draw.
 *
 * ⚠ PER PROJECT SINCE 2026-09-17 (migration 034). David: "between projects (even
 * if they're the same type) might have different ideal quotas and that will change
 * my budget so this needs to be per project. We can start copying another one but
 * if I change something it shouldn't affect other projects at all."
 *
 * From 2026-08-17 until then the ideal was one sheet per project TYPE
 * (quota_standards), and a change to it reached every show of the type. Those
 * sheets are still in the database. Nothing reads them except the fallback in
 * effective_days_per_unit(), which keeps the old rule for exactly as long as 034
 * is waiting to be applied, so no budget moves in between.
 *
 * Stored as DAYS PER ITEM rather than items per day, because everything it feeds
 * is already in those terms. Screens show both, because "half a day each" and
 * "two a day" are the same fact and people say whichever suits the step.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/studio.php';        // the Studio is filtered out below

/** Has migration 026 (the per-type sheets) been applied? Only the fallback needs them now. */
function standards_available(PDO $pdo): bool {
    return column_exists($pdo, 'quota_standards', 'days_per_unit');
}

/**
 * Are ideal quotas per project yet?
 *
 * ⚠ MIGRATION 034 RECORDED AS APPLIED, not a column existing. 034 copies each
 * type sheet's numbers into the steps that were planning on them, and until that
 * copy has happened a step's own days per item is the old value the plan was
 * ignoring. Reading it any earlier would move budgets for as long as the update
 * sat waiting.
 */
function ideals_per_project(PDO $pdo): bool {
    return migration_applied($pdo, '034_ideal_quotas_per_project.sql');
}

/**
 * The identity of a step, as a single string key.
 *
 * The triple migration 009 made unique, and the one ROADMAP names as the
 * cross-project comparison key. Lowercased so that a step typed "concept" on one
 * show matches "Concept" on another, the same normalisation the alias layer and
 * the asset-type merge apply, and for the same reason. Copying ideal quotas from
 * one project to another matches steps by it.
 */
function standard_key(string $name, string $appliesTo, ?string $assetType): string {
    return mb_strtolower(trim($name)) . '|' . $appliesTo . '|' . mb_strtolower(trim((string)$assetType));
}

/* ---------------------------------------------------------------------------
 * THE ONE NUMBER THE PLAN RUNS ON
 *
 * ⚠ EVERY CALCULATION GOES THROUGH effective_days_per_unit(). Before 2026-08-17
 * there were six separate readers of days per item (the quota, the scheduler, the
 * budget, the time-log comparison, the CSV export and the estimate baseline), and
 * two of them disagreeing is how Dates & headcount and the expense projection came
 * to report different numbers for the same step. One reader, one answer. Per
 * project or not, that rule stands.
 * ------------------------------------------------------------------------- */

/**
 * Days per item for planning.
 *
 * Since 034, the step's own number, which is this project's ideal. Before it, the
 * old rule: the type sheet's number where it had one, the step's own otherwise.
 *
 * ⚠ NOT retake-adjusted. Retakes are applied by the caller, because not every
 * caller applies them the same way: a per-second COST must not be inflated by
 * them (the seconds delivered do not change) while the DAYS must be.
 */
function effective_days_per_unit(PDO $pdo, int $projectId, array $step): float {
    if (ideals_per_project($pdo)) return (float)($step['days_per_unit'] ?: 1.0);
    $std = step_standard($pdo, $projectId, $step);
    if ($std !== null && (float)$std['days_per_unit'] > 0) return (float)$std['days_per_unit'];
    return (float)($step['days_per_unit'] ?: 1.0);
}

/**
 * 'ideal' or 'estimate': which kind of number the figure above is.
 *
 * Since 034 always 'ideal'. A project's own days per item IS its ideal, so there
 * is no second number left for it to be an estimate against.
 */
function effective_days_source(PDO $pdo, int $projectId, array $step): string {
    if (ideals_per_project($pdo)) return 'ideal';
    $std = step_standard($pdo, $projectId, $step);
    return ($std !== null && (float)$std['days_per_unit'] > 0) ? 'ideal' : 'estimate';
}

/* ---------------------------------------------------------------------------
 * The per-type sheets, read only by the fallback above, until 034.
 * ------------------------------------------------------------------------- */

/** Every standard on one type's sheet, keyed by standard_key(). */
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
 * standards_for_type() memoised per request.
 *
 * The fallback is called once per step inside loops that already run per step,
 * so the uncached version turned one query into thirty.
 */
function standards_cached(PDO $pdo, string $projectType): array {
    static $byType = [];
    if (!array_key_exists($projectType, $byType)) {
        $byType[$projectType] = standards_for_type($pdo, $projectType);
    }
    return $byType[$projectType];
}

/** A project's type, memoised: the fallback needs it and callers have an id. */
function project_type_of(PDO $pdo, int $projectId): string {
    static $types = [];
    if (!array_key_exists($projectId, $types)) {
        $stmt = $pdo->prepare('SELECT type FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $types[$projectId] = (string)($stmt->fetchColumn() ?: '');
    }
    return $types[$projectId];
}

/** The type sheet's standard for one step, or null. */
function step_standard(PDO $pdo, int $projectId, array $step): ?array {
    if (!standards_available($pdo)) return null;
    $sheet = standards_cached($pdo, project_type_of($pdo, $projectId));
    if (!$sheet) return null;
    return $sheet[standard_key($step['name'] ?? '', $step['applies_to'] ?? 'shot',
                               $step['applies_to_asset_type'] ?? '')] ?? null;
}

/* ---------------------------------------------------------------------------
 * EDITING AND COPYING ONE PROJECT'S IDEAL QUOTAS
 *
 * Two screens edit the same number, stored once on the step: the project's Ideal
 * quotas tab and Dates & headcount. Both check a typed value with
 * ideal_days_from_input(), so they cannot disagree about what is allowed.
 * Administrators only, on both, as ideal quotas have been since 2026-08-17: the
 * budget is built on them.
 * ------------------------------------------------------------------------- */

/**
 * A typed days per item, checked.
 *
 * Returns the number to store, or null when it is not a change. Within a
 * thousandth is not a change: the column holds three decimals, and a page that
 * re-posts what it displayed must never rewrite a number by rounding it.
 *
 * Throws, naming the step, when it is not a number of days above zero. Zero days
 * per item is not a fast step, it is one that takes no time, and every plan
 * divides by it.
 */
function ideal_days_from_input(string $label, string $raw, float $was): ?float {
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw) || (float)$raw <= 0) {
        throw new RuntimeException(sprintf(
            '%s: "%s" is not a number of days above zero, so it kept %s.',
            $label, $raw, fmt_num($was, 3)));
    }
    $new = round((float)$raw, 3);
    return abs($new - $was) < 0.0005 ? null : $new;
}

/** What a changed number's note becomes when nobody wrote one. */
function ideal_set_by(string $who): string {
    return mb_substr(sprintf('Set by %s on %s', $who !== '' ? $who : 'somebody', date('Y-m-d')), 0, 120);
}

/** Where a step's days per item came from, as its note says, or ''. */
function ideal_source_note(array $step): string {
    return trim((string)($step['days_source'] ?? ''));
}

/** "Layout 2 → 1.5, Colour 1 → 0.75, and 4 more": a list for a sentence, cut at $max. */
function ideal_list(array $items, int $max = 8): string {
    $shown = array_slice($items, 0, $max);
    return implode(', ', $shown) . (count($items) > $max ? ', and ' . (count($items) - $max) . ' more' : '');
}

/**
 * Save the Ideal quotas tab: each step's days per item and its note.
 *
 * ⚠ A CHANGED NUMBER WHOSE NOTE WAS LEFT ALONE GETS A NEW NOTE. "Copied from FC
 * Mobile" under a number somebody has since changed would go on claiming a
 * source it no longer has, so it becomes "Set by David on 2026-09-17" unless the
 * note was edited in the same save.
 *
 * @param array $days    step id => typed days per item
 * @param array $sources step id => typed note
 * @return array ['changed' => string[], 'notes' => int, 'errors' => string[]]
 */
function save_project_ideals(PDO $pdo, int $projectId, array $days, array $sources, string $who = ''): array {
    if (!ideals_per_project($pdo)) {
        throw new RuntimeException('Ideal quotas become per project with database update 034, which has '
                                 . 'not been applied yet, so nothing was saved.');
    }
    $steps = pipeline_steps($pdo, $projectId);
    $upd = $pdo->prepare(
        'UPDATE pipeline_steps SET days_per_unit = ?, days_source = ? WHERE id = ? AND project_id = ?'
    );
    $out = ['changed' => [], 'notes' => 0, 'errors' => []];

    foreach ($steps as $s) {
        $id = (int)$s['id'];
        if (!isset($days[$id])) continue;
        $label = step_display_name($s, $steps);
        $was = (float)($s['days_per_unit'] ?: 0);
        $oldNote = ideal_source_note($s);
        $note = isset($sources[$id]) ? mb_substr(trim((string)$sources[$id]), 0, 120) : $oldNote;

        try {
            $new = ideal_days_from_input($label, (string)$days[$id], $was);
        } catch (RuntimeException $e) {
            $out['errors'][] = $e->getMessage();
            continue;
        }
        if ($new === null && $note === $oldNote) continue;

        if ($new !== null) {
            if ($note === $oldNote) $note = ideal_set_by($who);
            $out['changed'][] = sprintf('%s %s → %s', $label, fmt_num($was, 3), fmt_num($new, 3));
        } else {
            $out['notes']++;
        }
        $upd->execute([$new ?? $was, $note !== '' ? $note : null, $id, $projectId]);
    }
    return $out;
}

/**
 * The projects another project's ideal quotas can be copied from.
 *
 * Every other project that has steps, its own type first: a show of the same kind
 * is the likeliest starting point, but not the only one, and the list does not
 * pretend otherwise. Archived shows are included and marked, because a finished
 * show is often the best evidence there is.
 */
function project_ideal_copy_sources(PDO $pdo, array $project): array {
    $st = $pdo->prepare(
        "SELECT p.id, p.name, p.code, p.type, p.status, COUNT(ps.id) AS steps
           FROM projects p
           JOIN pipeline_steps ps ON ps.project_id = p.id
          WHERE p.id <> ?"
        // The Studio has no pipeline worth copying. (includes/studio.php)
        . studio_exclude_sql($pdo, 'p') . "
          GROUP BY p.id, p.name, p.code, p.type, p.status
          ORDER BY p.type = ? DESC, p.status = 'archived', p.name"
    );
    $st->execute([(int)$project['id'], (string)$project['type']]);
    return $st->fetchAll();
}

/**
 * Copy another project's days per item onto this one, step by step.
 *
 * Matched by step identity (name, what it applies to, asset type), the way the
 * sheets always matched. A step the other project does not have keeps its own
 * number and is named in the result, so nobody assumes it was copied.
 *
 * ⚠ A COPY, NEVER A LINK. The numbers are written into this project's own steps,
 * and nothing remembers where they came from except the note that says so. A
 * change to either project afterwards reaches nothing on the other, which is the
 * whole of David's rule for this (2026-09-17).
 *
 * @return array ['from' => project row, 'changed' => string[], 'same' => int, 'unmatched' => string[]]
 */
function copy_project_ideals(PDO $pdo, int $toId, int $fromId, string $who = ''): array {
    if (!ideals_per_project($pdo)) {
        throw new RuntimeException('Copying ideal quotas between projects needs database update 034, '
                                 . 'which has not been applied yet, so nothing was copied.');
    }
    if ($fromId <= 0) throw new RuntimeException('Pick the project to copy the ideal quotas from.');
    if ($fromId === $toId) throw new RuntimeException('That is this project, so there was nothing to copy.');

    $p = $pdo->prepare('SELECT id, name, code FROM projects WHERE id = ?');
    $p->execute([$fromId]);
    $from = $p->fetch();
    if (!$from) throw new RuntimeException('That project no longer exists, so nothing was copied.');

    $source = [];
    foreach (pipeline_steps($pdo, $fromId) as $s) {
        if ((float)$s['days_per_unit'] <= 0) continue;
        $source[standard_key($s['name'], $s['applies_to'] ?? 'shot', $s['applies_to_asset_type'] ?? '')]
            = (float)$s['days_per_unit'];
    }
    $steps = pipeline_steps($pdo, $toId);
    if (!$steps) {
        throw new RuntimeException('This project has no pipeline steps yet, so there is nothing to copy onto.');
    }

    $note = mb_substr(sprintf('Copied from %s%s on %s', $from['name'],
                              $who !== '' ? ' by ' . $who : '', date('Y-m-d')), 0, 120);
    $upd = $pdo->prepare(
        'UPDATE pipeline_steps SET days_per_unit = ?, days_source = ? WHERE id = ? AND project_id = ?'
    );

    $out = ['from' => $from, 'changed' => [], 'same' => 0, 'unmatched' => []];
    $pdo->beginTransaction();
    try {
        foreach ($steps as $s) {
            $label = step_display_name($s, $steps);
            $key = standard_key($s['name'], $s['applies_to'] ?? 'shot', $s['applies_to_asset_type'] ?? '');
            if (!isset($source[$key])) {
                $out['unmatched'][] = $label;
                continue;
            }
            $was = (float)($s['days_per_unit'] ?: 0);
            $now = round($source[$key], 3);
            // Already the same number: left alone, note and all, because where it
            // came from is still true.
            if (abs($was - $now) < 0.0005) {
                $out['same']++;
                continue;
            }
            $upd->execute([$now, $note, (int)$s['id'], $toId]);
            $out['changed'][] = sprintf('%s %s → %s', $label, fmt_num($was, 3), fmt_num($now, 3));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/** The messages for a copy, said the same way from both screens that offer one. */
function copy_ideals_flash(array $r): void {
    $name = (string)$r['from']['name'];
    $n = count($r['changed']);
    if ($n > 0) {
        flash('ok', sprintf('Copied %d ideal quota%s from %s: %s. This project\'s schedule and budget follow '
                          . 'them, and no other project changed.',
                            $n, $n === 1 ? '' : 's', $name, ideal_list($r['changed'])));
    } elseif ($r['same'] > 0) {
        flash('ok', sprintf('Every step that %s also has already had the same days per item, so nothing '
                          . 'changed.', $name));
    }
    $u = count($r['unmatched']);
    if ($u > 0) {
        flash('warn', sprintf('%d step%s no step with the same name and scope on %s, so %s own days per '
                            . 'item: %s.',
                              $u, $u === 1 ? ' has' : 's have', $name,
                              $u === 1 ? 'it kept its' : 'they kept their', ideal_list($r['unmatched'])));
    }
}

/**
 * "Start from another project's ideal quotas": a project picker and a button.
 *
 * Drawn on the Ideal quotas tab and on Dates & headcount, which is where a new
 * project's numbers are first set, both posting action=copy_ideals to their own
 * screen. Asks before copying, because it changes this project's budget.
 */
function project_ideals_copy_form(PDO $pdo, array $project): void {
    $sources = project_ideal_copy_sources($pdo, $project);
    if (!$sources) return;
    $sameType = array_values(array_filter($sources, fn($s) => $s['type'] === $project['type']));
    $others   = array_values(array_filter($sources, fn($s) => $s['type'] !== $project['type']));
    $groups = [];
    if ($sameType) $groups[(string)$project['type'] . ' projects'] = $sameType;
    if ($others)   $groups[$sameType ? 'Other projects' : 'Projects'] = $others;
    $ask = "Copy days per item from the project you picked?\n\nIt changes this project's schedule "
         . "and budget, and nothing on any other project.";
    ?>
    <form method="post" class="inline-form toggle-form ideals-copy"
          onsubmit="return confirm(<?= e(json_encode($ask)) ?>);">
      <input type="hidden" name="action" value="copy_ideals">
      <label class="inline">Start from another project's ideal quotas
        <select name="copy_from_project" required>
          <option value="">pick a project…</option>
          <?php foreach ($groups as $label => $rows): ?>
            <optgroup label="<?= e($label) ?>">
              <?php foreach ($rows as $src): ?>
                <option value="<?= (int)$src['id'] ?>"><?= e($src['name']) ?> (<?= e($src['code']) ?>)<?=
                  $src['type'] !== $project['type'] ? ', ' . e($src['type']) : '' ?><?=
                  $src['status'] === 'archived' ? ', archived' : '' ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="secondary">Copy days per item</button>
      <small>
        Each step takes the number from the step with the same name and scope on that project, and
        steps it does not have keep theirs. A copy, not a link: changing either project afterwards
        changes nothing on the other.
      </small>
    </form>
    <?php
}
