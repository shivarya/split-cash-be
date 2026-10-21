-- Split Cash database schema — baseline that matches production as of 2026-10-21
-- (MariaDB 10.11, `mysqldump --no-data`). The previous copy of this file had drifted from prod.
--
-- Fresh install: run this file, then every migration_0NN_*.sql in order (currently 011).
-- Existing installs: run only the migrations they haven't recorded in `migrations`.

CREATE TABLE IF NOT EXISTS migrations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  google_id VARCHAR(255) DEFAULT NULL,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  profile_picture VARCHAR(500) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  currency VARCHAR(3) DEFAULT 'INR',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY email (email),
  UNIQUE KEY google_id (google_id),
  KEY idx_email (email),
  KEY idx_google_id (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_groups (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  image VARCHAR(500) DEFAULT NULL,
  category VARCHAR(50) DEFAULT 'general',
  created_by INT(11) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created_by (created_by),
  CONSTRAINT expense_groups_ibfk_1 FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_members (
  id INT(11) NOT NULL AUTO_INCREMENT,
  group_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  role ENUM('admin', 'member') DEFAULT 'member',
  status ENUM('active', 'left', 'pending') DEFAULT 'active',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_group_member (group_id, user_id),
  KEY idx_group_id (group_id),
  KEY idx_user_id (user_id),
  KEY idx_status (status),
  CONSTRAINT group_members_ibfk_1 FOREIGN KEY (group_id) REFERENCES expense_groups (id) ON DELETE CASCADE,
  CONSTRAINT group_members_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT(11) NOT NULL AUTO_INCREMENT,
  group_id INT(11) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(3) DEFAULT 'INR',
  category VARCHAR(50) DEFAULT 'general',
  paid_by INT(11) NOT NULL,
  split_type ENUM('equal', 'unequal', 'percentage') DEFAULT 'equal',
  date DATE NOT NULL,
  notes TEXT DEFAULT NULL,
  receipt_url VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_id (group_id),
  KEY idx_paid_by (paid_by),
  KEY idx_date (date),
  CONSTRAINT expenses_ibfk_1 FOREIGN KEY (group_id) REFERENCES expense_groups (id) ON DELETE CASCADE,
  CONSTRAINT expenses_ibfk_2 FOREIGN KEY (paid_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_splits (
  id INT(11) NOT NULL AUTO_INCREMENT,
  expense_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  percentage DECIMAL(5,2) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expense_id (expense_id),
  KEY idx_user_id (user_id),
  CONSTRAINT expense_splits_ibfk_1 FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE CASCADE,
  CONSTRAINT expense_splits_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy denormalised table; nothing reads it any more (balances are computed on demand).
CREATE TABLE IF NOT EXISTS balances (
  id INT(11) NOT NULL AUTO_INCREMENT,
  group_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  total_paid DECIMAL(10,2) DEFAULT 0.00,
  total_owed DECIMAL(10,2) DEFAULT 0.00,
  balance DECIMAL(10,2) DEFAULT 0.00,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_group_user_balance (group_id, user_id),
  KEY idx_group_id (group_id),
  KEY idx_user_id (user_id),
  CONSTRAINT balances_ibfk_1 FOREIGN KEY (group_id) REFERENCES expense_groups (id) ON DELETE CASCADE,
  CONSTRAINT balances_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settlements (
  id INT(11) NOT NULL AUTO_INCREMENT,
  group_id INT(11) NOT NULL,
  from_user_id INT(11) NOT NULL,
  to_user_id INT(11) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  date DATE NOT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_id (group_id),
  KEY idx_from_user (from_user_id),
  KEY idx_to_user (to_user_id),
  CONSTRAINT settlements_ibfk_1 FOREIGN KEY (group_id) REFERENCES expense_groups (id) ON DELETE CASCADE,
  CONSTRAINT settlements_ibfk_2 FOREIGN KEY (from_user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT settlements_ibfk_3 FOREIGN KEY (to_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  group_id INT(11) NOT NULL,
  email VARCHAR(255) NOT NULL,
  invited_by INT(11) NOT NULL,
  user_id INT(11) DEFAULT NULL,
  status ENUM('pending', 'accepted', 'expired') DEFAULT 'pending',
  token VARCHAR(255) NOT NULL,
  expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY token (token),
  KEY group_id (group_id),
  KEY invited_by (invited_by),
  KEY idx_token (token),
  KEY idx_email (email),
  KEY idx_status (status),
  KEY user_id (user_id),
  CONSTRAINT invitations_ibfk_1 FOREIGN KEY (group_id) REFERENCES expense_groups (id) ON DELETE CASCADE,
  CONSTRAINT invitations_ibfk_2 FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT invitations_ibfk_3 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
  id INT(11) NOT NULL AUTO_INCREMENT,
  group_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  action VARCHAR(50) NOT NULL,
  entity_type VARCHAR(50) DEFAULT NULL,
  entity_id INT(11) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY idx_group_id (group_id),
  KEY idx_created_at (created_at),
  CONSTRAINT activities_ibfk_1 FOREIGN KEY (group_id) REFERENCES expense_groups (id) ON DELETE CASCADE,
  CONSTRAINT activities_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (name) VALUES
  ('001_create_users_table'),
  ('002_create_groups_table'),
  ('003_create_group_members_table'),
  ('004_create_expenses_table'),
  ('005_create_expense_splits_table'),
  ('006_create_balances_table'),
  ('007_create_settlements_table'),
  ('008_create_invitations_table'),
  ('009_create_activities_table'),
  ('010_add_group_members_status')
ON DUPLICATE KEY UPDATE name = name;
