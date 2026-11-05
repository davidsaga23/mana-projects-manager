-- 077 — Access switches: what each role, and each person, can open.
--
-- David, 2026-11-04: "we should have a kind of feature toggle per user, like
-- Reviews are active for supervisors and production, spell is not active for
-- clients ... I think the feature toggle should live in the users tab." Settled
-- 2026-11-05 as ROLE DEFAULTS PLUS PER-PERSON CHANGES: one switch per feature per
-- role, and a person may be set On or Off against their role's answer.
--
-- The list of features and their starting answers live in CODE
-- (includes/features.php, FEATURES). These tables hold only what somebody has
-- CHANGED from that, so a feature added next year needs no migration and starts
-- at the answer written beside it. Until this runs the code's answers apply and
-- the Users screen says the switches cannot be changed yet.
--
-- access_log is the record David asked for: who changed whose access, when, from
-- what to what, and why. Role changes, switching an account on or off, the
-- responsibilities ticks and every "view as" are written there too.

CREATE TABLE IF NOT EXISTS role_features (
  role        VARCHAR(20) NOT NULL,            -- editor / supervisor / artist / client
  feature     VARCHAR(40) NOT NULL,            -- a key of FEATURES
  enabled     TINYINT(1)  NOT NULL,
  updated_by  INT DEFAULT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (role, feature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_features (
  user_id     INT NOT NULL,
  feature     VARCHAR(40) NOT NULL,
  enabled     TINYINT(1)  NOT NULL,            -- 1 = On, 0 = Off; no row = same as the role
  reason      VARCHAR(255) DEFAULT NULL,
  updated_by  INT DEFAULT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (user_id, feature),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_log (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id   INT DEFAULT NULL,            -- who did it (the real login, never a previewed one)
  target_user_id  INT DEFAULT NULL,            -- whose access, when it was one person
  target_role     VARCHAR(20) DEFAULT NULL,    -- or which role, when it was a role default
  what            VARCHAR(80)  NOT NULL,       -- a feature key, or role / active / salaried / view_as ...
  old_value       VARCHAR(80)  DEFAULT NULL,
  new_value       VARCHAR(80)  DEFAULT NULL,
  reason          VARCHAR(255) DEFAULT NULL,
  created_at      DATETIME NOT NULL,
  KEY idx_when (created_at),
  KEY idx_target (target_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
