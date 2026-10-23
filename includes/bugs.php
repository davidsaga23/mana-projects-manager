<?php
/**
 * Bug reports (migration 062): the Bug button on every screen, and bugs.php.
 *
 * David, 2026-10-22: "a bug button for Anima so people can log any bug, let
 * them attach images, videos or gifs, description, section. And from the user
 * you'll get type of user, user reporting the issue, date".
 *
 * The reporter fills in only what they know — the section, what went wrong, a
 * screenshot or a recording. Who they are, their role and when are taken from
 * the session, never typed, so a report can't be filed in someone else's name.
 */

// human_size() and the upload limits. Every page loads it with require_once,
// so this cannot declare it twice.
require_once __DIR__ . '/uploads.php';

/** Has migration 062 run? Read once per request. */
function bugs_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM bug_reports LIMIT 0');
        $ok = true;
    } catch (PDOException $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * The sections a bug can be filed under, in the order the app presents them.
 * Keyed by the script that draws each, so the form can preselect the screen the
 * reporter was on. Several scripts share a section where they are one screen
 * to the person using it (the Board's endpoints, a ticket and its list).
 */
function bug_sections(): array {
    return [
        'spell.php'         => 'Spell (the Board)',
        'mytasks.php'       => 'My tasks',
        'alltasks.php'      => 'Studio Tasks',
        'artists.php'       => 'Artists',
        'bookings.php'      => 'Bookings',
        'history_quotas.php'=> 'Historic quotas',
        'history_fees.php'  => 'Historic fees',
        'index.php'         => 'Projects list',
        'project.php'       => 'Project overview',
        'brief.php'         => 'Brief',
        'kickoff.php'       => 'Kick-off',
        'dashboard.php'     => 'Dashboard',
        'crew.php'          => 'Staffing',
        'person.php'        => 'Person page',
        'schedule.php'      => 'Schedule',
        'timeline.php'      => 'Timeline',
        'shots.php'         => 'Shots',
        'production.php'    => 'Production tracking',
        'tasks.php'         => 'Tasks / tickets',
        'team.php'          => 'Team performance',
        'wizard.php'        => 'Project setup',
        'import.php'        => 'Import',
        'finance.php'       => 'Finance',
        'users.php'         => 'Users',
        'login.php'         => 'Logging in',
        ''                  => 'Something else',
    ];
}

/** The section a script belongs to, for preselecting the form. */
function bug_section_for(string $script): string {
    $aliases = [
        'board.php' => 'spell.php', 'board_task.php' => 'spell.php',
        'ticket.php' => 'tasks.php', 'item.php' => 'production.php',
        'step.php' => 'production.php', 'finance_project.php' => 'finance.php',
        'timeline_gan.php' => 'timeline.php', 'timeline_import.php' => 'timeline.php',
        'hiring.php' => 'crew.php',
    ];
    $script = $aliases[$script] ?? $script;
    $all = bug_sections();
    return $all[$script] ?? $all[''];
}

/**
 * The largest single file, and the largest whole report, this server takes.
 *
 * Three limits apply and the smallest wins: the app's own upload_max_bytes,
 * PHP's upload_max_filesize, and post_max_size for everything together. A
 * report over post_max_size arrives with every field empty — description and
 * all — so the form checks these before sending rather than losing the text.
 */
function bug_upload_limits(): array {
    $toBytes = function (string $v): int {
        $v = trim($v);
        if ($v === '') return PHP_INT_MAX;
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        } ?: PHP_INT_MAX;
    };
    $post = $toBytes((string)ini_get('post_max_size'));
    $file = min((int)($GLOBALS['config']['upload_max_bytes'] ?? 25 * 1048576),
                $toBytes((string)ini_get('upload_max_filesize')), $post);
    return ['file' => $file, 'total' => $post];
}

/** "Open", "Fixed", "Won't fix". */
function bug_status_label(string $status): string {
    return ['open' => 'Open', 'fixed' => 'Fixed', 'wontfix' => "Won't fix"][$status] ?? $status;
}

/**
 * The floating button and its form, drawn by header.php on every screen.
 *
 * A plain multipart POST to bugs.php rather than fetch(): files are the point of
 * a bug report, and a form posts them with no JavaScript to get wrong. bugs.php
 * sends the reporter straight back to the page they were on, with a message.
 */
function render_bug_button(PDO $pdo): void {
    if (!is_logged_in() || !bugs_available($pdo)) return;
    // A role preview saves nothing (preview_blocks_saving), so the button would
    // be a form that always refuses. Viewing as a person does save, as the
    // admin, so the button stays.
    if (preview_blocks_saving()) return;

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $here = bug_section_for($script);
    $url = (string)($_SERVER['REQUEST_URI'] ?? '');
    $lim = bug_upload_limits();
    ?>
<button type="button" class="bug-fab" title="Report a bug"
        onclick="document.getElementById('bug-dialog').showModal()">🐞 <span>Report a bug</span></button>
<dialog id="bug-dialog" class="bug-dialog">
  <form method="post" action="bugs.php" enctype="multipart/form-data"
        data-max-file="<?= (int)$lim['file'] ?>" data-max-total="<?= (int)$lim['total'] ?>">
    <h3>Report a bug</h3>
    <p class="muted small">Your name, your role and today's date are added for you.</p>
    <input type="hidden" name="report_bug" value="1">
    <input type="hidden" name="page_url" value="<?= e($url) ?>">
    <label>Section
      <select name="section" required>
        <?php foreach (bug_sections() as $label): ?>
          <option<?= $label === $here ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>What went wrong? <span class="req-mark">*</span>
      <textarea name="description" rows="6" required maxlength="5000"
        placeholder="What you did, what you expected, and what happened instead."></textarea>
    </label>
    <label>Screenshots or recordings
      <input type="file" name="files[]" multiple
             accept="image/png,image/jpeg,image/gif,image/webp,video/mp4,video/quicktime,video/webm">
    </label>
    <p class="muted small">Images, GIFs or videos (PNG, JPG, GIF, WebP, MP4, MOV, WebM), up to
      <?= (int)($GLOBALS['config']['upload_max_files'] ?? 5) ?> files of <?= e(human_size((int)$lim['file'])) ?> each.</p>
    <p class="form-error" hidden></p>
    <div class="bug-actions">
      <button type="button" class="secondary" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit">Send report</button>
    </div>
  </form>
</dialog>
<script>
// Refuse an oversized file HERE, before sending. Past post_max_size the server
// receives nothing at all, so the description would be lost with the video.
document.querySelector('#bug-dialog form').addEventListener('submit', function (ev) {
  var f = this, err = f.querySelector('.form-error'), total = 0, bad = [];
  var maxFile = +f.dataset.maxFile, maxTotal = +f.dataset.maxTotal;
  Array.prototype.forEach.call(f.querySelector('input[type=file]').files, function (file) {
    total += file.size;
    if (file.size > maxFile) bad.push(file.name);
  });
  var mb = function (b) { return (b / 1048576).toFixed(1) + ' MB'; };
  var msg = bad.length ? bad.join(', ') + ' ' + (bad.length === 1 ? 'is' : 'are') + ' over the ' + mb(maxFile) + ' limit per file.'
          : total > maxTotal ? 'Together the files are ' + mb(total) + ', over the ' + mb(maxTotal) + ' limit.' : '';
  if (msg) { ev.preventDefault(); err.textContent = msg + ' Try a shorter clip or a screenshot.'; err.hidden = false; }
  else { f.querySelector('button[type=submit]').disabled = true; f.querySelector('button[type=submit]').textContent = 'Sending…'; }
});
</script>
    <?php
}
