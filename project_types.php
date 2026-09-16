<?php
// Adds and removes project types, then goes back to where the panel was.
//
// POST only, administrators only. The panel that posts here is drawn beside Type
// on a project's Basics screen, see project_type_panel(). A new type comes back
// PICKED on that screen but not saved, and the screen says so.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/project_types.php';
require_admin();

$returnProject = 0;
if (preg_match('/^basics:(\d+)$/', (string)($_POST['return'] ?? ''), $m)) $returnProject = (int)$m[1];
$back = $returnProject > 0 ? 'wizard.php?project=' . $returnProject . '&step=1' : 'index.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

try {
    if (isset($_POST['remove_type'])) {
        $gone = (string)$_POST['remove_type'];
        remove_project_type($pdo, $gone);
        flash('ok', sprintf('Removed the %s project type.', $gone));
    } else {
        $name = create_project_type($pdo, (string)($_POST['type_name'] ?? ''), current_user_display());
        flash('ok', sprintf('Created the %s project type.', $name));
        if ($returnProject > 0) $back .= '&pick_type=' . urlencode($name);
    }
} catch (Throwable $e) {
    flash('err', $e->getMessage());
}
header('Location: ' . $back);
exit;
