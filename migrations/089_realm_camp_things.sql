-- 089 — The Realm: the one thing each person may stand in a show's camp.
--
-- David, 2026-11-13, on the project camps: "Decorate is also available but only
-- one item per person."
--
-- realm_camp_things: one row per show and person. `thing` is a name from the
-- house library (REALM_ROOM_THINGS in includes/realm_room.php), `x` and `y` are
-- where it stands in the room's own units (240 wide, 160 tall). The key is the
-- show and the person together, which is what makes it one each: a second row
-- for the same person in the same camp cannot exist.
--
-- It is kept by SHOW, not by the piece of land the camp stands on, so the
-- things stay with the show if it ever gives its land back and takes another.
-- A show that stops being active loses its camp on the map; its rows are kept
-- and come back with it. A person who leaves the show keeps their thing in the
-- camp until they or Production take it out.
--
-- The scroll, the calendar, the board and the slaying bar are NOT rows here:
-- they are in every camp and their places are fixed in the code
-- (REALM_CAMP_FIXED in includes/realm_camp.php).
--
-- Until this runs every camp shows its fixtures and no decorations, and
-- Decorate says it needs a database update.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_camp_things (
  project_id  INT NOT NULL,
  user_id     INT NOT NULL,
  thing       VARCHAR(20) NOT NULL,
  x           SMALLINT NOT NULL,
  y           SMALLINT NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (project_id, user_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
