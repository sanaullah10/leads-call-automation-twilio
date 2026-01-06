<?php
// simple dotenv loader
function loadEnv($file = '.env')
{
     $file = __DIR__ . '/' . $file;
     if (!file_exists($file)) return;
     $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
     foreach ($lines as $line) {
          if (str_starts_with(trim($line), '#')) continue;
          [$key, $value] = array_map('trim', explode('=', $line, 2));
          $value = trim($value, "\"'");
          $_ENV[$key] = $value;
     }
}

loadEnv();

// Helper function to get environment variables from $_ENV or getenv()
if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);
        return $value !== false ? $value : $default;
    }
}

date_default_timezone_set(env('timezone', 'UTC'));

// Get database configuration with defaults
$dbDsn = env('DB_DSN', 'mysql:host=localhost;dbname=perfex;charset=utf8mb4');
$dbUser = env('DB_USER', 'root');
$dbPass = env('DB_PASS', '');

try {
     $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     ]);
} catch (PDOException $e) {
     die("DB error: " . $e->getMessage());
}