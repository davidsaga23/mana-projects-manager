-- 094 — The Realm: search across all chat history.
--
-- David, 2026-11-13: the studio is on Slack's free plan and loses everything
-- older than 90 days. Every Realm message is kept here, so it can be searched
-- forever; this index keeps that search quick once there are years of it.
--
-- A FULLTEXT index on the words of every message. includes/realm_search.php
-- uses it when it is there, and searches without it (more slowly, with LIKE)
-- until this has run, so the search works either way.
--
-- Runs once; migrate.php records it. Building the index takes a moment on a
-- big table and does not change any message.

ALTER TABLE realm_messages
  ADD FULLTEXT INDEX ft_realm_msg_body (body);
