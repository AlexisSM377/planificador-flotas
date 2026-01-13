<?php
/**
 * Configuration file for Bitacora Tracker
 * Load environment variables and set security settings
 */

// Load environment variables FIRST
function loadEnv($path = __DIR__ . '/../.env', $override = false) {
    if (!file_exists($path)) {
        return false; // .env file is optional
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos(trim($line), '=') === false) continue;
        
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!empty($key)) {
            // Set if override is true OR if not already set
            if ($override || !getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
    return true;
}

// Load environment files
loadEnv(__DIR__ . '/../.env');
loadEnv(__DIR__ . '/../.env.local', true); // Override with local settings

// NOW determine environment after loading env files
$environment = getenv('ENVIRONMENT') ?: 'production';
$isDev = $environment === 'development';

// Error handling - NEVER show errors in production
if ($isDev) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    // Log errors to file instead
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');
}

// Security headers
function setSecurityHeaders() {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // XSS Protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Content Security Policy
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'');
    
    // HSTS (only on HTTPS)
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Get root path
$rootPath = dirname(__DIR__);

// Configuration constants
define('SPREADSHEET_ID', getenv('SPREADSHEET_ID') ?: '');

// Handle credentials path - if it's relative, make it absolute
$credPath = getenv('GOOGLE_CREDENTIALS_PATH') ?: 'credentials/google.json';
if (strpos($credPath, '/') === 0 || strpos($credPath, '\\') === 0 || strpos($credPath, ':') === 1) {
    // Absolute path
    define('GOOGLE_CREDENTIALS_PATH', $credPath);
} else {
    // Relative path - make absolute from root
    define('GOOGLE_CREDENTIALS_PATH', $rootPath . '/' . ltrim($credPath, './'));
}

define('API_KEY', getenv('API_KEY') ?: '');
define('ALLOWED_ORIGINS', explode(',', getenv('ALLOWED_ORIGINS') ?: 'http://localhost'));
define('ENVIRONMENT', $environment);

// Validate required settings
if (empty(SPREADSHEET_ID) && !$isDev) {
    http_response_code(500);
    die('Configuration error: SPREADSHEET_ID not configured');
}

if (empty(API_KEY) && !$isDev) {
    http_response_code(500);
    die('Configuration error: API_KEY not configured');
}

if (!file_exists(GOOGLE_CREDENTIALS_PATH) && !$isDev) {
    http_response_code(500);
    die('Credentials file not found: ' . GOOGLE_CREDENTIALS_PATH);
}

// Call security headers function (only if running as web request)
if (php_sapi_name() !== 'cli') {
    setSecurityHeaders();
}
