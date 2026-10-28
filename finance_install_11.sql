-- finance_install_11 — one exchange rate per project per foreign currency.
--
-- ⚠ IMPORT THIS BY HAND, THROUGH phpMyAdmin, INTO THE FINANCE DATABASE.
-- migrate.php does not and must not touch this database. Run it ONCE.
-- Until it is imported, nothing converts: a non-USD rate keeps showing the
-- "more than one currency" warning exactly as before, and nothing breaks.
--
-- Tim and Jorik, 2026-10-28: USD is the default for every rate, bill and total.
-- A rare contract in another currency (Lewis in £) is entered in that currency;
-- Anima saves that day's exchange rate when the project is set up / signed off
-- and uses that ONE fixed rate for the whole project, showing both amounts:
-- "520.00 USD (400.00 GBP)". No daily rates, no Wise fees: the bank and the
-- books hold the exact amounts, this is for the budget.
--
-- One row per project per currency. usd_per_unit is how many US dollars one
-- unit of the currency buys (GBP 1.3288 means £1 = $1.3288). rate_date is the
-- day the rate is FOR, which is the source's date, not when it was saved.
-- source is 'ECB via frankfurter' for a fetched rate and 'manual' for a typed
-- one.

CREATE TABLE IF NOT EXISTS project_fx_rates (
  project_id INT NOT NULL,
  currency CHAR(3) NOT NULL,
  usd_per_unit DECIMAL(18,8) NOT NULL,
  rate_date DATE NOT NULL,
  source VARCHAR(60) NOT NULL,
  set_at DATETIME NOT NULL,
  set_by VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (project_id, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every change to a fixed rate, with the old one, who, when and why. A fixed
-- rate that can quietly move is not fixed, so a replaced rate is never lost.
CREATE TABLE IF NOT EXISTS project_fx_rates_log (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  currency CHAR(3) NOT NULL,
  old_usd_per_unit DECIMAL(18,8) DEFAULT NULL,
  new_usd_per_unit DECIMAL(18,8) NOT NULL,
  rate_date DATE NOT NULL,
  source VARCHAR(60) NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  changed_at DATETIME NOT NULL,
  changed_by VARCHAR(100) DEFAULT NULL,
  KEY idx_project (project_id, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
