-- 070 — who may change a show's budget once its kick-off is signed off.
--
-- David, 2026-10-28: "Only Jorik can raise a budget. I can make the budget
-- static after the project sign off but he'll need to change it manually.
-- Let's implement this already."
--
-- WHO is a tick on an account, not a name in the code, the same way
-- users.signs_off_kickoff (049) is: a new COO is a tick rather than a deploy.
-- It is ticked on the Kick-off page, "Who signs off" tab, by an administrator.
--
-- Only an ADMINISTRATOR's tick does anything, because the budget lives in the
-- finance database and only administrators can see money at all
-- (can_see_money() in includes/money.php). The tick is refused for any other
-- role when it is saved.
--
-- Before this runs, a signed-off show's budget is locked for EVERYBODY, and the
-- budget box says this migration is the reason. Shows not yet signed off are
-- edited exactly as before.
--
-- ⚠ THE HISTORY IS NOT IN HERE. Each change after sign-off (old amount, new
-- amount, who, when, why) is written to the FINANCE database, in the
-- budget_changes table from finance_install_10.sql, imported by hand.

ALTER TABLE users
  ADD COLUMN changes_budget TINYINT(1) NOT NULL DEFAULT 0;
