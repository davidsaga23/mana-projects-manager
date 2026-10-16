<?php
// Ideal quotas: removed 2026-10-16, kept as a forwarding address.
//
// This was a Finance management tab editing each step's days per item, the same
// number, stored once on the step, that Dates & headcount edits. David chose to
// remove it rather than keep one value on two screens under two names. Its
// approved-expense-projection match moved to Dates & headcount
// (includes/ideal_match.php); the copy from another project was already there.
//
// Kept only so a bookmark or an old link lands on the screen that took over.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login();

// ?from= is what links carried while this was a studio-wide sheet.
$projectId = (int)($_GET['project'] ?? $_GET['from'] ?? 0);
if ($projectId <= 0) {
    header('Location: index.php');
    exit;
}
flash('ok', 'Ideal quotas are now the Days per item column on this screen. It was always the same number.');
header('Location: wizard.php?project=' . $projectId . '&step=6');
exit;
