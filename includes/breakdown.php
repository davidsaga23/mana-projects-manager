<?php
// The shot breakdown — what appears in each shot.
//
// David, 2026-09-02: "let's work on the shot-asset link, we also need to define
// the frame count per shot... once everything's linked, we can export as an
// excel file and it should look something similar to the STFC2 dashboard and
// include vehicles, props, graphics, or any other asset needed."
//
// ⚠ THIS IS NOT ONLY A CONVENIENCE. `shot_assets` has existed since v1 and
// includes/scheduler.php has always READ it — scheduler_shot_assets() — but
// nothing in the codebase ever wrote a row. The scheduler resolves what a shot
// waits for in three steps, most specific first:
//
//   1. the same item in the step it waits for;
//   2. for a shot waiting on an ASSET step, the assets that shot actually uses;
//   3. failing that, the whole step.
//
// With the table empty, step 2 never fired and every shot fell through to step
// 3 — so every shot waited for the LAST asset in the show to be finished before
// its animation could start, including shots with no characters in them. Always
// in the pessimistic direction, which reads as "we need more people" rather than
// as missing data. Filling this in should SHORTEN the critical path.
//
// ⚠ AND SAVED SCHEDULES WILL NOT IMPROVE ON THEIR OWN. They are stored
// snapshots. Re-run the schedule after breaking shots down, or the plan on
// screen is still the pessimistic one.
//
// ONE JOIN TABLE, NOT ONE PER KIND. STFC had shot_characters and
// shot_environments and needed a schema change when Props arrived. Here the
// columns are generated from whatever asset types the project actually has, so
// "vehicles, props, graphics, or any other asset" needs no code and no
// migration — which is exactly what the v1 decision was bought for.
//
// IDS, NOT NAMES. STFC stored shot_characters.char_name and inherited the alias
// problem the whole platform exists to avoid: "RomulanOfficer01" and "Romulan
// Officer" become two different characters. Here it is asset_id.

require_once __DIR__ . '/functions.php';

/**
 * READING ORDER, not alphabetical (David, 2026-09-22: "let's also change the
 * order of the columns to EP, Shot, Frames, Character, Environment, BG, Prop,
 * FX, Notes").
 *
 * A breakdown is read the way a shot is built: who is in it, where it happens,
 * what is behind them, what they hold, what happens on top. Alphabetical put BG
 * in front of Character and FX between Environment and Prop, which is nobody's
 * order.
 *
 * Only a RANKING, not a list of allowed columns. The screen is still generated
 * from whatever types the project has — a new one called Vehicle needs no code
 * and appears immediately, alphabetically after the five named here. Add it
 * below only to move it up. The names are matched loosely (case, spaces and a
 * trailing "s" are ignored) so Props, props and Prop are one column's worth of
 * ordering, and the common spellings of the same thing sit together.
 */
const BREAKDOWN_TYPE_ORDER = [
    'character' => 1, 'char' => 1, 'cast' => 1,
    'environment' => 2, 'env' => 2, 'location' => 2, 'set' => 2,
    'bg' => 3, 'background' => 3,
    'prop' => 4,
    'fx' => 5, 'vfx' => 5, 'effect' => 5,
];

/** The key a type name is ranked under: lower case, no spaces, no trailing s. */
function breakdown_type_key(string $type): string {
    $k = mb_strtolower(trim($type));
    $k = str_replace([' ', '-', '_'], '', $k);
    if (mb_strlen($k) > 1 && mb_substr($k, -1) === 's') $k = mb_substr($k, 0, -1);
    return $k;
}

/**
 * The asset types this project actually uses, in a stable order.
 *
 * Stable is the point: the same on every load, the same in the export, and the
 * same across shows. Ordering by how many of each type exist would reshuffle
 * the sheet every time somebody added an asset.
 *
 * An asset with no type set gets its own column at the END rather than being
 * dropped: it is real work that somebody has not filed yet, and a breakdown that
 * silently omits assets is worse than an untidy one.
 */
function breakdown_asset_types(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT COALESCE(NULLIF(TRIM(asset_type), ''), '') AS t
           FROM assets WHERE project_id = ? ORDER BY t"
    );
    $stmt->execute([$projectId]);
    $types = [];
    $untyped = false;
    foreach ($stmt as $r) {
        if ($r['t'] === '') { $untyped = true; continue; }
        $types[] = (string)$r['t'];
    }

    // Ranked ones first in house order, then everything else alphabetically —
    // which is the order the query already returned them in, so usort's
    // tie-break on the name keeps it. 99 rather than PHP_INT_MAX so an unranked
    // type never collides with a rank if the list above ever grows.
    usort($types, function ($a, $b) {
        $ra = BREAKDOWN_TYPE_ORDER[breakdown_type_key($a)] ?? 99;
        $rb = BREAKDOWN_TYPE_ORDER[breakdown_type_key($b)] ?? 99;
        if ($ra !== $rb) return $ra <=> $rb;
        return strcasecmp($a, $b);
    });

    if ($untyped) $types[] = '';
    return $types;
}

/** Every asset on the project, grouped by type — the pickers' contents. */
function breakdown_assets(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        "SELECT id, name, COALESCE(NULLIF(TRIM(asset_type), ''), '') AS t
           FROM assets WHERE project_id = ? ORDER BY t, name"
    );
    $stmt->execute([$projectId]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(string)$r['t']][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
    }
    return $out;
}

/**
 * Has migration 042 been applied?
 *
 * Probed rather than assumed, like every other optional column in this codebase:
 * a screen that offers N/A against a database without the table is a save that
 * fails for no visible reason, and the whole breakdown would go down with it.
 * Without the table every screen here simply behaves as it did before 042 — the
 * cells stay empty, the warning keeps counting them, and nothing breaks.
 *
 * column_exists() returns false when the TABLE is missing (it catches the
 * PDOException from SHOW COLUMNS), so asking it about any column is a valid
 * table probe. Cached for the request, as it is.
 */
function breakdown_na_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'shot_type_na', 'shot_id');
    return $ok;
}

/**
 * The cells somebody has marked "there is nothing of this kind in this shot".
 *
 * Scoped by PROJECT through the join back to shots, for the same reason
 * breakdown_rows() does it: shot_type_na carries no project_id of its own, and
 * the join is the only thing keeping another show's rows out.
 *
 * @return array shot id => [asset type => true]
 */
function breakdown_na(PDO $pdo, int $projectId): array {
    if (!breakdown_na_available($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT n.shot_id, n.asset_type
           FROM shot_type_na n
           JOIN shots s ON s.id = n.shot_id
          WHERE s.project_id = ?'
    );
    $stmt->execute([$projectId]);
    $out = [];
    foreach ($stmt as $r) $out[(int)$r['shot_id']][(string)$r['asset_type']] = true;
    return $out;
}

/** The episodes with shots in them, for the filter. Empty string means none. */
function breakdown_episodes(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT episode FROM shots WHERE project_id = ? ORDER BY episode'
    );
    $stmt->execute([$projectId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The grid: one row per shot, carrying the assets it uses grouped by type.
 *
 * Two queries rather than one join, so a shot with no assets still comes back —
 * which is the whole point of the screen. A LEFT JOIN would do it too, but then
 * every shot row repeats once per asset and has to be folded back up in PHP
 * anyway.
 *
 * @param string $episode '' for all of them
 */
function breakdown_rows(PDO $pdo, int $projectId, string $episode = ''): array {
    $sql  = 'SELECT id, episode, shot_code, frames, placeholder_seconds, notes
               FROM shots WHERE project_id = ?';
    $args = [$projectId];
    if ($episode !== '') {
        $sql .= ' AND episode = ?';
        $args[] = $episode;
    }
    $sql .= ' ORDER BY episode, shot_code';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    // 'seconds' is the breakdown's footage, frames ÷ fps, and never the setup
    // seconds (placeholder_seconds) that the money reads: see
    // breakdown_set_frames() for why the two are kept apart.
    $fq = $pdo->prepare('SELECT fps FROM projects WHERE id = ?');
    $fq->execute([$projectId]);
    $fps = (int)$fq->fetchColumn();
    if ($fps <= 0) $fps = 24;

    $rows = [];
    foreach ($stmt as $r) {
        $rows[(int)$r['id']] = [
            'id'        => (int)$r['id'],
            'episode'   => (string)$r['episode'],
            'shot_code' => (string)$r['shot_code'],
            'frames'    => $r['frames'] !== null ? (int)$r['frames'] : null,
            'seconds'   => $r['frames'] !== null ? round((int)$r['frames'] / $fps, 3) : null,
            'notes'     => (string)($r['notes'] ?? ''),
            'assets'    => [],
            // Asset types this shot has been marked as needing NONE of. Kept
            // apart from 'assets' because they are the opposite fact, not an
            // empty version of the same one.
            'na'        => [],
        ];
    }
    if (!$rows) return [];

    // Scoped by PROJECT, not by the shot ids just fetched. The join back to
    // shots is what keeps another show's rows out even though shot_assets
    // carries no project_id of its own.
    $stmt = $pdo->prepare(
        "SELECT sa.shot_id, a.id, a.name, COALESCE(NULLIF(TRIM(a.asset_type), ''), '') AS t
           FROM shot_assets sa
           JOIN shots  s ON s.id = sa.shot_id
           JOIN assets a ON a.id = sa.asset_id
          WHERE s.project_id = ?
          ORDER BY t, a.name"
    );
    $stmt->execute([$projectId]);
    foreach ($stmt as $r) {
        $sid = (int)$r['shot_id'];
        if (!isset($rows[$sid])) continue;          // filtered out by episode
        $rows[$sid]['assets'][(string)$r['t']][] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
    }

    // One more query rather than a third join, so a shot with no assets and no
    // N/A marks still comes back — the same reason the assets are fetched
    // separately above.
    foreach (breakdown_na($pdo, $projectId) as $sid => $types) {
        if (!isset($rows[$sid])) continue;       // filtered out by episode
        $rows[$sid]['na'] = $types;
    }
    return array_values($rows);
}

/**
 * Both ends belong to this project, or nothing happens.
 *
 * shot_assets has foreign keys to shots and assets but carries no project_id, so
 * the database alone would happily accept a shot from one show linked to an
 * asset from another. Checked here because here is the only place that can.
 *
 * @throws RuntimeException when either id is not this project's
 */
function breakdown_check_pair(PDO $pdo, int $projectId, int $shotId, int $assetId): array {
    $stmt = $pdo->prepare('SELECT episode, shot_code FROM shots WHERE id = ? AND project_id = ?');
    $stmt->execute([$shotId, $projectId]);
    $shot = $stmt->fetch();
    if (!$shot) throw new RuntimeException('That shot is not on this project.');

    $stmt = $pdo->prepare('SELECT name, asset_type FROM assets WHERE id = ? AND project_id = ?');
    $stmt->execute([$assetId, $projectId]);
    $asset = $stmt->fetch();
    if (!$asset) throw new RuntimeException('That asset is not on this project.');

    return [$shot, $asset];
}

/** Put an asset in a shot. Re-adding is not an error — it is already true. */
function breakdown_link(PDO $pdo, int $projectId, int $shotId, int $assetId): string {
    [$shot, $asset] = breakdown_check_pair($pdo, $projectId, $shotId, $assetId);
    // INSERT IGNORE against the (shot_id, asset_id) primary key: two people
    // adding the same character to the same shot is a race that should end with
    // the character in the shot, not with an error on somebody's screen.
    $pdo->prepare('INSERT IGNORE INTO shot_assets (shot_id, asset_id) VALUES (?, ?)')
        ->execute([$shotId, $assetId]);

    // ⚠ PUTTING SOMETHING IN THE CELL CLEARS ITS N/A, because the two cannot
    // both be true: "this shot needs no characters" and "this shot needs Quark"
    // is a contradiction, and leaving both rows there would let the scheduler
    // and the grid disagree about the same cell.
    //
    // Done here rather than refused, because the producer's intent is not in
    // doubt — they ticked an asset. The picker disables its N/A row while chips
    // exist, so the only way to arrive here is from a cell that was marked N/A
    // and has just been corrected.
    //
    // The type is normalised exactly as breakdown_assets() normalises it, or a
    // cell headed " Character " would never match the row that empties it.
    if (breakdown_na_available($pdo)) {
        $pdo->prepare('DELETE FROM shot_type_na WHERE shot_id = ? AND asset_type = ?')
            ->execute([$shotId, trim((string)($asset['asset_type'] ?? ''))]);
    }
    return $asset['name'] . ' is in ' . $shot['shot_code'] . '.';
}

/** Take an asset out of a shot. */
function breakdown_unlink(PDO $pdo, int $projectId, int $shotId, int $assetId): string {
    [$shot, $asset] = breakdown_check_pair($pdo, $projectId, $shotId, $assetId);
    $pdo->prepare('DELETE FROM shot_assets WHERE shot_id = ? AND asset_id = ?')
        ->execute([$shotId, $assetId]);
    return $asset['name'] . ' removed from ' . $shot['shot_code'] . '.';
}

/**
 * Mark a cell as "nothing of this kind in this shot", or take the mark off.
 *
 * David, 2026-10-08: "let's add an N/A option for all the projects because it
 * might be that some of them we don't need anything."
 *
 * PER CELL — one shot, one asset type. SH160 needing no Character says nothing
 * about its Environment, and the scheduler can only use the finer fact.
 *
 * ⚠ THIS CHANGES THE SCHEDULE, which is the point. A shot with nothing linked
 * waits for the WHOLE asset step (see scheduler_item_earliest, rule 3), so a
 * shot with no characters has always waited for the last character in the show.
 * Marking the cell N/A is what lets the scheduler stop waiting.
 *
 * REFUSED WHILE THE CELL HAS ASSETS IN IT, rather than silently emptying it.
 * Removing somebody's breakdown work to honour a tick is the kind of quiet loss
 * that is only noticed when the export is already with the client.
 *
 * @param string $type '' is the "No type set" column, and is a real value
 * @throws RuntimeException when the shot is not this project's, or the cell is
 *                          not empty, or migration 042 has not been applied
 */
function breakdown_set_na(PDO $pdo, int $projectId, int $shotId, string $type, bool $on): string {
    if (!breakdown_na_available($pdo)) {
        throw new RuntimeException(
            'This database has not had migration 042 applied yet, so "N/A" cannot be saved. '
          . 'An administrator can apply it on the migrate.php screen.'
        );
    }

    $stmt = $pdo->prepare('SELECT shot_code FROM shots WHERE id = ? AND project_id = ?');
    $stmt->execute([$shotId, $projectId]);
    $shot = $stmt->fetch();
    if (!$shot) throw new RuntimeException('That shot is not on this project.');

    $type  = trim($type);
    $label = $type !== '' ? $type : 'untyped asset';
    $code  = (string)$shot['shot_code'];

    if (!$on) {
        $pdo->prepare('DELETE FROM shot_type_na WHERE shot_id = ? AND asset_type = ?')
            ->execute([$shotId, $type]);
        return $code . ' needs ' . $label . ' work decided again.';
    }

    // Normalised the same way breakdown_assets() groups the columns, so the
    // count below is of exactly the chips the cell is showing.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM shot_assets sa
           JOIN assets a ON a.id = sa.asset_id
          WHERE sa.shot_id = ? AND COALESCE(NULLIF(TRIM(a.asset_type), ''), '') = ?"
    );
    $stmt->execute([$shotId, $type]);
    $n = (int)$stmt->fetchColumn();
    if ($n > 0) {
        throw new RuntimeException(
            $code . ' already lists ' . $n . ' ' . $label . ($n === 1 ? '' : 's')
          . ', so it cannot also need none. Take ' . ($n === 1 ? 'it' : 'them')
          . ' out first if that is wrong.'
        );
    }

    // INSERT IGNORE against the (shot_id, asset_type) primary key: two people
    // marking the same cell is a race that should end with the cell marked.
    $pdo->prepare('INSERT IGNORE INTO shot_type_na (shot_id, asset_type) VALUES (?, ?)')
        ->execute([$shotId, $type]);
    return $code . ' needs no ' . $label . '.';
}

/**
 * Set a shot's frame count. THE BREAKDOWN'S OWN NUMBER, AND NOTHING ELSE'S.
 *
 * Until 2026-10-27 this also wrote placeholder_seconds (frames ÷ fps), so typing
 * a frame count here rewrote the seconds on shot setup, and with them the
 * expense projection, every per-second quota and the artist statements. David,
 * the same day: "Setup frame count should be used for expense projections,
 * frame counts in the breakdown should only apply to the breakdown." He had
 * found the setup total moving under him from a screen he thought was only a
 * breakdown, with no way to tell which shots had moved.
 *
 * So the two are separate now:
 *   shots.placeholder_seconds  set on shot setup, read by the money and quotas
 *   shots.frames               set here, read by the breakdown, its export,
 *                              Spell and the kick-off sheet
 * The seconds this returns are frames ÷ fps for the breakdown to show, worked
 * out, never stored.
 *
 * @return array ['frames' => ?int, 'seconds' => ?float]
 */
function breakdown_set_frames(PDO $pdo, array $project, int $shotId, string $raw): array {
    $projectId = (int)$project['id'];
    $stmt = $pdo->prepare('SELECT id FROM shots WHERE id = ? AND project_id = ?');
    $stmt->execute([$shotId, $projectId]);
    if (!$stmt->fetch()) throw new RuntimeException('That shot is not on this project.');

    $raw = trim($raw);
    if ($raw === '') {
        $pdo->prepare('UPDATE shots SET frames = NULL WHERE id = ?')->execute([$shotId]);
        return ['frames' => null, 'seconds' => null];
    }

    if (!preg_match('/^[0-9]+$/', $raw)) {
        throw new RuntimeException('"' . $raw . '" is not a whole number of frames.');
    }
    $frames = (int)$raw;
    if ($frames > 9999999) throw new RuntimeException('That is more frames than a shot can hold.');

    $fps = (int)($project['fps'] ?? 24);
    if ($fps <= 0) $fps = 24;
    $seconds = round($frames / $fps, 3);

    $pdo->prepare('UPDATE shots SET frames = ? WHERE id = ?')->execute([$frames, $shotId]);
    return ['frames' => $frames, 'seconds' => $seconds];
}

/**
 * A shot's own note, typed on the breakdown.
 *
 * David, 2026-09-22: "how can I edit these notes?" You could not. A shot's note
 * arrived with an import and stayed whatever the spreadsheet said: this screen
 * printed it, the setup screen has no field for it, and the item page shows it
 * read only. An asset's note has been editable since the wizard was built, which
 * is what made the gap hard to see.
 *
 * Empty clears it rather than storing a blank, so the column reads as a dash
 * again. Cut at 2,000 characters, and the cut is REPORTED: the column holds far
 * more, but a note that long is a document, and a silent truncation is the kind
 * of loss nobody notices until the note is needed.
 */
function breakdown_set_note(PDO $pdo, array $project, int $shotId, string $raw): array {
    $projectId = (int)$project['id'];
    $stmt = $pdo->prepare('SELECT id FROM shots WHERE id = ? AND project_id = ?');
    $stmt->execute([$shotId, $projectId]);
    if (!$stmt->fetch()) throw new RuntimeException('That shot is not on this project.');

    $note = trim($raw);
    $cut  = mb_strlen($note) > 2000;
    if ($cut) $note = mb_substr($note, 0, 2000);

    $pdo->prepare('UPDATE shots SET notes = ? WHERE id = ?')
        ->execute([$note === '' ? null : $note, $shotId]);
    return ['notes' => $note, 'cut' => $cut];
}

/**
 * How much of the show has been broken down, for the line above the grid.
 *
 * Counts shots NOBODY HAS SAID ANYTHING ABOUT against the total. Said plainly
 * because the scheduler's behaviour depends on it: a shot with nothing linked is
 * a shot that still waits for every asset in the show.
 *
 * ⚠ A SHOT COUNTS AS SPOKEN FOR ON ONE LINK *OR* ONE N/A MARK, not on having
 * every column filled. That is deliberately the same loose rule the count has
 * always used — one chip anywhere has always been enough — and 042 only adds
 * the other way of saying something. A shot marked "no Character" with its
 * Environment still blank is therefore counted as done, exactly as a shot with
 * one character and no environment already was.
 *
 * The strict version ("every column has either assets or N/A") is a better
 * number and a bigger change: it would move this figure on every project the day
 * it shipped, with no way for a producer to tell whether the work or the rule
 * had changed. Worth doing on purpose, not as a side effect of this.
 */
function breakdown_progress(PDO $pdo, int $projectId): array {
    $total = (int)$pdo->query(
        'SELECT COUNT(*) FROM shots WHERE project_id = ' . $projectId
    )->fetchColumn();

    // EXISTS rather than COUNT(DISTINCT) now that there are two ways to be
    // spoken for: counting distinct ids across two tables needs a UNION, while
    // the shot row itself only has to answer yes or no once.
    $naClause = breakdown_na_available($pdo)
        ? ' OR EXISTS (SELECT 1 FROM shot_type_na n WHERE n.shot_id = s.id)'
        : '';
    $done = (int)$pdo->query(
        'SELECT COUNT(*) FROM shots s
          WHERE s.project_id = ' . $projectId . '
            AND (EXISTS (SELECT 1 FROM shot_assets sa WHERE sa.shot_id = s.id)' . $naClause . ')'
    )->fetchColumn();

    $links = (int)$pdo->query(
        'SELECT COUNT(*)
           FROM shot_assets sa JOIN shots s ON s.id = sa.shot_id
          WHERE s.project_id = ' . $projectId
    )->fetchColumn();

    // Counted separately so the line above the grid can say "and 6 cells marked
    // N/A" rather than quietly folding them into the assets actually placed.
    $na = breakdown_na_available($pdo) ? (int)$pdo->query(
        'SELECT COUNT(*)
           FROM shot_type_na n JOIN shots s ON s.id = n.shot_id
          WHERE s.project_id = ' . $projectId
    )->fetchColumn() : 0;

    return ['total' => $total, 'done' => $done, 'empty' => max(0, $total - $done),
            'links' => $links, 'na' => $na];
}

/**
 * Which assets appear in which shots — the breakdown read the other way round.
 *
 * "This prop is in two shots" is the difference between building it properly and
 * not building it at all, and it is the same rows the grid draws.
 *
 * @return array asset id => ['name', 'type', 'shots' => [shot codes]]
 */
function breakdown_by_asset(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.name, COALESCE(NULLIF(TRIM(a.asset_type), ''), '') AS t,
                s.episode, s.shot_code
           FROM assets a
           LEFT JOIN shot_assets sa ON sa.asset_id = a.id
           LEFT JOIN shots s        ON s.id = sa.shot_id AND s.project_id = a.project_id
          WHERE a.project_id = ?
          ORDER BY t, a.name, s.episode, s.shot_code"
    );
    $stmt->execute([$projectId]);
    $out = [];
    foreach ($stmt as $r) {
        $id = (int)$r['id'];
        if (!isset($out[$id])) {
            $out[$id] = ['name' => (string)$r['name'], 'type' => (string)$r['t'], 'shots' => []];
        }
        if ($r['shot_code'] !== null) {
            $out[$id]['shots'][] = trim(($r['episode'] ? $r['episode'] . ' ' : '') . $r['shot_code']);
        }
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * A picture per shot (migration 109).
 *
 * David, 2026-11-25, on this screen: "let's add a way to manually add a
 * thumbnail per shot. When the first task version is available, it should
 * override the original thumbnail and always use the latest version of the
 * latest department. We want an easy way to visualize in the breakdown (and
 * open a preview) of the shot so we can easily identify it."
 * ------------------------------------------------------------------------ */

/** Has migration 109 run? Before it, nobody is offered a picture upload. */
function shot_thumbs_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = column_exists($pdo, 'shot_thumbnails', 'stored_name');
    return $ok;
}

/**
 * The picture each shot shows, keyed by shot id.
 *
 * ⚠ THE VERSIONS WIN OVER THE HAND-ADDED PICTURE, as soon as there is one. Of
 * every Department that has put a picture or a film up on the shot, the one
 * FURTHEST ALONG THE PIPELINE (step_order, as Spell lists them) gives it, and
 * its NEWEST such version is the one shown. So Comp v01 replaces Rough
 * Animation v07, even if the animation went up later: "the latest version of
 * the latest department", read as how far the shot has got, not as which
 * upload was last in time.
 *
 * A PDF or any other file that is not a picture or a film is skipped, because
 * it has nothing to show at this size; the Department's newest picture stands.
 *
 * Read fresh on every page, never copied into shot_thumbnails, so a new
 * version is on the breakdown the moment it is up, with nothing to keep in step.
 * Versions work without migration 109: only the hand-added half needs it.
 *
 * TWO QUERIES FOR THE WHOLE SHOW, whatever its size, matched up in PHP like
 * board_data(). Never throws: on any error, no pictures.
 *
 * @return array shot id => ['url', 'video' (bool), 'label', 'from_version' (bool),
 *                           'manual' (bool: a hand-added one exists, shown or not)]
 */
function breakdown_thumbs(PDO $pdo, int $projectId): array {
    require_once __DIR__ . '/uploads.php';      // version_label()
    $out = [];
    try {
        // Hand-added first, so a version found below replaces it.
        if (shot_thumbs_available($pdo)) {
            $q = $pdo->prepare('SELECT id, shot_id, original_name FROM shot_thumbnails WHERE project_id = ?');
            $q->execute([$projectId]);
            foreach ($q as $r) {
                $out[(int)$r['shot_id']] = [
                    'url'          => 'attachment.php?kind=shot&id=' . (int)$r['id'],
                    'video'        => false,
                    'label'        => 'Added by hand',
                    'from_version' => false,
                    'manual'       => true,
                ];
            }
        }

        if (!column_exists($pdo, 'progress_versions', 'version_no')) return $out;

        // Every picture or film put up on a shot of this show, with how far
        // along the pipeline its Department sits. Shots only ('s' + id): an
        // asset's versions are that asset's, not the shot's.
        $q = $pdo->prepare(
            "SELECT pr.item_key, ps.step_order, ps.id AS step_id, ps.name AS step_name,
                    v.id, v.version_no, v.mime_type
               FROM progress pr
               JOIN pipeline_steps ps ON ps.id = pr.step_id
               JOIN progress_versions v ON v.progress_id = pr.id
              WHERE pr.project_id = ? AND pr.item_key LIKE 's%'
                AND (v.mime_type LIKE 'image/%' OR v.mime_type LIKE 'video/%')"
        );
        $q->execute([$projectId]);
        $best = [];
        foreach ($q as $r) {
            if (!preg_match('/^s(\d+)$/', (string)$r['item_key'], $m)) continue;
            $shotId = (int)$m[1];
            // Pipeline order first (id breaks a tie, as pipeline_steps() orders
            // them), then the newest version within that Department.
            $rank = [(int)$r['step_order'], (int)$r['step_id'], (int)$r['version_no']];
            if (isset($best[$shotId]) && $best[$shotId]['rank'] >= $rank) continue;
            $best[$shotId] = ['rank' => $rank, 'row' => $r];
        }
        foreach ($best as $shotId => $b) {
            $r = $b['row'];
            $out[$shotId] = [
                'url'          => 'attachment.php?kind=version&id=' . (int)$r['id'],
                'video'        => strncmp((string)$r['mime_type'], 'video/', 6) === 0,
                'label'        => $r['step_name'] . ' ' . version_label((int)$r['version_no']),
                'from_version' => true,
                'manual'       => isset($out[$shotId]) && $out[$shotId]['manual'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[Anima breakdown] thumbs: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Put a picture up on one shot by hand, replacing the one it had.
 *
 * Pictures only (PNG, JPEG, GIF, WebP): it is a thumbnail. It passes the same
 * checks as every other upload (accept_upload(): size, extension AND contents).
 * The old file is removed from disk once the new one is recorded.
 *
 * @return string what happened, for the screen
 */
function shot_thumb_save(PDO $pdo, int $projectId, int $shotId, array $file, int $userId): string {
    require_once __DIR__ . '/uploads.php';
    if (!shot_thumbs_available($pdo)) {
        throw new RuntimeException('Shot pictures need a database update first: open migrate.php.');
    }
    $s = $pdo->prepare('SELECT shot_code FROM shots WHERE id = ? AND project_id = ?');
    $s->execute([$shotId, $projectId]);
    $code = $s->fetchColumn();
    if ($code === false) throw new RuntimeException('That shot is not on this show.');

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        throw new RuntimeException('A shot picture must be an image (PNG, JPG, GIF or WebP).');
    }
    $dir = ensure_upload_dir();
    $errors = [];
    $ok = accept_upload($file, $dir, $errors);
    if ($ok === null) throw new RuntimeException($errors[0] ?? 'That picture could not be saved.');

    $old = $pdo->prepare('SELECT stored_name FROM shot_thumbnails WHERE shot_id = ?');
    $old->execute([$shotId]);
    $oldName = $old->fetchColumn();

    try {
        $pdo->prepare(
            'INSERT INTO shot_thumbnails
               (shot_id, project_id, stored_name, original_name, mime_type, file_size, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE stored_name = VALUES(stored_name),
               original_name = VALUES(original_name), mime_type = VALUES(mime_type),
               file_size = VALUES(file_size), uploaded_by = VALUES(uploaded_by),
               uploaded_at = CURRENT_TIMESTAMP'
        )->execute([$shotId, $projectId, $ok['stored'], $ok['name'], $ok['mime'], $ok['size'],
                    $userId ?: null]);
    } catch (Throwable $e) {
        @unlink($dir . '/' . $ok['stored']);
        throw new RuntimeException('That picture could not be recorded.');
    }
    // The replaced file belongs to nothing now. basename() as attachment.php
    // checks it: a stored name is never a path.
    if ($oldName !== false && basename((string)$oldName) === $oldName) @unlink($dir . '/' . $oldName);

    return 'Picture saved on ' . $code . '.';
}

/** Take the hand-added picture off one shot. A version's picture is untouched. */
function shot_thumb_remove(PDO $pdo, int $projectId, int $shotId): string {
    require_once __DIR__ . '/uploads.php';
    if (!shot_thumbs_available($pdo)) return '';
    $q = $pdo->prepare('SELECT stored_name FROM shot_thumbnails WHERE shot_id = ? AND project_id = ?');
    $q->execute([$shotId, $projectId]);
    $name = $q->fetchColumn();
    if ($name === false) return 'That shot had no picture.';
    $pdo->prepare('DELETE FROM shot_thumbnails WHERE shot_id = ? AND project_id = ?')
        ->execute([$shotId, $projectId]);
    if (basename((string)$name) === $name) @unlink(upload_dir_path() . '/' . $name);
    return 'Picture removed.';
}
