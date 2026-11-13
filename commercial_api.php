<?php
// Commercial: every read and save the CRM screens make, as JSON.
//
// GET  ?action=load            everything the six views draw from
// POST {action: ..., ...}      one change; the screens reload the data after it
//
// The rules (stage history, proposal Sent → Proposal, quote Approved → deal
// value, Q-YYYY-NNN numbering, import grouping and duplicate skipping) are in
// includes/crm.php, not here and not in the browser.
//
// WHO: the "Commercial" switch (includes/features.php), which the access gate
// in require_login_json() reads for this script, and an account that can save.
// Administrators only to begin with (David, 2026-11-12).

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crm.php';
require_login_json();

header('Content-Type: application/json');
header('Cache-Control: no-store');

function crm_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!crm_allowed()) crm_out(['ok' => false, 'error' => "Commercial isn't open to your account."], 403);
if (!crm_available($pdo)) crm_out(['ok' => false, 'error' => 'Commercial needs database update 089. An administrator can apply it from Migrations.'], 503);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? '') !== 'load') throw new RuntimeException('Unknown request.');
        crm_out(['ok' => true] + crm_load($pdo));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Unknown request.');

    // Saves come from this app's own script only: it sends this header, which
    // a form on another site cannot, so a page elsewhere cannot make a signed-in
    // admin's browser change the CRM.
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'anima-crm') {
        throw new RuntimeException('That request did not come from the Commercial screen.');
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) throw new RuntimeException('The request was empty.');
    $action = (string)($in['action'] ?? '');
    $id = (int)($in['id'] ?? 0);

    switch ($action) {
        case 'lead_save':
            [$newId, $msg] = crm_save_lead($pdo, (array)($in['lead'] ?? []), $id);
            crm_out(['ok' => true, 'id' => (string)$newId, 'message' => $msg]);
        case 'lead_stage':
            crm_out(['ok' => true, 'message' => crm_move($pdo, $id, (string)($in['stage'] ?? ''))]);
        case 'lead_log':
            crm_log($pdo, $id, (string)($in['type'] ?? ''), (string)($in['text'] ?? ''), $in['contactId'] ?? null);
            crm_out(['ok' => true, 'message' => ((string)$in['type']) . ' logged']);
        case 'lead_snooze':
            crm_out(['ok' => true, 'message' => crm_snooze($pdo, $id, (int)($in['days'] ?? 0))]);
        case 'lead_value':
            $num = crm_str($in['quoteNumber'] ?? '', 20);
            $v = crm_money($in['value'] ?? null) ?? 0.0;
            crm_set_value($pdo, $id, $v, trim('Deal value set to ' . crm_usd($v) . ' from quote ' . $num));
            $c = crm_lead($pdo, $id);
            crm_out(['ok' => true, 'message' => "{$c['company']} deal value set to " . crm_usd($v)]);
        case 'leads_stage':
            crm_out(['ok' => true, 'message' => crm_bulk_stage($pdo, crm_ids($in['ids'] ?? []), (string)($in['stage'] ?? ''))]);
        case 'leads_mine':
            crm_out(['ok' => true, 'message' => crm_bulk_mine($pdo, crm_ids($in['ids'] ?? []))]);
        case 'leads_delete':
            $n = crm_delete_leads($pdo, crm_ids($in['ids'] ?? []));
            crm_out(['ok' => true, 'message' => $n === 1 ? 'Lead deleted' : "Deleted {$n} leads"]);
        case 'proposal_save':
            [$newId, $msgs] = crm_save_proposal($pdo, (array)($in['proposal'] ?? []), $id);
            crm_out(['ok' => true, 'id' => (string)$newId, 'message' => implode('. ', $msgs)]);
        case 'proposal_status':
            crm_out(['ok' => true, 'message' => implode('. ', crm_proposal_status($pdo, $id, (string)($in['status'] ?? '')))]);
        case 'proposal_delete':
            $pdo->prepare('DELETE FROM crm_proposals WHERE id = ?')->execute([$id]);
            crm_out(['ok' => true, 'message' => 'Proposal deleted']);
        case 'quote_save':
            [$newId, $msgs] = crm_save_quote($pdo, (array)($in['quote'] ?? []), $id);
            crm_out(['ok' => true, 'id' => (string)$newId, 'message' => implode('. ', $msgs)]);
        case 'quote_status':
            crm_out(['ok' => true, 'message' => implode('. ', crm_quote_status($pdo, $id, (string)($in['status'] ?? '')))]);
        case 'quote_delete':
            $pdo->prepare('DELETE FROM crm_quotes WHERE id = ?')->execute([$id]);
            crm_out(['ok' => true, 'message' => 'Quote deleted']);
        case 'import':
            $r = crm_import($pdo, (string)($in['name'] ?? ''), (array)($in['creates'] ?? []), (array)($in['adds'] ?? []), !empty($in['assignMe']));
            crm_out(['ok' => true] + $r);
    }
    throw new RuntimeException('Unknown request.');
} catch (Throwable $e) {
    // A RuntimeException is a sentence written for the reader; anything else is
    // a fault, said plainly rather than dressed up as advice.
    // (A PDOException is a RuntimeException too, so it is named first.)
    $msg = ($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'Couldn’t save: ' . $e->getMessage();
    crm_out(['ok' => false, 'error' => $msg], 400);
}
