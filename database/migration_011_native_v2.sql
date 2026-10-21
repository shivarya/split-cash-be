-- Migration 011: native app 2.0 (Splitwise-style features)
-- Additive only: the RN app (1.3.x) keeps working against the migrated schema.
-- Written against the prod schema (MariaDB 10.11) as dumped on 2026-10-21; also runs on MariaDB 10.4 (XAMPP).
-- Safe to re-run: every statement is IF NOT EXISTS / idempotent.

-- Groups: per-group "simplify debts", hidden 1:1 groups for non-group expenses, shareable invite link
ALTER TABLE expense_groups
  ADD COLUMN IF NOT EXISTS simplify_debts TINYINT(1) NOT NULL DEFAULT 1 AFTER category,
  ADD COLUMN IF NOT EXISTS is_direct TINYINT(1) NOT NULL DEFAULT 0 AFTER simplify_debts,
  ADD COLUMN IF NOT EXISTS direct_key VARCHAR(64) NULL AFTER is_direct,
  ADD COLUMN IF NOT EXISTS invite_code VARCHAR(32) NULL AFTER direct_key;
CREATE UNIQUE INDEX IF NOT EXISTS uq_direct_key ON expense_groups (direct_key);
CREATE UNIQUE INDEX IF NOT EXISTS uq_invite_code ON expense_groups (invite_code);

-- Expenses: two more split modes, and who created / last edited it (any member may edit now)
ALTER TABLE expenses
  MODIFY COLUMN split_type ENUM('equal', 'unequal', 'percentage', 'shares', 'adjustment') DEFAULT 'equal',
  ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER paid_by,
  ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by;

ALTER TABLE expense_splits
  ADD COLUMN IF NOT EXISTS shares DECIMAL(10, 2) NULL AFTER percentage,
  ADD COLUMN IF NOT EXISTS adjustment DECIMAL(10, 2) NULL AFTER shares;
CREATE INDEX IF NOT EXISTS idx_expense_user ON expense_splits (expense_id, user_id);

-- Settlements can now be recorded in either direction, so remember who recorded it
ALTER TABLE settlements
  ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER to_user_id;

-- Activity: a JSON snapshot (description, amount, per-user net in paise) so the feed and pushes survive deletes
ALTER TABLE activities
  ADD COLUMN IF NOT EXISTS meta TEXT NULL AFTER description;
CREATE INDEX IF NOT EXISTS idx_group_id_id ON activities (group_id, id);

-- Account deletion anonymises the row instead of deleting it (a hard delete would cascade into other members' expenses)
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL AFTER currency;

CREATE TABLE IF NOT EXISTS expense_comments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  expense_id INT NOT NULL,
  user_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_expense_id_id (expense_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  fcm_token VARCHAR(512) NOT NULL,
  platform VARCHAR(16) NOT NULL DEFAULT 'android',
  app_version VARCHAR(32) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_fcm (fcm_token(191)),
  INDEX idx_user_active (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Remind" rate limit (one per pair per day)
CREATE TABLE IF NOT EXISTS friend_reminders (
  id INT PRIMARY KEY AUTO_INCREMENT,
  from_user_id INT NOT NULL,
  to_user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_pair_time (from_user_id, to_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (name) VALUES ('011_native_v2')
ON DUPLICATE KEY UPDATE name = name;
