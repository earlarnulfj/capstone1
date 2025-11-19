# Google Console OAuth Client Setup - Correct Configuration

## Important: Two Different Fields

### ❌ WRONG - "Authorized JavaScript origins"
- **DO NOT** put the full callback URL here
- This field should contain **ONLY** the domain/protocol (no path!)
- Example: `http://localhost` (NOT `http://localhost/haha/auth/google/callback.php`)

### ✅ CORRECT - "Authorized redirect URIs"
- **DO** put the full callback URL here
- This field should contain the **COMPLETE** path to your callback
- Example: `http://localhost/haha/auth/google/callback.php`

## Step-by-Step Configuration

### 1. Authorized JavaScript origins
1. **Clear** the field or remove the path
2. Enter **ONLY**: `http://localhost`
   - If your site is at root: `http://localhost`
   - If you have a port: `http://localhost:8080`
   - **NO paths allowed here!**

### 2. Authorized redirect URIs
1. Keep or add: `http://localhost/haha/auth/google/callback.php`
   - This is the **full URL** including the path
   - This is correct and required

### 3. Save
1. Click "CREATE" or "SAVE"
2. Copy your Client ID and Client Secret
3. Paste them into `config/google_oauth.php`

## Quick Fix for Your Current Setup

**In "Authorized JavaScript origins":**
- ❌ Remove: `http://localhost/haha/auth/google/callback.php`
- ✅ Add: `http://localhost`

**In "Authorized redirect URIs":**
- ✅ Keep: `http://localhost/haha/auth/google/callback.php`

## Summary

- **JavaScript origins** = Domain only (no path)
- **Redirect URIs** = Full URL (with path)

