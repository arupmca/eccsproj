-- Sadya High School Employees' Credit Co-operative Society Ltd.
-- Schema v4: members, logins, rules, opening balances, monthly cash book
-- (draft -> final), loans, refunds and account closures.
--
-- *** FOR A BRAND-NEW, EMPTY INSTALL ONLY. ***
-- This script DELETES every table and every row, including opening balances
-- you may have corrected and any months already entered.
-- If you already have a shseccs database with your data, do NOT import this file:
-- run setup/upgrade_to_v4.php instead, which keeps your data.
--
-- Import in phpMyAdmin: Import tab -> choose file -> Go

CREATE DATABASE IF NOT EXISTS shseccs
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE shseccs;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS settings_history;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS fund_interest;
DROP TABLE IF EXISTS cash_book_entries;
DROP TABLE IF EXISTS payment_heads;
DROP TABLE IF EXISTS loans;
DROP TABLE IF EXISTS collections;
DROP TABLE IF EXISTS member_closures;
DROP TABLE IF EXISTS cash_months;
DROP TABLE IF EXISTS opening_balances;
DROP TABLE IF EXISTS member_logins;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS members;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- people

CREATE TABLE members (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_no   INT UNSIGNED NOT NULL,
  full_name   VARCHAR(120) NOT NULL,
  joined_on   DATE NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_member_no (member_no)
) ENGINE=InnoDB;

-- Everyone who can sign in: members and the administrator (secretary).
-- Other tables refer to users.id / members.id, never to login_id,
-- so login IDs can be changed freely later.
CREATE TABLE users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role                  ENUM('member','admin') NOT NULL,
  member_id             INT UNSIGNED NULL,
  login_id              VARCHAR(150) NOT NULL,
  display_name          VARCHAR(120) NULL,
  password_hash         VARCHAR(255) NOT NULL,
  must_change_password  TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME NULL,
  last_login_at         DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login_id (login_id),
  UNIQUE KEY uq_users_member_id (member_id),
  CONSTRAINT fk_users_member
    FOREIGN KEY (member_id) REFERENCES members (id)
    ON DELETE CASCADE,
  CONSTRAINT chk_users_role_member
    CHECK ((role = 'admin' AND member_id IS NULL)
        OR (role = 'member' AND member_id IS NOT NULL))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- rules

-- The society's rules, editable by the administrator (Settings tab).
CREATE TABLE settings (
  setting_key    VARCHAR(60) NOT NULL,
  setting_value  VARCHAR(60) NOT NULL,
  label          VARCHAR(120) NOT NULL,
  unit           VARCHAR(20) NOT NULL DEFAULT '',
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  updated_by     INT UNSIGNED NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

CREATE TABLE settings_history (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key  VARCHAR(60) NOT NULL,
  old_value    VARCHAR(60) NOT NULL,
  new_value    VARCHAR(60) NOT NULL,
  changed_by   INT UNSIGNED NULL,
  changed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_settings_history_key (setting_key)
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value, label, unit, sort_order) VALUES
  ('thrift_monthly',         '1000',   'Monthly thrift fund per member (compulsory)',      'Rs',        10),
  ('share_value',            '100',    'Value of one share',                               'Rs',        20),
  ('share_max_per_member',   '40000',  'Maximum share holding per member',                 'Rs',        30),
  ('admission_fee',          '100',    'Admission fee for a new member',                   'Rs',        40),
  ('min_shares_new_member',  '1',      'Minimum shares a new member must buy',             'shares',    50),
  ('loan_min_share_pct',     '5',      'Share balance needed, as % of loan applied for',   '%',         60),
  ('loan_min_guarantee_pct', '0.5',    'Guarantee fund needed, as % of loan applied for',  '%',         70),
  ('mt_rate',                '7.75',   'MT loan interest rate',                            '% a year',  80),
  ('mt_installments',        '60',     'MT loan number of EMIs',                           'months',    90),
  ('mt_max_amount',          '400000', 'MT loan maximum amount',                           'Rs',       100),
  ('st_rate',                '7',      'ST loan interest rate',                            '% a year', 110),
  ('st_installments',        '30',     'ST loan number of EMIs',                           'months',   120),
  ('st_max_amount',          '100000', 'ST loan maximum amount',                           'Rs',       130),
  ('thrift_interest_rate',   '6',      'Interest on thrift fund',                          '% a year', 140),
  ('guarantee_interest_rate','6',      'Interest on guarantee fund',                       '% a year', 150);

-- ------------------------------------------------------ opening balances

-- Position of every member on the day the system starts (from the society's
-- "Detailed List"). mt_interest_due / st_interest_due is the interest worked
-- out for the first month, as printed in that list.
CREATE TABLE opening_balances (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id            INT UNSIGNED NOT NULL,
  as_on                DATE NOT NULL,
  share                DECIMAL(12,2) NOT NULL DEFAULT 0,
  thrift_principal     DECIMAL(12,2) NOT NULL DEFAULT 0,
  thrift_interest      DECIMAL(12,2) NOT NULL DEFAULT 0,
  guarantee_principal  DECIMAL(12,2) NOT NULL DEFAULT 0,
  guarantee_interest   DECIMAL(12,2) NOT NULL DEFAULT 0,
  mt_principal         DECIMAL(12,2) NOT NULL DEFAULT 0,
  mt_interest_due      DECIMAL(12,2) NOT NULL DEFAULT 0,
  st_principal         DECIMAL(12,2) NOT NULL DEFAULT 0,
  st_interest_due      DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_opening_member_date (member_id, as_on),
  CONSTRAINT fk_opening_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB;

-- ------------------------------------------------- the monthly cash book

-- One row per month that has been started. Months are entered in order, one at
-- a time. Only 'final' months count in balances and ledgers.
CREATE TABLE cash_months (
  month_start   DATE NOT NULL COMMENT 'first day of the month',
  status        ENUM('draft','final') NOT NULL DEFAULT 'draft',
  entry_date    DATE NULL COMMENT 'date the collection was taken',
  sheet_saved   TINYINT(1) NOT NULL DEFAULT 0,
  started_by    INT UNSIGNED NULL,
  started_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalized_by  INT UNSIGNED NULL,
  finalized_at  DATETIME NULL,
  PRIMARY KEY (month_start)
) ENGINE=InnoDB;

-- A member's account closed on retirement or transfer. The settlement
-- refunds share, thrift and guarantee fund (with interest) and recovers any
-- loan dues from the same amount.
CREATE TABLE member_closures (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id           INT UNSIGNED NOT NULL,
  cash_month          DATE NOT NULL,
  closing_date        DATE NOT NULL,
  share_refund        DECIMAL(12,2) NOT NULL DEFAULT 0,
  thrift_balance      DECIMAL(12,2) NOT NULL DEFAULT 0,
  thrift_interest     DECIMAL(12,2) NOT NULL DEFAULT 0,
  guarantee_balance   DECIMAL(12,2) NOT NULL DEFAULT 0,
  guarantee_interest  DECIMAL(12,2) NOT NULL DEFAULT 0,
  mt_principal        DECIMAL(12,2) NOT NULL DEFAULT 0,
  mt_interest         DECIMAL(12,2) NOT NULL DEFAULT 0,
  st_principal        DECIMAL(12,2) NOT NULL DEFAULT 0,
  st_interest         DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_payable         DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'positive: society pays member; negative: member owes society',
  status              ENUM('draft','final') NOT NULL DEFAULT 'draft',
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_closures_member (member_id),
  CONSTRAINT fk_closures_member FOREIGN KEY (member_id) REFERENCES members (id),
  CONSTRAINT fk_closures_month  FOREIGN KEY (cash_month) REFERENCES cash_months (month_start)
) ENGINE=InnoDB;

-- Cash book, receipt side: one row per member per month.
-- Loan principal = total received - interest.
CREATE TABLE collections (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id         INT UNSIGNED NOT NULL,
  collection_month  DATE NOT NULL,
  received_on       DATE NOT NULL,
  thrift            DECIMAL(12,2) NOT NULL DEFAULT 0,
  share             DECIMAL(12,2) NOT NULL DEFAULT 0,
  mt_principal      DECIMAL(12,2) NOT NULL DEFAULT 0,
  mt_interest       DECIMAL(12,2) NOT NULL DEFAULT 0,
  st_principal      DECIMAL(12,2) NOT NULL DEFAULT 0,
  st_interest       DECIMAL(12,2) NOT NULL DEFAULT 0,
  admission         DECIMAL(12,2) NOT NULL DEFAULT 0,
  guarantee         DECIMAL(12,2) NOT NULL DEFAULT 0,
  others            DECIMAL(12,2) NOT NULL DEFAULT 0,
  closure_id        INT UNSIGNED NULL,
  remarks           VARCHAR(255) NULL,
  entered_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_collections_member_month (member_id, collection_month),
  KEY idx_collections_month (collection_month),
  CONSTRAINT fk_collections_member  FOREIGN KEY (member_id) REFERENCES members (id),
  CONSTRAINT fk_collections_month   FOREIGN KEY (collection_month) REFERENCES cash_months (month_start),
  CONSTRAINT fk_collections_closure FOREIGN KEY (closure_id) REFERENCES member_closures (id)
) ENGINE=InnoDB;

-- MT / ST loans issued after the system starts (older loans are in opening_balances).
CREATE TABLE loans (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id      INT UNSIGNED NOT NULL,
  loan_type      ENUM('MT','ST') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  interest_rate  DECIMAL(5,2) NOT NULL,
  installments   SMALLINT UNSIGNED NOT NULL,
  applied_on     DATE NOT NULL,
  issued_on      DATE NULL,
  status         ENUM('applied','approved','issued','rejected') NOT NULL DEFAULT 'applied',
  remarks        VARCHAR(255) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_loans_member (member_id),
  CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB;

-- Drop-down heads for the cash book. 'effect' says what an entry does to a member's account.
CREATE TABLE payment_heads (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80) NOT NULL,
  side        ENUM('R','P') NOT NULL,
  effect      ENUM('none','mt_loan','st_loan','share_refund','thrift_refund','guarantee_refund') NOT NULL DEFAULT 'none',
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_heads_name_side (name, side)
) ENGINE=InnoDB;

INSERT INTO payment_heads (name, side, effect, sort_order) VALUES
  ('Bank Withdrawal',                  'R', 'none',             10),
  ('MT Loan issued',                   'P', 'mt_loan',          10),
  ('ST Loan issued',                   'P', 'st_loan',          20),
  ('Share Refund',                     'P', 'share_refund',     30),
  ('Thrift Fund Refund',               'P', 'thrift_refund',    40),
  ('Guarantee Fund Refund',            'P', 'guarantee_refund', 50),
  ('Printing & Stationery',            'P', 'none',             60),
  ('Audit Fees',                       'P', 'none',             70),
  ('Education Fund',                   'P', 'none',             80),
  ('Donation to Co-Operative Society', 'P', 'none',             90),
  ('Entertainment Expenses',           'P', 'none',            100),
  ('Travelling Expenses',              'P', 'none',            110),
  ('Bank Charges',                     'P', 'none',            120),
  ('Bank Savings Deposit',             'P', 'none',            130),
  ('AGM Expenses',                     'P', 'none',            140),
  ('Others',                           'P', 'none',            900);

-- Cash book entries that are not member collections: payments, loans issued,
-- refunds and bank withdrawals.
CREATE TABLE cash_book_entries (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cash_month  DATE NOT NULL,
  entry_date  DATE NOT NULL,
  side        ENUM('R','P') NOT NULL,
  head_id     INT UNSIGNED NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  member_id   INT UNSIGNED NULL,
  loan_id     INT UNSIGNED NULL,
  closure_id  INT UNSIGNED NULL,
  narration   VARCHAR(255) NULL,
  entered_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cash_entries_month (cash_month),
  CONSTRAINT chk_cash_entries_amount CHECK (amount > 0),
  CONSTRAINT fk_cash_entries_month   FOREIGN KEY (cash_month) REFERENCES cash_months (month_start),
  CONSTRAINT fk_cash_entries_head    FOREIGN KEY (head_id)    REFERENCES payment_heads (id),
  CONSTRAINT fk_cash_entries_member  FOREIGN KEY (member_id)  REFERENCES members (id),
  CONSTRAINT fk_cash_entries_loan    FOREIGN KEY (loan_id)    REFERENCES loans (id),
  CONSTRAINT fk_cash_entries_closure FOREIGN KEY (closure_id) REFERENCES member_closures (id)
) ENGINE=InnoDB;

-- Interest credited to thrift and guarantee fund balances (at account closure,
-- and at year end in a later stage). Counts only once its month is final.
CREATE TABLE fund_interest (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id    INT UNSIGNED NOT NULL,
  cash_month   DATE NOT NULL,
  fund         ENUM('thrift','guarantee') NOT NULL,
  credited_on  DATE NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  closure_id   INT UNSIGNED NULL,
  narration    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_fund_interest_member (member_id),
  CONSTRAINT fk_fund_interest_member  FOREIGN KEY (member_id)  REFERENCES members (id),
  CONSTRAINT fk_fund_interest_month   FOREIGN KEY (cash_month) REFERENCES cash_months (month_start),
  CONSTRAINT fk_fund_interest_closure FOREIGN KEY (closure_id) REFERENCES member_closures (id)
) ENGINE=InnoDB;
