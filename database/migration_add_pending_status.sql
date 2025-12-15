-- Migration: Add pending status and user_id to invitations
-- This allows invitations to create placeholder users that appear in group member lists

-- Add 'pending' status to group_members
ALTER TABLE group_members 
MODIFY COLUMN status ENUM('active', 'left', 'pending') DEFAULT 'active';

-- Add user_id column to invitations table to link to placeholder users
ALTER TABLE invitations 
ADD COLUMN user_id INT NULL AFTER invited_by,
ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Record this migration
INSERT INTO migrations (name) VALUES ('migration_add_pending_status') 
ON DUPLICATE KEY UPDATE name=name;
