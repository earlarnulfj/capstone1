# Google OAuth Setup - Step by Step Guide

## Step 1: Configure OAuth Consent Screen

1. In Google Cloud Console, click **"Configure consent screen"** (yellow button on the Credentials page)
2. Choose **User Type**: 
   - For testing: Select "External" 
   - Click "Create"
3. Fill in the **App information**:
   - **App name**: Your application name (e.g., "Inventory Management System")
   - **User support email**: Your email address
   - **Developer contact information**: Your email address
   - Click **"Save and Continue"**
4. **Scopes** (Step 2):
   - Click **"Add or Remove Scopes"**
   - Search for and add:
     - `userinfo.email`
     - `userinfo.profile`
     - `openid`
   - Click **"Update"**
   - Click **"Save and Continue"**
5. **Test users** (Step 3):
   - Click **"Add Users"**
   - Add your Google email address (the one you'll use to test)
   - Click **"Save and Continue"**
6. **Summary** (Step 4):
   - Review and click **"Back to Dashboard"**

## Step 2: Create OAuth 2.0 Client ID

1. Go back to **APIs & Services** → **Credentials**
2. Click **"+ CREATE CREDENTIALS"** → **"OAuth client ID"**
3. If prompted, select **"Web application"** as Application type
4. Fill in:
   - **Name**: "Inventory System OAuth Client" (or any name)
   - **Authorized JavaScript origins**: 
     - For localhost: `http://localhost`
     - For production: `https://yourdomain.com`
   - **Authorized redirect URIs**: 
     - **IMPORTANT**: First, visit `/auth/google/debug.php` to get the EXACT redirect URI
     - Copy the "Calculated Redirect URI" from the debug page
     - Paste it here (e.g., `http://localhost/auth/google/callback.php` or `http://localhost/haha/auth/google/callback.php`)
5. Click **"CREATE"**
6. **COPY YOUR CREDENTIALS**:
   - Copy the **Client ID** (looks like: `123456789-abc.apps.googleusercontent.com`)
   - Copy the **Client Secret** (looks like: `GOCSPX-abc123xyz`)
   - Keep this window open or save these credentials!

## Step 3: Configure Your Application

1. Open `config/google_oauth.php`
2. Replace:
   ```php
   define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID_HERE');
   ```
   with:
   ```php
   define('GOOGLE_CLIENT_ID', 'paste-your-client-id-here');
   ```

3. Replace:
   ```php
   define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET_HERE');
   ```
   with:
   ```php
   define('GOOGLE_CLIENT_SECRET', 'paste-your-client-secret-here');
   ```

## Step 4: Verify Redirect URI

1. Visit: `http://localhost/auth/google/debug.php` (or your domain)
2. Copy the **"Calculated Redirect URI (actual)"** - the one in the green box
3. Go back to Google Cloud Console
4. Edit your OAuth Client ID
5. Make sure the redirect URI in "Authorized redirect URIs" matches EXACTLY
6. Save

## Step 5: Test

1. Clear your browser cookies for localhost
2. Go to `http://localhost/login.php`
3. Click "Sign in with Google" (any role)
4. You should be redirected to Google
5. Sign in with your Google account
6. You should be redirected back and logged in

## Troubleshooting

### If you see "400 Error":
- The redirect URI doesn't match exactly
- Visit `/auth/google/debug.php` and copy the EXACT redirect URI shown
- Make sure it's added in Google Console → OAuth Client → Authorized redirect URIs

### If you see "Redirect Loop":
- Clear cookies
- Check that redirect URI doesn't have duplicate paths
- Make sure the redirect URI in Google Console matches what's in debug.php

### If you see "No account found":
- Your Google email must exist in the database
- Check `users` table for Admin/Staff roles
- Check `suppliers` table for Supplier role
- The email in Google account must match exactly (case-sensitive)

