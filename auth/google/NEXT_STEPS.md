# Next Steps After Creating OAuth Client

## ✅ What You've Done
- Created OAuth 2.0 Client ID: "Web client 1"
- Client ID: `975310801467-t21b...`

## 📋 What To Do Now

### Step 1: Get Your Client ID and Secret

1. In Google Console, click on **"Web client 1"** (the name link) or click the **edit icon** (pencil)
2. You'll see:
   - **Client ID**: Copy this (full value, not just the preview)
   - **Client Secret**: Click "Show" to reveal it, then copy it

### Step 2: Configure Your Application

1. Open `config/google_oauth.php` in your code editor
2. Find these lines:
   ```php
   define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID_HERE');
   define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET_HERE');
   ```
3. Replace with your actual credentials:
   ```php
   define('GOOGLE_CLIENT_ID', '975310801467-t21b...paste-full-id-here');
   define('GOOGLE_CLIENT_SECRET', 'GOCSPX-...paste-full-secret-here');
   ```
4. Save the file

### Step 3: Verify Redirect URI Configuration

1. While editing the OAuth client in Google Console:
2. Check **"Authorized redirect URIs"** section
3. Make sure it contains: `http://localhost/haha/auth/google/callback.php`
   - If it's missing, add it
   - If the path is wrong, update it
4. Check **"Authorized JavaScript origins"** contains: `http://localhost` (domain only!)
5. Click **"SAVE"**

### Step 4: Test the Login

1. Clear your browser cookies (optional but recommended)
2. Visit: `http://localhost/login.php`
3. Click "Sign in with Google" (any role: Admin, Staff, or Supplier)
4. You should be redirected to Google
5. Sign in with your Google account
6. You'll be redirected back and logged in!

## 🔍 Troubleshooting

### If you see "Invalid redirect URI":
- Make sure the redirect URI in Google Console matches exactly
- Visit `/auth/google/debug.php` to see the exact URI your app uses
- Copy that URI and add it to Google Console

### If you see "No account found":
- Your Google email must exist in the database
- Check `users` table for Admin/Staff
- Check `suppliers` table for Supplier role
- Email must match exactly (case-sensitive)

### If login works but redirects to wrong page:
- Check the user's role in the database
- Admin/Staff should be in `users` table with role `management` or `staff`
- Supplier should be in `suppliers` table or `users` table with role `supplier`

## 🎉 Success Indicators

When everything works:
- Clicking "Sign in with Google" redirects to Google
- After signing in, you're redirected back to your site
- You're logged in and can access your dashboard
- Session is set correctly (check browser DevTools → Application → Cookies)

