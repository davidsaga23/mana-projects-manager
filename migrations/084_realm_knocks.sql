-- 084 — The Realm: knocking on a door that stands ajar.
--
-- David, 2026-11-12, with a picture of his own house's card saying "The door is
-- ajar. Knocking arrives with voice chat.": "when a door is ajar, let's open the
-- option to knock". Knocking had been promised for the voice stage; he wanted it
-- now, on its own.
--
-- realm_knocks: one row per knock. Somebody standing at an ajar door knocks
-- (status 'waiting'); the owner answers 'in' or 'no'. A knock nobody answers is
-- simply too old after two minutes; nothing has to clean it up. Being let in
-- lasts ten minutes, which is long enough to step out and back without knocking
-- again, and is checked on the server when somebody goes inside.
--
-- Only the latest knock between two people is kept: knocking again replaces
-- the earlier one.
--
-- Until this runs the Realm works as before: an ajar door cannot be entered,
-- and its card says knocking needs a database update.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_knocks (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  from_user    INT NOT NULL,
  to_user      INT NOT NULL,
  status       VARCHAR(8) NOT NULL DEFAULT 'waiting',   -- waiting / in / no
  created_at   DATETIME NOT NULL,
  answered_at  DATETIME DEFAULT NULL,
  KEY idx_realm_knock_to (to_user, status),
  KEY idx_realm_knock_from (from_user, id),
  FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
