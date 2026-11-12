-- 081 — The Realm, stage 1: the village, who is in it, and their doors.
--
-- David, 2026-11-12: "an epic fantasy office space where we can hang out, visit
-- other artist's offices, request meetings so they open up a voice chat ...
-- something that mixes the fantasy world of magic the gathering/lord of the
-- rings, with slack/discord." Settled the same day as ONE VILLAGE holding
-- everybody's house, whatever shows they are on ("I think they should stay in
-- one place only"), with each show as a piece of landscape around it ("I don't
-- think we'll ever have more than 5 projects at the same time").
--
-- This stage holds no chat, no voice and no mana: only where each person lives,
-- how their door stands, what their character wears, and whether they are here.
-- Until this runs, realm.php says the update is waiting and draws nothing.
--
-- realm_people: one row per account, made the first time that person opens the
-- Realm (so the village grows as people arrive, and nobody who never came has a
-- house). house_slot is the plot in the village and never changes once given.
-- place is where their character stands ('user:12' a house, 'tavern',
-- 'council', 'land:peaks' a show's war room) and inside says whether they went
-- in. last_ping is the heartbeat (here or away); last_active is the last time
-- they actually touched the page (here or resting).
--
-- realm_lands: which show holds which of the five pieces of landscape. A show
-- keeps its land while it is active and frees it when it is not.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_people (
  user_id      INT NOT NULL,
  house_slot   SMALLINT DEFAULT NULL,
  house_kind   VARCHAR(12) NOT NULL DEFAULT 'study',   -- study / tower / forge
  door         VARCHAR(8)  NOT NULL DEFAULT 'open',    -- open / ajar / sealed
  place        VARCHAR(40) NOT NULL DEFAULT '',
  inside       TINYINT(1)  NOT NULL DEFAULT 0,
  cloak        VARCHAR(12) NOT NULL DEFAULT 'mana',
  hat          VARCHAR(12) NOT NULL DEFAULT 'none',
  pet          VARCHAR(12) NOT NULL DEFAULT 'none',
  last_ping    DATETIME DEFAULT NULL,
  last_active  DATETIME DEFAULT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_realm_slot (house_slot),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS realm_lands (
  land        VARCHAR(12) NOT NULL,                    -- peaks / wood / ash / lake / moor
  project_id  INT NOT NULL,
  claimed_at  DATETIME NOT NULL,
  PRIMARY KEY (land),
  UNIQUE KEY uq_realm_land_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
