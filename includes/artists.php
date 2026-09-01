<?php
// The studio's book of people, read and written across projects.
//
// `hires` is per-APPROACH: one row is one conversation about one slot, and a
// person put forward for three jobs is three rows. That duplication was accepted
// deliberately (DECISIONS, 2026-08-17) rather than normalised into a freelancers
// table, and everything here follows from it:
//
//   * READING collapses to one row per person, taking the newest, because that
//     is the version most recently checked by somebody.
//   * WRITING fans back out to every row bearing that name, because an email or
//     a rating is a fact about the PERSON — corrected in one place it must not
//     leave five projects showing the old value.
//
// Matching is on the LOWERCASED NAME, the same rule known_candidates(), the
// alias layer and the asset-type merge all use. Deliberately not fuzzy: a silent
// near-match is the thing this codebase keeps refusing to do.

require_once __DIR__ . '/hiring.php';

/** The person-level columns — everything that is true of them, not of one job. */
const ARTIST_FIELDS = [
    'candidate_name', 'email', 'phone', 'portfolio_url', 'portfolio_password', 'source', 'notes',
    'location', 'position', 'seniority', 'project_type', 'worked_with_us', 'rating',
];

/** What is emphatically NOT person-level, listed so the reason is on the record. */
// stage, lane_id, step_id, step_name, project_id — each belongs to one approach.
// Writing those across a person's rows would move them onto slots nobody put
// them up for, and mark them hired on shows that never hired them.

/** Seniority, as a fixed vocabulary rather than free text. */
function artists_seniority_options(): array {
    return ['Junior', 'Mid', 'Senior', 'Lead'];
}

function artists_seniority_select(string $name, ?string $value, ?string $id = null): string {
    $out = '<select name="' . e($name) . '"' . ($id ? ' id="' . e($id) . '"' : '') . '>';
    $out .= '<option value=""></option>';
    foreach (artists_seniority_options() as $o) {
        $sel = ($value !== null && strcasecmp($value, $o) === 0) ? ' selected' : '';
        $out .= '<option value="' . e($o) . '"' . $sel . '>' . e($o) . '</option>';
    }
    return $out . '</select>';
}

function artists_rating_select(string $name, $value, ?string $id = null): string {
    $out = '<select name="' . e($name) . '"' . ($id ? ' id="' . e($id) . '"' : '') . '>';
    $out .= '<option value="">—</option>';
    for ($i = 1; $i <= 5; $i++) {
        $sel = ($value !== null && $value !== '' && (int)$value === $i) ? ' selected' : '';
        $out .= '<option value="' . $i . '"' . $sel . '>' . str_repeat('★', $i) . '</option>';
    }
    return $out . '</select>';
}

/**
 * One row per person, plus every approach each of them has had.
 *
 * Returns [people, approaches] where approaches is keyed by lowercased name.
 */
/** Every position anyone has been filed under, for the filter. */
function artists_positions(PDO $pdo): array {
    if (!hiring_available($pdo) || !column_exists($pdo, 'hires', 'position')) return [];
    $out = [];
    foreach ($pdo->query("SELECT DISTINCT position FROM hires
                           WHERE position IS NOT NULL AND position <> ''
                           ORDER BY position") as $r) {
        $out[] = $r['position'];
    }
    return $out;
}

/**
 * $withApproaches costs a second query over every name on the page. The grid
 * stopped showing that column on 2026-08-31, and gathering three hundred and
 * seventy-seven people's job history to display none of it is a round trip for
 * nothing.
 */
function artists_list(PDO $pdo, string $q, array $extra, array $positions = [],
                      bool $withApproaches = false): array {
    if (!hiring_available($pdo)) return [[], []];

    $cols = 'h.id, h.candidate_name, h.email, h.phone, h.portfolio_url, h.source, h.notes,
             h.stage, h.project_id, h.step_name';
    foreach ($extra as $c) $cols .= ', h.`' . $c . '`';

    $sql = "SELECT {$cols}, p.code AS project_code, p.name AS project_name
              FROM hires h
              JOIN (SELECT MAX(id) AS id FROM hires GROUP BY LOWER(candidate_name)) latest
                   ON latest.id = h.id
              LEFT JOIN projects p ON p.id = h.project_id";
    $args  = [];
    $where = [];

    // SEVERAL positions, ORed together: "Layout or BG Layout or CH Layout" is one
    // question, and a studio's list separates those three by department rather
    // than by seniority. Exact matches, not LIKE — they are picks from a list of
    // what is actually in the database, and a LIKE would let "Layout" quietly
    // swallow the other two and make the count wrong.
    if ($positions && in_array('position', $extra, true)) {
        $marks = implode(',', array_fill(0, count($positions), '?'));
        $where[] = 'h.position IN (' . $marks . ')';
        foreach ($positions as $one) $args[] = $one;
    }

    if ($q !== '') {
        // The fields somebody actually remembers a person by. Bound, never
        // interpolated — the column list above is built from a fixed whitelist,
        // the search term never is.
        $like = '%' . $q . '%';
        $any  = ['h.candidate_name LIKE ?', 'h.email LIKE ?', 'h.source LIKE ?', 'h.notes LIKE ?'];
        $args = array_merge($args, [$like, $like, $like, $like]);
        foreach (['location', 'position'] as $c) {
            if (in_array($c, $extra, true)) { $any[] = 'h.`' . $c . '` LIKE ?'; $args[] = $like; }
        }
        // The search is an OR across fields, but it is ANDed with the position:
        // filtering to Layout and searching "brazil" means Layout artists in
        // Brazil, not everyone in Brazil.
        $where[] = '(' . implode(' OR ', $any) . ')';
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY h.candidate_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $people = $stmt->fetchAll();

    // Every approach for the people on screen, in ONE query. Two hundred artists
    // would otherwise be two hundred round trips to draw one column.
    $approaches = [];
    $names = $withApproaches
        ? array_map(function ($p) { return mb_strtolower(trim($p['candidate_name'])); }, $people)
        : [];
    if ($names) {
        $in = implode(',', array_fill(0, count($names), '?'));
        $ap = $pdo->prepare(
            "SELECT h.candidate_name, h.stage, h.step_name, h.project_id, p.code AS project_code
               FROM hires h
               LEFT JOIN projects p ON p.id = h.project_id
              WHERE LOWER(h.candidate_name) IN ({$in})
              ORDER BY h.id DESC"
        );
        $ap->execute($names);
        foreach ($ap as $r) $approaches[mb_strtolower(trim($r['candidate_name']))][] = $r;
    }
    return [$people, $approaches];
}

/** Normalise one posted artist into column => value, for the columns that exist. */
function artists_values(array $in, array $extra): array {
    $v = [
        'candidate_name' => mb_substr(trim((string)($in['candidate_name'] ?? '')), 0, 120),
        'email'          => mb_substr(trim((string)($in['email'] ?? '')), 0, 190) ?: null,
        'phone'          => mb_substr(trim((string)($in['phone'] ?? '')), 0, 50) ?: null,
        'portfolio_url'  => mb_substr(trim((string)($in['portfolio_url'] ?? '')), 0, 500) ?: null,
        'source'         => mb_substr(trim((string)($in['source'] ?? '')), 0, 100) ?: null,
        'notes'          => trim((string)($in['notes'] ?? '')) ?: null,
    ];
    // Arrives with migration 031, so probed like the 025 columns rather than
    // assumed — the screen works either way.
    if (in_array('portfolio_password', $extra, true)) {
        $v['portfolio_password'] = mb_substr(trim((string)($in['portfolio_password'] ?? '')), 0, 120) ?: null;
    }
    if (in_array('location', $extra, true)) {
        $v['location'] = mb_substr(trim((string)($in['location'] ?? '')), 0, 80) ?: null;
    }
    if (in_array('position', $extra, true)) {
        $v['position'] = mb_substr(trim((string)($in['position'] ?? '')), 0, 80) ?: null;
    }
    if (in_array('seniority', $extra, true)) {
        $s = trim((string)($in['seniority'] ?? ''));
        // Matched against the list rather than stored as typed, so "senior" and
        // "Senior" do not become two different seniorities.
        $v['seniority'] = null;
        foreach (artists_seniority_options() as $o) if (strcasecmp($s, $o) === 0) $v['seniority'] = $o;
    }
    if (in_array('project_type', $extra, true)) {
        $v['project_type'] = mb_substr(trim((string)($in['project_type'] ?? '')), 0, 20) ?: null;
    }
    if (in_array('worked_with_us', $extra, true)) {
        $v['worked_with_us'] = !empty($in['worked_with_us']) ? 1 : 0;
    }
    if (in_array('rating', $extra, true)) {
        $r = trim((string)($in['rating'] ?? ''));
        $v['rating'] = ($r === '') ? null : max(1, min(5, (int)$r));
    }
    return $v;
}

/**
 * Write one person's details across EVERY row that bears their name.
 *
 * Returns how many rows were touched, which is said out loud: "saved across 4
 * records" is the moment somebody learns this list is shared with the projects.
 *
 * Renaming carries the other rows with it, so fixing a typo keeps one person one
 * person. If the new name already belongs to somebody else the two merge — which
 * is the right outcome when a misspelling had split one artist in two, and is
 * why the count is reported rather than assumed.
 */
function artists_save_person(PDO $pdo, string $origName, array $in, array $extra): int {
    $v = artists_values($in, $extra);
    if ($v['candidate_name'] === '') throw new RuntimeException('An artist needs a name.');

    $set = [];
    $args = [];
    foreach ($v as $col => $val) { $set[] = '`' . $col . '` = ?'; $args[] = $val; }
    $args[] = mb_strtolower(trim($origName));

    $sql = 'UPDATE hires SET ' . implode(', ', $set) . ' WHERE LOWER(candidate_name) = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $stmt->rowCount();
}

/**
 * Add people who belong to no project yet.
 *
 * Needs migration 030 — hires.project_id is NOT NULL before it, and the caller
 * checks that rather than letting the insert fail with a database error nobody
 * can read.
 *
 * A row already in the book is SKIPPED and said so, never silently merged: the
 * existing record may hold notes and a rating this blank row would flatten.
 */
function artists_add_bulk(PDO $pdo, array $rows, array $extra): array {
    $out = ['added' => 0, 'skipped' => []];

    $known = [];
    foreach ($pdo->query('SELECT DISTINCT LOWER(candidate_name) AS n FROM hires') as $r) {
        $known[$r['n']] = true;
    }

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $v = artists_values($row, $extra);
        if ($v['candidate_name'] === '') continue;            // a blank row is a skipped row

        $key = mb_strtolower($v['candidate_name']);
        if (isset($known[$key])) {
            $out['skipped'][] = '"' . $v['candidate_name'] . '" is already in the list, so that '
                              . 'row was skipped. Open their record to change it.';
            continue;
        }

        $cols = array_keys($v);
        $sql  = 'INSERT INTO hires (project_id, stage, `' . implode('`, `', $cols) . '`) '
              . 'VALUES (NULL, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        // 'lead' is the stage that means "known to us, nothing happening yet",
        // which is exactly what an artist added here is.
        $pdo->prepare($sql)->execute(array_merge(['lead'], array_values($v)));
        $known[$key] = true;
        $out['added']++;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * IMPORTING THE STUDIO'S EXISTING SPREADSHEET.
 *
 * Mana_Freelancer_DataBase.xlsx, opened and read rather than assumed. Its
 * columns, in order:
 *
 *   A Artist   B Location   C Position   D Seniority   E Project type
 *   F Email    G Demo / portfolio link   H Password    I Worked with us?
 *   J Notes    K Rate (USD)              L Rating
 *
 * Three things about that file decided how this behaves:
 *
 *   * COLUMN H IS A PASSWORD, and eleven rows have one. It is not imported and
 *     never will be — a plain-text password column is not something this
 *     database should have (settled 2026-08-17 when the paste parser met the
 *     same column). Counted and reported, never dropped in silence.
 *   * COLUMN K IS A RATE, and rates do not live in this database. Seventeen
 *     rows have one; they go through includes/money.php onto the person's
 *     record like every other rate, and only an administrator can write them.
 *   * ELEVEN NAMES APPEAR TWICE inside the sheet itself. The second is skipped
 *     and named, because merging two rows for one person means choosing which
 *     notes to lose, and that is not a choice to make on somebody's behalf.
 * ------------------------------------------------------------------------- */

/** Column positions in that sheet, 0-based. Named so the mapping is readable. */
const FL_NAME = 0, FL_LOCATION = 1, FL_POSITION = 2, FL_SENIORITY = 3,
      FL_TYPE = 4, FL_EMAIL = 5, FL_REEL = 6, FL_PASSWORD = 7,
      FL_WORKED = 8, FL_NOTES = 9, FL_RATE = 10, FL_RATING = 11;

/**
 * Read that spreadsheet into the artist list.
 *
 * $overwrite decides what happens to somebody already known: false skips them
 * and says so, true updates their details across every record they have. The
 * default is to skip, because the app's copy may carry notes typed since.
 */
function artists_import_rows(PDO $pdo, array $rows, array $extra, bool $overwrite): array {
    $out = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'passwords' => 0,
            'rates' => [], 'dupes' => [], 'notes' => []];

    $known = [];
    foreach ($pdo->query('SELECT LOWER(candidate_name) AS n, MAX(id) AS id
                            FROM hires GROUP BY LOWER(candidate_name)') as $r) {
        $known[$r['n']] = (int)$r['id'];
    }
    $seen = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $get = function (int $i) use ($row) { return trim((string)($row[$i] ?? '')); };

        $name = $get(FL_NAME);
        if ($name === '') continue;                       // blank row, or spacing
        // The title row and the header row both land here; neither is a person.
        if (strcasecmp($name, 'Artist') === 0 || strcasecmp($name, 'Freelancers database') === 0) {
            continue;
        }

        $key = mb_strtolower($name);
        if (isset($seen[$key])) { $out['dupes'][] = $name; continue; }
        $seen[$key] = true;

        if ($get(FL_PASSWORD) !== '') $out['passwords']++;   // counted, and now kept

        $in = [
            'candidate_name' => $name,
            'location'       => $get(FL_LOCATION),
            'position'       => $get(FL_POSITION),
            'seniority'      => $get(FL_SENIORITY),
            'project_type'   => $get(FL_TYPE),
            'email'          => $get(FL_EMAIL),
            'portfolio_url'  => $get(FL_REEL),
            // Column H. Imported since 031 — see that migration for why the
            // earlier refusal was too broad.
            'portfolio_password' => $get(FL_PASSWORD),
            'notes'          => $get(FL_NOTES),
            'rating'         => $get(FL_RATING),
            // The sheet writes 1 for yes and leaves it blank for no.
            'worked_with_us' => $get(FL_WORKED) !== '' && $get(FL_WORKED) !== '0' ? 1 : 0,
            'source'         => 'Freelancer spreadsheet',
        ];

        if (isset($known[$key])) {
            if (!$overwrite) { $out['skipped']++; continue; }
            artists_save_person($pdo, $name, $in, $extra);
            $hireId = $known[$key];
            $out['updated']++;
        } else {
            $v = artists_values($in, $extra);
            $cols = array_keys($v);
            $sql  = 'INSERT INTO hires (project_id, stage, `' . implode('`, `', $cols) . '`) '
                  . 'VALUES (NULL, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $pdo->prepare($sql)->execute(array_merge(['lead'], array_values($v)));
            $hireId = (int)$pdo->lastInsertId();
            $known[$key] = $hireId;
            $out['added']++;
        }

        // Collected rather than written here: the rate belongs to the OTHER
        // database, and this function must not be the second place that opens
        // it. The caller passes them to money.php.
        $rate = $get(FL_RATE);
        if ($rate !== '' && $hireId > 0) $out['rates'][] = ['id' => $hireId, 'name' => $name, 'rate' => $rate];
    }
    return $out;
}
