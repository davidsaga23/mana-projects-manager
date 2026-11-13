-- 090 — The Realm: mana, gold and praise.
--
-- David, 2026-11-12: "people should be able to praise with a spell or something
-- people doing a good job", with two pools: mana, which is only for praising
-- others ("they add mana each continuous day you log time and you don't earn if
-- you don't log time"), and gold, which is private ("No visible balances").
-- The rules of a praise were agreed with him on 2026-11-13 and are written in
-- includes/realm_praise.php.
--
-- realm_purse: one row per person, what they hold. `mana` is at most 5.
-- `streak` is how many working days in a row they have logged their time, and
-- `counted_to` is the last day that has been looked at, so each day is counted
-- once however often the Realm is opened. `approvals_to` does the same for
-- approved work: the last line of progress_log that has been counted. Nobody
-- but the owner is ever shown a purse.
--
-- realm_praise: one row per praise given, kept for good: it is the line others
-- see when it happens and the keepsake in the receiver's profile. `gold` is 1
-- when this praise gave the receiver a coin (the first from that person in the
-- week), 0 otherwise.
--
-- ⚠ NOTHING HERE IS ABOUT PAY. No rate, salary or invoice is read or stored, and
-- the finance database is not touched.
--
-- Until this runs the Realm works as before; the chest and the Praise button say
-- that a database update is needed.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_purse (
  user_id     INT NOT NULL,
  mana        INT NOT NULL DEFAULT 0,
  gold        INT NOT NULL DEFAULT 0,
  streak      INT NOT NULL DEFAULT 0,
  counted_to  DATE DEFAULT NULL,
  approvals_to INT DEFAULT NULL,
  updated_at  DATETIME DEFAULT NULL,
  PRIMARY KEY (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_praise (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  from_user   INT NOT NULL,
  to_user     INT NOT NULL,
  kind        VARCHAR(12) NOT NULL,               -- craft / help / idea / rescue
  note        VARCHAR(160) NOT NULL,
  gold        TINYINT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  KEY idx_realm_praise_to (to_user, id),
  KEY idx_realm_praise_pair (from_user, to_user, created_at),
  KEY idx_realm_praise_when (created_at),
  FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
