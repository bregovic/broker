<?php
/**
 * Global Configuration for Broker API
 * Handles both local environment files and Railway environment variables.
 */

// 1. Try to load local config if it exists
$localConfig = __DIR__ . '/env.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

// 2. Fallback to Environment Variables (Railway style)
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: '');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');

// 3. Railway DATABASE_URL support (optional)
if (getenv('DATABASE_URL')) {
    $url = parse_url(getenv('DATABASE_URL'));
    if (isset($url['host'])) define('DB_HOST_URL', $url['host']);
    if (isset($url['user'])) define('DB_USER_URL', $url['user']);
    if (isset($url['pass'])) define('DB_PASS_URL', $url['pass']);
    if (isset($url['path'])) define('DB_NAME_URL', substr($url['path'], 1));
}

/**
 * PDO Connection Helper
 */
function get_pdo() {
    $host = defined('DB_HOST_URL') ? DB_HOST_URL : DB_HOST;
    $db   = defined('DB_NAME_URL') ? DB_NAME_URL : DB_NAME;
    $user = defined('DB_USER_URL') ? DB_USER_URL : DB_USER;
    $pass = defined('DB_PASS_URL') ? DB_PASS_URL : DB_PASS;
    
    // Determine driver - Default to mysql for legacy compatibility
    $driver = 'mysql';
    if (strpos($host, 'postgres') !== false || getenv('DB_TYPE') === 'pgsql') {
        $driver = 'pgsql';
    }

    $dsn = "$driver:host=$host;dbname=$db;charset=utf8";
    if ($driver === 'pgsql') {
        // Postgres uses slightly different DSN
        $dsn = "pgsql:host=$host;dbname=$db";
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
