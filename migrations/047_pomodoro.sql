-- 047 — the running timer works in pomodoro cycles.
--
-- David, 2026-10-16: "when you hit start in the My tasks tab let's create
-- pomodoro cycles instead of just letting the time run".
--
-- Start now begins a 25-minute FOCUS block. When it runs out the minutes are
-- logged to the card and a break begins: 5 minutes, or 15 after every fourth
-- focus block. A break is never logged. The next focus block waits for a click,
-- because one that started itself while nobody was at the desk would log
-- twenty-five minutes nobody worked.
--
-- ⚠ THE SAME ROW, NOT A NEW TABLE. crew_id stays the primary key, so one timer
-- per person is still enforced by the schema (migration 043). A break is a state
-- of that one row rather than a second timer, and the card it belongs to stays
-- on it so the next focus block lands on the same work.
--
-- phase NULL is the old open stopwatch. A timer somebody had running when this
-- was applied keeps behaving exactly as it did when it was started, instead of
-- being cut down to twenty-five minutes after the fact. Every new Start writes
-- 'focus'.
--
-- started_at now means "when the CURRENT phase began". For a focus block that
-- is the same thing it always meant, which is what the minutes are measured
-- from and what the log date is taken from.

ALTER TABLE time_timers
  ADD COLUMN phase VARCHAR(12) DEFAULT NULL AFTER ticket_id,
  ADD COLUMN focus_round TINYINT NOT NULL DEFAULT 1 AFTER phase;
