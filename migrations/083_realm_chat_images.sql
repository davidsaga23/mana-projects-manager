-- 083 — The Realm's chat: an image in a message, and tagging somebody.
--
-- David, 2026-11-12: "let's also allow images and tagging people possible in
-- all chats".
--
-- The bell learns the kind `tag` ("David tagged you in The Tavern in the
-- Realm"). The list repeats every earlier value, because MODIFY replaces the
-- whole ENUM; re-running it changes nothing. It comes FIRST on purpose: it is
-- the statement that is safe to run twice, so a retry of this file does not
-- stop on it.
--
-- A message may then carry one image. The file itself goes in the uploads
-- folder beside ticket attachments, under a random name, checked by the same
-- accept_upload() every other upload passes through; these columns only say
-- which file, what it was called, and what kind it is. A message with an image
-- may have no words.
--
-- Until this runs, chat works as before: no button to add an image, and a tag
-- is only text.

ALTER TABLE notifications
  MODIFY kind ENUM('mention', 'assigned', 'status', 'review', 'reminder', 'raven', 'tag') NOT NULL;

ALTER TABLE realm_messages
  ADD COLUMN image VARCHAR(80) DEFAULT NULL,
  ADD COLUMN image_name VARCHAR(255) DEFAULT NULL,
  ADD COLUMN image_mime VARCHAR(60) DEFAULT NULL;
