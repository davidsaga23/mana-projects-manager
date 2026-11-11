-- finance_install_17 — a monthly salary for salaried staff, and the day rate worked out from it.
--
-- FINANCE DATABASE. Import by hand in phpMyAdmin, into the finance database,
-- once — never through migrate.php, which only knows the production database.
--
-- David, 2026-11-11: "Junior is right now considered as a salaried artist, so
-- we need to adjust the way his day rate is calculated and a place to put his
-- monthly salary so we can calculate his daily rate here ... Daily rate cost
-- should be calculated on 21 days per month ... It should be automatically
-- grayed out and state that this is a salary calculation without an option to
-- be edited in this context." Asked whether to store his own salary or one
-- shared figure, he chose his own (this replaces the rule of 2026-10-28 that no
-- personal salary is stored; see docs/DECISIONS.md).
--
-- When monthly_salary is set, the person's day rate IS monthly_salary ÷ 21,
-- per day, in the terms' currency: includes/money.php works it out on saving
-- and again on reading, so every page that prices a day (the expense
-- projection, the statements, the invoices) uses it, and no rate box can set
-- anything else. Empty means an ordinary rate, as before.
--
-- Until this is imported the salary box on the person page says so, and every
-- rate works exactly as it did.

ALTER TABLE crew_terms
  ADD COLUMN monthly_salary DECIMAL(12,2) DEFAULT NULL AFTER rate_currency;

-- The history mirrors the terms, so a salary change is recorded like a rate.
ALTER TABLE crew_terms_log
  ADD COLUMN monthly_salary DECIMAL(12,2) DEFAULT NULL AFTER rate_currency;
