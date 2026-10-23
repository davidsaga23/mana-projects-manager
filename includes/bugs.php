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
 *
 * The second value says who can open it: null for everyone, 'admin', or
 * 'tab:<key>' for a project tab, answered by hidden_from_viewers() so this
 * list and the project tabs can never disagree about who sees what.
 */
function bug_section_rules(): array {
    return [
        'spell.php'          => ['Spell (the Board)', null],
        'mytasks.php'        => ['My tasks', null],
        'alltasks.php'       => ['Studio Tasks', null],
        'artists.php'        => ['Artists', null],
        'bookings.php'       => ['Bookings', null],
        'history_quotas.php' => ['Historic quotas', null],
        'history_fees.php'   => ['Historic fees', 'admin'],
        'index.php'          => ['Projects list', null],
        'project.php'        => ['Project overview', 'tab:overview'],
        'brief.php'          => ['Brief', 'tab:brief'],
        'kickoff.php'        => ['Kick-off', null],
        'dashboard.php'      => ['Dashboard', 'tab:dashboard'],
        'crew.php'           => ['Staffing', 'tab:crew'],
        'person.php'         => ['Person page', null],
        'schedule.php'       => ['Schedule', 'tab:schedule'],
        'timeline.php'       => ['Timeline', 'tab:timeline'],
        'shots.php'          => ['Shots', 'tab:shots'],
        'production.php'     => ['Production tracking', 'tab:production'],
        'tasks.php'          => ['Tasks / tickets', 'tab:tasks'],
        'team.php'           => ['Team performance', 'tab:team'],
        'wizard.php'         => ['Project setup', 'tab:setup'],
        'import.php'         => ['Import', 'tab:import'],
        'finance.php'        => ['Finance', 'admin'],
        'users.php'          => ['Users', 'admin'],
        'login.php'          => ['Logging in', null],
        ''                   => ['Something else', null],
    ];
}

/**
 * The sections THIS account may file under: script => label.
 *
 * David, 2026-10-22: "make sure the list only shows the sections that are
 * visible/allowed per user". An artist offered "Historic fees" learns a screen
 * exists that they cannot open, and a report filed against it is noise.
 * Answers for the role being worn, like the menu does, so an admin viewing as
 * an artist sees the artist's list.
 */
function bug_sections(): array {
    $out = [];
    foreach (bug_section_rules() as $script => [$label, $gate]) {
        if ($gate === 'admin' && !is_admin()) continue;
        if (is_string($gate) && str_starts_with($gate, 'tab:') && hidden_from_viewers(substr($gate, 4))) continue;
        $out[$script] = $label;
    }
    return $out;
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

/** Has migration 064 run (needs_info, flag_reason, admin_notes, fix_note)? */
function bugs_flags_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok === null) $ok = bugs_available($pdo) && column_exists($pdo, 'bug_reports', 'needs_info');
    return $ok;
}

/** Why a report with a video waits for David: said once, here. */
const BUG_VIDEO_FLAG = 'Has a video, and Claude cannot watch videos. Describe what happens in it.';

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
