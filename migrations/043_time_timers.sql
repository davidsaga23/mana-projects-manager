-- 043 — a running timer, so hours can be measured instead of remembered.
--
-- David, 2026-10-08: "add a pomodoro or track time option at the end of each
-- task so the user can log time automatically."
--
-- Every hour in this platform is currently TYPED. Somebody finishes a day and
-- reconstructs it from memory into a box that says "1.5", and that number is
-- what the expense projection prices, what timelog_by_person() reports and what
-- the stop-cost panel reads. Remembered hours round to halves, forget the short
-- jobs entirely, and are always a little generous. A timer measures.
--
-- ⚠ THE RUNNING TIMER LIVES HERE, NOT IN THE BROWSER. The obvious build is a
-- setInterval and a number in localStorage, and it loses the afternoon the first
-- time a tab is closed, a laptop sleeps or a browser crashes — silently, with no
-- way to tell afterwards that anything was lost. A timer that quietly drops
-- three hours of somebody's work is the exact failure this codebase keeps paying
-- for (Lesson 11). Stored server-side it survives the tab, and you can start it
-- at the studio and stop it at home.
--
-- ⚠ crew_id IS THE PRIMARY KEY, AND THAT IS THE FEATURE. One running timer per
-- person, enforced by the schema rather than by remembering to check: nobody
-- works on two things at the same moment, and two concurrent timers would double
-- count the same hour into two different projects. Starting a second timer stops
-- the first and logs it (see start_timer() in includes/timelog.php).
--
-- NOTHING IS WRITTEN TO time_logs UNTIL THE TIMER STOPS. A running timer is not
-- yet a fact about the day — it is a claim in progress — and a half-finished
-- entry sitting in the ledger would be counted by every total that reads it.
--
-- project_id/progress_id/ticket_id mirror time_logs exactly, so stopping a timer
-- is a straight hand-off into log_time() with no translation that could drift.
--
-- ON DELETE CASCADE throughout: a timer is about work that still exists. If the
-- card, the tracked row or the person goes, a stopwatch pointing at nothing is
-- not worth keeping, and it has never been written to the ledger so nothing is
-- lost by dropping it.

CREATE TABLE IF NOT EXISTS time_timers (
  crew_id INT NOT NULL,
  project_id INT NOT NULL,
  -- Exactly one of these is set, the same rule log_time() follows. Not enforced
  -- by a constraint because MySQL 5.7 has no CHECK; enforced in start_timer().
  progress_id INT DEFAULT NULL,
  ticket_id INT DEFAULT NULL,
  started_at DATETIME NOT NULL,
  PRIMARY KEY (crew_id),
  KEY idx_timer_progress (progress_id),
  KEY idx_timer_ticket (ticket_id),
  CONSTRAINT fk_timer_crew FOREIGN KEY (crew_id)
    REFERENCES crew(id) ON DELETE CASCADE,
  CONSTRAINT fk_timer_project FOREIGN KEY (project_id)
    REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_timer_progress FOREIGN KEY (progress_id)
    REFERENCES progress(id) ON DELETE CASCADE,
  CONSTRAINT fk_timer_ticket FOREIGN KEY (ticket_id)
    REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
