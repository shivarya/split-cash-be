# Database Migrations

## Migration: Pending Member Status (migration_add_pending_status.sql)

### What's New
- Members invited by email now appear in the group immediately as "Pending"
- Pending members show their email address as their name
- When they log in with Google, their name automatically updates
- All expense history is preserved and linked to their real account

### Database Changes
1. Added `pending` status to `group_members` table
2. Added `user_id` column to `invitations` table to link placeholder users

### Required Migration
Run this in your database:

```sql
source database/migration_add_pending_status.sql
```

Or manually:

```sql
ALTER TABLE group_members 
MODIFY COLUMN status ENUM('active', 'left', 'pending') DEFAULT 'active';

ALTER TABLE invitations 
ADD COLUMN user_id INT NULL AFTER invited_by,
ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
```

### How It Works
1. When inviting a member by email:
   - Creates a placeholder user with email as name
   - Adds them to group with 'pending' status
   - Sends invitation email
2. When the invited user logs in:
   - System finds placeholder account with matching email
   - Transfers all memberships, expenses, and splits to real account
   - Deletes placeholder account
   - Updates status to 'active'

---

## Migration: Group Member Status (migration_add_status.sql)

### What's New
- Groups you leave will now remain visible in your Groups tab
- You can rejoin groups you've previously left
- Left groups are shown with a greyed-out appearance and a "You left • Tap to rejoin" badge

### Database Changes
This update adds a `status` field to the `group_members` table to track whether a member is `active` or `left`.

### Required Migration
If you have an existing database, run the migration:

```sql
-- Run this in your database
source database/migration_add_status.sql
```

Or manually:

```sql
ALTER TABLE group_members 
  ADD COLUMN status ENUM('active', 'left') DEFAULT 'active' AFTER role,
  ADD COLUMN left_at TIMESTAMP NULL AFTER joined_at,
  ADD INDEX idx_status (status);

UPDATE group_members SET status = 'active' WHERE status IS NULL;
```

### For New Installations
The main `schema.sql` file already includes these changes. No additional steps needed.

## Backend Changes
- **Leave Group**: Now sets `status = 'left'` instead of deleting the record
- **Get Groups**: Returns all groups including left ones with their status
- **Get Group Details**: Checks status and blocks access for left members
- **Rejoin Group**: New endpoint to reactivate membership
- **Add Member**: Reactivates left members instead of creating duplicates

## Frontend Changes
- **GroupListScreen**: Shows left groups with special styling and rejoin option
- **DashboardScreen**: Filters out left groups from statistics
- **API Service**: Added `rejoinGroup()` method

## Usage
1. Users can leave a group (status becomes 'left')
2. Left groups appear greyed out in the Groups tab
3. Tap a left group to rejoin it (status becomes 'active' again)
4. Transaction history is preserved even when left

## Benefits
- Users can easily rejoin groups they left by mistake
- Transaction history remains intact
- No data loss when leaving/rejoining groups
