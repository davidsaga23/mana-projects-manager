-- finance_install_18 — the details a freelancer's invoice carries (the invoice template).
--
-- FINANCE DATABASE. Applied from migrate.php → Finance database updates (or by
-- hand in phpMyAdmin, into the finance database, once).
--
-- David, 2026-11-11: "Let's work on the invoice template now." Anima fills in
-- each freelancer's monthly invoice from the days they logged (invoice.php);
-- these are the parts Anima cannot know: who they are on paper and where the
-- money goes. Asked whether Anima should store the bank details or the artist
-- types them each month, he chose to store them in Anima. They live HERE, in
-- the finance database, which the production connection has no rights to:
-- the same protection as every rate (includes/money.php, top of the file).
--
-- One row per person (crew.id, the link between the two databases). The
-- artist edits their own row from the invoice page; an administrator can edit
-- anybody's. updated_at / updated_by say who last changed it, because a
-- changed bank account is exactly what a payer should notice.
--
-- Until this is imported the invoice page still fills in the lines and the
-- totals, and says the details cannot be saved yet.

CREATE TABLE IF NOT EXISTS invoice_details (
  crew_id INT NOT NULL PRIMARY KEY,
  crew_name VARCHAR(100) DEFAULT NULL,
  legal_name VARCHAR(190) DEFAULT NULL,
  job_title VARCHAR(190) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  tax_id VARCHAR(100) DEFAULT NULL,
  bank_name VARCHAR(190) DEFAULT NULL,
  account_holder VARCHAR(190) DEFAULT NULL,
  account_number VARCHAR(100) DEFAULT NULL,
  routing_number VARCHAR(100) DEFAULT NULL,
  swift_iban VARCHAR(100) DEFAULT NULL,
  clabe VARCHAR(100) DEFAULT NULL,
  cpf VARCHAR(100) DEFAULT NULL,
  holder_address TEXT DEFAULT NULL,
  payment_notes TEXT DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
