-- 053 — Snooze a card to a future date.
--
-- David, 2026-10-20: "let's also add a snooze button for tasks with a new date
-- for editors and admins so they can reschedule/disappear the task that needs to
-- be addressed in the future."
--
-- WHAT IT IS: a wake-up date on a card. While `snoozed_until` is in the future
-- the card drops out of every active list — My tasks, the board, Studio Tasks,
-- the step page — and it reappears on its own the day that date arrives. Nothing
-- is deleted or archived; the card is only hidden, so it cannot be lost.
--
-- ⚠ WHY A SEPARATE COLUMN AND NOT `due_date`. The snooze date answers "when do I
-- want to SEE this again", which is not the same question as "when is it DUE".
-- A card due Nov 10 can be snoozed until Nov 5 — it still has to be done by the
-- 10th, it just gets out of the way until the 5th. Folding the two together
-- would quietly rewrite deadlines every time somebody tidied their list, which
-- is exactly the silent data-loss this schema keeps paying to avoid. So snooze
-- is its own field and the due date is never touched (David's call, stated on
-- the thread, kept unless he says otherwise).
--
-- ⚠ WHO MAY SNOOZE is enforced in the app (can_edit() — Production + admins),
-- not here: a column has no opinion about roles, the same way `archived` doesn't.
--
-- ⚠ WHERE THE HIDING HAPPENS is one SQL fragment, includes/tickets.php
-- snooze_exclude_sql(), applied in every place that lists active cards — written
-- down there so it is not rediscovered one bug at a time, the same discipline
-- overhead_exclude_sql() (migration 052) keeps.
--
-- NULL = never snoozed (the default for every existing card). A date in the past
-- is harmless: the card is simply shown, because its wake-up day has come.

ALTER TABLE tickets
  ADD COLUMN snoozed_until DATE DEFAULT NULL AFTER due_date,
  ADD KEY idx_snoozed (snoozed_until);
