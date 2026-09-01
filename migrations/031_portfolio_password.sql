-- 031 — the reel password, stored.
--
-- David, 2026-08-31: "let's add the portfolio password as a field ... so they're
-- easily accessible from the artists tab."
--
-- ⚠ THIS REVERSES A DECISION, twice made, and the reasoning is worth keeping
-- because the earlier one was not wrong so much as too broad.
--
-- On 2026-08-17 the paste parser met this column in the freelancer sheet and
-- refused it: "a plain-text password column is not something this database
-- should have." The importer built on 2026-08-31 refused it again. That instinct
-- is right about a CREDENTIAL — something that opens an account, where storing
-- it in the clear is indefensible.
--
-- This is not that. It is the password an artist puts on their OWN Vimeo or
-- Behance page and hands out so people can watch their reel. They print it in
-- their email signature. It opens nothing belonging to Mana, and nothing
-- belonging to the artist beyond the work they are showing you. Keeping it out
-- of the database did not make anybody safer; it just meant the reel could not
-- be opened from the screen that lists the reel.
--
-- The one real risk is people who reuse passwords, so this is named for exactly
-- what it is -- portfolio_password, beside portfolio_url -- and never invited to
-- be anything else. It is not a login, is never compared against one, and no
-- screen calls it "password" without saying which.
--
-- ADDITIVE.

ALTER TABLE hires
  ADD COLUMN portfolio_password VARCHAR(120) DEFAULT NULL AFTER portfolio_url;
