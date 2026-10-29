-- 072 — Who is salaried staff, ticked on the Users page.
--
-- David, 2026-10-28: "Who counts as salaried: let's add that attribute in the
-- users tab". The money rules of the same day: salaried staff (David, Tim,
-- Renata, Junior) cost one blended internal day rate and never invoice;
-- freelancers send one invoice a month. Home (home.php) reads this to decide
-- whether a person sees invoice information, amounts and invoice status at all.
--
-- On the LOGIN, beside changes_budget (migration 070), because the Users page is
-- where David manages people's standing. home_is_salaried() asks whether any
-- login linked to the person is ticked.
--
-- Default 0: until somebody is ticked, everybody reads as a freelancer, which
-- shows invoice panels to staff (confusing) but never shows anybody else's money.

ALTER TABLE users
  ADD COLUMN is_salaried TINYINT(1) NOT NULL DEFAULT 0;
