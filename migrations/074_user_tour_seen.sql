-- 074 — When a login was first shown the guided tour.
--
-- David, 2026-10-30: "can you create a FTU for new users on how to use the
-- platform and where to find stuff?" The tour (assets/tour.js, decided in
-- includes/tour.php) opens by itself once, on a person's first login, and is
-- re-opened from "Tour" in the top bar. This is the "once": NULL means never
-- shown, and the header stamps it to NOW() the moment the tour opens by itself.
--
-- On the LOGIN, beside mytasks_seen_at (migration 053), because it is the
-- person signing in who is shown it.
--
-- ⚠ EXISTING LOGINS ARE LEFT NULL, so everybody already on Anima sees the tour
-- once too. Unlike 053's badge that is wanted: Home, Reviews and Spell are all
-- new this month, and the tour is the one place that shows where they are.
-- To spare current users instead, run after this:
--   UPDATE users SET tour_seen_at = NOW();
--
-- Until this runs the browser remembers "seen" instead (localStorage), so the
-- tour still opens only once per browser.

ALTER TABLE users
  ADD COLUMN tour_seen_at DATETIME DEFAULT NULL;
