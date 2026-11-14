<?php
$dotenvPath = ROOT_PATH . '/.env';
$dotenvConfig = is_file($dotenvPath) ? parse_ini_file($dotenvPath, false, INI_SCANNER_RAW) : [];
if (!is_array($dotenvConfig)) {
    throw new RuntimeException('Unable to parse the .env database configuration');
}

$host = getenv('HR_DB_HOST');
$db = getenv('HR_DB_NAME');
$user = getenv('HR_DB_USER');
$pass = getenv('HR_DB_PASS');
$host = $host === false ? ($dotenvConfig['HR_DB_HOST'] ?? 'localhost') : $host;
$db = $db === false ? ($dotenvConfig['HR_DB_NAME'] ?? 'hr_system') : $db;
$user = $user === false ? ($dotenvConfig['HR_DB_USER'] ?? null) : $user;
$pass = $pass === false ? ($dotenvConfig['HR_DB_PASS'] ?? null) : $pass;
if (!is_string($user) || $user === '' || !is_string($pass)) {
    throw new RuntimeException('Configure HR_DB_USER and HR_DB_PASS in .env or the process environment');
}
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่าระบบ');
}
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
