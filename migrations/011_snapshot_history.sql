-- Make daily_snapshots actually recordable.
--
-- The table has existed since the v1 baseline and nothing has ever written to
-- it. That is worth fixing before anything that reads it, because of an
-- asymmetry the rest of this app doesn't have: a report screen can be built at
-- any time, but history cannot be backfilled. Every day that passes unrecorded
-- is a day of trend data gone for good — and "are we ahead or behind, and is it
-- getting better or worse" is the one question that needs history rather than a
-- current count.
--
-- Three additions, all for things the baseline columns can't express.

-- 1. ITEMS, NOT JUST SHOTS -----------------------------------------------------
-- The baseline counts shots_delivered_cum / shots_approved_cum, but a pipeline
-- step can apply to assets, or to both. There was no way to record "18 of 22
-- assets are through modelling".
--
-- The shot columns are KEPT rather than repurposed: for a step applying to both,
-- "how many shots are through comp" and "how many items are through comp" are
-- genuinely different questions, and a producer asks the first one. Items is the
-- complete answer; shots is the one people quote.
ALTER TABLE daily_snapshots
  ADD COLUMN items_delivered_cum INT NOT NULL DEFAULT 0 AFTER snapshot_date;

ALTER TABLE daily_snapshots
  ADD COLUMN items_approved_cum INT NOT NULL DEFAULT 0 AFTER items_delivered_cum;

-- How many items this step could have had on that day. Stored rather than
-- derived, because the breakdown grows: a shot added in October would make every
-- September percentage recompute against a total that didn't exist yet, and the
-- history would silently rewrite itself.
ALTER TABLE daily_snapshots
  ADD COLUMN items_total INT NOT NULL DEFAULT 0 AFTER items_approved_cum;

-- 2. BLOCKED WORK OVER TIME ----------------------------------------------------
-- Blocked is already reported separately everywhere else (it counts as remaining
-- work, but it needs someone to unblock it rather than an artist). As a trend it
-- says something a single number can't: three blocked shots for six weeks is a
-- different problem from three blocked shots since yesterday.
ALTER TABLE daily_snapshots
  ADD COLUMN blocked_count INT NOT NULL DEFAULT 0 AFTER items_total;

-- 3. AN INDEX FOR THE "HAS TODAY BEEN RECORDED?" CHECK -------------------------
-- That question runs on every page view, so it must be cheap. The existing
-- UNIQUE (step_id, snapshot_date) can't serve it — snapshot_date isn't the
-- leftmost column.
ALTER TABLE daily_snapshots
  ADD INDEX idx_snapshot_date (snapshot_date);
