<?php
// JSON endpoint behind the board's drag-and-drop and its quick-edit modal.
//
// Everything it can do is also possible through a normal form on ticket.php —
// this exists so moving a card feels like moving a card, not like submitting a
// page. Every response says what happened; a silent failure here would look
// exactly like a card that moved.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/statuses.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/tickets.php';
require_login_json();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

/** Load a card and the project it belongs to, or fail loudly. */
function api_load(PDO $pdo, int $id): array {
    $t = load_ticket($pdo, $id);
    if (!$t) throw new RuntimeException('That card no longer exists, reload the board.');
    return $t;
}

/**
 * A status must belong to the SAME project as the card.
 *
 * Statuses are project-scoped, so an id from another show would otherwise be
 * accepted and the card would land in a column that project cannot render —
 * invisible, which is the failure mode this codebase keeps designing against.
 */
function api_check_status(PDO $pdo, int $projectId, ?int $statusId): ?array {
    if ($statusId === null) return null;
    $map = statuses_by_id($pdo, $projectId);
    if (!isset($map[$statusId])) {
        throw new RuntimeException('That status does not belong to this project.');
    }
    return $map[$statusId];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('This endpoint only accepts POST.');
    }

    if ($action === 'move') {
        $id = (int)($_POST['id'] ?? 0);
        $statusId = !empty($_POST['status_id']) ? (int)$_POST['status_id'] : null;
        if (!$id || !$statusId) throw new RuntimeException('A card and a status are both required.');

        $t = api_load($pdo, $id);
        // The move goes into the card's own thread, same as an edit made on the
        // detail page — otherwise a card silently changes column overnight and
        // nobody can say who did it. Shared with the Studio Tasks dropdown.
        $status = set_ticket_status($pdo, $t, $statusId, $userId);

        echo json_encode([
            'ok' => true,
            'done' => ticket_status_is_done($status),
            'message' => ticket_ref($t) . ' moved to ' . $status['name'] . '.',
        ]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new RuntimeException('A card id is required.');

        $t = api_load($pdo, $id);
        $projectId = (int)$t['project_id'];

        $title = trim($_POST['title'] ?? '');
        if ($title === '') throw new RuntimeException('The title cannot be empty.');

        $statusId = !empty($_POST['status_id']) ? (int)$_POST['status_id'] : null;
        $status = api_check_status($pdo, $projectId, $statusId);

        $priority = in_array($_POST['priority'] ?? '', ticket_priorities(), true) ? $_POST['priority'] : 'medium';
        $crewId = !empty($_POST['assigned_crew_id']) ? (int)$_POST['assigned_crew_id'] : null;
        $due = !empty($_POST['due_date']) ? $_POST['due_date'] : null;

        // Same rule as the detail page: a ticket without an owner is a ticket
        // nobody is told about.
        if (($t['type'] ?? 'task') === 'ticket' && !$crewId) {
            throw new RuntimeException('A ticket has to stay assigned to somebody.');
        }

        $after = [
            'title'            => $title,
            'description'      => trim($_POST['description'] ?? '') ?: null,
            'status_id'        => $statusId,
            'priority'         => $priority,
            'assigned_crew_id' => $crewId,
            'due_date'         => $due,
        ];

        $pdo->prepare(
            'UPDATE tickets SET title = ?, description = ?, status_id = ?, priority = ?,
                    assigned_crew_id = ?, due_date = ? WHERE id = ?'
        )->execute([
            $after['title'], $after['description'], $after['status_id'],
            $after['priority'], $after['assigned_crew_id'], $after['due_date'], $id,
        ]);
        sync_ticket_completion($pdo, $id, $status);

        $statusNames = [];
        foreach (statuses_by_id($pdo, $projectId) as $sid => $s) $statusNames[$sid] = $s['name'];
        $crewNames = [];
        foreach (ticket_assignees($pdo, $projectId) as $c) $crewNames[(int)$c['id']] = $c['name'];
        if ($t['assigned_crew_id'] && !isset($crewNames[(int)$t['assigned_crew_id']])) {
            $crewNames[(int)$t['assigned_crew_id']] = $t['crew_name'] ?? ('crew #' . (int)$t['assigned_crew_id']);
        }
        $changes = describe_ticket_changes($t, $after, $statusNames, $crewNames);
        if ($changes) log_ticket_event($pdo, $id, $userId, implode("\n", $changes));

        $notes = ['Saved.'];
        if ($crewId && (int)($t['assigned_crew_id'] ?? 0) !== $crewId) {
            [, $m] = notify_assignee($pdo, $id, 'assigned');
            $notes[] = $m;
        }

        echo json_encode(['ok' => true, 'message' => implode(' ', $notes)]);
        exit;
    }

    throw new RuntimeException('Unknown action "' . $action . '".');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
