-- 080 - Reminders to log time.
--
-- David, 2026-11-11: "add pop up reminders for everyone to log times at the end
-- of the day and a button for admins to send and instant pop up for logging
-- times as an alternative". Settled the same day: 5 pm studio time, weekdays,
-- a one-hour snooze; anyone on a show that day, salaried staff included; email
-- only when an admin ticks it on an instant one.
--
-- The END-OF-DAY pop-up needs nothing stored: the page asks "is it past 5, is
-- this a working day, have I logged, am I on a show" and the answers are all in
-- the database already. Until this migration runs, that one still works.
--
-- The INSTANT one needs a signal the pages can read, so it is two tables: one
-- row per reminder sent (who sent it, when, whether it was emailed) and one row
-- per person it went to (when they put it away). Those rows are also the record
-- David asked for of who sent what.
--
-- notifications.kind is an ENUM, so the bell's new 'reminder' kind is added
-- here too. Until it is, a reminder simply does not ring the bell (writing it
-- as 'mention' would read "mentioned you on", which is not what happened).
--
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS log_reminders (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  sent_by     INT NULL,                       -- users.id of whoever pressed Send
  sent_by_name VARCHAR(120) NOT NULL DEFAULT '',
  sent_at     DATETIME NOT NULL,
  to_all      TINYINT(1) NOT NULL DEFAULT 0,  -- "everybody on a show today", as against chosen people
  emailed     TINYINT(1) NOT NULL DEFAULT 0,  -- the "also email them" tick
  note        VARCHAR(255) NULL,              -- an optional line from the sender
  KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS log_reminder_recipients (
  reminder_id  INT NOT NULL,
  crew_id      INT NOT NULL,
  dismissed_at DATETIME NULL,                 -- when they put the pop-up away
  PRIMARY KEY (reminder_id, crew_id),
  KEY idx_crew (crew_id, dismissed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE notifications
  MODIFY kind ENUM('mention', 'assigned', 'status', 'review', 'reminder') NOT NULL;
