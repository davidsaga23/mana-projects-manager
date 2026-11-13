<?php
// The Realm — the merchant, and what each person owns (migration 091).
//
// David, 2026-11-12: gold "buys clothes, accessories and pets", and on the same
// day, about the office: "they can modify it from a library of free and opt to
// buy new stuff". The shop was agreed with him on 2026-11-13, in its own thread
// ("let's open a new thread for that"), a few choices at a time; he answered
// "Agree to all three", then "yeah let's move forward", then "Yes, build it".
//
// THE RULES (all David, 2026-11-13).
//   * WHERE. A merchant stands in the Tavern, and that is where you buy: the
//     buyer must be inside the Tavern right now, checked here as well as on the
//     screen. The things for sale are also shown, with their price, in the
//     Decorate library and in "Your character", which send you to the merchant.
//   * WHAT. Eight things to begin with, REALM_SHOP below. Everything that was
//     free stays free.
//   * PRICES. Three tiers, 5, 12 and 25 coins, set against what a steady artist
//     earns (about 3 to 10 coins a week) and against the people who approve work
//     instead of delivering it, who earn far fewer.
//   * NO SCARCITY. Nothing runs out, nothing is limited in number and nothing is
//     taken away. In a studio of 20, "only three thrones exist" would turn a toy
//     into a contest, and there is no ranking in the Realm.
//   * ONE OF EACH PER PERSON. The unique key on realm_owned is what holds this.
//   * NO UNDO AND NO SELLING BACK. If a thing could be sold back, one person
//     could buy it, give it away, and the other sell it: gold handed over by
//     another route, which David ruled out on 2026-11-12. So the merchant asks
//     before taking anything, and that is the only way back.
//   * PRIVATE. What somebody owns is read only for the session's own account,
//     like the purse. Others see what you wear and what stands in your office,
//     never the list and never the coins.
//
// ⚠ THE SECOND LOCK. `buy` is in artist_may_post() so anybody in the Realm may
// send it; realm_shop_buy() is what decides. The buyer is the session's own
// account, there is no id to post, and the coins leave in the same statement
// that checks there are enough, so two quick clicks cannot pay once for two.
//
// ⚠ REALM_SHOP IS ALSO IN assets/realm.js: the office things are in THINGS
// (marked gold), the headwear in HATS, the companions in PETS and the cloak in
// CLOAKS, each with its drawing. The PRICES are only here; the screen is sent
// them. A thing added here and not drawn there shows as nothing.
//
// GIVING AND SWAPPING are in includes/realm_trade.php. realm_owned is one line
// per thing, with who holds it and how they got it, so a gift is that line
// changing hands; the thing is taken off its old owner's character and out of
// their office in the same transaction.
//
// ⚠ NOTHING HERE IS ABOUT PAY. No rate, salary or invoice is read or written,
// and the finance connection is never opened.

/**
 * What the merchant sells. id => [what kind of thing it is, its price in coins].
 * 'thing' stands in an office (the id is the one in REALM_ROOM_THINGS); 'hat',
 * 'pet' and 'cloak' are worn (the id is what realm_people stores).
 * ⚠ An id is used once across all four kinds: realm_owned stores only the id.
 */
const REALM_SHOP = [
    'trophy'  => ['thing', 5],
    'crown'   => ['hat',   5],
    'cap'     => ['hat',   5],
    'orb'     => ['thing', 12],
    'fox'     => ['pet',   12],
    'starry'  => ['cloak', 12],
    'throne'  => ['thing', 25],
    'dragon'  => ['pet',   25],
];
/** The name of each, for the sentences this file sends back. */
const REALM_SHOP_NAMES = [
    'trophy' => 'the shield and swords', 'crown' => 'the crown', 'cap' => 'the feathered cap',
    'orb' => 'the crystal ball', 'fox' => 'the fox', 'starry' => 'the starry cloak',
    'throne' => 'the throne', 'dragon' => 'the small dragon',
];

/** Has migration 091 arrived, and gold with it (090)? Asked once per request; never throws. */
function realm_shop_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM realm_owned LIMIT 1');
        $pdo->query('SELECT 1 FROM realm_purse LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * The ids of what $uid owns. ⚠ ONLY EVER CALLED WITH THE SESSION'S OWN ID.
 * Empty before the migration, and when it cannot be read. Never throws.
 */
function realm_owned(PDO $pdo, int $uid): array {
    if (!realm_shop_available($pdo) || $uid <= 0) return [];
    try {
        $q = $pdo->prepare('SELECT item FROM realm_owned WHERE user_id = ? ORDER BY id');
        $q->execute([$uid]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (isset(REALM_SHOP[(string)$id])) $out[] = (string)$id;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Is $v a cloak, headwear or companion the Realm can draw, free or bought?
 * Used when the map is read, where what somebody wears was already checked
 * against what they own when they put it on.
 */
function realm_look_known(string $part, string $v): bool {
    $free = ['cloak' => REALM_CLOAKS, 'hat' => REALM_HATS, 'pet' => REALM_PETS][$part] ?? [];
    return in_array($v, $free, true) || (isset(REALM_SHOP[$v]) && REALM_SHOP[$v][0] === $part);
}

/**
 * What travels with the map, for the asker alone: has 091 run, the price list,
 * and the ids of what THEY own, so the library and "Your character" can tell
 * "yours" from "at the merchant". No coins: those are asked for at the counter
 * (realm_shop_answer). One small read. Never throws.
 */
function realm_shop_state(PDO $pdo, int $uid): array {
    return ['ready' => realm_shop_available($pdo), 'items' => REALM_SHOP, 'owned' => realm_owned($pdo, $uid)];
}

/**
 * The counter: the same, plus the asker's own coins, brought up to date with
 * their time log and approved work first (realm_purse, includes/realm_praise.php).
 */
function realm_shop_answer(PDO $pdo, int $uid): array {
    $out = realm_shop_state($pdo, $uid);
    $out['gold'] = 0;
    if ($out['ready'] && function_exists('realm_purse')) $out['gold'] = (int)realm_purse($pdo, $uid)['gold'];
    return $out;
}

/**
 * Buy one thing from the merchant. Returns '' when it is now the asker's, or
 * the sentence to show.
 */
function realm_shop_buy(PDO $pdo, int $uid, string $item): string {
    if (!realm_shop_available($pdo)) return 'The merchant needs a database update first. An administrator opens migrate.php once.';
    if (!isset(REALM_SHOP[$item])) return 'The merchant does not sell that.';
    $price = (int)REALM_SHOP[$item][1];
    $name  = REALM_SHOP_NAMES[$item] ?? 'that';

    // At the counter: inside the Tavern, with the page open.
    $q = $pdo->prepare('SELECT place, inside, TIMESTAMPDIFF(SECOND, last_ping, NOW()) AS age FROM realm_people WHERE user_id = ?');
    $q->execute([$uid]);
    $at = $q->fetch();
    if (!$at || $at['age'] === null || (int)$at['age'] > REALM_ONLINE_SECONDS) return 'Open the Realm first.';
    if ((string)$at['place'] !== 'tavern' || (int)$at['inside'] !== 1) return 'The merchant is in the Tavern. Go inside to buy.';

    if (in_array($item, realm_owned($pdo, $uid), true)) return 'You already own ' . $name . '.';

    // Coins earned since the purse was last looked at count towards this.
    $gold = function_exists('realm_purse') ? (int)realm_purse($pdo, $uid)['gold'] : 0;
    if ($gold < $price) {
        $short = $price - $gold;
        return ucfirst($name) . ' costs ' . $price . ' coins and you have ' . $gold . '. ' . $short . ' more to go.';
    }

    try {
        $pdo->beginTransaction();
        $u = $pdo->prepare('UPDATE realm_purse SET gold = gold - ?, updated_at = NOW() WHERE user_id = ? AND gold >= ?');
        $u->execute([$price, $uid, $price]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); return 'You do not have enough coins for ' . $name . '.'; }
        // The unique key (user_id, item) refuses a second one, and the coins come back with the rollback.
        $pdo->prepare('INSERT INTO realm_owned (user_id, item, got, paid, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$uid, $item, 'bought', $price]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'That could not be bought. No coins were taken. Try again in a moment.';
    }
    return '';
}
