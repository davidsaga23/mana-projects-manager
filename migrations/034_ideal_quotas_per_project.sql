-- 034 — ideal quotas belong to each project.
--
-- David, 2026-09-17: "between projects (even if they're the same type) might have
-- different ideal quotas and that will change my budget so this needs to be per
-- project. We can start copying another one but if I change something it
-- shouldn't affect other projects at all."
--
-- Since 2026-08-17 a step planned on its project TYPE's sheet wherever that sheet
-- had a number, so one change reached every show of the type. From here a step's
-- own days per item is the number: the schedule, the quotas and the budget all
-- read it, and a change to it reaches nothing else. The code switches over the
-- moment this file is recorded as applied, not before.
--
-- ⚠ NOTHING ANY PROJECT PLANS ON CHANGES TODAY. Every step that was planning on a
-- sheet's number gets that number copied into its own column, so each date and
-- each budget reads the same straight after as straight before. Templates get the
-- same treatment from the sheet of the type they were saved for, so a project
-- started from one begins where it would have begun yesterday.
--
-- The sheets stay in quota_standards, untouched and no longer read. The values
-- being overwritten, which the plan was already ignoring, are kept in a side
-- table first rather than lost.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS, INSERT IGNORE (the first capture
-- wins), and UPDATEs that write the same numbers again.

CREATE TABLE IF NOT EXISTS days_per_unit_before_034 (
  source_table VARCHAR(30) NOT NULL,
  row_id INT NOT NULL,
  days_per_unit DECIMAL(6,3) NOT NULL,
  captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (source_table, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO days_per_unit_before_034 (source_table, row_id, days_per_unit)
SELECT 'pipeline_steps', id, days_per_unit FROM pipeline_steps;

INSERT IGNORE INTO days_per_unit_before_034 (source_table, row_id, days_per_unit)
SELECT 'pipeline_template_steps', id, days_per_unit FROM pipeline_template_steps;

-- The match effective_days_per_unit() made until today: type, step name, what it
-- applies to and asset type, ignoring case (the collation) and outer spaces.
UPDATE pipeline_steps ps
  JOIN projects p ON p.id = ps.project_id
  JOIN quota_standards qs
    ON qs.project_type = p.type
   AND qs.step_name = TRIM(ps.name)
   AND qs.applies_to = ps.applies_to
   AND qs.applies_to_asset_type = TRIM(COALESCE(ps.applies_to_asset_type, ''))
   SET ps.days_per_unit = qs.days_per_unit,
       ps.days_source = LEFT(CONCAT('The ', p.type, ' sheet, when quotas became per project'), 120)
 WHERE qs.days_per_unit > 0;

UPDATE pipeline_template_steps ts
  JOIN pipeline_templates t ON t.id = ts.template_id
  JOIN quota_standards qs
    ON qs.project_type = t.project_type
   AND qs.step_name = TRIM(ts.name)
   AND qs.applies_to = ts.applies_to
   AND qs.applies_to_asset_type = TRIM(COALESCE(ts.applies_to_asset_type, ''))
   SET ts.days_per_unit = qs.days_per_unit
 WHERE qs.days_per_unit > 0;
