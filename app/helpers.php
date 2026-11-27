<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $page): never
{
    header('Location: index.php?page=' . rawurlencode($page));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Muat ulang halaman, lalu coba kembali.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function audit(PDO $pdo, ?int $userId, string $action, string $entity, ?string $entityId = null, array $details = []): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packedIp = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : null;
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address)
         VALUES (:user_id, :action, :entity, :entity_id, :details, :ip_address)'
    );
    $statement->execute([
        'user_id' => $userId,
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
        'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
        'ip_address' => $packedIp,
    ]);
}

function geodesic_distance_m(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
{
    $latitudeDelta = deg2rad($latitudeB - $latitudeA);
    $longitudeDelta = deg2rad($longitudeB - $longitudeA);
    $a = sin($latitudeDelta / 2) ** 2
        + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;
    return 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
}

function attendance_location_join_sql(): string
{
    return ' LEFT JOIN (
        SELECT attendance_id,
               MAX(CASE WHEN event_type="check_in" THEN latitude END) AS check_in_latitude,
               MAX(CASE WHEN event_type="check_in" THEN longitude END) AS check_in_longitude,
               MAX(CASE WHEN event_type="check_in" THEN accuracy_m END) AS check_in_accuracy_m,
               MAX(CASE WHEN event_type="check_in" THEN distance_m END) AS check_in_distance_m,
               MAX(CASE WHEN event_type="check_out" THEN latitude END) AS check_out_latitude,
               MAX(CASE WHEN event_type="check_out" THEN longitude END) AS check_out_longitude,
               MAX(CASE WHEN event_type="check_out" THEN accuracy_m END) AS check_out_accuracy_m,
               MAX(CASE WHEN event_type="check_out" THEN distance_m END) AS check_out_distance_m
        FROM attendance_location_evidence GROUP BY attendance_id
    ) loc ON loc.attendance_id=a.id';
}

function format_date(?string $date, string $format = 'd M Y'): string
{
    if (!$date) {
        return '—';
    }
    $value = new DateTimeImmutable($date);
    $months = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $shortMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $weekdays = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $shortWeekdays = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    $monthIndex = (int) $value->format('n') - 1;
    $weekdayIndex = (int) $value->format('w');
    return match ($format) {
        'd M Y' => $value->format('d') . ' ' . $shortMonths[$monthIndex] . ' ' . $value->format('Y'),
        'F Y' => $months[$monthIndex] . ' ' . $value->format('Y'),
        'l, d F Y' => $weekdays[$weekdayIndex] . ', ' . $value->format('d') . ' ' . $months[$monthIndex] . ' ' . $value->format('Y'),
        'D' => $shortWeekdays[$weekdayIndex],
        'M' => $shortMonths[$monthIndex],
        default => $value->format($format),
    };
}

function page_title(string $page): string
{
    return [
        'dashboard' => 'Ringkasan',
        'employees' => 'Data ASN',
        'users' => 'Pengguna',
        'attendance' => 'Absensi',
        'calendar' => 'Kalender pendidikan',
        'reports' => 'Laporan',
        'roles' => 'Manajemen role',
        'access' => 'Manajemen akses',
        'location' => 'Lokasi sekolah',
        'account' => 'Akun saya',
    ][$page] ?? 'E-Absensi';
}

function role_label(string $role): string
{
    return [
        'admin' => 'Administrator',
        'tendik' => 'Tendik',
        'pendidik' => 'Pendidik',
        'principal' => 'Kepala Sekolah',
    ][$role] ?? $role;
}

function has_permission(PDO $pdo, ?array $user, string $permission): bool
{
    if (!$user) {
        return false;
    }
    if (in_array($permission, ['attendance.manual', 'location.manage'], true) && $user['role'] !== 'admin') {
        return false;
    }
    $statement = $pdo->prepare(
        'SELECT rp.is_allowed FROM role_permissions rp
         JOIN permissions p ON p.id=rp.permission_id
         JOIN roles r ON r.id=rp.role_id
         WHERE r.name=:role AND p.name=:permission LIMIT 1'
    );
    $statement->execute(['role' => $user['role'], 'permission' => $permission]);
    return (bool) $statement->fetchColumn();
}

function require_permission(PDO $pdo, string $permission): array
{
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    if (!has_permission($pdo, $user, $permission)) {
        http_response_code(403);
        exit('Akses Anda tidak memiliki izin yang diperlukan.');
    }
    return $user;
}

function school_work_start(PDO $pdo): string
{
    static $cached = null;
    if ($cached === null) {
        $value = $pdo->query('SELECT work_start_time FROM school_settings WHERE id=1')->fetchColumn();
        $cached = is_string($value) && $value !== '' ? $value : '07:30:00';
    }
    return $cached;
}

function is_late(array $row, string $workStart): bool
{
    if (empty($row['check_in_at'])) {
        return false;
    }
    return date('H:i:s', strtotime((string) $row['check_in_at'])) > $workStart;
}

function valid_timezone(string $zone): bool
{
    return in_array($zone, timezone_identifiers_list(), true);
}

function mysql_offset_for(string $zone): string
{
    $tz = new DateTimeZone($zone);
    $seconds = $tz->getOffset(new DateTimeImmutable('now', $tz));
    $sign = $seconds < 0 ? '-' : '+';
    $seconds = abs($seconds);
    return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

function sync_mysql_timezone(PDO $pdo): void
{
    $pdo->exec('SET time_zone = ' . $pdo->quote(mysql_offset_for(date_default_timezone_get())));
}

function attendance_state_error(?array $record, string $action): ?string
{
    if ($action === 'check_in') {
        return $record && $record['check_in_at']
            ? 'Anda sudah absen masuk pukul ' . date('H:i', strtotime($record['check_in_at'])) . '.'
            : null;
    }
    if (!$record || !$record['check_in_at']) {
        return 'Belum ada absen masuk hari ini. Lakukan absen masuk terlebih dahulu.';
    }
    if ($record['check_out_at']) {
        return 'Anda sudah absen pulang pukul ' . date('H:i', strtotime($record['check_out_at'])) . '.';
    }
    if ((int) $record['elapsed_s'] < 300) {
        return 'Absen pulang hanya dapat dicatat minimal 5 menit setelah jam masuk.';
    }
    return null;
}
