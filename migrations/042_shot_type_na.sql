-- 042 — "there is nothing of this kind in this shot", said out loud.
--
-- David, 2026-10-08, looking at the Character picker on SH160: "let's add an
-- N/A option for all the projects because it might be that some of them we
-- don't need anything."
--
-- Until now the breakdown had no way to tell these two apart:
--
--   nobody has filled this cell in yet
--   this shot genuinely has no character in it
--
-- Both were an empty cell. That is the gap this table closes, and it is worth a
-- table because BOTH READERS OF THE BREAKDOWN GET IT WRONG without it:
--
--   * shots.php counts a shot with no links as "nothing linked yet" and nags
--     about it for ever, because the producer can never finish a cell that is
--     meant to stay empty;
--   * ⚠ includes/scheduler.php makes that shot WAIT FOR THE WHOLE ASSET STEP.
--     scheduler_item_earliest() resolves "a shot waits for the assets it uses"
--     only when the shot has some; with none of the right type it falls through
--     to "wait for the entire step", so a shot with no characters waits for the
--     last character in the show to be finished before it can start. Always in
--     the pessimistic direction, which reads as "we need more people" rather
--     than as missing data.
--
-- So this is not cosmetic. Marking a cell N/A shortens the critical path, and
-- that is the whole reason the column deserves storage rather than a comment in
-- the shot's notes field.
--
-- PER CELL, NOT PER SHOT. One row is one shot × one asset type, because that is
-- the question actually being answered: SH160 needs no Character, but it still
-- needs its Environment and its BG. A per-shot "needs nothing" flag would be
-- true of almost no shot and would tell the scheduler nothing useful.
--
-- asset_type MATCHES assets.asset_type: VARCHAR(50). It is a free-text type
-- name, not an id, for the same reason the breakdown columns are generated from
-- it — "vehicles, props, graphics, or any other asset" arrives by typing it on
-- an asset, with no migration. '' IS A REAL VALUE here: it is the "No type set"
-- column the grid draws at the end for assets nobody has filed yet.
--
-- ⚠ RENAMING AN ASSET TYPE ORPHANS ITS N/A ROWS, and that is accepted. Rename
-- "Character" to "Characters" and the rows above still say "Character", which
-- no longer matches a column, so those cells read as unmarked again. That is
-- degradation in the SAFE direction: the cell goes back to "nobody has filled
-- this in yet", the warning returns, and the scheduler returns to its old
-- pessimistic wait. Nothing is claimed that is not true. The alternative —
-- keying on an asset-type id — is the schema change the generated-column design
-- was bought to avoid.
--
-- Rows die with their shot (ON DELETE CASCADE), exactly as shot_assets rows do.
-- There is no project_id, also exactly as shot_assets has none: every query
-- joins back to shots to scope it, and includes/breakdown.php says why.
--
-- IF NOT EXISTS so a re-run after a failure replays harmlessly, per
-- migrations/README.md.

CREATE TABLE IF NOT EXISTS shot_type_na (
  shot_id INT NOT NULL,
  asset_type VARCHAR(50) NOT NULL,            -- '' = the "No type set" column
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (shot_id, asset_type),
  FOREIGN KEY (shot_id) REFERENCES shots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
