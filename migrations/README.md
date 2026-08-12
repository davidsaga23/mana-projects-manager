# Migrations

Schema changes after the v1 baseline live here as numbered `.sql` files:

```
001_short_description.sql
002_another_change.sql
```

## Rules

- **Numbered, ascending, never reused.** `migrate.php` applies them in filename
  order and records each in `schema_migrations`.
- **Additive only.** Add tables/columns/indexes; never DROP a table with data in
  a migration. `install.sql` is the only destructive file and is for fresh
  installs only.
- **`install.sql` stays frozen** as the v1 baseline. A fresh install = import
  `install.sql`, then open `migrate.php` to catch up. No need to keep the two
  in sync by hand.
- Plain DDL/DML only — one statement per `;` at end of line. No stored
  procedures or DELIMITER tricks (the runner's splitter is deliberately simple).
- Comments start with `--`.
- **Write every statement so it survives being re-run.** A failed migration isn't
  recorded, so fixing the file and running it again replays the whole thing from
  the top. `UPDATE … WHERE x IS NULL`, `CREATE TABLE IF NOT EXISTS` and
  `INSERT IGNORE` all re-run harmlessly; a bare `INSERT` doesn't.

## Replacing an index MySQL is using for a foreign key

Create the new index **before** dropping the old one. MySQL refuses to drop an
index that a foreign key relies on:

> Cannot drop index 'x': needed in a foreign key constraint

A `UNIQUE KEY (project_id, name)` doubles as the index for the FK on
`project_id`, because `project_id` is its leftmost column. Adding the
replacement first — as long as it is also leftmost on that column — gives the
constraint something to keep using, and the drop then succeeds. Migration 009
does it in that order and says why.

## Applying

Open `migrate.php` in the browser (login required). It shows what's pending
first; nothing runs until the button is clicked, and every file is reported as
applied / skipped / failed.
