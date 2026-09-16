<?php
/**
 * Project types: the labels a project is filed under.
 *
 * David, 2026-09-16: "we should have a way to manually create new project types".
 * The four were fixed in the schema until migration 032 made them a list.
 *
 * ⚠ A TYPE DECIDES NO NUMBER. For one day it was the key of an ideal quotas sheet,
 * which is why creating one briefly meant copying a sheet. Since 2026-09-17 ideal
 * quotas belong to each project (see includes/standards.php), so a type files a
 * show, and puts the pipeline templates saved for it first. Creating one takes a
 * name and nothing else.
 */

require_once __DIR__ . '/functions.php';

/**
 * What is filed under a type: its projects, archived ones included, and the
 * pipeline templates saved for it.
 */
function project_type_usage(PDO $pdo, string $type): array {
    $p = $pdo->prepare('SELECT id, name, code, status FROM projects WHERE type = ? ORDER BY name');
    $p->execute([$type]);
    $t = $pdo->prepare('SELECT name FROM pipeline_templates WHERE project_type = ? ORDER BY name');
    $t->execute([$type]);
    return ['projects' => $p->fetchAll(), 'templates' => $t->fetchAll(PDO::FETCH_COLUMN)];
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

/** The types no project, archived or not, and no template is filed under. */
function unused_project_types(PDO $pdo): array {
    if (!project_types_available($pdo)) return [];
    return $pdo->query(
        'SELECT pt.name FROM project_types pt
          WHERE NOT EXISTS (SELECT 1 FROM projects p WHERE p.type = pt.name)
            AND NOT EXISTS (SELECT 1 FROM pipeline_templates t WHERE t.project_type = pt.name)
          ORDER BY pt.sort_order, pt.id'
    )->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Add a project type. Returns the name as stored.
 *
 * Names are 1 to 20 characters, the size of the columns they are written into,
 * and unique ignoring case, the way MySQL compares them.
 */
function create_project_type(PDO $pdo, string $rawName, string $who = ''): string {
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
    foreach (project_types($pdo, true) as $t) {
        if (mb_strtolower($t) === mb_strtolower($name)) {
            throw new RuntimeException(sprintf(
                'There is already a project type called "%s", so nothing was created.', $t));
        }
    }

    try {
        $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM project_types')
                         ->fetchColumn();
        $pdo->prepare('INSERT INTO project_types (name, sort_order, created_by) VALUES (?, ?, ?)')
            ->execute([$name, $next, $who !== '' ? $who : null]);
    } catch (PDOException $e) {
        // Two people adding the same name at the same moment: the unique key
        // refuses the second, and that is the same answer as above.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new RuntimeException(sprintf(
                'There is already a project type called "%s", so nothing was created.', $name));
        }
        throw $e;
    }
    project_types($pdo, true);
    return $name;
}

/**
 * Remove a project type nothing is filed under.
 *
 * ⚠ REFUSED WHILE ANY PROJECT OR TEMPLATE USES IT, archived projects included,
 * rather than moving them somewhere: which type a show is filed under is decided
 * on its own Basics screen, never as a side effect of tidying a list. And never
 * the last type, because every project needs one.
 */
function remove_project_type(PDO $pdo, string $type): void {
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
    $pdo->prepare('DELETE FROM project_types WHERE name = ?')->execute([$type]);
    project_types($pdo, true);
}

/**
 * "+ New project type", and the panel it opens beside Type on Basics.
 *
 * Adds a type, and removes the ones nothing uses, which is the only way a type
 * created by mistake ever goes away. Both post to project_types.php and come
 * back to this project's Basics.
 *
 * ⚠ THESE CONTROLS BELONG TO ANOTHER FORM. The panel sits inside the Basics form,
 * and forms cannot nest: the parser drops an inner <form> tag, and Create would
 * have posted the project's details somewhere else. So every control here names
 * $formId with the form attribute, and the caller draws that empty form after its
 * own has closed.
 *
 * ⚠ formnovalidate ON EVERY REMOVE BUTTON. They share the form with the required
 * name box, and without it removing a type would be blocked until a new name had
 * been typed.
 */
function project_type_panel(PDO $pdo, array $project, string $formId): void {
    $f = ' form="' . e($formId) . '"';
    $unused = unused_project_types($pdo);
    ?>
    <details class="type-new">
      <summary class="row-btn">+ New project type</summary>
      <div class="type-new-form">
        <input type="hidden" name="return" value="basics:<?= (int)$project['id'] ?>"<?= $f ?>>
        <label class="inline">Name
          <input name="type_name" maxlength="20" size="18" required autocomplete="off"
                 placeholder="e.g. 2D motion comic"<?= $f ?>>
        </label>
        <button type="submit" name="action" value="new_type"<?= $f ?>>Create project type</button>
        <small>
          A type files the project and decides which pipeline templates are offered first. It changes
          no quota: ideal quotas belong to each project.
        </small>
        <?php if ($unused): ?>
          <div class="type-unused">
            <span class="muted small">Not used by any project or template:</span>
            <?php foreach ($unused as $t): ?>
              <button type="submit" name="remove_type" value="<?= e($t) ?>" formnovalidate
                      class="row-btn row-btn-danger"<?= $f ?>
                      onclick="return confirm(<?= e(json_encode('Remove the ' . $t . ' project type? Nothing uses it.')) ?>);">Remove <?= e($t) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </details>
    <?php
}
