# API Testing Guide

## 1. Test Health Endpoint

**Test URL:** `https://shivarya.dev/api/health`

Open in browser or use curl:
```bash
curl https://shivarya.dev/api/health
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "status": "ok",
    "database": "connected",
    "timestamp": "2025-12-02T10:30:00.000Z"
  }
}
```

If you get "database": "disconnected", check your cPanel `.env` database credentials.

---

## 2. Test Google Authentication

**Endpoint:** `POST https://shivarya.dev/api/auth/google`

**Using curl:**
```bash
curl -X POST https://shivarya.dev/api/auth/google \
  -H "Content-Type: application/json" \
  -d '{"idToken":"YOUR_GOOGLE_ID_TOKEN_HERE"}'
```

**Expected Response (New User):**
```json
{
  "success": true,
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "name": "John Doe",
      "profile_picture": "https://...",
      "currency": "INR",
      "created_at": "2025-12-02T10:30:00.000Z"
    },
    "isNewUser": true
  }
}
```

---

## 3. Test Authenticated Endpoints

First, get a token from Google auth (step 2), then test:

### Get User Profile
```bash
curl https://shivarya.dev/api/auth/profile \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "email": "user@example.com",
    "name": "John Doe",
    "profile_picture": "https://...",
    "phone": null,
    "currency": "INR"
  }
}
```

### Create Group
```bash
curl -X POST https://shivarya.dev/api/groups \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Group",
    "description": "Testing API",
    "category": "trip"
  }'
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Test Group",
    "description": "Testing API",
    "category": "trip",
    "created_by": 1,
    "created_at": "2025-12-02T10:30:00.000Z"
  }
}
```

### Get Groups
```bash
curl https://shivarya.dev/api/groups \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Test Group",
      "description": "Testing API",
      "category": "trip",
      "created_by": 1,
      "member_count": 1,
      "total_expenses": 0,
      "your_balance": 0
    }
  ]
}
```

---

## 4. Test with Mobile App

### Update Mobile App Configuration

1. **Edit `mobile/.env`:**
   ```bash
   # Comment out local API
   # API_URL=http://10.0.2.2:3000/api
   
   # Use production API
   API_URL=https://shivarya.dev/api
   
   GOOGLE_CLIENT_ID=961328387938-mrob7sroupab8kk14kk1g0io1pa2b5ri.apps.googleusercontent.com
   ```

2. **Restart Metro bundler:**
   ```bash
   cd mobile
   npx expo start --clear
   ```

3. **Test in app:**
   - Open app on Android device/emulator
   - Click "Sign in with Google"
   - Select Google account
   - Should successfully log in and show Dashboard

---

## 5. Common Issues & Fixes

### Issue: "database": "disconnected"
**Fix:** Check cPanel `.env` file has correct database credentials:
```bash
DB_HOST=localhost
DB_NAME=your_cpanel_database_name
DB_USER=your_cpanel_database_user
DB_PASS=your_cpanel_database_password
```

### Issue: "CORS error" in browser
**Fix:** Check `.htaccess` has CORS headers:
```apache
Header set Access-Control-Allow-Origin "*"
Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
Header set Access-Control-Allow-Headers "Content-Type, Authorization"
```

### Issue: "404 Not Found" for API routes
**Fix:** 
1. Check `.htaccess` exists in root with rewrite rules
2. Verify mod_rewrite is enabled in cPanel (usually enabled by default)
3. Check file structure: `index.php` should be in root directory

### Issue: "Wrong recipient" error during Google Sign-In
**Fix:** Check cPanel `.env` has correct GOOGLE_CLIENT_ID matching your OAuth credentials

### Issue: Mobile app can't connect
**Fix:**
1. Make sure you're using `https://shivarya.dev/api` (not http)
2. Test health endpoint in browser first
3. Clear Metro cache: `npx expo start --clear`
4. Rebuild app if needed: `npx expo run:android`

---

## 6. Production Checklist

- [ ] Health endpoint returns `"status": "ok"` and `"database": "connected"`
- [ ] Google Sign-In works (test with real Google account)
- [ ] Create group works
- [ ] Get groups works
- [ ] Mobile app connects successfully
- [ ] Error logging is working (check cPanel error logs)
- [ ] `.env` file is secure (not publicly accessible)
- [ ] All environment variables are set correctly

---

## 7. Monitoring

### Check Server Logs
In cPanel → **Error Log** or **Metrics** → **Errors**

### Check Database
In cPanel → **phpMyAdmin** → Check `users`, `expense_groups`, `migrations` tables

### Test API Response Time
```bash
curl -w "@-" -o /dev/null -s https://shivarya.dev/api/health <<'EOF'
    time_namelookup:  %{time_namelookup}s\n
       time_connect:  %{time_connect}s\n
    time_appconnect:  %{time_appconnect}s\n
      time_redirect:  %{time_redirect}s\n
   time_starttransfer:  %{time_starttransfer}s\n
                      ----------\n
          time_total:  %{time_total}s\n
EOF
```

**Good response time:** < 500ms

---

## Need Help?

If any test fails, check:
1. cPanel error logs
2. Database connection in phpMyAdmin
3. `.htaccess` configuration
4. `.env` environment variables
5. PHP version (should be 7.4 or higher)
