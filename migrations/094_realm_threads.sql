-- 094 — The Realm's chat: threads.
--
-- David, 2026-11-13, chose threads for the Realm's chat: "reply to one message
-- without flooding the channel; long channels get unreadable without them".
-- Decided with him that day:
--   * any message in a channel OR in ravens can be replied to in a thread;
--   * a reply may also be "sent to the channel" with a tick box, as in Slack;
--   * replies in a thread you started or replied in count for you, both in that
--     channel's number and in a "Threads" row of their own; other people's
--     threads do not light anything up.
--
-- realm_messages.parent_id: the message a reply belongs to, or NULL for an
-- ordinary message. Replies stay in the same `channel` as their parent, so who
-- may read them is decided exactly as before (and realm_image.php needs no
-- change for a picture in a reply). Only one level: a reply has no replies.
-- realm_messages.also_channel: 1 when the reply was also sent to the channel.
--
-- realm_thread_reads: the last reply each person has seen in each thread, which
-- is all a thread's unread count needs (realm_reads does the same per channel).
--
-- Until this runs, chat works exactly as before, with no "Reply in thread".
-- Safe to re-run: the table is CREATE TABLE IF NOT EXISTS, and the last
-- statement stops a second run with "Duplicate column name", which is harmless
-- (the table before it is already there).

CREATE TABLE IF NOT EXISTS realm_thread_reads (
  user_id    INT NOT NULL,
  parent_id  BIGINT NOT NULL,
  last_id    BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, parent_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE realm_messages
  ADD COLUMN parent_id BIGINT DEFAULT NULL,
  ADD COLUMN also_channel TINYINT(1) NOT NULL DEFAULT 0,
  ADD KEY idx_realm_msg_parent (parent_id, id);
