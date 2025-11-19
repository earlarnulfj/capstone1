<?php
/**
 * Google OAuth Debug Helper
 * Use this file to verify your redirect URI configuration
 */
session_start();
require_once '../../config/google_oauth.php';
require_once '../../config/app.php';

// Handle error parameter from URL
$errorFromUrl = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google OAuth Debug - Redirect URI Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Google OAuth Configuration Debug</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($errorFromUrl)): ?>
                            <div class="alert alert-danger mb-3">
                                <strong>⚠️ Configuration Error:</strong><br>
                                <?php echo $errorFromUrl; ?>
                            </div>
                        <?php endif; ?>
                        
                        <h5>Current Configuration</h5>
                        
                        <div class="mb-3">
                            <strong>Client ID:</strong>
                            <div class="alert alert-info">
                                <?php 
                                if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID_HERE' || empty(GOOGLE_CLIENT_ID)) {
                                    echo '<span class="text-danger">NOT CONFIGURED</span>';
                                } else {
                                    echo htmlspecialchars(substr(GOOGLE_CLIENT_ID, 0, 20)) . '...';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Client Secret:</strong>
                            <div class="alert alert-<?php echo (GOOGLE_CLIENT_SECRET === 'YOUR_GOOGLE_CLIENT_SECRET_HERE' || empty(GOOGLE_CLIENT_SECRET)) ? 'danger' : 'success'; ?>">
                                <?php 
                                if (GOOGLE_CLIENT_SECRET === 'YOUR_GOOGLE_CLIENT_SECRET_HERE' || empty(GOOGLE_CLIENT_SECRET)) {
                                    echo '<span class="text-danger"><strong>⚠️ NOT CONFIGURED</strong></span><br>';
                                    echo '<small>This is likely causing the authentication to fail. Please click "Show" in Google Console and copy the Client Secret to config/google_oauth.php</small>';
                                } else {
                                    echo '<span class="text-success">✅ CONFIGURED</span> (hidden for security)';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Current Redirect URI (from config):</strong>
                            <div class="alert alert-warning">
                                <code><?php echo htmlspecialchars(GOOGLE_REDIRECT_URI); ?></code>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Calculated Redirect URIs (actual):</strong>
                            <?php
                            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $calcBase = defined('APP_BASE') ? APP_BASE : '';
                            if (strpos($calcBase, '/auth') !== false) {
                                $calcBase = dirname(dirname($calcBase));
                            }
                            if ($calcBase === '/' || $calcBase === '\\') {
                                $calcBase = '';
                            }
                            $calcCallbackPath = ($calcBase !== '') ? $calcBase . '/auth/google/callback.php' : '/auth/google/callback.php';
                            $calcRegisterCallbackPath = ($calcBase !== '') ? $calcBase . '/auth/google/register_callback.php' : '/auth/google/register_callback.php';
                            $calculatedCallbackUri = $protocol . '://' . $host . $calcCallbackPath;
                            $calculatedRegisterCallbackUri = $protocol . '://' . $host . $calcRegisterCallbackPath;
                            ?>
                            <div class="alert alert-success">
                                <strong>For Login:</strong><br>
                                <code><?php echo htmlspecialchars($calculatedCallbackUri); ?></code>
                            </div>
                            <div class="alert alert-info mt-2">
                                <strong>For Registration:</strong><br>
                                <code><?php echo htmlspecialchars($calculatedRegisterCallbackUri); ?></code>
                            </div>
                            <small class="text-danger"><strong>Add BOTH URIs to Google Console!</strong></small>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Protocol:</strong> <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'HTTPS' : 'HTTP'; ?><br>
                            <strong>Host:</strong> <?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost'); ?><br>
                            <strong>APP_BASE:</strong> <?php echo defined('APP_BASE') ? htmlspecialchars(APP_BASE) : '(not defined)'; ?><br>
                            <strong>Full URL:</strong> <?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '')); ?>
                        </div>
                        
                        <hr>
                        
                        <h5>Step-by-Step Setup Instructions</h5>
                        <div class="alert alert-info">
                            <strong>⚠️ IMPORTANT:</strong> You must configure the OAuth Consent Screen BEFORE creating credentials!
                        </div>
                        <ol>
                            <li><strong>Configure OAuth Consent Screen FIRST:</strong>
                                <ul>
                                    <li>Click the yellow "Configure consent screen" button in Google Console</li>
                                    <li>Choose "External" user type</li>
                                    <li>Fill in App name, support email, developer email</li>
                                    <li>Add scopes: <code>userinfo.email</code>, <code>userinfo.profile</code>, <code>openid</code></li>
                                    <li>Add test users (your Google email)</li>
                                    <li>Save and continue through all steps</li>
                                </ul>
                            </li>
                            <li><strong>Create OAuth 2.0 Client ID:</strong>
                                <ul>
                                    <li>Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Credentials</a></li>
                                    <li>Click "+ CREATE CREDENTIALS" → "OAuth client ID"</li>
                                    <li>Application type: <strong>Web application</strong></li>
                                    <li>Name: Any name (e.g., "Inventory System")</li>
                                    <li><strong>Authorized JavaScript origins:</strong>
                                        <div class="alert alert-warning mt-1 mb-1">
                                            <strong>⚠️ IMPORTANT:</strong> Enter <strong>ONLY</strong> the domain (no path!)<br>
                                            Example: <code>http://localhost</code> or <code>http://localhost:8080</code><br>
                                            <strong>DO NOT</strong> include the callback path here!
                                        </div>
                                    </li>
                                    <li><strong>Authorized redirect URIs:</strong>
                                        <div class="alert alert-success mt-1 mb-1">
                                            <strong>✅ CORRECT:</strong> Enter the <strong>FULL URL</strong> with path:<br>
                                            <code><?php echo htmlspecialchars($calculatedUri); ?></code>
                                        </div>
                                    </li>
                                </ul>
                            </li>
                            <li><strong>Copy the Redirect URI below and add it to Google Console:</strong>
                                <div class="alert alert-success mt-2">
                                    <strong>Copy this EXACT redirect URI:</strong><br>
                                    <code><?php echo htmlspecialchars($calculatedUri); ?></code>
                                </div>
                                <ul>
                                    <li>In "Authorized redirect URIs", click "ADD URI"</li>
                                    <li>Paste the URI from above</li>
                                    <li>Click "CREATE"</li>
                                </ul>
                            </li>
                            <li><strong>Copy your credentials:</strong>
                                <ul>
                                    <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong></li>
                                    <li>Paste them into <code>config/google_oauth.php</code></li>
                                </ul>
                            </li>
                            <li>Make sure:
                                <ul>
                                    <li>Protocol matches (http vs https)</li>
                                    <li>Domain matches exactly</li>
                                    <li>Path matches exactly (including leading slash)</li>
                                    <li>No trailing slashes</li>
                                </ul>
                            </li>
                            <li>Click Save</li>
                            <li>Wait 1-2 minutes for changes to propagate</li>
                            <li>Try logging in again</li>
                        </ol>
                        
                        <hr>
                        
                        <div class="alert alert-danger">
                            <strong>Common Issues:</strong>
                            <ul class="mb-0">
                                <li><strong>Redirect URI mismatch:</strong> The URI must match exactly (case-sensitive, including http/https)</li>
                                <li><strong>Missing credentials:</strong> Make sure Client ID and Secret are set in config/google_oauth.php</li>
                                <li><strong>Wrong protocol:</strong> If using HTTPS, make sure redirect URI uses https://</li>
                                <li><strong>Port numbers:</strong> If using a port (like localhost:8080), include it in the redirect URI</li>
                            </ul>
                        </div>
                        
                        <div class="mt-4">
                            <?php
                            $rootBase = defined('APP_BASE') ? APP_BASE : '';
                            if (strpos($rootBase, '/auth') !== false) {
                                $rootBase = dirname(dirname($rootBase));
                            }
                            if ($rootBase === '/' || $rootBase === '\\') {
                                $rootBase = '';
                            }
                            ?>
                            <a href="<?php echo $rootBase; ?>/login.php" class="btn btn-primary">Back to Login</a>
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="btn btn-outline-primary">Open Google Console</a>
                            <a href="https://console.cloud.google.com/apis/credentials/oauthclient?project=<?php echo urlencode($_GET['project'] ?? ''); ?>" target="_blank" class="btn btn-success">Create OAuth Client</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

