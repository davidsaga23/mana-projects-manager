# Email summaries: two a day instead of one email per thing

David, 2026-11-17: *"instead of sending an email for each mention, new task or comment, let's send
2 instead. One at the beginning of the day (around 9 am PST) and one at the end (5 pm PST) with a
summary of new stuff for that person."*

## What changed

**These no longer send an email on the spot.** They ring the person's bell instead, and go in their
next summary:

| What happens | Before | Now |
|---|---|---|
| Somebody @mentions you in a comment on a ticket | email at once | bell + summary (the bell is new for tickets) |
| Somebody @mentions you on a board task, or in a version's note | email at once + bell | bell + summary |
| A ticket is assigned to you (new or reassigned, on the ticket page, the Tasks screen or a new card) | email at once | bell + summary (the bell is new for tickets) |
| A ticket assigned to you changes status | email at once | bell + summary (the bell is new for tickets) |
| Cards made for you from a meeting transcript | one email per person | bell (one per card) + summary |

The summary also lists things that rang the bell but never emailed: board tasks assigned to you,
status changes on tasks you follow, review notes on your versions, and Realm ravens and tags.

**These still email at once, unchanged**, because somebody is waiting on them or they are not
"new stuff for you": login invitations, days-off requests and their answers, the kick-off sign-off,
budget change requests, "please log your time" when Production ticks *also email*, the note to the
admins when somebody is hired, and the SMTP test page.

## What a summary contains

- Sent at **9:00 am** and **5:00 pm Pacific** (America/Los_Angeles, so it follows daylight saving).
- Everything in that person's bell that arrived since their last summary **and is still unread**.
  Opening the bell marks it all read, so what somebody already saw in Anima is left out.
- Grouped: New tasks for you, Mentions (with the first lines of the comment), Review notes,
  Updates on tasks you follow, In the Realm. Each line links straight to the task, ticket or Realm.
- **No email at all when there is nothing new.**
- Only what is already in the person's own bell, so nobody is sent anything they could not see in
  Anima (a private Realm channel only rings its members). Never amounts or rates.
- An HTML part and a plain-text part. A sample: `docs/samples/email-digest-sample.html`.
- Sent to the email on the person's crew record (what Home shows), from the same mailbox as every
  Anima email.

Each person chooses on **Home → General info → Email summaries**: morning and end of day (the
default), morning only, end of day only, or none.

## Setting it up (David, once)

### 1. Run the database update

Open `https://anima-mana.com/migrate.php` and apply **104_email_digest** (two small new tables).
Until it runs, the one-at-a-time emails above have already stopped but no summary is sent; the bell
still shows everything.

### 2. Make sure config.php has the site's address

In `config.php` on the server: `'site_url' => 'https://anima-mana.com',`. The cron job has no web
address to read, so without this the summary refuses to run rather than send broken links.

### 3. Add the cron job in cPanel (the dependable way)

1. cPanel → **Advanced** → **Cron Jobs**.
2. *Cron Email*: leave it, or set your address. The command below is silent, so nothing is mailed.
3. *Add New Cron Job* → *Common Settings*: **Once Per Fifteen Minutes (`*/15 * * * *`)**.
4. *Command* (replace `ACCOUNT` with your cPanel username, shown as the **Home Directory**
   `/home/ACCOUNT` in cPanel's right-hand column):

   ```
   /usr/local/bin/php /home/ACCOUNT/public_html/cron_digest.php >/dev/null 2>&1
   ```

5. **Add New Cron Job**.

It runs every 15 minutes and almost always finds nothing due and stops. At 9:00 and 17:00 Pacific
it sends the summaries and records it, so a late or doubled run never sends anybody the same
summary twice. The cron clock does not matter: the script works out Pacific time itself.

To check it by hand (cPanel → **Terminal**, if enabled):

```
php ~/public_html/cron_digest.php --dry-run --slot=am
```

lists who would get a morning summary right now and its subject line, sending nothing.

### If you cannot add a cron job

Nothing to do: the bell's 30-second check, which every open Anima page already makes, sends a
summary that is 20 minutes late and not yet sent. It needs somebody to have Anima open in that
window (9 am to 1 pm, 5 pm to 10 pm Pacific); the cron job does not. To switch the fallback off,
`'digest_web_fallback' => false` in config.php.

A host whose cron can only fetch a web address: put a long random `'cron_key' => '...'` in
config.php and fetch `https://anima-mana.com/cron_digest.php?key=THAT_KEY` every 15 minutes.
Without the key the page answers 403.

## How it works (for whoever maintains it)

- `includes/digest.php`: the ring functions that replaced the emails (`ticket_ring_assignee()`,
  `ticket_ring_mentioned()`, `ticket_ring_transcript()`), the summary (`digest_run()`,
  `digest_items_for()`, `digest_render()`), the per-person choice, and the fallback.
- `cron_digest.php`: the cron entry point. CLI only, or `?key=` with `cron_key`.
- `notify_poll.php`: calls `digest_web_fallback()` after answering the bell.
- The old `notify_mentioned()`, `notify_assignee()`, `notify_transcript_tasks()` and
  `notify_mentioned_task()` in `includes/mailer.php` stay as thin wrappers so older callers work.
- Migration 104: `digest_people` (choice, last summary sent, last bell item looked at) and
  `digest_runs` (one row per run).
- A summary that misses its window entirely (9 am to 1 pm, 5 pm to 10 pm) is skipped; what it would
  have listed is still unread and goes in the next one.
- The first summary anybody gets only looks back 12 hours, so the first morning is not a list of
  everything that ever rang.
