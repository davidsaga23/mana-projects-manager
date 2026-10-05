-- 040 — a mention can be new, or already read.
--
-- David, 2026-10-06, asked for both halves of the notification: a list on My
-- tasks AND an email on request. A list without this column is a feed — it can
-- show that you were mentioned ten times but never which of them you have not
-- seen, and "3 new" is the only part of a notification anybody reads.
--
-- NULL means unseen, which is the right default for every mention that already
-- exists: nobody has had the chance to read them, because there was nowhere to
-- read them.
--
-- ⚠ SET ON OPENING THE TICKET, NOT ON SEEING THE LIST. A count on My tasks is a
-- pointer, not the thing itself; clearing it because somebody glanced at the
-- screen would lose the one signal telling them where to go. It is stamped when
-- they open the ticket the mention is on, which is the moment they could
-- actually have read it.
--
-- That write happens on a GET, deliberately, and is the second thing in this
-- codebase to do so — the daily snapshot is the first (DECISIONS, 2026-08-13).
-- Both are the same shape: a fact about "somebody looked" that has no form to
-- submit.
--
-- One statement, so it applies whole or not at all.

ALTER TABLE ticket_mentions
  ADD COLUMN seen_at DATETIME DEFAULT NULL AFTER named_as,
  ADD KEY idx_unseen (crew_id, seen_at);
