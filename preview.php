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
// role BELOW admin (preview_role() whitelists editor and artist and re-checks
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
        // somebody. Both kinds of preview are cleared together — a role one and a
        // person one are never meant to stack, and leaving either would strand.
        unset($_SESSION['preview_role'], $_SESSION['preview_user_id']);
        flash('ok', 'Back to your own account.');

    } elseif (isset($_POST['start'])) {
        // real_role(), not is_admin(): is_admin() reads the EFFECTIVE role, so an
        // admin already previewing as an artist would be refused permission to
        // switch to previewing as an editor.
        if (real_role() !== 'admin') {
            flash('err', 'Only an administrator can preview the app as another role.');
        } else {
            $want = (string)($_POST['role'] ?? '');
            if (!in_array($want, ['editor', 'supervisor', 'artist'], true)) {
                flash('err', 'That is not a role you can preview.');
            } else {
                // A role preview is generic, so it clears any person preview.
                unset($_SESSION['preview_user_id']);
                $_SESSION['preview_role'] = $want;
                flash('ok', 'Previewing as ' . role_in_words($want)
                          . '. Nothing will save until you leave the preview.');
                // Straight to the projects list rather than back where they were:
                // the screen they started from is very often one the borrowed
                // role cannot open, and landing on "that screen is for
                // administrators only" reads as a fault rather than the answer.
                $back = 'index.php';
            }
        }

    } elseif (isset($_POST['start_user'])) {
        // VIEW AS A SPECIFIC PERSON (David, 2026-10-21). Same two safeguards as a
        // role preview, enforced by preview_user() itself the moment the session
        // key is read: admin only, and only ever a lesser, active account. We
        // validate here as well so the admin gets a clear message rather than a
        // preview that silently refuses to take.
        if (real_role() !== 'admin') {
            flash('err', 'Only an administrator can view the app as someone else.');
        } else {
            $wantId = (int)($_POST['user_id'] ?? 0);
            $target = null;
            if ($wantId > 0) {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT id, username, display_name, role, active FROM users WHERE id = ?'
                    );
                    $stmt->execute([$wantId]);
                    $target = $stmt->fetch() ?: null;
                } catch (PDOException $e) {
                    $target = null;   // roles (005) missing — nothing to view as
                }
            }
            $role = $target ? (($target['role'] ?? '') === 'viewer' ? 'artist' : (string)$target['role']) : '';
            if (!$target || (int)($target['active'] ?? 0) !== 1
                || !in_array($role, ['editor', 'supervisor', 'artist'], true)
                || (int)$target['id'] === (int)$me['id']) {
                // An admin, an inactive account, yourself, or an unknown id: none
                // of these is a person you can borrow.
                flash('err', 'That is not an account you can view the app as.');
            } else {
                unset($_SESSION['preview_role']);           // role is taken from the person
                $_SESSION['preview_user_id'] = (int)$target['id'];
                // The audit row — "who looked as whom, and when" (migration 055).
                // Best-effort: a missing table must never block the preview, which
                // works whether or not 055 has been applied.
                try {
                    $log = $pdo->prepare(
                        'INSERT INTO preview_audit (admin_user_id, viewed_user_id, started_at) VALUES (?, ?, ?)'
                    );
                    $log->execute([(int)$me['id'], (int)$target['id'], date('Y-m-d H:i:s')]);
                } catch (PDOException $e) {
                    // migration 055 pending — recorded nowhere, but the view still works.
                }
                flash('ok', 'Viewing as ' . ($target['display_name'] ?: $target['username'])
                          . '. Nothing will save until you leave the preview.');
                $back = 'index.php';
            }
        }
    }
}

header('Location: ' . $back);
exit;
