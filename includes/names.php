<?php
// The alias layer — matching names that arrive from outside.
//
// This exists because of real incidents on STFC: "Animator.wasi" and "Wasi Ali
// Khan" are one person, "RomulanOfficer01" and "Romulan Officer" are one asset,
// and both silently dropped data into an unassigned bucket for weeks.
//
// The rule everywhere here: never guess, never auto-create. Resolution returns
// null when it doesn't know, and the caller keeps the raw value so the unmatched
// report can show exactly what arrived and how often.

/**
 * Tidy an incoming person name without changing who it refers to.
 *
 * Plaky appends " (inactive)" to deactivated members, so the same person arrives
 * under two spellings the moment they are deactivated. Stripping it is safe and
 * the suffix is itself useful — it means the person is no longer active.
 *
 * Returns ['name' => cleaned, 'inactive' => bool].
 */
function normalise_person_name(string $raw): array {
    $name = trim($raw);
    $inactive = false;
    if (preg_match('/^(.*?)\s*\(\s*inactive\s*\)\s*$/i', $name, $m)) {
        $name = trim($m[1]);
        $inactive = true;
    }
    $name = preg_replace('/\s+/u', ' ', $name);
    return ['name' => $name, 'inactive' => $inactive];
}

/**
 * Split an assignee field that may hold several people.
 *
 * Plaky writes them comma-separated inside one field ("Júnior Fonseca,Michal
 * Pilch"). The CSV parser has already handled the quoting, so a comma here
 * genuinely separates people.
 *
 * Returns a list of ['name' => …, 'inactive' => bool], in the order given —
 * the first is treated as primary.
 */
function split_assignees(?string $raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $out = [];
    foreach (preg_split('/\s*[,;]\s*/u', $raw) as $part) {
        if (trim($part) === '') continue;
        $person = normalise_person_name($part);
        if ($person['name'] !== '') $out[] = $person;
    }
    return $out;
}

/**
 * Find a crew member by any name they are known under.
 *
 * Checks the global alias table first, then the real name. MySQL's default
 * collation makes both comparisons case-insensitive.
 *
 * Returns crew id, or null when unknown — never creates anybody.
 */
function resolve_crew(PDO $pdo, string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;

    $stmt = $pdo->prepare('SELECT crew_id FROM crew_aliases WHERE alias_name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) return (int)$id;

    $stmt = $pdo->prepare('SELECT id FROM crew WHERE name = ?');
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** Teach the alias table that a name belongs to someone. */
function remember_crew_alias(PDO $pdo, string $alias, int $crewId): void {
    $alias = trim($alias);
    if ($alias === '') return;
    $pdo->prepare(
        'INSERT INTO crew_aliases (alias_name, crew_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE crew_id = VALUES(crew_id)'
    )->execute([$alias, $crewId]);
}

/**
 * Resolve a project-scoped name (step or asset) through name_aliases.
 *
 * "Lightning" arrives from a board where it is spelled that way 188 times; the
 * pipeline step is called "Lighting". An alias fixes that once, for good.
 *
 * Returns the canonical name — the alias target if one exists, otherwise the
 * name unchanged. Whether that canonical name matches anything is the caller's
 * question to answer.
 */
function resolve_name_alias(PDO $pdo, int $projectId, string $kind, string $name): string {
    $name = trim($name);
    if ($name === '') return $name;
    $stmt = $pdo->prepare(
        'SELECT canonical_name FROM name_aliases WHERE project_id = ? AND kind = ? AND alias = ?'
    );
    $stmt->execute([$projectId, $kind, $name]);
    $canonical = $stmt->fetchColumn();
    return $canonical === false ? $name : (string)$canonical;
}

/** Record that an incoming name means a canonical one. */
function remember_name_alias(PDO $pdo, int $projectId, string $kind, string $alias, string $canonical): void {
    $alias = trim($alias);
    $canonical = trim($canonical);
    if ($alias === '' || $canonical === '') return;
    $pdo->prepare(
        'INSERT INTO name_aliases (project_id, alias, canonical_name, kind) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)'
    )->execute([$projectId, $alias, $canonical, $kind]);
}

/**
 * Re-run assignee resolution across everything already stored.
 *
 * This is what makes the alias layer worth having: teach it once that
 * "Animator.wasi" is Wasi Ali Khan, and every progress record ever imported
 * under that name attaches to him — no re-import, no lost history. Possible only
 * because `assignee_raw` keeps what actually arrived.
 *
 * Returns how many records now have somebody attached.
 */
function reresolve_assignees(PDO $pdo, int $projectId): int {
    $stmt = $pdo->prepare(
        "SELECT id, assignee_raw FROM progress
          WHERE project_id = ? AND assignee_raw IS NOT NULL AND assignee_raw <> ''"
    );
    $stmt->execute([$projectId]);
    $rows = $stmt->fetchAll();

    $upd = $pdo->prepare('UPDATE progress SET crew_id = ? WHERE id = ?');
    $del = $pdo->prepare('DELETE FROM progress_crew WHERE progress_id = ?');
    $ins = $pdo->prepare('INSERT IGNORE INTO progress_crew (progress_id, crew_id) VALUES (?, ?)');

    $resolved = 0;
    $cache = [];
    foreach ($rows as $r) {
        $ids = [];
        foreach (split_assignees($r['assignee_raw']) as $person) {
            $name = $person['name'];
            if (!array_key_exists($name, $cache)) $cache[$name] = resolve_crew($pdo, $name);
            if ($cache[$name]) $ids[] = $cache[$name];
        }
        $ids = array_values(array_unique($ids));
        $upd->execute([$ids[0] ?? null, $r['id']]);
        $del->execute([$r['id']]);
        foreach ($ids as $cid) $ins->execute([$r['id'], $cid]);
        if ($ids) $resolved++;
    }
    return $resolved;
}

/**
 * The standing unmatched-names report for people.
 *
 * Derived from what was actually stored rather than from a separate log: any
 * progress row that kept a raw assignee but resolved to nobody. That means it
 * cannot drift out of step with reality, and it empties itself as aliases are
 * added.
 */
function unmatched_assignees(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare(
        "SELECT assignee_raw, COUNT(*) AS times_seen
           FROM progress
          WHERE project_id = ? AND crew_id IS NULL
            AND assignee_raw IS NOT NULL AND assignee_raw <> ''
          GROUP BY assignee_raw
          ORDER BY times_seen DESC, assignee_raw"
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}
