-- Migration: Add status column to group_members table
-- Run this if you already have an existing database

-- Add status and left_at columns
ALTER TABLE group_members 
  ADD COLUMN IF NOT EXISTS status ENUM('active', 'left') DEFAULT 'active' AFTER role,
  ADD COLUMN IF NOT EXISTS left_at TIMESTAMP NULL AFTER joined_at,
  ADD INDEX IF NOT EXISTS idx_status (status);

-- Set all existing members to 'active' status
UPDATE group_members SET status = 'active' WHERE status IS NULL;

-- Record migration
INSERT INTO migrations (name) VALUES ('010_add_group_members_status')
ON DUPLICATE KEY UPDATE name=name;
