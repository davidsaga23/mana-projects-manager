<?php
// THE OUTAGE BACKUP — Spell copied into a Google Sheet every hour, and the
// team's edits in that sheet brought back into Anima only when Production says so.
//
// David, 2026-11-19, the morning he could not open Anima (a stuck login, not an
// outage, but it made the point): "a spreadsheet, especially for Spell, updated
// hourly", so the studio has something to work from on a day Anima is down.
//
// ⚠ ONE WAY AUTOMATICALLY, THE OTHER WAY ONLY BY HAND. The design agreed that
// day, and the reason it is not a two-way sync:
//
//   - A spreadsheet is easy to damage without noticing: a sort that leaves one
//     column behind, a fill-down, a typo. Copied back automatically, any of
//     those silently rewrites real statuses on real shots.
//   - Edits made in both places in the same hour would overwrite each other,
//     and nobody would know which one was lost.
//   - When Anima is down its cron is down too, so an "automatic" sync would
//     stop exactly when it was needed.
//
// So: cron_sheet_backup.php writes a fresh copy every hour (Anima → Sheet).
// Bringing edits back (Sheet → Anima) is sheet_backup.php, a screen where
// Production sees each changed cell, sees where Anima ALSO changed since, ticks
// what to apply, and it is applied through the same functions Spell uses
// (includes/board_write.php), so history and bells behave normally.
//
// ⚠ THE HOURLY COPY NEVER OVERWRITES UNREVIEWED EDITS. Before rewriting a
// show's tab it reads it back and compares with what it last wrote (the
// snapshot, migration sheet_backup). If anybody changed a Status or Assignee
// there, that show's tab is left alone ("paused") and its second line says
// changes are waiting for review. Otherwise the day Anima came back, the first
// hourly run would wipe out the whole outage's work before anyone looked at it.
// Production un-pauses a show by finishing its review.
//
// ONE SPREADSHEET, ONE TAB PER SHOW, plus a "Read me" tab. One link for the
// whole studio to bookmark and one file to share; a show is a tab along the
// bottom, the way Spell's show chips sit along the top. One file per show would
// mean a new link to hand round every time a show starts.
//
// ⚠ THE SPREADSHEET IS MADE BY A PERSON, NOT BY ANIMA. A service account has no
// Drive storage of its own, so it cannot own a new file; it can only edit one a
// person made and shared. David makes the empty sheet once in the shared folder
// (docs/SHEET_BACKUP.md) and Anima fills it.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/board.php';          // board_data(), board_assignees(), board_projects(): what Spell shows
require_once __DIR__ . '/google_sheets.php';

/* ---------------------------------------------------------------------------
 * The layout of a show's tab. Rows 1 and 2 are the title and the "Last
 * updated" line, row 3 the headings, and the tasks from row 4 down.
 * ------------------------------------------------------------------------ */

/** The headings, in column order. Read-only unless named in SHEET_BACKUP_EDITABLE. */
const SHEET_BACKUP_HEADINGS = [
    'Anima ID',        // A  how a row finds its task again after a sort. Never edit.
    'Phase',           // B  Pre-production / Production / Post-production
    'Group',           // C  the episode, or the asset type
    'Shot / asset',    // D
    'Shot status',     // E  worked out from its tasks, as Spell shows it
    'Department',      // F
    'Status',          // G  ✎ editable
    'Assignee',        // H  ✎ editable
    'Latest version',  // I
    'Due',             // J
    'Frames',          // K
    'Notes',           // L
    'Link',            // M
];
const SHEET_BACKUP_COL_STATUS   = 6;
const SHEET_BACKUP_COL_ASSIGNEE = 7;
const SHEET_BACKUP_FIRST_ROW    = 3;   // 0-based index of row 4, the first task
const SHEET_BACKUP_README       = 'Read me';

/** Is the snapshot table there yet (the migration)? */
function sheet_backup_available(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM sheet_backup_tabs LIMIT 1');
        $pdo->query('SELECT 1 FROM sheet_backup_runs LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Every show's backup state, keyed by project id. */
function sheet_backup_states(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT * FROM sheet_backup_tabs') as $r) $out[(int)$r['project_id']] = $r;
    return $out;
}

/** The most recent runs, newest first. */
function sheet_backup_recent_runs(PDO $pdo, int $n = 5): array {
    return $pdo->query('SELECT * FROM sheet_backup_runs ORDER BY id DESC LIMIT ' . max(1, $n))->fetchAll();
}

/** "19 Nov 2026, 2:05 pm PST", the clock everybody at Mana reads. */
function sheet_backup_when(?string $at = null): string {
    $t = $at !== null ? strtotime($at) : time();
    return date('j M Y, g:i a T', $t ?: time());
}

/** A tab name Sheets accepts, and that cannot be mistaken for a formula. */
function sheet_backup_tab_title(array $project): string {
    $t = trim((string)($project['code'] ?: $project['name']));
    $t = trim(preg_replace('/[\[\]\*\?\/\\\\:]+/', ' ', $t));
    if ($t === '' || strcasecmp($t, SHEET_BACKUP_README) === 0) $t = 'Show ' . (int)$project['id'];
    return mb_substr($t, 0, 90);
}

/** Text compared the way a person reads it: case and extra spaces do not count. */
function sheet_backup_norm(?string $s): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
}

/**
 * One show as rows for its tab, read through board_data() — the very function
 * Spell draws from — so the sheet shows what Spell shows and nothing else.
 *
 * @return array [
 *   'rows'      => list of 13-cell rows (strings; Frames an int or ''),
 *   'snap'      => [anima id => ['item','dept','s','sid','a','cid']], what was written,
 *   'statuses'  => the show's status names, for the Status dropdown,
 *   'people'    => names a task can be given to, for the Assignee dropdown,
 * ]
 */
function sheet_backup_show_rows(PDO $pdo, int $projectId): array {
    $board = board_data($pdo, $projectId);
    $names = [];
    foreach (board_assignees($pdo, $projectId) as $c) $names[(int)$c['id']] = (string)$c['name'];

    $rows = [];
    $snap = [];
    $one = function (array $g, array $item, array $t, string $itemName, string $shotStatus)
               use (&$rows, &$snap, $names) {
        $key = $t['item_key'] . '|' . (int)$t['step_id'];
        $person = $t['crew_id'] !== null ? ($names[$t['crew_id']] ?? (string)($t['person'] ?? '')) : '';
        $v = $t['latest'] ?? null;
        $version = $v ? version_label((int)$v['version_no']) . ' · ' . (string)$v['original_name']
                      : (string)($t['version'] ?? '');
        $status = (string)($t['status_name'] ?? '');
        $rows[] = [
            $key,
            (string)$g['name'],
            (string)($g['detail'] ?? ''),
            $itemName,
            $shotStatus,
            (string)$t['step'],
            $status,
            $person,
            $version,
            (string)($t['due'] ?? ''),
            $item['frames'] !== null ? (int)$item['frames'] : '',
            (string)($t['notes'] ?? ''),
            (string)($t['link'] ?? ''),
        ];
        $snap[$key] = [
            'item' => $itemName,
            'dept' => (string)$t['step'],
            's'    => $status,
            'sid'  => $t['status_id'],
            'a'    => $person,
            'cid'  => $t['crew_id'],
        ];
    };

    foreach ($board['groups'] as $g) {
        foreach ($g['items'] as $item) {
            if ($item['self']) {
                // A whole-show deliverable IS its own task (Animatic, Color Script).
                $one($g, $item, $item['self'], (string)$item['name'], '');
                continue;
            }
            foreach ($item['tasks'] as $t) {
                $one($g, $item, $t, (string)$item['name'], (string)$item['status']['name']);
            }
        }
    }

    $people = array_values(array_unique(array_values($names)));
    return [
        'rows'     => $rows,
        'snap'     => $snap,
        'statuses' => array_values(array_map(fn($s) => (string)$s['name'], $board['statuses'])),
        'people'   => array_slice($people, 0, 450),   // Sheets caps a dropdown at 500
    ];
}

/**
 * What people changed in a show's tab since Anima last wrote it: only the
 * Status and Assignee columns, matched by the Anima ID in column A so a sorted
 * or filtered sheet still lines up. Pure: no database, no Google.
 *
 * @param array $snap   the snapshot stored when the tab was written
 * @param array $values the tab as read back (rows of strings), from A1
 * @return array [
 *   'edits'   => list of ['key','field' ('s'|'a'),'old','new','row' (sheet row number)],
 *   'twice'   => anima ids found on more than one row with different values,
 *   'missing' => how many of the tasks written are no longer in the sheet,
 *   'header'  => false when the heading row could not be found at all,
 * ]
 */
function sheet_backup_sheet_edits(array $snap, array $values): array {
    $head = null;
    foreach ($values as $i => $row) {
        if (sheet_backup_norm($row[0] ?? '') === sheet_backup_norm(SHEET_BACKUP_HEADINGS[0])) { $head = $i; break; }
    }
    if ($head === null) return ['edits' => [], 'twice' => [], 'missing' => count($snap), 'header' => false];

    // Read the two editable columns by their HEADINGS, so a column somebody
    // dragged elsewhere is still found. Fall back to where Anima put them.
    $colS = SHEET_BACKUP_COL_STATUS;
    $colA = SHEET_BACKUP_COL_ASSIGNEE;
    foreach ($values[$head] as $c => $h) {
        if (sheet_backup_norm($h) === 'status') $colS = $c;
        if (sheet_backup_norm($h) === 'assignee') $colA = $c;
    }

    $seen = [];
    $edits = [];
    $twice = [];
    for ($i = $head + 1, $n = count($values); $i < $n; $i++) {
        $row = $values[$i];
        $key = trim((string)($row[0] ?? ''));
        if ($key === '' || !isset($snap[$key])) continue;   // a row Anima did not write: not ours to read
        $s = trim((string)($row[$colS] ?? ''));
        $a = trim((string)($row[$colA] ?? ''));
        if (isset($seen[$key])) {
            // A row copied and pasted. If the copies disagree, nobody can say
            // which one meant it, so neither is offered.
            if (sheet_backup_norm($seen[$key]['s']) !== sheet_backup_norm($s)
                || sheet_backup_norm($seen[$key]['a']) !== sheet_backup_norm($a)) {
                $twice[$key] = true;
            }
            continue;
        }
        $seen[$key] = ['s' => $s, 'a' => $a];
        $was = $snap[$key];
        if (sheet_backup_norm($s) !== sheet_backup_norm($was['s'])) {
            $edits[] = ['key' => $key, 'field' => 's', 'old' => (string)$was['s'], 'new' => $s, 'row' => $i + 1];
        }
        if (sheet_backup_norm($a) !== sheet_backup_norm($was['a'])) {
            $edits[] = ['key' => $key, 'field' => 'a', 'old' => (string)$was['a'], 'new' => $a, 'row' => $i + 1];
        }
    }
    $edits = array_values(array_filter($edits, fn($e) => !isset($twice[$e['key']])));
    return [
        'edits'   => $edits,
        'twice'   => array_keys($twice),
        'missing' => count(array_diff_key($snap, $seen)),
        'header'  => true,
    ];
}

/* ---------------------------------------------------------------------------
 * Anima → Sheet: the hourly copy.
 * ------------------------------------------------------------------------ */

/** A Sheets colour from 0–255 values. */
function sheet_backup_rgb(int $r, int $g, int $b): array {
    return ['red' => $r / 255, 'green' => $g / 255, 'blue' => $b / 255];
}

/** A rectangle of a tab, 0-based, end exclusive, as the Sheets API wants it. */
function sheet_backup_grid(int $tabId, int $r0, int $r1, int $c0, int $c1): array {
    return ['sheetId' => $tabId, 'startRowIndex' => $r0, 'endRowIndex' => $r1,
            'startColumnIndex' => $c0, 'endColumnIndex' => $c1];
}

/** One sheet row of plain cells (text, or a number where one is given). */
function sheet_backup_cells(array $row): array {
    return ['values' => array_map(function ($v) {
        return ['userEnteredValue' => is_int($v) ? ['numberValue' => $v] : ['stringValue' => (string)$v]];
    }, $row)];
}

/**
 * Everything that rewrites one show's tab, as one batch: clear it, write the
 * rows, grey the read-only columns, put dropdowns on Status and Assignee, and
 * warn anybody who types in a grey column.
 */
function sheet_backup_tab_requests(int $tabId, array $project, array $built, array $tabInfo): array {
    $rows = $built['rows'];
    $n = count($rows);
    $end = SHEET_BACKUP_FIRST_ROW + max($n, 1);    // one past the last task row
    $cols = count(SHEET_BACKUP_HEADINGS);
    $grey = sheet_backup_rgb(217, 217, 217);
    $paleGrey = sheet_backup_rgb(243, 243, 243);
    $editHead = sheet_backup_rgb(255, 242, 179);
    $readOnly = 'Anima writes this column every hour. Only Status and Assignee are read back.';

    $req = [];
    // Room for every row. Only ever grown: shrinking would fight a filter
    // somebody left on, and the rows below the data are cleared anyway.
    $want = max($end + 20, (int)($tabInfo['rows'] ?? 0));
    $req[] = ['updateSheetProperties' => [
        'properties' => ['sheetId' => $tabId, 'gridProperties' => [
            'rowCount' => $want, 'frozenRowCount' => 3, 'frozenColumnCount' => 4,
        ]],
        'fields' => 'gridProperties(rowCount,frozenRowCount,frozenColumnCount)',
    ]];
    foreach ($tabInfo['protected'] ?? [] as $pid) {
        $req[] = ['deleteProtectedRange' => ['protectedRangeId' => $pid]];
    }
    $req[] = ['updateCells' => [
        'range'  => ['sheetId' => $tabId],
        'fields' => 'userEnteredValue,userEnteredFormat,dataValidation,note',
    ]];

    $grid = [
        sheet_backup_cells([$project['name'] . ' — Anima backup copy']),
        sheet_backup_cells(['Last updated ' . sheet_backup_when() . ' from Anima. Change only the yellow '
            . 'Status and Assignee columns. Your changes reach Anima only when Production reviews them '
            . '(Anima → menu → Sheet backup). See the Read me tab.']),
        sheet_backup_cells(SHEET_BACKUP_HEADINGS),
    ];
    foreach ($rows as $r) $grid[] = sheet_backup_cells($r);
    if (!$rows) $grid[] = sheet_backup_cells(['', 'Nothing on Spell for this show yet.']);
    $req[] = ['updateCells' => [
        'start'  => ['sheetId' => $tabId, 'rowIndex' => 0, 'columnIndex' => 0],
        'rows'   => $grid,
        'fields' => 'userEnteredValue',
    ]];

    $req[] = ['repeatCell' => [
        'range'  => sheet_backup_grid($tabId, 0, 1, 0, 1),
        'cell'   => ['userEnteredFormat' => ['textFormat' => ['bold' => true, 'fontSize' => 14]]],
        'fields' => 'userEnteredFormat.textFormat',
    ]];
    $req[] = ['repeatCell' => [
        'range'  => sheet_backup_grid($tabId, 2, 3, 0, $cols),
        'cell'   => ['userEnteredFormat' => ['backgroundColor' => $grey, 'textFormat' => ['bold' => true]]],
        'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
    ]];
    $req[] = ['repeatCell' => [
        'range'  => sheet_backup_grid($tabId, 2, 3, SHEET_BACKUP_COL_STATUS, SHEET_BACKUP_COL_ASSIGNEE + 1),
        'cell'   => ['userEnteredFormat' => ['backgroundColor' => $editHead, 'textFormat' => ['bold' => true]]],
        'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
    ]];
    foreach ([[0, SHEET_BACKUP_COL_STATUS], [SHEET_BACKUP_COL_ASSIGNEE + 1, $cols]] as [$c0, $c1]) {
        $req[] = ['repeatCell' => [
            'range'  => sheet_backup_grid($tabId, SHEET_BACKUP_FIRST_ROW, $end, $c0, $c1),
            'cell'   => ['userEnteredFormat' => ['backgroundColor' => $paleGrey]],
            'fields' => 'userEnteredFormat.backgroundColor',
        ]];
        // A WARNING, not a lock: whoever types in a grey cell is asked "are you
        // sure?". A hard lock would need every editor named on it, and the
        // point of the sheet is that anybody at Mana can work from it.
        $req[] = ['addProtectedRange' => ['protectedRange' => [
            'range' => sheet_backup_grid($tabId, SHEET_BACKUP_FIRST_ROW, $end, $c0, $c1),
            'description' => $readOnly, 'warningOnly' => true,
        ]]];
    }
    $req[] = ['addProtectedRange' => ['protectedRange' => [
        'range' => sheet_backup_grid($tabId, 0, 3, 0, $cols),
        'description' => 'The title and headings. Anima finds the rows by them.', 'warningOnly' => true,
    ]]];

    if ($rows) {
        foreach ([[SHEET_BACKUP_COL_STATUS, $built['statuses'], 'Pick one of this show\'s statuses. Leave empty for Not started.'],
                  [SHEET_BACKUP_COL_ASSIGNEE, $built['people'], 'Pick a person. Leave empty for nobody.']] as [$col, $list, $help]) {
            if (!$list) continue;
            $req[] = ['setDataValidation' => [
                'range' => sheet_backup_grid($tabId, SHEET_BACKUP_FIRST_ROW, $end, $col, $col + 1),
                'rule'  => [
                    'condition' => ['type' => 'ONE_OF_LIST',
                                    'values' => array_map(fn($v) => ['userEnteredValue' => $v], $list)],
                    'inputMessage' => $help,
                    'strict' => true,
                    'showCustomUi' => true,
                ],
            ]];
        }
        $req[] = ['setBasicFilter' => ['filter' => ['range' => sheet_backup_grid($tabId, 2, $end, 0, $cols)]]];
    }

    $widths = [80, 115, 110, 160, 105, 120, 150, 160, 170, 90, 65, 260, 200];
    foreach ($widths as $i => $px) {
        $req[] = ['updateDimensionProperties' => [
            'range' => ['sheetId' => $tabId, 'dimension' => 'COLUMNS', 'startIndex' => $i, 'endIndex' => $i + 1],
            'properties' => ['pixelSize' => $px],
            'fields' => 'pixelSize',
        ]];
    }
    return $req;
}

/** The Read me tab, rewritten every run so it always says the current rules. */
function sheet_backup_readme_requests(int $tabId, array $tabInfo): array {
    $lines = [
        ['Anima backup copy — read me first'],
        [''],
        ['What this is', 'A copy of Spell, written by Anima every hour. One tab per show, one row per task (a department\'s work on a shot or asset).'],
        ['When to use it', 'Only when Anima is down. While Anima works, work in Anima: this copy is overwritten every hour.'],
        ['What you can change', 'Only the yellow columns: Status and Assignee. Pick from the dropdown. Leave Status empty for Not started.'],
        ['Everything else', 'Grey columns are read-only. Google warns you if you type in one; anything typed there is ignored.'],
        ['Sorting and filtering', 'Fine. Anima finds each row by its Anima ID in column A, so never edit or delete that column.'],
        ['New rows', 'Ignored. A new shot or task has to be added in Anima once it is back.'],
        ['When Anima is back', 'Production opens Anima → menu → Sheet backup, checks every change made here, and ticks the ones to apply. Nothing comes back by itself.'],
        ['Until then', 'A tab with changes waiting is not refreshed, so nothing typed here is lost. Its second line says so.'],
        [''],
        ['Last written', sheet_backup_when()],
    ];
    $req = [];
    $req[] = ['updateSheetProperties' => [
        'properties' => ['sheetId' => $tabId, 'index' => 0],
        'fields' => 'index',
    ]];
    foreach ($tabInfo['protected'] ?? [] as $pid) {
        $req[] = ['deleteProtectedRange' => ['protectedRangeId' => $pid]];
    }
    $req[] = ['updateCells' => ['range' => ['sheetId' => $tabId], 'fields' => 'userEnteredValue,userEnteredFormat']];
    $req[] = ['updateCells' => [
        'start'  => ['sheetId' => $tabId, 'rowIndex' => 0, 'columnIndex' => 0],
        'rows'   => array_map('sheet_backup_cells', $lines),
        'fields' => 'userEnteredValue',
    ]];
    $req[] = ['repeatCell' => [
        'range'  => sheet_backup_grid($tabId, 0, 1, 0, 1),
        'cell'   => ['userEnteredFormat' => ['textFormat' => ['bold' => true, 'fontSize' => 14]]],
        'fields' => 'userEnteredFormat.textFormat',
    ]];
    $req[] = ['repeatCell' => [
        'range'  => sheet_backup_grid($tabId, 2, count($lines), 0, 1),
        'cell'   => ['userEnteredFormat' => ['textFormat' => ['bold' => true]]],
        'fields' => 'userEnteredFormat.textFormat',
    ]];
    $req[] = ['repeatCell' => [
        'range'  => sheet_backup_grid($tabId, 0, count($lines), 1, 2),
        'cell'   => ['userEnteredFormat' => ['wrapStrategy' => 'WRAP']],
        'fields' => 'userEnteredFormat.wrapStrategy',
    ]];
    foreach ([[0, 170], [1, 640]] as [$i, $px]) {
        $req[] = ['updateDimensionProperties' => [
            'range' => ['sheetId' => $tabId, 'dimension' => 'COLUMNS', 'startIndex' => $i, 'endIndex' => $i + 1],
            'properties' => ['pixelSize' => $px],
            'fields' => 'pixelSize',
        ]];
    }
    return $req;
}

/** Add a tab and hand back its id. */
function sheet_backup_add_tab(string $spreadsheetId, string $title): int {
    $res = gsheet_batch_update($spreadsheetId, [['addSheet' => ['properties' => ['title' => $title]]]]);
    $id = $res['replies'][0]['addSheet']['properties']['sheetId'] ?? null;
    if ($id === null) throw new RuntimeException('Google did not say what the new tab "' . $title . '" is called.');
    return (int)$id;
}

/** Which tab is this show's: the one it wrote last time, else one with its name. */
function sheet_backup_find_tab(array $tabs, ?array $state, string $title): ?int {
    if ($state && $state['tab_id'] !== null && isset($tabs[(int)$state['tab_id']])) return (int)$state['tab_id'];
    foreach ($tabs as $id => $t) if (strcasecmp($t['title'], $title) === 0) return $id;
    return null;
}

/**
 * The hourly copy. Writes every show Spell offers into its own tab, except a
 * show whose tab has edits nobody has reviewed yet (see the top of this file).
 *
 * @param array $opt  'dry' => true    read everything, write nothing
 *                    'only' => [ids]  just these shows
 *                    'force' => true  overwrite even unreviewed edits (only
 *                                     ever from the review screen's Finish)
 *                    'via' => 'cron' | 'screen'
 * @return array ['ok' => bool, 'status' => sentence, 'lines' => [sentences]]
 */
function sheet_backup_run(PDO $pdo, array $opt = []): array {
    $lines = [];
    $dry = !empty($opt['dry']);
    if (($problem = gsheet_setup_problem()) !== null) {
        return ['ok' => false, 'status' => $problem, 'lines' => []];
    }
    if (!sheet_backup_available($pdo)) {
        return ['ok' => false, 'status' => 'The Google Sheet backup needs its database update first (Migrations).',
                'lines' => []];
    }

    // ONE RUN AT A TIME: the cron job and a "copy now" pressed on the screen in
    // the same minute would otherwise both rewrite the same tab.
    $got = (int)$pdo->query("SELECT GET_LOCK('anima_sheet_backup', 0)")->fetchColumn();
    if ($got !== 1) return ['ok' => true, 'status' => 'A copy is already running; this one stood aside.', 'lines' => []];

    $runId = 0;
    if (!$dry) {
        $pdo->prepare('INSERT INTO sheet_backup_runs (started_at, via) VALUES (NOW(), ?)')
            ->execute([substr((string)($opt['via'] ?? 'cron'), 0, 10)]);
        $runId = (int)$pdo->lastInsertId();
    }

    $ok = true;
    $written = 0;
    $paused = 0;
    try {
        $sheetId = gsheet_backup_id();
        $tabs = gsheet_tabs($sheetId);
        $states = sheet_backup_states($pdo);

        $projects = board_projects($pdo);
        if (!empty($opt['only'])) {
            $only = array_flip(array_map('intval', $opt['only']));
            $projects = array_values(array_filter($projects, fn($p) => isset($only[(int)$p['id']])));
        }

        // The Read me tab, first along the bottom.
        if (!$dry && empty($opt['only'])) {
            $readme = null;
            foreach ($tabs as $id => $t) if (strcasecmp($t['title'], SHEET_BACKUP_README) === 0) $readme = $id;
            if ($readme === null) {
                $readme = sheet_backup_add_tab($sheetId, SHEET_BACKUP_README);
                $tabs[$readme] = ['title' => SHEET_BACKUP_README, 'rows' => 1000, 'protected' => []];
            }
            gsheet_batch_update($sheetId, sheet_backup_readme_requests($readme, $tabs[$readme]));
        }

        // Each show's title, unique within the spreadsheet.
        $plan = [];
        $titles = [];
        foreach ($projects as $p) {
            $t = sheet_backup_tab_title($p);
            if (isset($titles[strtolower($t)])) $t = mb_substr($t, 0, 80) . ' (' . (int)$p['id'] . ')';
            $titles[strtolower($t)] = true;
            $plan[(int)$p['id']] = ['project' => $p, 'title' => $t,
                                    'tab' => sheet_backup_find_tab($tabs, $states[(int)$p['id']] ?? null, $t)];
        }

        // ⚠ READ BACK BEFORE WRITING, all the shows in one call. A tab whose
        // Status or Assignee differs from what Anima last wrote has edits
        // nobody has reviewed, and is left exactly as it is.
        $toRead = [];
        foreach ($plan as $pid => $p) {
            if ($p['tab'] !== null && !empty($states[$pid]['snapshot']) && empty($opt['force'])) {
                $toRead[$pid] = gsheet_range($tabs[$p['tab']]['title'], 'A1:M');
            }
        }
        $read = $toRead ? array_combine(array_keys($toRead), gsheet_read($sheetId, array_values($toRead))) : [];

        foreach ($plan as $pid => $p) {
            $name = $p['project']['code'] ?: $p['project']['name'];
            $state = $states[$pid] ?? null;
            try {
                if (isset($read[$pid])) {
                    $snap = json_decode((string)$state['snapshot'], true)['rows'] ?? [];
                    $diff = sheet_backup_sheet_edits($snap, $read[$pid]);
                    // ⚠ A TAB WHOSE HEADINGS ARE GONE IS LEFT ALONE TOO. Its rows can
                    // no longer be matched, so "no edits found" would be a guess,
                    // and rewriting it could wipe out a day's work. The review
                    // screen says why, and its Finish button rewrites it.
                    if ($diff['edits'] || $diff['twice'] || !$diff['header']) {
                        $count = max(1, count($diff['edits']) + count($diff['twice']));
                        $paused++;
                        $lines[] = $name . ': ' . $count . ' change' . ($count === 1 ? '' : 's')
                                 . ' in the sheet waiting for review, so this tab was left alone.';
                        if (!$dry) {
                            gsheet_batch_update($sheetId, [['updateCells' => [
                                'start'  => ['sheetId' => $p['tab'], 'rowIndex' => 1, 'columnIndex' => 0],
                                'rows'   => [sheet_backup_cells(['⚠ Not refreshed since '
                                    . sheet_backup_when($state['written_at']) . ': ' . $count . ' change'
                                    . ($count === 1 ? '' : 's') . ' made here '
                                    . ($count === 1 ? 'is' : 'are') . ' waiting for Production to review in Anima '
                                    . '(menu → Sheet backup). Keep working here; nothing is lost.'])],
                                'fields' => 'userEnteredValue',
                            ]]]);
                            $pdo->prepare('UPDATE sheet_backup_tabs SET paused_at = COALESCE(paused_at, NOW()),
                                                  paused_edits = ?, checked_at = NOW() WHERE project_id = ?')
                                ->execute([$count, $pid]);
                        }
                        continue;
                    }
                }

                $built = sheet_backup_show_rows($pdo, $pid);
                if ($dry) {
                    $lines[] = $name . ': would write ' . count($built['rows']) . ' rows'
                             . ($p['tab'] === null ? ' into a new tab "' . $p['title'] . '"' : '') . '.';
                    continue;
                }
                $tab = $p['tab'];
                if ($tab === null) {
                    $tab = sheet_backup_add_tab($sheetId, $p['title']);
                    $tabs[$tab] = ['title' => $p['title'], 'rows' => 1000, 'protected' => []];
                }
                gsheet_batch_update($sheetId, sheet_backup_tab_requests($tab, $p['project'], $built, $tabs[$tab]));

                // The snapshot: what the sheet says as of now, so the next run
                // and the review screen can tell what PEOPLE changed.
                $pdo->prepare(
                    'INSERT INTO sheet_backup_tabs (project_id, tab_id, tab_title, written_at, checked_at,
                                                    snapshot, paused_at, paused_edits)
                     VALUES (?, ?, ?, NOW(), NOW(), ?, NULL, 0)
                     ON DUPLICATE KEY UPDATE tab_id = VALUES(tab_id), tab_title = VALUES(tab_title),
                        written_at = NOW(), checked_at = NOW(), snapshot = VALUES(snapshot),
                        paused_at = NULL, paused_edits = 0'
                )->execute([$pid, $tab, $tabs[$tab]['title'],
                            json_encode(['v' => 1, 'rows' => $built['snap']], JSON_UNESCAPED_UNICODE)]);
                $written++;
            } catch (Throwable $e) {
                $ok = false;
                $lines[] = $name . ': ' . $e->getMessage();
            }
        }
        $status = $dry
            ? 'Dry run: nothing was written.'
            : ('Copied ' . $written . ' show' . ($written === 1 ? '' : 's') . ' to the sheet'
               . ($paused ? ', ' . $paused . ' left alone with changes waiting for review' : '') . '.');
    } catch (Throwable $e) {
        $ok = false;
        $status = 'The copy to Google Sheets failed: ' . $e->getMessage();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('anima_sheet_backup')");
    }

    if ($runId) {
        $pdo->prepare('UPDATE sheet_backup_runs SET finished_at = NOW(), ok = ?, written = ?, paused = ?, note = ?
                        WHERE id = ?')
            ->execute([$ok ? 1 : 0, $written, $paused,
                       mb_substr($status . ($lines && !$ok ? ' ' . implode(' ', $lines) : ''), 0, 255), $runId]);
    }
    return ['ok' => $ok, 'status' => $status, 'lines' => $lines];
}

/* ---------------------------------------------------------------------------
 * Sheet → Anima: the review.
 * ------------------------------------------------------------------------ */

/**
 * Every change people made in one show's tab, each one checked against Anima
 * as it stands NOW. Reads the sheet; writes nothing.
 *
 * Each change carries:
 *   id        "<anima id>|s" or "|a", what the screen's checkbox posts
 *   item, dept, field ('Status' | 'Assignee'), old (what Anima wrote), new (what the sheet says)
 *   to        the id the new value means (status or crew), or null for "nobody / not started"
 *   now       what Anima says today, in words
 *   conflict  true when Anima ALSO changed this since the copy was written
 *   already   true when Anima already says what the sheet says (nothing to do)
 *   problem   why it cannot be applied (unknown status, unknown person…), or null
 *
 * @return array ['changes' => [...], 'twice' => [...], 'missing' => int,
 *                'written_at' => ?string, 'error' => ?string]
 */
function sheet_backup_review(PDO $pdo, int $projectId): array {
    $out = ['changes' => [], 'twice' => [], 'missing' => 0, 'written_at' => null, 'error' => null];
    $state = sheet_backup_states($pdo)[$projectId] ?? null;
    if (!$state || empty($state['snapshot'])) {
        $out['error'] = 'Anima has not written this show to the sheet yet, so there is nothing to compare.';
        return $out;
    }
    $out['written_at'] = $state['written_at'];
    $snap = json_decode((string)$state['snapshot'], true)['rows'] ?? [];

    $sheetId = gsheet_backup_id();
    $tabs = gsheet_tabs($sheetId);
    $tab = sheet_backup_find_tab($tabs, $state, (string)$state['tab_title']);
    if ($tab === null) {
        $out['error'] = 'The show\'s tab ("' . $state['tab_title'] . '") is no longer in the sheet. '
                      . 'If it was deleted, there are no edits to bring back.';
        return $out;
    }
    $values = gsheet_read($sheetId, [gsheet_range($tabs[$tab]['title'], 'A1:M')])[0] ?? [];
    $diff = sheet_backup_sheet_edits($snap, $values);
    if (!$diff['header']) {
        $out['error'] = 'The headings row (starting "Anima ID") is gone from the show\'s tab, so its rows '
                      . 'cannot be matched to tasks. Put it back (Edit → Undo, or File → Version history).';
        return $out;
    }
    $out['twice'] = array_map(fn($k) => ($snap[$k]['item'] ?? $k) . ' ' . ($snap[$k]['dept'] ?? ''), $diff['twice']);
    $out['missing'] = $diff['missing'];

    // Anima as it stands now, read the way Spell reads it.
    $now = sheet_backup_show_rows($pdo, $projectId)['snap'];
    $statusByName = [];
    foreach (statuses_by_id($pdo, $projectId) as $id => $s) {
        $statusByName[sheet_backup_norm($s['name'])][] = (int)$id;
    }
    $crewByName = [];
    foreach (board_assignees($pdo, $projectId) as $c) {
        $crewByName[sheet_backup_norm($c['name'])][] = (int)$c['id'];
    }

    foreach ($diff['edits'] as $ed) {
        $key = $ed['key'];
        $was = $snap[$key];
        $cur = $now[$key] ?? null;
        $isStatus = $ed['field'] === 's';
        $c = [
            'id'       => $key . '|' . $ed['field'],
            'key'      => $key,
            'item'     => (string)$was['item'],
            'dept'     => (string)$was['dept'],
            'field'    => $isStatus ? 'Status' : 'Assignee',
            'old'      => $ed['old'],
            'new'      => $ed['new'],
            'row'      => $ed['row'],
            'to'       => null,
            'now'      => $cur ? (string)$cur[$isStatus ? 's' : 'a'] : '',
            'conflict' => false,
            'already'  => false,
            'problem'  => null,
        ];

        // What the new words mean.
        $norm = sheet_backup_norm($ed['new']);
        if ($norm === '') {
            $c['to'] = null;
        } elseif ($isStatus) {
            $ids = $statusByName[$norm] ?? [];
            if (!$ids && $norm === 'not started') {
                $c['to'] = null;                      // what Spell calls an empty status
            } elseif (!$ids) {
                $c['problem'] = '"' . $ed['new'] . '" is not one of this show\'s statuses.';
            } else {
                $c['to'] = $ids[0];
            }
        } else {
            $ids = $crewByName[$norm] ?? [];
            if (!$ids) $c['problem'] = 'Nobody called "' . $ed['new'] . '" can be given a task on this show.';
            elseif (count($ids) > 1) $c['problem'] = 'More than one person is called "' . $ed['new'] . '". Set it on Spell.';
            else $c['to'] = $ids[0];
        }

        if ($cur === null) {
            $c['problem'] = 'This task is no longer on Spell (its department or item was removed).';
        } else {
            $snapId = $was[$isStatus ? 'sid' : 'cid'];
            $curId  = $cur[$isStatus ? 'sid' : 'cid'];
            $c['conflict'] = ($snapId === null ? null : (int)$snapId) !== ($curId === null ? null : (int)$curId);
            if ($c['problem'] === null) {
                $c['already'] = ($curId === null ? null : (int)$curId) === $c['to'];
            }
        }
        $out['changes'][] = $c;
    }
    return $out;
}

/**
 * Apply the ticked changes, re-reading the sheet first so what is applied is
 * what the sheet says NOW (not what the page showed a while ago). Each goes
 * through board_set_status() / board_set_crew(), Spell's own save.
 *
 * @param string[] $ticked change ids from the screen
 * @return array ['applied' => [sentences], 'skipped' => [sentences]]
 */
function sheet_backup_apply(PDO $pdo, int $projectId, array $ticked): array {
    require_once __DIR__ . '/board_write.php';
    $want = array_flip(array_map('strval', $ticked));
    $review = sheet_backup_review($pdo, $projectId);
    if ($review['error'] !== null) throw new RuntimeException($review['error']);

    $applied = [];
    $skipped = [];
    $found = [];
    foreach ($review['changes'] as $c) {
        if (!isset($want[$c['id']])) continue;
        $found[$c['id']] = true;
        $what = $c['item'] . ' ' . $c['dept'] . ' ' . $c['field'];
        if ($c['problem'] !== null) { $skipped[] = $what . ': ' . $c['problem']; continue; }
        if ($c['already']) { $skipped[] = $what . ': Anima already says this.'; continue; }
        [$itemKey, $stepId] = explode('|', $c['key']);
        try {
            $ctx = board_cell_context($pdo, $projectId, (int)$stepId, $itemKey);
            if ($c['field'] === 'Status') {
                board_set_status($pdo, $ctx, $c['to'], 'sheet');
            } else {
                board_set_crew($pdo, $ctx, $c['to']);
            }
            $applied[] = $what . ': ' . ($c['old'] !== '' ? $c['old'] : '(empty)') . ' → '
                       . ($c['new'] !== '' ? $c['new'] : '(empty)');
        } catch (Throwable $e) {
            $skipped[] = $what . ': ' . $e->getMessage();
        }
    }
    $gone = count(array_diff_key($want, $found));
    if ($gone) {
        $skipped[] = $gone . ' ticked change' . ($gone === 1 ? ' is' : 's are')
                   . ' no longer in the sheet (somebody changed it back since), so ' . ($gone === 1 ? 'it was' : 'they were') . ' left.';
    }
    return ['applied' => $applied, 'skipped' => $skipped];
}

/**
 * Finish a show's review: note who reviewed it, and rewrite its tab from Anima
 * now, so it is current again and the hourly copy carries on. Changes still in
 * the sheet that were not applied are overwritten, which the screen says
 * before the button is pressed.
 */
function sheet_backup_finish(PDO $pdo, int $projectId, string $who): array {
    $pdo->prepare('UPDATE sheet_backup_tabs SET reviewed_at = NOW(), reviewed_by = ? WHERE project_id = ?')
        ->execute([mb_substr($who, 0, 100), $projectId]);
    return sheet_backup_run($pdo, ['only' => [$projectId], 'force' => true, 'via' => 'screen']);
}
