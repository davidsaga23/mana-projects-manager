-- 060 — a person's days off, which the scheduler plans around.
--
-- David, 2026-10-22, from the roadmap ("Days off are a scheduler input, not an
-- HR note"): "Let's create this one too."
--
-- PER PERSON, NOT PER SHOW. A fortnight's holiday is a fortnight away from every
-- show the person is on, so the row hangs off `crew` alone. That is the
-- opposite of the engagement dates on `crew_projects` (migration 024), which
-- ARE per show — the same compositor starts here in September and there in
-- January — and the scheduler reads both: an absence blocks the days, an
-- engagement blocks everything outside its window.
--
-- PUBLIC HOLIDAYS ARE NOT HERE. They already live in `non_working_days`
-- (migration 010, the studio Calendar screen), which closes a day for everyone.
-- This table is for the days one person is away while the studio works.
--
-- A RANGE, both ends INCLUSIVE, matching the rule everywhere else in the
-- platform. One row for "away 3 to 14 November" rather than ten rows, because
-- that is how a holiday is asked for and how it is cancelled.
--
-- ON DELETE CASCADE: an absence means nothing without its person, and the merge
-- tool (people_merge.php) re-points what it wants to keep before it deletes.
CREATE TABLE IF NOT EXISTS crew_absences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  crew_id INT NOT NULL,
  from_date DATE NOT NULL,
  to_date DATE NOT NULL,
  reason VARCHAR(120) DEFAULT NULL,
  created_by VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_crew_absences_crew (crew_id, from_date),
  KEY idx_crew_absences_dates (from_date, to_date),
  FOREIGN KEY (crew_id) REFERENCES crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
