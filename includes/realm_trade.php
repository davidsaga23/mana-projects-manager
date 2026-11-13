<?php
// The Realm — giving and swapping what was bought (migration 091).
//
// David, 2026-11-13: "not exchangable are all the free starters, but anything
// you paid for is exchangable", and, after the shop was built: "let's build the
// giving and swapping". The rules below were put to him with the shop and he
// answered "yeah let's move forward".
//
// THE RULES.
//   * WHAT. Only what the merchant sells (REALM_SHOP). The free library, the
//     free clothes and gold itself never change hands.
//   * FACE TO FACE. Both people in the same place right now, both inside or
//     both outside, both with the page open: the same rule as a praise. It is
//     asked when the offer is made AND again at the moment things move, so if
//     one of them walks away nothing moves.
//   * A GIFT MUST BE ACCEPTED. With one of each per person, somebody cannot be
//     handed a second throne, so the receiver has a say anyway.
//   * A SWAP NEEDS BOTH TO AGREE. What somebody owns is private, so the one who
//     starts cannot pick from the other's things. Instead: A offers one thing
//     "for a swap"; B answers with one of their own; A sees it and agrees.
//     Making the offer and choosing what to give back are B's and A's first
//     yes; A's "Agree" is the last, and only then do the two things move, in
//     one transaction.
//   * ONE OF EACH still holds: a thing cannot go to somebody who has one. The
//     giver is told so, which is the one fact about another person's list that
//     this file ever gives away.
//   * A THING THAT LEAVES COMES OFF. It is taken off its old owner's character
//     and out of their stored room in the same transaction (realm_owned_strip),
//     so nobody keeps wearing what is no longer theirs.
//   * AN OFFER LASTS FIVE MINUTES from its last step. Making a new one ends
//     your earlier one.
//
// ⚠ THE SECOND LOCK. `offer` and `offer_answer` are in artist_may_post() so
// anybody in the Realm may send them; the functions here decide. The actor is
// always the session's own account. The ids posted are the OTHER person and the
// offer; an offer is answered only by the person it was made to and agreed only
// by the person who made it.
//
// ⚠ ONLY THE TWO PEOPLE SEE AN OFFER. realm_offer_news() is read for the
// session's own account. Nothing is announced to the village: who owns what
// stays private, and others see only what is worn and what stands in an office.
//
// ⚠ NOTHING HERE IS ABOUT PAY, and no gold moves: an exchange is things only.

/** How long an offer waits for its next step, in seconds. */
const REALM_OFFER_SECONDS = 300;
/** How long a finished offer is still told to the two people, so both see how it ended. */
const REALM_OFFER_NEWS_SECONDS = 120;

/** Has the offers table arrived (migration 091), with the shop? Asked once per request; never throws. */
function realm_offers_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!realm_shop_available($pdo)) return $ok = false;
    try {
        $pdo->query('SELECT 1 FROM realm_offers LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** The first name the Realm shows for $id. Never throws. */
function realm_trade_name(PDO $pdo, int $id): string {
    try {
        $q = $pdo->prepare('SELECT u.display_name, u.username, c.name FROM users u LEFT JOIN crew c ON c.id = u.crew_id WHERE u.id = ?');
        $q->execute([$id]);
        $r = $q->fetch();
        $name = $r ? trim((string)($r['name'] ?: ($r['display_name'] ?: $r['username']))) : '';
        return $name !== '' ? explode(' ', $name)[0] : 'They';
    } catch (Throwable $e) {
        return 'They';
    }
}

/**
 * Are $a and $b face to face right now? '' when they are, or the sentence to
 * show $a. The same question a praise asks.
 */
function realm_face_to_face(PDO $pdo, int $a, int $b): string {
    $q = $pdo->prepare('SELECT rp.user_id, rp.place, rp.inside, TIMESTAMPDIFF(SECOND, rp.last_ping, NOW()) AS age, u.role, u.active
                          FROM realm_people rp JOIN users u ON u.id = rp.user_id WHERE rp.user_id IN (?, ?)');
    $q->execute([$a, $b]);
    $at = [];
    foreach ($q->fetchAll() as $r) $at[(int)$r['user_id']] = $r;
    if (!isset($at[$a])) return 'Open the Realm first.';
    if (!isset($at[$b]) || (int)$at[$b]['active'] !== 1 || (string)$at[$b]['role'] === 'client') return 'That person is not in the Realm.';
    $name = realm_trade_name($pdo, $b);
    if ($at[$b]['age'] === null || (int)$at[$b]['age'] > REALM_ONLINE_SECONDS) return $name . ' is away. Things change hands face to face.';
    if ((string)$at[$b]['place'] === '' || (string)$at[$b]['place'] !== (string)$at[$a]['place']
        || (int)$at[$b]['inside'] !== (int)$at[$a]['inside']) {
        return 'Things change hands face to face. Walk to where ' . $name . ' is first.';
    }
    return '';
}

/**
 * Offer one of the asker's own things to $to, as a gift or for a swap.
 * Returns '' when the offer was made, or the sentence to show.
 */
function realm_offer_make(PDO $pdo, int $uid, int $to, string $item, string $kind): string {
    if (!realm_offers_available($pdo)) return 'Giving and swapping need a database update first. An administrator opens migrate.php once.';
    if ($to === $uid || $to <= 0) return 'Choose somebody else to give to.';
    if ($kind !== 'gift' && $kind !== 'swap') return 'Choose a gift or a swap.';
    if (!isset(REALM_SHOP[$item])) return 'Only what the merchant sells can be given or swapped.';
    $name = REALM_SHOP_NAMES[$item] ?? 'that';
    if (!in_array($item, realm_owned($pdo, $uid), true)) return ucfirst($name) . ' is not yours to give.';
    $why = realm_face_to_face($pdo, $uid, $to);
    if ($why !== '') return $why;
    // One of each per person. The one fact about somebody else's things that is ever told.
    if (in_array($item, realm_owned($pdo, $to), true)) return realm_trade_name($pdo, $to) . ' already has ' . $name . '. It is one of each.';
    try {
        // A new offer ends my earlier one.
        $pdo->prepare("UPDATE realm_offers SET status = 'cancelled', updated_at = NOW() WHERE from_user = ? AND status IN ('open', 'answered')")
            ->execute([$uid]);
        $pdo->prepare("INSERT INTO realm_offers (from_user, to_user, kind, give_item, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'open', NOW(), NOW())")
            ->execute([$uid, $to, $kind, $item]);
    } catch (Throwable $e) {
        return 'The offer could not be made. Try again in a moment.';
    }
    return '';
}

/**
 * The next step of an offer, by one of its two people. $say is:
 *   accept   by the person it was made to: take a gift, or answer a swap with
 *            $item, one of their own things
 *   decline  by the person it was made to: no thanks
 *   agree    by the person who made it, once a swap has been answered: do it
 *   cancel   by the person who made it: call it off
 * Returns '' when the step was taken, or the sentence to show.
 */
function realm_offer_answer(PDO $pdo, int $uid, int $offerId, string $say, string $item): string {
    if (!realm_offers_available($pdo)) return 'Giving and swapping need a database update first.';
    $q = $pdo->prepare("SELECT * FROM realm_offers WHERE id = ? AND (from_user = ? OR to_user = ?) AND status IN ('open', 'answered')
                           AND updated_at > DATE_SUB(NOW(), INTERVAL " . REALM_OFFER_SECONDS . " SECOND)");
    $q->execute([$offerId, $uid, $uid]);
    $o = $q->fetch();
    if (!$o) return 'That offer has ended.';
    $from = (int)$o['from_user'];
    $to   = (int)$o['to_user'];
    $status = (string)$o['status'];
    $end = function (string $as) use ($pdo, $offerId, $status): string {
        $u = $pdo->prepare('UPDATE realm_offers SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?');
        $u->execute([$as, $offerId, $status]);
        return $u->rowCount() === 1 ? '' : 'That offer has ended.';
    };
    try {
        if ($uid === $to) {
            if ($say === 'decline') return $end('declined');
            if ($say !== 'accept' || $status !== 'open') return 'That offer is waiting for the other person.';
            if ((string)$o['kind'] === 'gift') return realm_offer_move($pdo, $o);
            // A swap: what do I give back?
            if (!isset(REALM_SHOP[$item]) || !in_array($item, realm_owned($pdo, $uid), true)) return 'Choose one of your own things to give back.';
            if ($item === (string)$o['give_item']) return 'Choose something different to give back.';
            if (in_array($item, realm_owned($pdo, $from), true)) {
                return realm_trade_name($pdo, $from) . ' already has ' . (REALM_SHOP_NAMES[$item] ?? 'that') . '. Choose something else.';
            }
            $why = realm_face_to_face($pdo, $uid, $from);
            if ($why !== '') return $why;
            $u = $pdo->prepare("UPDATE realm_offers SET back_item = ?, status = 'answered', updated_at = NOW() WHERE id = ? AND status = 'open'");
            $u->execute([$item, $offerId]);
            return $u->rowCount() === 1 ? '' : 'That offer has ended.';
        }
        // The person who made it.
        if ($say === 'cancel') return $end('cancelled');
        if ($say !== 'agree' || $status !== 'answered') return 'That offer is waiting for the other person.';
        return realm_offer_move($pdo, $o);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'That could not be done, and nothing moved. Try again in a moment.';
    }
}

/**
 * The things change hands. Everything in one transaction: the offer is marked
 * done (which is also what stops two quick clicks moving a thing twice), each
 * line in realm_owned changes its owner, and each thing comes off the person it
 * left. Anything unexpected rolls all of it back. Returns '' or the sentence.
 */
function realm_offer_move(PDO $pdo, array $o): string {
    $id   = (int)$o['id'];
    $from = (int)$o['from_user'];
    $to   = (int)$o['to_user'];
    $kind = (string)$o['kind'];
    $give = (string)$o['give_item'];
    $back = $kind === 'swap' ? (string)($o['back_item'] ?? '') : '';
    if ($kind === 'swap' && $back === '') return 'That swap has not been answered yet.';

    // Still face to face? If one walked away, the offer ends and nothing moves.
    if (realm_face_to_face($pdo, $from, $to) !== '') {
        $pdo->prepare("UPDATE realm_offers SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status IN ('open', 'answered')")->execute([$id]);
        return 'You are no longer in the same place, so nothing moved.';
    }
    try {
        $pdo->beginTransaction();
        $u = $pdo->prepare("UPDATE realm_offers SET status = 'done', updated_at = NOW() WHERE id = ? AND status = ?");
        $u->execute([$id, (string)$o['status']]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); return 'That offer has ended.'; }
        $move = $pdo->prepare('UPDATE realm_owned SET user_id = ?, got = ?, from_user = ?, paid = 0, created_at = NOW() WHERE user_id = ? AND item = ?');
        // The unique key (user_id, item) refuses a second one of anything; that throws, and all of it rolls back.
        $move->execute([$to, $kind, $from, $from, $give]);
        if ($move->rowCount() !== 1) { $pdo->rollBack(); return ucfirst(REALM_SHOP_NAMES[$give] ?? 'that') . ' is no longer theirs to give, so nothing moved.'; }
        realm_owned_strip($pdo, $from, $give);
        if ($back !== '') {
            $move->execute([$from, $kind, $to, $to, $back]);
            if ($move->rowCount() !== 1) { $pdo->rollBack(); return ucfirst(REALM_SHOP_NAMES[$back] ?? 'that') . ' is no longer theirs to give, so nothing moved.'; }
            realm_owned_strip($pdo, $to, $back);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'That could not be done, and nothing moved. Try again in a moment.';
    }
    return '';
}

/**
 * $item has left $uid: take it off their character, or out of their stored
 * room. Called inside realm_offer_move()'s transaction, and it THROWS when it
 * cannot do its work, so that the exchange is undone rather than leaving
 * somebody wearing what is no longer theirs.
 *
 * A cloak goes back to the colour a new person is given (realm_enter); headwear
 * and a companion go back to none.
 */
function realm_owned_strip(PDO $pdo, int $uid, string $item): void {
    $kind = REALM_SHOP[$item][0] ?? '';
    if ($kind === 'thing') {
        $q = $pdo->prepare('SELECT layout FROM realm_rooms WHERE user_id = ?');
        try {
            $q->execute([$uid]);
        } catch (Throwable $e) {
            return;   // No rooms table (migration 086 not run): nothing is stored, so nothing to take out.
        }
        $raw = $q->fetchColumn();
        if ($raw === false || $raw === null) return;
        $room = json_decode((string)$raw, true);
        if (!is_array($room) || !isset($room['items']) || !is_array($room['items'])) return;
        $keep = [];
        foreach ($room['items'] as $it) {
            if (is_array($it) && (string)($it[0] ?? '') !== $item) $keep[] = $it;
        }
        if (count($keep) === count($room['items'])) return;
        $room['items'] = $keep;
        $pdo->prepare('UPDATE realm_rooms SET layout = ?, updated_at = NOW() WHERE user_id = ?')->execute([json_encode($room), $uid]);
        return;
    }
    if ($kind === 'cloak') {
        $pdo->prepare('UPDATE realm_people SET cloak = ? WHERE user_id = ? AND cloak = ?')
            ->execute([REALM_CLOAKS[$uid % count(REALM_CLOAKS)], $uid, $item]);
    } elseif ($kind === 'hat') {
        $pdo->prepare("UPDATE realm_people SET hat = 'none' WHERE user_id = ? AND hat = ?")->execute([$uid, $item]);
    } elseif ($kind === 'pet') {
        $pdo->prepare("UPDATE realm_people SET pet = 'none' WHERE user_id = ? AND pet = ?")->execute([$uid, $item]);
    }
}

/**
 * What the asker needs to know about their own offers right now:
 *   ready  false until migration 091 has run
 *   list   the offers they made or were made, still waiting or just ended:
 *          [{id, mine, with, name, kind, give, back, status}], where `mine`
 *          says the asker made it, `with` and `name` are the other person,
 *          and status is open, answered, done, declined, cancelled or gone
 *          (nobody took the next step in time).
 * Sent with the map, with the unread counts and with every chat answer, like a
 * knock, so the other person sees an offer within a few seconds. Never throws.
 */
function realm_offer_news(PDO $pdo, int $uid): array {
    $out = ['ready' => realm_offers_available($pdo), 'list' => []];
    if (!$out['ready']) return $out;
    try {
        $q = $pdo->prepare('SELECT id, from_user, to_user, kind, give_item, back_item, status,
                                   TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age
                              FROM realm_offers
                             WHERE (from_user = ? OR to_user = ?)
                               AND updated_at > DATE_SUB(NOW(), INTERVAL ' . (REALM_OFFER_SECONDS + REALM_OFFER_NEWS_SECONDS) . ' SECOND)
                          ORDER BY id');
        $q->execute([$uid, $uid]);
        foreach ($q->fetchAll() as $r) {
            $status  = (string)$r['status'];
            $waiting = $status === 'open' || $status === 'answered';
            if ($waiting && (int)$r['age'] > REALM_OFFER_SECONDS) $status = 'gone';
            elseif (!$waiting && (int)$r['age'] > REALM_OFFER_NEWS_SECONDS) continue;
            $mine  = (int)$r['from_user'] === $uid;
            $other = $mine ? (int)$r['to_user'] : (int)$r['from_user'];
            $out['list'][] = ['id' => (int)$r['id'], 'mine' => $mine, 'with' => $other, 'name' => realm_trade_name($pdo, $other),
                              'kind' => (string)$r['kind'] === 'swap' ? 'swap' : 'gift',
                              'give' => (string)$r['give_item'], 'back' => (string)($r['back_item'] ?? ''), 'status' => $status];
        }
    } catch (Throwable $e) {
        // No news rather than a failed map.
    }
    return $out;
}
