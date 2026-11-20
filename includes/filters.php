<?php
/**
 * Pick-several filters read from the address (David, 2026-11-20: "Multiple
 * selection: be able to pick one or more options from those menus. Reverse
 * selection: exclude from filtering the selected options").
 *
 * assets/multi-filter.js turns a filter <select data-multi> into a list of
 * ticks with an Include / Exclude switch. A form then posts
 *     ?status[]=3&status[]=5            only 3 and 5
 *     ?status[]=3&status_not=1          everything except 3
 * ⚠ AND THE OLD ONE-VALUE LINKS STILL MEAN WHAT THEY MEANT: ?status=3 (from a
 * notification, a bookmark, another page's link) is "only 3". A comma list is
 * NOT split, because a project or person name can hold a comma.
 *
 * A page reads a filter once with filter_get(), tests rows with filter_hit(),
 * marks its options with filter_sel() and opens the <select> with
 * filter_select_attrs(), so the page reloads showing what was picked.
 */

/**
 * @return array{in: string[], not: bool, on: bool}
 *   in  — the ticked values, as strings, blanks dropped
 *   not — Exclude: everything EXCEPT those
 *   on  — anything to filter at all (Exclude with nothing ticked is nothing)
 */
function filter_get(string $name, ?array $src = null): array {
    $src = $src ?? $_GET;
    $raw = $src[$name] ?? [];
    $list = is_array($raw) ? $raw : [$raw];
    $in = [];
    foreach ($list as $v) {
        if (!is_scalar($v)) continue;
        $v = trim((string)$v);
        if ($v !== '' && !in_array($v, $in, true)) $in[] = $v;
    }
    $not = (string)($src[$name . '_not'] ?? '') === '1';
    return ['in' => $in, 'not' => $not && $in !== [], 'on' => $in !== []];
}

/** Does a row whose value is $value pass? A list passes on ANY ticked (Include) or NONE (Exclude). */
function filter_hit(array $f, $value): bool {
    if (empty($f['on'])) return true;
    $many = is_array($value) ? $value : [$value];
    $hit = false;
    foreach ($many as $v) {
        if (in_array((string)$v, $f['in'], true)) { $hit = true; break; }
    }
    return $f['not'] ? !$hit : $hit;
}

/** ' selected' on an <option> that is ticked. */
function filter_sel(array $f, $value): string {
    return in_array((string)$value, $f['in'] ?? [], true) ? ' selected' : '';
}

/**
 * What goes on the <select>: the switch for the control, the label the chip
 * starts with, and Exclude when it is on. `multiple` so the page can be drawn
 * with several ticked before the script runs.
 */
function filter_select_attrs(array $f, string $label): string {
    return ' multiple data-multi data-multi-label="' . e($label) . '"'
         . (!empty($f['not']) ? ' data-exclude="1"' : '');
}

/**
 * The same filter as address parameters, for a link that has to keep it
 * (paging, a tab, a redirect back after saving). '' when nothing is picked.
 */
function filter_query(string $name, array $f): string {
    if (empty($f['on'])) return '';
    $q = count($f['in']) === 1 && !$f['not']
        ? [$name => $f['in'][0]]                       // the old, short form
        : [$name => $f['in']] + ($f['not'] ? [$name . '_not' => '1'] : []);
    return http_build_query($q);
}

/**
 * The filter in words, for a heading or a file name: 'EP01, EP02' or
 * 'all but EP01'. '' when nothing is picked. $names maps a value to what is
 * shown for it (an id to a show's name); a value missing from it is shown as is.
 */
function filter_words(array $f, array $names = []): string {
    if (empty($f['on'])) return '';
    $words = array_map(fn($v) => (string)($names[$v] ?? $v), $f['in']);
    return ($f['not'] ? 'all but ' : '') . implode(', ', $words);
}

/**
 * ⚠ A PAGE THAT ONLY EVER SHOWS ONE AT A TIME (statement.php shows one
 * project's statement) can still be handed a list by a ticked chip; it takes
 * the first one ticked rather than breaking.
 */
function filter_first(array $f): string {
    return $f['in'][0] ?? '';
}
