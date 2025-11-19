# Google OAuth Authentication Setup

## Prerequisites

1. You need a Google Cloud Project with OAuth 2.0 credentials
2. The Google account email must exist in your system's `users` or `suppliers` table

## Setup Instructions

### 1. Create Google OAuth Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to **APIs & Services** > **Credentials**
4. Click **Create Credentials** > **OAuth client ID**
5. If prompted, configure the OAuth consent screen:
   - User Type: External (unless you have Google Workspace)
   - App name: Your app name
   - User support email: Your email
   - Developer contact: Your email
   - Scopes: Add `email` and `profile`
   - Test users: Add any test emails
6. Create OAuth Client ID:
   - Application type: **Web application**
   - Name: Your app name
   - Authorized redirect URIs: 
     - For localhost: `http://localhost/auth/google/callback.php`
     - For production: `https://yourdomain.com/auth/google/callback.php`
7. Copy the **Client ID** and **Client Secret**

### 2. Configure the Application

Edit `config/google_oauth.php` and replace:
- `YOUR_GOOGLE_CLIENT_ID_HERE` with your Client ID
- `YOUR_GOOGLE_CLIENT_SECRET_HERE` with your Client Secret

Or set environment variables:
```bash
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
```

### 3. Ensure Users Exist

The Google email address must exist in your database:
- For Admin/Staff: Must exist in `users` table with matching email
- For Supplier: Must exist in `suppliers` table with matching email

### 4. Testing

1. Go to `login.php`
2. Click one of the "Sign in with Google" buttons (Admin, Staff, or Supplier)
3. You'll be redirected to Google to authenticate
4. After authentication, you'll be logged in to the selected role

## Features

- **Multi-role Support**: Log in as Admin, Staff, or Supplier
- **Simultaneous Logins**: You can be logged in to multiple roles at once
- **No Password Required**: Google authentication bypasses password verification
- **Secure**: Uses OAuth 2.0 with state token for CSRF protection

## Troubleshooting

### 400 Error - "The server cannot process the request"

This is the most common error. It usually means:

1. **Redirect URI Mismatch** (MOST COMMON):
   - The redirect URI used in your code must match EXACTLY what's in Google Cloud Console
   - Use the debug tool: Visit `/auth/google/debug.php` to see your current redirect URI
   - Copy that exact URI and add it to Google Console → Credentials → Your OAuth Client → Authorized redirect URIs
   - Common mistakes:
     - Using `http://` instead of `https://` (or vice versa)
     - Missing leading slash (`/auth/...` vs `auth/...`)
     - Including port numbers incorrectly
     - Case sensitivity (though URIs are usually case-insensitive)

2. **Missing or Invalid Credentials**:
   - Make sure `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` are set in `config/google_oauth.php`
   - They should NOT be "YOUR_GOOGLE_CLIENT_ID_HERE"

3. **How to Debug**:
   - Visit: `http://yourdomain.com/auth/google/debug.php` (or `https://yourdomain.com/auth/google/debug.php`)
   - This will show you the exact redirect URI your system is using
   - Copy that URI and add it to Google Cloud Console

4. **Step-by-Step Fix**:
   ```
   1. Visit /auth/google/debug.php
   2. Copy the "Current Redirect URI"
   3. Go to Google Cloud Console → APIs & Services → Credentials
   4. Click on your OAuth 2.0 Client ID
   5. Scroll to "Authorized redirect URIs"
   6. Click "ADD URI"
   7. Paste the exact URI from step 2
   8. Click "SAVE"
   9. Wait 1-2 minutes
   10. Try again
   ```

### Other Errors

- **"No account found"**: The Google email must exist in your database (`users` or `suppliers` table)
- **"Invalid security token"**: Usually means session expired, try again
- **"Failed to get access token"**: Check error logs for details, usually means redirect URI mismatch or invalid credentials

