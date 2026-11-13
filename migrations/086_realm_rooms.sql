-- 086 — The Realm: how each person has decorated the inside of their house.
--
-- David, 2026-11-12, in the "My house" thread: "let's start with decorate my
-- office. Let's all start with the same basic look and they can modify it from a
-- library of free and opt to buy new stuff."
--
-- realm_rooms: one row per person who has changed their room. `layout` is the
-- whole room as JSON: the wall, the floor, the rug, and every thing in it with
-- where it stands, for example
--   {"wall":"plaster","floor":"planks","rug":"classic",
--    "items":[["shelf",100,14],["portrait",152,16],["chest",186,64]]}
-- It is kept as one piece of text because it is always read and written whole
-- and never searched. includes/realm_room.php checks every save against the
-- library (REALM_ROOM_THINGS) and checks it again on the way out, so a row that
-- no longer passes is shown as the starting room rather than breaking a house.
--
-- Somebody with no row has the starting room, which is the same for everybody.
--
-- Until this runs the Realm works as before, every house shows the starting
-- room, and Decorate says it needs a database update.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS realm_rooms (
  user_id     INT NOT NULL,
  layout      TEXT NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
