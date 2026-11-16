<?php
// The Realm: @here and @channel in a chat message, as in Slack.
//
// David, 2026-11-16: "@here @channel too". Settled with him the same day:
//   * EVERYBODY IN THE REALM MAY USE THEM, not only Production. The guard
//     against noise is the confirmation below, not a rank.
//   * @channel rings everybody who can READ that channel: the members of a
//     private one, everybody who may see the show for a show's channel, and
//     everybody in the Realm for a studio-wide one.
//   * @here rings only those of them who have the Realm open right now. IDLE
//     COUNTS AS HERE ("Yes, online and idle"): an idle person usually has the
//     Realm in another tab while they work, so they are reachable. Slack counts
//     only the active ones; David chose wider.
//   * The page ASKS BEFORE SENDING when it would ring 6 people or more ("This
//     will notify 18 people. Send?"). The number comes from realm_broadcast_reach()
//     (GET reach= in realm_api.php), so the page never guesses who is in a
//     private channel.
//   * Not in ravens: there are only two people in one, and the other one's bell
//     already rings for every raven.
//
// What each person gets is exactly what a personal @tag gives (realm_tag_bell):
// one 'tag' line on Anima's bell, "Renata tagged you in The Tavern in the Realm",
// stored the same way so the Activity view and the bell read both alike. Somebody
// both named AND reached by @here hears it once.
//
// ⚠ PRIVACY: a private channel (migration 097) only ever reaches its members, and
// a show's channel only people staffed on that show. The reach is worked out
// here from the database, never from anything the page sends.
//
// No migration: it uses the 'tag' kind migration 083 added.

/** At or above this many people the page asks before sending (David, 2026-11-16). */
const REALM_BROADCAST_ASK = 6;

/**
 * Which broadcast a message carries: 'channel', 'here' or '' for none.
 * @channel wins when both are written: it reaches everybody @here would.
 * The @ must start a word, so an e-mail address such as a@here.com is not one.
 */
function realm_broadcast_word(string $body): string {
    if (strpos($body, '@') === false) return '';
    if (preg_match('/(?<![\w@.])@channel\b/i', $body) === 1) return 'channel';
    if (preg_match('/(?<![\w@.])@here\b/i', $body) === 1) return 'here';
    return '';
}

/**
 * The people a broadcast in $channel reaches, never the sender: a list of
 * ['user_id' => int, 'crew_id' => int, 'state' => 'online'|'idle'|'away'].
 * $which is 'channel' (everybody who can read it) or 'here' (those of them with
 * the Realm open, idle included). Ravens and unknown channels reach nobody.
 * Never throws: trouble is an empty list, which rings nobody.
 */
function realm_broadcast_reach(PDO $pdo, int $fromUid, string $channel, string $which): array {
    if ($which !== 'here' && $which !== 'channel') return [];
    if (strpos($channel, 'dm:') === 0) return [];
    try {
        // Which show the channel belongs to (0 = studio-wide), and for a private
        // made channel the members, who are the only possible readers.
        $show = 0;
        $members = null;
        if (preg_match('/^project:(\d+)$/', $channel, $m) === 1) {
            $show = (int)$m[1];
        } elseif (preg_match('/^ch:(\d{1,9})$/', $channel, $m) === 1) {
            $q = $pdo->prepare('SELECT is_private, project_id, archived_at FROM realm_chat_channels WHERE id = ?');
            $q->execute([(int)$m[1]]);
            $c = $q->fetch();
            if (!$c || $c['archived_at'] !== null) return [];
            $show = (int)($c['project_id'] ?? 0);
            if ((int)$c['is_private'] === 1) {
                $q = $pdo->prepare('SELECT user_id FROM realm_chat_members WHERE channel_id = ?');
                $q->execute([(int)$m[1]]);
                $members = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            }
        } elseif (!in_array($channel, ['announcements', 'tavern', 'council'], true)) {
            return [];
        }

        // Who is staffed on the show, by crew id; null when the table is missing
        // (visible_project_ids() does not scope a half-built database either).
        $staffed = null;
        if ($show > 0) {
            try {
                $q = $pdo->prepare('SELECT crew_id FROM crew_projects WHERE project_id = ?');
                $q->execute([$show]);
                $staffed = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {
                $staffed = null;
            }
        }
        $seeAll = function_exists('roles_see_all_projects') ? roles_see_all_projects() : ['admin', 'editor'];

        // The same people the map draws (realm_state): a house, an active
        // account, never a client.
        $rows = $pdo->query("SELECT rp.user_id, u.crew_id, u.role,
                                    TIMESTAMPDIFF(SECOND, rp.last_ping, NOW()) AS ping_age,
                                    TIMESTAMPDIFF(MINUTE, rp.last_active, NOW()) AS idle_min
                               FROM realm_people rp JOIN users u ON u.id = rp.user_id
                              WHERE rp.house_slot IS NOT NULL AND u.active = 1 AND u.role <> 'client'")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['user_id'];
            if ($id === $fromUid) continue;
            if ($members !== null && !in_array($id, $members, true)) continue;
            if ($show > 0 && $staffed !== null && !in_array((string)$r['role'], $seeAll, true)
                && !in_array((int)$r['crew_id'], $staffed, true)) continue;
            $here = $r['ping_age'] !== null && (int)$r['ping_age'] <= REALM_ONLINE_SECONDS;
            if ($which === 'here' && !$here) continue;
            $state = !$here ? 'away' : (($r['idle_min'] !== null && (int)$r['idle_min'] >= REALM_IDLE_MINUTES) ? 'idle' : 'online');
            $out[] = ['user_id' => $id, 'crew_id' => (int)$r['crew_id'], 'state' => $state];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ring the bell of everybody an @here / @channel in $body reaches. Called by
 * realm_tag_bell() after the named people, with the same $actor and $summary,
 * so the line is identical and somebody already rung (and not yet seen) is
 * skipped. People without a crew link have no bell, as with a personal tag.
 * Never throws.
 */
function realm_broadcast_bell(PDO $pdo, int $fromUid, string $channel, string $body, string $actor, string $summary): void {
    try {
        $which = realm_broadcast_word($body);
        if ($which === '' || !function_exists('notify_add')) return;
        $seen = $pdo->prepare("SELECT 1 FROM notifications WHERE crew_id = ? AND kind = 'tag' AND actor = ? AND summary = ? AND seen_at IS NULL LIMIT 1");
        foreach (realm_broadcast_reach($pdo, $fromUid, $channel, $which) as $p) {
            if ($p['crew_id'] <= 0) continue;
            $seen->execute([$p['crew_id'], $actor, $summary]);
            $rung = $seen->fetchColumn();
            $seen->closeCursor();
            if ($rung) continue;
            notify_add($pdo, [$p['crew_id']], ['kind' => 'tag', 'actor' => $actor, 'summary' => $summary]);
        }
    } catch (Throwable $e) {
        // The message was kept; only the bell is silent.
    }
}
