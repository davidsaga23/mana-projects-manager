<?php
// THE FIRST-TIME TOUR (David, 2026-10-30: "can you create a FTU for new users
// on how to use the platform and where to find stuff?").
//
// A short step-by-step overlay, different per role, shown once on a person's
// first login and re-opened any time from "Tour" in the top bar. The steps and
// every word of their copy live in assets/tour.js; this file only decides
// WHETHER the script loads on this page, and tells it who is looking.
//
// ⚠ INCLUDED BY includes/header.php, SO IT LOADS ON EVERY PAGE. It must stay
// tiny and must never throw: one guarded query per SESSION (not per page), and
// nothing at all once the tour has been decided for this session. The script
// itself is only sent when the tour is actually running.
//
// "SEEN" IS users.tour_seen_at (migration, see docs/MIGRATIONS.md). It is
// stamped the moment the tour is shown automatically, not when it is finished:
// "shown automatically once" means once, and somebody who closes the tab half
// way should not be met by it again on every login. Until the migration has
// run there is no column, and the script falls back to remembering it in the
// browser (localStorage) instead.

/**
 * Should the tour run on this page, and how? null = do not load the script.
 *
 *   start  the step to open at (0 = the welcome). A ?tour=N in the address
 *          means the tour is running and has just crossed to this page.
 *   auto   '' when somebody asked for it (the Tour link, or a step crossing
 *          pages); 'db' when it opens by itself and the database has already
 *          been told; 'local' when it opens by itself and only the browser can
 *          remember it (migration not run yet).
 */
function tour_boot(?PDO $pdo): ?array {
    try {
        if (!$pdo || !is_logged_in()) return null;

        $asked = $_GET['tour'] ?? null;
        if (is_string($asked) && ctype_digit($asked)) {
            return ['start' => (int)$asked, 'auto' => ''];
        }

        // Never by itself while an admin is viewing as somebody: that is
        // David checking a screen, not a new person arriving. The Tour link
        // still works there, which is the way to see each role's tour.
        if (in_preview()) return null;
        // Decided once per session, so every later page costs nothing.
        if (!empty($_SESSION['tour_decided'])) return null;
        $_SESSION['tour_decided'] = 1;

        try {
            $q = $pdo->prepare('SELECT tour_seen_at FROM users WHERE id = ?');
            $q->execute([(int)$_SESSION['user_id']]);
            if ($q->fetchColumn() !== null) return null;
            $pdo->prepare('UPDATE users SET tour_seen_at = NOW() WHERE id = ?')
                ->execute([(int)$_SESSION['user_id']]);
            return ['start' => 0, 'auto' => 'db'];
        } catch (PDOException $e) {
            // No tour_seen_at yet (migration pending): let the browser decide.
            return ['start' => 0, 'auto' => 'local'];
        }
    } catch (Throwable $e) {
        return null;    // chrome never takes a page down
    }
}

/**
 * Who is looking, as the data-* attributes the script reads to pick its steps.
 * Only called when tour_boot() said yes.
 */
function tour_attrs(PDO $pdo, array $boot): string {
    $crew = 0;
    $salaried = false;
    try {
        $crew = (int)(effective_crew_id($pdo) ?? 0);
        // The same answer as home_is_salaried() (includes/home.php), repeated
        // here rather than required: that file pulls in the money and timelog
        // code, which is far too much to load into the top bar.
        if ($crew > 0) {
            $sql = column_exists($pdo, 'users', 'is_salaried')
                ? 'SELECT COUNT(*) FROM users WHERE crew_id = ? AND is_salaried = 1'
                : "SELECT COUNT(*) FROM users WHERE crew_id = ? AND role IN ('admin','editor')";
            $q = $pdo->prepare($sql);
            $q->execute([$crew]);
            $salaried = (int)$q->fetchColumn() > 0;
        }
    } catch (Throwable $e) {
        // Read as a freelancer: one extra invoice step is the safe mistake.
    }
    $attrs = [
        'data-role'     => user_role(),
        'data-start'    => (string)(int)$boot['start'],
        'data-auto'     => (string)$boot['auto'],
        'data-home'     => $crew > 0 ? '1' : '0',
        'data-salaried' => $salaried ? '1' : '0',
    ];
    $out = '';
    foreach ($attrs as $k => $v) $out .= ' ' . $k . '="' . e($v) . '"';
    return $out;
}

/** This page's address with ?tour=0 added: the Tour link, which opens it here. */
function tour_link(): string {
    $uri   = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path  = basename((string)parse_url($uri, PHP_URL_PATH)) ?: 'index.php';
    $query = [];
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
    $query['tour'] = 0;
    return $path . '?' . http_build_query($query);
}
