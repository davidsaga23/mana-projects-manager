---
name: bugs
description: Pull Anima's open bug reports (filed with the 🐞 button) and work through them - read each, fix what can be fixed, open a PR, and close or flag the report. Use when David says /bugs, "check the bug reports", "process the bugs" or similar.
---

# Working through Anima's bug reports

People file bugs with the 🐞 button on every screen (bugs.php, migrations 062 and 064). This command pulls them onto this machine and works them.

## 1. Pull

```bash
python tools/pull_bugs.py
```

Every open report lands in `bug-inbox/bug-NNNN/`: a `report.json` (section, description, reporter, role, date, the page they were on, browser, David's notes) and the attached files. `bug-inbox/` is gitignored and rebuilt on every pull, so never keep work in it.

If it says there is no key: the key is `bug_export_key` in the server's `config.php`, and it goes in `tools/.bug_key` (gitignored) on this machine. Ask David for it; never write it anywhere else, never put it in a URL, a commit or a message.

## 2. Sort

The script prints each report as READY or WAITING.

- **WAITING** means `needs_info` is set and David has not written notes yet. Most are reports with a video: you cannot watch a video, so do not guess from the filename. Leave these alone and list them for David at the end, with the reason.
- **READY** means you can work it. A READY report with a video means David has described it in `admin_notes`; work from his notes, the description, and any stills in `frames/` (cut only when ffmpeg is installed).

Look at every screenshot with Read before deciding anything. A GIF may show only its first frame; say so if that matters.

## 3. Work each READY report

For each one, in order:

1. Find the cause in the code. The `section` and `page_url` say where to look; `role` matters, since many bugs are an artist or supervisor seeing something Production would not (see `includes/auth.php` and `includes/project_nav.php`).
2. If you understand it and can fix it: fix it on its own branch from `origin/master`, follow `START_HERE.md` and the docs pack, add a `docs/CHANGELOG.md` entry, and open a PR that says "Fixes bug #N". One PR per bug unless two reports are the same fault. Check `git worktree list` and `git status` first: other sessions share this checkout, so work in your own worktree when one is busy.
3. If the report is unclear or you cannot reproduce it from the code, flag it with the specific question David must answer, rather than guessing:
   ```bash
   python tools/pull_bugs.py flag N --note "Which project were you on, and did the timer show 0:00 or keep counting?"
   ```
4. If it is not a bug (works as designed), do not close it yourself; list it for David with your reasoning.

## 4. Close

Only once the PR is merged and deployed (the memory says merge and deploy finished PRs without asking):

```bash
python tools/pull_bugs.py close N fixed --note "PR #61"
```

`wontfix` only on David's say-so. `open` reopens one.

## 5. Report back

End with three short lists: fixed (bug, PR), flagged for David (bug, the question), and waiting on David's description (bug, why: usually "has a video"). David answers flags by writing notes on the Bugs page, which clears the flag; the next `/bugs` picks them up.
