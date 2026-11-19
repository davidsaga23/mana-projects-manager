<?php
// A Realm call's transcript and notes (migration 105, includes/realm_scribe.php).
//
// David, 2026-11-19: "can we add an automatic transcript of any call in any room
// happening in realm?" The notes (to-dos and agreements) are posted in the
// room's chat when the call ends; this page is where the WHOLE transcript is
// read, and where notes that failed are written again.
//
//   realm_call.php            the calls I was in, newest first (admins: every call)
//   realm_call.php?id=<n>     one call: who, when, its notes, every line said
//   POST retry=1 (id=<n>)     write that call's notes again, when they failed
//
// ⚠ THE WORDS BELONG TO THE PEOPLE WHO SAID THEM. A transcript is read by the
// people who were in that call, and by admins, NOT by everybody who can read the
// room's chat (realm_scribe_may_read()). Anybody else gets "not found", the same
// answer as for a call that does not exist. Not inside a preview, like the Realm.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/studio.php';
require_once __DIR__ . '/includes/realm.php';
require_once __DIR__ . '/includes/realm_chat.php';
require_once __DIR__ . '/includes/realm_scribe.php';
require_login();

$uid   = realm_user_id();
$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$ready = !in_preview() && realm_scribe_available($pdo);
$self  = 'realm_call.php' . ($id > 0 ? '?id=' . $id : '');

if ($ready && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry'])) {
    // The second lock behind artist_may_post(): only somebody who was in the call.
    if (realm_scribe_may_read($pdo, $uid, $id)) {
        @set_time_limit(240);   // Anthropic may take most of a minute
        $why = realm_scribe_retry($pdo, $id);
        if ($why === '') {
            $q = $pdo->prepare('SELECT status, note FROM realm_calls WHERE id = ?');
            $q->execute([$id]);
            $after = $q->fetch() ?: [];
            if (($after['status'] ?? '') !== 'done') $why = (string)($after['note'] ?? '') ?: 'The notes could not be written.';
        }
        flash($why === '' ? 'ok' : 'err', $why === '' ? 'The notes were written again and posted in the room\'s chat.' : $why);
    }
    header('Location: ' . $self);
    exit;
}

$call = null;
$lines = [];
$people = [];
$calls = [];
if ($ready && $id > 0) {
    if (realm_scribe_may_read($pdo, $uid, $id)) {
        $q = $pdo->prepare('SELECT * FROM realm_calls WHERE id = ?');
        $q->execute([$id]);
        $call = $q->fetch() ?: null;
    }
    if ($call) {
        $lines  = realm_scribe_lines($pdo, $id);
        $people = array_map(function ($u) use ($pdo) { return realm_person_name($pdo, (int)$u); }, realm_scribe_people($pdo, $id));
    }
} elseif ($ready) {
    $sql = 'SELECT c.id, c.place, c.status, c.started_at, c.ended_at,
                   (SELECT COUNT(*) FROM realm_call_lines l WHERE l.call_id = c.id) AS n_lines
              FROM realm_calls c';
    if (is_admin()) {
        $q = $pdo->query($sql . ' ORDER BY c.started_at DESC LIMIT 100');
    } else {
        $q = $pdo->prepare($sql . ' JOIN realm_call_people p ON p.call_id = c.id AND p.user_id = ? ORDER BY c.started_at DESC LIMIT 100');
        $q->execute([$uid]);
    }
    $calls = $q->fetchAll();
}

$statusWords = [
    'open'    => 'The call is still going on.',
    'writing' => 'The call has ended; its notes are being written.',
    'done'    => 'Notes posted in the room\'s chat.',
    'failed'  => 'The notes could not be written.',
    'empty'   => 'No notes: too little was said.',
];
$when = function (?string $at): string { return $at ? date('j M Y, H:i', strtotime($at)) : ''; };

$pageTitle = $call ? 'Call transcript' : 'Realm call transcripts';
require __DIR__ . '/includes/header.php';
?>

<h2 class="wizard-title"><?= $call ? 'Call transcript' : 'Realm call transcripts' ?>
  <?php if ($call): ?><span class="muted">· <?= e(realm_scribe_place_name($pdo, (string)$call['place'])) ?></span><?php endif; ?></h2>

<?php if (in_preview()): ?>
  <div class="flash flash-warn">Transcripts are not shown while you are viewing the app as somebody else.</div>
<?php elseif (!$ready): ?>
  <div class="flash flash-warn">Call transcripts need a database update first. An administrator opens migrate.php once.</div>
<?php elseif ($id > 0 && !$call): ?>
  <div class="flash flash-err">That transcript was not found. A transcript is only shown to the people who were in the call.</div>
  <p><a href="realm_call.php">See the calls you were in</a></p>
<?php elseif ($call): ?>
  <p class="lead">
    <?= e($when((string)$call['started_at'])) ?><?= $call['ended_at'] ? ' to ' . e(date('H:i', strtotime((string)$call['ended_at']))) : '' ?>
    <?= $people ? ' · with ' . e(implode(', ', $people)) : '' ?><br>
    <span class="muted"><?= e($statusWords[(string)$call['status']] ?? '') ?></span>
  </p>
  <?php if ((string)$call['status'] === 'failed'): ?>
    <div class="flash flash-err"><?= e((string)($call['note'] ?: 'The notes could not be written.')) ?></div>
    <form method="post" action="<?= e($self) ?>">
      <input type="hidden" name="id" value="<?= (int)$call['id'] ?>">
      <button type="submit" name="retry" value="1" class="btn">Write the notes again</button>
      <span class="muted">Takes up to a minute. The notes are posted in the room's chat.</span>
    </form>
  <?php elseif ((string)$call['status'] === 'empty' && $call['note']): ?>
    <p class="muted"><?= e((string)$call['note']) ?></p>
  <?php endif; ?>

  <?php if ((string)($call['summary'] ?? '') !== ''): ?>
    <h3>Notes</h3>
    <div class="card" style="white-space: pre-wrap"><?= e((string)$call['summary']) ?></div>
  <?php endif; ?>

  <h3>What was said</h3>
  <p class="muted">Each person's browser wrote down their own voice, so expect misheard words.
     Somebody muted, or on a browser that cannot do this (Firefox), is not in it. Somebody on
     speakers rather than headphones may have the others' words written down under their name.</p>
  <?php if (!$lines): ?>
    <p class="muted">Nothing was written down in this call.</p>
  <?php else: ?>
    <table class="review-table">
      <?php foreach ($lines as $l): ?>
        <tr><td class="muted" style="white-space: nowrap"><?= e($l['at']) ?></td>
            <td style="white-space: nowrap"><b><?= e($l['name']) ?></b></td>
            <td><?= e($l['body']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <p><a href="realm_call.php">All the calls you were in</a></p>
<?php else: ?>
  <p class="lead">Every call in the Realm is written down. When it ends, its to-dos and agreements are
     posted in the room's chat. The full transcript is here, for the people who were in the call<?= is_admin() ? ' (as an admin you see every call)' : '' ?>.</p>
  <?php if (!$calls): ?>
    <p class="muted">No calls yet.</p>
  <?php else: ?>
    <table class="review-table">
      <tr><th>When</th><th>Where</th><th>Lines</th><th>Notes</th></tr>
      <?php foreach ($calls as $c): ?>
        <tr><td><a href="realm_call.php?id=<?= (int)$c['id'] ?>"><?= e($when((string)$c['started_at'])) ?></a></td>
            <td><?= e(realm_scribe_place_name($pdo, (string)$c['place'])) ?></td>
            <td><?= (int)$c['n_lines'] ?></td>
            <td class="muted"><?= e($statusWords[(string)$c['status']] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
<?php endif; ?>

</div>
</body></html>
