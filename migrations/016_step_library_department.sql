-- Remember which department a step belongs to, so it is answered once.
--
-- Migration 015 gave steps a department but left every new project's steps
-- blank, which means retyping "Pre-production" against Concept on every show
-- forever. A step's phase is one of the most stable facts about it — Concept is
-- always pre-production, Comp is always post — so this is exactly what
-- step_library is for. It already remembers applies_to and tracks_seconds for
-- the same reason.
--
-- Global, no project_id: cross-project reuse is the entire point of the library,
-- and it is the mechanism that stops one show calling a phase "Post" and the
-- next calling it "Post-production".

ALTER TABLE step_library
  ADD COLUMN department VARCHAR(60) NOT NULL DEFAULT '' AFTER name;

-- Seed the library from the built-in templates, whose steps got departments in
-- 015. Only fills blanks — a name already carrying a department was set by a
-- person and outranks a default.
UPDATE step_library sl
   JOIN (
        SELECT name, MIN(department) AS department
          FROM pipeline_template_steps
         WHERE department <> ''
         GROUP BY name
   ) t ON t.name = sl.name
    SET sl.department = t.department
  WHERE sl.department = '';

-- And from steps already in real projects, which is where the answers actually
-- are on an install that has been running.
UPDATE step_library sl
   JOIN (
        SELECT name, MIN(department) AS department
          FROM pipeline_steps
         WHERE department <> ''
         GROUP BY name
   ) p ON p.name = sl.name
    SET sl.department = p.department
  WHERE sl.department = '';
