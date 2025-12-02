# PHP Backend Setup for Split Cash

## 🛠️ Local Development Setup

### 1. Install PHP and Composer

#### Windows (using Chocolatey):
```powershell
# Install Chocolatey if not installed
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))

# Install PHP
choco install php

# Install Composer
choco install composer

# Verify installations
php -v
composer -v
```

#### Or Download Manually:
- **PHP**: https://windows.php.net/download/ (Download PHP 8.x Thread Safe ZIP)
- **Composer**: https://getcomposer.org/download/

### 2. Install MySQL/MariaDB

```powershell
# Using XAMPP (includes PHP, MySQL, Apache)
choco install xampp

# Or standalone MySQL
choco install mysql
```

### 3. Install PHP Dependencies

```bash
cd server-php
composer install
```

This will install:
- `firebase/php-jwt` - JWT authentication
- `google/apiclient` - Google OAuth verification

### 4. Configure Environment

Create `.env` file in `server-php` directory:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=split_cash
DB_USER=root
DB_PASS=

JWT_SECRET=985e52ac9742bf4c64089fab4b59b1fbbac208300ec827c3f05de6e966285c85ba219339d7e107438536bc4e22713d671c7de22e1e4569cb25c70bd0fefbb7b6

GOOGLE_CLIENT_ID=961328387938-mrob7sroupab8kk14kk1g0io1pa2b5ri.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=YOUR_GOOGLE_CLIENT_SECRET

EMAIL_HOST=shivarya.dev
EMAIL_PORT=465
EMAIL_USER=splitcash@shivarya.dev
EMAIL_PASS=YOUR_EMAIL_PASSWORD

FRONTEND_URL=https://shivarya.github.io/split-cash
```

### 5. Setup Database

Import the SQL schema (same as Node.js version):

```bash
mysql -u root -p split_cash < database.sql
```

### 6. Test Locally

#### Option A: PHP Built-in Server
```bash
cd server-php
php -S localhost:3000 index.php
```

#### Option B: XAMPP
1. Copy `server-php` folder to `C:\xampp\htdocs\`
2. Start XAMPP Apache and MySQL
3. Access: `http://localhost/server-php/`

#### Option C: Using WAMP/MAMP
Similar to XAMPP setup

### 7. Test API

```bash
# Health check
curl http://localhost:3000/health

# Expected response:
# {"success":true,"data":{"status":"healthy","database":"connected","timestamp":"2025-11-25T..."}}
```

---

## 🚀 cPanel Deployment

### 1. Prepare Files

```powershell
# Compress PHP files
Compress-Archive -Path .\server-php\* -DestinationPath server-php.zip
```

### 2. Upload to cPanel

1. **Login to cPanel**: https://shivarya.dev:2083
2. **File Manager** → Navigate to `public_html/api/`
3. **Upload** `server-php.zip`
4. **Extract** the ZIP file
5. **Move contents** from `server-php` folder to `api` folder

Final structure:
```
public_html/
  └── api/
      ├── index.php
      ├── config/
      ├── controllers/
      ├── utils/
      ├── vendor/
      └── .htaccess
```

### 3. Install Composer Dependencies on cPanel

#### Method 1: SSH (if available)
```bash
ssh username@shivarya.dev
cd public_html/api
composer install
```

#### Method 2: Upload vendor folder
```powershell
# On local machine after composer install
cd server-php
Compress-Archive -Path .\vendor\* -DestinationPath vendor.zip

# Upload vendor.zip to cPanel and extract
```

### 4. Create .htaccess for Clean URLs

Create `public_html/api/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /api/
    
    # Handle CORS preflight
    RewriteCond %{REQUEST_METHOD} OPTIONS
    RewriteRule ^(.*)$ $1 [R=200,L]
    
    # Route all requests to index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>

# Enable CORS
Header always set Access-Control-Allow-Origin "*"
Header always set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
Header always set Access-Control-Allow-Headers "Content-Type, Authorization"
```

### 5. Configure PHP Settings

Create `public_html/api/.user.ini`:

```ini
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 256M
max_execution_time = 300
display_errors = Off
log_errors = On
error_log = /home/username/public_html/api/php_errors.log
```

### 6. Setup Environment Variables

Create `public_html/api/.env`:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=username_split_cash
DB_USER=username_split_cash
DB_PASS=YOUR_DB_PASSWORD

JWT_SECRET=985e52ac9742bf4c64089fab4b59b1fbbac208300ec827c3f05de6e966285c85ba219339d7e107438536bc4e22713d671c7de22e1e4569cb25c70bd0fefbb7b6

GOOGLE_CLIENT_ID=961328387938-mrob7sroupab8kk14kk1g0io1pa2b5ri.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=YOUR_SECRET

EMAIL_HOST=shivarya.dev
EMAIL_PORT=465
EMAIL_USER=splitcash@shivarya.dev
EMAIL_PASS=YOUR_PASSWORD

FRONTEND_URL=https://shivarya.github.io/split-cash
```

Load .env in `config/config.php`:

```php
<?php
// Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
            putenv(trim($name) . '=' . trim($value));
        }
    }
}
```

### 7. Create MySQL Database

1. **cPanel → MySQL Databases**
2. **Create Database**: `username_split_cash`
3. **Create User**: `username_split_cash`
4. **Grant All Privileges** to user on database
5. **Import SQL**: Use phpMyAdmin to import `database.sql`

### 8. Update Mobile App API URL

Update `mobile/.env`:

```env
API_URL=https://shivarya.dev/api
GOOGLE_CLIENT_ID=961328387938-mrob7sroupab8kk14kk1g0io1pa2b5ri.apps.googleusercontent.com
```

### 9. Test Production API

```bash
curl https://shivarya.dev/api/health
```

---

## 📝 API Endpoints

All endpoints remain the same as Node.js version:

- `POST /api/auth/google` - Google OAuth login
- `GET /api/auth/profile` - Get user profile
- `PUT /api/auth/profile` - Update user profile
- `POST /api/groups` - Create group
- `GET /api/groups` - Get user's groups
- `GET /api/groups/:id` - Get group details
- `POST /api/expenses/:groupId` - Add expense
- `GET /api/expenses/:groupId` - Get group expenses
- `GET /api/balances/:groupId` - Get group balances
- `GET /api/balances/my-balances` - Get user's all balances

---

## 🔧 Troubleshooting

### PHP Extensions Required

Enable in `php.ini`:
```ini
extension=mysqli
extension=pdo_mysql
extension=openssl
extension=curl
extension=mbstring
extension=json
```

### Common Issues

1. **500 Internal Server Error**
   - Check `php_errors.log`
   - Verify file permissions: `chmod 755` on directories, `644` on files

2. **Database Connection Failed**
   - Verify database credentials in `.env`
   - Check MySQL is running
   - Ensure user has privileges

3. **Composer autoload not found**
   - Run `composer install` in server-php directory
   - Or upload pre-installed `vendor` folder

4. **.htaccess not working**
   - Enable mod_rewrite in Apache
   - Check AllowOverride is set to All

---

## ✅ Verification Checklist

- [ ] PHP 7.4+ installed
- [ ] Composer dependencies installed
- [ ] MySQL database created and imported
- [ ] `.env` file configured
- [ ] `.htaccess` created
- [ ] `/health` endpoint returns success
- [ ] Google OAuth working
- [ ] Mobile app connects to API

---

**API URL**: `https://shivarya.dev/api`
**Health Check**: `https://shivarya.dev/api/health`
