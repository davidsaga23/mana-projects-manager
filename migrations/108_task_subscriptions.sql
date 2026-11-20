-- 108 — subscribing to tasks on Spell.
--
-- David, 2026-11-20, on Spell's task rows: "we need a way to select all or by
-- and the tasks and add a subscription button, that will basically means that
-- whoever is subscribed to a task, will get notifications on all the tasks
-- they're subscribed to".
--
-- --- WHY A TABLE NOW, WHEN 060 SAID THERE WOULD BE NONE ----------------------
--
-- Migration 060 decided you follow a task by being ON it (the assignee, a second
-- name), by having SPOKEN on it, or by having been NAMED on it, all facts the
-- database already holds, so nobody has to tick anything. That still stands and
-- nothing here replaces it. What it cannot express is a producer or supervisor
-- who wants to hear about a task they have no part in yet, or somebody who wants
-- to stop hearing about one. This table holds only those two choices:
--
--   following = 1   subscribed by hand, on top of whatever 060 derives
--   following = 0   unsubscribed: muted, even if 060 would derive them
--
-- No row means "as 060 says", which is every task until somebody chooses.
-- includes/notify.php notify_subscribers() reads both; includes/task_follow.php
-- writes them.
--
-- ⚠ KEYED BY THE TASK (step_id + item_key), NOT progress.id. A task on Spell
-- has no `progress` row until somebody first sets something on it, and
-- subscribing to forty untouched tasks must not create forty empty progress
-- rows to hang a subscription on. step_id + item_key is the same pair
-- `progress` itself is unique on, so the two always match.
--
-- crew_id, not user_id: it is the person who is told, as on notifications.
--
-- notifications.kind gains 'update' too: "Ana updated Comp on SH020 · due 14
-- Jan". The events that used to ring nobody but a subscriber now wants to hear
-- (a plain comment, a new assignee, a moved due date, a version put up while the
-- task was already in review) ring the hand-subscribed under that one kind.
--
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS task_subscriptions (
  step_id    INT NOT NULL,
  item_key   VARCHAR(20) NOT NULL,
  crew_id    INT NOT NULL,
  project_id INT NOT NULL,
  following  TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (step_id, item_key, crew_id),
  KEY idx_person (crew_id, project_id),
  FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE notifications
  MODIFY kind ENUM('mention', 'assigned', 'status', 'review', 'reminder', 'raven', 'tag', 'later', 'dayoff', 'update') NOT NULL;
