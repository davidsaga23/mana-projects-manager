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
 * The asset types this project actually uses, in a stable order.
 *
 * ALPHABETICAL, deliberately. The column order has to be the same on every load
 * and the same in the export, and it has to be predictable across shows —
 * ordering by how many of each type exist would reshuffle the sheet every time
 * somebody added an asset. Character, Environment, FX, Prop, Vehicle happens to
 * be the order a breakdown is usually read in anyway.
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
 * How much of the show has been broken down, for the line above the grid.
 *
 * Counts shots with NO asset against the total. Said plainly because the
 * scheduler's behaviour depends on it: a shot with nothing linked is a shot that
 * still waits for every asset in the show.
 */
function breakdown_progress(PDO $pdo, int $projectId): array {
    $total = (int)$pdo->query(
        'SELECT COUNT(*) FROM shots WHERE project_id = ' . $projectId
    )->fetchColumn();

    $done = (int)$pdo->query(
        'SELECT COUNT(DISTINCT sa.shot_id)
           FROM shot_assets sa JOIN shots s ON s.id = sa.shot_id
          WHERE s.project_id = ' . $projectId
    )->fetchColumn();

    $links = (int)$pdo->query(
        'SELECT COUNT(*)
           FROM shot_assets sa JOIN shots s ON s.id = sa.shot_id
          WHERE s.project_id = ' . $projectId
    )->fetchColumn();

    return ['total' => $total, 'done' => $done, 'empty' => max(0, $total - $done), 'links' => $links];
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
