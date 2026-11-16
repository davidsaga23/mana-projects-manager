-- 100 — A person's birthday, month and day only, for the Realm's celebrations.
--
-- David, 2026-11-16: "Celebrations: but only for birthdays, and only if they're
-- available in their profile. Their house should be decorated with balloons, and
-- colors so everyone knows." Agreed the same day: optional, filled in by each
-- person in their own profile (Home, or the portrait in their Realm house), and
-- month and day only, never the year, so nobody's age is kept.
--
-- On `crew`, next to phone and bio (migration 075). Both NULL by default: no
-- birthday is the normal state, and that person simply gets no celebration.
-- Until this runs the profile does not offer the field and nobody is decorated.

ALTER TABLE crew
  ADD COLUMN birth_month TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE crew
  ADD COLUMN birth_day TINYINT UNSIGNED DEFAULT NULL;
