<?php
// THE SCHEDULE TAB IS GONE; THIS ADDRESS REDIRECTS TO THE TIMELINE (2026-11-16).
//
// David: the Schedule tab "can't edit any dates", and he would rather adjust them
// in the Timeline. Asked whether the scheduler should go, he agreed to keep its
// engine (includes/scheduler.php) and drop the separate tab. Its preview, with
// Apply and Clear, is now the Timeline's "Draft dates from the plan…" dialog
// (includes/schedule_draft.php).
//
// Kept as a file so old links and bookmarks still land somewhere useful: the
// Timeline, with that dialog open (#draft), which is what this page used to show.
// A POST here is not acted on: nothing in Anima posts to this address any more,
// and a stale form saving behind a redirect is the "nothing happened" failure
// this app avoids. The Timeline still checks the person may see the show.

$projectId = (int)($_GET['project'] ?? 0);
header('Location: ' . ($projectId > 0 ? 'timeline.php?project=' . $projectId . '#draft' : 'index.php'));
exit;
