<?php
// A COLOUR PER PROJECT (David, 2026-11-17: "I wonder if we should add a color code
// per project and change the hue a little bit so when we're navigating anima we
// know visually which project we're on", agreed on a mock-up at "Subtle" strength,
// then "I still don't see the color codes here" on the projects list).
//
// ⚠ IDENTITY ONLY. The colour says WHICH show you are in: a thin bar across the top
// of the window, the show's code tag, the underline of the current tab, and a faint
// wash over the page. It never replaces Anima's working colours: blue is still every
// button and link, green is still approved, amber a warning and red late, on every
// show. A red show must not make everything on it look urgent. That is also why the
// palette below leaves out pure blue, green, amber and red.
//
// STORED as a project setting (`project_settings`, key `color`), so no migration:
// set on the project's Edit screen (Basics), or left on "Automatic". Automatic gives
// each project, in the order they were created, the next palette colour no other
// project has chosen, so two shows only share a colour once there are more shows than
// colours. Read once per request for every project together; never throws.

/** The palette: name => hex. Kept clear of the blue, green, amber and red that carry meaning. */
const PROJECT_PALETTE = [
    'coral'  => '#f2706d',
    'lilac'  => '#b49cff',
    'teal'   => '#3fd8c4',
    'pink'   => '#f58fc8',
    'orange' => '#f39a4c',
    'lime'   => '#b8d65a',
    'sky'    => '#7fb8ff',
    'sand'   => '#d9b98a',
];

/** Every project's colour, id => hex. Chosen colours first, then the automatic ones. */
function project_colors(PDO $pdo): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    try {
        $rows = $pdo->query("SELECT p.id, s.setting_value AS color
                               FROM projects p
                          LEFT JOIN project_settings s ON s.project_id = p.id AND s.setting_key = 'color'
                           ORDER BY p.id")->fetchAll();
    } catch (Throwable $e) {
        return $map;
    }
    $taken = [];
    foreach ($rows as $r) {
        $c = strtolower(trim((string)($r['color'] ?? '')));
        if (in_array($c, PROJECT_PALETTE, true)) { $map[(int)$r['id']] = $c; $taken[$c] = true; }
    }
    $pal = array_values(PROJECT_PALETTE);
    $free = array_values(array_filter($pal, fn($c) => !isset($taken[$c])));
    if (!$free) $free = $pal;
    $i = 0;
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if (isset($map[$id])) continue;
        $map[$id] = $free[$i % count($free)];
        $i++;
    }
    return $map;
}

/** One project's colour, or '' when it cannot be read. */
function project_color(PDO $pdo, int $projectId): string {
    return project_colors($pdo)[$projectId] ?? '';
}

/** The colour the person picked for this project, or '' for Automatic. */
function project_color_chosen(PDO $pdo, int $projectId): string {
    try {
        $c = strtolower(trim((string)project_setting($pdo, $projectId, 'color', '')));
    } catch (Throwable $e) {
        return '';
    }
    return in_array($c, PROJECT_PALETTE, true) ? $c : '';
}

/**
 * The page-wide part, drawn once by the project header (project_section_nav()),
 * which every page inside a project draws: the colour as --proj, the bar across the
 * top of the window and the faint wash. The rest (tag, tab underline) is in
 * assets/style.css and reads --proj.
 */
function project_color_page_style(string $hex): string {
    if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) return '';
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    return '<style>'
         . ':root{--proj:' . $hex . ';--proj-rgb:' . $r . ',' . $g . ',' . $b . '}'
         . 'body{background-image:linear-gradient(rgba(' . $r . ',' . $g . ',' . $b . ',.035),rgba(' . $r . ',' . $g . ',' . $b . ',.035));background-attachment:fixed}'
         . 'body::before{content:"";position:fixed;top:0;left:0;right:0;height:3px;background:' . $hex . ';z-index:1000;pointer-events:none}'
         . '@media print{body{background-image:none}body::before{display:none}}'
         . '</style>';
}
