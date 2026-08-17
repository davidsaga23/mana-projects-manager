-- ---------------------------------------------------------------------------
-- FINANCE DATABASE — schema, part 3: which POSITION an invoice is for.
--
-- ONLY RUN THIS IF YOU ALREADY IMPORTED finance_install_02.sql.
-- If you have not, import 02 instead — it now contains these two columns and
-- this file would fail on it with "duplicate column".
--
-- WHY: actual cost comes from invoices, not from days times a rate. Days times a
-- rate is a second estimate wearing an actual's clothes; only an invoice records
-- what was really billed (David, 2026-08-18).
--
-- To attach an invoice to a row of the expense projection, it has to name the
-- POSITION rather than just the person. Somebody holding lanes on both Layout
-- and Color is two positions at possibly two rates, and "who" alone cannot say
-- which of them a payment was for. crew_id + step_id together are the lane.
--
-- Both nullable: an invoice that covers no particular position — a kill fee, a
-- one-off, a bulk settlement — is a real thing. Forcing a step onto it would put
-- a fiction into a subtotal. Those are counted in the project total and simply
-- do not attach to a projection row, which the screen says out loud.
-- ---------------------------------------------------------------------------

ALTER TABLE invoices
  ADD COLUMN step_id INT DEFAULT NULL AFTER crew_name;

ALTER TABLE invoices
  ADD COLUMN step_name VARCHAR(100) DEFAULT NULL AFTER step_id;

ALTER TABLE invoices
  ADD INDEX idx_position (crew_id, step_id);
