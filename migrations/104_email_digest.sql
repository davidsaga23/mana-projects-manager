-- 104 - Two email summaries a day instead of one email per thing.
--
-- David, 2026-11-17: "instead of sending an email for each mention, new task
-- or comment, let's send 2 instead. One at the beginning of the day (around
-- 9 am PST) and one at the end (5 pm PST) with a summary of new stuff for that
-- person."
--
-- THE SUMMARY IS READ FROM THE BELL (notifications, migration 060). Everything
-- that used to send an email one at a time now rings the bell instead, and
-- includes/digest.php gathers what is still unread in each person's bell into
-- the 9 am and 5 pm Pacific emails. So there is no second list of "things to
-- email" to keep in step with the first: these two tables only remember WHO
-- has been sent WHAT, and when the sender last ran.
--
-- digest_people: one row per person, created the first time they are sent a
-- summary (or choose a setting on Home). No row means the default, both emails.
--   pref       both / morning / evening / off, chosen on Home > General info
--   last_slot  the last summary they were sent, e.g. '2026-11-17 am'. It is
--              claimed BEFORE the email goes out, so two senders running at the
--              same moment can never both email the same person the same slot.
--   upto_id    the highest bell item already looked at for them: the next
--              summary starts after it, so nothing is ever listed twice.
--
-- digest_runs: one row each time the sender ran (from the cPanel cron job, or
-- from the fallback in the bell's 30-second check), so "did this morning's go
-- out?" has an answer, and the fallback knows the cron already did it.
--
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS digest_people (
  crew_id    INT NOT NULL PRIMARY KEY,
  pref       ENUM('both', 'morning', 'evening', 'off') NOT NULL DEFAULT 'both',
  last_slot  VARCHAR(16) NULL,
  upto_id    INT NULL,
  sent_at    DATETIME NULL,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS digest_runs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slot_key    VARCHAR(16) NOT NULL,           -- '2026-11-17 am' / '2026-11-17 pm', Pacific dates
  via         VARCHAR(10) NOT NULL,           -- 'cron' or 'web' (the fallback)
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NULL,
  sent        INT NOT NULL DEFAULT 0,
  failed      INT NOT NULL DEFAULT 0,
  complete    TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = everybody due this slot was dealt with
  note        VARCHAR(255) NULL,
  KEY idx_digest_slot (slot_key, complete)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
