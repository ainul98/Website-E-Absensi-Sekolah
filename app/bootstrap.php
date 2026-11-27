<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('e_absensi_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Konfigurasi belum tersedia. Salin config.example.php menjadi config.php dan sesuaikan pengaturan database.');
}

$config = require $configPath;
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Makassar');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/biometric.php';

try {
    $dbConfig = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['name']
    );
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    try {
        $storedZone = $pdo->query('SELECT timezone FROM school_settings WHERE id=1')->fetchColumn();
    } catch (PDOException $exception) {
        $storedZone = false; // skema lama: kolom timezone belum ada, jalankan migrasi 003
    }
    if (is_string($storedZone) && valid_timezone($storedZone)) {
        date_default_timezone_set($storedZone);
    }
    sync_mysql_timezone($pdo);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    exit('Database tidak dapat dihubungi. Periksa konfigurasi dan pastikan layanan MySQL berjalan.');
}
