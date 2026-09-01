<?php
// The artist list as the studio's own freelancer spreadsheet.
//
// Mana_Freelancer_DataBase.xlsx was opened and its styles.xml read, not
// approximated: bold Arial 16 on FFE06666 for the title bar, bold Arial for the
// headers, plain Arial for the body, blue underlined for a reel link. Its twelve
// columns come out in its order, so a file downloaded from here drops into the
// same place the old one lived and every formula pointing at column F still
// finds the email.
//
// ⚠ THE PASSWORD COLUMN IS WRITTEN EMPTY, and that is deliberate rather than an
// omission. Column H exists so the shape matches, but this database has never
// held a reel password and will not start by exporting one — the same answer the
// paste parser and the importer give it. The header is still there so nobody
// wonders whether the column was lost.
//
// The RATE comes from the finance database through includes/money.php, so an
// editor's download simply has that column blank. Nobody is shown a number their
// account could not open on screen.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hiring.php';
require_once __DIR__ . '/includes/artists.php';
require_once __DIR__ . '/includes/money.php';
require_once __DIR__ . '/includes/xlsx.php';
require_login();

$back = 'artists.php';

// Reported, never a broken download (lesson 7).
if (!xlsx_available()) {
    flash('err', 'This server has no ZipArchive extension, so it cannot build an Excel file. '
               . 'Ask the host to enable php-zip.');
    header('Location: ' . $back);
    exit;
}
if (!hiring_available($pdo)) {
    flash('err', 'The hiring database updates have not been applied, so there is nothing to export.');
    header('Location: ' . $back);
    exit;
}

$extra = [];
foreach (['location', 'position', 'seniority', 'project_type', 'worked_with_us', 'rating'] as $c) {
    if (column_exists($pdo, 'hires', $c)) $extra[] = $c;
}

// The same search the screen was showing, so what downloads is what you were
// looking at rather than everything regardless.
$q = trim((string)($_GET['q'] ?? ''));
[$people] = artists_list($pdo, $q, $extra);
if (!$people) {
    flash('err', $q !== '' ? 'Nothing matches that search, so there was nothing to export.'
                           : 'There are no artists to export yet.');
    header('Location: ' . $back);
    exit;
}

$quotes = (can_see_money() && hire_quotes_available())
    ? hire_quotes(array_map(function ($p) { return (int)$p['id']; }, $people))
    : [];

$rows = [];

// Row 1: the title bar, run across all twelve columns so the fill does not stop
// halfway — the original does the same.
$title = [['v' => 'Freelancers database', 's' => XLSX_FL_TITLE]];
for ($i = 1; $i < 12; $i++) $title[] = ['v' => '', 's' => XLSX_FL_TITLE];
$rows[] = $title;

// Row 2: its headers, in its order.
$rows[] = array_map(function ($h) { return ['v' => $h, 's' => XLSX_FL_HEAD]; }, [
    'Artist', 'Location', 'Position', 'Seniority', 'Project type', 'Email',
    'Demo / portfolio link', 'Password', 'Worked with us?', 'Notes', 'Rate (USD)', 'Rating',
]);

$val = function ($p, string $k) { return (string)($p[$k] ?? ''); };

foreach ($people as $p) {
    $qt   = $quotes[(int)$p['id']] ?? null;
    $rate = ($qt && $qt['quoted_rate'] !== null) ? round((float)$qt['quoted_rate'], 2) : '';
    $reel = $val($p, 'portfolio_url');

    $rows[] = [
        ['v' => $val($p, 'candidate_name'), 's' => XLSX_FL_TEXT],
        ['v' => $val($p, 'location'),       's' => XLSX_FL_TEXT],
        ['v' => $val($p, 'position'),       's' => XLSX_FL_TEXT],
        ['v' => $val($p, 'seniority'),      's' => XLSX_FL_TEXT],
        ['v' => $val($p, 'project_type'),   's' => XLSX_FL_TEXT],
        ['v' => $val($p, 'email'),          's' => XLSX_FL_TEXT],
        // Blue and underlined like the original, but plain text rather than a
        // hyperlink: a real one needs a rels part per cell, and the address is
        // the useful half.
        ['v' => $reel, 's' => $reel !== '' ? XLSX_FL_LINK : XLSX_FL_TEXT],
        // Column H — the password. Present, and empty. See the note at the top.
        ['v' => '', 's' => XLSX_FL_TEXT],
        ['v' => !empty($p['worked_with_us']) ? 1 : '', 's' => XLSX_FL_TEXT],
        ['v' => $val($p, 'notes'),  's' => XLSX_FL_TEXT],
        ['v' => $rate,              's' => $rate === '' ? XLSX_FL_TEXT : XLSX_FL_NUM],
        ['v' => $val($p, 'rating'), 's' => XLSX_FL_TEXT],
    ];
}

$widths = [26, 14, 20, 12, 12, 30, 38, 10, 14, 34, 12, 8];

try {
    $bytes = xlsx_build('Freelancers', $rows, $widths);
} catch (Throwable $e) {
    flash('err', 'The file could not be built: ' . $e->getMessage());
    header('Location: ' . $back);
    exit;
}

// CODE_Descriptive_YYYY-MM-DD is the scheme every export here uses; there is no
// project code on a studio-wide list, so the studio's name takes that place.
xlsx_send($bytes, 'Mana_FreelancerDatabase_' . date('Y-m-d') . '.xlsx');
