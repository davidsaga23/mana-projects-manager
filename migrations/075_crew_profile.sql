-- 075 — A person's own profile: phone, a short bio and a picture.
--
-- David, 2026-10-30, the Home page's General info tab: "Name, email, phone (in
-- case they want to add it), profile picture, bio (let them write it)". The
-- person writes these themselves on Home (home.php); nobody else's are editable
-- there, and nothing about money is.
--
-- On `crew`, because that is what this schema means by a person (a login is
-- `users`). The picture is a file in the uploads directory, named at random
-- like every other upload, and served behind the login by avatar.php; only its
-- stored name and type are kept here.
--
-- All NULL by default: a profile nobody has filled in is the normal state, and
-- until this runs the tab shows name and email only and says so.

ALTER TABLE crew
  ADD COLUMN phone VARCHAR(50) DEFAULT NULL;

ALTER TABLE crew
  ADD COLUMN bio TEXT DEFAULT NULL;

ALTER TABLE crew
  ADD COLUMN photo_stored VARCHAR(100) DEFAULT NULL;

ALTER TABLE crew
  ADD COLUMN photo_mime VARCHAR(60) DEFAULT NULL;
