<?php

function loadEnv($path = __DIR__ . "/../.env", $override = false)
{
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), "#") === 0) {
            continue;
        }
        if (strpos(trim($line), "=") === false) {
            continue;
        }

        [$key, $value] = array_map("trim", explode("=", $line, 2));
        if (!empty($key)) {
            if ($override || !getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
    return true;
}

loadEnv(__DIR__ . "/../.env");
loadEnv(__DIR__ . "/../.env.local", true);

$environment = getenv("ENVIRONMENT") ?: "production";
$isDev = $environment === "development";

if ($isDev) {
    ini_set("display_errors", 1);
    error_reporting(E_ALL);
} else {
    ini_set("display_errors", 0);
    error_reporting(E_ALL);
    ini_set("log_errors", 1);
    ini_set("error_log", __DIR__ . "/../logs/error.log");
}

function setSecurityHeaders()
{
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header(
        'Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'',
    );

    if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
        header(
            "Strict-Transport-Security: max-age=31536000; includeSubDomains",
        );
    }
}

$rootPath = dirname(__DIR__);

define("SPREADSHEET_ID", getenv("SPREADSHEET_ID") ?: "");

$credPath = getenv("GOOGLE_CREDENTIALS_PATH") ?: "credentials/google.json";
if (
    strpos($credPath, "/") === 0 ||
    strpos($credPath, "\\") === 0 ||
    strpos($credPath, ":") === 1
) {
    define("GOOGLE_CREDENTIALS_PATH", $credPath);
} else {
    define("GOOGLE_CREDENTIALS_PATH", $rootPath . "/" . ltrim($credPath, "./"));
}

define("API_KEY", getenv("API_KEY") ?: "");
define(
    "ALLOWED_ORIGINS",
    explode(",", getenv("ALLOWED_ORIGINS") ?: "http://localhost"),
);
define("ENVIRONMENT", $environment);

if (php_sapi_name() !== "cli") {
    setSecurityHeaders();
}
