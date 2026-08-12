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

## Applying

Open `migrate.php` in the browser (login required). It shows what's pending
first; nothing runs until the button is clicked, and every file is reported as
applied / skipped / failed.
