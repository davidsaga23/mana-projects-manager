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
