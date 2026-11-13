-- 091 — The Realm: what each person owns (the merchant in the Tavern).
--
-- David, 2026-11-12: gold "buys clothes, accessories and pets", and things for
-- the office ("opt to buy new stuff"). The shop's rules were agreed with him on
-- 2026-11-13 and are written in includes/realm_shop.php.
--
-- realm_owned: ONE LINE PER THING SOMEBODY OWNS. `item` is the id in REALM_SHOP.
-- `got` is how it reached them: 'bought' today; 'gift' and 'swap' are for the
-- build after this one, when a thing changes hands by this line changing its
-- `user_id`. `from_user` is who it came from then, and `paid` the coins it cost
-- when it was bought (0 for a gift), kept so the history can be read later.
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
