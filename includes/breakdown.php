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

    $rows = [];
    foreach ($stmt as $r) {
        $rows[(int)$r['id']] = [
            'id'        => (int)$r['id'],
            'episode'   => (string)$r['episode'],
            'shot_code' => (string)$r['shot_code'],
            'frames'    => $r['frames'] !== null ? (int)$r['frames'] : null,
            'seconds'   => $r['placeholder_seconds'] !== null ? (float)$r['placeholder_seconds'] : null,
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
 * Set a shot's frame count, and DERIVE ITS FOOTAGE FROM IT.
 *
 * ⚠ THIS WRITES placeholder_seconds TOO, AND THAT MOVES MONEY. Despite the
 * name, placeholder_seconds is not a placeholder: step_quota() sums it as the
 * project's real footage, and every per-second rate on the expense projection is
 * multiplied by it. Frames are the thing an editor actually knows, so frames are
 * what gets typed and seconds are what gets computed — one number entered once,
 * rather than the same duration typed twice in two units and free to disagree.
 *
 * At 24 fps a 96-frame shot is 4.000 seconds. DECIMAL(6,3) holds that exactly;
 * 100 frames at 24 fps is 4.167, rounded at 3dp, which is a thousandth of a
 * second on a shot and cannot accumulate into anything visible.
 *
 * CLEARING THE FRAMES LEAVES THE SECONDS ALONE. The wizard can set a blanket
 * placeholder for every shot before anyone has cut anything, and wiping that on
 * an accidental empty field would silently shrink the budget.
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
        $stmt = $pdo->prepare('SELECT placeholder_seconds FROM shots WHERE id = ?');
        $stmt->execute([$shotId]);
        $secs = $stmt->fetchColumn();
        return ['frames' => null, 'seconds' => $secs !== null ? (float)$secs : null];
    }

    if (!preg_match('/^[0-9]+$/', $raw)) {
        throw new RuntimeException('"' . $raw . '" is not a whole number of frames.');
    }
    $frames = (int)$raw;
    if ($frames > 9999999) throw new RuntimeException('That is more frames than a shot can hold.');

    $fps = (int)($project['fps'] ?? 24);
    if ($fps <= 0) $fps = 24;
    $seconds = round($frames / $fps, 3);

    $pdo->prepare('UPDATE shots SET frames = ?, placeholder_seconds = ? WHERE id = ?')
        ->execute([$frames, $seconds, $shotId]);
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
