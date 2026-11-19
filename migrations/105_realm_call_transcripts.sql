-- 105 — The Realm: a written record of every call, and notes in the room's chat.
--
-- David, 2026-11-19: "can we add an automatic transcript of any call in any room
-- happening in realm?", then, after comparing Google Meet with keeping the call
-- in the Realm: keep the call in the Realm, bring no new paid service ("I'm good
-- to pay anthropic because we already have some credits ... but I don't want to
-- bring a new service to the mix"). So:
--
--   * EACH PERSON'S BROWSER WRITES DOWN ITS OWN PERSON. Chrome, Edge and Safari
--     turn speech into text by themselves (the Web Speech API). Only that TEXT
--     comes here, with the speaker's name attached by the server from the login.
--     The sound itself still never passes through Anima (migration 085).
--   * WHEN THE CALL ENDS, Claude reads the lines and writes the to-dos and the
--     agreements, posted in the room's chat as "Call notes".
--   * NOBODY IS WRITTEN DOWN BY SURPRISE: Join call says so before the
--     microphone opens, and a red "Transcribing" mark shows during the call.
--
-- realm_calls: one row per call. A call starts when the first person joins a
-- room's voice and ends when nobody is left in it.
--   open_place  the room while the call is going on, NULL after. UNIQUE, so two
--               people joining at the same instant still make ONE call.
--   status      open -> writing (Claude is reading it) -> done, or failed (note
--               says why), or empty (too little was said to be worth notes).
--   summary     what Claude wrote, exactly as it was posted.
--
-- realm_call_people: who was in the call (who may read the transcript).
-- realm_call_lines: what was said, one row per finished sentence, oldest first.
-- realm_call_messages: the chat messages that carry a call's notes, so the chat
--   can sign them "Call notes" and link to the transcript. A house has no channel,
--   so its notes go by raven to each guest: one call, several messages.
--
-- Until this runs, calls work exactly as before and nothing is written down.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_calls (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  place       VARCHAR(40) NOT NULL,
  open_place  VARCHAR(40) DEFAULT NULL,
  status      ENUM('open','writing','done','failed','empty') NOT NULL DEFAULT 'open',
  started_at  DATETIME NOT NULL,
  ended_at    DATETIME DEFAULT NULL,
  summary     TEXT DEFAULT NULL,
  note        VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uq_realm_calls_open (open_place),
  KEY idx_realm_calls_status (status),
  KEY idx_realm_calls_place (place, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_call_people (
  call_id    INT NOT NULL,
  user_id    INT NOT NULL,
  joined_at  DATETIME NOT NULL,
  PRIMARY KEY (call_id, user_id),
  KEY idx_realm_call_people_user (user_id),
  FOREIGN KEY (call_id) REFERENCES realm_calls(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_call_lines (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  call_id   INT NOT NULL,
  user_id   INT DEFAULT NULL,
  said_at   DATETIME NOT NULL,
  lang      VARCHAR(12) NOT NULL DEFAULT '',
  body      TEXT NOT NULL,
  KEY idx_realm_call_lines_call (call_id, said_at, id),
  FOREIGN KEY (call_id) REFERENCES realm_calls(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_call_messages (
  message_id  BIGINT NOT NULL PRIMARY KEY,
  call_id     INT NOT NULL,
  KEY idx_realm_call_messages_call (call_id),
  FOREIGN KEY (call_id) REFERENCES realm_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
