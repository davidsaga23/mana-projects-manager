-- 058 — which of a show's statuses mean the work has actually BEGUN.
--
-- David, 2026-10-22, looking at SH160 on the Board: it read "Not started" while
-- its BG Layout said In progress. He gave the rule himself — a shot is in
-- progress "when any of the tasks inside has other status than Waiting to Start
-- or Ready to Start".
--
-- ⚠ THE DATABASE COULD NOT ANSWER THAT QUESTION, which is why it was wrong.
-- `maps_to` says whether a status counts as delivered or approved; `is_blocked`
-- says it is stuck; `is_excluded` says it does not apply. NOTHING said whether
-- somebody has picked the work up. "Waiting to start", "Ready to start", "In
-- progress" and "Retake" all map to 'none', so the board treated all four as
-- untouched and a shot stayed "Not started" until something was finished.
--
-- So the vocabulary gains a fourth fact, exactly as it gained is_blocked and
-- is_excluded before it — the project's own statuses carrying their own
-- meaning, rather than any screen matching on names it cannot predict.
--
-- DEFAULT 1, because almost every status a studio invents means work is under
-- way: In progress, Retake, Pending review, On hold, Final. The exceptions are
-- the one or two at the very start, and they are seeded below.
--
-- ⚠ THE SEED IS AN EXPLICIT LIST OF SPELLINGS, never a fuzzy match — the same
-- rule PLAKY_COLUMN_ALIASES follows for the same reason. It gets every show
-- Anima holds today right on the first load; any show that calls its opening
-- state something else is one tick away on the Statuses screen, which is the
-- point of putting it on the vocabulary rather than in the code.
--
-- Safe to run twice: the UPDATE names the values it sets.

ALTER TABLE statuses
  ADD COLUMN counts_as_started TINYINT(1) NOT NULL DEFAULT 1 AFTER is_blocked;

UPDATE statuses
   SET counts_as_started = 0
 WHERE LOWER(TRIM(name)) IN (
         'waiting to start', 'waiting', 'not started', 'to do', 'todo',
         'ready to start', 'ready', 'backlog', 'pending'
       );
