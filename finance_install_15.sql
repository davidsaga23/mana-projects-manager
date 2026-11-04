-- finance_install_15 — an invoice's POSITION and its SENDER can differ.
--
-- ⚠ IMPORT THIS BY HAND, THROUGH phpMyAdmin, INTO THE FINANCE DATABASE.
-- migrate.php does not and must not touch this database. Run it ONCE.
-- Until it is imported, an invoice can already name a whole step (with nobody,
-- or somebody not on the crew, as the sender); naming a crew member as the
-- sender of an invoice on someone else's position, or on a whole step, says
-- this file is needed and saves nothing.
--
-- David, 2026-11-04, on MSD's invoice list: "for those unattached positions
-- let's make sure we can add it to the department (not the person) but it
-- happens sometimes we need to work with different artists but only one
-- position is there". CH Layout had one position and was invoiced by Luka,
-- James and Diego.
--
-- THE SHAPE. crew_id stays WHO SENT the invoice: their statement and Home list
-- it, exactly as now. step_id stays what it pays for. This column says whose
-- position on that step it counts against:
--   NULL  the sender's own (every invoice recorded before this file),
--   0     the step itself, no position ("not on a position" on the projection),
--   N     crew member N's position on that step.
-- No existing row is changed: every one keeps NULL and reads as it always did.

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN position_crew_id INT NULL DEFAULT NULL AFTER step_name;
