-- 101 - Asking for days off.
--
-- David, 2026-11-16: "let's add a button to request days off in peoples home
-- page, that one need to be sent to production or admins for approval. New
-- requests should be available in bookings".
--
-- ⚠ A TABLE OF ITS OWN, NOT A STATE ON crew_absences. Everything that plans
-- around a day off (the scheduler, Home's missing-log days, the 5 pm log
-- reminder, Bookings' stripes and Days off tab, the Realm) reads crew_absences
-- and treats every row in it as a day the person is away. A pending request
-- must count NOWHERE; with a state column, every one of those readers (and the
-- next one written) would need "AND state = 'approved'", and the one that forgot
-- would quietly let somebody skip logging on a day nobody agreed to. Kept apart,
-- crew_absences still means "approved days off" without anybody being told, and
-- the rows already in it are approved by definition.
--
-- APPROVING writes a real crew_absences row through add_crew_absence() and
-- keeps its id here (absence_id), so the request remembers what it became.
-- DECLINING keeps the row, with an optional note, so the person sees the answer.
-- WITHDRAWING a pending request deletes it: nobody decided anything.
--
-- notifications.kind is an ENUM, so the bell's 'dayoff' kind is added here too.
-- Until it is, nothing rings (and there are no requests to ring about).
--
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS day_off_requests (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  crew_id      INT NOT NULL,
  from_date    DATE NOT NULL,
  to_date      DATE NOT NULL,
  reason       VARCHAR(120) NULL,
  state        ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
  requested_by VARCHAR(100) NULL,              -- the login's display name, as created_by elsewhere
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_by   VARCHAR(100) NULL,
  decided_at   DATETIME NULL,
  note         VARCHAR(255) NULL,              -- why it was declined (optional)
  absence_id   INT NULL,                       -- the crew_absences row an approval made
  KEY idx_dor_state (state, from_date),
  KEY idx_dor_crew (crew_id, requested_at),
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE,
  FOREIGN KEY (absence_id) REFERENCES crew_absences(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE notifications
  MODIFY kind ENUM('mention', 'assigned', 'status', 'review', 'reminder', 'raven', 'tag', 'later', 'dayoff') NOT NULL;
