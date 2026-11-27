<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Salin config.example.php menjadi config.php terlebih dahulu.\n");
    exit(1);
}
$config = require $configPath;
$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
    $db['user'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$name = trim((string) readline('Nama administrator: '));
$email = strtolower(trim((string) readline('Email administrator: ')));
$password = (string) readline('Kata sandi (minimal 12 karakter): ');
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Nama/email tidak valid atau kata sandi kurang dari 12 karakter.\n");
    exit(1);
}
$statement = $pdo->prepare(
    'INSERT INTO users (role_id, name, email, password_hash, is_active)
     SELECT id, :name, :email, :password_hash, 1 FROM roles WHERE name="admin"'
);
$statement->execute([
    'name' => $name,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);
fwrite(STDOUT, "Akun administrator berhasil dibuat.\n");
