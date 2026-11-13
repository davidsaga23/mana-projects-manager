-- 085 — The Realm: voice between the people inside the same room.
--
-- David, 2026-11-12: "let's start working on the voice chats". Decided that day:
-- voice is for small informal groups ("I think is fine 3-5 people talking
-- informally"), it is face to face (the characters must be in the same room),
-- and nobody's microphone opens by walking in: each person presses Join voice.
--
-- THE SOUND NEVER PASSES THROUGH ANIMA. It goes straight from one browser to
-- another. What these two tables carry is only the arranging:
--
-- realm_voice: one row per person who is in voice right now, and in which room.
-- `session` is a number made fresh at every Join, so a second window or a
-- reloaded page is told apart from the first. `last_seen` is touched every
-- second by that person's browser while they are in voice; a row not touched
-- for 15 seconds means the page was closed, and is treated as not there. Nothing
-- has to clean it up for the Realm to be right.
--
-- realm_signals: the short technical notes two browsers pass each other to set
-- up their direct line (an offer, an answer, and a handful of network
-- addresses). The hosting cannot hold a connection open, so they wait here for
-- about a second until the other browser asks. Each is read once and deleted;
-- one older than 30 seconds is ignored. No sound and no words are ever in them.
--
-- Until this runs the Realm works as before, and the Join voice button says
-- voice needs a database update.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_voice (
  user_id    INT NOT NULL PRIMARY KEY,
  place      VARCHAR(40) NOT NULL,            -- the room, as realm_people.place spells it
  session    INT NOT NULL DEFAULT 0,          -- made fresh at every Join
  muted      TINYINT(1) NOT NULL DEFAULT 0,
  joined_at  DATETIME NOT NULL,
  last_seen  DATETIME NOT NULL,
  KEY idx_realm_voice_place (place, last_seen),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_signals (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  from_user     INT NOT NULL,
  from_session  INT NOT NULL DEFAULT 0,       -- the sender's realm_voice.session when it was sent
  to_user       INT NOT NULL,
  place         VARCHAR(40) NOT NULL,
  kind          VARCHAR(8) NOT NULL,          -- offer / answer / ice
  body          TEXT NOT NULL,
  created_at    DATETIME NOT NULL,
  KEY idx_realm_signal_to (to_user, id),
  FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
