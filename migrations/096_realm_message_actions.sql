-- 096 — The Realm's chat: Slack's message actions.
--
-- David, 2026-11-13, sending a screenshot of the little bar Slack shows over a
-- message: "let's add these same features from slack so the transition is
-- smoother". Settled with him the same day:
--   * forward a message to another channel or raven;
--   * save a message for later, with an optional reminder that rings Anima's
--     bell ("Save + reminders");
--   * in the "More" menu: edit and delete your OWN messages, copy a link to a
--     message, mark unread. (No pinning.)
--   * the three quick reactions are each person's three most used, from the
--     six the Realm already has (no new emoji).
-- Copy link and mark unread need nothing from this file; the rest does.
--
-- notifications.kind gains 'later': "Reminder about Renata's message in the
-- Realm", rung when a saved message's reminder time comes.
--
-- realm_saved: ONE LINE PER PERSON AND MESSAGE. Private: only ever read for the
-- session's own account. remind_at is NULL for "saved, no reminder"; rung turns
-- 1 once the bell has rung for it, so it rings once whichever page asks first.
--
-- realm_messages gains:
--   edited_at   when the author last changed the words ("(edited)");
--   deleted_at  when the author deleted it ("This message was deleted"). The row
--               stays, so a thread under it and the order of the channel stay too;
--               the words and the picture are emptied at that moment.
--   fwd_from, fwd_channel, fwd_user, fwd_at
--               on a forwarded copy: the original message, where it was said, who
--               said it and when. A copy of a copy keeps the ORIGINAL's.
--
-- Until this runs the Realm's chat works as before; the bar offers only what
-- needs no database change (reactions, threads, copy link, mark unread).
-- Safe to re-run: the ENUM line rewrites the whole list, the table is CREATE
-- TABLE IF NOT EXISTS, and the last statement stops a second run with
-- "Duplicate column name", which is harmless (everything before it is there).

ALTER TABLE notifications
  MODIFY kind ENUM('mention', 'assigned', 'status', 'review', 'reminder', 'raven', 'tag', 'later') NOT NULL;

CREATE TABLE IF NOT EXISTS realm_saved (
  user_id     INT NOT NULL,
  message_id  BIGINT NOT NULL,
  remind_at   DATETIME DEFAULT NULL,
  rung        TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (user_id, message_id),
  KEY idx_realm_saved_due (rung, remind_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (message_id) REFERENCES realm_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE realm_messages
  ADD COLUMN edited_at DATETIME DEFAULT NULL,
  ADD COLUMN deleted_at DATETIME DEFAULT NULL,
  ADD COLUMN fwd_from BIGINT DEFAULT NULL,
  ADD COLUMN fwd_channel VARCHAR(64) DEFAULT NULL,
  ADD COLUMN fwd_user INT DEFAULT NULL,
  ADD COLUMN fwd_at DATETIME DEFAULT NULL;
