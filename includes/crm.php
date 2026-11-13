<?php
/**
 * COMMERCIAL: the studio's CRM (migration 085). Leads, their contacts and
 * activity, proposals and quotes, and every rule about how they move.
 *
 * David, 2026-11-12: "can we incorporate this to anima?", with a prototype he
 * built as a claude.ai artifact and a handoff spec (HANDOFF.md, sections 1–4).
 * The prototype's screens are ported nearly as they were into
 * assets/commercial.js; what lived in the browser there and must not live in
 * the browser here, the RULES, is in this file, so a second tab, a slow
 * connection or a hand-made request cannot skip one:
 *
 *   - a stage change appends a "Moved from X to Y" Stage activity and sets or
 *     clears closed_at (crm_set_stage());
 *   - logging an Email, Call, Networking or Meeting sets the lead's
 *     last_contact_at, and the contact's when one is picked (crm_log());
 *   - a proposal moving to Sent moves a Contacted or Interested lead to
 *     Proposal; Sent, Accepted and Declined are logged on the lead
 *     (crm_proposal_effects());
 *   - a quote moving to Approved sets the lead's value to the quote total;
 *     Sent and Rejected are logged (crm_quote_effects());
 *   - quotes are numbered Q-YYYY-NNN here, never in the browser, so two people
 *     creating a quote at once cannot both get Q-2026-004 (crm_next_quote_number());
 *   - an import groups by company, adds to an existing company (same name,
 *     any case) rather than making a second lead, and skips a contact already
 *     saved: same email, or same name when there is no email (crm_import()).
 *
 * ⚠ SALES, NEVER COST. Anima tracks budget against cost and never profit
 * (CLAUDE.md). Everything here is what the studio is SELLING. Nothing in this
 * file reads a show's budget or the finance database, and nothing there reads
 * this. Keep it that way: a "won" figure next to a show's cost is a profit
 * figure, which is the one thing the money rules say Anima does not compute.
 *
 * WHO: the "Commercial" access switch (includes/features.php), administrators
 * only to begin with (David, 2026-11-12). Everybody who can open it can edit.
 * Every amount is USD; the UI is English.
 */

const CRM_STAGES = [
    'contacted'   => ['label' => 'Contacted',   'p' => 0.05],
    'interested'  => ['label' => 'Interested',  'p' => 0.25],
    'proposal'    => ['label' => 'Proposal',    'p' => 0.50],
    'negotiation' => ['label' => 'Negotiation', 'p' => 0.75],
    'won'         => ['label' => 'Won',         'p' => 1.00],
    'lost'        => ['label' => 'Lost',        'p' => 0.00],
];
const CRM_P_STATUS = ['todo' => 'To do', 'progress' => 'In progress', 'review' => 'Internal review',
                      'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined'];
const CRM_Q_STATUS = ['draft' => 'Draft', 'sent' => 'Sent', 'approved' => 'Approved', 'rejected' => 'Rejected'];
const CRM_ACT_TYPES = ['Email', 'Call', 'Networking', 'Meeting', 'Note'];
const CRM_CHANNELS = ['Email', 'Phone', 'Networking', 'LinkedIn', 'Referral', 'Other'];
/** How many activities a lead shows, newest first (the prototype's cap). Older ones stay in the table. */
const CRM_MAX_ACTIVITIES = 300;

/** Have the tables arrived (migration 085)? Never throws. */
function crm_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        foreach (['crm_leads', 'crm_contacts', 'crm_activities', 'crm_proposals', 'crm_quotes', 'crm_quote_items'] as $t) {
            $pdo->query("SELECT 1 FROM {$t} LIMIT 1");
        }
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** May the current account open Commercial? The switch, and an account that can save at all. */
function crm_allowed(): bool {
    return function_exists('feature_on') && feature_on('commercial') && can_edit();
}

// ---- small helpers -----------------------------------------------------------

function crm_now(): string { return date('Y-m-d H:i:s'); }

/** A stored DATETIME as ISO 8601 for the browser, or null. */
function crm_iso($dt): ?string {
    if ($dt === null || $dt === '') return null;
    $t = strtotime((string)$dt);
    return $t ? date('c', $t) : null;
}

function crm_str($v, int $max): string {
    $s = trim(str_replace("\0", '', (string)($v ?? '')));
    return mb_substr($s, 0, $max);
}

/** Free text that keeps its line breaks (notes). */
function crm_text($v, int $max = 20000): string {
    $s = rtrim(str_replace("\0", '', (string)($v ?? '')));
    return mb_substr($s, 0, $max);
}

/** 'YYYY-MM-DD' or null. */
function crm_date($v): ?string {
    $s = trim((string)($v ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
}

/** A non-negative USD amount, or null for "not set". */
function crm_money($v): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return round(max(0.0, (float)$v), 2);
}

function crm_stage($v, string $fallback = 'contacted'): string {
    $s = (string)$v;
    return isset(CRM_STAGES[$s]) ? $s : $fallback;
}

function crm_stage_label(string $k): string { return CRM_STAGES[$k]['label'] ?? $k; }

function crm_usd(float $n): string { return '$' . number_format(round($n)); }

/** A deck link: http or https only, as the prototype's safeUrl(). '' when it is not one. */
function crm_safe_url($u): string {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
    if (!filter_var($u, FILTER_VALIDATE_URL)) return '';
    $scheme = strtolower((string)parse_url($u, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? mb_substr($u, 0, 1000) : '';
}

/** The duplicate test for a contact: same email, or same name when there is no email. */
function crm_contact_key(array $c): string {
    $email = mb_strtolower(trim((string)($c['email'] ?? '')));
    if ($email !== '') return 'e:' . $email;
    $name = mb_strtolower(trim((string)($c['name'] ?? '')));
    return $name !== '' ? 'n:' . $name : '';
}

function crm_me(): ?int {
    $id = (int)($_SESSION['user_id'] ?? 0);
    return $id > 0 ? $id : null;
}

// ---- people --------------------------------------------------------------------

/**
 * Every login, for names and pictures on owners and activity, and which of them
 * may be given a lead: whoever has the Commercial switch and can save. Small
 * table; read once per load.
 */
function crm_people(PDO $pdo): array {
    $hasCrew = column_exists($pdo, 'users', 'crew_id');
    $hasPhoto = $hasCrew && column_exists($pdo, 'crew', 'photo_stored');
    $sql = 'SELECT u.id, u.username, u.display_name, u.role, u.active'
         . ($hasCrew ? ', u.crew_id' : ', NULL AS crew_id')
         . ($hasPhoto ? ', c.photo_stored' : ', NULL AS photo_stored')
         . ' FROM users u' . ($hasPhoto ? ' LEFT JOIN crew c ON c.id = u.crew_id' : '');
    $people = []; $owners = [];
    foreach ($pdo->query($sql)->fetchAll() as $u) {
        $id = (int)$u['id'];
        $people[(string)$id] = [
            'name'   => (string)($u['display_name'] ?: $u['username']),
            'avatar' => ($u['photo_stored'] ?? '') !== '' && $u['crew_id'] ? 'avatar.php?crew=' . (int)$u['crew_id'] : '',
        ];
        $role = (string)$u['role'];
        if ((int)$u['active'] === 1 && in_array($role, ['admin', 'editor'], true)
            && (!function_exists('feature_for_user') || feature_for_user('commercial', $id, $role))) {
            $owners[] = (string)$id;
        }
    }
    return ['people' => $people, 'owners' => $owners];
}

/** An owner id that may hold a lead, or null. */
function crm_owner(PDO $pdo, $v): ?int {
    $id = (int)($v ?? 0);
    if ($id <= 0) return null;
    static $owners = null;
    if ($owners === null) $owners = array_flip(crm_people($pdo)['owners']);
    return isset($owners[(string)$id]) ? $id : null;
}

// ---- reading -------------------------------------------------------------------

/** Everything the screens need, in the shapes the prototype used. */
function crm_load(PDO $pdo): array {
    $contacts = [];
    foreach ($pdo->query('SELECT * FROM crm_contacts ORDER BY lead_id, position, id')->fetchAll() as $c) {
        $contacts[(int)$c['lead_id']][] = [
            'id' => (string)$c['id'], 'name' => (string)$c['name'], 'title' => (string)$c['title'],
            'email' => (string)$c['email'], 'phone' => (string)$c['phone'], 'channel' => (string)$c['channel'],
            'contactedAt' => (string)($c['contacted_at'] ?? ''), 'lastContactAt' => crm_iso($c['last_contact_at']),
        ];
    }
    $acts = [];
    foreach ($pdo->query('SELECT * FROM crm_activities ORDER BY lead_id, at DESC, id DESC')->fetchAll() as $a) {
        $lid = (int)$a['lead_id'];
        if (isset($acts[$lid]) && count($acts[$lid]) >= CRM_MAX_ACTIVITIES) continue;
        $acts[$lid][] = [
            'id' => (string)$a['id'], 'at' => crm_iso($a['at']), 'by' => $a['by_user'] ? (string)$a['by_user'] : null,
            'type' => (string)$a['type'], 'text' => (string)$a['text'],
            'contactId' => $a['contact_id'] ? (string)$a['contact_id'] : null,
        ];
    }
    $leads = [];
    foreach ($pdo->query('SELECT * FROM crm_leads ORDER BY id')->fetchAll() as $l) {
        $id = (int)$l['id'];
        $tags = json_decode((string)($l['tags'] ?? ''), true);
        $leads[] = [
            'id' => (string)$id, 'company' => (string)$l['company'], 'website' => (string)$l['website'],
            'contacts' => $contacts[$id] ?? [], 'stage' => crm_stage($l['stage']),
            'value' => $l['value'] === null ? '' : (float)$l['value'],
            'projectType' => (string)$l['project_type'], 'source' => (string)$l['source'],
            'ownerId' => $l['owner_id'] ? (string)$l['owner_id'] : null, 'nextStep' => (string)$l['next_step'],
            'followUp' => (string)($l['follow_up'] ?? ''), 'tags' => is_array($tags) ? array_values($tags) : [],
            'notes' => (string)($l['notes'] ?? ''), 'lostReason' => (string)$l['lost_reason'],
            'activities' => $acts[$id] ?? [], 'lastContactAt' => crm_iso($l['last_contact_at']),
            'closedAt' => crm_iso($l['closed_at']), 'createdAt' => crm_iso($l['created_at']),
            'updatedAt' => crm_iso($l['updated_at']), 'createdBy' => $l['created_by'] ? (string)$l['created_by'] : null,
        ];
    }
    $proposals = [];
    foreach ($pdo->query('SELECT * FROM crm_proposals ORDER BY id')->fetchAll() as $p) {
        $proposals[] = [
            'id' => (string)$p['id'], 'title' => (string)$p['title'], 'leadId' => $p['lead_id'] ? (string)$p['lead_id'] : '',
            'status' => isset(CRM_P_STATUS[$p['status']]) ? (string)$p['status'] : 'todo',
            'dueDate' => (string)($p['due_date'] ?? ''), 'deckUrl' => (string)$p['deck_url'],
            'ownerId' => $p['owner_id'] ? (string)$p['owner_id'] : null, 'notes' => (string)($p['notes'] ?? ''),
            'createdAt' => crm_iso($p['created_at']), 'updatedAt' => crm_iso($p['updated_at']),
        ];
    }
    $items = [];
    foreach ($pdo->query('SELECT * FROM crm_quote_items ORDER BY quote_id, position, id')->fetchAll() as $i) {
        $items[(int)$i['quote_id']][] = ['id' => (string)$i['id'], 'desc' => (string)$i['description'],
                                         'qty' => (float)$i['qty'], 'rate' => (float)$i['rate']];
    }
    $quotes = [];
    foreach ($pdo->query('SELECT * FROM crm_quotes ORDER BY id')->fetchAll() as $q) {
        $quotes[] = [
            'id' => (string)$q['id'], 'number' => (string)$q['number'], 'title' => (string)$q['title'],
            'leadId' => $q['lead_id'] ? (string)$q['lead_id'] : '', 'proposalId' => $q['proposal_id'] ? (string)$q['proposal_id'] : '',
            'status' => isset(CRM_Q_STATUS[$q['status']]) ? (string)$q['status'] : 'draft',
            'validUntil' => (string)($q['valid_until'] ?? ''), 'items' => $items[(int)$q['id']] ?? [],
            'discountPct' => (float)$q['discount_pct'], 'taxPct' => (float)$q['tax_pct'], 'notes' => (string)($q['notes'] ?? ''),
            'createdAt' => crm_iso($q['created_at']), 'updatedAt' => crm_iso($q['updated_at']),
        ];
    }
    return ['leads' => $leads, 'proposals' => $proposals, 'quotes' => $quotes] + crm_people($pdo);
}

function crm_lead(PDO $pdo, int $id): array {
    $q = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
    $q->execute([$id]);
    $l = $q->fetch();
    if (!$l) throw new RuntimeException('That lead no longer exists. Reload the page.');
    return $l;
}

// ---- lead rules ------------------------------------------------------------------

function crm_add_activity(PDO $pdo, int $leadId, string $type, string $text, ?int $contactId = null, ?string $at = null): void {
    $pdo->prepare('INSERT INTO crm_activities (lead_id, at, by_user, type, text, contact_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$leadId, $at ?? crm_now(), crm_me(), $type, crm_text($text, 5000), $contactId]);
}

function crm_touch(PDO $pdo, int $leadId): void {
    $pdo->prepare('UPDATE crm_leads SET updated_at = ? WHERE id = ?')->execute([crm_now(), $leadId]);
}

/**
 * THE stage change. Every way a lead moves (the Status select, the drawer, a
 * bulk move, a proposal being sent) goes through here, so each one logs
 * "Moved from X to Y" and keeps closed_at right. Returns false when the lead
 * was already there.
 */
function crm_set_stage(PDO $pdo, array $lead, string $stage): bool {
    $stage = crm_stage($stage, '');
    if ($stage === '') throw new RuntimeException('That is not a status a lead can have.');
    $from = crm_stage($lead['stage']);
    if ($from === $stage) return false;
    $now = crm_now();
    $closed = in_array($stage, ['won', 'lost'], true) ? $now : null;
    $pdo->prepare('UPDATE crm_leads SET stage = ?, closed_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$stage, $closed, $now, (int)$lead['id']]);
    crm_add_activity($pdo, (int)$lead['id'], 'Stage', 'Moved from ' . crm_stage_label($from) . ' to ' . crm_stage_label($stage), null, $now);
    return true;
}

/** The lead's fields from a posted form, validated. Contacts are handled separately. */
function crm_lead_input(PDO $pdo, array $in): array {
    $company = crm_str($in['company'] ?? '', 255);
    if ($company === '') throw new RuntimeException('Add a client name to save the lead.');
    $tags = [];
    foreach ((array)($in['tags'] ?? []) as $t) {
        $t = crm_str($t, 60);
        if ($t !== '' && !in_array($t, $tags, true)) $tags[] = $t;
    }
    return [
        'company' => $company,
        'website' => crm_str($in['website'] ?? '', 255),
        'stage' => crm_stage($in['stage'] ?? 'contacted'),
        'value' => crm_money($in['value'] ?? null),
        'project_type' => crm_str($in['projectType'] ?? '', 100),
        'source' => crm_str($in['source'] ?? '', 60),
        'owner_id' => crm_owner($pdo, $in['ownerId'] ?? null),
        'next_step' => crm_str($in['nextStep'] ?? '', 500),
        'follow_up' => crm_date($in['followUp'] ?? ''),
        'tags' => json_encode(array_slice($tags, 0, 40), JSON_UNESCAPED_UNICODE),
        'notes' => crm_text($in['notes'] ?? ''),
        'lost_reason' => crm_str($in['lostReason'] ?? '', 500),
    ];
}

/** One posted contact, cleaned. Empty when it has nothing worth keeping. */
function crm_contact_input(array $c): array {
    $out = [
        'id' => (string)($c['id'] ?? ''),
        'name' => crm_str($c['name'] ?? '', 255), 'title' => crm_str($c['title'] ?? '', 255),
        'email' => crm_str($c['email'] ?? '', 255), 'phone' => crm_str($c['phone'] ?? '', 80),
        'channel' => in_array($c['channel'] ?? '', CRM_CHANNELS, true) ? (string)$c['channel'] : 'Email',
        'contacted_at' => crm_date($c['contactedAt'] ?? ''),
    ];
    return ($out['name'] === '' && $out['email'] === '' && $out['phone'] === '' && $out['title'] === '') ? [] : $out;
}

/**
 * Make a lead's contacts the posted list, in its order (the first is the
 * primary). A contact already on the lead is updated in place, so its
 * last_contact_at and the activity that names it survive an edit; one left
 * out is removed; anything else is added.
 */
function crm_sync_contacts(PDO $pdo, int $leadId, array $posted): void {
    $have = $pdo->prepare('SELECT id FROM crm_contacts WHERE lead_id = ?');
    $have->execute([$leadId]);
    $existing = array_flip(array_map('strval', $have->fetchAll(PDO::FETCH_COLUMN)));
    $keep = [];
    $pos = 0;
    $upd = $pdo->prepare('UPDATE crm_contacts SET position = ?, name = ?, title = ?, email = ?, phone = ?, channel = ?, contacted_at = ? WHERE id = ? AND lead_id = ?');
    $ins = $pdo->prepare('INSERT INTO crm_contacts (lead_id, position, name, title, email, phone, channel, contacted_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($posted as $raw) {
        $c = crm_contact_input((array)$raw);
        if (!$c) continue;
        if (isset($existing[$c['id']])) {
            $upd->execute([$pos, $c['name'], $c['title'], $c['email'], $c['phone'], $c['channel'], $c['contacted_at'], (int)$c['id'], $leadId]);
            $keep[$c['id']] = true;
        } else {
            $ins->execute([$leadId, $pos, $c['name'], $c['title'], $c['email'], $c['phone'], $c['channel'], $c['contacted_at'], crm_now()]);
        }
        $pos++;
    }
    $del = $pdo->prepare('DELETE FROM crm_contacts WHERE id = ? AND lead_id = ?');
    foreach (array_keys($existing) as $id) {
        if (!isset($keep[(string)$id])) $del->execute([(int)$id, $leadId]);
    }
}

/** Create or update a lead from the drawer. Returns [id, message]. */
function crm_save_lead(PDO $pdo, array $in, int $id = 0): array {
    $f = crm_lead_input($pdo, $in);
    $now = crm_now();
    $pdo->beginTransaction();
    try {
        if ($id <= 0) {
            $closed = in_array($f['stage'], ['won', 'lost'], true) ? $now : null;
            $pdo->prepare('INSERT INTO crm_leads (company, website, stage, value, project_type, source, owner_id, next_step, follow_up, tags, notes, lost_reason, closed_at, created_at, updated_at, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$f['company'], $f['website'], $f['stage'], $f['value'], $f['project_type'], $f['source'], $f['owner_id'],
                           $f['next_step'], $f['follow_up'], $f['tags'], $f['notes'], $f['lost_reason'], $closed, $now, $now, crm_me()]);
            $id = (int)$pdo->lastInsertId();
            crm_sync_contacts($pdo, $id, (array)($in['contacts'] ?? []));
            crm_add_activity($pdo, $id, 'Stage', 'Lead added as ' . crm_stage_label($f['stage']), null, $now);
            $msg = $f['stage'] === 'contacted' ? "Added {$f['company']} to Prospects" : "Added {$f['company']} to the Pipeline";
        } else {
            $lead = crm_lead($pdo, $id);
            // The owner the lead already has stays valid even if that person
            // has since lost the switch: saving a note must not unassign it.
            $owner = (int)($in['ownerId'] ?? 0);
            if ($owner > 0 && $owner === (int)$lead['owner_id']) $f['owner_id'] = $owner;
            $pdo->prepare('UPDATE crm_leads SET company = ?, website = ?, value = ?, project_type = ?, source = ?, owner_id = ?, next_step = ?, follow_up = ?, tags = ?, notes = ?, lost_reason = ?, updated_at = ? WHERE id = ?')
                ->execute([$f['company'], $f['website'], $f['value'], $f['project_type'], $f['source'], $f['owner_id'],
                           $f['next_step'], $f['follow_up'], $f['tags'], $f['notes'], $f['lost_reason'], $now, $id]);
            crm_sync_contacts($pdo, $id, (array)($in['contacts'] ?? []));
            crm_set_stage($pdo, $lead, $f['stage']);
            $msg = ($lead['stage'] === 'contacted' && $f['stage'] !== 'contacted') ? "{$f['company']} moved to the Pipeline" : 'Saved';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$id, $msg];
}

/** The Status select in a table row. */
function crm_move(PDO $pdo, int $id, string $stage): string {
    $lead = crm_lead($pdo, $id);
    $from = $lead['stage'];
    if (!crm_set_stage($pdo, $lead, $stage)) return '';
    if ($from === 'contacted') return "{$lead['company']} moved to the Pipeline (" . crm_stage_label($stage) . ')';
    if ($stage === 'contacted') return "{$lead['company']} moved back to Prospects";
    return "{$lead['company']} → " . crm_stage_label($stage);
}

/** Log an email, call, networking, meeting or note on a lead. */
function crm_log(PDO $pdo, int $leadId, string $type, string $text, $contactId): void {
    if (!in_array($type, CRM_ACT_TYPES, true)) throw new RuntimeException('That is not a kind of activity.');
    $text = crm_text($text, 5000);
    if (trim($text) === '') throw new RuntimeException('Write what happened first.');
    crm_lead($pdo, $leadId);
    $cid = (int)($contactId ?? 0);
    if ($cid > 0) {
        $own = $pdo->prepare('SELECT id FROM crm_contacts WHERE id = ? AND lead_id = ?');
        $own->execute([$cid, $leadId]);
        if (!$own->fetchColumn()) $cid = 0;
    }
    $now = crm_now();
    crm_add_activity($pdo, $leadId, $type, $text, $cid ?: null, $now);
    if ($type !== 'Note') {
        $pdo->prepare('UPDATE crm_leads SET last_contact_at = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $leadId]);
        if ($cid) $pdo->prepare('UPDATE crm_contacts SET last_contact_at = ? WHERE id = ?')->execute([$now, $cid]);
    } else {
        crm_touch($pdo, $leadId);
    }
}

/** +1 day / +1 week on Follow-ups: from the date when it is still ahead, else from today. */
function crm_snooze(PDO $pdo, int $id, int $days): string {
    if (!in_array($days, [1, 7], true)) throw new RuntimeException('Push a follow-up by a day or a week.');
    $lead = crm_lead($pdo, $id);
    $today = date('Y-m-d');
    $base = ($lead['follow_up'] && $lead['follow_up'] > $today) ? (string)$lead['follow_up'] : $today;
    $new = date('Y-m-d', strtotime($base . " +{$days} days"));
    $pdo->prepare('UPDATE crm_leads SET follow_up = ?, updated_at = ? WHERE id = ?')->execute([$new, crm_now(), $id]);
    return 'Follow-up moved to ' . date('M j', strtotime($new));
}

/** The ids posted for a bulk action, as ints. */
function crm_ids($v): array {
    return array_values(array_unique(array_filter(array_map('intval', (array)$v), fn($i) => $i > 0)));
}

function crm_bulk_stage(PDO $pdo, array $ids, string $stage): string {
    $stage = crm_stage($stage, '');
    if ($stage === '') throw new RuntimeException('That is not a status a lead can have.');
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            $q = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
            $q->execute([$id]);
            if (($lead = $q->fetch()) && crm_set_stage($pdo, $lead, $stage)) $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return 'Moved to ' . crm_stage_label($stage) . ": {$n} lead" . ($n === 1 ? '' : 's');
}

function crm_bulk_mine(PDO $pdo, array $ids): string {
    $me = crm_owner($pdo, crm_me());
    if (!$me) throw new RuntimeException('Your account cannot hold leads.');
    $n = 0;
    $q = $pdo->prepare('UPDATE crm_leads SET owner_id = ?, updated_at = ? WHERE id = ? AND (owner_id IS NULL OR owner_id <> ?)');
    foreach ($ids as $id) { $q->execute([$me, crm_now(), $id, $me]); $n += $q->rowCount(); }
    return "Assigned to you: {$n} lead" . ($n === 1 ? '' : 's');
}

/** Delete leads with their contacts and history. Proposals and quotes stay, as "Deleted client". */
function crm_delete_leads(PDO $pdo, array $ids): int {
    $n = 0;
    $q = $pdo->prepare('DELETE FROM crm_leads WHERE id = ?');
    foreach ($ids as $id) { $q->execute([$id]); $n += $q->rowCount(); }
    return $n;
}

/** Set a lead's deal value and log why (a quote approved, or the manual button). */
function crm_set_value(PDO $pdo, int $leadId, float $value, string $why): void {
    crm_lead($pdo, $leadId);
    $now = crm_now();
    $pdo->prepare('UPDATE crm_leads SET value = ?, updated_at = ? WHERE id = ?')->execute([round(max(0, $value)), $now, $leadId]);
    crm_add_activity($pdo, $leadId, 'Note', $why, null, $now);
}

// ---- proposals ----------------------------------------------------------------------

/**
 * What a proposal's new status does to its lead. Sent, Accepted and Declined
 * are each logged; Sent also moves a Contacted or Interested lead to Proposal.
 * Returns a message when the lead moved.
 */
function crm_proposal_effects(PDO $pdo, int $leadId, string $title, string $status): string {
    $word = ['sent' => 'sent', 'accepted' => 'accepted', 'declined' => 'declined'][$status] ?? null;
    if (!$leadId || !$word) return '';
    $q = $pdo->prepare('SELECT * FROM crm_leads WHERE id = ?');
    $q->execute([$leadId]);
    $lead = $q->fetch();
    if (!$lead) return '';
    crm_add_activity($pdo, $leadId, 'Note', "Proposal “{$title}” {$word}");
    crm_touch($pdo, $leadId);
    if ($status === 'sent' && in_array($lead['stage'], ['contacted', 'interested'], true)) {
        crm_set_stage($pdo, $lead, 'proposal');
        return "{$lead['company']} moved to the Proposal stage";
    }
    return '';
}

function crm_lead_or_null(PDO $pdo, $v): ?int {
    $id = (int)($v ?? 0);
    if ($id <= 0) return null;
    $q = $pdo->prepare('SELECT id FROM crm_leads WHERE id = ?');
    $q->execute([$id]);
    return $q->fetchColumn() ? $id : null;
}

/** Create or update a proposal. Returns [id, messages]. */
function crm_save_proposal(PDO $pdo, array $in, int $id = 0): array {
    $title = crm_str($in['title'] ?? '', 255);
    if ($title === '') throw new RuntimeException('Give the proposal a name.');
    $deckIn = trim((string)($in['deckUrl'] ?? ''));
    $deck = crm_safe_url($deckIn);
    if ($deckIn !== '' && $deck === '') throw new RuntimeException('That deck link doesn’t look like a web address.');
    $status = isset(CRM_P_STATUS[$in['status'] ?? '']) ? (string)$in['status'] : 'todo';
    $leadId = crm_lead_or_null($pdo, $in['leadId'] ?? null);
    $owner = crm_owner($pdo, $in['ownerId'] ?? null);
    $now = crm_now();
    $pdo->beginTransaction();
    try {
        $prevStatus = null;
        if ($id <= 0) {
            $pdo->prepare('INSERT INTO crm_proposals (title, lead_id, status, due_date, deck_url, owner_id, notes, created_at, updated_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$title, $leadId, $status, crm_date($in['dueDate'] ?? ''), $deck, $owner, crm_text($in['notes'] ?? ''), $now, $now, crm_me()]);
            $id = (int)$pdo->lastInsertId();
            $msgs = ['Proposal added'];
        } else {
            $q = $pdo->prepare('SELECT * FROM crm_proposals WHERE id = ?');
            $q->execute([$id]);
            $prev = $q->fetch();
            if (!$prev) throw new RuntimeException('That proposal no longer exists. Reload the page.');
            $prevStatus = (string)$prev['status'];
            if ((int)($in['ownerId'] ?? 0) > 0 && (int)$in['ownerId'] === (int)$prev['owner_id']) $owner = (int)$prev['owner_id'];
            $pdo->prepare('UPDATE crm_proposals SET title = ?, lead_id = ?, status = ?, due_date = ?, deck_url = ?, owner_id = ?, notes = ?, updated_at = ? WHERE id = ?')
                ->execute([$title, $leadId, $status, crm_date($in['dueDate'] ?? ''), $deck, $owner, crm_text($in['notes'] ?? ''), $now, $id]);
            $msgs = ['Saved'];
        }
        if ($prevStatus !== $status && ($m = crm_proposal_effects($pdo, (int)$leadId, $title, $status)) !== '') $msgs[] = $m;
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return [$id, $msgs];
}

/** A card dropped on another column of the board. */
function crm_proposal_status(PDO $pdo, int $id, string $status): array {
    if (!isset(CRM_P_STATUS[$status])) throw new RuntimeException('That is not a proposal status.');
    $q = $pdo->prepare('SELECT * FROM crm_proposals WHERE id = ?');
    $q->execute([$id]);
    $p = $q->fetch();
    if (!$p) throw new RuntimeException('That proposal no longer exists. Reload the page.');
    if ($p['status'] === $status) return [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE crm_proposals SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, crm_now(), $id]);
        $msgs = [$p['title'] . ' → ' . CRM_P_STATUS[$status]];
        if (($m = crm_proposal_effects($pdo, (int)$p['lead_id'], (string)$p['title'], $status)) !== '') $msgs[] = $m;
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $msgs;
}

// ---- quotes -------------------------------------------------------------------------

/**
 * Total = subtotal − discount + tax, where subtotal = Σ qty × rate,
 * discount = subtotal × discount% / 100 and tax = (subtotal − discount) × tax% / 100.
 * Exactly the prototype's quoteTotals(), which the browser also runs.
 */
function crm_quote_totals(array $items, float $discPct, float $taxPct): array {
    $sub = 0.0;
    foreach ($items as $i) $sub += (float)($i['qty'] ?? 0) * (float)($i['rate'] ?? 0);
    $disc = $sub * $discPct / 100;
    $tax = ($sub - $disc) * $taxPct / 100;
    return ['sub' => $sub, 'disc' => $disc, 'tax' => $tax, 'total' => $sub - $disc + $tax];
}

/** The quote's stored lines and percentages, totalled. */
function crm_stored_total(PDO $pdo, array $quote): float {
    $q = $pdo->prepare('SELECT qty, rate FROM crm_quote_items WHERE quote_id = ?');
    $q->execute([(int)$quote['id']]);
    return crm_quote_totals($q->fetchAll(), (float)$quote['discount_pct'], (float)$quote['tax_pct'])['total'];
}

/** Q-YYYY-NNN: the next number for the current year. Call inside the transaction that inserts it. */
function crm_next_quote_number(PDO $pdo): string {
    $y = date('Y');
    $q = $pdo->prepare('SELECT number FROM crm_quotes WHERE number LIKE ? FOR UPDATE');
    $q->execute(["Q-{$y}-%"]);
    $max = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n) {
        if (preg_match('/^Q-' . $y . '-(\d+)$/', (string)$n, $m)) $max = max($max, (int)$m[1]);
    }
    return sprintf('Q-%s-%03d', $y, $max + 1);
}

/** What a quote's new status does to its lead. Approved sets the deal value. */
function crm_quote_effects(PDO $pdo, int $leadId, string $number, string $status, float $total): string {
    if (!$leadId) return '';
    $q = $pdo->prepare('SELECT company FROM crm_leads WHERE id = ?');
    $q->execute([$leadId]);
    $company = $q->fetchColumn();
    if ($company === false) return '';
    if ($status === 'approved') {
        crm_set_value($pdo, $leadId, $total, "Quote {$number} approved: " . crm_usd($total) . '. Deal value updated.');
        return "{$company} deal value set to " . crm_usd($total);
    }
    if ($status === 'sent' || $status === 'rejected') {
        crm_add_activity($pdo, $leadId, 'Note', "Quote {$number} {$status}: " . crm_usd($total));
        crm_touch($pdo, $leadId);
    }
    return '';
}

/** Posted line items, cleaned: a line with no description and no rate is dropped. */
function crm_items_input($raw): array {
    $out = [];
    foreach ((array)$raw as $i) {
        $i = (array)$i;
        $desc = crm_str($i['desc'] ?? '', 500);
        $rate = is_numeric($i['rate'] ?? null) ? max(0.0, (float)$i['rate']) : 0.0;
        if ($desc === '' && !$rate) continue;
        $qty = is_numeric($i['qty'] ?? null) ? max(0.0, (float)$i['qty']) : 0.0;
        $out[] = ['desc' => $desc, 'qty' => round($qty, 3), 'rate' => round($rate, 2)];
    }
    return array_slice($out, 0, 200);
}

function crm_pct($v): float {
    return is_numeric($v) ? min(100.0, max(0.0, round((float)$v, 2))) : 0.0;
}

/** Create or update a quote. Returns [id, messages]. */
function crm_save_quote(PDO $pdo, array $in, int $id = 0): array {
    $items = crm_items_input($in['items'] ?? []);
    if (!$items) throw new RuntimeException('Add at least one line item.');
    $status = isset(CRM_Q_STATUS[$in['status'] ?? '']) ? (string)$in['status'] : 'draft';
    $leadId = crm_lead_or_null($pdo, $in['leadId'] ?? null);
    $propId = (int)($in['proposalId'] ?? 0);
    if ($propId > 0) {
        $q = $pdo->prepare('SELECT id FROM crm_proposals WHERE id = ?');
        $q->execute([$propId]);
        if (!$q->fetchColumn()) $propId = 0;
    }
    $disc = crm_pct($in['discountPct'] ?? 0);
    $tax = crm_pct($in['taxPct'] ?? 0);
    $f = [crm_str($in['title'] ?? '', 255), $leadId, $propId ?: null, $status, crm_date($in['validUntil'] ?? ''), $disc, $tax, crm_text($in['notes'] ?? '')];
    $now = crm_now();
    $pdo->beginTransaction();
    try {
        $prevStatus = null;
        if ($id <= 0) {
            $number = crm_next_quote_number($pdo);
            $pdo->prepare('INSERT INTO crm_quotes (title, lead_id, proposal_id, status, valid_until, discount_pct, tax_pct, notes, number, created_at, updated_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($f, [$number, $now, $now, crm_me()]));
            $id = (int)$pdo->lastInsertId();
            $msgs = ["Quote {$number} created"];
        } else {
            $q = $pdo->prepare('SELECT * FROM crm_quotes WHERE id = ?');
            $q->execute([$id]);
            $prev = $q->fetch();
            if (!$prev) throw new RuntimeException('That quote no longer exists. Reload the page.');
            $prevStatus = (string)$prev['status'];
            $number = (string)$prev['number'];
            $pdo->prepare('UPDATE crm_quotes SET title = ?, lead_id = ?, proposal_id = ?, status = ?, valid_until = ?, discount_pct = ?, tax_pct = ?, notes = ?, updated_at = ? WHERE id = ?')
                ->execute(array_merge($f, [$now, $id]));
            $pdo->prepare('DELETE FROM crm_quote_items WHERE quote_id = ?')->execute([$id]);
            $msgs = ['Saved'];
        }
        $ins = $pdo->prepare('INSERT INTO crm_quote_items (quote_id, position, description, qty, rate) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $pos => $it) $ins->execute([$id, $pos, $it['desc'], $it['qty'], $it['rate']]);
        if ($prevStatus !== $status) {
            $total = crm_quote_totals($items, $disc, $tax)['total'];
            if (($m = crm_quote_effects($pdo, (int)$leadId, $number, $status, $total)) !== '') $msgs[] = $m;
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return [$id, $msgs];
}

/** The Status select in the Quotes table. */
function crm_quote_status(PDO $pdo, int $id, string $status): array {
    if (!isset(CRM_Q_STATUS[$status])) throw new RuntimeException('That is not a quote status.');
    $q = $pdo->prepare('SELECT * FROM crm_quotes WHERE id = ?');
    $q->execute([$id]);
    $quote = $q->fetch();
    if (!$quote) throw new RuntimeException('That quote no longer exists. Reload the page.');
    if ($quote['status'] === $status) return [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE crm_quotes SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, crm_now(), $id]);
        $msgs = [$quote['number'] . ' → ' . CRM_Q_STATUS[$status]];
        $m = crm_quote_effects($pdo, (int)$quote['lead_id'], (string)$quote['number'], $status, crm_stored_total($pdo, $quote));
        if ($m !== '') $msgs[] = $m;
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $msgs;
}

// ---- import -------------------------------------------------------------------------

/**
 * One batch of a CSV import, already grouped by company in the browser (the
 * preview the person checked). Checked again here against what is saved NOW,
 * so a company added since the preview, or a second import of the same file,
 * adds to the existing lead and skips anyone already on it instead of
 * duplicating them ("Import the file again; anything already added is skipped").
 *
 *   creates: [{company, website, projectType, source, value, stage, followUp,
 *              nextStep, tags[], notes, contacts[]}]
 *   adds:    [{id, contacts[]}]
 *
 * Returns ['made' => companies created, 'added' => contacts added to existing ones].
 */
function crm_import(PDO $pdo, string $name, array $creates, array $adds, bool $assignMe): array {
    $name = crm_str($name, 120) ?: 'Pasted rows';
    $owner = $assignMe ? crm_owner($pdo, crm_me()) : null;
    $made = 0; $added = 0;
    $find = $pdo->prepare('SELECT id FROM crm_leads WHERE LOWER(TRIM(company)) = ? ORDER BY updated_at DESC, id DESC LIMIT 1');

    // Adds new people to an existing lead, skipping anyone already on it.
    $addTo = function (int $leadId, array $contacts) use ($pdo, $name, &$added): void {
        $have = $pdo->prepare('SELECT name, email FROM crm_contacts WHERE lead_id = ?');
        $have->execute([$leadId]);
        $seen = [];
        foreach ($have->fetchAll() as $c) $seen[crm_contact_key($c)] = true;
        $pos = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM crm_contacts WHERE lead_id = ?');
        $pos->execute([$leadId]);
        $p = (int)$pos->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO crm_contacts (lead_id, position, name, title, email, phone, channel, contacted_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $names = [];
        foreach ($contacts as $raw) {
            $c = crm_contact_input((array)$raw);
            $k = $c ? crm_contact_key($c) : '';
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $ins->execute([$leadId, $p++, $c['name'], $c['title'], $c['email'], $c['phone'], $c['channel'], $c['contacted_at'] ?? date('Y-m-d'), crm_now()]);
            $names[] = $c['name'] !== '' ? $c['name'] : $c['email'];
        }
        if ($names) {
            $n = count($names);
            crm_add_activity($pdo, $leadId, 'Note', "Added {$n} contact" . ($n === 1 ? '' : 's') . " from {$name}: " . implode(', ', $names));
            crm_touch($pdo, $leadId);
            $added += $n;
        }
    };

    $pdo->beginTransaction();
    try {
        foreach (array_slice($creates, 0, 500) as $raw) {
            $l = (array)$raw;
            $company = crm_str($l['company'] ?? '', 255);
            if ($company === '') continue;
            $find->execute([mb_strtolower($company)]);
            if ($existing = (int)$find->fetchColumn()) { $addTo($existing, (array)($l['contacts'] ?? [])); continue; }

            // One lead holding every contact of the group, duplicates inside the file dropped.
            $seen = []; $contacts = [];
            foreach ((array)($l['contacts'] ?? []) as $c) {
                $c = crm_contact_input((array)$c);
                $k = $c ? crm_contact_key($c) : '';
                if ($k === '' || isset($seen[$k])) continue;
                $seen[$k] = true;
                $contacts[] = ['name' => $c['name'], 'title' => $c['title'], 'email' => $c['email'], 'phone' => $c['phone'],
                               'channel' => $c['channel'], 'contactedAt' => $c['contacted_at'] ?? date('Y-m-d')];
            }
            $in = $l + ['ownerId' => $owner];
            $in['company'] = $company;
            $in['ownerId'] = $owner;
            $f = crm_lead_input($pdo, $in);
            $now = crm_now();
            $closed = in_array($f['stage'], ['won', 'lost'], true) ? $now : null;
            $pdo->prepare('INSERT INTO crm_leads (company, website, stage, value, project_type, source, owner_id, next_step, follow_up, tags, notes, lost_reason, closed_at, created_at, updated_at, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$f['company'], $f['website'], $f['stage'], $f['value'], $f['project_type'], $f['source'], $f['owner_id'],
                           $f['next_step'], $f['follow_up'], $f['tags'], $f['notes'], '', $closed, $now, $now, crm_me()]);
            $id = (int)$pdo->lastInsertId();
            crm_sync_contacts($pdo, $id, $contacts);
            $n = count($contacts);
            crm_add_activity($pdo, $id, 'Stage', "Imported from {$name} as " . crm_stage_label($f['stage']) . " with {$n} contact" . ($n === 1 ? '' : 's'), null, $now);
            $made++;
        }
        foreach (array_slice($adds, 0, 500) as $raw) {
            $a = (array)$raw;
            $leadId = crm_lead_or_null($pdo, $a['id'] ?? null);
            if ($leadId) $addTo($leadId, (array)($a['contacts'] ?? []));
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return ['made' => $made, 'added' => $added];
}
