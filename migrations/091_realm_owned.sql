-- 091 — The Realm: what each person owns (the merchant in the Tavern), and giving and swapping it.
--
-- David, 2026-11-12: gold "buys clothes, accessories and pets", and things for
-- the office ("opt to buy new stuff"). The shop's rules were agreed with him on
-- 2026-11-13 and are written in includes/realm_shop.php.
--
-- realm_owned: ONE LINE PER THING SOMEBODY OWNS. `item` is the id in REALM_SHOP.
-- `got` is how it reached them: 'bought', 'gift' or 'swap'. A thing changes
-- hands by this line changing its `user_id`; `from_user` is who it came from
-- then, and `paid` the coins it cost when it was bought (0 once it was given).
--
-- The unique key is the rule "one of each per person" (David, 2026-11-13): a
-- second crown cannot be bought, and later cannot be given to somebody who has
-- one.
--
-- Nobody but the owner is ever shown their lines. Others see only what is worn
-- and what stands in an office.
--
-- ⚠ NOTHING HERE IS ABOUT PAY. No rate, salary or invoice is read or stored, and
-- the finance database is not touched.
--
-- realm_offers: one row per offer of a gift or a swap (includes/realm_trade.php).
-- Somebody offers one of their things to a person standing where they are
-- (status 'open'). A gift is accepted ('done') or declined. For a swap the other
-- answers with one of their own things (`back_item`, status 'answered') and the
-- first person agrees ('done'); either may say no ('declined', 'cancelled').
-- An offer nobody moves on is simply too old after five minutes; nothing has to
-- clean it up. Only the two people ever read their offers.
--
-- Needs 090 (realm_purse) for there to be any gold to spend. Until this runs
-- the Realm works as before: the merchant says a database update is needed, and
-- the things for sale cannot be bought, worn or placed.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_owned (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  item        VARCHAR(24) NOT NULL,
  got         VARCHAR(8) NOT NULL DEFAULT 'bought',   -- bought / gift / swap
  from_user   INT DEFAULT NULL,
  paid        INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_realm_owned (user_id, item),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_offers (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  from_user   INT NOT NULL,
  to_user     INT NOT NULL,
  kind        VARCHAR(8) NOT NULL,                    -- gift / swap
  give_item   VARCHAR(24) NOT NULL,
  back_item   VARCHAR(24) DEFAULT NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'open',    -- open / answered / done / declined / cancelled
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  KEY idx_realm_offers_to (to_user, updated_at),
  KEY idx_realm_offers_from (from_user, updated_at),
  FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
