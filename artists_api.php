<?php
// Saving one artist, as you leave the field.
//
// The grid used to be a form with a Save button, and that carried a hazard worth
// remembering: PHP's max_input_vars defaults to 1000, so a page of three hundred
// people at thirteen fields each posts five thousand values and PHP drops the
// tail SILENTLY. A row at a time cannot reach that limit at all — the ceiling
// stops being something to work around and becomes something that cannot occur.
//
// It also keeps the page still. A form post reloads, and a reload on a filtered,
// scrolled list of three hundred and seventy-seven people loses your place every
// time you correct an email.
//
// The form and its button are still there for a browser with no JavaScript, and
// still guarded by the sentinel. This is the path everybody actually takes.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hiring.php';
require_once __DIR__ . '/includes/artists.php';
require_once __DIR__ . '/includes/money.php';
// Refuses a POST from a view-only account and answers in JSON rather than
// redirecting to a login page a fetch() cannot follow.
require_login_json();

header('Content-Type: application/json');

function artists_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_GET['action'] ?? '';
if (!in_array($action, ['save_row', 'delete_person'], true)) artists_fail('Unknown action.');
if (!hiring_available($pdo)) artists_fail('The hiring database updates are not applied.');

// --- removing somebody from the book ---------------------------------------
//
// TWO CALLS, and the first one writes nothing. It comes back with the server's
// own count of what would go, which is what the page then puts in the question;
// asking "delete 3 records?" from a number the page guessed at would be a
// confirmation of the wrong thing. The second carries confirm=1.
if ($action === 'delete_person') {
    try {
        $info = artists_delete_person($pdo, (string)($_POST['name'] ?? ''),
                                      ($_POST['confirm'] ?? '') === '1');
    } catch (Throwable $e) {
        artists_fail($e->getMessage());
    }
    echo json_encode(['ok' => true] + $info);
    exit;
}

$hireId = (int)($_POST['id'] ?? 0);
$orig   = trim((string)($_POST['orig_name'] ?? ''));
$name   = trim((string)($_POST['candidate_name'] ?? ''));
if ($hireId <= 0 || $orig === '') artists_fail('No artist named.');
if ($name === '') {
    // Refused rather than saved as blank: the name is what every other record of
    // this person is matched on, so an empty one would orphan the rest of them.
    artists_fail('An artist needs a name.');
}

// The same optional columns the screen probes, so a database part-way through
// its migrations saves what it has instead of erroring on what it has not.
$extra = [];
foreach (['location', 'position', 'seniority', 'project_type', 'worked_with_us', 'rating',
          'portfolio_password', 'collaborator_type'] as $c) {
    if (column_exists($pdo, 'hires', $c)) $extra[] = $c;
}

try {
    // Across every record of this person, for the reason in includes/artists.php:
    // an email is a fact about them, not about one job.
    $rows = artists_save_person($pdo, $orig, $_POST, $extra);
} catch (Throwable $e) {
    artists_fail($e->getMessage());
}

// The rate goes through the one door, onto this person's most recent approach.
// Absent from the post means the column was not on screen — an editor's page
// does not draw it — which is not the same as being cleared.
$rate = null;
if (can_see_money() && array_key_exists('quoted_rate', $_POST)) {
    try {
        save_hire_quote($hireId, $name, null,
                        ['quoted_rate' => $_POST['quoted_rate'], 'rate_unit' => 'day',
                         'rate_currency' => 'USD'], current_user_display());
        $rate = trim((string)$_POST['quoted_rate']);
    } catch (Throwable $e) {
        // The person saved; only the money did not. Said plainly, because a
        // half-save reported as success is the worst of both.
        echo json_encode(['ok' => true, 'rows' => $rows, 'warning' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['ok' => true, 'rows' => $rows, 'name' => $name, 'rate' => $rate]);
