-- Phase 10 — time logged per person, per task, per DAY.
--
-- `progress.time_spent_minutes` has existed since Phase 3 and is one running
-- total per task, arriving from Plaky. It can say a shot took nine hours. It
-- cannot say who spent them or when, so it cannot answer either of the two
-- questions the figure was wanted for (DECISIONS, 2026-08-12): checking an
-- invoice, and comparing what a task was estimated at against what it took.
--
-- This is the per-day entry that makes "actual" mean effort rather than elapsed
-- duration — the gap ROADMAP has been carrying since the estimate baseline was
-- built, and the same gap that makes per-person pace approximate today.
--
-- MINUTES, AS A WHOLE NUMBER. Hours are what people see and type; minutes are
-- what is stored. An integer count of minutes is exact, and 20 minutes as a
-- decimal hour is 0.333… — a column of those summed against an invoice drifts.
-- The column name carries its unit so nobody has to guess. Exactly the reasoning
-- of the 2026-08-12 time decision, now applied to the table that decision said
-- would eventually be needed.

CREATE TABLE IF NOT EXISTS time_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,

  -- Exactly one of these is set. A production task is a `progress` row — a step
  -- against a shot or an asset, which is what carries an assignee. A ticket is
  -- the other kind of work somebody does all day. Both are worth logging against
  -- and neither is the other, so they get a column each rather than a polymorphic
  -- id nobody can join on.
  progress_id INT DEFAULT NULL,
  ticket_id INT DEFAULT NULL,

  -- Who did the work. NOT NULL: an hour nobody worked is not a fact about
  -- anything, and this is the column every per-person figure is built on.
  crew_id INT NOT NULL,

  -- The day the work HAPPENED, not the day it was typed in. People log Friday
  -- on Monday morning, and a table that recorded the typing would put the week's
  -- work in the wrong week — which is precisely the error a pace chart cannot
  -- survive.
  log_date DATE NOT NULL,
  minutes INT NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,

  -- Who TYPED it, which is often not who did the work: a coordinator entering a
  -- whole team's day is normal. Kept separate so "Ana logged 6 hours" and
  -- "somebody logged 6 hours for Ana" are distinguishable.
  entered_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  KEY idx_crew_date (crew_id, log_date),
  KEY idx_project_date (project_id, log_date),
  KEY idx_progress (progress_id),
  KEY idx_ticket (ticket_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (progress_id) REFERENCES progress(id) ON DELETE CASCADE,
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE,
  FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DELIBERATELY NO UNIQUE KEY. Two entries for the same person, task and day are
-- correct, not a mistake: an hour before lunch and three after is two facts, and
-- collapsing them would lose the shape of a day. The running total is a SUM, and
-- it is computed rather than stored so it can never disagree with its own parts.
--
-- No foreign key on ticket_id: `tickets` arrives in migration 017, which every
-- current install has, but a constraint here would make this migration fail on
-- one that somehow doesn't — and the join is guarded in code either way.
--
-- WHAT HAPPENS TO progress.time_spent_minutes: it stays, and it stays meaning
-- "what Plaky said". It is not migrated into here and not deleted. Where both
-- exist the app prefers the SUM of these rows and labels which it is showing —
-- real entries beat an imported total, the same way a real shot count beats
-- project_settings.estimated_shots. Two sources for one number is only a trap
-- when nothing says which one you are looking at.
