<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function load_env_file($filePath) {
    if (!is_readable($filePath)) {
        return [];
    }

    $vars = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && (
            ($value[0] === '"' && substr($value, -1) === '"') ||
            ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $vars[$key] = $value;
        }
    }

    return $vars;
}

function get_config_value($key) {
    global $envConfig;

    if (isset($envConfig[$key])) {
        return trim((string)$envConfig[$key]);
    }

    $value = getenv($key);
    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }
    if ($value === false && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }
    return $value !== false ? trim((string)$value) : '';
}

$envConfig = [];
$envConfig = array_merge(
    $envConfig,
    load_env_file(__DIR__ . '/../.env'),
    load_env_file(__DIR__ . '/.env')
);

define('DB_NAME', get_config_value('GAP_DB_NAME'));
define('DB_USER', get_config_value('GAP_DB_USER'));
define('DB_PASSWORD', get_config_value('GAP_DB_PASSWORD'));
define('DB_HOST', get_config_value('GAP_DB_HOST') ?: 'localhost');

if (DB_NAME === '' || DB_USER === '' || DB_PASSWORD === '') {
    error_log('Database configuration is incomplete. Required env vars: GAP_DB_NAME, GAP_DB_USER, GAP_DB_PASSWORD.');
    http_response_code(500);
    exit('Application configuration error.');
}

include_once 'Database.php';

$dsn = "mysql:dbname=" . DB_NAME . ";host=" . DB_HOST;

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    error_log('Database connection failed.');
    http_response_code(500);
    exit('Database connection error.');
}

$db = new Database($pdo);
?>
