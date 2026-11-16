-- The Realm's Activity list (David, 2026-11-16): one list of everything that is
-- FOR YOU, as in Slack, so nothing gets lost when channels are busy.
--
-- The list itself needs no table: it is read from the messages, threads and
-- reactions that are already kept (includes/realm_activity.php). This table only
-- remembers what this person has marked as read IN the list:
--   * item = '*'                   "Mark all as read": everything that happened
--                                  before `at` is read.
--   * item = 'm:<message id>'      one message row (a mention, a reply, a raven)
--   * item = 'r:<message>:<user>:<emoji>'   one reaction row
-- A message is also read once its conversation (or thread) has been read in the
-- chat, through realm_reads / realm_thread_reads, so the list and the channel
-- counts agree. Until this has run, the list still shows; only reactions cannot
-- be marked read, and the page says so.

CREATE TABLE IF NOT EXISTS realm_activity_reads (
  user_id  INT NOT NULL,
  item     VARCHAR(64) NOT NULL,
  at       DATETIME NOT NULL,
  PRIMARY KEY (user_id, item),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
