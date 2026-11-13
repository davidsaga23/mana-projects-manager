-- 085 — Commercial: the CRM (leads, contacts, activity, proposals, quotes).
--
-- David, 2026-11-12, with a prototype he built as a claude.ai artifact: "can we
-- incorporate this to anima?" Settled the same day: the MAIN database (not the
-- finance one, and not a third one), behind the "Commercial" access switch,
-- administrators only to begin with.
--
-- ⚠ SALES, NOT COST. Anima tracks a show's budget against its cost and never
-- profit (CLAUDE.md, money rules). These tables hold what the studio is SELLING:
-- deal values and quotes to clients. Nothing here is joined into a show's
-- budget, costs, money strip or the finance database, and nothing there reads
-- these. A won deal does not create or change a project.
--
--   crm_leads        one per company (one lead per company, many contacts)
--   crm_contacts     the people at that company; position 0 is the primary
--   crm_activities   the lead's timeline, newest first when read. `type` and
--                    `contact_id` are what a later Gmail sync will write.
--   crm_proposals    a deck or creative proposal, linked to a lead
--   crm_quotes       a budget with line items, linked to a lead and optionally
--                    to a proposal; numbered Q-YYYY-NNN
--   crm_quote_items  the lines of a quote (USD)
--
-- Every amount is USD. Until this runs, Commercial says which update it needs.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS crm_leads (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  company          VARCHAR(255) NOT NULL,
  website          VARCHAR(255) NOT NULL DEFAULT '',
  stage            VARCHAR(12)  NOT NULL DEFAULT 'contacted',  -- contacted / interested / proposal / negotiation / won / lost
  value            DECIMAL(14,2) DEFAULT NULL,                 -- USD; NULL = not set
  project_type     VARCHAR(100) NOT NULL DEFAULT '',
  source           VARCHAR(60)  NOT NULL DEFAULT '',
  owner_id         INT DEFAULT NULL,
  next_step        VARCHAR(500) NOT NULL DEFAULT '',
  follow_up        DATE DEFAULT NULL,
  tags             TEXT DEFAULT NULL,                          -- a JSON list of strings
  notes            TEXT DEFAULT NULL,
  lost_reason      VARCHAR(500) NOT NULL DEFAULT '',
  last_contact_at  DATETIME DEFAULT NULL,
  closed_at        DATETIME DEFAULT NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  created_by       INT DEFAULT NULL,
  KEY idx_crm_lead_company (company),
  KEY idx_crm_lead_stage (stage),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_contacts (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  lead_id          INT NOT NULL,
  position         INT NOT NULL DEFAULT 0,                     -- 0 = the primary contact
  name             VARCHAR(255) NOT NULL DEFAULT '',
  title            VARCHAR(255) NOT NULL DEFAULT '',
  email            VARCHAR(255) NOT NULL DEFAULT '',
  phone            VARCHAR(80)  NOT NULL DEFAULT '',
  channel          VARCHAR(20)  NOT NULL DEFAULT 'Email',      -- how we first reached them
  contacted_at     DATE DEFAULT NULL,
  last_contact_at  DATETIME DEFAULT NULL,
  created_at       DATETIME NOT NULL,
  KEY idx_crm_contact_lead (lead_id, position),
  KEY idx_crm_contact_email (email),
  FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_activities (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  lead_id          INT NOT NULL,
  at               DATETIME NOT NULL,
  by_user          INT DEFAULT NULL,
  type             VARCHAR(12) NOT NULL,                       -- Email / Call / Networking / Meeting / Note / Stage
  text             TEXT NOT NULL,
  contact_id       INT DEFAULT NULL,
  KEY idx_crm_act_lead (lead_id, at),
  FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES crm_contacts(id) ON DELETE SET NULL,
  FOREIGN KEY (by_user) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_proposals (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(255) NOT NULL,
  lead_id          INT DEFAULT NULL,
  status           VARCHAR(10) NOT NULL DEFAULT 'todo',        -- todo / progress / review / sent / accepted / declined
  due_date         DATE DEFAULT NULL,
  deck_url         VARCHAR(1000) NOT NULL DEFAULT '',
  owner_id         INT DEFAULT NULL,
  notes            TEXT DEFAULT NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  created_by       INT DEFAULT NULL,
  KEY idx_crm_prop_lead (lead_id),
  FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_quotes (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  number           VARCHAR(20) NOT NULL,                       -- Q-YYYY-NNN
  title            VARCHAR(255) NOT NULL DEFAULT '',
  lead_id          INT DEFAULT NULL,
  proposal_id      INT DEFAULT NULL,
  status           VARCHAR(10) NOT NULL DEFAULT 'draft',       -- draft / sent / approved / rejected
  valid_until      DATE DEFAULT NULL,
  discount_pct     DECIMAL(6,2) NOT NULL DEFAULT 0,
  tax_pct          DECIMAL(6,2) NOT NULL DEFAULT 0,
  notes            TEXT DEFAULT NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  created_by       INT DEFAULT NULL,
  UNIQUE KEY uq_crm_quote_number (number),
  KEY idx_crm_quote_lead (lead_id),
  FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
  FOREIGN KEY (proposal_id) REFERENCES crm_proposals(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_quote_items (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  quote_id         INT NOT NULL,
  position         INT NOT NULL DEFAULT 0,
  description      VARCHAR(500) NOT NULL DEFAULT '',
  qty              DECIMAL(12,3) NOT NULL DEFAULT 0,
  rate             DECIMAL(14,2) NOT NULL DEFAULT 0,           -- USD
  KEY idx_crm_item_quote (quote_id, position),
  FOREIGN KEY (quote_id) REFERENCES crm_quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
