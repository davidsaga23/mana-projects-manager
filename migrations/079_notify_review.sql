-- 079 - A bell for review notes.
--
-- David, 2026-11-06: an assignee was told about notes on their version only when
-- the note @named them. A review note now rings everybody on the task (the
-- assignee, second names, anybody who has spoken or been named on it) and
-- whoever put the version up. notifications.kind is an ENUM, so the new kind
-- has to be added here. Until this runs those bells are written as 'mention'
-- ("mentioned you on ...") rather than not at all; see notify_add().
--
-- Safe to run twice.

ALTER TABLE notifications
  MODIFY kind ENUM('mention', 'assigned', 'status', 'review') NOT NULL;
