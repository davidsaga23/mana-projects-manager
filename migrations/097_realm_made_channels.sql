-- 097 — The Realm's chat: channels people make themselves, public or private.
--
-- David, 2026-11-13: "let's add the option to create new channels
-- (public/private) and ask if it's linked to a specific project so they can be
-- nested inside the general channels for each project".
--
-- realm_chat_channels: one row per channel somebody made. Its id in the chat is
-- "ch:<id>" (the built-in ones stay "tavern", "project:12" and so on, and are
-- not rows here).
--   * is_private = 0: everybody who lives in the Realm reads and writes it.
--   * is_private = 1: only the people in realm_chat_members. Nobody else is
--     told it exists, admins included (as with ravens).
--   * project_id: the show it belongs to, so the list shows it under that
--     show's own channel. NULL for a studio-wide channel. A channel of a show is
--     only for people who may see that show, public or not.
--   * archived_at: set when it is archived. It leaves the list and nobody
--     writes in it, but its messages are kept and can still be searched.
--
-- realm_chat_members: who is in a PRIVATE channel. A public channel has no rows
-- here: everybody is in it.
--
-- Until this runs, chat works exactly as before and the "+" beside CHANNELS
-- says a database update is needed. Safe to re-run: both tables are
-- CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_chat_channels (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL,
  is_private  TINYINT(1) NOT NULL DEFAULT 0,
  project_id  INT DEFAULT NULL,
  created_by  INT DEFAULT NULL,
  created_at  DATETIME NOT NULL,
  archived_at DATETIME DEFAULT NULL,
  archived_by INT DEFAULT NULL,
  KEY idx_realm_chan_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_chat_members (
  channel_id INT NOT NULL,
  user_id    INT NOT NULL,
  added_by   INT DEFAULT NULL,
  added_at   DATETIME NOT NULL,
  PRIMARY KEY (channel_id, user_id),
  KEY idx_realm_chan_member (user_id),
  FOREIGN KEY (channel_id) REFERENCES realm_chat_channels(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
