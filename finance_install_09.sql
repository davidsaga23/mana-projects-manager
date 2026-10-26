-- finance_install_09 — invoice status is Expected, Pending or Paid.
--
-- FINANCE DATABASE. Import by hand in phpMyAdmin, into the finance database,
-- once — never through migrate.php, which only knows the production database.
--
-- David, 2026-10-26: the invoice status dropdown should say Expected, Pending,
-- Paid. The four it had (received, approved, paid, disputed) answered a
-- question nobody was asking; what Production tracks is whether an invoice is
-- still to come, has come and waits for payment, or has been paid.
--
--   expected  the work is done or under way and an invoice is due from them,
--             but it has not arrived yet
--   pending   it has arrived and is waiting to be paid (was received, approved
--             or disputed)
--   paid      paid, with its date in paid_date as before
--
-- ⚠ A DISPUTED INVOICE KEEPS SAYING SO. It becomes pending like the others, but
-- "Disputed." goes at the front of its note first, so the fact is not lost with
-- the status that held it.
--
-- Three steps, in this order, because MySQL refuses to put a value into an ENUM
-- that does not list it: widen the list, move the rows, then narrow it.

ALTER TABLE invoices
  MODIFY status ENUM('received','approved','paid','disputed','expected','pending') NOT NULL DEFAULT 'pending';

UPDATE invoices SET notes = CONCAT('Disputed.', IF(notes IS NULL OR notes = '', '', CONCAT(' ', notes)))
 WHERE status = 'disputed';

UPDATE invoices SET status = 'pending' WHERE status IN ('received', 'approved', 'disputed');

ALTER TABLE invoices
  MODIFY status ENUM('expected','pending','paid') NOT NULL DEFAULT 'pending';
