-- 069 — a candidate can be lined up for a supervision position.
--
-- David, 2026-10-26, the day supervision positions arrived (068): "we need to
-- line up candidates". A hiring record has always pointed at a slot through
-- lane_id; a supervision position is not a slot, so it gets its own pointer.
-- A record carries one or the other, never both (save_hire() enforces it).
--
-- No foreign key, so this file re-runs cleanly. Removing a position clears the
-- pointer by hand (supervision_delete()), which is what ON DELETE SET NULL on
-- lane_id does for a slot: the conversation outlives the position. The title is
-- copied into step_name, as a slot's step is, for the same reason.
ALTER TABLE hires
  ADD COLUMN supervision_id INT DEFAULT NULL AFTER lane_id;

ALTER TABLE hires
  ADD INDEX idx_hires_supervision (supervision_id);
