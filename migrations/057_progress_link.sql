-- 057 — a link on a task, for the Board.
--
-- David, 2026-10-22, moving the studio off Plaky: the board his team works on
-- every day carries a Link column, and the real MSD export shows what goes in
-- it — Dropbox Replay share addresses on the pre-production deliverables, a
-- LucidLink address on a File prep task. It is how a version is handed over
-- today, and the only thing on that board Anima had nowhere to put.
--
-- ⚠ A LINK, NOT A FILE. This platform deliberately does not host media (the
-- "online version review" caution in docs/ROADMAP.md): shared cPanel hosting is
-- the wrong place for render files, and Replay and LucidLink already do that
-- job. Storing the ADDRESS keeps review state attached to the task that was
-- reviewed, which is the part that actually matters, without this becoming a
-- video host. When proper versions are built, this column is what they grow
-- from — a version record already has somewhere to point.
--
-- On `progress`, beside `version` and `notes` (migration 020), for the same
-- reason those are there: it describes a STEP'S WORK on an item, not the item.
-- SH010 has one link for its File prep and a different one for its Animation.
--
-- 500 characters: a Replay share address runs to about 50, a signed cloud
-- address with a token can run to several hundred, and a column that silently
-- truncates somebody's link is worse than one that is generous.
--
-- Left NULL everywhere. Nothing is backfilled: the Plaky importer never read a
-- Link column, so there is nothing in this database to move into it, and an
-- empty cell is the honest answer until somebody pastes one in.

ALTER TABLE progress
  ADD COLUMN link VARCHAR(500) DEFAULT NULL AFTER notes;
