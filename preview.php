<?php
// Start and stop "look at Anima as somebody else".
//
// David, 2026-09-02: "is there a way to see Anima from different profiles aside
// of just admin? Like editor or view only. I'd like to see what the others have
// access to."
//
// An admin borrows a lesser role for the session. Everything downstream follows
// on its own, because the whole app decides access through user_role() — the top
// bar hides what that role cannot open, require_admin() turns it away from the
// money screens, and can_edit() greys out what it cannot change.
//
// ⚠ THIS FILE DELIBERATELY DOES NOT CALL require_login().
//
// require_login() carries the read-only guard, and that guard now refuses every
// POST made during a preview. Routing the STOP button through it would mean the
// way out of a preview was blocked by the preview itself — locked in until the
// session cookie was cleared by hand. So the checks it needs are done here,
// explicitly, and they are the same two: logged in, and still active.
//
// Nothing here can raise a permission. Starting a preview only ever swaps in a
// role BELOW admin (preview_role() whitelists editor and viewer and re-checks
// the real role every time it is asked), and stopping one only puts back the
// role the account already had.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}
$me = current_user();
if (!$me || (int)($me['active'] ?? 1) !== 1) {
    // Same treatment require_login() gives a deactivated account.
    $_SESSION = [];
    session_destroy();
    header('Location: login.php?disabled=1');
    exit;
}

// Where to go back to. The caller's own text, so it is checked the way
// require_login() checks it: only a path beginning with a single slash is ours.
// A protocol-relative "//evil.com/" in a Location header sends the browser off
// this host entirely, wearing our domain in the link.
$back   = (string)($_POST['back'] ?? '');
$second = $back[1] ?? '';
if ($back === '' || $back[0] !== '/' || $second === '/' || $second === chr(92)) {
    $back = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['stop'])) {
        // Unconditional. If the key is set on an account that may not preview it
        // does nothing anyway, and refusing to clear it could only ever strand
        // somebody.
        unset($_SESSION['preview_role']);
        flash('ok', 'Back to your own account.');

    } elseif (isset($_POST['start'])) {
        // real_role(), not is_admin(): is_admin() reads the EFFECTIVE role, so an
        // admin already previewing as a viewer would be refused permission to
        // switch to previewing as an editor.
        if (real_role() !== 'admin') {
            flash('err', 'Only an administrator can preview the app as another role.');
        } else {
            $want = (string)($_POST['role'] ?? '');
            if (!in_array($want, ['editor', 'viewer'], true)) {
                flash('err', 'That is not a role you can preview.');
            } else {
                $_SESSION['preview_role'] = $want;
                flash('ok', 'Previewing as ' . ($want === 'viewer' ? 'a view-only account' : 'an editor')
                          . '. Nothing will save until you leave the preview.');
                // Straight to the projects list rather than back where they were:
                // the screen they started from is very often one the borrowed
                // role cannot open, and landing on "that screen is for
                // administrators only" reads as a fault rather than the answer.
                $back = 'index.php';
            }
        }
    }
}

header('Location: ' . $back);
exit;
