<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function refresh_current_user(PDO $pdo): ?array
{
    $sessionUser = current_user();
    if (!$sessionUser) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT u.id, u.employee_id, u.name, u.email, u.is_active, r.name AS role, e.is_active AS employee_active
         FROM users u JOIN roles r ON r.id=u.role_id
         LEFT JOIN employees e ON e.id=u.employee_id WHERE u.id=:id LIMIT 1'
    );
    $statement->execute(['id' => $sessionUser['id']]);
    $user = $statement->fetch();
    if (!$user || !(int) $user['is_active']
        || ($user['role'] !== 'admin' && (!(int) $user['employee_id'] || !(int) $user['employee_active']))) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    unset($user['is_active'], $user['employee_active']);
    $_SESSION['user'] = $user;
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    return $user;
}

function attempt_login(PDO $pdo, string $email, string $password): bool
{
    $statement = $pdo->prepare(
        'SELECT u.id, u.employee_id, u.name, u.email, u.password_hash, u.is_active, r.name AS role,
                e.is_active AS employee_active
         FROM users u JOIN roles r ON r.id = u.role_id
         LEFT JOIN employees e ON e.id=u.employee_id
         WHERE u.email = :email LIMIT 1'
    );
    $statement->execute(['email' => mb_strtolower(trim($email))]);
    $user = $statement->fetch();
    if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])
        || ($user['role'] !== 'admin' && (!(int) $user['employee_id'] || !(int) $user['employee_active']))) {
        return false;
    }

    session_regenerate_id(true);
    unset($user['password_hash'], $user['is_active'], $user['employee_active']);
    $_SESSION['user'] = $user;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $update->execute(['id' => $user['id']]);
    audit($pdo, (int) $user['id'], 'login', 'user', (string) $user['id']);
    return true;
}

function logout_user(PDO $pdo): void
{
    $user = current_user();
    if ($user) {
        audit($pdo, (int) $user['id'], 'logout', 'user', (string) $user['id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function login_throttled(PDO $pdo, string $email): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packedIp = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : null;
    $statement = $pdo->prepare(
        'SELECT
           SUM(JSON_UNQUOTE(JSON_EXTRACT(details, "$.email_hash")) = :email_hash) AS by_email,
           SUM(ip_address = :ip) AS by_ip
         FROM audit_logs
         WHERE action = "login_failed" AND created_at >= NOW() - INTERVAL 15 MINUTE'
    );
    $statement->execute([
        'email_hash' => hash('sha256', mb_strtolower(trim($email))),
        'ip' => $packedIp,
    ]);
    $counts = $statement->fetch();
    return (int) ($counts['by_email'] ?? 0) >= 5 || (int) ($counts['by_ip'] ?? 0) >= 20;
}
