-- 093 — The Realm: reactions on chat messages.
--
-- David, 2026-11-13, choosing what the Realm's chat needed next: emoji
-- reactions, "the most-used Slack feature, a [thumbs up] saves typing 'ok'".
--
-- realm_reactions: ONE LINE PER PERSON, MESSAGE AND EMOJI. Reacting again with
-- the same emoji takes the line away (a toggle), which the unique key makes
-- safe to do twice at once. `emoji` is a short name from the fixed set in
-- includes/realm_chat.php (REALM_REACTIONS: 'up', 'heart', ...), never the
-- picture itself, so the column holds plain letters whatever the server's
-- character set.
--
-- A reaction goes with its message and with the person: deleting either takes
-- the line away. It is never counted as an unread message and never rings
-- Anima's bell.
--
-- Until this runs the Realm's chat works exactly as before, with no reactions.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_reactions (
  message_id  BIGINT NOT NULL,
  user_id     INT NOT NULL,
  emoji       VARCHAR(12) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (message_id, user_id, emoji),
  FOREIGN KEY (message_id) REFERENCES realm_messages(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
