-- 082 — The Realm, stage 2: written chat.
--
-- David, 2026-11-12, once the village was live: "what's the next step? I'd like
-- to see if we can open a chat or a voice chat". Chat first, because starting a
-- voice call needs the same message delivery, and because chat can be tried by
-- one person alone.
--
-- realm_messages: every message, in one table. `channel` says where it was said:
--   'announcements', 'tavern', 'council'   the places everybody shares
--   'project:12'                           a show's war room, by project id (a
--                                          show keeps its chat even if it moves
--                                          to another piece of land)
--   'dm:3:7'                               a raven between two people, the two
--                                          user ids with the lower one first
-- user_id goes NULL, not away, when an account is deleted: what somebody said
-- stays in the conversation it was part of.
--
-- realm_reads: the last message each person has seen in each channel, which is
-- all an unread count needs.
--
-- Until this runs the Realm works exactly as before, with no chat in it.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_messages (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  channel     VARCHAR(40) NOT NULL,
  user_id     INT DEFAULT NULL,
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_realm_msg_channel (channel, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_reads (
  user_id  INT NOT NULL,
  channel  VARCHAR(40) NOT NULL,
  last_id  BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, channel),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The bell learns one more kind (David, 2026-11-12: a raven for somebody who is
-- away should "show in Anima's bell"). The list repeats every earlier value,
-- because MODIFY replaces the whole ENUM; re-running it changes nothing.
ALTER TABLE notifications
  MODIFY kind ENUM('mention', 'assigned', 'status', 'review', 'reminder', 'raven') NOT NULL;
