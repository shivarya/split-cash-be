# Quick Start - PHP Backend Setup

## Step 1: Install PHP (Windows)

```powershell
# Download PHP 8.2 from: https://windows.php.net/download/
# Extract to C:\php

# Add to PATH
[Environment]::SetEnvironmentVariable("Path", $env:Path + ";C:\php", "Machine")

# Verify
php -v
```

## Step 2: Install Composer

```powershell
# Download from: https://getcomposer.org/Composer-Setup.exe
# Run installer

# Verify
composer -v
```

## Step 3: Install Dependencies

```bash
cd server-php
composer install
```

## Step 4: Install MySQL (XAMPP Recommended)

```powershell
# Download XAMPP: https://www.apachefriends.org/download.html
# Install and start MySQL service

# Or use existing MySQL installation
```

## Step 5: Create Database

```sql
CREATE DATABASE split_cash;
USE split_cash;

-- Import schema from server\src\migrations\ files
-- Or run this quick schema:

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255),
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255),
    profile_picture TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE expense_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES expense_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_member (group_id, user_id)
);

CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    paid_by INT NOT NULL,
    category VARCHAR(50),
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES expense_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by) REFERENCES users(id)
);

CREATE TABLE expense_splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    paid_by INT NOT NULL,
    paid_to INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES expense_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by) REFERENCES users(id),
    FOREIGN KEY (paid_to) REFERENCES users(id)
);

CREATE TABLE invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES expense_groups(id) ON DELETE CASCADE
);
```

## Step 6: Configure Environment

Create `.env` file in `server-php/`:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=split_cash
DB_USER=root
DB_PASS=

JWT_SECRET=985e52ac9742bf4c64089fab4b59b1fbbac208300ec827c3f05de6e966285c85ba219339d7e107438536bc4e22713d671c7de22e1e4569cb25c70bd0fefbb7b6

GOOGLE_CLIENT_ID=961328387938-mrob7sroupab8kk14kk1g0io1pa2b5ri.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=YOUR_GOOGLE_CLIENT_SECRET

FRONTEND_URL=https://shivarya.github.io/split-cash
```

## Step 7: Start Server

```bash
cd server-php
php -S localhost:3000 index.php
```

## Step 8: Test API

```bash
# Health check
curl http://localhost:3000/health

# Should return:
# {"success":true,"data":{"status":"healthy","database":"connected","timestamp":"..."}}
```

## Step 9: Update Mobile App

Update `mobile/.env`:

```env
API_URL=http://10.0.2.2:3000
GOOGLE_CLIENT_ID=961328387938-mrob7sroupab8kk14kk1g0io1pa2b5ri.apps.googleusercontent.com
```

## Step 10: Test with Mobile App

```bash
cd mobile
npm run android
```

---

## Common Issues

### "Class 'PDO' not found"
Enable in `php.ini`:
```ini
extension=pdo_mysql
extension=mysqli
```

### "composer not found"
Add Composer to PATH or use full path:
```powershell
C:\ProgramData\ComposerSetup\bin\composer install
```

### "Database connection failed"
- Start MySQL in XAMPP Control Panel
- Check credentials in `.env`

---

## Ready for Production?

Follow the full deployment guide in `README.md` for cPanel deployment.
