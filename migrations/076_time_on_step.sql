-- 076 — Time logged on a STEP, with no particular task.
--
-- David, 2026-11-05, on the Expense projection's Actual days column: "make this
-- column editable for admins so we can log their time manually if needed", and,
-- asked where that time should sit: "On the step, no task".
--
-- Until now an entry was against exactly one of a task (progress_id) or a card
-- (ticket_id), and a task is one step on one shot or asset. Days typed on the
-- projection belong to a person on a step, not to any shot, and putting them on
-- a shot nobody chose would make that shot look slower than it was. So a third
-- target: step_id, set ONLY when the other two are empty. Such an entry counts
-- for its step everywhere time is split by step (Actual days, Logged x rate,
-- the statement, Home), and touches no shot.
--
-- NULL on every existing entry: nothing already logged changes. Until this
-- runs, the Actual days cell stays read-only and says this migration is needed.

ALTER TABLE time_logs
  ADD COLUMN step_id INT DEFAULT NULL AFTER ticket_id;

ALTER TABLE time_logs
  ADD KEY idx_step (step_id);

ALTER TABLE time_logs
  ADD CONSTRAINT fk_time_logs_step FOREIGN KEY (step_id) REFERENCES pipeline_steps(id) ON DELETE CASCADE;
