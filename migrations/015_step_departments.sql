-- Which phase of production a step belongs to.
--
-- "Above the line / Pre-production / Production / Post-production" is the
-- top-level grouping of Mana's expense projection and its subtotal boundary,
-- and it is how a crew list, a call sheet and a Gantt are all read. The
-- platform had no way to say it: step_order and the dependency graph describe
-- what runs WHEN, not what a step IS.
--
-- WHY A COLUMN RATHER THAN FOLDING IT INTO THE NAME
-- The obvious shortcut is to call the step "Pre-prod / Concept" and be done.
-- That breaks two things that already depend on the name:
--   * (name, applies_to, applies_to_asset_type) is the identity used to compare
--     the same step across projects (docs/ROADMAP.md). "Pre-prod / Concept" and
--     "Preproduction / Concept" are different strings, so two shows that spell
--     the phase differently stop matching and the benchmark quietly halves its
--     sample.
--   * step_library is a global vocabulary of step names. Prefixing fills it with
--     one entry per phase per step, which is the naming drift the alias layer
--     exists to prevent — self-inflicted.
-- A column keeps the name meaning one thing, and renaming a phase is one edit
-- rather than one per step.

ALTER TABLE pipeline_steps
  ADD COLUMN department VARCHAR(60) NOT NULL DEFAULT '' AFTER name;

ALTER TABLE pipeline_template_steps
  ADD COLUMN department VARCHAR(60) NOT NULL DEFAULT '' AFTER name;

-- Free text with a suggested list, exactly like asset_type: a fixed ENUM is what
-- forced a schema change to add Props, and every studio names its phases
-- slightly differently. '' means "not assigned to a phase", which is what every
-- existing row means today — deliberately not guessed at from step names,
-- because a wrong department is worse than a blank one when it is a subtotal
-- boundary on a budget.
ALTER TABLE pipeline_steps
  ADD INDEX idx_step_department (project_id, department);
