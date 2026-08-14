-- The studio follows the US holiday calendar, not the Mexican one.
--
-- Migration 010 seeded Mexico's statutory days (Ley Federal del Trabajo art. 74)
-- on the reasonable-looking but wrong assumption that a studio in Querétaro
-- keeps Mexican holidays. Mana works to US client calendars, so those rows are
-- wrong twice over: they mark days the team actually works as days off, and they
-- leave the days the team really does take counted as workable.
--
-- Both errors feed straight into every quota and every scheduled date, which is
-- why this is worth a migration rather than a note.

-- 1. REMOVE WHAT 010 SEEDED ---------------------------------------------------
-- Matched on date AND name together, deliberately. If somebody has since renamed
-- a row, or added their own entry that happens to fall on one of these dates,
-- it does not match and is left alone. Only the untouched seed rows go.
DELETE FROM non_working_days WHERE (day, name) IN (
  ('2026-01-01', 'Año Nuevo'),
  ('2026-02-02', 'Día de la Constitución'),
  ('2026-03-16', 'Natalicio de Benito Juárez'),
  ('2026-05-01', 'Día del Trabajo'),
  ('2026-09-16', 'Independencia'),
  ('2026-11-16', 'Revolución Mexicana'),
  ('2026-12-25', 'Navidad'),
  ('2027-01-01', 'Año Nuevo'),
  ('2027-02-01', 'Día de la Constitución'),
  ('2027-03-15', 'Natalicio de Benito Juárez'),
  ('2027-05-01', 'Día del Trabajo'),
  ('2027-09-16', 'Independencia'),
  ('2027-11-15', 'Revolución Mexicana'),
  ('2027-12-25', 'Navidad')
);

-- 2. THE US DAYS ALMOST EVERY COMPANY CLOSES FOR ------------------------------
-- Seeded because they are effectively universal in a US-facing studio. The
-- federal calendar has eleven days on it; the ones NOT here — MLK Day,
-- Presidents' Day, Juneteenth, Columbus Day, Veterans Day — are genuinely
-- split, plenty of studios work them, and seeding a day the team actually works
-- pads every schedule silently. Those are offered as tick-boxes on the calendar
-- screen instead, where a person decides.
--
-- Dates are the OBSERVED day, not the nominal one: 4 July 2026 falls on a
-- Saturday and the day actually taken is Friday the 3rd. A holiday landing on a
-- weekend costs nothing anyway — working_days_between() already skips weekends —
-- so recording the nominal date would be a row that changes no answer.
INSERT IGNORE INTO non_working_days (day, name) VALUES
  ('2026-01-01', 'New Year''s Day'),
  ('2026-05-25', 'Memorial Day'),
  ('2026-07-03', 'Independence Day (observed)'),
  ('2026-09-07', 'Labor Day'),
  ('2026-11-26', 'Thanksgiving'),
  ('2026-11-27', 'Day after Thanksgiving'),
  ('2026-12-25', 'Christmas Day'),
  ('2027-01-01', 'New Year''s Day'),
  ('2027-05-31', 'Memorial Day'),
  ('2027-07-05', 'Independence Day (observed)'),
  ('2027-09-06', 'Labor Day'),
  ('2027-11-25', 'Thanksgiving'),
  ('2027-11-26', 'Day after Thanksgiving'),
  ('2027-12-24', 'Christmas Day (observed)');

-- Any schedule saved before this ran was calculated against the wrong calendar.
-- The figures on the planning and schedule screens recalculate on sight, but a
-- SAVED schedule is a stored snapshot — re-run and save it to pick these up.
