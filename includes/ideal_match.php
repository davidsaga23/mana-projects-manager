<?php
/**
 * Matching one project's ideal quotas to an approved expense projection.
 *
 * David, 2026-09-17: "can you re-adjust the budget for FCM to match this approved
 * project expense? Comparing the current budget for that project is what made me
 * realize something was changing." The approved sheet is the hand-built
 * RTC_ExpenseProjection_v01.xlsx the platform was matched against on 2026-08-17.
 * The project had drifted from it since, because the type sheet it planned on
 * was edited from other projects.
 *
 * WHAT IT DOES. Each role's person-days in the approved sheet (every artist row
 * of that role added up) are worked back into days per item for the matching
 * step:
 *
 *     days per item = approved person-days / (this project's count x retake factor)
 *
 * using THIS PROJECT'S counts, coverage and retake allowance, the same ones
 * step_quota() and the expense projection use, so the projection it produces
 * carries the approved days. The sheet brings the days; the platform brings the
 * counts. That is also why a sheet that leaves the count blank, as every footage
 * row in the RTC one does, still matches.
 *
 * ⚠ PREVIEW FIRST, ALWAYS. A role typed by hand in a spreadsheet does not always
 * name a step the way the platform does ("Character Sheet" against "CH sheet"),
 * so every match is shown, and can be changed, before anything is written.
 *
 * ⚠ DAYS PER ITEM ONLY. Rates live in the finance database and are compared
 * here, never changed. A flat fee's cost does not depend on its days, so those
 * rows are left alone and said to be.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/finance.php';     // expense_projection(), quota.php, crew.php, standards.php

/** A role or step label reduced to what a match compares: case, and runs of spaces. */
function approved_key(string $label): string {
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $label)));
}

/**
 * The approved sheet, read into roles.
 *
 * Columns are found by their HEADER TEXT, not their position, so a sheet with a
 * column added or moved still reads. The header row is the first one holding
 * both "Role / Position" and "Total Days".
 *
 * @return array ['title' => string, 'total' => ?float, 'roles' => list of
 *               ['label','days','rows','units','rates','flat','no_days']]
 */
function approved_projection_read(string $path): array {
    $rows = xlsx_read_rows($path);

    $head = null;
    $col = [];
    foreach ($rows as $i => $r) {
        $at = [];
        foreach ($r as $j => $v) {
            $k = approved_key((string)$v);
            if ($k !== '' && !isset($at[$k])) $at[$k] = $j;
        }
        $role = $at['role / position'] ?? ($at['role'] ?? null);
        if ($role === null || !isset($at['total days'])) continue;

        $starting = function (string $prefix) use ($at): ?int {
            foreach ($at as $text => $j) {
                if (strpos($text, $prefix) === 0) return $j;
            }
            return null;
        };
        $head = $i;
        $col = [
            'role'  => $role,
            'days'  => $at['total days'],
            'type'  => $starting('rate type'),
            'rate'  => $starting('day rate'),
            'units' => $starting('total units'),
            'cost'  => $starting('projected cost'),
        ];
        break;
    }
    if ($head === null) {
        throw new RuntimeException('That sheet has no header row with "Role / Position" and "Total Days" '
                                 . 'in it, so it does not read as an expense projection. Nothing was changed.');
    }

    $cell = function (array $r, ?int $j): string {
        return $j === null ? '' : trim((string)($r[$j] ?? ''));
    };

    $roles = [];
    $index = [];
    $total = null;
    for ($i = $head + 1, $n = count($rows); $i < $n; $i++) {
        $r = $rows[$i];

        // The grand total sits on a row of its own, labelled in the first column,
        // and ends the roles. What follows it is the summary block, whose figures
        // sit in the role column ("37" artist rows) and are not roles.
        if (strpos(approved_key((string)($r[0] ?? '')), 'grand total') === 0) {
            $v = $cell($r, $col['cost']);
            if (is_numeric($v)) $total = (float)$v;
            break;
        }

        $label = $cell($r, $col['role']);
        // A department band or a subtotal has nothing here; a stray figure is not
        // a role either, for a sheet with no grand total row to stop at.
        if ($label === '' || is_numeric($label)) continue;

        $key = approved_key($label);
        if (!isset($index[$key])) {
            $index[$key] = count($roles);
            $roles[] = ['label' => $label, 'days' => 0.0, 'rows' => 0, 'units' => null,
                        'rates' => [], 'flat' => false, 'no_days' => false];
        }
        $role = &$roles[$index[$key]];

        if (approved_key($cell($r, $col['type'])) === 'flat') {
            $role['flat'] = true;
            unset($role);
            continue;
        }
        $days = $cell($r, $col['days']);
        if (!is_numeric($days) || (float)$days <= 0) {
            unset($role);
            continue;
        }
        $role['days'] += (float)$days;
        $role['rows']++;
        $units = $cell($r, $col['units']);
        if ($role['units'] === null && is_numeric($units) && (float)$units > 0) $role['units'] = (float)$units;
        $rate = $cell($r, $col['rate']);
        if (is_numeric($rate) && (float)$rate > 0) $role['rates'][fmt_num((float)$rate, 2)] = true;
        unset($role);
    }

    foreach ($roles as &$role) {
        $role['no_days'] = !$role['flat'] && $role['rows'] === 0;
        $role['rates'] = array_keys($role['rates']);
    }
    unset($role);

    if (!array_filter($roles, fn($x) => $x['rows'] > 0)) {
        throw new RuntimeException('No row in that sheet has a role with days in it, so there is nothing to '
                                 . 'match. Nothing was changed.');
    }
    return ['title' => trim((string)($rows[0][0] ?? '')), 'total' => $total, 'roles' => $roles];
}

/**
 * Which step each role matches on its own: "Name (scope)" exactly, the way the
 * platform's own export labels a role, or else the bare name when exactly one
 * step carries it. Anything else is left for a person to pick.
 *
 * @return array role index => step id, 0 for no match
 */
function approved_auto_match(array $roles, array $steps): array {
    $full = [];
    $bare = [];
    foreach ($steps as $s) {
        $full[approved_key($s['name'] . ' (' . step_scope_tag($s) . ')')] = (int)$s['id'];
        $bare[approved_key($s['name'])][] = (int)$s['id'];
    }
    $out = [];
    foreach ($roles as $i => $role) {
        $k = approved_key($role['label']);
        $out[$i] = $full[$k] ?? ((count($bare[$k] ?? []) === 1) ? $bare[$k][0] : 0);
    }
    return $out;
}

/**
 * The role => step matches posted from the preview, trusted only as far as they
 * name a step of this project, and only for roles that carry days.
 */
function approved_posted_mapping(array $roles, $posted, array $steps): array {
    $valid = [];
    foreach ($steps as $s) $valid[(int)$s['id']] = true;
    $posted = is_array($posted) ? $posted : [];
    $out = [];
    foreach ($roles as $i => $role) {
        $sid = (int)($posted[$i] ?? 0);
        $out[$i] = ($role['rows'] > 0 && isset($valid[$sid])) ? $sid : 0;
    }
    return $out;
}

/**
 * The days per item that reproduce $days, as plain a number as does it.
 *
 * The sheet's days are rounded to two decimals on every row, so the exact quotient
 * carries that rounding: 5.58 over 5.577 is 1.0005, when the number behind it was
 * plainly 1. The fewest decimals landing within the sheet's own rounding, half a
 * hundredth per row, are taken, up to the three the column holds.
 */
function approved_days_per_unit(float $days, float $divisor, int $rows): float {
    $exact = $days / $divisor;
    $tolerance = 0.005 * max(1, $rows) + 1e-9;
    for ($d = 0; $d <= 3; $d++) {
        $c = round($exact, $d);
        if ($c > 0 && abs($c * $divisor - $days) <= $tolerance) return $c;
    }
    return max(0.001, round($exact, 3));
}

/**
 * What matching would set, for a given role => step mapping.
 *
 * Two roles pointed at one step add their days together: a sheet can split one
 * step across two names, and the step still has one days per item.
 *
 * @return array ['retake' => float, 'steps' => step id => ['step','days','rows','roles',
 *               'units','unit_label','sheet_units','was','new']]
 */
function approved_match_proposal(PDO $pdo, int $projectId, array $roles, array $mapping): array {
    $steps = [];
    foreach (pipeline_steps($pdo, $projectId) as $s) $steps[(int)$s['id']] = $s;
    $retake = project_retake_pct($pdo, $projectId);
    $factor = retake_factor($retake);
    $estimates = project_estimates($pdo, $projectId);

    $by = [];
    foreach ($roles as $i => $role) {
        $sid = (int)($mapping[$i] ?? 0);
        if ($sid <= 0 || !isset($steps[$sid]) || $role['rows'] === 0) continue;
        if (!isset($by[$sid])) {
            $by[$sid] = ['step' => $steps[$sid], 'days' => 0.0, 'rows' => 0, 'roles' => [],
                         'sheet_units' => null];
        }
        $by[$sid]['days'] += $role['days'];
        $by[$sid]['rows'] += $role['rows'];
        $by[$sid]['roles'][] = $role['label'];
        if ($by[$sid]['sheet_units'] === null) $by[$sid]['sheet_units'] = $role['units'];
    }

    foreach ($by as $sid => &$m) {
        $q = step_quota($pdo, $projectId, $m['step'], null, $estimates, $retake);
        $m['units'] = (float)($q['units'] ?? 0);
        $m['unit_label'] = (string)($q['unit_label'] ?? '');
        $m['was'] = effective_days_per_unit($pdo, $projectId, $m['step']);
        // Nothing to divide by: a step with no shots, assets or runtime yet has
        // no days per item that could produce these days.
        $m['new'] = $m['units'] > 0
            ? approved_days_per_unit($m['days'], $m['units'] * $factor, $m['rows'])
            : null;
    }
    unset($m);
    return ['retake' => $retake, 'steps' => $by];
}

/**
 * This project's rates per step, from its own expense projection, for comparing
 * with the sheet's. Empty when the finance database cannot be read: a comparison
 * that cannot be made is left out, never guessed.
 *
 * @return array ['by_step' => step id => ['day' => rate strings, 'unpriced' => int], 'total' => ?float]
 */
function approved_match_rates(PDO $pdo, int $projectId): array {
    try {
        $proj = expense_projection($pdo, $projectId);
    } catch (Throwable $e) {
        return ['by_step' => [], 'total' => null];
    }
    $out = [];
    foreach ($proj['rows'] as $r) {
        $sid = (int)($r['step']['id'] ?? 0);
        if (!isset($out[$sid])) $out[$sid] = ['day' => [], 'other' => false, 'unpriced' => 0];
        if ($r['rate'] === null) {
            $out[$sid]['unpriced']++;
        } elseif (($r['rate_unit'] ?? '') === 'day') {
            $out[$sid]['day'][fmt_num((float)$r['rate'], 2)] = true;
        } else {
            $out[$sid]['other'] = true;
        }
    }
    foreach ($out as &$o) $o['day'] = array_keys($o['day']);
    unset($o);
    return ['by_step' => $out, 'total' => (float)$proj['grand_total']];
}

/**
 * Write a proposal: each matched step's new days per item, with a note saying
 * which approved sheet it came from.
 *
 * @return array ['changed' => string[], 'same' => int, 'no_count' => string[]]
 */
function approved_match_apply(PDO $pdo, int $projectId, array $proposal, string $fileName,
                              string $who = ''): array {
    if (!ideals_per_project($pdo)) {
        throw new RuntimeException('Matching an approved sheet needs database update 034, which has not '
                                 . 'been applied yet, so nothing was changed.');
    }
    $all = pipeline_steps($pdo, $projectId);
    $note = mb_substr(sprintf('Matched to %s%s on %s', $fileName, $who !== '' ? ' by ' . $who : '',
                              date('Y-m-d')), 0, 120);
    $upd = $pdo->prepare(
        'UPDATE pipeline_steps SET days_per_unit = ?, days_source = ? WHERE id = ? AND project_id = ?'
    );

    $out = ['changed' => [], 'same' => 0, 'no_count' => []];
    $pdo->beginTransaction();
    try {
        foreach ($proposal['steps'] as $sid => $m) {
            $label = step_display_name($m['step'], $all);
            if ($m['new'] === null) {
                $out['no_count'][] = $label;
                continue;
            }
            if (abs($m['new'] - $m['was']) < 0.0005) {
                $out['same']++;
                continue;
            }
            $upd->execute([$m['new'], $note, (int)$sid, $projectId]);
            $out['changed'][] = sprintf('%s %s → %s', $label, fmt_num($m['was'], 3), fmt_num($m['new'], 3));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * The screen part, drawn on Dates & headcount.
 *
 * Until 2026-10-16 this lived on the project's Ideal quotas tab. That tab edited
 * the same days per item as Dates & headcount, stored once on the step, so David
 * removed it ("Remove it", 2026-10-16) and the match moved here, beside the
 * boxes it fills in.
 * ------------------------------------------------------------------------- */

/** The actions below, so the screen hosting them can tell they are ours. */
const IDEAL_MATCH_ACTIONS = ['match_preview', 'match_remap', 'match_apply', 'match_cancel'];

/**
 * Upload, preview again, apply or cancel. $self is the hosting screen's address,
 * which is returned (with the preview's anchor when one is open) as where to go
 * next. Throws with a message meant for the person on any refusal.
 */
function ideal_match_post(PDO $pdo, int $projectId, string $action, string $self): string {
    if (!is_admin()) {
        throw new RuntimeException('Days per item are set on the business side, so only an '
                                 . 'administrator can match them to an approved sheet.');
    }
    $perProject = ideals_per_project($pdo);
    $to = $self;
    if ($action === 'match_preview') {
        // MATCHING AN APPROVED EXPENSE PROJECTION, step one: read the sheet and
        // hold it for the preview. Nothing is written until match_apply. See
        // the top of this file.
        if (!$perProject) {
            throw new RuntimeException('Matching an approved sheet needs database update 034, which '
                                     . 'has not been applied yet, so nothing was read.');
        }
        $f = $_FILES['approved'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose the approved expense projection, an .xlsx file, to match.');
        }
        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('That is not an .xlsx file. If it is an older .xls, open it in '
                                     . 'Excel and save it as .xlsx first.');
        }
        $sheet = approved_projection_read((string)$f['tmp_name']);
        // Held in the session rather than re-uploaded: the preview may be
        // adjusted and previewed again before anything is applied, and the
        // file is gone the moment this request ends.
        $_SESSION['ideal_match'][$projectId] = [
            'file'    => basename((string)$f['name']),
            'at'      => time(),
            'title'   => $sheet['title'],
            'total'   => $sheet['total'],
            'roles'   => $sheet['roles'],
            'mapping' => approved_auto_match($sheet['roles'], pipeline_steps($pdo, $projectId)),
        ];
        $to = $self . '&match=1#match';

    } elseif ($action === 'match_remap' || $action === 'match_apply') {
        $held = $_SESSION['ideal_match'][$projectId] ?? null;
        if (!$held || time() - (int)$held['at'] > 3600) {
            unset($_SESSION['ideal_match'][$projectId]);
            throw new RuntimeException('That preview has expired, so nothing was changed. Upload the '
                                     . 'approved sheet again.');
        }
        $mapping = approved_posted_mapping($held['roles'], $_POST['map'] ?? [],
                                           pipeline_steps($pdo, $projectId));
        if ($action === 'match_remap') {
            $_SESSION['ideal_match'][$projectId]['mapping'] = $mapping;
            $to = $self . '&match=1#match';
        } else {
            $proposal = approved_match_proposal($pdo, $projectId, $held['roles'], $mapping);
            $r = approved_match_apply($pdo, $projectId, $proposal, (string)$held['file'],
                                      current_user_display());
            unset($_SESSION['ideal_match'][$projectId]);

            $n = count($r['changed']);
            if ($n > 0) {
                flash('ok', sprintf('Matched to %s: %d step%s changed, %s. No other project changed.',
                                    $held['file'], $n, $n === 1 ? '' : 's', ideal_list($r['changed'], 30)));
            } else {
                flash('ok', sprintf('Every matched step already had the days per item %s works out to, '
                                  . 'so nothing changed.', $held['file']));
            }
            if ($r['no_count']) {
                flash('warn', sprintf('Nothing on this project to count %s by yet (no shots, assets or '
                                    . 'runtime), so %s left as %s.', ideal_list($r['no_count']),
                                      count($r['no_count']) === 1 ? 'it was' : 'they were',
                                      count($r['no_count']) === 1 ? 'it was' : 'they were'));
            }
            $skipped = [];
            foreach ($held['roles'] as $i => $role) {
                if ($role['rows'] > 0 && (int)($mapping[$i] ?? 0) === 0) $skipped[] = $role['label'];
            }
            if ($skipped) {
                flash('warn', sprintf('Not matched to any step, so not applied: %s.', ideal_list($skipped)));
            }
            // The figure somebody will check first, said here rather than left
            // to be found on another tab.
            $now = approved_match_rates($pdo, $projectId)['total'];
            if ($now !== null && $held['total'] !== null) {
                $gap = $now - (float)$held['total'];
                if (abs($gap) < 0.005) {
                    flash('ok', sprintf('The expense projection now totals %s, the same as the approved sheet.',
                                        number_format($now, 2)));
                } else {
                    flash('warn', sprintf('The expense projection now totals %s and the approved sheet %s, '
                                        . '%s apart. The days per item match the sheet, so the gap is in '
                                        . 'rates or other expenses.', number_format($now, 2),
                                          number_format((float)$held['total'], 2),
                                          number_format(abs($gap), 2)));
                }
            }
        }
    } else {
        unset($_SESSION['ideal_match'][$projectId]);
    }
    return $to;
}

/**
 * The upload form, or the preview of a sheet already uploaded. Administrators
 * only, once update 034 is applied; draws nothing otherwise.
 */
function ideal_match_render(PDO $pdo, array $project, array $steps): void {
    $projectId = (int)$project['id'];
    $canEdit = ideals_per_project($pdo) && is_admin();
    if (!$canEdit || !$steps) return;
    ?>
  <?php
  // An approved expense projection being matched, held from the upload until it
  // is applied or cancelled. An hour is plenty to read a preview; past that the
  // project may have moved on under it.
  $held = $canEdit ? ($_SESSION['ideal_match'][$projectId] ?? null) : null;
  if ($held && time() - (int)$held['at'] > 3600) {
      unset($_SESSION['ideal_match'][$projectId]);
      $held = null;
  }
  $showMatch = $held !== null && isset($_GET['match']);
  ?>

  <?php if ($canEdit): ?>
    <?php if (!$showMatch): ?>
      <form method="post" enctype="multipart/form-data" class="inline-form toggle-form ideals-match">
        <input type="hidden" name="action" value="match_preview">
        <label class="inline">Match an approved expense projection
          <input type="file" name="approved" accept=".xlsx" required>
        </label>
        <button type="submit" class="secondary">Preview</button>
        <small>
          Upload the approved sheet, in the shape <em>Download as Excel</em> produces. Each role's
          days are worked back into days per item for its step, using this project's own counts and
          retake allowance, so the projection carries the approved days. Nothing changes until you
          have seen the result and confirmed it.
        </small>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($showMatch):
    $mapping  = $held['mapping'];
    $proposal = approved_match_proposal($pdo, $projectId, $held['roles'], $mapping);
    $rates    = approved_match_rates($pdo, $projectId);
    // Every step offered under the label the platform's own export gives it, which
    // is what a role in the sheet was written against.
    $stepOptions = [];
    foreach ($steps as $st) $stepOptions[(int)$st['id']] = $st['name'] . ' (' . step_scope_tag($st) . ')';
    $rolesByStep = [];
    foreach ($held['roles'] as $i => $role) {
        $sid = (int)($mapping[$i] ?? 0);
        if ($sid > 0) $rolesByStep[$sid][] = $role['label'];
    }
    $factorPct = fmt_num((float)$proposal['retake'], 2);
  ?>
    <section class="match-preview" id="match">
      <h3>Matching <?= e((string)$held['file']) ?></h3>
      <p class="muted small">
        <?= $held['title'] !== '' ? '<strong>' . e((string)$held['title']) . '</strong>. ' : '' ?>
        Days per item are worked back from each role's days with this project's own counts and its
        retake allowance of <strong><?= e($factorPct) ?>%</strong>. If the sheet was built with a
        different allowance, change it further down this screen first and preview again.
        <?php if ($held['total'] !== null): ?>
          Approved total in the sheet: <strong><?= e(number_format((float)$held['total'], 2)) ?></strong>.
        <?php endif; ?>
        <?php if ($rates['total'] !== null): ?>
          This project's expense projection today: <strong><?= e(number_format($rates['total'], 2)) ?></strong>.
        <?php endif; ?>
      </p>

      <form method="post">
        <?php // Enter previews again rather than applying: the first submit button
              // is the one a keypress triggers. ?>
        <button type="submit" name="action" value="match_remap" hidden></button>
        <table class="grid-table match-table">
          <colgroup>
            <col style="width:17%"> <?php // Approved role ?>
            <col style="width:8%">  <?php // Person-days ?>
            <col style="width:21%"> <?php // Step on this project ?>
            <col style="width:12%"> <?php // Counted here ?>
            <col style="width:9%">  <?php // Days per item now ?>
            <col style="width:9%">  <?php // Becomes ?>
            <col style="width:24%"> <?php // Notes ?>
          </colgroup>
          <thead>
            <tr>
              <th>Approved role</th><th class="num">Person-days</th><th>Step on this project</th>
              <th class="num">Counted here</th><th class="num">Days per item now</th>
              <th class="num">Becomes</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($held['roles'] as $i => $role):
            $sid = (int)($mapping[$i] ?? 0);
            $m = $sid > 0 ? ($proposal['steps'][$sid] ?? null) : null;
            $notes = [];
            if ($role['flat']) {
                $notes[] = 'A flat fee: its cost does not depend on days, so it is left alone.';
            } elseif ($role['rows'] === 0) {
                $notes[] = 'No days in the sheet. A fixed cost like this belongs in Other expenses, not in '
                         . 'days per item.';
            } elseif ($m === null) {
                $notes[] = 'No step matched. Pick one, or this role is skipped.';
            } else {
                if ($m['new'] === null) {
                    $notes[] = 'Nothing on this project to count it by yet, so it cannot be worked out.';
                }
                if ($m['sheet_units'] !== null && abs($m['sheet_units'] - $m['units']) >= 0.5) {
                    $notes[] = sprintf('The sheet counts %s, this project %s.', fmt_num($m['sheet_units'], 2),
                                       fmt_num($m['units'], 2));
                }
                if (count($rolesByStep[$sid] ?? []) > 1) {
                    $notes[] = 'Added together with ' . implode(', ', array_diff($rolesByStep[$sid], [$role['label']]))
                             . ', which match the same step.';
                }
                $here = $rates['by_step'][$sid] ?? null;
                if ($here !== null && $role['rates']) {
                    // Either way round: a sheet rate missing here, or a rate here
                    // the sheet never had, both mean the cost will not come out equal.
                    if ($here['day'] && (array_diff($role['rates'], $here['day'])
                                         || array_diff($here['day'], $role['rates']))) {
                        $notes[] = sprintf('Rate in the sheet %s a day, on this project %s a day.',
                                           implode(', ', $role['rates']), implode(', ', $here['day']));
                    }
                    if ($here['unpriced'] > 0) {
                        $notes[] = sprintf('%d position%s on this step %s no rate yet.', $here['unpriced'],
                                           $here['unpriced'] === 1 ? '' : 's', $here['unpriced'] === 1 ? 'has' : 'have');
                    }
                }
            }
            $problem = $role['rows'] > 0 && ($m === null || $m['new'] === null); ?>
            <tr class="<?= $problem ? 'has-problem' : '' ?>">
              <td>
                <?= e($role['label']) ?>
                <?php if ($role['rows'] > 1): ?>
                  <div class="muted small"><?= (int)$role['rows'] ?> artist rows</div>
                <?php endif; ?>
              </td>
              <td class="num"><?= $role['rows'] > 0 ? e(fmt_num($role['days'], 2)) : '<span class="muted">&mdash;</span>' ?></td>
              <td>
                <?php if ($role['rows'] > 0): ?>
                  <select name="map[<?= (int)$i ?>]" aria-label="Step for <?= e($role['label']) ?>">
                    <option value="0">not matched, skip</option>
                    <?php foreach ($stepOptions as $optId => $optLabel): ?>
                      <option value="<?= (int)$optId ?>" <?= $optId === $sid ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <span class="muted small">not days per item</span>
                <?php endif; ?>
              </td>
              <td class="num small">
                <?php if ($m !== null): ?>
                  <?= e(fmt_num($m['units'], 2)) ?> <span class="muted"><?= e($m['unit_label']) ?></span>
                <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
              </td>
              <td class="num"><?= $m !== null ? e(fmt_num($m['was'], 3)) : '<span class="muted">&mdash;</span>' ?></td>
              <td class="num">
                <?php if ($m !== null && $m['new'] !== null): ?>
                  <strong class="<?= abs($m['new'] - $m['was']) >= 0.0005 ? 'match-changes' : '' ?>"><?= e(fmt_num($m['new'], 3)) ?></strong>
                <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
              </td>
              <td class="small"><?= $notes ? e(implode(' ', $notes)) : '<span class="muted">&mdash;</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php
        // Steps no role in the sheet reached. Left exactly as they are, and named,
        // so a step the approved budget never costed is noticed rather than assumed.
        $untouched = [];
        foreach ($steps as $st) {
            if (!isset($proposal['steps'][(int)$st['id']])) $untouched[] = step_display_name($st, $steps);
        }
        $changing = count(array_filter($proposal['steps'],
                                       fn($x) => $x['new'] !== null && abs($x['new'] - $x['was']) >= 0.0005));
        ?>
        <?php if ($untouched): ?>
          <p class="muted small">
            Not in the approved sheet, so left as they are: <?= e(implode(', ', $untouched)) ?>.
          </p>
        <?php endif; ?>

        <div class="actions">
          <button type="submit" name="action" value="match_apply"
                  onclick="return confirm(<?= e(json_encode(sprintf("Set days per item on %d step%s of this project?\n\nIt changes this project's schedule and budget, and nothing on any other project.", $changing, $changing === 1 ? '' : 's'))) ?>);">Set these days per item</button>
          <button type="submit" name="action" value="match_remap" class="secondary">Preview again with these matches</button>
          <button type="submit" name="action" value="match_cancel" class="secondary" formnovalidate>Cancel</button>
        </div>
        <p class="muted small">
          Only days per item change. Rates, positions and other expenses stay as they are: the notes
          point out where they differ from the sheet. Changed a match above? The numbers are worked
          out again from it when you apply, or preview again first to see them.
        </p>
      </form>
    </section>
  <?php endif; ?>

    <?php
}
