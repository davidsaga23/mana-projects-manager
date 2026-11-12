<?php
// The log-time pop-up's one endpoint (migration 080; includes/log_reminders.php).
//
// David, 2026-11-11: "add pop up reminders for everyone to log times at the end
// of the day and a button for admins to send and instant pop up". Every open page
// asks here every few minutes (assets/log-reminder.js, paused while the tab is
// hidden):
//
//   GET                      what to pop up now, or nothing
//   POST dismiss=auto        put today's end-of-day pop-up away (this login)
//   POST dismiss=<id>        put an admin's instant reminder away (this person)
//   POST snooze=30|60        nothing for that many minutes
//
// ⚠ ALWAYS THE ASKER'S OWN. The person is effective_crew_id(), from the session,
// never from the request. The only id that can be posted is a reminder's, and
// log_reminder_dismiss() matches it against the asker's own crew id in its
// WHERE clause, so somebody else's reminder cannot be touched. That is the
// second lock behind artist_may_post(), which lets 'dismiss' and 'snooze'
// through for artists and supervisors on this script and nothing else.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_login_json();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$answer = function (array $a) {
    echo json_encode($a + ['ok' => true]);
    exit;
};

// Switched off for this person or role (Users → Access), or no person behind
// this login, or an admin viewing as somebody: nothing to show, ever. The page
// script is not drawn in those cases either; this is the door agreeing.
$crewId = (int)(effective_crew_id($pdo) ?? 0);
if ($crewId <= 0 || in_preview() || !feature_on('log_reminders')) {
    $answer(['show' => null, 'off' => true]);
}

try {
    require_once __DIR__ . '/includes/log_reminders.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['snooze'])) {
            $min = (int)$_POST['snooze'];
            if (!in_array($min, LOG_REMINDER_SNOOZES, true)) $min = 60;
            log_reminder_remember('snooze_until', time() + $min * 60);
        }
        if (isset($_POST['dismiss'])) {
            $what = (string)$_POST['dismiss'];
            if ($what === 'auto') {
                log_reminder_remember('dismissed', true);
            } elseif (ctype_digit($what)) {
                log_reminder_dismiss($pdo, $crewId, (int)$what);
            }
        }
        $answer(['show' => null]);
    }

    $answer(['show' => log_reminder_due($pdo, $crewId)]);
} catch (Throwable $e) {
    // A reminder must never be the reason a page misbehaves.
    error_log('[Anima log reminder] ' . $e->getMessage());
    $answer(['show' => null]);
}
