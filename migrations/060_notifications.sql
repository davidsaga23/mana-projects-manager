-- 060 — the bell beside your name.
--
-- David, 2026-10-22, on being told that naming somebody "emails them and puts
-- it in their My tasks": "this is not the right behavior, you need to get a
-- notification in a bell close to your name so you know someone assign, tag or
-- changed a tasked you're subscribed to".
--
-- Three things reach the bell, which is exactly the three he named:
--   mention   somebody wrote your name in a comment
--   assigned  a task was handed to you
--   status    a task you are subscribed to moved
--
-- --- WHAT "SUBSCRIBED" MEANS ------------------------------------------------
--
-- ⚠ NOTHING TO TICK, AND NOTHING TO FORGET TO TICK. There is no subscription
-- table here on purpose. You follow a task if you are on it, have spoken on it,
-- or have been named on it — all three are facts the database already holds, so
-- a freelancer is subscribed to their own work from the moment it is theirs
-- without being taught what a subscription is. See notify_subscribers().
--
-- The rule that matters more than the rule: YOU ARE NEVER TOLD ABOUT YOUR OWN
-- ACTION. A bell that lights up because you moved a status is a bell people
-- stop reading within a day, and a bell people stop reading is worse than no
-- bell, because the one that mattered is in there too.
--
-- --- WHY THE WORDS ARE STORED ------------------------------------------------
--
-- ⚠ `actor` AND `summary` ARE COPIES, and deliberately so. A notification is a
-- record of a MOMENT — "Ana moved BG Layout on SH160 to Pending review" — and
-- re-deriving that sentence next week would quietly rewrite it the day a status
-- is renamed or a step is deleted. progress_log settled this same argument in
-- migration 021 and stores step_name, item_name and both status names beside
-- their ids for the same reason. The ids are what it MEANS; these are what it
-- SAID.
--
-- step_id and item_key travel alongside progress_id for the same reason they do
-- on progress_log: they are what builds the link back to the task, and they
-- survive a progress row being swept away.
--
-- crew_id, not user_id: `users` are logins and `crew` are people, and it is the
-- person who is told. Somebody with two logins is one person with one bell.
--
-- One statement, so it applies whole or not at all.

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crew_id INT NOT NULL,
  kind ENUM('mention', 'assigned', 'status') NOT NULL,

  -- What it said, at the moment it was said. See the note above.
  actor VARCHAR(120) NOT NULL DEFAULT '',
  summary VARCHAR(255) NOT NULL DEFAULT '',

  -- What it is about. All nullable and independent: a notification can point at
  -- a board task, at a ticket, or at a comment on either.
  project_id INT DEFAULT NULL,
  step_id INT DEFAULT NULL,
  item_key VARCHAR(20) DEFAULT NULL,
  progress_id INT DEFAULT NULL,
  ticket_id INT DEFAULT NULL,
  comment_id INT DEFAULT NULL,

  -- NULL means unread, which is the right default for everything in here.
  seen_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  KEY idx_bell (crew_id, seen_at, created_at),
  KEY idx_recent (crew_id, created_at),
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (progress_id) REFERENCES progress(id) ON DELETE CASCADE,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
