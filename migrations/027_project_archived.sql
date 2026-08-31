-- 027 — an ARCHIVED status for projects.
--
-- WHY, and why not a delete (David, 2026-08-31): "or if it's not delete at
-- least archive". A finished test project, or a show that was quoted and never
-- greenlit, should leave the front door without anything being destroyed.
--
-- Deletion already exists on index.php but REFUSES whenever the project has a
-- budget, an invoice, an expense or a hiring quote — those live in the separate
-- finance database, which has no foreign key back to projects and which a
-- DELETE here cannot reach. Archiving is the answer for everything that refuses,
-- and the better answer for most things that would not.
--
-- 'wrapped' was already taken and means something specific: delivered. A
-- cancelled pitch is not wrapped, and reading it that way would quietly overstate
-- what the studio has finished.
--
-- ADDITIVE. MODIFY on an ENUM only widens the set of accepted values here; every
-- existing row keeps the value it has, and nothing in the app tests for a status
-- other than 'setup' (the wizard's own draft check).

ALTER TABLE projects
  MODIFY status ENUM('setup','active','on_hold','wrapped','archived')
         NOT NULL DEFAULT 'setup';
